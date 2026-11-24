<?php
/**
 * Contact detail screen.
 *
 * The screen follows the reference hierarchy: identity header with
 * previous/next browsing, independent status and membership actions in the
 * side column, then Overview, Emails, Purchases, and Notes tabs. Only the
 * active tab loads its own models, so opening Overview never reads orders,
 * email jobs, notes, or status history.
 *
 * @package NowCRM
 */

namespace NowCRM\Admin;

use NowCRM\Contacts\ContactRepository;
use NowCRM\Contacts\CustomFieldDefinitions;
use NowCRM\Contacts\Status;
use NowCRM\Orders\OrderLinkRepository;
use NowCRM\Support\Capabilities;
use NowCRM\Terms\TermRepository;
use NowCRM\Woo\PurchaseHistory;

defined( 'ABSPATH' ) || exit;

final class ContactDetailPage {

	public const DEFAULT_TAB = 'overview';

	/**
	 * Supported tab slugs.
	 *
	 * @var string[]
	 */
	public const TABS = array( 'overview', 'emails', 'purchases', 'notes' );

	/**
	 * Return a supported tab slug, or the default.
	 */
	public static function normalize_tab( string $tab ): string {
		$tab = sanitize_key( $tab );

		return in_array( $tab, self::TABS, true ) ? $tab : self::DEFAULT_TAB;
	}

