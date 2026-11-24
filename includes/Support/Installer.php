<?php
/**
 * Activation and deactivation routines.
 *
 * @package NowCRM
 */

namespace NowCRM\Support;

defined( 'ABSPATH' ) || exit;

final class Installer {

	/**
	 * Activation: create tables, seed settings, grant capabilities.
	 *
	 * Deliberately performs no data import, no order backfill and schedules
	 * nothing. Re-activating an existing install must not touch stored data.
	 */
	public static function activate(): void {
		if ( ! Multisite::is_supported() ) {
			return;
		}

		if ( version_compare( PHP_VERSION, '8.3', '<' ) ) {
			deactivate_plugins( plugin_basename( NOWCRM_FILE ) );

			wp_die(
				esc_html__( 'NOW CRM 需要 PHP 8.3 或以上版本。', 'now-crm' ),
				esc_html__( '外掛無法啟用', 'now-crm' ),
				array( 'back_link' => true )
			);
		}

		Schema::install();
		Settings::ensure_defaults();
		Capabilities::grant();
	}

	/**
	 * Deactivation: keep every table and row. No email worker or cron exists in
	 * V0.1, so there is nothing to unschedule.
	 */
	public static function deactivate(): void {
		// Intentionally empty: uninstalling is never implicit.
	}
}
