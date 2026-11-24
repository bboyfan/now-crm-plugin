<?php
/**
 * Private campaign drafts and immutable email content.
 *
 * @package NowCRM
 */

namespace NowCRM\Campaign;

use NowCRM\Email\ConditionalContent;
use NowCRM\Email\EmailDesign;
use NowCRM\Email\EmailRenderer;
use NowCRM\Support\Capabilities;
use NowCRM\Templates\TemplateRepository;

defined( 'ABSPATH' ) || exit;

final class Campaigns {

	public const POST_TYPE = 'nowcrm_campaign';
	public const MODE_BLOCKS = 'blocks';
	public const MODE_HTML = 'html';

	public const STATUS_DRAFT = 'draft';
	public const STATUS_SCHEDULED = 'scheduled';
	public const STATUS_SENDING = 'sending';
	public const STATUS_PAUSED = 'paused';
	public const STATUS_SENT = 'sent';
	public const STATUS_CANCELLED = 'cancelled';

	public const META_STATUS = '_nowcrm_status';
	public const META_MODE = '_nowcrm_mode';
	public const META_HTML = '_nowcrm_html';
	public const META_SUBJECT = '_nowcrm_subject';
	public const META_PREHEADER = '_nowcrm_preheader';
	public const META_FROM_NAME = '_nowcrm_from_name';
	public const META_FROM_ADDR = '_nowcrm_from_address';
	public const META_REPLY_TO = '_nowcrm_reply_to';
	public const META_FOOTER = '_nowcrm_footer_text';
	public const META_AUDIENCE = '_nowcrm_audience';
	public const META_AB = '_nowcrm_ab';
	public const META_UTM = '_nowcrm_utm';
	public const META_TRACKING = '_nowcrm_tracking';
	public const META_DESIGN = '_nowcrm_design';
	public const META_SCHEDULE = '_nowcrm_schedule_at';
	public const META_SCHEDULE_END = '_nowcrm_schedule_end';
	public const META_SNAPSHOT = '_nowcrm_campaign_snapshot';
	public const META_SENT_AT = '_nowcrm_sent_at';

	public const BLOCKS = array(
		'core/paragraph', 'core/heading', 'core/image', 'core/list', 'core/list-item',
		'core/buttons', 'core/button', 'core/separator', 'core/spacer', 'core/group',
		'core/columns', 'core/column', 'core/table', 'core/html', 'core/quote',
		ConditionalContent::BLOCK,
	);

	private const FIELDS = array(
		'mode' => self::META_MODE,
		'html' => self::META_HTML,
		'subject' => self::META_SUBJECT,
		'preheader' => self::META_PREHEADER,
		'from_name' => self::META_FROM_NAME,
		'from_address' => self::META_FROM_ADDR,
		'reply_to' => self::META_REPLY_TO,
		'footer_text' => self::META_FOOTER,
	);

	public function register(): void {
		add_action( 'init', array( $this, 'register_post_type' ) );
		ConditionalContent::register();
	}

	public function register_post_type(): void {
		register_post_type(
			self::POST_TYPE,
			array(
				'label'        => __( '電子報', 'now-crm' ),
				'public'       => false,
				'show_ui'      => false,
				'show_in_rest' => false,
				'supports'     => array( 'title', 'editor' ),
				'map_meta_cap' => true,
			)
		);
	}

	public static function get( int $id ): ?\WP_Post {
		$post = $id > 0 ? get_post( $id ) : null;

		return $post && self::POST_TYPE === $post->post_type && ! in_array( $post->post_status, array( 'trash', 'auto-draft' ), true )
			? $post
			: null;
	}

	public static function can_edit( int $id ): bool {
		return Capabilities::can_manage_contacts() && self::get( $id ) && current_user_can( 'edit_post', $id );
	}

	public static function state( int $id ): string {
		return (string) get_post_meta( $id, self::META_STATUS, true ) ?: self::STATUS_DRAFT;
	}

