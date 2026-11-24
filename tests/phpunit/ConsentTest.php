<?php
/**
 * Consent layer: tokens, preference service, double opt-in, compliance.
 *
 * @package NowCRM
 */

use NowCRM\Contacts\Status;
use NowCRM\Campaign\Campaigns;
use NowCRM\Campaign\Delivery;
use NowCRM\Consent\Config;
use NowCRM\Consent\DoubleOptin;
use NowCRM\Consent\EntryPoints;
use NowCRM\Consent\Preferences;
use NowCRM\Consent\Throttle;
use NowCRM\Consent\Tokens;
use NowCRM\Email\Compliance;
use NowCRM\Email\EmailDispatcher;
use NowCRM\Email\EmailJobRepository;
use NowCRM\Email\EmailRenderer;
use NowCRM\Email\Queue;
use NowCRM\Email\SuppressionRepository;
use NowCRM\Terms\TermRepository;

/**
 * @covers \NowCRM\Consent\Tokens
 * @covers \NowCRM\Consent\Preferences
 * @covers \NowCRM\Consent\DoubleOptin
 * @covers \NowCRM\Email\Compliance
 */
final class ConsentTest extends NowCrmTestCase {

	private DoubleOptin $doi;
	private Preferences $prefs;
	private EmailJobRepository $jobs;

	public function set_up(): void {
		parent::set_up();

		global $wpdb;

		$this->doi   = new DoubleOptin();
		$this->prefs = new Preferences();
		$this->jobs  = new EmailJobRepository();

		Config::reset();
		delete_option( Config::LISTS_OPTION );
		delete_option( Config::PENDING_OPTION );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test helper, trusted prefix.
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'nowcrm_consent_throttle_%'" );
	}

	private function token( string $purpose, int $contact_id, string $email, int $ttl = 3600, array $extra = array() ): string {
		$issued = Tokens::issue( $purpose, $contact_id, $email, $ttl, $extra );

		$this->assertIsArray( $issued );

		return $issued['token'];
	}

	private function make_list( string $name ): int {
		$id = $this->terms->create( TermRepository::TYPE_LIST, $name );

		$this->assertIsInt( $id );

		return (int) $id;
	}

	public function test_token_is_purpose_scoped(): void {
		$contact = $this->make_contact();
		$email   = $this->contacts->get( $contact )['email'];

		$manage = $this->token( Tokens::PURPOSE_MANAGE, $contact, $email );

		$this->assertNotNull( Tokens::validate( $manage, Tokens::PURPOSE_MANAGE ) );
		$this->assertNull( Tokens::validate( $manage, Tokens::PURPOSE_CONFIRM ) );
		$this->assertNull( Tokens::validate( $manage, Tokens::PURPOSE_UNSUBSCRIBE ) );
		$this->assertNull( Tokens::validate( $manage, Tokens::PURPOSE_EMAIL_CHANGE ) );
	}

	public function test_token_rejects_tampering_and_expiry(): void {
		$contact = $this->make_contact();
		$email   = $this->contacts->get( $contact )['email'];

		$raw = $this->token( Tokens::PURPOSE_MANAGE, $contact, $email );

		$last = substr( $raw, -1 );
		$flip = substr( $raw, 0, -1 ) . ( 'a' === $last ? 'b' : 'a' );

		$this->assertNull( Tokens::decode( $flip ) );
		$this->assertNull( Tokens::decode( $raw . 'x' ) );
		$this->assertNull( Tokens::decode( '' ) );

		$expired = $this->token( Tokens::PURPOSE_MANAGE, $contact, $email, 0 );

		$this->assertNull( Tokens::decode( $expired ) );

		$fresh = $this->token( Tokens::PURPOSE_MANAGE, $contact, $email );

		$this->assertNotNull( Tokens::decode( $fresh ) );
	}

