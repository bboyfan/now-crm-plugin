<?php
/**
 * Overview and report navigation: tabs, range links, comparison ranges and
 * the counters each screen is allowed to show.
 *
 * @package NowCRM
 */

use NowCRM\Admin\AnalyticsTrend;
use NowCRM\Admin\DashboardPage;
use NowCRM\Admin\Menu;
use NowCRM\Admin\ReportsPage;
use NowCRM\Automation\AutomationRepository;
use NowCRM\Automation\TriggerRegistry;
use NowCRM\Campaign\Campaigns;
use NowCRM\Support\Capabilities;
use NowCRM\Support\Schema;

/**
 * @covers \NowCRM\Admin\AnalyticsTrend
 * @covers \NowCRM\Admin\DashboardPage
 * @covers \NowCRM\Admin\ReportsPage
 */
final class AnalyticsTest extends NowCrmTestCase {

	private int $admin = 0;

	public function set_up(): void {
		parent::set_up();

		$this->admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin );

		$_GET = array();
	}

	public function tear_down(): void {
		$_GET = array();
		wp_set_current_user( 0 );

		parent::tear_down();
	}

	/**
	 * Insert an accepted broadcast job at a fixed time.
	 */
	private function job( int $campaign_id, string $email, string $at ): int {
		global $wpdb;

		$table = Schema::table( 'email_jobs' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- trusted table name.
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table}
				(job_key, kind, campaign_id, recipient, subject, status, available_at, accepted_at, created_at, updated_at)
				VALUES (%s, %s, %d, %s, %s, %s, %s, %s, %s, %s)",
				'analytics-' . md5( $email . $at . wp_generate_password( 6, false ) ),
				'campaign',
				$campaign_id,
				$email,
				'Fixture subject',
				'accepted',
				$at,
				$at,
				$at,
				$at
			)
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Insert an engagement event at a fixed time.
	 */
	private function event( int $campaign_id, string $type, string $at, int $job_id = 0 ): void {
		global $wpdb;

		$wpdb->insert(
			Schema::table( 'email_events' ),
			array(
				'event_key'   => substr( 'analytics:' . $type . ':' . md5( wp_generate_password( 16, false ) ), 0, 191 ),
				'type'        => $type,
				'campaign_id' => $campaign_id,
				'job_id'      => $job_id,
				'contact_id'  => 0,
				'url'         => 'click' === $type ? 'https://example.test/offer' : '',
				'user_agent'  => 'fixture',
				'ip_hash'     => '',
				'meta'        => '{}',
				'occurred_at' => $at,
			)
		);
	}

	/**
	 * Insert a contact at a fixed creation time.
	 */
	private function contact( string $email, string $created_at, string $status = 'subscribed' ): int {
		global $wpdb;

		$wpdb->insert(
			Schema::table( 'contacts' ),
			array(
				'email'            => $email,
				'email_normalized' => mb_strtolower( $email, 'UTF-8' ),
				'status'           => $status,
				'source'           => 'test',
				'created_at'       => $created_at,
				'updated_at'       => $created_at,
			)
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Render a screen and return its HTML.
	 */
	private function render( callable $renderer ): string {
		ob_start();
		$renderer();
		return (string) ob_get_clean();
	}

	public function test_dashboard_links_real_contacts_queue_and_campaigns(): void {
		$this->contact( 'subscribed@example.test', '2026-01-05 10:00:00', 'subscribed' );
		$this->contact( 'gone@example.test', '2026-01-06 10:00:00', 'unsubscribed' );

		$campaign = $this->make_campaign( array( 'title' => 'Dashboard fixture campaign', 'status' => Campaigns::STATUS_SENT ) );
		$job      = $this->job( $campaign, 'reader@example.test', '2026-01-07 10:00:00' );
		$this->event( $campaign, 'open', '2026-01-07 11:00:00', $job );
		$this->event( $campaign, 'click', '2026-01-07 12:00:00', $job );

		$_GET = array( 'page' => Menu::DASHBOARD_SLUG );
		$html = $this->render( array( DashboardPage::class, 'render' ) );

		$this->assertStringContainsString( 'page=nowcrm&#038;status=subscribed', $html );
		$this->assertStringContainsString( 'page=nowcrm&#038;status=unsubscribed', $html );
		$this->assertStringContainsString( 'page=nowcrm-email&#038;status=queued', $html );
		$this->assertStringContainsString( 'page=nowcrm-reports', $html );
		$this->assertStringContainsString( 'page=nowcrm-campaign-new', $html );
		$this->assertStringContainsString( 'Dashboard fixture campaign', $html );
		$this->assertStringContainsString( 'page=nowcrm-reports&#038;tab=email&#038;campaign_id=' . $campaign, $html );
		$this->assertStringNotContainsString( 'nowcrm_run_queue', $html );
	}

	public function test_dashboard_omits_creation_without_the_campaign_capability(): void {
		$user = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$user = new WP_User( $user );
		$user->add_cap( Capabilities::MANAGE_CONTACTS );
		wp_set_current_user( $user->ID );

		$_GET = array( 'page' => Menu::DASHBOARD_SLUG );
		$html = $this->render( array( DashboardPage::class, 'render' ) );

		$this->assertStringNotContainsString( 'page=nowcrm-campaign-new', $html );
		$this->assertStringContainsString( 'page=nowcrm-reports', $html );
	}

	public function test_dashboard_growth_period_links_resolve_the_contacts_report_range(): void {
		$zone  = wp_timezone();
		$today = new DateTimeImmutable( 'now', $zone );
		$from  = $today->modify( '-' . ( 30 - 1 ) . ' days' )->format( 'Y-m-d' );
		$to    = $today->format( 'Y-m-d' );

		$_GET = array( 'page' => Menu::DASHBOARD_SLUG );
		$html = $this->render( array( DashboardPage::class, 'render' ) );

		$this->assertStringContainsString( 'page=nowcrm-dashboard&#038;days=7', $html );
		$this->assertStringContainsString( 'page=nowcrm-dashboard&#038;days=90', $html );
		$this->assertMatchesRegularExpression( '/href="[^"]*days=30"[^>]*aria-current="true"/', $html );
		$this->assertStringContainsString( 'page=nowcrm-reports&#038;tab=contacts&#038;from=' . $from . '&#038;to=' . $to, $html );

		$_GET = array( 'page' => Menu::DASHBOARD_SLUG, 'days' => '90' );
		$html = $this->render( array( DashboardPage::class, 'render' ) );

		$wide_from = $today->modify( '-' . ( 90 - 1 ) . ' days' )->format( 'Y-m-d' );
		$this->assertMatchesRegularExpression( '/href="[^"]*days=90"[^>]*aria-current="true"/', $html );
		$this->assertStringContainsString( 'page=nowcrm-reports&#038;tab=contacts&#038;from=' . $wide_from . '&#038;to=' . $to, $html );

		$_GET = array( 'page' => Menu::DASHBOARD_SLUG, 'days' => '45' );
		$html = $this->render( array( DashboardPage::class, 'render' ) );

		$this->assertStringNotContainsString( 'days=45', $html );
		$this->assertMatchesRegularExpression( '/href="[^"]*days=30"[^>]*aria-current="true"/', $html );
	}

	public function test_dashboard_lists_recent_contacts_and_active_automations(): void {
		$contact = $this->make_contact(
			array(
				'email'      => 'recent@example.test',
				'first_name' => 'Recent',
				'last_name'  => 'Person',
			)
		);

		$repository = new AutomationRepository();
		$active     = $repository->save(
			array(
				'name'         => 'Dashboard active flow',
				'status'       => AutomationRepository::STATUS_ACTIVE,
				'trigger_key'  => TriggerRegistry::ORDER_COMPLETED,
				'entry_config' => array( 'run_once' => false ),
				'steps'        => array( array( 'kind' => 'end' ) ),
			)
		);
		$this->assertIsInt( $active );

		$draft = $repository->save(
			array(
				'name'         => 'Dashboard draft flow',
				'status'       => AutomationRepository::STATUS_DRAFT,
				'trigger_key'  => TriggerRegistry::ORDER_COMPLETED,
				'entry_config' => array( 'run_once' => false ),
				'steps'        => array( array( 'kind' => 'end' ) ),
			)
		);
		$this->assertIsInt( $draft );

		$_GET = array( 'page' => Menu::DASHBOARD_SLUG );
		$html = $this->render( array( DashboardPage::class, 'render' ) );

		$this->assertStringContainsString( 'Recent Person', $html );
		$this->assertStringContainsString( 'recent@example.test', $html );
		$this->assertStringContainsString( 'page=nowcrm-contact&#038;id=' . $contact, $html );
		$this->assertStringContainsString( 'Dashboard active flow', $html );
		$this->assertStringContainsString( 'page=nowcrm-automation&#038;id=' . $active, $html );
		$this->assertStringContainsString( 'page=nowcrm-automation-runs&#038;id=' . $active, $html );
		$this->assertStringNotContainsString( 'Dashboard draft flow', $html );
	}

	public function test_dashboard_sending_notice_requires_the_settings_capability(): void {
		$this->set_settings( array( 'email_sending_enabled' => false ) );

		$_GET = array( 'page' => Menu::DASHBOARD_SLUG );
		$html = $this->render( array( DashboardPage::class, 'render' ) );

		$this->assertStringContainsString( '實際寄送目前關閉', $html );
		$this->assertStringContainsString( 'page=nowcrm-settings', $html );

		$user = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$user = new WP_User( $user );
		$user->add_cap( Capabilities::MANAGE_CONTACTS );
		wp_set_current_user( $user->ID );

		$html = $this->render( array( DashboardPage::class, 'render' ) );

		$this->assertStringNotContainsString( '實際寄送目前關閉', $html );
		$this->assertStringNotContainsString( 'page=nowcrm-settings', $html );
	}

	public function test_report_defaults_to_contacts_and_omits_other_tasks(): void {
		$this->contact( 'inside@example.test', '2026-01-05 10:00:00', 'subscribed' );

		$_GET = array( 'page' => ReportsPage::SLUG, 'from' => '2026-01-01', 'to' => '2026-01-31' );
		$html = $this->render( array( ReportsPage::class, 'render' ) );

		$this->assertStringContainsString( 'id="nowcrm-report-contacts"', $html );
		$this->assertStringNotContainsString( 'id="nowcrm-report-campaigns"', $html );
		$this->assertStringNotContainsString( 'id="nowcrm-report-orders"', $html );
		$this->assertStringNotContainsString( 'id="nowcrm-report-carts"', $html );
		$this->assertStringContainsString( 'page=nowcrm&#038;status=subscribed', $html );
		$this->assertMatchesRegularExpression(
			'/href="[^"]*tab=contacts&#038;from=2026-01-01&#038;to=2026-01-31"[^>]*aria-current="page"/',
			$html
		);
		$this->assertStringNotContainsString( 'nowcrm-report-export', $html );
	}

	public function test_unknown_tab_falls_back_to_the_contacts_task(): void {
		$_GET = array( 'page' => ReportsPage::SLUG, 'tab' => 'bogus' );
		$html = $this->render( array( ReportsPage::class, 'render' ) );

		$this->assertStringContainsString( 'id="nowcrm-report-contacts"', $html );
		$this->assertMatchesRegularExpression( '/href="[^"]*tab=contacts"[^>]*aria-current="page"/', $html );
	}

	public function test_email_tab_keeps_campaign_filters_export_and_detail_link(): void {
		$campaign = $this->make_campaign( array( 'title' => 'Email tab campaign', 'status' => Campaigns::STATUS_SENT ) );
		$job      = $this->job( $campaign, 'reader@example.test', '2026-01-07 10:00:00' );
		$this->event( $campaign, 'open', '2026-01-07 11:00:00', $job );

		$_GET = array(
			'page'   => ReportsPage::SLUG,
			'tab'    => 'email',
			'from'   => '2026-01-01',
			'to'     => '2026-01-31',
			'status' => Campaigns::STATUS_SENT,
		);
		$html = $this->render( array( ReportsPage::class, 'render' ) );

		$this->assertStringContainsString( 'id="nowcrm-report-campaigns"', $html );
		$this->assertStringNotContainsString( 'id="nowcrm-report-contacts"', $html );
		$this->assertStringContainsString( 'Email tab campaign', $html );
		$this->assertStringContainsString( 'nowcrm-report-export', $html );
		$this->assertStringContainsString( '#nowcrm-report-campaign-detail', $html );
		$this->assertStringNotContainsString( '目前的狀態是', $html );
		$this->assertStringNotContainsString( '不符合所選狀態', $html );
		$this->assertMatchesRegularExpression( '/href="[^"]*tab=email[^"]*"[^>]*aria-current="page"/', $html );
	}

	public function test_legacy_campaign_url_opens_the_email_tab_detail(): void {
		$campaign = $this->make_campaign( array( 'title' => 'Legacy detail campaign', 'status' => Campaigns::STATUS_SENT ) );
		$job      = $this->job( $campaign, 'reader@example.test', '2026-01-07 10:00:00' );
		$this->event( $campaign, 'click', '2026-01-07 12:00:00', $job );

		$_GET = array(
			'page'        => ReportsPage::SLUG,
			'campaign_id' => (string) $campaign,
			'from'        => '2026-01-01',
			'to'          => '2026-01-31',
		);
		$html = $this->render( array( ReportsPage::class, 'render' ) );

		$this->assertStringContainsString( 'id="nowcrm-report-campaign-detail"', $html );
		$this->assertStringContainsString( 'Legacy detail campaign', $html );
		$this->assertStringContainsString( 'id="nowcrm-report-campaign-links"', $html );
		$this->assertStringNotContainsString( 'id="nowcrm-report-contacts"', $html );
		$this->assertMatchesRegularExpression( '/href="[^"]*tab=email[^"]*"[^>]*aria-current="page"/', $html );
	}

	public function test_comparison_range_uses_the_previous_equal_period(): void {
		$this->contact( 'jan@example.test', '2026-01-10 10:00:00', 'subscribed' );
		$this->contact( 'feb-one@example.test', '2026-02-10 10:00:00', 'subscribed' );
		$this->contact( 'feb-two@example.test', '2026-02-11 10:00:00', 'unsubscribed' );

		$_GET = array(
			'page'    => ReportsPage::SLUG,
			'tab'     => 'contacts',
			'from'    => '2026-02-01',
			'to'      => '2026-02-28',
			'compare' => 'previous_period',
		);
		$html = $this->render( array( ReportsPage::class, 'render' ) );

		$this->assertStringContainsString( '比較範圍：2026-01-04 至 2026-01-31', $html );
		$this->assertStringContainsString( '前期', $html );
		$this->assertStringContainsString( '變化', $html );
		$this->assertStringContainsString( '目前總數與狀態分佈不隨範圍變動', $html );
	}

	public function test_custom_comparison_requires_two_valid_dates(): void {
		$this->contact( 'december@example.test', '2025-12-10 10:00:00', 'subscribed' );

		$_GET = array(
			'page'    => ReportsPage::SLUG,
			'tab'     => 'contacts',
			'from'    => '2026-01-01',
			'to'      => '2026-01-31',
			'compare' => 'custom',
		);
		$invalid = $this->render( array( ReportsPage::class, 'render' ) );

		$this->assertStringContainsString( '比較範圍無效，已省略比較。', $invalid );

		$_GET = array(
			'page'         => ReportsPage::SLUG,
			'tab'          => 'contacts',
			'from'         => '2026-01-01',
			'to'           => '2026-01-31',
			'compare'      => 'custom',
			'compare_from' => '2025-12-01',
			'compare_to'   => '2025-12-31',
		);
		$valid = $this->render( array( ReportsPage::class, 'render' ) );

		$this->assertStringContainsString( '比較範圍：2025-12-01 至 2025-12-31', $valid );
	}

	public function test_tab_links_keep_only_the_filters_the_target_uses(): void {
		$_GET = array(
			'page'        => ReportsPage::SLUG,
			'tab'         => 'email',
			'from'        => '2026-01-01',
			'to'          => '2026-01-31',
			'campaign_id' => '12',
			'status'      => Campaigns::STATUS_SENT,
			'paged'       => '2',
			'compare'     => 'previous_period',
		);
		$html = $this->render( array( ReportsPage::class, 'render' ) );

		$this->assertMatchesRegularExpression( '/href="[^"]*tab=contacts[^"]*"[^>]*>/', $html );
		$this->assertStringContainsString( 'page=nowcrm-reports&#038;tab=contacts&#038;from=2026-01-01&#038;to=2026-01-31&#038;compare=previous_period', $html );
		$this->assertStringContainsString( 'page=nowcrm-reports&#038;tab=email&#038;from=2026-01-01&#038;to=2026-01-31&#038;compare=previous_period&#038;campaign_id=12&#038;status=sent&#038;paged=2', $html );
	}

	public function test_historical_campaign_keeps_rows_but_not_a_detail_link(): void {
		$live     = $this->make_campaign( array( 'title' => 'Live detail campaign', 'status' => Campaigns::STATUS_SENT ) );
		$live_job = $this->job( $live, 'live@example.test', '2026-01-07 10:00:00' );
		$this->event( $live, 'click', '2026-01-07 12:00:00', $live_job );

		$retired     = $this->make_campaign( array( 'title' => 'Retired detail campaign', 'status' => Campaigns::STATUS_SENT ) );
		$retired_job = $this->job( $retired, 'retired@example.test', '2026-01-08 10:00:00' );
		$this->event( $retired, 'click', '2026-01-08 12:00:00', $retired_job );
		wp_trash_post( $retired );

		$_GET = array(
			'page' => ReportsPage::SLUG,
			'tab'  => 'email',
			'from' => '2026-01-01',
			'to'   => '2026-01-31',
		);
		$html = $this->render( array( ReportsPage::class, 'render' ) );

		$this->assertStringContainsString( 'Live detail campaign', $html );
		$this->assertStringContainsString( '#' . $retired, $html );
		$this->assertMatchesRegularExpression( '/href="[^"]*campaign_id=' . $live . '[^"]*#nowcrm-report-campaign-detail"/', $html );
		$this->assertStringNotContainsString( 'campaign_id=' . $retired, $html );
		$this->assertStringContainsString( '歷史資料，沒有可開啟的明細', $html );
	}

	public function test_commerce_and_cart_tabs_render_only_their_own_panels(): void {
		global $wpdb;

		$cart = $this->make_cart( array( 'total' => 300, 'currency' => 'TWD' ) );
		$wpdb->update( Schema::table( 'carts' ), array( 'status' => 'recovered' ), array( 'id' => (int) $cart['id'] ), array( '%s' ), array( '%d' ) );
		$this->cart_event( (int) $cart['id'], 'abandoned', '2026-01-10 10:00:00' );

		$_GET = array(
			'page' => ReportsPage::SLUG,
			'tab'  => 'commerce',
			'from' => '2026-01-01',
			'to'   => '2026-01-31',
		);
		$commerce = $this->render( array( ReportsPage::class, 'render' ) );

		$this->assertStringContainsString( 'id="nowcrm-report-orders"', $commerce );
		$this->assertStringNotContainsString( 'id="nowcrm-report-campaigns"', $commerce );
		$this->assertStringNotContainsString( 'id="nowcrm-report-carts"', $commerce );
		$this->assertStringContainsString( '範圍內沒有計入營收的訂單。', $commerce );

		$_GET = array(
			'page' => ReportsPage::SLUG,
			'tab'  => 'cart',
			'from' => '2026-01-01',
			'to'   => '2026-01-31',
		);
		$cart = $this->render( array( ReportsPage::class, 'render' ) );

		$this->assertStringContainsString( 'id="nowcrm-report-carts"', $cart );
		$this->assertStringNotContainsString( 'id="nowcrm-report-orders"', $cart );
		$this->assertStringContainsString( 'page=nowcrm-cart', $cart );
		$this->assertStringContainsString( '<span>待付款訂單（目前狀態）</span><strong>0</strong>', $cart );
		$this->assertStringContainsString( '<span>郵件挽回（目前狀態，不代表已付款）</span><strong>1</strong>', $cart );
		$this->assertStringContainsString( '<span>郵件挽回已付款訂單</span><strong>0</strong>', $cart );
		$this->assertStringContainsString( '<span>自然完成（未確認付款）</span>', $cart );
		$this->assertStringContainsString( '<span>挽回率（已付款訂單／放棄購物車）</span>', $cart );
		$this->assertStringContainsString( '期間事件（依事件發生時間）', $cart );
		$this->assertStringContainsString( 'data-nowcrm-cart-event="reminder_queued"', $cart );
		$this->assertStringContainsString( 'data-nowcrm-cart-event="order_paid"', $cart );
		$this->assertStringContainsString( '不代表已付款', $cart );
		$this->assertStringContainsString( '只使用 WooCommerce 既有紀錄，沒有訂單連結時不推估補值', $cart );
		$this->assertStringNotContainsString( '挽回金額（已付款，扣除退款）', $cart );
	}

	public function test_report_offers_quick_presets_and_always_visible_comparison_dates(): void {
		$zone  = wp_timezone();
		$today = new DateTimeImmutable( 'now', $zone );
		$from  = $today->modify( '-' . ( 30 - 1 ) . ' days' )->format( 'Y-m-d' );
		$to    = $today->format( 'Y-m-d' );

		$_GET = array(
			'page' => ReportsPage::SLUG,
			'tab'  => 'contacts',
			'from' => $from,
			'to'   => $to,
		);
		$html = $this->render( array( ReportsPage::class, 'render' ) );

		$this->assertStringContainsString( '快速期間', $html );
		$this->assertStringContainsString( '近 7 天', $html );
		$this->assertStringContainsString( '近 90 天', $html );
		$this->assertMatchesRegularExpression( '/href="[^"]*from=' . preg_quote( $from, '/' ) . '&#038;to=' . preg_quote( $to, '/' ) . '"[^>]*aria-current="true"/', $html );
		$this->assertStringContainsString( 'name="compare_from"', $html );
		$this->assertStringContainsString( 'name="compare_to"', $html );
		$this->assertStringContainsString( 'name="list_id"', $html );
		$this->assertStringContainsString( '清除篩選', $html );
	}

	public function test_previous_month_comparison_uses_complete_previous_calendar_month(): void {
		$_GET = array(
			'page'    => ReportsPage::SLUG,
			'tab'     => 'contacts',
			'from'    => '2026-01-31',
			'to'      => '2026-02-27',
			'compare' => 'previous_month',
		);
		$html = $this->render( array( ReportsPage::class, 'render' ) );

		$this->assertStringContainsString( '上一個完整日曆月', $html );
		$this->assertStringContainsString( '比較範圍：2025-12-01 至 2025-12-31（31 天）', $html );
		$this->assertStringContainsString( '以本期起始日 2026-01-31 為錨點', $html );
		$this->assertStringNotContainsString( '注意：比較範圍與目前範圍重疊', $html );

		$_GET = array(
			'page'    => ReportsPage::SLUG,
			'tab'     => 'contacts',
			'from'    => '2026-03-01',
			'to'      => '2026-03-31',
			'compare' => 'previous_month',
		);
		$html = $this->render( array( ReportsPage::class, 'render' ) );

		$this->assertStringContainsString( '比較範圍：2026-02-01 至 2026-02-28（28 天）', $html );

		$_GET = array(
			'page'    => ReportsPage::SLUG,
			'tab'     => 'contacts',
			'from'    => '2028-03-10',
			'to'      => '2028-03-20',
			'compare' => 'previous_month',
		);
		$html = $this->render( array( ReportsPage::class, 'render' ) );

		$this->assertStringContainsString( '比較範圍：2028-02-01 至 2028-02-29（29 天）', $html );
	}

	public function test_previous_quarter_and_year_use_complete_calendar_periods_without_overlap(): void {
		$_GET = array(
			'page'    => ReportsPage::SLUG,
			'tab'     => 'contacts',
			'from'    => '2026-02-15',
			'to'      => '2026-02-20',
			'compare' => 'previous_quarter',
		);
		$html = $this->render( array( ReportsPage::class, 'render' ) );

		$this->assertStringContainsString( '上一個完整日曆季', $html );
		$this->assertStringContainsString( '以本期起始日 2026-02-15 為錨點', $html );
		$this->assertStringContainsString( '比較範圍：2025-10-01 至 2025-12-31（92 天）', $html );
		$this->assertStringNotContainsString( '注意：比較範圍與目前範圍重疊', $html );

		$_GET = array(
			'page'    => ReportsPage::SLUG,
			'tab'     => 'contacts',
			'from'    => '2026-05-10',
			'to'      => '2026-05-20',
			'compare' => 'previous_quarter',
		);
		$html = $this->render( array( ReportsPage::class, 'render' ) );

		$this->assertStringContainsString( '比較範圍：2026-01-01 至 2026-03-31（90 天）', $html );

		$_GET = array(
			'page'    => ReportsPage::SLUG,
			'tab'     => 'contacts',
			'from'    => '2026-03-01',
			'to'      => '2026-03-31',
			'compare' => 'previous_year',
		);
		$html = $this->render( array( ReportsPage::class, 'render' ) );

		$this->assertStringContainsString( '上一個完整日曆年', $html );
		$this->assertStringContainsString( '比較範圍：2025-01-01 至 2025-12-31（365 天）', $html );
		$this->assertStringContainsString( '不會與本期重疊', $html );
		$this->assertStringNotContainsString( '注意：比較範圍與目前範圍重疊', $html );
	}

	public function test_one_day_trend_series_use_distinct_markers_and_keep_exact_values(): void {
		$html = $this->render(
			static function (): void {
				AnalyticsTrend::render(
					'單日比較',
					array(
						'本期' => array( '2026-01-05' => 3 ),
						'前期' => array( '2025-12-05' => 2 ),
					)
				);
			}
		);

		$this->assertStringContainsString( '<circle', $html );
		$this->assertStringContainsString( '<rect', $html );
		$this->assertStringContainsString( '本期（2026-01-05：3，單日圓點）', $html );
		$this->assertStringContainsString( '前期（2025-12-05：2，單日方形）', $html );
		$this->assertStringContainsString( '單日系列以標記顯示', $html );
		$this->assertStringContainsString( '查看每日數值', $html );
	}

	public function test_invalid_contact_term_filter_does_not_broaden_to_every_contact(): void {
		$this->contact( 'inside@example.test', '2026-01-05 10:00:00', 'subscribed' );

		$_GET = array(
			'page'    => ReportsPage::SLUG,
			'tab'     => 'contacts',
			'from'    => '2026-01-01',
			'to'      => '2026-01-31',
			'list_id' => '999999',
		);
		$html = $this->render( array( ReportsPage::class, 'render' ) );

		$this->assertStringContainsString( '名單或標籤篩選無效', $html );
		$this->assertStringContainsString( '不會自動改成全部聯絡人', $html );
		$this->assertStringNotContainsString( '目前狀態分佈', $html );
	}

	public function test_missing_campaign_scope_has_a_clear_empty_state(): void {
		$campaign = $this->make_campaign( array( 'title' => 'Picker campaign', 'status' => Campaigns::STATUS_SENT ) );
		$this->job( $campaign, 'reader@example.test', '2026-01-07 10:00:00' );

		$_GET = array(
			'page'        => ReportsPage::SLUG,
			'tab'         => 'email',
			'from'        => '2026-01-01',
			'to'          => '2026-01-31',
			'campaign_id' => '999999',
		);
		$html = $this->render( array( ReportsPage::class, 'render' ) );

		$this->assertStringContainsString( '找不到這封電子報，原電子報目前無法使用', $html );
		$this->assertStringContainsString( '找不到這封電子報，沒有可顯示的資料。', $html );
		$this->assertStringContainsString( '仍會列出保留的歷史寄送與互動資料', $html );
		$this->assertStringContainsString( '已刪除的電子報 #999999', $html );
	}

	public function test_selected_campaign_with_mismatched_current_status_hides_detail(): void {
		$campaign = $this->make_campaign( array( 'title' => 'Mismatch campaign', 'status' => Campaigns::STATUS_SENT ) );
		$job      = $this->job( $campaign, 'mismatch@example.test', '2026-01-07 10:00:00' );
		$this->event( $campaign, 'click', '2026-01-07 12:00:00', $job );

		$_GET = array(
			'page'        => ReportsPage::SLUG,
			'tab'         => 'email',
			'from'        => '2026-01-01',
			'to'          => '2026-01-31',
			'campaign_id' => (string) $campaign,
			'status'      => Campaigns::STATUS_DRAFT,
		);
		$html = $this->render( array( ReportsPage::class, 'render' ) );

		$this->assertStringContainsString( '與所選的「草稿」不同', $html );
		$this->assertStringContainsString( '清除狀態篩選', $html );
		$this->assertStringContainsString( '不符合所選狀態', $html );
		$this->assertStringNotContainsString( 'id="nowcrm-report-campaign-detail"', $html );
		$this->assertStringNotContainsString( 'id="nowcrm-report-campaign-links"', $html );
	}

	public function test_automation_tab_renders_bounded_runs_and_execution_links(): void {
		$contact = $this->contact( 'automation-run@example.test', '2026-01-01 00:00:00' );

		global $wpdb;

		$wpdb->insert(
			Schema::table( 'automations' ),
			array(
				'name'        => 'Automation report fixture',
				'status'      => 'active',
				'trigger_key' => 'contact_created',
				'created_at'  => '2026-01-01 00:00:00',
				'updated_at'  => '2026-01-01 00:00:00',
			)
		);
		$automation = (int) $wpdb->insert_id;

		$wpdb->insert(
			Schema::table( 'automation_runs' ),
			array(
				'automation_id'  => $automation,
				'contact_id'     => $contact,
				'status'         => 'active',
				'source_trigger' => 'contact_created',
				'started_at'     => '2026-01-10 10:00:00',
				'updated_at'     => '2026-01-10 10:00:00',
			)
		);

		$_GET = array(
			'page' => ReportsPage::SLUG,
			'tab'  => 'automation',
			'from' => '2026-01-01',
			'to'   => '2026-01-31',
		);
		$html = $this->render( array( ReportsPage::class, 'render' ) );

		$this->assertStringContainsString( 'id="nowcrm-report-automation"', $html );
		$this->assertStringNotContainsString( 'id="nowcrm-report-contacts"', $html );
		$this->assertStringNotContainsString( 'id="nowcrm-report-campaigns"', $html );
		$this->assertStringContainsString( 'Automation report fixture', $html );
		$this->assertStringContainsString( 'page=nowcrm-automation-runs&#038;id=' . $automation, $html );
	}

	/**
	 * Insert a cart event at a fixed time.
	 */
	private function cart_event( int $cart_id, string $type, string $at ): void {
		global $wpdb;

		$wpdb->insert(
			Schema::table( 'cart_events' ),
			array(
				'cart_id'     => $cart_id,
				'type'        => $type,
				'meta'        => '{}',
				'occurred_at' => $at,
			)
		);
	}
}
