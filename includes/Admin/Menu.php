<?php
/**
 * Admin menu registration, asset loading, and shared display helpers.
 *
 * @package NowCRM
 */

namespace NowCRM\Admin;

use NowCRM\Support\Capabilities;
use NowCRM\Terms\TermRepository;

defined( 'ABSPATH' ) || exit;

final class Menu {

	public const SLUG          = 'nowcrm';
	public const DASHBOARD_SLUG = 'nowcrm-dashboard';
	public const LISTS_SLUG    = 'nowcrm-lists';
	public const TAGS_SLUG     = 'nowcrm-tags';
	public const CAMPAIGNS_SLUG = 'nowcrm-campaigns';
	public const RECURRING_SLUG = 'nowcrm-recurring';
	public const AUTOMATIONS_SLUG = 'nowcrm-automations';
	public const AUTOMATION_SLUG  = 'nowcrm-automation';
	public const AUTOMATION_RUNS_SLUG = 'nowcrm-automation-runs';
	public const SEQUENCES_SLUG = 'nowcrm-sequences';
	public const SEQUENCE_SLUG  = 'nowcrm-sequence';
	public const SEQUENCE_RUNS_SLUG = 'nowcrm-sequence-runs';
	public const TEMPLATES_SLUG = 'nowcrm-templates';
	public const SETTINGS_SLUG = 'nowcrm-settings';
	public const CONTACT_SLUG  = 'nowcrm-contact';
	public const CART_SLUG     = 'nowcrm-cart';
	public const EMAIL_SLUG    = 'nowcrm-email';
	public const WEBHOOKS_SLUG = 'nowcrm-webhooks';
	public const API_KEYS_SLUG = 'nowcrm-api-keys';
	public const TOOLS_SLUG    = 'nowcrm-tools';

	/**
	 * Hook suffixes of the screens owned by this plugin.
	 *
	 * @var string[]
	 */
	private array $hooks = array();

