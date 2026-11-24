<?php
/**
 * Email delivery log: queue records, suppression summary, and worker diagnostics.
 *
 * The log is a read-only support surface. Detail view shows one job, its frozen
 * content snapshot when available, and the existing operator retry action only
 * when the queue itself would accept it.
 *
 * @package NowCRM
 */

namespace NowCRM\Admin;

use NowCRM\Automation\RunRepository;
use NowCRM\Campaign\Campaigns;
use NowCRM\Contacts\ContactRepository;
use NowCRM\Email\ContactHistory;
use NowCRM\Email\EmailJobRepository;
use NowCRM\Email\EmailRenderer;
use NowCRM\Email\SuppressionRepository;
use NowCRM\Email\WorkerHealth;
use NowCRM\Ses\Processor;
use NowCRM\Support\Capabilities;
use NowCRM\Support\Settings;

defined( 'ABSPATH' ) || exit;

final class EmailLogPage {

	public const TAB_LOG = 'log';

	public const TAB_SUPPRESSION = 'suppression';

	public const TAB_DIAGNOSTICS = 'diagnostics';

	/**
	 * Rows per page, matching the list baseline.
	 */
	public const PER_PAGE = 10;

	/**
	 * Statuses shown in filters and counters, in display order.
	 *
	 * @return string[]
	 */
	public static function statuses(): array {
		return array(
			EmailJobRepository::STATUS_QUEUED,
			EmailJobRepository::STATUS_SENDING,
			EmailJobRepository::STATUS_ACCEPTED,
			EmailJobRepository::STATUS_FAILED,
			EmailJobRepository::STATUS_SUPPRESSED,
			EmailJobRepository::STATUS_UNKNOWN,
			EmailJobRepository::STATUS_CANCELLED,
		);
	}

	/**
	 * Delivery values shown in the filter. `none` means no SNS receipt yet.
	 *
	 * @return string[]
	 */
	public static function delivery_statuses(): array {
		return array( 'none', ...ContactHistory::delivery_statuses() );
	}

	/**
	 * Job kinds the worker actually writes to the queue.
	 *
	 * @return string[]
	 */
	public static function kinds(): array {
		return array( 'campaign', 'cart', 'test', 'custom', 'automation', 'sequence', 'consent' );
	}

	/**
	 * Human label for a job kind. Unknown kinds fall back to the raw key.
	 */
	public static function kind_label( string $kind ): string {
		$labels = array(
			'campaign'   => __( '電子報', 'now-crm' ),
			'cart'       => __( '購物車提醒', 'now-crm' ),
			'test'       => __( '測試寄送', 'now-crm' ),
			'custom'     => __( '一次性 Email', 'now-crm' ),
			'automation' => __( '自動化', 'now-crm' ),
			'sequence'   => __( '序列', 'now-crm' ),
			'consent'    => __( '訂閱確認', 'now-crm' ),
		);

		return $labels[ $kind ] ?? $kind;
	}

	/**
	 * Allow-listed tab from the query string. Unknown or unreadable tabs fall
	 * back to the log, and diagnostics needs the settings capability.
	 */
	public static function tab_from_query( string $tab, bool $can_settings ): string {
		if ( self::TAB_SUPPRESSION === $tab ) {
			return self::TAB_SUPPRESSION;
		}

		if ( self::TAB_DIAGNOSTICS === $tab && $can_settings ) {
			return self::TAB_DIAGNOSTICS;
		}

		return self::TAB_LOG;
	}

	/**
	 * Validated list filters. An invalid or unreadable value returns an error
	 * and no filters, so the caller must not run a widened query.
	 *
	 * @param array<string, mixed> $query Raw query array.
	 * @return array{filters: array<string, mixed>, error: string}
	 */
	public static function filters_from_query( array $query ): array {
		$read = static function ( string $key ) use ( $query ): string {
			if ( ! isset( $query[ $key ] ) || ! is_string( $query[ $key ] ) ) {
				return '';
			}

			return trim( wp_unslash( $query[ $key ] ) );
		};

		$filters = array();

		$status = $read( 'status' );

		if ( '' !== $status ) {
			if ( ! in_array( $status, self::statuses(), true ) ) {
				return array( 'filters' => array(), 'error' => 'status' );
			}

			$filters['status'] = $status;
		}

		$kind = $read( 'kind' );

		if ( '' !== $kind ) {
			if ( ! in_array( $kind, self::kinds(), true ) ) {
				return array( 'filters' => array(), 'error' => 'kind' );
			}

			$filters['kind'] = $kind;
		}

		$delivery = $read( 'delivery_status' );

		if ( '' !== $delivery ) {
			if ( ! in_array( $delivery, self::delivery_statuses(), true ) ) {
				return array( 'filters' => array(), 'error' => 'delivery_status' );
			}

			$filters['delivery_status'] = $delivery;
		}

		foreach ( array( 'campaign_id', 'cart_id' ) as $key ) {
			$value = $read( $key );

			if ( '' === $value ) {
				continue;
			}

			if ( ! preg_match( '/^\d+$/', $value ) || absint( $value ) < 1 ) {
				return array( 'filters' => array(), 'error' => $key );
			}

			$filters[ $key ] = absint( $value );
		}

		$email = $read( 'email' );

		if ( '' !== $email ) {
			if ( ! is_email( $email ) || mb_strlen( $email, 'UTF-8' ) > 191 ) {
				return array( 'filters' => array(), 'error' => 'email' );
			}

			$filters['email'] = mb_strtolower( $email, 'UTF-8' );
		}

		return array( 'filters' => $filters, 'error' => '' );
	}

	/**
	 * Whether the repository retry SQL accepts this row.
	 *
	 * Mirrors EmailJobRepository::retry(): failed, unknown, or suppressed; never
	 * a campaign row that still belongs to a campaign; never a row with a
	 * recorded provider receipt; and never a cart-linked non-failure.
	 *
	 * @param array<string, mixed> $job Job row.
	 */
	public static function can_retry( array $job ): bool {
		$status = (string) ( $job['status'] ?? '' );
		$kind   = (string) ( $job['kind'] ?? '' );
		$campaign_id = (int) ( $job['campaign_id'] ?? 0 );
		$cart_id     = (int) ( $job['cart_id'] ?? 0 );

		if ( ! in_array( $status, array( EmailJobRepository::STATUS_FAILED, EmailJobRepository::STATUS_UNKNOWN, EmailJobRepository::STATUS_SUPPRESSED ), true ) ) {
			return false;
		}

		if ( 'campaign' === $kind && $campaign_id > 0 ) {
			return false;
		}

		// A recorded provider result is evidence; the repository never requeues it.
		if ( '' !== (string) ( $job['delivery_status'] ?? '' ) ) {
			return false;
		}

		// A cart-linked unknown outcome may already have reached the transport.
		if ( $cart_id > 0 && EmailJobRepository::STATUS_FAILED !== $status ) {
			return false;
		}

		return true;
	}

