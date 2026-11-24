<?php
/**
 * Contact CSV import and export behavior.
 *
 * @package NowCRM
 */

use NowCRM\Contacts\ContactRepository;
use NowCRM\Contacts\CsvExport;
use NowCRM\Contacts\CsvImport;
use NowCRM\Contacts\CsvStorage;
use NowCRM\Contacts\CustomFieldDefinitions;
use NowCRM\Contacts\CustomFieldValues;
use NowCRM\Contacts\Status;
use NowCRM\Terms\TermRepository;

/**
 * @covers \NowCRM\Contacts\CsvImport
 * @covers \NowCRM\Contacts\CsvExport
 * @covers \NowCRM\Contacts\CsvStorage
 */
final class ContactCsvTest extends NowCrmTestCase {

	private int $actor;

	/**
	 * Temp files created by one test.
	 *
	 * @var string[]
	 */
	private array $paths = array();

	/**
	 * Staged tokens created by one test.
	 *
	 * @var string[]
	 */
	private array $tokens = array();

	public function set_up(): void {
		parent::set_up();

		$this->actor = (int) self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->actor );

		$saved = CustomFieldDefinitions::save(
			array(
				array(
					'label'   => 'Company size',
					'slug'    => 'company_size',
					'type'    => 'select-one',
					'options' => array( '1-10', '11-50' ),
					'group'   => 'default',
				),
				array(
					'label'   => 'Score',
					'slug'    => 'score',
					'type'    => 'number',
					'options' => array(),
					'group'   => 'default',
				),
				array(
					'label'   => 'Birthday',
					'slug'    => 'birthday',
					'type'    => 'date',
					'options' => array(),
					'group'   => 'default',
				),
				array(
					'label'   => 'Interests',
					'slug'    => 'interests',
					'type'    => 'select-multi',
					'options' => array( 'News', 'Events' ),
					'group'   => 'default',
				),
				array(
					'label'   => 'Note',
					'slug'    => 'note',
					'type'    => 'text',
					'options' => array(),
					'group'   => 'default',
				),
			)
		);

