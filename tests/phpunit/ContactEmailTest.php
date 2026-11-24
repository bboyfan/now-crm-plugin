<?php
/**
 * Contact email history, preview, and one-off queue tests.
 *
 * @package NowCRM
 */

use NowCRM\Admin\ContactEmailPanel;
use NowCRM\Admin\Menu;
use NowCRM\Campaign\Campaigns;
use NowCRM\Contacts\Status;
use NowCRM\Email\ContactHistory;
use NowCRM\Email\EmailDispatcher;
use NowCRM\Email\EmailJobRepository;
use NowCRM\Email\Queue;
use NowCRM\Email\SuppressionRepository;
use NowCRM\Email\Tracking;
use NowCRM\Support\Schema;

/**
 * Sentinel thrown by the redirect filter so a handler never reaches exit().
 */
final class NowCrm_Email_Redirect_Captured extends \RuntimeException {}

/**
 * @covers \NowCRM\Email\ContactHistory
 * @covers \NowCRM\Admin\ContactEmailPanel
 */
final class ContactEmailTest extends NowCrmTestCase {

	private EmailJobRepository $jobs;

	private int $actor;

	public function set_up(): void {
		parent::set_up();

		$this->jobs  = new EmailJobRepository();
		$this->actor = (int) self::factory()->user->create( array( 'role' => 'administrator' ) );

		wp_set_current_user( $this->actor );
	}

	public function tear_down(): void {
		$_POST    = array();
		$_REQUEST = array();
		wp_set_current_user( 0 );

		parent::tear_down();
	}

	public function test_history_is_scoped_to_the_contact_and_never_leaks_other_jobs(): void {
		$owner    = $this->make_contact( array( 'email' => 'owner@example.test' ) );
		$intruder = $this->make_contact( array( 'email' => 'intruder@example.test' ) );

		$owned       = $this->enqueue_job( $owner, 'owner@example.test', 'campaign' );
		$intruder_job = $this->enqueue_job( $intruder, 'intruder@example.test', 'campaign' );
		$owned_null  = $this->enqueue_job( 0, 'owner@example.test', 'test' );
		$intruder_null = $this->enqueue_job( 0, 'intruder@example.test', 'test' );
		// A job assigned to another contact never becomes visible through the address.
		$foreign = $this->enqueue_job( $intruder, 'owner@example.test', 'cart' );

		$history = new ContactHistory();
		$result  = $history->search( $owner, 'owner@example.test' );
		$ids     = array_map( 'intval', wp_list_pluck( $result['items'], 'id' ) );

		$this->assertSame( 2, $result['total'] );
		$this->assertContains( $owned, $ids );
		$this->assertContains( $owned_null, $ids );
		$this->assertNotContains( $intruder_job, $ids );
		$this->assertNotContains( $foreign, $ids );

		$other = $history->search( $intruder, 'intruder@example.test' );
		$other_ids = array_map( 'intval', wp_list_pluck( $other['items'], 'id' ) );

		$this->assertSame( 3, $other['total'] );
		$this->assertContains( $intruder_job, $other_ids );
		$this->assertContains( $intruder_null, $other_ids );
		$this->assertContains( $foreign, $other_ids );
		$this->assertNotContains( $owned_null, $other_ids );
	}

	public function test_status_and_kind_filters_and_counts(): void {
		$contact = $this->make_contact( array( 'email' => 'filters@example.test' ) );

		$campaign = $this->enqueue_job( $contact, 'filters@example.test', 'campaign' );
		$custom   = $this->enqueue_job( $contact, 'filters@example.test', ContactHistory::KIND_CUSTOM );

		$this->set_job_status( $campaign, EmailJobRepository::STATUS_FAILED );
		$this->set_job_status( $custom, EmailJobRepository::STATUS_ACCEPTED );

		$history = new ContactHistory();
		$counts  = $history->counts( $contact, 'filters@example.test' );

		$this->assertSame( 2, $counts['total'] );
		$this->assertSame( 1, $counts[ EmailJobRepository::STATUS_FAILED ] );
		$this->assertSame( 1, $counts[ EmailJobRepository::STATUS_ACCEPTED ] );
		$this->assertSame( 0, $counts[ EmailJobRepository::STATUS_QUEUED ] );

		$failed = $history->search( $contact, 'filters@example.test', array( 'status' => 'failed' ) );
		$this->assertSame( 1, $failed['total'] );
		$this->assertSame( $campaign, (int) $failed['items'][0]['id'] );

		$only_custom = $history->search( $contact, 'filters@example.test', array( 'kind' => ContactHistory::KIND_CUSTOM ) );
		$this->assertSame( 1, $only_custom['total'] );
		$this->assertSame( $custom, (int) $only_custom['items'][0]['id'] );

		// Unknown filter values are ignored, not passed to SQL.
		$unfiltered = $history->search( $contact, 'filters@example.test', array( 'status' => 'bogus', 'kind' => 'bogus' ) );
		$this->assertSame( 2, $unfiltered['total'] );
	}

