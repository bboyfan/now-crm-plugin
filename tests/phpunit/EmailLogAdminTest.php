<?php
/**
 * Email log admin: filter validation, tabs, detail, and retry affordance.
 *
 * @package NowCRM
 */

use NowCRM\Admin\Actions;
use NowCRM\Admin\EmailLogPage;
use NowCRM\Admin\Menu;
use NowCRM\Email\ContactHistory;
use NowCRM\Email\EmailJobRepository;
use NowCRM\Support\Capabilities;

/**
 * @covers \NowCRM\Admin\EmailLogPage
 */
final class EmailLogAdminTest extends NowCrmTestCase {

	private EmailJobRepository $jobs;

	public function set_up(): void {
		parent::set_up();

		$this->jobs = new EmailJobRepository();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	public function tear_down(): void {
		$_GET     = array();
		$_POST    = array();
		$_REQUEST = array();
		wp_set_current_user( 0 );

		parent::tear_down();
	}

	public function test_detail_links_an_exact_run_only_when_it_belongs_to_the_job_automation(): void {
		global $wpdb;

		$contact = $this->make_contact();
		$now     = gmdate( 'Y-m-d H:i:s' );

		$wpdb->insert(
			\NowCRM\Support\Schema::table( 'automation_runs' ),
			array(
				'automation_id'  => 771,
				'contact_id'     => $contact,
				'status'         => \NowCRM\Automation\RunRepository::STATUS_ACTIVE,
				'source_trigger' => \NowCRM\Automation\TriggerRegistry::CART_ABANDONED,
				'source_ref'     => 'cart:5:1',
				'started_at'     => $now,
				'updated_at'     => $now,
			),
			array( '%d', '%d', '%s', '%s', '%s', '%s', '%s' )
		);

		$run_id = (int) $wpdb->insert_id;

		$this->assertGreaterThan( 0, $run_id );

		$job = $this->job(
			array(
				'kind'    => 'automation',
				'payload' => array(
					'automation_id' => 771,
					'run_id'        => $run_id,
					'step_id'       => 9,
				),
			)
		);

		$html = $this->render_page( array( 'job' => (string) $job ) );

		$this->assertStringContainsString( 'page=nowcrm-automation-runs', $html );
		$this->assertStringContainsString( 'run_id=' . $run_id, $html );
		$this->assertStringContainsString( '執行 #' . $run_id, $html );

		// A run that belongs to another workflow is not linked as this job's run.
		$wpdb->update(
			\NowCRM\Support\Schema::table( 'automation_runs' ),
			array( 'automation_id' => 772 ),
			array( 'id' => $run_id )
		);

		$mismatch = $this->render_page( array( 'job' => (string) $job ) );

		$this->assertStringContainsString( '找不到或不屬於此自動化', $mismatch );
		$this->assertStringNotContainsString( 'run_id=' . $run_id, $mismatch );
	}

	/**
	 * Render the page and return its HTML.
	 *
	 * @param array<string, mixed> $query Query arguments.
	 */
	private function render_page( array $query = array() ): string {
		$_GET     = array_merge( array( 'page' => Menu::EMAIL_SLUG ), $query );
		$_REQUEST = $_GET;

		$die    = static function ( $message = '', $title = '', $args = array() ): void {
			throw new WPDieException( 'render_died' );
		};
		$filter = static fn() => $die;

		add_filter( 'wp_die_handler', $filter, 99 );
		ob_start();

		try {
			EmailLogPage::render();
		} finally {
			$html = (string) ob_get_clean();
			remove_filter( 'wp_die_handler', $filter, 99 );
		}

		return $html;
	}

	/**
	 * Insert one job and force its status.
	 *
	 * @param array<string, mixed> $overrides Job fields.
	 */
	private function job( array $overrides = array(), string $status = EmailJobRepository::STATUS_QUEUED ): int {
		global $wpdb;

		$id = $this->jobs->enqueue(
			array_merge(
				array(
					'job_key'   => 'log-' . wp_generate_password( 10, false ),
					'kind'      => 'custom',
					'recipient' => 'log-' . wp_generate_password( 6, false ) . '@example.test',
					'subject'   => 'Fixture subject',
					'payload'   => array(),
				),
				$overrides
			)
		);

		$this->assertIsInt( $id );

		if ( EmailJobRepository::STATUS_QUEUED !== $status ) {
			$wpdb->update(
				\NowCRM\Support\Schema::table( 'email_jobs' ),
				array( 'status' => $status ),
				array( 'id' => (int) $id )
			);
		}

		return (int) $id;
	}

	/**
	 * User with contacts access only, no settings.
	 */
	private function contacts_only_user(): int {
		$user_id = (int) self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$user    = get_user_by( 'id', $user_id );

		$this->assertInstanceOf( WP_User::class, $user );
		$user->add_cap( Capabilities::MANAGE_CONTACTS );
		wp_set_current_user( $user_id );

		return $user_id;
	}

	public function test_delivery_filter_accepts_allow_listed_values_and_rejects_others(): void {
		foreach ( array( 'none', 'delivered', 'bounced', 'complained', 'deferred' ) as $value ) {
			$result = EmailLogPage::filters_from_query( array( 'delivery_status' => $value ) );

			$this->assertSame( '', $result['error'], $value . ' must be accepted.' );
			$this->assertSame( $value, $result['filters']['delivery_status'] );
		}

		$result = EmailLogPage::filters_from_query( array( 'status' => 'accepted', 'delivery_status' => 'bogus' ) );

		$this->assertSame( 'delivery_status', $result['error'] );
		$this->assertSame( array(), $result['filters'] );
	}

	public function test_none_delivery_filter_lists_only_jobs_without_a_receipt(): void {
		$this->job( array( 'recipient' => 'pending@example.test' ), EmailJobRepository::STATUS_ACCEPTED );

		$delivered = $this->job( array( 'recipient' => 'delivered@example.test' ), EmailJobRepository::STATUS_ACCEPTED );

		$this->assertTrue( $this->jobs->mark_delivery_event( $delivered, EmailJobRepository::DELIVERY_DELIVERED ) );

		$html = $this->render_page( array( 'delivery_status' => 'none' ) );

		$this->assertStringContainsString( 'pending@example.test', $html );
		$this->assertStringNotContainsString( 'delivered@example.test', $html );
	}

	public function test_display_label_prefers_delivery_result_and_falls_back_to_queue_status(): void {
		$this->assertSame( '已送達', ContactHistory::display_status_label( array( 'status' => 'accepted', 'delivery_status' => 'delivered' ) ) );
		$this->assertSame( '已退信', ContactHistory::display_status_label( array( 'status' => 'accepted', 'delivery_status' => 'bounced' ) ) );
		$this->assertSame( '客訴', ContactHistory::display_status_label( array( 'status' => 'accepted', 'delivery_status' => 'complained' ) ) );
		$this->assertSame( '暫時退信', ContactHistory::display_status_label( array( 'status' => 'accepted', 'delivery_status' => 'deferred' ) ) );
		$this->assertSame( '尚未回條', ContactHistory::delivery_label( '' ) );
		$this->assertSame( '已送出', ContactHistory::display_status_label( array( 'status' => 'accepted', 'delivery_status' => '' ) ) );
		$this->assertSame( '失敗', ContactHistory::display_status_label( array( 'status' => 'failed' ) ) );
	}

	public function test_filters_keep_valid_values_and_reject_invalid_ones(): void {
		$valid = EmailLogPage::filters_from_query(
			array(
				'status'      => 'failed',
				'kind'        => 'cart',
				'campaign_id' => '55',
				'cart_id'     => '7',
				'email'       => '  Buyer@Example.Test  ',
			)
		);

		$this->assertSame( '', $valid['error'] );
		$this->assertSame( 'failed', $valid['filters']['status'] );
		$this->assertSame( 'cart', $valid['filters']['kind'] );
		$this->assertSame( 55, $valid['filters']['campaign_id'] );
		$this->assertSame( 7, $valid['filters']['cart_id'] );
		$this->assertSame( 'buyer@example.test', $valid['filters']['email'] );

		foreach ( array( 'status' => 'bogus', 'kind' => 'bogus', 'campaign_id' => 'abc', 'cart_id' => '0', 'email' => 'not-an-email' ) as $key => $value ) {
			$query = array( $key => $value );

			if ( 'kind' !== $key ) {
				$query['kind'] = 'cart';
			}

			$result = EmailLogPage::filters_from_query( $query );

			$this->assertSame( $key, $result['error'], $key . ' must be rejected.' );
			$this->assertSame( array(), $result['filters'], $key . ' must not leave a widened scope.' );
		}

		// Array input is not a scalar filter and must not be read as one.
		$result = EmailLogPage::filters_from_query( array( 'status' => array( 'failed' ) ) );
		$this->assertSame( '', $result['error'] );
		$this->assertSame( array(), $result['filters'] );
	}

	public function test_tab_allowlist_falls_back_and_hides_diagnostics(): void {
		$this->assertSame( EmailLogPage::TAB_LOG, EmailLogPage::tab_from_query( '', true ) );
		$this->assertSame( EmailLogPage::TAB_LOG, EmailLogPage::tab_from_query( 'bogus', true ) );
		$this->assertSame( EmailLogPage::TAB_SUPPRESSION, EmailLogPage::tab_from_query( 'suppression', false ) );
		$this->assertSame( EmailLogPage::TAB_LOG, EmailLogPage::tab_from_query( 'diagnostics', false ) );
		$this->assertSame( EmailLogPage::TAB_DIAGNOSTICS, EmailLogPage::tab_from_query( 'diagnostics', true ) );
	}

	public function test_retry_matches_repository_conditions(): void {
		$this->assertTrue( EmailLogPage::can_retry( array( 'kind' => 'custom', 'status' => 'failed', 'campaign_id' => 0 ) ) );
		$this->assertTrue( EmailLogPage::can_retry( array( 'kind' => 'cart', 'status' => 'unknown', 'campaign_id' => 0 ) ) );
		$this->assertTrue( EmailLogPage::can_retry( array( 'kind' => 'custom', 'status' => 'suppressed', 'campaign_id' => 0 ) ) );
		$this->assertTrue( EmailLogPage::can_retry( array( 'kind' => 'campaign', 'status' => 'failed', 'campaign_id' => 0 ) ) );
		$this->assertFalse( EmailLogPage::can_retry( array( 'kind' => 'campaign', 'status' => 'failed', 'campaign_id' => 55 ) ) );
		$this->assertFalse( EmailLogPage::can_retry( array( 'kind' => 'custom', 'status' => 'accepted', 'campaign_id' => 0 ) ) );
		$this->assertFalse( EmailLogPage::can_retry( array( 'kind' => 'custom', 'status' => 'queued', 'campaign_id' => 0 ) ) );
		$this->assertFalse( EmailLogPage::can_retry( array( 'kind' => 'custom', 'status' => 'unknown', 'campaign_id' => 0, 'delivery_status' => 'delivered' ) ) );
		$this->assertFalse( EmailLogPage::can_retry( array( 'kind' => 'cart', 'status' => 'unknown', 'campaign_id' => 0, 'cart_id' => 7 ) ) );
		$this->assertTrue( EmailLogPage::can_retry( array( 'kind' => 'cart', 'status' => 'failed', 'campaign_id' => 0, 'cart_id' => 7 ) ) );
	}

	public function test_invalid_filter_renders_error_and_no_rows(): void {
		$this->job( array( 'recipient' => 'hidden@example.test', 'subject' => 'Hidden fixture' ) );

		$html = $this->render_page( array( 'status' => 'bogus' ) );

		$this->assertStringContainsString( '狀態篩選值不正確', $html );
		$this->assertStringContainsString( 'role="alert"', $html );
		$this->assertStringNotContainsString( 'hidden@example.test', $html );
		$this->assertStringNotContainsString( '沒有符合目前篩選條件', $html );
	}

	public function test_out_of_range_page_clamps_to_last_page(): void {
		for ( $i = 0; $i < 12; $i++ ) {
			$this->job( array( 'recipient' => 'page-' . $i . '@example.test' ) );
		}

		$html = $this->render_page( array( 'paged' => '99' ) );

		$this->assertStringContainsString( '共 12 筆', $html );
		$this->assertStringContainsString( 'page-0@example.test', $html );
		$this->assertStringNotContainsString( '目前還沒有寄送紀錄', $html );
	}

	public function test_detail_shows_attempts_and_retry_for_retryable_job(): void {
		global $wpdb;

		$id = $this->job(
			array(
				'recipient' => 'retry@example.test',
				'subject'   => 'Retry fixture',
				'payload'   => array(
					'template' => array(
						'mode'    => 'html',
						'content' => '<p>Hello {{first_name}}</p><a href="{{recovery_url}}">Recover</a><a href="{{unsubscribe_url}}">Unsubscribe</a>',
					),
					'recovery_url' => 'https://example.test/recover?token=SECRET',
				),
			),
			EmailJobRepository::STATUS_FAILED
		);

		$wpdb->update(
			\NowCRM\Support\Schema::table( 'email_jobs' ),
			array( 'attempts' => 2, 'last_error' => 'SMTP timeout <script>' ),
			array( 'id' => $id )
		);

		$html = $this->render_page( array( 'job' => (string) $id, 'kind' => 'custom' ) );

		$this->assertStringContainsString( 'retry@example.test', $html );
		$this->assertStringContainsString( '2 / 3', $html );
		$this->assertStringContainsString( 'SMTP timeout &lt;script&gt;', $html );
		$this->assertStringContainsString( 'value="nowcrm_retry_job"', $html );
		$this->assertStringContainsString( 'name="job" value="' . $id . '"', $html );
		$this->assertStringContainsString( 'Hello', $html );
		$this->assertStringContainsString( 'sandbox', $html );
		$this->assertStringContainsString( 'Content-Security-Policy', $html );
		$this->assertStringNotContainsString( 'href=&quot;', $html );
		$this->assertStringNotContainsString( 'SECRET', $html );
		$this->assertStringNotContainsString( 'token=', $html );
	}

	public function test_detail_omits_retry_for_receipted_or_cart_linked_unknown_jobs(): void {
		$receipt = $this->job(
			array( 'recipient' => 'receipt@example.test', 'kind' => 'custom' ),
			EmailJobRepository::STATUS_UNKNOWN
		);

		$this->assertTrue( $this->jobs->mark_delivery_event( $receipt, EmailJobRepository::DELIVERY_DELIVERED ) );

		$receipt_html = $this->render_page( array( 'job' => (string) $receipt ) );

		$this->assertStringContainsString( 'receipt@example.test', $receipt_html );
		$this->assertStringNotContainsString( 'value="nowcrm_retry_job"', $receipt_html );

		$cart = $this->job(
			array(
				'recipient' => 'cart-unknown@example.test',
				'kind'      => 'cart',
				'cart_id'   => 12,
			),
			EmailJobRepository::STATUS_UNKNOWN
		);

		$cart_html = $this->render_page( array( 'job' => (string) $cart ) );

		$this->assertStringContainsString( 'cart-unknown@example.test', $cart_html );
		$this->assertStringNotContainsString( 'value="nowcrm_retry_job"', $cart_html );

		$failed = $this->job(
			array( 'recipient' => 'unrelated-failed@example.test', 'kind' => 'custom' ),
			EmailJobRepository::STATUS_FAILED
		);

		$failed_html = $this->render_page( array( 'job' => (string) $failed ) );

		$this->assertStringContainsString( 'unrelated-failed@example.test', $failed_html );
		$this->assertStringContainsString( 'value="nowcrm_retry_job"', $failed_html );
	}

	public function test_detail_offers_no_retry_for_campaign_job(): void {
		$campaign = $this->make_campaign( array( 'status' => 'sending' ) );
		$id       = $this->job(
			array(
				'kind'        => 'campaign',
				'campaign_id' => $campaign,
				'recipient'   => 'campaign@example.test',
			),
			EmailJobRepository::STATUS_FAILED
		);

		$html = $this->render_page( array( 'job' => (string) $id ) );

		$this->assertStringContainsString( 'campaign@example.test', $html );
		$this->assertStringNotContainsString( 'value="nowcrm_retry_job"', $html );
	}

	public function test_missing_detail_id_reports_not_found_without_data(): void {
		$html = $this->render_page( array( 'job' => '999999' ) );

		$this->assertStringContainsString( '找不到這筆寄送紀錄', $html );
		$this->assertStringNotContainsString( 'value="nowcrm_retry_job"', $html );
	}

	public function test_detail_retry_carries_only_present_validated_return_context(): void {
		$id = $this->job(
			array( 'recipient' => 'return@example.test', 'kind' => 'custom' ),
			EmailJobRepository::STATUS_FAILED
		);

		$html = $this->render_page(
			array(
				'job'    => (string) $id,
				'status' => 'failed',
				'kind'   => 'custom',
				'email'  => 'return@example.test',
			)
		);

		$this->assertStringContainsString( 'name="return_job" value="1"', $html );
		$this->assertStringContainsString( 'name="return_status" value="failed"', $html );
		$this->assertStringContainsString( 'name="return_kind" value="custom"', $html );
		$this->assertStringContainsString( 'name="return_email" value="return@example.test"', $html );
		$this->assertStringNotContainsString( 'name="return_campaign_id"', $html );
		$this->assertStringNotContainsString( 'name="return_cart_id"', $html );
		$this->assertStringNotContainsString( 'name="return_paged"', $html );

		$paged = $this->render_page( array( 'job' => (string) $id, 'paged' => '2' ) );

		$this->assertStringContainsString( 'name="return_paged" value="2"', $paged );

		$this->assertStringNotContainsString( 'name="return_job"', $this->render_page() );
	}

	public function test_detail_links_cart_job_to_cart_page_and_filtered_log(): void {
		$id = $this->job(
			array(
				'kind'      => 'cart',
				'cart_id'   => 42,
				'recipient' => 'cart@example.test',
			),
			EmailJobRepository::STATUS_FAILED
		);

		$html = $this->render_page( array( 'job' => (string) $id ) );

		$this->assertStringContainsString( 'page=nowcrm-cart', $html );
		$this->assertStringContainsString( 'cart=42', $html );
		$this->assertStringContainsString( 'cart_id=42', $html );
		$this->assertStringContainsString( '已簽署的復原連結', $html );
		$this->assertStringNotContainsString( '<iframe', $html );
	}

	public function test_consent_job_never_renders_its_signed_confirmation_link(): void {
		$id = $this->job(
			array(
				'kind'      => 'consent',
				'recipient' => 'consent@example.test',
				'payload'   => array(
					'template' => array(
						'mode'    => 'html',
						'content' => '<a href="https://example.test/confirm?token=SIGNED">Confirm</a>',
					),
				),
			)
		);

		$html = $this->render_page( array( 'job' => (string) $id ) );

		$this->assertStringContainsString( '已簽署的確認連結', $html );
		$this->assertStringNotContainsString( 'SIGNED', $html );
		$this->assertStringNotContainsString( '<iframe', $html );
	}

	public function test_capability_is_required_to_open_the_log(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$this->expectException( WPDieException::class );

		$this->render_page();
	}

	public function test_diagnostics_tab_requires_settings_but_suppression_does_not(): void {
		$this->contacts_only_user();

		$html = $this->render_page( array( 'tab' => 'diagnostics' ) );

		$this->assertStringContainsString( '寄送紀錄', $html );
		$this->assertStringNotContainsString( '佇列與 worker 狀態', $html );
		$this->assertStringNotContainsString( 'nowcrm_run_queue', $html );

		$suppression = $this->render_page( array( 'tab' => 'suppression' ) );

		$this->assertStringContainsString( '抑制名單', $suppression );
		$this->assertStringContainsString( 'aria-current="page"', $suppression );
		$this->assertStringNotContainsString( '佇列與 worker 狀態', $suppression );
	}

	public function test_administrator_diagnostics_keeps_worker_action_in_settings_scope(): void {
		$html = $this->render_page( array( 'tab' => 'diagnostics' ) );

		$this->assertStringContainsString( '佇列與 worker 狀態', $html );
		$this->assertStringContainsString( 'value="nowcrm_run_queue"', $html );
		$this->assertStringContainsString( '實際寄送開關', $html );
	}

	public function test_diagnostics_shows_worker_schedule_and_ses_notification_sections(): void {
		wp_clear_scheduled_hook( \NowCRM\Email\Queue::CRON );

		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( \NowCRM\Email\Queue::CRON, array(), \NowCRM\Email\Queue::GROUP );
		}

		delete_option( \NowCRM\Email\WorkerHealth::OPTION );
		$this->job( array( 'recipient' => 'diagnostics@example.test' ) );

		$html = $this->render_page( array( 'tab' => 'diagnostics' ) );

		$this->assertStringContainsString( '佇列與 worker 狀態', $html );
		$this->assertStringContainsString( '上次成功執行', $html );
		$this->assertStringContainsString( '尚未記錄', $html );
		$this->assertStringContainsString( '下次排程', $html );
		$this->assertStringContainsString( '未排程', $html );
		$this->assertStringContainsString( '待人工查核（失敗／未知／抑制）', $html );
		$this->assertStringContainsString( 'button button-primary', $html );
		$this->assertStringContainsString( 'name="_wpnonce"', $html );
		$this->assertStringContainsString( 'SES 通知', $html );
		$this->assertStringContainsString( '最後收到', $html );
		$this->assertStringContainsString( '尚未收到', $html );
		$this->assertStringContainsString( '尚未設定允許的 Topic ARN', $html );
	}