		$this->assertNotWPError( $saved );
	}

	public function tear_down(): void {
		foreach ( $this->tokens as $token ) {
			CsvStorage::delete( $token );
		}

		foreach ( $this->paths as $path ) {
			if ( file_exists( $path ) ) {
				unlink( $path );
			}
		}

		wp_set_current_user( 0 );

		parent::tear_down();
	}

	public function test_upload_transport_rejects_missing_wrong_type_and_oversize(): void {
		$this->assertWPError(
			CsvImport::validate_upload( array( 'error' => UPLOAD_ERR_NO_FILE ), false )
		);

		$path = $this->temp_file( 'Email,First name' );

		$this->assertWPError(
			CsvImport::validate_upload(
				array(
					'error'     => UPLOAD_ERR_OK,
					'name'      => 'contacts.txt',
					'tmp_name'  => $path,
					'size'      => 10,
					'type'      => 'text/plain',
				),
				false
			)
		);

		$this->assertWPError(
			CsvImport::validate_upload(
				array(
					'error'     => UPLOAD_ERR_OK,
					'name'      => 'contacts.csv',
					'tmp_name'  => $path,
					'size'      => CsvImport::MAX_BYTES + 1,
					'type'      => 'text/csv',
				),
				false
			)
		);
	}

	public function test_prepare_handles_bom_semicolon_and_windows_1252(): void {
		$bom = $this->temp_file( "\xEF\xBB\xBFEmail;First name\r\nada@example.test;Ada\r\n" );
		$csv = new CsvImport();
		$state = $csv->prepare( $bom, 'semicolon', 'bom.csv', $this->actor );

		$this->assertNotWPError( $state );
		$this->tokens[] = (string) $state['token'];
		$this->assertSame( array( 'Email', 'First name' ), $state['headers'] );
		$this->assertSame( 1, $state['total_rows'] );

		$preview = $csv->preview( (string) $state['token'], $this->actor );
		$this->assertSame( 'Ada', $preview[0][1] );

		$cp1252 = $this->temp_file( "Email,First name\nada@example.test,\xE9\n" );
		$state2 = $csv->prepare( $cp1252, 'comma', 'cp1252.csv', $this->actor );

		$this->assertNotWPError( $state2 );
		$this->tokens[] = (string) $state2['token'];

		$preview2 = $csv->preview( (string) $state2['token'], $this->actor );
		$this->assertSame( 'é', $preview2[0][1] );
	}

	public function test_prepare_rejects_duplicate_and_empty_headers(): void {
		$csv = new CsvImport();

		$duplicate = $csv->prepare( $this->temp_file( "Email,Email\n" ), 'comma', 'dup.csv', $this->actor );
		$this->assertWPError( $duplicate );

		$empty = $csv->prepare( $this->temp_file( "Email,\n" ), 'comma', 'empty.csv', $this->actor );
		$this->assertWPError( $empty );

		$no_rows = $csv->prepare( $this->temp_file( "Email\n" ), 'comma', 'rows.csv', $this->actor );
		$this->assertWPError( $no_rows );
	}

	public function test_mapping_requires_email_and_rejects_unknown_targets(): void {
		$path  = $this->temp_file( "Email,Foo\nada@example.test,bar\n" );
		$csv   = new CsvImport();
		$state = $csv->prepare( $path, 'comma', 'map.csv', $this->actor );

		$this->assertNotWPError( $state );
		$this->tokens[] = (string) $state['token'];

		$missing = $csv->start( (string) $state['token'], array( 1 => 'first_name' ), array( 'status' => 'subscribed' ), $this->actor );
		$this->assertWPError( $missing );
		$this->assertSame( 'nowcrm_csv_missing_email', $missing->get_error_code() );

		$unknown = $csv->start( (string) $state['token'], array( 0 => 'wp_user_id' ), array( 'status' => 'subscribed' ), $this->actor );
		$this->assertWPError( $unknown );

		$this->assertArrayNotHasKey( 'company', CsvImport::fields() );

		$company = $csv->start( (string) $state['token'], array( 0 => 'email', 1 => 'company' ), array( 'status' => 'subscribed' ), $this->actor );
		$this->assertWPError( $company );
		$this->assertSame( 'nowcrm_csv_unknown_field', $company->get_error_code() );

		$custom = $csv->start(
			(string) $state['token'],
			array(
				0 => 'email',
				1 => 'cf:company_size',
			),
			array( 'status' => 'subscribed' ),
			$this->actor
		);
		$this->assertNotWPError( $custom );
	}

	public function test_import_persists_typed_standard_and_custom_fields(): void {
		$list = $this->terms->create( TermRepository::TYPE_LIST, 'Newsletter' );
		$tag  = $this->terms->create( TermRepository::TYPE_TAG, 'VIP' );

		$this->assertIsInt( $list );
		$this->assertIsInt( $tag );

		$headers = array( 'Email', 'First name', 'Last name', 'Phone', 'Date of Birth', 'Country', 'Company size', 'Score', 'Birthday', 'Interests', 'Lists', 'Tags' );
		$row     = array( 'typed@example.test', 'Ada', 'Lovelace', '0912345678', '12/31/1990', 'TW', '11-50', '1,234', '2020-02-02', 'News,Events', 'Newsletter,VIP', 'VIP' );
		$path    = $this->write_csv( array( $headers, $row ) );

		$mapping = $this->mapping(
			$headers,
			array(
				'Email'         => 'email',
				'First name'    => 'first_name',
				'Last name'     => 'last_name',
				'Phone'         => 'phone',
				'Date of Birth' => 'date_of_birth',
				'Country'       => 'country',
				'Company size'  => 'cf:company_size',
				'Score'         => 'cf:score',
				'Birthday'      => 'cf:birthday',
				'Interests'     => 'cf:interests',
				'Lists'         => 'lists',
				'Tags'          => 'tags',
			)
		);

		$result = $this->run_import( $path, $mapping, array( 'status' => Status::SUBSCRIBED ) );

		$this->assertTrue( $result['completed'] );
		$this->assertSame( 1, $result['total'] );
		$this->assertSame( 1, $result['inserted'] );
		$this->assertSame( 0, $result['skipped'] );

		$contact = $this->contacts->find_by_email( 'typed@example.test' );

		$this->assertIsArray( $contact );
		$this->assertSame( 'Ada', $contact['first_name'] );
		$this->assertSame( 'Lovelace', $contact['last_name'] );
		$this->assertSame( '1990-12-31', $contact['date_of_birth'] );
		$this->assertSame( 'TW', $contact['country'] );
		$this->assertSame( Status::SUBSCRIBED, $contact['status'] );
		$this->assertSame( 'csv', $contact['source'] );

		$values = ( new CustomFieldValues() )->for_contact( (int) $contact['id'] );

		$this->assertSame( '11-50', $values['company_size'] );
		$this->assertSame( 1234, $values['score'] );
		$this->assertSame( '2020-02-02', $values['birthday'] );
		$this->assertSame( array( 'News', 'Events' ), $values['interests'] );

		$names = array_map(
			static fn( array $term ): string => (string) $term['name'],
			$this->terms->for_contact( (int) $contact['id'] )
		);

		$this->assertContains( 'Newsletter', $names );
		$this->assertContains( 'VIP', $names );
	}

	public function test_keep_existing_data_and_status_guards(): void {
		$id = $this->make_contact(
			array(
				'email'      => 'keep@example.test',
				'first_name' => '',
				'city'       => 'Taipei',
				'status'     => Status::SUBSCRIBED,
			)
		);

		( new CustomFieldValues() )->save( $id, array( 'company_size' => '1-10' ) );

		$existing_list = $this->terms->create( TermRepository::TYPE_LIST, 'Keep' );
		$this->terms->attach( $id, (int) $existing_list );

		$headers = array( 'Email', 'First name', 'City', 'Company size' );
		$path    = $this->write_csv(
			array(
				$headers,
				array( 'keep@example.test', 'Ada', 'Kaohsiung', '11-50' ),
			)
		);
		$mapping = $this->mapping(
			$headers,
			array(
				'Email'        => 'email',
				'First name'   => 'first_name',
				'City'         => 'city',
				'Company size' => 'cf:company_size',
			)
		);

		$result = $this->run_import(
			$path,
			$mapping,
			array(
				'status'              => Status::UNSUBSCRIBED,
				'update'              => '1',
				'keep_existing_data'  => '1',
				'force_update_status' => '0',
			)
		);

		$this->assertSame( 1, $result['updated'] );

		$contact = $this->contacts->get( $id );

		$this->assertSame( 'Ada', $contact['first_name'] );
		$this->assertSame( 'Taipei', $contact['city'] );
		$this->assertSame( Status::UNSUBSCRIBED, $contact['status'] );

		$values = ( new CustomFieldValues() )->for_contact( $id );
		$this->assertSame( '1-10', $values['company_size'] );

		$names = array_map(
			static fn( array $term ): string => (string) $term['name'],
			$this->terms->for_contact( $id )
		);
		$this->assertContains( 'Keep', $names );

		// A non-forced import can never promote a suppressed contact.
		$path2 = $this->write_csv(
			array(
				array( 'Email' ),
				array( 'keep@example.test' ),
			)
		);

		$promote = $this->run_import(
			$path2,
			array( 0 => 'email' ),
			array(
				'status'              => Status::SUBSCRIBED,
				'update'              => '1',
				'force_update_status' => '0',
			)
		);

		$this->assertSame( 1, $promote['unchanged'] );
		$this->assertSame( Status::UNSUBSCRIBED, $this->contacts->get( $id )['status'] );

		// Force is the explicit, human-authorized override.
		$path3 = $this->write_csv(
			array(
				array( 'Email' ),
				array( 'keep@example.test' ),
			)
		);

		$this->run_import(
			$path3,
			array( 0 => 'email' ),
			array(
				'status'              => Status::SUBSCRIBED,
				'update'              => '1',
				'force_update_status' => '1',
			)
		);

		$this->assertSame( Status::SUBSCRIBED, $this->contacts->get( $id )['status'] );
	}

	public function test_existing_only_skips_unknown_emails_and_update_off_keeps_data(): void {
		$id = $this->make_contact(
			array(
				'email'      => 'existing@example.test',
				'first_name' => 'Old',
			)
		);

		$headers = array( 'Email', 'First name' );
		$path    = $this->write_csv(
			array(
				$headers,
				array( 'existing@example.test', 'New' ),
				array( 'missing@example.test', 'Nobody' ),
			)
		);

		$result = $this->run_import(
			$path,
			$this->mapping( $headers, array( 'Email' => 'email', 'First name' => 'first_name' ) ),
			array(
				'status'        => Status::SUBSCRIBED,
				'update'        => '0',
				'existing_only' => '1',
			)
		);

		$this->assertSame( 1, $result['not_in_crm'] );
		$this->assertSame( 1, $result['skipped'] );
		$this->assertSame( 'New', $this->contacts->get( $id )['first_name'] );
		$this->assertNull( $this->contacts->find_by_email( 'missing@example.test' ) );

		// Without "update existing", an import leaves stored contact data alone.
		$path2 = $this->write_csv(
			array(
				$headers,
				array( 'existing@example.test', 'Newer' ),
			)
		);

		$this->run_import(
			$path2,
			$this->mapping( $headers, array( 'Email' => 'email', 'First name' => 'first_name' ) ),
			array(
				'status' => Status::SUBSCRIBED,
				'update' => '0',
			)
		);

		$this->assertSame( 'New', $this->contacts->get( $id )['first_name'] );
	}

	public function test_rows_with_invalid_email_or_typed_values_are_counted_as_skipped(): void {
		$headers = array( 'Email', 'Date of Birth', 'Country' );
		$path    = $this->write_csv(
			array(
				$headers,
				array( 'not-an-email', '1990-01-01', 'TW' ),
				array( 'date@example.test', '31/31/1990', 'TW' ),
				array( 'country@example.test', '1990-01-01', 'ZZ' ),
				array( '', '', '' ),
			)
		);

		$result = $this->run_import(
			$path,
			$this->mapping(
				$headers,
				array(
					'Email'         => 'email',
					'Date of Birth' => 'date_of_birth',
					'Country'       => 'country',
				)
			),
			array( 'status' => Status::SUBSCRIBED )
		);

		$this->assertSame( 4, $result['total'] );
		$this->assertSame( 4, $result['skipped'] );
		$this->assertSame( 1, $result['reasons']['invalid_email'] );
		$this->assertSame( 1, $result['reasons']['invalid_date'] );
		$this->assertSame( 1, $result['reasons']['invalid_field'] );
		$this->assertSame( 1, $result['reasons']['empty'] );
		$this->assertNull( $this->contacts->find_by_email( 'date@example.test' ) );
	}

	public function test_export_selected_scope_includes_membership_and_custom_fields(): void {
		$list = (int) $this->terms->create( TermRepository::TYPE_LIST, 'VIP list' );
		$tag  = (int) $this->terms->create( TermRepository::TYPE_TAG, 'Buyer' );

		$first = $this->make_contact(
			array(
				'email'      => 'one@example.test',
				'first_name' => 'One',
			)
		);
		$second = $this->make_contact(
			array(
				'email'      => 'two@example.test',
				'first_name' => 'Two',
			)
		);

		$this->terms->attach( $first, $list );
		$this->terms->attach( $first, $tag );
		( new CustomFieldValues() )->save( $first, array( 'company_size' => '11-50' ) );

		$export = new CsvExport();
		$plan   = $export->plan(
			array(
				'scope'         => 'selected',
				'ids'           => array( $first ),
				'columns'       => array( 'email', 'first_name', 'lists', 'tags' ),
				'custom_fields' => array( 'company_size' ),
			)
		);

		$this->assertNotWPError( $plan );
		$this->assertSame( 1, $plan['total'] );

		$rows = iterator_to_array( $export->rows( $plan ), false );

		$this->assertCount( 1, $rows );
		$this->assertSame( 'one@example.test', $rows[0][0] );
		$this->assertSame( 'VIP list', $rows[0][2] );
		$this->assertSame( 'Buyer', $rows[0][3] );
		$this->assertSame( '11-50', $rows[0][4] );

		$all = $export->plan(
			array(
				'scope'   => 'filtered',
				'columns' => array( 'email' ),
			)
		);

		$this->assertNotWPError( $all );
		$this->assertSame( 2, $all['total'] );
		$this->assertCount( 2, iterator_to_array( $export->rows( $all ), false ) );

		$this->assertWPError(
			$export->plan( array( 'scope' => 'filtered', 'columns' => array() ) )
		);

		$too_many = range( 1, CsvExport::MAX_SELECTED + 1 );
		$this->assertWPError(
			$export->plan(
				array(
					'scope'   => 'selected',
					'ids'     => $too_many,
					'columns' => array( 'email' ),
				)
			)
		);
	}

	public function test_export_cells_are_formula_safe(): void {
		$this->assertSame( "'=1+1", CsvExport::safe_cell( '=1+1' ) );
		$this->assertSame( "'+SUM(A1)", CsvExport::safe_cell( '+SUM(A1)' ) );
		$this->assertSame( "'-5", CsvExport::safe_cell( -5 ) );
		$this->assertSame( "'@cmd", CsvExport::safe_cell( '@cmd' ) );
		$this->assertSame( "'\tcmd", CsvExport::safe_cell( "\tcmd" ) );
		$this->assertSame( '', CsvExport::safe_cell( null ) );
		$this->assertSame( 'normal', CsvExport::safe_cell( 'normal' ) );
	}

	public function test_storage_is_owner_scoped_and_deleted_after_completion(): void {
		$path  = $this->write_csv(
			array(
				array( 'Email' ),
				array( 'owner@example.test' ),
			)
		);
		$csv   = new CsvImport();
		$state = $csv->prepare( $path, 'comma', 'owner.csv', $this->actor );

		$this->assertNotWPError( $state );
		$token = (string) $state['token'];

		$other = (int) self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->assertNull( CsvStorage::read( $token, $other ) );

		$this->assertNotWPError( $csv->start( $token, array( 0 => 'email' ), array( 'status' => 'subscribed' ), $this->actor ) );
		$result = $csv->run_batch( $token, $this->actor );

		$this->assertNotWPError( $result );
		$this->assertTrue( $result['completed'] );
		$this->assertNull( CsvStorage::read( $token, $this->actor ) );
	}

	/**
	 * Write a CSV fixture.
	 *
	 * @param array<int, array<int, mixed>> $rows Rows.
	 */
	private function write_csv( array $rows, string $delimiter = ',' ): string {
		$base = tempnam( sys_get_temp_dir(), 'nowcrm-csv-' );

		$this->assertIsString( $base );
		@unlink( $base );

		$path   = $base . '.csv';
		$handle = fopen( $path, 'wb' );

		$this->assertIsResource( $handle );

		foreach ( $rows as $row ) {
			fputcsv( $handle, $row, $delimiter, '"', '\\' );
		}

		fclose( $handle );
		$this->paths[] = $path;

		return $path;
	}

	/**
	 * Write a raw byte fixture.
	 */
	private function temp_file( string $contents ): string {
		$path = tempnam( sys_get_temp_dir(), 'nowcrm-csv-' );

		$this->assertIsString( $path );
		file_put_contents( $path, $contents );
		$this->paths[] = $path;

		return $path;
	}

	/**
	 * Build a mapping from header names.
	 *
	 * @param string[]             $headers Header row.
	 * @param array<string, mixed> $map     Header name => target.
	 * @return array<int, string>
	 */
	private function mapping( array $headers, array $map ): array {
		$mapping = array();

		foreach ( $headers as $index => $header ) {
			$mapping[ $index ] = (string) ( $map[ $header ] ?? '' );
		}

		return $mapping;
	}

	/**
	 * Stage and run a whole import.
	 *
	 * @param array<int, string>   $mapping Mapping.
	 * @param array<string, mixed> $options Options.
	 * @return array<string, mixed>
	 */
	private function run_import( string $path, array $mapping, array $options ): array {
		$csv   = new CsvImport();
		$state = $csv->prepare( $path, 'comma', 'import.csv', $this->actor );

		$this->assertNotWPError( $state );

		$token = (string) $state['token'];

		$started = $csv->start( $token, $mapping, $options, $this->actor );

		$this->assertNotWPError( $started );

		for ( $i = 0; $i < 100; $i++ ) {
			$result = $csv->run_batch( $token, $this->actor );

			$this->assertNotWPError( $result );

			if ( ! empty( $result['completed'] ) ) {
				return $result;
			}
		}

		$this->fail( 'Import did not finish.' );
	}
}
