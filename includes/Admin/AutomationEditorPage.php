<?php
/**
 * Server-rendered automation editor.
 *
 * The canvas is a compact sequence of summary cards. Each card body is rendered
 * inside the form (no-JS fallback) and moved into the right-side drawer by the
 * inline script; values and listeners stay on the original nodes. The browser
 * posts one JSON step array; the repository validates it again.
 *
 * @package NowCRM
 */

namespace NowCRM\Admin;

use NowCRM\Automation\ActionRegistry;
use NowCRM\Automation\AutomationRepository;
use NowCRM\Automation\RunRepository;
use NowCRM\Automation\TriggerRegistry;
use NowCRM\Cart\Automation as CartAutomation;
use NowCRM\Companies\CompanyRepository;
use NowCRM\Contacts\AdvancedFilters;
use NowCRM\Contacts\Status;
use NowCRM\Sequences\SequenceRepository;
use NowCRM\Support\Capabilities;
use NowCRM\Terms\TermRepository;

defined( 'ABSPATH' ) || exit;

final class AutomationEditorPage {

	/**
	 * Terms for the editor selects.
	 *
	 * @var array<int, array<string, mixed>>|null
	 */
	private static ?array $terms_cache = null;

	/**
	 * Companies for the editor selects.
	 *
	 * @var array<int, array<string, mixed>>|null
	 */
	private static ?array $companies_cache = null;

	/**
	 * Automation ID of the editor page, for the shared email tools scope.
	 */
	private static int $source_id = 0;

	/**
	 * Active sequences for the action select.
	 *
	 * @var array<int, array<string, mixed>>|null
	 */
	private static ?array $sequences_cache = null;

	/**
	 * Render the editor.
	 */
	public static function render(): void {
		if ( ! Capabilities::can_manage_contacts() ) {
			wp_die( esc_html__( '沒有權限。', 'now-crm' ), '', array( 'response' => 403 ) );
		}

		$id         = absint( Menu::query( 'id' ) );
		$repository = new AutomationRepository();
		$automation = $repository->get( $id );

		if ( ! $automation ) {
			printf(
				'<div class="wrap nowcrm-wrap"><div class="notice notice-error"><p>%s</p> <a href="%s">%s</a></div></div>',
				esc_html__( '找不到自動化。', 'now-crm' ),
				esc_url( Menu::page_url( Menu::AUTOMATIONS_SLUG ) ),
				esc_html__( '返回列表', 'now-crm' )
			);

			return;
		}

		$steps    = $repository->nested_steps( $id );
		$config   = (array) $automation['trigger_config'];
		$entry    = (array) $automation['entry_config'];
		$counts   = $repository->run_counts( $id );
		$terms    = self::terms();
		self::$source_id = $id;
		$trigger_key = (string) $automation['trigger_key'];
		$status      = (string) $automation['status'];
		$live          = (int) ( $counts[ RunRepository::STATUS_ACTIVE ] ?? 0 ) + (int) ( $counts[ RunRepository::STATUS_WAITING ] ?? 0 );
		$filter_groups = self::trigger_filter_groups( $trigger_key );

		$form_url = admin_url( 'admin-post.php' );
		$list_url = Menu::page_url( Menu::AUTOMATIONS_SLUG );
		$runs_url = Menu::page_url( Menu::AUTOMATION_RUNS_SLUG, array( 'id' => $id ) );
		$chips    = self::trigger_chips( $config, $entry );
		$cart_owner = TriggerRegistry::CART_ABANDONED === $trigger_key
			? CartAutomation::resolve_owner()
			: array();
		$trigger_groups_data = array();

		foreach ( array_keys( TriggerRegistry::triggers() ) as $key ) {
			$trigger_groups_data[ $key ] = self::trigger_filter_groups( $key );
		}

		$status_labels = array();

		foreach ( Status::all() as $status_option ) {
			$status_labels[ $status_option ] = Status::label( $status_option );
		}

		$chip_data = array(
			'statuses'  => $status_labels,
			'terms'     => self::term_name_map(),
			'termTypes' => array(
				TermRepository::TYPE_LIST => __( '名單', 'now-crm' ),
				TermRepository::TYPE_TAG  => __( '標籤', 'now-crm' ),
			),
			'runOnce'   => __( '每位聯絡人只執行一次', 'now-crm' ),
			'reentry'   => __( '完成後可重新進入', 'now-crm' ),
			'forceRun'  => __( '未訂閱也執行動作', 'now-crm' ),
		);

		$metrics = array(
			RunRepository::STATUS_ACTIVE    => __( '執行中', 'now-crm' ),
			RunRepository::STATUS_WAITING   => __( '等待中', 'now-crm' ),
			RunRepository::STATUS_COMPLETED => __( '已完成', 'now-crm' ),
			RunRepository::STATUS_FAILED    => __( '失敗', 'now-crm' ),
		);
		?>
		<div class="wrap nowcrm-wrap nowcrm-automation">
			<div class="nowcrm-automation__topbar">
				<nav class="nowcrm-automation__crumbs" aria-label="<?php esc_attr_e( '麵包屑', 'now-crm' ); ?>">
					<a href="<?php echo esc_url( $list_url ); ?>"><?php esc_html_e( '自動化', 'now-crm' ); ?></a>
					<span class="nowcrm-automation__crumb-sep" aria-hidden="true">/</span>
					<span><?php echo esc_html( (string) $automation['name'] ); ?></span>
				</nav>

				<div class="nowcrm-automation__topbar-actions">
					<span class="nowcrm-automation__status nowcrm-automation__status--<?php echo esc_attr( $status ); ?>">
						<span class="nowcrm-automation__status-dot" aria-hidden="true"></span>
						<?php echo esc_html( AutomationListPage::status_label( $status ) ); ?>
					</span>

					<span class="nowcrm-automation__live<?php echo $live > 0 ? ' is-live' : ''; ?>" data-role="live-indicator">
						<span class="nowcrm-automation__live-dot" aria-hidden="true"></span>
						<?php
						printf(
							/* translators: %d: active plus waiting automation runs. */
							esc_html__( '%d 執行中／等待中', 'now-crm' ),
							(int) $live
						);
						?>
					</span>

					<a class="button" href="<?php echo esc_url( $runs_url ); ?>"><?php esc_html_e( '執行紀錄', 'now-crm' ); ?></a>

					<?php if ( AutomationRepository::STATUS_ACTIVE === $status ) : ?>
						<form method="post" action="<?php echo esc_url( $form_url ); ?>" class="nowcrm-automation__inline-form">
							<input type="hidden" name="action" value="nowcrm_set_automation_status" />
							<input type="hidden" name="id" value="<?php echo esc_attr( (string) $id ); ?>" />
							<input type="hidden" name="return" value="editor" />
							<input type="hidden" name="status" value="paused" />
							<?php wp_nonce_field( 'nowcrm_set_automation_status' ); ?>
							<button type="submit" class="button"><?php esc_html_e( '暫停', 'now-crm' ); ?></button>
						</form>
					<?php else : ?>
						<button type="submit" form="nowcrm-automation-form" class="button" name="save_status" value="active"
							data-automation-activate><?php esc_html_e( '啟用', 'now-crm' ); ?></button>
					<?php endif; ?>

					<details class="nowcrm-automation__menu">
						<summary class="button"><?php esc_html_e( '更多', 'now-crm' ); ?></summary>
						<div class="nowcrm-automation__menu-panel">
							<a href="<?php echo esc_url( $runs_url ); ?>"><?php esc_html_e( '執行紀錄', 'now-crm' ); ?></a>
							<?php if ( AutomationRepository::STATUS_DRAFT !== $status ) : ?>
								<form method="post" action="<?php echo esc_url( $form_url ); ?>">
									<input type="hidden" name="action" value="nowcrm_set_automation_status" />
									<input type="hidden" name="id" value="<?php echo esc_attr( (string) $id ); ?>" />
									<input type="hidden" name="return" value="editor" />
									<input type="hidden" name="status" value="draft" />
									<?php wp_nonce_field( 'nowcrm_set_automation_status' ); ?>
									<button type="submit" class="button-link"><?php esc_html_e( '轉為草稿', 'now-crm' ); ?></button>
								</form>
							<?php endif; ?>
							<form method="post" action="<?php echo esc_url( $form_url ); ?>">
								<input type="hidden" name="action" value="nowcrm_duplicate_automation" />
								<input type="hidden" name="id" value="<?php echo esc_attr( (string) $id ); ?>" />
								<?php wp_nonce_field( 'nowcrm_duplicate_automation' ); ?>
								<button type="submit" class="button-link"><?php esc_html_e( '複製自動化', 'now-crm' ); ?></button>
							</form>
							<form method="post" action="<?php echo esc_url( $form_url ); ?>" onsubmit="return confirm('<?php echo esc_js( __( '刪除自動化也會刪除所有執行紀錄。確定要刪除嗎？', 'now-crm' ) ); ?>');" data-confirm="<?php echo esc_attr( __( '刪除自動化也會刪除所有執行紀錄，任何未儲存的變更都不會保留。確定要刪除嗎？', 'now-crm' ) ); ?>">
								<input type="hidden" name="action" value="nowcrm_delete_automation" />
								<input type="hidden" name="id" value="<?php echo esc_attr( (string) $id ); ?>" />
								<?php wp_nonce_field( 'nowcrm_delete_automation' ); ?>
								<button type="submit" class="button-link button-link-delete"><?php esc_html_e( '刪除自動化', 'now-crm' ); ?></button>
							</form>
						</div>
					</details>

					<button type="submit" form="nowcrm-automation-form" class="button button-primary" data-role="save-button" data-testid="automation-save"><?php esc_html_e( '儲存', 'now-crm' ); ?></button>
					<span class="nowcrm-automation__save-state" data-save-state role="status"><?php esc_html_e( '已儲存', 'now-crm' ); ?></span>
				</div>
			</div>

			<header class="nowcrm-automation__hero">
				<h1 class="nowcrm-automation__title"><?php echo esc_html( (string) $automation['name'] ); ?></h1>
				<p class="nowcrm-automation__meta">
					<span><?php echo esc_html( AutomationListPage::trigger_label( $trigger_key ) ); ?></span>
					<span class="nowcrm-automation__meta-sep" aria-hidden="true">·</span>
					<span>
						<?php
						printf(
							/* translators: %s: last update time. */
							esc_html__( '更新 %s', 'now-crm' ),
							esc_html( Menu::format_datetime( (string) $automation['updated_at'] ) )
						);
						?>
					</span>
				</p>
			</header>

			<?php Actions::render_notice(); ?>

			<?php if ( TriggerRegistry::CART_ABANDONED === $trigger_key ) : ?>
				<?php
				$owner_id     = (int) ( $cart_owner['automation_id'] ?? 0 );
				$owner_valid  = ! empty( $cart_owner['valid'] );
				$managed_flow = CartAutomation::is_managed( $id );
				$settings_url = Menu::page_url( Menu::CART_SLUG, array( 'tab' => CartPage::TAB_SETTINGS ) );
				?>
				<div class="nowcrm-panel" data-testid="cart-owner">
					<h2><?php esc_html_e( '購物車提醒擁有者', 'now-crm' ); ?></h2>
					<p>
						<?php if ( $managed_flow ) : ?>
							<?php esc_html_e( '這是系統託管的預設購物車提醒流程；未指定自訂流程的購物車會使用它。', 'now-crm' ); ?>
						<?php elseif ( $owner_id === $id ) : ?>
							<strong><?php esc_html_e( '這是目前選用的購物車提醒流程。', 'now-crm' ); ?></strong>
						<?php elseif ( $owner_id > 0 && ! $owner_valid ) : ?>
							<?php esc_html_e( '目前設定的購物車流程無法使用（已暫停、找不到或缺少 Email 步驟）。在你更新設定前，系統不會自動改用其他流程。', 'now-crm' ); ?>
						<?php elseif ( $owner_id > 0 ) : ?>
							<?php
							echo esc_html(
								sprintf(
									/* translators: %d: automation ID. */
									__( '目前購物車提醒由自動化 #%d 處理；若要改用本流程，請到購物車設定選取。', 'now-crm' ),
									$owner_id
								)
							);
							?>
						<?php else : ?>
							<?php esc_html_e( '目前使用受管理預設流程；若要改用本流程，請到購物車設定選取。', 'now-crm' ); ?>
						<?php endif; ?>
						<?php if ( Capabilities::can_manage_settings() ) : ?>
							<a href="<?php echo esc_url( $settings_url ); ?>"><?php esc_html_e( '前往購物車設定', 'now-crm' ); ?></a>
						<?php endif; ?>
					</p>
					<p class="description"><?php esc_html_e( '只有啟用中、觸發事件為「購物車放棄」且包含 Email 步驟的流程可以成為自訂擁有者。', 'now-crm' ); ?></p>
				</div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( $form_url ); ?>" id="nowcrm-automation-form" data-testid="automation-form">
				<input type="hidden" name="action" value="nowcrm_save_automation" />
				<input type="hidden" name="id" value="<?php echo esc_attr( (string) $id ); ?>" />
				<input type="hidden" name="steps_json" id="nowcrm-steps-json" value="<?php echo esc_attr( (string) wp_json_encode( $steps ) ); ?>" />
				<input type="hidden" name="nowcrm_json" id="nowcrm-json-save" value="" />
				<?php wp_nonce_field( 'nowcrm_save_automation' ); ?>

				<div class="notice notice-error inline" id="nowcrm-automation-error" hidden><p></p></div>

				<div class="nowcrm-automation__layout">
					<section class="nowcrm-automation__canvas" aria-label="<?php esc_attr_e( '自動化流程', 'now-crm' ); ?>">
						<article class="nowcrm-automation__trigger-card">
							<span class="dashicons dashicons-update nowcrm-automation__trigger-icon" aria-hidden="true"></span>
							<div class="nowcrm-automation__trigger-main">
								<span class="nowcrm-automation__eyebrow"><?php esc_html_e( '觸發', 'now-crm' ); ?></span>
								<strong data-role="trigger-label"><?php echo esc_html( AutomationListPage::trigger_label( $trigger_key ) ); ?></strong>
								<p class="nowcrm-automation__trigger-desc" data-role="trigger-description"><?php echo esc_html( TriggerRegistry::description( $trigger_key ) ); ?></p>
								<div class="nowcrm-automation__chips" data-role="trigger-chips"<?php echo array() === $chips ? ' hidden' : ''; ?>>
									<?php foreach ( $chips as $chip ) : ?>
										<span class="nowcrm-automation__chip"><?php echo esc_html( $chip ); ?></span>
									<?php endforeach; ?>
								</div>
							</div>
							<a class="button" href="#nowcrm-automation-settings" data-role="edit-trigger"><?php esc_html_e( '編輯觸發', 'now-crm' ); ?></a>
						</article>

						<div class="nowcrm-step-list nowcrm-automation__flow<?php echo array() === $steps ? ' nowcrm-automation__flow--empty' : ''; ?>" id="nowcrm-steps" data-testid="automation-steps"
							data-nowcrm-conditions="<?php echo esc_attr( (string) wp_json_encode( self::condition_schema() ) ); ?>"
							data-nowcrm-trigger-groups="<?php echo esc_attr( (string) wp_json_encode( $trigger_groups_data ) ); ?>"
							data-nowcrm-chips="<?php echo esc_attr( (string) wp_json_encode( $chip_data ) ); ?>">
							<?php self::render_steps( $steps ); ?>
						</div>

						<p class="nowcrm-automation__flow-empty" data-role="flow-empty"<?php echo array() === $steps ? '' : ' hidden'; ?>>
							<?php esc_html_e( '尚未加入步驟。新增的步驟會放在流程起點，第一個「結束」之前。', 'now-crm' ); ?>
						</p>

						<button type="button" class="nowcrm-automation__add" id="nowcrm-add-step" data-testid="automation-add-step">
							＋ <?php esc_html_e( '新增步驟', 'now-crm' ); ?>
						</button>
					</section>

					<aside class="nowcrm-automation__aside">
						<section class="nowcrm-automation__panel">
							<div class="nowcrm-automation__panel-head">
								<h2><?php esc_html_e( '執行概況', 'now-crm' ); ?></h2>
								<a href="<?php echo esc_url( $runs_url ); ?>"><?php esc_html_e( '全部紀錄', 'now-crm' ); ?></a>
							</div>
							<div class="nowcrm-automation__metrics">
								<?php foreach ( $metrics as $metric_status => $metric_label ) : ?>
									<a class="nowcrm-automation__metric" href="<?php echo esc_url( Menu::page_url( Menu::AUTOMATION_RUNS_SLUG, array( 'id' => $id, 'run_status' => $metric_status ) ) ); ?>">
										<span class="nowcrm-automation__metric-value"><?php echo esc_html( number_format_i18n( (int) ( $counts[ $metric_status ] ?? 0 ) ) ); ?></span>
										<span class="nowcrm-automation__metric-label"><?php echo esc_html( $metric_label ); ?></span>
									</a>
								<?php endforeach; ?>
							</div>
						</section>

						<section class="nowcrm-automation__panel" id="nowcrm-automation-settings">
							<h2><?php esc_html_e( '自動化設定', 'now-crm' ); ?></h2>

							<label class="nowcrm-automation__field">
								<span><?php esc_html_e( '名稱', 'now-crm' ); ?></span>
								<input type="text" name="name" class="regular-text" required="required"
									value="<?php echo esc_attr( (string) $automation['name'] ); ?>" data-testid="automation-name"
									aria-describedby="nowcrm-automation-name-error" />
								<span class="nowcrm-automation__field-error" id="nowcrm-automation-name-error" data-role="name-error" hidden></span>
							</label>

							<label class="nowcrm-automation__field">
								<span><?php esc_html_e( '觸發事件', 'now-crm' ); ?></span>
								<select name="trigger_key" data-testid="automation-trigger">
									<?php foreach ( TriggerRegistry::triggers() as $key => $label ) : ?>
										<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $trigger_key, $key ); ?>>
											<?php echo esc_html( $label ); ?>
										</option>
									<?php endforeach; ?>
								</select>
							</label>

