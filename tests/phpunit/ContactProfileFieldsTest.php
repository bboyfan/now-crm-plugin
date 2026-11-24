<?php
/**
 * Contact profile field contract and form renderer.
 *
 * @package NowCRM
 */

use NowCRM\Admin\Actions;
use NowCRM\Admin\ContactProfileFields;
use NowCRM\Contacts\ProfileFields;

/**
 * @covers \NowCRM\Contacts\ProfileFields
 * @covers \NowCRM\Admin\ContactProfileFields
 */
final class ContactProfileFieldsTest extends NowCrmTestCase {

	private const KEYS = array(
		'prefix',
		'contact_type',
		'date_of_birth',
		'timezone',
		'address_line_1',
		'address_line_2',
		'city',
		'state',
		'postal_code',
		'country',
	);

	public function test_field_keys_are_contact_columns(): void {
		$this->assertSame( self::KEYS, ProfileFields::keys() );

		foreach ( self::KEYS as $key ) {
			$definition = ProfileFields::definition( $key );

			$this->assertIsArray( $definition );
			$this->assertArrayHasKey( 'type', $definition );
			$this->assertArrayHasKey( 'label', $definition );
			$this->assertArrayHasKey( 'nullable', $definition );
			$this->assertArrayHasKey( 'default', $definition );
		}

		$this->assertNull( ProfileFields::definition( 'email' ) );
	}

	public function test_defaults_are_canonical(): void {
		$this->assertSame(
			array(
				'prefix'         => '',
				'contact_type'   => 'lead',
				'date_of_birth'  => null,
				'timezone'       => '',
				'address_line_1' => '',
				'address_line_2' => '',
				'city'           => '',
				'state'          => '',
				'postal_code'    => '',
				'country'        => '',
			),
			ProfileFields::defaults()
		);

		$this->assertTrue( ProfileFields::definition( 'date_of_birth' )['nullable'] );
		$this->assertFalse( ProfileFields::definition( 'country' )['nullable'] );
	}

	public function test_omitted_fields_stay_out_of_the_patch(): void {
		$this->assertSame( array(), ProfileFields::normalize( array() ) );

		$this->assertSame(
			array( 'city' => 'Taipei' ),
			ProfileFields::normalize(
				array(
					'city'      => 'Taipei',
					'email'     => 'ignored@example.test',
					'status'    => 'subscribed',
					'term_ids'  => array( 1, 2 ),
					'note'      => 'ignored',
				)
			)
		);
	}

	public function test_full_patch_normalizes_select_and_date_values(): void {
		$patch = ProfileFields::normalize(
			array(
				'prefix'         => 'Ms',
				'contact_type'   => 'Customer',
				'date_of_birth'  => '1990-05-17',
				'timezone'       => 'Asia/Taipei',
				'address_line_1' => '1F., No. 1',
				'address_line_2' => '',
				'city'           => 'Taipei',
				'state'          => 'Taipei',
				'postal_code'    => '100',
				'country'        => 'tw',
			)
		);

		$this->assertIsArray( $patch );
		$this->assertSame( 'Ms', $patch['prefix'] );
		$this->assertSame( 'customer', $patch['contact_type'] );
		$this->assertSame( '1990-05-17', $patch['date_of_birth'] );
		$this->assertSame( 'TW', $patch['country'] );
		$this->assertSame( '', $patch['address_line_2'] );
	}

	public function test_invalid_contact_type_is_rejected(): void {
		$result = ProfileFields::normalize( array( 'contact_type' => 'vip' ) );

		$this->assertWPError( $result );
		$this->assertSame( 'nowcrm_invalid_contact_type', $result->get_error_code() );
	}

	public function test_empty_contact_type_falls_back_to_the_default(): void {
		$this->assertSame(
			array( 'contact_type' => 'lead' ),
			ProfileFields::normalize( array( 'contact_type' => '' ) )
		);
	}