	public function test_diagnostics_hides_raw_ses_errors_payload_and_recipients(): void {
		global $wpdb;

		$wpdb->insert(
			\NowCRM\Support\Schema::table( 'ses_events' ),
			array(
				'sns_message_id'    => 'sns-diagnostics-leak',
				'event_key'         => 'diagnostics-leak',
				'event_type'        => 'Delivery',
				'ses_message_id'    => 'ses-diagnostics-leak',
				'email_normalized'  => 'leak@example.test',
				'status'            => 'failed',
				'matched_recipients' => 0,
				'payload'           => '{"secret":"RAW-PAYLOAD-SECRET"}',
				'processed'         => 0,
				'error'             => 'RAW-ERROR-SECRET',
				'received_at'       => gmdate( 'Y-m-d H:i:s' ),
			)
		);

		$html = $this->render_page( array( 'tab' => 'diagnostics' ) );

		$this->assertStringContainsString( '處理失敗', $html );
		$this->assertStringNotContainsString( 'leak@example.test', $html );
		$this->assertStringNotContainsString( 'RAW-ERROR-SECRET', $html );
		$this->assertStringNotContainsString( 'RAW-PAYLOAD-SECRET', $html );
	}

	public function test_retry_post_round_trip_returns_to_job_with_plus_tagged_email(): void {
		$email = 'support+tag@example.test';
		$id    = $this->job(
			array( 'recipient' => $email, 'kind' => 'custom' ),
			EmailJobRepository::STATUS_FAILED
		);

		$_POST    = array(
			'action'        => 'nowcrm_retry_job',
			'job'           => (string) $id,
			'return_job'    => '1',
			'return_status' => 'failed',
			'return_kind'   => 'custom',
			'return_email'  => $email,
			'return_delivery_status' => 'deferred',
			'return_paged'  => '2',
			'_wpnonce'      => wp_create_nonce( 'nowcrm_retry_job_' . $id ),
		);
		$_REQUEST = $_POST;

		$args = $this->query_args( $this->capture_redirect( static fn() => Actions::retry_job() ) );

		$this->assertSame( Menu::EMAIL_SLUG, $args['page'] ?? '' );
		$this->assertSame( 'job_retried', $args['nowcrm_notice'] ?? '' );
		$this->assertSame( (string) $id, $args['job'] ?? '' );
		$this->assertSame( 'failed', $args['status'] ?? '' );
		$this->assertSame( 'custom', $args['kind'] ?? '' );
		$this->assertSame( $email, $args['email'] ?? '' );
		$this->assertSame( 'deferred', $args['delivery_status'] ?? '' );
		$this->assertSame( '2', $args['paged'] ?? '' );

		$this->assertSame( EmailJobRepository::STATUS_QUEUED, $this->jobs->get( $id )['status'] );
		$this->assertSame( 0, (int) $this->jobs->get( $id )['attempts'] );
	}

