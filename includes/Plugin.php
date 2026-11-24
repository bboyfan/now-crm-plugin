<?php
/**
 * Plugin container: dependency checks, module registration, upgrades.
 *
 * @package NowCRM
 */

namespace NowCRM;

use NowCRM\Support\Multisite;
use NowCRM\Support\Schema;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	private static ?self $instance = null;

	private bool $booted = false;

	/**
	 * Singleton accessor.
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {}

	/**
	 * Boot the plugin once.
	 */
	public function boot(): void {
		if ( $this->booted ) {
			return;
		}

		$this->booted = true;

		if ( version_compare( PHP_VERSION, '8.3', '<' ) ) {
			add_action( 'admin_notices', array( $this, 'notice_php_version' ) );

			return;
		}

		Multisite::guard();

		if ( ! Multisite::is_supported() ) {
			return;
		}

		add_action( 'init', array( Schema::class, 'maybe_upgrade' ), 1 );
		add_action( 'admin_init', array( Schema::class, 'maybe_upgrade' ), 1 );

		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', array( $this, 'notice_woocommerce_missing' ) );
		}

		foreach ( $this->modules() as $module ) {
			( new $module() )->register();
		}
	}

	/**
	 * Registered modules. Each one owns its own hooks.
	 *
	 * @return class-string[]
	 */
	private function modules(): array {
		return (array) apply_filters(
			'nowcrm_modules',
			array(
				Admin\Menu::class,
				Admin\OrderContactPanel::class,
				Admin\ContactCsvAdmin::class,
				Cli\Commands::class,
				Woo\OrderSync::class,
				Woo\CheckoutOptin::class,
				Woo\ProcessingHandoff::class,
				Automation\TriggerRegistry::class,
				Automation\AutomationScheduler::class,
				Sequences\Module::class,
				Email\Queue::class,
				Email\Tracking::class,
				Unsubscribe\Endpoint::class,
				Public\PreferencePage::class,
				Consent\EntryPoints::class,
				Campaign\Campaigns::class,
				Editor\Module::class,
				Campaign\Recurring::class,
				Campaign\Routes::class,
				Templates\TemplateRepository::class,
				Terms\Routes::class,
				SmartLinks\Module::class,
				Webhooks\Dispatcher::class,
				Webhooks\Inbound::class,
				Forms\Module::class,
				Admin\WebhooksPage::class,
				Admin\ApiKeysPage::class,
				Admin\ConsentSettings::class,
				Segments\Module::class,
				Cart\Automation::class,
				Cart\Tracker::class,
				Cart\Sweeper::class,
				Cart\Recovery::class,
				Ses\Endpoint::class,
				Privacy\Module::class,
			)
		);
	}

	/**
	 * Admin notice for an unsupported PHP version.
	 */
	public function notice_php_version(): void {
		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html__( 'NOW CRM 需要 PHP 8.3 或以上版本，目前已停止載入。', 'now-crm' )
		);
	}

	/**
	 * Admin notice when WooCommerce is missing. Contacts keep working.
	 */
	public function notice_woocommerce_missing(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		printf(
			'<div class="notice notice-warning"><p>%s</p></div>',
			esc_html__( 'NOW CRM 找不到 WooCommerce：聯絡人與名單仍可使用，但訂單同步不會運作。', 'now-crm' )
		);
	}
}