	/**
	 * Render the screen.
	 */
	public static function render(): void {
		if ( ! Capabilities::can_manage_contacts() ) {
			wp_die( esc_html__( '沒有權限。', 'now-crm' ), '', array( 'response' => 403 ) );
		}

		$can_settings = Capabilities::can_manage_settings();
		$tab          = self::tab_from_query( Menu::query( 'tab' ), $can_settings );
		?>
		<div class="wrap nowcrm-wrap">
			<h1><?php esc_html_e( '寄送紀錄', 'now-crm' ); ?></h1>
			<?php self::render_tabs( $tab, $can_settings ); ?>
			<?php Actions::render_notice(); ?>
			<?php
			if ( self::TAB_SUPPRESSION === $tab ) {
				self::render_suppression();
			} elseif ( self::TAB_DIAGNOSTICS === $tab ) {
				self::render_diagnostics();
			} else {
				self::render_log();
			}
			?>
		</div>
		<?php
	}

	/**
	 * Page tabs. Real links, one current tab.
	 */
	private static function render_tabs( string $current, bool $can_settings ): void {
		$tabs = array(
			self::TAB_LOG         => array( __( '寄送紀錄', 'now-crm' ), Menu::page_url( Menu::EMAIL_SLUG ) ),
			self::TAB_SUPPRESSION => array( __( '抑制名單', 'now-crm' ), Menu::page_url( Menu::EMAIL_SLUG, array( 'tab' => self::TAB_SUPPRESSION ) ) ),
		);

		if ( $can_settings ) {
			$tabs[ self::TAB_DIAGNOSTICS ] = array(
				__( '診斷', 'now-crm' ),
				Menu::page_url( Menu::EMAIL_SLUG, array( 'tab' => self::TAB_DIAGNOSTICS ) ),
			);
		}
		?>
		<nav class="nowcrm-contact-tabs" aria-label="<?php esc_attr_e( '寄送紀錄分頁', 'now-crm' ); ?>">
			<?php foreach ( $tabs as $key => $tab ) : ?>
				<a class="nowcrm-contact-tabs__item" href="<?php echo esc_url( $tab[1] ); ?>"
					<?php echo $key === $current ? 'aria-current="page"' : ''; ?>>
					<?php echo esc_html( $tab[0] ); ?>
				</a>
			<?php endforeach; ?>
		</nav>
		<?php
	}