	public function test_paging_is_bounded_and_reports_pages(): void {
		$contact = $this->make_contact( array( 'email' => 'paging@example.test' ) );

		for ( $i = 0; $i < 21; $i++ ) {
			$this->enqueue_job( $contact, 'paging@example.test', 'test', array(), 'Fixture ' . $i );
		}

		$history = new ContactHistory();
		$first   = $history->search( $contact, 'paging@example.test', array( 'page' => 1 ) );

		$this->assertSame( 21, $first['total'] );
		$this->assertSame( 2, $first['pages'] );
		$this->assertSame( 1, $first['page'] );
		$this->assertCount( 20, $first['items'] );

		$second = $history->search( $contact, 'paging@example.test', array( 'page' => 2 ) );
		$this->assertCount( 1, $second['items'] );

		$clamped = $history->search( $contact, 'paging@example.test', array( 'page' => 999 ) );
		$this->assertSame( 2, $clamped['page'] );
		$this->assertCount( 1, $clamped['items'] );
	}

	public function test_read_and_preview_do_not_mutate_jobs_or_issue_tokens(): void {
		$contact = $this->make_contact( array( 'email' => 'readonly@example.test', 'first_name' => 'Alice' ) );
		$job_id  = $this->enqueue_job(
			$contact,
			'readonly@example.test',
			'cart',
			array(
				'template' => array(
					'mode'    => 'html',
					'content' => '<p>Hello {{first_name}}</p>',
					'subject' => 'Hi {{first_name}}',
				),
			),
			'Hi'
		);

		$this->set_job_status( $job_id, EmailJobRepository::STATUS_ACCEPTED );

		$before = $this->table_count( 'email_jobs' );
		$tokens = $this->table_count( 'unsubscribe_tokens' );

		$history = new ContactHistory();
		$history->counts( $contact, 'readonly@example.test' );
		$history->search( $contact, 'readonly@example.test' );
		$preview = $history->preview( $contact, 'readonly@example.test', $job_id );

		$this->assertNotNull( $preview );
		$this->assertSame( 'Hi Alice', $preview['subject'] );
		$this->assertStringContainsString( 'Hello Alice', $preview['html'] );
		$this->assertStringNotContainsString( '/nowcrm/v1/open/', $preview['html'] );
		$this->assertStringNotContainsString( 'nowcrm_unsubscribe', $preview['html'] );

		$this->assertSame( $before, $this->table_count( 'email_jobs' ) );
		$this->assertSame( $tokens, $this->table_count( 'unsubscribe_tokens' ) );
		$this->assertSame( EmailJobRepository::STATUS_ACCEPTED, $this->jobs->get( $job_id )['status'] );
	}

	public function test_preview_of_another_contacts_job_is_unavailable(): void {
		$owner    = $this->make_contact( array( 'email' => 'preview-owner@example.test' ) );
		$intruder = $this->make_contact( array( 'email' => 'preview-intruder@example.test' ) );

		$job_id = $this->enqueue_job(
			$intruder,
			'preview-intruder@example.test',
			'cart',
			array( 'template' => array( 'mode' => 'html', 'content' => '<p>Secret</p>', 'subject' => 'Secret' ) )
		);

		$this->assertNull( ( new ContactHistory() )->preview( $owner, 'preview-owner@example.test', $job_id ) );
	}

