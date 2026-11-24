<?php
/**
 * Tools: diagnostics and Woo processing handoff.
 *
 * FluentCRM migration runs only through WP-CLI; this screen never starts or
 * advances an import and registers no admin-post handler.
 *
 * @package NowCRM
 */

namespace NowCRM\Admin;

use NowCRM\Support\Capabilities;
use NowCRM\Support\Schema;
use NowCRM\Support\Settings;
use NowCRM\Woo\ProcessingHandoff;

defined( 'ABSPATH' ) || exit;

final class ToolsPage {

	private const TAB_DIAGNOSTICS = 'diagnostics';
	private const TAB_WOO         = 'woo';

	public const FLUENTCRM_ANCHOR = 'nowcrm-fluentcrm-migration';

	/**
	 * Render the screen.
	 */
	public static function render(): void {
		if ( ! Capabilities::can_manage_settings() ) {
			wp_die( esc_html__( '沒有權限。', 'now-crm' ), '', array( 'response' => 403 ) );
		}

		$tabs = array(
			self::TAB_DIAGNOSTICS => __( '診斷', 'now-crm' ),
			self::TAB_WOO         => __( 'Woo 交接', 'now-crm' ),
		);

		$tab = Menu::query( 'tab' );

		if ( ! isset( $tabs[ $tab ] ) ) {
			$tab = self::TAB_DIAGNOSTICS;
		}
		?>
		<div class="wrap nowcrm-wrap">
			<h1><?php esc_html_e( '工具與上線檢查', 'now-crm' ); ?></h1>

			<?php self::render_notice(); ?>

			<nav class="nowcrm-contact-tabs" aria-label="<?php esc_attr_e( '工具分區', 'now-crm' ); ?>">
				<?php foreach ( $tabs as $key => $label ) : ?>
					<a class="nowcrm-contact-tabs__item"
						href="<?php echo esc_url( Menu::page_url( Menu::TOOLS_SLUG, self::TAB_DIAGNOSTICS === $key ? array() : array( 'tab' => $key ) ) ); ?>"
						<?php echo $key === $tab ? 'aria-current="page"' : ''; ?>>
						<?php echo esc_html( $label ); ?>
					</a>
				<?php endforeach; ?>
			</nav>

			<?php if ( self::TAB_DIAGNOSTICS === $tab ) : ?>
				<div class="nowcrm-panel" id="<?php echo esc_attr( self::FLUENTCRM_ANCHOR ); ?>">
					<h2><?php esc_html_e( 'FluentCRM 遷移指引（僅 WP-CLI）', 'now-crm' ); ?></h2>
					<p class="description">
						<?php esc_html_e( '遷移只透過 WP-CLI。這個頁面不會執行遷移，也沒有表單、按鈕或 admin-post 動作；舊的遷移分頁網址仍回到這個診斷分頁。', 'now-crm' ); ?>
					</p>
					<pre><code># 1. 預演：只讀取 FluentCRM 資料，不寫入聯絡人
wp nowcrm import fluentcrm --dry-run --campaigns

# 2. 檢視新增、更新、略過與失敗計數後，再正式套用
wp nowcrm import fluentcrm --apply --batch=500 --campaigns

# 3. 只在套用中斷後，接續最近一筆未完成的執行
wp nowcrm import fluentcrm --apply --batch=500 --campaigns --resume

# 4. 查詢已記錄的狀態，不會觸發匯入
wp nowcrm import status</code></pre>
					<ul class="nowcrm-meta">
						<li><span><?php esc_html_e( 'Dry-run 與 apply', 'now-crm' ); ?></span><strong><?php esc_html_e( '先預演、確認計數後再套用', 'now-crm' ); ?></strong></li>
						<li><span><?php esc_html_e( '中斷後', 'now-crm' ); ?></span><strong><?php esc_html_e( '對同一模式加上 --resume；完成後不要重跑', 'now-crm' ); ?></strong></li>
						<li><span><?php esc_html_e( '進度查詢', 'now-crm' ); ?></span><strong><?php esc_html_e( '只用 import status', 'now-crm' ); ?></strong></li>
					</ul>
					<p class="description">
						<?php esc_html_e( 'CLI 不會強制先執行 dry-run。--apply 會在該次指令立即寫入，並依 --batch 分批處理到完成。開始前請先備份，完整步驟見 docs/operations.md。', 'now-crm' ); ?>
					</p>
				</div>

				<div class="nowcrm-panel">
					<h2><?php esc_html_e( 'SES SNS 端點', 'now-crm' ); ?></h2>
					<p><code><?php echo esc_html( rest_url( 'nowcrm/v1/ses/sns' ) ); ?></code></p>
					<ul class="nowcrm-meta">
						<li><span><?php esc_html_e( '允許的 Topic ARN', 'now-crm' ); ?></span><strong><?php echo esc_html( Settings::string( 'ses_topic_arn' ) ?: __( '（未設定，端點會拒絕所有通知）', 'now-crm' ) ); ?></strong></li>
						<li><span><?php esc_html_e( '資料表版本', 'now-crm' ); ?></span><strong><?php echo esc_html( get_option( Schema::VERSION_OPTION, '—' ) . ' / ' . Schema::VERSION ); ?></strong></li>
					</ul>
					<p class="description"><?php esc_html_e( 'SNS 會驗證 Topic、簽章與 MessageId；hard bounce 與 complaint 會寫入抑制名單，transient bounce 不會永久封鎖。', 'now-crm' ); ?></p>
				</div>

				<div class="nowcrm-panel">
					<h2><?php esc_html_e( '資料表檢查', 'now-crm' ); ?></h2>
					<p class="description">
						<?php esc_html_e( '一般請求只比對已記錄的版本號，不會檢查資料表。需要確認資料表是否完整時，請按下方按鈕：外掛會重新執行建立語句（可重複執行，不會刪除資料），再檢查每張表是否存在。', 'now-crm' ); ?>
					</p>

					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="nowcrm_repair_schema" />
						<?php wp_nonce_field( 'nowcrm_repair_schema' ); ?>
						<button type="submit" class="button"><?php esc_html_e( '檢查並修正資料表', 'now-crm' ); ?></button>
					</form>
				</div>

				<div class="nowcrm-panel">
					<h2><?php esc_html_e( '寄送診斷', 'now-crm' ); ?></h2>
					<p class="description"><?php esc_html_e( '佇列統計、手動 worker 與寄送紀錄在各自頁面；這裡不重複顯示或執行寄送。', 'now-crm' ); ?></p>
					<ul class="nowcrm-meta">
						<li><span><?php esc_html_e( '寄送紀錄與抑制名單', 'now-crm' ); ?></span>
							<a href="<?php echo esc_url( Menu::page_url( Menu::EMAIL_SLUG ) ); ?>"><?php esc_html_e( '開啟寄送紀錄', 'now-crm' ); ?></a></li>
						<li><span><?php esc_html_e( '寄送診斷分頁', 'now-crm' ); ?></span>
							<a href="<?php echo esc_url( Menu::page_url( Menu::EMAIL_SLUG, array( 'tab' => EmailLogPage::TAB_DIAGNOSTICS ) ) ); ?>"><?php esc_html_e( '開啟診斷', 'now-crm' ); ?></a></li>
						<li><span><?php esc_html_e( '系統狀態與手動 worker', 'now-crm' ); ?></span>
							<a href="<?php echo esc_url( Menu::page_url( Menu::SETTINGS_SLUG, array( 'tab' => 'system' ) ) ); ?>"><?php esc_html_e( '開啟設定 → 系統', 'now-crm' ); ?></a></li>
					</ul>
				</div>
			<?php else : ?>
				<?php $handoff = new ProcessingHandoff(); ?>
				<?php $status  = $handoff->status(); ?>
				<?php
				$fluent_states = array(
					ProcessingHandoff::FLUENT_CONFLICT    => sprintf(
						/* translators: %d: published automation count. */
						__( '找到 %d 個已發佈的處理中自動化', 'now-crm' ),
						(int) $status['fluent_published']
					),
					ProcessingHandoff::FLUENT_CLEAR       => __( '未找到已發佈的處理中自動化（資料可讀）', 'now-crm' ),
					ProcessingHandoff::FLUENT_UNKNOWN     => match ( $status['fluent_reason'] ) {
						'runtime_inactive'     => __( '無法確認（FluentCRM 或 Pro 未啟用）', 'now-crm' ),
						'funnels_query_failed' => __( '無法確認（查詢失敗）', 'now-crm' ),
						default               => __( '無法確認（需人工核對）', 'now-crm' ),
					},
					ProcessingHandoff::FLUENT_UNAVAILABLE => __( '無法確認（找不到 fc_funnels）', 'now-crm' ),
				);
				$fluent_label = $fluent_states[ $status['fluent_state'] ] ?? $fluent_states[ ProcessingHandoff::FLUENT_UNKNOWN ];
				$native_on    = true === $status['woo_enabled'];
				$conflict     = ProcessingHandoff::FLUENT_CONFLICT === $status['fluent_state'];
				$unknown      = ! $conflict && ProcessingHandoff::FLUENT_CLEAR !== $status['fluent_state'];
				?>
				<div class="nowcrm-panel">
					<h2><?php esc_html_e( 'WooCommerce 訂單處理中通知', 'now-crm' ); ?></h2>
					<ul class="nowcrm-meta">
						<li><span><?php esc_html_e( 'Woo 原生 processing Email', 'now-crm' ); ?></span>
							<strong><?php echo esc_html( null === $status['woo_enabled'] ? __( '找不到', 'now-crm' ) : ( $native_on ? __( '啟用', 'now-crm' ) : __( '停用', 'now-crm' ) ) ); ?></strong></li>
						<li><span><?php esc_html_e( 'FluentCRM 外掛', 'now-crm' ); ?></span>
							<strong><?php echo esc_html( $status['fluentcrm_active'] ? __( '已啟用', 'now-crm' ) : __( '未啟用', 'now-crm' ) ); ?></strong></li>
						<li><span><?php esc_html_e( 'FluentCRM 聯絡人資料表', 'now-crm' ); ?></span>
							<strong><?php echo esc_html( $status['fluentcrm_tables'] ? __( '存在', 'now-crm' ) : __( '不存在', 'now-crm' ) ); ?></strong></li>
						<li><span><?php esc_html_e( 'FluentCRM 處理中自動化', 'now-crm' ); ?></span>
							<strong><?php echo esc_html( $fluent_label ); ?></strong></li>
						<li><span><?php esc_html_e( '目前寄送者', 'now-crm' ); ?></span>
							<strong><?php echo esc_html( self::owner_label( (string) $status['owner'] ) ); ?></strong></li>
					</ul>

					<p class="description">
						<?php esc_html_e( '安裝外掛或保留資料表，不代表 FluentCRM 正在寄送。FluentCRM 與 Pro 都啟用，且本站有已發佈的處理中流程時，才會判定為已知衝突。', 'now-crm' ); ?>
					</p>
					<p class="description">
						<?php if ( $conflict ) : ?>
							<?php esc_html_e( '下一步：先到 FluentCRM → Automations 停用或取消發佈處理中自動化，再回來啟用 Woo 原生郵件。系統不會修改 FluentCRM。', 'now-crm' ); ?>
						<?php elseif ( $unknown && $native_on ) : ?>
							<?php esc_html_e( '下一步：人工檢查其他外掛或舊流程是否仍寄送處理中通知。確認後才能停用 Woo 原生郵件。', 'now-crm' ); ?>
						<?php elseif ( $unknown ) : ?>
							<?php esc_html_e( '下一步：人工檢查其他外掛或舊流程是否仍寄送處理中通知；勾選確認後才可啟用 Woo 原生郵件。', 'now-crm' ); ?>
						<?php elseif ( $native_on ) : ?>
							<?php esc_html_e( '下一步：維持 Woo 原生郵件為唯一寄送者；不要同時啟用 FluentCRM 處理中自動化。', 'now-crm' ); ?>
						<?php else : ?>
							<?php esc_html_e( '下一步：選定唯一寄送者。NOW CRM V1 不寄送 Woo 交易信；若要由 Woo 寄送，請啟用原生郵件。', 'now-crm' ); ?>
						<?php endif; ?>
					</p>

					<?php if ( empty( $status['woo_email_exists'] ) ) : ?>
						<p class="description">
							<?php esc_html_e( '找不到 WooCommerce 原生「訂單處理中」郵件，因此這裡不提供切換。請先確認 WooCommerce 已啟用並正常載入。', 'now-crm' ); ?>
						</p>
					<?php elseif ( $conflict && ! $native_on ) : ?>
						<p class="description"><strong><?php esc_html_e( '啟用已封鎖：先停止 FluentCRM 處理中自動化，沒有變更任何設定。', 'now-crm' ); ?></strong></p>
					<?php else : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<input type="hidden" name="action" value="nowcrm_toggle_woo_processing">
							<?php wp_nonce_field( 'nowcrm_toggle_woo_processing' ); ?>
							<input type="hidden" name="enabled" value="<?php echo $native_on ? '0' : '1'; ?>">
							<?php if ( ! $native_on && $unknown ) : ?>
								<p><label>
									<input type="checkbox" name="acknowledge_unknown_owner" value="1">
									<?php esc_html_e( '我確認已人工檢查其他外掛或舊流程，沒有仍會寄送處理中通知的寄送者。', 'now-crm' ); ?>
								</label></p>
							<?php elseif ( $native_on ) : ?>
								<p><label>
									<input type="checkbox" name="acknowledge_alternative_owner" value="1">
									<?php esc_html_e( '我確認停用後由 FluentCRM 或另一個指定流程寄送，且只有一個寄送者。', 'now-crm' ); ?>
								</label></p>
							<?php endif; ?>
							<button type="submit" class="button <?php echo $native_on ? '' : 'button-primary'; ?>">
								<?php echo esc_html( $native_on ? __( '停用 Woo 原生處理中信', 'now-crm' ) : __( '啟用 Woo 原生處理中信', 'now-crm' ) ); ?>
							</button>
						</form>
					<?php endif; ?>

					<p class="description">
						<?php esc_html_e( 'NOW CRM V1 不寄送 Woo 交易信，也不提供交易信編輯器（另見 Issue #2）。完整切換與回復步驟見 docs/operations.md 的 Woo processing 交接章節。', 'now-crm' ); ?>
					</p>
				</div>

				<div class="nowcrm-panel">
					<h2><?php esc_html_e( 'Woo 歷史缺口', 'now-crm' ); ?></h2>
					<p class="description">
						<?php esc_html_e( '目前沒有可安全重用的瀏覽器工作服務，因此這一項只有 WP-CLI，沒有按鈕。指令會建立 transactional 聯絡人並補上訂單關聯，不會自動取得行銷寄送資格。', 'now-crm' ); ?>
					</p>
					<pre><code>wp nowcrm import woo --dry-run
wp nowcrm import woo --apply</code></pre>
					<p class="description"><?php esc_html_e( '先在正式站或可讀取正式 DB 的環境備份，再比對 dry-run 缺口數。', 'now-crm' ); ?></p>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Operator-facing owner label.
	 */
	private static function owner_label( string $owner ): string {
		$labels = array(
			'woo_native'           => __( 'Woo 原生（已確認）', 'now-crm' ),
			'woo_native_unverified' => __( 'Woo 原生（其他寄送者未確認）', 'now-crm' ),
			'fluent'               => __( 'FluentCRM 處理中自動化', 'now-crm' ),
			'none_or_other'        => __( '未找到已知寄送者', 'now-crm' ),
			'unknown'              => __( '無法確認', 'now-crm' ),
		);

		return $labels[ $owner ] ?? $labels['unknown'];
	}

	/**
	 * Print the allow-listed notice requested by this screen.
	 */
	private static function render_notice(): void {
		$notice = isset( $_GET['nowcrm_notice'] ) && is_string( $_GET['nowcrm_notice'] ) ? sanitize_key( wp_unslash( $_GET['nowcrm_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$notices = array(
			'woo_processing_on'          => array( 'success', __( 'WooCommerce 原生「訂單處理中」郵件已啟用。', 'now-crm' ) ),
			'woo_processing_off'         => array( 'warning', __( 'WooCommerce 原生「訂單處理中」郵件已停用。', 'now-crm' ) ),
			'woo_processing_unavailable' => array( 'error', __( '找不到 WooCommerce 原生「訂單處理中」郵件，沒有變更任何設定。', 'now-crm' ) ),
			'woo_processing_conflict'    => array( 'error', __( '找到已發佈的 FluentCRM 處理中自動化，沒有變更任何設定。請先停止該自動化。', 'now-crm' ) ),
			'woo_processing_ack_required' => array( 'error', __( '無法確認其他寄送者，沒有變更任何設定。請先人工檢查並勾選確認。', 'now-crm' ) ),
			'woo_processing_alternative_required' => array( 'error', __( '停用前必須確認另有唯一寄送者；沒有變更任何設定。', 'now-crm' ) ),
			'schema_ok'                  => array( 'success', __( '所有 NOW CRM 資料表都存在。', 'now-crm' ) ),
			'schema_missing'             => array( 'error', __( '仍有 NOW CRM 資料表缺失，請確認資料庫使用者有建立資料表的權限後再試一次。', 'now-crm' ) ),
		);

		if ( ! isset( $notices[ $notice ] ) ) {
			return;
		}

		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $notices[ $notice ][0] ),
			esc_html( $notices[ $notice ][1] )
		);
	}
}
