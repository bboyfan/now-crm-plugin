<?php
/**
 * Company repository tests: linkage, isolation, and deletion safety.
 *
 * @package NowCRM
 */

use NowCRM\Companies\CompanyRepository;

/**
 * @covers \NowCRM\Companies\CompanyRepository
 */
final class CompaniesTest extends NowCrmTestCase {

	private CompanyRepository $companies;

	public function set_up(): void {
		parent::set_up();

		$this->companies = new CompanyRepository();
	}

	public function test_create_requires_name_and_unique_name(): void {
		$missing = $this->companies->create( array( 'name' => '   ' ) );

		$this->assertWPError( $missing );
		$this->assertSame( 'nowcrm_invalid_company_name', $missing->get_error_code() );

		$id = $this->make_company( array( 'name' => 'Acme ' . wp_generate_password( 6, false ) ) );
		$name = $this->companies->get( $id )['name'];

		$dupe = $this->companies->create( array( 'name' => $name ) );

		$this->assertWPError( $dupe );
		$this->assertSame( 'nowcrm_duplicate_company', $dupe->get_error_code() );
		$this->assertSame( 1, $this->table_count( 'companies', "name = '" . esc_sql( $name ) . "'" ) );
	}

	public function test_update_validates_type_and_owner(): void {
		$id      = $this->make_company();
		$contact = $this->make_contact();

		$bad_type = $this->companies->update( $id, array( 'type' => 'Spaceship' ) );

		$this->assertWPError( $bad_type );
		$this->assertSame( 'nowcrm_invalid_company_type', $bad_type->get_error_code() );

		$bad_owner = $this->companies->update( $id, array( 'owner_id' => 999999999 ) );

		$this->assertWPError( $bad_owner );
		$this->assertSame( 'nowcrm_invalid_owner', $bad_owner->get_error_code() );

		$this->assertTrue( $this->companies->update( $id, array( 'owner_id' => $contact ) ) );

		$company = $this->companies->get( $id );

		$this->assertSame( $contact, $company['owner_id'] );

		// Setting the owner also links that contact to the company.
		$attached = $this->companies->contacts( $id );

		$this->assertNotWPError( $attached );
		$this->assertSame( 1, $attached['total'] );
		$this->assertSame( $contact, (int) $attached['items'][0]['id'] );
	}

	public function test_search_filters_sort_and_pagination(): void {
		$alpha = $this->make_company(
			array(
				'name'     => 'Alpha ' . wp_generate_password( 6, false ),
				'industry' => 'Banking',
				'type'     => 'Vendor',
			)
		);
		$beta  = $this->make_company(
			array(
				'name'     => 'Beta ' . wp_generate_password( 6, false ),
				'industry' => 'Banking',
				'type'     => 'Partner',
			)
		);

		$this->make_company(
			array(
				'name'     => 'Gamma ' . wp_generate_password( 6, false ),
				'industry' => 'Retail',
				'type'     => 'Partner',
			)
		);

		$by_industry = $this->companies->search( array( 'industry' => 'Banking', 'per_page' => 100 ) );

		$this->assertSame( 2, $by_industry['total'] );

		$by_type = $this->companies->search(
			array(
				'industry' => 'Banking',
				'type'     => 'Partner',
				'per_page' => 100,
			)
		);

		$this->assertSame( 1, $by_type['total'] );
		$this->assertSame( $beta, (int) $by_type['items'][0]['id'] );

		$by_name = $this->companies->search(
			array(
				'search'   => (string) $this->companies->get( $alpha )['name'],
				'per_page' => 100,
			)
		);

		$this->assertSame( 1, $by_name['total'] );
		$this->assertSame( $alpha, (int) $by_name['items'][0]['id'] );

		$first_page = $this->companies->search(
			array(
				'per_page' => 2,
				'page'     => 1,
				'orderby'  => 'id',
				'order'    => 'ASC',
			)
		);

		$this->assertSame( 3, $first_page['total'] );
		$this->assertCount( 2, $first_page['items'] );

		$second_page = $this->companies->search(
			array(
				'per_page' => 2,
				'page'     => 2,
				'orderby'  => 'id',
				'order'    => 'ASC',
			)
		);

		$this->assertCount( 1, $second_page['items'] );
		$this->assertGreaterThan(
			(int) $first_page['items'][1]['id'],
			(int) $second_page['items'][0]['id']
		);
	}

	public function test_attach_is_idempotent_and_validates_contacts(): void {
		$id      = $this->make_company();
		$first   = $this->make_contact();
		$second  = $this->make_contact();

		$this->assertSame( 2, $this->companies->attach_contacts( $id, array( $first, $second ) ) );
		$this->assertSame( 0, $this->companies->attach_contacts( $id, array( $first, $second ) ) );
		$this->assertSame( 2, $this->table_count( 'company_contacts', "company_id = {$id}" ) );

		$missing_contact = $this->companies->attach_contacts( $id, array( $first, 999999999 ) );

		$this->assertWPError( $missing_contact );
		$this->assertSame( 'nowcrm_invalid_contact', $missing_contact->get_error_code() );

		$missing_company = $this->companies->attach_contacts( 999999999, array( $first ) );

		$this->assertWPError( $missing_company );
		$this->assertSame( 'nowcrm_not_found', $missing_company->get_error_code() );
	}

