<?php
/**
 * Recurring campaigns: schedule settings, due runner, and child drafts.
 *
 * A parent campaign keeps its content. The runner copies that content into a
 * new draft campaign once per period. The period key is stored on the child,
 * so a period can never produce two children. The child keeps no live link to
 * the parent: later edits to either campaign do not change the other.
 *
 * @package NowCRM
 */

namespace NowCRM\Campaign;

defined( 'ABSPATH' ) || exit;

final class Recurring {

	public const HOOK  = 'nowcrm_recurring_tick';
	public const GROUP = 'nowcrm';
	public const CRON  = 'nowcrm_recurring_minute';

	public const META_STATUS     = '_nowcrm_recurring_status';
	public const META_FREQUENCY  = '_nowcrm_recurring_frequency';
	public const META_TIME       = '_nowcrm_recurring_time';
	public const META_DAY        = '_nowcrm_recurring_day';
	public const META_AUTO_SEND  = '_nowcrm_recurring_auto_send';
	public const META_NEXT_RUN   = '_nowcrm_recurring_next_run_at';
	public const META_LAST_RUN   = '_nowcrm_recurring_last_run_at';
	public const META_ERROR      = '_nowcrm_recurring_error';
	public const META_PARENT     = '_nowcrm_recurring_parent';
	public const META_SLOT       = '_nowcrm_recurring_slot';

	public const STATUS_ACTIVE   = 'active';
	public const STATUS_PAUSED   = 'paused';
	public const STATUS_INACTIVE = 'inactive';

	public const FREQUENCIES = array( 'daily', 'weekly', 'monthly' );
	public const DAYS        = array( 'mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun' );

	/** A run inside this window stays on the current slot; a later one moves on. */
	public const CUT_SECONDS = 1800;

	/** After a run, move far enough ahead that the same slot is not due again. */
	public const ADVANCE_CUT_SECONDS = 7200;

	/** Generate a little before the slot so a draft is ready to review. */
	public const LEAD_SECONDS = 2100;

	public const BATCH_LIMIT = 10;

	private static bool $running = false;

	/**
	 * Register the cron tick and the plugin lifecycle hooks.
	 */
	public function register(): void {
		add_action( self::HOOK, array( $this, 'run' ) );
		add_action( self::CRON, array( $this, 'run' ) );
		add_action( 'init', array( $this, 'ensure_scheduled' ), 23 );
		add_action( 'deactivated_plugin', array( $this, 'on_deactivate' ) );
		add_filter( 'cron_schedules', array( $this, 'cron_schedules' ) );
	}

	/**
	 * One-minute schedule for the WP-Cron fallback.
	 *
	 * @param array<string, array<string, mixed>> $schedules Schedules.
	 * @return array<string, array<string, mixed>>
	 */
	public function cron_schedules( array $schedules ): array {
		$schedules[ self::CRON ] = array(
			'interval' => MINUTE_IN_SECONDS,
			'display'  => __( 'NOW CRM 定期電子報', 'now-crm' ),
		);

		return $schedules;
	}

	/**
	 * Remove pending ticks when this plugin is deactivated.
	 *
	 * @param string $plugin Plugin basename.
	 */
	public function on_deactivate( $plugin ): void {
		if ( defined( 'NOWCRM_FILE' ) && plugin_basename( NOWCRM_FILE ) === (string) $plugin ) {
			( new self() )->unschedule();
		}
	}

	/**
	 * Cron callback: create one child per due parent.
	 *
	 * @return int Number of children created.
	 */
	public function run(): int {
		if ( self::$running || ! self::has_active() ) {
			return 0;
		}

		$lock = self::acquire_lock();
		if ( ! $lock ) {
			return 0;
		}

		self::$running = true;
		$created       = 0;

		try {
			foreach ( self::due_ids( self::batch_limit() ) as $id ) {
				try {
					if ( self::process( $id ) ) {
						++$created;
					}
				} catch ( \Throwable $exception ) {
					self::put( $id, self::META_ERROR, $exception->getMessage() ?: get_class( $exception ) );
					$settings = self::settings( $id );
					self::advance( $id, $settings );
				}
			}
		} finally {
			self::$running = false;
			self::release_lock( $lock );
		}

		return $created;
	}

