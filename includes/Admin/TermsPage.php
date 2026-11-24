<?php
/**
 * Lists and tags screens.
 *
 * @package NowCRM
 */

namespace NowCRM\Admin;

use NowCRM\Contacts\CsvExport;
use NowCRM\Contacts\Status;
use NowCRM\Support\Capabilities;
use NowCRM\Terms\TermRepository;

defined( 'ABSPATH' ) || exit;

final class TermsPage {

	/**
	 * Admin-post action that streams a term CSV.
	 */
	public const ACTION_EXPORT = 'nowcrm_export_terms';

	private const DEFAULT_PER_PAGE = 10;

	private const MAX_PER_PAGE = 100;

	/**
	 * Page sizes offered by the table footer.
	 *
	 * @var int[]
	 */
	private const PAGE_SIZES = array( 10, 25, 50, 100 );

	/**
	 * Sortable repository columns.
	 *
	 * @var string[]
	 */
	private const SORTABLE = array( 'id', 'name', 'slug', 'contact_count', 'created_at' );

	private const EXPORT_PAGE_SIZE = 100;

	private const MAX_EXPORT_ROWS = 10000;

	/**
	 * Render the lists screen.
	 */
	public static function render_lists(): void {
		self::render( TermRepository::TYPE_LIST );
	}

	/**
	 * Render the tags screen.
	 */
	public static function render_tags(): void {
		self::render( TermRepository::TYPE_TAG );
	}

