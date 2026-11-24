<?php
/**
 * Cart state machine sweeper: active → abandoned → lost, and reminder
 * enqueueing. Runs every minute through Action Scheduler or WP-Cron.
 *
 * @package NowCRM
 */

namespace NowCRM\Cart;

use NowCRM\Support\Settings;

defined( 'ABSPATH' ) || exit;

final class Sweeper {

	public const HOOK = 'nowcrm_cart_sweep';

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_action( self::HOOK, array( $this, 'run' ) );
		add_action( 'init', array( $this, 'ensure_scheduled' ), 21 );
		add_filter( 'cron_schedules', array( $this, 'cron_schedules' ) );
	}

	/**
	 * One-minute schedule used by the WP-Cron fallback.
	 *
	 * @param array<string, array<string, mixed>> $schedules Schedules.
	 * @return array<string, array<string, mixed>>
	 */
	public function cron_schedules( array $schedules ): array {
		$schedules['nowcrm_minute'] = array(
			'interval' => MINUTE_IN_SECONDS,
			'display'  => __( 'NOW CRM 每分鐘', 'now-crm' ),
		);

		return $schedules;
	}

	/**
	 * Ensure a recurring sweep exists.
	 */
	public function ensure_scheduled(): void {
		if ( ! Settings::bool( 'abandoned_cart_enabled' ) ) {
			return;
		}

		if ( function_exists( 'as_next_scheduled_action' ) && function_exists( 'as_schedule_recurring_action' ) ) {
			if ( ! as_next_scheduled_action( self::HOOK, array(), 'nowcrm' ) ) {
				as_schedule_recurring_action( time() + 30, MINUTE_IN_SECONDS, self::HOOK, array(), 'nowcrm' );
			}

			return;
		}

		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + 30, 'nowcrm_minute', self::HOOK );
		}
	}

	/**
	 * One sweep.
	 *
	 * @return array{abandoned: int, reminders: int, lost: int}
	 */
	public function run(): array {
		$result = array(
			'abandoned' => 0,
			'reminders' => 0,
			'lost'      => 0,
		);

		if ( ! Settings::bool( 'abandoned_cart_enabled' ) ) {
			return $result;
		}

		$repo   = new CartRepository();
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - max( 1, Settings::int( 'cart_idle_minutes' ) ) * MINUTE_IN_SECONDS );

		// mark_abandoned() owns the compare-and-set and the state event. Only
		// the winning sweep fires the single cart-abandoned hook.
		foreach ( $repo->due_for_abandon( Settings::int( 'cart_idle_minutes' ) ) as $cart ) {
			if ( $repo->mark_abandoned( (int) $cart['id'], (int) $cart['version'], $cutoff ) ) {
				++$result['abandoned'];

				do_action( 'nowcrm_cart_abandoned', (int) $cart['id'], (string) $cart['email'] );
			}
		}

		// Durable due reconciliation: the same automation owner starts the run
		// for carts whose transition event was missed or replayed. Never enqueue
		// a second reminder path here.
		foreach ( $repo->due_for_reminder() as $cart ) {
			if ( Automation::dispatch_due( $cart ) ) {
				++$result['reminders'];
			}
		}

		// mark_lost() owns the transition and cancels every open run and
		// queued job for that cart, so the sweeper never cancels twice.
		foreach ( $repo->due_for_lost( Settings::int( 'cart_lost_days' ) ) as $cart ) {
			if ( ! $repo->mark_lost( (int) $cart['id'], (int) $cart['version'] ) ) {
				continue;
			}

			++$result['lost'];
		}

		return $result;
	}
}