	/**
	 * Render one contact with its editable profile and tabbed history.
	 */
	public static function render(): void {
		if ( ! Capabilities::can_manage_contacts() ) {
			wp_die(
				esc_html__( '您沒有權限檢視聯絡人。', 'now-crm' ),
				'',
				array( 'response' => 403 )
			);
		}

		$contact_id = absint( Menu::query( 'id' ) );
		$tab        = self::normalize_tab( Menu::query( 'tab', self::DEFAULT_TAB ) );
		$repository = new ContactRepository();
		$contact    = $contact_id > 0 ? $repository->get( $contact_id ) : null;
		$form_url   = admin_url( 'admin-post.php' );

		?>
		<div class="wrap nowcrm-wrap nowcrm-contact-detail">
			<?php ContactsPage::render_nav( 'all' ); ?>

			<nav class="nowcrm-contact-detail__crumbs" aria-label="<?php esc_attr_e( '聯絡人位置', 'now-crm' ); ?>">
				<a href="<?php echo esc_url( Menu::page_url( Menu::SLUG ) ); ?>">
					<?php esc_html_e( '所有聯絡人', 'now-crm' ); ?>
				</a>

				<?php if ( $contact ) : ?>
					<span class="nowcrm-contact-detail__crumb-sep" aria-hidden="true">/</span>
					<span aria-current="page">
						<?php echo esc_html( Menu::contact_name( $contact ) ); ?>
						<span class="nowcrm-contact-detail__muted">#<?php echo esc_html( (string) $contact_id ); ?></span>
					</span>

					<?php $adjacent = $repository->adjacent_ids( $contact_id ); ?>
					<span class="nowcrm-contact-detail__pager">
						<?php if ( $adjacent['previous'] > 0 ) : ?>
							<a class="nowcrm-contact-detail__pager-link"
								href="<?php echo esc_url( self::tab_url( $adjacent['previous'], $tab ) ); ?>"
								aria-label="<?php echo esc_attr( sprintf( /* translators: %d: contact ID. */ __( '上一位聯絡人（#%d）', 'now-crm' ), $adjacent['previous'] ) ); ?>">
								<?php esc_html_e( '← 上一位', 'now-crm' ); ?>
							</a>
						<?php endif; ?>
						<?php if ( $adjacent['next'] > 0 ) : ?>
							<a class="nowcrm-contact-detail__pager-link"
								href="<?php echo esc_url( self::tab_url( $adjacent['next'], $tab ) ); ?>"
								aria-label="<?php echo esc_attr( sprintf( /* translators: %d: contact ID. */ __( '下一位聯絡人（#%d）', 'now-crm' ), $adjacent['next'] ) ); ?>">
								<?php esc_html_e( '下一位 →', 'now-crm' ); ?>
							</a>
						<?php endif; ?>
					</span>
				<?php endif; ?>
			</nav>

			<?php Actions::render_notice(); ?>

			<?php if ( ! $contact ) : ?>
				<div class="nowcrm-contact-detail__section">
					<h1 class="nowcrm-contact-detail__name"><?php esc_html_e( '找不到聯絡人', 'now-crm' ); ?></h1>
					<p class="description">
						<?php esc_html_e( '此聯絡人可能已被刪除，或連結中的 ID 不正確。請回到聯絡人列表重新選擇。', 'now-crm' ); ?>
					</p>
					<p>
						<a class="button" href="<?php echo esc_url( Menu::page_url( Menu::SLUG ) ); ?>">
							<?php esc_html_e( '返回聯絡人列表', 'now-crm' ); ?>
						</a>
					</p>
				</div>
			</div>
				<?php
				return;
			endif;

			$terms_repository = new TermRepository();
			$all_terms        = $terms_repository->all();
			$attached_terms   = $terms_repository->for_contact( $contact_id );
			$attached_ids     = array_map( 'intval', wp_list_pluck( $attached_terms, 'id' ) );

			$lists = array_values(
				array_filter(
					$all_terms,
					static fn( array $term ): bool => TermRepository::TYPE_LIST === $term['type']
				)
			);
			$tags  = array_values(
				array_filter(
					$all_terms,
					static fn( array $term ): bool => TermRepository::TYPE_TAG === $term['type']
				)
			);

			$name  = Menu::contact_name( $contact );
			$email = (string) $contact['email'];
			?>
			<header class="nowcrm-contact-detail__header">
				<div class="nowcrm-contact-detail__identity">
					<span class="nowcrm-avatar nowcrm-contact-detail__avatar" aria-hidden="true">
						<?php echo esc_html( self::initials( $name, $email ) ); ?>
					</span>
					<div class="nowcrm-contact-detail__identity-text">
						<h1 class="nowcrm-contact-detail__name"><?php echo esc_html( $name ); ?></h1>
						<p class="nowcrm-contact-detail__email"><?php echo esc_html( $email ); ?></p>
						<p class="nowcrm-contact-detail__since">
							<?php
							printf(
								/* translators: 1: contact ID, 2: created date. */
								esc_html__( '#%1$s · 聯絡人自 %2$s', 'now-crm' ),
								esc_html( (string) $contact_id ),
								esc_html( Menu::format_datetime( (string) $contact['created_at'] ) )
							);
							?>
						</p>
					</div>
				</div>

				<div class="nowcrm-contact-detail__actions">
					<a class="button" href="<?php echo esc_url( self::tab_url( $contact_id, 'emails' ) ); ?>">
						<?php esc_html_e( 'Email', 'now-crm' ); ?>
					</a>
					<form method="post" action="<?php echo esc_url( $form_url ); ?>" class="nowcrm-contact-detail__delete-form">
						<input type="hidden" name="action" value="nowcrm_delete_contact" />
						<input type="hidden" name="contact_id" value="<?php echo esc_attr( (string) $contact_id ); ?>" />
						<?php wp_nonce_field( 'nowcrm_delete_contact' ); ?>
						<button type="submit" class="button nowcrm-contact-detail__danger"
							onclick="return confirm('<?php echo esc_js( __( '確定要刪除此聯絡人嗎？此操作無法復原。', 'now-crm' ) ); ?>');">
							<?php esc_html_e( '刪除', 'now-crm' ); ?>
						</button>
					</form>
				</div>

				<div class="nowcrm-contact-detail__meta">
					<span class="nowcrm-contact-detail__meta-label"><?php esc_html_e( '類型', 'now-crm' ); ?></span>
					<span class="nowcrm-chip">
						<?php echo esc_html( \NowCRM\Contacts\ProfileFields::display_value( $contact, 'contact_type' ) ); ?>
					</span>
					<span class="nowcrm-contact-detail__meta-label"><?php esc_html_e( '狀態', 'now-crm' ); ?></span>
					<span class="nowcrm-status-badge nowcrm-status-badge--<?php echo esc_attr( (string) $contact['status'] ); ?>">
						<span class="nowcrm-status-dot" aria-hidden="true"></span>
						<?php echo esc_html( Status::label( (string) $contact['status'] ) ); ?>
					</span>
				</div>
			</header>

			<div class="nowcrm-contact-detail__body">
				<div class="nowcrm-contact-detail__main">
					<nav class="nowcrm-contact-detail__tabs" aria-label="<?php esc_attr_e( '聯絡人詳細資料分頁', 'now-crm' ); ?>">
						<?php
						$tabs = array(
							'overview'  => __( '概覽', 'now-crm' ),
							'emails'    => __( 'Email', 'now-crm' ),
							'purchases' => __( '訂單', 'now-crm' ),
							'notes'     => __( '筆記與動態', 'now-crm' ),
						);
						?>
						<?php foreach ( $tabs as $key => $label ) : ?>
							<a class="nowcrm-contact-detail__tab<?php echo $key === $tab ? ' nowcrm-contact-detail__tab--active' : ''; ?>"
								href="<?php echo esc_url( self::tab_url( $contact_id, $key ) ); ?>"
								<?php echo $key === $tab ? 'aria-current="page"' : ''; ?>>
								<?php echo esc_html( $label ); ?>
							</a>
						<?php endforeach; ?>
					</nav>

					<?php
					match ( $tab ) {
						'emails'    => ContactEmailPanel::render( $contact_id ),
						'purchases' => self::render_purchases( $contact_id ),
						'notes'     => self::render_notes( $contact_id ),
						default     => self::render_overview( $contact, $contact_id ),
					};
					?>
				</div>

				<aside class="nowcrm-contact-detail__side">
					<?php self::render_status_panel( $contact, $tab ); ?>
					<?php
					self::render_membership_group(
						$contact_id,
						TermRepository::TYPE_LIST,
						__( '名單', 'now-crm' ),
						__( '尚未加入任何名單。', 'now-crm' ),
						$lists,
						$attached_ids,
						$tab,
						$form_url
					);
					self::render_membership_group(
						$contact_id,
						TermRepository::TYPE_TAG,
						__( '標籤', 'now-crm' ),
						__( '尚未加入任何標籤。', 'now-crm' ),
						$tags,
						$attached_ids,
						$tab,
						$form_url
					);
					?>
				</aside>
			</div>
		</div>
		<?php
	}

