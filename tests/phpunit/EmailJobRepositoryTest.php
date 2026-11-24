<?php
/**
 * Persistent email queue semantics: dedupe, atomic claim, lease, retry.
 *
 * @package NowCRM
 */

use NowCRM\Email\EmailJobRepository;

/**
 * @covers \NowCRM\Email\EmailJobRepository
 */
final class EmailJobRepositoryTest extends NowCrmTestCase {

	private EmailJobRepository $jobs;

	public function set_up(): void {
		parent::set_up();
		$this->jobs = new EmailJobRepository();
	}

	/**
	 * Enqueue a due job.
	 */
	private function enqueue( string $key, array $overrides = array() ): int {
		$id = $this->jobs->enqueue(
			array_merge(
				array(
					'job_key'   => $key,
					'kind'      => 'campaign',
					'recipient' => $key . '@example.test',
					'subject'   => 'Fixture',
					'payload'   => array( 'a' => 1 ),
				),
				$overrides
			)
		);

		$this->assertIsInt( $id );

		return (int) $id;
	}

	public function test_duplicate_job_key_returns_the_same_row(): void {
		$first  = $this->enqueue( 'dup' );
		$second = $this->enqueue( 'dup' );

		$this->assertSame( $first, $second );
		$this->assertSame( 1, $this->table_count( 'email_jobs' ) );
	}

	public function test_claim_is_atomic_and_disjoint_between_workers(): void {
		$ids = array();

		for ( $i = 0; $i < 4; $i++ ) {
			$ids[] = $this->enqueue( 'job-' . $i );
		}

		$first  = $this->jobs->claim( 2, 'worker-a' );
		$second = $this->jobs->claim( 2, 'worker-b' );

		$this->assertCount( 2, $first );
		$this->assertCount( 2, $second );

		$claimed = array_merge( array_column( $first, 'id' ), array_column( $second, 'id' ) );

		$this->assertCount( 4, array_unique( $claimed ) );
		sort( $claimed );

		$this->assertSame( $ids, array_map( 'intval', $claimed ) );
		$this->assertSame( array(), $this->jobs->claim( 2, 'worker-c' ) );
	}

	public function test_future_jobs_are_not_claimed(): void {
		$this->enqueue( 'later', array( 'available_at' => time() + 300 ) );

		$this->assertSame( array(), $this->jobs->claim( 10, 'worker' ) );
	}

	public function test_failure_retries_then_becomes_terminal(): void {
		global $wpdb;

		$id = $this->enqueue( 'flaky', array( 'max_attempts' => 2 ) );

		$rows = $this->jobs->claim( 1, 'worker' );
		$this->assertSame( $id, (int) $rows[0]['id'] );

		$this->jobs->mark_failed( $id, 'worker', 'temporary' );
		$job = $this->jobs->get( $id );
		$this->assertSame( EmailJobRepository::STATUS_QUEUED, $job['status'] );
		$this->assertSame( 1, $job['attempts'] );

		// Force the backoff to have elapsed, then fail the final attempt.
		$wpdb->update(
			\NowCRM\Support\Schema::table( 'email_jobs' ),
			array( 'available_at' => gmdate( 'Y-m-d H:i:s', time() - 10 ) ),
			array( 'id' => $id )
		);

		$rows = $this->jobs->claim( 1, 'worker-2' );
		$this->assertCount( 1, $rows );

		$this->jobs->mark_failed( $id, 'worker-2', 'permanent' );
		$job = $this->jobs->get( $id );

		$this->assertSame( EmailJobRepository::STATUS_FAILED, $job['status'] );
		$this->assertSame( 2, $job['attempts'] );
		$this->assertSame( 'permanent', $job['last_error'] );
	}

