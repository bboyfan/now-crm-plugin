<?php
/**
 * Smart link storage. Links are private `nowcrm_smart_link` posts; every
 * setting lives in post meta and every click is counted in post meta.
 *
 * Post status stays `publish` for both active and paused links so the earlier
 * state survives a pause. `_nowcrm_smart_status` is the public switch.
 *
 * @package NowCRM
 */

namespace NowCRM\SmartLinks;

use NowCRM\Terms\TermRepository;
use WP_Error;

defined( 'ABSPATH' ) || exit;

final class SmartLinkRepository {

	public const POST_TYPE = 'nowcrm_smart_link';

	public const STATUS_ACTIVE = 'active';
	public const STATUS_PAUSED = 'paused';

	public const META_TARGET         = '_nowcrm_smart_target_url';
	public const META_STATUS         = '_nowcrm_smart_status';
	public const META_APPLY_LISTS    = '_nowcrm_smart_apply_lists';
	public const META_APPLY_TAGS     = '_nowcrm_smart_apply_tags';
	public const META_REMOVE_LISTS   = '_nowcrm_smart_remove_lists';
	public const META_REMOVE_TAGS    = '_nowcrm_smart_remove_tags';
	public const META_ALL_CLICKS     = '_nowcrm_smart_all_clicks';
	public const META_CONTACT_CLICKS = '_nowcrm_smart_contact_clicks';
	public const META_LAST_CLICK     = '_nowcrm_smart_last_click';

	public const CLICK_LOG_OPTION = 'nowcrm_smart_click';
	public const CLICK_LOG_LIMIT  = 200;

	public const QUERY_VAR       = 'nowcrm_smart';
	public const TOKEN_QUERY_VAR = 'nowcrm_contact';

	public const SLUG_MIN = 3;
	public const SLUG_MAX = 64;

	public const MAX_TERMS   = 200;
	public const MAX_URL_LEN = 2048;

	/**
	 * Register the private post type. No rewrite: the short URL is a query arg
	 * handled on `template_redirect`.
	 */
	public static function register_post_type(): void {
		register_post_type(
			self::POST_TYPE,
			array(
				'label'               => __( '智慧連結', 'now-crm' ),
				'public'              => false,
				'show_ui'             => false,
				'show_in_rest'        => false,
				'exclude_from_search' => true,
				'rewrite'             => false,
				'query_var'           => false,
				'supports'            => array( 'title' ),
				'map_meta_cap'        => true,
			)
		);
	}

	/**
	 * Public short URL. The optional token is opaque and carries no PII.
	 */
	public static function short_url( string $slug, string $token = '' ): string {
		$args = array( self::QUERY_VAR => $slug );

		if ( '' !== $token ) {
			$args[ self::TOKEN_QUERY_VAR ] = $token;
		}

		return add_query_arg( $args, home_url( '/' ) );
	}

	/**
	 * One link with its settings and counters.
	 *
	 * @return array<string, mixed>|null
	 */
	public function get( int $id ): ?array {
		$post = $id > 0 ? get_post( $id ) : null;

		if ( ! $post || self::POST_TYPE !== $post->post_type || in_array( $post->post_status, array( 'trash', 'auto-draft' ), true ) ) {
			return null;
		}

		$status = (string) get_post_meta( $post->ID, self::META_STATUS, true );
		$slug   = (string) $post->post_name;

		return array(
			'id'             => (int) $post->ID,
			'title'          => (string) get_the_title( $post ),
			'slug'           => $slug,
			'target_url'     => (string) get_post_meta( $post->ID, self::META_TARGET, true ),
			'status'         => self::STATUS_PAUSED === $status ? self::STATUS_PAUSED : self::STATUS_ACTIVE,
			'apply_lists'    => self::meta_ids( $post->ID, self::META_APPLY_LISTS ),
			'apply_tags'     => self::meta_ids( $post->ID, self::META_APPLY_TAGS ),
			'remove_lists'   => self::meta_ids( $post->ID, self::META_REMOVE_LISTS ),
			'remove_tags'    => self::meta_ids( $post->ID, self::META_REMOVE_TAGS ),
			'all_clicks'     => (int) get_post_meta( $post->ID, self::META_ALL_CLICKS, true ),
			'contact_clicks' => (int) get_post_meta( $post->ID, self::META_CONTACT_CLICKS, true ),
			'short_url'      => '' !== $slug ? self::short_url( $slug ) : '',
			'created_at'     => (string) $post->post_date_gmt,
			'updated_at'     => (string) $post->post_modified_gmt,
		);
	}

