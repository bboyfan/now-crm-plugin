<?php
/**
 * FluentCRM read-only import against anonymous fixtures.
 *
 * @package NowCRM
 */

use NowCRM\Contacts\Status;
use NowCRM\FluentCRM\Adapter;
use NowCRM\FluentCRM\Importer;
use NowCRM\Terms\TermRepository;

/**
 * @covers \NowCRM\FluentCRM\Adapter
 * @covers \NowCRM\FluentCRM\Importer
 */
final class FluentCrmImportTest extends NowCrmTestCase {

	public function set_up(): void {
		parent::set_up();
		$this->create_fixture_tables();
	}

	public function tear_down(): void {
		global $wpdb;

		foreach ( array( 'fc_subscribers', 'fc_lists', 'fc_tags', 'fc_subscriber_pivot', 'fc_campaigns', 'fc_campaign_emails' ) as $table ) {
			$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . $table ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- fixture cleanup.
		}

		parent::tear_down();
	}

	/**
	 * Minimal FluentCRM 3.x schema.
	 */
	private function create_fixture_tables(): void {
		global $wpdb;

		$charset = $wpdb->get_charset_collate();
		$p       = $wpdb->prefix;

		$wpdb->query( "DROP TABLE IF EXISTS {$p}fc_subscribers" );
		$wpdb->query( "DROP TABLE IF EXISTS {$p}fc_lists" );
		$wpdb->query( "DROP TABLE IF EXISTS {$p}fc_tags" );
		$wpdb->query( "DROP TABLE IF EXISTS {$p}fc_subscriber_pivot" );
		$wpdb->query( "DROP TABLE IF EXISTS {$p}fc_campaigns" );
		$wpdb->query( "DROP TABLE IF EXISTS {$p}fc_campaign_emails" );

		$wpdb->query(
			"CREATE TABLE {$p}fc_subscribers (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				user_id BIGINT UNSIGNED NULL,
				first_name VARCHAR(192) NULL,
				last_name VARCHAR(192) NULL,
				email VARCHAR(190) NOT NULL,
				phone VARCHAR(50) NULL,
				status VARCHAR(50) NOT NULL DEFAULT 'subscribed',
				source VARCHAR(50) NULL,
				created_at TIMESTAMP NULL,
				updated_at TIMESTAMP NULL,
				PRIMARY KEY (id),
				UNIQUE KEY email (email)
			) {$charset}"
		);

		$wpdb->query( "CREATE TABLE {$p}fc_lists (id INT UNSIGNED NOT NULL AUTO_INCREMENT, title VARCHAR(192) NOT NULL, slug VARCHAR(192) NOT NULL, PRIMARY KEY (id)) {$charset}" );
		$wpdb->query( "CREATE TABLE {$p}fc_tags (id INT UNSIGNED NOT NULL AUTO_INCREMENT, title VARCHAR(192) NOT NULL, slug VARCHAR(192) NOT NULL, PRIMARY KEY (id)) {$charset}" );