	public function test_preview_uses_the_campaign_snapshot_for_campaign_jobs(): void {
		$contact  = $this->make_contact( array( 'email' => 'campaign-preview@example.test', 'first_name' => 'Alice' ) );
		$campaign = $this->make_campaign( array( 'html' => '<p>Campaign for {{first_name}}</p>', 'subject' => 'Campaign subject' ) );

		update_post_meta(
			$campaign,
			Campaigns::META_SNAPSHOT,
			wp_slash( (string) wp_json_encode( Campaigns::snapshot( $campaign, Campaigns::draft( get_post( $campaign ) ) ) ) )
		);

		$job_id = $this->enqueue_job( $contact, 'campaign-preview@example.test', 'campaign', array(), 'Campaign subject', $campaign );

		$preview = ( new ContactHistory() )->preview( $contact, 'campaign-preview@example.test', $job_id );

		$this->assertNotNull( $preview );
		$this->assertStringContainsString( 'Campaign for Alice', $preview['html'] );
	}

	public function test_open_and_click_events_map_to_jobs(): void {
		$contact = $this->make_contact( array( 'email' => 'events@example.test' ) );
		$job_id  = $this->enqueue_job( $contact, 'events@example.test', 'campaign' );

		$tracking = new Tracking();
		$tracking->record_open( array( 'j' => $job_id, 'c' => 0, 't' => $contact ) );
		$tracking->record_click( array( 'j' => $job_id, 'c' => 0, 't' => $contact ), 'https://example.test/offer' );

		$events = ( new ContactHistory() )->events( array( $job_id, 999999 ) );

		$this->assertSame( 1, $events[ $job_id ]['open'] );
		$this->assertSame( 1, $events[ $job_id ]['click'] );
		$this->assertSame( 0, $events[999999]['open'] );
		$this->assertSame( 0, $events[999999]['click'] );
	}

	public function test_one_off_creates_one_real_queued_job_per_request_id(): void {
		$contact = $this->make_contact( array( 'email' => 'one-off@example.test', 'first_name' => 'Alice', 'last_name' => 'Chen' ) );
		$request = wp_generate_uuid4();

		$history = new ContactHistory();
		$input   = array(
			'subject'    => '問候 Alice',
			'body'       => '<p>Hello {{first_name}}, this is a real queue job.</p>',
			'request_id' => $request,
		);

		$first  = $history->queue_one_off( $contact, $input, $this->actor );
		$second = $history->queue_one_off( $contact, $input, $this->actor );

		$this->assertIsInt( $first );
		$this->assertSame( $first, $second );
		$this->assertSame( 1, $this->job_count( $contact ) );

		$job = $this->jobs->get( (int) $first );

		$this->assertSame( ContactHistory::KIND_CUSTOM, $job['kind'] );
		$this->assertSame( EmailJobRepository::STATUS_QUEUED, $job['status'] );
		$this->assertSame( $contact, $job['contact_id'] );
		$this->assertSame( 'one-off@example.test', $job['recipient'] );
		$this->assertSame( '問候 Alice', $job['subject'] );
		$this->assertSame( 'contact_one_off', $job['payload']['channel'] );
		$this->assertSame( $request, $job['payload']['request_id'] );
		$this->assertStringContainsString( 'real queue job', $job['payload']['template']['content'] );
		$this->assertSame( 'html', $job['payload']['template']['mode'] );

		$preview = $history->preview( $contact, 'one-off@example.test', (int) $first );
		$this->assertNotNull( $preview );
		$this->assertStringContainsString( 'Hello Alice', $preview['html'] );

		$this->assertSame( array(), get_option( EmailDispatcher::CAPTURE_OPTION, array() ) );
	}

	public function test_one_off_stays_queued_without_mail_when_sending_is_disabled(): void {
		$contact = $this->make_contact( array( 'email' => 'disabled@example.test' ) );

		$this->set_settings( array( 'email_sending_enabled' => false, 'email_transport' => 'capture' ) );

		$job_id = ( new ContactHistory() )->queue_one_off(
			$contact,
			array(
				'subject'    => 'Disabled send',
				'body'       => '<p>Must stay queued.</p>',
				'request_id' => wp_generate_uuid4(),
			),
			$this->actor
		);

		$this->assertIsInt( $job_id );

		( new Queue() )->run();

		$this->assertSame( EmailJobRepository::STATUS_QUEUED, $this->jobs->get( (int) $job_id )['status'] );
		$this->assertSame( array(), get_option( EmailDispatcher::CAPTURE_OPTION, array() ) );
	}

