<?php
/**
 * Signed tracking tokens and deduplicated events.
 *
 * @package NowCRM
 */

use NowCRM\Campaign\Campaigns;
use NowCRM\Email\EmailJobRepository;
use NowCRM\Email\Tracking;

/**
 * @covers \NowCRM\Email\Tracking
 */
final class TrackingTest extends NowCrmTestCase {

	private Tracking $tracking;

	public function set_up(): void {
		parent::set_up();
		$this->tracking = new Tracking();
	}

	public function test_signed_tokens_round_trip_and_tampering_fails(): void {
		$token = $this->tracking->sign( array( 'j' => 5, 'c' => 6, 't' => 7 ) );
		$data  = $this->tracking->verify( $token );

		$this->assertSame( 5, $data['j'] );
		$this->assertSame( 6, $data['c'] );
		$this->assertSame( 7, $data['t'] );

		$parts = explode( '.', $token );
		$this->assertNull( $this->tracking->verify( $parts[0] . '.' . str_repeat( 'A', 43 ) ) );
		$this->assertNull( $this->tracking->verify( 'garbage' ) );
	}

	public function test_inject_rewrites_links_and_adds_pixel_but_skips_unsubscribe(): void {
		$this->set_settings( array( 'campaign_tracking_enabled' => true ) );

		$job = array( 'id' => 9, 'campaign_id' => 3, 'contact_id' => 11 );
		$html = '<p><a href="https://example.test/offer?x=1">Offer</a> '
			. '<a href="https://example.test/?nowcrm_unsubscribe=1&amp;token=abc">Unsub</a></p>';

		$out = $this->tracking->inject( $html, $job, array( 'id' => 11 ) );

		$this->assertStringContainsString( '/nowcrm/v1/click/', $out );
		$this->assertStringContainsString( '/nowcrm/v1/open/', $out );
		$this->assertStringContainsString( 'nowcrm_unsubscribe=1', $out );
		$this->assertStringNotContainsString( 'href="https://example.test/offer?x=1"', $out );
		$parts = explode( '/click/', $out );
		$token = explode( '"', $parts[1] )[0];
		$data  = $this->tracking->verify( $token );

		$this->assertStringContainsString( 'utm_source=nowcrm', $data['u'] );
	}

	public function test_open_and_click_events_are_unique_per_campaign_contact_url(): void {
		$this->set_settings( array( 'campaign_tracking_enabled' => true ) );

		$payload = array( 'j' => 1, 'c' => 42, 't' => 77 );

		$this->assertTrue( $this->tracking->record_open( $payload, 'Mozilla' ) );
		$this->assertFalse( $this->tracking->record_open( $payload, 'Mozilla' ) );

		$this->assertTrue( $this->tracking->record_click( $payload, 'https://example.test/a' ) );
		$this->assertFalse( $this->tracking->record_click( $payload, 'https://example.test/a' ) );
		$this->assertTrue( $this->tracking->record_click( $payload, 'https://example.test/b' ) );

		$stats = $this->tracking->campaign_stats( 42 );

		$this->assertSame( 1, $stats['open'] );
		$this->assertSame( 2, $stats['click'] );
	}

	public function test_engagement_stats_count_unique_accepted_broadcast_recipients(): void {
		$this->set_settings( array( 'campaign_tracking_enabled' => true ) );

		$jobs     = new EmailJobRepository();
		$campaign = 301;
		$other    = 302;

		// A campaign job is only claimable while its campaign row is sending.
		$this->activate_campaign( $campaign );

		$first = $this->accept_job( $jobs, $campaign, 'first@example.test', 'campaign' );

		// A second accepted recipient never opens or clicks.
		$this->accept_job( $jobs, $campaign, 'second@example.test', 'campaign' );

		$test = $this->accept_job( $jobs, $campaign, 'test@example.test', 'test' );

		// The other campaign only ever accepted a test send; no broadcast row should appear.
		$this->accept_job( $jobs, $other, 'only-test@example.test', 'test' );

		// Queued broadcasts are not accepted recipients yet.
		$queued = $jobs->enqueue(
			array(
				'job_key'     => 'campaign:' . $campaign . ':queued@example.test',
				'kind'        => 'campaign',
				'campaign_id' => $campaign,
				'recipient'   => 'queued@example.test',
				'subject'     => 'Fixture',
			)
		);
		$this->assertIsInt( $queued );

		$this->assertTrue( $this->tracking->record_open( array( 'j' => $first, 'c' => $campaign, 't' => 1 ) ) );
		$this->assertTrue( $this->tracking->record_click( array( 'j' => $first, 'c' => $campaign, 't' => 1 ), 'https://example.test/a' ) );
		$this->assertTrue( $this->tracking->record_click( array( 'j' => $first, 'c' => $campaign, 't' => 1 ), 'https://example.test/b' ) );

		// Test-send activity must not inflate the broadcast counters.
		$this->tracking->record_open( array( 'j' => $test, 'c' => $campaign, 't' => 2 ) );
		$this->tracking->record_click( array( 'j' => $test, 'c' => $campaign, 't' => 2 ), 'https://example.test/c' );

		$stats = $this->tracking->campaign_engagement_stats( array( $campaign, $other, 0 ) );

		$this->assertSame(
			array(
				'accepted' => 2,
				'opened'   => 1,
				'clicked'  => 1,
			),
			$stats[ $campaign ]
		);
		$this->assertArrayNotHasKey( $other, $stats );
		$this->assertArrayNotHasKey( 0, $stats );
	}