	/**
	 * Register admin hooks. Loaded from Plugin::boot().
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_pages' ) );
		add_action( 'admin_menu', array( $this, 'order_submenu' ), 20 );
		add_action( 'admin_init', array( $this, 'prepare_contact_detail' ), 5 );
		add_filter( 'parent_file', array( $this, 'filter_parent_file' ) );
		add_filter( 'submenu_file', array( $this, 'filter_submenu_file' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_filter( 'admin_body_class', array( ContentEditorPage::class, 'body_class' ) );

		// admin-post.php defines WP_ADMIN before wp-load(), so its hooks are
		// only ever needed on admin requests.
		if ( is_admin() ) {
			Actions::register();
			add_action( 'admin_post_' . TermsPage::ACTION_EXPORT, array( TermsPage::class, 'export' ) );
			CampaignListPage::register();
			CampaignCreatePage::register();
			RecurringListPage::register();
			RecurringPage::register();
			TemplateListPage::register();
			TemplatePage::register();
			WorkflowEmail::register();
			WorkflowCatalog::register();
			AutomationWorkflowActions::register();
			ReportsPage::register();
		}
	}

	/**
	 * Register the top-level menu and its screens.
	 */
	public function add_pages(): void {
		$this->hooks[] = (string) add_menu_page(
			__( 'NOW CRM', 'now-crm' ),
			__( 'NOW CRM', 'now-crm' ),
			Capabilities::MANAGE_CONTACTS,
			self::DASHBOARD_SLUG,
			array( DashboardPage::class, 'render' ),
			'dashicons-groups',
			26
		);

		$this->hooks[] = (string) add_submenu_page(
			self::DASHBOARD_SLUG,
			__( '總覽', 'now-crm' ),
			__( '總覽', 'now-crm' ),
			Capabilities::MANAGE_CONTACTS,
			self::DASHBOARD_SLUG,
			array( DashboardPage::class, 'render' )
		);

		$this->hooks[] = (string) add_submenu_page(
			self::DASHBOARD_SLUG,
			__( '聯絡人', 'now-crm' ),
			__( '聯絡人', 'now-crm' ),
			Capabilities::MANAGE_CONTACTS,
			self::SLUG,
			array( ContactsPage::class, 'render' )
		);

		$this->hooks[] = (string) add_submenu_page(
			self::DASHBOARD_SLUG,
			__( '名單', 'now-crm' ),
			__( '名單', 'now-crm' ),
			Capabilities::MANAGE_CONTACTS,
			self::LISTS_SLUG,
			array( TermsPage::class, 'render_lists' )
		);

		$this->hooks[] = (string) add_submenu_page(
			self::DASHBOARD_SLUG,
			__( '標籤', 'now-crm' ),
			__( '標籤', 'now-crm' ),
			Capabilities::MANAGE_CONTACTS,
			self::TAGS_SLUG,
			array( TermsPage::class, 'render_tags' )
		);

		$this->hooks[] = (string) add_submenu_page(
			'',
			__( '分眾', 'now-crm' ),
			__( '分眾', 'now-crm' ),
			Capabilities::MANAGE_CONTACTS,
			SegmentsPage::SLUG,
			array( SegmentsPage::class, 'render' )
		);
		remove_submenu_page( '', SegmentsPage::SLUG );

		$this->hooks[] = (string) add_submenu_page(
			self::DASHBOARD_SLUG,
			__( '電子報', 'now-crm' ),
			__( '電子報', 'now-crm' ),
			Capabilities::MANAGE_CONTACTS,
			self::CAMPAIGNS_SLUG,
			array( CampaignListPage::class, 'render' )
		);

		$this->hooks[] = (string) add_submenu_page(
			self::DASHBOARD_SLUG,
			__( '定期寄送', 'now-crm' ),
			__( '定期寄送', 'now-crm' ),
			Capabilities::MANAGE_CONTACTS,
			self::RECURRING_SLUG,
			array( RecurringListPage::class, 'render' )
		);

		$this->hooks[] = (string) add_submenu_page(
			self::DASHBOARD_SLUG,
			__( '自動化', 'now-crm' ),
			__( '自動化', 'now-crm' ),
			Capabilities::MANAGE_CONTACTS,
			self::AUTOMATIONS_SLUG,
			array( AutomationListPage::class, 'render' )
		);

		$this->hooks[] = (string) add_submenu_page(
			self::DASHBOARD_SLUG,
			__( '序列', 'now-crm' ),
			__( '序列', 'now-crm' ),
			Capabilities::MANAGE_CONTACTS,
			self::SEQUENCES_SLUG,
			array( SequenceListPage::class, 'render' )
		);

		$this->hooks[] = (string) add_submenu_page(
			self::DASHBOARD_SLUG,
			__( '電子報範本', 'now-crm' ),
			__( '電子報範本', 'now-crm' ),
			Capabilities::MANAGE_CONTACTS,
			self::TEMPLATES_SLUG,
			array( TemplateListPage::class, 'render' )
		);

		$this->hooks[] = (string) add_submenu_page(
			self::DASHBOARD_SLUG,
			__( '購物車放棄', 'now-crm' ),
			__( '購物車放棄', 'now-crm' ),
			Capabilities::MANAGE_CONTACTS,
			self::CART_SLUG,
			array( CartPage::class, 'render' )
		);

		$this->hooks[] = (string) add_submenu_page(
			self::DASHBOARD_SLUG,
			__( '寄送紀錄', 'now-crm' ),
			__( '寄送紀錄', 'now-crm' ),
			Capabilities::MANAGE_CONTACTS,
			self::EMAIL_SLUG,
			array( EmailLogPage::class, 'render' )
		);

		$this->hooks[] = (string) add_submenu_page(
			self::DASHBOARD_SLUG,
			__( '報表', 'now-crm' ),
			__( '報表', 'now-crm' ),
			Capabilities::MANAGE_CONTACTS,
			ReportsPage::SLUG,
			array( ReportsPage::class, 'render' )
		);

		$this->hooks[] = (string) add_submenu_page(
			self::DASHBOARD_SLUG,
			__( 'Webhooks', 'now-crm' ),
			__( 'Webhooks', 'now-crm' ),
			Capabilities::MANAGE_SETTINGS,
			self::WEBHOOKS_SLUG,
			array( WebhooksPage::class, 'render' )
		);

		$this->hooks[] = (string) add_submenu_page(
			self::DASHBOARD_SLUG,
			__( 'API 金鑰', 'now-crm' ),
			__( 'API 金鑰', 'now-crm' ),
			Capabilities::MANAGE_SETTINGS,
			self::API_KEYS_SLUG,
			array( ApiKeysPage::class, 'render' )
		);

		$this->hooks[] = (string) add_submenu_page(
			self::DASHBOARD_SLUG,
			__( '設定', 'now-crm' ),
			__( '設定', 'now-crm' ),
			Capabilities::MANAGE_SETTINGS,
			self::SETTINGS_SLUG,
			array( SettingsPage::class, 'render' )
		);

		$this->hooks[] = (string) add_submenu_page(
			self::DASHBOARD_SLUG,
			__( '工具與上線檢查', 'now-crm' ),
			__( '工具', 'now-crm' ),
			Capabilities::MANAGE_SETTINGS,
			self::TOOLS_SLUG,
			array( ToolsPage::class, 'render' )
		);

		// Hidden detail screen; reachable only through the contacts list.
		// Keep the empty parent so WordPress registers admin_page_nowcrm-contact,
		// then remove the empty-parent submenu entry to keep the dashboard current.
		$contact_hook = (string) add_submenu_page(
			'',
			__( '聯絡人詳細資料', 'now-crm' ),
			__( '聯絡人詳細資料', 'now-crm' ),
			Capabilities::MANAGE_CONTACTS,
			self::CONTACT_SLUG,
			array( ContactDetailPage::class, 'render' )
		);
		$this->hooks[] = $contact_hook;
		remove_submenu_page( '', self::CONTACT_SLUG );

		$automation_hook = (string) add_submenu_page(
			'',
			__( '自動化編輯器', 'now-crm' ),
			__( '自動化編輯器', 'now-crm' ),
			Capabilities::MANAGE_CONTACTS,
			self::AUTOMATION_SLUG,
			array( AutomationEditorPage::class, 'render' )
		);
		$this->hooks[] = $automation_hook;
		remove_submenu_page( '', self::AUTOMATION_SLUG );

		$runs_hook = (string) add_submenu_page(
			'',
			__( '自動化執行紀錄', 'now-crm' ),
			__( '自動化執行紀錄', 'now-crm' ),
			Capabilities::MANAGE_CONTACTS,
			self::AUTOMATION_RUNS_SLUG,
			array( AutomationRunsPage::class, 'render' )
		);
		$this->hooks[] = $runs_hook;
		remove_submenu_page( '', self::AUTOMATION_RUNS_SLUG );

		$sequence_hook = (string) add_submenu_page(
			'',
			__( '序列編輯器', 'now-crm' ),
			__( '序列編輯器', 'now-crm' ),
			Capabilities::MANAGE_CONTACTS,
			self::SEQUENCE_SLUG,
			array( SequenceEditorPage::class, 'render' )
		);
		$this->hooks[] = $sequence_hook;
		remove_submenu_page( '', self::SEQUENCE_SLUG );

		$sequence_runs_hook = (string) add_submenu_page(
			'',
			__( '序列追蹤', 'now-crm' ),
			__( '序列追蹤', 'now-crm' ),
			Capabilities::MANAGE_CONTACTS,
			self::SEQUENCE_RUNS_SLUG,
			array( SequenceRunsPage::class, 'render' )
		);
		$this->hooks[] = $sequence_runs_hook;
		remove_submenu_page( '', self::SEQUENCE_RUNS_SLUG );

		// Hidden full-screen native editor; reached from the campaign/template UI.
		$editor_hook = (string) add_submenu_page(
			'',
			__( '內容編輯器', 'now-crm' ),
			__( '內容編輯器', 'now-crm' ),
			Capabilities::MANAGE_CONTACTS,
			ContentEditorPage::SLUG,
			array( ContentEditorPage::class, 'render' )
		);
		$this->hooks[] = $editor_hook;
		remove_submenu_page( '', ContentEditorPage::SLUG );

		$create_hook = (string) add_submenu_page(
			'',
			__( '建立電子報', 'now-crm' ),
			__( '建立電子報', 'now-crm' ),
			Capabilities::MANAGE_CONTACTS,
			CampaignCreatePage::SLUG,
			array( CampaignCreatePage::class, 'render' )
		);
		$this->hooks[] = $create_hook;
		remove_submenu_page( '', CampaignCreatePage::SLUG );
	}

