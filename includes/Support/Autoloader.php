<?php
/**
 * Minimal PSR-4 autoloader. Keeps the plugin installable without a composer
 * vendor directory in the distributed ZIP.
 *
 * @package NowCRM
 */

namespace NowCRM\Support;

defined( 'ABSPATH' ) || exit;

final class Autoloader {

	/**
	 * Register the autoloader for the NowCRM namespace.
	 *
	 * @param string $base_dir Absolute path to the includes directory.
	 */
	public static function register( string $base_dir ): void {
		spl_autoload_register(
			static function ( string $class ) use ( $base_dir ): void {
				$prefix = 'NowCRM\\';

				if ( ! str_starts_with( $class, $prefix ) ) {
					return;
				}

				$relative = substr( $class, strlen( $prefix ) );
				$path     = $base_dir . '/' . str_replace( '\\', '/', $relative ) . '.php';

				if ( is_readable( $path ) ) {
					require_once $path;
				}
			}
		);
	}
}