	public function test_prefix_allowlist_and_filter(): void {
		$this->assertSame( 'Mr', ProfileFields::normalize_field( 'prefix', 'Mr' ) );
		$this->assertSame( '', ProfileFields::normalize_field( 'prefix', '' ) );

		$result = ProfileFields::normalize_field( 'prefix', 'Dr' );
		$this->assertWPError( $result );
		$this->assertSame( 'nowcrm_invalid_prefix', $result->get_error_code() );

		$filter = static fn( array $prefixes ): array => array_merge( $prefixes, array( 'Dr' ) );

		add_filter( 'nowcrm_contact_prefixes', $filter );
		$this->assertSame( 'Dr', ProfileFields::normalize_field( 'prefix', 'Dr' ) );
		remove_filter( 'nowcrm_contact_prefixes', $filter );

		$this->assertFalse( ProfileFields::is_valid_prefix( 'Dr' ) );
	}

	public function test_country_accepts_iso_codes_only(): void {
		$this->assertTrue( ProfileFields::is_valid_country( 'TW' ) );
		$this->assertTrue( ProfileFields::is_valid_country( 'us' ) );
		$this->assertFalse( ProfileFields::is_valid_country( 'ZZ' ) );
		$this->assertFalse( ProfileFields::is_valid_country( 'TWN' ) );

		$this->assertSame( 'US', ProfileFields::normalize_field( 'country', 'us' ) );
		$this->assertSame( '', ProfileFields::normalize_field( 'country', '' ) );

		$result = ProfileFields::normalize_field( 'country', 'ZZ' );
		$this->assertWPError( $result );
		$this->assertSame( 'nowcrm_invalid_country', $result->get_error_code() );
	}

	public function test_date_edges(): void {
		$this->assertTrue( ProfileFields::is_valid_date( '2024-02-29' ) );
		$this->assertTrue( ProfileFields::is_valid_date( '1990-05-17' ) );

		foreach ( array( '2023-02-29', '2024-04-31', '2024-13-01', '2024-00-10', '2024-2-9', '17-05-1990', '2024/05/17', 'tomorrow' ) as $invalid ) {
			$this->assertFalse( ProfileFields::is_valid_date( $invalid ), $invalid );
		}

		$this->assertSame( '2024-02-29', ProfileFields::normalize_field( 'date_of_birth', '2024-02-29' ) );
		$this->assertNull( ProfileFields::normalize_field( 'date_of_birth', '' ) );
		$this->assertNull( ProfileFields::normalize_field( 'date_of_birth', null ) );

		$result = ProfileFields::normalize_field( 'date_of_birth', '2023-02-29' );

		$this->assertWPError( $result );
		$this->assertSame( 'nowcrm_invalid_date_of_birth', $result->get_error_code() );
	}

	public function test_timezone_must_be_an_iana_identifier(): void {
		$this->assertTrue( ProfileFields::is_valid_timezone( 'Asia/Taipei' ) );
		$this->assertFalse( ProfileFields::is_valid_timezone( 'Taipei' ) );
		$this->assertFalse( ProfileFields::is_valid_timezone( 'Asia/Nowhere' ) );

		$this->assertSame( 'Asia/Taipei', ProfileFields::normalize_field( 'timezone', ' Asia/Taipei ' ) );
		$this->assertSame( '', ProfileFields::normalize_field( 'timezone', '' ) );

		$result = ProfileFields::normalize_field( 'timezone', 'Taipei' );
		$this->assertWPError( $result );
		$this->assertSame( 'nowcrm_invalid_timezone', $result->get_error_code() );
	}

	public function test_text_values_are_sanitized_and_capped(): void {
		$this->assertSame( 'Taipei', ProfileFields::normalize_field( 'city', ' <b>Taipei</b> ' ) );
		$this->assertSame(
			str_repeat( 'a', 191 ),
			ProfileFields::normalize_field( 'address_line_1', str_repeat( 'a', 250 ) )
		);
	}

