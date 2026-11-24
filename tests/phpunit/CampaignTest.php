<?php
/**
 * Campaign review and delivery through the admin REST entry points.
 *
 * @package NowCRM
 */

use NowCRM\Campaign\Campaigns;
use NowCRM\Contacts\Status;
use NowCRM\Email\EmailDispatcher;
use NowCRM\Email\EmailJobRepository;
use NowCRM\Email\EmailRenderer;
use NowCRM\Email\Queue;
use NowCRM\Email\Tracking;
use NowCRM\Segments\SegmentRepository;
use NowCRM\Support\Schema;
use NowCRM\Templates\TemplateRepository;

final class CampaignTest extends NowCrmTestCase {

	private EmailJobRepository $jobs;

	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->jobs = new EmailJobRepository();
		$this->set_settings( array( 'email_sending_enabled' => true, 'email_transport' => 'capture', 'email_throttle_per_second' => 50 ) );
	}

	private function api( string $method, int $id, string $suffix = '', array $data = array() ): WP_REST_Response {
		$request = new WP_REST_Request( $method, '/nowcrm/v1/campaigns/' . $id . $suffix );
		if ( 'GET' === $method ) {
			$request->set_query_params( $data );
		} else {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( wp_json_encode( $data ) );
		}
		return rest_do_request( $request );
	}

	private function review( int $id ): array {
		$revision = $this->api( 'GET', $id )->get_data()['revision'];
		$response = $this->api( 'GET', $id, '/review', array( 'revision' => $revision ) );
		$this->assertSame( 200, $response->get_status() );
		return $response->get_data();
	}

	private function action( int $id, string $op, array $fields = array() ): WP_REST_Response {
		return $this->api( 'POST', $id, '/delivery', $fields + array( 'op' => $op ) );
	}

	public function test_new_campaign_tracking_and_utm_preferences_default_off(): void {
		$id    = $this->make_campaign();
		$draft = $this->api( 'GET', $id )->get_data()['draft'];

		$this->assertSame( array( 'open' => false, 'click' => false ), $draft['tracking'] );
		$this->assertFalse( $draft['utm']['enabled'] );
		$this->assertSame( array( 'open' => false, 'click' => false ), Campaigns::snapshot( $id, $draft )['tracking'] );
	}

	public function test_saved_campaign_tracking_preferences_keep_explicit_flags(): void {
		$id   = $this->make_campaign();
		$read = $this->api( 'GET', $id )->get_data();

		$draft                   = $read['draft'];
		$draft['tracking']       = array( 'open' => true, 'click' => false );
		$draft['utm']['enabled'] = true;
		$draft['utm']['source']  = 'newsletter';

		$saved = $this->api( 'PUT', $id, '', array( 'draft' => $draft, 'revision' => $read['revision'] ) );
		$this->assertSame( 200, $saved->get_status() );

		$stored = $this->api( 'GET', $id )->get_data()['draft'];
		$this->assertSame( array( 'open' => true, 'click' => false ), $stored['tracking'] );
		$this->assertTrue( $stored['utm']['enabled'] );
		$this->assertSame( 'newsletter', $stored['utm']['source'] );

		// A saved array without one flag keeps the other flag off.
		update_post_meta( $id, Campaigns::META_TRACKING, wp_slash( (string) wp_json_encode( array( 'click' => true ) ) ) );

		$post = Campaigns::get( $id );
		$this->assertNotNull( $post );

		$partial = Campaigns::draft( $post );
		$this->assertFalse( $partial['tracking']['open'] );
		$this->assertTrue( $partial['tracking']['click'] );
	}

	public function test_saved_modes_share_preview_and_reject_stale_writes(): void {
		$id = $this->make_campaign();
		$original = $this->api( 'GET', $id )->get_data();
		$draft = $original['draft'];
		$draft['mode'] = 'blocks';
		$draft['blocks'] = '<!-- wp:paragraph --><p>區塊正文 {{first_name}}</p><!-- /wp:paragraph -->';
		$draft['html'] = '<p>HTML body</p><script>alert(1)</script>';
		$draft['subject'] = "Coupon \\ Q3";
		$payload = array( 'draft' => $draft, 'revision' => $original['revision'] );
		$saved = $this->api( 'PUT', $id, '', $payload );
		$this->assertSame( 200, $saved->get_status() );
		$this->assertSame( '<p>HTML body</p>', $saved->get_data()['draft']['html'] );
		$this->assertSame( $draft['subject'], $saved->get_data()['draft']['subject'] );
		$this->assertSame( 409, $this->api( 'PUT', $id, '', $payload )->get_status() );

		$preview = $this->api( 'GET', $id, '/preview' )->get_data()['html'];
		$this->assertStringContainsString( '區塊正文', $preview );
		$this->assertStringContainsString( 'Content-Security-Policy', $preview );
		$this->assertStringNotContainsString( 'HTML body', $preview );
		$this->assertSame( 201, $this->api( 'POST', $id, '/duplicate' )->get_status() );
	}

	public function test_confirmation_rejects_same_size_audience_change_and_rolls_back(): void {
		$id = $this->make_campaign();
		$first = $this->make_contact();
		$review = $this->review( $id );
		$this->assertSame( 1, $review['count'] );

		$this->contacts->set_status( $first, Status::UNSUBSCRIBED, 'test' );
		$this->make_contact();
		$result = $this->action( $id, 'confirm', array( 'token' => $review['token'] ) );
		$this->assertSame( 409, $result->get_status() );
		$this->assertSame( 'nowcrm_review_changed', $result->get_data()['code'] );
		$this->assertSame( 'draft', Campaigns::state( $id ) );
		$this->assertSame( 0, $this->jobs->campaign_stats( $id )['total'] );
	}

	public function test_schedule_never_sends_early_and_can_be_unscheduled(): void {
		$id = $this->make_campaign();
		$this->make_contact();
		$token = $this->review( $id )['token'];
		$when = time() + 120;
		$schedule = wp_date( 'Y-m-d\TH:i', $when );
		$response = $this->action( $id, 'confirm', array( 'token' => $token, 'schedule' => $schedule ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'scheduled', $response->get_data()['state'] );
		( new Queue() )->run();
		$this->assertSame( 1, $this->jobs->campaign_stats( $id )['queued'] );
		$this->assertEmpty( get_option( EmailDispatcher::CAPTURE_OPTION ) );

		$this->assertSame( 'draft', $this->action( $id, 'unschedule' )->get_data()['state'] );
		$this->assertSame( 0, $this->jobs->campaign_stats( $id )['total'] );
		$this->assertNull( Campaigns::template( $id ) );
		$this->assertSame( 200, $this->action( $id, 'confirm', array( 'token' => $this->review( $id )['token'] ) )->get_status() );
		( new Queue() )->run();
		$this->assertSame( 'sent', Campaigns::state( $id ) );
	}

	public function test_range_spreads_delivery_within_the_chosen_window(): void {
		$id = $this->make_campaign();
		for ( $i = 0; $i < 8; ++$i ) {
			$this->make_contact();
		}
		$start_seconds = intdiv( time(), 60 ) * 60 + 3600;
		$start = wp_date( 'Y-m-d\TH:i', $start_seconds );
		$end = wp_date( 'Y-m-d\TH:i', $start_seconds + 600 );
		$result = $this->action( $id, 'confirm', array( 'token' => $this->review( $id )['token'], 'schedule' => $start, 'schedule_end' => $end ) );
		$this->assertSame( 200, $result->get_status() );
		$jobs = $this->jobs->search( array( 'campaign_id' => $id ) )['items'];
		$this->assertCount( 8, $jobs );
		$slots = array_column( $jobs, 'available_at' );
		$this->assertGreaterThan( 1, count( array_unique( $slots ) ) );
		$this->assertGreaterThanOrEqual( gmdate( 'Y-m-d H:i:s', $start_seconds ), min( $slots ) );
		$this->assertLessThanOrEqual( gmdate( 'Y-m-d H:i:s', $start_seconds + 600 ), max( $slots ) );
	}

	public function test_resume_sends_only_remaining_eligible_contacts(): void {
		$id = $this->make_campaign();
		$this->make_contact( array( 'email' => 'first@example.test' ) );
		$unsubscribed = $this->make_contact();
		$this->make_contact( array( 'email' => 'last@example.test' ) );
		$token = $this->review( $id )['token'];
		$this->assertSame( 200, $this->action( $id, 'confirm', array( 'token' => $token ) )->get_status() );
		$this->assertSame( 409, $this->action( $id, 'confirm', array( 'token' => $token ) )->get_status() );

		$worker = new Queue();
		$worker->process_rows( $this->jobs->claim( 1, 'first' ), 'first' );
		$this->assertSame( 'paused', $this->action( $id, 'pause' )->get_data()['state'] );
		$this->assertEmpty( $this->jobs->claim( 30, 'paused' ) );
		$this->contacts->set_status( $unsubscribed, Status::UNSUBSCRIBED, 'test' );
		$this->make_contact( array( 'email' => 'late@example.test' ) );
		update_post_meta( $id, Campaigns::META_HTML, '<p>Changed after confirmation</p>' );

		$this->assertSame( 'sending', $this->action( $id, 'resume' )->get_data()['state'] );
		$worker->run();
		$report = $this->api( 'GET', $id )->get_data();
		$this->assertSame( 'sent', $report['state'] );
		$this->assertSame( 2, $report['jobs']['accepted'] );
		$this->assertSame( 1, $report['jobs']['suppressed'] );
		$captured = get_option( EmailDispatcher::CAPTURE_OPTION );
		$this->assertSame( array( 'last@example.test', 'first@example.test' ), array_column( $captured, 'to' ) );
		$this->assertStringContainsString( 'Hello', $captured[0]['html'] );
		$this->assertStringContainsString( 'nowcrm_unsubscribe', $captured[0]['html'] );
	}

	public function test_conditional_block_renders_per_recipient_from_the_frozen_snapshot(): void {
		$id = $this->make_campaign(
			array(
				'html' => '<p>Before</p>'
					. '[nowcrm_if field="contact_type" operator="is" value="customer"]<p>Customer only</p>[/nowcrm_if]'
					. '<p>After</p>',
			)
		);
		$this->make_contact( array( 'email' => 'customer@example.test', 'contact_type' => 'customer' ) );
		$this->make_contact( array( 'email' => 'lead@example.test', 'contact_type' => 'lead' ) );

		$review = $this->review( $id );
		$this->assertSame( 2, $review['count'] );
		$this->assertSame( 200, $this->action( $id, 'confirm', array( 'token' => $review['token'] ) )->get_status() );
		$this->assertStringContainsString( '[nowcrm_if', (string) Campaigns::template( $id )['content'] );

		( new Queue() )->run();

		$mail = array();
		foreach ( (array) get_option( EmailDispatcher::CAPTURE_OPTION ) as $captured ) {
			$mail[ $captured['to'] ] = $captured['html'];
		}

		$this->assertStringContainsString( 'Customer only', $mail['customer@example.test'] );
		$this->assertStringNotContainsString( 'Customer only', $mail['lead@example.test'] );
		$this->assertStringContainsString( 'Before', $mail['lead@example.test'] );
		$this->assertStringContainsString( 'After', $mail['lead@example.test'] );
	}

	public function test_test_email_freezes_saved_content_and_excludes_tracking_and_broadcast_stats(): void {
		$id = $this->make_campaign();
		$response = $this->api( 'POST', $id, '/test', array( 'email' => 'test-mail@example.test' ) );
		$this->assertSame( 202, $response->get_status() );
		update_post_meta( $id, Campaigns::META_HTML, '<p>Later edit</p>' );
		( new Queue() )->run();

		$mail = get_option( EmailDispatcher::CAPTURE_OPTION )[0];
		$this->assertStringStartsWith( '[測試]', $mail['subject'] );
		$this->assertStringContainsString( 'Hello', $mail['html'] );
		$this->assertStringNotContainsString( '/nowcrm/v1/open/', $mail['html'] );
		$this->assertSame( 0, $this->jobs->campaign_stats( $id )['total'] );
	}

	public function test_materializes_7600_jobs_without_duplicate_recipients(): void {
		global $wpdb;
		$id = $this->make_campaign();
		$table = Schema::table( 'contacts' );
		$now = gmdate( 'Y-m-d H:i:s' );
		for ( $start = 1; $start <= 7600; $start += 200 ) {
			$emails = array_map( static fn( int $i ): string => "bulk-{$i}@example.test", range( $start, $start + 199 ) );
			$values = implode( ', ', array_fill( 0, 200, "(%s, %s, 'subscribed', %s, %s)" ) );
			$params = array_merge( ...array_map( static fn( string $email ): array => array( $email, $email, $now, $now ), $emails ) );
			$this->assertSame( 200, $wpdb->query( $wpdb->prepare( "INSERT INTO {$table} (email, email_normalized, status, created_at, updated_at) VALUES {$values}", $params ) ) );
		}
		$review = $this->review( $id );
		$this->assertSame( 7600, $review['count'] );
		$this->assertSame( 200, $this->action( $id, 'confirm', array( 'token' => $review['token'] ) )->get_status() );
		$this->assertSame( 7600, $this->jobs->campaign_stats( $id )['queued'] );
		$this->assertSame( 409, $this->action( $id, 'confirm', array( 'token' => $review['token'] ) )->get_status() );
		$this->assertSame( 7600, $this->jobs->campaign_stats( $id )['total'] );
	}

	public function test_audience_segments_agree_between_save_review_and_enqueue_and_reject_archived(): void {
		SegmentRepository::register_post_type();
		$segments = new SegmentRepository();
		$included = array();
		foreach ( array( 'Alpha', 'Beta', 'Other' ) as $name ) {
			$contact = $this->make_contact( array( 'first_name' => $name ) );
			if ( 'Other' !== $name ) {
				$included[] = $contact;
			}
		}
		$segment = $segments->create(
			array(
				'name'    => 'Alpha or Beta',
				'filters' => array(
					array( array( 'provider' => 'contact', 'field' => 'first_name', 'operator' => '=', 'value' => 'Alpha' ) ),
					array( array( 'provider' => 'contact', 'field' => 'first_name', 'operator' => '=', 'value' => 'Beta' ) ),
				),
			)
		);
		$this->assertIsInt( $segment );

		$id   = $this->make_campaign();
		$read = $this->api( 'GET', $id )->get_data();
		$this->assertContains( 'custom:' . $segment, array_column( $read['segments'], 'ref' ) );
		$this->assertContains( 'system:subscribed', array_column( $read['segments'], 'ref' ) );

		$draft = $read['draft'];
		$draft['audience'] = array(
			'scope'   => 'rules',
			'include' => array( array( 'list' => 0, 'tag' => 0, 'segment' => 'custom:' . $segment ) ),
			'exclude' => array(),
		);
		$saved = $this->api( 'PUT', $id, '', array( 'draft' => $draft, 'revision' => $read['revision'] ) );
		$this->assertSame( 200, $saved->get_status() );
		$this->assertSame( 'custom:' . $segment, $saved->get_data()['draft']['audience']['include'][0]['segment'] );

		$review = $this->review( $id );
		$this->assertSame( 2, $review['count'] );
		$this->assertSame( 200, $this->action( $id, 'confirm', array( 'token' => $review['token'] ) )->get_status() );

		$queued = array_map( 'intval', array_column( $this->jobs->campaign_recipients( $id ), 'contact_id' ) );
		sort( $included );
		sort( $queued );
		$this->assertSame( $included, $queued, 'Review and the queued jobs must use the same segment membership.' );

		$this->assertTrue( $segments->archive( $segment ) );
		$other = $this->make_campaign();
		$other_read = $this->api( 'GET', $other )->get_data();
		$other_draft = $other_read['draft'];
		$other_draft['audience'] = array(
			'scope'   => 'rules',
			'include' => array( array( 'list' => 0, 'tag' => 0, 'segment' => 'custom:' . $segment ) ),
			'exclude' => array(),
		);
		$rejected = $this->api( 'PUT', $other, '', array( 'draft' => $other_draft, 'revision' => $other_read['revision'] ) );
		$this->assertSame( 422, $rejected->get_status() );
		$this->assertSame( 'nowcrm_invalid_audience', $rejected->get_data()['code'] );
		$this->assertSame( 'draft', Campaigns::state( $other ) );
	}

	public function test_ab_subjects_split_stably_and_queue_resolves_per_recipient(): void {
		$id = $this->make_campaign();
		$emails = array();
		for ( $i = 0; $i < 20; ++$i ) {
			$email = 'ab-' . $i . '@example.test';
			$emails[ $this->make_contact( array( 'email' => $email ) ) ] = $email;
		}

		$read  = $this->api( 'GET', $id )->get_data();
		$draft = $read['draft'];
		$draft['subject'] = 'Subject A';
		$draft['ab'] = array( 'enabled' => true, 'subject_b' => 'Subject B' );
		$this->assertSame( 200, $this->api( 'PUT', $id, '', array( 'draft' => $draft, 'revision' => $read['revision'] ) )->get_status() );

		$start = wp_date( 'Y-m-d\TH:i', time() + 3600 );
		$confirm = $this->action( $id, 'confirm', array( 'token' => $this->review( $id )['token'], 'schedule' => $start ) );
		$this->assertSame( 200, $confirm->get_status() );

		$first = array();
		foreach ( $this->jobs->search( array( 'campaign_id' => $id ) )['items'] as $job ) {
			$split = (string) ( $job['payload']['ab'] ?? '' );
			$this->assertContains( $split, array( 'a', 'b' ) );
			$this->assertSame( 'b' === $split ? 'Subject B' : 'Subject A', $job['subject'], 'The queued subject must match the assigned variant.' );
			$first[ (int) $job['contact_id'] ] = $split;
		}
		$this->assertCount( 20, $first );
		$variants = array_values( array_unique( array_values( $first ) ) );
		sort( $variants );
		$this->assertSame( array( 'a', 'b' ), $variants, 'Both variants must get recipients.' );

		$this->assertSame( 200, $this->action( $id, 'unschedule' )->get_status() );
		$this->assertSame( 200, $this->action( $id, 'confirm', array( 'token' => $this->review( $id )['token'], 'schedule' => $start ) )->get_status() );
		$second = array();
		foreach ( $this->jobs->search( array( 'campaign_id' => $id ) )['items'] as $job ) {
			$second[ (int) $job['contact_id'] ] = (string) ( $job['payload']['ab'] ?? '' );
		}
		$this->assertSame( $first, $second, 'Re-enqueue must keep the same contact assignment.' );

		$this->assertSame( 200, $this->action( $id, 'unschedule' )->get_status() );
		$this->assertSame( 200, $this->action( $id, 'confirm', array( 'token' => $this->review( $id )['token'] ) )->get_status() );
		( new Queue() )->run();

		$subjects = array();
		foreach ( (array) get_option( EmailDispatcher::CAPTURE_OPTION ) as $mail ) {
			$subjects[ $mail['to'] ] = $mail['subject'];
		}
		foreach ( $first as $contact_id => $split ) {
			$this->assertSame( 'b' === $split ? 'Subject B' : 'Subject A', $subjects[ $emails[ $contact_id ] ] );
		}

		$tracking = new Tracking();
		foreach ( $this->jobs->search( array( 'campaign_id' => $id ) )['items'] as $job ) {
			$job_variant = (string) ( $job['payload']['ab'] ?? '' );
			if ( 'a' === $job_variant ) {
				$tracking->record( 'open:' . $job['id'], 'open', $id, (int) $job['id'], (int) $job['contact_id'] );
				$tracking->record( 'click:' . $job['id'], 'click', $id, (int) $job['id'], (int) $job['contact_id'], 'https://example.test/offer' );
				break;
			}
		}
		foreach ( $this->jobs->search( array( 'campaign_id' => $id ) )['items'] as $job ) {
			if ( 'b' === ( $job['payload']['ab'] ?? '' ) ) {
				$tracking->record( 'unsubscribe:' . $id . ':' . $job['contact_id'], 'unsubscribe', $id, (int) $job['id'], (int) $job['contact_id'] );
				break;
			}
		}

		$report = $this->api( 'GET', $id )->get_data();
		$this->assertSame( 'Subject A', $report['variants']['a']['subject'] );
		$this->assertSame( 'Subject B', $report['variants']['b']['subject'] );
		$this->assertSame( 20, $report['variants']['a']['accepted'] + $report['variants']['b']['accepted'] );
		$this->assertSame( 1, $report['variants']['a']['opened'] );
		$this->assertSame( 1, $report['variants']['a']['clicked'] );
		$this->assertSame( 1, $report['variants']['b']['unsubscribed'] );
	}

	public function test_ab_requires_subject_b_and_freezes_after_confirmation(): void {
		$id = $this->make_campaign();
		$this->make_contact();

		$read  = $this->api( 'GET', $id )->get_data();
		$draft = $read['draft'];
		$this->assertSame( array( 'enabled' => false, 'subject_b' => '' ), $draft['ab'], 'Existing campaigns must default to A/B off.' );
		$this->assertArrayNotHasKey( 'ab', Campaigns::snapshot( $id, $draft ), 'A campaign without A/B must keep its old snapshot shape.' );

		$draft['ab'] = array( 'enabled' => true, 'subject_b' => '' );
		$invalid = $this->api( 'PUT', $id, '', array( 'draft' => $draft, 'revision' => $read['revision'] ) );
		$this->assertSame( 422, $invalid->get_status() );
		$this->assertSame( 'nowcrm_invalid_ab', $invalid->get_data()['code'] );

		$draft['ab']['subject_b'] = 'B subject';
		$saved = $this->api( 'PUT', $id, '', array( 'draft' => $draft, 'revision' => $read['revision'] ) );
		$this->assertSame( 200, $saved->get_status() );
		$this->assertSame( 200, $this->action( $id, 'confirm', array( 'token' => $this->review( $id )['token'] ) )->get_status() );

		$report = $this->api( 'GET', $id )->get_data();
		$this->assertSame( 'B subject', $report['variants']['b']['subject'] );

		$late = $draft;
		$late['ab'] = array( 'enabled' => true, 'subject_b' => 'Late B' );
		$refused = $this->api( 'PUT', $id, '', array( 'draft' => $late, 'revision' => $saved->get_data()['revision'] ) );
		$this->assertSame( 409, $refused->get_status(), 'A/B must not change after confirmation.' );
		$this->assertSame( 'nowcrm_draft_changed', $refused->get_data()['code'] );

		update_post_meta( $id, Campaigns::META_AB, wp_slash( (string) wp_json_encode( array( 'enabled' => true, 'subject_b' => 'Tampered B' ) ) ) );
		$this->assertSame( 'B subject', Campaigns::template( $id )['ab']['subject_b'], 'The confirmed snapshot must stay immutable.' );

		$plain = $this->make_campaign();
		$this->make_contact();
		$this->assertSame( 200, $this->action( $plain, 'confirm', array( 'token' => $this->review( $plain )['token'] ) )->get_status() );
		( new Queue() )->run();
		$plain_report = $this->api( 'GET', $plain )->get_data();
		$this->assertArrayNotHasKey( 'variants', $plain_report, 'Reports without A/B must keep the old shape.' );
		$this->assertSame( 'Fixture subject', $plain_report['subject'] );
	}

	public function test_raw_preview_is_draft_only_read_only_and_sanitized(): void {
		$id       = $this->make_campaign( array( 'html' => '<p>Saved body</p>' ) );
		$original = $this->api( 'GET', $id )->get_data();

		$response = $this->api( 'POST', $id, '/preview', array( 'html' => '<p>Live {{first_name}}</p><script>alert(1)</script>' ) );
		$this->assertSame( 200, $response->get_status() );
		$html = $response->get_data()['html'];
		$this->assertStringContainsString( 'Live', $html, 'The unsaved HTML must drive the preview.' );
		$this->assertStringNotContainsString( '<script', $html, 'Preview must reuse the renderer sanitizer.' );

		$after = $this->api( 'GET', $id )->get_data();
		$this->assertSame( '<p>Saved body</p>', $after['draft']['html'], 'Preview must never persist the raw HTML.' );
		$this->assertSame( $original['revision'], $after['revision'], 'Preview must not advance the draft revision.' );

		update_post_meta( $id, Campaigns::META_STATUS, Campaigns::STATUS_SCHEDULED );
		$this->assertSame( 409, $this->api( 'POST', $id, '/preview', array( 'html' => '<p>Nope</p>' ) )->get_status() );

		wp_set_current_user( 0 );
		$this->assertContains( $this->api( 'POST', $id, '/preview', array( 'html' => '<p>Anon</p>' ) )->get_status(), array( 401, 403 ) );
	}

	public function test_complete_raw_html_is_preserved_and_fluentcrm_links_are_resolved(): void {
		$document = '<!DOCTYPE html><html lang="zh-Hant"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>FluentCRM import</title><style>p{color:#123456}</style></head><body><p>Hello {{first_name}}</p><p><a href="##crm.unsubscribe_url##">Unsubscribe</a></p></body></html>';
		$id       = $this->make_campaign( array( 'html' => $document ) );

		$response = $this->api( 'POST', $id, '/preview', array( 'html' => $document ) );
		$this->assertSame( 200, $response->get_status() );
		$html = $response->get_data()['html'];

		$this->assertSame( 1, substr_count( strtolower( $html ), '<!doctype' ), 'A complete document must keep its own doctype, not gain a second one.' );
		$this->assertStringNotContainsString( 'nowcrm-card', $html, 'A complete document must not be nested in the NOW wrapper.' );
		$this->assertStringContainsString( '<meta charset="utf-8">', $html, 'Document metadata is part of the message.' );
		$this->assertStringContainsString( 'color:#123456', $html, 'The document stylesheet must not be rewritten.' );
		$this->assertStringContainsString( 'Hello 示範', $html, 'Recipient smartcodes still resolve.' );
		$this->assertStringContainsString( 'href="#unsubscribe"', $html, 'FluentCRM unsubscribe smartcodes resolve to the preview URL.' );
		$this->assertStringNotContainsString( '##crm.', $html );
		$this->assertStringNotContainsString( '取消訂閱', $html, 'The document already carries a compliance link, so no footer is appended.' );

		$fragment = $this->api( 'POST', $id, '/preview', array( 'html' => '<p>Fragment</p>' ) )->get_data()['html'];
		$this->assertStringContainsString( 'nowcrm-card', $fragment, 'Fragments still get the NOW card and footer.' );

		$bare = '<!DOCTYPE html><html><head><title>Bare</title></head><body><a href="##crm.unsubscribe_url##">Unsubscribe</a></body></html>';
		$bare_preview = $this->api( 'POST', $id, '/preview', array( 'html' => $bare ) )->get_data()['html'];
		$this->assertStringContainsString( '<meta charset="utf-8"><title>Bare</title>', $bare_preview, 'A document saved before the fix gets its charset back; the preview CSP may precede it.' );

		$current = $this->api( 'GET', $id )->get_data();
		$draft   = $current['draft'];
		$draft['html'] = $document;
		$saved   = $this->api( 'PUT', $id, '', array( 'draft' => $draft, 'revision' => $current['revision'] ) );
		$this->assertSame( 200, $saved->get_status() );
		$this->assertStringContainsString( '<meta charset="utf-8">', $saved->get_data()['draft']['html'], 'Saving must not strip document metadata.' );
	}

	public function test_template_application_keeps_the_inactive_body(): void {
		$id = $this->make_campaign(
			array(
				'content' => '<!-- wp:paragraph --><p>Old blocks</p><!-- /wp:paragraph -->',
				'html'    => '<p>Old HTML</p>',
			)
		);
		update_post_meta( $id, Campaigns::META_MODE, Campaigns::MODE_BLOCKS );

		$template = ( new TemplateRepository() )->create(
			array(
				'name'      => 'Blocks template',
				'subject'   => 'Template subject',
				'preheader' => 'Template preview',
				'mode'      => Campaigns::MODE_BLOCKS,
				'blocks'    => '<!-- wp:paragraph --><p>Template blocks</p><!-- /wp:paragraph -->',
				'html'      => '<p>Template HTML</p>',
				'status'    => TemplateRepository::STATUS_PUBLISH,
			)
		);
		$this->assertIsInt( $template );

		$revision = $this->api( 'GET', $id )->get_data()['revision'];
		$response = $this->api( 'POST', $id, '', array( 'template_id' => $template, 'revision' => $revision ) );
		$this->assertSame( 200, $response->get_status() );

		$draft = $response->get_data()['draft'];
		$this->assertSame( Campaigns::MODE_BLOCKS, $draft['mode'] );
		$this->assertSame( '<!-- wp:paragraph --><p>Template blocks</p><!-- /wp:paragraph -->', $draft['blocks'] );
		$this->assertSame( '<p>Old HTML</p>', $draft['html'], 'Applying a block template must keep the saved HTML body.' );
		$this->assertSame( 'Template subject', $draft['subject'] );
	}

	public function test_unsaved_block_preview_applies_design_without_saving(): void {
		$id = $this->make_campaign( array( 'content' => '<!-- wp:paragraph --><p>Saved</p><!-- /wp:paragraph>' ) );
		update_post_meta( $id, Campaigns::META_MODE, Campaigns::MODE_BLOCKS );
		update_post_meta( $id, Campaigns::META_STATUS, Campaigns::STATUS_DRAFT );

		$before   = Campaigns::draft( Campaigns::get( $id ) );
		$revision = Campaigns::revision( $before );
		$email    = wp_get_current_user()->user_email;
		$blocks   = '<!-- wp:heading --><h2>促銷</h2><!-- /wp:heading -->'
			. '<!-- wp:paragraph --><p>{{email}}</p><!-- /wp:paragraph -->'
			. '<!-- wp:paragraph --><p style="color:#ff0000">指定色</p><!-- /wp:paragraph -->';

		$response = $this->api(
			'POST',
			$id,
			'/preview',
			array(
				'blocks' => $blocks,
				'design' => array(
					'body'    => '#101112',
					'content' => '#202122',
					'heading' => '#303132',
					'text'    => '#405060',
					'link'    => '#506070',
				),
			)
		);
		$this->assertSame( 200, $response->get_status() );
		$html = $response->get_data()['html'];

		$this->assertStringContainsString( '促銷', $html, 'Unsaved block content must drive the preview.' );
		$this->assertStringContainsString( esc_html( $email ), $html, 'Preview must substitute real smartcodes.' );
		$this->assertStringContainsString( 'background:#101112', $html );
		$this->assertStringContainsString( 'background:#202122', $html );
		$this->assertStringContainsString( 'color:#303132', $html );
		$this->assertStringContainsString( 'color:#405060', $html );
		$this->assertStringContainsString( 'color:#ff0000', $html, 'Explicit block colours must win.' );

		$after = Campaigns::draft( Campaigns::get( $id ) );
		$this->assertSame( $before['blocks'], $after['blocks'], 'Preview must never store unsaved blocks.' );
		$this->assertSame( array( 'body' => '', 'content' => '', 'heading' => '', 'text' => '', 'link' => '' ), $after['design'] );
		$this->assertSame( $revision, Campaigns::revision( $after ), 'Preview must not advance the draft revision.' );

		$this->assertSame( 400, $this->api( 'POST', $id, '/preview', array( 'html' => '<p>x</p>', 'blocks' => $blocks ) )->get_status(), 'Exactly one preview payload is valid.' );
		$this->assertSame( 400, $this->api( 'POST', $id, '/preview', array() )->get_status() );
		$this->assertSame( 200, $this->api( 'POST', $id, '/preview', array( 'html' => '' ) )->get_status(), 'A blank HTML body is still a valid preview payload.' );
	}

	public function test_design_roundtrip_is_sanitized_preserved_and_frozen(): void {
		$id = $this->make_campaign( array( 'content' => '<!-- wp:paragraph --><p>Saved</p><!-- /wp:paragraph>' ) );
		update_post_meta( $id, Campaigns::META_MODE, Campaigns::MODE_BLOCKS );
		update_post_meta( $id, Campaigns::META_STATUS, Campaigns::STATUS_DRAFT );

		$original        = $this->api( 'GET', $id )->get_data();
		$draft           = $original['draft'];
		$draft['mode']   = Campaigns::MODE_BLOCKS;
		$draft['blocks'] = '<!-- wp:heading --><h2>標題</h2><!-- /wp:heading -->'
			. '<!-- wp:paragraph --><p style="font-size:18px">大小</p><!-- /wp:paragraph -->'
			. '<!-- wp:paragraph --><p style="color:#ff0000">指定色</p><!-- /wp:paragraph -->';
		$draft['design'] = array(
			'body'    => '#AABBCC',
			'content' => '#112233',
			'heading' => '#0a0b0c',
			'text'    => '#1a2b3c',
			'link'    => 'red',
			'bogus'   => '#ffffff',
		);

		$saved = $this->api( 'PUT', $id, '', array( 'draft' => $draft, 'revision' => $original['revision'] ) );
		$this->assertSame( 200, $saved->get_status() );
		$stored = $saved->get_data()['draft']['design'];
		$this->assertSame( '#aabbcc', $stored['body'], 'Hex colours are normalized to lowercase.' );
		$this->assertSame( '#112233', $stored['content'] );
		$this->assertSame( '#0a0b0c', $stored['heading'] );
		$this->assertSame( '#1a2b3c', $stored['text'] );
		$this->assertSame( '', $stored['link'], 'Non-hex values are dropped.' );
		$this->assertArrayNotHasKey( 'bogus', $stored, 'Unknown design keys are not stored.' );

		$this->assertSame(
			409,
			$this->api( 'PUT', $id, '', array( 'draft' => $draft, 'revision' => $original['revision'] ) )->get_status(),
			'Design saves keep the revision guard.'
		);

		// A caller that omits design (for example a Raw HTML save) must keep it.
		$current = $saved->get_data()['draft'];
		unset( $current['design'] );
		$current['mode'] = Campaigns::MODE_HTML;
		$raw = $this->api( 'PUT', $id, '', array( 'draft' => $current, 'revision' => $saved->get_data()['revision'] ) );
		$this->assertSame( 200, $raw->get_status() );
		$this->assertSame( '#aabbcc', $raw->get_data()['draft']['design']['body'], 'Switching to Raw HTML must keep the inactive design.' );
		$this->assertSame( Campaigns::MODE_HTML, $raw->get_data()['draft']['mode'] );

		$current                   = $raw->get_data()['draft'];
		$current['mode']           = Campaigns::MODE_BLOCKS;
		$current['design']['link'] = '#445566';
		$blocked = $this->api( 'PUT', $id, '', array( 'draft' => $current, 'revision' => $raw->get_data()['revision'] ) );
		$this->assertSame( 200, $blocked->get_status() );
		$frozen_draft = $blocked->get_data()['draft'];
		$frozen       = Campaigns::snapshot( $id, $frozen_draft );
		$this->assertSame( '#aabbcc', $frozen['design']['body'], 'The snapshot freezes the design with the content.' );

		$later                   = $frozen_draft;
		$later['design']['body'] = '#999999';
		$later['design']['text'] = '#888888';
		$updated = $this->api( 'PUT', $id, '', array( 'draft' => $later, 'revision' => $blocked->get_data()['revision'] ) );
		$this->assertSame( 200, $updated->get_status() );
		$this->assertSame( '#aabbcc', $frozen['design']['body'], 'Later draft edits cannot change a frozen snapshot.' );
		$this->assertSame( '#999999', $updated->get_data()['draft']['design']['body'] );

		$rendered = ( new EmailRenderer() )->render(
			$frozen,
			array(
				'first_name'      => 'A',
				'email'           => 'a@example.test',
				'site_name'       => 'Site',
				'site_url'        => 'https://example.test',
				'unsubscribe_url' => '#unsubscribe',
			)
		)['html'];

		$this->assertStringContainsString( 'background:#aabbcc', $rendered, 'The frozen wrapper background is inlined.' );
		$this->assertStringContainsString( 'background:#112233', $rendered, 'The frozen content background is inlined.' );
		$this->assertStringContainsString( 'color:#0a0b0c', $rendered, 'Headings use the frozen heading colour.' );
		$this->assertStringContainsString( 'font-size:18px;color:#1a2b3c', $rendered, 'A size-only block style still gets the global text colour.' );
		$this->assertStringContainsString( 'style="color:#ff0000"', $rendered, 'An explicit block colour overrides the global text colour.' );
		$this->assertStringContainsString( 'color:#445566', $rendered, 'Links use the frozen link colour.' );
	}

	public function test_draft_read_exposes_advanced_catalog_and_recipient_options_are_draft_only(): void {
		$id = $this->make_campaign();
		$read = $this->api( 'GET', $id )->get_data();

		$this->assertArrayHasKey( 'advanced', $read, 'Draft reads expose the advanced catalog next to lists, tags, and segments.' );
		foreach ( array( 'fields', 'operators', 'providers', 'limits' ) as $key ) {
			$this->assertArrayHasKey( $key, $read['advanced'] );
		}
		$this->assertArrayHasKey( 'contact.first_name', $read['advanced']['fields'] );
		$this->assertSame( 'text', $read['advanced']['fields']['contact.first_name']['type'] );
		$this->assertContains( '=', $read['advanced']['fields']['contact.first_name']['ops'] );
		$this->assertArrayHasKey( 'segment.tags', $read['advanced']['fields'] );
		$this->assertArrayHasKey( 'email.sent', $read['advanced']['fields'] );
		$this->assertTrue( $read['advanced']['fields']['email.sent']['lookup'] );
		$this->assertArrayHasKey( Status::SUBSCRIBED, $read['advanced']['fields']['segment.status']['options'], 'Catalog options are value to label maps.' );
		$this->assertSame( 5, $read['advanced']['limits']['groups'] );
		$this->assertArrayHasKey( 'contact', $read['advanced']['providers'] );

		$unknown = $this->api( 'GET', $id, '/recipient-options', array( 'field' => 'contact.shoe_size' ) );
		$this->assertSame( 422, $unknown->get_status() );
		$this->assertSame( 'nowcrm_invalid_field', $unknown->get_data()['code'] );
		$this->assertSame( 422, $this->api( 'GET', $id, '/recipient-options', array( 'field' => 'contact.first_name' ) )->get_status(), 'A field without options must not invent any.' );

		$sent = $this->make_campaign( array( 'title' => 'Lookupable Broadcast' ) );
		$this->make_contact();
		$this->assertSame( 200, $this->action( $sent, 'confirm', array( 'token' => $this->review( $sent )['token'] ) )->get_status() );
		( new Queue() )->run();
		$this->assertSame( 'sent', Campaigns::state( $sent ) );

		$options = $this->api( 'GET', $id, '/recipient-options', array( 'field' => 'email.sent', 'search' => 'Lookupable' ) );
		$this->assertSame( 200, $options->get_status() );
		$this->assertContains( (string) $sent, array_column( $options->get_data()['options'], 'value' ) );
		$this->assertStringContainsString( 'Lookupable Broadcast', implode( ' ', array_column( $options->get_data()['options'], 'label' ) ) );

		$selected = $this->api( 'GET', $id, '/recipient-options', array( 'field' => 'email.sent', 'search' => 'no-such-title', 'selected' => (string) $sent ) );
		$this->assertSame( 200, $selected->get_status() );
		$this->assertContains( (string) $sent, array_column( $selected->get_data()['options'], 'value' ), 'Selected values load next to the search hits.' );

		$this->assertSame( 409, $this->api( 'GET', $sent, '/recipient-options', array( 'field' => 'email.sent' ) )->get_status(), 'Options are draft-only.' );
		$this->assertSame( 409, $this->api( 'POST', $sent, '/recipients', array( 'audience' => array( 'scope' => 'all', 'include' => array(), 'exclude' => array() ) ) )->get_status() );

		wp_set_current_user( 0 );
		$this->assertContains( $this->api( 'GET', $id, '/recipient-options', array( 'field' => 'email.sent' ) )->get_status(), array( 401, 403 ) );
		$this->assertContains( $this->api( 'POST', $id, '/recipients', array( 'audience' => array( 'scope' => 'all', 'include' => array(), 'exclude' => array() ) ) )->get_status(), array( 401, 403 ) );
	}

	public function test_recipients_endpoint_pages_unsaved_audiences_without_writing(): void {
		$id = $this->make_campaign();
		for ( $i = 0; $i < 25; ++$i ) {
			$this->make_contact( array( 'first_name' => 'Pager' ) );
		}

		// A blank message still estimates recipients: no content is needed.
		update_post_meta( $id, Campaigns::META_SUBJECT, '' );
		update_post_meta( $id, Campaigns::META_HTML, '' );

		$before = $this->api( 'GET', $id )->get_data();
		$audience = array(
			'scope'   => 'advanced',
			'include' => array(),
			'exclude' => array(),
			'filters' => array(
				'version' => 1,
				'groups'  => array(
					array(
						array( 'provider' => 'contact', 'field' => 'first_name', 'operator' => '=', 'value' => 'Pager' ),
					),
				),
			),
		);

		$last = $this->api( 'POST', $id, '/recipients', array( 'audience' => $audience, 'page' => 9 ) );
		$this->assertSame( 200, $last->get_status() );
		$page = $last->get_data();
		$this->assertSame( 25, $page['count'] );
		$this->assertSame( 2, $page['page'], 'A page past the end clamps to the last valid page.' );
		$this->assertSame( 20, $page['per_page'] );
		$this->assertCount( 5, $page['recipients'] );
		$this->assertSame( array( 'id', 'email', 'name' ), array_keys( $page['recipients'][0] ) );

		$this->assertCount( 20, $this->api( 'POST', $id, '/recipients', array( 'audience' => $audience, 'page' => 1 ) )->get_data()['recipients'] );

		$empty = $this->api( 'POST', $id, '/recipients', array( 'audience' => array( 'scope' => 'advanced', 'include' => array(), 'exclude' => array(), 'filters' => array() ) ) );
		$this->assertSame( 0, $empty->get_data()['count'], 'An empty advanced plan matches nobody.' );
		$this->assertSame( 25, $this->api( 'POST', $id, '/recipients', array( 'audience' => array( 'scope' => 'all', 'include' => array(), 'exclude' => array() ) ) )->get_data()['count'] );

		$malformed = $audience;
		$malformed['filters']['groups'][0][0]['field'] = 'shoe_size';
		$rejected = $this->api( 'POST', $id, '/recipients', array( 'audience' => $malformed ) );
		$this->assertSame( 422, $rejected->get_status() );
		$this->assertSame( 'nowcrm_invalid_audience', $rejected->get_data()['code'] );

		$after = $this->api( 'GET', $id )->get_data();
		$this->assertSame( $before['revision'], $after['revision'], 'Estimates never advance the draft revision.' );
		$this->assertSame( $before['draft']['audience'], $after['draft']['audience'] );
	}

	public function test_recipients_count_only_returns_only_the_count_for_unsaved_rules(): void {
		$id = $this->make_campaign();
		for ( $i = 0; $i < 5; ++$i ) {
			$this->make_contact( array( 'first_name' => 'Counter' ) );
		}
		for ( $i = 0; $i < 3; ++$i ) {
			$this->make_contact( array( 'first_name' => 'Other' ) );
		}

		$before   = $this->api( 'GET', $id )->get_data();
		$audience = array(
			'scope'   => 'advanced',
			'include' => array(),
			'exclude' => array(),
			'filters' => array(
				'version' => 1,
				'groups'  => array(
					array(
						array( 'provider' => 'contact', 'field' => 'first_name', 'operator' => '=', 'value' => 'Counter' ),
					),
				),
			),
		);

		$response = $this->api( 'POST', $id, '/recipients', array( 'audience' => $audience, 'count_only' => true, 'page' => 3 ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( 'count' => 5 ), $response->get_data(), 'Count-only returns no recipient rows and ignores the page.' );

		$saved = $this->api( 'POST', $id, '/recipients', array( 'audience' => $before['draft']['audience'], 'count_only' => true ) );
		$this->assertSame( 8, $saved->get_data()['count'], 'The unsaved rule is narrower than the saved audience.' );

		$zero = $audience;
		$zero['filters']['groups'][0][0]['value'] = 'Nobody';
		$this->assertSame(
			array( 'count' => 0 ),
			$this->api( 'POST', $id, '/recipients', array( 'audience' => $zero, 'count_only' => true ) )->get_data(),
			'A rule matching nobody still answers with a count.'
		);

		$invalid = $audience;
		$invalid['filters']['groups'][0][0]['field'] = 'shoe_size';
		$rejected = $this->api( 'POST', $id, '/recipients', array( 'audience' => $invalid, 'count_only' => true ) );
		$this->assertSame( 422, $rejected->get_status() );
		$this->assertSame( 'nowcrm_invalid_audience', $rejected->get_data()['code'], 'Count-only still validates the rule.' );

		$sent = $this->make_campaign( array( 'status' => Campaigns::STATUS_SENT ) );
		$this->assertSame( 409, $this->api( 'POST', $sent, '/recipients', array( 'audience' => $audience, 'count_only' => true ) )->get_status(), 'Count-only stays draft-only.' );

		$after = $this->api( 'GET', $id )->get_data();
		$this->assertSame( $before['revision'], $after['revision'], 'Count-only estimates never advance the draft revision.' );
		$this->assertSame( $before['draft']['audience'], $after['draft']['audience'], 'Count-only estimates never save the audience.' );
	}

	public function test_saved_advanced_and_segment_audiences_agree_across_review_queue_and_duplicate(): void {
		SegmentRepository::register_post_type();
		$segments = new SegmentRepository();
		$tag = $this->terms->create( 'tag', 'VIP' );

		$alice = $this->make_contact( array( 'first_name' => 'Alice' ) );
		$bob = $this->make_contact( array( 'first_name' => 'Bob' ) );
		$this->terms->attach( $bob, $tag );
		$carol = $this->make_contact( array( 'first_name' => 'Carol' ) );

		$id = $this->make_campaign();
		$read = $this->api( 'GET', $id )->get_data();
		$draft = $read['draft'];
		$draft['audience'] = array(
			'scope'   => 'advanced',
			'include' => array(),
			'exclude' => array(),
			'filters' => array(
				'version' => 1,
				'groups'  => array(
					array( array( 'provider' => 'contact', 'field' => 'first_name', 'operator' => '=', 'value' => 'Alice' ) ),
					array( array( 'provider' => 'segment', 'field' => 'tags', 'operator' => 'in', 'value' => array( $tag ) ) ),
				),
			),
		);

		$saved = $this->api( 'PUT', $id, '', array( 'draft' => $draft, 'revision' => $read['revision'] ) );
		$this->assertSame( 200, $saved->get_status() );
		$stored_audience = $saved->get_data()['draft']['audience'];
		$this->assertSame( 'advanced', $stored_audience['scope'] );
		$this->assertCount( 2, $stored_audience['filters']['groups'] );
		$this->assertSame( $saved->get_data()['revision'], $this->api( 'GET', $id )->get_data()['revision'], 'Reading back the saved plan keeps the revision stable.' );

		$review = $this->review( $id );
		$this->assertSame( 2, $review['count'] );
		$this->assertSame( 200, $this->action( $id, 'confirm', array( 'token' => $review['token'] ) )->get_status() );

		$queued = array_map( 'intval', array_column( $this->jobs->campaign_recipients( $id ), 'contact_id' ) );
		$expected = array( $alice, $bob );
		sort( $queued );
		sort( $expected );
		$this->assertSame( $expected, $queued, 'Review and queued jobs use the same advanced membership.' );

		$duplicate = $this->api( 'POST', $id, '/duplicate' );
		$this->assertSame( 201, $duplicate->get_status() );
		$url = $duplicate->get_data()['url'];
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
		$copy = (int) ( $query['campaign'] ?? 0 );
		$this->assertGreaterThan( 0, $copy );
		$this->assertSame( $stored_audience, $this->api( 'GET', $copy )->get_data()['draft']['audience'], 'Duplication preserves the advanced audience and exclusions.' );

		// Same-sized membership drift must invalidate the review token.
		$second = $this->make_campaign();
		$second_read = $this->api( 'GET', $second )->get_data();
		$second_draft = $second_read['draft'];
		$second_draft['audience'] = $stored_audience;
		$this->assertSame( 200, $this->api( 'PUT', $second, '', array( 'draft' => $second_draft, 'revision' => $second_read['revision'] ) )->get_status() );
		$second_review = $this->review( $second );
		$this->assertSame( 2, $second_review['count'] );
		$this->contacts->set_status( $alice, Status::UNSUBSCRIBED, 'test' );
		$replacement = $this->make_contact( array( 'first_name' => 'Bob' ) );
		$this->terms->attach( $replacement, $tag );
		$drift = $this->action( $second, 'confirm', array( 'token' => $second_review['token'] ) );
		$this->assertSame( 409, $drift->get_status() );
		$this->assertSame( 'nowcrm_review_changed', $drift->get_data()['code'] );
		$this->assertSame( 'draft', Campaigns::state( $second ) );
		$this->assertSame( 0, $this->jobs->campaign_stats( $second )['total'], 'A rejected confirmation rolls back its jobs.' );

		$segment = $segments->create(
			array(
				'name'    => 'Carols',
				'filters' => array( array( array( 'provider' => 'contact', 'field' => 'first_name', 'operator' => '=', 'value' => 'Carol' ) ) ),
			)
		);
		$third = $this->make_campaign();
		$third_read = $this->api( 'GET', $third )->get_data();
		$third_draft = $third_read['draft'];
		$third_draft['audience'] = array(
			'scope'   => 'segments',
			'include' => array(),
			'exclude' => array(),
			'segment' => 'custom:' . $segment,
		);
		$third_saved = $this->api( 'PUT', $third, '', array( 'draft' => $third_draft, 'revision' => $third_read['revision'] ) );
		$this->assertSame( 200, $third_saved->get_status() );
		$this->assertSame( 'custom:' . $segment, $third_saved->get_data()['draft']['audience']['segment'] );
		$this->assertArrayNotHasKey( 'filters', $third_saved->get_data()['draft']['audience'] );

		$third_review = $this->review( $third );
		$this->assertSame( 1, $third_review['count'] );
		$this->assertSame( 200, $this->action( $third, 'confirm', array( 'token' => $third_review['token'] ) )->get_status() );
		$this->assertSame( array( $carol ), array_map( 'intval', array_column( $this->jobs->campaign_recipients( $third ), 'contact_id' ) ) );
	}
}