	/**
	 * Render one term type with search, sorting, editing, and batch controls.
	 *
	 * @param string $type Term type.
	 */
	public static function render( string $type = TermRepository::TYPE_LIST ): void {
		if ( ! Capabilities::can_manage_contacts() ) {
			wp_die(
				esc_html__( '您沒有權限檢視名單或標籤。', 'now-crm' ),
				'',
				array( 'response' => 403 )
			);
		}

		if ( ! in_array( $type, TermRepository::types(), true ) ) {
			$type = TermRepository::TYPE_LIST;
		}

		$label     = Menu::term_type_label( $type );
		$page_slug = TermRepository::TYPE_TAG === $type ? Menu::TAGS_SLUG : Menu::LISTS_SLUG;
		$terms     = new TermRepository();

		$search  = sanitize_text_field( Menu::query( 's' ) );
		$orderby = sanitize_key( Menu::query( 'orderby', 'id' ) );
		$paged   = max( 1, absint( Menu::query( 'paged', '1' ) ) );
		$edit_id = absint( Menu::query( 'edit' ) );

		if ( ! in_array( $orderby, self::SORTABLE, true ) ) {
			$orderby = 'id';
		}

		$requested_order = strtolower( Menu::query( 'order', '' ) );
		$order           = in_array( $requested_order, array( 'asc', 'desc' ), true )
			? strtoupper( $requested_order )
			: ( in_array( $orderby, array( 'name', 'slug' ), true ) ? 'ASC' : 'DESC' );

		$per_page = absint( Menu::query( 'per_page', (string) self::DEFAULT_PER_PAGE ) );
		$per_page = max( 1, min( self::MAX_PER_PAGE, $per_page > 0 ? $per_page : self::DEFAULT_PER_PAGE ) );

		$result      = $terms->paginate( self::query_args( $type, $search, $orderby, $order, $paged, $per_page ) );
		$items       = $result['items'];
		$total       = (int) $result['total'];
		$total_pages = max( 1, (int) ceil( $total / $per_page ) );

		// Keep a stale page link on the last real page.
		if ( $paged > $total_pages ) {
			$paged      = $total_pages;
			$result     = $terms->paginate( self::query_args( $type, $search, $orderby, $order, $paged, $per_page ) );
			$items      = $result['items'];
			$total      = (int) $result['total'];
			$total_pages = max( 1, (int) ceil( $total / $per_page ) );
		}

		$editing     = null;
		$edit_invalid = false;

		if ( $edit_id > 0 ) {
			$candidate = $terms->get( $edit_id );

			if ( $candidate && (string) $candidate['type'] === $type ) {
				$editing = $candidate;
			} else {
				$edit_invalid = true;
			}
		}

		$dialog_open  = null !== $editing || ( null === $editing && '1' === Menu::query( 'new' ) && ! $edit_invalid );
		$import_open  = ! $dialog_open && '1' === Menu::query( 'import' );
		$dialog_mode  = null !== $editing ? 'edit' : 'create';
		$dialog_id    = null !== $editing ? (int) $editing['id'] : 0;

		$form_url = admin_url( 'admin-post.php' );

		$state_args = array(
			'orderby'  => $orderby,
			'order'    => strtolower( $order ),
			'per_page' => $per_page,
		);

		if ( '' !== $search ) {
			$state_args['s'] = $search;
		}

		$view_args = $state_args;

		if ( $paged > 1 ) {
			$view_args['paged'] = $paged;
		}

		$return_url = Menu::page_url( $page_slug, $view_args );
		$create_url = Menu::page_url( $page_slug, array_merge( $view_args, array( 'new' => '1' ) ) );
		$import_url = Menu::page_url( $page_slug, array_merge( $view_args, array( 'import' => '1' ) ) );

		$clear_search_args = $state_args;
		unset( $clear_search_args['s'] );
		$clear_search_url = Menu::page_url( $page_slug, $clear_search_args );

		$column_url = static function ( string $column ) use ( $page_slug, $state_args, $orderby, $order ): string {
			$default = in_array( $column, array( 'name', 'slug' ), true ) ? 'ASC' : 'DESC';
			$next    = $column === $orderby ? ( 'ASC' === $order ? 'DESC' : 'ASC' ) : $default;

			return Menu::page_url(
				$page_slug,
				array_merge(
					$state_args,
					array(
						'orderby' => $column,
						'order'   => strtolower( $next ),
					)
				)
			);
		};

		$aria_sort = static function ( string $column ) use ( $orderby, $order ): string {
			if ( $column !== $orderby ) {
				return 'none';
			}

			return 'ASC' === $order ? 'ascending' : 'descending';
		};

		$sort_marker = static function ( string $column ) use ( $orderby, $order ): string {
			if ( $column === $orderby ) {
				return 'ASC' === $order ? '↑' : '↓';
			}

			return '↕';
		};

		$export_args = array(
			'action'  => self::ACTION_EXPORT,
			'type'    => $type,
			'orderby' => $orderby,
			'order'   => strtolower( $order ),
		);

		if ( '' !== $search ) {
			$export_args['s'] = $search;
		}

		$export_url = wp_nonce_url(
			add_query_arg( $export_args, $form_url ),
			self::ACTION_EXPORT . '_' . $type
		);

		$resource       = TermRepository::TYPE_TAG === $type ? 'tags' : 'lists';
		$first_row      = $total > 0 ? ( ( $paged - 1 ) * $per_page ) + 1 : 0;
		$last_row       = min( $total, $paged * $per_page );
		$default_slug_hint = null !== $editing
			? __( '留空則保留目前 Slug。', 'now-crm' )
			: __( '留空則依名稱自動產生。', 'now-crm' );
		?>
		<div class="wrap nowcrm-wrap nowcrm-terms-page"
			data-nowcrm-terms
			data-rest-url="<?php echo esc_url( rest_url( 'nowcrm/v1/' ) ); ?>"
			data-rest-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>"
			data-resource="<?php echo esc_attr( $resource ); ?>"
			data-item-label="<?php echo esc_attr( sprintf( /* translators: %s: list or tag label. */ __( '個%s', 'now-crm' ), $label ) ); ?>"
			data-table-url="<?php echo esc_url( $return_url ); ?>">
			<?php ContactsPage::render_nav( TermRepository::TYPE_TAG === $type ? 'tags' : 'lists' ); ?>

			<div class="nowcrm-terms__head">
				<div class="nowcrm-terms__heading">
					<h1 class="wp-heading-inline"><?php echo esc_html( $label ); ?></h1>
					<span class="nowcrm-terms__count">（<?php echo esc_html( (string) $total ); ?>）</span>
				</div>

				<div class="nowcrm-terms__toolbar">
					<details class="nowcrm-more nowcrm-terms__more">
						<summary class="button nowcrm-terms__more-trigger"><?php esc_html_e( '更多操作', 'now-crm' ); ?></summary>
						<div class="nowcrm-more__menu nowcrm-terms__menu">
							<a href="<?php echo esc_url( $import_url ); ?>" data-nowcrm-open-import>
								<?php esc_html_e( '匯入 CSV', 'now-crm' ); ?>
							</a>
							<a href="<?php echo esc_url( $export_url ); ?>">
								<?php esc_html_e( '匯出 CSV', 'now-crm' ); ?>
							</a>
						</div>
					</details>

					<a class="button button-primary" href="<?php echo esc_url( $create_url ); ?>" data-nowcrm-open-create>
						<?php
						echo esc_html(
							sprintf(
								/* translators: %s: list or tag label. */
								__( '新增%s', 'now-crm' ),
								$label
							)
						);
						?>
					</a>
				</div>
			</div>
			<hr class="wp-header-end" />

			<?php if ( ! $dialog_open && ! $import_open ) : ?>
				<?php Actions::render_notice(); ?>
			<?php endif; ?>

			<?php if ( $edit_invalid ) : ?>
				<div class="notice notice-error inline">
					<p><?php esc_html_e( '找不到資料，可能已被刪除。請重新整理後再試。', 'now-crm' ); ?></p>
				</div>
			<?php endif; ?>

			<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="nowcrm-terms__filters">
				<input type="hidden" name="page" value="<?php echo esc_attr( $page_slug ); ?>" />
				<input type="hidden" name="per_page" value="<?php echo esc_attr( (string) $per_page ); ?>" />
				<input type="hidden" name="orderby" value="<?php echo esc_attr( $orderby ); ?>" />
				<input type="hidden" name="order" value="<?php echo esc_attr( strtolower( $order ) ); ?>" />

				<label class="nowcrm-terms__search">
					<span class="screen-reader-text">
						<?php
						echo esc_html(
							sprintf(
								/* translators: %s: list or tag label. */
								__( '搜尋%s', 'now-crm' ),
								$label
							)
						);
						?>
					</span>
					<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>"
						placeholder="<?php esc_attr_e( '搜尋名稱、Slug 或說明', 'now-crm' ); ?>" />
					<button type="submit" class="button"><?php esc_html_e( '搜尋', 'now-crm' ); ?></button>
				</label>

				<?php if ( '' !== $search ) : ?>
					<a class="nowcrm-terms__clear" href="<?php echo esc_url( $clear_search_url ); ?>">
						<?php esc_html_e( '清除搜尋', 'now-crm' ); ?>
					</a>
				<?php endif; ?>
			</form>

			<div class="nowcrm-terms__surface">
				<table class="nowcrm-terms__table">
					<thead>
						<tr>
							<th scope="col" class="nowcrm-terms__check">
								<input type="checkbox" data-nowcrm-terms-check-page
									aria-label="<?php esc_attr_e( '全選本頁', 'now-crm' ); ?>" />
							</th>
							<th scope="col" class="nowcrm-terms__col-id" aria-sort="<?php echo esc_attr( $aria_sort( 'id' ) ); ?>">
								<a href="<?php echo esc_url( $column_url( 'id' ) ); ?>">
									<?php esc_html_e( 'ID', 'now-crm' ); ?>
									<span class="nowcrm-terms__sort" aria-hidden="true"><?php echo esc_html( $sort_marker( 'id' ) ); ?></span>
								</a>
							</th>
							<th scope="col" aria-sort="<?php echo esc_attr( $aria_sort( 'name' ) ); ?>">
								<a href="<?php echo esc_url( $column_url( 'name' ) ); ?>">
									<?php esc_html_e( '名稱', 'now-crm' ); ?>
									<span class="nowcrm-terms__sort" aria-hidden="true"><?php echo esc_html( $sort_marker( 'name' ) ); ?></span>
								</a>
							</th>
							<th scope="col" class="nowcrm-terms__col-count">
								<?php
								echo esc_html(
									TermRepository::TYPE_TAG === $type
										? __( '已訂閱', 'now-crm' )
										: __( '已訂閱／總數', 'now-crm' )
								);
								?>
							</th>
							<th scope="col" class="nowcrm-terms__col-created" aria-sort="<?php echo esc_attr( $aria_sort( 'created_at' ) ); ?>">
								<a href="<?php echo esc_url( $column_url( 'created_at' ) ); ?>">
									<?php esc_html_e( '建立時間', 'now-crm' ); ?>
									<span class="nowcrm-terms__sort" aria-hidden="true"><?php echo esc_html( $sort_marker( 'created_at' ) ); ?></span>
								</a>
							</th>
							<th scope="col" class="nowcrm-terms__col-actions">
								<span class="screen-reader-text"><?php esc_html_e( '操作', 'now-crm' ); ?></span>
							</th>
						</tr>
					</thead>
					<tbody>
						<?php if ( array() === $items ) : ?>
							<tr>
								<td colspan="6">
									<div class="nowcrm-terms__empty">
										<?php if ( '' !== $search ) : ?>
											<p>
												<?php
												echo esc_html(
													sprintf(
														/* translators: %s: search query. */
														__( '沒有符合「%s」的項目。', 'now-crm' ),
														$search
													)
												);
												?>
											</p>
											<p class="nowcrm-terms__empty-actions">
												<a class="button" href="<?php echo esc_url( $clear_search_url ); ?>">
													<?php esc_html_e( '清除搜尋', 'now-crm' ); ?>
												</a>
											</p>
										<?php else : ?>
											<p>
												<?php
												echo esc_html(
													sprintf(
														/* translators: %s: list or tag label. */
														__( '尚未建立任何%1$s。', 'now-crm' ),
														$label
													)
												);
												?>
											</p>
											<p class="nowcrm-terms__empty-actions">
												<a class="button button-primary" href="<?php echo esc_url( $create_url ); ?>" data-nowcrm-open-create>
													<?php
													echo esc_html(
														sprintf(
															/* translators: %s: list or tag label. */
															__( '新增%s', 'now-crm' ),
															$label
														)
													);
													?>
												</a>
											</p>
										<?php endif; ?>
									</div>
								</td>
							</tr>
						<?php else : ?>
							<?php foreach ( $items as $item ) : ?>
								<?php
								$term_id        = (int) $item['id'];
								$name           = (string) $item['name'];
								$slug           = (string) $item['slug'];
								$contact_count  = (int) $item['contact_count'];
								$subscribed     = (int) $item['subscribed_count'];
								$description    = trim( wp_strip_all_tags( (string) ( $item['description'] ?? '' ) ) );
								$contact_url    = Menu::page_url( Menu::SLUG, array( 'term_id' => $term_id ) );
								$subscribed_url = Menu::page_url(
									Menu::SLUG,
									array(
										'term_id' => $term_id,
										'status'  => Status::SUBSCRIBED,
									)
								);
								$edit_url       = Menu::page_url( $page_slug, array_merge( $view_args, array( 'edit' => $term_id ) ) );
								$confirm        = sprintf(
									/* translators: 1: list or tag label, 2: term name. */
									__( '確定要刪除此%1$s「%2$s」嗎？聯絡人關聯會一併移除。', 'now-crm' ),
									$label,
									$name
								);
								?>
								<tr>
									<th scope="row" class="nowcrm-terms__check">
										<input type="checkbox" data-nowcrm-terms-row-check
											name="term_ids[]" form="nowcrm-terms-bulk"
											value="<?php echo esc_attr( (string) $term_id ); ?>" />
									</th>
									<td class="nowcrm-terms__col-id">
										<span class="nowcrm-terms__id"><?php echo esc_html( (string) $term_id ); ?></span>
									</td>
									<td class="nowcrm-terms__title">
										<a class="nowcrm-terms__title-link" href="<?php echo esc_url( $edit_url ); ?>">
											<?php echo esc_html( $name ); ?>
										</a>
										<span class="nowcrm-terms__meta">
											<code class="nowcrm-terms__slug"><?php echo esc_html( $slug ); ?></code>
											<?php if ( '' !== $description ) : ?>
												<span class="nowcrm-terms__description">
													<?php echo esc_html( wp_html_excerpt( $description, 90, '…' ) ); ?>
												</span>
											<?php endif; ?>
										</span>
									</td>
									<td class="nowcrm-terms__count-cell">
										<?php if ( TermRepository::TYPE_TAG === $type ) : ?>
											<a href="<?php echo esc_url( $subscribed_url ); ?>"
												aria-label="<?php echo esc_attr( sprintf( /* translators: %d: number of subscribed contacts. */ __( '已訂閱 %d 位聯絡人', 'now-crm' ), $subscribed ) ); ?>">
												<?php echo esc_html( (string) $subscribed ); ?>
											</a>
										<?php else : ?>
											<span class="nowcrm-terms__group-count">
												<span aria-hidden="true">(</span>
												<a href="<?php echo esc_url( $subscribed_url ); ?>"
													aria-label="<?php echo esc_attr( sprintf( /* translators: %d: number of subscribed contacts. */ __( '已訂閱 %d 位聯絡人', 'now-crm' ), $subscribed ) ); ?>">
													<?php echo esc_html( (string) $subscribed ); ?>
												</a>
												<span class="nowcrm-terms__sep" aria-hidden="true"> / </span>
												<a href="<?php echo esc_url( $contact_url ); ?>"
													aria-label="<?php echo esc_attr( sprintf( /* translators: %d: total number of contacts. */ __( '共 %d 位聯絡人', 'now-crm' ), $contact_count ) ); ?>">
													<?php echo esc_html( (string) $contact_count ); ?>
												</a>
												<span aria-hidden="true">)</span>
											</span>
										<?php endif; ?>
									</td>
									<td class="nowcrm-terms__col-created">
										<time class="nowcrm-terms__created" datetime="<?php echo esc_attr( (string) $item['created_at'] ); ?>">
											<?php echo esc_html( Menu::format_datetime( (string) $item['created_at'] ) ); ?>
										</time>
									</td>
									<td class="nowcrm-terms__col-actions">
										<details class="nowcrm-more nowcrm-terms__row-more">
											<summary class="nowcrm-terms__row-trigger"
												aria-label="<?php echo esc_attr( sprintf( /* translators: %s: term name. */ __( '更多操作：%s', 'now-crm' ), $name ) ); ?>">
												<span class="dashicons dashicons-ellipsis" aria-hidden="true"></span>
											</summary>
											<div class="nowcrm-more__menu nowcrm-terms__row-menu">
												<a href="<?php echo esc_url( $edit_url ); ?>"><?php esc_html_e( '編輯', 'now-crm' ); ?></a>
												<form method="post" action="<?php echo esc_url( $form_url ); ?>"
													onsubmit="return confirm('<?php echo esc_js( $confirm ); ?>');">
													<input type="hidden" name="action" value="nowcrm_delete_term" />
													<input type="hidden" name="term_id" value="<?php echo esc_attr( (string) $term_id ); ?>" />
													<input type="hidden" name="type" value="<?php echo esc_attr( $type ); ?>" />
													<?php wp_nonce_field( 'nowcrm_delete_term' ); ?>
													<button type="submit" class="nowcrm-more__button nowcrm-terms__danger">
														<?php esc_html_e( '刪除', 'now-crm' ); ?>
													</button>
												</form>
											</div>
										</details>
									</td>
								</tr>
							<?php endforeach; ?>
						<?php endif; ?>
					</tbody>
				</table>
			</div>

			<div class="nowcrm-terms__footer">
				<p class="nowcrm-terms__summary">
					<?php
					if ( $total > 0 ) {
						printf(
							/* translators: 1: first row number, 2: last row number, 3: total terms. */
							esc_html__( '顯示 %1$d–%2$d，共 %3$d 筆', 'now-crm' ),
							(int) $first_row,
							(int) $last_row,
							(int) $total
						);
					} else {
						esc_html_e( '共 0 筆', 'now-crm' );
					}
					?>
				</p>

				<div class="nowcrm-terms__page-sizes" aria-label="<?php esc_attr_e( '每頁筆數', 'now-crm' ); ?>">
					<span><?php esc_html_e( '每頁', 'now-crm' ); ?></span>
					<?php foreach ( self::PAGE_SIZES as $size ) : ?>
						<a class="nowcrm-terms__page-size <?php echo $per_page === $size ? 'is-active' : ''; ?>"
							href="<?php echo esc_url( Menu::page_url( $page_slug, array_merge( $state_args, array( 'per_page' => $size ) ) ) ); ?>"
							<?php echo $per_page === $size ? 'aria-current="true"' : ''; ?>>
							<?php echo esc_html( (string) $size ); ?>
						</a>
					<?php endforeach; ?>
				</div>

				<?php if ( $total_pages > 1 ) : ?>
					<div class="nowcrm-terms__pagination">
						<?php
						echo wp_kses_post(
							paginate_links(
								array(
									'base'      => add_query_arg(
										$state_args,
										admin_url( 'admin.php?page=' . rawurlencode( $page_slug ) )
									) . '&paged=%#%',
									'format'    => '',
									'current'   => $paged,
									'total'     => $total_pages,
									'prev_text' => '‹',
									'next_text' => '›',
								)
							)
						);
						?>
					</div>
				<?php endif; ?>
			</div>

			<?php if ( array() !== $items ) : ?>
				<form id="nowcrm-terms-bulk" class="nowcrm-terms__bulk" method="post"
					action="<?php echo esc_url( $form_url ); ?>" data-nowcrm-terms-bulk hidden
					onsubmit="return confirm('<?php echo esc_js( __( '確定要刪除選取的項目嗎？聯絡人關聯會一併移除。', 'now-crm' ) ); ?>');">
					<input type="hidden" name="action" value="nowcrm_delete_terms" />
					<input type="hidden" name="type" value="<?php echo esc_attr( $type ); ?>" />
					<?php wp_nonce_field( 'nowcrm_delete_terms' ); ?>

					<span class="nowcrm-terms__selection" data-nowcrm-terms-selection><?php esc_html_e( '已選取 0 個項目', 'now-crm' ); ?></span>
					<button type="submit" class="button nowcrm-terms__danger" disabled="disabled">
						<?php esc_html_e( '刪除選取', 'now-crm' ); ?>
					</button>
					<button type="button" class="button nowcrm-terms__clear-selection" data-nowcrm-terms-clear>
						<?php esc_html_e( '清除選取', 'now-crm' ); ?>
					</button>
				</form>
			<?php endif; ?>

			<dialog class="nowcrm-terms__dialog" id="nowcrm-terms-dialog"
				aria-labelledby="nowcrm-terms-dialog-title"
				data-mode="<?php echo esc_attr( $dialog_mode ); ?>"
				data-id="<?php echo esc_attr( (string) $dialog_id ); ?>"
				<?php echo $dialog_open ? 'open' : ''; ?>>
				<form method="post" action="<?php echo esc_url( $form_url ); ?>" id="nowcrm-terms-form" class="nowcrm-terms__form">
					<input type="hidden" name="action" value="<?php echo esc_attr( null !== $editing ? 'nowcrm_update_term' : 'nowcrm_create_term' ); ?>" />
					<?php if ( null !== $editing ) : ?>
						<input type="hidden" name="term_id" value="<?php echo esc_attr( (string) (int) $editing['id'] ); ?>" />
					<?php endif; ?>
					<input type="hidden" name="type" value="<?php echo esc_attr( $type ); ?>" />
					<?php wp_nonce_field( null !== $editing ? 'nowcrm_update_term' : 'nowcrm_create_term' ); ?>

					<div class="nowcrm-terms__dialog-head">
						<h2 id="nowcrm-terms-dialog-title">
							<?php
							echo esc_html(
								null !== $editing
									? sprintf(
										/* translators: %s: list or tag label. */
										__( '編輯%1$s', 'now-crm' ),
										$label
									)
									: sprintf(
										/* translators: %s: list or tag label. */
										__( '新增%1$s', 'now-crm' ),
										$label
									)
							);
							?>
						</h2>
						<button type="button" class="nowcrm-terms__dialog-close" data-nowcrm-close-terms
							aria-label="<?php esc_attr_e( '關閉', 'now-crm' ); ?>">
							<span class="dashicons dashicons-no-alt" aria-hidden="true"></span>
						</button>
					</div>

					<div class="nowcrm-terms__dialog-body">
						<?php if ( $dialog_open ) : ?>
							<?php Actions::render_notice(); ?>
						<?php endif; ?>

						<p class="nowcrm-terms__error" data-nowcrm-terms-error role="alert" hidden></p>

						<label class="nowcrm-terms__field">
							<span><?php esc_html_e( '名稱', 'now-crm' ); ?></span>
							<input type="text" name="name" required="required" autocomplete="off"
								value="<?php echo esc_attr( null !== $editing ? (string) $editing['name'] : '' ); ?>" />
						</label>

						<label class="nowcrm-terms__field">
							<span><?php esc_html_e( 'Slug（選填）', 'now-crm' ); ?></span>
							<input type="text" name="slug" autocomplete="off"
								placeholder="<?php echo esc_attr( $default_slug_hint ); ?>"
								value="<?php echo esc_attr( null !== $editing ? (string) $editing['slug'] : '' ); ?>" />
						</label>
						<p class="nowcrm-terms__hint"><?php echo esc_html( $default_slug_hint ); ?></p>

						<label class="nowcrm-terms__field">
							<span><?php esc_html_e( '內部說明（選填）', 'now-crm' ); ?></span>
							<textarea name="description" rows="3"><?php echo esc_textarea( null !== $editing ? (string) ( $editing['description'] ?? '' ) : '' ); ?></textarea>
						</label>
					</div>

					<div class="nowcrm-terms__dialog-foot">
						<a class="button" href="<?php echo esc_url( $return_url ); ?>" data-nowcrm-close-terms>
							<?php esc_html_e( '取消', 'now-crm' ); ?>
						</a>
						<button type="submit" class="button button-primary">
							<?php echo esc_html( null !== $editing ? __( '儲存', 'now-crm' ) : __( '建立', 'now-crm' ) ); ?>
						</button>
					</div>
				</form>
			</dialog>

			<dialog class="nowcrm-terms__dialog nowcrm-terms__dialog--import" id="nowcrm-terms-import"
				aria-labelledby="nowcrm-terms-import-title"
				<?php echo $import_open ? 'open' : ''; ?>>
				<form method="post" action="<?php echo esc_url( $form_url ); ?>" enctype="multipart/form-data">
					<input type="hidden" name="action" value="nowcrm_import_terms" />
					<input type="hidden" name="type" value="<?php echo esc_attr( $type ); ?>" />
					<?php wp_nonce_field( 'nowcrm_import_terms' ); ?>

					<div class="nowcrm-terms__dialog-head">
						<h2 id="nowcrm-terms-import-title">
							<?php
							echo esc_html(
								sprintf(
									/* translators: %s: list or tag label. */
									__( '匯入%1$s', 'now-crm' ),
									$label
								)
							);
							?>
						</h2>
						<button type="button" class="nowcrm-terms__dialog-close" data-nowcrm-close-import
							aria-label="<?php esc_attr_e( '關閉', 'now-crm' ); ?>">
							<span class="dashicons dashicons-no-alt" aria-hidden="true"></span>
						</button>
					</div>

					<div class="nowcrm-terms__dialog-body">
						<?php if ( $import_open ) : ?>
							<?php Actions::render_notice(); ?>
						<?php endif; ?>

						<label class="nowcrm-terms__field">
							<span><?php esc_html_e( 'CSV 檔', 'now-crm' ); ?></span>
							<input type="file" name="nowcrm_terms_csv" accept=".csv,text/csv" required="required" />
						</label>
						<p class="nowcrm-terms__hint">
							<?php esc_html_e( '第一列為欄位標題：name（或 title）、slug、description。相同 slug 會更新名稱並保留原有說明與聯絡人關聯；資料不正確的列會略過，每次最多 500 列。', 'now-crm' ); ?>
						</p>
					</div>

					<div class="nowcrm-terms__dialog-foot">
						<a class="button" href="<?php echo esc_url( $return_url ); ?>" data-nowcrm-close-import>
							<?php esc_html_e( '取消', 'now-crm' ); ?>
						</a>
						<button type="submit" class="button button-primary"><?php esc_html_e( '匯入', 'now-crm' ); ?></button>
					</div>
				</form>
			</dialog>
		</div>
		<?php
	}