	public function test_non_scalar_values_are_rejected(): void {
		$result = ProfileFields::normalize( array( 'city' => array( 'Taipei' ) ) );

		$this->assertWPError( $result );
		$this->assertSame( 'nowcrm_invalid_city', $result->get_error_code() );
	}

	public function test_form_and_display_values_use_canonical_defaults(): void {
		$empty = array();

		$this->assertSame( 'lead', ProfileFields::form_value( $empty, 'contact_type' ) );
		$this->assertSame( '', ProfileFields::form_value( $empty, 'date_of_birth' ) );
		$this->assertSame( '', ProfileFields::form_value( $empty, 'country' ) );

		$contact = array(
			'contact_type'  => 'customer',
			'date_of_birth' => '1990-05-17',
			'country'       => 'TW',
			'city'          => 'Taipei',
		);

		$this->assertSame( '客戶', ProfileFields::display_value( $contact, 'contact_type' ) );
		$this->assertSame( '1990-05-17', ProfileFields::display_value( $contact, 'date_of_birth' ) );
		$this->assertSame( ProfileFields::countries()['TW'], ProfileFields::display_value( $contact, 'country' ) );
		$this->assertSame( 'Taipei', ProfileFields::display_value( $contact, 'city' ) );
	}

	public function test_repository_persists_profile_fields_on_create_and_update(): void {
		$id = $this->make_contact(
			array(
				'prefix'         => 'Ms',
				'contact_type'   => 'customer',
				'date_of_birth'  => '1990-05-17',
				'timezone'       => 'Asia/Taipei',
				'address_line_1' => 'No. 1',
				'city'           => 'Taipei',
				'postal_code'    => '100',
				'country'        => 'TW',
			)
		);

		$contact = $this->contacts->get( $id );

		$this->assertSame( 'Ms', $contact['prefix'] );
		$this->assertSame( 'customer', $contact['contact_type'] );
		$this->assertSame( '1990-05-17', $contact['date_of_birth'] );
		$this->assertSame( 'Asia/Taipei', $contact['timezone'] );
		$this->assertSame( 'No. 1', $contact['address_line_1'] );
		$this->assertSame( 'Taipei', $contact['city'] );
		$this->assertSame( '100', $contact['postal_code'] );
		$this->assertSame( 'TW', $contact['country'] );

		$this->assertTrue( $this->contacts->update( $id, array( 'city' => 'Kaohsiung' ) ) );

		$updated = $this->contacts->get( $id );

		$this->assertSame( 'Kaohsiung', $updated['city'] );
		$this->assertSame( 'Ms', $updated['prefix'] );
		$this->assertSame( 'customer', $updated['contact_type'] );
		$this->assertSame( '1990-05-17', $updated['date_of_birth'] );
		$this->assertSame( 'No. 1', $updated['address_line_1'] );
		$this->assertSame( 'TW', $updated['country'] );
	}

	public function test_new_contact_gets_profile_defaults(): void {
		$contact = $this->contacts->get( $this->make_contact() );

		$this->assertSame( '', $contact['prefix'] );
		$this->assertSame( 'lead', $contact['contact_type'] );
		$this->assertNull( $contact['date_of_birth'] );
		$this->assertSame( '', $contact['timezone'] );
		$this->assertSame( '', $contact['country'] );
	}

	public function test_update_clears_date_of_birth_and_keeps_other_profile_fields(): void {
		$id = $this->make_contact(
			array(
				'prefix'        => 'Mr',
				'date_of_birth' => '1985-12-31',
				'city'          => 'Tainan',
			)
		);

		$this->assertTrue( $this->contacts->update( $id, array( 'date_of_birth' => '' ) ) );

		$contact = $this->contacts->get( $id );

		$this->assertNull( $contact['date_of_birth'] );
		$this->assertSame( 'Mr', $contact['prefix'] );
		$this->assertSame( 'Tainan', $contact['city'] );
	}

