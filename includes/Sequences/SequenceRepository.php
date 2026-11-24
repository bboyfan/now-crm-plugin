<?php
/**
 * Sequence definitions: a private `nowcrm_sequence` post plus ordered step meta.
 *
 * Status lives in post meta, not post_status: the CRM owns draft, active and
 * paused states. Steps are validated and sorted by delay before storage.
 *
 * @package NowCRM
 */

namespace NowCRM\Sequences;

use WP_Error;

defined( 'ABSPATH' ) || exit;

final class SequenceRepository {

	public const POST_TYPE = 'nowcrm_sequence';

	public const STATUS_DRAFT  = 'draft';
	public const STATUS_ACTIVE = 'active';
	public const STATUS_PAUSED = 'paused';

	public const META_STATUS  = '_nowcrm_sequence_status';
	public const META_STEPS   = '_nowcrm_sequence_steps';
	public const META_AUDIENCE = '_nowcrm_sequence_audience';
	public const META_REENTRY = '_nowcrm_sequence_allow_reentry';

	public const MAX_SEQUENCES    = 200;
	public const MAX_STEPS        = 20;
	public const MAX_NAME         = 191;
	public const MAX_SUBJECT      = 255;
	public const MAX_PREHEADER    = 255;
	public const MAX_CONTENT      = 524288;
	public const MAX_DELAY_SECONDS = 31536000;

	private AudienceResolver $audience;

	public function __construct( ?AudienceResolver $audience = null ) {
		$this->audience = $audience ?? new AudienceResolver();
	}

