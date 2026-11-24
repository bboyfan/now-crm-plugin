<?php
/**
 * Authorization, nonce and data-exposure guards.
 *
 * @package NowCRM
 */

use NowCRM\Admin\Actions;
use NowCRM\Admin\Menu;
use NowCRM\Contacts\CustomFieldDefinitions;
use NowCRM\Contacts\CustomFieldValues;
use NowCRM\Contacts\Status;
use NowCRM\Support\Capabilities;
use NowCRM\Support\Settings;

/**
 * Sentinel thrown by the redirect filter so a handler never reaches exit().
 */
final class NowCrm_Redirect_Captured extends \RuntimeException {}

/**
 * @covers \NowCRM\Admin\Actions
 * @covers \NowCRM\Admin\Menu
 */
final class SecurityTest extends NowCrmTestCase {

	/**
	 * Stored custom definitions captured before a test mutates them.
	 *
	 * @var mixed
	 */
	private $fields_backup = null;

	public function tear_down(): void {
		if ( null !== $this->fields_backup ) {
			if ( false === $this->fields_backup ) {
				delete_option( CustomFieldDefinitions::OPTION );
			} else {
				update_option( CustomFieldDefinitions::OPTION, $this->fields_backup );
			}

			$this->fields_backup = null;
		}

		$_POST    = array();
		$_REQUEST = array();
		wp_set_current_user( 0 );

		parent::tear_down();
	}

	public function test_administrator_receives_both_capabilities(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );

