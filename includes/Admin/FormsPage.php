<?php
/**
 * Forms list, creation/deletion handlers, and the scoped entry viewer.
 *
 * @package NowCRM
 */

namespace NowCRM\Admin;

use NowCRM\Forms\EntryRepository;
use NowCRM\Forms\FormRepository;
use NowCRM\Support\Capabilities;
use NowCRM\Terms\TermRepository;
use WP_Error;

defined( 'ABSPATH' ) || exit;

final class FormsPage {

	public const SLUG = 'nowcrm-forms';

	private const PER_PAGE = 20;
	private const LIST_PER_PAGE = 10;

	/**
	 * Statuses the forms list accepts in its filter.
	 *
	 * @var string[]
	 */
	private const FILTER_STATUSES = array( 'publish', 'draft', 'pending', 'private' );

	/**
	 * Register the admin screen and its mutation handlers.
	 */
	public static function register(): void {
		add_action( 'admin_menu', array( __CLASS__, 'add_page' ) );
		add_action( 'admin_post_nowcrm_create_form', array( __CLASS__, 'handle_create' ) );
		add_action( 'admin_post_nowcrm_delete_form', array( __CLASS__, 'handle_delete' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );

		FormEditorPage::register();
	}

	/**
	 * Add the forms screen below the NOW CRM menu.
	 */
	public static function add_page(): void {
		add_submenu_page(
			Menu::DASHBOARD_SLUG,
			__( '表單', 'now-crm' ),
			__( '表單', 'now-crm' ),
			Capabilities::MANAGE_CONTACTS,
			self::SLUG,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Load the shared admin stylesheet on forms screens.
	 */
	public static function enqueue(): void {
		if ( self::SLUG !== sanitize_key( Menu::query( 'page' ) ) ) {
			return;
		}

		wp_enqueue_style(
			'nowcrm-admin',
			plugins_url( 'assets/css/admin.css', NOWCRM_FILE ),
			array(),
			NOWCRM_VERSION
		);
	}

	/**
	 * Route between the list, editor, and entry viewer.
	 */
	public static function render(): void {
		if ( ! Capabilities::can_manage_contacts() ) {
			wp_die( esc_html__( '您沒有權限檢視表單。', 'now-crm' ), '', array( 'response' => 403 ) );
		}

		$form_id = absint( Menu::query( 'form' ) );
		$view    = sanitize_key( Menu::query( 'view' ) );

		if ( $form_id > 0 ) {
			if ( 'entries' === $view ) {
				self::render_entries( $form_id );

				return;
			}

			if ( 'entry' === $view ) {
				self::render_entry( $form_id );

				return;
			}

			FormEditorPage::render( $form_id );

			return;
		}

		if ( '1' === Menu::query( 'new' ) ) {
			self::render_create();

			return;
		}

		self::render_list();
	}

	/**
	 * Print an allow-listed forms notice.
	 */
	public static function render_notice(): void {
		if ( ! isset( $_GET['nowcrm_forms_notice'] ) || ! is_string( $_GET['nowcrm_forms_notice'] ) ) {
			return;
		}

		$code     = sanitize_key( wp_unslash( $_GET['nowcrm_forms_notice'] ) );
		$messages = self::notice_messages();

		if ( ! array_key_exists( $code, $messages ) ) {
			return;
		}

		$class = in_array( $code, array( 'title_required', 'needs_email', 'no_fields', 'invalid_options', 'not_found', 'error' ), true )
			? 'notice-error'
			: 'notice-success';

		printf(
			'<div class="notice %1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $class ),
			esc_html( $messages[ $code ] )
		);
	}

	/**
	 * Map a repository error to an allow-listed notice code.
	 */
	public static function notice_from_error( WP_Error $error ): string {
		return match ( $error->get_error_code() ) {
			'nowcrm_form_title'     => 'title_required',
			'nowcrm_form_needs_email' => 'needs_email',
			'nowcrm_form_no_fields' => 'no_fields',
			'nowcrm_form_options'   => 'invalid_options',
			'nowcrm_form_not_found' => 'not_found',
			default                 => 'error',
		};
	}

	/**
	 * Validated forms-list filters from the current query string.
	 *
	 * Defaults are omitted so plain links stay plain.
	 *
	 * @return array<string, mixed>
	 */
	public static function list_args(): array {
		$args   = array();
		$search = sanitize_text_field( Menu::query( 's' ) );
		$status = sanitize_key( Menu::query( 'status' ) );

		if ( '' !== $search ) {
			$args['s'] = $search;
		}

		if ( in_array( $status, self::FILTER_STATUSES, true ) ) {
			$args['status'] = $status;
		}

		$paged = max( 1, absint( Menu::query( 'paged', '1' ) ) );

		if ( $paged > 1 ) {
			$args['paged'] = $paged;
		}

		return $args;
	}

	/**
	 * Editor URL for one form.
	 *
	 * @param array<string, mixed> $args Validated list context.
	 */
	public static function editor_url( int $form_id, array $args = array() ): string {
		return Menu::page_url(
			self::SLUG,
			array_merge(
				$args,
				array(
					'form' => $form_id,
				)
			)
		);
	}

	/**
	 * Entry list URL for one form.
	 *
	 * @param array<string, mixed> $args Validated list and entry-page context.
	 */
	public static function entries_url( int $form_id, array $args = array() ): string {
		return Menu::page_url(
			self::SLUG,
			array_merge(
				$args,
				array(
					'form' => $form_id,
					'view' => 'entries',
				)
			)
		);
	}

	/**
	 * One entry URL for a form.
	 *
	 * @param array<string, mixed> $args Validated list and entry-page context.
	 */
	public static function entry_url( int $form_id, int $entry_id, array $args = array() ): string {
		return Menu::page_url(
			self::SLUG,
			array_merge(
				$args,
				array(
					'form'  => $form_id,
					'view'  => 'entry',
					'entry' => $entry_id,
				)
			)
		);
	}

	/**
	 * Entry-page filters: list context plus its own page number.
	 *
	 * @param array<string, mixed> $list_args Validated list context.
	 */
	private static function entries_args( array $list_args ): array {
		$page = max( 1, absint( Menu::query( 'epaged', '1' ) ) );

		if ( $page > 1 ) {
			$list_args['epaged'] = $page;
		}

		return $list_args;
	}

	/**
	 * Create handler. New forms start with a small working field set.
	 */
	public static function handle_create(): void {
		if ( ! Capabilities::can_manage_contacts() ) {
			wp_die( esc_html__( '您沒有權限新增表單。', 'now-crm' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( 'nowcrm_create_form' );

		$title = isset( $_POST['title'] ) && is_string( $_POST['title'] )
			? sanitize_text_field( wp_unslash( $_POST['title'] ) )
			: '';

		$created = FormRepository::create(
			array(
				'title'  => $title,
				'fields' => array(
					array(
						'label' => __( '姓名', 'now-crm' ),
						'type'  => 'text',
					),
					array(
						'label'    => __( 'Email', 'now-crm' ),
						'type'     => 'email',
						'required' => true,
					),
					array(
						'label' => __( '訊息', 'now-crm' ),
						'type'  => 'textarea',
					),
				),
			)
		);

		if ( is_wp_error( $created ) ) {
			self::redirect(
				array_merge(
					self::list_args(),
					array(
						'nowcrm_forms_notice' => self::notice_from_error( $created ),
						'new'                 => '1',
					)
				)
			);
		}

		self::redirect(
			array_merge(
				self::list_args(),
				array(
					'nowcrm_forms_notice' => 'created',
					'form'                => (int) $created,
				)
			)
		);
	}

	/**
	 * Delete handler. Removes the form and every entry that belongs to it.
	 */
	public static function handle_delete(): void {
		if ( ! Capabilities::can_manage_contacts() ) {
			wp_die( esc_html__( '您沒有權限刪除表單。', 'now-crm' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( 'nowcrm_delete_form' );

		$form_id = isset( $_POST['form_id'] ) && is_scalar( $_POST['form_id'] )
			? absint( wp_unslash( (string) $_POST['form_id'] ) )
			: 0;

		$deleted = FormRepository::delete( $form_id );

		self::redirect(
			array_merge(
				self::list_args(),
				array( 'nowcrm_forms_notice' => $deleted ? 'deleted' : 'not_found' )
			)
		);
	}

	/**
	 * Discrete create screen; the list is not rendered here.
	 */
	private static function render_create(): void {
		$list_args = self::list_args();
		$form_url  = add_query_arg( $list_args, admin_url( 'admin-post.php' ) );
		?>
		<div class="wrap nowcrm-wrap">
			<div class="nowcrm-contacts__head">
				<div>
					<h1 class="wp-heading-inline"><?php esc_html_e( '新增表單', 'now-crm' ); ?></h1>
				</div>
				<div class="nowcrm-contacts__toolbar">
					<a class="button" href="<?php echo esc_url( Menu::page_url( self::SLUG, $list_args ) ); ?>"><?php esc_html_e( '回表單列表', 'now-crm' ); ?></a>
				</div>
			</div>
			<hr class="wp-header-end" />

			<?php self::render_notice(); ?>

			<div class="nowcrm-panel" id="nowcrm-forms-create">
				<form method="post" action="<?php echo esc_url( $form_url ); ?>" class="nowcrm-filters">
					<input type="hidden" name="action" value="nowcrm_create_form" />
					<?php wp_nonce_field( 'nowcrm_create_form' ); ?>

					<label>
						<span><?php esc_html_e( '表單名稱', 'now-crm' ); ?></span>
						<input type="text" id="nowcrm-form-create-title" name="title" class="regular-text" required="required" />
					</label>

					<button type="submit" class="button button-primary">
						<?php esc_html_e( '建立表單', 'now-crm' ); ?>
					</button>
				</form>
				<p class="description">
					<?php esc_html_e( '新表單會先建立姓名、Email 與訊息三個欄位；建立後再調整欄位、名單、標籤與雙重確認。', 'now-crm' ); ?>
				</p>
			</div>
		</div>
		<?php
	}

	/**
	 * Forms list: real rows, status, entries, and placement.
	 */
	private static function render_list(): void {
		$forms     = FormRepository::all();
		$entries   = new EntryRepository();
		$list_args = self::list_args();
		$search    = (string) ( $list_args['s'] ?? '' );
		$status    = (string) ( $list_args['status'] ?? 'all' );
		$paged     = (int) ( $list_args['paged'] ?? 1 );

		$status_counts = array();

		foreach ( $forms as $form ) {
			$key                   = (string) ( $form['status'] ?? 'publish' );
			$status_counts[ $key ] = (int) ( $status_counts[ $key ] ?? 0 ) + 1;
		}

		$filtered = array();

		foreach ( $forms as $form ) {
			if ( 'all' !== $status && (string) ( $form['status'] ?? 'publish' ) !== $status ) {
				continue;
			}

			if ( '' !== $search && false === stripos( (string) $form['title'], $search ) ) {
				continue;
			}

			$filtered[] = $form;
		}

		$total       = count( $filtered );
		$total_pages = max( 1, (int) ceil( $total / self::LIST_PER_PAGE ) );
		$paged       = min( $paged, $total_pages );

		if ( $paged > 1 ) {
			$list_args['paged'] = $paged;
		} else {
			unset( $list_args['paged'] );
		}

		$items       = array_slice( $filtered, ( $paged - 1 ) * self::LIST_PER_PAGE, self::LIST_PER_PAGE );
		$first_row   = $total > 0 ? ( ( $paged - 1 ) * self::LIST_PER_PAGE ) + 1 : 0;
		$last_row    = min( $total, $paged * self::LIST_PER_PAGE );
		$is_filtered = '' !== $search || 'all' !== $status;
		$state_args  = $list_args;

		unset( $state_args['paged'] );

		$form_url   = add_query_arg( $list_args, admin_url( 'admin-post.php' ) );
		$list_url   = Menu::page_url( self::SLUG );
		$create_url = Menu::page_url( self::SLUG, array_merge( $list_args, array( 'new' => '1' ) ) );
		$term_names   = array(
			TermRepository::TYPE_LIST => array(),
			TermRepository::TYPE_TAG  => array(),
		);
		$term_repo    = new TermRepository();

		foreach ( array( TermRepository::TYPE_LIST, TermRepository::TYPE_TAG ) as $term_type ) {
			foreach ( $term_repo->all( $term_type ) as $term ) {
				$term_names[ $term_type ][ (int) $term['id'] ] = (string) $term['name'];
			}
		}

		$status_labels = array(
			'publish' => __( '已發佈', 'now-crm' ),
			'draft'   => __( '草稿', 'now-crm' ),
			'pending' => __( '待審', 'now-crm' ),
			'private' => __( '私人', 'now-crm' ),
		);

		?>
		<div class="wrap nowcrm-wrap">
			<div class="nowcrm-contacts__head">
				<div>
					<h1 class="wp-heading-inline"><?php esc_html_e( '表單', 'now-crm' ); ?></h1>
					<span class="nowcrm-contacts__count">（<?php echo esc_html( (string) $total ); ?>）</span>
				</div>
				<div class="nowcrm-contacts__toolbar">
					<a class="button button-primary" href="<?php echo esc_url( $create_url ); ?>">
						<?php esc_html_e( '新增表單', 'now-crm' ); ?>
					</a>
				</div>
			</div>
			<hr class="wp-header-end" />

			<?php self::render_notice(); ?>

			<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>"
				class="nowcrm-filters" id="nowcrm-forms-filters">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>" />

				<label>
					<span><?php esc_html_e( '搜尋表單', 'now-crm' ); ?></span>
					<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>"
						placeholder="<?php esc_attr_e( '搜尋表單名稱', 'now-crm' ); ?>" />
				</label>

				<label>
					<span><?php esc_html_e( '狀態', 'now-crm' ); ?></span>
					<select name="status">
						<option value="all" <?php selected( $status, 'all' ); ?>><?php esc_html_e( '全部', 'now-crm' ); ?></option>
						<?php foreach ( $status_labels as $value => $label ) : ?>
							<?php if ( isset( $status_counts[ $value ] ) || $status === $value ) : ?>
								<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $status, $value ); ?>>
									<?php echo esc_html( $label ); ?>
								</option>
							<?php endif; ?>
						<?php endforeach; ?>
					</select>
				</label>

				<button type="submit" class="button"><?php esc_html_e( '套用', 'now-crm' ); ?></button>

				<?php if ( $is_filtered ) : ?>
					<a class="button button-link" href="<?php echo esc_url( $list_url ); ?>"><?php esc_html_e( '清除條件', 'now-crm' ); ?></a>
				<?php endif; ?>
			</form>

			<p class="nowcrm-total">
				<?php
				if ( $total > 0 ) {
					printf(
						/* translators: 1: first row number, 2: last row number, 3: total forms. */
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

			<table class="wp-list-table widefat fixed striped nowcrm-table" id="nowcrm-forms">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( '名稱', 'now-crm' ); ?></th>
						<th scope="col"><?php esc_html_e( '狀態', 'now-crm' ); ?></th>
						<th scope="col" class="nowcrm-col-number"><?php esc_html_e( '欄位數', 'now-crm' ); ?></th>
						<th scope="col" class="nowcrm-col-number"><?php esc_html_e( '送出筆數', 'now-crm' ); ?></th>
						<th scope="col"><?php esc_html_e( '最近送出', 'now-crm' ); ?></th>
						<th scope="col"><?php esc_html_e( '更新時間', 'now-crm' ); ?></th>
						<th scope="col" class="nowcrm-col-actions"><?php esc_html_e( '操作', 'now-crm' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( array() === $items ) : ?>
						<tr>
							<td colspan="7">
								<div class="nowcrm-empty">
									<?php if ( $is_filtered ) : ?>
										<p><?php esc_html_e( '沒有符合目前條件的表單。', 'now-crm' ); ?></p>
										<p class="nowcrm-empty__actions">
											<a class="button" href="<?php echo esc_url( $list_url ); ?>"><?php esc_html_e( '清除條件', 'now-crm' ); ?></a>
										</p>
									<?php else : ?>
										<p><?php esc_html_e( '尚未建立任何表單。', 'now-crm' ); ?></p>
										<p class="nowcrm-empty__actions">
											<a class="button button-primary" href="<?php echo esc_url( $create_url ); ?>"><?php esc_html_e( '新增表單', 'now-crm' ); ?></a>
										</p>
									<?php endif; ?>
								</div>
							</td>
						</tr>
					<?php else : ?>
						<?php foreach ( $items as $form ) : ?>
							<?php
							$form_id   = (int) $form['id'];
							$url       = self::editor_url( $form_id, $list_args );
							$shortcode = '[nowcrm_form id="' . $form_id . '"]';
							$form_state = (string) ( $form['status'] ?? 'publish' );
							$entry_page = $entries->entries( $form_id, 1, 1 );
							$entry_total = (int) $entry_page['total'];
							$latest      = (string) ( $entry_page['items'][0]['created_at'] ?? '' );
							$list_names  = array();
							$tag_names   = array();

							foreach ( array_map( 'absint', (array) $form['lists'] ) as $term_id ) {
								$list_names[] = $term_names[ TermRepository::TYPE_LIST ][ $term_id ] ?? '#' . $term_id;
							}

							foreach ( array_map( 'absint', (array) $form['tags'] ) as $term_id ) {
								$tag_names[] = $term_names[ TermRepository::TYPE_TAG ][ $term_id ] ?? '#' . $term_id;
							}
							?>
							<tr data-form-id="<?php echo esc_attr( (string) $form_id ); ?>">
								<td>
									<strong><a href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( (string) $form['title'] ); ?></a></strong>
									<code><?php echo esc_html( $shortcode ); ?></code>
									<?php if ( array() !== $list_names || array() !== $tag_names ) : ?>
										<p class="description">
											<?php
											$join = array();

											if ( array() !== $list_names ) {
												$join[] = sprintf(
													/* translators: %s: list names. */
													__( '名單：%s', 'now-crm' ),
													implode( '、', $list_names )
												);
											}

											if ( array() !== $tag_names ) {
												$join[] = sprintf(
													/* translators: %s: tag names. */
													__( '標籤：%s', 'now-crm' ),
													implode( '、', $tag_names )
												);
											}

											echo esc_html( implode( '｜', $join ) );
											?>
										</p>
									<?php endif; ?>
								</td>
								<td>
									<span class="nowcrm-badge <?php echo 'publish' === $form_state ? 'nowcrm-badge--subscribed' : 'nowcrm-badge--unsubscribed'; ?>">
										<?php echo esc_html( $status_labels[ $form_state ] ?? $form_state ); ?>
									</span>
								</td>
								<td class="nowcrm-col-number"><?php echo esc_html( (string) count( (array) $form['fields'] ) ); ?></td>
								<td class="nowcrm-col-number">
									<a href="<?php echo esc_url( self::entries_url( $form_id, $list_args ) ); ?>"
										data-entry-count="<?php echo esc_attr( (string) $entry_total ); ?>">
										<?php echo esc_html( (string) $entry_total ); ?>
									</a>
								</td>
								<td>
									<?php if ( '' === $latest ) : ?>
										—
									<?php else : ?>
										<a href="<?php echo esc_url( self::entries_url( $form_id, $list_args ) ); ?>">
											<?php echo esc_html( Menu::format_datetime( $latest ) ); ?>
										</a>
									<?php endif; ?>
								</td>
								<td><?php echo esc_html( Menu::format_datetime( (string) $form['updated_at'] ) ); ?></td>
								<td class="nowcrm-col-actions">
									<a class="button button-small" href="<?php echo esc_url( $url ); ?>">
										<?php esc_html_e( '設定', 'now-crm' ); ?>
									</a>
									<a class="button button-small" href="<?php echo esc_url( self::entries_url( $form_id, $list_args ) ); ?>">
										<?php esc_html_e( '送出紀錄', 'now-crm' ); ?>
									</a>
									<form method="post" action="<?php echo esc_url( $form_url ); ?>" style="display:inline;"
										onsubmit="return confirm('<?php echo esc_js( __( '確定要刪除此表單與所有送出紀錄嗎？', 'now-crm' ) ); ?>');">
										<input type="hidden" name="action" value="nowcrm_delete_form" />
										<input type="hidden" name="form_id" value="<?php echo esc_attr( (string) $form_id ); ?>" />
										<?php wp_nonce_field( 'nowcrm_delete_form' ); ?>
										<button type="submit" class="button button-small button-link-delete">
											<?php esc_html_e( '刪除', 'now-crm' ); ?>
										</button>
									</form>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>

			<?php if ( $total_pages > 1 ) : ?>
				<div class="tablenav">
					<div class="tablenav-pages">
						<?php
						echo wp_kses_post(
							(string) paginate_links(
								array(
									'base'      => add_query_arg(
										$state_args,
										admin_url( 'admin.php?page=' . rawurlencode( self::SLUG ) )
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
				</div>
			<?php endif; ?>

			<p class="description"><?php esc_html_e( '表單以短代碼放置於網站頁面。送出後建立或更新聯絡人，並套用設定的名單與標籤；刪除表單會一併刪除送出紀錄。', 'now-crm' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Entry list for one form.
	 */
	private static function render_entries( int $form_id ): void {
		$form = FormRepository::get( $form_id );

		if ( ! $form ) {
			wp_die( esc_html__( '找不到表單。', 'now-crm' ), '', array( 'response' => 404 ) );
		}

		$list_args    = self::list_args();
		$entries_args = self::entries_args( $list_args );
		$paged        = max( 1, absint( Menu::query( 'epaged', '1' ) ) );
		$result       = ( new EntryRepository() )->entries( $form_id, $paged, self::PER_PAGE );
		$base         = self::editor_url( $form_id, $list_args );
		?>
		<div class="wrap nowcrm-wrap">
			<h1 class="wp-heading-inline">
				<?php
				echo esc_html(
					sprintf(
						/* translators: %s: form title. */
						__( '送出紀錄：%s', 'now-crm' ),
						(string) $form['title']
					)
				);
				?>
			</h1>
			<hr class="wp-header-end" />

			<?php self::render_notice(); ?>

			<p>
				<a class="button" href="<?php echo esc_url( $base ); ?>"><?php esc_html_e( '回表單設定', 'now-crm' ); ?></a>
				<a class="button button-link" href="<?php echo esc_url( Menu::page_url( self::SLUG, $list_args ) ); ?>">
					<?php esc_html_e( '表單列表', 'now-crm' ); ?>
				</a>
			</p>

			<table class="wp-list-table widefat fixed striped nowcrm-table">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( '送出時間', 'now-crm' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Email', 'now-crm' ); ?></th>
						<th scope="col"><?php esc_html_e( '聯絡人', 'now-crm' ); ?></th>
						<th scope="col" class="nowcrm-col-actions"><?php esc_html_e( '內容', 'now-crm' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( array() === $result['items'] ) : ?>
						<tr>
							<td colspan="4"><?php esc_html_e( '這個表單還沒有送出紀錄。', 'now-crm' ); ?></td>
						</tr>
					<?php else : ?>
						<?php foreach ( $result['items'] as $item ) : ?>
							<?php
							$entry_id  = (int) $item['id'];
							$contact_id = (int) $item['contact_id'];
							$entry_url = self::entry_url( $form_id, $entry_id, $entries_args );
							?>
							<tr>
								<td><?php echo esc_html( Menu::format_datetime( (string) $item['created_at'] ) ); ?></td>
								<td><?php echo esc_html( (string) $item['email'] ); ?></td>
								<td>
									<?php if ( $contact_id > 0 ) : ?>
										<a href="<?php echo esc_url( Menu::page_url( Menu::CONTACT_SLUG, array( 'id' => $contact_id ) ) ); ?>">
											<?php echo esc_html( '#' . $contact_id ); ?>
										</a>
									<?php else : ?>
										—
									<?php endif; ?>
								</td>
								<td class="nowcrm-col-actions">
									<a class="button button-small" href="<?php echo esc_url( $entry_url ); ?>">
										<?php esc_html_e( '檢視', 'now-crm' ); ?>
									</a>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>

			<?php if ( (int) $result['pages'] > 1 ) : ?>
				<div class="tablenav">
					<div class="tablenav-pages">
						<?php
						echo wp_kses_post(
							(string) paginate_links(
								array(
									'base'      => add_query_arg( 'epaged', '%#%', self::entries_url( $form_id, $list_args ) ),
									'format'    => '',
									'current'   => $paged,
									'total'     => (int) $result['pages'],
									'prev_text' => '«',
									'next_text' => '»',
								)
							)
						);
						?>
					</div>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * One entry, scoped to its form. All values are escaped on output.
	 */
	private static function render_entry( int $form_id ): void {
		$form = FormRepository::get( $form_id );

		if ( ! $form ) {
			wp_die( esc_html__( '找不到表單。', 'now-crm' ), '', array( 'response' => 404 ) );
		}

		$entry_id = absint( Menu::query( 'entry' ) );
		$entry    = ( new EntryRepository() )->get( $entry_id, $form_id );

		if ( ! $entry ) {
			wp_die( esc_html__( '找不到這筆送出紀錄，或它不屬於這個表單。', 'now-crm' ), '', array( 'response' => 404 ) );
		}

		$data      = (array) $entry['data'];
		$fields    = is_array( $data['fields'] ?? null ) ? $data['fields'] : array();
		$terms     = new TermRepository();
		$list_ids  = array_map( 'absint', (array) ( $data['lists'] ?? array() ) );
		$tag_ids   = array_map( 'absint', (array) ( $data['tags'] ?? array() ) );
		$list_args = self::list_args();
		?>
		<div class="wrap nowcrm-wrap">
			<h1 class="wp-heading-inline">
				<?php
				echo esc_html(
					sprintf(
						/* translators: %s: form title. */
						__( '送出內容：%s', 'now-crm' ),
						(string) $form['title']
					)
				);
				?>
			</h1>
			<hr class="wp-header-end" />

			<?php self::render_notice(); ?>

			<p>
				<a class="button" href="<?php echo esc_url( self::entries_url( $form_id, self::entries_args( $list_args ) ) ); ?>">
					<?php esc_html_e( '回送出紀錄', 'now-crm' ); ?>
				</a>
				<a class="button button-link" href="<?php echo esc_url( self::editor_url( $form_id, $list_args ) ); ?>">
					<?php esc_html_e( '表單設定', 'now-crm' ); ?>
				</a>
			</p>

			<div class="nowcrm-panel">
				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row"><?php esc_html_e( '送出時間', 'now-crm' ); ?></th>
							<td><?php echo esc_html( Menu::format_datetime( (string) $entry['created_at'] ) ); ?></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Email', 'now-crm' ); ?></th>
							<td><?php echo esc_html( (string) $entry['email'] ); ?></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( '聯絡人', 'now-crm' ); ?></th>
							<td>
								<?php if ( (int) $entry['contact_id'] > 0 ) : ?>
									<a href="<?php echo esc_url( Menu::page_url( Menu::CONTACT_SLUG, array( 'id' => (int) $entry['contact_id'] ) ) ); ?>">
										<?php echo esc_html( '#' . (int) $entry['contact_id'] ); ?>
									</a>
								<?php else : ?>
									—
								<?php endif; ?>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( '雙重確認', 'now-crm' ); ?></th>
							<td>
								<?php echo ! empty( $data['double_opt_in'] ) ? esc_html__( '需要（新聯絡人依全域／名單政策確認，未必立即寄出）', 'now-crm' ) : esc_html__( '不需要', 'now-crm' ); ?>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( '名單', 'now-crm' ); ?></th>
							<td><?php echo esc_html( self::term_names( $terms, $list_ids ) ); ?></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( '標籤', 'now-crm' ); ?></th>
							<td><?php echo esc_html( self::term_names( $terms, $tag_ids ) ); ?></td>
						</tr>
					</tbody>
				</table>
			</div>

			<table class="wp-list-table widefat fixed striped nowcrm-table">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( '欄位', 'now-crm' ); ?></th>
						<th scope="col"><?php esc_html_e( '送出值', 'now-crm' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( array() === $fields ) : ?>
						<tr>
							<td colspan="2"><?php esc_html_e( '此筆紀錄沒有欄位資料。', 'now-crm' ); ?></td>
						</tr>
					<?php else : ?>
						<?php foreach ( $fields as $field ) : ?>
							<?php
							if ( ! is_array( $field ) ) {
								continue;
							}

							$label = (string) ( $field['label'] ?? $field['key'] ?? '' );
							$value = $field['value'] ?? '';
							$text  = is_array( $value )
								? implode( '、', array_map( 'strval', $value ) )
								: (string) $value;
							?>
							<tr>
								<th scope="row"><?php echo esc_html( $label ); ?></th>
								<td><?php echo '' !== $text ? esc_html( $text ) : '—'; ?></td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Term names for display, preserving stored order.
	 *
	 * @param int[] $ids Term IDs.
	 */
	private static function term_names( TermRepository $terms, array $ids ): string {
		$names = array();

		foreach ( $ids as $id ) {
			$term = $terms->get( (int) $id );
			$names[] = $term ? (string) $term['name'] : '#' . (int) $id;
		}

		return array() === $names ? '—' : implode( '、', $names );
	}

	/**
	 * Redirect to this screen with arguments.
	 *
	 * @param array<string, mixed> $args Query arguments.
	 */
	private static function redirect( array $args ): void {
		wp_safe_redirect( Menu::page_url( self::SLUG, $args ) );
		exit;
	}

	/**
	 * Allow-listed form notices.
	 *
	 * @return array<string, string>
	 */
	private static function notice_messages(): array {
		return array(
			'created'          => __( '表單已建立。', 'now-crm' ),
			'saved'            => __( '表單已儲存。', 'now-crm' ),
			'deleted'          => __( '表單與送出紀錄已刪除。', 'now-crm' ),
			'title_required'   => __( '請輸入表單名稱。', 'now-crm' ),
			'needs_email'      => __( '表單必須包含且只能包含一個 Email 欄位。', 'now-crm' ),
			'no_fields'        => __( '請至少新增一個欄位。', 'now-crm' ),
			'invalid_options'  => __( '下拉選單、單選與核取方塊欄位至少需要一個選項。', 'now-crm' ),
			'not_found'        => __( '找不到表單或紀錄。', 'now-crm' ),
			'error'            => __( '操作失敗，請檢查輸入後再試一次。', 'now-crm' ),
		);
	}
}
