<?php
/**
 * SES/SNS event application.
 *
 * @package NowCRM
 */

use NowCRM\Contacts\Status;
use NowCRM\Email\EmailJobRepository;
use NowCRM\Email\SuppressionRepository;
use NowCRM\Ses\Endpoint;
use NowCRM\Ses\Processor;

/**
 * @covers \NowCRM\Ses\Processor
 * @covers \NowCRM\Ses\Endpoint
 */
final class SesTest extends NowCrmTestCase {

	/**
	 * Build an SNS envelope carrying a SES notification.
	 */
	private function envelope( string $type, array $message, string $message_id = '', string $timestamp = '' ): array {
		return array(
			'Type'      => 'Notification',
			'MessageId' => $message_id ?: 'sns-' . wp_generate_password( 10, false ),
			'TopicArn'  => 'arn:aws:sns:us-west-2:123456789012:nowcrm-test',
			'Timestamp' => $timestamp ?: gmdate( 'c' ),
			'Message'   => wp_json_encode( $message ),
		);
	}

	/**
	 * Queue and accept a job, returning its stored row.
	 *
	 * @return array<string, mixed>
	 */
	private function handoff( string $email, string $key ): array {
		$repo = new EmailJobRepository();
		$id   = $repo->enqueue(
			array(
				'job_key'   => $key,
				'kind'      => 'custom',
				'recipient' => $email,
				'subject'   => 'Fixture',
			)
		);

		$this->assertIsInt( $id );

		$token = 'tok-' . $key;
		$rows  = $repo->claim( 10, $token );

		$this->assertNotEmpty( $rows );
		$this->assertTrue( $repo->mark_accepted( (int) $id, $token, 'nowcrm-' . (int) $id . '@example.test' ) );

		return (array) $repo->get( (int) $id );
	}

	/**
	 * Build a SES message using the signed tag for a correlation value.
	 *
	 * @param array<string, mixed> $extra Extra message keys.
	 */
	private function tagged( string $type, string $email, string $correlation, array $extra = array() ): array {
		$message = array(
			'notificationType' => $type,
			'mail'             => array(
				'messageId' => 'ses-' . wp_generate_password( 8, false ),
				'tags'      => array( 'nowcrm_job' => array( $correlation ) ),
			),
		);

		if ( 'Delivery' === $type ) {
			$message['delivery'] = array( 'recipients' => array( array( 'emailAddress' => $email ) ) );
		}

		if ( 'Bounce' === $type ) {
			$message['bounce'] = array(
				'bounceType'        => 'Permanent',
				'bouncedRecipients' => array( array( 'emailAddress' => $email, 'diagnosticCode' => '550' ) ),
			);
		}

		if ( 'Complaint' === $type ) {
			$message['complaint'] = array(
				'complaintFeedbackType' => 'abuse',
				'complainedRecipients'  => array( array( 'emailAddress' => $email ) ),
			);
		}

		return array_replace_recursive( $message, $extra );
	}

	public function test_matched_events_update_the_job_and_contact(): void {
		$delivery_email = 'delivered@example.test';
		$delivery       = $this->handoff( $delivery_email, 'delivery-job' );
		$delivery_mail  = $this->tagged( 'Delivery', $delivery_email, (string) $delivery['ses_correlation'] );

		$result = ( new Processor() )->process( $this->envelope( 'Delivery', $delivery_mail ) );

		$this->assertSame( 'delivery', $result['reason'] );
		$this->assertSame(
			EmailJobRepository::DELIVERY_DELIVERED,
			( new EmailJobRepository() )->get( (int) $delivery['id'] )['delivery_status']
		);

		$bounce_email   = 'bounce-perm@example.test';
		$bounce_contact = $this->make_contact( array( 'email' => $bounce_email ) );
		$bounce         = $this->handoff( $bounce_email, 'bounce-job' );
		$bounce_mail    = $this->tagged( 'Bounce', $bounce_email, (string) $bounce['ses_correlation'] );

		// The original header alone must work too.
		$bounce_mail['mail']['tags']    = array();
		$bounce_mail['mail']['headers'] = array(
			array( 'name' => 'X-NowCRM-Job', 'value' => (string) $bounce['ses_correlation'] ),
		);

		$result = ( new Processor() )->process( $this->envelope( 'Bounce', $bounce_mail ) );

		$this->assertSame( 'bounce_permanent', $result['reason'] );
		$this->assertSame( Status::BOUNCED, $this->contacts->get( $bounce_contact )['status'] );
		$this->assertTrue( ( new SuppressionRepository() )->is_suppressed( $bounce_email ) );
		$this->assertSame( EmailJobRepository::DELIVERY_BOUNCED, ( new EmailJobRepository() )->get( (int) $bounce['id'] )['delivery_status'] );

		$complaint_email   = 'complaint@example.test';
		$complaint_contact = $this->make_contact( array( 'email' => $complaint_email ) );
		$complaint         = $this->handoff( $complaint_email, 'complaint-job' );
		$complaint_mail    = $this->tagged( 'Complaint', $complaint_email, (string) $complaint['ses_correlation'] );
		$complaint_mail['mail']['headers'] = array(
			array( 'name' => 'X-NowCRM-Job', 'value' => (string) $complaint['ses_correlation'] ),
		);

		$result = ( new Processor() )->process( $this->envelope( 'Complaint', $complaint_mail ) );

		$this->assertSame( 'complaint', $result['reason'] );
		$this->assertSame( Status::COMPLAINED, $this->contacts->get( $complaint_contact )['status'] );
		$this->assertSame( EmailJobRepository::DELIVERY_COMPLAINED, ( new EmailJobRepository() )->get( (int) $complaint['id'] )['delivery_status'] );

		$stats = Processor::diagnostics();
		$this->assertSame( 3, $stats['received'] );
		$this->assertSame( 3, $stats['matched'] );
		$this->assertSame( 0, $stats['unmatched'] );
		$this->assertSame( 0, $stats['failed'] );
		$this->assertNotSame( '', $stats['last_received_at'] );
	}