	/**
	 * Keep the NOW CRM submenu in a stable, product-facing order.
	 */
	public function order_submenu(): void {
		global $submenu;

		$parent = self::DASHBOARD_SLUG;

		if ( empty( $submenu[ $parent ] ) || ! is_array( $submenu[ $parent ] ) ) {
			return;
		}

		// The custom campaign list owns this entry; drop the CPT-generated one.

		$order = array(
			self::DASHBOARD_SLUG,
			ReportsPage::SLUG,
			self::SLUG,
			self::LISTS_SLUG,
			self::TAGS_SLUG,
			FormsPage::SLUG,
			self::CAMPAIGNS_SLUG,
			self::RECURRING_SLUG,
			self::SEQUENCES_SLUG,
			self::TEMPLATES_SLUG,
			SmartLinksPage::SLUG,
			self::EMAIL_SLUG,
			self::AUTOMATIONS_SLUG,
			self::CART_SLUG,
			self::SETTINGS_SLUG,
			ConsentSettings::SLUG,
			self::WEBHOOKS_SLUG,
			self::API_KEYS_SLUG,
			self::TOOLS_SLUG,
			ContactCsvAdmin::SLUG,
		);

		$positions = array_flip( $order );

		usort(
			$submenu[ $parent ],
			static function ( array $left, array $right ) use ( $positions ): int {
				$left_position  = $positions[ $left[2] ] ?? PHP_INT_MAX;
				$right_position = $positions[ $right[2] ] ?? PHP_INT_MAX;

				return $left_position <=> $right_position;
			}
		);
	}