	public function test_public_list_sync_never_touches_private_memberships(): void {
		$public_a = $this->make_list( 'Consent Public A' );
		$private  = $this->make_list( 'Consent Private' );
		$other    = $this->make_list( 'Consent Other' );

		Config::update(
			array(
				'public_lists_mode' => 'selected',
				'public_list_ids'   => array( $public_a ),
			)
		);

		$contact = $this->make_contact();
		$this->terms->attach( $contact, $public_a );
		$this->terms->attach( $contact, $private );

		$result = $this->prefs->save_profile(
			$contact,
			array(
				'first_name' => 'Pref',
				'lists'      => array( $public_a, $other ),
			)
		);

		$this->assertNotWPError( $result );

		$member = array();

		foreach ( $this->terms->for_contact( $contact ) as $term ) {
			if ( TermRepository::TYPE_LIST === (string) $term['type'] ) {
				$member[] = (int) $term['id'];
			}
		}

		// The public list stays, the non-public request is ignored, and the
		// pre-existing private membership is never detached.
		$this->assertContains( $public_a, $member );
		$this->assertContains( $private, $member );
		$this->assertNotContains( $other, $member );

		// Unchecking every public list detaches only public lists.
		$this->prefs->save_profile( $contact, array( 'lists' => array() ) );

		$member = array();

		foreach ( $this->terms->for_contact( $contact ) as $term ) {
			if ( TermRepository::TYPE_LIST === (string) $term['type'] ) {
				$member[] = (int) $term['id'];
			}
		}

		$this->assertNotContains( $public_a, $member );
		$this->assertContains( $private, $member );
	}

	public function test_unsubscribe_sets_status_and_suppression(): void {
		$contact = $this->make_contact( array( 'email' => 'consent-optout@example.test' ) );

		$result = $this->prefs->unsubscribe( $contact, 'too_many' );

		$this->assertTrue( $result['ok'] );
		$this->assertFalse( $result['already'] );
		$this->assertSame( Status::UNSUBSCRIBED, $this->contacts->get( $contact )['status'] );
		$this->assertTrue( ( new \NowCRM\Email\SuppressionRepository() )->is_suppressed( 'consent-optout@example.test' ) );

		$again = $this->prefs->unsubscribe( $contact );

		$this->assertTrue( $again['ok'] );
		$this->assertTrue( $again['already'] );
	}

	public function test_suppression_is_never_lifted_by_confirmation(): void {
		$contact = $this->make_contact( array( 'email' => 'consent-stuck@example.test' ) );

		$this->prefs->unsubscribe( $contact );
		$this->assertSame( Status::UNSUBSCRIBED, $this->contacts->get( $contact )['status'] );

		$this->assertWPError( $this->doi->send( $contact ) );
		$this->assertWPError( $this->doi->enroll( 'consent-stuck@example.test' ) );

		$raw     = $this->token( Tokens::PURPOSE_CONFIRM, $contact, 'consent-stuck@example.test' );
		$confirm = $this->doi->confirm( $raw );

		$this->assertWPError( $confirm );
		$this->assertSame( Status::UNSUBSCRIBED, $this->contacts->get( $contact )['status'] );
	}

	public function test_enroll_confirms_once_and_replays_idempotently(): void {
		$list = $this->make_list( 'Consent DOI' );

		Config::update( array( 'double_optin_enabled' => true ) );

		$enrolled = $this->doi->enroll( 'doi-new@example.test', array( 'first_name' => 'Doi' ), array( $list ) );

		$this->assertNotWPError( $enrolled );
		$this->assertTrue( $enrolled['sent'] );
		$this->assertSame( Status::TRANSACTIONAL, $this->contacts->get( $enrolled['contact_id'] )['status'] );
		$this->assertNotNull( Config::pending_get( $enrolled['contact_id'] ) );

		$raw   = $this->token( Tokens::PURPOSE_CONFIRM, $enrolled['contact_id'], 'doi-new@example.test', 3600, array( 'lists' => array( $list ) ) );
		$first = $this->doi->confirm( $raw );

		$this->assertFalse( is_wp_error( $first ) );
		$this->assertFalse( $first['already'] );
		$this->assertSame( Status::SUBSCRIBED, $this->contacts->get( $enrolled['contact_id'] )['status'] );

		$history = $this->history->for_contact( $enrolled['contact_id'], 10 );

		$second = $this->doi->confirm( $raw );

		$this->assertFalse( is_wp_error( $second ) );
		$this->assertTrue( $second['already'] );
		$this->assertSame( Status::SUBSCRIBED, $this->contacts->get( $enrolled['contact_id'] )['status'] );
		$this->assertCount( count( $history ), $this->history->for_contact( $enrolled['contact_id'], 10 ) );
	}