	public function test_unmatched_events_never_change_contacts_or_queues(): void {
		$email = 'ghost@example.test';

		$result = ( new Processor() )->process(
			$this->envelope( 'Bounce', $this->tagged( 'Bounce', $email, '999-abcdefghijklmnop' ) )
		);

		$this->assertSame( 'bounce_permanent', $result['reason'] );
		$this->assertNull( $this->contacts->find_by_email( $email ) );
		$this->assertFalse( ( new SuppressionRepository() )->is_suppressed( $email ) );

		$stats = Processor::diagnostics();
		$this->assertSame( 1, $stats['unmatched'] );
		$this->assertSame( 0, $stats['matched'] );
	}

	public function test_numeric_headers_and_conflicting_markers_are_not_proof(): void {
		$email   = 'legacy-header@example.test';
		$contact = $this->make_contact( array( 'email' => $email ) );
		$job     = $this->handoff( $email, 'legacy-header' );

		$mail            = $this->tagged( 'Complaint', $email, '' );
		$mail['mail']['tags'] = array();
		$mail['mail']['headers'] = array( array( 'name' => 'X-NowCRM-Job', 'value' => (string) $job['id'] ) );

		( new Processor() )->process( $this->envelope( 'Complaint', $mail ) );

		$this->assertSame( Status::SUBSCRIBED, $this->contacts->get( $contact )['status'] );
		$this->assertFalse( ( new SuppressionRepository() )->is_suppressed( $email ) );

		// A valid tag conflicts with a different header: both must be refused.
		$mail = $this->tagged( 'Complaint', $email, (string) $job['ses_correlation'] );
		$mail['mail']['headers'] = array( array( 'name' => 'X-NowCRM-Job', 'value' => $job['id'] . '-0000000000000000' ) );

		( new Processor() )->process( $this->envelope( 'Complaint', $mail ) );

		$this->assertSame( Status::SUBSCRIBED, $this->contacts->get( $contact )['status'] );
	}

	public function test_mismatched_recipient_cannot_claim_another_job(): void {
		$email   = 'owner@example.test';
		$contact = $this->make_contact( array( 'email' => $email ) );
		$job     = $this->handoff( $email, 'owner-job' );

		( new Processor() )->process(
			$this->envelope( 'Bounce', $this->tagged( 'Bounce', 'other@example.test', (string) $job['ses_correlation'] ) )
		);

		$this->assertSame( Status::SUBSCRIBED, $this->contacts->get( $contact )['status'] );
		$this->assertSame( EmailJobRepository::STATUS_ACCEPTED, ( new EmailJobRepository() )->get( (int) $job['id'] )['status'] );
		$this->assertSame( '', ( new EmailJobRepository() )->get( (int) $job['id'] )['delivery_status'] );
	}

	public function test_duplicate_and_previously_failed_events_are_safe(): void {
		global $wpdb;

		$email    = 'replay@example.test';
		$contact  = $this->make_contact( array( 'email' => $email ) );
		$job      = $this->handoff( $email, 'replay-job' );
		$message  = $this->tagged( 'Complaint', $email, (string) $job['ses_correlation'] );
		$envelope = $this->envelope( 'Complaint', $message, 'sns-replay', '2026-01-01T00:00:00Z' );

		// Seed a row whose previous processing failed.
		$event_key = hash( 'sha256', 'Complaint|' . $message['mail']['messageId'] . '|' . $email . '|' . $envelope['Timestamp'] );
		$wpdb->insert(
			\NowCRM\Support\Schema::table( 'ses_events' ),
			array(
				'sns_message_id'   => 'sns-replay',
				'event_key'        => $event_key,
				'event_type'       => 'Complaint',
				'ses_message_id'   => $message['mail']['messageId'],
				'email_normalized' => $email,
				'payload'          => '{}',
				'processed'        => 0,
				'status'           => 'failed',
				'received_at'      => '2026-01-01T00:00:00',
			)
		);

		$result = ( new Processor() )->process( $envelope );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'complaint', $result['reason'] );
		$this->assertSame( Status::COMPLAINED, $this->contacts->get( $contact )['status'] );
		$this->assertSame( 1, $this->table_count( 'ses_events' ) );