	/**
	 * Overview tab: inline profile edit, custom data, and read-only metadata.
	 *
	 * @param array<string, mixed> $contact Contact row.
	 */
	private static function render_overview( array $contact, int $contact_id ): void {
		$form_url = admin_url( 'admin-post.php' );
		?>
		<form method="post" action="<?php echo esc_url( $form_url ); ?>" class="nowcrm-contact-detail__form"
			data-nowcrm-profile-form
			data-error-text="<?php esc_attr_e( '儲存失敗，請檢查欄位後再試一次。', 'now-crm' ); ?>">
			<input type="hidden" name="action" value="nowcrm_save_contact" />
			<input type="hidden" name="contact_id" value="<?php echo esc_attr( (string) $contact_id ); ?>" />
			<?php wp_nonce_field( 'nowcrm_save_contact' ); ?>

			<p class="nowcrm-contact-detail__inline-notice" data-nowcrm-profile-error role="alert" tabindex="-1" hidden></p>

			<section class="nowcrm-contact-detail__section" aria-labelledby="nowcrm-section-basic">
				<h2 id="nowcrm-section-basic"><?php esc_html_e( '基本資料', 'now-crm' ); ?></h2>
				<?php ContactProfileFields::render_basic( $contact ); ?>
			</section>

			<section class="nowcrm-contact-detail__section" aria-labelledby="nowcrm-section-address">
				<h2 id="nowcrm-section-address"><?php esc_html_e( '地址資料', 'now-crm' ); ?></h2>
				<?php ContactProfileFields::render_address( $contact ); ?>
			</section>

			<?php if ( array() !== CustomFieldDefinitions::all() ) : ?>
				<section class="nowcrm-contact-detail__section" aria-labelledby="nowcrm-section-custom">
					<h2 id="nowcrm-section-custom"><?php esc_html_e( '自訂資料', 'now-crm' ); ?></h2>
					<?php CustomFields::render_contact_fields( $contact_id ); ?>
				</section>
			<?php endif; ?>

			<div class="nowcrm-contact-detail__form-actions">
				<button type="submit" class="button button-primary"><?php esc_html_e( '儲存聯絡人', 'now-crm' ); ?></button>
				<button type="reset" class="button"><?php esc_html_e( '重設', 'now-crm' ); ?></button>
				<span class="description"><?php esc_html_e( '重設會還原為上次儲存的值。狀態與名單／標籤請使用右側操作。', 'now-crm' ); ?></span>
			</div>
		</form>

		<section class="nowcrm-contact-detail__section" aria-labelledby="nowcrm-section-additional">
			<h2 id="nowcrm-section-additional"><?php esc_html_e( '其他資訊', 'now-crm' ); ?></h2>
			<ul class="nowcrm-meta">
				<li>
					<span><?php esc_html_e( '建立時間', 'now-crm' ); ?></span>
					<strong><?php echo esc_html( Menu::format_datetime( (string) $contact['created_at'] ) ); ?></strong>
				</li>
				<li>
					<span><?php esc_html_e( '最後更新', 'now-crm' ); ?></span>
					<strong><?php echo esc_html( Menu::format_datetime( (string) $contact['updated_at'] ) ); ?></strong>
				</li>
				<li>
					<span><?php esc_html_e( '來源', 'now-crm' ); ?></span>
					<strong><?php echo esc_html( (string) $contact['source'] ); ?></strong>
				</li>
				<?php self::render_wp_user( $contact ); ?>
			</ul>
		</section>
		<?php
	}