		$this->assertTrue( user_can( $admin, Capabilities::MANAGE_CONTACTS ) );
		$this->assertTrue( user_can( $admin, Capabilities::MANAGE_SETTINGS ) );
	}

	public function test_subscriber_cannot_create_a_contact(): void {
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		wp_set_current_user( $subscriber );
		$this->post(
			array(
				'_wpnonce' => wp_create_nonce( 'nowcrm_create_contact' ),
				'email'    => 'blocked@example.test',
			)
		);

		$this->expectException( 'WPDieException' );

		Actions::create_contact();
	}

	public function test_missing_nonce_is_rejected_for_an_administrator(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->post( array( 'email' => 'no-nonce@example.test' ) );

		$this->expectException( 'WPDieException' );

		Actions::create_contact();
	}

	public function test_invalid_nonce_is_rejected(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->post(
			array(
				'_wpnonce' => 'not-a-valid-nonce',
				'email'    => 'bad-nonce@example.test',
			)
		);

		$this->expectException( 'WPDieException' );

		Actions::create_contact();
	}

	public function test_administrator_with_nonce_creates_a_contact(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->post(
			array(
				'_wpnonce' => wp_create_nonce( 'nowcrm_create_contact' ),
				'email'    => 'allowed@example.test',
				'status'   => Status::TRANSACTIONAL,
			)
		);

		$location = $this->capture_redirect( static fn() => Actions::create_contact() );

		$this->assertStringContainsString( 'page=' . Menu::CONTACT_SLUG, $location );
		$this->assertNotNull( $this->contacts->find_by_email( 'allowed@example.test' ) );
		$this->assertSame( Status::TRANSACTIONAL, $this->contacts->find_by_email( 'allowed@example.test' )['status'] );
	}

	public function test_create_contact_persists_terms_and_custom_values_before_created_hook(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$list = (int) $this->terms->create( 'list', 'VIP list' );
		$tag  = (int) $this->terms->create( 'tag', 'Early' );

		$this->fields_backup = get_option( CustomFieldDefinitions::OPTION, false );

		$this->assertNotWPError(
			CustomFieldDefinitions::save(
				array(
					array( 'label' => 'Nickname', 'slug' => 'nickname', 'type' => 'text' ),
				)
			)
		);

		$seen = array();

		add_action(
			'nowcrm_contact_created',
			function ( $id, $context ) use ( &$seen ) {
				$seen[] = array(
					'id'     => (int) $id,
					'status' => (string) $context['status'],
					'terms'  => count( $this->terms->for_contact( (int) $id ) ),
					'custom' => ( new CustomFieldValues() )->for_contact( (int) $id ),
				);
			},
			10,
			2
		);

		$this->post(
			array(
				'_wpnonce'          => wp_create_nonce( 'nowcrm_create_contact' ),
				'email'             => 'drawer@example.test',
				'prefix'            => 'Ms',
				'first_name'        => 'Ada',
				'last_name'         => 'Lovelace',
				'phone'             => '0912345678',
				'date_of_birth'     => '1990-12-31',
				'address_line_1'    => '1 Analytical Way',
				'city'              => 'Taipei',
				'country'           => 'TW',
				'status'            => Status::PENDING,
				'term_ids'          => array( $list, $tag ),
				'nowcrm_cf_present' => array( 'nickname' ),
				'nowcrm_cf'         => array( 'nickname' => 'Ada' ),
				'create_mode'       => 'another',
			)
		);

		$location = $this->capture_redirect( static fn() => Actions::create_contact() );

		$this->assertStringContainsString( 'page=' . Menu::SLUG, $location );
		$this->assertStringContainsString( 'nowcrm_drawer=1', $location );

		$contact = $this->contacts->find_by_email( 'drawer@example.test' );

		$this->assertNotNull( $contact );
		$this->assertSame( Status::PENDING, $contact['status'] );
		$this->assertSame( 'Ada', $contact['first_name'] );
		$this->assertSame( 'Ms', $contact['prefix'] );
		$this->assertSame( '1 Analytical Way', $contact['address_line_1'] );
		$this->assertSame( 'TW', $contact['country'] );
		$this->assertSame( 'Ada', CustomFieldValues::find( ( new CustomFieldValues() )->for_contact( (int) $contact['id'] ), 'nickname' ) );
		$this->assertCount( 2, $this->terms->for_contact( (int) $contact['id'] ) );

		$this->assertCount( 1, $seen );
		$this->assertSame( Status::PENDING, $seen[0]['status'] );
		$this->assertSame( 2, $seen[0]['terms'] );
		$this->assertSame( 'Ada', CustomFieldValues::find( $seen[0]['custom'], 'nickname' ) );
	}

	public function test_invalid_status_keeps_the_drawer_draft_and_writes_nothing(): void {
		$user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user );

		$this->post(
			array(
				'_wpnonce'   => wp_create_nonce( 'nowcrm_create_contact' ),
				'email'      => 'invalid-status@example.test',
				'first_name' => 'Kept',
				'status'     => 'vip',
			)
		);

		$location = $this->capture_redirect( static fn() => Actions::create_contact() );

		$this->assertStringContainsString( 'nowcrm_notice=invalid_status', $location );
		$this->assertStringContainsString( 'nowcrm_drawer=1', $location );
		$this->assertNull( $this->contacts->find_by_email( 'invalid-status@example.test' ) );

		$draft = get_transient( 'nowcrm_contact_draft_' . $user );

		$this->assertIsArray( $draft );
		$this->assertSame( 'Kept', $draft['first_name'] );
		$this->assertSame( 'status', $draft['focus'] );
	}

	public function test_duplicate_creation_keeps_the_existing_row_untouched(): void {
		$user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user );

		$existing = $this->make_contact(
			array(
				'email'      => 'duplicate@example.test',
				'first_name' => 'Original',
			)
		);

		$this->post(
			array(
				'_wpnonce'   => wp_create_nonce( 'nowcrm_create_contact' ),
				'email'      => 'DUPLICATE@example.test',
				'first_name' => 'Replacement',
				'status'     => Status::SUBSCRIBED,
			)
		);

		$location = $this->capture_redirect( static fn() => Actions::create_contact() );

		$this->assertStringContainsString( 'nowcrm_notice=duplicate_email', $location );

		$contact = $this->contacts->get( $existing );

		$this->assertSame( 'Original', $contact['first_name'] );
		$this->assertSame( 1, $this->table_count( 'contacts', "email_normalized = 'duplicate@example.test'" ) );
	}

	public function test_contact_manager_cannot_change_settings(): void {
		$role = add_role( 'crm_contacts_only', 'CRM Contacts Only', array( 'read' => true ) );

		$this->assertNotNull( $role );
		$role->add_cap( Capabilities::MANAGE_CONTACTS );

		$user = self::factory()->user->create( array( 'role' => 'crm_contacts_only' ) );

		wp_set_current_user( $user );
		$this->post(
			array(
				'_wpnonce'         => wp_create_nonce( 'nowcrm_save_settings' ),
				'settings_scope'   => 'general',
				'woo_sync_enabled' => '1',
			)
		);

		try {
			Actions::save_settings();
			$this->fail( 'A contact manager must not be able to change settings.' );
		} catch ( WPDieException $e ) {
			$this->addToAssertionCount( 1 );
		} finally {
			remove_role( 'crm_contacts_only' );
		}

		$this->assertFalse( Settings::bool( 'woo_sync_enabled' ), 'Settings must not change without the capability.' );
	}

	public function test_administrator_with_nonce_changes_settings(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->post(
			array(
				'_wpnonce'                         => wp_create_nonce( 'nowcrm_save_settings' ),
				'settings_scope'                   => 'general',
				'woo_sync_enabled'                 => '1',
				'auto_subscribe_new_woo_contacts'   => '1',
				'show_checkout_subscription_checkbox' => '1',
				'email_sending_enabled'            => '1',
			)
		);

		$location = $this->capture_redirect( static fn() => Actions::save_settings() );

		$this->assertStringContainsString( 'page=' . Menu::SETTINGS_SLUG, $location );
		$this->assertTrue( Settings::bool( 'woo_sync_enabled' ) );
		$this->assertTrue( Settings::bool( 'show_checkout_subscription_checkbox' ) );
		$this->assertFalse( Settings::bool( 'email_sending_enabled' ) );
		$this->assertTrue( Settings::bool( 'auto_subscribe_new_woo_contacts' ) );
	}

	public function test_markup_is_stripped_from_contact_fields(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->post(
			array(
				'_wpnonce'   => wp_create_nonce( 'nowcrm_create_contact' ),
				'email'      => 'xss@example.test',
				'first_name' => '<script>alert(1)</script>Mei',
			)
		);

		$this->capture_redirect( static fn() => Actions::create_contact() );

		$contact = $this->contacts->find_by_email( 'xss@example.test' );

		$this->assertStringNotContainsString( '<script', $contact['first_name'] );
	}

	public function test_public_routes_are_allowlisted_and_do_not_expose_contacts(): void {
		$routes = array();

		foreach ( array_keys( rest_get_server()->get_routes() ) as $route ) {
			$route = (string) $route;

			if ( str_starts_with( $route, '/nowcrm/v1' ) ) {
				$routes[] = $route;
			}

			$this->assertStringNotContainsString( 'contact', $route, 'No public CRM data route is allowed.' );
		}

		sort( $routes );

		$this->assertSame(
			array(
				'/nowcrm/v1',
				'/nowcrm/v1/campaigns/(?P<id>[1-9]\d*)',
				'/nowcrm/v1/campaigns/(?P<id>[1-9]\d*)/content',
				'/nowcrm/v1/campaigns/(?P<id>[1-9]\d*)/delivery',
				'/nowcrm/v1/campaigns/(?P<id>[1-9]\d*)/duplicate',
				'/nowcrm/v1/campaigns/(?P<id>[1-9]\d*)/preview',
				'/nowcrm/v1/campaigns/(?P<id>[1-9]\d*)/recipient-options',
				'/nowcrm/v1/campaigns/(?P<id>[1-9]\d*)/recipients',
				'/nowcrm/v1/campaigns/(?P<id>[1-9]\d*)/recurring',
				'/nowcrm/v1/campaigns/(?P<id>[1-9]\d*)/review',
				'/nowcrm/v1/campaigns/(?P<id>[1-9]\d*)/test',
				'/nowcrm/v1/cart/track',
				'/nowcrm/v1/click/(?P<token>[A-Za-z0-9_\-\.]+)',
				'/nowcrm/v1/lists',
				'/nowcrm/v1/lists/(?P<id>[1-9]\d*)',
				'/nowcrm/v1/lists/bulk',
				'/nowcrm/v1/lists/bulk-delete',
				'/nowcrm/v1/lists/by-slug/(?P<slug>[^/]+)',
				'/nowcrm/v1/open/(?P<token>[A-Za-z0-9_\-\.]+)',
				'/nowcrm/v1/ses/sns',
				'/nowcrm/v1/tags',
				'/nowcrm/v1/tags/(?P<id>[1-9]\d*)',
				'/nowcrm/v1/tags/bulk',
				'/nowcrm/v1/tags/bulk-delete',
				'/nowcrm/v1/tags/by-slug/(?P<slug>[^/]+)',
				'/nowcrm/v1/templates/(?P<id>[1-9]\d*)/content',
			),
			$routes
		);
	}

	public function test_no_admin_ajax_endpoint_is_registered(): void {
		global $wp_filter;

		foreach ( array_keys( (array) $wp_filter ) as $hook ) {
			$hook = (string) $hook;

			if ( ! str_contains( $hook, 'nowcrm' ) ) {
				continue;
			}

			$this->assertStringStartsNotWith( 'wp_ajax_', $hook, 'NOW CRM must not expose admin-ajax endpoints.' );
			$this->assertStringStartsNotWith( 'wp_ajax_nopriv_', $hook, 'NOW CRM must not expose unauthenticated admin-ajax endpoints.' );
		}
	}

	/**
	 * Capture the wp_safe_redirect() target of a handler.
	 *
	 * @param callable $handler Handler under test.
	 */
	private function capture_redirect( callable $handler ): string {
		$captured = '';
		$filter   = static function ( $location ) use ( &$captured ): void {
			$captured = (string) $location;
			throw new NowCrm_Redirect_Captured( $captured );
		};

		add_filter( 'wp_redirect', $filter, 1 );

		try {
			$handler();
		} catch ( NowCrm_Redirect_Captured $e ) {
			$captured = $e->getMessage();
		} finally {
			remove_filter( 'wp_redirect', $filter, 1 );
		}

		$this->assertNotSame( '', $captured, 'The handler did not redirect.' );

		return $captured;
	}

	/**
	 * Simulate a POST request.
	 *
	 * @param array<string, mixed> $fields Request fields.
	 */
	private function post( array $fields ): void {
		$_POST    = $fields;
		$_REQUEST = $fields;
	}
}