		$wpdb->query(
			"CREATE TABLE {$p}fc_subscriber_pivot (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				subscriber_id BIGINT UNSIGNED NOT NULL,
				object_id BIGINT UNSIGNED NOT NULL,
				object_type VARCHAR(50) NOT NULL,
				status VARCHAR(50) NULL,
				PRIMARY KEY (id),
				UNIQUE KEY subscriber_object (subscriber_id,object_id,object_type)
			) {$charset}"
		);

		$wpdb->query(
			"CREATE TABLE {$p}fc_campaigns (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				type VARCHAR(50) NOT NULL DEFAULT 'campaign',
				title VARCHAR(192) NOT NULL,
				status VARCHAR(50) NOT NULL DEFAULT 'sent',
				email_subject VARCHAR(192) NULL,
				email_body LONGTEXT NULL,
				recipients_count INT NOT NULL DEFAULT 0,
				created_at TIMESTAMP NULL,
				PRIMARY KEY (id)
			) {$charset}"
		);

		$wpdb->query(
			"CREATE TABLE {$p}fc_campaign_emails (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				campaign_id BIGINT UNSIGNED NULL,
				status VARCHAR(50) NOT NULL DEFAULT 'pending',
				is_open TINYINT(1) NOT NULL DEFAULT 0,
				click_counter INT NULL,
				PRIMARY KEY (id)
			) {$charset}"
		);
	}

	/**
	 * Insert a subscriber and optional relations.
	 *
	 * @param array<string, mixed> $data email, status, first_name, last_name.
	 * @param int[]                $lists List IDs.
	 * @param int[]                $tags  Tag IDs.
	 */
	private function subscriber( array $data, array $lists = array(), array $tags = array() ): int {
		global $wpdb;

		$wpdb->insert(
			$wpdb->prefix . 'fc_subscribers',
			array(
				'email'      => $data['email'],
				'status'     => $data['status'] ?? 'subscribed',
				'first_name' => $data['first_name'] ?? 'Remote',
				'last_name'  => $data['last_name'] ?? 'Person',
				'phone'      => $data['phone'] ?? '',
				'user_id'    => $data['user_id'] ?? null,
				'source'     => 'fixture',
				'created_at' => gmdate( 'Y-m-d H:i:s' ),
			)
		);

		$id = (int) $wpdb->insert_id;

		foreach ( $lists as $list_id ) {
			$wpdb->insert(
				$wpdb->prefix . 'fc_subscriber_pivot',
				array(
					'subscriber_id' => $id,
					'object_id'     => $list_id,
					'object_type'   => 'FluentCrm\\App\\Models\\Lists',
				)
			);
		}

		foreach ( $tags as $tag_id ) {
			$wpdb->insert(
				$wpdb->prefix . 'fc_subscriber_pivot',
				array(
					'subscriber_id' => $id,
					'object_id'     => $tag_id,
					'object_type'   => 'FluentCrm\\App\\Models\\Tags',
				)
			);
		}

		return $id;
	}

	public function test_adapter_detects_subscribers_and_batches(): void {
		$this->subscriber( array( 'email' => 'adapter@example.test' ) );

		$adapter = new Adapter();

		$this->assertTrue( $adapter->available() );
		$this->assertSame( 1, $adapter->contact_count() );

		$rows = $adapter->contacts_batch( 0, 10 );

		$this->assertCount( 1, $rows );
		$this->assertSame( 'adapter@example.test', $rows[0]['email'] );
	}

	public function test_dry_run_writes_no_contacts(): void {
		$list_id = 1;
		global $wpdb;

		$wpdb->insert( $wpdb->prefix . 'fc_lists', array( 'title' => 'Newsletter', 'slug' => 'newsletter' ) );
		$this->subscriber( array( 'email' => 'dry@example.test' ), array( $list_id ) );

		$report = ( new Importer() )->run( array( 'mode' => 'dry_run' ) );

		$this->assertSame( 'dry_run', $report['mode'] );
		$this->assertSame( 1, $report['created'] );
		$this->assertSame( 0, $this->table_count( 'contacts' ) );
		$this->assertSame( 0, $this->table_count( 'terms' ) );
	}

	public function test_apply_imports_statuses_terms_and_is_idempotent(): void {
		global $wpdb;

		$wpdb->insert( $wpdb->prefix . 'fc_lists', array( 'title' => 'Newsletter', 'slug' => 'newsletter' ) );
		$wpdb->insert( $wpdb->prefix . 'fc_tags', array( 'title' => 'VIP', 'slug' => 'vip' ) );

		$this->subscriber( array( 'email' => 'subscribed@example.test' ), array( 1 ), array( 1 ) );
		$this->subscriber( array( 'email' => 'pending@example.test', 'status' => 'pending' ) );
		$this->subscriber( array( 'email' => 'spam@example.test', 'status' => 'spammed' ) );

		$report = ( new Importer() )->run( array( 'mode' => 'apply', 'batch' => 2 ) );

		$this->assertSame( 3, $report['created'] );
		$this->assertSame( Status::SUBSCRIBED, $this->contacts->find_by_email( 'subscribed@example.test' )['status'] );
		$this->assertSame( Status::TRANSACTIONAL, $this->contacts->find_by_email( 'pending@example.test' )['status'] );
		$this->assertSame( Status::COMPLAINED, $this->contacts->find_by_email( 'spam@example.test' )['status'] );

		$contact = $this->contacts->find_by_email( 'subscribed@example.test' );
		$terms   = $this->terms->for_contact( (int) $contact['id'] );
		$names   = array_column( $terms, 'name' );

		$this->assertContains( 'Newsletter', $names );
		$this->assertContains( 'VIP', $names );

		$second = ( new Importer() )->run( array( 'mode' => 'apply' ) );

		$this->assertSame( 0, $second['created'] );
		$this->assertSame( 3, $this->table_count( 'contacts' ) );
	}

	public function test_existing_suppressed_status_is_not_loosened(): void {
		$this->make_contact( array( 'email' => 'stay-off@example.test', 'status' => Status::UNSUBSCRIBED ) );
		$this->subscriber( array( 'email' => 'stay-off@example.test', 'status' => 'subscribed' ) );

		( new Importer() )->run( array( 'mode' => 'apply' ) );

		$this->assertSame( Status::UNSUBSCRIBED, $this->contacts->find_by_email( 'stay-off@example.test' )['status'] );
	}

	public function test_resume_continues_from_the_saved_cursor(): void {
		global $wpdb;

		$this->subscriber( array( 'email' => 'one@example.test' ) );
		$this->subscriber( array( 'email' => 'two@example.test' ) );
		$this->subscriber( array( 'email' => 'three@example.test' ) );

		$wpdb->insert(
			\NowCRM\Support\Schema::table( 'import_runs' ),
			array(
				'source'       => 'fluentcrm',
				'mode'         => 'apply',
				'status'       => 'running',
				'cursor_value' => '2',
				'started_at'   => gmdate( 'Y-m-d H:i:s' ),
			)
		);

		$report = ( new Importer() )->run( array( 'mode' => 'apply', 'resume' => true ) );

		$this->assertSame( 1, $report['created'] );
		$this->assertNotNull( $this->contacts->find_by_email( 'three@example.test' ) );
		$this->assertNull( $this->contacts->find_by_email( 'one@example.test' ) );
	}

	public function test_campaign_archive_copies_content_and_engagement(): void {
		global $wpdb;

		$wpdb->insert(
			$wpdb->prefix . 'fc_campaigns',
			array(
				'title'            => 'Legacy blast',
				'status'           => 'sent',
				'email_subject'    => 'Legacy subject',
				'email_body'       => '<p>Legacy body</p>',
				'recipients_count' => 3,
				'created_at'       => gmdate( 'Y-m-d H:i:s' ),
			)
		);

		foreach ( array( array( 'sent', 1, 2 ), array( 'sent', 0, 0 ), array( 'sent', 1, 5 ) ) as $row ) {
			$wpdb->insert(
				$wpdb->prefix . 'fc_campaign_emails',
				array(
					'campaign_id'   => 1,
					'status'        => $row[0],
					'is_open'       => $row[1],
					'click_counter' => $row[2],
				)
			);
		}

		$report = ( new Importer() )->run( array( 'mode' => 'apply', 'campaigns' => true ) );

		$this->assertSame( 1, $report['campaigns'] );

		$legacy = $wpdb->get_row( 'SELECT * FROM ' . \NowCRM\Support\Schema::table( 'legacy_campaigns' ) . ' WHERE remote_id = 1', ARRAY_A );

		$this->assertSame( 'Legacy subject', $legacy['subject'] );
		$this->assertStringContainsString( 'Legacy body', $legacy['content'] );
		$this->assertSame( 3, (int) $legacy['recipients_count'] );
		$this->assertSame( 2, (int) $legacy['open_count'] );
		$this->assertSame( 2, (int) $legacy['click_count'] );

		( new Importer() )->run( array( 'mode' => 'apply', 'campaigns' => true ) );

		$this->assertSame( 1, $this->table_count( 'legacy_campaigns' ) );
	}

	public function test_bounded_batch_keeps_the_run_open_until_the_cursor_is_exhausted(): void {
		$this->subscriber( array( 'email' => 'first@example.test' ) );
		$this->subscriber( array( 'email' => 'second@example.test' ) );

		$importer = new Importer();

		$first = $importer->run( array( 'mode' => 'apply', 'batch' => 1, 'limit' => 1 ) );

		$this->assertSame( 1, $first['processed'] );
		$this->assertFalse( $first['complete'] );

		$runs = $importer->runs( 1 );
		$this->assertSame( 'running', $runs[0]['status'] );

		$second = $importer->run( array( 'mode' => 'apply', 'batch' => 1, 'limit' => 1, 'resume' => true ) );

		$this->assertSame( 1, $second['processed'] );
		$this->assertFalse( $second['complete'] );
		$this->assertSame( (int) $first['run_id'], (int) $second['run_id'] );

		$last = $importer->run( array( 'mode' => 'apply', 'batch' => 1, 'limit' => 1, 'resume' => true ) );

		$this->assertTrue( $last['complete'] );
		$this->assertSame( 2, $last['created'] );
		$this->assertSame( 2, $this->contacts->count_by_status()['subscribed'] );
	}
}
