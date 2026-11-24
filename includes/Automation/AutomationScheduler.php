<?php
/**
 * Automation cron scheduling.
 *
 * One recurring tick processes due runs. The schedule exists only while at
 * least one automation is active. Saving, pausing, and deleting an automation
 * resyncs the schedule.
 *
 * A second, daily schedule scans birthdays at site midnight. It exists only
 * while an active birthday automation exists.
 *
 * @package NowCRM
 */

namespace NowCRM\Automation;

defined( 'ABSPATH' ) || exit;

final class AutomationScheduler {

	public const HOOK          = 'nowcrm_automation_tick';
	public const BIRTHDAY_HOOK = 'nowcrm_automation_birthday_scan';
	public const BIRTHDAY_CRON = 'nowcrm_daily';
	public const GROUP         = 'nowcrm';

	private static bool $synced          = false;
	private static bool $birthday_synced = false;

	/**
	 * Register the tick and keep the schedule current.
	 */
	public function register(): void {
		add_action( self::HOOK, array( $this, 'run' ) );
		add_action( self::BIRTHDAY_HOOK, array( $this, 'run_birthdays' ) );
		add_action( 'init', array( $this, 'ensure_scheduled' ), 22 );
		add_action( 'init', array( $this, 'ensure_birthday_scheduled' ), 22 );
		add_action( 'deactivated_plugin', array( $this, 'on_deactivate' ) );
		add_filter( 'cron_schedules', array( $this, 'cron_schedules' ) );
	}

	/**
	 * Daily recurrence used by the WP-Cron fallback.
	 *
	 * @param array<string, array<string, mixed>> $schedules Schedules.
	 * @return array<string, array<string, mixed>>
	 */
	public function cron_schedules( array $schedules ): array {
		$schedules[ self::BIRTHDAY_CRON ] = array(
			'interval' => DAY_IN_SECONDS,
			'display'  => __( 'NOW CRM 每天', 'now-crm' ),
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
			$this->unschedule();
			$this->unschedule_birthday();
		}
	}

	/**
	 * Cron callback.
	 */
	public function run(): void {
		( new Engine() )->run_due();
	}

	/**
	 * Daily cron callback: start birthday runs for today's contacts.
	 */
	public function run_birthdays(): void {
		( new TriggerRegistry() )->fire_birthdays();
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

		if ( ( new AutomationRepository() )->has_active() ) {
			$this->schedule();
		}
	}

	/**
	 * Keep the daily birthday scan scheduled while it has subscribers.
	 */
	public function ensure_birthday_scheduled(): void {
		if ( self::$birthday_synced ) {
			return;
		}

		self::$birthday_synced = true;

		$this->sync_birthday();
	}

	/**
	 * Rebuild the schedule after a status or definition change.
	 */
	public static function sync(): void {
		$self = new self();

		if ( ( new AutomationRepository() )->has_active() ) {
			$self->schedule();
		} else {
			$self->unschedule();
		}

		$self->sync_birthday();
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

	/**
	 * Align the daily birthday scan with the active trigger.
	 */
	private function sync_birthday(): void {
		$active = array() !== ( new AutomationRepository() )->active_by_trigger( TriggerRegistry::CONTACT_BIRTHDAY );

		if ( $active ) {
			$this->schedule_birthday();

			return;
		}

		$this->unschedule_birthday();
	}

	/**
	 * Whether the birthday scan is already scheduled.
	 */
	private function is_birthday_scheduled(): bool {
		if ( function_exists( 'as_next_scheduled_action' ) && did_action( 'action_scheduler_init' ) ) {
			return false !== as_next_scheduled_action( self::BIRTHDAY_HOOK, array(), self::GROUP );
		}

		return false !== wp_next_scheduled( self::BIRTHDAY_HOOK );
	}

	/**
	 * Add the daily scan once, aimed at the next site-local midnight.
	 */
	private function schedule_birthday(): void {
		if ( $this->is_birthday_scheduled() ) {
			return;
		}

		$when = $this->next_local_midnight();

		if ( function_exists( 'as_schedule_recurring_action' ) && did_action( 'action_scheduler_init' ) ) {
			as_schedule_recurring_action( $when, DAY_IN_SECONDS, self::BIRTHDAY_HOOK, array(), self::GROUP, true );

			return;
		}

		wp_schedule_event( $when, self::BIRTHDAY_CRON, self::BIRTHDAY_HOOK );
	}

	/**
	 * Remove every pending birthday scan.
	 */
	private function unschedule_birthday(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::BIRTHDAY_HOOK, array(), self::GROUP );
		}

		wp_clear_scheduled_hook( self::BIRTHDAY_HOOK );
	}

	/**
	 * Unix timestamp of the next midnight in the site timezone.
	 */
	private function next_local_midnight(): int {
		$now = new \DateTimeImmutable( 'now', wp_timezone() );

		return $now->modify( '+1 day' )->setTime( 0, 0, 0 )->getTimestamp();
	}
}
