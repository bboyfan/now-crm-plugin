<?php
/**
 * Schema migration tests.
 *
 * @package NowCRM
 */

use NowCRM\Support\Schema;

/**
 * @covers \NowCRM\Support\Schema
 */
final class SchemaTest extends NowCrmTestCase {

	public function test_tables_and_unique_keys_exist(): void {
		foreach ( Schema::TABLES as $table ) {
			$this->assertTrue(
				(bool) $this->table_exists( $table ),
				"Missing table: {$table}"
			);
		}

		$this->assertTrue( $this->index_is_unique( 'contacts', 'email_normalized' ) );
		$this->assertTrue( $this->index_is_unique( 'terms', 'type_slug' ) );
		$this->assertTrue( $this->index_is_unique( 'contact_terms', 'contact_term' ) );
		$this->assertTrue( $this->index_is_unique( 'contact_field_values', 'contact_field' ) );
		$this->assertTrue( $this->has_index( 'contact_field_values', 'field_key' ) );
		$this->assertTrue( $this->index_is_unique( 'order_links', 'order_id' ) );
		$this->assertTrue( $this->has_index( 'order_links', 'is_counted' ) );
		$this->assertTrue( $this->has_index( 'contact_status_events', 'contact_event_time' ) );
		$this->assertTrue( $this->has_index( 'contact_notes', 'contact_timeline' ) );
		$this->assertTrue( $this->index_is_unique( 'email_jobs', 'job_key' ) );
		$this->assertTrue( $this->has_index( 'email_jobs', 'report_delivery' ) );
		$this->assertTrue( $this->has_index( 'email_jobs', 'delivery_status' ) );
		$this->assertTrue( $this->has_index( 'email_jobs', 'source_key' ) );
		$this->assertTrue( $this->has_index( 'email_jobs', 'run_id' ) );
		$this->assertTrue( $this->has_index( 'email_events', 'report_engagement' ) );
		$this->assertTrue( $this->index_is_unique( 'carts', 'cart_cycle_key' ) );
		$this->assertTrue( $this->has_index( 'carts', 'status_activity' ) );
		$this->assertTrue( $this->has_index( 'carts', 'status_abandoned' ) );
		$this->assertTrue( $this->has_index( 'cart_events', 'event_type_time' ) );
		$this->assertTrue( $this->index_is_unique( 'automation_runs', 'automation_entry' ) );
		$this->assertTrue( $this->index_is_unique( 'automation_runs', 'source_identity' ) );
		$this->assertFalse( $this->has_index( 'automation_runs', 'automation_contact' ) );
		$this->assertTrue( $this->has_index( 'automation_runs', 'cart_id' ) );
		$this->assertTrue( $this->has_index( 'automation_runs', 'legacy_cart_source' ) );
		$this->assertTrue( $this->index_is_unique( 'ses_events', 'sns_message_id' ) );
		$this->assertTrue( $this->index_is_unique( 'unsubscribe_tokens', 'token_hash' ) );
		$this->assertTrue( $this->index_is_unique( 'suppressions', 'email_normalized' ) );
	}