	public function test_one_off_refuses_a_contact_who_is_not_subscribed_without_changing_status(): void {
		$contact = $this->make_contact(
			array(
				'email'  => 'transactional@example.test',
				'status' => Status::TRANSACTIONAL,
			)
		);

		$result = ( new ContactHistory() )->queue_one_off(
			$contact,
			array(
				'subject'    => 'Not allowed',
				'body'       => '<p>Marketing needs consent.</p>',
				'request_id' => wp_generate_uuid4(),
			),
			$this->actor
		);

		$this->assertWPError( $result );
		$this->assertSame( 'nowcrm_not_subscribed', $result->get_error_code() );
		$this->assertSame( 0, $this->job_count( $contact ) );
		$this->assertSame( Status::TRANSACTIONAL, $this->contacts->get( $contact )['status'] );
	}

	public function test_one_off_refuses_a_suppressed_address_and_keeps_suppression(): void {
		$contact = $this->make_contact( array( 'email' => 'suppressed@example.test' ) );

		$this->assertTrue( ( new SuppressionRepository() )->add( 'suppressed@example.test', 'manual', 'test' ) );

		$result = ( new ContactHistory() )->queue_one_off(
			$contact,
			array(
				'subject'    => 'Blocked',
				'body'       => '<p>Suppressed address.</p>',
				'request_id' => wp_generate_uuid4(),
			),
			$this->actor
		);

		$this->assertWPError( $result );
		$this->assertSame( 'nowcrm_suppressed', $result->get_error_code() );
		$this->assertSame( 0, $this->job_count( $contact ) );
		$this->assertSame( Status::SUBSCRIBED, $this->contacts->get( $contact )['status'] );
		$this->assertTrue( ( new SuppressionRepository() )->is_suppressed( 'suppressed@example.test' ) );
	}

	public function test_one_off_validates_request_id_subject_and_body(): void {
		$contact = $this->make_contact( array( 'email' => 'validation@example.test' ) );
		$history = new ContactHistory();

		$missing_request = $history->queue_one_off(
			$contact,
			array( 'subject' => 'Hi', 'body' => '<p>Body</p>', 'request_id' => 'not-a-uuid' ),
			$this->actor
		);
		$this->assertWPError( $missing_request );
		$this->assertSame( 'nowcrm_invalid_request', $missing_request->get_error_code() );

		$blank_subject = $history->queue_one_off(
			$contact,
			array( 'subject' => '   ', 'body' => '<p>Body</p>', 'request_id' => wp_generate_uuid4() ),
			$this->actor
		);
		$this->assertWPError( $blank_subject );
		$this->assertSame( 'nowcrm_invalid_subject', $blank_subject->get_error_code() );

		$blank_body = $history->queue_one_off(
			$contact,
			array( 'subject' => 'Hi', 'body' => "  \n  ", 'request_id' => wp_generate_uuid4() ),
			$this->actor
		);
		$this->assertWPError( $blank_body );
		$this->assertSame( 'nowcrm_invalid_body', $blank_body->get_error_code() );

		$too_large = $history->queue_one_off(
			$contact,
			array(
				'subject'    => 'Hi',
				'body'       => str_repeat( 'a', ContactHistory::MAX_BODY_BYTES + 1 ),
				'request_id' => wp_generate_uuid4(),
			),
			$this->actor
		);
		$this->assertWPError( $too_large );
		$this->assertSame( 'nowcrm_invalid_body', $too_large->get_error_code() );

		$this->assertSame( 0, $this->job_count( $contact ) );
	}

	public function test_handler_queues_and_redirects_to_the_queued_job(): void {
		$contact = $this->make_contact( array( 'email' => 'handler@example.test' ) );

		$this->post(
			array(
				'_wpnonce'   => wp_create_nonce( ContactEmailPanel::ACTION_SEND ),
				'contact_id' => $contact,
				'request_id' => wp_generate_uuid4(),
				'subject'    => 'Handler subject',
				'body'       => '<p>Handler body</p>',
			)
		);

		$location = $this->capture_redirect( static fn() => ContactEmailPanel::send_one_off() );

		$this->assertStringContainsString( 'page=' . Menu::CONTACT_SLUG, $location );
		$this->assertStringContainsString( 'nowcrm_email_notice=queued', $location );
		$this->assertStringContainsString( 'email_job=', $location );
		$this->assertSame( 1, $this->job_count( $contact ) );
	}

