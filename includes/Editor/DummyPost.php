<?php
/**
 * Private working posts for the native block editor.
 *
 * Campaign and template content stays in its own post type and is saved
 * through Campaigns::save() / TemplateRepository::update(). The block editor
 * only ever talks to a disposable private post, so core REST saves, autosaves,
 * revisions and post locks cannot mutate a campaign or a template directly.
 *
 * @package NowCRM
 */

namespace NowCRM\Editor;

use NowCRM\Automation\AutomationRepository;
use NowCRM\Campaign\Campaigns;
use NowCRM\Sequences\SequenceRepository;
use NowCRM\Support\Capabilities;
use NowCRM\Templates\TemplateRepository;

defined( 'ABSPATH' ) || exit;

final class DummyPost {

	public const POST_TYPE = 'nowcrm_editor';

	public const META_TARGET_TYPE = '_nowcrm_editor_target_type';
	public const META_TARGET_ID   = '_nowcrm_editor_target_id';

	private const TARGETS = array( 'campaign', 'template', 'sequence', 'automation' );

	public function register(): void {
		add_action( 'init', array( $this, 'register_post_type' ) );
		add_filter( 'map_meta_cap', array( $this, 'map_caps' ), 10, 4 );
		add_filter( 'wp_insert_post_data', array( $this, 'force_draft' ), 10, 2 );
		add_action( 'before_delete_post', array( $this, 'delete_for_post' ) );
	}

	public function register_post_type(): void {
		register_post_type(
			self::POST_TYPE,
			array(
				'label'               => __( 'NOW CRM 編輯器', 'now-crm' ),
				'public'              => false,
				'publicly_queryable'  => false,
				'show_ui'             => false,
				'show_in_menu'        => false,
				'show_in_rest'        => true,
				'rest_base'           => 'nowcrm-editor',
				'supports'            => array( 'editor', 'custom-fields' ),
				'capability_type'     => array( 'nowcrm_editor', 'nowcrm_editors' ),
				'capabilities'        => array(
					'edit_posts'    => Capabilities::MANAGE_CONTACTS,
					'create_posts'  => 'do_not_allow',
					'publish_posts' => 'do_not_allow',
				),
				'map_meta_cap'        => true,
				'delete_with_user'    => false,
			)
		);
	}

	/**
	 * Bind working-post access to the live authority of the linked CRM object.
	 *
	 * A working post is a disposable copy. Stored role grants must never grant
	 * access to it, so item caps resolve against the campaign, template,
	 * sequence or automation the working post belongs to. Autosaves and
	 * revisions resolve through their parent working post.
	 *
	 * @param string[] $caps    Primitive capabilities required by core.
	 * @param string   $cap     Meta capability being checked.
	 * @param int      $user_id User ID.
	 * @param mixed[]  $args    Capability arguments, starting with the post ID.
	 * @return string[]
	 */
	public function map_caps( array $caps, string $cap, int $user_id, array $args ): array {
		if ( ! in_array( $cap, array( 'read_post', 'edit_post', 'delete_post' ), true ) || ! isset( $args[0] ) ) {
			return $caps;
		}

		$post = get_post( (int) $args[0] );

		if ( $post && 'revision' === $post->post_type ) {
			$post = $post->post_parent > 0 ? get_post( (int) $post->post_parent ) : null;
		}

		if ( ! $post || self::POST_TYPE !== $post->post_type ) {
			return $caps;
		}

		$type      = (string) get_post_meta( $post->ID, self::META_TARGET_TYPE, true );
		$target_id = (int) get_post_meta( $post->ID, self::META_TARGET_ID, true );

		if ( ! in_array( $type, self::TARGETS, true ) || $target_id <= 0 || ! user_can( $user_id, Capabilities::MANAGE_CONTACTS ) ) {
			return array( 'do_not_allow' );
		}

		if ( 'campaign' === $type || 'template' === $type ) {
			$target = 'campaign' === $type ? Campaigns::get( $target_id ) : null;

			if ( 'template' === $type ) {
				$target = ( new TemplateRepository() )->get( $target_id ) ? get_post( $target_id ) : null;
			}

			// Only map onto the real target. Never recurse into a working post.
			if ( ! $target || self::target_post_type( $type ) !== $target->post_type ) {
				return array( 'do_not_allow' );
			}

			$caps   = map_meta_cap( $cap, $user_id, $target_id );
			$caps[] = Capabilities::MANAGE_CONTACTS;

			return $caps;
		}

		$exists = 'sequence' === $type
			? null !== ( new SequenceRepository() )->get( $target_id )
			: null !== ( new AutomationRepository() )->get( $target_id );

		return $exists ? array( Capabilities::MANAGE_CONTACTS ) : array( 'do_not_allow' );
	}