	public function test_failed_retry_keeps_delivery_status_context(): void {
		$id = $this->job(
			array( 'recipient' => 'no-retry@example.test', 'kind' => 'custom' ),
			EmailJobRepository::STATUS_ACCEPTED
		);

		$this->assertTrue( $this->jobs->mark_delivery_event( $id, EmailJobRepository::DELIVERY_DELIVERED ) );

		$_POST    = array(
			'action'        => 'nowcrm_retry_job',
			'job'           => (string) $id,
			'return_status' => 'accepted',
			'return_kind'   => 'custom',
			'return_delivery_status' => 'delivered',
			'_wpnonce'      => wp_create_nonce( 'nowcrm_retry_job_' . $id ),
		);
		$_REQUEST = $_POST;

		$args = $this->query_args( $this->capture_redirect( static fn() => Actions::retry_job() ) );

		$this->assertSame( 'job_retry_failed', $args['nowcrm_notice'] ?? '' );
		$this->assertSame( 'accepted', $args['status'] ?? '' );
		$this->assertSame( 'delivered', $args['delivery_status'] ?? '' );
	}

	public function test_delivery_counter_copy_separates_recorded_results_from_no_receipt(): void {
		$html = $this->render_page();

		$this->assertStringContainsString( '送達狀態計數包含所有已記錄結果的 Job，不限佇列狀態', $html );
		$this->assertStringContainsString( '「尚未回條」只計算已送出但沒有結果的 Job', $html );
	}