	public function test_signed_callback_events_stop_recording_when_tracking_is_off(): void {
		$jobs = new EmailJobRepository();
		$job  = $this->make_job( $jobs, 'campaign', 401, 'callback-campaign' );

		$open  = $this->tracking->verify( $this->tracking->sign( array( 'j' => $job, 'c' => 401, 't' => 1 ) ) );
		$click = $this->tracking->verify(
			$this->tracking->sign( array( 'j' => $job, 'c' => 401, 't' => 1, 'u' => 'https://example.test/offer' ) )
		);

		$this->assertIsArray( $open );
		$this->assertIsArray( $click );

		$this->set_settings( array( 'campaign_tracking_enabled' => false ) );

		$this->assertFalse( $this->tracking->record_open_callback( $open, 'Mozilla' ) );
		$this->assertFalse( $this->tracking->record_click_callback( $click, 'https://example.test/offer', 'Mozilla' ) );

		$stats = $this->tracking->campaign_stats( 401 );
		$this->assertSame( 0, $stats['open'] );
		$this->assertSame( 0, $stats['click'] );

		$this->set_settings( array( 'campaign_tracking_enabled' => true ) );

		$this->assertTrue( $this->tracking->record_open_callback( $open, 'Mozilla' ) );
		$this->assertTrue( $this->tracking->record_click_callback( $click, 'https://example.test/offer', 'Mozilla' ) );

		$stats = $this->tracking->campaign_stats( 401 );
		$this->assertSame( 1, $stats['open'] );
		$this->assertSame( 1, $stats['click'] );
	}

	public function test_campaign_and_sequence_callbacks_follow_their_own_setting(): void {
		$jobs     = new EmailJobRepository();
		$campaign = $this->make_job( $jobs, 'campaign', 402, 'kind-campaign' );
		$sequence = $this->make_job( $jobs, 'sequence', 0, 'kind-sequence' );

		$campaign_open  = array( 'j' => $campaign, 'c' => 402, 't' => 1 );
		$campaign_click = array( 'j' => $campaign, 'c' => 402, 't' => 1, 'u' => 'https://example.test/offer' );
		$sequence_click = array( 'j' => $sequence, 'c' => 0, 't' => 1, 'u' => 'https://example.test/offer' );

		$this->set_settings( array( 'campaign_tracking_enabled' => true, 'sequence_tracking_enabled' => false ) );

		$this->assertTrue( $this->tracking->record_open_callback( $campaign_open ) );
		$this->assertFalse( $this->tracking->record_click_callback( $sequence_click, 'https://example.test/offer' ) );

		$this->set_settings( array( 'campaign_tracking_enabled' => false, 'sequence_tracking_enabled' => true ) );

		$this->assertFalse( $this->tracking->record_click_callback( $campaign_click, 'https://example.test/offer' ) );
		$this->assertTrue( $this->tracking->record_click_callback( $sequence_click, 'https://example.test/offer' ) );

		$stats = $this->tracking->campaign_stats( 402 );
		$this->assertSame( 1, $stats['open'] );
		$this->assertSame( 0, $stats['click'] );
	}

	public function test_callback_events_require_an_existing_job(): void {
		$this->set_settings( array( 'campaign_tracking_enabled' => true, 'sequence_tracking_enabled' => true ) );

		$this->assertFalse( $this->tracking->record_open_callback( array( 'j' => 999999, 'c' => 403, 't' => 1 ) ) );
		$this->assertSame( 0, $this->tracking->campaign_stats( 403 )['open'] );
	}

	/**
	 * Insert one queued job of a given kind for callback fixtures.
	 */
	private function make_job( EmailJobRepository $jobs, string $kind, int $campaign_id, string $key ): int {
		$id = $jobs->enqueue(
			array(
				'job_key'     => $key,
				'kind'        => $kind,
				'campaign_id' => $campaign_id,
				'recipient'   => 'fixture-' . md5( $key ) . '@example.test',
				'subject'     => 'Fixture',
			)
		);

		$this->assertIsInt( $id );

		return (int) $id;
	}

	/**
	 * Create a real campaign post in a sendable state at a fixed ID.
	 */
	private function activate_campaign( int $campaign_id ): void {
		$id = wp_insert_post(
			array(
				'import_id'    => $campaign_id,
				'post_type'    => Campaigns::POST_TYPE,
				'post_status'  => 'draft',
				'post_title'   => 'Fixture campaign ' . $campaign_id,
				'post_content' => '<p>Fixture</p>',
			),
			true
		);

		$this->assertIsInt( $id );
		$this->assertSame( $campaign_id, $id );

		update_post_meta( $id, Campaigns::META_STATUS, Campaigns::STATUS_SENDING );
	}

	/**
	 * Insert an accepted job for the engagement fixture.
	 */
	private function accept_job( EmailJobRepository $jobs, int $campaign_id, string $email, string $kind ): int {
		$id = $jobs->enqueue(
			array(
				'job_key'     => $kind . ':' . $campaign_id . ':' . $email,
				'kind'        => $kind,
				'campaign_id' => $campaign_id,
				'recipient'   => $email,
				'subject'     => 'Fixture',
			)
		);

		$this->assertIsInt( $id );

		$token = 'tracking-test-' . $id;
		$jobs->claim( 1, $token );

		$this->assertTrue( $jobs->mark_accepted( (int) $id, $token ) );

		return (int) $id;
	}

	public function test_tracking_is_disabled_by_setting(): void {
		\NowCRM\Support\Settings::update( array( 'campaign_tracking_enabled' => false ) );

		$html = '<p><a href="https://example.test/offer">Offer</a></p>';
		$out  = $this->tracking->inject( $html, array( 'id' => 1, 'campaign_id' => 2 ), array( 'id' => 3 ) );

		$this->assertSame( $html, $out );
	}
}