	public static function status_label( string $state ): string {
		return array(
			self::STATUS_DRAFT     => __( '草稿', 'now-crm' ),
			self::STATUS_SCHEDULED => __( '已排程', 'now-crm' ),
			self::STATUS_SENDING   => __( '寄送中', 'now-crm' ),
			self::STATUS_PAUSED    => __( '已暫停', 'now-crm' ),
			self::STATUS_SENT      => __( '寄送結束', 'now-crm' ),
			self::STATUS_CANCELLED => __( '已取消', 'now-crm' ),
		)[ $state ] ?? $state;
	}

	public static function draft( \WP_Post $post ): array {
		$draft = array(
			'title'  => $post->post_title,
			'blocks' => $post->post_content,
		);

		foreach ( self::FIELDS as $field => $key ) {
			$draft[ $field ] = (string) get_post_meta( $post->ID, $key, true );
		}

		// New and untouched drafts show the default footer in the editor field
		// instead of rendering it only at preview time.
		if ( '' === $draft['footer_text'] && self::STATUS_DRAFT === self::state( $post->ID ) ) {
			$draft['footer_text'] = EmailRenderer::default_footer();
		}

		$draft['mode'] = self::MODE_HTML === $draft['mode'] ? self::MODE_HTML : self::MODE_BLOCKS;
		$draft['audience'] = Audience::stored( get_post_meta( $post->ID, self::META_AUDIENCE, true ) );
		$draft['ab'] = self::ab( $post->ID );
		$draft['tracking'] = array_merge( array( 'open' => false, 'click' => false ), self::meta_array( $post->ID, self::META_TRACKING ) );
		$draft['utm'] = array_merge(
			array( 'enabled' => false, 'source' => '', 'medium' => '', 'campaign' => '', 'term' => '', 'content' => '' ),
			self::meta_array( $post->ID, self::META_UTM )
		);
		$draft['design'] = EmailDesign::normalize( self::meta_array( $post->ID, self::META_DESIGN ) );

		return $draft;
	}

	public static function revision( array $draft ): string {
		return hash( 'sha256', (string) wp_json_encode( $draft ) );
	}

	public static function save( int $id, array $input, string $revision ) {
		return self::transaction(
			$id,
			static function ( \WP_Post $post ) use ( $id, $input, $revision ) {
				global $wpdb;

				if ( self::STATUS_DRAFT !== self::state( $id ) || $revision !== self::revision( self::draft( $post ) ) ) {
					return self::error( 'draft_changed', __( '草稿已變更。請重新整理後再編輯。', 'now-crm' ), 409 );
				}

				$fields = self::sanitize( $input );
				if ( is_wp_error( $fields ) ) {
					return $fields;
				}

				$result = $wpdb->update(
					$wpdb->posts,
					array(
						'post_title'        => $fields['title'],
						'post_content'      => $fields['blocks'],
						'post_modified'     => current_time( 'mysql' ),
						'post_modified_gmt' => gmdate( 'Y-m-d H:i:s' ),
					),
					array( 'ID' => $id )
				);
				if ( false === $result ) {
					return self::storage_error();
				}

				foreach ( self::FIELDS as $field => $key ) {
					self::write_meta( $id, $key, $fields[ $field ] );
				}
				foreach ( array( 'audience' => self::META_AUDIENCE, 'ab' => self::META_AB, 'tracking' => self::META_TRACKING, 'utm' => self::META_UTM ) as $field => $key ) {
					self::write_meta( $id, $key, (string) wp_json_encode( $fields[ $field ] ) );
				}
				// Callers that omit design keep the stored colours.
				if ( array_key_exists( 'design', $fields ) ) {
					self::write_meta( $id, self::META_DESIGN, (string) wp_json_encode( $fields['design'] ) );
				}

				return true;
			}
		);
	}

