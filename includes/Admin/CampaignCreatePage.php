<?php
/**
 * Independent campaign creation screen.
 *
 * @package NowCRM
 */

namespace NowCRM\Admin;

use NowCRM\Campaign\Campaigns;
use NowCRM\Support\Capabilities;

defined( 'ABSPATH' ) || exit;

final class CampaignCreatePage {

	public const SLUG = 'nowcrm-campaign-new';

	private const NOTICE_KEY = 'nowcrm_campaign_create_';

	public static function url(): string {
		return Menu::page_url( self::SLUG );
	}

	public static function register(): void {
		add_action( 'admin_post_nowcrm_create_campaign', array( __CLASS__, 'handle_create' ) );
	}

	public static function render(): void {
		if ( ! Capabilities::can_manage_contacts() ) {
			wp_die( esc_html__( '沒有權限。', 'now-crm' ), '', array( 'response' => 403 ) );
		}

		$post_type = get_post_type_object( Campaigns::POST_TYPE );
		if ( ! $post_type || ! current_user_can( $post_type->cap->create_posts ) ) {
			wp_die( esc_html__( '沒有權限。', 'now-crm' ), '', array( 'response' => 403 ) );
		}

		$key    = self::NOTICE_KEY . get_current_user_id();
		$state  = get_transient( $key );
		$values = array(
			'title' => '',
			'mode'  => Campaigns::MODE_BLOCKS,
		);
		$error = '';

		if ( is_array( $state ) ) {
			delete_transient( $key );
			$values = wp_parse_args( is_array( $state['values'] ?? null ) ? $state['values'] : array(), $values );
			$error  = (string) ( $state['error'] ?? '' );
		}
		?>
		<div class="wrap nowcrm-wrap nowcrm-create-page">
			<header class="nowcrm-workspace__header">
				<div class="nowcrm-workspace__title">
					<h1><?php esc_html_e( '建立電子報', 'now-crm' ); ?></h1>
				</div>
				<a class="button" href="<?php echo esc_url( Menu::page_url( Menu::CAMPAIGNS_SLUG ) ); ?>"><?php esc_html_e( '返回電子報列表', 'now-crm' ); ?></a>
			</header>

			<?php if ( '' !== $error ) : ?>
				<div class="notice notice-error"><p><?php echo esc_html( $error ); ?></p></div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="nowcrm-panel nowcrm-create-page__form">
				<input type="hidden" name="action" value="nowcrm_create_campaign">
				<?php wp_nonce_field( 'nowcrm_create_campaign' ); ?>

				<div class="nowcrm-workspace__fields">
					<label class="nowcrm-workspace__field nowcrm-field-head" for="nowcrm-create-title">
						<span><?php esc_html_e( '內部標題', 'now-crm' ); ?></span>
						<input
							type="text"
							id="nowcrm-create-title"
							name="title"
							value="<?php echo esc_attr( (string) $values['title'] ); ?>"
							maxlength="160"
							placeholder="<?php esc_attr_e( '未命名電子報會自動產生標題', 'now-crm' ); ?>"
							data-nowcrm-create-title
						>
						<span class="nowcrm-campaigns__counter" data-nowcrm-create-counter>0 / 160</span>
					</label>
				</div>

				<fieldset class="nowcrm-mode-fieldset">
					<legend><?php esc_html_e( '編輯方式', 'now-crm' ); ?></legend>
					<div class="nowcrm-mode-cards">
						<label class="nowcrm-mode-card">
							<input type="radio" name="mode" value="<?php echo esc_attr( Campaigns::MODE_BLOCKS ); ?>" <?php checked( $values['mode'], Campaigns::MODE_BLOCKS ); ?>>
							<span class="nowcrm-mode-card__icon" aria-hidden="true">
								<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.7"><rect x="3" y="4" width="18" height="16" rx="2"/><path d="M7 8h10M7 12h7M7 16h10"/></svg>
							</span>
							<span class="nowcrm-mode-card__text">
								<span class="nowcrm-mode-card__label"><?php esc_html_e( 'Gutenberg', 'now-crm' ); ?></span>
								<span class="nowcrm-mode-card__hint"><?php esc_html_e( 'WordPress 原生編輯器', 'now-crm' ); ?></span>
							</span>
						</label>
						<label class="nowcrm-mode-card">
							<input type="radio" name="mode" value="<?php echo esc_attr( Campaigns::MODE_HTML ); ?>" <?php checked( $values['mode'], Campaigns::MODE_HTML ); ?>>
							<span class="nowcrm-mode-card__icon" aria-hidden="true">
								<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.7"><path d="m8 8-4 4 4 4M16 8l4 4-4 4M13 5l-2 14"/></svg>
							</span>
							<span class="nowcrm-mode-card__text">
								<span class="nowcrm-mode-card__label"><?php esc_html_e( 'Raw HTML', 'now-crm' ); ?></span>
								<span class="nowcrm-mode-card__hint"><?php esc_html_e( '完整 HTML 控制', 'now-crm' ); ?></span>
							</span>
						</label>
					</div>
					<p class="nowcrm-create__tip" id="nowcrm-create-tip" hidden>
						<span class="dashicons dashicons-info-outline" aria-hidden="true"></span>
						<span><?php esc_html_e( 'Gutenberg 是預設且建議的選項，相容性與長期支援最佳。', 'now-crm' ); ?></span>
					</p>
				</fieldset>

				<p class="nowcrm-workspace__footer">
					<button type="submit" class="button button-primary"><?php esc_html_e( '建立並繼續', 'now-crm' ); ?></button>
				</p>
			</form>
		</div>
		<?php
		wp_print_inline_script_tag(
			<<<'JS'
( function () {
	var title = document.querySelector( '[data-nowcrm-create-title]' );
	var counter = document.querySelector( '[data-nowcrm-create-counter]' );
	var tip = document.getElementById( 'nowcrm-create-tip' );
	var sync = function () {
		var checked = document.querySelector( 'input[name="mode"]:checked' );
		if ( tip ) {
			tip.hidden = ! checked || checked.value !== 'html';
		}
	};
	if ( title && counter ) {
		var count = function () { counter.textContent = title.value.length + ' / 160'; };
		title.addEventListener( 'input', count );
		count();
	}
	document.querySelectorAll( 'input[name="mode"]' ).forEach( function ( input ) {
		input.addEventListener( 'change', sync );
	} );
	sync();
}() );
JS
		);
	}

