<?php
/**
 * Custom contact field definitions and values.
 *
 * @package NowCRM
 */

use NowCRM\Admin\Actions;
use NowCRM\Admin\CustomFields;
use NowCRM\Contacts\CustomFieldDefinitions;
use NowCRM\Contacts\CustomFieldValues;
use NowCRM\Support\Schema;

/**
 * Covers the definition store, the settings save path, and the per-contact
 * value store.
 */
final class CustomFieldsTest extends NowCrmTestCase {

	public function set_up(): void {
		parent::set_up();

		delete_option( CustomFieldDefinitions::OPTION );
		delete_option( CustomFieldDefinitions::GROUPS_OPTION );
	}

	/**
	 * Definition shapes used by the value tests.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function definitions(): array {
		return array(
			array(
				'label' => 'Nickname',
				'slug'  => 'nickname',
				'type'  => 'text',
			),
			array(
				'label' => 'Notes',
				'slug'  => 'notes',
				'type'  => 'textarea',
			),
			array(
				'label' => 'Score',
				'slug'  => 'score',
				'type'  => 'number',
			),
			array(
				'label' => 'Tier',
				'slug'  => 'tier',
				'type'  => 'select-one',
				'options' => array( 'Bronze', 'Silver' ),
			),
			array(
				'label' => 'Interests',
				'slug'  => 'interests',
				'type'  => 'select-multi',
				'options' => array( 'News', 'Events', 'Offers' ),
			),
			array(
				'label' => 'Plan',
				'slug'  => 'plan',
				'type'  => 'radio',
				'options' => array( 'Monthly', 'Yearly' ),
			),
			array(
				'label' => 'Channels',
				'slug'  => 'channels',
				'type'  => 'checkbox',
				'options' => array( 'Email', 'SMS' ),
			),
			array(
				'label' => 'Birthday',
				'slug'  => 'birthday',
				'type'  => 'date',
			),
			array(
				'label' => 'Last call',
				'slug'  => 'last_call',
				'type'  => 'date_time',
			),
		);
	}

	private function save_definitions(): void {
		$this->assertNotWPError( CustomFieldDefinitions::save( $this->definitions() ) );
	}

	public function test_every_reference_kind_is_supported(): void {
		$kinds = array_keys( CustomFieldDefinitions::kinds() );

		$this->assertSame(
			array( 'text', 'textarea', 'number', 'select-one', 'select-multi', 'radio', 'checkbox', 'date', 'date_time' ),
			$kinds
		);

		foreach ( array( 'select-one', 'select-multi', 'radio', 'checkbox' ) as $choice ) {
			$this->assertTrue( CustomFieldDefinitions::needs_options( $choice ) );
		}

		$this->assertFalse( CustomFieldDefinitions::needs_options( 'text' ) );
		$this->assertTrue( CustomFieldDefinitions::is_multi( 'checkbox' ) );
		$this->assertFalse( CustomFieldDefinitions::is_multi( 'radio' ) );
	}

	public function test_save_keeps_order_and_generates_stable_keys(): void {
		$saved = CustomFieldDefinitions::save(
			array(
				array( 'label' => 'Company size', 'type' => 'text' ),
				array( 'label' => 'Email', 'type' => 'text' ),
				array( 'label' => '公司規模', 'type' => 'text' ),
				array( 'label' => 'Tags', 'type' => 'checkbox', 'options' => array( 'A', 'B', 'A' ) ),
			)
		);

		$this->assertNotWPError( $saved );
		$this->assertSame( array( 'company_size', 'cf_email', 'custom_field', 'tags' ), CustomFieldDefinitions::keys() );
		$this->assertSame( array( 'A', 'B' ), $saved[3]['options'] );

		// The key stays the same when the submitted case changes.
		$again = CustomFieldDefinitions::save(
			array(
				array( 'label' => 'Company size', 'slug' => 'COMPANY_SIZE', 'type' => 'text' ),
			)
		);

		$this->assertNotWPError( $again );
		$this->assertSame( 'company_size', $again[0]['slug'] );
		$this->assertSame( 'company_size', CustomFieldDefinitions::get( 'Company_size' )['slug'] );
	}

	public function test_invalid_definition_rejects_the_whole_patch(): void {
		$this->save_definitions();
		$before = CustomFieldDefinitions::all();

		$bad = CustomFieldDefinitions::save(
			array(
				array( 'label' => 'Fine', 'slug' => 'fine', 'type' => 'text' ),
				array( 'label' => '', 'slug' => 'nameless', 'type' => 'text' ),
			)
		);

		$this->assertWPError( $bad );
		$this->assertSame( $before, CustomFieldDefinitions::all() );
		$this->assertNull( CustomFieldDefinitions::get( 'fine' ) );

		$this->assertWPError(
			CustomFieldDefinitions::save( array( array( 'label' => 'Wrong', 'type' => 'unknown' ) ) )
		);
		$this->assertWPError(
			CustomFieldDefinitions::save( array( array( 'label' => 'No options', 'type' => 'radio' ) ) )
		);
		$this->assertWPError(
			CustomFieldDefinitions::save(
				array(
					array( 'label' => 'One', 'slug' => 'one', 'type' => 'text' ),
					array( 'label' => 'Two', 'slug' => 'ONE', 'type' => 'text' ),
				)
			)
		);
	}

	public function test_groups_are_named_and_rename_keeps_field_keys(): void {
		$saved = CustomFieldDefinitions::save(
			array(
				array( 'label' => 'Account', 'type' => 'text', 'group' => 'VIP Customers' ),
			)
		);

		$this->assertNotWPError( $saved );
		$this->assertSame( 'vip-customers', $saved[0]['group'] );

		$groups = CustomFieldDefinitions::groups();
		$this->assertSame( 'vip-customers', $groups[0]['slug'] );
		$this->assertSame( 'VIP Customers', $groups[0]['title'] );

		$renamed = CustomFieldDefinitions::rename_group( 'vip-customers', '重要客戶' );
		$this->assertNotWPError( $renamed );
		$this->assertSame( '重要客戶', CustomFieldDefinitions::groups()[0]['title'] );
		$this->assertSame( 'account', CustomFieldDefinitions::keys()[0] );
		$this->assertWPError( CustomFieldDefinitions::rename_group( 'missing', 'Title' ) );
	}

	public function test_values_round_trip_for_every_kind(): void {
		$this->save_definitions();
		$contact = $this->make_contact();
		$values  = new CustomFieldValues();

		$saved = $values->save(
			$contact,
			array(
				'nickname'   => '  Ada  ',
				'notes'      => '<p>Hello</p><script>alert(1)</script>',
				'score'      => '1,234.5',
				'tier'       => 'Silver',
				'interests'  => 'News, Events',
				'plan'       => 'Yearly',
				'channels'   => array( 'Email', ' SMS ', '' ),
				'birthday'   => '2026-02-28',
				'last_call'  => '2026-02-28T09:30',
			)
		);

		$this->assertNotWPError( $saved );

		$stored = $values->for_contact( $contact );

		$this->assertSame( 'Ada', $stored['nickname'] );
		$this->assertStringStartsWith( '<p>Hello</p>', $stored['notes'] );
		$this->assertStringNotContainsString( '<script', $stored['notes'] );
		$this->assertSame( 1234.5, $stored['score'] );
		$this->assertSame( 'Silver', $stored['tier'] );
		$this->assertSame( array( 'News', 'Events' ), $stored['interests'] );
		$this->assertSame( 'Yearly', $stored['plan'] );
		$this->assertSame( array( 'Email', 'SMS' ), $stored['channels'] );
		$this->assertSame( '2026-02-28', $stored['birthday'] );
		$this->assertSame( '2026-02-28 09:30:00', $stored['last_call'] );
		$this->assertSame( 'News, Events', $values->for_csv( $contact )['interests'] );

		// A partial patch leaves untouched fields alone.
		$this->assertNotWPError( $values->save( $contact, array( 'nickname' => 'Grace' ) ) );
		$this->assertSame( 'Grace', $values->for_contact( $contact )['nickname'] );
		$this->assertSame( '2026-02-28', $values->for_contact( $contact )['birthday'] );
	}

	public function test_invalid_value_blocks_the_whole_patch(): void {
		$this->save_definitions();
		$contact = $this->make_contact();
		$values  = new CustomFieldValues();

		$this->assertNotWPError( $values->save( $contact, array( 'nickname' => 'Ada', 'score' => '10' ) ) );

		$result = $values->save(
			$contact,
			array(
				'nickname' => 'Changed',
				'score'    => 'not-a-number',
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'Ada', $values->for_contact( $contact )['nickname'] );
		$this->assertSame( 10, $values->for_contact( $contact )['score'] );

		$unknown = $values->save( $contact, array( 'no_such_field' => 'x' ) );
		$this->assertWPError( $unknown );
		$this->assertSame( 'nowcrm_invalid_custom_values', $unknown->get_error_code() );

		$this->assertWPError( $values->save( $contact, array( 'birthday' => '2026-02-30' ) ) );
		$this->assertWPError( $values->save( $contact, array( 'nickname' => array( 'one' ) ) ) );
	}

	public function test_validate_accepts_values_without_an_existing_contact(): void {
		$this->save_definitions();

		$valid = CustomFieldValues::validate( array( 'nickname' => 'Ada', 'score' => '10' ) );

		$this->assertNotWPError( $valid );
		$this->assertSame( 'Ada', $valid['values']['nickname'] );
		$this->assertSame( 10, $valid['values']['score'] );

		$invalid = CustomFieldValues::validate( array( 'score' => 'not-a-number' ) );

		$this->assertWPError( $invalid );
		$this->assertSame( 'nowcrm_invalid_custom_values', $invalid->get_error_code() );

		$this->assertWPError( CustomFieldValues::validate( array( 'no_such_field' => 'x' ) ) );
	}

	public function test_explicit_clear_and_replace_missing(): void {
		global $wpdb;

		$this->save_definitions();
		$contact = $this->make_contact();
		$values  = new CustomFieldValues();

		$this->assertNotWPError(
			$values->save(
				$contact,
				array(
					'nickname'  => 'Ada',
					'interests' => array( 'News', 'Events' ),
				)
			)
		);

		// An empty list clears the row, not stores an empty array.
		$this->assertNotWPError( $values->save( $contact, array( 'interests' => '' ) ) );
		$this->assertArrayNotHasKey( 'interests', $values->for_contact( $contact ) );

		$table = Schema::table( CustomFieldValues::TABLE );
		$this->assertSame(
			1,
			(int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE contact_id = %d", $contact ) )
		);

		$this->assertNotWPError( $values->save( $contact, array( 'tier' => 'Bronze' ), true ) );
		$this->assertSame( array( 'tier' => 'Bronze' ), $values->for_contact( $contact ) );

		$values->delete_for_contact( $contact );
		$this->assertSame( array(), $values->for_contact( $contact ) );
	}

	public function test_form_markers_allow_clearing_multiselect(): void {
		$this->save_definitions();
		$contact = $this->make_contact();
		$values  = new CustomFieldValues();

		$this->assertNotWPError(
			$values->save( $contact, array( 'nickname' => 'Ada', 'interests' => array( 'News' ) ) )
		);

		$post = array(
			CustomFieldValues::FORM_MARKER => array( 'interests' ),
			CustomFieldValues::FORM_FIELD  => array( 'interests' => array() ),
		);

		$this->assertSame( array( 'interests' => array() ), CustomFieldValues::parse_request( $post ) );
		$this->assertSame( 'nowcrm_cf[interests][]', CustomFieldValues::form_name( 'interests', true ) );

		$this->assertNotWPError( $values->save( $contact, CustomFieldValues::parse_request( $post ) ) );
		$this->assertArrayNotHasKey( 'interests', $values->for_contact( $contact ) );
		$this->assertSame( 'Ada', $values->for_contact( $contact )['nickname'] );
	}

	public function test_settings_save_is_atomic_and_drops_unreferenced_groups(): void {
		$this->assertTrue(
			CustomFields::save_settings(
				array(
					'fields' => array(
						array(
							'label' => 'Account name',
							'slug'  => 'account_name',
							'type'  => 'text',
							'group' => 'vip',
							'order' => 0,
						),
					),
					'groups' => array(
						array(
							'slug'  => 'vip',
							'title' => 'VIP',
							'order' => 0,
						),
						array(
							'slug'  => 'unused',
							'title' => 'Unused',
							'order' => 1,
						),
					),
				)
			)
		);

		$this->assertSame( array( 'account_name' ), CustomFieldDefinitions::keys() );
		$this->assertSame( 'vip', CustomFieldDefinitions::all()[0]['group'] );
		$this->assertSame( array( 'vip' ), array_column( CustomFieldDefinitions::groups(), 'slug' ) );
		$this->assertSame( 'VIP', CustomFieldDefinitions::groups()[0]['title'] );

		$before_fields = CustomFieldDefinitions::all();
		$before_groups = CustomFieldDefinitions::groups();

		$failed = CustomFields::save_settings(
			array(
				'fields' => array(
					array(
						'label' => 'Broken',
						'slug'  => 'broken',
						'type'  => 'unknown',
						'order' => 0,
					),
				),
				'groups' => array(
					array(
						'slug'  => 'vip',
						'title' => 'VIP',
						'order' => 0,
					),
					array(
						'slug'  => 'unused',
						'title' => 'Unused',
						'order' => 1,
					),
				),
			)
		);

		$this->assertWPError( $failed );
		$this->assertSame( $before_fields, CustomFieldDefinitions::all() );
		$this->assertSame( $before_groups, CustomFieldDefinitions::groups() );
		$this->assertSame( 'VIP', CustomFieldDefinitions::groups()[0]['title'] );
	}

	public function test_settings_edit_keeps_field_keys_and_stored_values(): void {
		$this->save_definitions();
		$contact = $this->make_contact();
		$values  = new CustomFieldValues();

		$this->assertNotWPError( $values->save( $contact, array( 'nickname' => 'Ada' ) ) );

		$rows = array();

		foreach ( CustomFieldDefinitions::all() as $index => $field ) {
			$rows[] = array(
				'slug'    => $field['slug'],
				'label'   => 'nickname' === $field['slug'] ? 'Public nickname' : $field['label'],
				'type'    => $field['type'],
				'options' => implode( "\n", $field['options'] ),
				'group'   => '',
				'order'   => $index,
			);
		}

		$this->assertTrue( CustomFields::save_settings( array( 'fields' => $rows ) ) );

		$this->assertCount( count( $this->definitions() ), CustomFieldDefinitions::all() );
		$this->assertSame( array_column( $this->definitions(), 'slug' ), CustomFieldDefinitions::keys() );
		$this->assertSame( 'Public nickname', CustomFieldDefinitions::get( 'nickname' )['label'] );
		$this->assertSame( 'Ada', $values->for_contact( $contact )['nickname'] );
	}

	public function test_contact_delete_removes_field_values(): void {
		$this->save_definitions();
		$contact = $this->make_contact();
		$values  = new CustomFieldValues();

		$this->assertNotWPError( $values->save( $contact, array( 'nickname' => 'Ada' ) ) );
		$this->assertSame( 1, $this->table_count( 'contact_field_values', 'contact_id = ' . (int) $contact ) );

		$this->assertTrue( $this->contacts->delete( $contact ) );
		$this->assertSame( 0, $this->table_count( 'contact_field_values', 'contact_id = ' . (int) $contact ) );
	}

	public function test_contact_form_handler_saves_profile_and_custom_values(): void {
		$this->save_definitions();
		$contact = $this->make_contact( array( 'first_name' => 'Before' ) );
		$row     = $this->contacts->get( $contact );

		$result = Actions::persist_contact(
			$contact,
			array(
				'email'      => (string) $row['email'],
				'first_name' => 'After',
				'last_name'  => 'Buyer',
				'phone'      => '0912345678',
				CustomFieldValues::FORM_MARKER => array( 'nickname', 'interests', 'birthday' ),
				CustomFieldValues::FORM_FIELD  => array(
					'nickname'  => 'Ada',
					'interests' => array( 'News' ),
					'birthday'  => '2026-02-28',
				),
			)
		);

		$this->assertTrue( $result );

		$saved = $this->contacts->get( $contact );
		$this->assertSame( 'After', $saved['first_name'] );
		$this->assertSame( '0912345678', $saved['phone'] );

		$values = ( new CustomFieldValues() )->for_contact( $contact );
		$this->assertSame( 'Ada', $values['nickname'] );
		$this->assertSame( array( 'News' ), $values['interests'] );
		$this->assertSame( '2026-02-28', $values['birthday'] );
	}

	public function test_invalid_custom_value_blocks_the_standard_profile_update(): void {
		$this->save_definitions();
		$contact = $this->make_contact( array( 'first_name' => 'Before' ) );
		$row     = $this->contacts->get( $contact );

		$result = Actions::persist_contact(
			$contact,
			array(
				'email'      => (string) $row['email'],
				'first_name' => 'Changed',
				'last_name'  => 'Buyer',
				'phone'      => '',
				CustomFieldValues::FORM_MARKER => array( 'nickname', 'score' ),
				CustomFieldValues::FORM_FIELD  => array(
					'nickname' => 'Changed nickname',
					'score'    => 'not-a-number',
				),
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'nowcrm_invalid_custom_values', $result->get_error_code() );
		$this->assertSame( 'Before', $this->contacts->get( $contact )['first_name'] );
		$this->assertArrayNotHasKey( 'nickname', ( new CustomFieldValues() )->for_contact( $contact ) );
	}

	public function test_legacy_contact_form_without_markers_keeps_custom_values(): void {
		$this->save_definitions();
		$contact = $this->make_contact();
		$row     = $this->contacts->get( $contact );
		$values  = new CustomFieldValues();

		$this->assertNotWPError( $values->save( $contact, array( 'nickname' => 'Ada' ) ) );

		$result = Actions::persist_contact(
			$contact,
			array(
				'email'      => (string) $row['email'],
				'first_name' => 'Legacy',
				'last_name'  => 'Buyer',
				'phone'      => '',
			)
		);

		$this->assertTrue( $result );
		$this->assertSame( 'Legacy', $this->contacts->get( $contact )['first_name'] );
		$this->assertSame( 'Ada', $values->for_contact( $contact )['nickname'] );
	}

	public function test_contact_form_renders_typed_inputs_and_markers(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->save_definitions();
		$contact = $this->make_contact();
		$values  = new CustomFieldValues();

		$this->assertNotWPError(
			$values->save(
				$contact,
				array(
					'nickname'  => 'Ada',
					'interests' => array( 'News' ),
					'birthday'  => '2026-02-28',
					'last_call' => '2026-02-28 09:30:00',
				)
			)
		);

		ob_start();
		CustomFields::render_contact_fields( $contact );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'name="nowcrm_cf_present[]" value="nickname"', $html );
		$this->assertStringContainsString( 'name="nowcrm_cf[nickname]"', $html );
		$this->assertStringContainsString( 'value="Ada"', $html );
		$this->assertStringContainsString( 'name="nowcrm_cf[interests][]"', $html );
		$this->assertMatchesRegularExpression( '/name="nowcrm_cf\[interests\]\[\]"\s+value="News"\s+checked=/', $html );
		$this->assertStringContainsString( 'type="date"', $html );
		$this->assertStringContainsString( 'value="2026-02-28"', $html );
		$this->assertStringContainsString( 'type="datetime-local"', $html );
		$this->assertStringContainsString( 'value="2026-02-28T09:30"', $html );
	}
}