	private static function sanitize( array $input ) {
		$fields = array();
		foreach ( array_merge( array( 'title', 'blocks' ), array_keys( self::FIELDS ) ) as $field ) {
			if ( ! isset( $input[ $field ] ) || ! is_string( $input[ $field ] ) ) {
				return self::error( 'invalid_field', __( '草稿欄位格式不正確。', 'now-crm' ) );
			}
			$fields[ $field ] = sanitize_text_field( $input[ $field ] );
		}
		if ( '' === $fields['title'] || ! in_array( $fields['mode'], array( self::MODE_BLOCKS, self::MODE_HTML ), true ) ) {
			return self::error( 'invalid_content', __( '請填寫內部標題並選擇編輯方式。', 'now-crm' ) );
		}
		foreach ( array( 'from_address', 'reply_to' ) as $field ) {
			if ( '' !== $fields[ $field ] && ! is_email( $fields[ $field ] ) ) {
				return self::error( 'invalid_email', __( '請輸入有效的寄件或回覆地址。', 'now-crm' ) );
			}
		}
		$fields['footer_text'] = sanitize_textarea_field( $input['footer_text'] );
		$fields['html'] = ( new EmailRenderer() )->sanitize_raw_html( $input['html'] );
		$fields['blocks'] = self::sanitize_blocks( (string) $input['blocks'] );
		if ( is_wp_error( $fields['blocks'] ) ) {
			return $fields['blocks'];
		}

		$fields['audience'] = Audience::validate( $input['audience'] ?? null );
		if ( is_wp_error( $fields['audience'] ) ) {
			return $fields['audience'];
		}

		$ab = is_array( $input['ab'] ?? null ) ? $input['ab'] : array();
		$fields['ab'] = array(
			'enabled'   => true === ( $ab['enabled'] ?? false ),
			'subject_b' => is_string( $ab['subject_b'] ?? null ) ? sanitize_text_field( $ab['subject_b'] ) : '',
		);
		if ( $fields['ab']['enabled'] && '' === $fields['ab']['subject_b'] ) {
			return self::ab_error();
		}

		$tracking = is_array( $input['tracking'] ?? null ) ? $input['tracking'] : array();
		$fields['tracking'] = array( 'open' => true === ( $tracking['open'] ?? false ), 'click' => true === ( $tracking['click'] ?? false ) );
		$utm = is_array( $input['utm'] ?? null ) ? $input['utm'] : array();
		$fields['utm'] = array( 'enabled' => true === ( $utm['enabled'] ?? false ) );
		foreach ( array( 'source', 'medium', 'campaign', 'term', 'content' ) as $field ) {
			$fields['utm'][ $field ] = is_string( $utm[ $field ] ?? null ) ? sanitize_text_field( $utm[ $field ] ) : '';
		}

		if ( array_key_exists( 'design', $input ) ) {
			if ( ! is_array( $input['design'] ) && ! is_object( $input['design'] ) ) {
				return self::error( 'invalid_design', __( '設計顏色格式不正確。', 'now-crm' ) );
			}
			$fields['design'] = EmailDesign::normalize( $input['design'] );
		}

		return $fields;
	}

	/**
	 * Sanitize and validate one serialized block document.
	 *
	 * Shared by saves and unsaved previews so both use the same allow-list.
	 *
	 * @return string|\WP_Error
	 */
	public static function sanitize_blocks( string $blocks ) {
		$blocks = current_user_can( 'unfiltered_html' ) ? $blocks : wp_kses_post( $blocks );

		return self::allowed_blocks( parse_blocks( $blocks ) )
			? $blocks
			: self::error( 'unsupported_block', __( '正文內含不支援電子郵件的區塊。', 'now-crm' ) );
	}

	private static function allowed_blocks( array $blocks ): bool {
		foreach ( $blocks as $block ) {
			if ( ( $block['blockName'] && ! in_array( $block['blockName'], self::BLOCKS, true ) ) || ! self::allowed_blocks( $block['innerBlocks'] ) ) {
				return false;
			}
		}
		return true;
	}

