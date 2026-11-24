<?php
/**
 * Privacy module registration.
 *
 * @package NowCRM
 */

namespace NowCRM\Privacy;

defined( 'ABSPATH' ) || exit;

final class Module {

	/**
	 * Register the WordPress personal data exporter.
	 */
	public function register(): void {
		PersonalDataExporter::register();
	}
}
