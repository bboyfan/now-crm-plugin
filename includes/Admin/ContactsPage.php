<?php
/**
 * Contacts list screen.
 *
 * @package NowCRM
 */

namespace NowCRM\Admin;

use NowCRM\Contacts\AdvancedFilters;
use NowCRM\Contacts\ContactRepository;
use NowCRM\Contacts\CsvExport;
use NowCRM\Contacts\CustomFieldValues;
use NowCRM\Contacts\ProfileFields;
use NowCRM\Contacts\Status;
use NowCRM\Support\Capabilities;
use NowCRM\Terms\TermRepository;

defined( 'ABSPATH' ) || exit;

final class ContactsPage {

	private const PER_PAGE = 10;

	/**
	 * Page sizes that stay inside the selected-action ceiling.
	 */
	private const PAGE_SIZES = array( 10, 20, 50, 100, 150 );

	/**
	 * Sortable keys. Everything else falls back to the newest ID.
	 */
	private const SORTABLE = array( 'id', 'first_name', 'status' );

	/**
	 * Render the contacts table, filters, and the create drawer.
	 */
	public static function render(): void {
		if ( ! Capabilities::can_manage_contacts() ) {
			wp_die(
				esc_html__( '您沒有權限檢視聯絡人。', 'now-crm' ),
				'',
				array( 'response' => 403 )
			);
		}

		$repository = new ContactRepository();
		$terms      = new TermRepository();

		$search = sanitize_text_field( Menu::query( 's' ) );
		$paged  = max( 1, absint( Menu::query( 'paged', '1' ) ) );

		$per_page = absint( Menu::query( 'per_page', (string) self::PER_PAGE ) );

		if ( ! in_array( $per_page, self::PAGE_SIZES, true ) ) {
			$per_page = self::PER_PAGE;
		}

		$orderby = sanitize_key( Menu::query( 'orderby', 'id' ) );

		if ( ! in_array( $orderby, self::SORTABLE, true ) ) {
			$orderby = 'id';
		}

		$order = 'ASC' === strtoupper( Menu::query( 'order', 'DESC' ) ) ? 'ASC' : 'DESC';

		$mode_request = sanitize_key( Menu::query( 'mode' ) );
		$has_plan     = isset( $_GET[ ContactAdvancedFilters::INPUT ] ) || isset( $_GET[ ContactAdvancedFilters::REMOVE ] );
		$mode         = 'advanced' === $mode_request || ( $has_plan && 'simple' !== $mode_request )
			? 'advanced'
			: 'simple';
		$applied_search = 'advanced' === $mode ? '' : $search;

		$status = sanitize_key( Menu::query( 'status' ) );

		if ( '' !== $status && ! Status::is_valid( $status ) ) {
			$status = '';
		}

		$term = absint( Menu::query( 'term_id' ) );

		$simple_statuses = self::valid_statuses( self::query_array( 'statuses' ) );

		if ( array() === $simple_statuses && '' !== $status ) {
			$simple_statuses = array( $status );
		}

		$list_ids = self::query_ids( self::query_array( 'lists' ) );
		$tag_ids  = self::query_ids( self::query_array( 'tags' ) );

		// A legacy term link keeps its exact meaning when no grouped control is
		// used. With grouped controls, the term joins its own category.
		if ( $term > 0 && ( array() !== $list_ids || array() !== $tag_ids ) ) {
			$legacy = $terms->get( $term );

			if ( $legacy ) {
				if ( TermRepository::TYPE_LIST === (string) $legacy['type'] ) {
					$list_ids[] = $term;
				} else {
					$tag_ids[] = $term;
				}
			}
		}

		$advanced       = null;
		$advanced_error = null;
		$simple_plan    = null;
		$simple_error   = null;
		$filters        = null;

		if ( 'advanced' === $mode ) {
			$advanced = ContactAdvancedFilters::parse_request( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter.

			if ( is_wp_error( $advanced ) ) {
				$advanced_error = $advanced;
				$filters        = $advanced;
				$advanced       = null;
			} else {
				$filters = $advanced;
			}
		} else {
			$simple_plan = ContactAdvancedFilters::plan_from_simple( $simple_statuses, $list_ids, $tag_ids );

			if ( is_wp_error( $simple_plan ) ) {
				$simple_error = $simple_plan;
				$filters      = $simple_plan;
				$simple_plan  = null;
			} else {
				$filters = $simple_plan;
			}
		}

		$query_args = array(
			'search'   => $applied_search,
			'status'   => 'advanced' === $mode ? '' : $status,
			'term_id'  => 'advanced' === $mode ? 0 : $term,
			'filters'  => $filters,
			'page'     => $paged,
			'per_page' => $per_page,
			'orderby'  => $orderby,
			'order'    => $order,
		);

		$result = $repository->search( $query_args );

		$total       = (int) $result['total'];
		$total_pages = max( 1, (int) ceil( $total / $per_page ) );

		if ( $paged > $total_pages ) {
			$paged             = $total_pages;
			$query_args['page'] = $paged;
			$result            = $repository->search( $query_args );
			$total             = (int) $result['total'];
		}

		$items = $result['items'];

		$counts    = $repository->count_by_status();
		$all_lists = $terms->all( TermRepository::TYPE_LIST );
		$all_tags  = $terms->all( TermRepository::TYPE_TAG );

		$base_url   = Menu::page_url( Menu::SLUG );
		$form_url   = admin_url( 'admin-post.php' );
		$import_url = Menu::page_url( ContactCsvAdmin::SLUG, array( 'tab' => 'import' ) );

		$blocked = null !== $advanced_error || null !== $simple_error;

		// Applied simple state. Advanced mode keeps it only so a user can go
		// back without losing the previous compact filters.
		$simple_state = array();

		if ( '' !== $status ) {
			$simple_state['status'] = $status;
		} elseif ( count( $simple_statuses ) > 1 ) {
			$simple_state['statuses'] = $simple_statuses;
		}

		if ( $term > 0 && array() === $list_ids && array() === $tag_ids ) {
			$simple_state['term_id'] = $term;
		}

		if ( array() !== $list_ids ) {
			$simple_state['lists'] = $list_ids;
		}

		if ( array() !== $tag_ids ) {
			$simple_state['tags'] = $tag_ids;
		}

		$applied_args = array();

		if ( '' !== $applied_search ) {
			$applied_args['s'] = $applied_search;
		}

		$applied_args += $simple_state;

		if ( 'advanced' === $mode ) {
			$applied_args['mode'] = 'advanced';

			if ( null !== $advanced ) {
				$applied_args[ ContactAdvancedFilters::INPUT ] = AdvancedFilters::to_query( $advanced );
			}
		}

		if ( self::PER_PAGE !== $per_page ) {
			$applied_args['per_page'] = $per_page;
		}

		if ( 'id' !== $orderby || 'DESC' !== $order ) {
			$applied_args['orderby'] = $orderby;
			$applied_args['order']   = $order;
		}

		$export_args = array(
			'tab'      => 'export',
			'scope'    => 'filtered',
			's'        => $applied_search,
			'per_page' => $per_page,
		);

		if ( 'advanced' === $mode && null !== $advanced ) {
			$export_args[ ContactAdvancedFilters::INPUT ] = AdvancedFilters::to_query( $advanced );
		} elseif ( 'simple' === $mode ) {
			$export_args += $simple_state;
		}

		$export_url = $blocked
			? ''
			: Menu::page_url( ContactCsvAdmin::SLUG, $export_args );

		$sortable_urls = array();

		foreach ( array( 'first_name', 'status' ) as $key ) {
			$next_order = $orderby === $key && 'ASC' === $order ? 'DESC' : 'ASC';
			$sortable_urls[ $key ] = Menu::page_url(
				Menu::SLUG,
				array_merge(
					$applied_args,
					array(
						'orderby' => $key,
						'order'   => $next_order,
					)
				)
			);
		}

		$mode_url = Menu::page_url(
			Menu::SLUG,
			array_merge(
				array(),
				$simple_state,
				'simple' === $mode ? array( 'mode' => 'advanced' ) : array(),
				array(
					'per_page' => $per_page,
					'orderby'  => $orderby,
					'order'    => $order,
				)
			)
		);
		$simple_mode_url = Menu::page_url(
			Menu::SLUG,
			array_merge(
				array(),
				$simple_state,
				array(
					'mode'     => 'simple',
					'per_page' => $per_page,
					'orderby'  => $orderby,
					'order'    => $order,
				)
			)
		);
		$clear_url = Menu::page_url( Menu::SLUG, 'advanced' === $mode ? array( 'mode' => 'advanced' ) : array() );

		$has_filters = '' !== $applied_search
			|| null !== $filters
			|| $term > 0
			|| array() !== $simple_statuses
			|| array() !== $list_ids
			|| array() !== $tag_ids;

		$drawer_open = '1' === Menu::query( 'nowcrm_drawer' );
		$draft       = $drawer_open ? get_transient( 'nowcrm_contact_draft_' . get_current_user_id() ) : null;

		if ( $drawer_open ) {
			delete_transient( 'nowcrm_contact_draft_' . get_current_user_id() );
		}

		$draft         = is_array( $draft ) ? $draft : array();
		$draft_custom  = is_array( $draft['custom_data'] ?? null ) ? $draft['custom_data'] : array();
		$draft_terms   = array_map( 'intval', (array) ( $draft['term_ids'] ?? array() ) );
		$draft_status  = (string) ( $draft['status'] ?? Status::SUBSCRIBED );
		$draft_focus   = (string) ( $draft['focus'] ?? '' );
		$draft_consent = ! empty( $draft['consent'] );
		$drawer_value  = static fn( string $key ): string => (string) ( $draft[ $key ] ?? '' );
		$address_open  = array() !== array_filter(
			array_map( $drawer_value, array( 'address_line_1', 'address_line_2', 'city', 'state', 'postal_code', 'country' ) )
		) || in_array( $draft_focus, array( 'address_line_1', 'address_line_2', 'city', 'state', 'postal_code', 'country' ), true );
		?>
		<div class="wrap nowcrm-wrap nowcrm-contacts-page">
			<?php self::render_nav( 'all' ); ?>

			<div class="nowcrm-contacts__head">
				<div>
					<h1 class="wp-heading-inline"><?php esc_html_e( '聯絡人', 'now-crm' ); ?></h1>
					<span class="nowcrm-contacts__count">
						<?php
						printf(
							/* translators: %d: number of contacts. */
							esc_html__( '（%d）', 'now-crm' ),
							(int) $total
						);
						?>
					</span>
				</div>
				<div class="nowcrm-contacts__toolbar">
					<details class="nowcrm-more">
						<summary class="button"><?php esc_html_e( '更多操作', 'now-crm' ); ?></summary>
						<div class="nowcrm-more__menu">
							<?php if ( ! $blocked ) : ?>
								<button type="button" class="nowcrm-more__button" data-nowcrm-open-export="filtered">
									<?php esc_html_e( '匯出', 'now-crm' ); ?>
								</button>
								<noscript>
									<a href="<?php echo esc_url( $export_url ); ?>"><?php esc_html_e( '匯出（CSV 設定）', 'now-crm' ); ?></a>
								</noscript>
							<?php endif; ?>
							<button type="button" class="nowcrm-more__button" data-nowcrm-reset-table>
								<?php esc_html_e( '重設表格偏好', 'now-crm' ); ?>
							</button>
						</div>
					</details>
					<a class="button" href="<?php echo esc_url( $import_url ); ?>"><?php esc_html_e( '匯入', 'now-crm' ); ?></a>
					<button type="button" class="button button-primary" data-nowcrm-open-contact>
						<?php esc_html_e( '新增聯絡人', 'now-crm' ); ?>
					</button>
				</div>
			</div>
			<hr class="wp-header-end" />

			<?php if ( ! $drawer_open ) : ?>
				<?php Actions::render_notice(); ?>
			<?php endif; ?>
			<?php ContactBulkActions::render_result_notice(); ?>
			<?php if ( null !== $simple_error ) : ?>
				<div class="notice notice-error inline">
					<p><?php echo esc_html( $simple_error->get_error_message() ); ?></p>
				</div>
			<?php endif; ?>

			<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>"
				class="nowcrm-contacts-filters" id="nowcrm-contacts-filters">
				<input type="hidden" name="page" value="<?php echo esc_attr( Menu::SLUG ); ?>" />
				<input type="hidden" name="per_page" value="<?php echo esc_attr( (string) $per_page ); ?>" />
				<input type="hidden" name="orderby" value="<?php echo esc_attr( $orderby ); ?>" />
				<input type="hidden" name="order" value="<?php echo esc_attr( $order ); ?>" />

				<?php if ( 'advanced' === $mode ) : ?>
					<input type="hidden" name="mode" value="advanced" />
					<?php self::hidden_fields( $simple_state ); ?>
					<?php self::hidden_condition_fields( $advanced ); ?>
				<?php endif; ?>

				<label class="nowcrm-contacts-search">
					<span class="screen-reader-text"><?php esc_html_e( '搜尋聯絡人', 'now-crm' ); ?></span>
					<input type="search" name="s" value="<?php echo esc_attr( $applied_search ); ?>"
						placeholder="<?php esc_attr_e( '搜尋姓名、Email、電話或 #ID', 'now-crm' ); ?>"
						<?php disabled( $blocked || 'advanced' === $mode ); ?> />
					<button type="submit" class="button" <?php disabled( $blocked || 'advanced' === $mode ); ?>>
						<?php esc_html_e( '搜尋', 'now-crm' ); ?>
					</button>
					<?php if ( '' !== $applied_search && ! $blocked ) : ?>
						<a class="nowcrm-filter-clear" href="<?php echo esc_url( Menu::page_url( Menu::SLUG, array_merge( $applied_args, array( 's' => '' ) ) ) ); ?>">
							<?php esc_html_e( '清除搜尋', 'now-crm' ); ?>
						</a>
					<?php endif; ?>
				</label>

				<label class="nowcrm-contacts-status">
					<span class="screen-reader-text"><?php esc_html_e( '狀態篩選', 'now-crm' ); ?></span>
					<select name="status" data-nowcrm-status-filter <?php disabled( 'advanced' === $mode || $blocked ); ?>>
						<option value=""><?php echo 'advanced' === $mode ? esc_html__( '狀態（進階模式）', 'now-crm' ) : esc_html__( '全部狀態', 'now-crm' ); ?></option>
						<?php if ( count( $simple_statuses ) > 1 && 'simple' === $mode ) : ?>
							<option value="" selected="selected" disabled="disabled">
								<?php
								printf(
									/* translators: %d: number of selected statuses. */
									esc_html__( '多個狀態（%d）', 'now-crm' ),
									count( $simple_statuses )
								);
								?>
							</option>
						<?php endif; ?>
						<?php foreach ( Status::all() as $option ) : ?>
							<option value="<?php echo esc_attr( $option ); ?>" <?php selected( $status, $option ); ?>>
								<?php
								echo esc_html(
									sprintf(
										'%1$s (%2$d)',
										Status::label( $option ),
										(int) ( $counts[ $option ] ?? 0 )
									)
								);
								?>
							</option>
						<?php endforeach; ?>
					</select>
				</label>

				<details class="nowcrm-popover nowcrm-filter-popover<?php echo ( 'advanced' === $mode || $blocked ) ? ' is-disabled' : ''; ?>">
					<summary class="nowcrm-filter-btn">
						<?php
						printf(
							/* translators: %d: number of selected lists. */
							esc_html__( '名單 %d', 'now-crm' ),
							count( $list_ids )
						);
						?>
					</summary>
					<div class="nowcrm-popover__panel">
						<?php if ( array() === $all_lists ) : ?>
							<p class="description"><?php esc_html_e( '尚無名單。', 'now-crm' ); ?></p>
						<?php else : ?>
							<?php foreach ( $all_lists as $list ) : ?>
								<label class="nowcrm-popover__option">
									<input type="checkbox" name="lists[]" value="<?php echo esc_attr( (string) (int) $list['id'] ); ?>"
										<?php checked( in_array( (int) $list['id'], $list_ids, true ) ); ?>
										<?php disabled( 'advanced' === $mode || $blocked ); ?> />
									<?php echo esc_html( (string) $list['name'] ); ?>
								</label>
							<?php endforeach; ?>
						<?php endif; ?>
						<button type="submit" class="button button-small" <?php disabled( 'advanced' === $mode || $blocked ); ?>>
							<?php esc_html_e( '套用名單', 'now-crm' ); ?>
						</button>
					</div>
				</details>

				<details class="nowcrm-popover nowcrm-filter-popover">
					<summary class="nowcrm-filter-btn">
						<?php
						printf(
							/* translators: %d: number of selected tags. */
							esc_html__( '標籤 %d', 'now-crm' ),
							count( $tag_ids )
						);
						?>
					</summary>
					<div class="nowcrm-popover__panel">
						<?php if ( array() === $all_tags ) : ?>
							<p class="description"><?php esc_html_e( '尚無標籤。', 'now-crm' ); ?></p>
						<?php else : ?>
							<?php foreach ( $all_tags as $tag ) : ?>
								<label class="nowcrm-popover__option">
									<input type="checkbox" name="tags[]" value="<?php echo esc_attr( (string) (int) $tag['id'] ); ?>"
										<?php checked( in_array( (int) $tag['id'], $tag_ids, true ) ); ?>
										<?php disabled( 'advanced' === $mode || $blocked ); ?> />
									<?php echo esc_html( (string) $tag['name'] ); ?>
								</label>
							<?php endforeach; ?>
						<?php endif; ?>
						<button type="submit" class="button button-small" <?php disabled( 'advanced' === $mode || $blocked ); ?>>
							<?php esc_html_e( '套用標籤', 'now-crm' ); ?>
						</button>
					</div>
				</details>

				<a class="nowcrm-filter-link <?php echo 'advanced' === $mode ? 'is-active' : ''; ?>"
					href="<?php echo esc_url( 'advanced' === $mode ? $simple_mode_url : $mode_url ); ?>">
					<?php echo 'advanced' === $mode ? esc_html__( '關閉進階篩選', 'now-crm' ) : esc_html__( '進階篩選', 'now-crm' ); ?>
				</a>

				<button type="submit" class="button nowcrm-filter-apply" <?php disabled( $blocked ); ?>>
					<?php esc_html_e( '套用', 'now-crm' ); ?>
				</button>

				<details class="nowcrm-popover nowcrm-view-options">
					<summary class="nowcrm-filter-btn"><?php esc_html_e( '檢視', 'now-crm' ); ?></summary>
					<div class="nowcrm-popover__panel">
						<label class="nowcrm-popover__option">
							<input type="checkbox" data-nowcrm-compact-view />
							<?php esc_html_e( '精簡檢視', 'now-crm' ); ?>
						</label>
						<fieldset class="nowcrm-view-columns">
							<legend><?php esc_html_e( '欄位', 'now-crm' ); ?></legend>
							<?php
							$view_columns = array(
								'lists'   => __( '名單', 'now-crm' ),
								'tags'    => __( '標籤', 'now-crm' ),
								'status'  => __( '狀態', 'now-crm' ),
								'orders'  => __( '訂單數', 'now-crm' ),
								'spent'   => __( '消費', 'now-crm' ),
								'created' => __( '建立時間', 'now-crm' ),
								'id'      => __( 'ID', 'now-crm' ),
							);
							$default_columns = array( 'lists', 'tags', 'status' );
							?>
							<?php foreach ( $view_columns as $column => $label ) : ?>
								<label class="nowcrm-popover__option">
									<input type="checkbox" data-nowcrm-column="<?php echo esc_attr( $column ); ?>"
										<?php checked( in_array( $column, $default_columns, true ) ); ?> />
									<?php echo esc_html( $label ); ?>
								</label>
							<?php endforeach; ?>
						</fieldset>
						<button type="button" class="button button-small" data-nowcrm-reset-table>
							<?php esc_html_e( '重設表格偏好', 'now-crm' ); ?>
						</button>
					</div>
				</details>
			</form>

			<?php if ( 'advanced' === $mode ) : ?>
				<section class="nowcrm-advanced-panel" aria-label="<?php esc_attr_e( '進階篩選', 'now-crm' ); ?>">
					<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="nowcrm-advanced-form">
						<input type="hidden" name="page" value="<?php echo esc_attr( Menu::SLUG ); ?>" />
						<input type="hidden" name="mode" value="advanced" />
						<input type="hidden" name="per_page" value="<?php echo esc_attr( (string) $per_page ); ?>" />
						<input type="hidden" name="orderby" value="<?php echo esc_attr( $orderby ); ?>" />
						<input type="hidden" name="order" value="<?php echo esc_attr( $order ); ?>" />
						<?php self::hidden_fields( $simple_state ); ?>

						<?php ContactAdvancedFilters::render( $advanced, $advanced_error ); ?>

						<div class="nowcrm-advanced-actions">
							<button type="submit" class="button button-primary"><?php esc_html_e( '套用篩選', 'now-crm' ); ?></button>
							<a class="button" href="<?php echo esc_url( $clear_url ); ?>"><?php esc_html_e( '清除篩選', 'now-crm' ); ?></a>
							<a class="button button-link" href="<?php echo esc_url( $simple_mode_url ); ?>"><?php esc_html_e( '關閉進階篩選', 'now-crm' ); ?></a>
						</div>
					</form>

					<?php if ( null !== $advanced ) : ?>
						<form method="post" action="<?php echo esc_url( $form_url ); ?>" class="nowcrm-save-segment">
							<input type="hidden" name="action" value="nowcrm_segment_save" />
							<input type="hidden" name="id" value="0" />
							<?php wp_nonce_field( 'nowcrm_segment_save' ); ?>
							<?php self::hidden_condition_fields( $advanced ); ?>
							<label>
								<span><?php esc_html_e( '分眾名稱', 'now-crm' ); ?></span>
								<input type="text" name="name" required="required" />
							</label>
							<button type="submit" class="button"><?php esc_html_e( '儲存為分眾', 'now-crm' ); ?></button>
						</form>
					<?php endif; ?>
				</section>
			<?php endif; ?>

			<?php if ( $total > 0 && ! $blocked ) : ?>
				<?php
				$bulk_confirm = "var op=this.elements.bulk_operation.value,s=this.elements.bulk_scope,n=0;"
					. "for(var i=0;i<this.elements.length;i++){var b=this.elements[i];if(b.name==='contact_ids[]'&&b.checked){n++;}}"
					. "if(!op){alert(this.dataset.messageOperation);return false;}"
					. "if(s&&s.value==='all'){return confirm(this.dataset.confirmAll.replace('%d',this.dataset.count)+(op==='delete'?' '+this.dataset.confirmDelete:''));}"
					. "if(!n){alert(this.dataset.messageSelection);return false;}"
					. "return confirm(this.dataset.confirmSelected.replace('%d',n));";
				?>
				<form id="nowcrm-contacts-bulk" method="post" action="<?php echo esc_url( $form_url ); ?>"
					class="nowcrm-bulk-form" data-nowcrm-bulk-form hidden
					data-count="<?php echo esc_attr( (string) $total ); ?>"
					data-total="<?php echo esc_attr( (string) $total ); ?>"
					data-page-count="<?php echo esc_attr( (string) count( $items ) ); ?>"
					data-has-more="<?php echo $total > count( $items ) ? '1' : '0'; ?>"
					data-message-operation="<?php esc_attr_e( '請選擇批次操作。', 'now-crm' ); ?>"
					data-message-selection="<?php esc_attr_e( '請先勾選要處理的聯絡人。', 'now-crm' ); ?>"
					<?php /* translators: %d: number of selected contacts. */ ?>
					data-confirm-selected="<?php esc_attr_e( '確定要對選取的 %d 位聯絡人執行此操作嗎？', 'now-crm' ); ?>"
					<?php /* translators: %d: number of contacts matching the current filters. */ ?>
					data-confirm-all="<?php esc_attr_e( '確定要對目前篩選的全部 %d 位聯絡人執行此操作嗎？', 'now-crm' ); ?>"
					data-confirm-delete="<?php esc_attr_e( '連結 WooCommerce 訂單的聯絡人不會被刪除。', 'now-crm' ); ?>"
					onsubmit="<?php echo esc_attr( $bulk_confirm ); ?>">
					<input type="hidden" name="action" value="nowcrm_bulk_contacts" />
					<?php wp_nonce_field( ContactBulkActions::NONCE ); ?>

					<div class="nowcrm-bulk-bar__head">
						<strong data-nowcrm-selection-label><?php esc_html_e( '0 已選取', 'now-crm' ); ?></strong>
						<button type="button" class="nowcrm-bulk-bar__close" data-nowcrm-clear-selection
							aria-label="<?php esc_attr_e( '清除選取', 'now-crm' ); ?>">×</button>
						<button type="button" class="nowcrm-bulk-bar__link" data-nowcrm-select-all hidden>
							<?php
							printf(
								/* translators: %d: number of matching contacts. */
								esc_html__( '選取全部 %d 位聯絡人', 'now-crm' ),
								(int) $total
							);
							?>
						</button>
						<button type="button" class="nowcrm-bulk-bar__link" data-nowcrm-select-page hidden>
							<?php
							printf(
								/* translators: %d: number of contacts on this page. */
								esc_html__( '只選本頁（%d）', 'now-crm' ),
								count( $items )
							);
							?>
						</button>
						<span class="nowcrm-bulk-bar__scope" data-nowcrm-scope-label></span>
						<button type="button" class="nowcrm-bulk-bar__link" data-nowcrm-export-selected hidden>
							<?php esc_html_e( '匯出選取', 'now-crm' ); ?>
						</button>
					</div>

					<div class="nowcrm-bulk-bar__fields">
					<input type="hidden" name="filter_search" value="<?php echo esc_attr( $applied_search ); ?>" />
					<input type="hidden" name="filter_status" value="<?php echo esc_attr( 'advanced' === $mode ? '' : $status ); ?>" />
					<input type="hidden" name="filter_term" value="<?php echo esc_attr( (string) ( 'advanced' === $mode ? 0 : $term ) ); ?>" />
					<input type="hidden" name="filter_mode" value="<?php echo esc_attr( $mode ); ?>" />
					<input type="hidden" name="filter_per_page" value="<?php echo esc_attr( (string) $per_page ); ?>" />
					<input type="hidden" name="filter_orderby" value="<?php echo esc_attr( $orderby ); ?>" />
					<input type="hidden" name="filter_order" value="<?php echo esc_attr( $order ); ?>" />
					<?php foreach ( $list_ids as $filter_list_id ) : ?>
						<input type="hidden" name="filter_lists[]" value="<?php echo esc_attr( (string) (int) $filter_list_id ); ?>" />
					<?php endforeach; ?>
					<?php foreach ( $tag_ids as $filter_tag_id ) : ?>
						<input type="hidden" name="filter_tags[]" value="<?php echo esc_attr( (string) (int) $filter_tag_id ); ?>" />
					<?php endforeach; ?>
					<?php foreach ( $simple_statuses as $filter_status ) : ?>
						<input type="hidden" name="filter_statuses[]" value="<?php echo esc_attr( $filter_status ); ?>" />
					<?php endforeach; ?>
					<input type="hidden" name="<?php echo esc_attr( ContactAdvancedFilters::TRANSPORT ); ?>"
						value="<?php echo esc_attr( AdvancedFilters::to_json( is_array( $filters ) ? $filters : null ) ); ?>" />
					<input type="hidden" name="filter_paged" value="<?php echo esc_attr( (string) $paged ); ?>" />
					<input type="hidden" name="expected_total" value="<?php echo esc_attr( (string) $total ); ?>" />
					<input type="hidden" name="nowcrm_bulk_token" value="" />

					<label>
						<span><?php esc_html_e( '批次操作', 'now-crm' ); ?></span>
						<select name="bulk_operation">
							<option value=""><?php esc_html_e( '選擇操作', 'now-crm' ); ?></option>
							<optgroup label="<?php esc_attr_e( '名單', 'now-crm' ); ?>">
								<option value="attach_list"><?php esc_html_e( '加入名單', 'now-crm' ); ?></option>
								<option value="detach_list"><?php esc_html_e( '移出名單', 'now-crm' ); ?></option>
							</optgroup>
							<optgroup label="<?php esc_attr_e( '標籤', 'now-crm' ); ?>">
								<option value="attach_tag"><?php esc_html_e( '加入標籤', 'now-crm' ); ?></option>
								<option value="detach_tag"><?php esc_html_e( '移出標籤', 'now-crm' ); ?></option>
							</optgroup>
							<option value="set_status"><?php esc_html_e( '設定狀態', 'now-crm' ); ?></option>
							<option value="delete"><?php esc_html_e( '永久刪除', 'now-crm' ); ?></option>
						</select>
					</label>

					<label>
						<span><?php esc_html_e( '名單（加入／移出）', 'now-crm' ); ?></span>
						<select name="bulk_list_ids[]" multiple="multiple" size="3">
							<?php foreach ( $all_lists as $list ) : ?>
								<option value="<?php echo esc_attr( (string) (int) $list['id'] ); ?>">
									<?php echo esc_html( (string) $list['name'] ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</label>

					<label>
						<span><?php esc_html_e( '標籤（加入／移出）', 'now-crm' ); ?></span>
						<select name="bulk_tag_ids[]" multiple="multiple" size="3">
							<?php foreach ( $all_tags as $tag ) : ?>
								<option value="<?php echo esc_attr( (string) (int) $tag['id'] ); ?>">
									<?php echo esc_html( (string) $tag['name'] ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</label>

					<label>
						<span><?php esc_html_e( '新狀態', 'now-crm' ); ?></span>
						<select name="bulk_status">
							<option value=""><?php esc_html_e( '不改狀態', 'now-crm' ); ?></option>
							<?php foreach ( Status::all() as $option ) : ?>
								<option value="<?php echo esc_attr( $option ); ?>">
									<?php echo esc_html( Status::label( $option ) ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</label>

					<input type="hidden" name="bulk_scope" value="selected" />

					<button type="submit" class="button button-primary" disabled="disabled">
						<?php esc_html_e( '執行批次操作', 'now-crm' ); ?>
					</button>

					<span class="description">
						<?php esc_html_e( '附加或移除只影響所選名單／標籤，其他名單與標籤保持不變。', 'now-crm' ); ?>
					</span>
					<p id="nowcrm-bulk-progress" class="description" role="status" aria-live="polite"></p>
					</div>
				</form>
				<script>
				( function () {
					var form = document.getElementById( 'nowcrm-contacts-bulk' );

					if ( ! form ) {
						return;
					}

					var button   = form.querySelector( 'button[type="submit"]' );
					var progress = document.getElementById( 'nowcrm-bulk-progress' );
					var token    = form.querySelector( 'input[name="nowcrm_bulk_token"]' );
					var current  = token ? token.value : '';
					var running  = false;
					var failed   = <?php echo wp_json_encode( __( '批次操作失敗，請重新整理後再試。', 'now-crm' ) ); ?>;

					form.addEventListener( 'change', function () {
						if ( running ) {
							return;
						}

						current = '';

						if ( token ) {
							token.value = '';
						}
					} );

					form.addEventListener( 'submit', function ( event ) {
						var scope = form.elements.bulk_scope;

						if ( event.defaultPrevented || ! scope || 'all' !== scope.value ) {
							return;
						}

						event.preventDefault();
						button.disabled = true;
						running = true;

						if ( progress ) {
							progress.textContent = <?php echo wp_json_encode( __( '準備處理…', 'now-crm' ) ); ?>;
						}

						var send = function ( body ) {
							fetch( form.action, {
								method: 'POST',
								credentials: 'same-origin',
								headers: { Accept: 'application/json' },
								body: body
							} )
								.then( function ( response ) {
									return response.json();
								} )
								.then( function ( result ) {
									if ( ! result || ! result.success ) {
										var data = result && result.data ? result.data : {};

										if ( data.redirect ) {
											window.location.assign( data.redirect );
											return;
										}

										throw new Error( data.message || failed );
									}

									var data = result.data;

									if ( data.completed && data.redirect ) {
										window.location.assign( data.redirect );
										return;
									}

									if ( ! data.token ) {
										throw new Error( failed );
									}

									current = data.token;

									if ( token ) {
										token.value = data.token;
									}

									if ( progress ) {
										progress.textContent = data.progress || '';
									}

									var next = new FormData( form );
									next.set( 'nowcrm_bulk_batch', '1' );
									next.set( 'nowcrm_bulk_token', current );
									send( next );
								} )
								.catch( function ( error ) {
									running = false;

									if ( progress ) {
										progress.textContent = error.message || failed;
									}

									button.disabled = false;
								} );
						};

						var first = new FormData( form );
						first.set( 'nowcrm_bulk_batch', '1' );
						first.set( 'nowcrm_bulk_token', current );
						send( first );
					} );
				} )();
				</script>
			<?php endif; ?>

			<?php
			$first_row = $total > 0 ? ( ( $paged - 1 ) * $per_page ) + 1 : 0;
			$last_row  = min( $total, $paged * $per_page );
			$colspan   = 9;
			?>
			<div class="nowcrm-contacts-surface" data-nowcrm-contacts-surface>
				<table class="nowcrm-contacts-table" data-nowcrm-contacts-table>
					<thead>
						<tr>
							<th scope="col" class="nowcrm-col-check">
								<input type="checkbox" class="nowcrm-contact-check-all" data-nowcrm-check-page
									aria-label="<?php esc_attr_e( '全選本頁', 'now-crm' ); ?>" />
							</th>
							<th scope="col" class="nowcrm-col-contact" data-nowcrm-col="contact"
								aria-sort="<?php echo 'first_name' === $orderby ? ( 'ASC' === $order ? 'ascending' : 'descending' ) : 'none'; ?>">
								<a href="<?php echo esc_url( $sortable_urls['first_name'] ); ?>">
									<?php esc_html_e( '聯絡人', 'now-crm' ); ?>
									<span class="nowcrm-sort" aria-hidden="true"><?php echo 'first_name' === $orderby ? ( 'ASC' === $order ? '↑' : '↓' ) : '↕'; ?></span>
								</a>
							</th>
							<th scope="col" data-nowcrm-col="lists"><?php esc_html_e( '名單', 'now-crm' ); ?></th>
							<th scope="col" data-nowcrm-col="tags"><?php esc_html_e( '標籤', 'now-crm' ); ?></th>
							<th scope="col" data-nowcrm-col="status"
								aria-sort="<?php echo 'status' === $orderby ? ( 'ASC' === $order ? 'ascending' : 'descending' ) : 'none'; ?>">
								<a href="<?php echo esc_url( $sortable_urls['status'] ); ?>">
									<?php esc_html_e( '狀態', 'now-crm' ); ?>
									<span class="nowcrm-sort" aria-hidden="true"><?php echo 'status' === $orderby ? ( 'ASC' === $order ? '↑' : '↓' ) : '↕'; ?></span>
								</a>
							</th>
							<th scope="col" data-nowcrm-col="orders" hidden><?php esc_html_e( '訂單數', 'now-crm' ); ?></th>
							<th scope="col" data-nowcrm-col="spent" hidden><?php esc_html_e( '消費', 'now-crm' ); ?></th>
							<th scope="col" data-nowcrm-col="created" hidden><?php esc_html_e( '建立時間', 'now-crm' ); ?></th>
							<th scope="col" data-nowcrm-col="id" hidden><?php esc_html_e( 'ID', 'now-crm' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php if ( array() === $items ) : ?>
							<tr>
								<td colspan="<?php echo esc_attr( (string) $colspan ); ?>">
									<div class="nowcrm-empty">
										<?php if ( $blocked ) : ?>
											<p><?php esc_html_e( '篩選條件不正確，因此沒有顯示任何聯絡人。請修正或清除篩選。', 'now-crm' ); ?></p>
										<?php elseif ( $has_filters ) : ?>
											<p><?php esc_html_e( '沒有符合目前篩選的聯絡人。', 'now-crm' ); ?></p>
											<p class="nowcrm-empty__actions">
												<a class="button" href="<?php echo esc_url( $base_url ); ?>"><?php esc_html_e( '清除篩選', 'now-crm' ); ?></a>
											</p>
										<?php else : ?>
											<p><?php esc_html_e( '尚未新增任何聯絡人。', 'now-crm' ); ?></p>
											<p class="nowcrm-empty__actions">
												<button type="button" class="button button-primary" data-nowcrm-open-contact>
													<?php esc_html_e( '新增聯絡人', 'now-crm' ); ?>
												</button>
												<a class="button" href="<?php echo esc_url( $import_url ); ?>"><?php esc_html_e( '匯入聯絡人', 'now-crm' ); ?></a>
												<a class="button" href="<?php echo esc_url( Menu::page_url( FormsPage::SLUG ) ); ?>"><?php esc_html_e( '用表單收集', 'now-crm' ); ?></a>
											</p>
										<?php endif; ?>
									</div>
								</td>
							</tr>
						<?php else : ?>
							<?php foreach ( $items as $item ) : ?>
								<?php
								$contact_id = (int) $item['id'];
								$aggregate  = is_array( $item['aggregate'] ?? null ) ? $item['aggregate'] : $repository->aggregate( $contact_id );
								$detail_url = Menu::page_url( Menu::CONTACT_SLUG, array( 'id' => $contact_id ) );
								$row_terms  = (array) ( $item['terms'] ?? array() );
								$name       = trim( (string) ( $item['first_name'] ?? '' ) . ' ' . (string) ( $item['last_name'] ?? '' ) );
								$email      = (string) ( $item['email'] ?? '' );
								$primary    = '' !== $name ? $name : $email;
								?>
								<tr>
									<th scope="row" class="nowcrm-col-check">
										<input type="checkbox" class="nowcrm-contact-check" data-nowcrm-row-check
											name="contact_ids[]" form="nowcrm-contacts-bulk"
											value="<?php echo esc_attr( (string) $contact_id ); ?>" />
									</th>
									<td data-nowcrm-col="contact">
										<div class="nowcrm-contact-cell">
											<span class="nowcrm-avatar" aria-hidden="true"><?php echo esc_html( self::initials( $primary ) ); ?></span>
											<span class="nowcrm-contact-cell__text">
												<a class="nowcrm-contact-name" href="<?php echo esc_url( $detail_url ); ?>">
													<?php echo esc_html( $primary ); ?>
												</a>
												<?php if ( '' !== $name && '' !== $email ) : ?>
													<span class="nowcrm-contact-email"><?php echo esc_html( $email ); ?></span>
												<?php endif; ?>
											</span>
										</div>
									</td>
									<td data-nowcrm-col="lists"><?php self::render_chips( $row_terms, TermRepository::TYPE_LIST ); ?></td>
									<td data-nowcrm-col="tags"><?php self::render_chips( $row_terms, TermRepository::TYPE_TAG ); ?></td>
									<td data-nowcrm-col="status">
										<span class="nowcrm-status-badge nowcrm-status-badge--<?php echo esc_attr( (string) $item['status'] ); ?>">
											<span class="nowcrm-status-dot" aria-hidden="true"></span>
											<?php echo esc_html( Status::label( (string) $item['status'] ) ); ?>
										</span>
									</td>
									<td data-nowcrm-col="orders" hidden><?php echo esc_html( (string) (int) $aggregate['order_count'] ); ?></td>
									<td data-nowcrm-col="spent" hidden>
										<?php
										echo esc_html(
											Menu::format_amount(
												(float) $aggregate['total_spent'],
												(string) $aggregate['currency']
											)
										);
										?>
									</td>
									<td data-nowcrm-col="created" hidden><?php echo esc_html( Menu::format_datetime( (string) $item['created_at'] ) ); ?></td>
									<td data-nowcrm-col="id" hidden><?php echo esc_html( (string) $contact_id ); ?></td>
								</tr>
							<?php endforeach; ?>
						<?php endif; ?>
					</tbody>
				</table>

				<div class="nowcrm-contacts-footer">
					<p class="nowcrm-contacts-footer__count">
						<?php
						if ( $total > 0 ) {
							printf(
								/* translators: 1: first row number, 2: last row number, 3: total contacts. */
								esc_html__( '顯示 %1$d–%2$d，共 %3$d 位聯絡人', 'now-crm' ),
								(int) $first_row,
								(int) $last_row,
								(int) $total
							);
						} else {
							esc_html_e( '共 0 位聯絡人', 'now-crm' );
						}
						?>
					</p>

					<div class="nowcrm-page-sizes" aria-label="<?php esc_attr_e( '每頁筆數', 'now-crm' ); ?>">
						<span><?php esc_html_e( '每頁', 'now-crm' ); ?></span>
						<?php foreach ( self::PAGE_SIZES as $size ) : ?>
							<a class="nowcrm-page-size <?php echo $per_page === $size ? 'is-active' : ''; ?>"
								href="<?php echo esc_url( Menu::page_url( Menu::SLUG, array_merge( $applied_args, array( 'per_page' => $size ) ) ) ); ?>"
								<?php echo $per_page === $size ? 'aria-current="true"' : ''; ?>>
								<?php echo esc_html( (string) $size ); ?>
							</a>
						<?php endforeach; ?>
					</div>

					<?php if ( $total_pages > 1 ) : ?>
						<nav class="nowcrm-pagination" aria-label="<?php esc_attr_e( '聯絡人分頁', 'now-crm' ); ?>">
							<?php if ( $paged > 1 ) : ?>
								<a class="nowcrm-page-link" href="<?php echo esc_url( Menu::page_url( Menu::SLUG, array_merge( $applied_args, array( 'paged' => $paged - 1 ) ) ) ); ?>">‹</a>
							<?php endif; ?>

							<?php for ( $page_number = max( 1, $paged - 2 ); $page_number <= min( $total_pages, $paged + 2 ); $page_number++ ) : ?>
								<a class="nowcrm-page-link <?php echo $page_number === $paged ? 'is-active' : ''; ?>"
									href="<?php echo esc_url( Menu::page_url( Menu::SLUG, array_merge( $applied_args, array( 'paged' => $page_number ) ) ) ); ?>"
									<?php echo $page_number === $paged ? 'aria-current="page"' : ''; ?>>
									<?php echo esc_html( (string) $page_number ); ?>
								</a>
							<?php endfor; ?>

							<?php if ( $paged < $total_pages ) : ?>
								<a class="nowcrm-page-link" href="<?php echo esc_url( Menu::page_url( Menu::SLUG, array_merge( $applied_args, array( 'paged' => $paged + 1 ) ) ) ); ?>">›</a>
							<?php endif; ?>
						</nav>
					<?php endif; ?>
				</div>
			</div>

			<?php if ( ! $blocked ) : ?>
				<dialog class="nowcrm-export-dialog" id="nowcrm-export-dialog"
					aria-labelledby="nowcrm-export-dialog-title">
					<form method="post" action="<?php echo esc_url( $form_url ); ?>" class="nowcrm-export-form">
						<input type="hidden" name="action" value="<?php echo esc_attr( ContactCsvAdmin::ACTION_EXPORT ); ?>" />
						<?php wp_nonce_field( ContactCsvAdmin::NONCE_EXPORT ); ?>
						<input type="hidden" name="scope" value="filtered" data-nowcrm-export-scope />
						<input type="hidden" name="contact_ids" value="" data-nowcrm-export-ids />
						<input type="hidden" name="search" value="<?php echo esc_attr( $applied_search ); ?>" />
						<input type="hidden" name="status" value="<?php echo esc_attr( 'advanced' === $mode ? '' : $status ); ?>" />
						<input type="hidden" name="term_id" value="<?php echo esc_attr( (string) ( 'advanced' === $mode ? 0 : $term ) ); ?>" />
						<input type="hidden" name="<?php echo esc_attr( ContactAdvancedFilters::TRANSPORT ); ?>"
							value="<?php echo esc_attr( AdvancedFilters::to_json( is_array( $filters ) ? $filters : null ) ); ?>" />

						<div class="nowcrm-export-dialog__head">
							<h2 id="nowcrm-export-dialog-title"><?php esc_html_e( '匯出聯絡人', 'now-crm' ); ?></h2>
							<button type="button" class="nowcrm-drawer__close" data-nowcrm-close-export
								aria-label="<?php esc_attr_e( '關閉', 'now-crm' ); ?>">
								<span class="dashicons dashicons-no-alt" aria-hidden="true"></span>
							</button>
						</div>

						<div class="nowcrm-export-dialog__body">
							<p class="nowcrm-muted" data-nowcrm-export-summary>
								<?php esc_html_e( '匯出符合目前篩選的聯絡人。', 'now-crm' ); ?>
							</p>

							<fieldset class="nowcrm-export-columns">
								<legend><?php esc_html_e( '欄位', 'now-crm' ); ?></legend>
								<?php
								$export_defaults = array( 'email', 'first_name', 'last_name', 'status' );
								foreach ( CsvExport::columns() as $column_key => $column_label ) :
									?>
									<label>
										<input type="checkbox" name="columns[]" value="<?php echo esc_attr( $column_key ); ?>"
											<?php checked( in_array( $column_key, $export_defaults, true ) ); ?> />
										<?php echo esc_html( $column_label ); ?>
									</label>
								<?php endforeach; ?>
							</fieldset>

							<?php $export_custom = CsvExport::custom_columns(); ?>
							<?php if ( array() !== $export_custom ) : ?>
								<fieldset class="nowcrm-export-columns">
									<legend><?php esc_html_e( '自訂欄位', 'now-crm' ); ?></legend>
									<?php foreach ( $export_custom as $field_slug => $field_label ) : ?>
										<label>
											<input type="checkbox" name="custom_fields[]" value="<?php echo esc_attr( $field_slug ); ?>" />
											<?php echo esc_html( $field_label ); ?>
										</label>
									<?php endforeach; ?>
								</fieldset>
							<?php endif; ?>
						</div>

						<div class="nowcrm-export-dialog__foot">
							<button type="button" class="button" data-nowcrm-close-export>
								<?php esc_html_e( '取消', 'now-crm' ); ?>
							</button>
							<button type="submit" class="button button-primary">
								<?php esc_html_e( '匯出 CSV', 'now-crm' ); ?>
							</button>
						</div>
					</form>
				</dialog>
			<?php endif; ?>

			<dialog class="nowcrm-drawer" id="nowcrm-contact-drawer"
				aria-labelledby="nowcrm-contact-drawer-title"
				data-focus="<?php echo esc_attr( $draft_focus ); ?>"
				data-reset="<?php echo '' === $draft_focus && 'created' === sanitize_key( Menu::query( 'nowcrm_notice' ) ) ? '1' : '0'; ?>"
				<?php echo $drawer_open ? 'open' : ''; ?>>
				<form method="post" action="<?php echo esc_url( $form_url ); ?>" class="nowcrm-drawer__form" id="nowcrm-contact-create">
					<input type="hidden" name="action" value="nowcrm_create_contact" />
					<?php wp_nonce_field( 'nowcrm_create_contact' ); ?>

					<div class="nowcrm-drawer__header">
						<h2 id="nowcrm-contact-drawer-title"><?php esc_html_e( '新增聯絡人', 'now-crm' ); ?></h2>
						<button type="button" class="nowcrm-drawer__close" data-nowcrm-close-contact
							aria-label="<?php esc_attr_e( '關閉', 'now-crm' ); ?>">
							<span class="dashicons dashicons-no-alt" aria-hidden="true"></span>
						</button>
					</div>

					<div class="nowcrm-drawer__body">
						<?php Actions::render_notice(); ?>

						<div class="nowcrm-contact-fields">
							<label class="nowcrm-field">
								<span><?php esc_html_e( '稱謂', 'now-crm' ); ?></span>
								<select name="prefix">
									<option value=""><?php esc_html_e( '—', 'now-crm' ); ?></option>
									<?php foreach ( ProfileFields::prefixes() as $prefix ) : ?>
										<option value="<?php echo esc_attr( $prefix ); ?>" <?php selected( $drawer_value( 'prefix' ), $prefix ); ?>>
											<?php echo esc_html( $prefix ); ?>
										</option>
									<?php endforeach; ?>
								</select>
							</label>

							<label class="nowcrm-field">
								<span><?php esc_html_e( '名字', 'now-crm' ); ?></span>
								<input type="text" name="first_name" value="<?php echo esc_attr( $drawer_value( 'first_name' ) ); ?>" />
							</label>

							<label class="nowcrm-field">
								<span><?php esc_html_e( '姓氏', 'now-crm' ); ?></span>
								<input type="text" name="last_name" value="<?php echo esc_attr( $drawer_value( 'last_name' ) ); ?>" />
							</label>

							<label class="nowcrm-field">
								<span><?php esc_html_e( 'Email', 'now-crm' ); ?></span>
								<input type="email" name="email" required="required"
									aria-invalid="<?php echo 'email' === $draft_focus ? 'true' : 'false'; ?>"
									value="<?php echo esc_attr( $drawer_value( 'email' ) ); ?>" />
							</label>

							<label class="nowcrm-field">
								<span><?php esc_html_e( '電話', 'now-crm' ); ?></span>
								<input type="text" name="phone" value="<?php echo esc_attr( $drawer_value( 'phone' ) ); ?>" />
							</label>

							<label class="nowcrm-field">
								<span><?php esc_html_e( '出生日期', 'now-crm' ); ?></span>
								<input type="date" name="date_of_birth"
									value="<?php echo esc_attr( $drawer_value( 'date_of_birth' ) ); ?>" />
							</label>
						</div>

						<details class="nowcrm-drawer__section" <?php echo $address_open ? 'open' : ''; ?>>
							<summary><?php esc_html_e( '新增地址資訊', 'now-crm' ); ?></summary>
							<div class="nowcrm-contact-fields">
								<?php foreach ( array( 'address_line_1' => __( '地址第 1 行', 'now-crm' ), 'address_line_2' => __( '地址第 2 行', 'now-crm' ), 'city' => __( '城市', 'now-crm' ), 'state' => __( '州／省／地區', 'now-crm' ), 'postal_code' => __( '郵遞區號', 'now-crm' ) ) as $field => $label ) : ?>
									<label class="nowcrm-field">
										<span><?php echo esc_html( $label ); ?></span>
										<input type="text" name="<?php echo esc_attr( $field ); ?>"
											value="<?php echo esc_attr( $drawer_value( $field ) ); ?>" />
									</label>
								<?php endforeach; ?>
								<label class="nowcrm-field">
									<span><?php esc_html_e( '國家', 'now-crm' ); ?></span>
									<select name="country">
										<option value=""><?php esc_html_e( '—', 'now-crm' ); ?></option>
										<?php foreach ( ProfileFields::countries() as $code => $name ) : ?>
											<option value="<?php echo esc_attr( (string) $code ); ?>" <?php selected( $drawer_value( 'country' ), (string) $code ); ?>>
												<?php echo esc_html( (string) $name ); ?>
											</option>
										<?php endforeach; ?>
									</select>
								</label>
							</div>
						</details>

						<div class="nowcrm-drawer__section">
							<h3><?php esc_html_e( '識別資料', 'now-crm' ); ?></h3>
							<fieldset class="nowcrm-terms">
								<legend><?php esc_html_e( '名單', 'now-crm' ); ?></legend>
								<?php if ( array() === $all_lists ) : ?>
									<p class="description"><?php esc_html_e( '尚未建立任何名單。', 'now-crm' ); ?></p>
								<?php else : ?>
									<?php foreach ( $all_lists as $list ) : ?>
										<label class="nowcrm-checkbox">
											<input type="checkbox" name="term_ids[]"
												value="<?php echo esc_attr( (string) (int) $list['id'] ); ?>"
												<?php checked( in_array( (int) $list['id'], $draft_terms, true ) ); ?> />
											<?php echo esc_html( (string) $list['name'] ); ?>
										</label>
									<?php endforeach; ?>
								<?php endif; ?>
							</fieldset>

							<fieldset class="nowcrm-terms">
								<legend><?php esc_html_e( '標籤', 'now-crm' ); ?></legend>
								<?php if ( array() === $all_tags ) : ?>
									<p class="description"><?php esc_html_e( '尚未建立任何標籤。', 'now-crm' ); ?></p>
								<?php else : ?>
									<?php foreach ( $all_tags as $tag ) : ?>
										<label class="nowcrm-checkbox">
											<input type="checkbox" name="term_ids[]"
												value="<?php echo esc_attr( (string) (int) $tag['id'] ); ?>"
												<?php checked( in_array( (int) $tag['id'], $draft_terms, true ) ); ?> />
											<?php echo esc_html( (string) $tag['name'] ); ?>
										</label>
									<?php endforeach; ?>
								<?php endif; ?>
							</fieldset>
						</div>

						<?php CustomFields::render_new_contact_fields( $draft_custom ); ?>

						<label class="nowcrm-field nowcrm-field--status">
							<span><?php esc_html_e( '聯絡人狀態', 'now-crm' ); ?></span>
							<select name="status" id="nowcrm-contact-status">
								<?php foreach ( Status::all() as $option ) : ?>
									<option value="<?php echo esc_attr( $option ); ?>" <?php selected( $draft_status, $option ); ?>>
										<?php echo esc_html( Status::label( $option ) ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</label>

						<label class="nowcrm-checkbox nowcrm-contact-consent" data-nowcrm-consent
							<?php echo Status::PENDING === $draft_status ? '' : 'hidden'; ?>>
							<input type="checkbox" name="nowcrm_consent" value="1" <?php checked( $draft_consent ); ?> />
							<?php esc_html_e( '寄送訂閱確認信（使用現有雙重確認機制）', 'now-crm' ); ?>
						</label>
					</div>

					<div class="nowcrm-drawer__footer">
						<button type="submit" class="button" name="create_mode" value="another">
							<?php esc_html_e( '再建立並新增', 'now-crm' ); ?>
						</button>
						<button type="button" class="button" data-nowcrm-close-contact>
							<?php esc_html_e( '取消', 'now-crm' ); ?>
						</button>
						<button type="submit" class="button button-primary" name="create_mode" value="profile">
							<?php esc_html_e( '建立聯絡人', 'now-crm' ); ?>
						</button>
					</div>
				</form>
			</dialog>

			<script>
			( function () {
				var dialog = document.getElementById( 'nowcrm-contact-drawer' );

				if ( ! dialog ) {
					return;
				}

				var form = document.getElementById( 'nowcrm-contact-create' );
				var opener = null;
				var status = document.getElementById( 'nowcrm-contact-status' );
				var consent = dialog.querySelector( '[data-nowcrm-consent]' );

				function toggleConsent() {
					if ( status && consent ) {
						consent.hidden = 'pending' !== status.value;
					}
				}

				function open() {
					opener = document.activeElement;

					if ( typeof dialog.showModal === 'function' && ! dialog.open ) {
						dialog.showModal();
					} else {
						dialog.setAttribute( 'open', '' );
					}

					toggleConsent();
				}

				function close() {
					if ( typeof dialog.close === 'function' && dialog.open ) {
						dialog.close();
					} else {
						dialog.removeAttribute( 'open' );
					}

					if ( opener && typeof opener.focus === 'function' ) {
						opener.focus();
					}
				}

				document.querySelectorAll( '[data-nowcrm-open-contact]' ).forEach( function ( button ) {
					button.addEventListener( 'click', open );
				} );

				dialog.querySelectorAll( '[data-nowcrm-close-contact]' ).forEach( function ( button ) {
					button.addEventListener( 'click', close );
				} );

				dialog.addEventListener( 'click', function ( event ) {
					if ( event.target === dialog ) {
						close();
					}
				} );

				dialog.addEventListener( 'cancel', function ( event ) {
					event.preventDefault();
					close();
				} );

				if ( status ) {
					status.addEventListener( 'change', toggleConsent );
				}

				if ( dialog.hasAttribute( 'open' ) ) {
					if ( typeof dialog.showModal === 'function' ) {
						dialog.close();
						dialog.showModal();
					}

					if ( '1' === dialog.dataset.reset && form ) {
						form.reset();

						if ( status ) {
							status.value = 'subscribed';
						}
					}

					toggleConsent();

					var focus = dialog.dataset.focus;

					if ( focus ) {
						var field = dialog.querySelector( '[name="' + focus + '"]' );

						if ( field ) {
							field.focus();
						}
					}
				}
			} )();
			</script>
		</div>
		<?php
	}

	/**
	 * Contacts tabs shared by the list, terms, and segments screens.
	 *
	 * @param string $current all|lists|tags|segments.
	 */
	public static function render_nav( string $current ): void {
		$tabs = array(
			'all'      => array( __( '所有聯絡人', 'now-crm' ), Menu::page_url( Menu::SLUG ) ),
			'lists'    => array( __( '名單', 'now-crm' ), Menu::page_url( Menu::LISTS_SLUG ) ),
			'tags'     => array( __( '標籤', 'now-crm' ), Menu::page_url( Menu::TAGS_SLUG ) ),
			'segments' => array( __( '分眾', 'now-crm' ), Menu::page_url( SegmentsPage::SLUG ) ),
		);
		?>
		<nav class="nowcrm-contact-tabs" aria-label="<?php esc_attr_e( '聯絡人', 'now-crm' ); ?>">
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
	 * Scalar array from the query string.
	 *
	 * @return array<int, mixed>
	 */
	private static function query_array( string $key ): array {
		if ( ! isset( $_GET[ $key ] ) || ! is_array( $_GET[ $key ] ) ) {
			return array();
		}

		return (array) wp_unslash( $_GET[ $key ] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter.
	}

	/**
	 * Positive integer IDs from a query array.
	 *
	 * @param array<int, mixed> $values Raw values.
	 * @return int[]
	 */
	private static function query_ids( array $values ): array {
		$ids = array();

		foreach ( $values as $value ) {
			$value = absint( $value );

			if ( $value > 0 && ! in_array( $value, $ids, true ) ) {
				$ids[] = $value;
			}
		}

		return $ids;
	}

	/**
	 * Valid status slugs from a query array.
	 *
	 * @param array<int, mixed> $values Raw values.
	 * @return string[]
	 */
	private static function valid_statuses( array $values ): array {
		$statuses = array();

		foreach ( $values as $value ) {
			if ( ! is_scalar( $value ) ) {
				continue;
			}

			$value = sanitize_key( (string) $value );

			if ( Status::is_valid( $value ) && ! in_array( $value, $statuses, true ) ) {
				$statuses[] = $value;
			}
		}

		return $statuses;
	}

	/**
	 * Hidden inputs for a query argument map, keeping arrays as `name[]`.
	 *
	 * @param array<string, mixed> $args Query arguments.
	 */
	private static function hidden_fields( array $args ): void {
		foreach ( $args as $key => $value ) {
			if ( is_array( $value ) ) {
				foreach ( $value as $item ) {
					if ( is_scalar( $item ) ) {
						printf(
							'<input type="hidden" name="%s[]" value="%s" />',
							esc_attr( (string) $key ),
							esc_attr( (string) $item )
						);
					}
				}

				continue;
			}

			if ( is_scalar( $value ) ) {
				printf(
					'<input type="hidden" name="%s" value="%s" />',
					esc_attr( (string) $key ),
					esc_attr( (string) $value )
				);
			}
		}
	}

	/**
	 * Hidden condition rows for the advanced parser.
	 *
	 * @param array<string, mixed>|null $plan Validated plan.
	 */
	private static function hidden_condition_fields( ?array $plan ): void {
		foreach ( AdvancedFilters::to_query( $plan ) as $group_index => $group ) {
			foreach ( $group as $row_index => $row ) {
				$name = ContactAdvancedFilters::INPUT . '[' . (int) $group_index . '][' . (int) $row_index . ']';

				foreach ( array( 'provider', 'field', 'operator' ) as $key ) {
					printf(
						'<input type="hidden" name="%s" value="%s" />',
						esc_attr( $name . '[' . $key . ']' ),
						esc_attr( (string) ( $row[ $key ] ?? '' ) )
					);
				}

				$value = $row['value'] ?? '';

				if ( is_array( $value ) ) {
					foreach ( $value as $item ) {
						printf(
							'<input type="hidden" name="%s[]" value="%s" />',
							esc_attr( $name . '[value]' ),
							esc_attr( (string) $item )
						);
					}
				} else {
					printf(
						'<input type="hidden" name="%s" value="%s" />',
						esc_attr( $name . '[value]' ),
						esc_attr( (string) $value )
					);
				}
			}
		}
	}

	/**
	 * Two-letter avatar initials. No network image is used.
	 */
	private static function initials( string $text ): string {
		$text = trim( $text );

		if ( '' === $text ) {
			return '?';
		}

		$parts = preg_split( '/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY );
		$parts = is_array( $parts ) ? $parts : array( $text );

		if ( count( $parts ) > 1 ) {
			return mb_strtoupper( mb_substr( (string) $parts[0], 0, 1 ) . mb_substr( (string) $parts[ count( $parts ) - 1 ], 0, 1 ), 'UTF-8' );
		}

		return mb_strtoupper( mb_substr( (string) $parts[0], 0, 2 ), 'UTF-8' );
	}

	/**
	 * Chips for one term type attached to a contact.
	 *
	 * @param array<int, array<string, mixed>> $terms Contact terms.
	 * @param string                           $type  list|tag.
	 */
	private static function render_chips( array $terms, string $type ): void {
		$matches = array_values(
			array_filter(
				$terms,
				static fn( array $term ): bool => (string) ( $term['type'] ?? '' ) === $type
			)
		);

		if ( array() === $matches ) {
			echo '<span class="nowcrm-muted">—</span>';
			return;
		}

		echo '<span class="nowcrm-chip-list" data-nowcrm-chip-list>';
		foreach ( $matches as $term ) {
			printf(
				'<span class="nowcrm-chip" data-nowcrm-chip>%s</span>',
				esc_html( (string) ( $term['name'] ?? '' ) )
			);
		}
		echo '<span class="nowcrm-chip nowcrm-chip--more" data-nowcrm-chip-more hidden></span>';
		echo '</span>';
	}
}
