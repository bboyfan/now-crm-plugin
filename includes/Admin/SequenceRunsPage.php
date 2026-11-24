<?php
/**
 * Per-sequence tracker report and enrollment cancellation.
 *
 * @package NowCRM
 */

namespace NowCRM\Admin;

use NowCRM\Contacts\ContactRepository;
use NowCRM\Sequences\SequenceRepository;
use NowCRM\Sequences\StepStats;
use NowCRM\Sequences\TrackerRepository;
use NowCRM\Support\Capabilities;
use NowCRM\Support\Settings;

defined( 'ABSPATH' ) || exit;

final class SequenceRunsPage {

	private const PER_PAGE = 25;

	/**
	 * Tracker status labels.
	 *
	 * @return array<string, string>
	 */
	public static function status_labels(): array {
		return array(
			TrackerRepository::STATUS_ACTIVE    => __( '進行中', 'now-crm' ),
			TrackerRepository::STATUS_COMPLETED => __( '已完成', 'now-crm' ),
			TrackerRepository::STATUS_CANCELLED => __( '已取消', 'now-crm' ),
		);
	}

	/**
	 * Render the tracker list.
	 */
	public static function render(): void {
		if ( ! Capabilities::can_manage_contacts() ) {
			wp_die( esc_html__( '沒有權限。', 'now-crm' ), '', array( 'response' => 403 ) );
		}

		$id       = absint( Menu::query( 'id' ) );
		$sequence = ( new SequenceRepository() )->get( $id );

		if ( ! $sequence ) {
			printf(
				'<div class="wrap nowcrm-wrap"><div class="notice notice-error"><p>%s</p> <a href="%s">%s</a></div></div>',
				esc_html__( '找不到序列。', 'now-crm' ),
				esc_url( Menu::page_url( Menu::SEQUENCES_SLUG ) ),
				esc_html__( '返回列表', 'now-crm' )
			);

			return;
		}

		$page     = max( 1, absint( Menu::query( 'paged', '1' ) ) );
		$trackers = new TrackerRepository();
		$result   = $trackers->paginate( $id, $page, self::PER_PAGE );
		$stats    = $trackers->stats( $id );
		$contacts = new ContactRepository();
		$steps    = (array) $sequence['steps'];
		$step_stats = ( new StepStats() )->for_sequence( $id );
		$pages    = (int) ceil( $result['total'] / self::PER_PAGE );
		$edit_url = Menu::page_url( Menu::SEQUENCE_SLUG, array( 'id' => $id ) );
		$list_url = Menu::page_url( Menu::SEQUENCES_SLUG );
		?>
		<div class="wrap nowcrm-wrap nowcrm-form-workspace nowcrm-form-workspace--wide">
			<div class="nowcrm-form-workspace__header">
				<div class="nowcrm-form-workspace__heading">
					<h1><?php esc_html_e( '序列追蹤', 'now-crm' ); ?></h1>
					<p class="nowcrm-form-workspace__meta">
						<?php echo esc_html( (string) $sequence['name'] ); ?>
						· <?php echo esc_html( SequenceListPage::audience_label( (array) $sequence['audience'] ) ); ?>
						· <?php echo esc_html( SequenceListPage::status_label( (string) $sequence['status'] ) ); ?>
					</p>
				</div>
				<div class="nowcrm-form-workspace__actions">
					<a class="button" href="<?php echo esc_url( $edit_url ); ?>"><?php esc_html_e( '返回編輯器', 'now-crm' ); ?></a>
					<a class="button" href="<?php echo esc_url( $list_url ); ?>"><?php esc_html_e( '序列列表', 'now-crm' ); ?></a>
				</div>
			</div>

			<?php Actions::render_notice(); ?>

			<?php if ( ! Settings::bool( 'email_sending_enabled' ) ) : ?>
				<div class="notice notice-warning"><p><?php esc_html_e( '實際寄送未開啟：佇列中的序列信件不會寄出。', 'now-crm' ); ?></p></div>
			<?php endif; ?>

			<div class="nowcrm-panel">
				<h2><?php echo esc_html( (string) $sequence['name'] ); ?></h2>
				<div class="nowcrm-form-workspace__stats">
					<div class="nowcrm-form-workspace__stat">
						<span><?php echo esc_html( self::status_labels()[ TrackerRepository::STATUS_ACTIVE ] ); ?></span>
						<strong><?php echo esc_html( (string) (int) $stats['trackers']['active'] ); ?></strong>
					</div>
					<div class="nowcrm-form-workspace__stat">
						<span><?php echo esc_html( self::status_labels()[ TrackerRepository::STATUS_COMPLETED ] ); ?></span>
						<strong><?php echo esc_html( (string) (int) $stats['trackers']['completed'] ); ?></strong>
					</div>
					<div class="nowcrm-form-workspace__stat">
						<span><?php echo esc_html( self::status_labels()[ TrackerRepository::STATUS_CANCELLED ] ); ?></span>
						<strong><?php echo esc_html( (string) (int) $stats['trackers']['cancelled'] ); ?></strong>
					</div>
					<div class="nowcrm-form-workspace__stat">
						<span><?php esc_html_e( '待寄', 'now-crm' ); ?></span>
						<strong><?php echo esc_html( (string) (int) $stats['jobs']['queued'] ); ?></strong>
					</div>
					<div class="nowcrm-form-workspace__stat">
						<span><?php esc_html_e( '已接受', 'now-crm' ); ?></span>
						<strong><?php echo esc_html( (string) (int) $stats['jobs']['accepted'] ); ?></strong>
					</div>
					<div class="nowcrm-form-workspace__stat">
						<span><?php esc_html_e( '抑制', 'now-crm' ); ?></span>
						<strong><?php echo esc_html( (string) (int) $stats['jobs']['suppressed'] ); ?></strong>
					</div>
					<div class="nowcrm-form-workspace__stat">
						<span><?php esc_html_e( '失敗', 'now-crm' ); ?></span>
						<strong><?php echo esc_html( (string) (int) $stats['jobs']['failed'] ); ?></strong>
					</div>
				</div>

				<?php self::render_step_stats( $steps, $step_stats ); ?>

				<div class="nowcrm-form-workspace__table-scroll">
				<table class="wp-list-table widefat fixed striped nowcrm-table" data-testid="sequence-runs">
					<thead>
						<tr>
							<th class="nowcrm-col-id"><?php esc_html_e( '追蹤', 'now-crm' ); ?></th>
							<th><?php esc_html_e( '聯絡人', 'now-crm' ); ?></th>
							<th><?php esc_html_e( '狀態', 'now-crm' ); ?></th>
							<th class="nowcrm-col-number"><?php esc_html_e( '輪次', 'now-crm' ); ?></th>
							<th class="nowcrm-col-number"><?php esc_html_e( '下一步', 'now-crm' ); ?></th>
							<th><?php esc_html_e( '下次處理', 'now-crm' ); ?></th>
							<th><?php esc_html_e( '開始／結束', 'now-crm' ); ?></th>
							<th class="nowcrm-col-actions"><?php esc_html_e( '操作', 'now-crm' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php if ( array() === $result['items'] ) : ?>
							<tr><td colspan="8"><?php esc_html_e( '目前沒有追蹤紀錄。請先在列表頁加入受眾。', 'now-crm' ); ?></td></tr>
						<?php else : ?>
							<?php foreach ( $result['items'] as $tracker ) : ?>
								<?php
								$tracker_id = (int) $tracker['id'];
								$contact    = $contacts->get( (int) $tracker['contact_id'] );
								$next_index = (int) $tracker['next_index'];
								$next_step  = $steps[ $next_index ] ?? null;
								$closed     = TrackerRepository::STATUS_ACTIVE !== $tracker['status'];
								$contact_url = $contact
									? Menu::page_url( Menu::CONTACT_SLUG, array( 'id' => (int) $contact['id'] ) )
									: '';
								?>
								<tr data-tracker-id="<?php echo esc_attr( (string) $tracker_id ); ?>">
									<td>#<?php echo esc_html( (string) $tracker_id ); ?></td>
									<td>
										<?php if ( $contact && '' !== $contact_url ) : ?>
											<a href="<?php echo esc_url( $contact_url ); ?>"><?php echo esc_html( Menu::contact_name( $contact ) ); ?></a>
											<div class="description"><?php echo esc_html( (string) $contact['email'] ); ?></div>
										<?php else : ?>
											<span class="description"><?php esc_html_e( '聯絡人已刪除', 'now-crm' ); ?></span>
										<?php endif; ?>
									</td>
									<td><?php echo esc_html( self::status_labels()[ $tracker['status'] ] ?? (string) $tracker['status'] ); ?></td>
									<td><?php echo esc_html( (string) $tracker['cycle'] ); ?></td>
									<td>
										<?php if ( $next_step ) : ?>
											<?php echo esc_html( (string) $next_step['name'] ); ?>
											<div class="description"><?php echo esc_html( (string) $next_step['subject'] ); ?></div>
										<?php else : ?>
											<span class="description"><?php esc_html_e( '等待佇列結束', 'now-crm' ); ?></span>
										<?php endif; ?>
									</td>
									<td><?php echo esc_html( Menu::format_datetime( $tracker['next_run_at'] ) ); ?></td>
									<td>
										<?php echo esc_html( Menu::format_datetime( (string) $tracker['started_at'] ) ); ?>
										<?php if ( $tracker['completed_at'] || $tracker['cancelled_at'] ) : ?>
											<div class="description">
												<?php echo esc_html( Menu::format_datetime( (string) ( $tracker['completed_at'] ?: $tracker['cancelled_at'] ) ) ); ?>
											</div>
										<?php endif; ?>
									</td>
									<td>
										<?php if ( ! $closed ) : ?>
											<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
												onsubmit="return confirm('<?php echo esc_js( __( '取消後不會再排入這個序列的後續步驟，並會取消尚未寄出的佇列。確定嗎？', 'now-crm' ) ); ?>');">
												<input type="hidden" name="action" value="nowcrm_cancel_sequence_enrollment" />
												<input type="hidden" name="id" value="<?php echo esc_attr( (string) $id ); ?>" />
												<input type="hidden" name="contact_id" value="<?php echo esc_attr( (string) $tracker['contact_id'] ); ?>" />
												<input type="hidden" name="return" value="runs" />
												<?php wp_nonce_field( 'nowcrm_cancel_sequence_enrollment' ); ?>
												<button type="submit" class="button button-small button-link-delete"><?php esc_html_e( '取消追蹤', 'now-crm' ); ?></button>
											</form>
										<?php else : ?>
											<span class="description">—</span>
										<?php endif; ?>
									</td>
								</tr>
							<?php endforeach; ?>
						<?php endif; ?>
					</tbody>
				</table>
				</div>

				<?php if ( $pages > 1 ) : ?>
					<p class="nowcrm-pagination">
						<?php if ( $page > 1 ) : ?>
							<a class="button" href="<?php echo esc_url( Menu::page_url( Menu::SEQUENCE_RUNS_SLUG, array( 'id' => $id, 'paged' => $page - 1 ) ) ); ?>"><?php esc_html_e( '上一頁', 'now-crm' ); ?></a>
						<?php endif; ?>
						<span class="description">
							<?php
							printf(
								/* translators: 1: current page, 2: total pages. */
								esc_html__( '第 %1$d / %2$d 頁', 'now-crm' ),
								(int) $page,
								(int) $pages
							);
							?>
						</span>
						<?php if ( $page < $pages ) : ?>
							<a class="button" href="<?php echo esc_url( Menu::page_url( Menu::SEQUENCE_RUNS_SLUG, array( 'id' => $id, 'paged' => $page + 1 ) ) ); ?>"><?php esc_html_e( '下一頁', 'now-crm' ); ?></a>
						<?php endif; ?>
					</p>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Per-step delivery and unique engagement table.
	 *
	 * @param array<int, array<string, mixed>> $steps Current sequence steps.
	 * @param array{steps: array<string, array<string, int>>, legacy: array<int, array<string, int>>, totals: array<string, int>} $stats Step stats.
	 */
	private static function render_step_stats( array $steps, array $stats ): void {
		$rows      = (array) ( $stats['steps'] ?? array() );
		$legacy    = (array) ( $stats['legacy'] ?? array() );
		$totals    = (array) ( $stats['totals'] ?? array() );
		$has_rows  = array() !== $rows || array() !== $legacy || array() !== $steps;
		?>
		<h3><?php esc_html_e( '步驟成效', 'now-crm' ); ?></h3>
		<p class="description">
			<?php esc_html_e( '只統計序列佇列，不含測試寄送。開啟與點擊以傳輸已接受的郵件數為分母；同一封郵件重複開啟或點擊只計一次。新佇列項目以步驟 UUID 歸戶；沒有 UUID 的舊資料依歷史位置另行列出，不代表目前步驟名稱。開啟訊號可能來自郵件用戶端預取。', 'now-crm' ); ?>
		</p>
		<div class="nowcrm-form-workspace__table-scroll">
		<table class="wp-list-table widefat fixed striped nowcrm-table" data-testid="sequence-step-stats">
			<thead>
				<tr>
					<th><?php esc_html_e( '步驟', 'now-crm' ); ?></th>
					<th><?php esc_html_e( '加入後', 'now-crm' ); ?></th>
					<th class="nowcrm-col-number"><?php esc_html_e( '已接受', 'now-crm' ); ?></th>
					<th class="nowcrm-col-number"><?php esc_html_e( '開啟', 'now-crm' ); ?></th>
					<th class="nowcrm-col-number"><?php esc_html_e( '點擊', 'now-crm' ); ?></th>
					<th class="nowcrm-col-number"><?php esc_html_e( '開啟率', 'now-crm' ); ?></th>
					<th class="nowcrm-col-number"><?php esc_html_e( '點擊率', 'now-crm' ); ?></th>
					<th class="nowcrm-col-number"><?php esc_html_e( '待寄', 'now-crm' ); ?></th>
					<th class="nowcrm-col-number"><?php esc_html_e( '失敗', 'now-crm' ); ?></th>
					<th class="nowcrm-col-number"><?php esc_html_e( '抑制', 'now-crm' ); ?></th>
					<th class="nowcrm-col-number"><?php esc_html_e( '取消', 'now-crm' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( ! $has_rows ) : ?>
					<tr><td colspan="11"><?php esc_html_e( '還沒有步驟佇列紀錄。', 'now-crm' ); ?></td></tr>
				<?php else : ?>
					<?php foreach ( $steps as $index => $step ) : ?>
						<?php
						$uuid   = (string) ( $step['uuid'] ?? '' );
						$counts = '' !== $uuid ? ( $rows[ $uuid ] ?? array() ) : array();
						self::render_step_stat_row( (int) $index, $step, $counts );
						?>
					<?php endforeach; ?>
					<?php foreach ( $legacy as $position => $counts ) : ?>
						<?php self::render_legacy_stat_row( (int) $position, (array) $counts ); ?>
					<?php endforeach; ?>
					<tr data-step-stat="total">
						<td><strong><?php esc_html_e( '合計', 'now-crm' ); ?></strong></td>
						<td>—</td>
						<?php foreach ( array( 'accepted', 'opens', 'clicks' ) as $metric ) : ?>
							<td class="nowcrm-col-number"><strong><?php echo esc_html( number_format_i18n( (int) ( $totals[ $metric ] ?? 0 ) ) ); ?></strong></td>
						<?php endforeach; ?>
						<td class="nowcrm-col-number"><strong><?php echo esc_html( self::rate_label( $totals, 'opens' ) ); ?></strong></td>
						<td class="nowcrm-col-number"><strong><?php echo esc_html( self::rate_label( $totals, 'clicks' ) ); ?></strong></td>
						<?php foreach ( array( 'queued', 'failed', 'suppressed', 'cancelled' ) as $metric ) : ?>
							<td class="nowcrm-col-number"><strong><?php echo esc_html( number_format_i18n( (int) ( $totals[ $metric ] ?? 0 ) ) ); ?></strong></td>
						<?php endforeach; ?>
					</tr>
				<?php endif; ?>
			</tbody>
		</table>
		</div>
		<?php
	}

	/**
	 * One current step row.
	 *
	 * @param int                  $index  Current display position.
	 * @param array<string, mixed> $step   Current step.
	 * @param array<string, int>   $counts Step counters keyed by UUID.
	 */
	private static function render_step_stat_row( int $index, array $step, array $counts ): void {
		$subject = (string) ( $step['subject'] ?? '' );
		$delay   = (int) ( $step['delay_seconds'] ?? 0 );
		?>
		<tr data-step-stat="step-<?php echo esc_attr( (string) $index ); ?>">
			<td>
				<strong><?php echo esc_html( (string) ( $index + 1 ) . '. ' . (string) ( $step['name'] ?? '' ) ); ?></strong>
				<?php if ( '' !== $subject ) : ?>
					<div class="description"><?php echo esc_html( $subject ); ?></div>
				<?php endif; ?>
			</td>
			<td><?php echo esc_html( SequenceEditorPage::format_delay( $delay ) ); ?></td>
			<?php self::render_stat_numbers( $counts ); ?>
		</tr>
		<?php
	}

	/**
	 * One legacy row. The historical position is not a current step.
	 *
	 * @param int                $position Historical step position from job_key.
	 * @param array<string, int> $counts   Step counters.
	 */
	private static function render_legacy_stat_row( int $position, array $counts ): void {
		?>
		<tr data-step-stat="legacy-<?php echo esc_attr( (string) $position ); ?>">
			<td>
				<strong><?php echo esc_html( sprintf( /* translators: %d: historical zero-based position. */ __( '歷史位置 %d（舊資料，未綁定目前步驟）', 'now-crm' ), $position ) ); ?></strong>
			</td>
			<td>—</td>
			<?php self::render_stat_numbers( $counts ); ?>
		</tr>
		<?php
	}

	/**
	 * Numeric columns shared by current and legacy rows.
	 *
	 * @param array<string, int> $counts Step counters.
	 */
	private static function render_stat_numbers( array $counts ): void {
		foreach ( array( 'accepted', 'opens', 'clicks' ) as $metric ) :
			?>
			<td class="nowcrm-col-number"><?php echo esc_html( number_format_i18n( (int) ( $counts[ $metric ] ?? 0 ) ) ); ?></td>
		<?php endforeach; ?>
		<td class="nowcrm-col-number"><?php echo esc_html( self::rate_label( $counts, 'opens' ) ); ?></td>
		<td class="nowcrm-col-number"><?php echo esc_html( self::rate_label( $counts, 'clicks' ) ); ?></td>
		<?php foreach ( array( 'queued', 'failed', 'suppressed', 'cancelled' ) as $metric ) : ?>
			<td class="nowcrm-col-number"><?php echo esc_html( number_format_i18n( (int) ( $counts[ $metric ] ?? 0 ) ) ); ?></td>
		<?php endforeach; ?>
		<?php
	}

	/**
	 * Percent label with an explicit empty denominator.
	 *
	 * @param array<string, int> $counts Step counters.
	 */
	private static function rate_label( array $counts, string $metric ): string {
		$rate = StepStats::rate( $counts, $metric );

		return null === $rate ? '—' : number_format_i18n( $rate, 1 ) . '%';
	}
}
