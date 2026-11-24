<?php
/**
 * Abandoned-cart admin: list filters, paging, detail attribution, settings.
 *
 * @package NowCRM
 */

use NowCRM\Admin\Actions;
use NowCRM\Admin\CartPage;
use NowCRM\Admin\Menu;
use NowCRM\Automation\AutomationRepository;
use NowCRM\Automation\TriggerRegistry;
use NowCRM\Cart\Automation as CartAutomation;
use NowCRM\Cart\CartRepository;
use NowCRM\Support\Capabilities;
use NowCRM\Support\Settings;

/**
 * Sentinel thrown by the redirect filter so a handler never reaches exit().
 */
final class NowCrm_Cart_Redirect_Captured extends \RuntimeException {}

/**
 * @covers \NowCRM\Admin\CartPage
 * @covers \NowCRM\Cart\CartRepository
 */
final class CartAdminTest extends NowCrmTestCase {

	public function set_up(): void {
		parent::set_up();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->set_settings(
			array(
				'abandoned_cart_enabled'      => true,
				'email_sending_enabled'       => true,
				'cart_idle_minutes'           => 20,
				'cart_reminder_delay_minutes' => 0,
				'cart_lost_days'              => 5,
				'cart_cooldown_days'          => 3,
				'cart_max_reminders'          => 1,
				'cart_recovery_days'          => 7,
			)
		);
	}

	public function tear_down(): void {
		$_GET     = array();
		$_POST    = array();
		$_REQUEST = array();
		wp_set_current_user( 0 );

		parent::tear_down();
	}

	public function test_list_filters_by_status_and_exact_email(): void {
		$target = $this->make_cart( array( 'email' => 'cart-target@example.test' ) );
		$this->make_cart( array( 'email' => 'cart-other@example.test' ) );

		( new CartRepository() )->mark_abandoned( (int) $target['id'] );

		$_GET = array(
			'page'   => Menu::CART_SLUG,
			'status' => 'abandoned',
			'email'  => 'cart-target@example.test',
		);

		$html = $this->render();

		$this->assertStringContainsString( 'cart-target@example.test', $html );
		$this->assertStringNotContainsString( 'cart-other@example.test', $html );
		$this->assertStringContainsString( '共 1 筆', $html );

		// The email filter is an exact address, not a prefix.
		$_GET = array(
			'page'  => Menu::CART_SLUG,
			'email' => 'cart-target@example.test',
		);
		$html = $this->render();
		$this->assertStringContainsString( 'cart-target@example.test', $html );
		$this->assertStringNotContainsString( 'cart-other@example.test', $html );
	}

	public function test_invalid_filters_show_an_error_and_hide_data(): void {
		$cart = $this->make_cart( array( 'email' => 'cart-invalid@example.test' ) );

		$_GET = array(
			'page'   => Menu::CART_SLUG,
			'status' => 'not-a-status',
		);

		$html = $this->render();

		$this->assertStringContainsString( '狀態篩選不正確，因此未顯示資料。', $html );
		$this->assertStringNotContainsString( 'cart-invalid@example.test', $html );
		$this->assertStringNotContainsString( '#' . (int) $cart['id'], $html );
	}

	public function test_list_pages_ten_rows_and_keeps_the_filter_in_pagination(): void {
		$emails = array();

		for ( $index = 1; $index <= 12; $index++ ) {
			$emails[] = 'cart-page-' . $index . '@example.test';
		}

		foreach ( $emails as $email ) {
			$this->make_cart( array( 'email' => $email ) );
		}

		$_GET = array(
			'page'  => Menu::CART_SLUG,
			'paged' => '2',
		);

		$html = $this->render();

		$this->assertStringContainsString( '共 12 筆', $html );
		$this->assertStringContainsString( '顯示 11–12，共 12 筆', $html );
		$this->assertStringContainsString( $emails[0], $html );
		$this->assertStringContainsString( $emails[1], $html );
		$this->assertStringNotContainsString( $emails[2], $html );

		// Pagination links keep the validated status filter.
		$_GET = array(
			'page'   => Menu::CART_SLUG,
			'status' => 'active',
		);

		$html = $this->render();
		$this->assertStringContainsString( 'status=active', $html );
		$this->assertStringContainsString( 'paged=2', $html );
	}