	public function test_contacts_table_has_profile_columns(): void {
		global $wpdb;

		$table = Schema::table( 'contacts' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is trusted.
		$rows = $wpdb->get_results( "SHOW COLUMNS FROM {$table}", ARRAY_A );
		$map  = array_column( (array) $rows, null, 'Field' );

		foreach ( \NowCRM\Contacts\ProfileFields::keys() as $column ) {
			$this->assertArrayHasKey( $column, $map, "Missing contacts column: {$column}" );
		}

		$this->assertSame( 'lead', $map['contact_type']['Default'] );
		$this->assertSame( 'YES', $map['date_of_birth']['Null'] );
	}

	public function test_migration_is_idempotent_and_keeps_data(): void {
		$contact_id = $this->make_contact( array( 'email' => 'keep@example.test' ) );

		$this->assertSame( Schema::VERSION, get_option( Schema::VERSION_OPTION ) );

		Schema::install();
		Schema::install();

		$this->assertSame( Schema::VERSION, get_option( Schema::VERSION_OPTION ) );
		$this->assertSame( 1, $this->table_count( 'contacts' ) );
		$this->assertSame( $contact_id, (int) $this->contacts->get( $contact_id )['id'] );
	}

	public function test_maybe_upgrade_runs_once_and_reports_state(): void {
		$this->assertFalse( Schema::maybe_upgrade(), 'A current schema must not migrate again.' );

		delete_option( Schema::VERSION_OPTION );

		$this->assertTrue( Schema::maybe_upgrade() );
		$this->assertSame( Schema::VERSION, get_option( Schema::VERSION_OPTION ) );
	}

	public function test_run_source_and_cart_order_columns_exist(): void {
		$runs = $this->columns( 'automation_runs' );
		$jobs = $this->columns( 'email_jobs' );
		$carts = $this->columns( 'carts' );

		foreach ( array( 'entry_key', 'source_key', 'cart_id', 'cart_version' ) as $column ) {
			$this->assertArrayHasKey( $column, $runs, "Missing automation_runs column: {$column}" );
		}

		foreach ( array( 'source_key', 'run_id' ) as $column ) {
			$this->assertArrayHasKey( $column, $jobs, "Missing email_jobs column: {$column}" );
		}

		$this->assertArrayHasKey( 'activity_order_ms', $carts );
		$this->assertSame( '', $runs['entry_key']['Default'] );
		$this->assertSame( 'YES', $runs['source_key']['Null'] );
		$this->assertSame( 'YES', $runs['cart_id']['Null'] );
		$this->assertSame( 'YES', $runs['cart_version']['Null'] );
		$this->assertSame( 'YES', $jobs['source_key']['Null'] );
		$this->assertSame( 'YES', $jobs['run_id']['Null'] );
		$this->assertSame( '0', $carts['activity_order_ms']['Default'] );
	}

	public function test_email_jobs_store_provider_delivery_results(): void {
		$jobs = $this->columns( 'email_jobs' );

		foreach ( array( 'delivery_status', 'delivery_at', 'delivery_error', 'ses_correlation' ) as $column ) {
			$this->assertArrayHasKey( $column, $jobs, "Missing email_jobs column: {$column}" );
		}

		$this->assertSame( '', $jobs['delivery_status']['Default'] );
		$this->assertSame( 'NO', $jobs['delivery_status']['Null'] );
		$this->assertSame( 'YES', $jobs['delivery_at']['Null'] );
		$this->assertSame( 'YES', $jobs['delivery_error']['Null'] );
		$this->assertSame( 'YES', $jobs['ses_correlation']['Null'] );
		$this->assertTrue( $this->has_index( 'email_jobs', 'delivery_status' ) );
		$this->assertTrue( $this->has_index( 'email_jobs', 'ses_correlation' ) );
		$this->assertTrue( $this->has_index( 'email_jobs', 'recipient' ) );

		$events = $this->columns( 'ses_events' );

		foreach ( array( 'status', 'matched_recipients', 'processed' ) as $column ) {
			$this->assertArrayHasKey( $column, $events, "Missing ses_events column: {$column}" );
		}

		$this->assertSame( '', $events['status']['Default'] );
		$this->assertSame( '0', $events['matched_recipients']['Default'] );
		$this->assertTrue( $this->has_index( 'ses_events', 'status' ) );
		$this->assertTrue( $this->has_index( 'ses_events', 'received_at' ) );
	}

	/**
	 * @dataProvider legacy_schema_versions
	 *
	 * @param string $installed_version Stored schema version before the upgrade.
	 */
	public function test_legacy_run_unique_is_replaced_without_losing_rows( string $installed_version ): void {
		global $wpdb;

		$previous_prefix  = $wpdb->prefix;
		$previous_version = (string) get_option( Schema::VERSION_OPTION, '' );
		$prefix           = $previous_prefix . 'nowcrmlegacy' . strtolower( wp_generate_password( 6, false ) ) . '_';
		$runs             = $prefix . 'nowcrm_automation_runs';
		$events           = $prefix . 'nowcrm_automation_run_events';
		$jobs             = $prefix . 'nowcrm_email_jobs';
		$carts            = $prefix . 'nowcrm_carts';
		$now              = gmdate( 'Y-m-d H:i:s' );

		try {
			$wpdb->prefix = $prefix;

			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- fixture DDL uses a test-only prefix.
			$this->create_legacy_tables( $prefix, $wpdb->get_charset_collate(), $installed_version );

			$wpdb->insert(
				$runs,
				array(
					'automation_id'   => 77,
					'contact_id'      => 88,
					'status'          => 'active',
					'next_step_id'    => 5,
					'next_run_at'     => $now,
					'source_trigger'  => 'cart_abandoned',
					'source_ref'      => 'cart:55',
					'started_at'      => $now,
					'updated_at'      => $now,
				),
				array( '%d', '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s' )
			);
			$legacy_run_id = (int) $wpdb->insert_id;

			$wpdb->insert(
				$runs,
				array(
					'automation_id'  => 77,
					'contact_id'     => 89,
					'status'         => 'completed',
					'next_run_at'    => null,
					'source_trigger' => 'order_completed',
					'source_ref'     => 'order:900',
					'started_at'     => $now,
					'updated_at'     => $now,
				),
				array( '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
			);
			$normal_run_id = (int) $wpdb->insert_id;

			$wpdb->insert(
				$events,
				array(
					'run_id'      => $legacy_run_id,
					'step_id'     => 5,
					'contact_id'  => 88,
					'status'      => 'completed',
					'payload'     => wp_json_encode( array( 'job_id' => 0 ) ),
					'occurred_at' => $now,
				),
				array( '%d', '%d', '%d', '%s', '%s', '%s' )
			);
			$event_id = (int) $wpdb->insert_id;

			$wpdb->insert(
				$jobs,
				array(
					'job_key'        => 'cart:55:reminder:1',
					'kind'           => 'cart',
					'cart_id'        => 55,
					'contact_id'     => 88,
					'recipient'      => 'legacy@example.test',
					'recipient_name' => 'Legacy',
					'subject'        => 'Legacy cart',
					'payload'        => wp_json_encode( array( 'cart_version' => 2 ) ),
					'status'         => 'queued',
					'priority'       => 5,
					'available_at'   => $now,
					'created_at'     => $now,
					'updated_at'     => $now,
				),
				array( '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s' )
			);
			$job_id = (int) $wpdb->insert_id;

			$wpdb->insert(
				$carts,
				array(
					'id'                  => 55,
					'cart_cycle_key'      => 'legacy-cycle-55',
					'session_key'         => '',
					'email'               => 'legacy@example.test',
					'email_normalized'    => 'legacy@example.test',
					'status'              => 'abandoned',
					'cart_snapshot'       => '[]',
					'cart_hash'           => 'legacy-hash',
					'item_count'          => 1,
					'total'               => 100,
					'currency'            => 'TWD',
					'version'             => 2,
					'last_activity_at'    => $now,
					'abandoned_at'        => $now,
					'created_at'          => $now,
					'updated_at'          => $now,
				),
				array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%d', '%s', '%s', '%s', '%s' )
			);

			update_option( Schema::VERSION_OPTION, $installed_version );
			Schema::install();

			$this->assertSame( Schema::VERSION, get_option( Schema::VERSION_OPTION ) );
			$this->assertTrue( $this->has_index( 'email_jobs', 'report_delivery' ) );
			$this->assertTrue( $this->has_index( 'email_jobs', 'delivery_status' ) );
			$this->assertTrue( $this->has_index( 'email_jobs', 'recipient' ) );

			$upgraded_jobs = $this->columns( 'email_jobs' );
			$this->assertArrayHasKey( 'delivery_status', $upgraded_jobs );
			$this->assertArrayHasKey( 'delivery_at', $upgraded_jobs );
			$this->assertArrayHasKey( 'delivery_error', $upgraded_jobs );
			$this->assertArrayHasKey( 'ses_correlation', $upgraded_jobs );

			$upgraded_events = $this->columns( 'ses_events' );
			$this->assertArrayHasKey( 'status', $upgraded_events );
			$this->assertArrayHasKey( 'matched_recipients', $upgraded_events );
			$this->assertArrayHasKey( 'processed', $upgraded_events );
			$this->assertTrue( $this->index_is_unique( 'automation_runs', 'automation_entry' ) );
			$this->assertTrue( $this->index_is_unique( 'automation_runs', 'source_identity' ) );
			$this->assertFalse( $this->has_index( 'automation_runs', 'automation_contact' ) );
			$this->assertTrue( $this->has_index( 'automation_runs', 'cart_id' ) );
			$this->assertTrue( $this->has_index( 'automation_runs', 'legacy_cart_source' ) );
			$this->assertTrue( $this->has_index( 'carts', 'status_activity' ) );
			$this->assertTrue( $this->has_index( 'carts', 'status_abandoned' ) );
			$this->assertTrue( $this->has_index( 'cart_events', 'event_type_time' ) );

			$run = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$runs} WHERE id = %d", $legacy_run_id ), ARRAY_A );

			$this->assertSame( 88, (int) $run['contact_id'] );
			$this->assertSame( 'cart:55', $run['source_ref'] );
			$this->assertSame( 'legacy-cart:' . $legacy_run_id, $run['entry_key'] );
			$this->assertNull( $run['source_key'] );
			$this->assertNull( $run['cart_id'] );
			$this->assertNull( $run['cart_version'] );

			$this->assertSame(
				1,
				(int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$events} WHERE id = %d", $event_id ) )
			);

			$job = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$jobs} WHERE id = %d", $job_id ), ARRAY_A );

			$this->assertSame( 'cart:55:reminder:1', $job['job_key'] );
			$this->assertNull( $job['source_key'] );
			$this->assertNull( $job['run_id'] );

			$cart = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$carts} WHERE id = %d", 55 ), ARRAY_A );

			$this->assertSame( 0, (int) $cart['activity_order_ms'] );

			$repo = new \NowCRM\Automation\RunRepository();

			$this->assertSame( $normal_run_id, (int) $repo->find( 77, 89 )['id'] );
			$this->assertNull( $repo->find( 77, 88 ), 'A legacy cart run must not become the ordinary contact run.' );

			$inserted = $wpdb->insert(
				$runs,
				array(
					'automation_id'  => 77,
					'contact_id'     => 88,
					'status'         => 'active',
					'next_step_id'   => 6,
					'next_run_at'    => $now,
					'source_trigger' => 'cart_abandoned',
					'source_ref'     => 'cart:55:2',
					'entry_key'      => 'cart:55:2',
					'source_key'     => 'cart:55:2',
					'cart_id'        => 55,
					'cart_version'   => 2,
					'started_at'     => $now,
					'updated_at'     => $now,
				),
				array( '%d', '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s' )
			);

			$this->assertSame( 1, $inserted );
			$second_run_id = (int) $wpdb->insert_id;

			$duplicate = $wpdb->insert(
				$runs,
				array(
					'automation_id'  => 77,
					'contact_id'     => 88,
					'status'         => 'active',
					'next_run_at'    => $now,
					'source_trigger' => 'cart_abandoned',
					'source_ref'     => 'cart:55:2',
					'entry_key'      => 'cart:55:2',
					'source_key'     => 'cart:55:2',
					'cart_id'        => 55,
					'cart_version'   => 2,
					'started_at'     => $now,
					'updated_at'     => $now,
				),
				array( '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s' )
			);

			$this->assertFalse( $duplicate, 'The source identity must stay unique.' );

			$duplicate_source = $wpdb->insert(
				$runs,
				array(
					'automation_id'  => 77,
					'contact_id'     => 88,
					'status'         => 'active',
					'next_run_at'    => $now,
					'source_trigger' => 'cart_abandoned',
					'source_ref'     => 'cart:55:2',
					'entry_key'      => 'cart:55:2-other',
					'source_key'     => 'cart:55:2',
					'cart_id'        => 55,
					'cart_version'   => 2,
					'started_at'     => $now,
					'updated_at'     => $now,
				),
				array( '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s' )
			);

			$this->assertFalse( $duplicate_source, 'One cart source must map to one run.' );

			$normal = $wpdb->insert(
				$runs,
				array(
					'automation_id'  => 78,
					'contact_id'     => 99,
					'status'         => 'active',
					'next_run_at'    => $now,
					'source_trigger' => 'order_completed',
					'entry_key'      => '',
					'started_at'     => $now,
					'updated_at'     => $now,
				),
				array( '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
			);

			$this->assertSame( 1, $normal );

			$duplicate_normal = $wpdb->insert(
				$runs,
				array(
					'automation_id'  => 78,
					'contact_id'     => 99,
					'status'         => 'active',
					'next_run_at'    => $now,
					'source_trigger' => 'order_completed',
					'entry_key'      => '',
					'started_at'     => $now,
					'updated_at'     => $now,
				),
				array( '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
			);

			$this->assertFalse( $duplicate_normal, 'The ordinary contact entry key must stay unique.' );

			$coexist = $wpdb->insert(
				$runs,
				array(
					'automation_id'  => 77,
					'contact_id'     => 88,
					'status'         => 'active',
					'next_run_at'    => $now,
					'source_trigger' => 'order_completed',
					'entry_key'      => '',
					'started_at'     => $now,
					'updated_at'     => $now,
				),
				array( '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
			);

			$this->assertSame( 1, $coexist, 'A converted legacy cart row must not occupy the ordinary entry key.' );
			$ordinary_run_id = (int) $wpdb->insert_id;
			$this->assertSame( $ordinary_run_id, (int) $repo->find( 77, 88 )['id'] );

			$this->assertSame( $second_run_id, (int) $repo->find_by_source_key( 'cart:55:2' )['id'] );
			$this->assertNull( $repo->find_by_source_key( 'cart:55' ), 'Legacy rows keep no guessed source key.' );

			$cart_runs = array_map(
				static fn( array $row ): int => (int) $row['id'],
				$repo->for_cart( 55, 20 )
			);

			$this->assertSame( array( $second_run_id, $legacy_run_id ), $cart_runs );

			$before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$runs}" );
			Schema::install();

			$this->assertSame( $before, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$runs}" ) );
			$this->assertTrue( $this->index_is_unique( 'automation_runs', 'automation_entry' ) );
			$this->assertFalse( $this->has_index( 'automation_runs', 'automation_contact' ) );
		} finally {
			$wpdb->prefix = $previous_prefix;

			foreach ( Schema::TABLES as $table ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- fixture cleanup uses the recorded prefix.
				$wpdb->query( 'DROP TABLE IF EXISTS ' . $prefix . 'nowcrm_' . $table );
			}

			if ( '' === $previous_version ) {
				delete_option( Schema::VERSION_OPTION );
			} else {
				update_option( Schema::VERSION_OPTION, $previous_version, false );
			}
		}
	}

	/**
	 * Columns of one plugin table as field => metadata.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function columns( string $table ): array {
		global $wpdb;

		$name = Schema::table( $table );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is trusted.
		$rows = $wpdb->get_results( "SHOW COLUMNS FROM {$name}", ARRAY_A );

		return array_column( (array) $rows, null, 'Field' );
	}

	/**
	 * Stored versions that predate the cart source identity columns.
	 *
	 * @return array<string, array{0:string}>
	 */
	public function legacy_schema_versions(): array {
		return array(
			'2.4.0' => array( '2.4.0' ),
			'2.5.0' => array( '2.5.0' ),
		);
	}

	/**
	 * Create the pre-source shapes of the tables touched by the migration.
	 */
	private function create_legacy_tables( string $prefix, string $charset, string $version ): void {
		global $wpdb;

		$runs  = $prefix . 'nowcrm_automation_runs';
		$events = $prefix . 'nowcrm_automation_run_events';
		$jobs  = $prefix . 'nowcrm_email_jobs';
		$carts = $prefix . 'nowcrm_carts';

		$report_delivery = version_compare( $version, '2.5.0', '>=' )
			? ",\n\t\t\t\tKEY report_delivery (kind,status,accepted_at,campaign_id)"
			: '';

		$wpdb->query(
			"CREATE TABLE {$runs} (
				id bigint(20) unsigned NOT NULL auto_increment,
				automation_id bigint(20) unsigned NOT NULL,
				contact_id bigint(20) unsigned NOT NULL,
				status varchar(20) NOT NULL default 'active',
				last_completed_step_id bigint(20) unsigned NULL default NULL,
				next_step_id bigint(20) unsigned NULL default NULL,
				next_run_at datetime NULL default NULL,
				source_trigger varchar(64) NOT NULL default '',
				source_ref varchar(191) NOT NULL default '',
				last_error text NULL,
				started_at datetime NOT NULL,
				finished_at datetime NULL default NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY automation_contact (automation_id,contact_id),
				KEY status_due (status,next_run_at),
				KEY contact_id (contact_id)
			) {$charset};"
		);

		$wpdb->query(
			"CREATE TABLE {$events} (
				id bigint(20) unsigned NOT NULL auto_increment,
				run_id bigint(20) unsigned NOT NULL,
				step_id bigint(20) unsigned NOT NULL,
				contact_id bigint(20) unsigned NOT NULL,
				status varchar(20) NOT NULL default 'completed',
				value_cents bigint(20) NULL default NULL,
				currency char(3) NOT NULL default '',
				payload longtext NULL,
				occurred_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY run_step (run_id,step_id),
				KEY step_id (step_id),
				KEY contact_id (contact_id),
				KEY occurred_at (occurred_at)
			) {$charset};"
		);