	/**
	 * Post type that stores one kind of editable target.
	 */
	private static function target_post_type( string $type ): string {
		return match ( $type ) {
			'campaign' => Campaigns::POST_TYPE,
			'template' => TemplateRepository::POST_TYPE,
			default => '',
		};
	}

	/**
	 * The block editor may try to publish or submit the working post. Keep it a
	 * draft so no endpoint ever exposes its content.
	 *
	 * @param array<string, mixed> $data    Slashed post data.
	 * @param array<string, mixed> $postarr Raw post array.
	 * @return array<string, mixed>
	 */
	public function force_draft( array $data, array $postarr ): array {
		if ( self::POST_TYPE === ( $data['post_type'] ?? '' ) ) {
			$data['post_status'] = 'draft';
		}

		return $data;
	}

	/**
	 * Return (or create) the working post for a campaign/template target.
	 *
	 * @param string      $type             campaign or template.
	 * @param int         $target_id        Campaign or template ID.
	 * @param string|null $content_override Unsaved blocks kept at the source by a
	 *                                      failed save. Null keeps the stored source.
	 * @return int|\WP_Error
	 */
	public static function ensure( string $type, int $target_id, ?string $content_override = null ) {
		if ( ! in_array( $type, self::TARGETS, true ) || $target_id <= 0 ) {
			return Campaigns::error( 'invalid_editor_target', __( '無效的編輯目標。', 'now-crm' ), 400 );
		}

		$source = self::source( $type, $target_id );
		if ( is_wp_error( $source ) ) {
			return $source;
		}

		// A sequence or automation owns many emails inside one form, so its
		// working post is a reusable blank canvas: persisted step content must
		// never be copied into it, and seeding always comes from the currently
		// edited step in the page.
		$blank = in_array( $type, array( 'sequence', 'automation' ), true );

		if ( null !== $content_override && ! $blank ) {
			$source['content'] = $content_override;
		}

		$dummy = self::find( $type, $target_id );

		if ( $dummy ) {
			if ( $blank ) {
				if ( (string) $dummy->post_title !== $source['title'] ) {
					wp_update_post(
						array(
							'ID'          => $dummy->ID,
							'post_title'  => wp_slash( $source['title'] ),
							'post_status' => 'draft',
						)
					);
				}

				return (int) $dummy->ID;
			}

			// Timestamps miss same-second saves and direct writes to the working
			// post, so compare the stored title and body with the source.
			if ( (string) $dummy->post_title !== $source['title'] || (string) $dummy->post_content !== $source['content'] ) {
				self::write( $dummy->ID, $source['title'], $source['content'], $type, $target_id );
			}

			return (int) $dummy->ID;
		}

		$id = wp_insert_post(
			array(
				'post_type'    => self::POST_TYPE,
				'post_status'  => 'draft',
				'post_title'   => wp_slash( $source['title'] ),
				'post_content' => wp_slash( $source['content'] ),
				'post_author'  => get_current_user_id(),
			),
			true
		);

		if ( is_wp_error( $id ) || ! $id ) {
			return Campaigns::error( 'editor_storage', __( '無法建立編輯工作區。', 'now-crm' ), 500 );
		}

		self::write( (int) $id, $source['title'], $source['content'], $type, $target_id );

		return (int) $id;
	}

