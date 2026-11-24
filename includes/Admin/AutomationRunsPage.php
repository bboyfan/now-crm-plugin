<?php
/**
 * Per-automation run and step-event report.
 *
 * @package NowCRM
 */

namespace NowCRM\Admin;

use NowCRM\Automation\ActionRegistry;
use NowCRM\Automation\AutomationRepository;
use NowCRM\Automation\RunRepository;
use NowCRM\Automation\TriggerRegistry;
use NowCRM\Contacts\ContactRepository;
use NowCRM\Email\EmailJobRepository;
use NowCRM\Support\Capabilities;

defined( 'ABSPATH' ) || exit;

final class AutomationRunsPage {

	private const PER_PAGE = 25;

	/**
	 * Labels for run statuses.
	 *
	 * @return array<string, string>
	 */
	public static function status_labels(): array {
		return array(
			RunRepository::STATUS_ACTIVE    => __( '執行中', 'now-crm' ),
			RunRepository::STATUS_WAITING   => __( '等待中', 'now-crm' ),
			RunRepository::STATUS_COMPLETED => __( '已完成', 'now-crm' ),
			RunRepository::STATUS_CANCELLED => __( '已取消', 'now-crm' ),
			RunRepository::STATUS_FAILED    => __( '失敗', 'now-crm' ),
		);
	}

	/**
	 * Label of one run status.
	 */
	public static function status_label( string $status ): string {
		return self::status_labels()[ $status ] ?? $status;
	}

