<?php
/**
 * Settings screen: general/Woo, email/SES, custom fields, system.
 *
 * Each tab owns one save scope. The abandoned-cart toggle and reminder template
 * stay on the cart screen; this page links there instead of duplicating them.
 *
 * @package NowCRM
 */

namespace NowCRM\Admin;

use NowCRM\Contacts\ContactRepository;
use NowCRM\Email\Queue;
use NowCRM\Orders\OrderLinkRepository;
use NowCRM\Support\Capabilities;
use NowCRM\Support\Schema;
use NowCRM\Support\Settings;

defined( 'ABSPATH' ) || exit;

final class SettingsPage {

	private const TAB_GENERAL = 'general';
	private const TAB_EMAIL   = 'email';
	private const TAB_FIELDS  = 'fields';
	private const TAB_SYSTEM  = 'system';

	/**
	 * Render the settings form and system status.
	 */
	public static function render(): void {
		if ( ! Capabilities::can_manage_settings() ) {
			wp_die(
				esc_html__( '您沒有權限檢視設定。', 'now-crm' ),
				'',
				array( 'response' => 403 )
			);
		}

		$tabs = array(
			self::TAB_GENERAL => __( '一般與 WooCommerce', 'now-crm' ),
			self::TAB_EMAIL   => __( '寄送與 SES', 'now-crm' ),
			self::TAB_FIELDS  => __( '自訂欄位', 'now-crm' ),
			self::TAB_SYSTEM  => __( '系統', 'now-crm' ),
		);

		$tab = Menu::query( 'tab' );

		if ( ! isset( $tabs[ $tab ] ) ) {
			$tab = self::TAB_GENERAL;
		}

		$settings = Settings::all();
		$form_url = admin_url( 'admin-post.php' );
		?>
		<div class="wrap nowcrm-wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( '設定', 'now-crm' ); ?></h1>
			<hr class="wp-header-end" />

			<?php Actions::render_notice(); ?>

			<nav class="nowcrm-contact-tabs" aria-label="<?php esc_attr_e( '設定分區', 'now-crm' ); ?>">
				<?php foreach ( $tabs as $key => $label ) : ?>
					<a class="nowcrm-contact-tabs__item"
						href="<?php echo esc_url( Menu::page_url( Menu::SETTINGS_SLUG, self::TAB_GENERAL === $key ? array() : array( 'tab' => $key ) ) ); ?>"
						<?php echo $key === $tab ? 'aria-current="page"' : ''; ?>>
						<?php echo esc_html( $label ); ?>
					</a>
				<?php endforeach; ?>
			</nav>

			<?php if ( self::TAB_GENERAL === $tab ) : ?>
				<form method="post" action="<?php echo esc_url( $form_url ); ?>" class="nowcrm-panel">
					<input type="hidden" name="action" value="nowcrm_save_settings" />
					<input type="hidden" name="redirect_page" value="<?php echo esc_attr( Menu::SETTINGS_SLUG ); ?>" />
					<input type="hidden" name="settings_scope" value="general" />
					<?php wp_nonce_field( 'nowcrm_save_settings' ); ?>

					<h2><?php esc_html_e( '一般與 WooCommerce', 'now-crm' ); ?></h2>
					<div class="nowcrm-toggles">
						<div class="nowcrm-toggle">
							<label for="nowcrm-setting-woo-sync-enabled">
								<input type="checkbox" id="nowcrm-setting-woo-sync-enabled" name="woo_sync_enabled" value="1"
									<?php checked( (bool) $settings['woo_sync_enabled'] ); ?> />
								<span class="nowcrm-toggle__label"><?php esc_html_e( '啟用 WooCommerce 訂單同步', 'now-crm' ); ?></span>
							</label>
							<p class="description"><?php esc_html_e( '訂單建立或更新時自動建立／更新聯絡人與訂單連結。既有訂單缺口回填只有 WP-CLI，指令見工具頁。', 'now-crm' ); ?></p>
						</div>
						<div class="nowcrm-toggle">
							<label for="nowcrm-setting-auto-subscribe">
								<input type="checkbox" id="nowcrm-setting-auto-subscribe" name="auto_subscribe_new_woo_contacts" value="1"
									<?php checked( (bool) $settings['auto_subscribe_new_woo_contacts'] ); ?> />
								<span class="nowcrm-toggle__label"><?php esc_html_e( '新 WooCommerce 顧客自動訂閱', 'now-crm' ); ?></span>
							</label>
							<p class="description"><?php esc_html_e( '只影響新聯絡人。未顯示結帳勾選框代表沒有做出選擇，不是拒絕；這時若未要求雙重確認，才套用自動訂閱。', 'now-crm' ); ?></p>
						</div>
						<div class="nowcrm-toggle">
							<label for="nowcrm-setting-checkout-consent">
								<input type="checkbox" id="nowcrm-setting-checkout-consent" name="show_checkout_subscription_checkbox" value="1"
									<?php checked( (bool) $settings['show_checkout_subscription_checkbox'] ); ?> />
								<span class="nowcrm-toggle__label"><?php esc_html_e( '結帳頁顯示訂閱勾選框', 'now-crm' ); ?></span>
							</label>
							<p class="description"><?php esc_html_e( '在 Classic 與區塊結帳顯示選填的「訂閱電子報」，預設不勾選。勾選是明確同意，取消勾選是明確拒絕；不會改變既有聯絡人的狀態。', 'now-crm' ); ?></p>
						</div>
					</div>

					<p class="description">
						<?php esc_html_e( '雙重確認範圍由「同意與偏好」的全域及名單政策決定。需要確認時，只有明確勾選的新聯絡人會排確認信，確認前維持 transactional（僅交易信）。未要求確認時，明確勾選的新聯絡人直接訂閱。', 'now-crm' ); ?>
					</p>

					<p class="submit">
						<button type="submit" class="button button-primary"><?php esc_html_e( '儲存設定', 'now-crm' ); ?></button>
					</p>
				</form>

				<div class="nowcrm-panel">
					<h2><?php esc_html_e( '相關設定', 'now-crm' ); ?></h2>
					<p class="description"><?php esc_html_e( '這些畫面各自管理自己的設定；此頁只提供入口，不會送出它們的欄位。', 'now-crm' ); ?></p>
					<ul class="nowcrm-meta">
						<li><span><?php esc_html_e( '同意與偏好', 'now-crm' ); ?></span>
							<a href="<?php echo esc_url( Menu::page_url( ConsentSettings::SLUG ) ); ?>"><?php esc_html_e( '雙重確認、偏好中心、合規頁尾', 'now-crm' ); ?></a></li>
						<li><span><?php esc_html_e( '購物車放棄', 'now-crm' ); ?></span>
							<a href="<?php echo esc_url( Menu::page_url( Menu::CART_SLUG, array( 'tab' => 'settings' ) ) ); ?>"><?php esc_html_e( '功能開關、閒置與提醒規則、提醒信內容', 'now-crm' ); ?></a></li>
						<li><span><?php esc_html_e( 'Incoming Webhooks', 'now-crm' ); ?></span>
							<a href="<?php echo esc_url( Menu::page_url( WebhooksPage::SLUG ) ); ?>"><?php esc_html_e( '對外通知與簽章', 'now-crm' ); ?></a></li>
						<li><span><?php esc_html_e( 'API 金鑰', 'now-crm' ); ?></span>
							<a href="<?php echo esc_url( Menu::page_url( ApiKeysPage::SLUG ) ); ?>"><?php esc_html_e( 'REST 存取金鑰', 'now-crm' ); ?></a></li>
						<li><span><?php esc_html_e( '工具與遷移', 'now-crm' ); ?></span>
							<a href="<?php echo esc_url( Menu::page_url( Menu::TOOLS_SLUG ) . '#' . ToolsPage::FLUENTCRM_ANCHOR ); ?>"><?php esc_html_e( 'FluentCRM 匯入指引、資料表檢查、Woo 交接', 'now-crm' ); ?></a></li>
					</ul>
				</div>
			<?php elseif ( self::TAB_EMAIL === $tab ) : ?>
				<form method="post" action="<?php echo esc_url( $form_url ); ?>" class="nowcrm-panel">
					<input type="hidden" name="action" value="nowcrm_save_settings" />
					<input type="hidden" name="redirect_page" value="<?php echo esc_attr( Menu::SETTINGS_SLUG ); ?>" />
					<input type="hidden" name="settings_scope" value="email" />
					<?php wp_nonce_field( 'nowcrm_save_settings' ); ?>

					<h2><?php esc_html_e( '寄送', 'now-crm' ); ?></h2>
					<div class="nowcrm-toggles">
						<div class="nowcrm-toggle">
							<label for="nowcrm-setting-sending">
								<input type="checkbox" id="nowcrm-setting-sending" name="email_sending_enabled" value="1"
									<?php checked( (bool) $settings['email_sending_enabled'] ); ?> />
								<span class="nowcrm-toggle__label"><?php esc_html_e( '啟用實際寄送', 'now-crm' ); ?></span>
							</label>
							<p class="description"><?php esc_html_e( '關閉時佇列只累積不寄出。開啟後交給 wp_mail() 與站台設定的寄信外掛（例如 FluentSMTP）寄出。', 'now-crm' ); ?></p>
						</div>
						<div class="nowcrm-toggle">
							<label for="nowcrm-setting-campaign-tracking">
								<input type="checkbox" id="nowcrm-setting-campaign-tracking" name="campaign_tracking_enabled" value="1"
									<?php checked( (bool) $settings['campaign_tracking_enabled'] ); ?> />
								<span class="nowcrm-toggle__label"><?php esc_html_e( '啟用 Campaign 開信／點擊追蹤', 'now-crm' ); ?></span>
							</label>
							<p class="description"><?php esc_html_e( '只控制 Campaign、購物車與自訂郵件。開信像素可能被 Apple／Gmail 預取，只作趨勢參考。', 'now-crm' ); ?></p>
						</div>
						<div class="nowcrm-toggle">
							<label for="nowcrm-setting-sequence-tracking">
								<input type="checkbox" id="nowcrm-setting-sequence-tracking" name="sequence_tracking_enabled" value="1"
									<?php checked( (bool) $settings['sequence_tracking_enabled'] ); ?> />
								<span class="nowcrm-toggle__label"><?php esc_html_e( '啟用序列開信／點擊追蹤', 'now-crm' ); ?></span>
							</label>
							<p class="description"><?php esc_html_e( '預設關閉。只有開啟後新排入的序列佇列項目會帶追蹤標記；之後關閉就不再注入，舊項目也不會補追蹤。開信像素可能被預取。', 'now-crm' ); ?></p>
						</div>
					</div>

					<table class="form-table" role="presentation"><tbody>
						<tr>
							<th><label for="nowcrm-transport"><?php esc_html_e( 'Local transport', 'now-crm' ); ?></label></th>
							<td>
								<select id="nowcrm-transport" name="email_transport">
									<option value="wp_mail" <?php selected( $settings['email_transport'], 'wp_mail' ); ?>><?php esc_html_e( 'wp_mail（正式）', 'now-crm' ); ?></option>
									<option value="capture" <?php selected( $settings['email_transport'], 'capture' ); ?>><?php esc_html_e( 'Capture（只寫入本機紀錄，不寄出）', 'now-crm' ); ?></option>
								</select>
								<p class="description"><?php esc_html_e( 'Capture 只把結果寫入本機紀錄，不呼叫 wp_mail()，也不會有真實 SES 回條。wp_mail 會交給站台寄信程式（例如 FluentSMTP）再轉交 SES；實際送達只由 SES 回條補充。', 'now-crm' ); ?></p>
							</td>
						</tr>
						<tr><th><label for="nowcrm-from-name"><?php esc_html_e( '寄件者名稱', 'now-crm' ); ?></label></th>
							<td><input type="text" id="nowcrm-from-name" class="regular-text" name="email_from_name" value="<?php echo esc_attr( (string) $settings['email_from_name'] ); ?>"></td></tr>
						<tr><th><label for="nowcrm-from-address"><?php esc_html_e( '寄件者 Email', 'now-crm' ); ?></label></th>
							<td><input type="email" id="nowcrm-from-address" class="regular-text" name="email_from_address" value="<?php echo esc_attr( (string) $settings['email_from_address'] ); ?>">
								<p class="description"><?php esc_html_e( '需為 SES 已驗證的身分；空白時使用 wordpress@站台網域。', 'now-crm' ); ?></p></td></tr>
						<tr><th><label for="nowcrm-reply-to"><?php esc_html_e( 'Reply-To', 'now-crm' ); ?></label></th>
							<td><input type="email" id="nowcrm-reply-to" class="regular-text" name="email_reply_to" value="<?php echo esc_attr( (string) $settings['email_reply_to'] ); ?>"></td></tr>
						<tr><th><label for="nowcrm-batch"><?php esc_html_e( '每批寄送數', 'now-crm' ); ?></label></th>
							<td><input type="number" id="nowcrm-batch" name="email_batch_size" min="1" max="500" value="<?php echo esc_attr( (string) $settings['email_batch_size'] ); ?>"></td></tr>
						<tr><th><label for="nowcrm-throttle"><?php esc_html_e( '每秒上限', 'now-crm' ); ?></label></th>
							<td><input type="number" id="nowcrm-throttle" name="email_throttle_per_second" min="1" max="100" value="<?php echo esc_attr( (string) $settings['email_throttle_per_second'] ); ?>">
								<p class="description"><?php esc_html_e( '站內預設 15/sec；請保留 Woo 交易信容量，實際 SES 配額需另行確認。', 'now-crm' ); ?></p></td></tr>
						<tr><th><label for="nowcrm-attempts"><?php esc_html_e( '新郵件最大嘗試次數', 'now-crm' ); ?></label></th>
							<td><input type="number" id="nowcrm-attempts" name="email_max_attempts" min="1" max="10" value="<?php echo esc_attr( (string) $settings['email_max_attempts'] ); ?>" aria-describedby="nowcrm-attempts-desc">
								<p class="description" id="nowcrm-attempts-desc"><?php esc_html_e( '只在建立新佇列項目時寫入，既有項目保留原上限。已標記 unknown 的項目代表交握結果不明，不會因調高次數自動重試；需在寄送紀錄逐筆重試，且可能重複寄出。', 'now-crm' ); ?></p></td></tr>
						<tr><th><label for="nowcrm-lease"><?php esc_html_e( 'Worker lease（分鐘）', 'now-crm' ); ?></label></th>
							<td><input type="number" id="nowcrm-lease" name="email_lease_minutes" min="1" max="60" value="<?php echo esc_attr( (string) $settings['email_lease_minutes'] ); ?>"></td></tr>
					</tbody></table>

					<h2><?php esc_html_e( 'SES SNS 回條', 'now-crm' ); ?></h2>
					<table class="form-table" role="presentation"><tbody>
						<tr><th><label for="nowcrm-ses-configuration-set"><?php esc_html_e( 'Configuration Set（選填）', 'now-crm' ); ?></label></th>
							<td><input type="text" id="nowcrm-ses-configuration-set" class="regular-text" name="ses_configuration_set" maxlength="64" pattern="[A-Za-z0-9_-]*" value="<?php echo esc_attr( (string) $settings['ses_configuration_set'] ); ?>" aria-describedby="nowcrm-ses-configuration-set-desc">
								<p class="description" id="nowcrm-ses-configuration-set-desc"><?php esc_html_e( '留空時使用 SES 身分或帳號的預設 Configuration Set。實際寄送會以 X-SES-CONFIGURATION-SET 標頭帶入此名稱；Capture 不寄出，也不會帶入。名稱必須先在 AWS 建立；不存在或未掛事件目的地時，SES 可能拒絕寄送。這裡只記錄名稱，不會建立或修改 AWS 設定。', 'now-crm' ); ?></p></td></tr>
						<tr><th><label for="nowcrm-ses-topic"><?php esc_html_e( '允許的 Topic ARN', 'now-crm' ); ?></label></th>
							<td><input type="text" id="nowcrm-ses-topic" class="large-text" name="ses_topic_arn" value="<?php echo esc_attr( (string) $settings['ses_topic_arn'] ); ?>" aria-describedby="nowcrm-ses-topic-desc">
								<p class="description" id="nowcrm-ses-topic-desc"><?php esc_html_e( '逗號分隔多個 ARN；空白或不相符時，SNS 端點拒絕所有通知。端點只接受簽章通過、Topic 在允許清單內的通知。', 'now-crm' ); ?></p></td></tr>
					</tbody></table>

					<p class="description">
						<?php esc_html_e( '回條只補充寄送紀錄的送達狀態，不改佇列狀態。只有簽章通過、Topic 在允許清單內，且能對應到 NOW 佇列中寄送 Job 的通知，才會更新該筆送達狀態；抑制名單只由其中已對應的硬退信與投訴更新。「已送出」只代表交給站台寄信程式；沒有回條不代表已送達。Capture 不寄出，也不會有真實 SES 回條。', 'now-crm' ); ?>
					</p>
					<p class="description">
						<a href="<?php echo esc_url( Menu::page_url( Menu::EMAIL_SLUG, array( 'tab' => EmailLogPage::TAB_DIAGNOSTICS ) ) ); ?>"><?php esc_html_e( '查看寄送紀錄的診斷', 'now-crm' ); ?></a>
						<?php esc_html_e( '：SES 通知計數、worker 上次成功與下次排程。本頁不執行 worker，也不寄送測試信。', 'now-crm' ); ?>
					</p>

					<p class="submit">
						<button type="submit" class="button button-primary"><?php esc_html_e( '儲存設定', 'now-crm' ); ?></button>
					</p>
				</form>
			<?php elseif ( self::TAB_FIELDS === $tab ) : ?>
				<?php CustomFields::render_settings_panel(); ?>
			<?php else : ?>
				<?php
				$contacts   = new ContactRepository();
				$orders     = new OrderLinkRepository();
				$counts     = $contacts->count_by_status();
				$total      = array_sum( $counts );
				$order_rows = $orders->count();
				$counted    = $orders->count( true );
				$installed  = (string) get_option( Schema::VERSION_OPTION, '' );
				$queue      = ( new Queue() )->status();
				?>
				<div class="nowcrm-panel">
					<h2><?php esc_html_e( '系統狀態', 'now-crm' ); ?></h2>
					<ul class="nowcrm-meta">
						<li><span><?php esc_html_e( '資料表版本', 'now-crm' ); ?></span>
							<strong><?php echo esc_html( ( '' !== $installed ? $installed : '—' ) . ' / ' . Schema::VERSION ); ?></strong></li>
						<li><span><?php esc_html_e( '聯絡人總數', 'now-crm' ); ?></span><strong><?php echo esc_html( (string) (int) $total ); ?></strong></li>
						<li><span><?php esc_html_e( '訂單連結數', 'now-crm' ); ?></span><strong><?php echo esc_html( (string) $order_rows ); ?></strong></li>
						<li><span><?php esc_html_e( '計入營收的訂單', 'now-crm' ); ?></span><strong><?php echo esc_html( (string) $counted ); ?></strong></li>
						<li><span><?php esc_html_e( '佇列（queued／accepted／failed）', 'now-crm' ); ?></span>
							<strong><?php echo esc_html( $queue['queued'] . ' / ' . $queue['accepted'] . ' / ' . $queue['failed'] ); ?></strong></li>
					</ul>
					<p class="description">
						<?php esc_html_e( '寄送診斷、抑制名單與手動 worker 都在寄送紀錄的「診斷」分頁；此頁只顯示狀態，不重複執行寄送。', 'now-crm' ); ?>
					</p>
				</div>

				<div class="nowcrm-panel">
					<h2><?php esc_html_e( '手動 worker 與寄送診斷', 'now-crm' ); ?></h2>
					<p class="description"><?php esc_html_e( '手動處理一批佇列仍需要「啟用實際寄送」。處理後會留在寄送紀錄。', 'now-crm' ); ?></p>
					<p><a class="button" href="<?php echo esc_url( Menu::page_url( Menu::EMAIL_SLUG, array( 'tab' => EmailLogPage::TAB_DIAGNOSTICS ) ) ); ?>"><?php esc_html_e( '開啟寄送診斷與手動 worker', 'now-crm' ); ?></a></p>
				</div>

				<div class="nowcrm-panel nowcrm-panel--hint">
					<h2><?php esc_html_e( 'CLI', 'now-crm' ); ?></h2>
					<pre><code>wp nowcrm status
wp nowcrm email status
wp nowcrm email worker
wp nowcrm campaign list
wp nowcrm cart list</code></pre>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}
}