	public function test_invalid_profile_value_blocks_the_update(): void {
		$id = $this->make_contact( array( 'city' => 'Taipei' ) );

		$result = $this->contacts->update(
			$id,
			array(
				'city'    => 'Kaohsiung',
				'country' => 'ZZ',
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'nowcrm_invalid_country', $result->get_error_code() );
		$this->assertSame( 'Taipei', $this->contacts->get( $id )['city'] );
	}

	public function test_contact_form_persists_profile_fields_and_clears_birthday(): void {
		$id = $this->make_contact( array( 'email' => 'form@example.test' ) );

		$result = Actions::persist_contact(
			$id,
			array(
				'email'          => 'form@example.test',
				'first_name'     => 'Mei',
				'last_name'      => 'Chen',
				'phone'          => '0912345678',
				'prefix'         => 'Ms',
				'contact_type'   => 'customer',
				'date_of_birth'  => '1990-05-17',
				'timezone'       => 'Asia/Taipei',
				'address_line_1' => 'No. 1',
				'address_line_2' => '',
				'city'           => 'Taipei',
				'state'          => '',
				'postal_code'    => '100',
				'country'        => 'TW',
				'status'         => 'subscribed',
				'note'           => '',
			)
		);

		$this->assertTrue( $result );

		$contact = $this->contacts->get( $id );

		$this->assertSame( 'Ms', $contact['prefix'] );
		$this->assertSame( 'customer', $contact['contact_type'] );
		$this->assertSame( '1990-05-17', $contact['date_of_birth'] );
		$this->assertSame( 'TW', $contact['country'] );
		$this->assertSame( 'Taipei', $contact['city'] );

		// A later form post omits most profile controls and clears the birthday.
		$result = Actions::persist_contact(
			$id,
			array(
				'email'         => 'form@example.test',
				'first_name'    => 'Mei',
				'last_name'     => 'Chen',
				'phone'         => '0912345678',
				'date_of_birth' => '',
				'status'        => 'subscribed',
				'note'          => '',
			)
		);

		$this->assertTrue( $result );

		$contact = $this->contacts->get( $id );

		$this->assertNull( $contact['date_of_birth'] );
		$this->assertSame( 'Ms', $contact['prefix'] );
		$this->assertSame( 'customer', $contact['contact_type'] );
		$this->assertSame( 'TW', $contact['country'] );
		$this->assertSame( 'Taipei', $contact['city'] );
	}

	public function test_form_renders_basic_and_address_sections(): void {
		$contact = array(
			'first_name'     => 'Mei',
			'last_name'      => 'Chen',
			'email'          => 'mei@example.test',
			'phone'          => '0912345678',
			'prefix'         => 'Ms',
			'contact_type'   => 'customer',
			'date_of_birth'  => '1990-05-17',
			'timezone'       => 'Asia/Taipei',
			'address_line_1' => 'No. 1',
			'address_line_2' => '',
			'city'           => 'Taipei',
			'state'          => '',
			'postal_code'    => '100',
			'country'        => 'TW',
		);

		ob_start();
		ContactProfileFields::render_basic( $contact );
		ContactProfileFields::render_address( $contact );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'name="first_name"', $html );
		$this->assertStringContainsString( 'name="last_name"', $html );
		$this->assertStringContainsString( 'name="email"', $html );
		$this->assertStringContainsString( 'name="phone"', $html );
		$this->assertStringContainsString( 'name="prefix"', $html );
		$this->assertStringContainsString( 'name="contact_type"', $html );
		$this->assertStringContainsString( 'name="date_of_birth"', $html );
		$this->assertStringContainsString( 'name="timezone"', $html );
		$this->assertStringContainsString( 'value="1990-05-17"', $html );
		$this->assertStringContainsString( 'name="address_line_1"', $html );
		$this->assertStringContainsString( 'name="postal_code"', $html );
		$this->assertStringContainsString( 'name="country"', $html );
		$this->assertMatchesRegularExpression( '/value="customer"\s+selected=/', $html );
		$this->assertMatchesRegularExpression( '/value="Ms"\s+selected=/', $html );
		$this->assertMatchesRegularExpression( '/value="TW"\s+selected=/', $html );
	}
}
