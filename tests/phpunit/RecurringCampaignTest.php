<?php
/**
 * Recurring campaign schedule, dedupe, and child creation.
 *
 * @package NowCRM
 */

use NowCRM\Admin\CampaignPage;
use NowCRM\Campaign\Campaigns;
use NowCRM\Campaign\Recurring;
use NowCRM\Email\EmailJobRepository;

final class RecurringCampaignTest extends NowCrmTestCase {

	private EmailJobRepository $jobs;

	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->jobs = new EmailJobRepository();
		$this->set_settings(
			array(
				'email_sending_enabled'    => true,
				'email_transport'          => 'capture',
				'email_throttle_per_second' => 50,
			)
		);
	}

	/**
	 * Settings array for next-run tests.
	 */
	private function schedule( string $frequency, string $time, string $day = '' ): array {
		return array(
			'status'    => Recurring::STATUS_ACTIVE,
			'enabled'   => true,
			'frequency' => $frequency,
			'time'      => $time,
			'day'       => $day,
			'auto_send' => false,
		);
	}

	/**
	 * Site-local timestamp for a wall clock time.
	 */
	private function local( string $datetime ): int {
		return ( new DateTimeImmutable( $datetime, wp_timezone() ) )->getTimestamp();
	}

	/**
	 * Site-local label for a UTC value returned by next_run_at().
	 */
	private function label( ?string $utc ): string {
		return null === $utc || '' === $utc ? '' : get_date_from_gmt( $utc, 'Y-m-d H:i' );
	}

	/**
	 * Call the recurring REST endpoint.
	 */
	private function api( string $method, int $id, array $data = array() ): WP_REST_Response {
		$request = new WP_REST_Request( $method, '/nowcrm/v1/campaigns/' . $id . '/recurring' );
		if ( 'GET' === $method ) {
			$request->set_query_params( $data );
		} else {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( wp_json_encode( $data ) );
		}

		return rest_do_request( $request );
	}

	/**
	 * Call the campaign delivery endpoint.
	 */
	private function delivery( int $id, array $data ): WP_REST_Response {
		$request = new WP_REST_Request( 'POST', '/nowcrm/v1/campaigns/' . $id . '/delivery' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( $data ) );

		return rest_do_request( $request );
	}

	/**
	 * Draft revision token for the review endpoint.
	 */
	private function revision( int $id ): string {
		return Campaigns::revision( Campaigns::draft( Campaigns::get( $id ) ) );
	}

	/**
	 * Campaign review response.
	 */
	private function review( int $id ): WP_REST_Response {
		$request = new WP_REST_Request( 'GET', '/nowcrm/v1/campaigns/' . $id . '/review' );
		$request->set_query_params( array( 'revision' => $this->revision( $id ) ) );

		return rest_do_request( $request );
	}

	/**
	 * Create a campaign that can act as a recurring parent.
	 *
	 * @param array<string, mixed> $overrides Post overrides plus `recurring` and `due`.
	 */
	private function make_recurring_parent( array $overrides = array() ): int {
		$id = $this->make_campaign( $overrides );
		$this->make_contact( array( 'email' => 'recurring-' . wp_generate_password( 6, false ) . '@example.test' ) );

		$settings = array_merge(
			array(
				'frequency' => 'daily',
				'time'      => '09:00',
				'day'       => '',
				'status'    => Recurring::STATUS_ACTIVE,
				'auto_send' => false,
			),
			$overrides['recurring'] ?? array()
		);

		$saved = Recurring::save( $id, $settings );
		$this->assertIsArray( $saved, 'Recurring settings must save.' );

		if ( ! empty( $overrides['due'] ) ) {
			update_post_meta( $id, Recurring::META_NEXT_RUN, gmdate( 'Y-m-d H:i:s', time() - MINUTE_IN_SECONDS ) );
		}

		return $id;
	}

	public function test_daily_next_run_keeps_a_slot_that_is_far_enough_ahead(): void {
		$settings = $this->schedule( 'daily', '09:00' );

		$this->assertSame(
			'2026-03-10 09:00',
			$this->label( Recurring::next_run_at( $settings, Recurring::CUT_SECONDS, $this->local( '2026-03-10 08:00:00' ) ) )
		);
		$this->assertSame(
			'2026-03-11 09:00',
			$this->label( Recurring::next_run_at( $settings, Recurring::CUT_SECONDS, $this->local( '2026-03-10 08:45:00' ) ) )
		);
		$this->assertSame(
			'2026-03-11 09:00',
			$this->label( Recurring::next_run_at( $settings, Recurring::CUT_SECONDS, $this->local( '2026-03-10 11:00:00' ) ) )
		);
	}

	public function test_weekly_next_run_uses_the_selected_weekday(): void {
		$settings = $this->schedule( 'weekly', '09:00', 'wed' );

		$this->assertSame(
			'2026-03-11 09:00',
			$this->label( Recurring::next_run_at( $settings, Recurring::CUT_SECONDS, $this->local( '2026-03-09 10:00:00' ) ) )
		);
		$this->assertSame(
			'2026-03-11 09:00',
			$this->label( Recurring::next_run_at( $settings, Recurring::CUT_SECONDS, $this->local( '2026-03-11 08:00:00' ) ) )
		);
		$this->assertSame(
			'2026-03-18 09:00',
			$this->label( Recurring::next_run_at( $settings, Recurring::CUT_SECONDS, $this->local( '2026-03-11 08:45:00' ) ) )
		);
	}

	public function test_monthly_next_run_clamps_the_day_to_the_month_length(): void {
		$settings = $this->schedule( 'monthly', '09:00', '31' );

		$this->assertSame(
			'2026-01-31 09:00',
			$this->label( Recurring::next_run_at( $settings, Recurring::CUT_SECONDS, $this->local( '2026-01-15 12:00:00' ) ) )
		);
		$this->assertSame(
			'2026-02-28 09:00',
			$this->label( Recurring::next_run_at( $settings, Recurring::CUT_SECONDS, $this->local( '2026-01-31 12:00:00' ) ) )
		);
		$this->assertSame(
			'2026-02-28 09:00',
			$this->label( Recurring::next_run_at( $settings, Recurring::CUT_SECONDS, $this->local( '2026-02-05 12:00:00' ) ) )
		);
	}

	public function test_settings_reject_incomplete_schedules(): void {
		$id = $this->make_campaign();

		$this->assertSame(
			'nowcrm_recurring_time',
			Recurring::save( $id, array( 'frequency' => 'daily', 'time' => '9am' ) )->get_error_code()
		);
		$this->assertSame(
			'nowcrm_recurring_frequency',
			Recurring::save( $id, array( 'frequency' => 'hourly', 'time' => '09:00' ) )->get_error_code()
		);
		$this->assertSame(
			'nowcrm_recurring_day',
			Recurring::save( $id, array( 'frequency' => 'weekly', 'time' => '09:00' ) )->get_error_code()
		);
		$this->assertSame(
			'nowcrm_recurring_day',
			Recurring::save( $id, array( 'frequency' => 'monthly', 'time' => '09:00', 'day' => '32' ) )->get_error_code()
		);

		$saved = Recurring::save( $id, array( 'frequency' => 'monthly', 'time' => '7:05', 'day' => '15' ) );
		$this->assertIsArray( $saved );
		$this->assertSame( 'monthly', $saved['frequency'] );
		$this->assertSame( '07:05', $saved['time'] );
		$this->assertSame( '15', $saved['day'] );
	}

	public function test_activation_requires_a_subject_and_body(): void {
		$id = $this->make_campaign( array( 'subject' => '', 'html' => '' ) );

		$result = Recurring::save( $id, array( 'frequency' => 'daily', 'time' => '09:00', 'status' => 'active' ) );

		$this->assertWPError( $result );
		$this->assertSame( 'nowcrm_recurring_content', $result->get_error_code() );
	}

	public function test_runner_creates_one_draft_child_with_copied_content(): void {
		$parent = $this->make_recurring_parent(
			array(
				'due'       => true,
				'title'     => 'Template parent',
				'subject'   => 'Template subject',
				'html'      => '<p>Template body</p>',
				'audience'  => array(
					'scope'   => 'all',
					'include' => array(),
					'exclude' => array(),
				),
			)
		);

		update_post_meta( $parent, Campaigns::META_PREHEADER, 'Template preheader' );
		update_post_meta( $parent, Campaigns::META_FROM_NAME, 'NOW Shop' );
		update_post_meta( $parent, Campaigns::META_FROM_ADDR, 'shop@example.test' );
		update_post_meta( $parent, Campaigns::META_REPLY_TO, 'reply@example.test' );
		update_post_meta( $parent, Campaigns::META_FOOTER, 'Footer text' );
		update_post_meta( $parent, Campaigns::META_TRACKING, (string) wp_json_encode( array( 'open' => true, 'click' => false ) ) );
		update_post_meta( $parent, Campaigns::META_UTM, (string) wp_json_encode( array( 'enabled' => true, 'source' => 'newsletter', 'medium' => 'email', 'campaign' => 'q1', 'term' => '', 'content' => '' ) ) );

		$slot = (string) get_post_meta( $parent, Recurring::META_NEXT_RUN, true );
		$this->assertNotSame( '', $slot );

		$this->assertSame( 1, ( new Recurring() )->run() );

		$children = Recurring::children( $parent );
		$this->assertCount( 1, $children );

		$child = Campaigns::get( $children[0] );
		$this->assertNotNull( $child );
		$this->assertSame( 'draft', Campaigns::state( $child->ID ) );
		$this->assertSame( (string) $parent, (string) get_post_meta( $child->ID, Recurring::META_PARENT, true ) );
		$this->assertSame(
			Recurring::period_key( Recurring::settings( $parent ), $slot ),
			(string) get_post_meta( $child->ID, Recurring::META_SLOT, true )
		);
		$this->assertSame( '', (string) get_post_meta( $child->ID, Recurring::META_STATUS, true ) );

		$source = Campaigns::draft( get_post( $parent ) );
		$copy   = Campaigns::draft( $child );
		foreach ( array( 'mode', 'html', 'subject', 'preheader', 'from_name', 'from_address', 'reply_to', 'footer_text', 'audience', 'tracking', 'utm' ) as $field ) {
			$this->assertSame( $source[ $field ], $copy[ $field ], $field . ' must be copied.' );
		}
		$this->assertSame( '<p>Template body</p>', $copy['html'] );
		$this->assertNotSame( $source['title'], $copy['title'] );

		$summary = Recurring::summary( $parent );
		$this->assertSame( 1, $summary['child_count'] );
		$this->assertSame( $child->ID, $summary['last_child']['id'] );
		$this->assertNotSame( '', $summary['last_run_at'] );
		$this->assertNotSame( '', $summary['next_run_at'] );
		$this->assertSame( '', $summary['error'] );
	}

	public function test_one_child_per_period(): void {
		$parent = $this->make_recurring_parent( array( 'due' => true ) );
		$slot   = (string) get_post_meta( $parent, Recurring::META_NEXT_RUN, true );
		$settings = Recurring::settings( $parent );

		$this->assertIsInt( Recurring::generate_for( $parent, $settings, $slot ) );

		$second = Recurring::generate_for( $parent, $settings, $slot );
		$this->assertWPError( $second );
		$this->assertSame( 'nowcrm_recurring_duplicate', $second->get_error_code() );
		$this->assertCount( 1, Recurring::children( $parent ) );

		// A runner that sees the same slot again creates nothing.
		update_post_meta( $parent, Recurring::META_NEXT_RUN, $slot );
		$this->assertSame( 0, ( new Recurring() )->run() );
		$this->assertCount( 1, Recurring::children( $parent ) );
	}

	public function test_paused_and_disabled_parents_do_not_generate(): void {
		$parent = $this->make_recurring_parent( array( 'due' => true ) );

		$this->assertIsArray( Recurring::pause( $parent ) );
		$this->assertSame( 0, ( new Recurring() )->run() );
		$this->assertCount( 0, Recurring::children( $parent ) );

		$this->assertIsArray( Recurring::disable( $parent ) );
		$settings = Recurring::settings( $parent );
		$this->assertSame( 'inactive', $settings['status'] );
		$this->assertFalse( $settings['enabled'] );
		$this->assertSame( '', $settings['next_run_at'] );
		$this->assertSame( 0, ( new Recurring() )->run() );
		$this->assertCount( 0, Recurring::children( $parent ) );
	}

	public function test_invalid_source_creates_no_child_and_records_the_failure(): void {
		$parent = $this->make_campaign( array( 'subject' => '', 'html' => '' ) );
		$this->make_contact();

		update_post_meta( $parent, Recurring::META_STATUS, Recurring::STATUS_ACTIVE );
		update_post_meta( $parent, Recurring::META_FREQUENCY, 'daily' );
		update_post_meta( $parent, Recurring::META_TIME, '09:00' );
		update_post_meta( $parent, Recurring::META_NEXT_RUN, gmdate( 'Y-m-d H:i:s', time() - MINUTE_IN_SECONDS ) );

		$this->assertSame( 0, ( new Recurring() )->run() );
		$this->assertCount( 0, Recurring::children( $parent ) );
		$this->assertStringContainsString( '主旨', Recurring::settings( $parent )['error'] );
	}

	public function test_manual_generate_now_uses_the_period_key(): void {
		$parent   = $this->make_recurring_parent();
		$slot     = (string) get_post_meta( $parent, Recurring::META_NEXT_RUN, true );
		$settings = Recurring::settings( $parent );

		$this->assertSame( 'active', $settings['status'] );

		$child = Recurring::generate_now( $parent );
		$this->assertIsInt( $child );
		$this->assertSame(
			Recurring::period_key( $settings, $slot ),
			(string) get_post_meta( $child, Recurring::META_SLOT, true )
		);

		update_post_meta( $parent, Recurring::META_NEXT_RUN, $slot );
		$again = Recurring::generate_now( $parent );
		$this->assertWPError( $again );
		$this->assertSame( 'nowcrm_recurring_duplicate', $again->get_error_code() );
		$this->assertCount( 1, Recurring::children( $parent ) );
	}

	public function test_auto_send_needs_the_parent_flag_and_delivery_acceptance(): void {
		$draft_parent = $this->make_recurring_parent( array( 'due' => true ) );
		$this->assertSame( 1, ( new Recurring() )->run() );
		$draft_child = Recurring::children( $draft_parent )[0];
		$this->assertSame( 'draft', Campaigns::state( $draft_child ) );
		$this->assertSame( 0, $this->jobs->campaign_stats( $draft_child )['total'] );

		$auto_parent = $this->make_recurring_parent(
			array(
				'due'       => true,
				'recurring' => array( 'auto_send' => true ),
			)
		);
		$this->assertSame( 1, ( new Recurring() )->run() );
		$auto_child = Recurring::children( $auto_parent )[0];
		$this->assertGreaterThan( 0, $this->jobs->campaign_stats( $auto_child )['total'] );
		$this->assertNotSame( 'draft', Campaigns::state( $auto_child ) );
	}

	public function test_auto_send_stays_draft_when_delivery_is_disabled(): void {
		$this->set_settings( array( 'email_sending_enabled' => false ) );
		$parent = $this->make_recurring_parent(
			array(
				'due'       => true,
				'recurring' => array( 'auto_send' => true ),
			)
		);

		$this->assertSame( 1, ( new Recurring() )->run() );
		$child = Recurring::children( $parent )[0];
		$this->assertSame( 'draft', Campaigns::state( $child ) );
		$this->assertSame( 0, $this->jobs->campaign_stats( $child )['total'] );
		$this->assertNotSame( '', (string) get_post_meta( $child, Recurring::META_ERROR, true ) );
	}

	public function test_child_content_is_independent_of_the_parent(): void {
		$parent = $this->make_recurring_parent( array( 'due' => true, 'subject' => 'Original subject', 'html' => '<p>Original body</p>' ) );

		$this->assertSame( 1, ( new Recurring() )->run() );
		$child = Recurring::children( $parent )[0];

		update_post_meta( $parent, Campaigns::META_SUBJECT, 'Changed subject' );
		update_post_meta( $parent, Campaigns::META_HTML, '<p>Changed body</p>' );

		$copy = Campaigns::draft( Campaigns::get( $child ) );
		$this->assertSame( 'Original subject', $copy['subject'] );
		$this->assertSame( '<p>Original body</p>', $copy['html'] );
	}

	public function test_rest_recurring_saves_and_generates_one_child(): void {
		$parent = $this->make_recurring_parent();

		$saved = $this->api(
			'PUT',
			$parent,
			array(
				'status'    => 'active',
				'frequency' => 'weekly',
				'time'      => '10:30',
				'day'       => 'thu',
				'auto_send' => false,
			)
		);
		$this->assertSame( 200, $saved->get_status() );

		$data = $saved->get_data();
		$this->assertSame( 'weekly', $data['recurring']['frequency'] );
		$this->assertSame( '10:30', $data['recurring']['time'] );
		$this->assertSame( 'thu', $data['recurring']['day'] );
		$this->assertFalse( $data['recurring']['auto_send'] );
		$this->assertNotSame( '', $data['recurring']['next_run_at'] );
		$this->assertTrue( $data['is_draft'] );
		$this->assertFalse( $data['is_child'] );

		$slot = (string) get_post_meta( $parent, Recurring::META_NEXT_RUN, true );

		$read = $this->api( 'GET', $parent );
		$this->assertSame( 200, $read->get_status() );
		$this->assertSame( 'weekly', $read->get_data()['recurring']['frequency'] );

		$generated = $this->api( 'POST', $parent, array( 'op' => 'generate' ) );
		$this->assertSame( 201, $generated->get_status() );
		$this->assertIsInt( $generated->get_data()['child_id'] );
		$this->assertSame( 1, $generated->get_data()['recurring']['child_count'] );

		update_post_meta( $parent, Recurring::META_NEXT_RUN, $slot );
		$duplicate = $this->api( 'POST', $parent, array( 'op' => 'generate' ) );
		$this->assertSame( 409, $duplicate->get_status() );
		$this->assertSame( 'nowcrm_recurring_duplicate', $duplicate->get_data()['code'] );

		$paused = $this->api( 'POST', $parent, array( 'op' => 'pause' ) );
		$this->assertSame( 200, $paused->get_status() );
		$this->assertSame( 'paused', $paused->get_data()['recurring']['status'] );

		$disabled = $this->api( 'POST', $parent, array( 'op' => 'disable' ) );
		$this->assertSame( 200, $disabled->get_status() );
		$this->assertSame( 'inactive', $disabled->get_data()['recurring']['status'] );
		$this->assertSame( '', $disabled->get_data()['recurring']['next_run_at'] );
	}

	public function test_rest_recurring_rejects_invalid_values(): void {
		$parent = $this->make_recurring_parent();

		$invalid = $this->api( 'PUT', $parent, array( 'status' => 'active', 'frequency' => 'daily', 'time' => '9am' ) );
		$this->assertSame( 422, $invalid->get_status() );
		$this->assertSame( 'nowcrm_recurring_time', $invalid->get_data()['code'] );

		$bad_op = $this->api( 'POST', $parent, array( 'op' => 'destroy' ) );
		$this->assertSame( 400, $bad_op->get_status() );
	}

	public function test_rest_recurring_requires_campaign_edit_capability(): void {
		$parent = $this->make_recurring_parent();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$this->assertSame( 403, $this->api( 'GET', $parent )->get_status() );
		$this->assertSame(
			403,
			$this->api( 'PUT', $parent, array( 'status' => 'active', 'frequency' => 'daily', 'time' => '09:00' ) )->get_status()
		);
		$this->assertSame( 403, $this->api( 'POST', $parent, array( 'op' => 'generate' ) )->get_status() );
		$this->assertSame( 0, Recurring::child_count( $parent ) );
	}

	public function test_rest_recurring_inactive_save_creates_nothing_and_generated_child_joins_history(): void {
		$parent = $this->make_recurring_parent( array( 'recurring' => array( 'status' => Recurring::STATUS_INACTIVE ) ) );

		$inactive = $this->api(
			'PUT',
			$parent,
			array(
				'frequency' => 'daily',
				'time'      => '08:15',
				'day'       => '',
				'auto_send' => false,
			)
		);
		$this->assertSame( 200, $inactive->get_status() );
		$this->assertSame( 'inactive', $inactive->get_data()['recurring']['status'] );
		$this->assertSame( 0, $inactive->get_data()['child_count'] );
		$this->assertSame( array(), $inactive->get_data()['children'] );
		$this->assertSame( 0, Recurring::child_count( $parent ) );

		$before = Campaigns::draft( Campaigns::get( $parent ) );

		$this->assertSame(
			200,
			$this->api( 'PUT', $parent, array( 'status' => 'active', 'frequency' => 'daily', 'time' => '08:15', 'day' => '', 'auto_send' => false ) )->get_status()
		);

		$generated = $this->api( 'POST', $parent, array( 'op' => 'generate' ) );
		$this->assertSame( 201, $generated->get_status() );

		$data    = $generated->get_data();
		$child   = (int) $data['child_id'];
		$history = $data['children'];

		$this->assertSame( 1, $data['child_count'] );
		$this->assertCount( 1, $history );
		$this->assertSame( $child, $history[0]['id'] );
		$this->assertSame( Recurring::STATUS_ACTIVE, $data['recurring']['status'] );
		$this->assertNotSame( '', $history[0]['created'] );
		$this->assertSame( Campaigns::status_label( Campaigns::state( $child ) ), $history[0]['status_label'] );
		$this->assertSame( CampaignPage::url( $child ), $history[0]['url'] );

		$after = Campaigns::draft( Campaigns::get( $parent ) );
		$this->assertSame( $before['subject'], $after['subject'] );
		$this->assertSame( $before['html'], $after['html'] );
		$this->assertSame( $before['audience'], $after['audience'] );
	}

	public function test_recurring_parent_cannot_confirm_directly(): void {
		$parent = $this->make_recurring_parent();

		$review = $this->review( $parent );
		$this->assertSame( 200, $review->get_status() );

		$blocked = $this->delivery( $parent, array( 'op' => 'confirm', 'token' => $review->get_data()['token'] ) );
		$this->assertSame( 409, $blocked->get_status() );
		$this->assertSame( 'nowcrm_recurring_parent', $blocked->get_data()['code'] );
		$this->assertSame( 0, $this->jobs->campaign_stats( $parent )['total'] );

		$this->assertIsArray( Recurring::disable( $parent ) );
		$allowed = $this->delivery( $parent, array( 'op' => 'confirm', 'token' => $review->get_data()['token'] ) );
		$this->assertSame( 200, $allowed->get_status() );
	}

	public function test_campaign_page_renders_workspace_without_recurring_panel(): void {
		$parent = $this->make_recurring_parent();

		ob_start();
		CampaignPage::render( $parent );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'id="nowcrm-campaign-workspace"', $html );
		$this->assertStringContainsString( 'role="status"', $html );
		$this->assertStringContainsString( '正在載入電子報…', $html );
		$this->assertStringContainsString( '<noscript>', $html );
		$this->assertStringNotContainsString( 'nowcrm-recurring', $html );
		$this->assertStringNotContainsString( 'data-nowcrm-recurring', $html );

		$child = Recurring::generate_now( $parent );
		$this->assertIsInt( $child );

		ob_start();
		CampaignPage::render( $child );
		$child_html = (string) ob_get_clean();

		$this->assertStringContainsString( 'id="nowcrm-campaign-workspace"', $child_html );
		$this->assertStringNotContainsString( 'nowcrm-recurring', $child_html );
		$this->assertStringNotContainsString( 'data-nowcrm-recurring', $child_html );
		$this->assertStringNotContainsString( 'nowcrm-panel', $child_html );
	}
}