	public function test_double_optin_resend_is_throttled(): void {
		$contact = $this->make_contact( array( 'status' => Status::TRANSACTIONAL ) );

		$first = $this->doi->send( $contact );

		$this->assertNotWPError( $first );
		$this->assertTrue( $first['sent'] );

		$second = $this->doi->send( $contact );

		$this->assertWPError( $second );
		$this->assertSame( 'nowcrm_doi_throttled', $second->get_error_code() );
		$this->assertSame( 1, $this->table_count( 'email_jobs', "job_key LIKE 'nowcrm-doi:{$contact}:%'" ) );
	}

	public function test_confirmation_email_stays_queued_when_sending_is_disabled(): void {
		$this->set_settings( array( 'email_sending_enabled' => false ) );

		$contact = $this->make_contact( array( 'status' => Status::TRANSACTIONAL ) );
		$sent    = $this->doi->send( $contact );

		$this->assertNotWPError( $sent );

		$job = $this->jobs->get( (int) $sent['job_id'] );

		$this->assertSame( EmailJobRepository::STATUS_QUEUED, $job['status'] );
		$this->assertSame( 'consent', $job['kind'] );
		$this->assertSame( array(), get_option( EmailDispatcher::CAPTURE_OPTION, array() ) );
	}

	public function test_compliance_requires_a_link_in_marketing_mail(): void {
		$this->assertTrue( Compliance::validate( null, '<p>Hi</p>', true ) );

		$missing = Compliance::validate( 'WENSZU 電子報', '<p>Hi</p>', false );

		$this->assertWPError( $missing );
		$this->assertSame( 'nowcrm_compliance_missing_link', $missing->get_error_code() );

		$this->assertTrue( Compliance::validate( '退訂：{{unsubscribe_url}}', '<p>Hi</p>', false ) );
		$this->assertTrue( Compliance::validate( '偏好：{{manage_url}}', '<p>Hi</p>', false ) );
		$this->assertTrue( Compliance::validate( '', '<p><a href="https://example.test/?nowcrm_preferences=1">偏好</a></p>', false ) );

		$unknown = Compliance::validate( '{{bogus}} {{unsubscribe_url}}', '<p>Hi</p>', false );

		$this->assertWPError( $unknown );
		$this->assertSame( 'nowcrm_compliance_unknown_smartcode', $unknown->get_error_code() );
	}

	public function test_footer_resolution_order_is_template_global_default(): void {
		Config::update( array( 'footer_text' => '全域頁尾' ) );

		$this->assertSame( '範本頁尾', Compliance::resolve( '範本頁尾' ) );
		$this->assertSame( '全域頁尾', Compliance::resolve( '' ) );
		$this->assertSame( '全域頁尾', Compliance::resolve( null ) );

		Config::update( array( 'footer_text' => '' ) );

		$this->assertSame( \NowCRM\Email\EmailRenderer::default_footer(), Compliance::resolve( '' ) );
	}

	public function test_public_request_is_never_an_oracle(): void {
		$contact = $this->make_contact( array( 'email' => 'consent-known@example.test' ) );
		$email   = $this->contacts->get( $contact )['email'];

		$unknown = $this->prefs->request_link( 'consent-nobody@example.test' );
		$known   = $this->prefs->request_link( $email );

		$this->assertSame( $unknown['message'], $known['message'] );
		$this->assertSame( 0, $this->table_count( 'email_jobs', "recipient = 'consent-nobody@example.test'" ) );
		$this->assertSame( 1, $this->table_count( 'email_jobs', "recipient = '" . esc_sql( $email ) . "'" ) );

		// The second immediate request is throttled but reads identically.
		$repeat = $this->prefs->request_link( $email );

		$this->assertSame( $known['message'], $repeat['message'] );
		$this->assertSame( 1, $this->table_count( 'email_jobs', "recipient = '" . esc_sql( $email ) . "'" ) );
	}

	public function test_spammed_addresses_get_no_public_link(): void {
		$this->make_contact(
			array(
				'email'  => 'consent-spam@example.test',
				'status' => Status::SPAMMED,
			)
		);

		$result = $this->prefs->request_link( 'consent-spam@example.test' );

		$this->assertFalse( $result['sent'] );
		$this->assertSame( 0, $this->table_count( 'email_jobs', "recipient = 'consent-spam@example.test'" ) );
	}

