<?php
/**
 * Email payload retention preview and apply contract.
 *
 * @package NowCRM
 */

use NowCRM\Email\ContactHistory;
use NowCRM\Email\EmailJobRepository;
use NowCRM\Privacy\Retention;
use NowCRM\Support\Schema;

/**
 * @covers \NowCRM\Privacy\Retention
 */
final class EmailRetentionTest extends NowCrmTestCase {

	private const OLD    = '2020-01-01 00:00:00';
	private const RECENT = 'now';

	/**
	 * Insert a job directly with a terminal status and timestamp.
	 *
	 * @param array<string, mixed> $overrides Column overrides.
	 */
	private function make_job( array $overrides = array() ): int {
		global $wpdb;

		$now = gmdate( 'Y-m-d H:i:s' );

		$row = array_merge(
			array(
				'job_key'      => 'retention-' . wp_generate_password( 10, false ),
				'kind'         => 'campaign',
				'recipient'    => 'retention-' . wp_generate_password( 6, false ) . '@example.test',
				'recipient_name' => '',
				'subject'      => 'Retention fixture',
				'payload'      => wp_json_encode( array( 'template' => array( 'content' => 'SECRET-JOB-BODY' ) ) ),
				'status'       => EmailJobRepository::STATUS_ACCEPTED,
				'priority'     => 10,
				'available_at' => $now,
				'attempts'     => 1,
				'max_attempts' => 3,
				'accepted_at'  => $now,
				'created_at'   => $now,
				'updated_at'   => $this->stamp( self::OLD ),
			),
			$overrides
		);

		$this->assertTrue( (bool) $wpdb->insert( Schema::table( 'email_jobs' ), $row ) );

		return (int) $wpdb->insert_id;
	}

