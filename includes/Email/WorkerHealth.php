<?php
/**
 * Worker health: last successful queue tick and the current schedule snapshot.
 *
 * record() is called by the queue after one successful tick. status() only
 * reads; an admin render must never run or schedule the worker.
 *
 * @package NowCRM
 */

namespace NowCRM\Email;

defined( 'ABSPATH' ) || exit;

final class WorkerHealth {

	/**
	 * Last successful tick, as a UTC Unix timestamp. This is an option, not a
	 * transient, so it survives cache flushes and can be read without a write.
	 */
	public const OPTION = 'nowcrm_worker_last_success';

	/**
	 * The recurring worker runs every minute. Allow five minutes of scheduler
	 * latency before a stored next run counts as overdue.
	 */
	private const OVERDUE_GRACE = 300;

	/**
	 * Store the current UTC time after a successful queue tick.
	 */
	public static function record(): void {
		update_option( self::OPTION, time(), false );
	}

	/**
	 * Read-only snapshot for the diagnostics screen.
	 *
	 * `last_success_at` and `next_scheduled_at` are Unix timestamps or null.
	 * `scheduler` names where the next run was found, so "not scheduled" is
	 * distinct from a boolean false.
	 *
	 * `needs_review` counts failed, unknown, and suppressed rows for a human to
	 * inspect. It is a snapshot, not permission to retry; the per-row retry rule
	 * still applies (a recorded receipt or a cart-linked non-failure blocks it).
	 *
	 * @return array{
	 *     last_success_at: int|null,
	 *     next_scheduled_at: int|null,
	 *     scheduler: string,
	 *     overdue: bool,
	 *     wp_cron_disabled: bool,
	 *     due: int,
	 *     needs_review: int,
	 *     total: int
	 * }
	 */
	public static function status(): array {
		$last_success = (int) get_option( self::OPTION, 0 );
		$schedule     = self::schedule();
		$next         = $schedule['next_at'];
		$repo         = new EmailJobRepository();
		$stats        = $repo->global_stats();

		$needs_review = (int) ( $stats[ EmailJobRepository::STATUS_FAILED ] ?? 0 )
			+ (int) ( $stats[ EmailJobRepository::STATUS_UNKNOWN ] ?? 0 )
			+ (int) ( $stats[ EmailJobRepository::STATUS_SUPPRESSED ] ?? 0 );

		return array(
			'last_success_at'   => $last_success > 0 ? $last_success : null,
			'next_scheduled_at' => $next,
			'scheduler'         => $schedule['scheduler'],
			'overdue'           => null !== $next && time() - $next > self::OVERDUE_GRACE,
			'wp_cron_disabled'  => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
			'due'               => $repo->due_count(),
			'needs_review'      => $needs_review,
			'total'             => (int) ( $stats['total'] ?? 0 ),
		);
	}

	/**
	 * Next heartbeat, preferring Action Scheduler when it is loaded.
	 *
	 * @return array{scheduler: string, next_at: int|null}
	 */
	private static function schedule(): array {
		if ( function_exists( 'as_next_scheduled_action' ) ) {
			$next = as_next_scheduled_action( Queue::CRON, array(), Queue::GROUP );

			if ( is_numeric( $next ) ) {
				return array(
					'scheduler' => 'action-scheduler',
					'next_at'   => (int) $next,
				);
			}
		}

		if ( function_exists( 'wp_next_scheduled' ) ) {
			$next = wp_next_scheduled( Queue::CRON );

			if ( is_numeric( $next ) ) {
				return array(
					'scheduler' => 'wp-cron',
					'next_at'   => (int) $next,
				);
			}
		}

		return array(
			'scheduler' => 'none',
			'next_at'   => null,
		);
	}
}