	public function test_email_change_reconfirms_before_applying(): void {
		$contact = $this->make_contact(
			array(
				'email'  => 'consent-old@example.test',
				'status' => Status::TRANSACTIONAL,
			)
		);

		$requested = $this->prefs->request_email_change( $contact, 'consent-new@example.test' );

		$this->assertNotWPError( $requested );
		$this->assertTrue( $requested['sent'] );
		$this->assertSame( 'consent-old@example.test', $this->contacts->get( $contact )['email'] );

		// A taken address gets the identical reply and no state change.
		$taken_contact = $this->make_contact( array( 'email' => 'consent-taken@example.test' ) );

		$refused = $this->prefs->request_email_change( $contact, 'consent-taken@example.test' );

		$this->assertNotWPError( $refused );
		$this->assertSame( $requested['message'], $refused['message'] );
		$this->assertFalse( $refused['sent'] );

		$raw       = $this->token( Tokens::PURPOSE_EMAIL_CHANGE, $contact, 'consent-old@example.test', 3600, array( 'new' => 'consent-new@example.test' ) );
		$confirmed = $this->prefs->confirm_email_change( $raw );

		$this->assertNotWPError( $confirmed );
		$this->assertFalse( $confirmed['already'] );
		$this->assertSame( 'consent-new@example.test', $this->contacts->get( $contact )['email'] );
		$this->assertSame( Status::TRANSACTIONAL, $this->contacts->get( $contact )['status'] );

		$repeat = $this->prefs->confirm_email_change( $raw );

		$this->assertNotWPError( $repeat );
		$this->assertTrue( $repeat['already'] );

		$this->assertWPError( $this->prefs->confirm_email_change( $this->token( Tokens::PURPOSE_MANAGE, $contact, 'consent-new@example.test' ) ) );

		$this->contacts->delete( $taken_contact );
	}

	public function test_consent_mail_sends_through_queue_without_bypassing_suppression(): void {
		$this->set_settings(
			array(
				'email_sending_enabled'     => true,
				'email_transport'           => 'capture',
				'email_throttle_per_second' => 100,
			)
		);

		$contact = $this->make_contact( array( 'email' => 'consent-worker@example.test', 'status' => Status::TRANSACTIONAL ) );
		$sent    = $this->doi->send( $contact );

		$this->assertNotWPError( $sent );

		( new Queue() )->run();

		$job = $this->jobs->get( (int) $sent['job_id'] );

		$this->assertSame( EmailJobRepository::STATUS_ACCEPTED, $job['status'] );

		$log = get_option( EmailDispatcher::CAPTURE_OPTION, array() );

		$this->assertCount( 1, $log );
		$this->assertSame( 'consent', $log[0]['kind'] );
		$this->assertStringContainsString( 'nowcrm_preferences', $log[0]['html'] );

		foreach ( (array) $log[0]['headers'] as $header ) {
			$this->assertStringNotContainsString( 'List-Unsubscribe', (string) $header );
		}

		$blocked = $this->make_contact( array( 'email' => 'consent-blocked@example.test', 'status' => Status::TRANSACTIONAL ) );

		( new SuppressionRepository() )->add( 'consent-blocked@example.test', 'bounced', 'test' );

		$job_id = $this->jobs->enqueue(
			array(
				'job_key'    => 'nowcrm-doi:' . $blocked . ':blocked',
				'kind'       => 'consent',
				'contact_id' => $blocked,
				'recipient'  => 'consent-blocked@example.test',
				'subject'    => 'Blocked',
				'payload'    => array(
					'template' => array(
						'mode'      => 'html',
						'content'   => '<p>Hi</p>',
						'subject'   => 'Blocked',
						'preheader' => '',
						'footer_text' => '',
					),
				),
			)
		);

		$this->assertIsInt( $job_id );

		( new Queue() )->run();

		$this->assertSame( EmailJobRepository::STATUS_SUPPRESSED, $this->jobs->get( (int) $job_id )['status'] );
		$this->assertCount( 1, get_option( EmailDispatcher::CAPTURE_OPTION, array() ) );
	}

