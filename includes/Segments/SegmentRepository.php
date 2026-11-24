<?php
/**
 * Saved contact segments: storage, validation, and lifecycle.
 *
 * A segment is a `nowcrm_segment` post. The name is the post title, and the
 * filter plan, description, and stable key are post meta. The filter plan uses
 * the same `{version: 1, groups: ...}` shape as AdvancedFilters: conditions
 * inside one group are AND-ed, and groups are OR-ed. Only fields that the
 * native filter catalog supports can be saved, so an unknown provider or field
 * fails validation instead of being stored and ignored.
 *
 * Segments are resolved live by SegmentResolver. Nothing stores a copy of the
 * member set, so an edit applies on the next resolution in the same request.
 * `usage()` reports consumers that store a segment reference, and `delete()`
 * refuses while any reference remains. Archive is the reversible option: an
 * archived segment keeps its definition but resolution fails closed.
 *
 * @package NowCRM
 */

namespace NowCRM\Segments;

use NowCRM\Campaign\Campaigns;
use NowCRM\Contacts\AdvancedFilters;
use WP_Error;
use WP_Post;

defined( 'ABSPATH' ) || exit;

final class SegmentRepository {

	public const POST_TYPE = 'nowcrm_segment';

	public const META_KEY         = '_nowcrm_segment_key';
	public const META_DESCRIPTION = '_nowcrm_segment_description';
	public const META_FILTERS     = '_nowcrm_segment_filters';

	public const STATUS_ACTIVE   = 'active';
	public const STATUS_ARCHIVED = 'archived';

	public const MAX_SEGMENTS    = 200;
	public const MAX_NAME        = 120;
	public const MAX_DESCRIPTION = 500;
	public const MAX_KEY         = 60;