	/**
	 * Log list and single-job detail.
	 */
	private static function render_log(): void {
		$repo   = new EmailJobRepository();
		$parsed = self::filters_from_query( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filters.
		$error  = (string) $parsed['error'];
		$filters = (array) $parsed['filters'];

		$detail_id = absint( Menu::query( 'job' ) );
		$paged     = max( 1, absint( Menu::query( 'paged', '1' ) ) );

		if ( $detail_id > 0 ) {
			$job = $repo->get( $detail_id );

			if ( $job ) {
				self::render_detail( $job, $filters, $paged );
				return;
			}

			?>
			<div class="nowcrm-panel">
				<h2><?php esc_html_e( '找不到這筆寄送紀錄', 'now-crm' ); ?></h2>
				<p><?php esc_html_e( 'Job 可能已不存在或編號不正確。不會顯示替代資料。', 'now-crm' ); ?></p>
				<p><a class="button" href="<?php echo esc_url( self::list_url( $filters, $paged ) ); ?>"><?php esc_html_e( '返回寄送紀錄', 'now-crm' ); ?></a></p>
			</div>
			<?php
			return;
		}

		$limit  = self::PER_PAGE;
		$result = array( 'items' => array(), 'total' => 0 );

		if ( '' === $error ) {
			$result = $repo->search(
				array_merge(
					$filters,
					array(
						'limit'  => $limit,
						'offset' => ( $paged - 1 ) * $limit,
					)
				)
			);

			$pages = (int) ceil( $result['total'] / $limit );

			if ( $pages > 0 && $paged > $pages ) {
				$paged  = $pages;
				$result = $repo->search(
					array_merge(
						$filters,
						array(
							'limit'  => $limit,
							'offset' => ( $paged - 1 ) * $limit,
						)
					)
				);
			}
		}

		$total  = (int) $result['total'];
		$pages  = (int) ceil( $total / $limit );

		self::render_stats();

		if ( '' !== $error ) {
			self::render_filter_error( $error );
			self::render_filters( $filters );
			return;
		}

		self::render_filters( $filters );

		$has_filters = (bool) $filters;

		?>
		<div class="nowcrm-panel">
			<table class="widefat striped nowcrm-table">
				<thead><tr>
					<th><?php esc_html_e( '建立時間', 'now-crm' ); ?></th>
					<th><?php esc_html_e( '類型', 'now-crm' ); ?></th>
					<th><?php esc_html_e( '狀態', 'now-crm' ); ?></th>
					<th><?php esc_html_e( '收件者', 'now-crm' ); ?></th>
					<th><?php esc_html_e( '主旨', 'now-crm' ); ?></th>
					<th><?php esc_html_e( '嘗試', 'now-crm' ); ?></th>
					<th><?php esc_html_e( '來源', 'now-crm' ); ?></th>
					<th><?php esc_html_e( '操作', 'now-crm' ); ?></th>
				</tr></thead>
				<tbody>
				<?php if ( ! $result['items'] ) : ?>
					<tr>
						<td colspan="8">
							<div class="nowcrm-empty">
								<p>
									<?php
									echo $has_filters
										? esc_html__( '沒有符合目前篩選條件的寄送紀錄。', 'now-crm' )
										: esc_html__( '目前還沒有寄送紀錄。', 'now-crm' );
									?>
								</p>
								<?php if ( $has_filters ) : ?>
									<p><a class="button" href="<?php echo esc_url( self::list_url( array() ) ); ?>"><?php esc_html_e( '清除篩選', 'now-crm' ); ?></a></p>
								<?php endif; ?>
							</div>
						</td>
					</tr>
				<?php else : ?>
					<?php foreach ( $result['items'] as $job ) : ?>
						<?php $job_error = (string) ( $job['last_error'] ?? '' ); ?>
						<tr>
							<td>
								<?php echo esc_html( Menu::format_datetime( (string) $job['created_at'] ) ); ?>
								<span class="description">#<?php echo esc_html( (string) (int) $job['id'] ); ?></span>
							</td>
							<td><?php echo esc_html( self::kind_label( (string) $job['kind'] ) ); ?></td>
							<td>
								<span class="nowcrm-status-badge">
									<span class="nowcrm-status-dot" aria-hidden="true"></span>
									<?php echo esc_html( ContactHistory::display_status_label( $job ) ); ?>
								</span>
								<?php if ( '' !== (string) ( $job['delivery_status'] ?? '' ) ) : ?>
									<span class="description">
										<?php
										printf(
											/* translators: %s: queue status label. */
											esc_html__( '佇列：%s', 'now-crm' ),
											esc_html( ContactHistory::status_label( (string) $job['status'] ) )
										);
										?>
									</span>
								<?php endif; ?>
								<?php if ( '' !== $job_error ) : ?>
									<span class="description"><?php echo esc_html( mb_substr( $job_error, 0, 60 ) ); ?></span>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( (string) $job['recipient'] ); ?></td>
							<td><?php echo esc_html( mb_substr( (string) $job['subject'], 0, 60 ) ); ?></td>
							<td><?php echo esc_html( $job['attempts'] . ' / ' . $job['max_attempts'] ); ?></td>
							<td><?php echo esc_html( self::source_label( $job ) ); ?></td>
							<td>
								<a class="button button-small" href="<?php echo esc_url( self::detail_url( (int) $job['id'], $filters, $paged ) ); ?>">
									<?php esc_html_e( '查看', 'now-crm' ); ?>
								</a>
								<?php self::render_retry_form( $job, self::return_args( $filters, $paged ) ); ?>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
				</tbody>
			</table>

			<?php if ( $total > 0 ) : ?>
				<div class="nowcrm-contacts-footer">
					<p>
						<?php
						printf(
							/* translators: 1: first row number, 2: last row number, 3: total rows. */
							esc_html__( '顯示 %1$d–%2$d，共 %3$s 筆', 'now-crm' ),
							(int) ( ( $paged - 1 ) * $limit + 1 ),
							(int) min( $paged * $limit, $total ),
							esc_html( number_format_i18n( $total ) )
						);
						?>
					</p>

					<?php if ( $pages > 1 ) : ?>
						<nav class="nowcrm-pagination" aria-label="<?php esc_attr_e( '寄送紀錄分頁', 'now-crm' ); ?>">
							<?php if ( $paged > 1 ) : ?>
								<a class="nowcrm-page-link" href="<?php echo esc_url( self::paged_url( $paged - 1, $filters ) ); ?>">‹</a>
							<?php endif; ?>

							<?php for ( $number = max( 1, $paged - 2 ); $number <= min( $pages, $paged + 2 ); $number++ ) : ?>
								<a class="nowcrm-page-link <?php echo $number === $paged ? 'is-active' : ''; ?>"
									href="<?php echo esc_url( self::paged_url( $number, $filters ) ); ?>"
									<?php echo $number === $paged ? 'aria-current="page"' : ''; ?>>
									<?php echo esc_html( (string) $number ); ?>
								</a>
							<?php endfor; ?>

							<?php if ( $paged < $pages ) : ?>
								<a class="nowcrm-page-link" href="<?php echo esc_url( self::paged_url( $paged + 1, $filters ) ); ?>">›</a>
							<?php endif; ?>
						</nav>
					<?php endif; ?>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Queue and delivery counters.
	 */
	private static function render_stats(): void {
		$repo  = new EmailJobRepository();
		$stats = $repo->global_stats();

		$delivery = $repo->delivery_stats();
		$order    = array(
			EmailJobRepository::DELIVERY_DELIVERED,
			EmailJobRepository::DELIVERY_BOUNCED,
			EmailJobRepository::DELIVERY_COMPLAINED,
			EmailJobRepository::DELIVERY_DEFERRED,
			'none',
		);
		?>
		<div class="nowcrm-panel">
			<ul class="nowcrm-meta">
				<li>
					<span><?php esc_html_e( '全部', 'now-crm' ); ?></span>
					<strong><?php echo esc_html( number_format_i18n( (int) $stats['total'] ) ); ?></strong>
				</li>
				<?php foreach ( self::statuses() as $key ) : ?>
					<li>
						<span><?php echo esc_html( ContactHistory::status_label( $key ) ); ?></span>
						<strong><?php echo esc_html( number_format_i18n( (int) ( $stats[ $key ] ?? 0 ) ) ); ?></strong>
					</li>
				<?php endforeach; ?>
			</ul>
			<p class="description">
				<?php esc_html_e( '以上是佇列狀態：全部是全站 Job 數，「已送出」表示 transport 已接受，還不代表已送達信箱。這些計數不受下方篩選影響。', 'now-crm' ); ?>
			</p>
			<ul class="nowcrm-meta">
				<?php foreach ( $order as $key ) : ?>
					<li>
						<span><?php echo esc_html( ContactHistory::delivery_label( 'none' === $key ? '' : $key ) ); ?></span>
						<strong><?php echo esc_html( number_format_i18n( (int) ( $delivery[ $key ] ?? 0 ) ) ); ?></strong>
					</li>
				<?php endforeach; ?>
			</ul>
			<p class="description">
				<?php esc_html_e( '送達狀態計數包含所有已記錄結果的 Job，不限佇列狀態；「尚未回條」只計算已送出但沒有結果的 Job。結果來自 SES SNS 的 Delivery／Bounce／Complaint 通知；沒有通知不代表已送達。', 'now-crm' ); ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Filters. Values are the validated set, so a bad value never broadens scope.
	 *
	 * @param array<string, mixed> $filters Validated filters.
	 */
	private static function render_filters( array $filters ): void {
		$campaign_id = (int) ( $filters['campaign_id'] ?? 0 );
		?>
		<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="nowcrm-panel nowcrm-filters">
			<input type="hidden" name="page" value="<?php echo esc_attr( Menu::EMAIL_SLUG ); ?>" />
			<input type="hidden" name="tab" value="<?php echo esc_attr( self::TAB_LOG ); ?>" />
			<?php if ( $campaign_id > 0 ) : ?>
				<input type="hidden" name="campaign_id" value="<?php echo esc_attr( (string) $campaign_id ); ?>" />
			<?php endif; ?>

			<label>
				<span><?php esc_html_e( '狀態', 'now-crm' ); ?></span>
				<select name="status">
					<option value=""><?php esc_html_e( '全部狀態', 'now-crm' ); ?></option>
					<?php foreach ( self::statuses() as $state ) : ?>
						<option value="<?php echo esc_attr( $state ); ?>" <?php selected( (string) ( $filters['status'] ?? '' ), $state ); ?>>
							<?php echo esc_html( ContactHistory::status_label( $state ) ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</label>

			<label>
				<span><?php esc_html_e( '送達狀態', 'now-crm' ); ?></span>
				<select name="delivery_status">
					<option value=""><?php esc_html_e( '全部送達狀態', 'now-crm' ); ?></option>
					<?php foreach ( self::delivery_statuses() as $delivery ) : ?>
						<option value="<?php echo esc_attr( $delivery ); ?>" <?php selected( (string) ( $filters['delivery_status'] ?? '' ), $delivery ); ?>>
							<?php echo esc_html( ContactHistory::delivery_label( 'none' === $delivery ? '' : $delivery ) ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</label>

			<label>
				<span><?php esc_html_e( '類型', 'now-crm' ); ?></span>
				<select name="kind">
					<option value=""><?php esc_html_e( '全部類型', 'now-crm' ); ?></option>
					<?php foreach ( self::kinds() as $type ) : ?>
						<option value="<?php echo esc_attr( $type ); ?>" <?php selected( (string) ( $filters['kind'] ?? '' ), $type ); ?>>
							<?php echo esc_html( self::kind_label( $type ) ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</label>

			<label>
				<span><?php esc_html_e( 'Email', 'now-crm' ); ?></span>
				<input type="email" name="email" value="<?php echo esc_attr( (string) ( $filters['email'] ?? '' ) ); ?>" />
			</label>

			<label>
				<span><?php esc_html_e( '購物車 ID', 'now-crm' ); ?></span>
				<input type="number" min="1" step="1" name="cart_id" value="<?php echo esc_attr( (string) ( $filters['cart_id'] ?? '' ) ); ?>" />
			</label>

			<button type="submit" class="button"><?php esc_html_e( '篩選', 'now-crm' ); ?></button>
			<a class="button" href="<?php echo esc_url( self::list_url( array() ) ); ?>"><?php esc_html_e( '清除篩選', 'now-crm' ); ?></a>
		</form>
		<?php

		if ( $campaign_id > 0 ) {
			?>
			<div class="nowcrm-panel">
				<p>
					<?php
					printf(
						/* translators: %d: campaign ID. */
						esc_html__( '目前只顯示電子報 #%d 的寄送紀錄。', 'now-crm' ),
						(int) $campaign_id
					);
					?>
					<?php if ( Campaigns::can_edit( $campaign_id ) ) : ?>
						<a href="<?php echo esc_url( CampaignPage::url( $campaign_id ) ); ?>"><?php esc_html_e( '返回電子報', 'now-crm' ); ?></a>
					<?php endif; ?>
					<a href="<?php echo esc_url( self::list_url( array_diff_key( $filters, array( 'campaign_id' => true ) ) ) ); ?>">
						<?php esc_html_e( '移除電子報篩選', 'now-crm' ); ?>
					</a>
				</p>
			</div>
			<?php
		}
	}

	/**
	 * Invalid filter message. The caller shows no rows for this scope.
	 */
	private static function render_filter_error( string $key ): void {
		$messages = array(
			'status'      => __( '狀態篩選值不正確，未顯示任何紀錄。請從清單重新選擇。', 'now-crm' ),
			'kind'        => __( '類型篩選值不正確，未顯示任何紀錄。請從清單重新選擇。', 'now-crm' ),
			'delivery_status' => __( '送達狀態篩選值不正確，未顯示任何紀錄。請從清單重新選擇。', 'now-crm' ),
			'campaign_id' => __( '電子報編號必須是正整數，未顯示任何紀錄。', 'now-crm' ),
			'cart_id'     => __( '購物車編號必須是正整數，未顯示任何紀錄。', 'now-crm' ),
			'email'       => __( 'Email 格式不正確，未顯示任何紀錄。請輸入完整地址。', 'now-crm' ),
		);
		?>
		<div class="notice notice-error inline" role="alert">
			<p><?php echo esc_html( $messages[ $key ] ?? __( '篩選條件不正確，未顯示任何紀錄。', 'now-crm' ) ); ?></p>
			<p><a href="<?php echo esc_url( self::list_url( array() ) ); ?>"><?php esc_html_e( '清除篩選', 'now-crm' ); ?></a></p>
		</div>
		<?php
	}

	/**
	 * POST return context for the retry handler: the current validated view.
	 *
	 * @param array<string, mixed> $filters     Validated filters.
	 * @param int                  $paged       Current validated list page.
	 * @param bool                 $with_detail True when retrying from the detail view.
	 * @return array<string, mixed>
	 */
	private static function return_args( array $filters, int $paged, bool $with_detail = false ): array {
		$return = array();

		if ( $with_detail ) {
			$return['return_job'] = 1;
		}

		foreach ( $filters as $key => $value ) {
			$return[ 'return_' . $key ] = $value;
		}

		if ( $paged > 1 ) {
			$return['return_paged'] = $paged;
		}

		return $return;
	}

	/**
	 * One job, read-only.
	 *
	 * @param array<string, mixed> $job     Job row.
	 * @param array<string, mixed> $filters Validated list filters to return to.
	 * @param int                  $paged   Current validated list page.
	 */
	private static function render_detail( array $job, array $filters, int $paged ): void {
		$job_id      = (int) $job['id'];
		$error       = (string) ( $job['last_error'] ?? '' );
		$campaign_id = (int) ( $job['campaign_id'] ?? 0 );
		$cart_id     = (int) ( $job['cart_id'] ?? 0 );
		$contact_id  = (int) ( $job['contact_id'] ?? 0 );
		$payload     = is_array( $job['payload'] ?? null ) ? $job['payload'] : array();
		$automation_id = (int) ( $payload['automation_id'] ?? 0 );
		$run_id        = (int) ( $payload['run_id'] ?? 0 );
		$run           = $run_id > 0 ? ( new RunRepository() )->get( $run_id ) : null;

		if ( $run && (int) $run['automation_id'] !== $automation_id ) {
			// A run that belongs to another workflow is not evidence for this job.
			$run = null;
		}

		$contact     = $contact_id > 0 ? ( new ContactRepository() )->get( $contact_id ) : null;
		$preview     = self::preview( $job );
		?>
		<div class="nowcrm-panel">
			<p>
				<a href="<?php echo esc_url( self::list_url( $filters, $paged ) ); ?>"><?php esc_html_e( '← 返回寄送紀錄', 'now-crm' ); ?></a>
			</p>
			<h2>
				<?php
				echo esc_html(
					sprintf(
						/* translators: %d: job ID. */
						__( '寄送紀錄 #%d', 'now-crm' ),
						$job_id
					)
				);
				?>
			</h2>
			<p>
				<span class="nowcrm-status-badge">
					<span class="nowcrm-status-dot" aria-hidden="true"></span>
					<?php echo esc_html( ContactHistory::display_status_label( $job ) ); ?>
				</span>
				<?php echo esc_html( self::kind_label( (string) $job['kind'] ) ); ?>
				<?php if ( '' !== (string) ( $job['delivery_status'] ?? '' ) ) : ?>
					<span class="description">
						<?php
						printf(
							/* translators: %s: queue status label. */
							esc_html__( '佇列：%s', 'now-crm' ),
							esc_html( ContactHistory::status_label( (string) $job['status'] ) )
						);
						?>
					</span>
				<?php endif; ?>
			</p>
		</div>

		<div class="nowcrm-panel">
			<table class="widefat striped">
				<tbody>
					<tr>
						<th scope="row"><?php esc_html_e( '收件者', 'now-crm' ); ?></th>
						<td>
							<?php echo esc_html( (string) $job['recipient'] ); ?>
							<?php if ( '' !== (string) $job['recipient_name'] ) : ?>
								<span class="description"><?php echo esc_html( (string) $job['recipient_name'] ); ?></span>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( '聯絡人', 'now-crm' ); ?></th>
						<td>
							<?php if ( $contact_id > 0 && $contact ) : ?>
								<a href="<?php echo esc_url( Menu::page_url( Menu::CONTACT_SLUG, array( 'id' => $contact_id, 'tab' => 'emails' ) ) ); ?>">
									<?php echo esc_html( Menu::contact_name( $contact ) . ' · ' . (string) $contact['email'] ); ?>
								</a>
							<?php elseif ( $contact_id > 0 ) : ?>
								<?php
								echo esc_html(
									sprintf(
										/* translators: %d: contact ID. */
										__( '聯絡人 #%d（目前找不到）', 'now-crm' ),
										$contact_id
									)
								);
								?>
							<?php else : ?>
								<?php esc_html_e( '未綁定聯絡人', 'now-crm' ); ?>
							<?php endif; ?>
						</td>
					</tr>
					<?php if ( $automation_id > 0 || $run_id > 0 ) : ?>
						<tr>
							<th scope="row"><?php esc_html_e( '自動化執行', 'now-crm' ); ?></th>
							<td>
								<?php if ( $automation_id > 0 ) : ?>
									<a href="<?php echo esc_url( Menu::page_url( Menu::AUTOMATION_SLUG, array( 'id' => $automation_id ) ) ); ?>">
										<?php
										echo esc_html(
											sprintf(
												/* translators: %d: automation ID. */
												__( '自動化 #%d', 'now-crm' ),
												$automation_id
											)
										);
										?>
									</a>
								<?php endif; ?>
								<?php if ( $run_id > 0 ) : ?>
									<?php if ( $run ) : ?>
										<a href="<?php echo esc_url( Menu::page_url( Menu::AUTOMATION_RUNS_SLUG, array( 'id' => $automation_id, 'run_id' => $run_id ) ) ); ?>">
											<?php
											echo esc_html(
												sprintf(
													/* translators: %d: run ID. */
													__( '執行 #%d', 'now-crm' ),
													$run_id
												)
											);
											?>
										</a>
									<?php else : ?>
										<span class="description">
											<?php
											echo esc_html(
												sprintf(
													/* translators: %d: run ID. */
													__( '執行 #%d（找不到或不屬於此自動化）', 'now-crm' ),
													$run_id
												)
											);
											?>
										</span>
									<?php endif; ?>
								<?php endif; ?>
							</td>
						</tr>
					<?php endif; ?>
					<tr>
						<th scope="row"><?php esc_html_e( '主旨', 'now-crm' ); ?></th>
						<td><?php echo esc_html( (string) $job['subject'] ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( '嘗試次數', 'now-crm' ); ?></th>
						<td><?php echo esc_html( $job['attempts'] . ' / ' . $job['max_attempts'] ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( '來源', 'now-crm' ); ?></th>
						<td>
							<?php if ( $campaign_id > 0 ) : ?>
								<?php if ( Campaigns::can_edit( $campaign_id ) ) : ?>
									<a href="<?php echo esc_url( CampaignPage::url( $campaign_id ) ); ?>">
										<?php
										echo esc_html(
											sprintf(
												/* translators: %d: campaign ID. */
												__( '電子報 #%d', 'now-crm' ),
												$campaign_id
											)
										);
										?>
									</a>
								<?php elseif ( Campaigns::get( $campaign_id ) ) : ?>
									<?php
									echo esc_html(
										sprintf(
											/* translators: %d: campaign ID. */
											__( '電子報 #%d（沒有檢視權限）', 'now-crm' ),
											$campaign_id
										)
									);
									?>
								<?php else : ?>
									<?php
									echo esc_html(
										sprintf(
											/* translators: %d: campaign ID. */
											__( '電子報 #%d（找不到）', 'now-crm' ),
											$campaign_id
										)
									);
									?>
								<?php endif; ?>
							<?php elseif ( $cart_id > 0 ) : ?>
								<a href="<?php echo esc_url( Menu::page_url( Menu::CART_SLUG, array( 'cart' => $cart_id ) ) ); ?>">
									<?php
									echo esc_html(
										sprintf(
											/* translators: %d: cart ID. */
											__( '購物車 #%d', 'now-crm' ),
											$cart_id
										)
									);
									?>
								</a>
								<a href="<?php echo esc_url( Menu::page_url( Menu::EMAIL_SLUG, array( 'tab' => self::TAB_LOG, 'cart_id' => $cart_id ) ) ); ?>">
									<?php esc_html_e( '查看同購物車寄送紀錄', 'now-crm' ); ?>
								</a>
							<?php elseif ( (int) ( $payload['automation_id'] ?? 0 ) > 0 ) : ?>
								<a href="<?php echo esc_url( Menu::page_url( Menu::AUTOMATION_SLUG, array( 'id' => (int) $payload['automation_id'] ) ) ); ?>">
									<?php
									printf(
										/* translators: %d: automation ID. */
										esc_html__( '自動化 #%d', 'now-crm' ),
										(int) $payload['automation_id']
									);
									?>
								</a>
							<?php elseif ( (int) ( $payload['sequence_id'] ?? 0 ) > 0 ) : ?>
								<a href="<?php echo esc_url( Menu::page_url( Menu::SEQUENCE_SLUG, array( 'id' => (int) $payload['sequence_id'] ) ) ); ?>">
									<?php
									printf(
										/* translators: %d: sequence ID. */
										esc_html__( '序列 #%d', 'now-crm' ),
										(int) $payload['sequence_id']
									);
									?>
								</a>
							<?php else : ?>
								<?php echo esc_html( self::source_label( $job ) ); ?>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( '建立時間', 'now-crm' ); ?></th>
						<td><?php echo esc_html( Menu::format_datetime( (string) $job['created_at'] ) ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( '更新時間', 'now-crm' ); ?></th>
						<td><?php echo esc_html( Menu::format_datetime( (string) $job['updated_at'] ) ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( '排定寄送', 'now-crm' ); ?></th>
						<td><?php echo esc_html( Menu::format_datetime( (string) $job['available_at'] ) ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( '領取時間', 'now-crm' ); ?></th>
						<td><?php echo esc_html( Menu::format_datetime( (string) ( $job['claimed_at'] ?? '' ) ) ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( '佇列狀態', 'now-crm' ); ?></th>
						<td><?php echo esc_html( ContactHistory::status_label( (string) $job['status'] ) ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( '送出時間（transport 接受）', 'now-crm' ); ?></th>
						<td><?php echo esc_html( Menu::format_datetime( (string) ( $job['accepted_at'] ?? '' ) ) ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( '送達狀態', 'now-crm' ); ?></th>
						<td>
							<?php
							echo esc_html(
								'' !== (string) ( $job['delivery_status'] ?? '' )
									? ContactHistory::delivery_label( (string) $job['delivery_status'] )
									: '—'
							);
							?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( '送達時間', 'now-crm' ); ?></th>
						<td><?php echo esc_html( Menu::format_datetime( (string) ( $job['delivery_at'] ?? '' ) ) ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( '退信／客訴原因', 'now-crm' ); ?></th>
						<td><?php echo esc_html( (string) ( $job['delivery_error'] ?? '' ) ?: '—' ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Message ID', 'now-crm' ); ?></th>
						<td><?php echo esc_html( (string) ( $job['message_id'] ?? '' ) ?: '—' ); ?></td>
					</tr>
				</tbody>
			</table>
		</div>

		<?php if ( '' !== $error ) : ?>
			<div class="nowcrm-panel nowcrm-panel--hint">
				<h2><?php esc_html_e( '技術錯誤', 'now-crm' ); ?></h2>
				<pre><?php echo esc_html( $error ); ?></pre>
				<p class="description"><?php esc_html_e( '這是佇列或 transport 回報的原始文字，僅供排查；不代表已再次嘗試。', 'now-crm' ); ?></p>
			</div>
		<?php endif; ?>

		<div class="nowcrm-panel">
			<h2><?php esc_html_e( '內容預覽', 'now-crm' ); ?></h2>
			<?php if ( $preview ) : ?>
				<p class="description">
					<?php esc_html_e( '唯讀範本預覽：連結與 srcset 已移除，並以 CSP 限制為僅載入 data: 圖片，不注入開信像素、退訂或復原權杖。', 'now-crm' ); ?>
				</p>
				<iframe sandbox srcdoc="<?php echo esc_attr( $preview['html'] ); ?>"
					width="100%" height="480" title="<?php esc_attr_e( 'Email 內容預覽', 'now-crm' ); ?>"></iframe>
			<?php else : ?>
				<div class="notice notice-warning inline">
					<p><?php echo esc_html( self::preview_reason( $job ) ); ?></p>
				</div>
			<?php endif; ?>
		</div>

		<?php if ( self::can_retry( $job ) ) : ?>
			<div class="nowcrm-panel">
				<h2><?php esc_html_e( '重新排入佇列', 'now-crm' ); ?></h2>
				<p class="description">
					<?php esc_html_e( '只會把這筆失敗、結果不明或已阻擋的 Job 重設為待寄；寄送當下仍會重新檢查寄送開關、聯絡人狀態與抑制名單。', 'now-crm' ); ?>
				</p>
				<?php
				self::render_retry_form( $job, self::return_args( $filters, $paged, true ) );
				?>
			</div>
		<?php endif; ?>
		<?php
	}

	/**
	 * Render a preview only when the snapshot cannot carry a signed link.
	 *
	 * Cart reminders resolve the signed recovery URL before enqueue, and
	 * consent mail resolves a signed confirmation URL, so those snapshots are
	 * never rendered. Other kinds render non-clickable HTML under a strict CSP.
	 *
	 * @param array<string, mixed> $job Job row.
	 * @return array{html: string}|null
	 */
	private static function preview( array $job ): ?array {
		$kind = (string) ( $job['kind'] ?? '' );

		if ( ! in_array( $kind, array( 'campaign', 'test', 'custom', 'automation', 'sequence' ), true ) ) {
			return null;
		}

		$template = ContactHistory::template_for_job( $job );

		if ( ! $template ) {
			return null;
		}

		$contact_id = (int) ( $job['contact_id'] ?? 0 );
		$contact    = $contact_id > 0 ? ( new ContactRepository() )->get( $contact_id ) : null;
		$payload    = is_array( $job['payload'] ?? null ) ? $job['payload'] : array();

		$context = array(
			'first_name'      => $contact ? (string) ( $contact['first_name'] ?? '' ) : (string) ( $job['recipient_name'] ?? '' ),
			'last_name'       => $contact ? (string) ( $contact['last_name'] ?? '' ) : '',
			'email'           => $contact ? (string) ( $contact['email'] ?? '' ) : (string) ( $job['recipient'] ?? '' ),
			'site_name'       => wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ),
			'site_url'        => home_url( '/' ),
			'order_id'        => (string) ( $payload['order_id'] ?? '' ),
			'order_total'     => (string) ( $payload['order_total'] ?? '' ),
			'cart_url'        => function_exists( 'wc_get_cart_url' ) ? wc_get_cart_url() : home_url( '/' ),
			'recovery_url'    => '',
			'unsubscribe_url' => '',
		);

		$rendered = ( new EmailRenderer() )->render( $template, $context );

		return array( 'html' => self::isolate_preview( $rendered['html'] ) );
	}

	/**
	 * Why a preview is absent. Cart and consent are deliberate, not missing.
	 *
	 * @param array<string, mixed> $job Job row.
	 */
	private static function preview_reason( array $job ): string {
		$kind = (string) ( $job['kind'] ?? '' );

		if ( 'cart' === $kind ) {
			return __( '購物車提醒的快照包含已簽署的復原連結，因此不顯示內容預覽。', 'now-crm' );
		}

		if ( 'consent' === $kind ) {
			return __( '訂閱確認信的範本包含已簽署的確認連結，因此不顯示內容預覽。', 'now-crm' );
		}

		return __( '找不到這封 Email 的範本快照（例如電子報或範本已刪除）。不會以目前草稿或替代內容顯示預覽。', 'now-crm' );
	}

	/**
	 * Make rendered HTML non-navigable and non-prefetching.
	 *
	 * The CSP meta is inserted first in <head>. href/srcset are removed, and
	 * any remaining remote asset is blocked by img-src data:.
	 */
	private static function isolate_preview( string $html ): string {
		$csp = '<meta http-equiv="Content-Security-Policy" content="default-src \'none\'; img-src data:; style-src \'unsafe-inline\'; base-uri \'none\'; form-action \'none\'">';

		$html = (string) preg_replace( '#\s(?:xlink:)?href\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+)#i', '', $html );
		$html = (string) preg_replace( '#\ssrcset\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+)#i', '', $html );
		$html = (string) preg_replace( '#<base\b[^>]*>#i', '', $html );

		$replaced = 0;
		$html     = (string) preg_replace( '#<head\b[^>]*>#i', '$0' . $csp, $html, 1, $replaced );

		if ( ! $replaced ) {
			$html = $csp . $html;
		}

		return $html;
	}

	/**
	 * Existing nonce action, submitted as POST.
	 *
	 * @param array<string, mixed> $job         Job row.
	 * @param array<string, mixed> $return_args Validated return context for the handler.
	 */
	private static function render_retry_form( array $job, array $return_args = array() ): void {
		if ( ! self::can_retry( $job ) ) {
			return;
		}

		$job_id = (int) $job['id'];
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="nowcrm_retry_job" />
			<input type="hidden" name="job" value="<?php echo esc_attr( (string) $job_id ); ?>" />
			<?php foreach ( $return_args as $name => $value ) : ?>
				<input type="hidden" name="<?php echo esc_attr( (string) $name ); ?>" value="<?php echo esc_attr( (string) $value ); ?>" />
			<?php endforeach; ?>
			<?php wp_nonce_field( 'nowcrm_retry_job_' . $job_id ); ?>
			<button type="submit" class="button button-small"><?php esc_html_e( '重試', 'now-crm' ); ?></button>
		</form>
		<?php
	}

	/**
	 * Compact source text for the list.
	 *
	 * @param array<string, mixed> $job Job row.
	 */
	private static function source_label( array $job ): string {
		$payload = is_array( $job['payload'] ?? null ) ? $job['payload'] : array();
		$kind    = (string) $job['kind'];

		if ( (int) ( $job['campaign_id'] ?? 0 ) > 0 ) {
			/* translators: %d: campaign ID. */
			return sprintf( __( '電子報 #%d', 'now-crm' ), (int) $job['campaign_id'] );
		}

		if ( (int) ( $job['cart_id'] ?? 0 ) > 0 ) {
			/* translators: %d: cart ID. */
			return sprintf( __( '購物車 #%d', 'now-crm' ), (int) $job['cart_id'] );
		}

		if ( (int) ( $payload['automation_id'] ?? 0 ) > 0 ) {
			/* translators: %d: automation ID. */
			return sprintf( __( '自動化 #%d', 'now-crm' ), (int) $payload['automation_id'] );
		}

		if ( (int) ( $payload['sequence_id'] ?? 0 ) > 0 ) {
			/* translators: %d: sequence ID. */
			return sprintf( __( '序列 #%d', 'now-crm' ), (int) $payload['sequence_id'] );
		}

		return 'consent' === $kind ? __( '訂閱確認', 'now-crm' ) : '—';
	}

	/**
	 * Suppression summary. View-only; removing entries stays in its own flow.
	 */
	private static function render_suppression(): void {
		$counts = ( new SuppressionRepository() )->counts();
		$reasons = array(
			'unsubscribed' => __( '已退訂', 'now-crm' ),
			'bounced'      => __( '硬退信', 'now-crm' ),
			'complained'   => __( '投訴', 'now-crm' ),
			'manual'       => __( '手動加入', 'now-crm' ),
		);
		?>
		<div class="nowcrm-panel">
			<h2><?php esc_html_e( '抑制名單', 'now-crm' ); ?></h2>
			<ul class="nowcrm-meta">
				<?php foreach ( $reasons as $reason => $label ) : ?>
					<li>
						<span><?php echo esc_html( $label ); ?></span>
						<strong><?php echo esc_html( number_format_i18n( (int) ( $counts[ $reason ] ?? 0 ) ) ); ?></strong>
					</li>
				<?php endforeach; ?>
			</ul>
			<p class="description">
				<?php esc_html_e( '退訂、硬退信與投訴會立即阻擋佇列中的行銷郵件；transient bounce 不會永久封鎖。這裡只顯示計數，不提供刪除或修改。', 'now-crm' ); ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Settings-only worker diagnostics. The worker action itself re-checks the
	 * same capability and nonce, so this page never grants it. Rendering only
	 * reads health and counters; it never runs or schedules the worker.
	 */
	private static function render_diagnostics(): void {
		$repo   = new EmailJobRepository();
		$stats  = $repo->global_stats();
		$health = WorkerHealth::status();
		$ses    = Processor::diagnostics();

		$sending   = Settings::bool( 'email_sending_enabled' );
		$scheduler = array(
			'action-scheduler' => __( 'Action Scheduler', 'now-crm' ),
			'wp-cron'          => __( 'WP-Cron', 'now-crm' ),
			'none'             => __( '找不到排程', 'now-crm' ),
		);
		$scheduler_label = $scheduler[ $health['scheduler'] ] ?? $scheduler['none'];

		if ( null === $health['next_scheduled_at'] ) {
			$schedule_state = __( '未排程', 'now-crm' );
		} elseif ( $health['overdue'] ) {
			$schedule_state = __( '已逾時', 'now-crm' );
		} else {
			$schedule_state = __( '已排程', 'now-crm' );
		}

		$last_received = '';
		if ( is_string( $ses['last_received_at'] ) && '' !== $ses['last_received_at'] ) {
			$received_at = strtotime( $ses['last_received_at'] . ' UTC' );

			if ( false !== $received_at ) {
				$last_received = self::format_time( (int) $received_at );
			}
		}

		$ses_configured = '' !== trim( Settings::string( 'ses_topic_arn' ) );
		?>
		<div class="nowcrm-panel">
			<h2><?php esc_html_e( '佇列與 worker 狀態', 'now-crm' ); ?></h2>
			<ul class="nowcrm-meta">
				<li>
					<span><?php esc_html_e( '實際寄送開關', 'now-crm' ); ?></span>
					<strong><?php echo $sending ? esc_html__( '開啟', 'now-crm' ) : esc_html__( '關閉', 'now-crm' ); ?></strong>
				</li>
				<li>
					<span><?php esc_html_e( '上次成功執行', 'now-crm' ); ?></span>
					<strong><?php echo esc_html( null === $health['last_success_at'] ? __( '尚未記錄', 'now-crm' ) : self::format_time( (int) $health['last_success_at'] ) ); ?></strong>
				</li>
				<li>
					<span><?php esc_html_e( '下次排程', 'now-crm' ); ?></span>
					<strong><?php echo esc_html( null === $health['next_scheduled_at'] ? __( '未排程', 'now-crm' ) : self::format_time( (int) $health['next_scheduled_at'] ) ); ?></strong>
				</li>
				<li>
					<span><?php esc_html_e( '排程來源', 'now-crm' ); ?></span>
					<strong><?php echo esc_html( $scheduler_label ); ?></strong>
				</li>
				<li>
					<span><?php esc_html_e( '排程狀態', 'now-crm' ); ?></span>
					<strong><?php echo esc_html( $schedule_state ); ?></strong>
				</li>
				<li>
					<span><?php esc_html_e( '待寄送', 'now-crm' ); ?></span>
					<strong><?php echo esc_html( number_format_i18n( (int) ( $stats[ EmailJobRepository::STATUS_QUEUED ] ?? 0 ) ) ); ?></strong>
				</li>
				<li>
					<span><?php esc_html_e( '到期可處理', 'now-crm' ); ?></span>
					<strong><?php echo esc_html( number_format_i18n( $health['due'] ) ); ?></strong>
				</li>
				<li>
					<span><?php esc_html_e( '待人工查核（失敗／未知／抑制）', 'now-crm' ); ?></span>
					<strong><?php echo esc_html( number_format_i18n( $health['needs_review'] ) ); ?></strong>
				</li>
				<li>
					<span><?php esc_html_e( '佇列總數', 'now-crm' ); ?></span>
					<strong><?php echo esc_html( number_format_i18n( $health['total'] ) ); ?></strong>
				</li>
				<li>
					<span><?php esc_html_e( '每批筆數', 'now-crm' ); ?></span>
					<strong><?php echo esc_html( (string) Settings::int( 'email_batch_size' ) ); ?></strong>
				</li>
			</ul>

			<?php if ( ! $sending ) : ?>
				<p class="description"><?php esc_html_e( '實際寄送關閉時，worker 不處理佇列，也不會更新上次成功時間；排程本身仍可能繼續觸發。', 'now-crm' ); ?></p>
			<?php endif; ?>

			<?php if ( null === $health['next_scheduled_at'] ) : ?>
				<p class="description">
					<strong><?php esc_html_e( '找不到已排程的 worker。', 'now-crm' ); ?></strong>
					<?php esc_html_e( '重新載入任一 NOW 頁面會嘗試排程；若仍沒有排程，請檢查 Action Scheduler 或 WP-Cron 的設定與失敗紀錄。本頁不會自動補排程。', 'now-crm' ); ?>
				</p>
			<?php elseif ( $health['overdue'] ) : ?>
				<p class="description">
					<strong><?php esc_html_e( '下次排程已逾時超過 5 分鐘。', 'now-crm' ); ?></strong>
					<?php esc_html_e( 'worker 可能沒有執行；請檢查 Action Scheduler、WP-Cron 或伺服器排程與失敗紀錄。手動處理只執行一批，不會修正排程。', 'now-crm' ); ?>
				</p>
			<?php endif; ?>

			<?php if ( $health['wp_cron_disabled'] && 'wp-cron' === $health['scheduler'] ) : ?>
				<p class="description">
					<strong><?php esc_html_e( 'DISABLE_WP_CRON 已定義。', 'now-crm' ); ?></strong>
					<?php esc_html_e( 'WP-Cron 不會由頁面請求觸發，必須由伺服器排程呼叫 wp-cron.php。本頁無法確認伺服器排程是否存在，也不代表作業系統排程已設定。', 'now-crm' ); ?>
				</p>
			<?php endif; ?>

			<?php if ( $sending && null === $health['last_success_at'] ) : ?>
				<p class="description">
					<strong><?php esc_html_e( '寄送已開啟，但尚未記錄成功執行。', 'now-crm' ); ?></strong>
					<?php esc_html_e( '若寄送期間沒有成功執行，佇列不會前進；請確認排程來源正在呼叫 worker。', 'now-crm' ); ?>
				</p>
			<?php endif; ?>

			<p class="description">
				<?php esc_html_e( '此頁需要設定權限。手動執行只處理一批佇列，仍受寄送開關、聯絡人狀態與抑制名單限制；待人工查核為失敗、未知與已抑制的總數，只供查核，不代表每筆都能重試（有回條或購物車 unknown 會阻擋重試）。', 'now-crm' ); ?>
			</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="nowcrm_run_queue" />
				<?php wp_nonce_field( 'nowcrm_run_queue' ); ?>
				<button type="submit" class="button button-primary"><?php esc_html_e( '處理一批佇列', 'now-crm' ); ?></button>
				<a class="button" href="<?php echo esc_url( Menu::page_url( Menu::SETTINGS_SLUG, array( 'tab' => 'email' ) ) ); ?>"><?php esc_html_e( '前往寄送設定', 'now-crm' ); ?></a>
			</form>
		</div>

		<div class="nowcrm-panel">
			<h2><?php esc_html_e( 'SES 通知', 'now-crm' ); ?></h2>
			<ul class="nowcrm-meta">
				<li>
					<span><?php esc_html_e( '已收到', 'now-crm' ); ?></span>
					<strong><?php echo esc_html( number_format_i18n( (int) $ses['received'] ) ); ?></strong>
				</li>
				<li>
					<span><?php esc_html_e( '已對應 NOW 寄送', 'now-crm' ); ?></span>
					<strong><?php echo esc_html( number_format_i18n( (int) $ses['matched'] ) ); ?></strong>
				</li>
				<li>
					<span><?php esc_html_e( '未對應', 'now-crm' ); ?></span>
					<strong><?php echo esc_html( number_format_i18n( (int) $ses['unmatched'] ) ); ?></strong>
				</li>
				<li>
					<span><?php esc_html_e( '處理失敗', 'now-crm' ); ?></span>
					<strong><?php echo esc_html( number_format_i18n( (int) $ses['failed'] ) ); ?></strong>
				</li>
				<li>
					<span><?php esc_html_e( '已忽略', 'now-crm' ); ?></span>
					<strong><?php echo esc_html( number_format_i18n( (int) $ses['ignored'] ) ); ?></strong>
				</li>
				<li>
					<span><?php esc_html_e( '最後收到', 'now-crm' ); ?></span>
					<strong><?php echo esc_html( '' !== $last_received ? $last_received : __( '尚未收到', 'now-crm' ) ); ?></strong>
				</li>
			</ul>

			<?php if ( ! $ses_configured ) : ?>
				<p class="description">
					<strong><?php esc_html_e( '尚未設定允許的 Topic ARN。', 'now-crm' ); ?></strong>
					<?php esc_html_e( 'SNS 端點會拒絕所有通知；設定 Topic ARN 後才可能收到回條。', 'now-crm' ); ?>
					<a href="<?php echo esc_url( Menu::page_url( Menu::SETTINGS_SLUG, array( 'tab' => 'email' ) ) ); ?>"><?php esc_html_e( '前往寄送設定', 'now-crm' ); ?></a>
				</p>
			<?php elseif ( 0 === (int) $ses['received'] ) : ?>
				<p class="description">
					<strong><?php esc_html_e( '已設定 Topic ARN，但尚未收到事件。', 'now-crm' ); ?></strong>
					<?php esc_html_e( '請確認 SNS 訂閱已確認、Configuration Set 掛有 Delivery／Bounce／Complaint 事件目的地，且實際寄送使用該 Configuration Set。', 'now-crm' ); ?>
				</p>
			<?php endif; ?>

			<?php if ( (int) $ses['received'] > 0 && 0 === (int) $ses['matched'] ) : ?>
				<p class="description">
					<strong><?php esc_html_e( '已收到事件，但沒有對應到 NOW 的寄送 Job。', 'now-crm' ); ?></strong>
					<?php esc_html_e( '未對應的通知不會更新送達狀態或抑制名單；請確認事件來自這個站台的寄送，且收件者與寄送紀錄相符。', 'now-crm' ); ?>
				</p>
			<?php endif; ?>

			<?php if ( (int) $ses['failed'] > 0 ) : ?>
				<p class="description">
					<strong><?php esc_html_e( '有事件處理失敗。', 'now-crm' ); ?></strong>
					<?php esc_html_e( '請檢查伺服器錯誤記錄；本頁不顯示原始錯誤、payload 或收件者資料。', 'now-crm' ); ?>
				</p>
			<?php endif; ?>

			<?php if ( 'capture' === Settings::string( 'email_transport' ) ) : ?>
				<p class="description">
					<strong><?php esc_html_e( '目前 Local transport 是 Capture。', 'now-crm' ); ?></strong>
					<?php esc_html_e( 'Capture 不寄出，因此不會有真實 SES 回條；這裡的計數只反映曾收到的通知。', 'now-crm' ); ?>
				</p>
			<?php endif; ?>

			<p class="description">
				<?php esc_html_e( '只有簽章通過、Topic 在允許清單內，且能對應到 NOW 寄送 Job 的通知，才會更新該筆送達狀態；抑制名單只由其中已對應的硬退信與投訴更新。沒有回條不代表已送達，「已送出」只代表交給站台寄信程式。', 'now-crm' ); ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Format a Unix timestamp in the WordPress timezone.
	 */
	private static function format_time( int $timestamp ): string {
		return wp_date( 'Y-m-d H:i:s', $timestamp ) . '（' . wp_timezone_string() . '）';
	}

	/**
	 * Pre-encode values that add_query_arg() adds without encoding.
	 *
	 * @param array<string, mixed> $filters Validated filters.
	 * @return array<string, mixed>
	 */
	private static function encode_filters( array $filters ): array {
		if ( isset( $filters['email'] ) ) {
			$filters['email'] = rawurlencode( (string) $filters['email'] );
		}

		return $filters;
	}

	/**
	 * List URL with only validated filters and page.
	 *
	 * @param array<string, mixed> $filters Validated filters.
	 * @param int                  $paged   Current validated list page.
	 */
	private static function list_url( array $filters, int $paged = 1 ): string {
		$args = array_merge( array( 'tab' => self::TAB_LOG ), self::encode_filters( $filters ) );

		if ( $paged > 1 ) {
			$args['paged'] = $paged;
		}

		return Menu::page_url( Menu::EMAIL_SLUG, $args );
	}

	/**
	 * Detail URL preserving only validated filters and page.
	 *
	 * @param int                  $job_id  Job ID.
	 * @param array<string, mixed> $filters Validated filters.
	 * @param int                  $paged   Current validated list page.
	 */
	private static function detail_url( int $job_id, array $filters, int $paged = 1 ): string {
		$args = array_merge( array( 'tab' => self::TAB_LOG ), self::encode_filters( $filters ), array( 'job' => $job_id ) );

		if ( $paged > 1 ) {
			$args['paged'] = $paged;
		}

		return Menu::page_url( Menu::EMAIL_SLUG, $args );
	}

	/**
	 * Paged list URL preserving only validated filters.
	 *
	 * @param int                  $page    Page number.
	 * @param array<string, mixed> $filters Validated filters.
	 */
	private static function paged_url( int $page, array $filters ): string {
		return Menu::page_url(
			Menu::EMAIL_SLUG,
			array_merge( array( 'tab' => self::TAB_LOG ), self::encode_filters( $filters ), array( 'paged' => max( 1, $page ) ) )
		);
	}
}
