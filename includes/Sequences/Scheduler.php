<?php
/**
 * Sequence cron scheduling.
 *
 * A recurring tick runs only while at least one sequence is active. The engine
 * itself is idempotent, so a missed tick only delays the next step.
 *
 * @package NowCRM
 */

namespace NowCRM\Sequences;

defined( 'ABSPATH' ) || exit;

final class Scheduler {

	public const HOOK  = 'nowcrm_sequence_tick';
	public const GROUP = 'nowcrm';

	private static bool $synced = false;

	/**
	 * Register the tick and keep the schedule current.
	 */
	public function register(): void {
		add_action( self::HOOK, array( $this, 'run' ) );
		add_action( 'init', array( $this, 'ensure_scheduled' ), 24 );
		add_action( 'deactivated_plugin', array( $this, 'on_deactivate' ) );
	}

	/**
	 * Remove pending ticks when this plugin is deactivated.
	 *
	 * @param string $plugin Plugin basename.
	 */
	public function on_deactivate( $plugin ): void {
		if ( defined( 'NOWCRM_FILE' ) && plugin_basename( NOWCRM_FILE ) === (string) $plugin ) {
			$this->unschedule();
		}
	}

	/**
	 * Cron callback.
	 */
	public function run(): void {
		( new Engine() )->process_due();
	}

	/**
	 * Schedule the tick when work exists.
	 */
	public function ensure_scheduled(): void {
		if ( self::$synced ) {
			return;
		}

		self::$synced = true;

		if ( $this->is_scheduled() ) {
			return;
		}

		if ( ( new SequenceRepository() )->has_active() ) {
			$this->schedule();
		}
	}

	/**
	 * Rebuild the schedule after a status or definition change.
	 */
	public static function sync(): void {
		$self = new self();

		if ( ( new SequenceRepository() )->has_active() ) {
			$self->schedule();

			return;
		}

		$self->unschedule();
	}

	/**
	 * Whether a tick is already scheduled.
	 */
	private function is_scheduled(): bool {
		if ( function_exists( 'as_next_scheduled_action' ) && did_action( 'action_scheduler_init' ) ) {
			return false !== as_next_scheduled_action( self::HOOK, array(), self::GROUP );
		}

		return false !== wp_next_scheduled( self::HOOK );
	}

	/**
	 * Add the recurring tick once.
	 */
	private function schedule(): void {
		if ( $this->is_scheduled() ) {
			return;
		}

		if ( function_exists( 'as_schedule_recurring_action' ) && did_action( 'action_scheduler_init' ) ) {
			as_schedule_recurring_action( time() + 60, MINUTE_IN_SECONDS, self::HOOK, array(), self::GROUP, true );

			return;
		}

		wp_schedule_event( time() + 60, 'nowcrm_minute', self::HOOK );
	}

	/**
	 * Remove every pending tick.
	 */
	private function unschedule(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::HOOK, array(), self::GROUP );
		}

		wp_clear_scheduled_hook( self::HOOK );
	}
}