	public function test_handler_requires_capability_and_nonce(): void {
		$contact = $this->make_contact();

		$this->post(
			array(
				'contact_id' => $contact,
				'request_id' => wp_generate_uuid4(),
				'subject'    => 'No nonce',
				'body'       => '<p>Body</p>',
			)
		);

		try {
			ContactEmailPanel::send_one_off();
			$this->fail( 'A missing nonce must be rejected.' );
		} catch ( WPDieException $e ) {
			$this->addToAssertionCount( 1 );
		}

		wp_set_current_user( (int) self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$this->post(
			array(
				'_wpnonce'   => wp_create_nonce( ContactEmailPanel::ACTION_SEND ),
				'contact_id' => $contact,
				'request_id' => wp_generate_uuid4(),
				'subject'    => 'No capability',
				'body'       => '<p>Body</p>',
			)
		);

		$this->expectException( 'WPDieException' );

		ContactEmailPanel::send_one_off();
	}

	public function test_handler_redirects_not_subscribed_contact_without_creating_a_job(): void {
		$contact = $this->make_contact(
			array(
				'email'  => 'handler-transactional@example.test',
				'status' => Status::TRANSACTIONAL,
			)
		);

		$this->post(
			array(
				'_wpnonce'   => wp_create_nonce( ContactEmailPanel::ACTION_SEND ),
				'contact_id' => $contact,
				'request_id' => wp_generate_uuid4(),
				'subject'    => 'Blocked',
				'body'       => '<p>Blocked</p>',
			)
		);

		$location = $this->capture_redirect( static fn() => ContactEmailPanel::send_one_off() );

		$this->assertStringContainsString( 'nowcrm_email_notice=not_subscribed', $location );
		$this->assertSame( 0, $this->job_count( $contact ) );
		$this->assertSame( Status::TRANSACTIONAL, $this->contacts->get( $contact )['status'] );
	}

	/**
	 * Enqueue one job fixture.
	 *
	 * @param array<string, mixed> $payload Job payload.
	 */
	private function enqueue_job(
		int $contact_id,
		string $email,
		string $kind = 'campaign',
		array $payload = array(),
		string $subject = 'Fixture',
		int $campaign_id = 0
	): int {
		$job = array(
			'job_key'        => 'fixture:' . wp_generate_password( 24, false ),
			'kind'           => $kind,
			'contact_id'     => $contact_id,
			'recipient'      => $email,
			'recipient_name' => 'Fixture Recipient',
			'subject'        => $subject,
			'payload'        => $payload,
		);

		if ( $campaign_id > 0 ) {
			$job['campaign_id'] = $campaign_id;
		}

		$id = $this->jobs->enqueue( $job );

		$this->assertIsInt( $id );

		return (int) $id;
	}

	/**
	 * Force a job status without running the worker.
	 */
	private function set_job_status( int $job_id, string $status ): void {
		global $wpdb;

		$wpdb->update(
			Schema::table( 'email_jobs' ),
			array( 'status' => $status ),
			array( 'id' => $job_id ),
			array( '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Number of jobs owned by one contact row.
	 */
	private function job_count( int $contact_id ): int {
		return $this->table_count( 'email_jobs', 'contact_id = ' . (int) $contact_id );
	}

	/**
	 * Capture the wp_safe_redirect() target of a handler.
	 */
	private function capture_redirect( callable $handler ): string {
		$captured = '';
		$filter   = static function ( $location ) use ( &$captured ): void {
			$captured = (string) $location;
			throw new NowCrm_Email_Redirect_Captured( $captured );
		};

		add_filter( 'wp_redirect', $filter, 1 );

		try {
			$handler();
		} catch ( NowCrm_Email_Redirect_Captured $e ) {
			$captured = $e->getMessage();
		} finally {
			remove_filter( 'wp_redirect', $filter, 1 );
		}

		$this->assertNotSame( '', $captured, 'The handler did not redirect.' );

		return $captured;
	}

	/**
	 * Simulate a POST request with string form values.
	 *
	 * @param array<string, mixed> $fields Request fields.
	 */
	private function post( array $fields ): void {
		$normalize = static function ( $value ) use ( &$normalize ) {
			if ( is_array( $value ) ) {
				return array_map( $normalize, $value );
			}

			if ( null === $value ) {
				return '';
			}

			return is_scalar( $value ) ? (string) $value : $value;
		};

		$payload = array();

		foreach ( $fields as $key => $value ) {
			$payload[ $key ] = $normalize( $value );
		}

		$_POST    = $payload;
		$_REQUEST = $payload;
	}
}
