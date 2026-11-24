<?php
/**
 * Settings screen: SES configuration set field, scoped save, and Woo copy.
 *
 * @package NowCRM
 */

use NowCRM\Admin\Menu;
use NowCRM\Admin\SettingsPage;
use NowCRM\Support\Settings;

/**
 * @covers \NowCRM\Admin\SettingsPage
 * @covers \NowCRM\Admin\Actions::save_settings
 */
final class SettingsAdminTest extends NowCrmTestCase {

	public function set_up(): void {
		parent::set_up();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	public function tear_down(): void {
		$_GET     = array();
		$_POST    = array();
		$_REQUEST = array();
		wp_set_current_user( 0 );

		parent::tear_down();
	}

	public function test_email_tab_uses_configuration_set_and_links_own_diagnostics(): void {
		$html = $this->render_page( array( 'tab' => 'email' ) );

		$this->assertStringContainsString( 'name="ses_configuration_set"', $html );
		$this->assertStringContainsString( 'Configuration Set（選填）', $html );
		$this->assertStringContainsString( 'X-SES-CONFIGURATION-SET', $html );
		$this->assertStringContainsString( 'SES 可能拒絕寄送', $html );
		$this->assertStringContainsString( 'Capture 不寄出，也不會帶入', $html );
		$this->assertStringContainsString( 'name="ses_topic_arn"', $html );
		$this->assertStringContainsString( '查看寄送紀錄的診斷', $html );
		$this->assertStringContainsString( 'page=nowcrm-email&#038;tab=diagnostics', $html );
		$this->assertStringContainsString( '新郵件最大嘗試次數', $html );
		$this->assertStringContainsString( '交握結果不明', $html );

		$this->assertStringNotContainsString( 'name="ses_region"', $html );
		$this->assertStringNotContainsString( '>Region<', $html );
	}

	public function test_general_tab_distinguishes_no_choice_from_a_decline_and_scopes_doi(): void {
		$html = $this->render_page( array( 'tab' => 'general' ) );

		$this->assertStringContainsString( '沒有做出選擇，不是拒絕', $html );
		$this->assertStringContainsString( '勾選是明確同意，取消勾選是明確拒絕', $html );
		$this->assertStringContainsString( '雙重確認範圍', $html );
		$this->assertStringContainsString( '維持 transactional', $html );
	}

	public function test_valid_configuration_set_is_trimmed_and_saved(): void {
		Settings::update( array( 'ses_configuration_set' => 'old-set' ) );

		$args = $this->save_email_scope( array( 'ses_configuration_set' => '  new-set_1  ' ) );

		$this->assertSame( 'settings_saved', $args['nowcrm_notice'] ?? '' );
		$this->assertSame( 'email', $args['tab'] ?? '' );
		$this->assertSame( 'new-set_1', Settings::string( 'ses_configuration_set' ) );
	}

	public function test_invalid_configuration_set_is_reported_and_keeps_previous_values(): void {
		Settings::update(
			array(
				'ses_configuration_set' => 'kept-set',
				'email_from_name'       => 'Old Sender',
			)
		);

		$args = $this->save_email_scope(
			array(
				'ses_configuration_set' => 'bad set!',
				'email_from_name'       => 'New Sender',
			)
		);

		$this->assertSame( 'settings_invalid_ses_configuration_set', $args['nowcrm_notice'] ?? '' );
		$this->assertSame( 'email', $args['tab'] ?? '' );
		$this->assertSame( 'kept-set', Settings::string( 'ses_configuration_set' ) );
		$this->assertSame( 'Old Sender', Settings::string( 'email_from_name' ) );
	}

	public function test_unreadable_configuration_set_is_rejected_without_a_save(): void {
		Settings::update( array( 'ses_configuration_set' => 'kept-set' ) );

		$_POST    = array(
			'action'                => 'nowcrm_save_settings',
			'settings_scope'        => 'email',
			'ses_configuration_set' => array( 'nested' ),
			'_wpnonce'              => wp_create_nonce( 'nowcrm_save_settings' ),
		);
		$_REQUEST = $_POST;

		$args = $this->query_args( $this->capture_redirect( static fn() => \NowCRM\Admin\Actions::save_settings() ) );

		$this->assertSame( 'settings_invalid_ses_configuration_set', $args['nowcrm_notice'] ?? '' );
		$this->assertSame( 'kept-set', Settings::string( 'ses_configuration_set' ) );
	}

	public function test_configuration_set_longer_than_aws_limit_is_rejected(): void {
		Settings::update( array( 'ses_configuration_set' => 'kept-set' ) );

		$args = $this->save_email_scope( array( 'ses_configuration_set' => str_repeat( 'a', 65 ) ) );

		$this->assertSame( 'settings_invalid_ses_configuration_set', $args['nowcrm_notice'] ?? '' );
		$this->assertSame( 'kept-set', Settings::string( 'ses_configuration_set' ) );
	}

	/**
	 * POST the email scope and return the redirect query.
	 *
	 * @param array<string, mixed> $overrides Fields to post.
	 * @return array<string, string>
	 */
	private function save_email_scope( array $overrides = array() ): array {
		$_POST    = array_merge(
			array(
				'action'                => 'nowcrm_save_settings',
				'settings_scope'        => 'email',
				'ses_configuration_set' => '',
				'ses_topic_arn'         => 'arn:aws:sns:us-west-2:123456789012:nowcrm',
				'_wpnonce'              => wp_create_nonce( 'nowcrm_save_settings' ),
			),
			$overrides
		);
		$_REQUEST = $_POST;

		return $this->query_args( $this->capture_redirect( static fn() => \NowCRM\Admin\Actions::save_settings() ) );
	}

	/**
	 * Render the settings screen and return its HTML.
	 *
	 * @param array<string, mixed> $query Query arguments.
	 */
	private function render_page( array $query = array() ): string {
		$_GET     = array_merge( array( 'page' => Menu::SETTINGS_SLUG ), $query );
		$_REQUEST = $_GET;

		$die    = static function ( $message = '', $title = '', $args = array() ): void {
			throw new WPDieException( 'render_died' );
		};
		$filter = static fn() => $die;

		add_filter( 'wp_die_handler', $filter, 99 );
		ob_start();

		try {
			SettingsPage::render();
		} finally {
			$html = (string) ob_get_clean();
			remove_filter( 'wp_die_handler', $filter, 99 );
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
			throw new NowCrm_SettingsAdmin_Redirect_Captured( $captured );
		};

		add_filter( 'wp_redirect', $filter, 1 );

		try {
			$handler();
		} catch ( NowCrm_SettingsAdmin_Redirect_Captured $exception ) {
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
final class NowCrm_SettingsAdmin_Redirect_Captured extends \RuntimeException {}
