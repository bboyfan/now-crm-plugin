<?php
/**
 * Consent settings screen: double opt-in, public lists, footer, throttles.
 *
 * Server-rendered with the existing `.nowcrm-wrap` / `.nowcrm-panel` pattern;
 * no build step. Saved through its own `admin-post.php` handler because the
 * read-only `Admin\Actions` dispatcher is frozen this stage.
 *
 * SEAM: `Admin\Menu::order_submenu()` should place `self::SLUG` after the
 * campaigns entry, and `Admin\SettingsPage` may link here. Until then this
 * class registers its own submenu so the screen is not dead.
 *
 * @package NowCRM
 */

namespace NowCRM\Admin;

use NowCRM\Consent\Config;
use NowCRM\Support\Capabilities;
use NowCRM\Terms\TermRepository;

defined( 'ABSPATH' ) || exit;

final class ConsentSettings {

	public const SLUG = 'nowcrm-consent';

	/**
	 * Hooks. Called from `Plugin::modules()` next to the other screens.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_page' ) );
		add_action( 'admin_post_nowcrm_save_consent', array( $this, 'save' ) );
	}

	public function add_page(): void {
		add_submenu_page(
			Menu::DASHBOARD_SLUG,
			__( '同意與偏好', 'now-crm' ),
			__( '同意與偏好', 'now-crm' ),
			Capabilities::MANAGE_SETTINGS,
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Render the screen.
	 */
	public function render(): void {
		if ( ! Capabilities::can_manage_settings() ) {
			wp_die( esc_html__( '您沒有權限檢視設定。', 'now-crm' ), '', array( 'response' => 403 ) );
		}

		$settings  = Config::all();
		$terms     = new TermRepository();
		$lists     = $terms->all( TermRepository::TYPE_LIST );
		$double    = $settings['double_optin'];
		$form_url  = admin_url( 'admin-post.php' );
		$mode      = (string) $settings['public_lists_mode'];
		$selected  = array_map( 'intval', (array) $settings['public_list_ids'] );
		$list_cfgs = array();

		foreach ( $lists as $list ) {
			$list_cfgs[ (int) $list['id'] ] = Config::list_config( (int) $list['id'] );
		}

		$stored_lists = get_option( Config::LISTS_OPTION, array() );
		$stored_lists = is_array( $stored_lists ) ? $stored_lists : array();
		?>
		<div class="wrap nowcrm-wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( '同意與偏好', 'now-crm' ); ?></h1>
			<hr class="wp-header-end" />

			<nav class="nowcrm-settings-crumbs" aria-label="<?php esc_attr_e( '設定位置', 'now-crm' ); ?>">
				<p class="description">
					<a href="<?php echo esc_url( Menu::page_url( Menu::SETTINGS_SLUG ) ); ?>"><?php esc_html_e( '設定', 'now-crm' ); ?></a>
					<span aria-hidden="true">/</span>
					<span aria-current="page"><?php esc_html_e( '同意與偏好', 'now-crm' ); ?></span>
				</p>
			</nav>

			<p class="description"><?php esc_html_e( '這些設定套用於訂閱表單、偏好中心與行銷郵件。', 'now-crm' ); ?></p>

			<?php Actions::render_notice(); ?>

			<form method="post" action="<?php echo esc_url( $form_url ); ?>">
				<input type="hidden" name="action" value="nowcrm_save_consent" />
				<?php wp_nonce_field( 'nowcrm_save_consent' ); ?>

				<div class="nowcrm-panel">
					<h2><?php esc_html_e( '雙重確認（Double Opt-in）', 'now-crm' ); ?></h2>
					<p><label>
						<input type="checkbox" name="double_optin_enabled" value="1" <?php checked( (bool) $settings['double_optin_enabled'] ); ?> />
						<?php esc_html_e( '新訂閱預設需要確認（個別名單可覆寫）', 'now-crm' ); ?>
					</label></p>
					<table class="form-table" role="presentation"><tbody>
						<tr><th><label for="nowcrm-doi-subject"><?php esc_html_e( '確認信主旨', 'now-crm' ); ?></label></th>
							<td><input type="text" id="nowcrm-doi-subject" class="regular-text" name="double_optin_email_subject" value="<?php echo esc_attr( (string) $double['email_subject'] ); ?>" /></td></tr>
						<tr><th><label for="nowcrm-doi-body"><?php esc_html_e( '確認信內文（含 {{confirm_url}}）', 'now-crm' ); ?></label></th>
							<td><textarea id="nowcrm-doi-body" class="large-text" rows="6" name="double_optin_email_body"><?php echo esc_textarea( (string) $double['email_body'] ); ?></textarea></td></tr>
						<tr><th><label for="nowcrm-doi-after"><?php esc_html_e( '確認完成訊息', 'now-crm' ); ?></label></th>
							<td><textarea id="nowcrm-doi-after" class="large-text" rows="4" name="double_optin_after_confirm_message"><?php echo esc_textarea( (string) $double['after_confirm_message'] ); ?></textarea></td></tr>
						<tr><th><label for="nowcrm-doi-throttle"><?php esc_html_e( '重寄冷卻（分鐘）', 'now-crm' ); ?></label></th>
							<td><input type="number" id="nowcrm-doi-throttle" name="double_optin_resend_throttle_minutes" min="1" max="1440" value="<?php echo esc_attr( (string) (int) $double['resend_throttle_minutes'] ); ?>" /></td></tr>
					</tbody></table>
				</div>

				<div class="nowcrm-panel">
					<h2><?php esc_html_e( '偏好中心公開名單', 'now-crm' ); ?></h2>
					<p class="description"><?php esc_html_e( '未設定時不公開任何名單。公開只決定偏好中心顯示哪些名單；不會改變聯絡人的訂閱狀態、取消訂閱、抑制名單或寄送資格。', 'now-crm' ); ?></p>
					<p>
						<label><input type="radio" name="public_lists_mode" value="all" <?php checked( $mode, 'all' ); ?> /> <?php esc_html_e( '全部名單', 'now-crm' ); ?></label><br />
						<label><input type="radio" name="public_lists_mode" value="none" <?php checked( $mode, 'none' ); ?> /> <?php esc_html_e( '不公開（預設）', 'now-crm' ); ?></label><br />
						<label><input type="radio" name="public_lists_mode" value="selected" <?php checked( $mode, 'selected' ); ?> /> <?php esc_html_e( '只公開勾選的名單', 'now-crm' ); ?></label>
					</p>
					<?php if ( array() === $lists ) : ?>
						<p class="description"><?php esc_html_e( '尚無名單可公開。', 'now-crm' ); ?></p>
					<?php else : ?>
						<p class="description"><?php esc_html_e( '只有選擇「只公開勾選的名單」時，下列勾選才會生效。', 'now-crm' ); ?></p>
						<?php foreach ( $lists as $list ) : ?>
							<p><label>
								<input type="checkbox" name="public_list_ids[]" value="<?php echo (int) $list['id']; ?>" <?php checked( in_array( (int) $list['id'], $selected, true ) ); ?> />
								<?php echo esc_html( (string) $list['name'] ); ?>
							</label></p>
						<?php endforeach; ?>
					<?php endif; ?>
				</div>

				<div class="nowcrm-panel">
					<h2><?php esc_html_e( '個別名單覆寫', 'now-crm' ); ?></h2>

					<?php if ( array() === $lists ) : ?>
						<p class="description"><?php esc_html_e( '尚無名單可覆寫。', 'now-crm' ); ?></p>
					<?php else : ?>
						<p class="description"><?php esc_html_e( '留空的主旨或內文會沿用全域設定。', 'now-crm' ); ?></p>
						<table class="widefat"><thead><tr>
							<th><?php esc_html_e( '名單', 'now-crm' ); ?></th>
							<th><?php esc_html_e( '雙重確認', 'now-crm' ); ?></th>
							<th><?php esc_html_e( '主旨覆寫', 'now-crm' ); ?></th>
							<th><?php esc_html_e( '內文覆寫', 'now-crm' ); ?></th>
						</tr></thead><tbody>
						<?php foreach ( $lists as $list ) :
							$id  = (int) $list['id'];
							$cfg = $list_cfgs[ $id ];
							$own = isset( $stored_lists[ $id ] ) && is_array( $stored_lists[ $id ] ) ? $stored_lists[ $id ] : array();
							?>
							<tr>
								<td><?php echo esc_html( (string) $list['name'] ); ?></td>
								<td><select name="list_mode[<?php echo esc_attr( (string) $id ); ?>]">
									<option value="inherit" <?php selected( $cfg['mode'], 'inherit' ); ?>><?php esc_html_e( '跟隨全域', 'now-crm' ); ?></option>
									<option value="require" <?php selected( $cfg['mode'], 'require' ); ?>><?php esc_html_e( '需要確認', 'now-crm' ); ?></option>
									<option value="skip" <?php selected( $cfg['mode'], 'skip' ); ?>><?php esc_html_e( '不需要確認', 'now-crm' ); ?></option>
								</select></td>
								<td><input type="text" name="list_subject[<?php echo esc_attr( (string) $id ); ?>]" value="<?php echo esc_attr( (string) ( $own['email_subject'] ?? '' ) ); ?>" /></td>
								<td><textarea rows="2" cols="30" name="list_body[<?php echo esc_attr( (string) $id ); ?>]"><?php echo esc_textarea( (string) ( $own['email_body'] ?? '' ) ); ?></textarea></td>
							</tr>
						<?php endforeach; ?>
						</tbody></table>
					<?php endif; ?>
				</div>

				<div class="nowcrm-panel">
					<h2><?php esc_html_e( '合規頁尾與限制', 'now-crm' ); ?></h2>
					<p class="description"><?php esc_html_e( '行銷郵件必須包含取消訂閱或管理偏好連結。', 'now-crm' ); ?></p>
					<table class="form-table" role="presentation"><tbody>
						<tr><th><label for="nowcrm-consent-footer"><?php esc_html_e( '全域頁尾（可用 {{unsubscribe_url}} / {{manage_url}}）', 'now-crm' ); ?></label></th>
							<td><textarea id="nowcrm-consent-footer" class="large-text" rows="3" name="footer_text"><?php echo esc_textarea( (string) $settings['footer_text'] ); ?></textarea></td></tr>
						<tr><th><label for="nowcrm-req-throttle"><?php esc_html_e( '公開索取連結冷卻（分鐘）', 'now-crm' ); ?></label></th>
							<td><input type="number" id="nowcrm-req-throttle" name="public_request_throttle_minutes" min="1" max="1440" value="<?php echo esc_attr( (string) (int) $settings['public_request_throttle_minutes'] ); ?>" /></td></tr>
					</tbody></table>
				</div>

				<p class="submit">
					<button type="submit" class="button button-primary"><?php esc_html_e( '儲存設定', 'now-crm' ); ?></button>
				</p>
			</form>
		</div>
		<?php
	}