	/**
	 * Stream a term CSV for one type.
	 *
	 * The type is fixed by the request and the nonce binds to it, so a caller
	 * can never export the other type. Rows come from bounded repository pages.
	 */
	public static function export(): void {
		if ( ! Capabilities::can_manage_contacts() ) {
			wp_die(
				esc_html__( '您沒有權限匯出資料。', 'now-crm' ),
				'',
				array( 'response' => 403 )
			);
		}

		$type = isset( $_GET['type'] ) && is_string( $_GET['type'] )
			? sanitize_key( wp_unslash( $_GET['type'] ) )
			: '';

		if ( ! in_array( $type, TermRepository::types(), true ) ) {
			wp_die(
				esc_html__( '名單或標籤類型不正確。', 'now-crm' ),
				'',
				array( 'response' => 400 )
			);
		}

		check_admin_referer( self::ACTION_EXPORT . '_' . $type );

		$search  = isset( $_GET['s'] ) && is_string( $_GET['s'] )
			? sanitize_text_field( wp_unslash( $_GET['s'] ) )
			: '';
		$orderby = isset( $_GET['orderby'] ) && is_string( $_GET['orderby'] )
			? sanitize_key( wp_unslash( $_GET['orderby'] ) )
			: 'id';

		if ( ! in_array( $orderby, self::SORTABLE, true ) ) {
			$orderby = 'id';
		}

		$requested_order = isset( $_GET['order'] ) && is_string( $_GET['order'] )
			? strtolower( sanitize_key( wp_unslash( $_GET['order'] ) ) )
			: '';
		$order           = in_array( $requested_order, array( 'asc', 'desc' ), true )
			? strtoupper( $requested_order )
			: ( in_array( $orderby, array( 'name', 'slug' ), true ) ? 'ASC' : 'DESC' );

		$repository = new TermRepository();
		$filename   = ( TermRepository::TYPE_TAG === $type ? 'nowcrm-tags-' : 'nowcrm-lists-' )
			. gmdate( 'Ymd-Hi' ) . '.csv';

		while ( ob_get_level() > 0 ) {
			@ob_end_clean();
		}

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

		$output = fopen( 'php://output', 'wb' );

		if ( false === $output ) {
			exit;
		}

		fputcsv( $output, self::export_header(), ',', '"', '\\' );

		$written = 0;
		$page    = 1;

		do {
			$result = $repository->paginate(
				self::query_args( $type, $search, $orderby, $order, $page, self::EXPORT_PAGE_SIZE )
			);
			$items  = $result['items'];

			foreach ( $items as $item ) {
				if ( $written >= self::MAX_EXPORT_ROWS ) {
					break 2;
				}

				fputcsv( $output, self::export_row( $item ), ',', '"', '\\' );
				++$written;
			}

			++$page;
		} while ( count( $items ) === self::EXPORT_PAGE_SIZE && $written < self::MAX_EXPORT_ROWS );

		// phpcs:ignore WordPress.WP.AlternativeFunctions -- the CSV export writes to php://output; WP_Filesystem cannot stream the response handle.
		fclose( $output );
		exit;
	}

