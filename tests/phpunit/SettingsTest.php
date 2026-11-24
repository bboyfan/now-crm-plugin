<?php
/**
 * Settings tests.
 *
 * @package NowCRM
 */

use NowCRM\Admin\Actions;
use NowCRM\Admin\Menu;
use NowCRM\Support\Settings;

/**
 * @covers \NowCRM\Support\Settings
 */
final class SettingsTest extends NowCrmTestCase {

	/**
	 * The defaults are part of the product contract: nothing sends email and no
	 * automatic sync runs until an operator turns it on.
	 */
	public function test_defaults_match_the_contract(): void {
		$all = Settings::all();

		$this->assertSame( Settings::DEFAULTS, $all );
		$this->assertFalse( $all['woo_sync_enabled'] );
		$this->assertFalse( $all['auto_subscribe_new_woo_contacts'] );
		$this->assertFalse( $all['show_checkout_subscription_checkbox'] );
		$this->assertFalse( $all['email_sending_enabled'] );
		$this->assertFalse( $all['abandoned_cart_enabled'] );
		$this->assertFalse( $all['campaign_tracking_enabled'] );
		$this->assertSame( 'wp_mail', $all['email_transport'] );
		$this->assertSame( 15, $all['email_throttle_per_second'] );
		$this->assertSame( 20, $all['cart_idle_minutes'] );
		$this->assertSame( 1, $all['cart_max_reminders'] );
	}

	public function test_typed_settings_are_clamped_and_sanitized(): void {
		Settings::update(
			array(
				'email_batch_size'          => 9999,
				'email_throttle_per_second' => 0,
				'cart_idle_minutes'         => -5,
				'email_transport'           => 'nonsense',
				'email_from_address'        => 'not-an-email',
				'ses_topic_arn'             => '<b>arn</b>',
			)
		);

		$all = Settings::all();

		$this->assertSame( 500, $all['email_batch_size'] );
		$this->assertSame( 1, $all['email_throttle_per_second'] );
		$this->assertSame( 1, $all['cart_idle_minutes'] );
		$this->assertSame( 'wp_mail', $all['email_transport'] );
		$this->assertSame( '', $all['email_from_address'] );
		$this->assertSame( 'arn', $all['ses_topic_arn'] );
	}

	public function test_update_casts_booleans_and_ignores_unknown_keys(): void {
		$result = Settings::update(
			array(
				'woo_sync_enabled' => '1',
				'email_sending_enabled' => 0,
				'not_a_real_key'        => true,
			)
		);

		$this->assertTrue( $result['woo_sync_enabled'] );
		$this->assertFalse( $result['email_sending_enabled'] );
		$this->assertArrayNotHasKey( 'not_a_real_key', $result );
		$this->assertTrue( Settings::bool( 'woo_sync_enabled' ) );
	}

	public function test_stored_garbage_falls_back_to_defaults(): void {
		update_option( Settings::OPTION, 'not-an-array' );

		$this->assertSame( Settings::DEFAULTS, Settings::all() );

		update_option( Settings::OPTION, array( 'woo_sync_enabled' => 'yes' ) );

		$this->assertTrue( Settings::bool( 'woo_sync_enabled' ) );
		$this->assertFalse( Settings::bool( 'auto_subscribe_new_woo_contacts' ) );
		$this->assertFalse( Settings::bool( 'campaign_tracking_enabled' ) );
	}

	public function test_reset_restores_defaults(): void {
		Settings::update( array( 'woo_sync_enabled' => true ) );
		Settings::reset();

		$this->assertSame( Settings::DEFAULTS, Settings::all() );
	}

	public function test_enabling_sending_does_not_hijack_wp_mail(): void {
		Settings::update( array( 'email_sending_enabled' => true ) );

		$this->assertFalse( has_filter( 'pre_wp_mail' ), 'NOW CRM must not replace the wp_mail() transport.' );
	}

	public function test_email_scope_does_not_clear_general_checkboxes(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		Settings::update( array( 'woo_sync_enabled' => true ) );

		$this->post_settings(
			array(
				'settings_scope'       => 'email',
				'email_sending_enabled' => '1',
				'email_transport'      => 'capture',
			)
		);

		$location = $this->capture_redirect( static fn() => Actions::save_settings() );

		$this->assertStringContainsString( 'page=' . Menu::SETTINGS_SLUG, $location );
		$this->assertTrue( Settings::bool( 'woo_sync_enabled' ), 'An email-scope form must not clear a general checkbox.' );
		$this->assertTrue( Settings::bool( 'email_sending_enabled' ) );
		$this->assertSame( 'capture', Settings::string( 'email_transport' ) );
	}

	public function test_cart_scope_keeps_the_legacy_template_id_and_returns_to_settings_tab(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		Settings::update( array( 'cart_reminder_template_id' => 77 ) );

		$this->post_settings(
			array(
				'settings_scope'        => 'cart',
				'redirect_page'         => Menu::CART_SLUG,
				'abandoned_cart_enabled' => '1',
				'cart_idle_minutes'     => '45',
			)
		);

		$location = $this->capture_redirect( static fn() => Actions::save_settings() );

		$this->assertStringContainsString( 'page=' . Menu::CART_SLUG, $location );
		$this->assertStringContainsString( 'tab=settings', $location );
		$this->assertSame( 77, Settings::int( 'cart_reminder_template_id' ), 'The legacy migration source is not writable from the cart settings form.' );
		$this->assertSame( 45, Settings::int( 'cart_idle_minutes' ) );
		$this->assertTrue( Settings::bool( 'abandoned_cart_enabled' ) );
	}

	/**
	 * Simulate a settings POST request.
	 *
	 * @param array<string, mixed> $fields Request fields.
	 */
	private function post_settings( array $fields ): void {
		$payload = array_merge(
			array(
				'_wpnonce' => wp_create_nonce( 'nowcrm_save_settings' ),
				'action'   => 'nowcrm_save_settings',
			),
			$fields
		);

		$_POST    = $payload;
		$_REQUEST = $payload;
	}

	/**
	 * Capture the redirect issued by a handler.
	 */
	private function capture_redirect( callable $handler ): string {
		$captured = '';
		$filter   = static function ( $location ) use ( &$captured ): void {
			$captured = (string) $location;
			throw new NowCrm_Settings_Redirect_Captured( $captured );
		};

		add_filter( 'wp_redirect', $filter, 1 );

		try {
			$handler();
		} catch ( NowCrm_Settings_Redirect_Captured $e ) {
			$captured = $e->getMessage();
		} finally {
			remove_filter( 'wp_redirect', $filter, 1 );
		}

		$this->assertNotSame( '', $captured, 'The handler did not redirect.' );

		return $captured;
	}
}

/**
 * Internal signal for a captured redirect.
 */
final class NowCrm_Settings_Redirect_Captured extends \RuntimeException {}