		$wpdb->query(
			"CREATE TABLE {$jobs} (
				id bigint(20) unsigned NOT NULL auto_increment,
				job_key varchar(191) NOT NULL,
				kind varchar(30) NOT NULL default 'campaign',
				campaign_id bigint(20) unsigned NULL default NULL,
				cart_id bigint(20) unsigned NULL default NULL,
				contact_id bigint(20) unsigned NULL default NULL,
				recipient varchar(191) NOT NULL,
				recipient_name varchar(191) NOT NULL default '',
				subject varchar(255) NOT NULL default '',
				payload longtext NULL,
				status varchar(20) NOT NULL default 'queued',
				priority smallint(6) NOT NULL default 10,
				available_at datetime NOT NULL,
				attempts smallint(6) unsigned NOT NULL default 0,
				max_attempts smallint(6) unsigned NOT NULL default 3,
				claim_token varchar(64) NULL default NULL,
				claimed_at datetime NULL default NULL,
				lease_expires_at datetime NULL default NULL,
				last_error text NULL,
				message_id varchar(191) NULL default NULL,
				accepted_at datetime NULL default NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY job_key (job_key),
				KEY status_due (status,available_at),
				KEY campaign_status (campaign_id,status),
				KEY contact_id (contact_id),
				KEY cart_id (cart_id){$report_delivery}
			) {$charset};"
		);

		$wpdb->query(
			"CREATE TABLE {$carts} (
				id bigint(20) unsigned NOT NULL auto_increment,
				cart_cycle_key varchar(64) NOT NULL,
				session_key varchar(191) NOT NULL default '',
				user_id bigint(20) unsigned NULL default NULL,
				email varchar(191) NOT NULL default '',
				email_normalized varchar(191) NOT NULL default '',
				status varchar(20) NOT NULL default 'active',
				cart_snapshot longtext NULL,
				cart_hash char(64) NOT NULL default '',
				item_count int(10) unsigned NOT NULL default 0,
				total decimal(18,4) NOT NULL default 0,
				currency char(3) NOT NULL default '',
				version int(10) unsigned NOT NULL default 1,
				last_activity_at datetime NOT NULL,
				abandoned_at datetime NULL default NULL,
				reminder_due_at datetime NULL default NULL,
				reminders_sent tinyint(3) unsigned NOT NULL default 0,
				last_reminder_at datetime NULL default NULL,
				recovered_order_id bigint(20) unsigned NULL default NULL,
				recovery_source varchar(20) NULL default NULL,
				lost_at datetime NULL default NULL,
				recovery_token_hash char(64) NULL default NULL,
				recovery_token_expires datetime NULL default NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY cart_cycle_key (cart_cycle_key),
				KEY status_due (status,reminder_due_at),
				KEY email_normalized (email_normalized),
				KEY last_activity_at (last_activity_at)
			) {$charset};"
		);
	}

	/**
	 * Whether a plugin table is present.
	 */
	private function table_exists( string $table ): bool {
		global $wpdb;

		$name = Schema::table( $table );

		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $name ) ) ) === $name;
	}
}
