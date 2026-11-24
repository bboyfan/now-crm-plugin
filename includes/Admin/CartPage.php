<?php
/**
 * Abandoned-cart screen: captured carts, one cart's attribution detail, and
 * the settings that drive the worker.
 *
 * Reminder content lives in the owning automation Email step; this screen
 * only manages capture/timing and points to that automation. The list, the
 * detail, and the settings form are three states of the same visible slug.
 *
 * @package NowCRM
 */

namespace NowCRM\Admin;

use NowCRM\Automation\AutomationRepository;
use NowCRM\Automation\RunRepository;
use NowCRM\Automation\TriggerRegistry;
use NowCRM\Cart\Automation as CartAutomation;
use NowCRM\Cart\CartRepository;
use NowCRM\Contacts\ContactRepository;
use NowCRM\Support\Capabilities;
use NowCRM\Support\Settings;

defined( 'ABSPATH' ) || exit;

final class CartPage {

	public const TAB_LIST     = 'list';
	public const TAB_SETTINGS = 'settings';

	/**
	 * Rows per page. The list is a browsing surface, not an export.
	 */
	public const PER_PAGE = 10;

	/**
	 * Status labels used by the table, the funnel, and the filter.
	 *
	 * @return array<string, string>
	 */
	public static function status_labels(): array {
		return array(
			CartRepository::STATUS_ACTIVE        => __( '捕捉中', 'now-crm' ),
			CartRepository::STATUS_ABANDONED     => __( '已放棄', 'now-crm' ),
			CartRepository::STATUS_ORDER_PENDING => __( '待付款', 'now-crm' ),
			CartRepository::STATUS_LOST          => __( '已流失', 'now-crm' ),
			CartRepository::STATUS_COMPLETED     => __( '已完成', 'now-crm' ),
			CartRepository::STATUS_RECOVERED     => __( '已挽回', 'now-crm' ),
		);
	}

	/**
	 * Return a supported screen state, or the default list.
	 */
	public static function normalize_tab( string $tab ): string {
		$tab = sanitize_key( $tab );

		return self::TAB_SETTINGS === $tab ? self::TAB_SETTINGS : self::TAB_LIST;
	}

	/**
	 * Render the list, one cart detail, or the settings form.
	 */
	public static function render(): void {
		if ( ! Capabilities::can_manage_contacts() ) {
			wp_die( esc_html__( '沒有權限。', 'now-crm' ), '', array( 'response' => 403 ) );
		}

		$tab = self::normalize_tab( Menu::query( 'tab' ) );

		if ( self::TAB_SETTINGS === $tab ) {
			if ( ! Capabilities::can_manage_settings() ) {
				wp_die( esc_html__( '您沒有權限變更購物車設定。', 'now-crm' ), '', array( 'response' => 403 ) );
			}

			self::render_settings();
			return;
		}

		$cart_id = absint( Menu::query( 'cart' ) );

		if ( $cart_id > 0 ) {
			self::render_detail( $cart_id );
			return;
		}

		self::render_list();
	}