	/**
	 * Purchases tab: accounting summary and linked WooCommerce orders.
	 */
	private static function render_purchases( int $contact_id ): void {
		$aggregate  = ( new ContactRepository() )->aggregate( $contact_id );
		$orders     = PurchaseHistory::enrich( ( new OrderLinkRepository() )->links_for_contact( $contact_id, 50 ) );
		$order_count = (int) $aggregate['order_count'];
		?>
		<section class="nowcrm-contact-detail__section" aria-labelledby="nowcrm-section-purchase-summary">
			<h2 id="nowcrm-section-purchase-summary"><?php esc_html_e( '購買統計', 'now-crm' ); ?></h2>
			<ul class="nowcrm-meta">
				<li>
					<span><?php esc_html_e( '計入訂單數', 'now-crm' ); ?></span>
					<strong><?php echo esc_html( (string) $order_count ); ?></strong>
				</li>
				<li>
					<span><?php esc_html_e( '累計消費', 'now-crm' ); ?></span>
					<strong>
						<?php
						echo esc_html(
							Menu::format_amount(
								(float) $aggregate['total_spent'],
								(string) $aggregate['currency']
							)
						);
						?>
					</strong>
				</li>
				<li>
					<span><?php esc_html_e( '平均客單價', 'now-crm' ); ?></span>
					<strong>
						<?php
						echo esc_html(
							Menu::format_amount(
								$order_count > 0 ? (float) $aggregate['total_spent'] / $order_count : 0.0,
								(string) $aggregate['currency']
							)
						);
						?>
					</strong>
				</li>
				<li>
					<span><?php esc_html_e( '首筆訂單', 'now-crm' ); ?></span>
					<strong><?php echo esc_html( Menu::format_datetime( $aggregate['first_order_at'] ) ); ?></strong>
				</li>
				<li>
					<span><?php esc_html_e( '最近訂單', 'now-crm' ); ?></span>
					<strong><?php echo esc_html( Menu::format_datetime( $aggregate['last_order_at'] ) ); ?></strong>
				</li>
			</ul>
		</section>

		<section class="nowcrm-contact-detail__section" aria-labelledby="nowcrm-section-orders">
			<h2 id="nowcrm-section-orders"><?php esc_html_e( '訂單連結', 'now-crm' ); ?></h2>
			<table class="wp-list-table widefat striped nowcrm-table">
				<thead>
					<tr>
						<th><?php esc_html_e( '訂單', 'now-crm' ); ?></th>
						<th><?php esc_html_e( '狀態', 'now-crm' ); ?></th>
						<th><?php esc_html_e( '商品明細', 'now-crm' ); ?></th>
						<th class="nowcrm-col-number"><?php esc_html_e( '總額', 'now-crm' ); ?></th>
						<th><?php esc_html_e( '計入統計', 'now-crm' ); ?></th>
						<th><?php esc_html_e( '建立時間', 'now-crm' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( array() === $orders ) : ?>
						<tr>
							<td colspan="6"><?php esc_html_e( '尚未連結任何訂單。', 'now-crm' ); ?></td>
						</tr>
					<?php else : ?>
						<?php foreach ( $orders as $purchase ) : ?>
							<tr>
								<td>
									<?php if ( '' !== (string) $purchase['edit_url'] ) : ?>
										<a href="<?php echo esc_url( (string) $purchase['edit_url'] ); ?>">
											<?php echo esc_html( '#' . (string) $purchase['number'] ); ?>
										</a>
									<?php else : ?>
										<?php echo esc_html( '#' . (string) $purchase['number'] ); ?>
									<?php endif; ?>
								</td>
								<td><?php echo esc_html( self::order_status_label( (string) $purchase['status'] ) ); ?></td>
								<td>
									<?php if ( array() === $purchase['items'] ) : ?>
										—
									<?php else : ?>
										<?php foreach ( $purchase['items'] as $item ) : ?>
											<div>
												<?php echo esc_html( (string) $item['name'] ); ?>
												<?php if ( '' !== (string) $item['options'] ) : ?>
													<span class="description">（<?php echo esc_html( (string) $item['options'] ); ?>）</span>
												<?php endif; ?>
												<span class="description">
													<?php
													printf(
														/* translators: 1: item quantity, 2: formatted line total. */
														esc_html__( '× %1$s，%2$s', 'now-crm' ),
														esc_html( (string) $item['quantity'] ),
														esc_html( Menu::format_amount( (float) $item['total'], (string) $purchase['currency'] ) )
													);
													?>
												</span>
											</div>
										<?php endforeach; ?>
									<?php endif; ?>
								</td>
								<td>
									<?php
									echo esc_html(
										Menu::format_amount(
											(float) $purchase['net_total'],
											(string) $purchase['currency']
										)
									);
									?>
								</td>
								<td>
									<?php echo ! empty( $purchase['is_counted'] ) ? esc_html__( '是', 'now-crm' ) : esc_html__( '否', 'now-crm' ); ?>
								</td>
								<td><?php echo esc_html( Menu::format_datetime( (string) $purchase['date_created'] ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
		</section>
		<?php
	}

	/**
	 * Notes tab: status activity and the note list/editor.
	 */
	private static function render_notes( int $contact_id ): void {
		$events = ( new ContactRepository() )->history()->for_contact( $contact_id, 20 );
		?>
		<section class="nowcrm-contact-detail__section" aria-labelledby="nowcrm-section-activity">
			<h2 id="nowcrm-section-activity"><?php esc_html_e( '狀態動態', 'now-crm' ); ?></h2>
			<table class="wp-list-table widefat striped nowcrm-table">
				<thead>
					<tr>
						<th><?php esc_html_e( '時間', 'now-crm' ); ?></th>
						<th><?php esc_html_e( '狀態變化', 'now-crm' ); ?></th>
						<th><?php esc_html_e( '來源', 'now-crm' ); ?></th>
						<th><?php esc_html_e( '備註', 'now-crm' ); ?></th>
						<th><?php esc_html_e( '操作者', 'now-crm' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( array() === $events ) : ?>
						<tr>
							<td colspan="5"><?php esc_html_e( '尚無狀態歷程。', 'now-crm' ); ?></td>
						</tr>
					<?php else : ?>
						<?php foreach ( $events as $event ) : ?>
							<?php $old = (string) ( $event['old_status'] ?? '' ); ?>
							<tr>
								<td><?php echo esc_html( Menu::format_datetime( (string) $event['occurred_at'] ) ); ?></td>
								<td>
									<?php
									printf(
										'%1$s → %2$s',
										esc_html( '' !== $old ? Status::label( $old ) : '—' ),
										esc_html( Status::label( (string) $event['new_status'] ) )
									);
									?>
								</td>
								<td><?php echo esc_html( (string) $event['source'] ); ?></td>
								<td><?php echo esc_html( (string) ( $event['note'] ?? '' ) ); ?></td>
								<td><?php echo esc_html( self::actor_name( $event['actor_id'] ?? null ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
		</section>

		<?php ContactNotes::render( $contact_id ); ?>
		<?php
	}

	/**
	 * Independent status action. Never sends mail and never touches profile or
	 * membership rows.
	 *
	 * @param array<string, mixed> $contact Contact row.
	 */
	private static function render_status_panel( array $contact, string $tab ): void {
		$contact_id = (int) $contact['id'];
		$note_id    = 'nowcrm-status-note';
		?>
		<section class="nowcrm-contact-detail__section" aria-labelledby="nowcrm-section-status">
			<h2 id="nowcrm-section-status"><?php esc_html_e( '狀態', 'now-crm' ); ?></h2>
			<p class="nowcrm-contact-detail__status-current">
				<span class="nowcrm-status-badge nowcrm-status-badge--<?php echo esc_attr( (string) $contact['status'] ); ?>">
					<span class="nowcrm-status-dot" aria-hidden="true"></span>
					<?php echo esc_html( Status::label( (string) $contact['status'] ) ); ?>
				</span>
			</p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="nowcrm-contact-detail__status-form">
				<input type="hidden" name="action" value="nowcrm_change_contact_status" />
				<input type="hidden" name="contact_id" value="<?php echo esc_attr( (string) $contact_id ); ?>" />
				<input type="hidden" name="tab" value="<?php echo esc_attr( $tab ); ?>" />
				<?php wp_nonce_field( 'nowcrm_change_contact_status_' . $contact_id ); ?>

				<label for="nowcrm-status"><?php esc_html_e( '變更狀態', 'now-crm' ); ?></label>
				<select id="nowcrm-status" name="status">
					<?php foreach ( Status::all() as $option ) : ?>
						<option value="<?php echo esc_attr( $option ); ?>" <?php selected( (string) $contact['status'], $option ); ?>>
							<?php echo esc_html( Status::label( $option ) ); ?>
						</option>
					<?php endforeach; ?>
				</select>

				<label for="<?php echo esc_attr( $note_id ); ?>"><?php esc_html_e( '狀態備註（選填）', 'now-crm' ); ?></label>
				<textarea id="<?php echo esc_attr( $note_id ); ?>" name="note" rows="2"></textarea>

				<p class="description">
					<?php esc_html_e( '只在狀態實際變更時寫入歷程，來源記錄為 admin。此操作不會寄送 Email。', 'now-crm' ); ?>
				</p>

				<button type="submit" class="button button-primary"><?php esc_html_e( '更新狀態', 'now-crm' ); ?></button>
			</form>
		</section>
		<?php
	}

	/**
	 * One membership block: attached chips with explicit remove, plus a
	 * collapsed searchable picker for the remaining terms of this type.
	 *
	 * @param array<int, array<string, mixed>> $terms        All terms of this type.
	 * @param int[]                            $attached_ids Currently attached IDs.
	 */
	private static function render_membership_group( int $contact_id, string $type, string $title, string $empty_text, array $terms, array $attached_ids, string $tab, string $form_url ): void {
		$attached  = array_values(
			array_filter(
				$terms,
				static fn( array $term ): bool => in_array( (int) $term['id'], $attached_ids, true )
			)
		);
		$available = array_values(
			array_filter(
				$terms,
				static fn( array $term ): bool => ! in_array( (int) $term['id'], $attached_ids, true )
			)
		);

		$nonce_action = 'nowcrm_contact_term_' . $contact_id;
		$field_id     = 'nowcrm-term-search-' . $type;
		?>
		<section class="nowcrm-contact-detail__section nowcrm-contact-detail__group" aria-labelledby="<?php echo esc_attr( 'nowcrm-section-' . $type ); ?>">
			<h2 id="<?php echo esc_attr( 'nowcrm-section-' . $type ); ?>">
				<?php echo esc_html( $title ); ?>
				<span class="nowcrm-contact-detail__count">(<?php echo esc_html( (string) count( $attached ) ); ?>)</span>
			</h2>

			<?php if ( array() === $attached ) : ?>
				<p class="nowcrm-contact-detail__empty"><?php echo esc_html( $empty_text ); ?></p>
			<?php else : ?>
				<ul class="nowcrm-contact-detail__chips">
					<?php foreach ( $attached as $term ) : ?>
						<?php $term_id = (int) $term['id']; ?>
						<li class="nowcrm-contact-detail__chip">
							<span class="nowcrm-contact-detail__chip-name"><?php echo esc_html( (string) $term['name'] ); ?></span>
							<form method="post" action="<?php echo esc_url( $form_url ); ?>" class="nowcrm-contact-detail__chip-form">
								<input type="hidden" name="action" value="nowcrm_change_contact_term" />
								<input type="hidden" name="operation" value="remove" />
								<input type="hidden" name="contact_id" value="<?php echo esc_attr( (string) $contact_id ); ?>" />
								<input type="hidden" name="type" value="<?php echo esc_attr( $type ); ?>" />
								<input type="hidden" name="term_id" value="<?php echo esc_attr( (string) $term_id ); ?>" />
								<input type="hidden" name="tab" value="<?php echo esc_attr( $tab ); ?>" />
								<?php wp_nonce_field( $nonce_action ); ?>
								<button type="submit" class="nowcrm-contact-detail__chip-remove"
									aria-label="<?php echo esc_attr( sprintf( /* translators: 1: term name, 2: term type. */ __( '從%2$s移除「%1$s」', 'now-crm' ), (string) $term['name'], $title ) ); ?>"
									onclick="return confirm('<?php echo esc_js( sprintf( /* translators: 1: term name, 2: term type. */ __( '確定要從%2$s移除「%1$s」嗎？', 'now-crm' ), (string) $term['name'], $title ) ); ?>');">
									<span aria-hidden="true">×</span>
								</button>
							</form>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>

			<?php if ( array() !== $available ) : ?>
				<details class="nowcrm-contact-detail__picker" data-nowcrm-term-picker>
					<summary><?php echo esc_html( sprintf( /* translators: %s: term type. */ __( '新增%s', 'now-crm' ), $title ) ); ?></summary>
					<form method="post" action="<?php echo esc_url( $form_url ); ?>" class="nowcrm-contact-detail__picker-form">
						<input type="hidden" name="action" value="nowcrm_change_contact_term" />
						<input type="hidden" name="operation" value="attach" />
						<input type="hidden" name="contact_id" value="<?php echo esc_attr( (string) $contact_id ); ?>" />
						<input type="hidden" name="type" value="<?php echo esc_attr( $type ); ?>" />
						<input type="hidden" name="tab" value="<?php echo esc_attr( $tab ); ?>" />
						<?php wp_nonce_field( $nonce_action ); ?>

						<label class="screen-reader-text" for="<?php echo esc_attr( $field_id ); ?>">
							<?php echo esc_html( sprintf( /* translators: %s: term type. */ __( '搜尋%s', 'now-crm' ), $title ) ); ?>
						</label>
						<input type="search" id="<?php echo esc_attr( $field_id ); ?>" data-nowcrm-term-search
							placeholder="<?php echo esc_attr( sprintf( /* translators: %s: term type. */ __( '搜尋%s', 'now-crm' ), $title ) ); ?>" />

						<div class="nowcrm-contact-detail__options">
							<?php foreach ( $available as $term ) : ?>
								<button type="submit" name="term_id" value="<?php echo esc_attr( (string) (int) $term['id'] ); ?>"
									class="nowcrm-contact-detail__option" data-nowcrm-term-option>
									<?php echo esc_html( (string) $term['name'] ); ?>
								</button>
							<?php endforeach; ?>
						</div>
						<p class="nowcrm-contact-detail__empty" data-nowcrm-term-empty hidden>
							<?php esc_html_e( '沒有符合的名稱。', 'now-crm' ); ?>
						</p>
					</form>
				</details>
			<?php endif; ?>
		</section>
		<?php
	}

	/**
	 * WordPress user linkage rows for the Additional Information block.
	 *
	 * @param array<string, mixed> $contact Contact row.
	 */
	private static function render_wp_user( array $contact ): void {
		$wp_user = ! empty( $contact['wp_user_id'] )
			? get_userdata( (int) $contact['wp_user_id'] )
			: null;
		?>
		<li>
			<span><?php esc_html_e( 'WordPress 使用者', 'now-crm' ); ?></span>
			<strong>
				<?php if ( ! $wp_user ) : ?>
					<?php esc_html_e( '未連結', 'now-crm' ); ?>
				<?php elseif ( current_user_can( 'edit_user', (int) $wp_user->ID ) ) : ?>
					<a href="<?php echo esc_url( (string) get_edit_user_link( (int) $wp_user->ID ) ); ?>">
						<?php echo esc_html( (string) $wp_user->display_name ); ?>
					</a>
				<?php else : ?>
					<?php echo esc_html( (string) $wp_user->display_name ); ?>
				<?php endif; ?>
			</strong>
		</li>
		<li>
			<span><?php esc_html_e( '使用者 ID', 'now-crm' ); ?></span>
			<strong><?php echo esc_html( $wp_user ? (string) (int) $wp_user->ID : '—' ); ?></strong>
		</li>
		<li>
			<span><?php esc_html_e( '使用者 Email', 'now-crm' ); ?></span>
			<strong><?php echo esc_html( $wp_user ? (string) $wp_user->user_email : '—' ); ?></strong>
		</li>
		<?php
	}

	/**
	 * Contact detail URL for one tab.
	 *
	 * @param array<string, mixed> $args Extra query arguments.
	 */
	private static function tab_url( int $contact_id, string $tab, array $args = array() ): string {
		return Menu::page_url(
			Menu::CONTACT_SLUG,
			array_merge(
				array(
					'id'  => $contact_id,
					'tab' => self::normalize_tab( $tab ),
				),
				$args
			)
		);
	}

	/**
	 * Avatar initials.
	 */
	private static function initials( string $name, string $email ): string {
		$source = '' !== $name ? $name : $email;
		$parts  = preg_split( '/\s+/u', trim( $source ) );
		$parts  = is_array( $parts ) ? array_values( array_filter( $parts, 'strlen' ) ) : array();

		if ( array() === $parts ) {
			return '?';
		}

		if ( 1 === count( $parts ) ) {
			return mb_strtoupper( mb_substr( $parts[0], 0, 2 ) );
		}

		return mb_strtoupper( mb_substr( $parts[0], 0, 1 ) . mb_substr( $parts[ count( $parts ) - 1 ], 0, 1 ) );
	}

	/**
	 * WooCommerce order status label.
	 */
	private static function order_status_label( string $status ): string {
		if ( function_exists( 'wc_get_order_status_name' ) ) {
			return (string) wc_get_order_status_name( $status );
		}

		return '' === $status ? '—' : (string) preg_replace( '/^wc-/', '', $status );
	}

	/**
	 * Display name of the user responsible for a status event.
	 *
	 * @param mixed $actor_id Raw actor ID.
	 */
	private static function actor_name( $actor_id ): string {
		$actor_id = absint( $actor_id );

		if ( $actor_id <= 0 ) {
			return '—';
		}

		$user = get_userdata( $actor_id );

		return $user ? (string) $user->display_name : '#' . $actor_id;
	}
}