							<fieldset class="nowcrm-automation__entry">
								<legend><?php esc_html_e( '進入條件', 'now-crm' ); ?></legend>
								<label>
									<input type="checkbox" name="run_once" value="1" <?php checked( ! empty( $entry['run_once'] ) ); ?> />
									<?php esc_html_e( '每位聯絡人只執行一次（完成後不再進入）', 'now-crm' ); ?>
								</label>
								<label>
									<input type="checkbox" name="force_run" value="1" <?php checked( ! empty( $entry['force_run'] ) ); ?> />
									<?php esc_html_e( '即使聯絡人未訂閱，也執行動作（Email 仍受寄送門檻限制）', 'now-crm' ); ?>
								</label>
							</fieldset>

							<div class="nowcrm-automation__filters" data-role="trigger-filters">
								<h3><?php esc_html_e( '觸發篩選（選填）', 'now-crm' ); ?></h3>
								<p class="description"><?php esc_html_e( '留空表示全部符合。多選以 Ctrl／Command 選取；切換觸發事件時只顯示該事件支援的篩選。', 'now-crm' ); ?></p>
								<p class="description" data-role="trigger-filters-empty"<?php echo array() === $filter_groups ? '' : ' hidden'; ?>>
									<?php esc_html_e( '此觸發事件沒有額外篩選。', 'now-crm' ); ?>
								</p>

								<div class="nowcrm-automation__filter-group" data-trigger-group="statuses"<?php echo in_array( 'statuses', $filter_groups, true ) ? '' : ' hidden'; ?>>
									<label class="nowcrm-automation__field">
										<span><?php esc_html_e( '只接受這些狀態', 'now-crm' ); ?></span>
										<select name="trigger_statuses[]" multiple size="5">
											<?php foreach ( Status::all() as $status_option ) : ?>
												<option value="<?php echo esc_attr( $status_option ); ?>" <?php echo in_array( $status_option, (array) ( $config['statuses'] ?? array() ), true ) ? 'selected' : ''; ?>>
													<?php echo esc_html( Status::label( $status_option ) ); ?>
												</option>
											<?php endforeach; ?>
										</select>
									</label>
								</div>

								<div class="nowcrm-automation__filter-group" data-trigger-group="terms"<?php echo in_array( 'terms', $filter_groups, true ) ? '' : ' hidden'; ?>>
									<label class="nowcrm-automation__field">
										<span><?php esc_html_e( '只接受這些名單／標籤', 'now-crm' ); ?></span>
										<select name="trigger_term_ids[]" multiple size="5">
											<?php foreach ( $terms as $term ) : ?>
												<option value="<?php echo esc_attr( (string) $term['id'] ); ?>" <?php echo in_array( (int) $term['id'], array_map( 'intval', (array) ( $config['term_ids'] ?? array() ) ), true ) ? 'selected' : ''; ?>>
													<?php echo esc_html( ( TermRepository::TYPE_LIST === $term['type'] ? __( '名單', 'now-crm' ) : __( '標籤', 'now-crm' ) ) . '：' . $term['name'] ); ?>
												</option>
											<?php endforeach; ?>
										</select>
									</label>

									<label class="nowcrm-automation__field">
										<span><?php esc_html_e( '名單／標籤類型', 'now-crm' ); ?></span>
										<select name="trigger_term_types[]" multiple size="2">
											<?php foreach ( array( TermRepository::TYPE_LIST => __( '名單', 'now-crm' ), TermRepository::TYPE_TAG => __( '標籤', 'now-crm' ) ) as $type => $type_label ) : ?>
												<option value="<?php echo esc_attr( $type ); ?>" <?php echo in_array( $type, (array) ( $config['term_types'] ?? array() ), true ) ? 'selected' : ''; ?>>
													<?php echo esc_html( $type_label ); ?>
												</option>
											<?php endforeach; ?>
										</select>
									</label>
								</div>

								<div class="nowcrm-automation__filter-group" data-trigger-group="sources"<?php echo in_array( 'sources', $filter_groups, true ) ? '' : ' hidden'; ?>>
									<label class="nowcrm-automation__field">
										<span><?php esc_html_e( '來源（逗號分隔）', 'now-crm' ); ?></span>
										<input type="text" name="trigger_sources" class="regular-text"
											value="<?php echo esc_attr( implode( ', ', (array) ( $config['sources'] ?? array() ) ) ); ?>"
											placeholder="woocommerce, csv" />
									</label>
								</div>
							</div>
						</section>
					</aside>
				</div>
			</form>

			<dialog class="nowcrm-automation-picker" id="nowcrm-step-picker" aria-labelledby="nowcrm-step-picker-title">
				<div class="nowcrm-automation-picker__head">
					<h2 id="nowcrm-step-picker-title"><?php esc_html_e( '新增步驟', 'now-crm' ); ?></h2>
					<button type="button" class="nowcrm-drawer__close" data-picker-close aria-label="<?php esc_attr_e( '關閉', 'now-crm' ); ?>">✕</button>
				</div>
				<div class="nowcrm-automation-picker__search">
					<label for="nowcrm-step-picker-search" class="screen-reader-text"><?php esc_html_e( '搜尋步驟', 'now-crm' ); ?></label>
					<input type="search" id="nowcrm-step-picker-search" data-picker-search
						placeholder="<?php esc_attr_e( '搜尋步驟名稱或說明', 'now-crm' ); ?>" autocomplete="off" />
				</div>
				<p class="nowcrm-automation-picker__empty" data-picker-empty hidden><?php esc_html_e( '沒有符合的步驟。', 'now-crm' ); ?></p>
				<?php foreach ( self::picker_groups() as $group_label => $kinds ) : ?>
					<div class="nowcrm-automation-picker__group" data-picker-group>
						<h3><?php echo esc_html( $group_label ); ?></h3>
						<div class="nowcrm-automation-picker__grid">
							<?php foreach ( $kinds as $kind => $description ) : ?>
								<button type="button" class="nowcrm-automation-picker__card" data-picker-kind="<?php echo esc_attr( $kind ); ?>" data-picker-text="<?php echo esc_attr( self::kind_label( $kind ) . ' ' . $description . ' ' . $group_label ); ?>">
									<span class="dashicons <?php echo esc_attr( self::step_icon( $kind ) ); ?>" aria-hidden="true"></span>
									<strong><?php echo esc_html( self::kind_label( $kind ) ); ?></strong>
									<span><?php echo esc_html( $description ); ?></span>
								</button>
							<?php endforeach; ?>
						</div>
					</div>
				<?php endforeach; ?>
			</dialog>

			<dialog class="nowcrm-drawer nowcrm-automation-drawer" id="nowcrm-step-drawer" aria-labelledby="nowcrm-step-drawer-title">
				<div class="nowcrm-automation-drawer__panel">
					<header class="nowcrm-drawer__header nowcrm-automation-drawer__head">
						<div class="nowcrm-automation-drawer__heading">
							<span class="nowcrm-automation__eyebrow" data-role="drawer-eyebrow"></span>
							<h2 id="nowcrm-step-drawer-title" data-role="drawer-title"></h2>
						</div>
						<div class="nowcrm-automation-drawer__nav">
							<button type="button" class="button button-small" data-drawer-back hidden><?php esc_html_e( '← 返回', 'now-crm' ); ?></button>
							<button type="button" class="nowcrm-drawer__close" data-drawer-close aria-label="<?php esc_attr_e( '關閉', 'now-crm' ); ?>">✕</button>
						</div>
					</header>
					<div class="nowcrm-drawer__body" data-role="drawer-body"></div>
					<p class="nowcrm-automation-drawer__hint"><?php esc_html_e( '設定會保留在這次編輯中；仍要按頁首「儲存」才會寫入自動化。', 'now-crm' ); ?></p>
					<footer class="nowcrm-drawer__footer nowcrm-automation-drawer__foot">
						<button type="button" class="button button-link-delete" data-drawer-delete><?php esc_html_e( '刪除步驟', 'now-crm' ); ?></button>
						<button type="button" class="button" data-drawer-done><?php esc_html_e( '完成', 'now-crm' ); ?></button>
					</footer>
				</div>
			</dialog>

			<template id="nowcrm-step-template">
				<?php self::render_card( array() ); ?>
			</template>
		</div>

