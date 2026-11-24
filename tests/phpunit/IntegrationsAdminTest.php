<?php
/**
 * Integration screens: list/detail routing and preserved save scope.
 *
 * @package NowCRM
 */

use NowCRM\Admin\ApiKeysPage;
use NowCRM\Admin\ConsentSettings;
use NowCRM\Admin\WebhooksPage;
use NowCRM\Support\Capabilities;
use NowCRM\Webhooks\DeliveryLog;
use NowCRM\Webhooks\Events;
use NowCRM\Webhooks\WebhookRepository;

/**
 * @covers \NowCRM\Admin\WebhooksPage
 * @covers \NowCRM\Admin\ApiKeysPage
 * @covers \NowCRM\Admin\ConsentSettings
 */
final class IntegrationsAdminTest extends NowCrmTestCase {

	private WebhookRepository $webhooks;
	private DeliveryLog $log;
	private string $event;

	public function set_up(): void {
		parent::set_up();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		Capabilities::grant();

		delete_option( WebhookRepository::OPTION );
		delete_option( DeliveryLog::OPTION );

		$this->webhooks = new WebhookRepository();
		$this->log      = new DeliveryLog();
		$this->event    = (string) array_key_first( Events::all() );
	}

	public function tear_down(): void {
		$_GET     = array();
		$_POST    = array();
		$_REQUEST = array();

		remove_all_filters( 'wp_is_application_passwords_available' );
		wp_set_current_user( 0 );

		parent::tear_down();
	}

	public function test_webhook_list_is_data_first_and_keeps_endpoint_actions(): void {
		$created = $this->webhooks->create(
			array(
				'name'   => 'Order sync',
				'url'    => 'https://example.test/hooks/now',
				'events' => array( $this->event ),
				'active' => true,
			)
		);

		$this->assertIsArray( $created );

		$_GET = array( 'page' => WebhooksPage::SLUG );

		$html = $this->render( array( WebhooksPage::class, 'render' ) );

		$this->assertStringContainsString( 'Order sync', $html );
		$this->assertStringContainsString( 'nowcrm-webhook-row-' . (string) $created['id'], $html );
		$this->assertStringContainsString( 'view=new', $html );
		$this->assertStringNotContainsString( 'id="nowcrm-webhook-form"', $html );
		$this->assertStringContainsString( 'nowcrm_webhook_rotate_secret', $html );
		$this->assertStringContainsString( 'nowcrm_webhook_delete', $html );
	}

	public function test_webhook_detail_shows_only_its_deliveries_and_edit_is_opt_in(): void {
		$first = $this->webhooks->create(
			array(
				'name'   => 'First endpoint',
				'url'    => 'https://example.test/hooks/first',
				'events' => array( $this->event ),
				'active' => true,
			)
		);
		$second = $this->webhooks->create(
			array(
				'name'   => 'Second endpoint',
				'url'    => 'https://example.test/hooks/second',
				'events' => array( $this->event ),
				'active' => true,
			)
		);

		$this->assertIsArray( $first );
		$this->assertIsArray( $second );

		$this->log->add(
			array(
				'webhook_id'    => (string) $first['id'],
				'event'         => $this->event,
				'status'        => 'delivered',
				'response_code' => 200,
			)
		);
		$this->log->add(
			array(
				'webhook_id'    => (string) $second['id'],
				'event'         => $this->event,
				'status'        => 'failed',
				'response_code' => 500,
			)
		);

		$_GET = array(
			'page'    => WebhooksPage::SLUG,
			'webhook' => (string) $first['id'],
			'view'    => 'detail',
		);

		$html = $this->render( array( WebhooksPage::class, 'render' ) );

		$this->assertStringContainsString( '此端點的傳送紀錄', $html );
		$this->assertStringContainsString( 'First endpoint', $html );
		$this->assertStringNotContainsString( 'Second endpoint', $html );
		$this->assertStringNotContainsString( 'id="nowcrm-webhook-form"', $html );
	}

	public function test_bare_webhook_link_still_opens_the_edit_form(): void {
		$created = $this->webhooks->create(
			array(
				'name'   => 'Bookmarked endpoint',
				'url'    => 'https://example.test/hooks/bookmark',
				'events' => array( $this->event ),
				'active' => false,
			)
		);

		$this->assertIsArray( $created );

		$_GET = array(
			'page'    => WebhooksPage::SLUG,
			'webhook' => (string) $created['id'],
		);

		$html = $this->render( array( WebhooksPage::class, 'render' ) );

		$this->assertStringContainsString( 'id="nowcrm-webhook-form"', $html );
		$this->assertStringContainsString( 'value="Bookmarked endpoint"', $html );
	}

	public function test_unknown_webhook_reports_missing_without_the_form(): void {
		$_GET = array(
			'page'    => WebhooksPage::SLUG,
			'webhook' => 'does-not-exist',
		);

		$html = $this->render( array( WebhooksPage::class, 'render' ) );

		$this->assertStringContainsString( '找不到這個 Webhook', $html );
		$this->assertStringNotContainsString( 'id="nowcrm-webhook-form"', $html );
	}

	public function test_api_key_list_renders_before_an_explicit_create_request(): void {
		add_filter( 'wp_is_application_passwords_available', '__return_true' );

		$_GET = array( 'page' => ApiKeysPage::SLUG );

		$html = $this->render( array( ApiKeysPage::class, 'render' ) );

		$this->assertStringContainsString( '現有金鑰', $html );
		$this->assertStringContainsString( 'view=new', $html );
		$this->assertStringNotContainsString( 'name="action" value="nowcrm_ap_create"', $html );

		$_GET = array(
			'page' => ApiKeysPage::SLUG,
			'view' => 'new',
		);

		$html = $this->render( array( ApiKeysPage::class, 'render' ) );

		$this->assertStringContainsString( 'name="action" value="nowcrm_ap_create"', $html );
		$this->assertStringContainsString( 'id="nowcrm-api-create"', $html );
	}