	/**
	 * Link by slug, whatever its status. Callers must check the status.
	 *
	 * @return array<string, mixed>|null
	 */
	public function find_by_slug( string $slug ): ?array {
		$slug = sanitize_title( $slug );

		if ( '' === $slug ) {
			return null;
		}

		$posts = get_posts(
			array(
				'post_type'        => self::POST_TYPE,
				'post_status'      => array( 'publish' ),
				'name'             => $slug,
				'numberposts'      => 1,
				'suppress_filters' => false,
			)
		);

		return $posts ? $this->get( (int) $posts[0]->ID ) : null;
	}

	/**
	 * Links for the admin screen.
	 *
	 * @param array<string, mixed> $args search, page, per_page.
	 * @return array{items: array<int, array<string, mixed>>, total: int, page: int, per_page: int, pages: int}
	 */
	public function paginate( array $args = array() ): array {
		$search   = sanitize_text_field( (string) ( $args['search'] ?? '' ) );
		$page     = max( 1, (int) ( $args['page'] ?? 1 ) );
		$per_page = max( 1, min( 100, (int) ( $args['per_page'] ?? 20 ) ) );

		$posts = get_posts(
			array(
				'post_type'        => self::POST_TYPE,
				'post_status'      => array( 'publish' ),
				'numberposts'      => -1,
				'orderby'          => 'date',
				'order'            => 'DESC',
				'suppress_filters' => false,
			)
		);

		$items = array();

		foreach ( $posts as $post ) {
			$link = $this->get( (int) $post->ID );

			if ( ! $link ) {
				continue;
			}

			if ( '' !== $search ) {
				$haystack = $link['title'] . ' ' . $link['slug'] . ' ' . $link['target_url'];

				if ( false === mb_stripos( $haystack, $search, 0, 'UTF-8' ) ) {
					continue;
				}
			}

			$items[] = $link;
		}

		$total = count( $items );
		$pages = (int) ceil( $total / $per_page );

		return array(
			'items'    => array_slice( $items, ( $page - 1 ) * $per_page, $per_page ),
			'total'    => $total,
			'page'     => $page,
			'per_page' => $per_page,
			'pages'    => $pages,
		);
	}

