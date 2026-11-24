<?php
/**
 * Database schema: table names and idempotent migrations.
 *
 * @package NowCRM
 */

namespace NowCRM\Support;

defined( 'ABSPATH' ) || exit;

final class Schema {

	public const VERSION_OPTION = 'nowcrm_schema_version';
	public const VERSION        = '2.8.0';

	/**
	 * Every table owned by the plugin, keyed by short name.
	 *
	 * @var string[]
	 */
	public const TABLES = array(
		'contacts',
		'terms',
		'contact_terms',
		'contact_field_values',
		'order_links',
		'contact_status_events',
		'contact_notes',
		'companies',
		'company_contacts',
		'email_jobs',
		'email_events',
		'email_templates',
		'suppressions',
		'unsubscribe_tokens',
		'carts',
		'cart_events',
		'ses_events',
		'import_runs',
		'legacy_campaigns',
		'automations',
		'automation_steps',
		'automation_runs',
		'automation_run_events',
		'sequence_trackers',
	);

	/**
	 * Fully qualified table name for a logical table.
	 *
	 * @param string $name One of self::TABLES.
	 */
	public static function table( string $name ): string {
		global $wpdb;

		return $wpdb->prefix . 'nowcrm_' . $name;
	}

	/**
	 * Run the migration when the stored version is behind.
	 *
	 * Normal requests only compare the stored version; table integrity is
	 * checked during activation, upgrades, and the explicit repair action.
	 *
	 * @return bool Whether a migration ran.
	 */
	public static function maybe_upgrade(): bool {
		if ( ! self::is_supported_site() ) {
			return false;
		}

		$installed = (string) get_option( self::VERSION_OPTION, '' );

		if ( version_compare( $installed, self::VERSION, '>=' ) ) {
			return false;
		}

		self::install();

		return version_compare( (string) get_option( self::VERSION_OPTION, '' ), self::VERSION, '>=' );
	}

	/**
	 * Create or update every table. Safe to call repeatedly; never drops data.
	 */
	public static function install(): void {
		if ( ! self::acquire_migration_lock() ) {
			return;
		}

		try {
			self::install_tables();
		} finally {
			self::release_migration_lock();
		}
	}

	/**
	 * Create or update every table and finish the versioned migration.
	 */
	private static function install_tables(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();

		$contacts = self::table( 'contacts' );
		$terms    = self::table( 'terms' );
		$links    = self::table( 'contact_terms' );
		$values   = self::table( 'contact_field_values' );
		$orders   = self::table( 'order_links' );
		$events   = self::table( 'contact_status_events' );
		$notes         = self::table( 'contact_notes' );
		$companies     = self::table( 'companies' );
		$company_links = self::table( 'company_contacts' );

		$jobs       = self::table( 'email_jobs' );
		$mail_events = self::table( 'email_events' );
		$templates  = self::table( 'email_templates' );
		$suppress   = self::table( 'suppressions' );
		$tokens     = self::table( 'unsubscribe_tokens' );
		$carts      = self::table( 'carts' );
		$cart_events = self::table( 'cart_events' );
		$ses_events = self::table( 'ses_events' );
		$imports    = self::table( 'import_runs' );
		$legacy     = self::table( 'legacy_campaigns' );

		$automations = self::table( 'automations' );
		$auto_steps  = self::table( 'automation_steps' );
		$auto_runs   = self::table( 'automation_runs' );
		$auto_events = self::table( 'automation_run_events' );

		$sequence_trackers = self::table( 'sequence_trackers' );

		$sql = array();

		$sql[] = "CREATE TABLE {$contacts} (
			id bigint(20) unsigned NOT NULL auto_increment,
			email varchar(191) NOT NULL,
			email_normalized varchar(191) NOT NULL,
			first_name varchar(100) NOT NULL default '',
			last_name varchar(100) NOT NULL default '',
			phone varchar(40) NOT NULL default '',
			prefix varchar(20) NOT NULL default '',
			contact_type varchar(20) NOT NULL default 'lead',
			date_of_birth date NULL default NULL,
			timezone varchar(64) NOT NULL default '',
			address_line_1 varchar(191) NOT NULL default '',
			address_line_2 varchar(191) NOT NULL default '',
			city varchar(100) NOT NULL default '',
			state varchar(100) NOT NULL default '',
			postal_code varchar(20) NOT NULL default '',
			country varchar(2) NOT NULL default '',
			wp_user_id bigint(20) unsigned NULL default NULL,
			status varchar(20) NOT NULL default 'subscribed',
			status_changed_at datetime NULL default NULL,
			source varchar(50) NOT NULL default 'manual',
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY email_normalized (email_normalized),
			KEY status (status),
			KEY wp_user_id (wp_user_id)
		) {$charset};";