	/**
	 * Update the working post from the source, or record the last synced source.
	 */
	public static function touch( int $dummy_id, string $type, int $target_id ): void {
		$source = self::source( $type, $target_id );
		if ( is_wp_error( $source ) ) {
			return;
		}

		if ( 'sequence' === $type ) {
			// A sequence canvas is blank on purpose; only its owning title moves.
			wp_update_post(
				array(
					'ID'          => $dummy_id,
					'post_title'  => wp_slash( $source['title'] ),
					'post_status' => 'draft',
				)
			);

			return;
		}

		self::write( $dummy_id, $source['title'], $source['content'], $type, $target_id );
	}

	/**
	 * Delete the working post for a campaign/template.
	 */
	public static function delete_for_target( string $type, int $target_id ): void {
		$dummy = self::find( $type, $target_id );
		if ( $dummy ) {
			wp_delete_post( $dummy->ID, true );
		}
	}

	/**
	 * Delete a working post when its campaign/template is permanently deleted.
	 */
	public function delete_for_post( int $post_id ): void {
		$post_type = get_post_type( $post_id );

		if ( Campaigns::POST_TYPE === $post_type ) {
			self::delete_for_target( 'campaign', $post_id );
			return;
		}

		if ( TemplateRepository::POST_TYPE === $post_type ) {
			self::delete_for_target( 'template', $post_id );
			return;
		}

		if ( SequenceRepository::POST_TYPE === $post_type ) {
			self::delete_for_target( 'sequence', $post_id );
		}
	}

	private static function write( int $dummy_id, string $title, string $content, string $type, int $target_id ): void {
		wp_update_post(
			array(
				'ID'           => $dummy_id,
				'post_title'   => wp_slash( $title ),
				'post_content' => wp_slash( $content ),
				'post_status'  => 'draft',
			)
		);
		update_post_meta( $dummy_id, self::META_TARGET_TYPE, $type );
		update_post_meta( $dummy_id, self::META_TARGET_ID, $target_id );
	}

	/**
	 * @return \WP_Post|null
	 */
	private static function find( string $type, int $target_id ): ?\WP_Post {
		$posts = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'all',
				'meta_query'     => array(
					array( 'key' => self::META_TARGET_TYPE, 'value' => $type ),
					array( 'key' => self::META_TARGET_ID, 'value' => (string) $target_id ),
				),
				'no_found_rows'  => true,
			)
		);

		return $posts[0] ?? null;
	}

	/**
	 * Source title and content for a target.
	 *
	 * @return array{title: string, content: string}|\WP_Error
	 */
	private static function source( string $type, int $target_id ) {
		if ( 'campaign' === $type ) {
			$post = Campaigns::get( $target_id );
			if ( ! $post ) {
				return Campaigns::error( 'not_found', __( '找不到電子報。', 'now-crm' ), 404 );
			}
			if ( Campaigns::STATUS_DRAFT !== Campaigns::state( $target_id ) ) {
				return Campaigns::error( 'campaign_state', __( '只有草稿可以編輯內容。', 'now-crm' ), 409 );
			}

			return array(
				'title'   => $post->post_title,
				'content' => $post->post_content,
			);
		}

		if ( 'sequence' === $type ) {
			$sequence = ( new SequenceRepository() )->get( $target_id );
			if ( ! $sequence ) {
				return Campaigns::error( 'not_found', __( '找不到序列。', 'now-crm' ), 404 );
			}

			// The canvas is blank; the sequence keeps its own step content.
			return array(
				'title'   => (string) $sequence['name'],
				'content' => '',
			);
		}

		if ( 'automation' === $type ) {
			$automation = ( new AutomationRepository() )->get( $target_id );
			if ( ! $automation ) {
				return Campaigns::error( 'not_found', __( '找不到自動化。', 'now-crm' ), 404 );
			}

			return array(
				'title'   => (string) $automation['name'],
				'content' => '',
			);
		}

		$repo = new TemplateRepository();
		$template = $repo->get( $target_id );
		if ( ! $template ) {
			return Campaigns::error( 'not_found', __( '找不到電子報範本。', 'now-crm' ), 404 );
		}

		return array(
			'title'   => (string) $template['name'],
			'content' => (string) $template['blocks'],
		);
	}
}
