<?php
/**
 * Test bootstrap: loads WooCommerce and NOW CRM inside the WordPress test suite.
 *
 * The WordPress installation, the test library and the database all live outside
 * this repository. Only this file, phpunit.xml.dist and the test cases are
 * tracked; see docs/testing.md for the runner.
 *
 * @package NowCRM
 */

declare( strict_types=1 );

$tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $tests_dir ) {
	$tests_dir = dirname( __DIR__ ) . '/tests/.php-tests/phpunit';
}

if ( ! file_exists( $tests_dir . '/includes/functions.php' ) ) {
	fwrite(
		STDERR,
		"WP_TESTS_DIR is not a WordPress test library: {$tests_dir}\n" .
		"Run tests/bin/run-tests.sh, which mounts the library downloaded by the setup step.\n"
	);
	exit( 1 );
}

$config_path = getenv( 'WP_TESTS_CONFIG_FILE_PATH' );

if ( $config_path ) {
	define( 'WP_TESTS_CONFIG_FILE_PATH', $config_path );
}

$polyfills = getenv( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' );

if ( $polyfills ) {
	define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', $polyfills );
}

if ( '1' === (string) getenv( 'WP_TESTS_MULTISITE' ) ) {
	define( 'WP_TESTS_MULTISITE', true );
}

require_once $tests_dir . '/includes/functions.php';

/**
 * Load WooCommerce before NOW CRM, mirroring a normal WordPress request order.
 */
tests_add_filter(
	'muplugins_loaded',
	static function (): void {
		// The HPOS suite is a separate process: WooCommerce reads these options
		// during boot, so they must be filtered before woocommerce.php loads.
		if ( '1' === (string) getenv( 'NOWCRM_HPOS' ) ) {
			add_filter( 'pre_option_woocommerce_custom_orders_table_enabled', static fn() => 'yes' );
			add_filter( 'pre_option_woocommerce_custom_orders_table_data_sync_enabled', static fn() => 'no' );
			add_filter( 'pre_option_woocommerce_feature_custom_order_tables_enabled', static fn() => 'yes' );
		}

		$woo = WP_PLUGIN_DIR . '/woocommerce/woocommerce.php';

		if ( ! file_exists( $woo ) ) {
			fwrite( STDERR, "WooCommerce is required for these tests: {$woo}\n" );
			exit( 1 );
		}

		require_once $woo;
		require_once dirname( __DIR__ ) . '/now-crm.php';
	}
);

/**
 * WooCommerce creates its tables and pages from its activation hook, which never
 * runs inside the test suite, so install it explicitly.
 */
tests_add_filter(
	'setup_theme',
	static function (): void {
		if ( class_exists( 'WC_Install' ) ) {
			WC_Install::install();
		}

		if ( '1' === (string) getenv( 'NOWCRM_HPOS' ) && class_exists( 'WC_Install' ) ) {
			global $wpdb;

			update_option( 'woocommerce_custom_orders_table_created', 'yes' );

			foreach ( array( 'wc_orders', 'wc_order_addresses', 'wc_order_operational_data', 'wc_orders_meta' ) as $table ) {
				$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$table}" );
			}

			if ( function_exists( 'wc_get_container' ) && class_exists( '\Automattic\WooCommerce\Internal\DataStores\Orders\OrdersTableDataStore' ) ) {
				$data_store = wc_get_container()->get( '\Automattic\WooCommerce\Internal\DataStores\Orders\OrdersTableDataStore' );

				if ( is_object( $data_store ) && method_exists( $data_store, 'get_database_schema' ) ) {
					foreach ( explode( ';', (string) $data_store->get_database_schema() ) as $statement ) {
						$statement = trim( $statement );

						if ( '' !== $statement ) {
							$wpdb->query( $statement );
						}
					}
				}
			}
		}
	}
);

require $tests_dir . '/includes/bootstrap.php';

require_once __DIR__ . '/NowCrmTestCase.php';

// Activation hooks do not run in the suite; install the plugin schema and caps.
if ( class_exists( \NowCRM\Support\Installer::class ) ) {
	\NowCRM\Support\Installer::activate();
}
