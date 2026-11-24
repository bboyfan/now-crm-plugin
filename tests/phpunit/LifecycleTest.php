<?php
/**
 * Plugin lifecycle safety: activation, reactivation, deactivation.
 *
 * @package NowCRM
 */

use NowCRM\Support\Installer;

/**
 * @covers \NowCRM\Support\Installer
 */
final class LifecycleTest extends NowCrmTestCase {

	public function test_deactivation_keeps_all_data(): void {
		$contact_id = $this->make_contact( array( 'email' => 'keep-after-deactivate@example.test' ) );
		$list       = $this->terms->create( 'list', 'Keeper' );

		$this->terms->attach( $contact_id, $list );

		Installer::deactivate();

		$this->assertNotNull( $this->contacts->get( $contact_id ) );
		$this->assertNotNull( $this->terms->get( $list ) );
		$this->assertSame( 1, $this->table_count( 'contact_terms' ) );
	}

	public function test_reactivation_is_idempotent_and_preserves_rows(): void {
		$contact_id = $this->make_contact( array( 'email' => 'reactivate@example.test' ) );
		$settings   = array_merge( \NowCRM\Support\Settings::DEFAULTS, array( 'woo_sync_enabled' => true ) );

		\NowCRM\Support\Settings::update( array( 'woo_sync_enabled' => true ) );

		Installer::activate();
		Installer::activate();

		$this->assertSame( 1, $this->table_count( 'contacts' ) );
		$this->assertNotNull( $this->contacts->get( $contact_id ) );
		$this->assertTrue(
			\NowCRM\Support\Settings::bool( 'woo_sync_enabled' ),
			'Reactivation must not reset operator settings.'
		);
		$this->assertSame( $settings, \NowCRM\Support\Settings::all() );
	}

	public function test_activation_grants_capabilities_to_administrators(): void {
		$role = get_role( 'administrator' );

		$this->assertNotNull( $role );
		$this->assertTrue( $role->has_cap( 'nowcrm_manage_contacts' ) );
		$this->assertTrue( $role->has_cap( 'nowcrm_manage_settings' ) );
	}

	public function test_activation_creates_no_contacts_and_no_cron(): void {
		$this->assertSame( 0, $this->table_count( 'contacts' ) );
		$this->assertSame( 0, $this->table_count( 'order_links' ) );

		$crons = _get_cron_array();

		foreach ( (array) $crons as $hooks ) {
			foreach ( array_keys( (array) $hooks ) as $hook ) {
				$this->assertStringNotContainsString( 'nowcrm', (string) $hook );
			}
		}
	}
}
