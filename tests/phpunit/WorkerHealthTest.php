<?php
/**
 * Worker health: successful tick recording, schedule states, and queue counts.
 *
 * @package NowCRM
 */

use NowCRM\Email\EmailJobRepository;
use NowCRM\Email\Queue;
use NowCRM\Email\WorkerHealth;
use NowCRM\Support\Schema;

/**
 * @covers \NowCRM\Email\WorkerHealth
 */
final class WorkerHealthTest extends NowCrmTestCase {

	public function set_up(): void {
		parent::set_up();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->clear_schedule();
		delete_option( WorkerHealth::OPTION );
	}

	public function tear_down(): void {
		$this->clear_schedule();
		delete_option( WorkerHealth::OPTION );

		parent::tear_down();
	}

	public function test_record_stores_the_current_utc_time_without_autoload(): void {
		$status = WorkerHealth::status();
		$this->assertNull( $status['last_success_at'] );

		$before = time();
		WorkerHealth::record();
		$after = time();

		$stored = (int) get_option( WorkerHealth::OPTION, 0 );

		$this->assertGreaterThanOrEqual( $before, $stored );
		$this->assertLessThanOrEqual( $after, $stored );

		$status = WorkerHealth::status();
		$this->assertSame( $stored, $status['last_success_at'] );

		global $wpdb;

		$autoload = $wpdb->get_var(
			$wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", WorkerHealth::OPTION )
		);

		$this->assertContains( $autoload, array( 'off', 'no' ) );
	}

	public function test_status_separates_absent_schedule_from_a_false_value(): void {
		$status = WorkerHealth::status();

		$this->assertSame( 'none', $status['scheduler'] );
		$this->assertNull( $status['next_scheduled_at'] );
		$this->assertFalse( $status['overdue'] );
	}

	public function test_status_marks_a_past_next_run_as_overdue(): void {
		wp_schedule_single_event( time() - 600, Queue::CRON );

		$status = WorkerHealth::status();

		$this->assertSame( 'wp-cron', $status['scheduler'] );
		$this->assertIsInt( $status['next_scheduled_at'] );
		$this->assertTrue( $status['overdue'] );
	}

	public function test_status_does_not_mark_a_future_next_run_as_overdue(): void {
		wp_schedule_single_event( time() + 60, Queue::CRON );

		$status = WorkerHealth::status();

		$this->assertSame( 'wp-cron', $status['scheduler'] );
		$this->assertFalse( $status['overdue'] );
	}

	public function test_status_counts_due_needs_review_and_total_from_the_queue(): void {
		$repo = new EmailJobRepository();

		$queued = $repo->enqueue(
			array(
				'job_key'   => 'health-queued',
				'kind'      => 'custom',
				'recipient' => 'queued@example.test',
				'subject'   => 'Queued',
			)
		);
		$failed = $repo->enqueue(
			array(
				'job_key'   => 'health-failed',
				'kind'      => 'custom',
				'recipient' => 'failed@example.test',
				'subject'   => 'Failed',
			)
		);

		$this->assertIsInt( $queued );
		$this->assertIsInt( $failed );

		global $wpdb;

		$wpdb->update(
			Schema::table( 'email_jobs' ),
			array( 'status' => EmailJobRepository::STATUS_FAILED ),
			array( 'id' => (int) $failed )
		);

		$status = WorkerHealth::status();

		$this->assertSame( 1, $status['due'] );
		$this->assertSame( 1, $status['needs_review'] );
		$this->assertSame( 2, $status['total'] );
	}

	public function test_status_reads_without_scheduling_or_recording_a_tick(): void {
		wp_schedule_single_event( time() + 60, Queue::CRON );

		$scheduled = wp_next_scheduled( Queue::CRON );

		WorkerHealth::status();

		$this->assertSame( $scheduled, wp_next_scheduled( Queue::CRON ) );
		$this->assertFalse( get_option( WorkerHealth::OPTION, false ) );
	}

	private function clear_schedule(): void {
		wp_clear_scheduled_hook( Queue::CRON );

		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( Queue::CRON, array(), Queue::GROUP );
		}
	}
}