	public static function handle_create(): void {
		if ( ! Capabilities::can_manage_contacts() ) {
			wp_die( esc_html__( '您沒有權限執行此操作。', 'now-crm' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( 'nowcrm_create_campaign' );

		$post_type = get_post_type_object( Campaigns::POST_TYPE );
		if ( ! $post_type || ! current_user_can( $post_type->cap->create_posts ) ) {
			wp_die( esc_html__( '您沒有權限執行此操作。', 'now-crm' ), '', array( 'response' => 403 ) );
		}

		$values = array(
			'title' => self::post_string( 'title' ),
			'mode'  => Campaigns::MODE_HTML === self::post_string( 'mode' ) ? Campaigns::MODE_HTML : Campaigns::MODE_BLOCKS,
		);

		$values['title'] = trim( sanitize_text_field( $values['title'] ) );
		if ( '' === $values['title'] ) {
			$values['title'] = self::default_title();
		}
		if ( mb_strlen( $values['title'], 'UTF-8' ) > 160 ) {
			$values['title'] = mb_substr( $values['title'], 0, 160, 'UTF-8' );
		}

		$post_id = Campaigns::create_draft( $values['title'], $values['mode'] );

		if ( is_wp_error( $post_id ) || ! $post_id ) {
			set_transient(
				self::NOTICE_KEY . get_current_user_id(),
				array(
					'values' => $values,
					'error'  => is_wp_error( $post_id ) ? $post_id->get_error_message() : __( '無法建立電子報，請再試一次。', 'now-crm' ),
				),
				MINUTE_IN_SECONDS
			);
			wp_safe_redirect( self::url() );
			exit;
		}

		wp_safe_redirect( CampaignPage::url( (int) $post_id ) );
		exit;
	}

	private static function post_string( string $key ): string {
		return isset( $_POST[ $key ] ) && is_string( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : '';
	}

	private static function default_title(): string {
		$base = sprintf(
			/* translators: %s: current date and time */
			__( '未命名電子報 %s', 'now-crm' ),
			wp_date( 'Y-m-d H:i' )
		);

		$title  = $base;
		$suffix = 2;
		while ( self::title_exists( $title ) ) {
			$title = $base . ' ' . $suffix;
			++$suffix;
		}

		return $title;
	}

	private static function title_exists( string $title ): bool {
		$existing = get_posts(
			array(
				'post_type'        => Campaigns::POST_TYPE,
				'post_status'      => 'any',
				'title'            => $title,
				'posts_per_page'   => 1,
				'fields'           => 'ids',
				'no_found_rows'    => true,
				'suppress_filters' => true, // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.SuppressFilters_suppress_filters -- duplicate-title lookup must not be altered by other plugins.
			)
		);

		return (bool) $existing;
	}
}