	public static function snapshot( int $id, array $draft ): array {
		$renderer = new EmailRenderer();
		$utm = $draft['utm'];
		foreach ( array( 'source' => 'nowcrm', 'medium' => 'email', 'campaign' => 'campaign-' . $id ) as $key => $default ) {
			$utm[ $key ] = $utm[ $key ] ?: $default;
		}

		$sender = \NowCRM\Email\EmailDispatcher::sender( $draft );
		$snapshot = array(
			'mode'         => self::MODE_HTML,
			'content'      => self::MODE_HTML === $draft['mode'] ? $draft['html'] : $renderer->render_blocks( $draft['blocks'] ),
			'subject'      => $draft['subject'],
			'preheader'    => $draft['preheader'],
			'from_name'    => $sender['from_name'],
			'from_address' => $sender['from_address'],
			'reply_to'     => $sender['reply_to'],
			'footer_text'  => $draft['footer_text'],
			'tracking'     => $draft['tracking'],
			'utm'          => $utm,
		);

		if ( self::MODE_BLOCKS === $draft['mode'] ) {
			$snapshot['design'] = EmailDesign::normalize( $draft['design'] ?? array() );
		}

		$ab = self::ab_state( $draft );
		if ( $ab['enabled'] && '' !== trim( $ab['subject_b'] ) ) {
			$snapshot['ab'] = $ab;
		}

		return $snapshot;
	}

	public static function template( int $id ): ?array {
		$snapshot = self::meta_array( $id, self::META_SNAPSHOT );
		return isset( $snapshot['content'] ) ? $snapshot : null;
	}

	/**
	 * Create a draft campaign, optionally seeded from a published template.
	 *
	 * The template content is copied into the campaign when this method runs.
	 * The campaign keeps no template ID, so later template edits or deletion
	 * cannot change the campaign draft or its send snapshot.
	 *
	 * @param string $title       Internal campaign title.
	 * @param string $mode        MODE_BLOCKS or MODE_HTML for a blank draft.
	 * @param int    $template_id Published template ID, or 0 for a blank draft.
	 * @param string $subject     Optional initial subject.
	 * @param string $preheader   Optional initial preheader.
	 * @return int|\WP_Error
	 */
	public static function create_draft( string $title, string $mode, int $template_id = 0, string $subject = '', string $preheader = '' ) {
		$post_type = get_post_type_object( self::POST_TYPE );

		if ( ! Capabilities::can_manage_contacts() || ! $post_type || ! current_user_can( $post_type->cap->create_posts ) ) {
			return self::error( 'campaign_forbidden', __( '您沒有建立電子報的權限。', 'now-crm' ), 403 );
		}

		$title = sanitize_text_field( $title );

		if ( '' === $title ) {
			return self::error( 'campaign_title_required', __( '請輸入電子報的內部標題。', 'now-crm' ) );
		}

		$mode     = self::MODE_HTML === $mode ? self::MODE_HTML : self::MODE_BLOCKS;
		$template = null;

		if ( $template_id > 0 ) {
			$template = ( new TemplateRepository() )->for_campaign( $template_id );

			if ( ! $template ) {
				return self::error( 'template_not_found', __( '找不到可用的電子報範本。', 'now-crm' ), 404 );
			}

			$mode = $template['mode'];
		}

		$post_id = wp_insert_post(
			array(
				'post_type'    => self::POST_TYPE,
				'post_status'  => 'draft',
				'post_title'   => wp_slash( $title ),
				'post_content' => wp_slash( (string) ( $template['blocks'] ?? '' ) ),
				'post_author'  => get_current_user_id(),
			),
			true
		);

		if ( is_wp_error( $post_id ) || ! $post_id ) {
			return self::storage_error();
		}

		$post_id = (int) $post_id;

		update_post_meta( $post_id, self::META_MODE, $mode );
		update_post_meta( $post_id, self::META_STATUS, self::STATUS_DRAFT );

		if ( $template ) {
			update_post_meta( $post_id, self::META_SUBJECT, wp_slash( (string) $template['subject'] ) );
			update_post_meta( $post_id, self::META_PREHEADER, wp_slash( (string) $template['preheader'] ) );
			update_post_meta( $post_id, self::META_HTML, wp_slash( (string) $template['html'] ) );
		}

		if ( '' !== $subject ) {
			update_post_meta( $post_id, self::META_SUBJECT, wp_slash( sanitize_text_field( $subject ) ) );
		}
		if ( '' !== $preheader ) {
			update_post_meta( $post_id, self::META_PREHEADER, wp_slash( sanitize_text_field( $preheader ) ) );
		}

		return $post_id;
	}