	/**
	 * Insert one SES event with a raw payload.
	 *
	 * @param int  $processed  Processed flag.
	 * @param bool $historical Whether the event is old.
	 */
	private function make_ses( int $processed, bool $historical ): int {
		global $wpdb;

		$id = 'sns-' . wp_generate_password( 12, false );

		$this->assertTrue(
			(bool) $wpdb->insert(
				Schema::table( 'ses_events' ),
				array(
					'sns_message_id'   => $id,
					'event_key'        => 'event-' . $id,
					'event_type'       => 'Delivery',
					'email_normalized' => 'ses-' . wp_generate_password( 8, false ) . '@example.test',
					'payload'          => 'RAW-SNS-BODY',
					'processed'        => $processed,
					'received_at'      => $historical ? $this->stamp( self::OLD ) : gmdate( 'Y-m-d H:i:s' ),
				)
			)
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Resolve the magic recent/old fixture stamp.
	 */
	private function stamp( string $value ): string {
		return self::RECENT === $value ? gmdate( 'Y-m-d H:i:s' ) : $value;
	}

	/**
	 * Raw payload column of one job.
	 */
	private function job_payload( int $id ): ?string {
		global $wpdb;

		return $wpdb->get_var(
			$wpdb->prepare( 'SELECT payload FROM ' . Schema::table( 'email_jobs' ) . ' WHERE id = %d', $id )
		);
	}

	/**
	 * Raw payload column of one SES event.
	 */
	private function ses_payload( int $id ): ?string {
		global $wpdb;

		return $wpdb->get_var(
			$wpdb->prepare( 'SELECT payload FROM ' . Schema::table( 'ses_events' ) . ' WHERE id = %d', $id )
		);
	}

	public function test_preview_is_read_only_and_counts_the_same_clauses(): void {
		$accepted = $this->make_job();
		$queued   = $this->make_job( array( 'status' => EmailJobRepository::STATUS_QUEUED ) );
		$ses_old  = $this->make_ses( 1, true );
		$ses_live = $this->make_ses( 0, true );

		$service = new Retention();
		$preview = $service->preview( Retention::DEFAULT_DAYS );

		$this->assertSame( 1, $preview['jobs'] );
		$this->assertSame( 1, $preview['ses'] );
		$this->assertSame( 'SECRET-JOB-BODY', $this->payload_text( $this->job_payload( $accepted ) ) );
		$this->assertSame( 'SECRET-JOB-BODY', $this->payload_text( $this->job_payload( $queued ) ) );
		$this->assertSame( 'RAW-SNS-BODY', $this->ses_payload( $ses_old ) );
		$this->assertSame( 'RAW-SNS-BODY', $this->ses_payload( $ses_live ) );
	}

	public function test_apply_clears_only_proven_terminal_and_processed_payloads(): void {
		global $wpdb;

		$contact = $this->make_contact( array( 'email' => 'retention-contact@example.test' ) );

		$campaign = $this->make_job(
			array(
				'contact_id' => $contact,
				'status'     => EmailJobRepository::STATUS_ACCEPTED,
			)
		);

		$automation_payload = array(
			'template'      => array( 'content' => 'SECRET-JOB-BODY' ),
			'recovery_url'  => 'https://example.test/?token=SECRET-RECOVERY-URL',
			'automation_id' => 7,
			'run_id'        => 8,
			'step_id'       => 9,
			'cart_id'       => 10,
			'cart_version'  => 3,
			'order_total'   => '42',
		);

		$automation = $this->make_job(
			array(
				'kind'       => 'automation',
				'contact_id' => $contact,
				'cart_id'    => 10,
				'run_id'     => 8,
				'status'     => EmailJobRepository::STATUS_CANCELLED,
				'payload'    => wp_json_encode( $automation_payload ),
			)
		);

		$recent = $this->make_job(
			array(
				'status'     => EmailJobRepository::STATUS_ACCEPTED,
				'updated_at' => gmdate( 'Y-m-d H:i:s' ),
			)
		);

		$live = array();

		foreach ( array(
			EmailJobRepository::STATUS_SENDING,
			EmailJobRepository::STATUS_FAILED,
			EmailJobRepository::STATUS_SUPPRESSED,
			EmailJobRepository::STATUS_UNKNOWN,
		) as $status ) {
			$live[ $status ] = $this->make_job( array( 'status' => $status ) );
		}

		$ses_old        = $this->make_ses( 1, true );
		$ses_recent     = $this->make_ses( 1, false );
		$ses_unprocessed = $this->make_ses( 0, true );

		$events = Schema::table( 'email_events' );
		$wpdb->insert(
			$events,
			array(
				'event_key'   => 'retention-event',
				'type'        => 'open',
				'campaign_id' => 12,
				'job_id'      => $campaign,
				'contact_id'  => $contact,
				'meta'        => '{"immutable":"1"}',
				'occurred_at' => self::OLD,
			)
		);
		$event_before = (string) $wpdb->get_var(
			$wpdb->prepare( 'SELECT meta FROM ' . $events . ' WHERE id = %d', (int) $wpdb->insert_id )
		);

		$result = ( new Retention() )->apply( Retention::DEFAULT_DAYS, 10 );

		$this->assertSame( 2, $result['jobs']['eligible'] );
		$this->assertSame( 2, $result['jobs']['cleared'] );
		$this->assertSame( 1, $result['ses']['eligible'] );
		$this->assertSame( 1, $result['ses']['cleared'] );

		$this->assertNull( $this->job_payload( $campaign ) );

		$kept = json_decode( (string) $this->job_payload( $automation ), true );

		$this->assertSame(
			array(
				'_nowcrm_retained' => 1,
				'automation_id'    => 7,
				'run_id'           => 8,
				'step_id'          => 9,
				'cart_id'          => 10,
				'cart_version'     => 3,
			),
			$kept
		);

		$json = (string) $this->job_payload( $automation );
		$this->assertStringNotContainsString( 'SECRET-JOB-BODY', $json );
		$this->assertStringNotContainsString( 'SECRET-RECOVERY-URL', $json );
		$this->assertStringNotContainsString( 'order_total', $json );

		// The row and its audit identity stay.
		$row = ( new EmailJobRepository() )->get( $automation );
		$this->assertNotNull( $row );
		$this->assertSame( EmailJobRepository::STATUS_CANCELLED, $row['status'] );
		$this->assertNotSame( '', $row['job_key'] );
		$this->assertSame( 10, $row['cart_id'] );
		$this->assertSame( 8, $row['run_id'] );

		// The frozen body is gone, so the existing preview reports unavailable.
		$this->assertNull( ContactHistory::template_for_job( $row ) );

		$this->assertSame( 'SECRET-JOB-BODY', $this->payload_text( $this->job_payload( $recent ) ) );

		foreach ( $live as $status => $id ) {
			$this->assertSame( 'SECRET-JOB-BODY', $this->payload_text( $this->job_payload( $id ) ), $status );
		}

		$this->assertNull( $this->ses_payload( $ses_old ) );
		$this->assertSame( 'RAW-SNS-BODY', $this->ses_payload( $ses_recent ) );
		$this->assertSame( 'RAW-SNS-BODY', $this->ses_payload( $ses_unprocessed ) );
		$this->assertSame( '{"immutable":"1"}', $event_before );

		$this->assertSame(
			1,
			(int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $events . ' WHERE event_key = ' . "'retention-event'" )
		);

		$preview = ( new Retention() )->preview( Retention::DEFAULT_DAYS );
		$this->assertSame( 0, $preview['jobs'] );
		$this->assertSame( 0, $preview['ses'] );
	}

	public function test_reduced_linked_payloads_do_not_starve_plain_jobs(): void {
		$linked = array();

		for ( $index = 0; $index < 3; $index++ ) {
			$linked[] = $this->make_job(
				array(
					'kind'      => 'automation',
					'run_id'    => 100 + $index,
					'status'    => EmailJobRepository::STATUS_CANCELLED,
					'payload'   => wp_json_encode(
						array(
							'template'      => array( 'content' => 'SECRET-JOB-BODY' ),
							'automation_id' => 7,
							'run_id'        => 100 + $index,
							'step_id'       => 9,
							'cart_id'       => 10,
							'cart_version'  => 3,
						)
					),
				)
			);
		}

		$plain = array( $this->make_job(), $this->make_job(), $this->make_job() );

		$service = new Retention();

		$this->assertSame( 6, $service->preview( Retention::DEFAULT_DAYS )['jobs'] );

		$first = $service->apply( Retention::DEFAULT_DAYS, 2 );
		$this->assertSame( 2, $first['jobs']['cleared'] );

		$second = $service->apply( Retention::DEFAULT_DAYS, 2 );
		$this->assertSame( 2, $second['jobs']['cleared'] );

		$third = $service->apply( Retention::DEFAULT_DAYS, 2 );
		$this->assertSame( 2, $third['jobs']['cleared'] );

		$fourth = $service->apply( Retention::DEFAULT_DAYS, 2 );
		$this->assertSame( 0, $fourth['jobs']['cleared'] );
		$this->assertSame( 0, $service->preview( Retention::DEFAULT_DAYS )['jobs'] );

		foreach ( $linked as $id ) {
			$kept = json_decode( (string) $this->job_payload( $id ), true );

			$this->assertSame( 1, $kept['_nowcrm_retained'] );
			$this->assertSame( 7, $kept['automation_id'] );
			$this->assertSame( 9, $kept['step_id'] );
			$this->assertArrayNotHasKey( 'template', $kept );
		}

		foreach ( $plain as $id ) {
			$this->assertNull( $this->job_payload( $id ) );
		}
	}

	public function test_apply_processes_one_bounded_batch(): void {
		$jobs = array();

		for ( $index = 0; $index < 3; $index++ ) {
			$jobs[] = $this->make_job();
		}

		$service = new Retention();
		$first   = $service->apply( Retention::DEFAULT_DAYS, 2 );

		$this->assertSame( 3, $first['jobs']['eligible'] );
		$this->assertSame( 2, $first['jobs']['cleared'] );

		$remaining = array_values(
			array_filter(
				$jobs,
				fn( int $id ): bool => null !== $this->job_payload( $id )
			)
		);

		$this->assertCount( 1, $remaining );

		$second = $service->apply( Retention::DEFAULT_DAYS, 2 );

		$this->assertSame( 1, $second['jobs']['cleared'] );
		$this->assertNull( $this->job_payload( $remaining[0] ) );
	}

	public function test_apply_flag_fails_closed(): void {
		$this->assertTrue( Retention::parse_apply_flag( true ) );
		$this->assertTrue( Retention::parse_apply_flag( 'true' ) );
		$this->assertTrue( Retention::parse_apply_flag( ' TRUE ' ) );
		$this->assertFalse( Retention::parse_apply_flag( false ) );
		$this->assertFalse( Retention::parse_apply_flag( 'false' ) );
		$this->assertFalse( Retention::parse_apply_flag( 'FALSE' ) );
		$this->assertNull( Retention::parse_apply_flag( '1' ) );
		$this->assertNull( Retention::parse_apply_flag( 'yes' ) );
		$this->assertNull( Retention::parse_apply_flag( '' ) );
		$this->assertNull( Retention::parse_apply_flag( array() ) );
	}

	/**
	 * Decode a JSON payload column to its content marker.
	 *
	 * @param string|null $raw Raw column.
	 */
	private function payload_text( ?string $raw ): string {
		$decoded = json_decode( (string) $raw, true );

		return (string) ( $decoded['template']['content'] ?? '' );
	}
}
