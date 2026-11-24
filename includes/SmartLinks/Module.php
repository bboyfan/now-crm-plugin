<?php
/**
 * Smart link module entry point. Add `SmartLinks\Module::class` to
 * Plugin::modules() to enable the private link store, the public short route,
 * and the admin screen.
 *
 * @package NowCRM
 */

namespace NowCRM\SmartLinks;

use NowCRM\Admin\SmartLinksPage;
use NowCRM\Public\SmartLinkPage;

defined( 'ABSPATH' ) || exit;

final class Module {

	/**
	 * Register hooks. Every owned hook is added here.
	 */
	public function register(): void {
		add_action( 'init', array( SmartLinkRepository::class, 'register_post_type' ) );

		( new SmartLinkPage() )->register();

		if ( is_admin() ) {
			SmartLinksPage::register();
		}
	}
}