		$again = ( new Processor() )->process( $envelope );

		$this->assertTrue( $again['ok'] );
		$this->assertSame( 'duplicate', $again['reason'] );
		$this->assertSame( 1, $this->table_count( 'ses_events' ) );
	}

	public function test_diagnostics_include_legacy_rows_as_ignored(): void {
		global $wpdb;

		$wpdb->insert(
			\NowCRM\Support\Schema::table( 'ses_events' ),
			array(
				'sns_message_id'   => 'legacy-sns',
				'event_key'        => 'legacy-key',
				'event_type'       => 'Delivery',
				'ses_message_id'   => 'legacy-ses',
				'email_normalized' => 'legacy@example.test',
				'payload'          => '{}',
				'processed'        => 1,
				'received_at'      => '2026-01-01 00:00:00',
			)
		);

		( new Processor() )->process(
			$this->envelope(
				'Bounce',
				array(
					'notificationType' => 'Bounce',
					'mail'             => array( 'messageId' => 'unused' ),
					'bounce'           => array(
						'bounceType'        => 'Permanent',
						'bouncedRecipients' => array( array( 'emailAddress' => 'unmatched@example.test' ) ),
					),
				)
			)
		);

		$stats = Processor::diagnostics();

		$this->assertSame( 2, $stats['received'] );
		$this->assertSame( 0, $stats['matched'] );
		$this->assertSame( 1, $stats['unmatched'] );
		$this->assertSame( 0, $stats['failed'] );
		$this->assertSame( 1, $stats['ignored'] );
	}

	public function test_endpoint_rejects_unknown_topic_and_accepts_a_verified_fixture(): void {
		$payload = $this->envelope(
			'Complaint',
			$this->tagged( 'Complaint', 'no-topic@example.test', '999-abcdefghijklmnop' ),
			'sns-topic-test'
		);

		$request = new \WP_REST_Request( 'POST', '/nowcrm/v1/ses/sns' );
		$request->set_body( wp_json_encode( $payload ) );

		$response = ( new Endpoint() )->handle( $request );
		$this->assertSame( 403, $response->get_status() );

		add_filter( 'nowcrm_sns_allowed_topics', static fn() => array( $payload['TopicArn'] ) );
		add_filter( 'nowcrm_sns_signature_valid', static fn() => true );

		$response = ( new Endpoint() )->handle( $request );

		$this->assertSame( 200, $response->get_status() );
	}

	public function test_ambiguous_unknown_keeps_marker_for_late_signed_complaint(): void {
		$repo  = new EmailJobRepository();
		$email = 'late-complaint@example.test';

		$contact = $this->make_contact( array( 'email' => $email ) );
		$id      = $repo->enqueue(
			array(
				'job_key'   => 'late-complaint',
				'kind'      => 'custom',
				'recipient' => $email,
				'subject'   => 'Fixture',
			)
		);

		$this->assertIsInt( $id );

		$token = 'late-complaint-token';
		$rows  = $repo->claim( 1, $token );

		$this->assertCount( 1, $rows );
		$this->assertSame( (int) $id, (int) $rows[0]['id'] );

		$marker = (string) $repo->get( (int) $id )['ses_correlation'];
		$this->assertNotSame( '', $marker );

		$this->assertTrue( $repo->mark_unknown( (int) $id, $token, 'wp_mail_returned_false' ) );

		$job = $repo->get( (int) $id );

		$this->assertSame( EmailJobRepository::STATUS_UNKNOWN, $job['status'] );
		$this->assertSame( $marker, $job['ses_correlation'] );

		$result = ( new Processor() )->process(
			$this->envelope( 'Complaint', $this->tagged( 'Complaint', $email, $marker ) )
		);

		$this->assertSame( 'complaint', $result['reason'] );

		$job = $repo->get( (int) $id );

		$this->assertSame( EmailJobRepository::STATUS_UNKNOWN, $job['status'] );
		$this->assertSame( EmailJobRepository::DELIVERY_COMPLAINED, $job['delivery_status'] );
		$this->assertSame( Status::COMPLAINED, $this->contacts->get( $contact )['status'] );
		$this->assertTrue( ( new SuppressionRepository() )->is_suppressed( $email ) );
		$this->assertFalse( $repo->retry( (int) $id ) );
	}
}