	public function test_campaign_confirm_rejects_a_missing_footer_link(): void {
		$this->set_settings( array( 'email_sending_enabled' => true ) );
		$this->make_contact();

		$campaign = $this->make_campaign();
		update_post_meta( $campaign, Campaigns::META_FOOTER, 'WENSZU 電子報' );
		$delivery = new Delivery();

		$post     = get_post( $campaign );
		$revision = Campaigns::revision( Campaigns::draft( $post ) );
		$review   = $delivery->review( $post, $revision );

		$this->assertIsArray( $review );

		$result = $delivery->confirm( $campaign, $review['token'], '' );

		$this->assertWPError( $result );
		$this->assertSame( 'nowcrm_compliance_missing_link', $result->get_error_code() );

		update_post_meta( $campaign, Campaigns::META_FOOTER, '退訂：{{unsubscribe_url}}' );

		$post        = get_post( $campaign );
		$revision    = Campaigns::revision( Campaigns::draft( $post ) );
		$review      = $delivery->review( $post, $revision );
		$confirmed   = $delivery->confirm( $campaign, $review['token'], '' );

		$this->assertTrue( $confirmed );
		$this->assertSame( Campaigns::STATUS_SENDING, Campaigns::state( $campaign ) );
	}

	public function test_renderer_footer_renders_manage_and_unsubscribe_links(): void {
		$renderer = new EmailRenderer();
		$rendered = $renderer->render(
			array(
				'mode'        => 'html',
				'content'     => '<p>Hi</p>',
				'subject'     => 'Subject',
				'footer_text' => '退訂：{{unsubscribe_url}} 偏好：{{manage_url}}',
			),
			array(
				'email'           => 'footer@example.test',
				'unsubscribe_url' => 'https://example.test/?nowcrm_unsubscribe=1',
				'manage_url'      => 'https://example.test/?nowcrm_preferences=1',
			)
		);

		$this->assertStringContainsString( '<a href="https://example.test/?nowcrm_preferences=1"', $rendered['html'] );
		$this->assertStringContainsString( '<a href="https://example.test/?nowcrm_unsubscribe=1"', $rendered['html'] );

		$plain = $renderer->render(
			array(
				'mode'        => 'html',
				'content'     => '<p>Hi</p>',
				'subject'     => 'Subject',
				'footer_text' => 'WENSZU 電子報',
			),
			array( 'email' => 'footer@example.test' )
		);

		$this->assertStringContainsString( 'WENSZU 電子報', $plain['html'] );
		$this->assertStringNotContainsString( '{{', $plain['html'] );
	}

	public function test_form_entry_point_starts_pending_only_when_required(): void {
		$list    = $this->make_list( 'Consent Entry' );
		$contact = $this->make_contact();
		$entries = new EntryPoints();
		$payload = array(
			'double_opt_in'   => true,
			'contact_created' => true,
			'lists'           => array( $list ),
		);

		$entries->on_form_submitted( 1, 2, $contact, $payload );

		$this->assertSame( Status::SUBSCRIBED, $this->contacts->get( $contact )['status'] );
		$this->assertSame( 0, $this->table_count( 'email_jobs', "job_key LIKE 'nowcrm-doi:{$contact}:%'" ) );

		Config::set_list_config( $list, array( 'mode' => 'require' ) );

		$entries->on_form_submitted( 1, 2, $contact, $payload );

		$this->assertSame( Status::TRANSACTIONAL, $this->contacts->get( $contact )['status'] );
		$this->assertSame( 1, $this->table_count( 'email_jobs', "job_key LIKE 'nowcrm-doi:{$contact}:%'" ) );
		$this->assertNotNull( Config::pending_get( $contact ) );
	}

	public function test_csv_entry_point_never_touches_existing_consent(): void {
		$list    = $this->make_list( 'Consent CSV' );
		$contact = $this->make_contact();
		$this->terms->attach( $contact, $list );
		$entries = new EntryPoints();

		$entries->on_csv_imported( $contact, (string) $this->contacts->get( $contact )['email'], array() );

		$this->assertSame( Status::SUBSCRIBED, $this->contacts->get( $contact )['status'] );

		Config::update( array( 'double_optin_enabled' => true ) );

		$entries->on_csv_imported( $contact, (string) $this->contacts->get( $contact )['email'], array() );

		$this->assertSame( Status::TRANSACTIONAL, $this->contacts->get( $contact )['status'] );
		$this->assertSame( 1, $this->table_count( 'email_jobs', "job_key LIKE 'nowcrm-doi:{$contact}:%'" ) );
	}
}