	/**
	 * Register the private segment post type.
	 */
	public static function register_post_type(): void {
		register_post_type(
			self::POST_TYPE,
			array(
				'label'               => __( '分眾', 'now-crm' ),
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
	 * Stored segments, plus system segments, in list order.
	 *
	 * @param array<string, mixed> $args status (active|archived|all), search,
	 *                                   include_system (bool).
	 * @return array<int, array<string, mixed>>
	 */
	public function all( array $args = array() ): array {
		$args = array_merge(
			array(
				'status'         => 'all',
				'search'         => '',
				'include_system' => true,
			),
			$args
		);

		$status = in_array( $args['status'], array( 'active', 'archived' ), true )
			? (string) $args['status']
			: 'all';
		$search = trim( (string) $args['search'] );
		$items  = array();

		if ( ! empty( $args['include_system'] ) && 'archived' !== $status ) {
			foreach ( SystemSegments::all() as $segment ) {
				if ( '' === $search || $this->matches( $segment, $search ) ) {
					$items[] = $segment;
				}
			}
		}

		$posts = get_posts(
			array(
				'post_type'        => self::POST_TYPE,
				'post_status'      => array( 'publish', 'draft' ),
				'numberposts'      => self::MAX_SEGMENTS,
				'orderby'          => 'date',
				'order'            => 'DESC',
				'suppress_filters' => false,
			)
		);

		foreach ( $posts as $post ) {
			$segment = $this->shape( $post );

			if ( 'all' !== $status && $status !== $segment['status'] ) {
				continue;
			}

			if ( '' !== $search && ! $this->matches( $segment, $search ) ) {
				continue;
			}

			$items[] = $segment;
		}

		return $items;
	}

	/**
	 * One stored segment.
	 *
	 * @return array<string, mixed>|null
	 */
	public function get( int $id ): ?array {
		$post = $id > 0 ? get_post( $id ) : null;

		if ( ! $post || self::POST_TYPE !== $post->post_type || in_array( $post->post_status, array( 'trash', 'auto-draft' ), true ) ) {
			return null;
		}

		return $this->shape( $post );
	}

	/**
	 * Find a segment by reference.
	 *
	 * Accepts `custom:12`, `12`, `system:subscribed`, or `subscribed`. A bare
	 * key checks system segments first, then stored keys.
	 *
	 * @param int|string $ref Segment reference.
	 * @return array<string, mixed>|null
	 */
	public function find( int|string $ref ): ?array {
		if ( is_int( $ref ) || ( is_string( $ref ) && preg_match( '/^\d+$/', $ref ) ) ) {
			return $this->get( (int) $ref );
		}

		$ref = trim( (string) $ref );

		if ( str_starts_with( $ref, 'custom:' ) ) {
			return $this->get( (int) substr( $ref, 7 ) );
		}

		if ( str_starts_with( $ref, 'system:' ) ) {
			return SystemSegments::get( substr( $ref, 7 ) );
		}

		$system = SystemSegments::get( $ref );

		if ( null !== $system ) {
			return $system;
		}

		$id = $this->find_by_key( $ref );

		return $id > 0 ? $this->get( $id ) : null;
	}

	/**
	 * Create a segment.
	 *
	 * @param array<string, mixed> $data name, key (optional), description,
	 *                                   filters (plan or JSON).
	 * @return int|WP_Error Segment ID.
	 */
	public function create( array $data ) {
		$name = $this->clean_name( $data['name'] ?? '' );

		if ( is_wp_error( $name ) ) {
			return $name;
		}

		$description = $this->clean_description( $data['description'] ?? '' );

		if ( is_wp_error( $description ) ) {
			return $description;
		}

		$filters = $this->prepare_filters( $data['filters'] ?? null );

		if ( is_wp_error( $filters ) ) {
			return $filters;
		}

		if ( $this->custom_count() >= self::MAX_SEGMENTS ) {
			return new WP_Error( 'nowcrm_segment_limit', __( '已達分眾數量上限。', 'now-crm' ) );
		}

		if ( $this->name_exists( $name ) ) {
			return new WP_Error( 'nowcrm_segment_name_exists', __( '已有同名分眾，請換一個名稱。', 'now-crm' ) );
		}

		$key = $this->prepare_key( $data['key'] ?? '', $name );

		if ( is_wp_error( $key ) ) {
			return $key;
		}

		$id = wp_insert_post(
			array(
				'post_type'   => self::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => $name,
				'post_author' => 0,
			),
			true
		);

		if ( is_wp_error( $id ) ) {
			return $id;
		}

		$this->write_meta( (int) $id, $key, $description, $filters );

		return (int) $id;
	}

	/**
	 * Update a stored segment. Missing keys keep the stored value.
	 *
	 * @param array<string, mixed> $data Same keys as create().
	 * @return true|WP_Error
	 */
	public function update( int $id, array $data ) {
		$existing = $this->get( $id );

		if ( null === $existing ) {
			return new WP_Error( 'nowcrm_segment_not_found', __( '找不到這個分眾。', 'now-crm' ) );
		}

		$name = array_key_exists( 'name', $data )
			? $this->clean_name( $data['name'] )
			: (string) $existing['name'];

		if ( is_wp_error( $name ) ) {
			return $name;
		}

		$description = array_key_exists( 'description', $data )
			? $this->clean_description( $data['description'] )
			: (string) $existing['description'];

		if ( is_wp_error( $description ) ) {
			return $description;
		}

		$filters = array_key_exists( 'filters', $data )
			? $this->prepare_filters( $data['filters'] )
			: $existing['filters'];

		if ( is_wp_error( $filters ) ) {
			return $filters;
		}

		if ( ! is_array( $filters ) || array() === (array) ( $filters['groups'] ?? array() ) ) {
			return new WP_Error( 'nowcrm_segment_invalid', __( '這個分眾缺少有效的篩選條件，請重新儲存。', 'now-crm' ) );
		}

		if ( $this->name_exists( $name, $id ) ) {
			return new WP_Error( 'nowcrm_segment_name_exists', __( '已有同名分眾，請換一個名稱。', 'now-crm' ) );
		}

		$key = array_key_exists( 'key', $data )
			? $this->prepare_key( $data['key'], $name, $id )
			: (string) $existing['key'];

		if ( is_wp_error( $key ) ) {
			return $key;
		}

		$updated = wp_update_post(
			array(
				'ID'         => $id,
				'post_title' => $name,
			),
			true
		);

		if ( is_wp_error( $updated ) ) {
			return $updated;
		}

		$this->write_meta( $id, $key, $description, $filters );

		return true;
	}

	/**
	 * Copy a stored segment with a unique name and key.
	 *
	 * @return int|WP_Error New segment ID.
	 */
	public function duplicate( int $id ) {
		$source = $this->get( $id );

		if ( null === $source ) {
			return new WP_Error( 'nowcrm_segment_not_found', __( '找不到這個分眾。', 'now-crm' ) );
		}

		$name = $this->copy_name( (string) $source['name'] );

		return $this->create(
			array(
				'name'        => $name,
				'key'         => '',
				'description' => (string) $source['description'],
				'filters'     => $source['filters'],
			)
		);
	}

	/**
	 * Archive a stored segment. Archived segments stay listed but never resolve
	 * for campaign, export, or automation until restored.
	 *
	 * @return true|WP_Error
	 */
	public function archive( int $id ) {
		$segment = $this->get( $id );

		if ( null === $segment ) {
			return new WP_Error( 'nowcrm_segment_not_found', __( '找不到這個分眾。', 'now-crm' ) );
		}

		if ( self::STATUS_ARCHIVED === $segment['status'] ) {
			return true;
		}

		$updated = wp_update_post(
			array(
				'ID'          => $id,
				'post_status' => 'draft',
			),
			true
		);

		return is_wp_error( $updated ) ? $updated : true;
	}

	/**
	 * Restore an archived segment to active.
	 *
	 * @return true|WP_Error
	 */
	public function restore( int $id ) {
		$segment = $this->get( $id );

		if ( null === $segment ) {
			return new WP_Error( 'nowcrm_segment_not_found', __( '找不到這個分眾。', 'now-crm' ) );
		}

		if ( self::STATUS_ACTIVE === $segment['status'] ) {
			return true;
		}

		$updated = wp_update_post(
			array(
				'ID'          => $id,
				'post_status' => 'publish',
			),
			true
		);

		return is_wp_error( $updated ) ? $updated : true;
	}

	/**
	 * Delete a stored segment when no consumer still references it.
	 *
	 * @return true|WP_Error
	 */
	public function delete( int $id ) {
		$segment = $this->get( $id );

		if ( null === $segment ) {
			return new WP_Error( 'nowcrm_segment_not_found', __( '找不到這個分眾。', 'now-crm' ) );
		}

		$usage = $this->usage( $id );

		if ( array() !== $usage ) {
			return new WP_Error(
				'nowcrm_segment_in_use',
				__( '這個分眾仍被使用，請先移除引用或改為封存。', 'now-crm' ),
				array( 'usage' => $usage )
			);
		}

		return (bool) wp_delete_post( $id, true );
	}

	/**
	 * Places that still reference a stored segment.
	 *
	 * @return string[] Human readable reference labels.
	 */
	public function usage( int $id ): array {
		$map = $this->usage_map();

		return (array) apply_filters( 'nowcrm_segment_usage', $map[ $id ] ?? array(), $id, $this->get( $id ) );
	}

	/**
	 * Reference labels for every stored segment, from one campaign scan.
	 *
	 * Campaign audience rows can carry `segment` beside `list` and `tag`.
	 *
	 * @return array<int, string[]>
	 */
	public function usage_map(): array {
		$map = array();

		$campaigns = get_posts(
			array(
				'post_type'        => Campaigns::POST_TYPE,
				'post_status'      => 'any',
				'numberposts'      => -1,
				'fields'           => 'ids',
				'meta_key'         => Campaigns::META_AUDIENCE, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- bounded to campaigns.
				'suppress_filters' => false,
			)
		);

		foreach ( (array) $campaigns as $campaign_id ) {
			$campaign_id = (int) $campaign_id;
			$audience    = json_decode( (string) get_post_meta( $campaign_id, Campaigns::META_AUDIENCE, true ), true );

			if ( ! is_array( $audience ) ) {
				continue;
			}

			foreach ( array( 'include', 'exclude' ) as $group ) {
				foreach ( is_array( $audience[ $group ] ?? null ) ? $audience[ $group ] : array() as $row ) {
					$segment_id = is_array( $row ) ? absint( $row['segment'] ?? 0 ) : 0;

					if ( $segment_id > 0 ) {
						$map[ $segment_id ][ sprintf(
							/* translators: 1: campaign ID, 2: campaign title. */
							__( '電子報 #%1$d「%2$s」的收件規則', 'now-crm' ),
							$campaign_id,
							(string) get_the_title( $campaign_id )
						) ] = true;
					}
				}
			}
		}

		foreach ( $map as $segment_id => $labels ) {
			$map[ $segment_id ] = array_keys( $labels );
		}

		return $map;
	}

	/**
	 * Stored segment shape from a post.
	 *
	 * @return array<string, mixed>
	 */
	private function shape( WP_Post $post ): array {
		return array(
			'id'          => (int) $post->ID,
			'key'         => (string) get_post_meta( $post->ID, self::META_KEY, true ),
			'ref'         => 'custom:' . (int) $post->ID,
			'name'        => (string) get_the_title( $post ),
			'description' => (string) get_post_meta( $post->ID, self::META_DESCRIPTION, true ),
			'status'      => 'publish' === $post->post_status ? self::STATUS_ACTIVE : self::STATUS_ARCHIVED,
			'is_system'   => false,
			'predicate'   => null,
			'filters'     => $this->read_filters( (int) $post->ID ),
			'created_at'  => (string) $post->post_date_gmt,
			'updated_at'  => (string) $post->post_modified_gmt,
		);
	}

	/**
	 * Stored filter plan, or null when the meta is missing or not an object.
	 *
	 * @return array<string, mixed>|null
	 */
	private function read_filters( int $id ): ?array {
		$raw = get_post_meta( $id, self::META_FILTERS, true );

		if ( is_array( $raw ) ) {
			return $raw;
		}

		if ( ! is_string( $raw ) || '' === trim( $raw ) ) {
			return null;
		}

		$decoded = json_decode( $raw, true );

		return is_array( $decoded ) ? $decoded : null;
	}

	/**
	 * Save the three meta values.
	 *
	 * @param array<string, mixed> $filters Valid plan.
	 */
	private function write_meta( int $id, string $key, string $description, array $filters ): void {
		update_post_meta( $id, self::META_KEY, $key );
		update_post_meta( $id, self::META_DESCRIPTION, $description );
		update_post_meta( $id, self::META_FILTERS, (string) wp_json_encode( $filters ) );
	}

	/**
	 * Validate a name.
	 *
	 * @param mixed $raw Raw name.
	 * @return string|WP_Error
	 */
	private function clean_name( $raw ) {
		if ( ! is_scalar( $raw ) ) {
			return new WP_Error( 'nowcrm_segment_name', __( '請輸入分眾名稱。', 'now-crm' ) );
		}

		$name = trim( sanitize_text_field( (string) $raw ) );

		if ( '' === $name ) {
			return new WP_Error( 'nowcrm_segment_name', __( '請輸入分眾名稱。', 'now-crm' ) );
		}

		if ( $this->length( $name ) > self::MAX_NAME ) {
			return new WP_Error(
				'nowcrm_segment_name',
				sprintf(
					/* translators: %d: maximum name length. */
					__( '分眾名稱最多 %d 個字。', 'now-crm' ),
					self::MAX_NAME
				)
			);
		}

		return $name;
	}

	/**
	 * Validate a description.
	 *
	 * @param mixed $raw Raw description.
	 * @return string|WP_Error
	 */
	private function clean_description( $raw ) {
		if ( ! is_scalar( $raw ) ) {
			return '';
		}

		$description = trim( sanitize_textarea_field( (string) $raw ) );

		if ( $this->length( $description ) > self::MAX_DESCRIPTION ) {
			return new WP_Error(
				'nowcrm_segment_description',
				sprintf(
					/* translators: %d: maximum description length. */
					__( '分眾說明最多 %d 個字。', 'now-crm' ),
					self::MAX_DESCRIPTION
				)
			);
		}

		return $description;
	}

	/**
	 * Validate and normalize the filter plan.
	 *
	 * A custom segment must define at least one condition. An empty plan would
	 * match every contact, which is a wider result than the user asked for.
	 *
	 * @param mixed $raw Plan, JSON text, or raw groups.
	 * @return array<string, mixed>|WP_Error
	 */
	private function prepare_filters( $raw ) {
		if ( is_wp_error( $raw ) ) {
			return new WP_Error( 'nowcrm_segment_invalid', $raw->get_error_message() );
		}

		$plan = AdvancedFilters::from_json( $raw );

		if ( is_wp_error( $plan ) ) {
			return new WP_Error( 'nowcrm_segment_invalid', $plan->get_error_message() );
		}

		if ( null === $plan || array() === (array) ( $plan['groups'] ?? array() ) ) {
			return new WP_Error( 'nowcrm_segment_empty', __( '請至少加入一個篩選條件。', 'now-crm' ) );
		}

		return $plan;
	}

	/**
	 * Validate a key and make it unique.
	 *
	 * An explicit duplicate key is an error. A key derived from the name gets a
	 * numeric suffix.
	 *
	 * @param mixed  $raw     Explicit key, or empty to derive one.
	 * @param string $name    Segment name.
	 * @param int    $exclude Stored segment ID to ignore.
	 * @return string|WP_Error
	 */
	private function prepare_key( $raw, string $name, int $exclude = 0 ) {
		$explicit = is_scalar( $raw ) ? trim( (string) $raw ) : '';
		$key      = sanitize_title( '' !== $explicit ? $explicit : $name );

		if ( '' === $key ) {
			$key = 'segment';
		}

		$key = rtrim( substr( $key, 0, self::MAX_KEY ), '-' );

		if ( '' === $key ) {
			$key = 'segment';
		}

		if ( SystemSegments::is_key( $key ) ) {
			return new WP_Error( 'nowcrm_segment_key_reserved', __( '這個識別碼保留給系統分眾。', 'now-crm' ) );
		}

		if ( $this->find_by_key( $key, $exclude ) > 0 ) {
			if ( '' !== $explicit ) {
				return new WP_Error( 'nowcrm_segment_key_exists', __( '這個識別碼已被其他分眾使用。', 'now-crm' ) );
			}

			for ( $suffix = 2; $suffix <= 99; $suffix++ ) {
				$candidate = substr( $key, 0, self::MAX_KEY - strlen( (string) $suffix ) - 1 ) . '-' . $suffix;

				if ( $this->find_by_key( $candidate, $exclude ) <= 0 ) {
					return $candidate;
				}
			}

			return new WP_Error( 'nowcrm_segment_key_exists', __( '無法產生唯一的識別碼，請手動指定。', 'now-crm' ) );
		}

		return $key;
	}

	/**
	 * Unique copy name.
	 */
	private function copy_name( string $name ): string {
		$suffix = __( '（副本）', 'now-crm' );
		$base   = $this->length( $name . $suffix ) <= self::MAX_NAME
			? $name . $suffix
			: substr( $name, 0, self::MAX_NAME - $this->length( $suffix ) ) . $suffix;
		$copy   = $base;

		for ( $index = 2; $index <= 99 && $this->name_exists( $copy ); $index++ ) {
			$tail = $suffix . $index;
			$copy = $this->length( $name . $tail ) <= self::MAX_NAME
				? $name . $tail
				: substr( $name, 0, self::MAX_NAME - $this->length( $tail ) ) . $tail;
		}

		return $copy;
	}

	/**
	 * Whether a stored or system segment already uses a name.
	 */
	private function name_exists( string $name, int $exclude = 0 ): bool {
		$needle = $this->fold( $name );

		foreach ( SystemSegments::all() as $segment ) {
			if ( $this->fold( (string) $segment['name'] ) === $needle ) {
				return true;
			}
		}

		$posts = get_posts(
			array(
				'post_type'        => self::POST_TYPE,
				'post_status'      => array( 'publish', 'draft' ),
				'numberposts'      => self::MAX_SEGMENTS,
				'fields'           => 'ids',
				'suppress_filters' => false,
			)
		);

		foreach ( $posts as $post_id ) {
			if ( (int) $post_id === $exclude ) {
				continue;
			}

			if ( $this->fold( (string) get_the_title( (int) $post_id ) ) === $needle ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Stored segment ID with a key.
	 */
	private function find_by_key( string $key, int $exclude = 0 ): int {
		if ( '' === $key ) {
			return 0;
		}

		$posts = get_posts(
			array(
				'post_type'        => self::POST_TYPE,
				'post_status'      => array( 'publish', 'draft' ),
				'numberposts'      => 1,
				'fields'           => 'ids',
				'meta_key'         => self::META_KEY, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- bounded lookup.
				'meta_value'       => $key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- bounded lookup.
				'post__not_in'     => $exclude > 0 ? array( $exclude ) : array(),
				'suppress_filters' => false,
			)
		);

		return (int) ( $posts[0] ?? 0 );
	}

	/**
	 * Number of stored segments, capped at the limit.
	 */
	private function custom_count(): int {
		$posts = get_posts(
			array(
				'post_type'        => self::POST_TYPE,
				'post_status'      => array( 'publish', 'draft' ),
				'numberposts'      => self::MAX_SEGMENTS + 1,
				'fields'           => 'ids',
				'suppress_filters' => false,
			)
		);

		return count( (array) $posts );
	}

	/**
	 * Case-folded text for name comparison.
	 */
	private function fold( string $value ): string {
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $value ) : strtolower( $value );
	}

	/**
	 * Multibyte-safe length.
	 */
	private function length( string $value ): int {
		return function_exists( 'mb_strlen' ) ? mb_strlen( $value ) : strlen( $value );
	}

	/**
	 * Whether a segment matches a search term.
	 *
	 * @param array<string, mixed> $segment Segment shape.
	 */
	private function matches( array $segment, string $search ): bool {
		$needle = $this->fold( $search );

		foreach ( array( 'name', 'key', 'description' ) as $field ) {
			if ( str_contains( $this->fold( (string) ( $segment[ $field ] ?? '' ) ), $needle ) ) {
				return true;
			}
		}

		return false;
	}
}