	/**
	 * Whether at least one parent is active.
	 */
	public static function has_active(): bool {
		global $wpdb;

		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} active ON active.post_id = p.ID AND active.meta_key = %s AND active.meta_value = %s
				WHERE p.post_type = %s AND p.post_status NOT IN ('trash', 'auto-draft')
				LIMIT 1",
				self::META_STATUS,
				self::STATUS_ACTIVE,
				Campaigns::POST_TYPE
			)
		);
	}

	/**
	 * Schedule or clear the tick after a settings change.
	 */
	public static function sync(): void {
		$self = new self();

		if ( self::has_active() ) {
			$self->schedule();

			return;
		}

		$self->unschedule();
	}

	/**
	 * Keep the tick scheduled while a parent is active.
	 */
	public function ensure_scheduled(): void {
		if ( $this->is_scheduled() || ! self::has_active() ) {
			return;
		}

		$this->schedule();
	}

	/**
	 * Parent schedule states in display order.
	 *
	 * @return array<string, string>
	 */
	public static function status_labels(): array {
		return array(
			self::STATUS_ACTIVE   => __( '啟用中', 'now-crm' ),
			self::STATUS_PAUSED   => __( '已暫停', 'now-crm' ),
			self::STATUS_INACTIVE => __( '未啟用', 'now-crm' ),
		);
	}

	/**
	 * One page of parent campaigns, with counts for the filter tabs.
	 *
	 * Only campaigns that carry a recurring state are parents. Normal
	 * campaigns and generated children never appear here.
	 *
	 * @param string $search   Title fragment.
	 * @param string $status   Schedule state, or "all".
	 * @param int    $page     Requested page.
	 * @param int    $per_page Rows per page.
	 * @return array{items: \WP_Post[], counts: array<string, int>, total: int, page: int, pages: int}
	 */
	public static function paginate( string $search, string $status, int $page, int $per_page = 10 ): array {
		global $wpdb;

		$per_page = max( 1, $per_page );
		$counts   = self::counts( $search );
		$labels   = self::status_labels();
		$status   = in_array( $status, array_keys( $labels ), true ) ? $status : 'all';
		$total    = 'all' === $status ? $counts['all'] : (int) ( $counts[ $status ] ?? 0 );
		$pages    = (int) ceil( $total / $per_page );
		$page     = max( 1, $page );

		if ( $pages > 0 ) {
			$page = min( $page, $pages );
		} else {
			$page = 1;
		}

		$items = array();

		if ( $total > 0 ) {
			[ $from, $where, $params ] = self::parent_query( $search );

			if ( 'all' !== $status ) {
				$where   .= ' AND rs.meta_value = %s';
				$params[] = $status;
			}

			$params[] = $per_page;
			$params[] = ( $page - 1 ) * $per_page;

			$ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT p.ID {$from} {$where} ORDER BY p.post_date DESC, p.ID DESC LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Clauses are built from placeholders.
					$params
				)
			);

			foreach ( (array) $ids as $id ) {
				$post = get_post( (int) $id );

				if ( $post ) {
					$items[] = $post;
				}
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
	 * Parent campaign counts keyed by state plus "all", honoring the search.
	 *
	 * @param string $search Title fragment.
	 * @return array<string, int>
	 */
	public static function counts( string $search ): array {
		global $wpdb;

		$counts = array(
			'all'                  => 0,
			self::STATUS_ACTIVE    => 0,
			self::STATUS_PAUSED    => 0,
			self::STATUS_INACTIVE  => 0,
		);

		[ $from, $where, $params ] = self::parent_query( $search );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT rs.meta_value AS state, COUNT(*) AS total {$from} {$where} GROUP BY rs.meta_value", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Clauses are built from placeholders.
				$params
			)
		);

		foreach ( (array) $rows as $row ) {
			$state = (string) ( $row->state ?? '' );

			if ( ! array_key_exists( $state, $counts ) ) {
				continue;
			}

			$counts[ $state ]  = (int) $row->total;
			$counts['all']    += (int) $row->total;
		}

		return $counts;
	}

	/**
	 * Meta clause that hides recurring parents and children.
	 *
	 * Combine it with the caller's own meta clause under a top-level AND.
	 *
	 * @return array<int|string, mixed>
	 */
	public static function exclude_meta_query(): array {
		return array(
			'relation' => 'AND',
			array(
				'key'     => self::META_STATUS,
				'compare' => 'NOT EXISTS',
			),
			array(
				'key'     => self::META_PARENT,
				'compare' => 'NOT EXISTS',
			),
		);
	}

	/**
	 * Shared FROM and WHERE for parent listing queries.
	 *
	 * @param string $search Title fragment.
	 * @return array{0: string, 1: string, 2: array<int, mixed>}
	 */
	private static function parent_query( string $search ): array {
		global $wpdb;

		$post_type = get_post_type_object( Campaigns::POST_TYPE );
		$statuses  = array( 'draft', 'publish', 'pending', 'future' );

		if ( $post_type && current_user_can( $post_type->cap->edit_private_posts ) ) {
			$statuses[] = 'private';
		}

		$from  = "FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} rs ON rs.post_id = p.ID AND rs.meta_key = %s";
		$where = 'WHERE p.post_type = %s'
			. ' AND p.post_status IN (' . implode( ', ', array_fill( 0, count( $statuses ), '%s' ) ) . ')'
			. ' AND rs.meta_value IN (' . implode( ', ', array_fill( 0, 3, '%s' ) ) . ')';

		$params = array_merge(
			array( self::META_STATUS, Campaigns::POST_TYPE ),
			$statuses,
			array( self::STATUS_ACTIVE, self::STATUS_PAUSED, self::STATUS_INACTIVE )
		);

		if ( $post_type && ! current_user_can( $post_type->cap->edit_others_posts ) ) {
			$where   .= ' AND p.post_author = %d';
			$params[] = get_current_user_id();
		}

		if ( '' !== $search ) {
			$where   .= ' AND p.post_title LIKE %s';
			$params[] = '%' . $wpdb->esc_like( $search ) . '%';
		}

		return array( $from, $where, $params );
	}

	/**
	 * Stored settings for a parent campaign.
	 *
	 * @return array{status: string, enabled: bool, frequency: string, time: string, day: string, auto_send: bool, next_run_at: string, last_run_at: string, error: string}
	 */
	public static function settings( int $id ): array {
		$status = strtolower( (string) get_post_meta( $id, self::META_STATUS, true ) );
		if ( ! in_array( $status, array( self::STATUS_ACTIVE, self::STATUS_PAUSED, self::STATUS_INACTIVE ), true ) ) {
			$status = self::STATUS_INACTIVE;
		}

		$frequency = strtolower( (string) get_post_meta( $id, self::META_FREQUENCY, true ) );

		return array(
			'status'      => $status,
			'enabled'     => self::STATUS_INACTIVE !== $status,
			'frequency'   => in_array( $frequency, self::FREQUENCIES, true ) ? $frequency : 'daily',
			'time'        => (string) get_post_meta( $id, self::META_TIME, true ) ?: '09:00',
			'day'         => (string) get_post_meta( $id, self::META_DAY, true ),
			'auto_send'   => self::flag( get_post_meta( $id, self::META_AUTO_SEND, true ) ),
			'next_run_at' => (string) get_post_meta( $id, self::META_NEXT_RUN, true ),
			'last_run_at' => (string) get_post_meta( $id, self::META_LAST_RUN, true ),
			'error'       => (string) get_post_meta( $id, self::META_ERROR, true ),
		);
	}

	/**
	 * Validate and store schedule settings.
	 *
	 * Only activation needs a subject and a body. A paused schedule can be saved
	 * while the campaign is still empty, so the create form can preset it.
	 *
	 * @return array|\WP_Error
	 */
	public static function save( int $id, array $input ) {
		$post = Campaigns::get( $id );
		if ( ! $post ) {
			return Campaigns::error( 'not_found', __( '找不到電子報。', 'now-crm' ), 404 );
		}

		if ( '' !== (string) get_post_meta( $id, self::META_PARENT, true ) ) {
			return Campaigns::error( 'recurring_child', __( '這是定期電子報的子電子報，不能設定定期寄送。', 'now-crm' ) );
		}

		$settings = self::validate( $input );
		if ( is_wp_error( $settings ) ) {
			return $settings;
		}

		if ( self::STATUS_ACTIVE === $settings['status'] && ! self::has_content( Campaigns::snapshot( $id, Campaigns::draft( $post ) ) ) ) {
			return Campaigns::error( 'recurring_content', __( '請先填寫主旨和正文，再啟用定期寄送。', 'now-crm' ) );
		}

		self::put( $id, self::META_STATUS, $settings['status'] );
		self::put( $id, self::META_FREQUENCY, $settings['frequency'] );
		self::put( $id, self::META_TIME, $settings['time'] );
		self::put( $id, self::META_DAY, $settings['day'] );
		self::put( $id, self::META_AUTO_SEND, $settings['auto_send'] ? '1' : '' );
		self::put( $id, self::META_NEXT_RUN, $settings['enabled'] ? (string) self::next_run_at( $settings ) : '' );
		self::put( $id, self::META_ERROR, '' );

		self::sync();

		return self::settings( $id );
	}

	/**
	 * Pause generation without losing the schedule.
	 *
	 * @return array|\WP_Error
	 */
	public static function pause( int $id ) {
		return self::set_status( $id, self::STATUS_PAUSED );
	}

	/**
	 * Resume generation from the next period.
	 *
	 * @return array|\WP_Error
	 */
	public static function resume( int $id ) {
		return self::set_status( $id, self::STATUS_ACTIVE );
	}

	/**
	 * Stop recurring generation.
	 *
	 * @return array|\WP_Error
	 */
	public static function disable( int $id ) {
		return self::set_status( $id, self::STATUS_INACTIVE );
	}

	/**
	 * Change the active state and recompute the next run.
	 *
	 * @return array|\WP_Error
	 */
	public static function set_status( int $id, string $status ) {
		return self::save( $id, array_merge( self::settings( $id ), array( 'status' => $status ) ) );
	}

	/**
	 * Validate schedule settings.
	 *
	 * @param array $input Raw settings.
	 * @return array|\WP_Error Normalized settings.
	 */
	public static function validate( array $input ) {
		$frequency = strtolower( trim( self::text( $input['frequency'] ?? '' ) ) );
		if ( ! in_array( $frequency, self::FREQUENCIES, true ) ) {
			return Campaigns::error( 'recurring_frequency', __( '請選擇每日、每週或每月的寄送頻率。', 'now-crm' ) );
		}

		$time = self::parse_time( self::text( $input['time'] ?? '' ) );
		if ( ! $time ) {
			return Campaigns::error( 'recurring_time', __( '請輸入 24 小時制的寄送時間（HH:MM）。', 'now-crm' ) );
		}

		$day = '';
		if ( 'weekly' === $frequency ) {
			$day = self::weekday_key( self::text( $input['day'] ?? '' ) );
			if ( '' === $day ) {
				return Campaigns::error( 'recurring_day', __( '請選擇每週寄送的星期。', 'now-crm' ) );
			}
		} elseif ( 'monthly' === $frequency ) {
			$number = (int) self::text( $input['day'] ?? '' );
			if ( $number < 1 || $number > 31 ) {
				return Campaigns::error( 'recurring_day', __( '請選擇每月寄送的日期（1 到 31）。', 'now-crm' ) );
			}
			$day = (string) $number;
		}

		$status = strtolower( trim( self::text( $input['status'] ?? '' ) ) );
		if ( '' === $status && isset( $input['enabled'] ) ) {
			$status = self::flag( $input['enabled'] ) ? self::STATUS_ACTIVE : self::STATUS_INACTIVE;
		}
		if ( '' === $status ) {
			$status = self::STATUS_ACTIVE;
		}
		if ( ! in_array( $status, array( self::STATUS_ACTIVE, self::STATUS_PAUSED, self::STATUS_INACTIVE ), true ) ) {
			return Campaigns::error( 'recurring_status', __( '定期寄送狀態不正確。', 'now-crm' ) );
		}

		return array(
			'status'    => $status,
			'enabled'   => self::STATUS_INACTIVE !== $status,
			'frequency' => $frequency,
			'time'      => sprintf( '%02d:%02d', $time[0], $time[1] ),
			'day'       => $day,
			'auto_send' => self::flag( $input['auto_send'] ?? false ),
		);
	}

	/**
	 * Next run in UTC, or null when the settings cannot produce one.
	 *
	 * The time of day is site-local. A slot at or before the cut-off moves to the
	 * next period. A monthly day above the month length uses the last day.
	 *
	 * @param array    $settings    Schedule settings.
	 * @param int      $cut_seconds Slot lead time kept for this call.
	 * @param int|null $now         Reference timestamp. Defaults to the current time.
	 */
	public static function next_run_at( array $settings, int $cut_seconds = self::CUT_SECONDS, ?int $now = null ): ?string {
		$frequency = (string) ( $settings['frequency'] ?? '' );
		$time      = self::parse_time( (string) ( $settings['time'] ?? '' ) );

		if ( ! in_array( $frequency, self::FREQUENCIES, true ) || ! $time ) {
			return null;
		}

		$now     = null === $now ? time() : $now;
		$zone    = wp_timezone();
		$base    = ( new \DateTimeImmutable( '@' . $now ) )->setTimezone( $zone );
		$hour    = $time[0];
		$minute  = $time[1];

		if ( 'daily' === $frequency ) {
			$candidate = $base->setTime( $hour, $minute, 0 );
			if ( $candidate->getTimestamp() - $now <= $cut_seconds ) {
				$candidate = $candidate->modify( '+1 day' );
			}

			return self::utc_string( $candidate );
		}

		if ( 'weekly' === $frequency ) {
			$start = self::weekday( (string) ( $settings['day'] ?? '' ) );
			if ( ! $start ) {
				return null;
			}

			$delta     = ( $start - (int) $base->format( 'N' ) + 7 ) % 7;
			$candidate = $base->modify( '+' . $delta . ' days' )->setTime( $hour, $minute, 0 );
			if ( 0 === $delta && $candidate->getTimestamp() - $now <= $cut_seconds ) {
				$candidate = $candidate->modify( '+7 days' );
			}

			return self::utc_string( $candidate );
		}

		$day       = min( 31, max( 1, (int) ( $settings['day'] ?? 1 ) ) );
		$candidate = self::month_day( (int) $base->format( 'Y' ), (int) $base->format( 'n' ), $day, $hour, $minute, $zone );
		if ( $candidate->getTimestamp() - $now <= $cut_seconds ) {
			$next      = $base->modify( 'first day of next month' );
			$candidate = self::month_day( (int) $next->format( 'Y' ), (int) $next->format( 'n' ), $day, $hour, $minute, $zone );
		}

		return self::utc_string( $candidate );
	}

	/**
	 * Period key for a run slot: one child per day, week, or month.
	 *
	 * Weeks start on the campaign's send day, not on Monday.
	 */
	public static function period_key( array $settings, string $slot ): string {
		$timestamp = self::utc_timestamp( $slot );
		if ( ! $timestamp ) {
			return '';
		}

		$local     = ( new \DateTimeImmutable( '@' . $timestamp ) )->setTimezone( wp_timezone() );
		$frequency = (string) ( $settings['frequency'] ?? 'daily' );

		if ( 'monthly' === $frequency ) {
			return 'monthly:' . $local->format( 'Y-m' );
		}

		if ( 'weekly' === $frequency ) {
			$start = self::weekday( (string) ( $settings['day'] ?? '' ) ) ?: 1;
			$back  = ( (int) $local->format( 'N' ) - $start + 7 ) % 7;

			return 'weekly:' . $local->modify( '-' . $back . ' days' )->format( 'Y-m-d' );
		}

		return 'daily:' . $local->format( 'Y-m-d' );
	}

	/**
	 * Create the child campaign for one period.
	 *
	 * @return int|\WP_Error Child campaign ID.
	 */
	public static function generate_for( int $parent_id, array $settings, string $slot ) {
		$parent = Campaigns::get( $parent_id );
		if ( ! $parent ) {
			return Campaigns::error( 'recurring_not_found', __( '找不到定期電子報。', 'now-crm' ), 404 );
		}

		if ( empty( $settings['enabled'] ) ) {
			return Campaigns::error( 'recurring_inactive', __( '此電子報未啟用定期寄送。', 'now-crm' ) );
		}

		$key = self::period_key( $settings, $slot );
		if ( '' === $key ) {
			return Campaigns::error( 'recurring_schedule', __( '無法計算定期寄送的期間。', 'now-crm' ) );
		}

		if ( self::child_for_period( $parent_id, $key ) ) {
			return self::duplicate_error();
		}

		$review = ( new Delivery() )->review( $parent, Campaigns::revision( Campaigns::draft( $parent ) ) );
		if ( is_wp_error( $review ) ) {
			return $review;
		}
		if ( empty( $review['ready'] ) ) {
			return Campaigns::error( 'recurring_source_invalid', __( '定期電子報缺少主旨、正文或有效收件人。', 'now-crm' ) );
		}

		$result = Campaigns::transaction(
			$parent_id,
			static function ( \WP_Post $post ) use ( $parent_id, $key, $slot ) {
				// The parent row lock serializes runners, so this check is final.
				if ( self::child_for_period( $parent_id, $key ) ) {
					return self::duplicate_error();
				}

				$child_id = Campaigns::duplicate( $post );
				if ( is_wp_error( $child_id ) ) {
					return $child_id;
				}

				$child_id = (int) $child_id;
				wp_update_post(
					array(
						'ID'         => $child_id,
						'post_title' => wp_slash( self::child_title( $slot ) ),
					)
				);
				self::put( $child_id, self::META_PARENT, (string) $parent_id );
				self::put( $child_id, self::META_SLOT, $key );
				self::put( $child_id, Campaigns::META_STATUS, Campaigns::STATUS_DRAFT );

				return $child_id;
			}
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$child_id = (int) $result;

		if ( ! empty( $settings['auto_send'] ) ) {
			self::auto_deliver( $child_id, $slot );
		}

		return $child_id;
	}

	/**
	 * Create one child for the next period now. Used by the admin action.
	 *
	 * @return int|\WP_Error Child campaign ID.
	 */
	public static function generate_now( int $id ) {
		$settings = self::settings( $id );
		if ( ! $settings['enabled'] ) {
			return Campaigns::error( 'recurring_inactive', __( '請先啟用定期寄送。', 'now-crm' ) );
		}

		$slot = $settings['next_run_at'];
		if ( '' === $slot ) {
			$slot = (string) self::next_run_at( $settings );
		}
		if ( '' === $slot ) {
			return Campaigns::error( 'recurring_schedule', __( '無法計算下一次寄送時間。', 'now-crm' ) );
		}

		$result = self::generate_for( $id, $settings, $slot );
		if ( is_wp_error( $result ) ) {
			if ( 'nowcrm_recurring_duplicate' !== $result->get_error_code() ) {
				self::put( $id, self::META_ERROR, $result->get_error_message() );
			}

			return $result;
		}

		self::mark_ran( $id, $settings );

		return $result;
	}

	/**
	 * Child campaign IDs, newest first.
	 *
	 * @return int[]
	 */
	public static function children( int $parent_id, int $limit = 20 ): array {
		global $wpdb;

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} parent ON parent.post_id = p.ID AND parent.meta_key = %s AND parent.meta_value = %s
				WHERE p.post_type = %s AND p.post_status NOT IN ('trash', 'auto-draft')
				ORDER BY p.ID DESC
				LIMIT %d",
				self::META_PARENT,
				(string) $parent_id,
				Campaigns::POST_TYPE,
				max( 1, $limit )
			)
		);

		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Child count for a parent.
	 */
	public static function child_count( int $parent_id ): int {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} parent ON parent.post_id = p.ID AND parent.meta_key = %s AND parent.meta_value = %s
				WHERE p.post_type = %s AND p.post_status NOT IN ('trash', 'auto-draft')",
				self::META_PARENT,
				(string) $parent_id,
				Campaigns::POST_TYPE
			)
		);
	}

	/**
	 * Display data for the settings panel.
	 *
	 * @return array<string, mixed>
	 */
	public static function summary( int $id ): array {
		$settings = self::settings( $id );
		$latest   = self::children( $id, 1 );
		$child    = $latest ? Campaigns::get( $latest[0] ) : null;
		$state    = $child ? Campaigns::state( $child->ID ) : '';

		return $settings + array(
			'next_run_label' => '' !== $settings['next_run_at'] ? get_date_from_gmt( $settings['next_run_at'], 'Y-m-d H:i' ) : '',
			'last_run_label' => '' !== $settings['last_run_at'] ? get_date_from_gmt( $settings['last_run_at'], 'Y-m-d H:i' ) : '',
			'child_count'    => self::child_count( $id ),
			'last_child'     => $child
				? array(
					'id'           => $child->ID,
					'title'        => $child->post_title,
					'state'        => $state,
					'status_label' => Campaigns::status_label( $state ),
					'url'          => \NowCRM\Admin\CampaignPage::url( $child->ID ),
				)
				: null,
		);
	}

	/**
	 * Child campaign ID that already holds a period key, or 0.
	 */
	public static function child_for_period( int $parent_id, string $key ): int {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} parent ON parent.post_id = p.ID AND parent.meta_key = %s AND parent.meta_value = %s
				INNER JOIN {$wpdb->postmeta} slot ON slot.post_id = p.ID AND slot.meta_key = %s AND slot.meta_value = %s
				WHERE p.post_type = %s AND p.post_status NOT IN ('trash', 'auto-draft')
				LIMIT 1",
				self::META_PARENT,
				(string) $parent_id,
				self::META_SLOT,
				$key,
				Campaigns::POST_TYPE
			)
		);
	}

	/**
	 * One due parent. Returns true when a child was created.
	 */
	private static function process( int $id ): bool {
		$settings = self::settings( $id );
		if ( self::STATUS_ACTIVE !== $settings['status'] ) {
			return false;
		}

		$slot = $settings['next_run_at'];
		if ( '' === $slot ) {
			$slot = (string) self::next_run_at( $settings );
		}
		if ( '' === $slot ) {
			self::put( $id, self::META_ERROR, __( '無法計算下一次寄送時間。', 'now-crm' ) );
			self::advance( $id, $settings );

			return false;
		}

		$result = self::generate_for( $id, $settings, $slot );
		if ( is_wp_error( $result ) ) {
			// A duplicate means the period is already covered. Any other error is
			// recorded, and the slot advances so a broken parent cannot spin.
			if ( 'nowcrm_recurring_duplicate' !== $result->get_error_code() ) {
				self::put( $id, self::META_ERROR, $result->get_error_message() );
			}
			self::advance( $id, $settings );

			return false;
		}

		self::mark_ran( $id, $settings );

		return true;
	}

	/**
	 * Send or schedule the child when the parent allows it.
	 *
	 * The child stays a draft unless Delivery accepts every confirm rule.
	 */
	private static function auto_deliver( int $child_id, string $slot ): bool {
		$child = Campaigns::get( $child_id );
		if ( ! $child ) {
			return false;
		}

		$delivery = new Delivery();
		$review   = $delivery->review( $child, Campaigns::revision( Campaigns::draft( $child ) ) );

		if ( is_wp_error( $review ) || empty( $review['ready'] ) ) {
			self::put(
				$child_id,
				self::META_ERROR,
				is_wp_error( $review ) ? $review->get_error_message() : __( '缺少主旨、正文或有效收件人。', 'now-crm' )
			);

			return false;
		}

		$when = self::utc_timestamp( $slot ) > time() + MINUTE_IN_SECONDS
			? get_date_from_gmt( $slot, 'Y-m-d\TH:i' )
			: '';
		$result = $delivery->confirm( $child_id, $review['token'], $when );

		if ( is_wp_error( $result ) ) {
			self::put( $child_id, self::META_ERROR, $result->get_error_message() );

			return false;
		}

		self::put( $child_id, self::META_ERROR, '' );

		return true;
	}

	/**
	 * Record a completed run and move to the next period.
	 */
	private static function mark_ran( int $id, array $settings ): void {
		self::put( $id, self::META_LAST_RUN, gmdate( 'Y-m-d H:i:s' ) );
		self::put( $id, self::META_ERROR, '' );
		self::advance( $id, $settings );
	}

	/**
	 * Move the next run past the current period.
	 */
	private static function advance( int $id, array $settings ): void {
		self::put( $id, self::META_NEXT_RUN, (string) ( self::next_run_at( $settings, self::ADVANCE_CUT_SECONDS ) ?? '' ) );
	}

	/**
	 * Due parent IDs with the earliest slot first.
	 *
	 * @return int[]
	 */
	private static function due_ids( int $limit ): array {
		global $wpdb;

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} active ON active.post_id = p.ID AND active.meta_key = %s AND active.meta_value = %s
				INNER JOIN {$wpdb->postmeta} next_run ON next_run.post_id = p.ID AND next_run.meta_key = %s AND next_run.meta_value <> '' AND next_run.meta_value <= %s
				WHERE p.post_type = %s AND p.post_status NOT IN ('trash', 'auto-draft')
				ORDER BY next_run.meta_value ASC, p.ID ASC
				LIMIT %d",
				self::META_STATUS,
				self::STATUS_ACTIVE,
				self::META_NEXT_RUN,
				gmdate( 'Y-m-d H:i:s', time() + self::LEAD_SECONDS ),
				Campaigns::POST_TYPE,
				$limit
			)
		);

		return array_map( 'intval', (array) $ids );
	}

	private static function batch_limit(): int {
		return max( 1, (int) apply_filters( 'nowcrm_recurring_batch_limit', self::BATCH_LIMIT ) );
	}

	/** Connection-scoped lock so one runner generates at a time. */
	private static function acquire_lock(): ?string {
		global $wpdb;

		$key = 'nowcrm-recurring-' . md5( DB_NAME . ':' . $wpdb->prefix );

		return '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $key ) ) ? $key : null;
	}

	private static function release_lock( string $key ): void {
		global $wpdb;
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $key ) );
	}

	private function is_scheduled(): bool {
		if ( function_exists( 'as_next_scheduled_action' ) && did_action( 'action_scheduler_init' ) ) {
			return false !== as_next_scheduled_action( self::HOOK, array(), self::GROUP );
		}

		return false !== wp_next_scheduled( self::HOOK );
	}

	private function schedule(): void {
		if ( $this->is_scheduled() ) {
			return;
		}

		if ( function_exists( 'as_schedule_recurring_action' ) && did_action( 'action_scheduler_init' ) ) {
			as_schedule_recurring_action( time() + 60, MINUTE_IN_SECONDS, self::HOOK, array(), self::GROUP, true );

			return;
		}

		wp_schedule_event( time() + 60, self::CRON, self::HOOK );
	}

	private function unschedule(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::HOOK, array(), self::GROUP );
		}

		wp_clear_scheduled_hook( self::HOOK );
	}

	private static function duplicate_error(): \WP_Error {
		return Campaigns::error( 'recurring_duplicate', __( '這個期間的電子報已建立。', 'now-crm' ), 409 );
	}

	private static function child_title( string $slot ): string {
		/* translators: %s: scheduled send time */
		return sprintf( __( '定期電子報 %s', 'now-crm' ), get_date_from_gmt( $slot, 'Y-m-d H:i' ) );
	}

	/**
	 * Parse HH:MM, with optional :SS.
	 *
	 * @return array{0:int,1:int}|null
	 */
	private static function parse_time( string $value ): ?array {
		if ( 1 !== preg_match( '/^([01]?\d|2[0-3]):([0-5]\d)(?::[0-5]\d)?$/', trim( $value ), $match ) ) {
			return null;
		}

		return array( (int) $match[1], (int) $match[2] );
	}

	private static function weekday_key( string $value ): string {
		$value = strtolower( trim( $value ) );
		if ( in_array( $value, self::DAYS, true ) ) {
			return $value;
		}

		$full = array(
			'monday'    => 'mon',
			'tuesday'   => 'tue',
			'wednesday' => 'wed',
			'thursday'  => 'thu',
			'friday'    => 'fri',
			'saturday'  => 'sat',
			'sunday'    => 'sun',
		);
		if ( isset( $full[ $value ] ) ) {
			return $full[ $value ];
		}

		$number = (int) $value;

		return $number >= 1 && $number <= 7 ? self::DAYS[ $number - 1 ] : '';
	}

	private static function weekday( string $day ): int {
		$key  = self::weekday_key( $day );
		$index = '' === $key ? false : array_search( $key, self::DAYS, true );

		return false === $index ? 0 : $index + 1;
	}

	private static function month_day( int $year, int $month, int $day, int $hour, int $minute, \DateTimeZone $zone ): \DateTimeImmutable {
		$days = (int) ( new \DateTimeImmutable( sprintf( '%04d-%02d-01', $year, $month ), $zone ) )->format( 't' );
		$day  = min( $day, $days );

		return new \DateTimeImmutable( sprintf( '%04d-%02d-%02d %02d:%02d:00', $year, $month, $day, $hour, $minute ), $zone );
	}

	private static function utc_string( \DateTimeImmutable $date ): string {
		return gmdate( 'Y-m-d H:i:s', $date->getTimestamp() );
	}

	private static function utc_timestamp( string $value ): int {
		$date = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $value, new \DateTimeZone( 'UTC' ) );

		return $date ? $date->getTimestamp() : 0;
	}

	private static function flag( $value ): bool {
		if ( ! is_scalar( $value ) ) {
			return false;
		}

		return true === $value || 1 === $value || in_array( strtolower( (string) $value ), array( '1', 'yes', 'true' ), true );
	}

	/** String view of a raw input value, or an empty string. */
	private static function text( $value ): string {
		return is_scalar( $value ) ? (string) $value : '';
	}

	private static function has_content( array $snapshot ): bool {
		$html = (string) ( $snapshot['content'] ?? '' );

		return '' !== trim( (string) ( $snapshot['subject'] ?? '' ) ) && (
			1 === preg_match( '/[^\s\p{Z}]/u', html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) ||
			1 === preg_match( '/<img\b[^>]+\bsrc\s*=/i', $html )
		);
	}

	private static function put( int $id, string $key, string $value ): void {
		if ( '' === $value ) {
			delete_post_meta( $id, $key );

			return;
		}

		update_post_meta( $id, $key, $value );
	}
}