	/**
	 * Captured carts with real status counts, filters, and paging.
	 */
	private static function render_list(): void {
		$repo  = new CartRepository();
		$stats = $repo->stats();

		$status_raw = sanitize_key( Menu::query( 'status', 'all' ) );
		$status_raw = '' === $status_raw ? 'all' : $status_raw;
		$email_raw  = trim( sanitize_text_field( Menu::query( 'email' ) ) );
		$paged      = max( 1, absint( Menu::query( 'paged', '1' ) ) );
		$errors     = array();

		if ( 'all' !== $status_raw && ! array_key_exists( $status_raw, self::status_labels() ) ) {
			$errors[] = __( '狀態篩選不正確，因此未顯示資料。', 'now-crm' );
		}

		$email = '';

		if ( '' !== $email_raw ) {
			if ( is_email( $email_raw ) ) {
				$email = $email_raw;
			} else {
				$errors[] = __( 'Email 格式不正確，因此未顯示資料。', 'now-crm' );
			}
		}

		$status        = 'all' === $status_raw || array_key_exists( $status_raw, self::status_labels() )
			? $status_raw
			: 'all';
		$status_invalid = 'all' !== $status_raw && ! array_key_exists( $status_raw, self::status_labels() );
		$email_invalid  = '' !== $email_raw && '' === $email;
		$blocked        = (bool) $errors;
		$filters = array();

		if ( 'all' !== $status ) {
			$filters['status'] = $status;
		}

		if ( '' !== $email ) {
			$filters['email'] = $email;
		}

		$result = $blocked
			? array(
				'items' => array(),
				'total' => 0,
			)
			: $repo->search(
				array_merge(
					$filters,
					array(
						'limit'  => self::PER_PAGE,
						'offset' => ( $paged - 1 ) * self::PER_PAGE,
					)
				)
			);

		$total  = (int) $result['total'];
		$items  = (array) $result['items'];
		$pages  = max( 1, (int) ceil( $total / self::PER_PAGE ) );
		$first  = $total > 0 ? ( $paged - 1 ) * self::PER_PAGE + 1 : 0;
		$last   = $first + count( $items ) - 1;
		$view   = $filters;

		if ( $paged > 1 ) {
			$view['paged'] = $paged;
		}

		$settings_url = Menu::page_url( Menu::CART_SLUG, array( 'tab' => self::TAB_SETTINGS ) );
		$capture_on   = Settings::bool( 'abandoned_cart_enabled' );
		$filtered     = '' !== $email || 'all' !== $status;
		?>
		<div class="wrap nowcrm-wrap nowcrm-cart nowcrm-cart--list">
			<?php self::render_page_tabs( self::TAB_LIST ); ?>

			<div class="nowcrm-contacts__head nowcrm-cart__head">
				<div class="nowcrm-cart__heading">
					<h1>
						<?php esc_html_e( '購物車放棄', 'now-crm' ); ?>
						<span class="nowcrm-contacts__count">
							<?php
							if ( $filtered ) {
								esc_html_e( '符合篩選：', 'now-crm' );
							}

							printf(
								/* translators: %s: number of matching carts. */
								esc_html__( '共 %s 筆', 'now-crm' ),
								esc_html( number_format_i18n( $total ) )
							);
							?>
						</span>
					</h1>
					<p class="nowcrm-cart__scope">
						<?php echo esc_html( $filtered ? __( '符合目前篩選條件', 'now-crm' ) : __( '全部購物車', 'now-crm' ) ); ?>
					</p>
				</div>
				<span class="nowcrm-chip">
					<?php echo esc_html( $capture_on ? __( '捕捉已啟用', 'now-crm' ) : __( '捕捉未啟用', 'now-crm' ) ); ?>
				</span>
			</div>

			<?php Actions::render_notice(); ?>

			<?php if ( ! $capture_on ) : ?>
				<div class="notice notice-warning">
					<p>
						<?php esc_html_e( '購物車捕捉目前未啟用，新的結帳活動不會寫入。', 'now-crm' ); ?>
						<?php if ( Capabilities::can_manage_settings() ) : ?>
							<a href="<?php echo esc_url( $settings_url ); ?>"><?php esc_html_e( '前往設定開啟', 'now-crm' ); ?></a>
						<?php endif; ?>
					</p>
					<p><?php esc_html_e( '已排入佇列的購物車提醒不會寄出，重新啟用後也不會自動補寄。', 'now-crm' ); ?></p>
				</div>
			<?php endif; ?>

			<section class="nowcrm-panel nowcrm-cart-snapshot" aria-labelledby="nowcrm-cart-snapshot-title">
				<h2 id="nowcrm-cart-snapshot-title"><?php esc_html_e( '目前狀態快照', 'now-crm' ); ?></h2>
				<p class="description nowcrm-cart-snapshot__note">
					<?php esc_html_e( '全站累計，不受 Email 篩選影響。點選狀態會保留目前的 Email 篩選，並回到第 1 頁。', 'now-crm' ); ?>
				</p>
				<ul class="nowcrm-cart-snapshot__grid">
					<li>
						<a class="nowcrm-cart-snapshot__cell"
							href="<?php echo esc_url( self::filter_url( $filters, 'all' ) ); ?>"
							<?php echo 'all' === $status ? 'aria-current="page"' : ''; ?>
							aria-label="<?php echo esc_attr( sprintf( /* translators: 1: status label, 2: number of carts. */ __( '%1$s，%2$s 筆', 'now-crm' ), __( '全部', 'now-crm' ), number_format_i18n( (int) ( $stats['total'] ?? 0 ) ) ) ); ?>">
							<span class="nowcrm-cart-snapshot__label"><?php esc_html_e( '全部', 'now-crm' ); ?></span>
							<strong class="nowcrm-cart-snapshot__count"><?php echo esc_html( number_format_i18n( (int) ( $stats['total'] ?? 0 ) ) ); ?></strong>
						</a>
					</li>
					<?php foreach ( self::status_labels() as $key => $label ) : ?>
						<li>
							<a class="nowcrm-cart-snapshot__cell"
								href="<?php echo esc_url( self::filter_url( $filters, $key ) ); ?>"
								<?php echo $key === $status ? 'aria-current="page"' : ''; ?>
								aria-label="<?php echo esc_attr( sprintf( /* translators: 1: status label, 2: number of carts. */ __( '%1$s，%2$s 筆', 'now-crm' ), $label, number_format_i18n( (int) ( $stats[ $key ] ?? 0 ) ) ) ); ?>">
								<span class="nowcrm-cart-snapshot__label"><?php echo esc_html( $label ); ?></span>
								<strong class="nowcrm-cart-snapshot__count"><?php echo esc_html( number_format_i18n( (int) ( $stats[ $key ] ?? 0 ) ) ); ?></strong>
							</a>
						</li>
					<?php endforeach; ?>
				</ul>
			</section>

			<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="nowcrm-contacts-filters nowcrm-cart-filters">
				<input type="hidden" name="page" value="<?php echo esc_attr( Menu::CART_SLUG ); ?>">

				<div class="nowcrm-cart-field">
					<label for="nowcrm-cart-status"><?php esc_html_e( '狀態', 'now-crm' ); ?></label>
					<select id="nowcrm-cart-status" name="status" <?php echo $status_invalid ? 'aria-invalid="true"' : ''; ?>>
						<option value="all" <?php selected( $status, 'all' ); ?>><?php esc_html_e( '全部狀態', 'now-crm' ); ?></option>
						<?php foreach ( self::status_labels() as $key => $label ) : ?>
							<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $status, $key ); ?>>
								<?php echo esc_html( $label ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</div>

				<div class="nowcrm-cart-field">
					<label for="nowcrm-cart-email"><?php esc_html_e( 'Email（完整比對）', 'now-crm' ); ?></label>
					<input type="email" id="nowcrm-cart-email" name="email"
						value="<?php echo esc_attr( $email_raw ); ?>"
						placeholder="<?php esc_attr_e( '完整 Email', 'now-crm' ); ?>"
						aria-describedby="nowcrm-cart-email-hint"
						<?php echo $email_invalid ? 'aria-invalid="true"' : ''; ?>>
					<p class="nowcrm-cart-field__hint" id="nowcrm-cart-email-hint">
						<?php esc_html_e( '只比對完全相同的 Email，不會部分比對。', 'now-crm' ); ?>
					</p>
				</div>

				<div class="nowcrm-cart-filter-actions">
					<button type="submit" class="button button-primary"><?php esc_html_e( '篩選', 'now-crm' ); ?></button>
					<a class="button nowcrm-filter-clear" href="<?php echo esc_url( self::list_url() ); ?>">
						<?php esc_html_e( '清除', 'now-crm' ); ?>
					</a>
				</div>
			</form>

			<?php if ( $blocked ) : ?>
				<div class="notice notice-error" role="alert">
					<p><?php echo esc_html( implode( ' ', $errors ) ); ?></p>
				</div>
			<?php endif; ?>

			<div class="nowcrm-contacts-surface nowcrm-cart__surface">
				<div class="nowcrm-cart-table-scroll" role="region" tabindex="0" aria-label="<?php esc_attr_e( '購物車資料表，可水平捲動', 'now-crm' ); ?>">
					<table class="nowcrm-contacts-table nowcrm-table">
						<thead>
							<tr>
								<th scope="col"><?php esc_html_e( '購物車', 'now-crm' ); ?></th>
								<th scope="col"><?php esc_html_e( '狀態', 'now-crm' ); ?></th>
								<th scope="col" class="nowcrm-cart-col-email"><?php esc_html_e( 'Email', 'now-crm' ); ?></th>
								<th scope="col" class="nowcrm-col-number"><?php esc_html_e( '商品', 'now-crm' ); ?></th>
								<th scope="col" class="nowcrm-col-number"><?php esc_html_e( '金額', 'now-crm' ); ?></th>
								<th scope="col" class="nowcrm-col-number"><?php esc_html_e( '提醒', 'now-crm' ); ?></th>
								<th scope="col"><?php esc_html_e( '最後活動', 'now-crm' ); ?></th>
								<th scope="col"><?php esc_html_e( '結果', 'now-crm' ); ?></th>
								<th scope="col" class="nowcrm-col-actions"><?php esc_html_e( '操作', 'now-crm' ); ?></th>
							</tr>
						</thead>
						<tbody>
						<?php if ( ! $items ) : ?>
							<tr>
								<td colspan="9">
									<div class="nowcrm-empty">
										<p><?php echo esc_html( self::empty_message( $blocked, $filtered, $paged, $total ) ); ?></p>
										<p class="nowcrm-empty__actions">
											<?php if ( $paged > 1 && $total > 0 ) : ?>
												<a class="button" href="<?php echo esc_url( self::list_url( $filters ) ); ?>">
													<?php esc_html_e( '回到第一頁', 'now-crm' ); ?>
												</a>
												<?php if ( $filtered ) : ?>
													<a class="button" href="<?php echo esc_url( self::list_url() ); ?>">
														<?php esc_html_e( '清除篩選', 'now-crm' ); ?>
													</a>
												<?php endif; ?>
											<?php elseif ( $blocked || $filtered ) : ?>
												<a class="button" href="<?php echo esc_url( self::list_url() ); ?>">
													<?php esc_html_e( '清除篩選', 'now-crm' ); ?>
												</a>
											<?php elseif ( ! $capture_on && Capabilities::can_manage_settings() ) : ?>
												<a class="button" href="<?php echo esc_url( $settings_url ); ?>">
													<?php esc_html_e( '前往設定開啟捕捉', 'now-crm' ); ?>
												</a>
											<?php endif; ?>
										</p>
									</div>
								</td>
							</tr>
						<?php endif; ?>
					<?php foreach ( $items as $cart ) : ?>
						<?php
						$cart_id    = (int) $cart['id'];
						$detail_url = self::detail_url( $cart_id, $view );
						$email_url  = self::list_url( array_merge( $filters, array( 'email' => (string) $cart['email'] ) ) );
						$reminders  = (int) $cart['reminders_sent'];
						?>
						<tr>
							<td>
								<a href="<?php echo esc_url( $detail_url ); ?>">#<?php echo esc_html( (string) $cart_id ); ?></a>
							</td>
							<td><?php echo self::status_badge( (string) $cart['status'] ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside. ?></td>
							<td class="nowcrm-cart-email">
								<a href="<?php echo esc_url( $email_url ); ?>"><?php echo esc_html( (string) $cart['email'] ); ?></a>
							</td>
							<td class="nowcrm-col-number"><?php echo esc_html( (string) (int) $cart['item_count'] ); ?></td>
							<td class="nowcrm-col-number"><?php echo esc_html( Menu::format_amount( (float) $cart['total'], (string) $cart['currency'] ) ); ?></td>
							<td class="nowcrm-col-number">
								<?php
								if ( $reminders > 0 ) {
									printf(
										/* translators: %s: number of reminder emails sent. */
										esc_html__( '%s 封', 'now-crm' ),
										esc_html( number_format_i18n( $reminders ) )
									);
								} else {
									echo '—';
								}
								?>
							</td>
							<td><?php echo esc_html( Menu::format_datetime( (string) $cart['last_activity_at'] ) ); ?></td>
							<td><?php echo esc_html( self::result_text( $cart ) ); ?></td>
							<td class="nowcrm-col-actions">
								<a class="button button-small" href="<?php echo esc_url( $detail_url ); ?>">
									<?php esc_html_e( '查看', 'now-crm' ); ?>
								</a>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				</div>

				<?php if ( $total > 0 ) : ?>
					<div class="nowcrm-contacts-footer">
						<p class="nowcrm-contacts-footer__count">
							<?php
							if ( $items ) {
								printf(
									/* translators: 1: first row number, 2: last row number, 3: total matches. */
									esc_html__( '顯示 %1$s–%2$s，共 %3$s 筆', 'now-crm' ),
									esc_html( number_format_i18n( $first ) ),
									esc_html( number_format_i18n( $last ) ),
									esc_html( number_format_i18n( $total ) )
								);
							} else {
								printf(
									/* translators: 1: current page, 2: total matches. */
									esc_html__( '第 %1$s 頁沒有資料，共 %2$s 筆', 'now-crm' ),
									esc_html( number_format_i18n( $paged ) ),
									esc_html( number_format_i18n( $total ) )
								);
							}
							?>
						</p>

						<?php if ( $pages > 1 ) : ?>
							<nav class="nowcrm-pagination" aria-label="<?php esc_attr_e( '購物車分頁', 'now-crm' ); ?>">
								<?php if ( $paged > 1 ) : ?>
									<a class="nowcrm-page-link" href="<?php echo esc_url( self::page_url( $filters, $paged - 1 ) ); ?>"
										aria-label="<?php esc_attr_e( '上一頁', 'now-crm' ); ?>">
										<span aria-hidden="true">‹</span>
									</a>
								<?php endif; ?>

								<?php for ( $number = max( 1, $paged - 2 ); $number <= min( $pages, $paged + 2 ); $number++ ) : ?>
									<a class="nowcrm-page-link <?php echo $number === $paged ? 'is-active' : ''; ?>"
										href="<?php echo esc_url( self::page_url( $filters, $number ) ); ?>"
										<?php echo $number === $paged ? 'aria-current="page"' : ''; ?>>
										<?php echo esc_html( (string) $number ); ?>
									</a>
								<?php endfor; ?>

								<?php if ( $paged < $pages ) : ?>
									<a class="nowcrm-page-link" href="<?php echo esc_url( self::page_url( $filters, $paged + 1 ) ); ?>"
										aria-label="<?php esc_attr_e( '下一頁', 'now-crm' ); ?>">
										<span aria-hidden="true">›</span>
									</a>
								<?php endif; ?>
							</nav>
						<?php endif; ?>
					</div>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * One cart: attribution, captured items, activity, and safe links.
	 */
	private static function render_detail( int $cart_id ): void {
		$repo   = new CartRepository();
		$cart   = $repo->get( $cart_id );
		$filters = self::detail_filters();
		?>
		<div class="wrap nowcrm-wrap nowcrm-cart nowcrm-cart--detail">
			<?php self::render_page_tabs( self::TAB_LIST ); ?>

			<nav class="nowcrm-cart-crumbs" aria-label="<?php esc_attr_e( '購物車位置', 'now-crm' ); ?>">
				<a href="<?php echo esc_url( self::list_url( $filters ) ); ?>"><?php esc_html_e( '← 所有購物車', 'now-crm' ); ?></a>
				<?php if ( $cart ) : ?>
					<span class="nowcrm-cart-crumbs__sep" aria-hidden="true">/</span>
					<span aria-current="page">#<?php echo esc_html( (string) $cart_id ); ?></span>
				<?php endif; ?>
			</nav>

			<?php Actions::render_notice(); ?>

			<?php if ( ! $cart ) : ?>
				<div class="nowcrm-panel">
					<h1><?php esc_html_e( '找不到購物車', 'now-crm' ); ?></h1>
					<p class="description">
						<?php esc_html_e( '此購物車可能已被刪除，或連結中的 ID 不正確。請回到列表重新選擇。', 'now-crm' ); ?>
					</p>
					<p>
						<a class="button" href="<?php echo esc_url( self::list_url( $filters ) ); ?>">
							<?php esc_html_e( '返回購物車列表', 'now-crm' ); ?>
						</a>
					</p>
				</div>
			</div>
				<?php
				return;
			endif;

			$order_id     = (int) $cart['recovered_order_id'];
			$order_url    = self::order_url( $order_id );
			$contact      = ( new ContactRepository() )->find_by_email( (string) $cart['email'] );
			$contact_url  = $contact ? Menu::page_url( Menu::CONTACT_SLUG, array( 'id' => (int) $contact['id'] ) ) : '';
			$log_url      = Menu::page_url(
				Menu::EMAIL_SLUG,
				array(
					'kind'    => 'cart',
					'cart_id' => $cart_id,
				)
			);
			$events       = $repo->events( $cart_id );
			$runs         = self::source_runs( $cart_id );
			$items        = self::snapshot_items( $cart );
			$max          = max( 0, Settings::int( 'cart_max_reminders' ) );
			$status       = (string) $cart['status'];
			$ended        = in_array( $status, array( CartRepository::STATUS_LOST, CartRepository::STATUS_COMPLETED, CartRepository::STATUS_RECOVERED ), true );
			?>
			<header class="nowcrm-contacts__head nowcrm-cart-detail__head">
				<div class="nowcrm-cart-detail__identity">
					<h1 class="nowcrm-cart-detail__email"><?php echo esc_html( (string) $cart['email'] ); ?></h1>
					<p class="description">
						<?php
						printf(
							/* translators: 1: cart ID, 2: captured time, 3: last activity time. */
							esc_html__( '#%1$s · 捕捉於 %2$s · 最後活動 %3$s', 'now-crm' ),
							esc_html( (string) $cart_id ),
							esc_html( Menu::format_datetime( (string) $cart['created_at'] ) ),
							esc_html( Menu::format_datetime( (string) $cart['last_activity_at'] ) )
						);
						?>
					</p>
				</div>
				<div class="nowcrm-chip-list nowcrm-cart-detail__summary">
					<?php echo self::status_badge( $status ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside. ?>
					<span class="nowcrm-chip">
						<?php echo esc_html( Menu::format_amount( (float) $cart['total'], (string) $cart['currency'] ) ); ?>
					</span>
					<span class="nowcrm-chip">
						<?php
						printf(
							/* translators: %s: number of items in the captured cart. */
							esc_html__( '%s 件商品', 'now-crm' ),
							esc_html( number_format_i18n( (int) $cart['item_count'] ) )
						);
						?>
					</span>
				</div>
			</header>

			<div class="nowcrm-grid">
				<div class="nowcrm-grid__main">
					<div class="nowcrm-panel">
						<h2><?php esc_html_e( '訂單歸屬', 'now-crm' ); ?></h2>
						<ul class="nowcrm-meta">
							<li>
								<span><?php esc_html_e( '結果', 'now-crm' ); ?></span>
								<strong><?php echo esc_html( self::outcome_label( $cart ) ); ?></strong>
							</li>
							<li>
								<span><?php esc_html_e( '訂單', 'now-crm' ); ?></span>
								<strong>
									<?php if ( $order_id > 0 && '' !== $order_url ) : ?>
										<a href="<?php echo esc_url( $order_url ); ?>">#<?php echo esc_html( (string) $order_id ); ?></a>
									<?php elseif ( $order_id > 0 ) : ?>
										#<?php echo esc_html( (string) $order_id ); ?>
									<?php else : ?>
										—
									<?php endif; ?>
								</strong>
							</li>
							<li>
								<span><?php esc_html_e( '歸因來源', 'now-crm' ); ?></span>
								<strong><?php echo esc_html( self::source_label( (string) ( $cart['recovery_source'] ?? '' ) ) ); ?></strong>
							</li>
						</ul>
						<p class="description">
							<?php esc_html_e( '自然完成表示顧客沒有透過提醒信連結完成訂單；只有從提醒信連結回到購物車後完成的訂單才計入「郵件連結挽回」。', 'now-crm' ); ?>
						</p>
					</div>

					<div class="nowcrm-panel">
						<h2><?php esc_html_e( '商品快照', 'now-crm' ); ?></h2>
						<?php if ( ! $items ) : ?>
							<p class="description"><?php esc_html_e( '此購物車沒有可安全呈現的商品快照。', 'now-crm' ); ?></p>
						<?php else : ?>
							<table class="nowcrm-contacts-table nowcrm-table nowcrm-cart-items">
								<thead>
									<tr>
										<th scope="col"><?php esc_html_e( '商品', 'now-crm' ); ?></th>
										<th scope="col" class="nowcrm-col-number"><?php esc_html_e( '數量', 'now-crm' ); ?></th>
									</tr>
								</thead>
								<tbody>
									<?php foreach ( $items as $item ) : ?>
										<tr>
											<td>
												<?php echo esc_html( $item['name'] ); ?>
												<?php if ( '' !== $item['meta'] ) : ?>
													<span class="description"><?php echo esc_html( $item['meta'] ); ?></span>
												<?php endif; ?>
											</td>
											<td class="nowcrm-col-number"><?php echo esc_html( $item['quantity'] ); ?></td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
						<?php endif; ?>
					</div>

					<div class="nowcrm-panel" data-testid="cart-runs">
						<h2><?php esc_html_e( '自動化執行', 'now-crm' ); ?></h2>
						<?php if ( ! $runs ) : ?>
							<p class="description">
								<?php esc_html_e( '這台購物車還沒有建立自動化執行。只有以這台購物車為來源的執行會出現在這裡。', 'now-crm' ); ?>
							</p>
						<?php else : ?>
							<div class="nowcrm-cart-table-scroll" role="region" tabindex="0" aria-label="<?php esc_attr_e( '自動化執行資料表，可水平捲動', 'now-crm' ); ?>">
								<table class="nowcrm-contacts-table nowcrm-table nowcrm-cart-items">
									<thead>
										<tr>
											<th scope="col"><?php esc_html_e( '執行', 'now-crm' ); ?></th>
											<th scope="col"><?php esc_html_e( '自動化', 'now-crm' ); ?></th>
											<th scope="col"><?php esc_html_e( '狀態', 'now-crm' ); ?></th>
											<th scope="col"><?php esc_html_e( '開始', 'now-crm' ); ?></th>
										</tr>
									</thead>
									<tbody>
										<?php foreach ( $runs as $run ) : ?>
											<?php
											$run_id      = (int) $run['id'];
											$run_auto_id = (int) $run['automation_id'];
											$run_url     = Menu::page_url(
												Menu::AUTOMATION_RUNS_SLUG,
												array(
													'id'     => $run_auto_id,
													'run_id' => $run_id,
												)
											);
											$auto_url    = Menu::page_url( Menu::AUTOMATION_SLUG, array( 'id' => $run_auto_id ) );
											?>
											<tr>
												<td>
													<a href="<?php echo esc_url( $run_url ); ?>">#<?php echo esc_html( (string) $run_id ); ?></a>
												</td>
												<td>
													<a href="<?php echo esc_url( $auto_url ); ?>">#<?php echo esc_html( (string) $run_auto_id ); ?></a>
												</td>
												<td><?php echo esc_html( AutomationRunsPage::status_label( (string) $run['status'] ) ); ?></td>
												<td><?php echo esc_html( Menu::format_datetime( (string) $run['started_at'] ) ); ?></td>
											</tr>
										<?php endforeach; ?>
									</tbody>
								</table>
							</div>
						<?php endif; ?>
						<p class="description">
							<?php esc_html_e( '執行只連回這台購物車；其他購物車的提醒不會互相覆蓋或取消。', 'now-crm' ); ?>
						</p>
					</div>

					<div class="nowcrm-panel">
						<h2><?php esc_html_e( '活動紀錄', 'now-crm' ); ?></h2>
						<?php if ( ! $events ) : ?>
							<p class="description"><?php esc_html_e( '沒有活動紀錄。', 'now-crm' ); ?></p>
						<?php else : ?>
							<ul class="nowcrm-meta">
								<?php foreach ( $events as $event ) : ?>
									<li>
										<span><?php echo esc_html( self::event_label( (string) $event['type'] ) ); ?></span>
										<strong><?php echo esc_html( Menu::format_datetime( (string) $event['occurred_at'] ) ); ?></strong>
									</li>
								<?php endforeach; ?>
							</ul>
						<?php endif; ?>
					</div>
				</div>

				<aside class="nowcrm-grid__side nowcrm-cart-side">
					<div class="nowcrm-panel">
						<h2><?php esc_html_e( '提醒與還原', 'now-crm' ); ?></h2>
						<ul class="nowcrm-meta">
							<li><span><?php esc_html_e( '已寄提醒', 'now-crm' ); ?></span><strong><?php echo esc_html( number_format_i18n( (int) $cart['reminders_sent'] ) ); ?></strong></li>
							<li><span><?php esc_html_e( '最後提醒', 'now-crm' ); ?></span><strong><?php echo esc_html( Menu::format_datetime( (string) $cart['last_reminder_at'] ) ); ?></strong></li>
							<li>
								<span><?php esc_html_e( '下次提醒', 'now-crm' ); ?></span>
								<strong>
									<?php
									echo esc_html(
										CartRepository::STATUS_ABANDONED === $status
											? Menu::format_datetime( (string) $cart['reminder_due_at'] )
											: '—'
									);
									?>
								</strong>
							</li>
							<li><span><?php esc_html_e( '還原連結有效至', 'now-crm' ); ?></span><strong><?php echo esc_html( Menu::format_datetime( (string) $cart['recovery_token_expires'] ) ); ?></strong></li>
						</ul>
						<?php if ( $ended ) : ?>
							<p class="description"><?php esc_html_e( '此購物車已結束，佇列中的提醒已取消。', 'now-crm' ); ?></p>
						<?php elseif ( CartRepository::STATUS_ORDER_PENDING === $status ) : ?>
							<p class="description">
								<?php esc_html_e( '這台購物車已建立訂單但尚未確認付款，因此不算挽回；未寄出的提醒已暫停。請以 WooCommerce 訂單的付款狀態為準。', 'now-crm' ); ?>
							</p>
						<?php elseif ( CartRepository::STATUS_ABANDONED === $status ) : ?>
							<p class="description">
								<?php
								if ( $max > 0 ) {
									esc_html_e( '提醒由排程自動寄送；目前引擎每次放棄只排 1 封。此頁不提供手動寄送。', 'now-crm' );
								} else {
									esc_html_e( '目前設定為不寄送購物車提醒。此頁不提供手動寄送。', 'now-crm' );
								}
								?>
							</p>
						<?php else : ?>
							<p class="description">
								<?php
								printf(
									/* translators: %d: idle minutes before a cart is abandoned. */
									esc_html__( '尚未達到放棄條件；閒置 %d 分鐘後才會進入提醒流程。', 'now-crm' ),
									(int) Settings::int( 'cart_idle_minutes' )
								);
								?>
							</p>
						<?php endif; ?>
					</div>

					<div class="nowcrm-panel">
						<h2><?php esc_html_e( '相關連結', 'now-crm' ); ?></h2>
						<ul class="nowcrm-meta">
							<li>
								<span><?php esc_html_e( '聯絡人', 'now-crm' ); ?></span>
								<strong>
									<?php if ( '' !== $contact_url ) : ?>
										<a href="<?php echo esc_url( $contact_url ); ?>"><?php esc_html_e( '開啟聯絡人', 'now-crm' ); ?></a>
									<?php else : ?>
										—
									<?php endif; ?>
								</strong>
							</li>
							<li>
								<span><?php esc_html_e( '寄送紀錄', 'now-crm' ); ?></span>
								<strong>
									<a href="<?php echo esc_url( $log_url ); ?>"><?php esc_html_e( '此購物車的提醒信', 'now-crm' ); ?></a>
								</strong>
							</li>
						</ul>
						<p class="description">
							<?php esc_html_e( '沒有對應聯絡人代表此 Email 尚未建立聯絡人；提醒信只會寄給已訂閱的聯絡人。', 'now-crm' ); ?>
						</p>
					</div>
				</aside>
			</div>
		</div>
		<?php
	}

	/**
	 * Settings; the reminder content lives in the owning automation.
	 */
	private static function render_settings(): void {
		$settings = Settings::all();

		$selected_automation = Settings::int( CartAutomation::SETTING );
		$owned_automation    = $selected_automation > 0
			? ( new AutomationRepository() )->get( $selected_automation )
			: null;
		$automation_options  = array();

		foreach ( CartAutomation::eligible_options() as $option ) {
			if ( ! is_array( $option ) || ! empty( $option['managed'] ) ) {
				continue;
			}

			$option_id = (int) ( $option['id'] ?? 0 );

			if ( $option_id > 0 ) {
				$automation_options[ $option_id ] = $option;
			}
		}

		// A stored selection outside the eligible list stays visible and selected.
		$selection_stale = $selected_automation > 0 && ! isset( $automation_options[ $selected_automation ] );
		$owner_label     = __( '受管理預設流程', 'now-crm' );

		if ( $selected_automation > 0 ) {
			$owner_label = $owned_automation
				? (string) $owned_automation['name'] . '（#' . $selected_automation . '）'
				/* translators: %d: automation ID. */
				: sprintf( __( '找不到 #%d', 'now-crm' ), $selected_automation );
		}

		$managed_id = (int) get_option( CartAutomation::MANAGED_OPTION, 0 );

		if ( $managed_id <= 0 ) {
			$ensured = CartAutomation::ensure_managed();

			if ( ! is_wp_error( $ensured ) ) {
				$managed_id = (int) $ensured;
			}
		}

		$owner_id    = $selected_automation > 0 ? $selected_automation : $managed_id;
		$owner_row   = $owner_id > 0 ? ( new AutomationRepository() )->get( $owner_id ) : null;
		$owner_url   = $owner_row ? Menu::page_url( Menu::AUTOMATION_SLUG, array( 'id' => $owner_id ) ) : '';

		$form_url = admin_url( 'admin-post.php' );
		?>
		<div class="wrap nowcrm-wrap nowcrm-cart nowcrm-cart--settings">
			<?php self::render_page_tabs( self::TAB_SETTINGS ); ?>

			<header class="nowcrm-cart-settings__head">
				<h1><?php esc_html_e( '購物車放棄', 'now-crm' ); ?></h1>
				<p class="description"><?php esc_html_e( '這個頁面管理捕捉、提醒時程與流程擁有者；提醒信內容統一在流程的 Email 步驟編輯。', 'now-crm' ); ?></p>
			</header>

			<nav class="nowcrm-cart-jump" aria-label="<?php esc_attr_e( '購物車設定區段', 'now-crm' ); ?>">
				<a href="#nowcrm-cart-status"><?php esc_html_e( '引擎狀態', 'now-crm' ); ?></a>
				<a href="#nowcrm-cart-settings"><?php esc_html_e( '購物車設定', 'now-crm' ); ?></a>
				<a href="#nowcrm-cart-email"><?php esc_html_e( '提醒信內容', 'now-crm' ); ?></a>
			</nav>

			<?php Actions::render_notice(); ?>

			<section class="nowcrm-panel nowcrm-cart-status" id="nowcrm-cart-status" aria-labelledby="nowcrm-cart-status-title">
				<h2 id="nowcrm-cart-status-title"><?php esc_html_e( '引擎狀態', 'now-crm' ); ?></h2>
				<ul class="nowcrm-meta">
					<li>
						<span><?php esc_html_e( '捕捉狀態', 'now-crm' ); ?></span>
						<strong><?php echo esc_html( Settings::bool( 'abandoned_cart_enabled' ) ? __( '已啟用', 'now-crm' ) : __( '未啟用', 'now-crm' ) ); ?></strong>
					</li>
					<li>
						<span><?php esc_html_e( '寄送狀態', 'now-crm' ); ?></span>
						<strong><?php echo esc_html( Settings::bool( 'email_sending_enabled' ) ? __( '已開啟', 'now-crm' ) : __( '未開啟', 'now-crm' ) ); ?></strong>
					</li>
				</ul>
				<p class="description">
					<?php esc_html_e( '捕捉與寄送是兩個獨立開關。捕捉未啟用時不會寫入新的結帳活動；寄送未開啟時，提醒仍會排入佇列，但不會送出。提醒由每分鐘的排程與寄送佇列執行；引擎每次放棄只寄 1 封提醒。', 'now-crm' ); ?>
				</p>
			</section>

			<form method="post" action="<?php echo esc_url( $form_url ); ?>" class="nowcrm-panel nowcrm-cart-settings" id="nowcrm-cart-settings">
				<input type="hidden" name="action" value="nowcrm_save_settings">
				<input type="hidden" name="redirect_page" value="nowcrm-cart">
				<input type="hidden" name="redirect_tab" value="settings">
				<input type="hidden" name="settings_scope" value="cart">
				<?php wp_nonce_field( 'nowcrm_save_settings' ); ?>
				<h2><?php esc_html_e( '購物車設定', 'now-crm' ); ?></h2>

				<fieldset class="nowcrm-cart-group">
					<legend><?php esc_html_e( '捕捉與狀態', 'now-crm' ); ?></legend>
					<table class="form-table" role="presentation"><tbody>
						<tr>
							<th scope="row"><label for="cart-enabled"><?php esc_html_e( '啟用購物車捕捉與提醒', 'now-crm' ); ?></label></th>
							<td>
								<label class="nowcrm-cart-check">
									<input type="checkbox" id="cart-enabled" name="abandoned_cart_enabled" value="1" <?php checked( (bool) $settings['abandoned_cart_enabled'] ); ?>>
									<span><?php esc_html_e( '寫入新的結帳活動，並依下方時程寄送提醒', 'now-crm' ); ?></span>
								</label>
								<p class="description"><?php esc_html_e( '取消勾選後不會寫入新的購物車；已排入佇列的提醒不會送出。', 'now-crm' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="cart-idle"><?php esc_html_e( '閒置幾分鐘後視為放棄', 'now-crm' ); ?></label></th>
							<td>
								<input type="number" id="cart-idle" name="cart_idle_minutes" min="1" max="1440" value="<?php echo esc_attr( (string) $settings['cart_idle_minutes'] ); ?>">
								<p class="description"><?php esc_html_e( '1–1440 分鐘。未達此閒置時間的購物車不會標記放棄。', 'now-crm' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="cart-lost"><?php esc_html_e( '幾天後標記流失', 'now-crm' ); ?></label></th>
							<td>
								<input type="number" id="cart-lost" name="cart_lost_days" min="1" max="60" value="<?php echo esc_attr( (string) $settings['cart_lost_days'] ); ?>">
								<p class="description"><?php esc_html_e( '1–60 天。到期後狀態改為已流失，並取消佇列中的提醒。', 'now-crm' ); ?></p>
							</td>
						</tr>
					</tbody></table>
				</fieldset>

				<fieldset class="nowcrm-cart-group">
					<legend><?php esc_html_e( '提醒時程與還原', 'now-crm' ); ?></legend>
					<table class="form-table" role="presentation"><tbody>
						<tr>
							<th scope="row"><label for="cart-delay"><?php esc_html_e( '放棄後延遲幾分鐘寄送', 'now-crm' ); ?></label></th>
							<td>
								<input type="number" id="cart-delay" name="cart_reminder_delay_minutes" min="0" max="1440" value="<?php echo esc_attr( (string) $settings['cart_reminder_delay_minutes'] ); ?>">
								<p class="description"><?php esc_html_e( '0–1440 分鐘。0 表示標記放棄後立即排入佇列。', 'now-crm' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="cart-cooldown"><?php esc_html_e( '同 Email 冷卻天數', 'now-crm' ); ?></label></th>
							<td>
								<input type="number" id="cart-cooldown" name="cart_cooldown_days" min="0" max="60" value="<?php echo esc_attr( (string) $settings['cart_cooldown_days'] ); ?>">
								<p class="description"><?php esc_html_e( '0–60 天。冷卻期內，同一 Email 的提醒不會寄出；0 表示不冷卻。', 'now-crm' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="cart-max"><?php esc_html_e( '提醒封數上限', 'now-crm' ); ?></label></th>
							<td>
								<input type="number" id="cart-max" name="cart_max_reminders" min="0" max="3" value="<?php echo esc_attr( (string) $settings['cart_max_reminders'] ); ?>">
								<p class="description"><?php esc_html_e( '0–3 封。0 表示不寄送。目前引擎每次放棄只排 1 封，上限大於 1 不會產生多步提醒。', 'now-crm' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="cart-recovery"><?php esc_html_e( '還原連結有效天數', 'now-crm' ); ?></label></th>
							<td>
								<input type="number" id="cart-recovery" name="cart_recovery_days" min="1" max="30" value="<?php echo esc_attr( (string) $settings['cart_recovery_days'] ); ?>">
								<p class="description"><?php esc_html_e( '1–30 天。提醒信中的還原連結在此期間內有效。', 'now-crm' ); ?></p>
							</td>
						</tr>
					</tbody></table>
				</fieldset>

				<fieldset class="nowcrm-cart-group">
					<legend><?php esc_html_e( '流程擁有者', 'now-crm' ); ?></legend>
					<table class="form-table" role="presentation"><tbody>
						<tr>
							<th scope="row"><label for="cart-automation"><?php esc_html_e( '選用購物車自動化', 'now-crm' ); ?></label></th>
							<td>
								<select id="cart-automation" name="cart_automation_id" data-testid="cart-automation-select">
									<option value="0" <?php selected( $selected_automation, 0 ); ?>>
										<?php esc_html_e( '受管理預設流程（預設）', 'now-crm' ); ?>
									</option>
									<?php foreach ( $automation_options as $option_id => $option ) : ?>
										<option value="<?php echo esc_attr( (string) $option_id ); ?>" <?php selected( $selected_automation, $option_id ); ?>>
											<?php echo esc_html( (string) $option['name'] ); ?>（#<?php echo esc_html( (string) $option_id ); ?>）
										</option>
									<?php endforeach; ?>
									<?php if ( $selection_stale ) : ?>
										<?php
										$stale_state = null === $owned_automation
											? __( '已不存在', 'now-crm' )
											: ( AutomationRepository::STATUS_ACTIVE !== (string) $owned_automation['status']
												? __( '已暫停', 'now-crm' )
												: __( '目前無法選用', 'now-crm' ) );
										?>
										<option value="<?php echo esc_attr( (string) $selected_automation ); ?>" selected="selected">
											<?php echo esc_html( $owner_label . '（' . $stale_state . '）' ); ?>
										</option>
									<?php endif; ?>
								</select>
								<p class="description">
									<?php esc_html_e( '留空使用受管理預設流程；選取自訂流程時，只會列出啟用中且有 Email 步驟的購物車放棄流程。目前擁有者：', 'now-crm' ); ?>
									<strong><?php echo esc_html( $owner_label ); ?></strong>
								</p>
								<?php if ( $selection_stale ) : ?>
									<div class="notice notice-warning inline" role="alert">
										<p><?php esc_html_e( '目前選取的流程已暫停、不存在或無法選用。在你儲存新的選擇前，系統不會自動改回預設流程。', 'now-crm' ); ?></p>
									</div>
								<?php endif; ?>
							</td>
						</tr>
					</tbody></table>
				</fieldset>

				<p class="submit">
					<button type="submit" class="button button-primary"><?php esc_html_e( '儲存設定', 'now-crm' ); ?></button>
				</p>
			</form>

			<section class="nowcrm-panel nowcrm-cart-template" id="nowcrm-cart-email">
				<h2><?php esc_html_e( '提醒信內容', 'now-crm' ); ?></h2>
				<p class="description">
					<?php esc_html_e( '提醒信的主旨、Preheader 與正文統一在自動化的 Email 步驟編輯；這個頁面只管理寄送時程與由哪個流程負責。', 'now-crm' ); ?>
				</p>

				<?php if ( $owner_row && $owner_url ) : ?>
					<p>
						<?php esc_html_e( '目前擁有者：', 'now-crm' ); ?>
						<strong><?php echo esc_html( (string) $owner_row['name'] . '（#' . $owner_id . '）' ); ?></strong>
					</p>
					<p>
						<a class="button" href="<?php echo esc_url( $owner_url ); ?>"><?php esc_html_e( '在自動化編輯 Email', 'now-crm' ); ?></a>
					</p>
				<?php else : ?>
					<div class="notice notice-warning inline" role="alert">
						<p><?php esc_html_e( '目前沒有可用的購物車放棄流程。請先建立或選用一個包含 Email 步驟的流程。', 'now-crm' ); ?></p>
					</div>
				<?php endif; ?>

				<p class="description">
					<?php esc_html_e( '寄送時系統會替換 {{cart_items}} 與 {{recovery_url}}；Email 步驟請保留這兩個 token，商品表格與還原連結才會出現。', 'now-crm' ); ?>
				</p>
			</section>
		</div>
		<?php
	}

	/**
	 * Page-level tabs: data list and configuration.
	 */
	private static function render_page_tabs( string $current ): void {
		if ( ! Capabilities::can_manage_settings() ) {
			return;
		}

		$tabs = array(
			self::TAB_LIST     => array( __( '購物車', 'now-crm' ), self::list_url() ),
			self::TAB_SETTINGS => array( __( '設定與提醒', 'now-crm' ), Menu::page_url( Menu::CART_SLUG, array( 'tab' => self::TAB_SETTINGS ) ) ),
		);
		?>
		<nav class="nowcrm-contact-tabs" aria-label="<?php esc_attr_e( '購物車頁面', 'now-crm' ); ?>">
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
	 * Admin URL for the captured-cart list.
	 *
	 * @param array<string, mixed> $args Validated filter arguments.
	 */
	private static function list_url( array $args = array() ): string {
		return Menu::page_url( Menu::CART_SLUG, self::encode_args( $args ) );
	}

	/**
	 * Admin URL for one captured cart, preserving validated filters.
	 *
	 * @param array<string, mixed> $args Validated filter arguments.
	 */
	private static function detail_url( int $cart_id, array $args = array() ): string {
		return Menu::page_url( Menu::CART_SLUG, self::encode_args( array_merge( $args, array( 'cart' => $cart_id ) ) ) );
	}

	/**
	 * Encode validated filter values for the admin query string.
	 *
	 * Menu::page_url() builds the query with add_query_arg(), which does not
	 * encode values. A raw "+" in an email address would arrive as a space.
	 *
	 * @param array<string, mixed> $args Validated filter arguments.
	 * @return array<string, mixed>
	 */
	private static function encode_args( array $args ): array {
		if ( isset( $args['email'] ) ) {
			$args['email'] = rawurlencode( (string) $args['email'] );
		}

		return $args;
	}

	/**
	 * List URL for one status while keeping the validated email filter.
	 *
	 * @param array<string, mixed> $filters Validated status/email filters.
	 */
	private static function filter_url( array $filters, string $status ): string {
		if ( 'all' === $status ) {
			unset( $filters['status'] );
		} else {
			$filters['status'] = $status;
		}

		unset( $filters['paged'] );

		return self::list_url( $filters );
	}

	/**
	 * Paged list URL.
	 *
	 * @param array<string, mixed> $filters Validated status/email filters.
	 */
	private static function page_url( array $filters, int $number ): string {
		if ( $number > 1 ) {
			$filters['paged'] = $number;
		} else {
			unset( $filters['paged'] );
		}

		return self::list_url( $filters );
	}

	/**
	 * Validated filters to carry from a cart detail back to the list.
	 *
	 * @return array<string, mixed>
	 */
	private static function detail_filters(): array {
		$filters = array();
		$status  = sanitize_key( Menu::query( 'status', 'all' ) );

		if ( '' !== $status && 'all' !== $status && array_key_exists( $status, self::status_labels() ) ) {
			$filters['status'] = $status;
		}

		$email = trim( sanitize_text_field( Menu::query( 'email' ) ) );

		if ( '' !== $email && is_email( $email ) ) {
			$filters['email'] = $email;
		}

		$paged = absint( Menu::query( 'paged' ) );

		if ( $paged > 1 ) {
			$filters['paged'] = $paged;
		}

		return $filters;
	}

	/**
	 * Runs whose source is exactly this cart, newest first.
	 *
	 * The cart column is authoritative. Rows written before that column
	 * existed only qualify when both the trigger and the legacy `cart:<id>`
	 * source reference match, so another cart's run is never linked here.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function source_runs( int $cart_id ): array {
		$runs = array();

		foreach ( ( new RunRepository() )->for_cart( $cart_id, 20 ) as $run ) {
			if ( ! is_array( $run ) ) {
				continue;
			}

			$bound = (int) ( $run['cart_id'] ?? 0 );

			if ( $bound > 0 ) {
				if ( $bound === $cart_id ) {
					$runs[] = $run;
				}

				continue;
			}

			if ( TriggerRegistry::CART_ABANDONED === (string) ( $run['source_trigger'] ?? '' )
				&& 'cart:' . $cart_id === (string) ( $run['source_ref'] ?? '' ) ) {
				$runs[] = $run;
			}
		}

		return $runs;
	}

	/**
	 * Empty-state sentence for the three distinct list states.
	 */
	private static function empty_message( bool $blocked, bool $filtered, int $paged, int $total ): string {
		if ( $blocked ) {
			return __( '篩選條件不正確，因此未顯示任何購物車。', 'now-crm' );
		}

		if ( $paged > 1 && $total > 0 ) {
			return __( '這一頁沒有購物車。', 'now-crm' );
		}

		if ( $filtered ) {
			return __( '沒有符合篩選條件的購物車。', 'now-crm' );
		}

		return __( '還沒有捕捉到購物車。結帳頁送出 Email 並停留後，才會寫入第一筆。', 'now-crm' );
	}

	/**
	 * Status badge. Cart statuses are neutral; only the label carries meaning.
	 */
	private static function status_badge( string $status ): string {
		$label = self::status_labels()[ $status ] ?? $status;

		return '<span class="nowcrm-status-badge"><span class="nowcrm-status-dot" aria-hidden="true"></span>'
			. esc_html( $label )
			. '</span>';
	}

	/**
	 * Attribution label stored on a completed cart.
	 *
	 * @param array<string, mixed> $cart Cart row.
	 */
	private static function attribution_label( array $cart ): string {
		if ( CartRepository::STATUS_RECOVERED === (string) $cart['status'] || 'email_click' === (string) ( $cart['recovery_source'] ?? '' ) ) {
			return __( '郵件連結挽回', 'now-crm' );
		}

		if ( CartRepository::STATUS_COMPLETED === (string) $cart['status'] || 'natural' === (string) ( $cart['recovery_source'] ?? '' ) ) {
			return __( '自然完成', 'now-crm' );
		}

		return self::source_label( (string) ( $cart['recovery_source'] ?? '' ) );
	}

	/**
	 * Outcome sentence for the list and detail.
	 *
	 * @param array<string, mixed> $cart Cart row.
	 */
	private static function outcome_label( array $cart ): string {
		if ( ! empty( $cart['recovered_order_id'] ) ) {
			return self::attribution_label( $cart );
		}

		return match ( (string) $cart['status'] ) {
			CartRepository::STATUS_ACTIVE        => __( '尚未完成，仍在捕捉', 'now-crm' ),
			CartRepository::STATUS_ABANDONED     => __( '已放棄，尚未完成訂單', 'now-crm' ),
			CartRepository::STATUS_ORDER_PENDING => __( '已建立訂單，等待付款確認', 'now-crm' ),
			CartRepository::STATUS_LOST          => __( '已流失，未完成訂單', 'now-crm' ),
			default                              => __( '尚未完成訂單', 'now-crm' ),
		};
	}

	/**
	 * List result cell: order and attribution, or an em dash.
	 *
	 * @param array<string, mixed> $cart Cart row.
	 */
	private static function result_text( array $cart ): string {
		if ( empty( $cart['recovered_order_id'] ) ) {
			return '—';
		}

		return sprintf(
			/* translators: 1: order ID, 2: attribution label. */
			__( '訂單 #%1$s · %2$s', 'now-crm' ),
			(string) (int) $cart['recovered_order_id'],
			self::attribution_label( $cart )
		);
	}

	/**
	 * Machine attribution value as a human label.
	 */
	private static function source_label( string $source ): string {
		return match ( $source ) {
			'natural'     => __( '自然完成', 'now-crm' ),
			'email_click' => __( '郵件連結點擊', 'now-crm' ),
			default       => '' === $source ? '—' : $source,
		};
	}

	/**
	 * Cart event type as a human label.
	 */
	private static function event_label( string $type ): string {
		$labels = array(
			'captured'           => __( '捕捉購物車', 'now-crm' ),
			'abandoned'          => __( '標記放棄', 'now-crm' ),
			'reminder_queued'    => __( '提醒排入佇列', 'now-crm' ),
			'reminder_sent'      => __( '提醒已交付寄送', 'now-crm' ),
			'lost'               => __( '標記流失', 'now-crm' ),
			'recovery_click'     => __( '還原連結點擊', 'now-crm' ),
			'cart_restored'      => __( '購物車已還原', 'now-crm' ),
			'order_placed'       => __( '已建立訂單（待付款）', 'now-crm' ),
			'order_paid'         => __( '訂單已付款', 'now-crm' ),
			'order_natural'      => __( '自然完成訂單', 'now-crm' ),
			'order_email_click'  => __( '郵件連結挽回訂單', 'now-crm' ),
		);

		return $labels[ $type ] ?? $type;
	}

	/**
	 * Snapshot items rendered without trusting anything beyond the stored IDs
	 * and quantity. Names come from WooCommerce when it is available.
	 *
	 * @param array<string, mixed> $cart Cart row.
	 * @return array<int, array{name: string, meta: string, quantity: string}>
	 */
	private static function snapshot_items( array $cart ): array {
		$items = array();

		foreach ( (array) ( $cart['items'] ?? array() ) as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$product_id   = absint( $item['product_id'] ?? 0 );
			$variation_id = absint( $item['variation_id'] ?? 0 );
			$quantity     = (float) ( $item['quantity'] ?? 0 );

			if ( $product_id <= 0 && $variation_id <= 0 ) {
				continue;
			}

			$lookup = $variation_id > 0 ? $variation_id : $product_id;
			$name   = sprintf(
				/* translators: %d: product ID. */
				__( '商品 #%d', 'now-crm' ),
				$lookup
			);
			$meta   = $variation_id > 0
				? sprintf(
					/* translators: %d: variation ID. */
					__( '規格 #%d', 'now-crm' ),
					$variation_id
				)
				: '';

			if ( function_exists( 'wc_get_product' ) ) {
				$product = wc_get_product( $lookup );

				if ( $product ) {
					$name = $product->get_name();
				}
			}

			$items[] = array(
				'name'     => $name,
				'meta'     => $meta,
				'quantity' => $quantity === floor( $quantity )
					? number_format_i18n( $quantity, 0 )
					: number_format_i18n( $quantity, 2 ),
			);
		}

		return $items;
	}

	/**
	 * WooCommerce order edit URL, only when the order exists and the current
	 * user may manage WooCommerce.
	 */
	private static function order_url( int $order_id ): string {
		if ( $order_id <= 0 || ! function_exists( 'wc_get_order' ) ) {
			return '';
		}

		// Show the ID without a link unless this user can actually edit orders.
		if ( ! current_user_can( 'edit_shop_order', $order_id ) && ! current_user_can( 'edit_shop_orders' ) ) {
			return '';
		}

		$order = wc_get_order( $order_id );

		if ( ! $order instanceof \WC_Order ) {
			return '';
		}

		return (string) $order->get_edit_order_url();
	}
}
