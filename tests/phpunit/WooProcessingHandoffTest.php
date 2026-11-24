<?php
/**
 * Woo processing owner detection and guarded transitions.
 *
 * @package NowCRM
 */

use NowCRM\Support\Capabilities;
use NowCRM\Woo\ProcessingHandoff;

/**
 * @covers \NowCRM\Woo\ProcessingHandoff
 */
final class WooProcessingHandoffTest extends NowCrmTestCase {

	private string $table = '';

	/** @var string[] */
	private array $active_plugins = array();

	public function set_up(): void {
		parent::set_up();

		$this->active_plugins = (array) get_option( 'active_plugins', array() );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		Capabilities::grant();
	}

	public function tear_down(): void {
		global $wpdb;

		if ( '' !== $this->table ) {
			$wpdb->query( "DROP TEMPORARY TABLE IF EXISTS {$this->table}" );
			$this->table = '';
		}

		update_option( 'active_plugins', $this->active_plugins );
		delete_option( ProcessingHandoff::OPTION );
		$_POST    = array();
		$_REQUEST = array();

		parent::tear_down();
	}

	public function test_published_processing_automation_is_a_hard_enable_conflict(): void {
		$this->activate_fluent( true, true );
		$this->make_funnels_table();
		$this->insert_funnel( 'woocommerce_order_status_processing', 'published' );

		$status = ( new ProcessingHandoff() )->status();

		$this->assertSame( ProcessingHandoff::FLUENT_CONFLICT, $status['fluent_state'] );
		$this->assertSame( 1, $status['fluent_published'] );

		$location = $this->toggle( array( 'enabled' => '1' ) );

		$this->assertStringContainsString( 'nowcrm_notice=woo_processing_conflict', $location );
		$this->assertFalse( get_option( ProcessingHandoff::OPTION ), 'A known conflict must not save the toggle.' );
	}

	public function test_unknown_state_requires_operator_ack_before_enabling(): void {
		// No fc_funnels table: the state must be unavailable, never safe.
		$location = $this->toggle( array( 'enabled' => '1' ) );

		$this->assertStringContainsString( 'nowcrm_notice=woo_processing_ack_required', $location );
		$this->assertFalse( get_option( ProcessingHandoff::OPTION ) );

		$location = $this->toggle(
			array(
				'enabled'                    => '1',
				'acknowledge_unknown_owner' => '1',
			)
		);

		$this->assertStringContainsString( 'nowcrm_notice=woo_processing_on', $location );
		$this->assertSame( 'yes', (string) ( get_option( ProcessingHandoff::OPTION )['enabled'] ?? '' ) );
	}

	public function test_clear_state_allows_enabling_without_ack(): void {
		$this->activate_fluent( true, true );
		$this->make_funnels_table();

		$status = ( new ProcessingHandoff() )->status();

		$this->assertSame( ProcessingHandoff::FLUENT_CLEAR, $status['fluent_state'] );

		$location = $this->toggle( array( 'enabled' => '1' ) );

		$this->assertStringContainsString( 'nowcrm_notice=woo_processing_on', $location );
		$this->assertSame( 'yes', (string) ( get_option( ProcessingHandoff::OPTION )['enabled'] ?? '' ) );
	}

	public function test_disabling_requires_alternative_owner_ack(): void {
		update_option( ProcessingHandoff::OPTION, array( 'enabled' => 'yes' ), false );
		$this->make_funnels_table();
		$this->insert_funnel( 'woocommerce_order_status_processing', 'published' );

		$location = $this->toggle( array( 'enabled' => '0' ) );

		$this->assertStringContainsString( 'nowcrm_notice=woo_processing_alternative_required', $location );
		$this->assertSame( 'yes', (string) ( get_option( ProcessingHandoff::OPTION )['enabled'] ?? '' ) );

		$location = $this->toggle(
			array(
				'enabled'                         => '0',
				'acknowledge_alternative_owner'   => '1',
			)
		);

		$this->assertStringContainsString( 'nowcrm_notice=woo_processing_off', $location );
		$this->assertSame( 'no', (string) ( get_option( ProcessingHandoff::OPTION )['enabled'] ?? '' ) );
	}

	public function test_retained_rows_with_inactive_core_are_not_a_hard_conflict(): void {
		$this->activate_fluent( false, true );
		$this->make_funnels_table();
		$this->insert_funnel( 'woocommerce_order_status_processing', 'published' );

		$status = ( new ProcessingHandoff() )->status();

		$this->assertSame( ProcessingHandoff::FLUENT_UNKNOWN, $status['fluent_state'] );
		$this->assertSame( 'runtime_inactive', $status['fluent_reason'] );
		$this->assertSame( 0, $status['fluent_published'] );

		$location = $this->toggle( array( 'enabled' => '1' ) );

		$this->assertStringContainsString( 'nowcrm_notice=woo_processing_ack_required', $location );
		$this->assertFalse( get_option( ProcessingHandoff::OPTION ) );

		$location = $this->toggle(
			array(
				'enabled'                    => '1',
				'acknowledge_unknown_owner' => '1',
			)
		);

		$this->assertStringContainsString( 'nowcrm_notice=woo_processing_on', $location );
		$this->assertSame( 'yes', (string) ( get_option( ProcessingHandoff::OPTION )['enabled'] ?? '' ) );
	}