	public function test_list_retry_form_and_detail_links_keep_the_page_and_filters(): void {
		$ids = array();

		for ( $i = 0; $i < 11; $i++ ) {
			$ids[] = $this->job(
				array( 'recipient' => 'paged-' . $i . '@example.test', 'kind' => 'custom' ),
				EmailJobRepository::STATUS_FAILED
			);
		}

		$query = array( 'paged' => '2', 'status' => 'failed', 'kind' => 'custom' );
		$html  = $this->render_page( $query );

		$this->assertStringContainsString( 'name="return_status" value="failed"', $html );
		$this->assertStringContainsString( 'name="return_kind" value="custom"', $html );
		$this->assertStringContainsString( 'name="return_paged" value="2"', $html );
		$this->assertStringNotContainsString( 'name="return_job"', $html );
		$this->assertMatchesRegularExpression( '/job=\d+&#038;paged=2/', $html );

		$detail = $this->render_page( array_merge( $query, array( 'job' => (string) $ids[0] ) ) );

		$this->assertStringContainsString( 'name="return_job" value="1"', $detail );
		$this->assertStringContainsString( 'name="return_paged" value="2"', $detail );
		$this->assertStringContainsString( 'kind=custom&#038;paged=2', $detail );
	}

	public function test_list_retry_post_returns_to_the_filtered_page_without_detail_job(): void {
		$email = 'list+tag@example.test';
		$id    = $this->job(
			array( 'recipient' => $email, 'kind' => 'custom' ),
			EmailJobRepository::STATUS_FAILED
		);

		$_POST    = array(
			'action'        => 'nowcrm_retry_job',
			'job'           => (string) $id,
			'return_status' => 'failed',
			'return_kind'   => 'custom',
			'return_email'  => $email,
			'return_paged'  => '2',
			'_wpnonce'      => wp_create_nonce( 'nowcrm_retry_job_' . $id ),
		);
		$_REQUEST = $_POST;

		$args = $this->query_args( $this->capture_redirect( static fn() => Actions::retry_job() ) );

		$this->assertSame( Menu::EMAIL_SLUG, $args['page'] ?? '' );
		$this->assertSame( 'job_retried', $args['nowcrm_notice'] ?? '' );
		$this->assertArrayNotHasKey( 'job', $args );
		$this->assertSame( 'failed', $args['status'] ?? '' );
		$this->assertSame( 'custom', $args['kind'] ?? '' );
		$this->assertSame( $email, $args['email'] ?? '' );
		$this->assertSame( '2', $args['paged'] ?? '' );
	}

	/**
	 * Capture the wp_safe_redirect() target of a handler.
	 */
	private function capture_redirect( callable $handler ): string {
		$captured = '';
		$filter   = static function ( $location ) use ( &$captured ): void {
			$captured = (string) $location;
			throw new NowCrm_EmailLog_Redirect_Captured( $captured );
		};

		add_filter( 'wp_redirect', $filter, 1 );

		try {
			$handler();
		} catch ( NowCrm_EmailLog_Redirect_Captured $exception ) {
			$captured = $exception->getMessage();
		} finally {
			remove_filter( 'wp_redirect', $filter, 1 );
		}

		$this->assertNotSame( '', $captured, 'The handler did not redirect.' );

		return $captured;
	}

	/**
	 * @return array<string, string>
	 */
	private function query_args( string $location ): array {
		$query = (string) wp_parse_url( $location, PHP_URL_QUERY );
		parse_str( $query, $args );

		return array_map( 'strval', $args );
	}
}

/**
 * Sentinel thrown by the redirect filter so the handler never reaches exit().
 */
final class NowCrm_EmailLog_Redirect_Captured extends \RuntimeException {}