	public function test_expired_lease_becomes_unknown_not_queued(): void {
		global $wpdb;

		$id = $this->enqueue( 'crash' );
		$this->jobs->claim( 1, 'worker' );

		$wpdb->update(
			\NowCRM\Support\Schema::table( 'email_jobs' ),
			array( 'lease_expires_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ),
			array( 'id' => $id )
		);

		$this->assertSame( 1, $this->jobs->release_stale() );
		$this->assertSame( EmailJobRepository::STATUS_UNKNOWN, $this->jobs->get( $id )['status'] );

		// Unknown jobs are never auto-claimed again.
		$this->assertSame( array(), $this->jobs->claim( 10, 'worker-2' ) );

		$this->assertTrue( $this->jobs->retry( $id ) );
		$this->assertSame( EmailJobRepository::STATUS_QUEUED, $this->jobs->get( $id )['status'] );
	}

	public function test_stale_worker_cannot_overwrite_a_reclaimed_job(): void {
		global $wpdb;

		$id = $this->enqueue( 'stale' );
		$this->jobs->claim( 1, 'old-worker' );

		$wpdb->update(
			\NowCRM\Support\Schema::table( 'email_jobs' ),
			array(
				'claim_token'      => 'new-worker',
				'lease_expires_at' => gmdate( 'Y-m-d H:i:s', time() + 300 ),
			),
			array( 'id' => $id )
		);

		$this->assertFalse( $this->jobs->mark_accepted( $id, 'old-worker', 'msg' ) );
		$this->assertSame( EmailJobRepository::STATUS_SENDING, $this->jobs->get( $id )['status'] );
	}

	public function test_suppressing_an_address_updates_queued_jobs_only(): void {
		$target = $this->enqueue( 'target', array( 'recipient' => 'blocked@example.test' ) );
		$other  = $this->enqueue( 'other', array( 'recipient' => 'keep@example.test' ) );

		$this->assertSame( 1, $this->jobs->suppress_email( 'blocked@example.test', 'bounced' ) );
		$this->assertSame( EmailJobRepository::STATUS_SUPPRESSED, $this->jobs->get( $target )['status'] );
		$this->assertSame( EmailJobRepository::STATUS_QUEUED, $this->jobs->get( $other )['status'] );
	}

	public function test_search_filters_status_and_campaign(): void {
		$this->enqueue( 'one', array( 'campaign_id' => 55, 'kind' => 'campaign' ) );
		$this->enqueue( 'two', array( 'campaign_id' => 66, 'kind' => 'cart' ) );

		$result = $this->jobs->search( array( 'campaign_id' => 55 ) );

		$this->assertSame( 1, $result['total'] );
		$this->assertSame( 'one@example.test', $result['items'][0]['recipient'] );
	}

	public function test_cancel_campaign_leaves_terminal_rows_alone(): void {
		$campaign = $this->make_campaign( array( 'status' => 'sending' ) );
		$queued = $this->enqueue( 'q', array( 'campaign_id' => $campaign ) );
		$this->jobs->claim( 1, 'worker' );
		$this->jobs->mark_accepted( $queued, 'worker', 'msg' );

		$new = $this->enqueue( 'n', array( 'campaign_id' => $campaign ) );

		$this->assertSame( 1, $this->jobs->cancel_campaign( $campaign ) );
		$this->assertSame( EmailJobRepository::STATUS_CANCELLED, $this->jobs->get( $new )['status'] );
		$this->assertSame( EmailJobRepository::STATUS_ACCEPTED, $this->jobs->get( $queued )['status'] );
	}

	/**
	 * Create one transport-accepted job.
	 */
	private function accepted_job( string $key, string $message_id = '' ): int {
		$id    = $this->enqueue( $key );
		$token = 'worker-' . $key;
		$rows  = $this->jobs->claim( 1, $token );

		$this->assertCount( 1, $rows );
		$this->assertSame( $id, (int) $rows[0]['id'] );
		$this->assertTrue( $this->jobs->mark_accepted( $id, $token, $message_id ) );

		return $id;
	}

	public function test_delivery_results_never_downgrade_and_repeat_only_adds_information(): void {
		$id = $this->accepted_job( 'delivery-rank' );

		$this->assertTrue( $this->jobs->mark_delivery_event( $id, EmailJobRepository::DELIVERY_DEFERRED, 'MailboxFull' ) );

		$job = $this->jobs->get( $id );

		$this->assertSame( EmailJobRepository::STATUS_ACCEPTED, $job['status'] );
		$this->assertSame( EmailJobRepository::DELIVERY_DEFERRED, $job['delivery_status'] );
		$this->assertSame( 'MailboxFull', $job['delivery_error'] );
		$this->assertNotSame( '', $job['delivery_at'] );

		// Same result without a new diagnostic is a no-op.
		$this->assertFalse( $this->jobs->mark_delivery_event( $id, EmailJobRepository::DELIVERY_DEFERRED, 'MailboxFull' ) );

		// Same rank may still record a new diagnostic.
		$this->assertTrue( $this->jobs->mark_delivery_event( $id, EmailJobRepository::DELIVERY_DEFERRED, 'MailboxFull (retry)' ) );
		$this->assertSame( 'MailboxFull (retry)', $this->jobs->get( $id )['delivery_error'] );

		// delivered outranks deferred, bounced outranks delivered, complaint outranks bounced.
		$this->assertTrue( $this->jobs->mark_delivery_event( $id, EmailJobRepository::DELIVERY_DELIVERED ) );
		$this->assertFalse( $this->jobs->mark_delivery_event( $id, EmailJobRepository::DELIVERY_DEFERRED, 'late transient' ) );
		$this->assertSame( EmailJobRepository::DELIVERY_DELIVERED, $this->jobs->get( $id )['delivery_status'] );

		$this->assertTrue( $this->jobs->mark_delivery_event( $id, EmailJobRepository::DELIVERY_BOUNCED, '550' ) );
		$this->assertFalse( $this->jobs->mark_delivery_event( $id, EmailJobRepository::DELIVERY_DELIVERED ) );
		$this->assertSame( EmailJobRepository::DELIVERY_BOUNCED, $this->jobs->get( $id )['delivery_status'] );

		$this->assertTrue( $this->jobs->mark_delivery_event( $id, EmailJobRepository::DELIVERY_COMPLAINED, 'abuse' ) );
		$this->assertFalse( $this->jobs->mark_delivery_event( $id, EmailJobRepository::DELIVERY_BOUNCED, 'later bounce' ) );
		$this->assertSame( EmailJobRepository::DELIVERY_COMPLAINED, $this->jobs->get( $id )['delivery_status'] );
	}

	public function test_delivery_event_requires_allow_listed_status_and_handoff_queue_state(): void {
		$id = $this->accepted_job( 'delivery-guard' );

		$this->assertFalse( $this->jobs->mark_delivery_event( $id, 'accepted' ) );
		$this->assertSame( '', $this->jobs->get( $id )['delivery_status'] );

		$queued = $this->enqueue( 'delivery-queued' );

		$this->assertFalse( $this->jobs->mark_delivery_event( $queued, EmailJobRepository::DELIVERY_DELIVERED ) );
		$this->assertSame( '', $this->jobs->get( $queued )['delivery_status'] );
	}

	public function test_retry_never_erases_recorded_delivery_evidence(): void {
		global $wpdb;

		$id = $this->accepted_job( 'delivery-retry' );

		$this->assertTrue( $this->jobs->mark_delivery_event( $id, EmailJobRepository::DELIVERY_BOUNCED, '550' ) );

		$wpdb->update(
			\NowCRM\Support\Schema::table( 'email_jobs' ),
			array( 'status' => EmailJobRepository::STATUS_FAILED ),
			array( 'id' => $id )
		);

		// Evidence exists, so the operator retry must not requeue the row.
		$this->assertFalse( $this->jobs->retry( $id ) );

		$job = $this->jobs->get( $id );

		$this->assertSame( EmailJobRepository::STATUS_FAILED, $job['status'] );
		$this->assertSame( EmailJobRepository::DELIVERY_BOUNCED, $job['delivery_status'] );
		$this->assertSame( '550', $job['delivery_error'] );
	}

	public function test_delivery_stats_count_receipts_in_any_queue_state(): void {
		$delivered = $this->accepted_job( 'stats-delivered' );

		$this->assertTrue( $this->jobs->mark_delivery_event( $delivered, EmailJobRepository::DELIVERY_DELIVERED ) );

		$this->accepted_job( 'stats-none' );

		// Ambiguous handoff, then a real provider receipt: it must count.
		$unknown = $this->enqueue( 'stats-unknown' );
		$token   = 'stats-unknown-token';
		$this->assertCount( 1, $this->jobs->claim( 1, $token ) );
		$this->assertTrue( $this->jobs->mark_unknown( $unknown, $token, 'wp_mail_returned_false' ) );
		$this->assertTrue( $this->jobs->mark_delivery_event( $unknown, EmailJobRepository::DELIVERY_DELIVERED ) );

		// No receipt and not accepted: this row must not land in `none`.
		$this->enqueue( 'stats-queued' );

		$stats = $this->jobs->delivery_stats();

		$this->assertSame( 2, $stats['delivered'] );
		$this->assertSame( 1, $stats['none'] );

		$none = $this->jobs->search( array( 'delivery_status' => 'none' ) );

		$this->assertSame( 1, $none['total'] );
		$this->assertSame( 'stats-none@example.test', $none['items'][0]['recipient'] );

		$found = $this->jobs->search( array( 'delivery_status' => EmailJobRepository::DELIVERY_DELIVERED ) );

		$this->assertSame( 2, $found['total'] );
		$recipients = array_column( $found['items'], 'recipient' );
		sort( $recipients );
		$this->assertSame( array( 'stats-delivered@example.test', 'stats-unknown@example.test' ), $recipients );
	}
}
