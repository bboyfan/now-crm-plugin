<?php
/**
 * Reusable campaign email templates.
 *
 * Templates are a separate post type. A campaign copies template content when
 * it is created, so later template edits or deletion never change a campaign
 * draft or its send snapshot.
 *
 * @package NowCRM
 */

namespace NowCRM\Templates;

use NowCRM\Campaign\Campaigns;
use NowCRM\Email\EmailRenderer;
use NowCRM\Support\Capabilities;
use WP_Error;

defined( 'ABSPATH' ) || exit;

final class TemplateRepository {

	public const POST_TYPE = 'nowcrm_template';

	public const STATUS_DRAFT   = 'draft';
	public const STATUS_PUBLISH = 'publish';

	public const META_MODE      = '_nowcrm_template_mode';
	public const META_HTML      = '_nowcrm_template_html';
	public const META_SUBJECT   = '_nowcrm_template_subject';
	public const META_PREHEADER = '_nowcrm_template_preheader';

	/**
	 * Maximum number of templates one bulk request may delete.
	 */
	public const BULK_LIMIT = 100;

	/**
	 * Templates offered by the campaign creation form.
	 */
	private const PICKER_LIMIT = 200;

	/**
	 * @var string[]
	 */
	private const STATUSES = array( self::STATUS_DRAFT, self::STATUS_PUBLISH );

	public function register(): void {
		add_action( 'init', array( $this, 'register_post_type' ) );
	}

	public function register_post_type(): void {
		register_post_type(
			self::POST_TYPE,
			array(
				'label'        => __( '電子報範本', 'now-crm' ),
				'public'       => false,
				'show_ui'      => false,
				'show_in_rest' => false,
				'rewrite'      => false,
				'query_var'    => false,
				'supports'     => array( 'title' ),
				'map_meta_cap' => true,
			)
		);
	}

	/**
	 * Fetch one draft or published template.
	 *
	 * @return array<string, mixed>|null
	 */
	public function get( int $id ): ?array {
		$post = $id > 0 ? get_post( $id ) : null;

		if ( ! $post || self::POST_TYPE !== $post->post_type || ! in_array( $post->post_status, self::STATUSES, true ) ) {
			return null;
		}

		return $this->cast( $post );
	}

	/**
	 * Stable content revision used by the native editor conflict check.
	 *
	 * @param array<string, mixed> $template Template shape.
	 */
	public function revision( array $template ): string {
		return hash(
			'sha256',
			(string) wp_json_encode(
				array(
					'name'      => (string) ( $template['name'] ?? '' ),
					'mode'      => (string) ( $template['mode'] ?? '' ),
					'blocks'    => (string) ( $template['blocks'] ?? '' ),
					'html'      => (string) ( $template['html'] ?? '' ),
					'subject'   => (string) ( $template['subject'] ?? '' ),
					'preheader' => (string) ( $template['preheader'] ?? '' ),
					'status'    => (string) ( $template['status'] ?? '' ),
				)
			)
		);
	}

	/**
	 * Revision for one stored template, or null when it does not exist.
	 */
	public function revision_for( int $id ): ?string {
		$template = $this->get( $id );

		return $template ? $this->revision( $template ) : null;
	}

	public function can_create(): bool {
		$type = get_post_type_object( self::POST_TYPE );

		return Capabilities::can_manage_contacts() && $type && current_user_can( $type->cap->create_posts );
	}

	public function can_edit( int $id ): bool {
		return Capabilities::can_manage_contacts() && null !== $this->get( $id ) && current_user_can( 'edit_post', $id );
	}

	public function can_delete( int $id ): bool {
		return Capabilities::can_manage_contacts() && null !== $this->get( $id ) && current_user_can( 'delete_post', $id );
	}

	/**
	 * Search and page the template directory.
	 *
	 * @param array<string, mixed> $args page, per_page, search, status.
	 * @return array{items: array<int, array<string, mixed>>, total: int, pages: int, page: int, per_page: int}
	 */
	public function paginate( array $args = array() ): array {
		$page     = max( 1, (int) ( $args['page'] ?? 1 ) );
		$per_page = min( 100, max( 1, (int) ( $args['per_page'] ?? 20 ) ) );
		$status   = (string) ( $args['status'] ?? '' );
		$search   = trim( (string) ( $args['search'] ?? '' ) );

		$query_args = array(
			'post_type'              => self::POST_TYPE,
			'post_status'            => in_array( $status, self::STATUSES, true ) ? array( $status ) : self::STATUSES,
			'posts_per_page'         => $per_page,
			'paged'                  => $page,
			'orderby'                => 'modified',
			'order'                  => 'DESC',
			'ignore_sticky_posts'    => true,
			'update_post_term_cache' => false,
		);

		if ( '' !== $search ) {
			$query_args['s'] = $search;
		}

		$query = new \WP_Query( $query_args );

		return array(
			'items'    => array_map( array( $this, 'cast' ), $query->posts ),
			'total'    => (int) $query->found_posts,
			'pages'    => (int) $query->max_num_pages,
			'page'     => $page,
			'per_page' => $per_page,
		);
	}