	public function test_plus_alias_email_filter_round_trips_through_pagination_and_detail_links(): void {
		$email = 'cart+promo@example.test';
		$carts = array();

		for ( $index = 1; $index <= 12; $index++ ) {
			$carts[] = $this->make_cart( array( 'email' => $email ) );
		}

		$_GET = array(
			'page'  => Menu::CART_SLUG,
			'email' => $email,
		);

		$html = $this->render();

		$this->assertStringContainsString( '共 12 筆', $html );
		$this->assertStringContainsString( 'cart+promo@example.test', $html );
		$this->assertStringContainsString( 'email=cart%2Bpromo%40example.test', $html );
		$this->assertStringNotContainsString( 'email=cart+promo@example.test', $html );
		$this->assertStringContainsString( 'paged=2', $html );

		$_GET = array(
			'page'  => Menu::CART_SLUG,
			'email' => $email,
			'paged' => '2',
		);

		$html = $this->render();

		$this->assertStringContainsString( '顯示 11–12，共 12 筆', $html );
		$this->assertStringContainsString( 'email=cart%2Bpromo%40example.test', $html );
		$this->assertStringNotContainsString( 'email=cart+promo@example.test', $html );

		$_GET = array(
			'page'  => Menu::CART_SLUG,
			'cart'  => (string) $carts[0]['id'],
			'email' => $email,
		);

		$html = $this->render();

		$this->assertStringContainsString( 'email=cart%2Bpromo%40example.test', $html );
		$this->assertStringNotContainsString( 'email=cart+promo@example.test', $html );
	}

	public function test_stale_page_returns_to_page_one_with_the_plus_alias_scope(): void {
		$email = 'cart-stale+alias@example.test';
		$this->make_cart( array( 'email' => $email ) );

		$_GET = array(
			'page'   => Menu::CART_SLUG,
			'status' => CartRepository::STATUS_ACTIVE,
			'email'  => $email,
			'paged'  => '5',
		);

		$html = $this->render();

		// The page is out of range, but a matching row exists on page one.
		$this->assertStringContainsString( '這一頁沒有購物車。', $html );
		$this->assertStringContainsString( '共 1 筆', $html );

		$encoded = preg_quote( rawurlencode( $email ), '/' );

		$this->assertMatchesRegularExpression(
			'/href="[^"]*status=active[^"]*email=' . $encoded . '[^"]*"[^>]*>\s*回到第一頁/',
			$html
		);
		$this->assertDoesNotMatchRegularExpression(
			'/href="[^"]*paged=5[^"]*"[^>]*>\s*回到第一頁/',
			$html
		);
		$this->assertStringNotContainsString( '前往設定開啟捕捉', $html );
	}

	public function test_empty_list_does_not_ask_an_enabled_operator_to_enable_capture(): void {
		$_GET = array( 'page' => Menu::CART_SLUG );

		$html = $this->render();

		$this->assertStringContainsString( '還沒有捕捉到購物車', $html );
		$this->assertStringNotContainsString( '前往設定開啟捕捉', $html );
	}