	public function test_consent_screen_keeps_one_save_form_and_all_fields(): void {
		$_GET = array( 'page' => ConsentSettings::SLUG );

		$html = $this->render( array( new ConsentSettings(), 'render' ) );

		$this->assertSame( 1, substr_count( $html, '<form' ) );
		$this->assertStringContainsString( 'value="nowcrm_save_consent"', $html );
		$this->assertStringContainsString( 'name="double_optin_email_subject"', $html );
		$this->assertStringContainsString( 'name="double_optin_resend_throttle_minutes"', $html );
		$this->assertStringContainsString( 'name="public_lists_mode"', $html );
		$this->assertStringContainsString( 'name="footer_text"', $html );
		$this->assertStringContainsString( 'name="public_request_throttle_minutes"', $html );
	}

	public function test_failed_webhook_create_returns_to_new_form_with_draft(): void {
		$_POST    = array(
			'action' => 'nowcrm_webhook_save',
			'name'   => 'Draft endpoint',
			'url'    => 'http://example.com/hooks/draft',
			'events' => array( $this->event ),
			'active' => '1',
		);
		$_REQUEST = array( '_wpnonce' => wp_create_nonce( 'nowcrm_webhook_save' ) );

		$location = $this->capture_redirect(
			static function (): void {
				WebhooksPage::save();
			}
		);

		$this->assertStringContainsString( 'nowcrm_webhook_notice=https_required', $location );
		$this->assertStringContainsString( 'view=new', $location );

		$query = array();
		parse_str( (string) wp_parse_url( $location, PHP_URL_QUERY ), $query );
		$_GET = $query;

		$html = $this->render( array( WebhooksPage::class, 'render' ) );

		$this->assertStringContainsString( 'id="nowcrm-webhook-form"', $html );
		$this->assertStringContainsString( 'value="Draft endpoint"', $html );
		$this->assertStringContainsString( 'value="http://example.com/hooks/draft"', $html );
		$this->assertSame( array(), $this->webhooks->all(), 'A failed create must not store the endpoint.' );
	}

	public function test_failed_webhook_update_returns_to_edit_with_draft_and_unchanged_secret(): void {
		$created = $this->webhooks->create(
			array(
				'name'   => 'Keep this endpoint',
				'url'    => 'https://example.test/hooks/keep',
				'events' => array( $this->event ),
				'active' => false,
			)
		);

		$this->assertIsArray( $created );
		$secret = (string) $created['secret'];

		$_POST    = array(
			'action' => 'nowcrm_webhook_save',
			'id'     => (string) $created['id'],
			'name'   => 'Draft endpoint',
			'url'    => 'http://example.com/hooks/draft',
			'events' => array( $this->event ),
			'active' => '1',
		);
		$_REQUEST = array( '_wpnonce' => wp_create_nonce( 'nowcrm_webhook_save' ) );

		$location = $this->capture_redirect(
			static function (): void {
				WebhooksPage::save();
			}
		);

		$this->assertStringContainsString( 'nowcrm_webhook_notice=https_required', $location );
		$this->assertStringContainsString( 'webhook=' . (string) $created['id'], $location );

		$query = array();
		parse_str( (string) wp_parse_url( $location, PHP_URL_QUERY ), $query );
		$_GET = $query;

		$html = $this->render( array( WebhooksPage::class, 'render' ) );

		$this->assertStringContainsString( 'id="nowcrm-webhook-form"', $html );
		$this->assertStringContainsString( 'value="Draft endpoint"', $html );
		$this->assertStringContainsString( 'value="http://example.com/hooks/draft"', $html );
		$this->assertStringContainsString( 'value="' . (string) $created['id'] . '"', $html );
		$this->assertMatchesRegularExpression( '/name="active" value="1"\s+checked/', $html );
		$this->assertStringNotContainsString( $secret, $html, 'The signing secret must not enter the draft.' );

		$stored = $this->webhooks->find( (string) $created['id'] );

		$this->assertIsArray( $stored );
		$this->assertSame( 'Keep this endpoint', $stored['name'] );
		$this->assertSame( 'https://example.test/hooks/keep', $stored['url'] );
		$this->assertSame( $secret, $stored['secret'] );

		$_GET = array(
			'page'    => WebhooksPage::SLUG,
			'webhook' => (string) $created['id'],
		);

		$html = $this->render( array( WebhooksPage::class, 'render' ) );

		$this->assertStringContainsString( 'value="Keep this endpoint"', $html );
		$this->assertStringNotContainsString( 'value="Draft endpoint"', $html );
	}

	/**
	 * Capture one screen render.
	 *
	 * @param callable $screen Renderer.
	 */
	private function render( callable $screen ): string {
		ob_start();

		try {
			$screen();
		} finally {
			$html = (string) ob_get_clean();
		}

		return $html;
	}

	/**
	 * Capture the wp_safe_redirect() target of a handler.
	 */
	private function capture_redirect( callable $handler ): string {
		$captured = '';
		$filter   = static function ( $location ) use ( &$captured ): void {
			$captured = (string) $location;
			throw new \RuntimeException( $captured );
		};

		add_filter( 'wp_redirect', $filter, 1 );

		try {
			$handler();
		} catch ( \RuntimeException $exception ) {
			$captured = $exception->getMessage();
		} finally {
			remove_filter( 'wp_redirect', $filter, 1 );
		}

		$this->assertNotSame( '', $captured, 'The handler did not redirect.' );

		return $captured;
	}
}
