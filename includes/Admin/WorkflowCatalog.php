<?php
/**
 * Shared dense catalog for campaigns, templates, automations, and sequences.
 *
 * The catalog renders the common list shell: header, status facets, search,
 * a selectable table, the contextual bulk bar, and pagination. Each page
 * supplies its own columns and row cells.
 *
 * @package NowCRM
 */

namespace NowCRM\Admin;

defined( 'ABSPATH' ) || exit;

final class WorkflowCatalog {

	/**
	 * Default rows per page, matching the contacts list.
	 */
	public const PER_PAGE = 10;

	/**
	 * Register bulk handlers and screen assets.
	 */
	public static function register(): void {
		WorkflowCatalogActions::register();
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	/**
	 * Load the catalog stylesheet and script on the four list screens only.
	 */
	public static function enqueue(): void {
		$page = Menu::query( 'page' );

		if ( ! in_array( $page, array( Menu::CAMPAIGNS_SLUG, Menu::RECURRING_SLUG, Menu::TEMPLATES_SLUG, Menu::AUTOMATIONS_SLUG, Menu::SEQUENCES_SLUG ), true ) ) {
			return;
		}

		if ( Menu::CAMPAIGNS_SLUG === $page && absint( Menu::query( 'campaign' ) ) ) {
			return;
		}

		if ( Menu::RECURRING_SLUG === $page && absint( Menu::query( 'campaign' ) ) ) {
			return;
		}

		if ( Menu::TEMPLATES_SLUG === $page && absint( Menu::query( 'template' ) ) ) {
			return;
		}

		wp_enqueue_style(
			'nowcrm-workflow-catalog',
			plugins_url( 'assets/css/workflow-catalog.css', NOWCRM_FILE ),
			array( 'nowcrm-admin' ),
			(string) filemtime( NOWCRM_DIR . '/assets/css/workflow-catalog.css' )
		);
		wp_enqueue_script(
			'nowcrm-workflow-catalog',
			plugins_url( 'assets/js/workflow-catalog.js', NOWCRM_FILE ),
			array(),
			(string) filemtime( NOWCRM_DIR . '/assets/js/workflow-catalog.js' ),
			true
		);
	}

	/**
	 * Print the bulk-action notice requested by the current screen.
	 *
	 * @param string $noun Resource name used in the message.
	 */
	public static function render_notice( string $noun ): void {
		$code = sanitize_key( Menu::query( 'nowcrm_notice' ) );
		$known = array(
			'bulk_deleted',
			'bulk_status',
			'no_selection',
			'invalid_operation',
			'invalid_filter',
			'too_many',
			'state_blocked',
			'forbidden',
			'not_found',
		);

		if ( ! in_array( $code, $known, true ) ) {
			return;
		}

		$deleted = absint( Menu::query( 'nowcrm_deleted' ) );
		$skipped = absint( Menu::query( 'nowcrm_skipped' ) );
		$limit   = absint( Menu::query( 'nowcrm_limit' ) );
		$errors  = array( 'no_selection', 'invalid_operation', 'invalid_filter', 'too_many', 'state_blocked', 'forbidden', 'not_found' );

		if ( 'bulk_deleted' === $code ) {
			$message = $deleted > 0
				/* translators: 1: number of deleted rows, 2: resource name. */
				? sprintf( __( '已刪除 %1$d 筆%2$s。', 'now-crm' ), $deleted, $noun )
				/* translators: %s: resource name. */
				: sprintf( __( '沒有刪除任何%s。', 'now-crm' ), $noun );

			if ( $skipped > 0 ) {
				/* translators: %d: number of skipped rows. */
				$message .= ' ' . sprintf( __( '另有 %d 筆未處理（狀態、權限或已不存在）。', 'now-crm' ), $skipped );
			}
		} elseif ( 'bulk_status' === $code ) {
			/* translators: 1: number of updated rows, 2: resource name. */
			$message = sprintf( __( '已更新 %1$d 筆%2$s的狀態。', 'now-crm' ), $deleted, $noun );

			if ( $skipped > 0 ) {
				/* translators: %d: number of skipped rows. */
				$message .= ' ' . sprintf( __( '另有 %d 筆未處理（權限或設定不完整）。', 'now-crm' ), $skipped );
			}
		} elseif ( 'no_selection' === $code ) {
			/* translators: %s: resource name. */
			$message = sprintf( __( '請先選取%s。', 'now-crm' ), $noun );
		} elseif ( 'invalid_operation' === $code ) {
			$message = __( '操作不正確，未變更任何資料。', 'now-crm' );
		} elseif ( 'invalid_filter' === $code ) {
			$message = __( '篩選條件不正確，未變更任何資料。', 'now-crm' );
		} elseif ( 'state_blocked' === $code ) {
			$message = __( '此電子報目前的狀態不能移至回收桶；請先取消或等待寄送結束。', 'now-crm' );
		} elseif ( 'too_many' === $code ) {
			/* translators: 1: resource name, 2: maximum rows per request. */
			$message = sprintf( __( '符合篩選的%1$s超過單次上限 %2$d 筆，未變更任何資料。', 'now-crm' ), $noun, $limit );
		} elseif ( 'forbidden' === $code ) {
			$message = __( '您沒有執行此操作的權限。', 'now-crm' );
		} else {
			/* translators: %s: resource name. */
			$message = sprintf( __( '找不到可處理的%s。', 'now-crm' ), $noun );
		}

		printf(
			'<div class="notice %1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( in_array( $code, $errors, true ) ? 'notice-error' : 'notice-success' ),
			esc_html( $message )
		);
	}

	/**
	 * Render one catalog screen.
	 *
	 * Row cells must be escaped by the caller. Set "eligible" to false to
	 * render a disabled checkbox with the reason.
	 *
	 * @param array<string, mixed> $config Screen configuration.
	 */
	public static function render( array $config ): void {
		$slug   = (string) $config['slug'];
		$title  = (string) $config['title'];
		$noun   = (string) $config['noun'];
		$search = (string) ( $config['search'] ?? '' );
		$status = (string) ( $config['status'] ?? 'all' );
		$status = '' === $status ? 'all' : $status;
		$statuses = (array) ( $config['statuses'] ?? array() );
		$counts   = isset( $config['counts'] ) && is_array( $config['counts'] ) ? $config['counts'] : null;
		$library  = (int) ( $config['library_total'] ?? 0 );
		$total    = (int) ( $config['total'] ?? 0 );
		$page     = max( 1, (int) ( $config['page'] ?? 1 ) );
		$pages    = max( 0, (int) ( $config['pages'] ?? 0 ) );
		$columns  = (array) ( $config['columns'] ?? array() );
		$rows     = (array) ( $config['rows'] ?? array() );
		$bulk     = isset( $config['bulk'] ) && is_array( $config['bulk'] ) ? $config['bulk'] : null;
		$create   = isset( $config['create'] ) && is_array( $config['create'] ) ? $config['create'] : array();
		$empty    = array_merge(
			array(
				/* translators: %s: resource name. */
				'filtered' => sprintf( __( '沒有符合條件的%1$s。', 'now-crm' ), $noun ),
				/* translators: %s: resource name. */
				'page'     => sprintf( __( '這一頁沒有%1$s。', 'now-crm' ), $noun ),
				/* translators: %s: resource name. */
				'library'  => sprintf( __( '還沒有%1$s，先建立第一筆。', 'now-crm' ), $noun ),
			),
			(array) ( $config['empty'] ?? array() )
		);
		$base_url = Menu::page_url( $slug );
		$filtered = '' !== $search || 'all' !== $status;
		$args     = array();

		if ( '' !== $search ) {
			$args['s'] = $search;
		}

		if ( 'all' !== $status ) {
			$args['status'] = $status;
		}
		?>
		<div class="wrap nowcrm-wrap nowcrm-catalog">
			<div class="nowcrm-catalog__head">
				<div class="nowcrm-catalog__heading">
					<h1><?php echo esc_html( $title ); ?></h1>
					<p class="nowcrm-catalog__summary">
						<?php
						echo esc_html(
							sprintf(
								/* translators: 1: number of stored rows, 2: resource name. */
								__( '共 %1$s 筆%2$s', 'now-crm' ),
								number_format_i18n( $library ),
								$noun
							)
						);

						if ( $filtered ) {
							echo esc_html(
								' · ' . sprintf(
									/* translators: %s: number of rows matching the current filters. */
									__( '顯示 %s 筆', 'now-crm' ),
									number_format_i18n( $total )
								)
							);
						}
						?>
					</p>
				</div>
				<?php if ( ! empty( $create['url'] ) && ! empty( $create['label'] ) ) : ?>
					<a class="button button-primary" href="<?php echo esc_url( (string) $create['url'] ); ?>">
						<?php echo esc_html( (string) $create['label'] ); ?>
					</a>
				<?php endif; ?>
			</div>

			<?php if ( ! empty( $config['panel'] ) ) : ?>
				<?php echo $config['panel']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Caller escapes the panel. ?>
			<?php endif; ?>

			<?php self::render_status_tabs( $slug, $statuses, $status, $counts, $search ); ?>

			<form class="nowcrm-catalog__search" method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" role="search">
				<input type="hidden" name="page" value="<?php echo esc_attr( $slug ); ?>">
				<?php if ( 'all' !== $status ) : ?>
					<input type="hidden" name="status" value="<?php echo esc_attr( $status ); ?>">
				<?php endif; ?>
				<label class="screen-reader-text" for="nowcrm-catalog-search"><?php
					/* translators: %s: resource name, for example "自動化". */
					echo esc_html( sprintf( __( '搜尋%1$s', 'now-crm' ), $noun ) );
				?></label>
				<input type="search" id="nowcrm-catalog-search" name="s" value="<?php echo esc_attr( $search ); ?>"
					placeholder="<?php echo esc_attr( (string) ( $config['search_placeholder'] ?? __( '搜尋名稱', 'now-crm' ) ) ); ?>">
				<button type="submit" class="button"><?php esc_html_e( '搜尋', 'now-crm' ); ?></button>
				<?php if ( $filtered ) : ?>
					<a class="nowcrm-catalog__clear" href="<?php echo esc_url( $base_url ); ?>"><?php esc_html_e( '清除篩選', 'now-crm' ); ?></a>
				<?php endif; ?>
			</form>

			<?php if ( ! $rows ) : ?>
				<?php self::render_empty( $empty, $filtered, $total, $base_url, $create ); ?>
			<?php else : ?>
				<?php
				if ( null !== $bulk ) {
					self::render_bulk_bar( $bulk, $search, $status, $page, $total, self::selectable_count( $rows ) );
				}
				?>
				<div class="nowcrm-catalog__surface">
					<div class="nowcrm-catalog__scroll">
						<table class="nowcrm-catalog__table"<?php echo empty( $config['testid'] ) ? '' : ' data-testid="' . esc_attr( (string) $config['testid'] ) . '"'; ?>>
							<thead>
								<tr>
									<?php if ( null !== $bulk ) : ?>
										<th scope="col" class="nowcrm-catalog__col-check">
											<input type="checkbox" data-nowcrm-check-page aria-label="<?php esc_attr_e( '選取本頁', 'now-crm' ); ?>">
										</th>
									<?php endif; ?>
									<?php foreach ( $columns as $column ) : ?>
										<th scope="col" class="<?php echo esc_attr( (string) ( $column['class'] ?? '' ) ); ?>">
											<?php echo esc_html( (string) ( $column['label'] ?? '' ) ); ?>
										</th>
									<?php endforeach; ?>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $rows as $row ) : ?>
									<tr>
										<?php if ( null !== $bulk ) : ?>
											<th scope="row" class="nowcrm-catalog__col-check">
												<?php self::render_row_check( $bulk, $row ); ?>
											</th>
										<?php endif; ?>
										<?php foreach ( (array) ( $row['cells'] ?? array() ) as $index => $cell ) : ?>
											<td class="<?php echo esc_attr( (string) ( $columns[ $index ]['class'] ?? '' ) ); ?>">
												<?php echo $cell; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Caller escapes each cell. ?>
											</td>
										<?php endforeach; ?>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>

					<div class="nowcrm-catalog__footer">
						<p class="nowcrm-catalog__range">
							<?php
							if ( $total > 0 ) {
								echo esc_html(
									sprintf(
										/* translators: 1: first row number, 2: last row number, 3: total rows, 4: resource name. */
										__( '顯示 %1$d–%2$d，共 %3$d 筆%4$s', 'now-crm' ),
										( ( $page - 1 ) * self::PER_PAGE ) + 1,
										min( $total, $page * self::PER_PAGE ),
										$total,
										$noun
									)
								);
							} else {
								/* translators: %s: resource name, for example "自動化". */
								echo esc_html( sprintf( __( '共 0 筆%1$s', 'now-crm' ), $noun ) );
							}
							?>
						</p>

						<?php self::render_pagination( $slug, $args, $page, $pages ); ?>
					</div>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Status facet tabs with counts.
	 *
	 * @param string                     $slug     Menu slug.
	 * @param array<string, string>      $statuses Status key to label.
	 * @param string                     $current  Current status key.
	 * @param array<string, int>|null    $counts   Optional counts keyed by status.
	 * @param string                     $search   Current search.
	 */
	private static function render_status_tabs( string $slug, array $statuses, string $current, ?array $counts, string $search ): void {
		if ( ! $statuses ) {
			return;
		}
		?>
		<nav class="nowcrm-catalog__tabs" aria-label="<?php esc_attr_e( '依狀態篩選', 'now-crm' ); ?>">
			<?php
			foreach ( array_merge( array( 'all' => __( '全部', 'now-crm' ) ), $statuses ) as $key => $label ) :
				$args = array();

				if ( '' !== $search ) {
					$args['s'] = $search;
				}

				if ( 'all' !== $key ) {
					$args['status'] = $key;
				}

				$count = null === $counts ? null : (int) ( $counts[ $key ] ?? 0 );
				?>
				<a class="nowcrm-catalog__tab<?php echo $key === $current ? ' is-current' : ''; ?>"
					href="<?php echo esc_url( Menu::page_url( $slug, $args ) ); ?>"
					<?php echo $key === $current ? 'aria-current="page"' : ''; ?>>
					<?php echo esc_html( (string) $label ); ?>
					<?php if ( null !== $count ) : ?>
						<span class="nowcrm-catalog__tab-count"><?php echo esc_html( number_format_i18n( $count ) ); ?></span>
					<?php endif; ?>
				</a>
			<?php endforeach; ?>
		</nav>
		<?php
	}

	/**
	 * Render the empty state for an empty library or empty result set.
	 *
	 * @param array<string, string> $empty    Messages.
	 * @param bool                  $filtered Whether filters are active.
	 * @param int                   $total    Matching rows.
	 * @param string                $base_url List URL without filters.
	 * @param array<string, mixed>  $create   Create button, if any.
	 */
	private static function render_empty( array $empty, bool $filtered, int $total, string $base_url, array $create ): void {
		?>
		<div class="nowcrm-catalog__empty">
			<?php if ( $filtered ) : ?>
				<p><?php echo esc_html( (string) $empty['filtered'] ); ?></p>
				<p><a class="button" href="<?php echo esc_url( $base_url ); ?>"><?php esc_html_e( '清除篩選', 'now-crm' ); ?></a></p>
			<?php elseif ( $total > 0 ) : ?>
				<p><?php echo esc_html( (string) $empty['page'] ); ?></p>
				<p><a class="button" href="<?php echo esc_url( $base_url ); ?>"><?php esc_html_e( '回到第一頁', 'now-crm' ); ?></a></p>
			<?php else : ?>
				<p><?php echo esc_html( (string) $empty['library'] ); ?></p>
				<?php if ( ! empty( $create['url'] ) && ! empty( $create['label'] ) ) : ?>
					<p><a class="button button-primary" href="<?php echo esc_url( (string) $create['url'] ); ?>"><?php echo esc_html( (string) $create['label'] ); ?></a></p>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * One row checkbox. Ineligible rows stay visible but cannot be selected.
	 *
	 * @param array<string, mixed> $bulk Bulk configuration.
	 * @param array<string, mixed> $row  Row data.
	 */
	private static function render_row_check( array $bulk, array $row ): void {
		$id    = (int) ( $row['id'] ?? 0 );
		$label = (string) ( $row['label'] ?? '' );

		if ( $id < 1 ) {
			return;
		}

		if ( false === ( $row['eligible'] ?? true ) ) {
			printf(
				'<input type="checkbox" disabled="disabled" aria-disabled="true" title="%1$s" aria-label="%2$s">',
				esc_attr( (string) ( $row['reason'] ?? __( '此項目不能批次處理。', 'now-crm' ) ) ),
				esc_attr( $label )
			);

			return;
		}

		printf(
			'<input type="checkbox" data-nowcrm-row-check name="%1$s[]" form="nowcrm-catalog-bulk" value="%2$d" aria-label="%3$s">',
			esc_attr( (string) $bulk['field'] ),
			(int) $id,
			esc_attr( $label )
		);
	}

	/**
	 * Contextual bulk bar. It stays hidden until a row is selected.
	 *
	 * @param array<string, mixed> $bulk       Bulk configuration.
	 * @param string               $search     Current search.
	 * @param string               $status     Current status.
	 * @param int                  $page       Current page.
	 * @param int                  $total      Matching rows.
	 * @param int                  $page_count Rows on this page.
	 */
	private static function render_bulk_bar( array $bulk, string $search, string $status, int $page, int $total, int $page_count ): void {
		$options  = (array) ( $bulk['options'] ?? array() );
		$statuses = (array) ( $bulk['statuses'] ?? array() );
		$confirm  = (array) ( $bulk['confirm'] ?? array() );
		$scope    = ! empty( $bulk['scope'] );
		$single   = 1 === count( $options ) ? (string) array_key_first( $options ) : null;
		$submit_label = isset( $bulk['submit'] )
			? (string) $bulk['submit']
			: ( null !== $single && isset( $options[ $single ] ) ? (string) $options[ $single ] : __( '套用', 'now-crm' ) );
		$onsubmit = 'var f=this,e=f.elements,map=' . wp_json_encode( $confirm ) . ',n=0,i,box;'
			. 'for(i=0;i<e.length;i++){box=e[i];if("checkbox"===box.type&&box.checked&&box.name){n++;}}'
			. 'var scope=e.scope?e.scope.value:"page";'
			. 'if(scope!=="all"&&!n){window.alert(f.dataset.messageSelection);return false;}'
			. 'if(e.bulk_operation&&"SELECT"===e.bulk_operation.tagName&&!e.bulk_operation.value){window.alert(f.dataset.messageOperation);return false;}'
			. 'var op=e.bulk_operation?e.bulk_operation.value:"";'
			. 'var msg=map[op]||map[""]||"";'
			. 'return !msg||window.confirm(msg);';
		?>
		<form id="nowcrm-catalog-bulk" class="nowcrm-catalog__bulk" data-nowcrm-catalog-bulk hidden
			method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
			data-total="<?php echo esc_attr( (string) $total ); ?>"
			data-page-count="<?php echo esc_attr( (string) $page_count ); ?>"
			data-message-selection="<?php esc_attr_e( '請先選取要處理的項目。', 'now-crm' ); ?>"
			data-message-operation="<?php esc_attr_e( '請選擇批次操作。', 'now-crm' ); ?>"
			onsubmit="<?php echo esc_attr( $onsubmit ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( (string) $bulk['action'] ); ?>">
			<?php wp_nonce_field( (string) $bulk['nonce'] ); ?>
			<input type="hidden" name="filter_search" value="<?php echo esc_attr( $search ); ?>">
			<input type="hidden" name="filter_status" value="<?php echo esc_attr( $status ); ?>">
			<input type="hidden" name="filter_paged" value="<?php echo esc_attr( (string) $page ); ?>">

			<div class="nowcrm-catalog__bulk-head">
				<strong data-nowcrm-count><?php esc_html_e( '0 已選取', 'now-crm' ); ?></strong>
				<button type="button" class="nowcrm-catalog__bulk-close" data-nowcrm-clear
					aria-label="<?php esc_attr_e( '清除選取', 'now-crm' ); ?>">×</button>
				<button type="button" class="nowcrm-catalog__bulk-link" data-nowcrm-select-all hidden>
					<?php
					echo esc_html(
						sprintf(
							/* translators: %d: number of matching rows. */
							__( '選取全部符合篩選的 %d 筆', 'now-crm' ),
							$total
						)
					);
					?>
				</button>
				<button type="button" class="nowcrm-catalog__bulk-link" data-nowcrm-select-page hidden>
					<?php
					echo esc_html(
						sprintf(
							/* translators: %d: number of rows on this page. */
							__( '只選本頁（%d）', 'now-crm' ),
							$page_count
						)
					);
					?>
				</button>
				<span class="nowcrm-catalog__bulk-scope" data-nowcrm-scope-label></span>
			</div>

			<div class="nowcrm-catalog__bulk-fields">
				<?php if ( $scope ) : ?>
					<input type="hidden" name="scope" value="page" data-nowcrm-scope>
				<?php endif; ?>

				<?php if ( null !== $single ) : ?>
					<input type="hidden" name="bulk_operation" value="<?php echo esc_attr( $single ); ?>">
				<?php elseif ( $options ) : ?>
					<label>
						<span><?php esc_html_e( '批次操作', 'now-crm' ); ?></span>
						<select name="bulk_operation">
							<option value=""><?php esc_html_e( '選擇操作', 'now-crm' ); ?></option>
							<?php foreach ( $options as $value => $label ) : ?>
								<option value="<?php echo esc_attr( (string) $value ); ?>"><?php echo esc_html( (string) $label ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>
				<?php else : ?>
					<input type="hidden" name="bulk_operation" value="">
				<?php endif; ?>

				<?php if ( $statuses ) : ?>
					<label>
						<span><?php esc_html_e( '新狀態', 'now-crm' ); ?></span>
						<select name="bulk_status">
							<?php foreach ( $statuses as $value => $label ) : ?>
								<option value="<?php echo esc_attr( (string) $value ); ?>"><?php echo esc_html( (string) $label ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>
				<?php endif; ?>

				<button type="submit" class="button button-primary"<?php echo ( $options && null === $single ) ? ' disabled="disabled"' : ''; ?>>
					<?php echo esc_html( $submit_label ); ?>
				</button>

				<?php if ( ! empty( $bulk['note'] ) ) : ?>
					<span class="nowcrm-catalog__bulk-note"><?php echo esc_html( (string) $bulk['note'] ); ?></span>
				<?php endif; ?>
			</div>
		</form>
		<?php
	}

	/**
	 * Number of rows on the page that can be selected.
	 *
	 * @param array<int, array<string, mixed>> $rows Page rows.
	 */
	private static function selectable_count( array $rows ): int {
		$count = 0;

		foreach ( $rows as $row ) {
			if ( (int) ( $row['id'] ?? 0 ) > 0 && false !== ( $row['eligible'] ?? true ) ) {
				++$count;
			}
		}

		return $count;
	}

	/**
	 * Pagination links that keep the active filters.
	 *
	 * @param string               $slug  Menu slug.
	 * @param array<string, mixed> $args  Active filter arguments.
	 * @param int                  $page  Current page.
	 * @param int                  $pages Total pages.
	 */
	private static function render_pagination( string $slug, array $args, int $page, int $pages ): void {
		if ( $pages < 2 ) {
			return;
		}
		?>
		<nav class="nowcrm-pagination" aria-label="<?php esc_attr_e( '列表分頁', 'now-crm' ); ?>">
			<?php if ( $page > 1 ) : ?>
				<a class="nowcrm-page-link" href="<?php echo esc_url( Menu::page_url( $slug, array_merge( $args, array( 'paged' => $page - 1 ) ) ) ); ?>">‹</a>
			<?php endif; ?>
			<?php for ( $number = max( 1, $page - 2 ); $number <= min( $pages, $page + 2 ); $number++ ) : ?>
				<a class="nowcrm-page-link<?php echo $number === $page ? ' is-active' : ''; ?>"
					href="<?php echo esc_url( Menu::page_url( $slug, array_merge( $args, array( 'paged' => $number ) ) ) ); ?>"
					<?php echo $number === $page ? 'aria-current="page"' : ''; ?>>
					<?php echo esc_html( (string) $number ); ?>
				</a>
			<?php endfor; ?>
			<?php if ( $page < $pages ) : ?>
				<a class="nowcrm-page-link" href="<?php echo esc_url( Menu::page_url( $slug, array_merge( $args, array( 'paged' => $page + 1 ) ) ) ); ?>">›</a>
			<?php endif; ?>
		</nav>
		<?php
	}
}