	/**
	 * Save handler for `admin-post.php?action=nowcrm_save_consent`.
	 */
	public function save(): void {
		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( (string) $_POST['_wpnonce'] ) ), 'nowcrm_save_consent' ) ) {
			wp_die( esc_html__( '驗證失敗，請重新整理後再試。', 'now-crm' ), '', array( 'response' => 403 ) );
		}

		if ( ! Capabilities::can_manage_settings() ) {
			wp_die( esc_html__( '您沒有權限變更設定。', 'now-crm' ), '', array( 'response' => 403 ) );
		}

		$patch = array(
			'double_optin_enabled'            => isset( $_POST['double_optin_enabled'] ) && '1' === (string) $_POST['double_optin_enabled'],
			'public_lists_mode'               => isset( $_POST['public_lists_mode'] ) ? sanitize_key( (string) wp_unslash( $_POST['public_lists_mode'] ) ) : 'none',
			'footer_text'                     => isset( $_POST['footer_text'] ) ? wp_unslash( (string) $_POST['footer_text'] ) : '',
			'public_request_throttle_minutes' => isset( $_POST['public_request_throttle_minutes'] ) ? (int) $_POST['public_request_throttle_minutes'] : 5,
			'double_optin'                    => array(
				'email_subject'           => isset( $_POST['double_optin_email_subject'] ) ? wp_unslash( (string) $_POST['double_optin_email_subject'] ) : '',
				'email_body'              => isset( $_POST['double_optin_email_body'] ) ? wp_unslash( (string) $_POST['double_optin_email_body'] ) : '',
				'after_confirm_message'   => isset( $_POST['double_optin_after_confirm_message'] ) ? wp_unslash( (string) $_POST['double_optin_after_confirm_message'] ) : '',
				'resend_throttle_minutes' => isset( $_POST['double_optin_resend_throttle_minutes'] ) ? (int) $_POST['double_optin_resend_throttle_minutes'] : 5,
			),
		);

		if ( isset( $_POST['public_list_ids'] ) && is_array( $_POST['public_list_ids'] ) ) {
			$patch['public_list_ids'] = array_map( 'intval', wp_unslash( $_POST['public_list_ids'] ) );
		} else {
			$patch['public_list_ids'] = array();
		}

		Config::update( $patch );

		$modes    = isset( $_POST['list_mode'] ) && is_array( $_POST['list_mode'] ) ? wp_unslash( $_POST['list_mode'] ) : array();
		$subjects = isset( $_POST['list_subject'] ) && is_array( $_POST['list_subject'] ) ? wp_unslash( $_POST['list_subject'] ) : array();
		$bodies   = isset( $_POST['list_body'] ) && is_array( $_POST['list_body'] ) ? wp_unslash( $_POST['list_body'] ) : array();

		foreach ( $modes as $id => $mode ) {
			$id = (int) $id;

			if ( $id <= 0 ) {
				continue;
			}

			Config::set_list_config(
				$id,
				array(
					'mode'          => sanitize_key( (string) $mode ),
					'email_subject' => (string) ( $subjects[ $id ] ?? '' ),
					'email_body'    => (string) ( $bodies[ $id ] ?? '' ),
				)
			);
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'           => self::SLUG,
					'nowcrm_notice'  => 'settings_saved',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}
}