	public static function duplicate( \WP_Post $post ) {
		$draft = self::draft( $post );
		$id = wp_insert_post(
			array(
				'post_type'    => self::POST_TYPE,
				'post_status'  => 'draft',
				'post_title'   => wp_slash( $draft['title'] . __( '（複製）', 'now-crm' ) ),
				'post_content' => wp_slash( $draft['blocks'] ),
			),
			true
		);
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		foreach ( self::FIELDS as $field => $key ) {
			update_post_meta( $id, $key, wp_slash( $draft[ $field ] ) );
		}
		foreach ( array( 'audience' => self::META_AUDIENCE, 'ab' => self::META_AB, 'tracking' => self::META_TRACKING, 'utm' => self::META_UTM, 'design' => self::META_DESIGN ) as $field => $key ) {
			update_post_meta( $id, $key, wp_slash( (string) wp_json_encode( $draft[ $field ] ) ) );
		}
		return (int) $id;
	}

	public static function transaction( int $id, callable $operation ) {
		global $wpdb;

		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			return self::storage_error();
		}
		$committed = false;
		try {
			$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE ID = %d AND post_type = %s FOR UPDATE", $id, self::POST_TYPE ) );
			wp_cache_delete( $id, 'posts' );
			wp_cache_delete( $id, 'post_meta' );
			$post = self::get( $id );
			if ( ! $post ) {
				return self::error( 'not_found', __( '找不到電子報。', 'now-crm' ), 404 );
			}
			$result = $operation( $post );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			$committed = false !== $wpdb->query( 'COMMIT' );
			return $committed ? $result : self::storage_error();
		} catch ( \RuntimeException $exception ) {
			return self::storage_error();
		} finally {
			if ( ! $committed ) {
				$wpdb->query( 'ROLLBACK' );
			}
			if ( $id > 0 ) {
				clean_post_cache( $id );
			}
		}
	}

	/** Write campaign meta under the campaign row lock. */
	public static function write_meta( int $id, string $key, ?string $value ): void {
		global $wpdb;

		$where = array( 'post_id' => $id, 'meta_key' => $key );
		if ( null === $value ) {
			$result = $wpdb->delete( $wpdb->postmeta, $where );
		} else {
			$exists = $wpdb->get_var( $wpdb->prepare( "SELECT meta_id FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s LIMIT 1", $id, $key ) );
			if ( $wpdb->last_error ) {
				throw new \RuntimeException( 'Campaign meta lookup failed.' );
			}
			$result = $exists
				? $wpdb->update( $wpdb->postmeta, array( 'meta_value' => $value ), $where )
				: $wpdb->insert( $wpdb->postmeta, $where + array( 'meta_value' => $value ) );
		}
		if ( false === $result ) {
			throw new \RuntimeException( 'Campaign meta write failed.' );
		}
	}

	public static function error( string $code, string $message, int $status = 422 ): \WP_Error {
		return new \WP_Error( 'nowcrm_' . $code, $message, array( 'status' => $status ) );
	}

	public static function ab_error(): \WP_Error {
		return self::error( 'invalid_ab', __( '請填寫 B 版郵件主旨，或關閉主旨 A／B 測試。', 'now-crm' ) );
	}

	/**
	 * Normalized A/B subject state from a draft or a stored meta array.
	 *
	 * @param array<string, mixed> $draft Draft or array with an `ab` key.
	 * @return array{enabled: bool, subject_b: string}
	 */
	public static function ab_state( array $draft ): array {
		$ab = isset( $draft['ab'] ) && is_array( $draft['ab'] ) ? $draft['ab'] : array();

		return array(
			'enabled'   => true === ( $ab['enabled'] ?? false ),
			'subject_b' => is_string( $ab['subject_b'] ?? null ) ? $ab['subject_b'] : '',
		);
	}

	private static function ab( int $id ): array {
		return self::ab_state( array( 'ab' => self::meta_array( $id, self::META_AB ) ) );
	}

	public static function storage_error(): \WP_Error {
		return self::error( 'campaign_storage', __( '無法儲存電子報。請重試。', 'now-crm' ), 500 );
	}

	private static function meta_array( int $id, string $key ): array {
		$value = json_decode( (string) get_post_meta( $id, $key, true ), true );
		return is_array( $value ) ? $value : array();
	}
}