	/**
	 * Render the run list.
	 */
	public static function render(): void {
		if ( ! Capabilities::can_manage_contacts() ) {
			wp_die( esc_html__( '沒有權限。', 'now-crm' ), '', array( 'response' => 403 ) );
		}

		$id        = absint( Menu::query( 'id' ) );
		$automaton = ( new AutomationRepository() )->get( $id );
		$page      = max( 1, absint( Menu::query( 'paged', '1' ) ) );
		$run_id    = absint( Menu::query( 'run_id' ) );

		if ( ! $automaton ) {
			printf(
				'<div class="wrap nowcrm-wrap"><div class="notice notice-error"><p>%s</p></div></div>',
				esc_html__( '找不到自動化。', 'now-crm' )
			);

			return;
		}

		if ( $run_id > 0 ) {
			self::render_run_detail( $automaton, $id, $run_id );

			return;
		}

		$status_filter = sanitize_key( Menu::query( 'run_status', '' ) );
		$search        = sanitize_text_field( Menu::query( 'run_search', '' ) );
		$runs_repo     = new RunRepository();
		$result        = $runs_repo->paginate_filtered(
			$id,
			array(
				'status'   => $status_filter,
				'search'   => $search,
				'page'     => $page,
				'per_page' => self::PER_PAGE,
			)
		);
		$page          = (int) $result['page'];
		$events        = $runs_repo->events_for_runs( wp_list_pluck( $result['items'], 'id' ) );
		$all_steps     = ( new AutomationRepository() )->steps( $id );
		$steps         = array();

		foreach ( $all_steps as $step ) {
			$steps[ (int) $step['id'] ] = $step;
		}

		$split_stats  = $runs_repo->branch_stats( $id, $all_steps );
		$contacts     = new ContactRepository();
		$total_pages  = (int) $result['pages'];
		$total        = (int) $result['total'];
		$filtered     = '' !== $status_filter || '' !== $search;
		$first_row    = $total > 0 ? ( ( $page - 1 ) * self::PER_PAGE ) + 1 : 0;
		$last_row     = min( $total, $page * self::PER_PAGE );
		$edit_url     = Menu::page_url( Menu::AUTOMATION_SLUG, array( 'id' => $id ) );
		$list_url     = Menu::page_url( Menu::AUTOMATIONS_SLUG );
		$clear_url    = Menu::page_url( Menu::AUTOMATION_RUNS_SLUG, array( 'id' => $id ) );
		$page_args    = array( 'id' => $id );
		$count_text   = $filtered
			/* translators: %d: number of runs matching the filters. */
			? sprintf( __( '符合篩選 %d 筆執行', 'now-crm' ), $total )
			/* translators: %d: number of runs. */
			: sprintf( __( '共 %d 筆執行', 'now-crm' ), $total );

		if ( '' !== $status_filter ) {
			$page_args['run_status'] = $status_filter;
		}

		if ( '' !== $search ) {
			$page_args['run_search'] = $search;
		}
		?>
		<div class="wrap nowcrm-wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( '自動化執行紀錄', 'now-crm' ); ?></h1>
			<a class="page-title-action" href="<?php echo esc_url( $edit_url ); ?>"><?php esc_html_e( '返回編輯器', 'now-crm' ); ?></a>
			<a class="page-title-action" href="<?php echo esc_url( $list_url ); ?>"><?php esc_html_e( '自動化列表', 'now-crm' ); ?></a>
			<hr class="wp-header-end" />

			<?php Actions::render_notice(); ?>
			<?php AutomationWorkflowActions::render_notice(); ?>

			<div class="nowcrm-panel">
				<h2><?php echo esc_html( (string) $automaton['name'] ); ?></h2>
				<p class="description">
					<?php
					printf(
						/* translators: 1: trigger label, 2: automation status, 3: run count text. */
						esc_html__( '觸發：%1$s｜狀態：%2$s｜%3$s', 'now-crm' ),
						esc_html( AutomationListPage::trigger_label( (string) $automaton['trigger_key'] ) ),
						esc_html( AutomationListPage::status_label( (string) $automaton['status'] ) ),
						esc_html( $count_text )
					);
					?>
				</p>
			</div>

			<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>"
				class="nowcrm-contacts-filters" data-testid="automation-run-filters">
				<input type="hidden" name="page" value="<?php echo esc_attr( Menu::AUTOMATION_RUNS_SLUG ); ?>" />
				<input type="hidden" name="id" value="<?php echo esc_attr( (string) $id ); ?>" />
				<label class="nowcrm-contacts-search">
					<span class="screen-reader-text"><?php esc_html_e( '搜尋聯絡人', 'now-crm' ); ?></span>
					<input type="search" name="run_search" value="<?php echo esc_attr( $search ); ?>"
						placeholder="<?php esc_attr_e( 'Email 或姓名', 'now-crm' ); ?>" />
					<button type="submit" class="button"><?php esc_html_e( '篩選', 'now-crm' ); ?></button>
				</label>
				<label class="nowcrm-contacts-status">
					<span class="screen-reader-text"><?php esc_html_e( '狀態篩選', 'now-crm' ); ?></span>
					<select name="run_status">
						<option value=""><?php esc_html_e( '全部狀態', 'now-crm' ); ?></option>
						<?php foreach ( self::status_labels() as $value => $label ) : ?>
							<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $status_filter, $value ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<?php if ( $filtered ) : ?>
					<a class="nowcrm-filter-clear" href="<?php echo esc_url( $clear_url ); ?>"><?php esc_html_e( '清除篩選', 'now-crm' ); ?></a>
				<?php endif; ?>
			</form>

			<div class="nowcrm-contacts-surface">
				<table class="nowcrm-contacts-table" data-testid="automation-runs">
					<thead>
						<tr>
							<th scope="col" class="nowcrm-col-id"><?php esc_html_e( 'Run', 'now-crm' ); ?></th>
							<th scope="col"><?php esc_html_e( '聯絡人', 'now-crm' ); ?></th>
							<th scope="col"><?php esc_html_e( '狀態', 'now-crm' ); ?></th>
							<th scope="col"><?php esc_html_e( '下一步', 'now-crm' ); ?></th>
							<th scope="col"><?php esc_html_e( '下次執行', 'now-crm' ); ?></th>
							<th scope="col"><?php esc_html_e( '來源', 'now-crm' ); ?></th>
							<th scope="col"><?php esc_html_e( '開始／結束', 'now-crm' ); ?></th>
							<th scope="col" class="nowcrm-col-actions"><?php esc_html_e( '操作', 'now-crm' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php if ( array() === $result['items'] ) : ?>
							<tr>
								<td colspan="8">
									<div class="nowcrm-empty">
										<?php if ( $filtered ) : ?>
											<p><?php esc_html_e( '沒有符合目前篩選的執行紀錄。', 'now-crm' ); ?></p>
											<p class="nowcrm-empty__actions">
												<a class="button" href="<?php echo esc_url( $clear_url ); ?>"><?php esc_html_e( '清除篩選', 'now-crm' ); ?></a>
											</p>
										<?php elseif ( $total > 0 ) : ?>
											<p><?php esc_html_e( '這一頁沒有執行紀錄。', 'now-crm' ); ?></p>
											<p class="nowcrm-empty__actions">
												<a class="button" href="<?php echo esc_url( $clear_url ); ?>"><?php esc_html_e( '回到第一頁', 'now-crm' ); ?></a>
											</p>
										<?php else : ?>
											<p><?php esc_html_e( '這項自動化還沒有執行紀錄。', 'now-crm' ); ?></p>
											<p><?php esc_html_e( '啟用自動化並等待觸發事件後，執行會顯示在這裡。', 'now-crm' ); ?></p>
											<p class="nowcrm-empty__actions">
												<a class="button" href="<?php echo esc_url( $edit_url ); ?>"><?php esc_html_e( '返回編輯器', 'now-crm' ); ?></a>
												<a class="button" href="<?php echo esc_url( $list_url ); ?>"><?php esc_html_e( '自動化列表', 'now-crm' ); ?></a>
											</p>
										<?php endif; ?>
									</div>
								</td>
							</tr>
						<?php else : ?>
							<?php foreach ( $result['items'] as $run ) : ?>
								<?php
								$run_id       = (int) $run['id'];
								$contact      = $contacts->get( (int) $run['contact_id'] );
								$contact_name = $contact ? trim( (string) ( $contact['first_name'] ?? '' ) . ' ' . (string) ( $contact['last_name'] ?? '' ) ) : '';
								$contact_email = $contact ? (string) ( $contact['email'] ?? '' ) : '';
								$primary      = '' !== $contact_name ? $contact_name : $contact_email;
								$next         = null !== $run['next_step_id'] ? ( $steps[ (int) $run['next_step_id'] ] ?? null ) : null;
								$event_rows   = $events[ $run_id ] ?? array();
								$run_active   = RunRepository::STATUS_ACTIVE === $run['status'];
								?>
								<tr data-run-id="<?php echo esc_attr( (string) $run_id ); ?>">
									<td>#<?php echo esc_html( (string) $run_id ); ?></td>
									<td>
										<?php if ( $contact ) : ?>
											<a href="<?php echo esc_url( Menu::page_url( Menu::CONTACT_SLUG, array( 'id' => (int) $contact['id'] ) ) ); ?>">
												<?php echo esc_html( $primary ); ?>
											</a>
											<?php if ( '' !== $contact_name && '' !== $contact_email ) : ?>
												<div class="description"><?php echo esc_html( $contact_email ); ?></div>
											<?php endif; ?>
										<?php else : ?>
											<span class="description">#<?php echo esc_html( (string) $run['contact_id'] ); ?></span>
										<?php endif; ?>
									</td>
									<td>
										<span class="nowcrm-status-pill<?php echo $run_active ? ' nowcrm-status-pill--active' : ''; ?>">
											<?php if ( $run_active ) : ?>
												<span class="nowcrm-status-pill__dot" aria-hidden="true"></span>
											<?php endif; ?>
											<?php echo esc_html( self::status_label( (string) $run['status'] ) ); ?>
										</span>
										<?php if ( '' !== (string) $run['last_error'] ) : ?>
											<div class="description"><?php echo esc_html( (string) $run['last_error'] ); ?></div>
										<?php endif; ?>
									</td>
									<td>
										<?php if ( $next ) : ?>
											<?php echo esc_html( self::step_label( $next ) ); ?>
										<?php else : ?>
											—
										<?php endif; ?>
									</td>
									<td><?php echo esc_html( Menu::format_datetime( $run['next_run_at'] ) ); ?></td>
									<td>
										<?php echo esc_html( AutomationListPage::trigger_label( (string) $run['source_trigger'] ) ); ?>
										<?php $source_cart_id = self::run_cart_id( $run ); ?>
										<?php if ( $source_cart_id > 0 ) : ?>
											<div>
												<a href="<?php echo esc_url( Menu::page_url( Menu::CART_SLUG, array( 'cart' => $source_cart_id ) ) ); ?>">
													<?php
													echo esc_html(
														sprintf(
															/* translators: %d: cart ID. */
															__( '購物車 #%d', 'now-crm' ),
															$source_cart_id
														)
													);
													?>
												</a>
											</div>
										<?php elseif ( '' !== (string) $run['source_ref'] ) : ?>
											<div class="description"><?php echo esc_html( (string) $run['source_ref'] ); ?></div>
										<?php endif; ?>
									</td>
									<td>
										<?php echo esc_html( Menu::format_datetime( (string) $run['started_at'] ) ); ?>
										<div class="description"><?php echo esc_html( Menu::format_datetime( $run['finished_at'] ) ); ?></div>
									</td>
									<td>
										<a class="button button-small" href="<?php echo esc_url( Menu::page_url( Menu::AUTOMATION_RUNS_SLUG, array( 'id' => $id, 'run_id' => $run_id ) ) ); ?>">
											<?php esc_html_e( '查看', 'now-crm' ); ?>
										</a>
										<?php self::render_run_action( $run, $event_rows, $id, $page, $status_filter, $search ); ?>
									</td>
								</tr>
								<?php if ( array() !== $event_rows ) : ?>
									<tr>
										<td colspan="8">
											<details>
												<summary><?php echo esc_html( sprintf( /* translators: %d: number of events. */ __( '步驟事件（%d）', 'now-crm' ), count( $event_rows ) ) ); ?></summary>
												<table class="widefat striped">
													<thead>
														<tr>
															<th><?php esc_html_e( '步驟', 'now-crm' ); ?></th>
															<th><?php esc_html_e( '結果', 'now-crm' ); ?></th>
															<th><?php esc_html_e( '時間', 'now-crm' ); ?></th>
															<th><?php esc_html_e( '內容', 'now-crm' ); ?></th>
														</tr>
													</thead>
													<tbody>
														<?php foreach ( $event_rows as $event ) : ?>
															<?php $event_step = $steps[ (int) $event['step_id'] ] ?? null; ?>
															<tr>
																<td><?php echo esc_html( $event_step ? self::step_label( $event_step ) : '#' . (string) $event['step_id'] ); ?></td>
																<td><?php echo esc_html( self::event_status_label( (string) $event['status'] ) ); ?></td>
																<td><?php echo esc_html( Menu::format_datetime( (string) $event['occurred_at'] ) ); ?></td>
																<td><code><?php echo esc_html( self::payload_summary( (array) $event['payload'] ) ); ?></code></td>
															</tr>
														<?php endforeach; ?>
													</tbody>
												</table>
											</details>
										</td>
									</tr>
								<?php endif; ?>
							<?php endforeach; ?>
						<?php endif; ?>
					</tbody>
				</table>

				<div class="nowcrm-contacts-footer">
					<p class="nowcrm-contacts-footer__count">
						<?php
						if ( $total > 0 && $first_row <= $total ) {
							printf(
								/* translators: 1: first row number, 2: last row number, 3: total runs. */
								esc_html__( '顯示 %1$d–%2$d，共 %3$d 筆執行', 'now-crm' ),
								(int) $first_row,
								(int) $last_row,
								(int) $total
							);
						} else {
							printf(
								/* translators: %d: total runs. */
								esc_html__( '共 %d 筆執行', 'now-crm' ),
								(int) $total
							);
						}
						?>
					</p>

					<?php if ( $total_pages > 1 ) : ?>
						<nav class="nowcrm-pagination" aria-label="<?php esc_attr_e( '執行紀錄分頁', 'now-crm' ); ?>">
							<?php if ( $page > 1 ) : ?>
								<a class="nowcrm-page-link" href="<?php echo esc_url( Menu::page_url( Menu::AUTOMATION_RUNS_SLUG, array_merge( $page_args, array( 'paged' => $page - 1 ) ) ) ); ?>">‹</a>
							<?php endif; ?>
							<?php for ( $number = max( 1, $page - 2 ); $number <= min( $total_pages, $page + 2 ); $number++ ) : ?>
								<a class="nowcrm-page-link<?php echo $number === $page ? ' is-active' : ''; ?>"
									href="<?php echo esc_url( Menu::page_url( Menu::AUTOMATION_RUNS_SLUG, array_merge( $page_args, array( 'paged' => $number ) ) ) ); ?>"
									<?php echo $number === $page ? 'aria-current="page"' : ''; ?>>
									<?php echo esc_html( (string) $number ); ?>
								</a>
							<?php endfor; ?>
							<?php if ( $page < $total_pages ) : ?>
								<a class="nowcrm-page-link" href="<?php echo esc_url( Menu::page_url( Menu::AUTOMATION_RUNS_SLUG, array_merge( $page_args, array( 'paged' => $page + 1 ) ) ) ); ?>">›</a>
							<?php endif; ?>
						</nav>
					<?php endif; ?>
				</div>
			</div>

			<?php if ( array() !== $split_stats ) : ?>
				<div class="nowcrm-panel" data-testid="automation-split-stats">
					<h2><?php esc_html_e( '分流結果', 'now-crm' ); ?></h2>
					<p class="description"><?php esc_html_e( '進入＝分流事件選到該分支的執行數。完成＝同一執行之後在該分支以外的步驟留下事件。分支內結束的執行只計入進入，不計入完成。', 'now-crm' ); ?></p>
					<table class="widefat striped nowcrm-table">
						<thead>
							<tr>
								<th scope="col"><?php esc_html_e( '步驟', 'now-crm' ); ?></th>
								<th scope="col"><?php esc_html_e( 'A 分支', 'now-crm' ); ?></th>
								<th scope="col"><?php esc_html_e( 'B 分支', 'now-crm' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $split_stats as $split_step_id => $branches ) : ?>
								<?php $split_step = $steps[ (int) $split_step_id ] ?? null; ?>
								<tr data-split-step="<?php echo esc_attr( (string) $split_step_id ); ?>">
									<td><?php echo esc_html( $split_step ? self::step_label( $split_step ) : '#' . (string) $split_step_id ); ?></td>
									<?php foreach ( array( AutomationRepository::BRANCH_A, AutomationRepository::BRANCH_B ) as $branch ) : ?>
										<td>
											<?php
											printf(
												/* translators: 1: entered count, 2: completed count. */
												esc_html__( '進入 %1$d／完成 %2$d', 'now-crm' ),
												(int) $branches[ $branch ]['entered'],
												(int) $branches[ $branch ]['completed']
											);
											?>
										</td>
									<?php endforeach; ?>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * One run, rendered only when it belongs to the automation in the URL.
	 *
	 * A mismatched or missing run is an explicit dead end: the page never falls
	 * back to another run's contact, cart, or payload.
	 *
	 * @param array<string, mixed> $automaton      Automation row.
	 * @param int                  $automation_id Automation ID from the URL.
	 * @param int                  $run_id        Run ID from the URL.
	 */
	private static function render_run_detail( array $automaton, int $automation_id, int $run_id ): void {
		$repo     = new RunRepository();
		$run      = $repo->get( $run_id );
		$back_url = Menu::page_url( Menu::AUTOMATION_RUNS_SLUG, array( 'id' => $automation_id ) );
		$edit_url = Menu::page_url( Menu::AUTOMATION_SLUG, array( 'id' => $automation_id ) );
		$list_url = Menu::page_url( Menu::AUTOMATIONS_SLUG );

		if ( ! $run || (int) $run['automation_id'] !== $automation_id ) {
			?>
			<div class="wrap nowcrm-wrap">
				<h1>
					<?php
					echo esc_html(
						sprintf(
							/* translators: %d: run ID. */
							__( '執行 #%d', 'now-crm' ),
							$run_id
						)
					);
					?>
				</h1>
				<div class="notice notice-error" role="alert">
					<p><?php esc_html_e( '找不到屬於這項自動化的執行，因此沒有顯示任何其他紀錄。', 'now-crm' ); ?></p>
				</div>
				<p>
					<a class="button" href="<?php echo esc_url( $back_url ); ?>"><?php esc_html_e( '返回執行紀錄', 'now-crm' ); ?></a>
					<a class="button" href="<?php echo esc_url( $edit_url ); ?>"><?php esc_html_e( '返回編輯器', 'now-crm' ); ?></a>
				</p>
			</div>
			<?php
			return;
		}

		$events = $repo->events_for_runs( array( $run_id ) );
		$events = (array) ( $events[ $run_id ] ?? array() );
		$steps  = array();

		foreach ( ( new AutomationRepository() )->steps( $automation_id ) as $step ) {
			$steps[ (int) $step['id'] ] = $step;
		}

		$contact  = ( new ContactRepository() )->get( (int) $run['contact_id'] );
		$cart_id  = self::run_cart_id( $run );
		$cart_url = $cart_id > 0
			? Menu::page_url( Menu::CART_SLUG, array( 'cart' => $cart_id ) )
			: '';
		$jobs_url = $cart_id > 0
			? Menu::page_url( Menu::EMAIL_SLUG, array( 'tab' => EmailLogPage::TAB_LOG, 'cart_id' => $cart_id ) )
			: '';
		$jobs     = self::trusted_jobs( $events, $run );
		?>
		<div class="wrap nowcrm-wrap">
			<nav aria-label="<?php esc_attr_e( '執行位置', 'now-crm' ); ?>">
				<a href="<?php echo esc_url( $back_url ); ?>"><?php esc_html_e( '執行紀錄', 'now-crm' ); ?></a>
				<span aria-hidden="true"> / </span>
				<a href="<?php echo esc_url( $edit_url ); ?>"><?php echo esc_html( (string) $automaton['name'] ); ?></a>
				<span aria-hidden="true"> / </span>
				<span aria-current="page">#<?php echo esc_html( (string) $run_id ); ?></span>
			</nav>

			<h1 class="wp-heading-inline">
				<?php
				echo esc_html(
					sprintf(
						/* translators: %d: run ID. */
						__( '執行 #%d', 'now-crm' ),
						$run_id
					)
				);
				?>
			</h1>
			<a class="page-title-action" href="<?php echo esc_url( $back_url ); ?>"><?php esc_html_e( '返回執行紀錄', 'now-crm' ); ?></a>
			<a class="page-title-action" href="<?php echo esc_url( $list_url ); ?>"><?php esc_html_e( '自動化列表', 'now-crm' ); ?></a>
			<hr class="wp-header-end" />

			<?php Actions::render_notice(); ?>

			<div class="nowcrm-panel" data-testid="automation-run-detail">
				<h2><?php echo esc_html( (string) $automaton['name'] ); ?></h2>
				<ul class="nowcrm-meta">
					<li><span><?php esc_html_e( '狀態', 'now-crm' ); ?></span><strong><?php echo esc_html( self::status_label( (string) $run['status'] ) ); ?></strong></li>
					<li>
						<span><?php esc_html_e( '聯絡人', 'now-crm' ); ?></span>
						<strong>
							<?php if ( $contact ) : ?>
								<a href="<?php echo esc_url( Menu::page_url( Menu::CONTACT_SLUG, array( 'id' => (int) $contact['id'] ) ) ); ?>">
									<?php echo esc_html( trim( (string) $contact['first_name'] . ' ' . (string) $contact['last_name'] ) ?: (string) $contact['email'] ); ?>
								</a>
							<?php else : ?>
								#<?php echo esc_html( (string) (int) $run['contact_id'] ); ?>
							<?php endif; ?>
						</strong>
					</li>
					<li>
						<span><?php esc_html_e( '來源', 'now-crm' ); ?></span>
						<strong>
							<?php echo esc_html( AutomationListPage::trigger_label( (string) $run['source_trigger'] ) ); ?>
							<?php if ( '' !== (string) $run['source_ref'] ) : ?>
								<code><?php echo esc_html( (string) $run['source_ref'] ); ?></code>
							<?php endif; ?>
						</strong>
					</li>
					<li>
						<span><?php esc_html_e( '購物車', 'now-crm' ); ?></span>
						<strong>
							<?php if ( '' !== $cart_url ) : ?>
								<a href="<?php echo esc_url( $cart_url ); ?>">#<?php echo esc_html( (string) $cart_id ); ?></a>
							<?php else : ?>
								—
							<?php endif; ?>
						</strong>
					</li>
					<li><span><?php esc_html_e( '開始', 'now-crm' ); ?></span><strong><?php echo esc_html( Menu::format_datetime( (string) $run['started_at'] ) ); ?></strong></li>
					<li><span><?php esc_html_e( '結束', 'now-crm' ); ?></span><strong><?php echo esc_html( Menu::format_datetime( $run['finished_at'] ) ); ?></strong></li>
				</ul>
				<?php if ( '' !== (string) $run['last_error'] ) : ?>
					<p class="description"><?php echo esc_html( (string) $run['last_error'] ); ?></p>
				<?php endif; ?>
			</div>

			<div class="nowcrm-panel">
				<h2><?php esc_html_e( '步驟事件', 'now-crm' ); ?></h2>
				<?php if ( array() === $events ) : ?>
					<p class="description"><?php esc_html_e( '這次執行還沒有步驟事件。', 'now-crm' ); ?></p>
				<?php else : ?>
					<table class="widefat striped nowcrm-table">
						<thead>
							<tr>
								<th scope="col"><?php esc_html_e( '步驟', 'now-crm' ); ?></th>
								<th scope="col"><?php esc_html_e( '結果', 'now-crm' ); ?></th>
								<th scope="col"><?php esc_html_e( '時間', 'now-crm' ); ?></th>
								<th scope="col"><?php esc_html_e( '摘要', 'now-crm' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $events as $event ) : ?>
								<?php $event_step = $steps[ (int) $event['step_id'] ] ?? null; ?>
								<tr>
									<td><?php echo esc_html( $event_step ? self::step_label( $event_step ) : '#' . (string) $event['step_id'] ); ?></td>
									<td><?php echo esc_html( self::event_status_label( (string) $event['status'] ) ); ?></td>
									<td><?php echo esc_html( Menu::format_datetime( (string) $event['occurred_at'] ) ); ?></td>
									<td><?php echo esc_html( self::safe_event_detail( (array) $event['payload'] ) ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
				<p class="description"><?php esc_html_e( '摘要只列出可核對的狀態與 ID；不顯示信件內容、復原權杖或結帳個資。', 'now-crm' ); ?></p>
			</div>

			<div class="nowcrm-panel">
				<h2><?php esc_html_e( '寄送 Job', 'now-crm' ); ?></h2>
				<?php if ( ! $jobs ) : ?>
					<p class="description">
						<?php esc_html_e( '沒有可確認屬於這次執行的寄送 Job。', 'now-crm' ); ?>
						<?php if ( '' !== $jobs_url ) : ?>
							<a href="<?php echo esc_url( $jobs_url ); ?>"><?php esc_html_e( '查看這台購物車的寄送紀錄', 'now-crm' ); ?></a>
						<?php endif; ?>
					</p>
				<?php else : ?>
					<table class="widefat striped nowcrm-table">
						<thead>
							<tr>
								<th scope="col"><?php esc_html_e( 'Job', 'now-crm' ); ?></th>
								<th scope="col"><?php esc_html_e( '類型', 'now-crm' ); ?></th>
								<th scope="col"><?php esc_html_e( '狀態', 'now-crm' ); ?></th>
								<th scope="col"><?php esc_html_e( '建立時間', 'now-crm' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $jobs as $job ) : ?>
								<tr>
									<td>
										<a href="<?php echo esc_url( Menu::page_url( Menu::EMAIL_SLUG, array( 'tab' => EmailLogPage::TAB_LOG, 'job' => (int) $job['id'] ) ) ); ?>">
											#<?php echo esc_html( (string) (int) $job['id'] ); ?>
										</a>
									</td>
									<td><?php echo esc_html( EmailLogPage::kind_label( (string) $job['kind'] ) ); ?></td>
									<td><?php echo esc_html( self::job_status_label( (string) $job['status'] ) ); ?></td>
									<td><?php echo esc_html( Menu::format_datetime( (string) $job['created_at'] ) ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
					<?php if ( '' !== $jobs_url ) : ?>
						<p><a href="<?php echo esc_url( $jobs_url ); ?>"><?php esc_html_e( '查看這台購物車的全部寄送紀錄', 'now-crm' ); ?></a></p>
					<?php endif; ?>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Cart bound to a run: the dedicated column first, then the exact legacy
	 * `cart:<id>` reference for rows written before that column existed.
	 *
	 * @param array<string, mixed> $run Run row.
	 */
	private static function run_cart_id( array $run ): int {
		$bound = (int) ( $run['cart_id'] ?? 0 );

		if ( $bound > 0 ) {
			return $bound;
		}

		if ( TriggerRegistry::CART_ABANDONED !== (string) ( $run['source_trigger'] ?? '' ) ) {
			return 0;
		}

		if ( preg_match( '/^cart:(\d+)$/', (string) ( $run['source_ref'] ?? '' ), $matches ) ) {
			return absint( $matches[1] );
		}

		return 0;
	}

	/**
	 * Jobs referenced by this run's events and verified against the run.
	 *
	 * @param array<int, array<string, mixed>> $events Event rows.
	 * @param array<string, mixed>             $run    Run row.
	 * @return array<int, array<string, mixed>>
	 */
	private static function trusted_jobs( array $events, array $run ): array {
		$jobs = array();
		$repo = new EmailJobRepository();

		foreach ( $events as $event ) {
			$payload = (array) ( $event['payload'] ?? array() );
			$job_id  = (int) ( $payload['job_id'] ?? 0 );

			if ( $job_id <= 0 ) {
				continue;
			}

			$job = $repo->get( $job_id );

			if ( ! $job ) {
				continue;
			}

			$job_payload = is_array( $job['payload'] ?? null ) ? $job['payload'] : array();

			if ( (int) ( $job_payload['run_id'] ?? 0 ) !== (int) $run['id']
				|| (int) ( $job_payload['automation_id'] ?? 0 ) !== (int) $run['automation_id'] ) {
				continue;
			}

			$jobs[ $job_id ] = $job;
		}

		return $jobs;
	}

	/**
	 * Allow-listed event detail for the run view.
	 *
	 * @param array<string, mixed> $payload Event payload.
	 */
	private static function safe_event_detail( array $payload ): string {
		$parts = array();

		foreach ( array( 'reason', 'status', 'branch', 'event' ) as $key ) {
			if ( isset( $payload[ $key ] ) && is_scalar( $payload[ $key ] ) && '' !== (string) $payload[ $key ] ) {
				$parts[] = $key . '：' . sanitize_text_field( (string) $payload[ $key ] );
			}
		}

		foreach ( array( 'job_id', 'step_id', 'weight_a', 'weight_b' ) as $key ) {
			if ( isset( $payload[ $key ] ) && is_numeric( $payload[ $key ] ) ) {
				$parts[] = $key . '：' . (int) $payload[ $key ];
			}
		}

		if ( isset( $payload['job_key'] ) && is_string( $payload['job_key'] ) && '' !== $payload['job_key'] ) {
			$parts[] = 'job_key：' . sanitize_text_field( $payload['job_key'] );
		}

		return array() === $parts ? '—' : implode( '；', $parts );
	}

	/**
	 * Human label for one job status in the run view.
	 */
	private static function job_status_label( string $status ): string {
		return match ( $status ) {
			EmailJobRepository::STATUS_QUEUED     => __( '待寄', 'now-crm' ),
			EmailJobRepository::STATUS_SENDING    => __( '寄送中', 'now-crm' ),
			EmailJobRepository::STATUS_ACCEPTED   => __( '已交付', 'now-crm' ),
			EmailJobRepository::STATUS_FAILED     => __( '失敗', 'now-crm' ),
			EmailJobRepository::STATUS_SUPPRESSED => __( '已阻擋', 'now-crm' ),
			EmailJobRepository::STATUS_UNKNOWN    => __( '結果不明', 'now-crm' ),
			EmailJobRepository::STATUS_CANCELLED  => __( '已取消', 'now-crm' ),
			default                               => $status,
		};
	}

	/**
	 * Short step label.
	 *
	 * @param array<string, mixed> $step Step row.
	 */
	private static function step_label( array $step ): string {
		$kind = (string) $step['kind'];

		if ( AutomationRepository::KIND_ACTION === $kind ) {
			$action_key = (string) $step['action_key'];

			return (string) $step['ordinal'] . '. ' . ( ActionRegistry::actions()[ $action_key ] ?? $action_key );
		}

		if ( AutomationRepository::KIND_EMAIL === $kind ) {
			return (string) $step['ordinal'] . '. Email';
		}

		if ( AutomationRepository::KIND_CONDITION === $kind ) {
			return (string) $step['ordinal'] . '. ' . __( '條件', 'now-crm' );
		}

		if ( AutomationRepository::KIND_SPLIT === $kind ) {
			$config = (array) ( $step['config'] ?? array() );

			return (string) $step['ordinal'] . '. ' . __( '分流', 'now-crm' )
				. ' A' . (int) ( $config['weight_a'] ?? AutomationRepository::SPLIT_DEFAULT_WEIGHT )
				. '／B' . (int) ( $config['weight_b'] ?? AutomationRepository::SPLIT_DEFAULT_WEIGHT );
		}

		if ( AutomationRepository::KIND_GOAL === $kind ) {
			$config  = (array) ( $step['config'] ?? array() );
			$mode    = (string) ( $config['mode'] ?? AutomationRepository::GOAL_MODE_REQUIRED );
			$timeout = (int) ( $config['timeout_seconds'] ?? 0 );
			$label   = (string) $step['ordinal'] . '. ' . __( '目標', 'now-crm' );

			if ( AutomationRepository::GOAL_MODE_OPTIONAL === $mode ) {
				$label .= '（' . __( '選用', 'now-crm' ) . '）';
			} elseif ( $timeout > 0 ) {
				$label .= '（' . sprintf(
					/* translators: %s: human duration such as "2 小時". */
					__( '逾時 %s', 'now-crm' ),
					self::duration_label( $timeout )
				) . '）';
			}

			return $label;
		}

		if ( AutomationRepository::KIND_WAIT === $kind ) {
			return (string) $step['ordinal'] . '. ' . __( '等待', 'now-crm' ) . ' ' . self::duration_label( (int) $step['delay_seconds'] );
		}

		return (string) $step['ordinal'] . '. ' . __( '結束', 'now-crm' );
	}

	/**
	 * Human duration without a leading verb.
	 *
	 * @param int $seconds Stored seconds.
	 */
	private static function duration_label( int $seconds ): string {
		$seconds = max( 0, $seconds );

		if ( 0 === $seconds ) {
			return __( '立即', 'now-crm' );
		}

		$parts = array();

		foreach ( array( 86400 => __( '天', 'now-crm' ), 3600 => __( '小時', 'now-crm' ), 60 => __( '分', 'now-crm' ), 1 => __( '秒', 'now-crm' ) ) as $size => $unit ) {
			$value = intdiv( $seconds, $size );

			if ( $value > 0 ) {
				$parts[] = $value . ' ' . $unit;
				$seconds -= $value * $size;
			}
		}

		return implode( ' ', $parts );
	}

	/**
	 * Human label for one step-event status.
	 */
	private static function event_status_label( string $status ): string {
		return match ( $status ) {
			RunRepository::EVENT_COMPLETED   => __( '完成', 'now-crm' ),
			RunRepository::EVENT_WAITING     => __( '等待中', 'now-crm' ),
			RunRepository::EVENT_IN_PROGRESS => __( '進行中', 'now-crm' ),
			RunRepository::EVENT_INTERRUPTED => __( '已中斷', 'now-crm' ),
			RunRepository::EVENT_SKIPPED     => __( '略過', 'now-crm' ),
			RunRepository::EVENT_FAILED      => __( '失敗', 'now-crm' ),
			RunRepository::EVENT_TIMEOUT     => __( '逾時', 'now-crm' ),
			default                          => $status,
		};
	}

	/**
	 * Cancel or retry control for one run.
	 *
	 * @param array<string, mixed>             $run           Run row.
	 * @param array<int, array<string, mixed>> $event_rows    Events already loaded for this run.
	 * @param int                              $automation_id Automation ID.
	 * @param int                              $page          Current page.
	 * @param string                           $status_filter Active status filter.
	 * @param string                           $search        Active contact search.
	 */
	private static function render_run_action( array $run, array $event_rows, int $automation_id, int $page, string $status_filter, string $search ): void {
		$status = (string) $run['status'];
		$open   = in_array( $status, array( RunRepository::STATUS_ACTIVE, RunRepository::STATUS_WAITING ), true );

		if ( ! $open && RunRepository::STATUS_FAILED !== $status ) {
			echo '—';

			return;
		}

		$cancel = $open;

		if ( ! $cancel ) {
			$next_step_id = (int) ( $run['next_step_id'] ?? 0 );

			if ( $next_step_id <= 0 ) {
				echo '—';

				return;
			}

			foreach ( $event_rows as $event ) {
				if ( (int) ( $event['step_id'] ?? 0 ) !== $next_step_id ) {
					continue;
				}

				if ( in_array( (string) ( $event['status'] ?? '' ), array( RunRepository::EVENT_IN_PROGRESS, RunRepository::EVENT_INTERRUPTED ), true ) ) {
					echo esc_html__( '步驟結果不明，無法安全重試。', 'now-crm' );

					return;
				}
			}
		}
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
			onsubmit="return confirm('<?php echo esc_js( $cancel ? __( '取消後不會再推進這次執行。已排入佇列的郵件不會撤回。確定要取消嗎？', 'now-crm' ) : __( '重試會重新執行失敗的步驟。已完成的步驟不會重跑。確定要重試嗎？', 'now-crm' ) ); ?>');">
			<input type="hidden" name="action" value="<?php echo esc_attr( $cancel ? AutomationWorkflowActions::CANCEL : AutomationWorkflowActions::RETRY ); ?>" />
			<input type="hidden" name="run_id" value="<?php echo esc_attr( (string) (int) $run['id'] ); ?>" />
			<input type="hidden" name="automation_id" value="<?php echo esc_attr( (string) $automation_id ); ?>" />
			<input type="hidden" name="paged" value="<?php echo esc_attr( (string) max( 1, $page ) ); ?>" />
			<input type="hidden" name="run_status" value="<?php echo esc_attr( $status_filter ); ?>" />
			<input type="hidden" name="run_search" value="<?php echo esc_attr( $search ); ?>" />
			<?php AutomationWorkflowActions::nonce_field(); ?>
			<button type="submit" class="button button-small">
				<?php echo esc_html( $cancel ? __( '取消', 'now-crm' ) : __( '重試', 'now-crm' ) ); ?>
			</button>
		</form>
		<?php
	}

	/**
	 * Compact JSON payload for the report.
	 *
	 * @param array<string, mixed> $payload Event payload.
	 */
	private static function payload_summary( array $payload ): string {
		if ( AutomationRepository::KIND_SPLIT === ( $payload['kind'] ?? '' ) ) {
			return sprintf(
				/* translators: 1: chosen branch, 2: A weight, 3: B weight. */
				__( '分支：%1$s（A %2$d／B %3$d）', 'now-crm' ),
				strtoupper( (string) ( $payload['branch'] ?? '' ) ),
				(int) ( $payload['weight_a'] ?? 0 ),
				(int) ( $payload['weight_b'] ?? 0 )
			);
		}

		$json = wp_json_encode( $payload );

		return mb_substr( is_string( $json ) ? $json : '', 0, 300 );
	}
}
