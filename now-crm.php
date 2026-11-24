<?php
/**
 * Plugin Name:       NOW CRM
 * Plugin URI:        https://github.com/bboyfan/now-crm-plugin
 * Description:       A self-hosted CRM for WordPress and WooCommerce: contacts, lists, tags, campaigns, automation, and abandoned-cart email. WooCommerce is optional.
 * Version:           1.1
 * Requires at least: 6.7
 * Requires PHP:      8.3
 * Author:            WENSZU
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       now-crm
 *
 * Copyright (C) 2026 WENSZU.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package NowCRM
 */

defined( 'ABSPATH' ) || exit;

define( 'NOWCRM_VERSION', '1.1' );
define( 'NOWCRM_FILE', __FILE__ );
define( 'NOWCRM_DIR', __DIR__ );

require_once NOWCRM_DIR . '/includes/Support/Autoloader.php';

\NowCRM\Support\Autoloader::register( NOWCRM_DIR . '/includes' );

register_activation_hook( NOWCRM_FILE, array( \NowCRM\Support\Installer::class, 'activate' ) );
register_deactivation_hook( NOWCRM_FILE, array( \NowCRM\Support\Installer::class, 'deactivate' ) );

add_action(
	'plugins_loaded',
	static function (): void {
		\NowCRM\Plugin::instance()->boot();
	},
	5
);