	/**
	 * Published templates that can seed a new campaign.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function published(): array {
		$posts = get_posts(
			array(
				'post_type'        => self::POST_TYPE,
				'post_status'      => self::STATUS_PUBLISH,
				'posts_per_page'   => self::PICKER_LIMIT,
				'orderby'          => 'title',
				'order'            => 'ASC',
				'no_found_rows'    => true,
				'suppress_filters' => false,
			)
		);

		return array_map( array( $this, 'cast' ), $posts );
	}

	/**
	 * A published template, or null when the template is missing, unpublished
	 * or belongs to another post type.
	 *
	 * @return array<string, mixed>|null
	 */
	public function for_campaign( int $id ): ?array {
		$template = $this->get( $id );

		return $template && self::STATUS_PUBLISH === $template['status'] ? $template : null;
	}

	/**
	 * Create a draft template.
	 *
	 * @param array<string, mixed> $data name, mode, blocks, html, subject, preheader, status.
	 * @return int|WP_Error
	 */
	public function create( array $data ) {
		if ( ! $this->can_create() ) {
			return self::error( 'forbidden', __( '您沒有建立電子報範本的權限。', 'now-crm' ), 403 );
		}

		$fields = $this->sanitize( $data );
		if ( is_wp_error( $fields ) ) {
			return $fields;
		}

		$id = wp_insert_post(
			array(
				'post_type'    => self::POST_TYPE,
				'post_status'  => $fields['status'],
				'post_title'   => wp_slash( $fields['name'] ),
				'post_content' => wp_slash( $fields['blocks'] ),
				'post_author'  => get_current_user_id(),
			),
			true
		);

		if ( is_wp_error( $id ) || ! $id ) {
			return self::error( 'db_error', __( '無法建立電子報範本。請再試一次。', 'now-crm' ), 500 );
		}

		$this->write_fields( (int) $id, $fields );

		return (int) $id;
	}

	/**
	 * Update one template.
	 *
	 * @param array<string, mixed> $data name, mode, blocks, html, subject, preheader, status.
	 * @return true|WP_Error
	 */
	public function update( int $id, array $data ) {
		$template = $this->get( $id );

		if ( ! $template ) {
			return self::error( 'not_found', __( '找不到電子報範本。', 'now-crm' ), 404 );
		}

		if ( ! $this->can_edit( $id ) ) {
			return self::error( 'forbidden', __( '您沒有編輯此電子報範本的權限。', 'now-crm' ), 403 );
		}

		$fields = $this->sanitize( $data, $template );
		if ( is_wp_error( $fields ) ) {
			return $fields;
		}

		$result = wp_update_post(
			array(
				'ID'           => $id,
				'post_status'  => $fields['status'],
				'post_title'   => wp_slash( $fields['name'] ),
				'post_content' => wp_slash( $fields['blocks'] ),
			),
			true
		);

		if ( is_wp_error( $result ) || ! $result ) {
			return self::error( 'db_error', __( '無法儲存電子報範本。請再試一次。', 'now-crm' ), 500 );
		}

		$this->write_fields( $id, $fields );

		return true;
	}

	/**
	 * Copy one template into a new, independent draft.
	 *
	 * @return int|WP_Error
	 */
	public function duplicate( int $id ) {
		$template = $this->get( $id );

		if ( ! $template ) {
			return self::error( 'not_found', __( '找不到電子報範本。', 'now-crm' ), 404 );
		}

		if ( ! $this->can_create() || ! $this->can_edit( $id ) ) {
			return self::error( 'forbidden', __( '您沒有複製此電子報範本的權限。', 'now-crm' ), 403 );
		}

		return $this->create(
			array(
				'name'      => sprintf(
					/* translators: %s: original template name. */
					__( '[複製] %s', 'now-crm' ),
					$template['name']
				),
				'mode'      => $template['mode'],
				'blocks'    => $template['blocks'],
				'html'      => $template['html'],
				'subject'   => $template['subject'],
				'preheader' => $template['preheader'],
				'status'    => self::STATUS_DRAFT,
			)
		);
	}

	/**
	 * Permanently delete one template. Campaigns never reference template rows.
	 *
	 * @return true|WP_Error
	 */
	public function delete( int $id ) {
		if ( ! $this->get( $id ) ) {
			return self::error( 'not_found', __( '找不到電子報範本。', 'now-crm' ), 404 );
		}

		if ( ! $this->can_delete( $id ) ) {
			return self::error( 'forbidden', __( '您沒有刪除此電子報範本的權限。', 'now-crm' ), 403 );
		}

		if ( ! wp_delete_post( $id, true ) ) {
			return self::error( 'db_error', __( '無法刪除電子報範本。請再試一次。', 'now-crm' ), 500 );
		}

		return true;
	}