	/**
	 * CSV header cells for one term export.
	 *
	 * @return string[]
	 */
	public static function export_header(): array {
		return array(
			'ID',
			__( '類型', 'now-crm' ),
			__( '名稱', 'now-crm' ),
			'Slug',
			__( '說明', 'now-crm' ),
			__( '聯絡人數', 'now-crm' ),
			__( '已訂閱', 'now-crm' ),
			__( '建立時間', 'now-crm' ),
			__( '更新時間', 'now-crm' ),
		);
	}

	/**
	 * One safe CSV row. Contact fields are never present.
	 *
	 * @param array<string, mixed> $term Term row with membership counts.
	 * @return string[]
	 */
	public static function export_row( array $term ): array {
		return array_map(
			array( CsvExport::class, 'safe_cell' ),
			array(
				(string) (int) ( $term['id'] ?? 0 ),
				(string) ( $term['type'] ?? '' ),
				(string) ( $term['name'] ?? '' ),
				(string) ( $term['slug'] ?? '' ),
				(string) ( $term['description'] ?? '' ),
				(string) (int) ( $term['contact_count'] ?? 0 ),
				(string) (int) ( $term['subscribed_count'] ?? 0 ),
				(string) ( $term['created_at'] ?? '' ),
				(string) ( $term['updated_at'] ?? '' ),
			)
		);
	}

	/**
	 * Repository arguments for one table page.
	 *
	 * @return array<string, mixed>
	 */
	private static function query_args( string $type, string $search, string $orderby, string $order, int $page, int $per_page ): array {
		return array(
			'type'     => $type,
			'search'   => $search,
			'orderby'  => $orderby,
			'order'    => $order,
			'page'     => max( 1, $page ),
			'per_page' => max( 1, min( self::MAX_PER_PAGE, $per_page ) ),
		);
	}
}