	public function test_detach_keeps_contacts_and_clears_owner(): void {
		$id      = $this->make_company();
		$owner   = $this->make_contact();
		$other   = $this->make_contact();

		$this->companies->attach_contacts( $id, array( $owner, $other ) );
		$this->companies->set_owner( $id, $owner );

		$this->assertSame( 1, $this->companies->detach_contacts( $id, array( $owner ) ) );
		$this->assertSame( 1, $this->table_count( 'company_contacts', "company_id = {$id}" ) );
		$this->assertSame( 2, $this->table_count( 'contacts', "id IN ({$owner},{$other})" ) );

		$company = $this->companies->get( $id );

		$this->assertNull( $company['owner_id'] );

		$remaining = $this->companies->contacts( $id, array( 'per_page' => 100 ) );

		$this->assertSame( 1, $remaining['total'] );
		$this->assertSame( $other, (int) $remaining['items'][0]['id'] );
	}

	public function test_delete_company_keeps_contacts_and_other_links(): void {
		$first   = $this->make_company();
		$second  = $this->make_company();
		$contact = $this->make_contact();

		$this->companies->attach_contacts( $first, array( $contact ) );
		$this->companies->attach_contacts( $second, array( $contact ) );

		$this->assertTrue( $this->companies->delete( $first ) );
		$this->assertNull( $this->companies->get( $first ) );
		$this->assertSame( 0, $this->table_count( 'company_contacts', "company_id = {$first}" ) );

		// The contact survives, and the other company's link is untouched.
		$this->assertSame( 1, $this->table_count( 'contacts', "id = {$contact}" ) );
		$this->assertSame( 1, $this->table_count( 'company_contacts', "company_id = {$second}" ) );
	}

	public function test_delete_contact_clears_company_links_and_owner(): void {
		$company       = $this->make_company();
		$other_company = $this->make_company();
		$contact       = $this->make_contact();
		$other         = $this->make_contact();

		$this->companies->attach_contacts( $company, array( $contact, $other ) );
		$this->companies->attach_contacts( $other_company, array( $other ) );
		$this->companies->set_owner( $company, $contact );

		$this->assertTrue( $this->contacts->delete( $contact ) );
		$this->assertNull( $this->contacts->get( $contact ) );

		// The company row stays, but the deleted contact's pivot and owner
		// reference are gone while unrelated rows are untouched.
		$this->assertNotNull( $this->companies->get( $company ) );
		$this->assertNull( $this->companies->get( $company )['owner_id'] );
		$this->assertSame( 0, $this->table_count( 'company_contacts', "contact_id = {$contact}" ) );
		$this->assertSame( 1, $this->table_count( 'company_contacts', "company_id = {$company} AND contact_id = {$other}" ) );
		$this->assertSame( 1, $this->table_count( 'company_contacts', "company_id = {$other_company}" ) );
		$this->assertSame( 1, $this->table_count( 'contacts', "id = {$other}" ) );
	}

	public function test_cross_company_isolation(): void {
		$first   = $this->make_company();
		$second  = $this->make_company();
		$contact = $this->make_contact();

		$this->companies->attach_contacts( $first, array( $contact ) );

		$other_contacts = $this->companies->contacts( $second, array( 'per_page' => 100 ) );

		$this->assertSame( 0, $other_contacts['total'] );

		$available = $this->companies->available_contacts( $second, '', 100 );
		$ids       = array_map( static fn( array $row ): int => (int) $row['id'], $available );

		$this->assertContains( $contact, $ids );

		$unavailable = $this->companies->available_contacts( $first, '', 100 );
		$used_ids    = array_map( static fn( array $row ): int => (int) $row['id'], $unavailable );

		$this->assertNotContains( $contact, $used_ids );

		$this->assertTrue( $this->has_index( 'company_contacts', 'company_contact' ) );
		$this->assertTrue( $this->index_is_unique( 'company_contacts', 'company_contact' ) );

		$ranked = $this->companies->search(
			array(
				'orderby'  => 'contacts_count',
				'order'    => 'DESC',
				'per_page' => 100,
			)
		);

		$this->assertSame( $first, (int) $ranked['items'][0]['id'] );
		$this->assertSame( 1, (int) $ranked['items'][0]['contact_count'] );
	}

	/**
	 * Create a company with fictitious data.
	 *
	 * @param array<string, mixed> $overrides Field overrides.
	 */
	private function make_company( array $overrides = array() ): int {
		$id = $this->companies->create(
			array_merge(
				array(
					'name'     => 'Fixture ' . wp_generate_password( 8, false ),
					'industry' => 'Technology',
					'type'     => 'Prospect',
				),
				$overrides
			)
		);

		$this->assertIsInt( $id, 'Fixture company creation failed.' );

		return (int) $id;
	}
}