	public function test_detail_separates_natural_completion_from_email_recovery(): void {
		$natural = $this->make_cart( array( 'email' => 'cart-natural@example.test' ) );
		$recovered = $this->make_cart( array( 'email' => 'cart-recovered@example.test' ) );
		$repo = new CartRepository();

		$repo->complete_with_order( (int) $natural['id'], 501, 'natural' );
		$repo->complete_with_order( (int) $recovered['id'], 502, 'email_click' );

		$_GET = array(
			'page' => Menu::CART_SLUG,
			'cart' => (string) $natural['id'],
		);

		$html = $this->render();

		$this->assertStringContainsString( '自然完成', $html );
		$this->assertStringContainsString( '#501', $html );
		$this->assertStringNotContainsString( '郵件連結點擊', $html );
		$this->assertStringNotContainsString( (string) $natural['session_key'], $html );
		$this->assertStringContainsString( 'kind=cart', $html );
		$this->assertStringContainsString( 'cart_id=' . (int) $natural['id'], $html );

		$_GET = array(
			'page' => Menu::CART_SLUG,
			'cart' => (string) $recovered['id'],
		);

		$html = $this->render();

		$this->assertStringContainsString( '郵件連結挽回', $html );
		$this->assertStringContainsString( '#502', $html );
		$this->assertStringContainsString( '郵件連結點擊', $html );
	}

	public function test_detail_never_prints_the_recovery_token_or_session(): void {
		$cart = $this->make_cart( array( 'email' => 'cart-token@example.test' ) );
		$hash = str_repeat( 'a', 64 );

		( new CartRepository() )->set_recovery_token( (int) $cart['id'], $hash, gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ) );

		$_GET = array(
			'page' => Menu::CART_SLUG,
			'cart' => (string) $cart['id'],
		);

		$html = $this->render();