	/**
	 * Give the hidden contact detail screen a title before admin-header runs.
	 *
	 * A submenu page registered with an empty parent is not discoverable by
	 * get_admin_page_title(); without this, WP 7.1 calls strip_tags( null ).
	 */
	public function prepare_contact_detail(): void {
		$page = self::query( 'page' );

		if ( self::CONTACT_SLUG === $page ) {
			$GLOBALS['title'] = __( '聯絡人詳細資料', 'now-crm' );
		} elseif ( self::AUTOMATION_SLUG === $page ) {
			$GLOBALS['title'] = __( '自動化編輯器', 'now-crm' );
		} elseif ( self::AUTOMATION_RUNS_SLUG === $page ) {
			$GLOBALS['title'] = __( '自動化執行紀錄', 'now-crm' );
		} elseif ( self::SEQUENCE_SLUG === $page ) {
			$GLOBALS['title'] = __( '序列編輯器', 'now-crm' );
		} elseif ( self::SEQUENCE_RUNS_SLUG === $page ) {
			$GLOBALS['title'] = __( '序列追蹤', 'now-crm' );
		} elseif ( ContentEditorPage::SLUG === $page ) {
			$GLOBALS['title'] = __( '內容編輯器', 'now-crm' );
		} elseif ( CampaignCreatePage::SLUG === $page ) {
			$GLOBALS['title'] = __( '建立電子報', 'now-crm' );
		} elseif ( SegmentsPage::SLUG === $page ) {
			$GLOBALS['title'] = __( '分眾', 'now-crm' );
		} elseif ( self::RECURRING_SLUG === $page ) {
			$GLOBALS['title'] = __( '定期寄送', 'now-crm' );
		}
	}

	/**
	 * Keep the NOW CRM menu open on hidden and campaign screens.
	 *
	 * @param string|null $parent_file Current parent file.
	 */
	public function filter_parent_file( $parent_file ) {
		$page = self::query( 'page' );

		if ( self::CONTACT_SLUG === $page || SegmentsPage::SLUG === $page || self::AUTOMATION_SLUG === $page || self::AUTOMATION_RUNS_SLUG === $page || self::SEQUENCE_SLUG === $page || self::SEQUENCE_RUNS_SLUG === $page || ContentEditorPage::SLUG === $page || CampaignCreatePage::SLUG === $page || self::RECURRING_SLUG === $page ) {
			return self::DASHBOARD_SLUG;
		}

		return $parent_file;
	}

	/**
	 * Highlight the owning menu item for hidden and editor screens.
	 *
	 * @param string|null $submenu_file Current submenu file.
	 */
	public function filter_submenu_file( $submenu_file ) {
		$page = self::query( 'page' );

		if ( self::CONTACT_SLUG === $page || SegmentsPage::SLUG === $page ) {
			return self::SLUG;
		}

		if ( self::AUTOMATION_SLUG === $page || self::AUTOMATION_RUNS_SLUG === $page ) {
			return self::AUTOMATIONS_SLUG;
		}

		if ( self::SEQUENCE_SLUG === $page || self::SEQUENCE_RUNS_SLUG === $page ) {
			return self::SEQUENCES_SLUG;
		}

		if ( self::RECURRING_SLUG === $page ) {
			return self::RECURRING_SLUG;
		}

		if ( ContentEditorPage::SLUG === $page && 'template' === self::query( 'target' ) ) {
			return self::TEMPLATES_SLUG;
		}

		if ( ContentEditorPage::SLUG === $page || CampaignCreatePage::SLUG === $page ) {
			return self::CAMPAIGNS_SLUG;
		}

		return $submenu_file;
	}

