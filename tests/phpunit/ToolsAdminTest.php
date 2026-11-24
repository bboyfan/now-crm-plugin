<?php
/**
 * Tools screen: diagnostics default, retired migration entry point, Woo handoff.
 *
 * @package NowCRM
 */

use NowCRM\Admin\Menu;
use NowCRM\Admin\ToolsPage;
use NowCRM\Support\Capabilities;
use NowCRM\Woo\ProcessingHandoff;

/**
 * @covers \NowCRM\Admin\ToolsPage
 */
final class ToolsAdminTest extends NowCrmTestCase {

	public function test_migration_admin_post_route_is_not_registered(): void {
		$this->assertFalse( has_action( 'admin_post_nowcrm_tools_fluent_migrate' ) );
		$this->assertFalse( method_exists( ToolsPage::class, 'handle_migration' ) );

		do_action( 'admin_post_nowcrm_tools_fluent_migrate' );

		$this->assertSame( 0, $this->table_count( 'import_runs' ), 'The tools screen must not expose a migration mutation route.' );
	}

	public function test_default_tab_is_diagnostics(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$_GET = array( 'page' => Menu::TOOLS_SLUG );

		$html = $this->render();

		$this->assertStringContainsString( '檢查並修正資料表', $html );
		$this->assertMatchesRegularExpression( '/href="[^"]*page=nowcrm-tools"[^>]*aria-current="page"/', $html );
		$this->assertStringNotContainsString( '開始 dry-run', $html );
	}

	public function test_retired_migration_tab_falls_back_to_diagnostics(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$_GET = array(
			'page' => Menu::TOOLS_SLUG,
			'tab'  => 'migration',
		);

		$html = $this->render();

		$this->assertStringContainsString( '檢查並修正資料表', $html );
		$this->assertStringContainsString( 'FluentCRM 遷移指引', $html );
		$this->assertStringNotContainsString( 'nowcrm_tools_fluent_migrate', $html );
		$this->assertStringNotContainsString( '開始 dry-run', $html );
		$this->assertStringNotContainsString( '套用遷移', $html );
		$this->assertStringNotContainsString( 'tab=migration', $html );
	}

	public function test_diagnostics_shows_fluentcrm_cli_guide_without_mutation(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$_GET = array( 'page' => Menu::TOOLS_SLUG );

		$html = $this->render();

		$this->assertStringContainsString( 'id="' . ToolsPage::FLUENTCRM_ANCHOR . '"', $html );
		$this->assertStringContainsString( 'wp nowcrm import fluentcrm --dry-run', $html );
		$this->assertStringContainsString( 'wp nowcrm import fluentcrm --apply', $html );
		$this->assertStringContainsString( '--resume', $html );
		$this->assertStringContainsString( 'wp nowcrm import status', $html );
		$this->assertStringContainsString( 'CLI 不會強制先執行 dry-run', $html );
		$this->assertStringNotContainsString( 'nowcrm_tools_fluent_migrate', $html );
	}

	public function test_woo_handoff_tab_is_preserved(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$_GET = array(
			'page' => Menu::TOOLS_SLUG,
			'tab'  => 'woo',
		);

		$html = $this->render();

		$this->assertStringContainsString( 'nowcrm_toggle_woo_processing', $html );
		$this->assertStringContainsString( 'Woo 歷史缺口', $html );
		$this->assertStringNotContainsString( '檢查並修正資料表', $html );
	}

	public function test_handoff_distinguishes_active_plugin_from_importable_contact_tables(): void {
		global $wpdb;

		$plugin = 'fluent-crm/fluent-crm.php';
		$active = (array) get_option( 'active_plugins', array() );
		update_option( 'active_plugins', array_values( array_diff( $active, array( $plugin ) ) ) );

		try {
			foreach ( array( 'subscribers', 'contacts' ) as $suffix ) {
				$table = $wpdb->prefix . 'fc_' . $suffix;
				$wpdb->query( "CREATE TEMPORARY TABLE {$table} (email varchar(255) NOT NULL)" );

				try {
					$status = ( new ProcessingHandoff() )->status();
					$this->assertTrue( $status['fluentcrm_tables'], $suffix );
					$this->assertFalse( $status['fluentcrm_active'], $suffix );
				} finally {
					$wpdb->query( "DROP TEMPORARY TABLE IF EXISTS {$table}" );
				}
			}

			update_option( 'active_plugins', array_merge( $active, array( $plugin ) ) );
			$this->assertTrue( ( new ProcessingHandoff() )->status()['fluentcrm_active'] );
		} finally {
			update_option( 'active_plugins', $active );
		}
	}

	public function test_missing_woo_processing_email_hides_toggle_and_refuses_save(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		Capabilities::grant();

		$mailer = WC()->mailer();
		$emails = $mailer->emails;

		unset( $mailer->emails['WC_Email_Customer_Processing_Order'] );
		delete_option( ProcessingHandoff::OPTION );

		try {
			$_GET = array(
				'page' => Menu::TOOLS_SLUG,
				'tab'  => 'woo',
			);

			$html = $this->render();

			$this->assertStringNotContainsString( 'nowcrm_toggle_woo_processing', $html );
			$this->assertStringContainsString( '找不到 WooCommerce 原生「訂單處理中」郵件', $html );

			$_POST    = array( 'enabled' => '1' );
			$_REQUEST = array( '_wpnonce' => wp_create_nonce( 'nowcrm_toggle_woo_processing' ) );

			$location = $this->capture_redirect(
				static function (): void {
					( new ProcessingHandoff() )->toggle();
				}
			);

			$this->assertStringContainsString( 'nowcrm_notice=woo_processing_unavailable', $location );
			$this->assertFalse( get_option( ProcessingHandoff::OPTION ), 'A missing email must not save the toggle.' );
		} finally {
			$mailer->emails = $emails;
			$_POST          = array();
			$_REQUEST       = array();
		}
	}

	public function tear_down(): void {
		$_GET     = array();
		$_POST    = array();
		$_REQUEST = array();

		parent::tear_down();
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

	/**
	 * Render the tools screen.
	 */
	private function render(): string {
		ob_start();

		try {
			ToolsPage::render();
		} finally {
			$html = (string) ob_get_clean();
		}

		return $html;
	}
}