	/**
	 * Create a link.
	 *
	 * @param array<string, mixed> $data title, target_url, slug, apply/remove lists and tags, status.
	 * @return int|WP_Error Post ID.
	 */
	public function create( array $data ) {
		$title = self::sanitize_title_text( (string) ( $data['title'] ?? '' ) );

		if ( '' === $title ) {
			return new WP_Error( 'nowcrm_smart_title_required', __( '請輸入智慧連結名稱。', 'now-crm' ) );
		}

		$target = self::sanitize_target( (string) ( $data['target_url'] ?? '' ) );

		if ( null === $target ) {
			return new WP_Error( 'nowcrm_smart_invalid_target', __( '目標網址必須是完整的 http 或 https 網址。', 'now-crm' ) );
		}

		$status = self::sanitize_status( (string) ( $data['status'] ?? self::STATUS_ACTIVE ) );

		if ( null === $status ) {
			return new WP_Error( 'nowcrm_smart_invalid_status', __( '連結狀態不正確。', 'now-crm' ) );
		}

		$requested = trim( (string) ( $data['slug'] ?? '' ) );

		if ( '' !== $requested ) {
			$slug = self::sanitize_slug( $requested );

			if ( null === $slug ) {
				return new WP_Error( 'nowcrm_smart_invalid_slug', __( 'Slug 只能使用小寫英數字與連字號，長度 3 到 64 個字元。', 'now-crm' ) );
			}

			if ( $this->slug_exists( $slug ) ) {
				return new WP_Error( 'nowcrm_smart_duplicate_slug', __( '這個 Slug 已被其他智慧連結使用。', 'now-crm' ) );
			}
		} else {
			$slug = $this->generate_slug();
		}

		$terms = $this->prepare_terms( $data );

		if ( is_wp_error( $terms ) ) {
			return $terms;
		}

		$post_id = wp_insert_post(
			array(
				'post_type'   => self::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => $title,
				'post_name'   => $slug,
				'post_content' => '',
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return new WP_Error( 'nowcrm_smart_db_error', __( '智慧連結建立失敗，請再試一次。', 'now-crm' ) );
		}

		update_post_meta( $post_id, self::META_TARGET, $target );
		update_post_meta( $post_id, self::META_STATUS, $status );

		foreach ( $terms as $key => $ids ) {
			update_post_meta( $post_id, $key, $ids );
		}

		return (int) $post_id;
	}

	/**
	 * Update the supplied fields only.
	 *
	 * @param array<string, mixed> $data Partial link payload.
	 * @return array<string, mixed>|WP_Error Fresh link.
	 */
	public function update( int $id, array $data ) {
		$link = $this->get( $id );

		if ( ! $link ) {
			return new WP_Error( 'nowcrm_smart_not_found', __( '找不到這個智慧連結。', 'now-crm' ) );
		}

		$post = array( 'ID' => $id );

		if ( isset( $data['title'] ) ) {
			$title = self::sanitize_title_text( (string) $data['title'] );

			if ( '' === $title ) {
				return new WP_Error( 'nowcrm_smart_title_required', __( '請輸入智慧連結名稱。', 'now-crm' ) );
			}

			$post['post_title'] = $title;
		}

		if ( isset( $data['target_url'] ) ) {
			$target = self::sanitize_target( (string) $data['target_url'] );

			if ( null === $target ) {
				return new WP_Error( 'nowcrm_smart_invalid_target', __( '目標網址必須是完整的 http 或 https 網址。', 'now-crm' ) );
			}

			$post['meta_input'][ self::META_TARGET ] = $target;
		}

		if ( isset( $data['slug'] ) && '' !== trim( (string) $data['slug'] ) ) {
			$slug = self::sanitize_slug( (string) $data['slug'] );

			if ( null === $slug ) {
				return new WP_Error( 'nowcrm_smart_invalid_slug', __( 'Slug 只能使用小寫英數字與連字號，長度 3 到 64 個字元。', 'now-crm' ) );
			}

			if ( $this->slug_exists( $slug, $id ) ) {
				return new WP_Error( 'nowcrm_smart_duplicate_slug', __( '這個 Slug 已被其他智慧連結使用。', 'now-crm' ) );
			}

			$post['post_name'] = $slug;
		}

		if ( isset( $data['status'] ) ) {
			$status = self::sanitize_status( (string) $data['status'] );

			if ( null === $status ) {
				return new WP_Error( 'nowcrm_smart_invalid_status', __( '連結狀態不正確。', 'now-crm' ) );
			}

			$post['meta_input'][ self::META_STATUS ] = $status;
		}

		$terms = $this->prepare_terms( $data, false );

		if ( is_wp_error( $terms ) ) {
			return $terms;
		}

		foreach ( $terms as $key => $ids ) {
			$post['meta_input'][ $key ] = $ids;
		}

		$updated = wp_update_post( $post, true );

		if ( is_wp_error( $updated ) ) {
			return new WP_Error( 'nowcrm_smart_db_error', __( '智慧連結儲存失敗，請再試一次。', 'now-crm' ) );
		}

		return $this->get( $id );
	}

	/**
	 * Pause or activate a link.
	 *
	 * @return bool|WP_Error
	 */
	public function set_status( int $id, string $status ) {
		if ( ! $this->get( $id ) ) {
			return new WP_Error( 'nowcrm_smart_not_found', __( '找不到這個智慧連結。', 'now-crm' ) );
		}

		$status = self::sanitize_status( $status );

		if ( null === $status ) {
			return new WP_Error( 'nowcrm_smart_invalid_status', __( '連結狀態不正確。', 'now-crm' ) );
		}

		update_post_meta( $id, self::META_STATUS, $status );

		return true;
	}

	/**
	 * Delete a link and its click log entries.
	 */
	public function delete( int $id ): bool {
		if ( ! $this->get( $id ) ) {
			return false;
		}

		$log = array_values(
			array_filter(
				$this->click_log(),
				static function ( $entry ) use ( $id ): bool {
					return (int) ( $entry['link'] ?? 0 ) !== $id;
				}
			)
		);

		update_option( self::CLICK_LOG_OPTION, $log, false );

		return (bool) wp_delete_post( $id, true );
	}

	/**
	 * Count one click. A contact ID of 0 means an anonymous visitor.
	 *
	 * @return array{all: int, contact: int}
	 */
	public function record_click( int $link_id, int $contact_id = 0 ): array {
		if ( $link_id <= 0 || ! $this->get( $link_id ) ) {
			return array(
				'all'     => 0,
				'contact' => 0,
			);
		}

		$all     = (int) get_post_meta( $link_id, self::META_ALL_CLICKS, true ) + 1;
		$contact = (int) get_post_meta( $link_id, self::META_CONTACT_CLICKS, true );

		update_post_meta( $link_id, self::META_ALL_CLICKS, $all );
		update_post_meta( $link_id, self::META_LAST_CLICK, gmdate( 'Y-m-d H:i:s' ) );

		if ( $contact_id > 0 ) {
			$contact++;
			update_post_meta( $link_id, self::META_CONTACT_CLICKS, $contact );
		}

		$this->append_click_log( $link_id, $contact_id > 0 );

		return array(
			'all'     => $all,
			'contact' => $contact,
		);
	}

	/**
	 * Recent clicks, newest first. Entries carry no contact identity and no
	 * address, so the log holds no PII.
	 *
	 * @return array<int, array{link: int, contact: bool, at: int}>
	 */
	public function click_log(): array {
		$log = get_option( self::CLICK_LOG_OPTION, array() );

		return is_array( $log ) ? array_values( $log ) : array();
	}

	/**
	 * Validate and normalize a target URL.
	 *
	 * Only absolute http(s) URLs pass. A stored value is checked again at click
	 * time, so a tampered meta row cannot become an open redirect.
	 */
	public static function sanitize_target( string $raw ): ?string {
		$raw = trim( $raw );

		if ( '' === $raw || strlen( $raw ) > self::MAX_URL_LEN ) {
			return null;
		}

		// Spaces and control characters never belong in a redirect target.
		if ( preg_match( '/[\x00-\x20\x7f]/', $raw ) ) {
			return null;
		}

		if ( ! preg_match( '#^https?://#i', $raw ) ) {
			return null;
		}

		$parts = wp_parse_url( $raw );

		if ( ! is_array( $parts ) ) {
			return null;
		}

		$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );

		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return null;
		}