		$sql[] = "CREATE TABLE {$terms} (
			id bigint(20) unsigned NOT NULL auto_increment,
			type varchar(20) NOT NULL,
			name varchar(191) NOT NULL,
			slug varchar(191) NOT NULL,
			description text NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY type_slug (type,slug),
			KEY type (type)
		) {$charset};";

		$sql[] = "CREATE TABLE {$links} (
			id bigint(20) unsigned NOT NULL auto_increment,
			contact_id bigint(20) unsigned NOT NULL,
			term_id bigint(20) unsigned NOT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY contact_term (contact_id,term_id),
			KEY contact_id (contact_id),
			KEY term_id (term_id)
		) {$charset};";

		$sql[] = "CREATE TABLE {$values} (
			id bigint(20) unsigned NOT NULL auto_increment,
			contact_id bigint(20) unsigned NOT NULL,
			field_key varchar(64) NOT NULL,
			value longtext NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY contact_field (contact_id,field_key),
			KEY field_key (field_key)
		) {$charset};";

		$sql[] = "CREATE TABLE {$orders} (
			id bigint(20) unsigned NOT NULL auto_increment,
			order_id bigint(20) unsigned NOT NULL,
			contact_id bigint(20) unsigned NOT NULL,
			billing_email varchar(191) NOT NULL default '',
			status varchar(50) NOT NULL default '',
			total decimal(18,4) NOT NULL default 0,
			net_total decimal(18,4) NOT NULL default 0,
			currency char(3) NOT NULL default '',
			is_counted tinyint(1) NOT NULL default 0,
			marketing_optin tinyint(1) NULL default NULL,
			date_created datetime NULL default NULL,
			date_paid datetime NULL default NULL,
			date_completed datetime NULL default NULL,
			synced_at datetime NOT NULL,
			sync_version int(10) unsigned NOT NULL default 1,
			PRIMARY KEY  (id),
			UNIQUE KEY order_id (order_id),
			KEY contact_id (contact_id),
			KEY is_counted (is_counted),
			KEY date_created (date_created)
		) {$charset};";

		$sql[] = "CREATE TABLE {$events} (
			id bigint(20) unsigned NOT NULL auto_increment,
			contact_id bigint(20) unsigned NOT NULL,
			old_status varchar(20) NULL default NULL,
			new_status varchar(20) NOT NULL,
			source varchar(191) NOT NULL,
			note text NULL,
			actor_id bigint(20) unsigned NULL default NULL,
			occurred_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY contact_id (contact_id),
			KEY contact_event_time (contact_id,occurred_at)
		) {$charset};";

		$sql[] = "CREATE TABLE {$notes} (
			id bigint(20) unsigned NOT NULL auto_increment,
			contact_id bigint(20) unsigned NOT NULL,
			author_id bigint(20) unsigned NULL default NULL,
			kind varchar(30) NOT NULL default 'note',
			title varchar(255) NOT NULL default '',
			body longtext NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY contact_timeline (contact_id,created_at)
		) {$charset};";

		$sql[] = "CREATE TABLE {$templates} (
			id bigint(20) unsigned NOT NULL auto_increment,
			kind varchar(30) NOT NULL default 'custom',
			name varchar(191) NOT NULL,
			subject varchar(255) NOT NULL default '',
			preheader varchar(255) NOT NULL default '',
			mode varchar(20) NOT NULL default 'html',
			content longtext NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY kind (kind)
		) {$charset};";

		$sql[] = "CREATE TABLE {$jobs} (
			id bigint(20) unsigned NOT NULL auto_increment,
			job_key varchar(191) NOT NULL,
			kind varchar(30) NOT NULL default 'campaign',
			campaign_id bigint(20) unsigned NULL default NULL,
			cart_id bigint(20) unsigned NULL default NULL,
			contact_id bigint(20) unsigned NULL default NULL,
			run_id bigint(20) unsigned NULL default NULL,
			source_key varchar(191) NULL default NULL,
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
			ses_correlation varchar(100) NULL default NULL,
			accepted_at datetime NULL default NULL,
			delivery_status varchar(20) NOT NULL default '',
			delivery_at datetime NULL default NULL,
			delivery_error text NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY job_key (job_key),
			KEY status_due (status,available_at),
			KEY campaign_status (campaign_id,status),
			KEY contact_id (contact_id),
			KEY recipient (recipient),
			KEY cart_id (cart_id),
			KEY run_id (run_id),
			KEY source_key (source_key),
			KEY report_delivery (kind,status,accepted_at,campaign_id),
			KEY delivery_status (delivery_status,delivery_at),
			KEY ses_correlation (ses_correlation)
		) {$charset};";

		$sql[] = "CREATE TABLE {$mail_events} (
			id bigint(20) unsigned NOT NULL auto_increment,
			event_key varchar(191) NOT NULL,
			type varchar(30) NOT NULL,
			campaign_id bigint(20) unsigned NULL default NULL,
			job_id bigint(20) unsigned NULL default NULL,
			contact_id bigint(20) unsigned NULL default NULL,
			url varchar(1000) NOT NULL default '',
			user_agent varchar(255) NOT NULL default '',
			ip_hash char(64) NOT NULL default '',
			meta text NULL,
			occurred_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY event_key (event_key),
			KEY campaign_type (campaign_id,type),
			KEY contact_id (contact_id),
			KEY job_id (job_id),
			KEY report_engagement (occurred_at,campaign_id,type,contact_id,job_id)
		) {$charset};";

		$sql[] = "CREATE TABLE {$suppress} (
			id bigint(20) unsigned NOT NULL auto_increment,
			email_normalized varchar(191) NOT NULL,
			reason varchar(30) NOT NULL,
			source varchar(191) NOT NULL default '',
			contact_id bigint(20) unsigned NULL default NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY email_normalized (email_normalized),
			KEY reason (reason)
		) {$charset};";

		$sql[] = "CREATE TABLE {$tokens} (
			id bigint(20) unsigned NOT NULL auto_increment,
			token_hash char(64) NOT NULL,
			contact_id bigint(20) unsigned NOT NULL,
			campaign_id bigint(20) unsigned NULL default NULL,
			job_id bigint(20) unsigned NULL default NULL,
			purpose varchar(30) NOT NULL default 'unsubscribe',
			expires_at datetime NOT NULL,
			used_at datetime NULL default NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY token_hash (token_hash),
			KEY contact_id (contact_id),
			KEY expires_at (expires_at)
		) {$charset};";

		$sql[] = "CREATE TABLE {$carts} (
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
			activity_order_ms bigint(20) unsigned NOT NULL default 0,
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
			KEY status_activity (status,last_activity_at),
			KEY status_abandoned (status,abandoned_at),
			KEY email_normalized (email_normalized),
			KEY last_activity_at (last_activity_at)
		) {$charset};";

		$sql[] = "CREATE TABLE {$cart_events} (
			id bigint(20) unsigned NOT NULL auto_increment,
			cart_id bigint(20) unsigned NOT NULL,
			type varchar(30) NOT NULL,
			meta text NULL,
			occurred_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY cart_id (cart_id),
			KEY event_type_time (type,occurred_at,cart_id),
			KEY type (type)
		) {$charset};";

		$sql[] = "CREATE TABLE {$ses_events} (
			id bigint(20) unsigned NOT NULL auto_increment,
			sns_message_id varchar(100) NOT NULL,
			event_key varchar(191) NOT NULL default '',
			event_type varchar(30) NOT NULL,
			ses_message_id varchar(191) NULL default NULL,
			email_normalized varchar(191) NULL default NULL,
			status varchar(20) NOT NULL default '',
			matched_recipients int(10) unsigned NOT NULL default 0,
			payload longtext NULL,
			processed tinyint(1) NOT NULL default 0,
			error text NULL,
			received_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY sns_message_id (sns_message_id),
			UNIQUE KEY event_key (event_key),
			KEY event_type (event_type),
			KEY email_normalized (email_normalized),
			KEY status (status),
			KEY received_at (received_at)
		) {$charset};";

		$sql[] = "CREATE TABLE {$imports} (
			id bigint(20) unsigned NOT NULL auto_increment,
			source varchar(30) NOT NULL,
			mode varchar(20) NOT NULL,
			status varchar(20) NOT NULL default 'running',
			cursor_value varchar(191) NOT NULL default '',
			imported int(10) unsigned NOT NULL default 0,
			updated int(10) unsigned NOT NULL default 0,
			skipped int(10) unsigned NOT NULL default 0,
			failed int(10) unsigned NOT NULL default 0,
			report longtext NULL,
			started_at datetime NOT NULL,
			finished_at datetime NULL default NULL,
			PRIMARY KEY  (id),
			KEY source_status (source,status)
		) {$charset};";

		$sql[] = "CREATE TABLE {$legacy} (
			id bigint(20) unsigned NOT NULL auto_increment,
			remote_id bigint(20) unsigned NOT NULL,
			title varchar(255) NOT NULL default '',
			subject varchar(255) NOT NULL default '',
			type varchar(30) NOT NULL default '',
			status varchar(30) NOT NULL default '',
			recipients_count int(10) unsigned NOT NULL default 0,
			open_count int(10) unsigned NOT NULL default 0,
			click_count int(10) unsigned NOT NULL default 0,
			content longtext NULL,
			meta longtext NULL,
			remote_created_at datetime NULL default NULL,
			imported_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY remote_id (remote_id)
		) {$charset};";

		$sql[] = "CREATE TABLE {$companies} (
			id bigint(20) unsigned NOT NULL auto_increment,
			name varchar(191) NOT NULL,
			owner_id bigint(20) unsigned NULL default NULL,
			industry varchar(191) NOT NULL default '',
			type varchar(50) NOT NULL default '',
			email varchar(191) NOT NULL default '',
			phone varchar(50) NOT NULL default '',
			address_line_1 varchar(191) NOT NULL default '',
			address_line_2 varchar(191) NOT NULL default '',
			postal_code varchar(50) NOT NULL default '',
			city varchar(100) NOT NULL default '',
			state varchar(100) NOT NULL default '',
			country varchar(100) NOT NULL default '',
			employees_number int(10) unsigned NOT NULL default 0,
			description longtext NULL,
			logo varchar(191) NOT NULL default '',
			website varchar(191) NOT NULL default '',
			linkedin_url varchar(191) NOT NULL default '',
			facebook_url varchar(191) NOT NULL default '',
			twitter_url varchar(191) NOT NULL default '',
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY name (name),
			KEY owner_id (owner_id),
			KEY industry (industry),
			KEY type (type)
		) {$charset};";

		$sql[] = "CREATE TABLE {$company_links} (
			id bigint(20) unsigned NOT NULL auto_increment,
			company_id bigint(20) unsigned NOT NULL,
			contact_id bigint(20) unsigned NOT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY company_contact (company_id,contact_id),
			KEY company_id (company_id),
			KEY contact_id (contact_id)
		) {$charset};";

		$sql[] = "CREATE TABLE {$automations} (
			id bigint(20) unsigned NOT NULL auto_increment,
			name varchar(191) NOT NULL default '',
			status varchar(20) NOT NULL default 'draft',
			trigger_key varchar(64) NOT NULL default '',
			trigger_config longtext NULL,
			entry_config longtext NULL,
			created_by bigint(20) unsigned NULL default NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY status_trigger (status,trigger_key),
			KEY trigger_key (trigger_key)
		) {$charset};";

		$sql[] = "CREATE TABLE {$auto_steps} (
			id bigint(20) unsigned NOT NULL auto_increment,
			automation_id bigint(20) unsigned NOT NULL,
			ordinal int(10) unsigned NOT NULL default 0,
			parent_step_id bigint(20) unsigned NULL default NULL,
			branch varchar(10) NULL default NULL,
			kind varchar(20) NOT NULL default 'action',
			action_key varchar(64) NOT NULL default '',
			config longtext NULL,
			delay_seconds int(10) unsigned NOT NULL default 0,
			cumulative_delay_seconds int(10) unsigned NOT NULL default 0,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY automation_ordinal (automation_id,ordinal),
			KEY automation_branch (automation_id,parent_step_id,branch,ordinal),
			KEY kind (kind)
		) {$charset};";

		$sql[] = "CREATE TABLE {$auto_runs} (
			id bigint(20) unsigned NOT NULL auto_increment,
			automation_id bigint(20) unsigned NOT NULL,
			contact_id bigint(20) unsigned NOT NULL,
			status varchar(20) NOT NULL default 'active',
			last_completed_step_id bigint(20) unsigned NULL default NULL,
			next_step_id bigint(20) unsigned NULL default NULL,
			next_run_at datetime NULL default NULL,
			source_trigger varchar(64) NOT NULL default '',
			source_ref varchar(191) NOT NULL default '',
			entry_key varchar(191) NOT NULL default '',
			source_key varchar(191) NULL default NULL,
			cart_id bigint(20) unsigned NULL default NULL,
			cart_version int(10) unsigned NULL default NULL,
			last_error text NULL,
			started_at datetime NOT NULL,
			finished_at datetime NULL default NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY automation_entry (automation_id,contact_id,entry_key),
			UNIQUE KEY source_identity (source_key),
			KEY status_due (status,next_run_at),
			KEY contact_id (contact_id),
			KEY cart_id (cart_id,id),
			KEY legacy_cart_source (source_trigger,source_ref)
		) {$charset};";

		$sql[] = "CREATE TABLE {$auto_events} (
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
		) {$charset};";

		$sql[] = "CREATE TABLE {$sequence_trackers} (
			id bigint(20) unsigned NOT NULL auto_increment,
			sequence_id bigint(20) unsigned NOT NULL,
			contact_id bigint(20) unsigned NOT NULL,
			status varchar(20) NOT NULL default 'active',
			cycle int(10) unsigned NOT NULL default 1,
			next_index int(10) unsigned NOT NULL default 0,
			next_run_at datetime NULL default NULL,
			active_key tinyint(1) NULL default 1,
			last_error text NULL,
			started_at datetime NOT NULL,
			completed_at datetime NULL default NULL,
			cancelled_at datetime NULL default NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY active_enrollment (sequence_id,contact_id,active_key),
			KEY status_due (status,next_run_at),
			KEY sequence_id (sequence_id),
			KEY contact_id (contact_id)
		) {$charset};";

		foreach ( $sql as $statement ) {
			dbDelta( $statement );
		}

		$previous = (string) get_option( self::VERSION_OPTION, '' );
		if ( '2.0.0' === $previous && ! \NowCRM\Campaign\Migration::run() ) {
			return;
		}

		if ( ! self::migrate_source_identity() ) {
			return;
		}

		update_option( self::VERSION_OPTION, self::VERSION, false );
	}

	/**
	 * Rebuild the run identity keys after the new columns exist.
	 *
	 * The old `(automation_id,contact_id)` UNIQUE blocks a second cart for the
	 * same contact. The new UNIQUE keys are added first and verified, then old
	 * cart rows get a stable legacy entry key so they no longer occupy the
	 * ordinary empty entry key. The old key is dropped last. A failure leaves
	 * the stored version unchanged so the next request retries the same steps.
	 */
	private static function migrate_source_identity(): bool {
		global $wpdb;

		$runs = self::table( 'automation_runs' );
		$jobs = self::table( 'email_jobs' );
		$carts = self::table( 'carts' );
		$ses_events = self::table( 'ses_events' );

		$required = array(
			$runs       => array( 'entry_key', 'source_key', 'cart_id', 'cart_version' ),
			$jobs       => array( 'source_key', 'run_id', 'delivery_status', 'delivery_at', 'delivery_error', 'ses_correlation' ),
			$carts      => array( 'activity_order_ms' ),
			$ses_events => array( 'status', 'matched_recipients', 'processed' ),
		);

		foreach ( $required as $table => $columns ) {
			foreach ( $columns as $column ) {
				if ( ! self::column_exists( $table, $column ) ) {
					return false;
				}
			}
		}

		if ( ! self::ensure_unique_index( $runs, 'automation_entry', '`automation_id`,`contact_id`,`entry_key`' ) ) {
			return false;
		}

		if ( ! self::ensure_unique_index( $runs, 'source_identity', '`source_key`' ) ) {
			return false;
		}

		if ( ! self::index_exists( $runs, 'cart_id' ) || ! self::index_exists( $runs, 'legacy_cart_source' ) || ! self::index_exists( $jobs, 'source_key' ) || ! self::index_exists( $jobs, 'run_id' ) || ! self::index_exists( $jobs, 'recipient' ) ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is trusted.
		$backfilled = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$runs} SET entry_key = CONCAT(%s, id) WHERE source_key IS NULL AND entry_key = %s AND source_trigger = %s",
				'legacy-cart:',
				'',
				'cart_abandoned'
			)
		);

		if ( false === $backfilled ) {
			return false;
		}

		if ( self::index_state( $runs, 'automation_contact' ) !== null ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is trusted.
			$wpdb->query( "ALTER TABLE {$runs} DROP INDEX automation_contact" );

			if ( self::index_state( $runs, 'automation_contact' ) !== null ) {
				return false;
			}
		}

		return self::index_state( $runs, 'automation_entry' ) === true
			&& self::index_state( $runs, 'source_identity' ) === true;
	}

	/**
	 * Add one UNIQUE index or confirm the existing index has the same shape.
	 */
	private static function ensure_unique_index( string $table, string $name, string $columns ): bool {
		global $wpdb;

		$state = self::index_state( $table, $name );

		if ( true === $state ) {
			return true;
		}

		if ( false === $state ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table, index name and columns are trusted.
		$wpdb->query( "ALTER TABLE {$table} ADD UNIQUE KEY {$name} ({$columns})" );

		return self::index_state( $table, $name ) === true;
	}

	/**
	 * Whether a column exists.
	 */
	private static function column_exists( string $table, string $column ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is trusted.
		return null !== $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", $column ) );
	}

	/**
	 * Whether any index with this name exists.
	 */
	private static function index_exists( string $table, string $name ): bool {
		return self::index_state( $table, $name ) !== null;
	}

	/**
	 * Index state: null when absent, true when unique, false when not unique.
	 */
	private static function index_state( string $table, string $name ): ?bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is trusted.
		$rows = $wpdb->get_results( "SHOW INDEX FROM {$table}", ARRAY_A );

		foreach ( (array) $rows as $row ) {
			if ( $name === (string) $row['Key_name'] ) {
				return 0 === (int) $row['Non_unique'];
			}
		}

		return null;
	}

	/**
	 * One writer runs the schema migration at a time.
	 */
	private static function acquire_migration_lock(): bool {
		global $wpdb;

		return '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 10)', self::migration_lock_name() ) );
	}

	/**
	 * Release the schema migration lock.
	 */
	private static function release_migration_lock(): void {
		global $wpdb;

		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', self::migration_lock_name() ) );
	}

	/**
	 * Per-install lock name.
	 */
	private static function migration_lock_name(): string {
		global $wpdb;

		$database = defined( 'DB_NAME' ) ? (string) DB_NAME : '';

		return 'nowcrm_schema_' . substr( md5( $database . '|' . $wpdb->prefix ), 0, 32 );
	}

	/**
	 * Whether all plugin tables are present.
	 *
	 * Only call this from activation, upgrade, or the explicit maintenance
	 * action: it costs one SHOW TABLES per plugin table.
	 */
	public static function tables_exist(): bool {
		global $wpdb;

		foreach ( self::TABLES as $name ) {
			$table = self::table( $name );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- SHOW TABLES cannot be prepared.
			$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );

			if ( $found !== $table ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Whether this site may own tables at all.
	 */
	private static function is_supported_site(): bool {
		return Multisite::is_supported();
	}
}