	/**
	 * Permanently delete up to BULK_LIMIT templates.
	 *
	 * @param int[] $ids Template IDs.
	 */
	public function delete_many( array $ids, int $limit = self::BULK_LIMIT ): int {
		$ids = array_slice(
			array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) ),
			0,
			max( 0, $limit )
		);

		$deleted = 0;

		foreach ( $ids as $id ) {
			$result = $this->delete( $id );
			if ( true === $result ) {
				++$deleted;
			}
		}

		return $deleted;
	}

	/**
	 * Sanitize the editable fields. Both content representations are preserved
	 * when the mode changes, exactly like campaign drafts.
	 *
	 * @param array<string, mixed>      $input    Raw fields.
	 * @param array<string, mixed>|null $existing Stored template for partial input.
	 * @return array<string, string>|WP_Error
	 */
	private function sanitize( array $input, ?array $existing = null ) {
		$mode = isset( $input['mode'] ) ? sanitize_key( (string) $input['mode'] ) : (string) ( $existing['mode'] ?? Campaigns::MODE_BLOCKS );

		if ( ! in_array( $mode, array( Campaigns::MODE_BLOCKS, Campaigns::MODE_HTML ), true ) ) {
			return self::error( 'invalid_mode', __( '請選擇 Gutenberg 或 Raw HTML 編輯方式。', 'now-crm' ) );
		}

		$name = isset( $input['name'] ) ? sanitize_text_field( (string) $input['name'] ) : (string) ( $existing['name'] ?? '' );

		if ( '' === $name ) {
			return self::error( 'invalid_name', __( '請輸入範本名稱。', 'now-crm' ) );
		}

		$status = isset( $input['status'] ) ? sanitize_key( (string) $input['status'] ) : (string) ( $existing['status'] ?? self::STATUS_DRAFT );
		if ( ! in_array( $status, self::STATUSES, true ) ) {
			$status = self::STATUS_DRAFT;
		}

		$blocks = isset( $input['blocks'] ) && is_string( $input['blocks'] )
			? (string) $input['blocks']
			: (string) ( $existing['blocks'] ?? '' );
		$html = isset( $input['html'] ) && is_string( $input['html'] )
			? (string) $input['html']
			: (string) ( $existing['html'] ?? '' );

		$renderer = new EmailRenderer();
		$blocks   = current_user_can( 'unfiltered_html' ) ? $blocks : wp_kses_post( $blocks );
		$html     = $renderer->sanitize_raw_html( $html );

		if ( ! self::allowed_blocks( parse_blocks( $blocks ) ) ) {
			return self::error( 'unsupported_block', __( '正文內含不支援電子報的區塊。', 'now-crm' ) );
		}

		return array(
			'name'      => $name,
			'subject'   => isset( $input['subject'] ) ? sanitize_text_field( (string) $input['subject'] ) : (string) ( $existing['subject'] ?? '' ),
			'preheader' => isset( $input['preheader'] ) ? sanitize_text_field( (string) $input['preheader'] ) : (string) ( $existing['preheader'] ?? '' ),
			'mode'      => $mode,
			'blocks'    => $blocks,
			'html'      => $html,
			'status'    => $status,
		);
	}

	/**
	 * Same block list the campaign editor enforces.
	 *
	 * @param array<int, array<string, mixed>> $blocks Parsed blocks.
	 */
	private static function allowed_blocks( array $blocks ): bool {
		foreach ( $blocks as $block ) {
			if ( ( $block['blockName'] && ! in_array( $block['blockName'], Campaigns::BLOCKS, true ) ) || ! self::allowed_blocks( $block['innerBlocks'] ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * @param array<string, string> $fields Sanitized fields.
	 */
	private function write_fields( int $id, array $fields ): void {
		update_post_meta( $id, self::META_MODE, $fields['mode'] );
		update_post_meta( $id, self::META_SUBJECT, wp_slash( $fields['subject'] ) );
		update_post_meta( $id, self::META_PREHEADER, wp_slash( $fields['preheader'] ) );
		update_post_meta( $id, self::META_HTML, wp_slash( $fields['html'] ) );
	}

	/**
	 * Cast a post to the template shape.
	 *
	 * @return array<string, mixed>
	 */
	private function cast( \WP_Post $post ): array {
		$mode = (string) get_post_meta( $post->ID, self::META_MODE, true );

		return array(
			'id'        => (int) $post->ID,
			'name'      => $post->post_title,
			'mode'      => Campaigns::MODE_HTML === $mode ? Campaigns::MODE_HTML : Campaigns::MODE_BLOCKS,
			'blocks'    => $post->post_content,
			'html'      => (string) get_post_meta( $post->ID, self::META_HTML, true ),
			'subject'   => (string) get_post_meta( $post->ID, self::META_SUBJECT, true ),
			'preheader' => (string) get_post_meta( $post->ID, self::META_PREHEADER, true ),
			'status'    => (string) $post->post_status,
			'modified'  => (string) $post->post_modified,
		);
	}

	public static function error( string $code, string $message, int $status = 422 ): WP_Error {
		return new WP_Error( 'nowcrm_template_' . $code, $message, array( 'status' => $status ) );
	}
}