		if ( empty( $parts['host'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return null;
		}

		$host = strtolower( (string) $parts['host'] );

		if ( ! preg_match( '/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$/', $host ) && ! preg_match( '/^\[[0-9a-f:]+\]$/', $host ) ) {
			return null;
		}

		$clean = esc_url_raw( $raw, array( 'http', 'https' ) );

		if ( '' === $clean ) {
			return null;
		}

		$check = wp_parse_url( $clean );

		if ( ! is_array( $check ) || ! in_array( strtolower( (string) ( $check['scheme'] ?? '' ) ), array( 'http', 'https' ), true ) || empty( $check['host'] ) ) {
			return null;
		}

		return $clean;
	}

	/**
	 * Normalize a slug, or null when it cannot be a slug.
	 */
	public static function sanitize_slug( string $raw ): ?string {
		$slug = sanitize_title( trim( $raw ) );

		if ( '' === $slug || strlen( $slug ) > self::SLUG_MAX ) {
			return null;
		}

		return preg_match( '/^[a-z0-9][a-z0-9-]{2,63}$/', $slug ) ? $slug : null;
	}

	/**
	 * Normalize a status, or null when it is unknown.
	 */
	public static function sanitize_status( string $status ): ?string {
		$status = sanitize_key( $status );

		return in_array( $status, array( self::STATUS_ACTIVE, self::STATUS_PAUSED ), true ) ? $status : null;
	}

	/**
	 * Single-line title with a fixed maximum length.
	 */
	private static function sanitize_title_text( string $title ): string {
		$title = sanitize_text_field( $title );

		return mb_substr( $title, 0, 191, 'UTF-8' );
	}

	/**
	 * Whether another link already uses the slug.
	 */
	private function slug_exists( string $slug, int $exclude_id = 0 ): bool {
		$posts = get_posts(
			array(
				'post_type'        => self::POST_TYPE,
				'post_status'      => array( 'publish', 'draft', 'pending', 'private', 'future' ),
				'name'             => $slug,
				'numberposts'      => 1,
				'post__not_in'     => $exclude_id > 0 ? array( $exclude_id ) : array(),
				'suppress_filters' => false,
			)
		);

		return array() !== $posts;
	}

	/**
	 * Random unique slug.
	 */
	private function generate_slug(): string {
		for ( $i = 0; $i < 10; $i++ ) {
			$slug = bin2hex( random_bytes( 5 ) );

			if ( ! $this->slug_exists( $slug ) ) {
				return $slug;
			}
		}

		// A duplicate here is practically impossible; the caller still gets a
		// unique insert because wp_unique_post_slug appends a suffix.
		return bin2hex( random_bytes( 5 ) );
	}

	/**
	 * Normalize the four membership lists. Unknown or wrong-type terms are
	 * rejected so a stored action never points at a missing term; the click
	 * path checks again and skips drift.
	 *
	 * @param array<string, mixed> $data        Link payload.
	 * @param bool                 $fill_missing Fill absent keys with empty arrays.
	 * @return array<string, int[]>|WP_Error
	 */
	private function prepare_terms( array $data, bool $fill_missing = true ) {
		$map = array(
			self::META_APPLY_LISTS  => array( 'apply_lists', TermRepository::TYPE_LIST ),
			self::META_APPLY_TAGS   => array( 'apply_tags', TermRepository::TYPE_TAG ),
			self::META_REMOVE_LISTS => array( 'remove_lists', TermRepository::TYPE_LIST ),
			self::META_REMOVE_TAGS  => array( 'remove_tags', TermRepository::TYPE_TAG ),
		);

		$terms = array();
		$repo  = new TermRepository();

		foreach ( $map as $meta_key => $spec ) {
			[ $field, $type ] = $spec;

			if ( ! $fill_missing && ! array_key_exists( $field, $data ) ) {
				continue;
			}

			$ids = array_slice( array_keys( array_flip( array_map( 'absint', (array) ( $data[ $field ] ?? array() ) ) ) ), 0, self::MAX_TERMS );
			$kept = array();

			foreach ( $ids as $term_id ) {
				if ( $term_id <= 0 ) {
					continue;
				}

				$term = $repo->get( (int) $term_id );

				if ( ! $term || (string) $term['type'] !== $type ) {
					return new WP_Error( 'nowcrm_smart_invalid_term', __( '選取的名單或標籤已不存在。', 'now-crm' ) );
				}

				$kept[] = (int) $term_id;
			}

			$terms[ $meta_key ] = $kept;
		}

		return $terms;
	}

	/**
	 * Stored term IDs for one meta key.
	 *
	 * @return int[]
	 */
	private static function meta_ids( int $post_id, string $key ): array {
		$value = get_post_meta( $post_id, $key, true );

		return is_array( $value ) ? array_values( array_map( 'intval', $value ) ) : array();
	}

	/**
	 * Append to the bounded click log.
	 */
	private function append_click_log( int $link_id, bool $has_contact ): void {
		$log = $this->click_log();

		array_unshift(
			$log,
			array(
				'link'    => $link_id,
				'contact' => $has_contact,
				'at'      => time(),
			)
		);

		update_option( self::CLICK_LOG_OPTION, array_slice( $log, 0, self::CLICK_LOG_LIMIT ), false );
	}
}