	/**
	 * Register the private sequence post type.
	 */
	public static function register_post_type(): void {
		register_post_type(
			self::POST_TYPE,
			array(
				'label'               => __( '電子報序列', 'now-crm' ),
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
	 * One sequence in its public shape.
	 *
	 * @return array<string, mixed>|null
	 */
	public function get( int $id ): ?array {
		$post = $id > 0 ? get_post( $id ) : null;

		if ( ! $post || self::POST_TYPE !== $post->post_type || in_array( $post->post_status, array( 'trash', 'auto-draft' ), true ) ) {
			return null;
		}

		$status = (string) get_post_meta( $id, self::META_STATUS, true );

		if ( ! in_array( $status, array( self::STATUS_DRAFT, self::STATUS_ACTIVE, self::STATUS_PAUSED ), true ) ) {
			$status = self::STATUS_DRAFT;
		}

		$steps = json_decode( (string) get_post_meta( $id, self::META_STEPS, true ), true );
		$steps = is_array( $steps ) ? array_values( array_filter( $steps, 'is_array' ) ) : array();

		$audience = json_decode( (string) get_post_meta( $id, self::META_AUDIENCE, true ), true );

		return array(
			'id'            => $id,
			'name'          => $post->post_title,
			'status'        => $status,
			'audience'      => is_array( $audience ) ? $audience : array( 'type' => AudienceResolver::TYPE_ALL ),
			'allow_reentry' => '1' === (string) get_post_meta( $id, self::META_REENTRY, true ),
			'steps'         => $steps,
			'created_at'    => $post->post_date_gmt,
			'updated_at'    => $post->post_modified_gmt,
		);
	}

	/**
	 * Stored sequences, newest first.
	 *
	 * @param array<string, mixed> $args status (draft|active|paused|all), limit.
	 * @return array<int, array<string, mixed>>
	 */
	public function all( array $args = array() ): array {
		$args = array_merge(
			array(
				'status' => 'all',
				'limit'  => self::MAX_SEQUENCES,
			),
			$args
		);

		$status = (string) $args['status'];
		$limit  = max( 1, min( self::MAX_SEQUENCES, (int) $args['limit'] ) );

		$posts = get_posts(
			array(
				'post_type'        => self::POST_TYPE,
				'post_status'      => array( 'publish', 'draft' ),
				'numberposts'      => $limit,
				'orderby'          => 'date',
				'order'            => 'DESC',
				'suppress_filters' => false,
			)
		);

		$items = array();

		foreach ( $posts as $post ) {
			$sequence = $this->get( (int) $post->ID );

			if ( ! $sequence ) {
				continue;
			}

			if ( 'all' !== $status && $status !== $sequence['status'] ) {
				continue;
			}

			$items[] = $sequence;
		}

		return $items;
	}

	/**
	 * Paged sequence catalog with search, status facet counts, and a clamped page.
	 *
	 * `draft` includes sequences with a missing or unrecognized status meta,
	 * matching the effective status returned by get().
	 *
	 * @param string $search   Title search text.
	 * @param string $status   all|draft|active|paused.
	 * @param int    $page     Requested page (clamped after counting).
	 * @param int    $per_page Rows per page.
	 * @return array{items: array<int, array<string, mixed>>, counts: array<string, int>, total: int, page: int, pages: int}
	 */
	public function catalog( string $search, string $status, int $page, int $per_page ): array {
		$per_page = max( 1, min( self::MAX_SEQUENCES, $per_page ) );
		$search   = trim( $search );
		$status   = sanitize_key( $status );

		if ( ! in_array( $status, array( self::STATUS_DRAFT, self::STATUS_ACTIVE, self::STATUS_PAUSED ), true ) ) {
			$status = 'all';
		}

		$base = array(
			'post_type'        => self::POST_TYPE,
			'post_status'      => array( 'publish', 'draft' ),
			'suppress_filters' => false,
		);

		if ( '' !== $search ) {
			$base['s'] = $search;
		}

		$counts = array(
			'all'    => $this->catalog_count( $base ),
			'active' => $this->catalog_count( $base, $this->catalog_meta_query( self::STATUS_ACTIVE ) ),
			'paused' => $this->catalog_count( $base, $this->catalog_meta_query( self::STATUS_PAUSED ) ),
		);

		$counts['draft'] = max( 0, $counts['all'] - $counts['active'] - $counts['paused'] );

		$total = (int) ( $counts[ $status ] ?? $counts['all'] );
		$pages = max( 1, (int) ceil( $total / $per_page ) );
		$page  = min( max( 1, $page ), $pages );

		$args = array_merge(
			$base,
			array(
				'posts_per_page' => $per_page,
				'paged'          => $page,
				'orderby'        => array(
					'date' => 'DESC',
					'ID'   => 'DESC',
				),
				'no_found_rows'  => true,
			)
		);

		if ( 'all' !== $status ) {
			$args['meta_query'] = $this->catalog_meta_query( $status );
		}

		$query = new \WP_Query( $args );
		$items = array();

		foreach ( $query->posts as $post ) {
			$sequence = $this->get( (int) $post->ID );

			if ( $sequence ) {
				$items[] = $sequence;
			}
		}

		return array(
			'items'  => $items,
			'counts' => $counts,
			'total'  => $total,
			'page'   => $page,
			'pages'  => $pages,
		);
	}

	/**
	 * Count matching sequences for one catalog facet.
	 *
	 * @param array<string, mixed>      $args       Base query args.
	 * @param array<int, array<string, string>>|null $meta_query Status clause, or null for all.
	 */
	private function catalog_count( array $args, ?array $meta_query = null ): int {
		$args['posts_per_page']         = 1;
		$args['paged']                  = 1;
		$args['fields']                 = 'ids';
		$args['no_found_rows']          = false;
		$args['update_post_meta_cache'] = false;
		$args['update_post_term_cache'] = false;

		if ( null !== $meta_query ) {
			$args['meta_query'] = $meta_query;
		}

		$query = new \WP_Query( $args );

		return (int) $query->found_posts;
	}

	/**
	 * Status clause for catalog items and counts.
	 *
	 * @return array<int, array<string, string>>
	 */
	private function catalog_meta_query( string $status ): array {
		if ( self::STATUS_DRAFT !== $status ) {
			return array(
				array(
					'key'   => self::META_STATUS,
					'value' => $status,
				),
			);
		}

		return array(
			'relation' => 'OR',
			array(
				'key'   => self::META_STATUS,
				'value' => self::STATUS_DRAFT,
			),
			array(
				'key'     => self::META_STATUS,
				'compare' => 'NOT EXISTS',
			),
			array(
				'key'     => self::META_STATUS,
				'value'   => array( self::STATUS_ACTIVE, self::STATUS_PAUSED ),
				'compare' => 'NOT IN',
				'type'    => 'CHAR',
			),
		);
	}

	/**
	 * Whether any sequence is active.
	 */
	public function has_active(): bool {
		$ids = get_posts(
			array(
				'post_type'        => self::POST_TYPE,
				'post_status'      => array( 'publish', 'draft' ),
				'numberposts'      => 1,
				'fields'           => 'ids',
				'meta_key'         => self::META_STATUS, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- bounded lookup.
				'meta_value'       => self::STATUS_ACTIVE, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- bounded lookup.
				'suppress_filters' => false,
			)
		);

		return array() !== $ids;
	}

	/**
	 * Create a sequence from a validated definition.
	 *
	 * @param array<string, mixed> $input name, status, audience, allow_reentry, steps.
	 * @return int|WP_Error
	 */
	public function create( array $input ) {
		$definition = $this->normalize_definition( $input );

		if ( is_wp_error( $definition ) ) {
			return $definition;
		}

		$id = wp_insert_post(
			array(
				'post_type'   => self::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => wp_slash( $definition['name'] ),
				'post_author' => get_current_user_id(),
			),
			true
		);

		if ( is_wp_error( $id ) || ! $id ) {
			return $this->storage_error( $id );
		}

		$id = (int) $id;

		$this->write_meta( $id, $definition );

		Scheduler::sync();

		return $id;
	}

	/**
	 * Replace a sequence definition.
	 *
	 * @param array<string, mixed> $input Same keys as create().
	 * @return true|WP_Error
	 */
	public function update( int $id, array $input ) {
		$existing = $this->get( $id );

		if ( ! $existing ) {
			return new WP_Error( 'nowcrm_sequence_not_found', __( '找不到這個序列。', 'now-crm' ) );
		}

		$definition = $this->normalize_definition( $input );

		if ( is_wp_error( $definition ) ) {
			return $definition;
		}

		$updated = wp_update_post(
			array(
				'ID'         => $id,
				'post_title' => wp_slash( $definition['name'] ),
			),
			true
		);

		if ( is_wp_error( $updated ) || ! $updated ) {
			return $this->storage_error( $updated );
		}

		$this->write_meta( $id, $definition );

		if ( self::STATUS_ACTIVE === $definition['status'] ) {
			( new TrackerRepository() )->wake( $id );
		}

		Scheduler::sync();

		return true;
	}

	/**
	 * Change only the lifecycle state.
	 *
	 * @return true|WP_Error
	 */
	public function set_status( int $id, string $status ) {
		$sequence = $this->get( $id );

		if ( ! $sequence ) {
			return new WP_Error( 'nowcrm_sequence_not_found', __( '找不到這個序列。', 'now-crm' ) );
		}

		$status = sanitize_key( $status );

		if ( ! in_array( $status, array( self::STATUS_DRAFT, self::STATUS_ACTIVE, self::STATUS_PAUSED ), true ) ) {
			return new WP_Error( 'nowcrm_sequence_status', __( '序列狀態不正確。', 'now-crm' ) );
		}

		if ( self::STATUS_ACTIVE === $status && array() === $sequence['steps'] ) {
			return new WP_Error( 'nowcrm_sequence_steps', __( '啟用序列前請至少加入一個步驟。', 'now-crm' ) );
		}

		if ( self::STATUS_ACTIVE === $status ) {
			$audience = ( new AudienceResolver() )->normalize( $sequence['audience'] );

			if ( is_wp_error( $audience ) ) {
				return $audience;
			}
		}

		update_post_meta( $id, self::META_STATUS, $status );

		if ( self::STATUS_ACTIVE === $status ) {
			( new TrackerRepository() )->wake( $id );
		}

		Scheduler::sync();

		return true;
	}

	/**
	 * Copy a sequence into a new draft.
	 *
	 * @return int|WP_Error
	 */
	public function duplicate( int $id ) {
		$sequence = $this->get( $id );

		if ( ! $sequence ) {
			return new WP_Error( 'nowcrm_sequence_not_found', __( '找不到這個序列。', 'now-crm' ) );
		}

		return $this->create(
			array(
				'name'          => mb_substr( (string) $sequence['name'] . '（複製）', 0, self::MAX_NAME ),
				'status'        => self::STATUS_DRAFT,
				'audience'      => $sequence['audience'],
				'allow_reentry' => $sequence['allow_reentry'],
				'steps'         => array_map(
					static function ( array $step ): array {
						unset( $step['uuid'] );

						return $step;
					},
					$sequence['steps']
				),
			)
		);
	}

	/**
	 * Delete a sequence after cancelling its active enrollments.
	 *
	 * @param bool $force Skip the trash.
	 * @return bool|WP_Error
	 */
	public function delete( int $id, bool $force = false ) {
		$sequence = $this->get( $id );

		if ( ! $sequence ) {
			return new WP_Error( 'nowcrm_sequence_not_found', __( '找不到這個序列。', 'now-crm' ) );
		}

		( new Engine() )->cancel( $id );

		if ( $force ) {
			( new TrackerRepository() )->delete_for_sequence( $id );
		}

		$deleted = wp_delete_post( $id, $force );

		if ( ! $deleted ) {
			return new WP_Error( 'nowcrm_sequence_delete_failed', __( '無法刪除這個序列。', 'now-crm' ) );
		}

		Scheduler::sync();

		return true;
	}

	/**
	 * Eligible audience size, after status and suppression gates.
	 *
	 * @return int|WP_Error
	 */
	public function members( int $id ) {
		$sequence = $this->get( $id );

		if ( ! $sequence ) {
			return new WP_Error( 'nowcrm_sequence_not_found', __( '找不到這個序列。', 'now-crm' ) );
		}

		$audience = $this->audience->normalize( $sequence['audience'] );

		if ( is_wp_error( $audience ) ) {
			return $audience;
		}

		return $this->audience->count( $audience );
	}

	/**
	 * Validate and canonicalize a definition.
	 *
	 * @param array<string, mixed> $input Raw definition.
	 * @return array<string, mixed>|WP_Error
	 */
	public function normalize_definition( array $input ) {
		$name = trim( sanitize_text_field( (string) ( $input['name'] ?? '' ) ) );

		if ( '' === $name ) {
			return new WP_Error( 'nowcrm_sequence_name', __( '請輸入序列名稱。', 'now-crm' ) );
		}

		if ( mb_strlen( $name, 'UTF-8' ) > self::MAX_NAME ) {
			return new WP_Error( 'nowcrm_sequence_name', __( '序列名稱過長。', 'now-crm' ) );
		}

		$status = sanitize_key( (string) ( $input['status'] ?? self::STATUS_DRAFT ) );

		if ( ! in_array( $status, array( self::STATUS_DRAFT, self::STATUS_ACTIVE, self::STATUS_PAUSED ), true ) ) {
			return new WP_Error( 'nowcrm_sequence_status', __( '序列狀態不正確。', 'now-crm' ) );
		}

		$audience = $this->audience->normalize( $input['audience'] ?? array( 'type' => AudienceResolver::TYPE_ALL ) );

		if ( is_wp_error( $audience ) ) {
			return $audience;
		}

		$steps = $this->normalize_steps( $input['steps'] ?? array() );

		if ( is_wp_error( $steps ) ) {
			return $steps;
		}

		return array(
			'name'          => $name,
			'status'        => $status,
			'audience'      => $audience,
			'allow_reentry' => ! empty( $input['allow_reentry'] ),
			'steps'         => $steps,
		);
	}

	/**
	 * Resolve a posted delay from unit fields or the legacy seconds field.
	 *
	 * The editor posts value, unit, and a seconds remainder. Legacy callers
	 * post only delay_seconds. Both paths preserve exact seconds.
	 *
	 * @param array<string, mixed> $row Raw step row.
	 * @return int|WP_Error
	 */
	private function normalize_delay( array $row ) {
		$has_parts = isset( $row['delay_value'] ) || isset( $row['delay_unit'] ) || isset( $row['delay_seconds_part'] );

		if ( $has_parts ) {
			$value     = $row['delay_value'] ?? 0;
			$remainder = $row['delay_seconds_part'] ?? 0;
			$unit      = sanitize_key( (string) ( $row['delay_unit'] ?? 'minutes' ) );
			$units     = array(
				'minutes' => MINUTE_IN_SECONDS,
				'hours'   => HOUR_IN_SECONDS,
				'days'    => DAY_IN_SECONDS,
				'seconds' => 1,
			);

			if ( is_scalar( $value ) && '' === (string) $value ) {
				$value = 0;
			}

			if ( is_scalar( $remainder ) && '' === (string) $remainder ) {
				$remainder = 0;
			}

			if ( ! isset( $units[ $unit ] ) || ! is_numeric( $value ) || ! is_numeric( $remainder ) ) {
				return new WP_Error( 'nowcrm_sequence_delay', __( '步驟延遲必須是有效的時間。', 'now-crm' ) );
			}

			$value     = (int) $value;
			$remainder = (int) $remainder;

			if ( $value < 0 || $remainder < 0 || $remainder > 59 ) {
				return new WP_Error( 'nowcrm_sequence_delay', __( '步驟延遲超出允許範圍。', 'now-crm' ) );
			}

			$delay = $value * $units[ $unit ] + $remainder;
		} else {
			$delay_raw = $row['delay_seconds'] ?? 0;

			if ( ! is_numeric( $delay_raw ) ) {
				return new WP_Error( 'nowcrm_sequence_delay', __( '步驟延遲必須是秒數。', 'now-crm' ) );
			}

			$delay = (int) $delay_raw;
		}

		if ( $delay < 0 || $delay > self::MAX_DELAY_SECONDS ) {
			return new WP_Error( 'nowcrm_sequence_delay', __( '步驟延遲超出允許範圍。', 'now-crm' ) );
		}

		return $delay;
	}

	/**
	 * Reuse a posted step UUID or create one for a new step.
	 *
	 * The UUID travels with the step card, so renames, delay edits, and
	 * reordering keep the same attribution key.
	 *
	 * @param mixed $value Posted UUID.
	 */
	private function normalize_step_uuid( $value ): string {
		$value = is_string( $value ) ? strtolower( trim( $value ) ) : '';

		if ( preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $value ) ) {
			return $value;
		}

		return wp_generate_uuid4();
	}

	/**
	 * Validate, trim, and order the steps by delay.
	 *
	 * @param mixed $raw Step list.
	 * @return array<int, array<string, mixed>>|WP_Error
	 */
	private function normalize_steps( $raw ) {
		if ( ! is_array( $raw ) || array() === $raw ) {
			return new WP_Error( 'nowcrm_sequence_steps', __( '序列至少需要一個步驟。', 'now-crm' ) );
		}

		if ( count( $raw ) > self::MAX_STEPS ) {
			return new WP_Error(
				'nowcrm_sequence_steps',
				sprintf(
					/* translators: %d: maximum number of steps. */
					__( '序列最多只能有 %d 個步驟。', 'now-crm' ),
					self::MAX_STEPS
				)
			);
		}

		$steps = array();
		$index = 0;

		foreach ( array_values( $raw ) as $row ) {
			++$index;

			if ( ! is_array( $row ) ) {
				return new WP_Error( 'nowcrm_sequence_steps', __( '序列步驟格式不正確。', 'now-crm' ) );
			}

			$subject = trim( sanitize_text_field( (string) ( $row['subject'] ?? '' ) ) );

			if ( '' === $subject ) {
				return new WP_Error(
					'nowcrm_sequence_subject',
					sprintf(
						/* translators: %d: step number. */
						__( '第 %d 個步驟缺少主旨。', 'now-crm' ),
						$index
					)
				);
			}

			if ( mb_strlen( $subject, 'UTF-8' ) > self::MAX_SUBJECT ) {
				return new WP_Error( 'nowcrm_sequence_subject', __( '步驟主旨過長。', 'now-crm' ) );
			}

			$preheader = trim( sanitize_text_field( (string) ( $row['preheader'] ?? '' ) ) );

			if ( mb_strlen( $preheader, 'UTF-8' ) > self::MAX_PREHEADER ) {
				return new WP_Error( 'nowcrm_sequence_preheader', __( '步驟 Preheader 過長。', 'now-crm' ) );
			}

			$mode = sanitize_key( (string) ( $row['mode'] ?? 'html' ) );

			if ( ! in_array( $mode, array( 'html', 'blocks' ), true ) ) {
				return new WP_Error( 'nowcrm_sequence_mode', __( '步驟內容格式不正確。', 'now-crm' ) );
			}

			$content = (string) ( $row['content'] ?? '' );

			if ( '' === trim( $content ) ) {
				return new WP_Error( 'nowcrm_sequence_content', __( '步驟內容不能空白。', 'now-crm' ) );
			}

			if ( strlen( $content ) > self::MAX_CONTENT ) {
				return new WP_Error( 'nowcrm_sequence_content', __( '步驟內容過長。', 'now-crm' ) );
			}

			$delay = $this->normalize_delay( $row );

			if ( is_wp_error( $delay ) ) {
				return $delay;
			}

			$step_name = trim( sanitize_text_field( (string) ( $row['name'] ?? '' ) ) );

			if ( '' === $step_name ) {
				$step_name = sprintf(
					/* translators: %d: step number. */
					__( '步驟 %d', 'now-crm' ),
					$index
				);
			}

			$steps[] = array(
				'name'          => $step_name,
				'subject'       => $subject,
				'preheader'     => $preheader,
				'mode'          => $mode,
				'content'       => $content,
				'delay_seconds' => $delay,
				'uuid'          => $this->normalize_step_uuid( $row['uuid'] ?? '' ),
				'order'         => $index,
			);
		}

		usort(
			$steps,
			static function ( array $left, array $right ): int {
				return $left['delay_seconds'] <=> $right['delay_seconds'] ?: $left['order'] <=> $right['order'];
			}
		);

		return array_map(
			static function ( array $step ): array {
				unset( $step['order'] );

				return $step;
			},
			$steps
		);
	}

	/**
	 * Persist the definition meta.
	 *
	 * @param array<string, mixed> $definition Normalized definition.
	 */
	private function write_meta( int $id, array $definition ): void {
		update_post_meta( $id, self::META_STATUS, $definition['status'] );
		update_post_meta( $id, self::META_STEPS, wp_slash( (string) wp_json_encode( $definition['steps'] ) ) );
		update_post_meta( $id, self::META_AUDIENCE, wp_slash( (string) wp_json_encode( $definition['audience'] ) ) );
		update_post_meta( $id, self::META_REENTRY, $definition['allow_reentry'] ? '1' : '0' );
	}

	/**
	 * Normalize a storage failure.
	 *
	 * @param mixed $fallback Insert result or existing error.
	 */
	private function storage_error( $fallback = null ): WP_Error {
		if ( is_wp_error( $fallback ) ) {
			return $fallback;
		}

		return new WP_Error( 'nowcrm_sequence_save_failed', __( '無法儲存序列。', 'now-crm' ) );
	}
}