		<?php
		self::render_script();
	}

	/**
	 * Terms for the action selects.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function terms(): array {
		if ( null === self::$terms_cache ) {
			self::$terms_cache = ( new TermRepository() )->all();
		}

		return self::$terms_cache;
	}

	/**
	 * Term names keyed by ID.
	 *
	 * @return array<int, string>
	 */
	private static function term_name_map(): array {
		$map = array();

		foreach ( self::terms() as $term ) {
			$map[ (int) $term['id'] ] = (string) $term['name'];
		}

		return $map;
	}

	/**
	 * Companies for the action selects.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function companies(): array {
		if ( null === self::$companies_cache ) {
			self::$companies_cache = ( new CompanyRepository() )->search( array( 'per_page' => 100 ) )['items'];
		}

		return self::$companies_cache;
	}

	/**
	 * Company names keyed by ID.
	 *
	 * @return array<int, string>
	 */
	private static function company_name_map(): array {
		$map = array();

		foreach ( self::companies() as $company ) {
			$map[ (int) $company['id'] ] = (string) $company['name'];
		}

		return $map;
	}

	/**
	 * Active sequences for the action select.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function sequences(): array {
		if ( null === self::$sequences_cache ) {
			self::$sequences_cache = ( new SequenceRepository() )->all( array( 'status' => SequenceRepository::STATUS_ACTIVE ) );
		}

		return self::$sequences_cache;
	}

	/**
	 * Active sequence names keyed by ID.
	 *
	 * @return array<int, string>
	 */
	private static function sequence_name_map(): array {
		$map = array();

		foreach ( self::sequences() as $sequence ) {
			$map[ (int) $sequence['id'] ] = (string) $sequence['name'];
		}

		return $map;
	}

	/**
	 * Trigger filter groups accepted by the save handler.
	 *
	 * Keep this in sync with Actions::automation_trigger_config(); the editor
	 * only hides/disables groups, the server remains the persistence boundary.
	 *
	 * @return array<int, string>
	 */
	private static function trigger_filter_groups( string $trigger ): array {
		$groups = array();

		if ( in_array( $trigger, array( TriggerRegistry::CONTACT_STATUS, TriggerRegistry::CONTACT_CREATED ), true ) ) {
			$groups[] = 'statuses';
		}

		if ( in_array( $trigger, array( TriggerRegistry::TERM_ATTACHED, TriggerRegistry::TERM_DETACHED ), true ) ) {
			$groups[] = 'terms';
		}

		if ( in_array( $trigger, array( TriggerRegistry::CONTACT_CREATED, TriggerRegistry::CONTACT_UPDATED, TriggerRegistry::CSV_IMPORTED, TriggerRegistry::CONTACT_STATUS ), true ) ) {
			$groups[] = 'sources';
		}

		return $groups;
	}

	/**
	 * Chips shown on the trigger card.
	 *
	 * @param array<string, mixed> $config Trigger config.
	 * @param array<string, mixed> $entry  Entry config.
	 * @return array<int, string>
	 */
	private static function trigger_chips( array $config, array $entry ): array {
		$chips = array();
		$terms = self::term_name_map();

		foreach ( (array) ( $config['statuses'] ?? array() ) as $status_key ) {
			if ( Status::is_valid( (string) $status_key ) ) {
				$chips[] = Status::label( (string) $status_key );
			}
		}

		foreach ( (array) ( $config['term_ids'] ?? array() ) as $term_id ) {
			if ( isset( $terms[ (int) $term_id ] ) ) {
				$chips[] = $terms[ (int) $term_id ];
			}
		}

		foreach ( (array) ( $config['term_types'] ?? array() ) as $type ) {
			if ( TermRepository::TYPE_LIST === $type ) {
				$chips[] = __( '名單', 'now-crm' );
			} elseif ( TermRepository::TYPE_TAG === $type ) {
				$chips[] = __( '標籤', 'now-crm' );
			}
		}

		foreach ( (array) ( $config['sources'] ?? array() ) as $source ) {
			$chips[] = (string) $source;
		}

		$chips[] = ! empty( $entry['run_once'] )
			? __( '每位聯絡人只執行一次', 'now-crm' )
			: __( '完成後可重新進入', 'now-crm' );

		if ( ! empty( $entry['force_run'] ) ) {
			$chips[] = __( '未訂閱也執行動作', 'now-crm' );
		}

		return array_values( array_unique( array_filter( $chips ) ) );
	}

	/**
	 * Field catalog for the condition builder.
	 *
	 * The browser builds the same plan shape that `AdvancedFilters::normalize()`
	 * validates. Field options come from the shared catalog, so the editor
	 * never invents a provider, field, or operator.
	 *
	 * @return array<string, mixed>
	 */
	private static function condition_schema(): array {
		$schema = array(
			'fields'    => array(),
			'operators' => AdvancedFilters::operator_labels(),
			'providers' => AdvancedFilters::provider_labels(),
			'valueless' => array( 'is_null', 'not_null' ),
			'maxGroups' => AdvancedFilters::MAX_GROUPS,
			'maxRows'   => AdvancedFilters::MAX_CONDITIONS,
			'terms'     => array(
				TermRepository::TYPE_LIST => array(),
				TermRepository::TYPE_TAG  => array(),
			),
		);

		foreach ( self::terms() as $term ) {
			$type = (string) $term['type'];

			if ( isset( $schema['terms'][ $type ] ) ) {
				$schema['terms'][ $type ][ (string) $term['id'] ] = (string) $term['name'];
			}
		}

		foreach ( AdvancedFilters::fields() as $key => $definition ) {
			$type = (string) ( $definition['type'] ?? '' );

			$schema['fields'][ $key ] = array(
				'provider'  => (string) ( $definition['provider'] ?? '' ),
				'field'     => (string) ( $definition['field'] ?? '' ),
				'label'     => (string) ( $definition['label'] ?? '' ),
				'type'      => $type,
				'ops'       => array_values( (array) ( $definition['ops'] ?? array() ) ),
				'options'   => 'terms' === $type ? array() : (array) ( $definition['options'] ?? array() ),
				'term_type' => (string) ( $definition['term_type'] ?? '' ),
			);
		}

		return $schema;
	}

	/**
	 * Categorized picker entries.
	 *
	 * @return array<string, array<string, string>>
	 */
	private static function picker_groups(): array {
		return array(
			__( '執行動作', 'now-crm' ) => array(
				AutomationRepository::KIND_ACTION => __( '加入或移除名單／標籤、變更狀態、加入序列或公司。', 'now-crm' ),
				AutomationRepository::KIND_WAIT   => __( '暫停流程指定時間後再繼續。', 'now-crm' ),
				AutomationRepository::KIND_EMAIL  => __( '寄送一封電子郵件。', 'now-crm' ),
			),
			__( '流程控制', 'now-crm' ) => array(
				AutomationRepository::KIND_CONDITION => __( '依聯絡人資料走「符合」或「不符合」分支。', 'now-crm' ),
				AutomationRepository::KIND_SPLIT     => __( '依固定權重把聯絡人分到 A 或 B 分支。', 'now-crm' ),
				AutomationRepository::KIND_GOAL      => __( '等待指定事件，可設定逾時分支。', 'now-crm' ),
				AutomationRepository::KIND_END       => __( '在此結束流程。', 'now-crm' ),
			),
		);
	}

	/**
	 * Render a nested list of cards.
	 *
	 * Cards after the first explicit `end` stay untouched in storage but are
	 * marked unreachable, matching the engine's short circuit.
	 *
	 * @param array<int, array<string, mixed>> $steps Steps.
	 */
	private static function render_steps( array $steps ): void {
		$steps    = array_values( $steps );
		$last     = count( $steps ) - 1;
		$ended    = false;

		foreach ( $steps as $index => $step ) {
			$kind = (string) ( $step['kind'] ?? AutomationRepository::KIND_ACTION );
			self::render_card( $step, $ended, AutomationRepository::KIND_END === $kind && $index < $last );

			if ( AutomationRepository::KIND_END === $kind ) {
				$ended = true;
			}
		}
	}

	/**
	 * Render one step card.
	 *
	 * @param array<string, mixed> $step        Step data; empty for the template.
	 * @param bool                 $unreachable Whether an earlier end short circuits this card.
	 * @param bool                 $terminal    Whether this end card is followed by cards.
	 */
	private static function render_card( array $step, bool $unreachable = false, bool $terminal = false ): void {
		$kind      = (string) ( $step['kind'] ?? AutomationRepository::KIND_ACTION );
		$action    = (string) ( $step['action_key'] ?? '' );
		$config    = (array) ( $step['config'] ?? array() );
		$email_mode = (string) ( $config['mode'] ?? ( array() === $step ? 'blocks' : 'html' ) );
		$delay     = (int) ( $step['delay_seconds'] ?? 0 );
		$term_ids  = array_map( 'intval', (array) ( $config['term_ids'] ?? array() ) );
		$condition = (array) ( $config['conditions'] ?? array() );
		$goal_event    = (string) ( $config['event'] ?? AutomationRepository::GOAL_TERM_ATTACHED );
		$goal_term_ids = array_map( 'intval', (array) ( $config['term_ids'] ?? array() ) );
		$goal_statuses = array_map( 'strval', (array) ( $config['statuses'] ?? array() ) );
		$goal_mode     = (string) ( $config['mode'] ?? AutomationRepository::GOAL_MODE_REQUIRED );
		$goal_timeout  = (int) ( $config['timeout_seconds'] ?? 0 );
		$weight_a      = (int) ( $config['weight_a'] ?? AutomationRepository::SPLIT_DEFAULT_WEIGHT );
		$weight_b      = (int) ( $config['weight_b'] ?? AutomationRepository::SPLIT_DEFAULT_WEIGHT );
		$condition_json = array() === $condition ? '' : (string) wp_json_encode( $condition, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		$condition_value = array() === $condition ? '' : (string) wp_json_encode( $condition );
		$delay_parts      = self::duration_parts( $delay );
		$goal_timeout_parts = self::duration_parts( $goal_timeout );
		$terms          = self::terms();
		$term_actions   = array( ActionRegistry::APPLY_LIST, ActionRegistry::REMOVE_LIST, ActionRegistry::APPLY_TAG, ActionRegistry::REMOVE_TAG );
		$company_card   = in_array( $action, array( ActionRegistry::APPLY_COMPANY, ActionRegistry::DETACH_COMPANY ), true );
		$companies      = $company_card ? self::companies() : array();
		$sequence_card  = ActionRegistry::ADD_TO_SEQUENCE === $action;
		$sequences      = self::sequences();
		$sequence_id    = (int) ( $config['sequence_id'] ?? 0 );
		$sequence_ids   = array_map( static fn( $sequence ) => (int) $sequence['id'], $sequences );
		$saved_sequence = null;

		if ( $sequence_card && $sequence_id > 0 && ! in_array( $sequence_id, $sequence_ids, true ) ) {
			$saved_sequence = ( new SequenceRepository() )->get( $sequence_id );
		}
		$action_group   = in_array( $action, $term_actions, true ) ? 'term' : ( ActionRegistry::SET_STATUS === $action ? 'status' : ( $company_card ? 'company' : ( $sequence_card ? 'sequence' : '' ) ) );
		$action_options = ActionRegistry::actions();

		if ( ! $company_card ) {
			unset( $action_options[ ActionRegistry::APPLY_COMPANY ], $action_options[ ActionRegistry::DETACH_COMPANY ] );
		}
		?>
		<div class="nowcrm-step-card<?php echo $unreachable ? ' is-unreachable' : ''; ?>" data-kind="<?php echo esc_attr( $kind ); ?>">
			<div class="nowcrm-step-card__head">
				<button type="button" class="nowcrm-step-card__summary" data-action="edit" aria-expanded="false">
					<span class="dashicons <?php echo esc_attr( self::step_icon( $kind ) ); ?> nowcrm-step-card__icon" data-role="step-icon" aria-hidden="true"></span>
					<span class="nowcrm-step-card__text">
						<span class="nowcrm-step-card__eyebrow"><?php echo esc_html( self::kind_label( $kind ) ); ?></span>
						<strong data-role="step-title"><?php echo esc_html( self::step_summary( $step ) ); ?></strong>
						<span class="nowcrm-step-card__meta" data-role="step-summary"></span>
						<span class="nowcrm-step-card__flag" data-role="step-flag"<?php echo ( $unreachable || $terminal ) ? '' : ' hidden'; ?>><?php
						if ( $unreachable ) {
							esc_html_e( '上方已有結束步驟，不會執行。', 'now-crm' );
						} elseif ( $terminal ) {
							esc_html_e( '流程在此結束，之後的步驟不會執行。', 'now-crm' );
						}
						?></span>
					</span>
				</button>
				<span class="nowcrm-step-card__actions">
					<button type="button" class="button button-small" data-action="insert-after" title="<?php esc_attr_e( '在此之後新增步驟', 'now-crm' ); ?>" aria-label="<?php esc_attr_e( '在此之後新增步驟', 'now-crm' ); ?>">＋</button>
					<button type="button" class="button button-small" data-action="up" title="<?php esc_attr_e( '上移', 'now-crm' ); ?>" aria-label="<?php esc_attr_e( '上移', 'now-crm' ); ?>">↑</button>
					<button type="button" class="button button-small" data-action="down" title="<?php esc_attr_e( '下移', 'now-crm' ); ?>" aria-label="<?php esc_attr_e( '下移', 'now-crm' ); ?>">↓</button>
					<button type="button" class="button button-small button-link-delete" data-action="remove" title="<?php esc_attr_e( '移除步驟', 'now-crm' ); ?>" aria-label="<?php esc_attr_e( '移除步驟', 'now-crm' ); ?>"><?php esc_html_e( '移除', 'now-crm' ); ?></button>
				</span>
			</div>

			<div class="nowcrm-step-card__body">
				<p class="nowcrm-step-card__error" data-role="step-error" role="alert" hidden></p>
				<label>
					<span><?php esc_html_e( '步驟類型', 'now-crm' ); ?></span>
					<select data-field="kind">
						<?php foreach ( array( 'action' => __( '動作', 'now-crm' ), 'wait' => __( '等待', 'now-crm' ), 'email' => __( 'Email', 'now-crm' ), 'condition' => __( '條件分支', 'now-crm' ), 'split' => __( '分流（A／B）', 'now-crm' ), 'goal' => __( '目標', 'now-crm' ), 'end' => __( '結束', 'now-crm' ) ) as $value => $label ) : ?>
							<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $kind, $value ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>

				<div data-group="action" <?php echo 'action' === $kind ? '' : 'hidden'; ?>>
					<label>
						<span><?php esc_html_e( '動作', 'now-crm' ); ?></span>
						<select data-field="action_key">
							<?php foreach ( $action_options as $key => $label ) : ?>
								<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $action, $key ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>

					<div data-action-config="term" <?php echo 'term' === $action_group ? '' : 'hidden'; ?>>
						<label>
							<span><?php esc_html_e( '名單／標籤', 'now-crm' ); ?></span>
							<select data-field="term_ids" multiple size="5">
								<?php foreach ( $terms as $term ) : ?>
									<option value="<?php echo esc_attr( (string) $term['id'] ); ?>" <?php echo in_array( (int) $term['id'], $term_ids, true ) ? 'selected' : ''; ?>>
										<?php echo esc_html( ( TermRepository::TYPE_LIST === $term['type'] ? __( '名單', 'now-crm' ) : __( '標籤', 'now-crm' ) ) . '：' . $term['name'] ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</label>
					</div>

					<div data-action-config="status" <?php echo 'status' === $action_group ? '' : 'hidden'; ?>>
						<label>
							<span><?php esc_html_e( '新狀態', 'now-crm' ); ?></span>
							<select data-field="status">
								<?php foreach ( Status::all() as $status_option ) : ?>
									<option value="<?php echo esc_attr( $status_option ); ?>" <?php selected( (string) ( $config['status'] ?? '' ), $status_option ); ?>>
										<?php echo esc_html( Status::label( $status_option ) ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</label>
					</div>

					<?php if ( $company_card ) : ?>
						<div data-action-config="company">
							<label>
								<span><?php esc_html_e( '公司', 'now-crm' ); ?></span>
								<select data-field="company_id">
									<option value="0"><?php esc_html_e( '選擇公司', 'now-crm' ); ?></option>
									<?php foreach ( $companies as $company ) : ?>
										<option value="<?php echo esc_attr( (string) $company['id'] ); ?>" <?php selected( (int) ( $config['company_id'] ?? 0 ), (int) $company['id'] ); ?>>
											<?php echo esc_html( (string) $company['name'] ); ?>
										</option>
									<?php endforeach; ?>
								</select>
							</label>
						</div>
					<?php endif; ?>

					<div data-action-config="sequence" <?php echo 'sequence' === $action_group ? '' : 'hidden'; ?>>
						<label>
							<span><?php esc_html_e( '序列', 'now-crm' ); ?></span>
							<select data-field="sequence_id">
								<option value="0"><?php esc_html_e( '選擇已啟用的序列', 'now-crm' ); ?></option>
								<?php if ( $saved_sequence ) : ?>
									<?php $saved_status = SequenceListPage::status_label( (string) $saved_sequence['status'] ); ?>
									<option value="<?php echo esc_attr( (string) $saved_sequence['id'] ); ?>" selected="selected">
										<?php echo esc_html( (string) $saved_sequence['name'] . '（' . $saved_status . '）' ); ?>
									</option>
								<?php endif; ?>
								<?php foreach ( $sequences as $sequence ) : ?>
									<option value="<?php echo esc_attr( (string) $sequence['id'] ); ?>" <?php selected( $sequence_id, (int) $sequence['id'] ); ?>>
										<?php echo esc_html( (string) $sequence['name'] ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<?php if ( $saved_sequence ) : ?>
								<span class="description"><?php esc_html_e( '目前連結的序列已不在啟用清單；儲存時會保留這個既有連結，新選擇仍只列出啟用中的序列。', 'now-crm' ); ?></span>
							<?php endif; ?>
						</label>
						<p class="description"><?php esc_html_e( '只會加入目前這一位聯絡人。未訂閱、已抑制或已有進行中追蹤紀錄時會略過。', 'now-crm' ); ?></p>
					</div>
				</div>

				<div data-group="wait" <?php echo 'wait' === $kind ? '' : 'hidden'; ?>>
					<label>
						<span><?php esc_html_e( '等待時間', 'now-crm' ); ?></span>
						<span class="nowcrm-duration">
							<input type="number" min="0" step="1" data-field="delay_value" value="<?php echo esc_attr( (string) $delay_parts['value'] ); ?>" />
							<select data-field="delay_unit">
								<?php foreach ( array( 'minutes' => __( '分', 'now-crm' ), 'hours' => __( '小時', 'now-crm' ), 'days' => __( '天', 'now-crm' ), 'seconds' => __( '秒', 'now-crm' ) ) as $unit => $unit_label ) : ?>
									<option value="<?php echo esc_attr( $unit ); ?>" <?php selected( $delay_parts['unit'], $unit ); ?>><?php echo esc_html( $unit_label ); ?></option>
								<?php endforeach; ?>
							</select>
						</span>
					</label>
					<input type="hidden" data-field="delay_seconds" value="<?php echo esc_attr( (string) $delay ); ?>" />
					<p class="description"><?php esc_html_e( '儲存時仍以秒為單位。0 表示不等待。', 'now-crm' ); ?></p>
				</div>

				<div data-group="goal" <?php echo 'goal' === $kind ? '' : 'hidden'; ?>>
					<label>
						<span><?php esc_html_e( '目標事件', 'now-crm' ); ?></span>
						<select data-field="goal_event">
							<?php foreach ( AutomationRepository::goal_events() as $value => $label ) : ?>
								<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $goal_event, $value ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>

					<div class="nowcrm-inline-form">
						<label>
							<span><?php esc_html_e( '目標類型', 'now-crm' ); ?></span>
							<select data-field="goal_mode">
								<option value="required" <?php selected( $goal_mode, AutomationRepository::GOAL_MODE_REQUIRED ); ?>><?php esc_html_e( '必經（等待達成）', 'now-crm' ); ?></option>
								<option value="optional" <?php selected( $goal_mode, AutomationRepository::GOAL_MODE_OPTIONAL ); ?>><?php esc_html_e( '選用（未達成時繼續）', 'now-crm' ); ?></option>
							</select>
						</label>
						<label>
							<span><?php esc_html_e( '逾時（0 表示永久等待）', 'now-crm' ); ?></span>
							<span class="nowcrm-duration">
								<input type="number" min="0" step="1" data-field="goal_timeout_value" value="<?php echo esc_attr( (string) $goal_timeout_parts['value'] ); ?>" />
								<select data-field="goal_timeout_unit">
									<?php foreach ( array( 'minutes' => __( '分', 'now-crm' ), 'hours' => __( '小時', 'now-crm' ), 'days' => __( '天', 'now-crm' ), 'seconds' => __( '秒', 'now-crm' ) ) as $unit => $unit_label ) : ?>
										<option value="<?php echo esc_attr( $unit ); ?>" <?php selected( $goal_timeout_parts['unit'], $unit ); ?>><?php echo esc_html( $unit_label ); ?></option>
									<?php endforeach; ?>
								</select>
							</span>
						</label>
						<input type="hidden" data-field="goal_timeout" value="<?php echo esc_attr( (string) $goal_timeout ); ?>" />
					</div>
					<p class="description"><?php esc_html_e( '必經：等待目標事件；設定逾時後，時間到改走下方的逾時分支。選用：未達成時不等待，直接續行（不會讓聯絡人跳回先前的步驟）。舊的自動化維持永久等待。', 'now-crm' ); ?></p>

					<div data-goal-config="term" <?php echo AutomationRepository::is_goal_term_event( $goal_event ) ? '' : 'hidden'; ?>>
						<label>
							<span><?php esc_html_e( '名單／標籤', 'now-crm' ); ?></span>
							<select data-field="goal_term_ids" multiple size="5">
								<?php foreach ( $terms as $term ) : ?>
									<option value="<?php echo esc_attr( (string) $term['id'] ); ?>" <?php echo in_array( (int) $term['id'], $goal_term_ids, true ) ? 'selected' : ''; ?>>
										<?php echo esc_html( ( TermRepository::TYPE_LIST === $term['type'] ? __( '名單', 'now-crm' ) : __( '標籤', 'now-crm' ) ) . '：' . $term['name'] ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</label>
					</div>

					<div data-goal-config="status" <?php echo AutomationRepository::is_goal_term_event( $goal_event ) ? 'hidden' : ''; ?>>
						<label>
							<span><?php esc_html_e( '目標狀態', 'now-crm' ); ?></span>
							<select data-field="goal_statuses" multiple size="5">
								<?php foreach ( Status::all() as $status_option ) : ?>
									<option value="<?php echo esc_attr( $status_option ); ?>" <?php echo in_array( $status_option, $goal_statuses, true ) ? 'selected' : ''; ?>>
										<?php echo esc_html( Status::label( $status_option ) ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</label>
					</div>

					<div class="nowcrm-branch" data-branch-block="timeout">
						<div class="nowcrm-branch__head">
							<strong><?php esc_html_e( '逾時／未達成分支', 'now-crm' ); ?></strong>
							<button type="button" class="button button-small" data-action="add-branch" data-branch="timeout"><?php esc_html_e( '加入分支步驟', 'now-crm' ); ?></button>
						</div>
						<div class="nowcrm-step-list" data-branch="timeout">
							<?php
							if ( AutomationRepository::KIND_GOAL === $kind ) {
								self::render_steps( (array) ( $step['timeout'] ?? array() ) );
							}
							?>
						</div>
					</div>
				</div>

				<div data-group="email"
					data-workflow-email="automation"
					data-workflow-source-id="<?php echo esc_attr( (string) self::$source_id ); ?>"
					<?php echo 'email' === $kind ? '' : 'hidden'; ?>>
					<label>
						<span><?php esc_html_e( '主旨', 'now-crm' ); ?></span>
						<input type="text" data-field="subject" data-workflow-field="subject" value="<?php echo esc_attr( (string) ( $config['subject'] ?? '' ) ); ?>" />
					</label>
					<label>
						<span><?php esc_html_e( '模式', 'now-crm' ); ?></span>
						<select data-field="mode" data-workflow-field="mode">
							<option value="html" <?php selected( $email_mode, 'html' ); ?>><?php esc_html_e( 'Raw HTML', 'now-crm' ); ?></option>
							<option value="blocks" <?php selected( $email_mode, 'blocks' ); ?>><?php esc_html_e( 'Gutenberg 區塊', 'now-crm' ); ?></option>
						</select>
					</label>
					<label>
						<span><?php esc_html_e( '預覽文字', 'now-crm' ); ?></span>
						<input type="text" data-field="preheader" data-workflow-field="preheader" value="<?php echo esc_attr( (string) ( $config['preheader'] ?? '' ) ); ?>" />
					</label>
					<div data-workflow-mode-pane="blocks" hidden>
						<span class="nowcrm-step-content__label"><?php esc_html_e( '內容（Gutenberg Blocks）', 'now-crm' ); ?></span>
						<div class="nowcrm-step-content__frame" data-workflow-frame-host
							data-workflow-frame-url="<?php echo esc_url( ContentEditorPage::url( 'automation', self::$source_id, true ) ); ?>"></div>
						<p class="description nowcrm-step-content__status" data-workflow-frame-status role="status" aria-live="polite"></p>
					</div>
					<div data-workflow-mode-pane="html" hidden>
						<label>
							<span><?php esc_html_e( '內容（Raw HTML）', 'now-crm' ); ?></span>
							<textarea rows="10" data-field="content" data-workflow-field="content"><?php echo esc_textarea( (string) ( $config['content'] ?? '' ) ); ?></textarea>
						</label>
					</div>

					<?php WorkflowEmail::render_tools( 'automation' ); ?>
				</div>

				<div data-group="split" <?php echo 'split' === $kind ? '' : 'hidden'; ?>>
					<p class="description"><?php esc_html_e( '依自動化與聯絡人固定分派，同一聯絡人重跑會落在同一分支。A＋B 權重必須是 0–100 的整數且合計 100。', 'now-crm' ); ?></p>

					<div class="nowcrm-split-weights">
						<label>
							<span><?php esc_html_e( 'A 分支權重（%）', 'now-crm' ); ?></span>
							<input type="number" min="0" max="100" step="1" data-field="weight_a" data-testid="automation-split-weight-a" value="<?php echo esc_attr( (string) $weight_a ); ?>" />
						</label>
						<label>
							<span><?php esc_html_e( 'B 分支權重（%）', 'now-crm' ); ?></span>
							<input type="number" min="0" max="100" step="1" data-field="weight_b" data-testid="automation-split-weight-b" value="<?php echo esc_attr( (string) $weight_b ); ?>" />
						</label>
					</div>

					<?php foreach ( array( AutomationRepository::BRANCH_A => __( 'A 分支', 'now-crm' ), AutomationRepository::BRANCH_B => __( 'B 分支', 'now-crm' ) ) as $branch => $label ) : ?>
						<div class="nowcrm-branch" data-branch-block="<?php echo esc_attr( $branch ); ?>" data-testid="automation-split-branch-<?php echo esc_attr( $branch ); ?>">
							<div class="nowcrm-branch__head">
								<strong><?php echo esc_html( $label ); ?></strong>
								<button type="button" class="button button-small" data-action="add-branch" data-branch="<?php echo esc_attr( $branch ); ?>"><?php esc_html_e( '加入分支步驟', 'now-crm' ); ?></button>
							</div>
							<div class="nowcrm-step-list" data-branch="<?php echo esc_attr( $branch ); ?>">
								<?php
								if ( 'split' === $kind ) {
									self::render_steps( (array) ( $step[ $branch ] ?? array() ) );
								}
								?>
							</div>
						</div>
					<?php endforeach; ?>
				</div>

				<div data-group="condition" <?php echo 'condition' === $kind ? '' : 'hidden'; ?>>
					<input type="hidden" data-field="conditions_plan" value="<?php echo esc_attr( $condition_value ); ?>" />
					<div class="nowcrm-condition-modes">
						<span class="nowcrm-condition-mode" data-role="condition-mode"><?php esc_html_e( '視覺化條件', 'now-crm' ); ?></span>
						<button type="button" class="button button-small" data-action="apply-condition-json" hidden><?php esc_html_e( '以 JSON 重建視覺化', 'now-crm' ); ?></button>
					</div>
					<div class="nowcrm-condition-builder" data-role="condition-builder" data-testid="automation-condition-builder"></div>
					<p class="description" data-role="condition-error" role="alert" hidden></p>
					<details class="nowcrm-condition-json">
						<summary><?php esc_html_e( '進階：直接編輯 JSON', 'now-crm' ); ?></summary>
						<label>
							<span><?php esc_html_e( '條件計畫（進階篩選格式）', 'now-crm' ); ?></span>
							<textarea rows="6" data-field="conditions_json" data-role="condition-json" placeholder='{"version":1,"groups":[[{"provider":"segment","field":"status","operator":"in","value":["subscribed"]}]]}'><?php echo esc_textarea( $condition_json ); ?></textarea>
						</label>
					</details>

					<?php foreach ( array( AutomationRepository::BRANCH_YES => __( '符合（yes）', 'now-crm' ), AutomationRepository::BRANCH_NO => __( '不符合（no）', 'now-crm' ) ) as $branch => $label ) : ?>
						<div class="nowcrm-branch" data-branch-block="<?php echo esc_attr( $branch ); ?>">
							<div class="nowcrm-branch__head">
								<strong><?php echo esc_html( $label ); ?></strong>
								<button type="button" class="button button-small" data-action="add-branch" data-branch="<?php echo esc_attr( $branch ); ?>"><?php esc_html_e( '加入分支步驟', 'now-crm' ); ?></button>
							</div>
							<div class="nowcrm-step-list" data-branch="<?php echo esc_attr( $branch ); ?>">
								<?php
								if ( 'condition' === $kind ) {
									self::render_steps( (array) ( $step[ $branch ] ?? array() ) );
								}
								?>
							</div>
						</div>
					<?php endforeach; ?>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Human label for a kind.
	 */
	private static function kind_label( string $kind ): string {
		return match ( $kind ) {
			AutomationRepository::KIND_WAIT => __( '等待', 'now-crm' ),
			AutomationRepository::KIND_EMAIL => 'Email',
			AutomationRepository::KIND_CONDITION => __( '條件分支', 'now-crm' ),
			AutomationRepository::KIND_SPLIT => __( '分流（A／B）', 'now-crm' ),
			AutomationRepository::KIND_GOAL => __( '目標', 'now-crm' ),
			AutomationRepository::KIND_END => __( '結束', 'now-crm' ),
			default => __( '動作', 'now-crm' ),
		};
	}

	/**
	 * Dashicon class for one kind.
	 */
	private static function step_icon( string $kind ): string {
		return match ( $kind ) {
			AutomationRepository::KIND_WAIT => 'dashicons-clock',
			AutomationRepository::KIND_EMAIL => 'dashicons-email-alt',
			AutomationRepository::KIND_CONDITION => 'dashicons-randomize',
			AutomationRepository::KIND_SPLIT => 'dashicons-networking',
			AutomationRepository::KIND_GOAL => 'dashicons-flag',
			AutomationRepository::KIND_END => 'dashicons-marker',
			default => 'dashicons-admin-generic',
		};
	}

	/**
	 * Plain-text one-line summary for a step card.
	 *
	 * @param array<string, mixed> $step Step data.
	 */
	private static function step_summary( array $step ): string {
		$kind   = (string) ( $step['kind'] ?? AutomationRepository::KIND_ACTION );
		$config = (array) ( $step['config'] ?? array() );

		if ( AutomationRepository::KIND_ACTION === $kind ) {
			$key     = (string) ( $step['action_key'] ?? '' );
			$label   = ActionRegistry::actions()[ $key ] ?? self::kind_label( $kind );
			$detail  = '';
			$term_id = array( ActionRegistry::APPLY_LIST, ActionRegistry::REMOVE_LIST, ActionRegistry::APPLY_TAG, ActionRegistry::REMOVE_TAG );

			if ( in_array( $key, $term_id, true ) ) {
				$names = self::term_name_map();
				$picked = array();

				foreach ( (array) ( $config['term_ids'] ?? array() ) as $id ) {
					if ( isset( $names[ (int) $id ] ) ) {
						$picked[] = $names[ (int) $id ];
					}
				}

				$detail = implode( '、', array_values( array_unique( $picked ) ) );
			} elseif ( ActionRegistry::SET_STATUS === $key ) {
				$status = (string) ( $config['status'] ?? '' );
				$detail = Status::is_valid( $status ) ? Status::label( $status ) : '';
			} elseif ( ActionRegistry::ADD_TO_SEQUENCE === $key ) {
				$sequences = self::sequence_name_map();
				$sequence_id = (int) ( $config['sequence_id'] ?? 0 );
				$detail    = $sequences[ $sequence_id ] ?? '';

				if ( '' === $detail && $sequence_id > 0 ) {
					$sequence = ( new SequenceRepository() )->get( $sequence_id );

					if ( $sequence ) {
						$detail = (string) $sequence['name'] . '（' . SequenceListPage::status_label( (string) $sequence['status'] ) . '）';
					}
				}
			} elseif ( in_array( $key, array( ActionRegistry::APPLY_COMPANY, ActionRegistry::DETACH_COMPANY ), true ) ) {
				$companies = self::company_name_map();
				$detail    = $companies[ (int) ( $config['company_id'] ?? 0 ) ] ?? '';
			}

			return '' !== $detail ? $label . '：' . $detail : $label;
		}

		if ( AutomationRepository::KIND_WAIT === $kind ) {
			$seconds = (int) ( $step['delay_seconds'] ?? 0 );

			return $seconds > 0
				? sprintf(
					/* translators: %s: human duration such as "1 天 2 小時". */
					__( '等待 %s', 'now-crm' ),
					self::duration_text( $seconds )
				)
				: __( '不等待', 'now-crm' );
		}

		if ( AutomationRepository::KIND_EMAIL === $kind ) {
			$subject = trim( (string) ( $config['subject'] ?? '' ) );

			return '' !== $subject ? $subject : __( '尚未設定主旨', 'now-crm' );
		}

		if ( AutomationRepository::KIND_CONDITION === $kind ) {
			$groups = (array) ( $config['conditions']['groups'] ?? array() );
			$group_count = 0;
			$row_count   = 0;

			foreach ( $groups as $group ) {
				if ( ! is_array( $group ) ) {
					continue;
				}

				$group_count++;
				$row_count += count( $group );
			}

			return sprintf(
				/* translators: 1: condition group count, 2: condition row count. */
				__( '%1$d 組條件 · %2$d 個條件', 'now-crm' ),
				$group_count,
				$row_count
			);
		}

		if ( AutomationRepository::KIND_SPLIT === $kind ) {
			return sprintf(
				'A %d%% / B %d%%',
				(int) ( $config['weight_a'] ?? AutomationRepository::SPLIT_DEFAULT_WEIGHT ),
				(int) ( $config['weight_b'] ?? AutomationRepository::SPLIT_DEFAULT_WEIGHT )
			);
		}

		if ( AutomationRepository::KIND_GOAL === $kind ) {
			$event = (string) ( $config['event'] ?? AutomationRepository::GOAL_TERM_ATTACHED );
			$parts = array( AutomationRepository::goal_events()[ $event ] ?? $event );

			if ( AutomationRepository::is_goal_term_event( $event ) ) {
				$names  = self::term_name_map();
				$picked = array();

				foreach ( (array) ( $config['term_ids'] ?? array() ) as $term_id ) {
					if ( isset( $names[ (int) $term_id ] ) ) {
						$picked[] = $names[ (int) $term_id ];
					}
				}
			} else {
				$picked = array();

				foreach ( (array) ( $config['statuses'] ?? array() ) as $status_option ) {
					if ( Status::is_valid( (string) $status_option ) ) {
						$picked[] = Status::label( (string) $status_option );
					}
				}
			}

			if ( array() !== $picked ) {
				$parts[] = implode( '、', array_values( array_unique( $picked ) ) );
			}

			$parts[] = AutomationRepository::GOAL_MODE_OPTIONAL === (string) ( $config['mode'] ?? '' )
				? __( '選用', 'now-crm' )
				: __( '必經', 'now-crm' );

			$timeout = (int) ( $config['timeout_seconds'] ?? 0 );

			if ( $timeout > 0 ) {
				$parts[] = sprintf(
					/* translators: %s: human duration. */
					__( '逾時 %s', 'now-crm' ),
					self::duration_text( $timeout )
				);
			}

			return implode( ' · ', $parts );
		}

		if ( AutomationRepository::KIND_END === $kind ) {
			return __( '流程結束', 'now-crm' );
		}

		return self::kind_label( $kind );
	}

	/**
	 * Largest exact unit for a stored seconds value.
	 *
	 * @return array{value: int, unit: string}
	 */
	private static function duration_parts( int $seconds ): array {
		$seconds = max( 0, $seconds );

		if ( $seconds > 0 ) {
			foreach ( array( 'days' => 86400, 'hours' => 3600, 'minutes' => 60 ) as $unit => $size ) {
				if ( 0 === $seconds % $size ) {
					return array(
						'value' => intdiv( $seconds, $size ),
						'unit'  => $unit,
					);
				}
			}
		}

		return array(
			'value' => $seconds,
			'unit'  => 'seconds',
		);
	}

	/**
	 * Human duration without a leading verb.
	 */
	private static function duration_text( int $seconds ): string {
		$seconds = max( 0, $seconds );
		$parts   = array();

		foreach ( array( 86400 => __( '天', 'now-crm' ), 3600 => __( '小時', 'now-crm' ), 60 => __( '分', 'now-crm' ), 1 => __( '秒', 'now-crm' ) ) as $size => $label ) {
			$value = intdiv( $seconds, $size );

			if ( $value > 0 ) {
				$parts[] = $value . ' ' . $label;
				$seconds -= $value * $size;
			}
		}

		return implode( ' ', $parts );
	}

	/**
	 * Inline editor script.
	 */
	private static function render_script(): void {
		$kind_labels = array(
			'action'    => __( '動作', 'now-crm' ),
			'wait'      => __( '等待', 'now-crm' ),
			'email'     => 'Email',
			'condition' => __( '條件分支', 'now-crm' ),
			'split'     => __( '分流（A／B）', 'now-crm' ),
			'goal'      => __( '目標', 'now-crm' ),
			'end'       => __( '結束', 'now-crm' ),
		);

		$trigger_descriptions = array();

		foreach ( array_keys( TriggerRegistry::triggers() ) as $trigger_key ) {
			$trigger_descriptions[ $trigger_key ] = TriggerRegistry::description( $trigger_key );
		}
		?>
		<script>
		( function () {
			var labels = <?php echo wp_json_encode( $kind_labels ); ?>;
			var actionLabels = <?php echo wp_json_encode( ActionRegistry::actions() ); ?>;
			var goalEventLabels = <?php echo wp_json_encode( AutomationRepository::goal_events() ); ?>;
			var triggerDescriptions = <?php echo wp_json_encode( $trigger_descriptions ); ?>;
			var root = document.getElementById( 'nowcrm-steps' );
			var hidden = document.getElementById( 'nowcrm-steps-json' );
			var template = document.getElementById( 'nowcrm-step-template' );
			var form = document.getElementById( 'nowcrm-automation-form' );

			if ( ! root || ! hidden || ! template || ! form ) {
				return;
			}

			root.classList.add( 'nowcrm-automation--js' );

			var errorBox = document.getElementById( 'nowcrm-automation-error' );
			var saveButton = document.querySelector( '[data-role="save-button"]' );
			var saveState = document.querySelector( '[data-save-state]' );
			var drawer = document.getElementById( 'nowcrm-step-drawer' );
			var drawerBody = drawer ? drawer.querySelector( '[data-role="drawer-body"]' ) : null;
			var drawerTitle = drawer ? drawer.querySelector( '[data-role="drawer-title"]' ) : null;
			var drawerEyebrow = drawer ? drawer.querySelector( '[data-role="drawer-eyebrow"]' ) : null;
			var drawerDelete = drawer ? drawer.querySelector( '[data-drawer-delete]' ) : null;
			var drawerBack = drawer ? drawer.querySelector( '[data-drawer-back]' ) : null;
			var picker = document.getElementById( 'nowcrm-step-picker' );
			var pickerSearch = picker ? picker.querySelector( '[data-picker-search]' ) : null;
			var pickerEmpty = picker ? picker.querySelector( '[data-picker-empty]' ) : null;
			var addButton = document.getElementById( 'nowcrm-add-step' );
			var flowEmpty = document.querySelector( '[data-role="flow-empty"]' );
			var openCard = null;
			var pickerTarget = null;
			var drawerStack = [];
			var dirty = false;
			var saving = false;
			var changeVersion = 0;
			var invalid = false;
			var collectErrors = [];

			var saveFailedText = <?php echo wp_json_encode( __( '儲存失敗，請檢查欄位後再試。', 'now-crm' ) ); ?>;
			var dirtyText = <?php echo wp_json_encode( __( '未儲存變更', 'now-crm' ) ); ?>;
			var cleanText = <?php echo wp_json_encode( __( '已儲存', 'now-crm' ) ); ?>;
			var savingText = <?php echo wp_json_encode( __( '儲存中…', 'now-crm' ) ); ?>;
			var lateEditText = <?php echo wp_json_encode( __( '已儲存，但儲存期間還有新變更尚未寫入。', 'now-crm' ) ); ?>;
			var frameLoadingText = <?php echo wp_json_encode( __( '正在載入 Gutenberg 編輯器…', 'now-crm' ) ); ?>;
			var frameFailedText = <?php echo wp_json_encode( __( 'Gutenberg 編輯器載入失敗，請重新載入頁面。', 'now-crm' ) ); ?>;
			var deleteConfirm = <?php echo wp_json_encode( __( '移除這個步驟？', 'now-crm' ) ); ?>;
			var invalidAlert = <?php echo wp_json_encode( __( '請修正標示的欄位：條件需要完整的欄位、運算子與值；目標需要名單／標籤或狀態；等待、逾時與分流權重必須是範圍內的整數。', 'now-crm' ) ); ?>;
			var missingNameText = <?php echo wp_json_encode( __( '請輸入自動化名稱。', 'now-crm' ) ); ?>;
			var integerText = <?php echo wp_json_encode( __( '請輸入整數。', 'now-crm' ) ); ?>;
			var integerRangeText = <?php echo wp_json_encode( __( '請輸入範圍內的整數。', 'now-crm' ) ); ?>;
			var splitTotalText = <?php echo wp_json_encode( __( 'A＋B 權重必須是 0–100 的整數且合計 100。', 'now-crm' ) ); ?>;
			var goalTargetText = <?php echo wp_json_encode( __( '請選擇目標的名單／標籤或狀態。', 'now-crm' ) ); ?>;
			var jsonSwitchText = <?php echo wp_json_encode( __( '進階 JSON 目前是唯一來源。要改用視覺化編輯並覆蓋 JSON 嗎？', 'now-crm' ) ); ?>;
			var visualModeText = <?php echo wp_json_encode( __( '視覺化條件', 'now-crm' ) ); ?>;
			var jsonModeText = <?php echo wp_json_encode( __( '進階 JSON 模式', 'now-crm' ) ); ?>;
			var unreachableText = <?php echo wp_json_encode( __( '上方已有結束步驟，不會執行。', 'now-crm' ) ); ?>;
			var terminalText = <?php echo wp_json_encode( __( '流程在此結束，之後的步驟不會執行。', 'now-crm' ) ); ?>;

			var unitSeconds = {
				seconds: 1,
				minutes: 60,
				hours: 3600,
				days: 86400
			};

			var actionGroup = {
				apply_list: 'term',
				remove_list: 'term',
				apply_tag: 'term',
				remove_tag: 'term',
				set_status: 'status',
				apply_company: 'company',
				detach_company: 'company',
				add_to_sequence: 'sequence'
			};

			var conditionSchema = {};

			try {
				conditionSchema = JSON.parse( root.getAttribute( 'data-nowcrm-conditions' ) || '{}' );
			} catch ( error ) {
				conditionSchema = {};
			}

			var conditionFields = conditionSchema.fields || {};
			var conditionOperators = conditionSchema.operators || {};
			var conditionProviders = conditionSchema.providers || {};
			var conditionValueless = conditionSchema.valueless || [];
			var conditionTerms = conditionSchema.terms || {};
			var conditionMaxGroups = conditionSchema.maxGroups || 5;
			var conditionMaxRows = conditionSchema.maxRows || 10;

			var triggerGroups = {};

			try {
				triggerGroups = JSON.parse( root.getAttribute( 'data-nowcrm-trigger-groups' ) || '{}' );
			} catch ( triggerGroupsError ) {
				triggerGroups = {};
			}

			var chipData = {};

			try {
				chipData = JSON.parse( root.getAttribute( 'data-nowcrm-chips' ) || '{}' );
			} catch ( chipDataError ) {
				chipData = {};
			}

			form.setAttribute( 'novalidate', 'novalidate' );

			var text = {
				chooseField: <?php echo wp_json_encode( __( '選擇欄位', 'now-crm' ) ); ?>,
				chooseOperator: <?php echo wp_json_encode( __( '選擇運算子', 'now-crm' ) ); ?>,
				remove: <?php echo wp_json_encode( __( '移除', 'now-crm' ) ); ?>,
				addRow: <?php echo wp_json_encode( __( '＋ 新增條件', 'now-crm' ) ); ?>,
				addGroup: <?php echo wp_json_encode( __( '＋ 新增群組（OR）', 'now-crm' ) ); ?>,
				or: <?php echo wp_json_encode( __( '或 (OR)', 'now-crm' ) ); ?>,
				group: <?php
					/* translators: %d: group number. */
					echo wp_json_encode( __( '群組 %d（條件之間：AND）', 'now-crm' ) );
				?>,
				field: <?php echo wp_json_encode( __( '欄位', 'now-crm' ) ); ?>,
				operator: <?php echo wp_json_encode( __( '運算子', 'now-crm' ) ); ?>,
				value: <?php echo wp_json_encode( __( '值', 'now-crm' ) ); ?>,
				empty: <?php echo wp_json_encode( __( '至少要有一個完整條件：欄位、運算子與值。', 'now-crm' ) ); ?>,
				incomplete: <?php echo wp_json_encode( __( '有條件尚未填完，請補上欄位、運算子與值。', 'now-crm' ) ); ?>,
				dirtyLost: <?php echo wp_json_encode( __( '有未儲存的變更，這個操作不會儲存它們。要繼續嗎？', 'now-crm' ) ); ?>,
				badJson: <?php echo wp_json_encode( __( '進階 JSON 格式不正確。', 'now-crm' ) ); ?>,
				dateHint: <?php echo wp_json_encode( __( 'YYYY-MM-DD，或天數', 'now-crm' ) ); ?>,
				noWait: <?php echo wp_json_encode( __( '不等待', 'now-crm' ) ); ?>,
				waitPrefix: <?php echo wp_json_encode( __( '等待 ', 'now-crm' ) ); ?>,
				noSubject: <?php echo wp_json_encode( __( '尚未設定主旨', 'now-crm' ) ); ?>,
				flowEnd: <?php echo wp_json_encode( __( '流程結束', 'now-crm' ) ); ?>,
				groups: <?php echo wp_json_encode( __( '組條件', 'now-crm' ) ); ?>,
				conditions: <?php echo wp_json_encode( __( '個條件', 'now-crm' ) ); ?>,
				required: <?php echo wp_json_encode( __( '必經', 'now-crm' ) ); ?>,
				optional: <?php echo wp_json_encode( __( '選用', 'now-crm' ) ); ?>,
				timeout: <?php echo wp_json_encode( __( '逾時 ', 'now-crm' ) ); ?>,
				dotSeparator: ' · '
			};

			text.unsupported = <?php echo wp_json_encode( __( '這份 JSON 含有視覺化無法表示的條件，已保留 JSON 模式，未覆寫內容。', 'now-crm' ) ); ?>;

			function esc( value ) {
				return String( value === null || value === undefined ? '' : value )
					.replace( /&/g, '&amp;' )
					.replace( /</g, '&lt;' )
					.replace( />/g, '&gt;' )
					.replace( /"/g, '&quot;' );
			}

			function showSaveError( message ) {
				if ( ! errorBox ) {
					window.alert( message );
					return;
				}

				var paragraph = errorBox.querySelector( 'p' );

				if ( paragraph ) {
					paragraph.textContent = message;
				}

				errorBox.hidden = false;
				errorBox.setAttribute( 'tabindex', '-1' );

				if ( errorBox.scrollIntoView ) {
					errorBox.scrollIntoView( { block: 'nearest' } );
				}

				errorBox.focus( { preventScroll: true } );
			}

			function markDirty() {
				changeVersion++;

				if ( dirty ) {
					return;
				}

				dirty = true;

				if ( saveState ) {
					saveState.textContent = dirtyText;
					saveState.classList.add( 'is-dirty' );
				}
			}

			function markClean() {
				dirty = false;

				if ( saveState ) {
					saveState.textContent = cleanText;
					saveState.classList.remove( 'is-dirty' );
				}
			}

			function finishSaving() {
				saving = false;

				if ( saveButton ) {
					saveButton.disabled = false;
				}
			}

			function showLateSave( redirect ) {
				if ( ! saveState ) {
					return;
				}

				saveState.textContent = '';
				saveState.appendChild( document.createTextNode( lateEditText + ' ' ) );

				if ( redirect ) {
					var link = document.createElement( 'a' );
					link.href = redirect;
					link.textContent = <?php echo wp_json_encode( __( '重新載入', 'now-crm' ) ); ?>;
					saveState.appendChild( link );
				}

				saveState.classList.add( 'is-dirty' );
			}

			function submitLive( formNode, extra ) {
				var requestVersion = changeVersion;
				saving = true;

				if ( saveButton ) {
					saveButton.disabled = true;
				}

				if ( saveState ) {
					saveState.textContent = savingText;
					saveState.classList.remove( 'is-dirty' );
				}

				var data = new window.FormData( formNode );

				Object.keys( extra || {} ).forEach( function ( key ) {
					data.append( key, extra[ key ] );
				} );

				// A hidden input named "action" shadows form.action, so read the
				// attribute instead of the DOM property.
				window.fetch( formNode.getAttribute( 'action' ), {
					method: 'POST',
					body: data,
					credentials: 'same-origin'
				} ).then( function ( response ) {
					return response.text().then( function ( body ) {
						var payload = null;

						try {
							payload = JSON.parse( body );
						} catch ( parseError ) {
							payload = null;
						}

						return { payload: payload };
					} );
				} ).then( function ( result ) {
					if ( result.payload && result.payload.success && result.payload.data && result.payload.data.redirect ) {
						if ( changeVersion !== requestVersion ) {
							finishSaving();
							showLateSave( result.payload.data.redirect );
							return;
						}

						markClean();
						window.location.href = result.payload.data.redirect;
						return;
					}

					var message = result.payload && result.payload.data && result.payload.data.message ? result.payload.data.message : saveFailedText;
					finishSaving();
					showSaveError( message );
				} ).catch( function () {
					finishSaving();
					showSaveError( saveFailedText );
				} );
			}

			function bodyOf( card ) {
				if ( card.nowcrmBody ) {
					return card.nowcrmBody;
				}

				var body = card.querySelector( ':scope > .nowcrm-step-card__body' );

				if ( body ) {
					card.nowcrmBody = body;
				}

				return body;
			}

			function ownsNode( body, node ) {
				var element = node;

				while ( element && element !== body ) {
					if ( element.nodeType === 1 && element.classList && element.classList.contains( 'nowcrm-step-card' ) ) {
						return false;
					}

					element = element.parentElement;
				}

				return element === body;
			}

			function ownNodes( card, selector ) {
				var body = bodyOf( card );

				if ( ! body ) {
					return [];
				}

				return Array.prototype.filter.call( body.querySelectorAll( selector ), function ( node ) {
					return ownsNode( body, node );
				} );
			}

			function ownNode( card, selector ) {
				var nodes = ownNodes( card, selector );

				return nodes.length ? nodes[ 0 ] : null;
			}

			function fieldEl( card, name ) {
				var body = bodyOf( card );

				if ( ! body ) {
					return null;
				}

				var nodes = body.querySelectorAll( '[data-field="' + name + '"]' );

				for ( var index = 0; index < nodes.length; index++ ) {
					if ( ownsNode( body, nodes[ index ] ) ) {
						return nodes[ index ];
					}
				}

				return null;
			}

			function selectedOptions( card, name ) {
				var select = fieldEl( card, name );

				if ( ! select || ! select.options ) {
					return [];
				}

				return Array.prototype.slice.call( select.options ).filter( function ( option ) {
					return option.selected;
				} );
			}

			function optionText( select ) {
				if ( ! select || select.selectedIndex < 0 || ! select.options[ select.selectedIndex ] ) {
					return '';
				}

				return String( select.options[ select.selectedIndex ].textContent || '' ).replace( /\s+/g, ' ' ).trim();
			}

			function selectedLabels( card, name ) {
				return selectedOptions( card, name ).map( function ( option ) {
					var label = String( option.textContent || '' ).replace( /\s+/g, ' ' ).trim();
					var bits = label.split( '：' );

					return bits.length > 1 ? bits.slice( 1 ).join( '：' ).trim() : label;
				} ).filter( function ( value, index, list ) {
					return value && list.indexOf( value ) === index;
				} );
			}

			function durationText( seconds ) {
				seconds = Math.max( 0, parseInt( seconds, 10 ) || 0 );

				if ( 0 === seconds ) {
					return '';
				}

				var units = [ [ <?php echo wp_json_encode( __( '天', 'now-crm' ) ); ?>, 86400 ], [ <?php echo wp_json_encode( __( '小時', 'now-crm' ) ); ?>, 3600 ], [ <?php echo wp_json_encode( __( '分', 'now-crm' ) ); ?>, 60 ], [ <?php echo wp_json_encode( __( '秒', 'now-crm' ) ); ?>, 1 ] ];
				var parts = [];

				units.forEach( function ( unit ) {
					var value = Math.floor( seconds / unit[ 1 ] );

					if ( value > 0 ) {
						parts.push( value + ' ' + unit[ 0 ] );
						seconds -= value * unit[ 1 ];
					}
				} );

				return parts.join( ' ' );
			}

			function durationFromControls( card, valueName, unitName, hiddenName ) {
				var valueField = fieldEl( card, valueName );
				var unitField = fieldEl( card, unitName );

				if ( valueField && unitField ) {
					var value = parseInt( valueField.value, 10 );

					if ( isNaN( value ) || value < 0 ) {
						value = 0;
					}

					return value * ( unitSeconds[ unitField.value ] || 1 );
				}

				var hiddenField = fieldEl( card, hiddenName );

				return hiddenField ? parseInt( hiddenField.value, 10 ) || 0 : 0;
			}

			function syncDuration( card, valueName, unitName, hiddenName ) {
				var hiddenField = fieldEl( card, hiddenName );
				var valueField = fieldEl( card, valueName );
				var unitField = fieldEl( card, unitName );

				if ( ! hiddenField ) {
					return;
				}

				hiddenField.value = String( durationFromControls( card, valueName, unitName, hiddenName ) );
			}

			function bindDuration( card, valueName, unitName, hiddenName ) {
				var valueField = fieldEl( card, valueName );
				var unitField = fieldEl( card, unitName );

				if ( valueField ) {
					valueField.addEventListener( 'input', function () {
						syncDuration( card, valueName, unitName, hiddenName );
					} );
				}

				if ( unitField ) {
					unitField.addEventListener( 'change', function () {
						syncDuration( card, valueName, unitName, hiddenName );
					} );
				}

				syncDuration( card, valueName, unitName, hiddenName );
			}

			function conditionCounts( card ) {
				var builder = ownNode( card, '[data-role="condition-builder"]' );
				var counts = { groups: 0, rows: 0 };

				if ( ! builder ) {
					return counts;
				}

				Array.prototype.forEach.call( builder.querySelectorAll( '[data-condition-group]' ), function ( group ) {
					var filled = 0;

					Array.prototype.forEach.call( group.querySelectorAll( '[data-condition-row]' ), function ( row ) {
						var select = row.querySelector( '[data-cond="field"]' );

						if ( select && select.value ) {
							filled++;
						}
					} );

					if ( filled > 0 ) {
						counts.groups++;
						counts.rows += filled;
					}
				} );

				return counts;
			}

			function stepSummary( card ) {
				var kindField = fieldEl( card, 'kind' );
				var kind = kindField ? kindField.value : ( card.dataset.kind || 'action' );

				if ( 'action' === kind ) {
					var actionField = fieldEl( card, 'action_key' );
					var key = actionField ? actionField.value : '';
					var label = actionLabels[ key ] || labels.action;
					var group = actionGroup[ key ];
					var detail = '';

					if ( 'term' === group ) {
						detail = selectedLabels( card, 'term_ids' ).join( '、' );
					} else if ( 'status' === group ) {
						detail = selectedLabels( card, 'status' ).join( '、' );
					} else if ( 'sequence' === group ) {
						var sequenceField = fieldEl( card, 'sequence_id' );

						if ( sequenceField && sequenceField.value !== '0' ) {
							detail = optionText( sequenceField );
						}
					} else if ( 'company' === group ) {
						var companyField = fieldEl( card, 'company_id' );

						if ( companyField && companyField.value !== '0' ) {
							detail = optionText( companyField );
						}
					}

					return detail ? label + '：' + detail : label;
				}

				if ( 'wait' === kind ) {
					var seconds = durationFromControls( card, 'delay_value', 'delay_unit', 'delay_seconds' );

					return seconds > 0 ? text.waitPrefix + durationText( seconds ) : text.noWait;
				}

				if ( 'email' === kind ) {
					var subjectField = fieldEl( card, 'subject' );
					var subject = subjectField ? subjectField.value.trim() : '';

					return subject || text.noSubject;
				}

				if ( 'condition' === kind ) {
					var counts = { groups: 0, rows: 0 };

					if ( card.dataset.conditionMode === 'json' ) {
						var summaryJson = fieldEl( card, 'conditions_json' );

						try {
							var summaryPlan = JSON.parse( summaryJson ? summaryJson.value : '' );

							if ( summaryPlan && Array.isArray( summaryPlan.groups ) ) {
								summaryPlan.groups.forEach( function ( group ) {
									if ( Array.isArray( group ) && group.length ) {
										counts.groups++;
										counts.rows += group.length;
									}
								} );
							}
						} catch ( summaryError ) {
							counts = { groups: 0, rows: 0 };
						}
					} else {
						counts = conditionCounts( card );
					}

					return counts.groups + ' ' + text.groups + text.dotSeparator + counts.rows + ' ' + text.conditions;
				}

				if ( 'split' === kind ) {
					var weightA = fieldEl( card, 'weight_a' );
					var weightB = fieldEl( card, 'weight_b' );

					return 'A ' + ( weightA ? weightA.value : '0' ) + '% / B ' + ( weightB ? weightB.value : '0' ) + '%';
				}

				if ( 'goal' === kind ) {
					var eventField = fieldEl( card, 'goal_event' );
					var eventKey = eventField ? eventField.value : '';
					var parts = [ goalEventLabels[ eventKey ] || eventKey ];
					var targets = eventKey === 'status_changed'
						? selectedLabels( card, 'goal_statuses' )
						: selectedLabels( card, 'goal_term_ids' );

					if ( targets.length ) {
						parts.push( targets.join( '、' ) );
					}

					var modeField = fieldEl( card, 'goal_mode' );
					var goalOptional = modeField && modeField.value === 'optional';
					parts.push( goalOptional ? text.optional : text.required );

					if ( ! goalOptional ) {
						var timeout = durationFromControls( card, 'goal_timeout_value', 'goal_timeout_unit', 'goal_timeout' );

						if ( timeout > 0 ) {
							parts.push( text.timeout + durationText( timeout ) );
						}
					}

					return parts.join( text.dotSeparator );
				}

				if ( 'end' === kind ) {
					return text.flowEnd;
				}

				return labels[ kind ] || kind;
			}

			function refreshSummary( card ) {
				var title = card.querySelector( '[data-role="step-title"]' );

				if ( title ) {
					title.textContent = stepSummary( card );
				}

				if ( openCard === card ) {
					updateDrawerHead( card );
				}
			}

			function updateDrawerHead( card ) {
				var kindField = fieldEl( card, 'kind' );
				var kind = kindField ? kindField.value : ( card.dataset.kind || 'action' );

				if ( drawerEyebrow ) {
					drawerEyebrow.textContent = labels[ kind ] || kind;
				}

				if ( drawerTitle ) {
					var title = card.querySelector( '[data-role="step-title"]' );
					drawerTitle.textContent = title ? title.textContent : ( labels[ kind ] || kind );
				}
			}

			function setStepFlag( card, message ) {
				var flag = card.querySelector( ':scope > .nowcrm-step-card__head [data-role="step-flag"]' );

				if ( ! flag ) {
					return;
				}

				flag.textContent = message || '';
				flag.hidden = ! message;
			}

			function refreshList( list ) {
				if ( ! list ) {
					return;
				}

				var ended = false;
				var cards = Array.prototype.filter.call( list.children, function ( child ) {
					return child.classList && child.classList.contains( 'nowcrm-step-card' );
				} );

				cards.forEach( function ( card, index ) {
					var kindField = fieldEl( card, 'kind' );
					var kind = kindField ? kindField.value : ( card.dataset.kind || 'action' );

					card.classList.toggle( 'is-unreachable', ended );
					setStepFlag( card, ended ? unreachableText : '' );

					if ( kind === 'end' ) {
						if ( ! ended && index < cards.length - 1 ) {
							setStepFlag( card, terminalText );
						}

						ended = true;
					}
				} );

				if ( list === root ) {
					root.classList.toggle( 'nowcrm-automation__flow--empty', cards.length === 0 );

					if ( flowEmpty ) {
						flowEmpty.hidden = cards.length !== 0;
					}
				}
			}

			function refreshAllLists() {
				refreshList( root );
				root.querySelectorAll( '.nowcrm-step-list' ).forEach( function ( list ) {
					refreshList( list );
				} );
			}

			function triggerSelect() {
				return document.querySelector( '[data-testid="automation-trigger"]' );
			}

			function activeTriggerGroups() {
				var select = triggerSelect();

				return select ? ( triggerGroups[ select.value ] || [] ) : [];
			}

			function syncTriggerGroups() {
				var groups = activeTriggerGroups();
				var any = false;

				document.querySelectorAll( '[data-trigger-group]' ).forEach( function ( group ) {
					var on = groups.indexOf( group.getAttribute( 'data-trigger-group' ) ) >= 0;
					group.hidden = ! on;

					if ( on ) {
						any = true;
					}

					group.querySelectorAll( 'input, select, textarea' ).forEach( function ( control ) {
						control.disabled = ! on;
					} );
				} );

				var empty = document.querySelector( '[data-role="trigger-filters-empty"]' );

				if ( empty ) {
					empty.hidden = any;
				}
			}

			function selectedControlValues( name ) {
				var field = form.querySelector( '[name="' + name + '"]' );

				if ( ! field || ! field.options ) {
					return [];
				}

				return Array.prototype.slice.call( field.options ).filter( function ( option ) {
					return option.selected;
				} ).map( function ( option ) {
					return option.value;
				} );
			}

			function renderTriggerChips() {
				var container = document.querySelector( '[data-role="trigger-chips"]' );

				if ( ! container ) {
					return;
				}

				var groups = activeTriggerGroups();
				var chips = [];
				var statuses = chipData.statuses || {};
				var terms = chipData.terms || {};
				var termTypes = chipData.termTypes || {};

				if ( groups.indexOf( 'statuses' ) >= 0 ) {
					selectedControlValues( 'trigger_statuses[]' ).forEach( function ( value ) {
						if ( statuses[ value ] ) {
							chips.push( statuses[ value ] );
						}
					} );
				}

				if ( groups.indexOf( 'terms' ) >= 0 ) {
					selectedControlValues( 'trigger_term_ids[]' ).forEach( function ( value ) {
						if ( terms[ value ] ) {
							chips.push( terms[ value ] );
						}
					} );

					selectedControlValues( 'trigger_term_types[]' ).forEach( function ( value ) {
						if ( termTypes[ value ] ) {
							chips.push( termTypes[ value ] );
						}
					} );
				}

				if ( groups.indexOf( 'sources' ) >= 0 ) {
					var sources = form.querySelector( '[name="trigger_sources"]' );

					if ( sources ) {
						sources.value.split( ',' ).forEach( function ( source ) {
							var value = source.trim();

							if ( value ) {
								chips.push( value );
							}
						} );
					}
				}

				var runOnce = form.querySelector( '[name="run_once"]' );
				var forceRun = form.querySelector( '[name="force_run"]' );
				chips.push( runOnce && runOnce.checked ? chipData.runOnce : chipData.reentry );

				if ( forceRun && forceRun.checked ) {
					chips.push( chipData.forceRun );
				}

				chips = chips.filter( function ( chip, index, list ) {
					return chip && list.indexOf( chip ) === index;
				} );

				container.textContent = '';
				container.hidden = chips.length === 0;
				chips.forEach( function ( chip ) {
					var span = document.createElement( 'span' );
					span.className = 'nowcrm-automation__chip';
					span.textContent = chip;
					container.appendChild( span );
				} );
			}

			function refreshTriggerSummary() {
				var select = triggerSelect();
				var description = document.querySelector( '[data-role="trigger-description"]' );
				var label = document.querySelector( '[data-role="trigger-label"]' );

				if ( ! select ) {
					return;
				}

				if ( description ) {
					description.textContent = triggerDescriptions[ select.value ] || '';
				}

				if ( label ) {
					var option = select.options[ select.selectedIndex ];
					label.textContent = option ? option.textContent.trim() : select.value;
				}

				syncTriggerGroups();
				renderTriggerChips();
			}

			function setDrawerOpen( open ) {
				if ( ! drawer ) {
					return;
				}

				if ( open ) {
					if ( typeof drawer.showModal === 'function' ) {
						if ( ! drawer.open ) {
							drawer.showModal();
						}
					} else {
						drawer.setAttribute( 'open', 'open' );
					}

					document.body.classList.add( 'nowcrm-automation-drawer-open' );
					return;
				}

				if ( typeof drawer.close === 'function' ) {
					if ( drawer.open ) {
						drawer.close();
					}
				} else {
					drawer.removeAttribute( 'open' );
				}

				document.body.classList.remove( 'nowcrm-automation-drawer-open' );
			}

			function emailFrameBridge( card ) {
				var body = bodyOf( card );
				var frame = body ? body.querySelector( '[data-workflow-frame]' ) : null;

				if ( ! frame ) {
					return null;
				}

				try {
					return frame.contentWindow && frame.contentWindow.nowcrmEditorBridge ? frame.contentWindow.nowcrmEditorBridge : null;
				} catch ( error ) {
					return null;
				}
			}

			function emailFrameStatus( card, message, isError ) {
				var body = bodyOf( card );
				var node = body ? body.querySelector( '[data-workflow-frame-status]' ) : null;

				if ( ! node ) {
					return;
				}

				node.textContent = message || '';
				node.classList.toggle( 'is-error', !! isError );
			}

			function seedEmailFrame( card ) {
				var bridge = emailFrameBridge( card );
				var body = bodyOf( card );
				var area = body ? body.querySelector( '[data-workflow-field="content"]' ) : null;

				if ( ! bridge || ! bridge.isReady() || ! area || typeof bridge.replaceBlocks !== 'function' ) {
					emailFrameStatus( card, frameFailedText, true );
					return false;
				}

				var result = bridge.replaceBlocks( area.value );

				if ( ! result || ! result.ready ) {
					emailFrameStatus( card, frameFailedText, true );
					return false;
				}

				card.nowcrmEmailSeed = typeof result.blocks === 'string' ? result.blocks : area.value;
				emailFrameStatus( card, '' );

				return true;
			}

			function syncEmailFrame( card ) {
				var bridge = emailFrameBridge( card );
				var body = bodyOf( card );
				var area = body ? body.querySelector( '[data-workflow-field="content"]' ) : null;

				if ( ! bridge || ! bridge.isReady() || ! area ) {
					return false;
				}

				var value = bridge.blocks();

				if ( typeof value !== 'string' ) {
					return false;
				}

				if ( bridge.isDirty ? bridge.isDirty() : value !== card.nowcrmEmailSeed ) {
					area.value = value;
				}

				return true;
			}

			function mountEmailFrame( card ) {
				var body = bodyOf( card );
				var host = body ? body.querySelector( '[data-workflow-frame-host]' ) : null;

				if ( ! host || host.querySelector( 'iframe' ) ) {
					return;
				}

				var url = host.getAttribute( 'data-workflow-frame-url' );

				if ( ! url ) {
					emailFrameStatus( card, frameFailedText, true );
					return;
				}

				emailFrameStatus( card, frameLoadingText, false );

				var frame = document.createElement( 'iframe' );
				frame.className = 'nowcrm-template-frame';
				frame.setAttribute( 'data-workflow-frame', '' );
				frame.title = <?php echo wp_json_encode( __( 'Gutenberg 內容編輯器', 'now-crm' ) ); ?>;
				frame.src = url;
				host.appendChild( frame );

				var deadline = Date.now() + 25000;

				( function waitForFrame() {
					if ( ! frame.isConnected ) {
						return;
					}

					var bridge = emailFrameBridge( card );

					if ( bridge && bridge.isReady() ) {
						seedEmailFrame( card );
						return;
					}

					if ( Date.now() > deadline ) {
						emailFrameStatus( card, frameFailedText, true );
						return;
					}

					window.setTimeout( waitForFrame, 250 );
				}() );
			}

			function returnBodyToCard( card ) {
				var body = bodyOf( card );

				if ( body && drawerBody && body.parentElement === drawerBody ) {
					card.appendChild( body );
					card.nowcrmBody = body;
				}
			}

			function setExpanded( card, expanded ) {
				var summaryButton = card.querySelector( ':scope > .nowcrm-step-card__head [data-action="edit"]' );

				if ( summaryButton ) {
					summaryButton.setAttribute( 'aria-expanded', expanded ? 'true' : 'false' );
				}
			}

			function summaryOf( card ) {
				return card.querySelector( ':scope > .nowcrm-step-card__head [data-action="edit"]' );
			}

			function openDrawer( card ) {
				if ( ! drawer || ! drawerBody || ! card ) {
					return;
				}

				var body = bodyOf( card );

				if ( ! body ) {
					return;
				}

				var active = document.activeElement;

				if ( openCard && openCard !== card ) {
					var opener = active && active.closest && active.closest( '.nowcrm-step-card' ) === openCard ? active : null;
					returnBodyToCard( openCard );
					openCard.classList.remove( 'is-editing' );
					setExpanded( openCard, false );
					drawerStack.push( openCard );
					card.__nowcrmOpener = opener;
				} else if ( ! openCard ) {
					card.__nowcrmOpener = active && active.closest ? active : null;
				}

				drawerBody.innerHTML = '';
				drawerBody.appendChild( body );
				openCard = card;

				if ( 'email' === ( card.dataset.kind || '' ) ) {
					var modeField = fieldEl( card, 'mode' );

					if ( modeField && 'blocks' === modeField.value ) {
						mountEmailFrame( card );
					}
				}

				setDrawerOpen( true );
				card.classList.add( 'is-editing' );
				setExpanded( card, true );
				updateDrawerHead( card );

				if ( drawerBack ) {
					drawerBack.hidden = drawerStack.length === 0;
				}

				var focusable = drawerBody.querySelector( 'input:not([disabled]), select:not([disabled]), textarea:not([disabled]), button:not([disabled])' );

				if ( focusable ) {
					focusable.focus( { preventScroll: true } );
				}
			}

			function detachOpenCard() {
				var card = openCard;

				if ( ! card ) {
					return null;
				}

				returnBodyToCard( card );
				card.classList.remove( 'is-editing' );
				setExpanded( card, false );
				openCard = null;

				if ( drawerBody ) {
					drawerBody.innerHTML = '';
				}

				return card;
			}

			function closeDrawer( returnBody, focusCard ) {
				var card = openCard;

				if ( returnBody === false && card ) {
					var body = bodyOf( card );

					if ( body && body.parentElement ) {
						body.parentElement.removeChild( body );
					}

					card.nowcrmBody = null;
					card.classList.remove( 'is-editing' );
					setExpanded( card, false );
					openCard = null;
				} else {
					card = detachOpenCard();
				}

				drawerStack = [];
				setDrawerOpen( false );

				if ( drawerBack ) {
					drawerBack.hidden = true;
				}

				if ( ! card ) {
					return;
				}

				if ( focusCard !== false ) {
					var opener = card.__nowcrmOpener;
					var summary = summaryOf( card );

					if ( opener && ! opener.disabled && opener.offsetParent !== null ) {
						opener.focus( { preventScroll: true } );
					} else if ( summary ) {
						summary.focus( { preventScroll: true } );
					}
				}

				if ( returnBody !== false ) {
					refreshSummary( card );
				}
			}

			function drawerBackStep() {
				if ( ! openCard || ! drawerStack.length ) {
					return;
				}

				var opener = openCard.__nowcrmOpener;
				detachOpenCard();

				var previous = drawerStack.pop();
				openCard = null;
				openDrawer( previous );

				if ( drawerBack ) {
					drawerBack.hidden = drawerStack.length === 0;
				}

				if ( opener && document.contains( opener ) && opener.offsetParent !== null ) {
					opener.focus( { preventScroll: true } );
				}
			}

			function filterPicker( query ) {
				if ( ! picker ) {
					return;
				}

				query = String( query || '' ).trim().toLowerCase();
				var visible = 0;

				picker.querySelectorAll( '[data-picker-group]' ).forEach( function ( group ) {
					var groupVisible = 0;

					group.querySelectorAll( '[data-picker-kind]' ).forEach( function ( card ) {
						var haystack = ( card.getAttribute( 'data-picker-text' ) || card.textContent || '' ).toLowerCase();
						var match = ! query || haystack.indexOf( query ) >= 0;
						card.hidden = ! match;

						if ( match ) {
							groupVisible++;
						}
					} );

					group.hidden = groupVisible === 0;
					visible += groupVisible;
				} );

				if ( pickerEmpty ) {
					pickerEmpty.hidden = visible !== 0;
				}
			}

			function openPicker( target ) {
				if ( ! picker ) {
					return;
				}

				pickerTarget = target || { list: root, after: null };

				if ( pickerSearch ) {
					pickerSearch.value = '';
				}

				filterPicker( '' );

				if ( typeof picker.showModal === 'function' ) {
					if ( ! picker.open ) {
						picker.showModal();
					}
				} else {
					picker.setAttribute( 'open', 'open' );
				}

				window.setTimeout( function () {
					if ( pickerSearch ) {
						pickerSearch.focus( { preventScroll: true } );
					}
				}, 0 );
			}

			function closePicker() {
				if ( ! picker ) {
					return;
				}

				if ( typeof picker.close === 'function' ) {
					if ( picker.open ) {
						picker.close();
					}
				} else {
					picker.removeAttribute( 'open' );
				}

				pickerTarget = null;
			}

			function firstEndCard( list ) {
				var children = Array.prototype.slice.call( list.children );

				for ( var index = 0; index < children.length; index++ ) {
					var child = children[ index ];

					if ( child.classList && child.classList.contains( 'nowcrm-step-card' ) && child.dataset.kind === 'end' ) {
						return child;
					}
				}

				return null;
			}

			function add( target, kind ) {
				target = target || { list: root, after: null };

				var list = target.list || root;
				var card = template.content.firstElementChild.cloneNode( true );
				card.querySelector( '[data-field="kind"]' ).value = kind;
				card.dataset.kind = kind;

				if ( target.after && target.after.parentElement === list ) {
					list.insertBefore( card, target.after.nextSibling );
				} else {
					var endCard = firstEndCard( list );

					if ( endCard ) {
						list.insertBefore( card, endCard );
					} else {
						list.appendChild( card );
					}
				}

				bind( card );
				refreshSummary( card );
				refreshAllLists();
				markDirty();
				openDrawer( card );
			}

			function ensureDrawerClearOfRemoved( card ) {
				if ( openCard && ( openCard === card || card.contains( openCard ) || openCard.contains( card ) || ! root.contains( openCard ) ) ) {
					closeDrawer( true, false );
				}
			}

			function fieldDefinition( key ) {
				return conditionFields[ key ] || null;
			}

			function allowedOperators( definition ) {
				if ( definition && definition.ops && definition.ops.length ) {
					return definition.ops;
				}

				return Object.keys( conditionOperators );
			}

			function valueOptions( definition ) {
				if ( ! definition ) {
					return {};
				}

				if ( definition.type === 'terms' ) {
					return conditionTerms[ definition.term_type ] || {};
				}

				return definition.options || {};
			}

			function isOptionType( definition ) {
				return !! definition && [ 'select', 'terms', 'custom_select', 'custom_multi' ].indexOf( definition.type ) >= 0;
			}

			function fieldSelectHtml( key ) {
				var html = '<select data-cond="field"><option value="">' + esc( text.chooseField ) + '</option>';
				var byProvider = {};
				var order = [];

				Object.keys( conditionFields ).forEach( function ( fieldKey ) {
					var definition = conditionFields[ fieldKey ];
					var provider = definition.provider || '';

					if ( ! byProvider[ provider ] ) {
						byProvider[ provider ] = [];
						order.push( provider );
					}

					byProvider[ provider ].push( '<option value="' + esc( fieldKey ) + '"' + ( fieldKey === key ? ' selected' : '' ) + '>' + esc( definition.label ) + '</option>' );
				} );

				order.forEach( function ( provider ) {
					html += '<optgroup label="' + esc( conditionProviders[ provider ] || provider ) + '">' + byProvider[ provider ].join( '' ) + '</optgroup>';
				} );

				return html + '</select>';
			}

			function valueControlHtml( definition, value ) {
				var selected = Array.isArray( value ) ? value : ( value === '' || value === null || value === undefined ? [] : [ String( value ) ] );
				var type = definition ? definition.type : 'text';

				if ( type === 'number' || type === 'custom_number' ) {
					return '<input type="number" step="any" data-cond="value" value="' + esc( selected[0] || '' ) + '" />';
				}

				if ( type === 'date' || type === 'custom_date' ) {
					return '<input type="text" data-cond="value" value="' + esc( selected[0] || '' ) + '" placeholder="' + esc( text.dateHint ) + '" />';
				}

				if ( ! isOptionType( definition ) ) {
					return '<input type="text" data-cond="value" value="' + esc( selected[0] || '' ) + '" />';
				}

				var options = valueOptions( definition );
				var html = '<select multiple size="4" data-cond="value">';

				Object.keys( options ).forEach( function ( optionValue ) {
					html += '<option value="' + esc( optionValue ) + '"' + ( selected.indexOf( optionValue ) >= 0 ? ' selected' : '' ) + '>' + esc( options[ optionValue ] ) + '</option>';
				} );

				return html + '</select>';
			}

			function syncConditionRow( row, operator, value ) {
				var key = row.querySelector( '[data-cond="field"]' ).value;
				var definition = fieldDefinition( key );
				var allowed = allowedOperators( definition );
				var operatorSelect = row.querySelector( '[data-cond="operator"]' );
				var current = operator && allowed.indexOf( operator ) >= 0 ? operator : operatorSelect.value;

				if ( allowed.indexOf( current ) < 0 ) {
					current = allowed[0] || '';
				}

				operatorSelect.innerHTML = allowed.map( function ( op ) {
					return '<option value="' + esc( op ) + '">' + esc( conditionOperators[ op ] || op ) + '</option>';
				} ).join( '' );
				operatorSelect.value = current;

				row.querySelector( '[data-cond="value-wrap"]' ).innerHTML = valueControlHtml( definition, value === undefined ? '' : value );

				var control = row.querySelector( '[data-cond="value"]' );

				if ( control ) {
					control.disabled = conditionValueless.indexOf( operatorSelect.value ) >= 0;
				}
			}

			function makeConditionRow( key, operator, value ) {
				var row = document.createElement( 'div' );
				row.className = 'nowcrm-condition-row';
				row.setAttribute( 'data-condition-row', '' );
				row.innerHTML = '<label><span>' + esc( text.field ) + '</span>' + fieldSelectHtml( key || '' ) + '</label>'
					+ '<label><span>' + esc( text.operator ) + '</span><select data-cond="operator"></select></label>'
					+ '<label><span>' + esc( text.value ) + '</span><span data-cond="value-wrap"></span></label>'
					+ '<button type="button" class="button-link" data-action="remove-condition">' + esc( text.remove ) + '</button>';
				syncConditionRow( row, operator || '', value );

				return row;
			}

			function makeConditionGroup( rows ) {
				var group = document.createElement( 'fieldset' );
				group.className = 'nowcrm-condition-group';
				group.setAttribute( 'data-condition-group', '' );
				group.innerHTML = '<legend></legend>';
				var list = Array.isArray( rows ) && rows.length ? rows.slice() : [ null ];

				list.forEach( function ( row ) {
					var key = row && row.provider && row.field ? row.provider + '.' + row.field : '';

					group.appendChild( makeConditionRow( key, row ? row.operator : '', row ? row.value : '' ) );
				} );

				var addRow = document.createElement( 'button' );
				addRow.type = 'button';
				addRow.className = 'button-link';
				addRow.setAttribute( 'data-action', 'add-condition' );
				addRow.textContent = text.addRow;
				group.appendChild( addRow );

				return group;
			}

			function makeOrSeparator() {
				var separator = document.createElement( 'div' );
				separator.className = 'nowcrm-condition-or';
				separator.setAttribute( 'data-condition-or', '' );
				separator.setAttribute( 'aria-hidden', 'true' );
				separator.textContent = text.or;

				return separator;
			}

			function reindexConditionGroups( builder ) {
				var index = 0;

				Array.prototype.forEach.call( builder.querySelectorAll( '[data-condition-group]' ), function ( group ) {
					var legend = group.querySelector( 'legend' );

					if ( legend ) {
						legend.textContent = text.group.replace( '%d', String( index + 1 ) );
					}

					index++;
				} );

				var addGroup = builder.querySelector( '[data-action="add-group"]' );

				if ( addGroup ) {
					addGroup.hidden = builder.querySelectorAll( '[data-condition-group]' ).length >= conditionMaxGroups;
				}
			}

			function renderConditionPlan( builder, plan ) {
				builder.innerHTML = '';

				var groups = plan && plan.groups && plan.groups.length ? plan.groups : [ [] ];

				groups.slice( 0, conditionMaxGroups ).forEach( function ( rows, index ) {
					if ( index > 0 ) {
						builder.appendChild( makeOrSeparator() );
					}

					builder.appendChild( makeConditionGroup( rows ) );
				} );

				var addGroup = document.createElement( 'button' );
				addGroup.type = 'button';
				addGroup.className = 'button';
				addGroup.setAttribute( 'data-action', 'add-group' );
				addGroup.textContent = text.addGroup;
				builder.appendChild( addGroup );
				reindexConditionGroups( builder );
			}

			function conditionPlan( card ) {
				var builder = ownNode( card, '[data-role="condition-builder"]' );
				var groups = [];
				var incomplete = false;

				if ( ! builder ) {
					return { plan: null, error: text.empty };
				}

				Array.prototype.forEach.call( builder.querySelectorAll( '[data-condition-group]' ), function ( group ) {
					var rows = [];

					Array.prototype.forEach.call( group.querySelectorAll( '[data-condition-row]' ), function ( row ) {
						var key = row.querySelector( '[data-cond="field"]' ).value;
						var operatorSelect = row.querySelector( '[data-cond="operator"]' );
						var operator = operatorSelect.value;
						var definition = fieldDefinition( key );
						var control = row.querySelector( '[data-cond="value"]' );
						var value = '';
						var bad = false;

						if ( ! key ) {
							row.classList.remove( 'nowcrm-condition-row--invalid' );
							return;
						}

						if ( ! definition || ! operator || allowedOperators( definition ).indexOf( operator ) < 0 ) {
							bad = true;
						} else if ( conditionValueless.indexOf( operator ) >= 0 ) {
							value = '';
						} else if ( control && control.multiple ) {
							value = Array.prototype.slice.call( control.options ).filter( function ( option ) {
								return option.selected;
							} ).map( function ( option ) {
								return option.value;
							} ).filter( Boolean );

							bad = ! value.length;
						} else {
							value = control ? control.value.trim() : '';
							bad = '' === value;
						}

						row.classList.toggle( 'nowcrm-condition-row--invalid', bad );

						if ( bad ) {
							incomplete = true;
							return;
						}

						rows.push( {
							provider: definition.provider,
							field: definition.field,
							operator: operator,
							value: value
						} );
					} );

					if ( rows.length ) {
						groups.push( rows );
					}
				} );

				if ( incomplete ) {
					return { plan: null, error: text.incomplete };
				}

				if ( ! groups.length ) {
					return { plan: null, error: text.empty };
				}

				return { plan: { version: 1, groups: groups }, error: '' };
			}

			function showConditionError( card, message ) {
				var errorField = ownNode( card, '[data-role="condition-error"]' );

				if ( errorField ) {
					errorField.textContent = message || '';
					errorField.hidden = ! message;
				}

				card.classList.toggle( 'nowcrm-step-card--invalid', !! message );
			}

			function setConditionMode( card, mode ) {
				var isJson = mode === 'json';
				var builder = ownNode( card, '[data-role="condition-builder"]' );
				var applyButton = ownNode( card, '[data-action="apply-condition-json"]' );
				var label = ownNode( card, '[data-role="condition-mode"]' );

				card.dataset.conditionMode = isJson ? 'json' : 'visual';

				if ( builder ) {
					builder.classList.toggle( 'is-disabled', isJson );
					builder.querySelectorAll( 'input, select, textarea, button' ).forEach( function ( control ) {
						control.disabled = isJson;
					} );
				}

				if ( applyButton ) {
					applyButton.hidden = ! isJson;
				}

				if ( label ) {
					label.textContent = isJson ? jsonModeText : visualModeText;
					label.classList.toggle( 'is-json', isJson );
				}
			}

			function planFromJson( raw ) {
				raw = String( raw || '' ).trim();

				if ( '' === raw ) {
					return { plan: null, error: text.empty };
				}

				var parsed = null;

				try {
					parsed = JSON.parse( raw );
				} catch ( parseError ) {
					return { plan: null, error: text.badJson };
				}

				if ( ! parsed || typeof parsed !== 'object' || ! Array.isArray( parsed.groups ) || ! parsed.groups.length ) {
					return { plan: null, error: text.empty };
				}

				if ( parsed.groups.length > conditionMaxGroups ) {
					return { plan: null, error: text.unsupported };
				}

				for ( var groupIndex = 0; groupIndex < parsed.groups.length; groupIndex++ ) {
					var group = parsed.groups[ groupIndex ];

					if ( ! Array.isArray( group ) || ! group.length || group.length > conditionMaxRows ) {
						return { plan: null, error: text.unsupported };
					}

					for ( var rowIndex = 0; rowIndex < group.length; rowIndex++ ) {
						var row = group[ rowIndex ];
						var key = row && row.provider && row.field ? row.provider + '.' + row.field : '';
						var definition = fieldDefinition( key );

						if ( ! definition || allowedOperators( definition ).indexOf( row.operator ) < 0 ) {
							return { plan: null, error: text.unsupported };
						}

						if ( conditionValueless.indexOf( row.operator ) >= 0 ) {
							continue;
						}

						var value = row.value;
						var values = Array.isArray( value ) ? value : ( value === '' || value === null || value === undefined ? [] : [ String( value ) ] );

						if ( ! values.length ) {
							return { plan: null, error: text.unsupported };
						}

						if ( isOptionType( definition ) ) {
							var options = valueOptions( definition );

							for ( var valueIndex = 0; valueIndex < values.length; valueIndex++ ) {
								if ( ! Object.prototype.hasOwnProperty.call( options, String( values[ valueIndex ] ) ) ) {
									return { plan: null, error: text.unsupported };
								}
							}
						}
					}
				}

				return { plan: parsed, error: '' };
			}

			function syncConditionTransport( card ) {
				if ( card.dataset.conditionMode === 'json' ) {
					return;
				}

				var hiddenField = fieldEl( card, 'conditions_plan' );
				var jsonField = fieldEl( card, 'conditions_json' );
				var result = conditionPlan( card );

				if ( hiddenField ) {
					hiddenField.value = result.error ? '' : JSON.stringify( result.plan );
				}

				if ( jsonField && ! result.error ) {
					jsonField.value = JSON.stringify( result.plan, null, 2 );
				}

				showConditionError( card, result.error );
			}

			function ensureConditionBuilder( card ) {
				var builder = ownNode( card, '[data-role="condition-builder"]' );

				if ( ! builder || builder.dataset.ready === '1' ) {
					return;
				}

				var hiddenField = fieldEl( card, 'conditions_plan' );
				var jsonField = fieldEl( card, 'conditions_json' );
				var stored = hiddenField ? hiddenField.value : '';
				var parsed = planFromJson( stored );

				if ( stored && parsed.error ) {
					renderConditionPlan( builder, null );
					setConditionMode( card, 'json' );
					showConditionError( card, text.unsupported );
				} else {
					renderConditionPlan( builder, parsed.plan );
					setConditionMode( card, 'visual' );
				}

				builder.dataset.ready = '1';

				builder.addEventListener( 'click', function ( event ) {
					var button = event.target.closest( '[data-action]' );

					if ( ! button || ! builder.contains( button ) || card.dataset.conditionMode === 'json' ) {
						return;
					}

					var changed = false;

					if ( button.dataset.action === 'add-condition' ) {
						var group = button.closest( '[data-condition-group]' );
						var rows = group ? group.querySelectorAll( '[data-condition-row]' ).length : 0;

						if ( group && rows < conditionMaxRows ) {
							group.insertBefore( makeConditionRow( '', '', '' ), button );
							changed = true;
						}
					} else if ( button.dataset.action === 'add-group' ) {
						if ( builder.querySelectorAll( '[data-condition-group]' ).length < conditionMaxGroups ) {
							builder.insertBefore( makeOrSeparator(), button );
							builder.insertBefore( makeConditionGroup( [] ), button );
							reindexConditionGroups( builder );
							changed = true;
						}
					} else if ( button.dataset.action === 'remove-condition' ) {
						var row = button.closest( '[data-condition-row]' );

						if ( row ) {
							row.parentElement.removeChild( row );
							changed = true;
						}
					}

					if ( ! changed ) {
						return;
					}

					syncConditionTransport( card );
					markDirty();
					refreshSummary( card );
				} );

				builder.addEventListener( 'change', function ( event ) {
					if ( card.dataset.conditionMode === 'json' ) {
						return;
					}

					var row = event.target.closest( '[data-condition-row]' );

					if ( ! row ) {
						return;
					}

					if ( event.target.getAttribute( 'data-cond' ) === 'field' ) {
						var key = event.target.value;
						var definition = fieldDefinition( key );
						var allowed = allowedOperators( definition );

						syncConditionRow( row, allowed[0] || '', '' );
					} else if ( event.target.getAttribute( 'data-cond' ) === 'operator' ) {
						var control = row.querySelector( '[data-cond="value"]' );

						if ( control ) {
							control.disabled = conditionValueless.indexOf( event.target.value ) >= 0;
						}
					}

					syncConditionTransport( card );
					markDirty();
					refreshSummary( card );
				} );

				if ( jsonField ) {
					jsonField.addEventListener( 'input', function () {
						setConditionMode( card, 'json' );

						var raw = jsonField.value.trim();
						var error = '';

						if ( '' === raw ) {
							error = text.empty;
						} else {
							try {
								var parsedJson = JSON.parse( raw );

								if ( ! parsedJson || typeof parsedJson !== 'object' ) {
									error = text.badJson;
								}
							} catch ( jsonError ) {
								error = text.badJson;
							}
						}

						showConditionError( card, error );
						refreshSummary( card );
					} );
				}

				var applyButton = ownNode( card, '[data-action="apply-condition-json"]' );

				if ( applyButton ) {
					applyButton.addEventListener( 'click', function () {
						var result = planFromJson( jsonField ? jsonField.value : '' );

						if ( result.error ) {
							showConditionError( card, result.error );
							return;
						}

						renderConditionPlan( builder, result.plan );
						setConditionMode( card, 'visual' );
						syncConditionTransport( card );
						markDirty();
						refreshSummary( card );
					} );
				}
			}

			function toggle( card ) {
				var body = bodyOf( card );
				var kindField = fieldEl( card, 'kind' );
				var kind = kindField ? kindField.value : 'action';
				var actionField = fieldEl( card, 'action_key' );
				var actionKey = actionField ? actionField.value : '';

				card.dataset.kind = kind;

				if ( 'email' === kind && openCard === card ) {
					var modeField = fieldEl( card, 'mode' );

					if ( modeField && 'blocks' === modeField.value ) {
						mountEmailFrame( card );
					}
				}

				if ( ! body ) {
					return;
				}

				body.querySelectorAll( ':scope > [data-group]' ).forEach( function ( group ) {
					group.hidden = group.dataset.group !== kind;
				} );
				body.querySelectorAll( ':scope > [data-group] > [data-action-config]' ).forEach( function ( group ) {
					group.hidden = group.dataset.actionConfig !== actionGroup[ actionKey ];
				} );

				var goalEvent = fieldEl( card, 'goal_event' );

				if ( goalEvent ) {
					var goalKind = goalEvent.value === 'status_changed' ? 'status' : 'term';
					body.querySelectorAll( ':scope > [data-group="goal"] > [data-goal-config]' ).forEach( function ( group ) {
						group.hidden = group.dataset.goalConfig !== goalKind;
					} );
				}

				var goalMode = fieldEl( card, 'goal_mode' );
				var goalTimeout = fieldEl( card, 'goal_timeout' );
				var goalTimeoutValue = fieldEl( card, 'goal_timeout_value' );
				var goalTimeoutUnit = fieldEl( card, 'goal_timeout_unit' );
				var optional = goalMode && goalMode.value === 'optional';

				if ( goalTimeout ) {
					goalTimeout.disabled = optional;
				}

				if ( goalTimeoutValue ) {
					goalTimeoutValue.disabled = optional;
				}

				if ( goalTimeoutUnit ) {
					goalTimeoutUnit.disabled = optional;
				}

				if ( kind === 'condition' ) {
					ensureConditionBuilder( card );
				}
			}

			function branchList( card, branch ) {
				var block = ownNode( card, '[data-branch-block="' + branch + '"]' );

				if ( ! block ) {
					return null;
				}

				return block.querySelector( ':scope > .nowcrm-step-list' );
			}

			function bind( card ) {
				var body = bodyOf( card );
				var kindField = fieldEl( card, 'kind' );
				var actionField = fieldEl( card, 'action_key' );

				if ( kindField ) {
					kindField.addEventListener( 'change', function () {
						toggle( card );
						refreshSummary( card );
						refreshAllLists();
					} );
				}

				if ( actionField ) {
					actionField.addEventListener( 'change', function () {
						toggle( card );
						refreshSummary( card );
					} );
				}

				var goalEvent = fieldEl( card, 'goal_event' );

				if ( goalEvent ) {
					goalEvent.addEventListener( 'change', function () {
						toggle( card );
						refreshSummary( card );
					} );
				}

				var goalMode = fieldEl( card, 'goal_mode' );

				if ( goalMode ) {
					goalMode.addEventListener( 'change', function () {
						toggle( card );
						refreshSummary( card );
					} );
				}

				bindDuration( card, 'delay_value', 'delay_unit', 'delay_seconds' );
				bindDuration( card, 'goal_timeout_value', 'goal_timeout_unit', 'goal_timeout' );

				if ( body ) {
					body.addEventListener( 'input', function () {
						markDirty();
						refreshSummary( card );
					} );

					body.addEventListener( 'change', function ( event ) {
						markDirty();
						refreshSummary( card );

						var modeField = event.target.closest ? event.target.closest( '[data-workflow-field="mode"]' ) : null;

						if ( modeField && openCard === card ) {
							if ( 'blocks' === modeField.value ) {
								mountEmailFrame( card );
							} else {
								syncEmailFrame( card );
							}
						}
					} );
				}

				var summaryButton = card.querySelector( ':scope > .nowcrm-step-card__head [data-action="edit"]' );

				if ( summaryButton ) {
					summaryButton.addEventListener( 'click', function () {
						if ( openCard === card ) {
							closeDrawer( true, true );
						} else {
							openDrawer( card );
						}
					} );
				}

				card.querySelectorAll( ':scope > .nowcrm-step-card__head [data-action]' ).forEach( function ( button ) {
					var mode = button.dataset.action;

					if ( mode === 'edit' ) {
						return;
					}

					button.addEventListener( 'click', function () {
						if ( mode === 'insert-after' ) {
							openPicker( { list: card.parentElement, after: card } );
							return;
						}

						if ( mode === 'remove' ) {
							ensureDrawerClearOfRemoved( card );
							card.remove();
							refreshAllLists();
							markDirty();
							return;
						}

						var sibling = mode === 'up' ? card.previousElementSibling : card.nextElementSibling;

						if ( sibling && sibling.classList && sibling.classList.contains( 'nowcrm-step-card' ) ) {
							card.parentElement.insertBefore( card, mode === 'up' ? sibling : sibling.nextSibling );
							refreshAllLists();
							markDirty();
						}
					} );
				} );

				ownNodes( card, '[data-action="add-branch"]' ).forEach( function ( button ) {
					button.addEventListener( 'click', function () {
						var block = button.closest( '[data-branch-block]' );
						var list = block ? block.querySelector( ':scope > .nowcrm-step-list' ) : null;

						openPicker( { list: list || card.parentElement, after: null } );
					} );
				} );

				toggle( card );
				refreshSummary( card );
			}

			function setStepError( card, message ) {
				var field = ownNode( card, '[data-role="step-error"]' );

				if ( field ) {
					field.textContent = message || '';
					field.hidden = ! message;
				}

				card.classList.toggle( 'nowcrm-step-card--invalid', !! message );
			}

			function addCollectError( card, message, focus ) {
				invalid = true;
				collectErrors.push( { card: card, message: message, focus: focus || null } );
				setStepError( card, message );
			}

			function readIntegerField( card, name, min, max, allowEmpty ) {
				var field = fieldEl( card, name );
				var raw = field ? String( field.value ).trim() : '';

				if ( '' === raw ) {
					return allowEmpty ? { ok: true, value: 0, field: field } : { ok: false, message: integerText, field: field };
				}

				if ( ! /^\d+$/.test( raw ) ) {
					return { ok: false, message: integerText, field: field };
				}

				var value = Number( raw );

				if ( value < min || value > max ) {
					return { ok: false, message: integerRangeText, field: field };
				}

				return { ok: true, value: value, field: field };
			}

			function collect( list ) {
				return Array.prototype.slice.call( list.children ).filter( function ( card ) {
					return card.classList && card.classList.contains( 'nowcrm-step-card' );
				} ).map( function ( card ) {
					var kindField = fieldEl( card, 'kind' );
					var kind = kindField ? kindField.value : 'action';
					var step = { kind: kind };
					setStepError( card, '' );

					if ( kind === 'action' ) {
						var actionField = fieldEl( card, 'action_key' );
						var actionKey = actionField ? actionField.value : '';
						var config = {};

						if ( actionGroup[ actionKey ] === 'term' ) {
							config.term_ids = selectedOptions( card, 'term_ids' ).map( function ( option ) {
								return parseInt( option.value, 10 ) || 0;
							} ).filter( Boolean );
						} else if ( actionGroup[ actionKey ] === 'status' ) {
							var statusField = fieldEl( card, 'status' );
							config.status = statusField ? statusField.value : '';
						} else if ( actionGroup[ actionKey ] === 'sequence' ) {
							var sequenceField = fieldEl( card, 'sequence_id' );
							config.sequence_id = sequenceField ? ( parseInt( sequenceField.value, 10 ) || 0 ) : 0;
						} else {
							var companyField = fieldEl( card, 'company_id' );
							config.company_id = companyField ? ( parseInt( companyField.value, 10 ) || 0 ) : 0;
						}

						step.action_key = actionKey;
						step.config = config;
					} else if ( kind === 'wait' ) {
						var delayValue = readIntegerField( card, 'delay_value', 0, 31536000, true );
						var delayUnit = fieldEl( card, 'delay_unit' );
						var delaySeconds = delayValue.ok ? delayValue.value * ( unitSeconds[ delayUnit ? delayUnit.value : 'seconds' ] || 1 ) : 0;

						if ( ! delayValue.ok ) {
							addCollectError( card, delayValue.message, delayValue.field );
						} else if ( delaySeconds > 31536000 ) {
							addCollectError( card, integerRangeText, delayValue.field );
						}

						step.delay_seconds = delaySeconds;
					} else if ( kind === 'email' ) {
						syncEmailFrame( card );

						step.config = {
							subject: ( fieldEl( card, 'subject' ) || {} ).value || '',
							mode: ( fieldEl( card, 'mode' ) || {} ).value || 'html',
							preheader: ( fieldEl( card, 'preheader' ) || {} ).value || '',
							content: ( fieldEl( card, 'content' ) || {} ).value || ''
						};
					} else if ( kind === 'goal' ) {
						var goalEventField = fieldEl( card, 'goal_event' );
						var goalEventKey = goalEventField ? goalEventField.value : '';
						var goalConfig = { event: goalEventKey };
						var goalMissing = false;

						if ( goalEventKey === 'status_changed' ) {
							goalConfig.statuses = selectedOptions( card, 'goal_statuses' ).map( function ( option ) {
								return option.value;
							} ).filter( Boolean );
							goalMissing = ! goalConfig.statuses.length;
						} else {
							goalConfig.term_ids = selectedOptions( card, 'goal_term_ids' ).map( function ( option ) {
								return parseInt( option.value, 10 ) || 0;
							} ).filter( Boolean );
							goalMissing = ! goalConfig.term_ids.length;
						}

						var goalModeField = fieldEl( card, 'goal_mode' );
						goalConfig.mode = goalModeField ? goalModeField.value : 'required';
						goalConfig.timeout_seconds = 0;

						if ( goalMissing ) {
							addCollectError( card, goalTargetText, goalEventKey === 'status_changed' ? fieldEl( card, 'goal_statuses' ) : fieldEl( card, 'goal_term_ids' ) );
						}

						if ( 'required' === goalConfig.mode ) {
							var goalTimeoutValue = readIntegerField( card, 'goal_timeout_value', 0, 31536000, true );
							var goalTimeoutUnit = fieldEl( card, 'goal_timeout_unit' );

							if ( ! goalTimeoutValue.ok ) {
								addCollectError( card, goalTimeoutValue.message, goalTimeoutValue.field );
							} else {
								goalConfig.timeout_seconds = goalTimeoutValue.value * ( unitSeconds[ goalTimeoutUnit ? goalTimeoutUnit.value : 'seconds' ] || 1 );

								if ( goalConfig.timeout_seconds > 31536000 ) {
									addCollectError( card, integerRangeText, goalTimeoutValue.field );
								}
							}
						}
						step.config = goalConfig;
						var timeoutList = branchList( card, 'timeout' );
						step.timeout = timeoutList ? collect( timeoutList ) : [];
					} else if ( kind === 'split' ) {
						var weightA = readIntegerField( card, 'weight_a', 0, 100, false );
						var weightB = readIntegerField( card, 'weight_b', 0, 100, false );

						if ( ! weightA.ok ) {
							addCollectError( card, weightA.message, weightA.field );
						}

						if ( ! weightB.ok ) {
							addCollectError( card, weightB.message, weightB.field );
						}

						if ( weightA.ok && weightB.ok && weightA.value + weightB.value !== 100 ) {
							addCollectError( card, splitTotalText, weightB.field );
						}

						step.config = { weight_a: weightA.ok ? weightA.value : 0, weight_b: weightB.ok ? weightB.value : 0 };

						var branchA = branchList( card, 'a' );
						var branchB = branchList( card, 'b' );
						step.a = branchA ? collect( branchA ) : [];
						step.b = branchB ? collect( branchB ) : [];
					} else if ( kind === 'condition' ) {
						ensureConditionBuilder( card );
						var conditionPlanValue = null;
						var conditionError = '';
						var conditionFocus = null;
						var jsonField = fieldEl( card, 'conditions_json' );

						if ( card.dataset.conditionMode === 'json' ) {
							var rawCondition = jsonField ? jsonField.value.trim() : '';

							if ( rawCondition === '' ) {
								conditionError = text.empty;
							} else {
								try {
									var parsedCondition = JSON.parse( rawCondition );

									if ( ! parsedCondition || typeof parsedCondition !== 'object' || ! Array.isArray( parsedCondition.groups ) ) {
										conditionError = text.badJson;
									} else {
										conditionPlanValue = parsedCondition;
									}
								} catch ( error ) {
									conditionError = text.badJson;
								}
							}

							conditionFocus = jsonField;
						} else {
							var conditionResult = conditionPlan( card );
							conditionPlanValue = conditionResult.plan;
							conditionError = conditionResult.error;
							conditionFocus = ownNode( card, '.nowcrm-condition-row--invalid [data-cond="field"]' );
						}

						if ( conditionError ) {
							showConditionError( card, conditionError );
							addCollectError( card, conditionError, conditionFocus );
						} else {
							card.classList.remove( 'nowcrm-step-card--invalid' );
							showConditionError( card, '' );
						}

						step.config = { conditions: conditionPlanValue };
						var yes = branchList( card, 'yes' );
						var no = branchList( card, 'no' );
						step.yes = yes ? collect( yes ) : [];
						step.no = no ? collect( no ) : [];
					} else {
						step.config = {};
					}

					return step;
				} );
			}

			root.querySelectorAll( '.nowcrm-step-card' ).forEach( bind );

			if ( addButton ) {
				addButton.addEventListener( 'click', function () {
					openPicker( { list: root, after: null } );
				} );
			}

			if ( picker ) {
				picker.addEventListener( 'click', function ( event ) {
					if ( event.target === picker ) {
						closePicker();
					}
				} );

				picker.querySelectorAll( '[data-picker-close]' ).forEach( function ( button ) {
					button.addEventListener( 'click', function () {
						closePicker();
					} );
				} );

				picker.querySelectorAll( '[data-picker-kind]' ).forEach( function ( button ) {
					button.addEventListener( 'click', function () {
						var kind = button.getAttribute( 'data-picker-kind' );
						var target = pickerTarget;
						closePicker();
						add( target || { list: root, after: null }, kind );
					} );
				} );

				if ( pickerSearch ) {
					pickerSearch.addEventListener( 'input', function () {
						filterPicker( pickerSearch.value );
					} );
				}
			}

			if ( drawer ) {
				drawer.addEventListener( 'cancel', function ( event ) {
					event.preventDefault();
					closeDrawer( true, true );
				} );

				drawer.addEventListener( 'click', function ( event ) {
					if ( event.target === drawer ) {
						closeDrawer( true, true );
					}
				} );

				drawer.querySelectorAll( '[data-drawer-close]' ).forEach( function ( button ) {
					button.addEventListener( 'click', function () {
						closeDrawer( true, true );
					} );
				} );

				if ( drawerBack ) {
					drawerBack.addEventListener( 'click', function () {
						drawerBackStep();
					} );
				}

				var doneButton = drawer.querySelector( '[data-drawer-done]' );

				if ( doneButton ) {
					doneButton.addEventListener( 'click', function () {
						closeDrawer( true, true );
					} );
				}

				if ( drawerDelete ) {
					drawerDelete.addEventListener( 'click', function () {
						if ( ! openCard || ! window.confirm( deleteConfirm ) ) {
							return;
						}

						var card = openCard;
						closeDrawer( false, false );
						card.remove();
						refreshAllLists();
						markDirty();
					} );
				}
			}

			document.addEventListener( 'keydown', function ( event ) {
				if ( event.key === 'Escape' && openCard && typeof drawer.showModal !== 'function' && ! ( picker && picker.open ) ) {
					closeDrawer( true, true );
					return;
				}

				if ( ! ( event.metaKey || event.ctrlKey ) || String( event.key ).toLowerCase() !== 's' ) {
					return;
				}

				event.preventDefault();

				if ( saving ) {
					return;
				}

				submitVia( saveButton );
			} );

			function submitVia( button ) {
				if ( typeof form.requestSubmit === 'function' ) {
					if ( button && button.form === form ) {
						form.requestSubmit( button );
					} else {
						form.requestSubmit();
					}
				} else if ( button ) {
					button.click();
				}
			}

			form.addEventListener( 'keydown', function ( event ) {
				if ( event.key !== 'Enter' || event.target.tagName !== 'INPUT' || [ 'submit', 'checkbox', 'radio', 'button' ].indexOf( event.target.type ) >= 0 ) {
					return;
				}

				event.preventDefault();

				if ( ! saving ) {
					submitVia( saveButton );
				}
			} );

			form.addEventListener( 'input', markDirty );
			form.addEventListener( 'change', markDirty );

			document.querySelectorAll( '.nowcrm-automation form' ).forEach( function ( node ) {
				if ( node === form ) {
					return;
				}

				if ( node.hasAttribute( 'data-confirm' ) ) {
					node.onsubmit = null;
				}

				node.addEventListener( 'submit', function ( event ) {
					var message = node.getAttribute( 'data-confirm' );

					if ( message && ! window.confirm( message ) ) {
						event.preventDefault();
						return;
					}

					if ( ! message && dirty && ! window.confirm( text.dirtyLost ) ) {
						event.preventDefault();
						return;
					}

					markClean();
				} );
			} );

			var triggerField = triggerSelect();
			var editTrigger = document.querySelector( '.nowcrm-automation [data-role="edit-trigger"]' );
			var nameField = document.querySelector( '[data-testid="automation-name"]' );
			var nameError = document.querySelector( '[data-role="name-error"]' );

			if ( triggerField ) {
				triggerField.addEventListener( 'change', refreshTriggerSummary );
			}

			[ 'run_once', 'force_run' ].forEach( function ( name ) {
				var control = form.querySelector( '[name="' + name + '"]' );

				if ( control ) {
					control.addEventListener( 'change', renderTriggerChips );
				}
			} );

			var sourcesControl = form.querySelector( '[name="trigger_sources"]' );

			if ( sourcesControl ) {
				sourcesControl.addEventListener( 'input', renderTriggerChips );
			}

			if ( editTrigger && triggerField ) {
				editTrigger.addEventListener( 'click', function ( event ) {
					event.preventDefault();

					if ( triggerField.scrollIntoView ) {
						triggerField.scrollIntoView( { block: 'center' } );
					}

					triggerField.focus( { preventScroll: true } );
				} );
			}

			refreshTriggerSummary();
			refreshAllLists();

			form.addEventListener( 'submit', function ( event ) {
				if ( saving ) {
					event.preventDefault();
					return;
				}

				invalid = false;
				collectErrors = [];
				var nameMissing = !! ( nameField && ! nameField.value.trim() );

				if ( nameError ) {
					nameError.textContent = nameMissing ? missingNameText : '';
					nameError.hidden = ! nameMissing;
				}

				if ( nameField ) {
					if ( nameMissing ) {
						nameField.setAttribute( 'aria-invalid', 'true' );
					} else {
						nameField.removeAttribute( 'aria-invalid' );
					}
				}

				var steps = collect( root );

				if ( nameMissing ) {
					invalid = true;
				}

				if ( invalid ) {
					event.preventDefault();
					showSaveError( invalidAlert );

					if ( nameMissing && nameField ) {
						nameField.focus( { preventScroll: true } );
					} else if ( collectErrors.length ) {
						var firstError = collectErrors[ 0 ];
						openDrawer( firstError.card );

						if ( firstError.focus && firstError.focus.offsetParent !== null ) {
							firstError.focus.focus( { preventScroll: true } );
						}
					}

					return;
				}

				if ( errorBox ) {
					errorBox.hidden = true;
				}

				hidden.value = JSON.stringify( steps );

				if ( ! window.fetch || ! window.FormData ) {
					return;
				}

				var jsonFlag = document.getElementById( 'nowcrm-json-save' );

				if ( jsonFlag ) {
					jsonFlag.value = '1';
				}

				var submitter = event.submitter || null;
				var activating = !! ( submitter && submitter.hasAttribute && submitter.hasAttribute( 'data-automation-activate' ) );
				event.preventDefault();
				submitLive( form, activating ? { save_status: 'active' } : {} );
			} );

			window.addEventListener( 'beforeunload', function ( event ) {
				if ( ! dirty ) {
					return;
				}

				event.preventDefault();
				event.returnValue = '';

				return '';
			} );
		}() );
		</script>
		<?php
	}
}