	public function test_retained_rows_with_inactive_pro_are_not_a_hard_conflict(): void {
		$this->activate_fluent( true, false );
		$this->make_funnels_table();
		$this->insert_funnel( 'woocommerce_order_status_processing', 'published' );

		$status = ( new ProcessingHandoff() )->status();

		$this->assertSame( ProcessingHandoff::FLUENT_UNKNOWN, $status['fluent_state'] );
		$this->assertSame( 'runtime_inactive', $status['fluent_reason'] );
		$this->assertSame( 0, $status['fluent_published'] );

		$location = $this->toggle(
			array(
				'enabled'                    => '1',
				'acknowledge_unknown_owner' => '1',
			)
		);

		$this->assertStringContainsString( 'nowcrm_notice=woo_processing_on', $location );
		$this->assertSame( 'yes', (string) ( get_option( ProcessingHandoff::OPTION )['enabled'] ?? '' ) );
	}

	public function test_other_site_funnels_table_is_not_inspected(): void {
		global $wpdb;

		$this->activate_fluent( true, true );

		$other = $wpdb->base_prefix . 'site2_fc_funnels';

		$wpdb->query( "CREATE TEMPORARY TABLE {$other} (id bigint unsigned NOT NULL AUTO_INCREMENT, type varchar(20) NOT NULL, trigger_name varchar(191) NOT NULL, status varchar(20) NOT NULL, PRIMARY KEY (id))" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- test fixture.

		try {
			$wpdb->insert(
				$other,
				array(
					'type'         => 'funnels',
					'trigger_name' => 'woocommerce_order_status_processing',
					'status'       => 'published',
				),
				array( '%s', '%s', '%s' )
			);

			$status = ( new ProcessingHandoff() )->status();

			$this->assertSame( ProcessingHandoff::FLUENT_UNAVAILABLE, $status['fluent_state'] );
			$this->assertSame( 0, $status['fluent_published'] );
		} finally {
			$wpdb->query( "DROP TEMPORARY TABLE IF EXISTS {$other}" );
		}
	}

	public function test_draft_or_other_triggers_are_not_a_conflict(): void {
		$this->activate_fluent( true, true );
		$this->make_funnels_table();
		$this->insert_funnel( 'woocommerce_order_status_processing', 'draft' );
		$this->insert_funnel( 'woocommerce_order_status_completed', 'published' );

		$status = ( new ProcessingHandoff() )->status();

		$this->assertSame( ProcessingHandoff::FLUENT_CLEAR, $status['fluent_state'] );
		$this->assertSame( 0, $status['fluent_published'] );
	}

	private function activate_fluent( bool $core, bool $pro ): void {
		$plugins = array_values(
			array_diff(
				$this->active_plugins,
				array( 'fluent-crm/fluent-crm.php', 'fluentcampaign-pro/fluentcampaign-pro.php' )
			)
		);

		if ( $core ) {
			$plugins[] = 'fluent-crm/fluent-crm.php';
		}

		if ( $pro ) {
			$plugins[] = 'fluentcampaign-pro/fluentcampaign-pro.php';
		}

		update_option( 'active_plugins', $plugins );
	}

	private function make_funnels_table(): void {
		global $wpdb;

		$this->table = $wpdb->prefix . 'fc_funnels';

		$wpdb->query( "CREATE TEMPORARY TABLE {$this->table} (id bigint unsigned NOT NULL AUTO_INCREMENT, type varchar(20) NOT NULL, trigger_name varchar(191) NOT NULL, status varchar(20) NOT NULL, PRIMARY KEY (id))" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- test fixture.
	}

	private function insert_funnel( string $trigger, string $status ): void {
		global $wpdb;

		$wpdb->insert(
			$this->table,
			array(
				'type'         => 'funnels',
				'trigger_name' => $trigger,
				'status'       => $status,
			),
			array( '%s', '%s', '%s' )
		);
	}

	/**
	 * Run the toggle handler with a valid nonce and capture the redirect.
	 *
	 * @param array<string, string> $post Posted fixture fields.
	 */
	private function toggle( array $post ): string {
		$_POST    = $post;
		$_REQUEST = array( '_wpnonce' => wp_create_nonce( 'nowcrm_toggle_woo_processing' ) );

		$captured = '';
		$filter   = static function ( $location ) use ( &$captured ): void {
			$captured = (string) $location;
			throw new \RuntimeException( $captured );
		};

		add_filter( 'wp_redirect', $filter, 1 );

		try {
			( new ProcessingHandoff() )->toggle();
		} catch ( \RuntimeException $exception ) {
			$captured = $exception->getMessage();
		} finally {
			remove_filter( 'wp_redirect', $filter, 1 );
			$_POST    = array();
			$_REQUEST = array();
		}

		$this->assertNotSame( '', $captured, 'The handler did not redirect.' );

		return $captured;
	}
}