	/**
	 * Whether the current admin screen edits a WooCommerce order.
	 */
	private static function is_order_screen(): bool {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		return $screen instanceof \WP_Screen && in_array( $screen->id, OrderContactPanel::screen_ids(), true );
	}

	/**
	 * Load the admin stylesheet only on NOW CRM screens.
	 *
	 * @param string $hook_suffix Current admin screen hook suffix.
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		$order_screen = self::is_order_screen();

		if ( ! $order_screen && ! in_array( $hook_suffix, $this->hooks, true ) && get_plugin_page_hookname( ConsentSettings::SLUG, self::DASHBOARD_SLUG ) !== $hook_suffix ) {
			return;
		}

		wp_enqueue_style(
			'nowcrm-admin',
			plugins_url( 'assets/css/admin.css', NOWCRM_FILE ),
			array(),
			(string) filemtime( NOWCRM_DIR . '/assets/css/admin.css' )
		);

		if ( $order_screen ) {
			$stylesheet = 'assets/css/order-panel.css';

			wp_enqueue_style(
				'nowcrm-order-panel',
				plugins_url( $stylesheet, NOWCRM_FILE ),
				array( 'nowcrm-admin' ),
				(string) filemtime( NOWCRM_DIR . '/' . $stylesheet )
			);

			return;
		}

		if ( in_array( self::query( 'page' ), array( self::DASHBOARD_SLUG, ReportsPage::SLUG ), true ) ) {
			$stylesheet = 'assets/css/analytics.css';

			wp_enqueue_style(
				'nowcrm-analytics',
				plugins_url( $stylesheet, NOWCRM_FILE ),
				array( 'nowcrm-admin' ),
				(string) filemtime( NOWCRM_DIR . '/' . $stylesheet )
			);
		}

		if ( self::SLUG === self::query( 'page' ) ) {
			wp_enqueue_script(
				'nowcrm-contact-list',
				plugins_url( 'assets/js/contact-list.js', NOWCRM_FILE ),
				array(),
				(string) filemtime( NOWCRM_DIR . '/assets/js/contact-list.js' ),
				true
			);
		}

		$contacts_asset = match ( self::query( 'page' ) ) {
			self::CONTACT_SLUG => 'contact-detail',
			self::LISTS_SLUG, self::TAGS_SLUG => 'contact-terms',
			default => '',
		};

		if ( '' !== $contacts_asset ) {
			$stylesheet = 'assets/css/' . $contacts_asset . '.css';
			$script     = 'assets/js/' . $contacts_asset . '.js';
			$handle     = 'nowcrm-' . $contacts_asset;

			wp_enqueue_style(
				$handle,
				plugins_url( $stylesheet, NOWCRM_FILE ),
				array( 'nowcrm-admin' ),
				(string) filemtime( NOWCRM_DIR . '/' . $stylesheet )
			);
			wp_enqueue_script(
				$handle,
				plugins_url( $script, NOWCRM_FILE ),
				array(),
				(string) filemtime( NOWCRM_DIR . '/' . $script ),
				true
			);
		}

		if ( self::AUTOMATION_SLUG === self::query( 'page' ) ) {
			$stylesheet = 'assets/css/automation-editor.css';

			wp_enqueue_style(
				'nowcrm-automation-editor',
				plugins_url( $stylesheet, NOWCRM_FILE ),
				array( 'nowcrm-admin' ),
				(string) filemtime( NOWCRM_DIR . '/' . $stylesheet )
			);
		}

		if ( self::CART_SLUG === self::query( 'page' ) ) {
			$stylesheet = 'assets/css/cart-admin.css';

			wp_enqueue_style(
				'nowcrm-cart-admin',
				plugins_url( $stylesheet, NOWCRM_FILE ),
				array( 'nowcrm-admin' ),
				(string) filemtime( NOWCRM_DIR . '/' . $stylesheet )
			);
		}

		$recurring_id = self::RECURRING_SLUG === self::query( 'page' ) ? absint( self::query( 'campaign' ) ) : 0;
		$form_workspace = in_array( self::query( 'page' ), array( self::SEQUENCE_SLUG, self::SEQUENCE_RUNS_SLUG ), true )
			|| ( self::RECURRING_SLUG === self::query( 'page' ) && ! $recurring_id );

		if ( $form_workspace ) {
			$stylesheet = 'assets/css/form-workspace.css';

			wp_enqueue_style(
				'nowcrm-form-workspace',
				plugins_url( $stylesheet, NOWCRM_FILE ),
				wp_style_is( 'nowcrm-admin', 'enqueued' ) ? array( 'nowcrm-admin' ) : array(),
				(string) filemtime( NOWCRM_DIR . '/' . $stylesheet )
			);
		}

		if ( self::CAMPAIGNS_SLUG === self::query( 'page' ) && absint( self::query( 'campaign' ) ) ) {
			CampaignPage::enqueue( absint( self::query( 'campaign' ) ) );
		}
		if ( $recurring_id ) {
			CampaignPage::enqueue( $recurring_id, RecurringPage::list_url(), __( '← 定期寄送', 'now-crm' ), true );
		}
		if ( ContentEditorPage::SLUG === self::query( 'page' ) ) {
			ContentEditorPage::enqueue();
		}
	}

	/**
	 * Admin URL for one of this plugin's screens.
	 *
	 * @param string               $page Menu slug.
	 * @param array<string, mixed> $args Extra query arguments.
	 */
	public static function page_url( string $page, array $args = array() ): string {
		return add_query_arg(
			array_merge( array( 'page' => $page ), $args ),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * String value from the current query string, ignoring arrays.
	 *
	 * @param string $key     Query argument name.
	 * @param string $default Fallback value.
	 */
	public static function query( string $key, string $default = '' ): string {
		if ( ! isset( $_GET[ $key ] ) || ! is_string( $_GET[ $key ] ) ) {
			return $default;
		}

		return wp_unslash( $_GET[ $key ] );
	}

	/**
	 * Format a stored UTC datetime for display in the site timezone.
	 *
	 * @param string|null $utc MySQL datetime in UTC.
	 */
	public static function format_datetime( ?string $utc ): string {
		if ( null === $utc || '' === $utc ) {
			return '—';
		}

		$timestamp = strtotime( $utc . ' UTC' );

		if ( false === $timestamp ) {
			return '—';
		}

		return (string) wp_date( 'Y-m-d H:i', $timestamp );
	}

	/**
	 * Format a monetary amount with its currency code.
	 *
	 * @param float  $amount   Raw amount.
	 * @param string $currency Three letter currency code, may be empty.
	 */
	public static function format_amount( float $amount, string $currency = '' ): string {
		$formatted = number_format_i18n( $amount, 2 );

		return '' === $currency ? $formatted : $formatted . ' ' . $currency;
	}

	/**
	 * Human readable contact name.
	 *
	 * @param array<string, mixed> $contact Contact row.
	 */
	public static function contact_name( array $contact ): string {
		$name = trim(
			(string) ( $contact['first_name'] ?? '' ) . ' ' . (string) ( $contact['last_name'] ?? '' )
		);

		return '' !== $name ? $name : '—';
	}

	/**
	 * Initials for the avatar, from the name and falling back to the email.
	 */
	public static function initials( string $name, string $email ): string {
		$source = '' !== $name ? $name : $email;
		$parts  = preg_split( '/\s+/u', trim( $source ) );
		$parts  = is_array( $parts ) ? array_values( array_filter( $parts, 'strlen' ) ) : array();

		if ( array() === $parts ) {
			return '?';
		}

		if ( 1 === count( $parts ) ) {
			return mb_strtoupper( mb_substr( (string) $parts[0], 0, 2 ), 'UTF-8' );
		}

		return mb_strtoupper(
			mb_substr( (string) $parts[0], 0, 1 ) . mb_substr( (string) $parts[ count( $parts ) - 1 ], 0, 1 ),
			'UTF-8'
		);
	}

	/**
	 * Human readable term type.
	 */
	public static function term_type_label( string $type ): string {
		return TermRepository::TYPE_TAG === $type
			? __( '標籤', 'now-crm' )
			: __( '名單', 'now-crm' );
	}
}