		$this->assertStringContainsString( '還原連結有效至', $html );
		$this->assertStringNotContainsString( $hash, $html );
		$this->assertStringNotContainsString( (string) $cart['session_key'], $html );
		$this->assertStringNotContainsString( 'recovery_token', $html );
	}

	public function test_detail_items_use_the_snapshot_and_fall_back_without_woo_names(): void {
		$product = new \WC_Product_Simple();
		$product->set_name( 'Fixture cart product' );
		$product->set_regular_price( '100' );
		$product->set_status( 'publish' );
		$product->save();

		$cart = $this->make_cart(
			array(
				'email'    => 'cart-items@example.test',
				'snapshot' => array(
					array(
						'product_id'   => $product->get_id(),
						'variation_id' => 0,
						'quantity'     => 2,
						'variation'    => array(),
					),
					array(
						'product_id'   => 999999,
						'variation_id' => 0,
						'quantity'     => 1,
						'variation'    => array(),
					),
				),
			)
		);

		$_GET = array(
			'page' => Menu::CART_SLUG,
			'cart' => (string) $cart['id'],
		);

		$html = $this->render();

		$this->assertStringContainsString( 'Fixture cart product', $html );
		$this->assertStringContainsString( '商品 #999999', $html );
		$this->assertStringContainsString( '商品', $html );
	}

	public function test_settings_tab_requires_the_settings_capability(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$user    = get_user_by( 'id', $user_id );
		$user->add_cap( Capabilities::MANAGE_CONTACTS );

		wp_set_current_user( $user_id );

		$_GET = array(
			'page' => Menu::CART_SLUG,
			'tab'  => 'settings',
		);

		try {
			CartPage::render();
			$this->fail( 'The settings tab should die without the settings capability.' );
		} catch ( WPDieException $exception ) {
			$this->assertStringContainsString( '沒有權限', $exception->getMessage() );
		}

		// The list stays available, but it must not advertise settings.
		$_GET = array( 'page' => Menu::CART_SLUG );
		$html = $this->render();

		$this->assertStringNotContainsString( '設定與提醒', $html );
	}

	public function test_settings_links_reminder_content_to_the_owner_automation(): void {
		$managed = CartAutomation::ensure_managed();
		$this->assertIsInt( $managed );

		$_GET = array(
			'page' => Menu::CART_SLUG,
			'tab'  => 'settings',
		);

		$html = $this->render();

		$this->assertStringContainsString( '在自動化編輯 Email', $html );
		$this->assertStringContainsString( 'page=nowcrm-automation&#038;id=' . (int) $managed, $html );
		$this->assertStringContainsString( '{{cart_items}}', $html );
		$this->assertStringNotContainsString( 'nowcrm_save_cart_template', $html );
		$this->assertStringNotContainsString( 'name="template_id"', $html );
		$this->assertStringNotContainsString( 'cart-template-content', $html );

		$repo = new AutomationRepository();
		$custom = $repo->save(
			array(
				'name'         => 'Custom cart owner',
				'status'       => AutomationRepository::STATUS_ACTIVE,
				'trigger_key'  => TriggerRegistry::CART_ABANDONED,
				'entry_config' => array( 'run_once' => false ),
				'steps'        => array(
					array(
						'kind'   => 'email',
						'config' => array( 'subject' => 'Custom cart subject', 'mode' => 'html', 'content' => '<p>Custom cart body</p>' ),
					),
					array( 'kind' => 'end' ),
				),
			)
		);

		$this->assertIsInt( $custom );
		$this->set_settings( array( CartAutomation::SETTING => (int) $custom ) );

		$html = $this->render();

		$this->assertStringContainsString( 'Custom cart owner（#' . (int) $custom . '）', $html );
		$this->assertStringContainsString( 'page=nowcrm-automation&#038;id=' . (int) $custom, $html );
		$this->assertStringNotContainsString( 'nowcrm_save_cart_template', $html );
	}

	public function test_legacy_cart_template_migrates_into_existing_managed_flow_once(): void {
		$this->set_settings( array( 'cart_reminder_template_id' => 0 ) );
		delete_option( CartAutomation::MANAGED_OPTION );
		delete_option( CartAutomation::MIGRATED_OPTION );
		$managed = CartAutomation::ensure_managed();
		$this->assertIsInt( $managed );

		$templates = new \NowCRM\Email\EmailTemplateRepository();
		$template  = $templates->create(
			array(
				'kind'    => 'cart',
				'name'    => 'legacy-cart',
				'subject' => 'Legacy branded subject',
				'content' => '<p>Legacy branded body</p>{{cart_items}}{{recovery_url}}',
			)
		);

		$this->assertIsInt( $template );
		$this->set_settings( array( 'cart_reminder_template_id' => (int) $template ) );
		delete_option( CartAutomation::MIGRATED_OPTION );

		CartAutomation::migrate_legacy_template();

		$steps = ( new AutomationRepository() )->nested_steps( (int) $managed );
		$this->assertSame( 'Legacy branded subject', $steps[0]['config']['subject'] ?? '' );
		$this->assertSame( '<p>Legacy branded body</p>{{cart_items}}{{recovery_url}}', $steps[0]['config']['content'] ?? '' );
		$this->assertSame( 'html', $steps[0]['config']['mode'] ?? '' );

		// A later template edit must not overwrite the automation-owned email.
		$this->assertTrue(
			$templates->update(
				(int) $template,
				array(
					'name'    => 'legacy-cart',
					'subject' => 'Changed later',
					'content' => '<p>Changed later body</p>',
					'mode'    => 'html',
				)
			)
		);
		delete_option( CartAutomation::MIGRATED_OPTION );
		CartAutomation::migrate_legacy_template();

		$steps = ( new AutomationRepository() )->nested_steps( (int) $managed );
		$this->assertSame( 'Legacy branded subject', $steps[0]['config']['subject'] ?? '' );
	}

	public function test_order_pending_filter_and_count_are_exposed(): void {
		global $wpdb;

		$cart = $this->make_cart( array( 'email' => 'cart-pending@example.test' ) );

		$wpdb->update(
			\NowCRM\Support\Schema::table( 'carts' ),
			array( 'status' => CartRepository::STATUS_ORDER_PENDING ),
			array( 'id' => (int) $cart['id'] )
		);

		$_GET = array(
			'page'   => Menu::CART_SLUG,
			'status' => CartRepository::STATUS_ORDER_PENDING,
		);

		$html = $this->render();

		$this->assertStringContainsString( '待付款', $html );
		$this->assertStringContainsString( 'cart-pending@example.test', $html );
		$this->assertStringContainsString( '共 1 筆', $html );
		$this->assertStringContainsString( 'value="' . CartRepository::STATUS_ORDER_PENDING . '"', $html );
	}

	public function test_detail_links_only_runs_whose_source_is_this_cart(): void {
		$contact = $this->make_contact();
		$cart    = $this->make_cart( array( 'email' => 'cart-runs@example.test' ) );
		$other   = $this->make_cart( array( 'email' => 'cart-other-runs@example.test' ) );

		$run       = $this->insert_cart_run( (int) $cart['id'], (int) $cart['version'], 9101, $contact );
		$other_run = $this->insert_cart_run( (int) $other['id'], (int) $other['version'], 9102, $contact );

		$_GET = array(
			'page' => Menu::CART_SLUG,
			'cart' => (string) $cart['id'],
		);

		$html = $this->render();

		$this->assertStringContainsString( 'data-testid="cart-runs"', $html );
		$this->assertStringContainsString( 'run_id=' . $run, $html );
		$this->assertStringContainsString( 'id=9101', $html );
		$this->assertStringNotContainsString( 'run_id=' . $other_run, $html );
		$this->assertStringNotContainsString( 'id=9102', $html );
		$this->assertStringNotContainsString( (string) $cart['session_key'], $html );
	}

	public function test_settings_save_persists_only_an_eligible_cart_automation(): void {
		$automations = new \NowCRM\Automation\AutomationRepository();
		$steps       = array(
			array(
				'kind'   => 'email',
				'config' => array( 'subject' => 'Cart owner', 'mode' => 'html', 'content' => '<p>Owner</p>' ),
			),
			array( 'kind' => 'end' ),
		);
		$eligible    = $automations->save(
			array(
				'name'         => 'Eligible cart owner',
				'status'       => \NowCRM\Automation\AutomationRepository::STATUS_ACTIVE,
				'trigger_key'  => \NowCRM\Automation\TriggerRegistry::CART_ABANDONED,
				'entry_config' => array( 'run_once' => false ),
				'steps'        => $steps,
			)
		);

		$this->assertIsInt( $eligible );
		$this->set_settings( array( 'cart_automation_id' => 0 ) );

		$this->post(
			'nowcrm_save_settings',
			array(
				'settings_scope'       => 'cart',
				'cart_automation_id'   => (string) $eligible,
			)
		);

		$args = $this->query_args( $this->capture_redirect( static fn() => Actions::save_settings() ) );

		$this->assertSame( 'settings_saved', $args['nowcrm_notice'] ?? '' );
		$this->assertSame( (int) $eligible, Settings::int( 'cart_automation_id' ) );

		$paused = $automations->save(
			array(
				'name'         => 'Paused cart owner',
				'status'       => \NowCRM\Automation\AutomationRepository::STATUS_PAUSED,
				'trigger_key'  => \NowCRM\Automation\TriggerRegistry::CART_ABANDONED,
				'entry_config' => array( 'run_once' => false ),
				'steps'        => $steps,
			)
		);

		$this->assertIsInt( $paused );

		$this->post(
			'nowcrm_save_settings',
			array(
				'settings_scope'     => 'cart',
				'cart_automation_id' => (string) $paused,
			)
		);

		$args = $this->query_args( $this->capture_redirect( static fn() => Actions::save_settings() ) );

		$this->assertSame( 'cart_automation_invalid', $args['nowcrm_notice'] ?? '' );
		$this->assertSame( (int) $eligible, Settings::int( 'cart_automation_id' ), 'A rejected selection must not replace the stored owner.' );
	}

	public function test_settings_keep_a_paused_selection_visible_instead_of_unselecting_it(): void {
		$automations = new \NowCRM\Automation\AutomationRepository();
		$paused      = $automations->save(
			array(
				'name'         => 'Paused visible owner',
				'status'       => \NowCRM\Automation\AutomationRepository::STATUS_PAUSED,
				'trigger_key'  => \NowCRM\Automation\TriggerRegistry::CART_ABANDONED,
				'entry_config' => array( 'run_once' => false ),
				'steps'        => array(
					array(
						'kind'   => 'email',
						'config' => array( 'subject' => 'Paused owner', 'mode' => 'html', 'content' => '<p>Paused</p>' ),
					),
					array( 'kind' => 'end' ),
				),
			)
		);

		$this->assertIsInt( $paused );
		$this->set_settings( array( 'cart_automation_id' => (int) $paused ) );

		$_GET = array(
			'page' => Menu::CART_SLUG,
			'tab'  => CartPage::TAB_SETTINGS,
		);

		$html = $this->render();

		$this->assertStringContainsString( 'data-testid="cart-automation-select"', $html );
		$this->assertStringContainsString( '已暫停', $html );
		$this->assertStringContainsString( 'value="' . (int) $paused . '" selected="selected"', $html );
		$this->assertStringContainsString( '在你儲存新的選擇前，系統不會自動改回預設流程', $html );
	}

	/**
	 * Insert one source-scoped run row for the traceability links.
	 */
	private function insert_cart_run( int $cart_id, int $version, int $automation_id, int $contact_id ): int {
		global $wpdb;

		$now      = gmdate( 'Y-m-d H:i:s' );
		$inserted = $wpdb->insert(
			\NowCRM\Support\Schema::table( 'automation_runs' ),
			array(
				'automation_id'  => $automation_id,
				'contact_id'     => $contact_id,
				'cart_id'        => $cart_id,
				'cart_version'   => $version,
				'status'         => \NowCRM\Automation\RunRepository::STATUS_ACTIVE,
				'source_trigger' => \NowCRM\Automation\TriggerRegistry::CART_ABANDONED,
				'source_ref'     => 'cart:' . $cart_id . ':' . $version,
				'started_at'     => $now,
				'updated_at'     => $now,
			),
			array( '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s' )
		);

		$this->assertTrue( (bool) $inserted );

		return (int) $wpdb->insert_id;
	}

	/**
	 * Render the current screen state.
	 */
	private function render(): string {
		ob_start();

		try {
			CartPage::render();
		} finally {
			$html = (string) ob_get_clean();
		}

		return $html;
	}

	/**
	 * Populate POST for an admin-post handler with a valid nonce.
	 *
	 * @param array<string, mixed> $data Posted values.
	 */
	private function post( string $action, array $data ): void {
		$_POST = array_merge(
			array(
				'_wpnonce' => wp_create_nonce( $action ),
				'action'   => $action,
			),
			$data
		);
		$_REQUEST = $_POST;
	}

	/**
	 * Query arguments from a redirect location.
	 *
	 * @return array<string, string>
	 */
	private function query_args( string $location ): array {
		$query = (string) wp_parse_url( $location, PHP_URL_QUERY );
		parse_str( $query, $args );

		return array_map( 'strval', $args );
	}

	/**
	 * Capture a handler redirect instead of reaching exit().
	 */
	private function capture_redirect( callable $handler ): string {
		$captured = '';
		$filter   = static function ( $location ) use ( &$captured ): void {
			$captured = (string) $location;
			throw new NowCrm_Cart_Redirect_Captured( $captured );
		};

		add_filter( 'wp_redirect', $filter, 1 );

		try {
			$handler();
		} catch ( NowCrm_Cart_Redirect_Captured $exception ) {
			$captured = $exception->getMessage();
		} finally {
			remove_filter( 'wp_redirect', $filter, 1 );
		}

		$this->assertNotSame( '', $captured, 'The handler did not redirect.' );

		return $captured;
	}
}
