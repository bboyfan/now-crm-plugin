<?php
/**
 * Smart link admin screen: a data-first list, an on-demand create screen, and
 * a focused edit screen with status, click counts, and usage guidance.
 *
 * @package NowCRM
 */

namespace NowCRM\Admin;

use NowCRM\SmartLinks\SmartLinkRepository;
use NowCRM\Support\Capabilities;
use NowCRM\Terms\TermRepository;
use WP_Error;

defined( 'ABSPATH' ) || exit;

final class SmartLinksPage {

	public const SLUG = 'nowcrm-smart-links';

	private const NOTICE_QUERY = 'nowcrm_smart_notice';
	private const PER_PAGE     = 20;

	/**
	 * Notice codes that represent failures.
	 *
	 * @var string[]
	 */
	private const ERROR_CODES = array(
		'title_required',
		'invalid_target',
		'invalid_slug',
		'duplicate_slug',
		'invalid_term',
		'invalid_status',
		'not_found',
		'db_error',
	);

	/**
	 * Register the admin screen and its mutation handlers.
	 */
	public static function register(): void {
		add_action( 'admin_menu', array( __CLASS__, 'add_page' ) );
		add_action( 'admin_post_nowcrm_create_smart_link', array( __CLASS__, 'handle_create' ) );
		add_action( 'admin_post_nowcrm_update_smart_link', array( __CLASS__, 'handle_update' ) );
		add_action( 'admin_post_nowcrm_set_smart_link_status', array( __CLASS__, 'handle_status' ) );
		add_action( 'admin_post_nowcrm_delete_smart_link', array( __CLASS__, 'handle_delete' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	/**
	 * Add the smart links screen below the NOW CRM menu.
	 */
	public static function add_page(): void {
		add_submenu_page(
			Menu::DASHBOARD_SLUG,
			__( '智慧連結', 'now-crm' ),
			__( '智慧連結', 'now-crm' ),
			Capabilities::MANAGE_CONTACTS,
			self::SLUG,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Load the shared admin stylesheet and this screen's small layout rules.
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

		wp_add_inline_style( 'nowcrm-admin', self::styles() );
	}

	/**
	 * Render the list, the create screen, or the edit screen.
	 */
	public static function render(): void {
		if ( ! Capabilities::can_manage_contacts() ) {
			wp_die(
				esc_html__( '您沒有權限管理智慧連結。', 'now-crm' ),
				'',
				array( 'response' => 403 )
			);
		}

		$repo  = new SmartLinkRepository();
		$terms = new TermRepository();

		$lists = $terms->all( TermRepository::TYPE_LIST );
		$tags  = $terms->all( TermRepository::TYPE_TAG );

		$search  = sanitize_text_field( Menu::query( 's' ) );
		$paged   = max( 1, absint( Menu::query( 'paged', '1' ) ) );
		$edit_id = absint( Menu::query( 'edit' ) );

		$list_args = array();

		if ( '' !== $search ) {
			$list_args['s'] = $search;
		}

		if ( $paged > 1 ) {
			$list_args['paged'] = $paged;
		}

		$list_url = Menu::page_url( self::SLUG, $list_args );

		if ( $edit_id > 0 ) {
			$editing = $repo->get( $edit_id );

			if ( $editing ) {
				self::render_editor( $editing, $lists, $tags, $list_url, $list_args );
				return;
			}

			self::render_list( $repo, $search, $paged, $lists, $tags, true );
			return;
		}

		if ( '1' === Menu::query( 'new' ) ) {
			self::render_editor( null, $lists, $tags, $list_url, $list_args );
			return;
		}

		self::render_list( $repo, $search, $paged, $lists, $tags, false );
	}

	/**
	 * The default screen: filters, rows, and paging. No form until requested.
	 *
	 * @param SmartLinkRepository              $repo     Repository.
	 * @param string                           $search   Applied search.
	 * @param int                              $paged    Requested page.
	 * @param array<int, array<string, mixed>> $lists    Lists.
	 * @param array<int, array<string, mixed>> $tags     Tags.
	 * @param bool                             $not_found True when an edit ID did not resolve.
	 */
	private static function render_list( SmartLinkRepository $repo, string $search, int $paged, array $lists, array $tags, bool $not_found ): void {
		$result = $repo->paginate(
			array(
				'search'   => $search,
				'page'     => $paged,
				'per_page' => self::PER_PAGE,
			)
		);

		if ( $paged > (int) $result['pages'] && (int) $result['total'] > 0 ) {
			$paged  = (int) $result['pages'];
			$result = $repo->paginate(
				array(
					'search'   => $search,
					'page'     => $paged,
					'per_page' => self::PER_PAGE,
				)
			);
		}

		$links       = $result['items'];
		$total       = (int) $result['total'];
		$total_pages = (int) $result['pages'];
		$form_url    = admin_url( 'admin-post.php' );
		$scope_args  = array_filter( array( 's' => $search ) );

		if ( $paged > 1 ) {
			$scope_args['paged'] = $paged;
		}

		$create_url  = Menu::page_url( self::SLUG, array_merge( array( 'new' => '1' ), $scope_args ) );
		$list_names  = self::term_names( $lists );
		$tag_names   = self::term_names( $tags );
		$first_row   = $total > 0 ? ( ( $paged - 1 ) * self::PER_PAGE ) + 1 : 0;
		$last_row    = min( $total, $paged * self::PER_PAGE );
		?>
		<div class="wrap nowcrm-wrap" id="nowcrm-smart-links">
			<div class="nowcrm-contacts__head">
				<div>
					<h1 class="wp-heading-inline"><?php esc_html_e( '智慧連結', 'now-crm' ); ?></h1>
					<span class="nowcrm-contacts__count">
						<?php
						printf(
							/* translators: %d: number of links. */
							esc_html__( '（%d）', 'now-crm' ),
							(int) $total
						);
						?>
					</span>
				</div>
				<div class="nowcrm-contacts__toolbar">
					<a class="button button-primary" href="<?php echo esc_url( $create_url ); ?>">
						<?php esc_html_e( '新增智慧連結', 'now-crm' ); ?>
					</a>
				</div>
			</div>
			<hr class="wp-header-end" />

			<?php self::render_notice(); ?>

			<?php if ( $not_found ) : ?>
				<div class="notice notice-error inline">
					<p><?php esc_html_e( '找不到這個智慧連結，已返回列表。', 'now-crm' ); ?></p>
				</div>
			<?php endif; ?>

			<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="nowcrm-contacts-filters">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>" />
				<label class="nowcrm-contacts-search">
					<span class="screen-reader-text"><?php esc_html_e( '搜尋智慧連結', 'now-crm' ); ?></span>
					<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>"
						placeholder="<?php esc_attr_e( '搜尋名稱、Slug 或目標網址', 'now-crm' ); ?>" />
					<button type="submit" class="button"><?php esc_html_e( '搜尋', 'now-crm' ); ?></button>
					<?php if ( '' !== $search ) : ?>
						<a class="nowcrm-filter-clear" href="<?php echo esc_url( Menu::page_url( self::SLUG ) ); ?>">
							<?php esc_html_e( '清除搜尋', 'now-crm' ); ?>
						</a>
					<?php endif; ?>
				</label>
			</form>

			<p class="description nowcrm-smart-links__usage">
				<?php
				echo wp_kses_post(
					sprintf(
						/* translators: %s: query parameter name. */
						esc_html__( '短網址固定使用 %s 查詢參數。電子報寄送時附加的聯絡人簽章不會顯示在後台，也不會在後台開啟連結。', 'now-crm' ),
						'<code>?nowcrm_smart=&lt;slug&gt;</code>'
					)
				);
				?>
			</p>

			<div class="nowcrm-contacts-surface">
				<table class="nowcrm-contacts-table">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( '名稱', 'now-crm' ); ?></th>
							<th scope="col"><?php esc_html_e( '目標網址', 'now-crm' ); ?></th>
							<th scope="col"><?php esc_html_e( '狀態', 'now-crm' ); ?></th>
							<th scope="col"><?php esc_html_e( '對象動作', 'now-crm' ); ?></th>
							<th scope="col"><?php esc_html_e( '點擊', 'now-crm' ); ?></th>
							<th scope="col"><?php esc_html_e( '短網址', 'now-crm' ); ?></th>
							<th scope="col" class="nowcrm-col-actions"><?php esc_html_e( '操作', 'now-crm' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php if ( array() === $links ) : ?>
							<tr>
								<td colspan="7">
									<div class="nowcrm-empty">
										<?php if ( '' !== $search ) : ?>
											<p><?php esc_html_e( '沒有符合目前搜尋的智慧連結。', 'now-crm' ); ?></p>
											<p class="nowcrm-empty__actions">
												<a class="button" href="<?php echo esc_url( Menu::page_url( self::SLUG ) ); ?>">
													<?php esc_html_e( '清除搜尋', 'now-crm' ); ?>
												</a>
											</p>
										<?php else : ?>
											<p><?php esc_html_e( '尚未建立任何智慧連結。', 'now-crm' ); ?></p>
											<p class="nowcrm-empty__actions">
												<a class="button button-primary" href="<?php echo esc_url( $create_url ); ?>">
													<?php esc_html_e( '新增智慧連結', 'now-crm' ); ?>
												</a>
											</p>
										<?php endif; ?>
									</div>
								</td>
							</tr>
						<?php else : ?>
							<?php foreach ( $links as $link ) : ?>
								<?php
								$link_id   = (int) $link['id'];
								$is_paused = SmartLinkRepository::STATUS_PAUSED === $link['status'];
								$all       = (int) $link['all_clicks'];
								$contacts  = min( $all, (int) $link['contact_clicks'] );
								$anonymous = max( 0, $all - $contacts );
								$edit_url  = Menu::page_url(
									self::SLUG,
									array_merge(
										$scope_args,
										array( 'edit' => $link_id )
									)
								);
								?>
								<tr data-nowcrm-smart-link="<?php echo esc_attr( (string) $link_id ); ?>"
									data-nowcrm-smart-status="<?php echo esc_attr( (string) $link['status'] ); ?>">
									<td>
										<strong><?php echo esc_html( (string) $link['title'] ); ?></strong>
										<div class="description"><code><?php echo esc_html( (string) $link['slug'] ); ?></code></div>
									</td>
									<td>
										<span title="<?php echo esc_attr( (string) $link['target_url'] ); ?>">
											<?php echo esc_html( wp_html_excerpt( (string) $link['target_url'], 60, '…' ) ); ?>
										</span>
									</td>
									<td>
										<span class="nowcrm-status-badge">
											<span class="nowcrm-status-dot" aria-hidden="true"></span>
											<?php echo esc_html( $is_paused ? __( '已暫停', 'now-crm' ) : __( '啟用中', 'now-crm' ) ); ?>
										</span>
									</td>
									<td>
										<?php self::render_actions_cell( $link, $list_names, $tag_names ); ?>
									</td>
									<td>
										<strong><?php echo esc_html( (string) $all ); ?></strong>
										<div class="description">
											<?php
											printf(
												/* translators: 1: clicks with a contact, 2: anonymous clicks. */
												esc_html__( '聯絡人 %1$d · 匿名 %2$d', 'now-crm' ),
												(int) $contacts,
												(int) $anonymous
											);
											?>
										</div>
									</td>
									<td>
										<input type="text" readonly="readonly" class="code nowcrm-smart-links__short"
											data-nowcrm-smart-link-url="<?php echo esc_attr( (string) $link_id ); ?>"
											aria-label="<?php esc_attr_e( '短網址', 'now-crm' ); ?>"
											value="<?php echo esc_attr( (string) $link['short_url'] ); ?>" />
									</td>
									<td>
										<a class="button button-small" href="<?php echo esc_url( $edit_url ); ?>">
											<?php esc_html_e( '編輯', 'now-crm' ); ?>
										</a>
										<form method="post" action="<?php echo esc_url( $form_url ); ?>" class="nowcrm-inline">
											<input type="hidden" name="action" value="nowcrm_set_smart_link_status" />
											<input type="hidden" name="link_id" value="<?php echo esc_attr( (string) $link_id ); ?>" />
											<input type="hidden" name="status" value="<?php echo esc_attr( $is_paused ? SmartLinkRepository::STATUS_ACTIVE : SmartLinkRepository::STATUS_PAUSED ); ?>" />
											<input type="hidden" name="s" value="<?php echo esc_attr( $search ); ?>" />
											<?php if ( $paged > 1 ) : ?>
												<input type="hidden" name="paged" value="<?php echo esc_attr( (string) $paged ); ?>" />
											<?php endif; ?>
											<?php wp_nonce_field( 'nowcrm_set_smart_link_status' ); ?>
											<button type="submit" class="button button-small"
												data-nowcrm-smart-link-action="<?php echo esc_attr( $is_paused ? 'activate' : 'pause' ); ?>">
												<?php echo esc_html( $is_paused ? __( '啟用', 'now-crm' ) : __( '暫停', 'now-crm' ) ); ?>
											</button>
										</form>
										<form method="post" action="<?php echo esc_url( $form_url ); ?>" class="nowcrm-inline">
											<input type="hidden" name="action" value="nowcrm_delete_smart_link" />
											<input type="hidden" name="link_id" value="<?php echo esc_attr( (string) $link_id ); ?>" />
											<input type="hidden" name="s" value="<?php echo esc_attr( $search ); ?>" />
											<?php if ( $paged > 1 ) : ?>
												<input type="hidden" name="paged" value="<?php echo esc_attr( (string) $paged ); ?>" />
											<?php endif; ?>
											<?php wp_nonce_field( 'nowcrm_delete_smart_link' ); ?>
											<button type="submit" class="button button-small button-link-delete"
												data-nowcrm-smart-link-action="delete"
												onclick="return confirm('<?php echo esc_js( __( '確定要刪除這個智慧連結嗎？', 'now-crm' ) ); ?>');">
												<?php esc_html_e( '刪除', 'now-crm' ); ?>
											</button>
										</form>
									</td>
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
								/* translators: 1: first row number, 2: last row number, 3: total links. */
								esc_html__( '顯示 %1$d–%2$d，共 %3$d 筆智慧連結', 'now-crm' ),
								(int) $first_row,
								(int) $last_row,
								(int) $total
							);
						} else {
							esc_html_e( '共 0 筆智慧連結', 'now-crm' );
						}
						?>
					</p>

					<?php if ( $total_pages > 1 ) : ?>
						<nav class="nowcrm-pagination" aria-label="<?php esc_attr_e( '智慧連結分頁', 'now-crm' ); ?>">
							<?php if ( $paged > 1 ) : ?>
								<a class="nowcrm-page-link" href="<?php echo esc_url( Menu::page_url( self::SLUG, array_merge( array_filter( array( 's' => $search ) ), array( 'paged' => $paged - 1 ) ) ) ); ?>">‹</a>
							<?php endif; ?>

							<?php for ( $page_number = max( 1, $paged - 2 ); $page_number <= min( $total_pages, $paged + 2 ); $page_number++ ) : ?>
								<a class="nowcrm-page-link <?php echo $page_number === $paged ? 'is-active' : ''; ?>"
									href="<?php echo esc_url( Menu::page_url( self::SLUG, array_merge( array_filter( array( 's' => $search ) ), array( 'paged' => $page_number ) ) ) ); ?>"
									<?php echo $page_number === $paged ? 'aria-current="page"' : ''; ?>>
									<?php echo esc_html( (string) $page_number ); ?>
								</a>
							<?php endfor; ?>

							<?php if ( $paged < $total_pages ) : ?>
								<a class="nowcrm-page-link" href="<?php echo esc_url( Menu::page_url( self::SLUG, array_merge( array_filter( array( 's' => $search ) ), array( 'paged' => $paged + 1 ) ) ) ); ?>">›</a>
							<?php endif; ?>
						</nav>
					<?php endif; ?>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Create or edit screen. A null link means the create screen.
	 *
	 * @param array<string, mixed>|null        $link      Existing link when editing.
	 * @param array<int, array<string, mixed>> $lists     Lists.
	 * @param array<int, array<string, mixed>> $tags      Tags.
	 * @param string                           $list_url  Back/cancel target.
	 * @param array<string, mixed>             $list_args Validated search and page scope.
	 */
	private static function render_editor( ?array $link, array $lists, array $tags, string $list_url, array $list_args ): void {
		$is_edit     = null !== $link;
		$route       = $is_edit ? 'edit:' . (int) $link['id'] : 'create';
		$draft       = self::take_draft( $route );
		$values      = $link;

		if ( null !== $draft ) {
			$values = $is_edit
				? array_merge( (array) $link, $draft )
				: array_merge( array( 'status' => SmartLinkRepository::STATUS_ACTIVE ), $draft );
		}

		$form_action = $is_edit ? 'nowcrm_update_smart_link' : 'nowcrm_create_smart_link';
		$slug        = $is_edit ? (string) $link['slug'] : '';
		$status      = $is_edit ? (string) $link['status'] : SmartLinkRepository::STATUS_ACTIVE;
		$is_paused   = SmartLinkRepository::STATUS_PAUSED === $status;
		$all         = $is_edit ? (int) $link['all_clicks'] : 0;
		$contacts    = $is_edit ? min( $all, (int) $link['contact_clicks'] ) : 0;
		$anonymous   = max( 0, $all - $contacts );
		$heading     = $is_edit
			? sprintf(
				/* translators: %s: link title. */
				__( '編輯：%s', 'now-crm' ),
				(string) $link['title']
			)
			: __( '新增智慧連結', 'now-crm' );
		?>
		<div class="wrap nowcrm-wrap" id="nowcrm-smart-links">
			<p class="nowcrm-smart-links__back">
				<a href="<?php echo esc_url( $list_url ); ?>">← <?php esc_html_e( '返回智慧連結', 'now-crm' ); ?></a>
			</p>

			<div class="nowcrm-contacts__head">
				<div>
					<h1 class="wp-heading-inline nowcrm-smart-links__heading"><?php echo esc_html( $heading ); ?></h1>
					<?php if ( $is_edit ) : ?>
						<span class="nowcrm-contacts__count">
							<span class="nowcrm-status-badge">
								<span class="nowcrm-status-dot" aria-hidden="true"></span>
								<?php echo esc_html( $is_paused ? __( '已暫停', 'now-crm' ) : __( '啟用中', 'now-crm' ) ); ?>
							</span>
							<code><?php echo esc_html( $slug ); ?></code>
						</span>
					<?php endif; ?>
				</div>
			</div>
			<hr class="wp-header-end" />

			<?php self::render_notice(); ?>

			<div class="nowcrm-smart-links__body">
				<form id="nowcrm-smart-link-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="nowcrm-panel">
					<input type="hidden" name="action" value="<?php echo esc_attr( $form_action ); ?>" />
					<?php if ( $is_edit ) : ?>
						<input type="hidden" name="link_id" value="<?php echo esc_attr( (string) (int) $link['id'] ); ?>" />
					<?php endif; ?>
					<?php wp_nonce_field( $form_action ); ?>
					<?php foreach ( $list_args as $name => $value ) : ?>
						<input type="hidden" name="<?php echo esc_attr( (string) $name ); ?>" value="<?php echo esc_attr( (string) $value ); ?>" />
					<?php endforeach; ?>

					<?php self::fields( $values ); ?>
					<?php self::terms_fields( $lists, $tags, $values ); ?>

					<p class="nowcrm-smart-links__buttons">
						<button type="submit" class="button button-primary">
							<?php echo esc_html( $is_edit ? __( '儲存', 'now-crm' ) : __( '建立', 'now-crm' ) ); ?>
						</button>
						<a class="button" href="<?php echo esc_url( $list_url ); ?>">
							<?php esc_html_e( '取消', 'now-crm' ); ?>
						</a>
					</p>
				</form>

				<aside class="nowcrm-smart-links__side">
					<?php if ( $is_edit ) : ?>
						<div class="nowcrm-panel">
							<h2><?php esc_html_e( '成效', 'now-crm' ); ?></h2>
							<dl class="nowcrm-smart-links__metrics">
								<dt><?php esc_html_e( '全部點擊', 'now-crm' ); ?></dt>
								<dd><?php echo esc_html( (string) $all ); ?></dd>
								<dt><?php esc_html_e( '聯絡人點擊', 'now-crm' ); ?></dt>
								<dd><?php echo esc_html( (string) $contacts ); ?></dd>
								<dt><?php esc_html_e( '匿名點擊', 'now-crm' ); ?></dt>
								<dd><?php echo esc_html( (string) $anonymous ); ?></dd>
								<dt><?php esc_html_e( '建立時間', 'now-crm' ); ?></dt>
								<dd><?php echo esc_html( Menu::format_datetime( (string) $link['created_at'] ) ); ?></dd>
								<dt><?php esc_html_e( '更新時間', 'now-crm' ); ?></dt>
								<dd><?php echo esc_html( Menu::format_datetime( (string) $link['updated_at'] ) ); ?></dd>
							</dl>
							<p class="description">
								<?php esc_html_e( '聯絡人點擊帶有有效簽章；其他點擊計為匿名。暫停期間不計入任何點擊。', 'now-crm' ); ?>
							</p>
						</div>

						<div class="nowcrm-panel">
							<h2><?php esc_html_e( '使用方式', 'now-crm' ); ?></h2>
							<label class="nowcrm-field">
								<span><?php esc_html_e( '短網址', 'now-crm' ); ?></span>
								<input type="text" readonly="readonly" class="large-text code"
									value="<?php echo esc_attr( (string) $link['short_url'] ); ?>" />
							</label>
							<p class="description">
								<?php
								echo wp_kses_post(
									sprintf(
										/* translators: %s: query parameter name. */
										esc_html__( '固定查詢參數：%s。把短網址貼到電子報、頁面或外部素材；外掛只用 slug 轉址。', 'now-crm' ),
										'<code>nowcrm_smart=' . esc_html( $slug ) . '</code>'
									)
								);
								?>
							</p>
							<p class="description">
								<?php esc_html_e( '電子報寄送時由系統附加的聯絡人簽章不會顯示在這裡。請勿手動附加 nowcrm_contact 參數，也不要在後台開啟連結。', 'now-crm' ); ?>
							</p>
						</div>
					<?php else : ?>
						<div class="nowcrm-panel">
							<h2><?php esc_html_e( '使用方式', 'now-crm' ); ?></h2>
							<p class="description">
								<?php esc_html_e( '儲存後會產生短網址。短網址固定使用 ?nowcrm_smart=<slug>；Slug 留空時自動產生。', 'now-crm' ); ?>
							</p>
							<p class="description">
								<?php esc_html_e( '只有帶有效聯絡人簽章的點擊才會套用下方名單與標籤；後台不會開啟或預覽目標網址。', 'now-crm' ); ?>
							</p>
						</div>
					<?php endif; ?>
				</aside>
			</div>
		</div>
		<?php
	}

	/**
	 * Audience action groups for one row, with term names.
	 *
	 * @param array<string, mixed> $link      Link row.
	 * @param array<int, string>   $list_names List ID to name map.
	 * @param array<int, string>   $tag_names  Tag ID to name map.
	 */
	private static function render_actions_cell( array $link, array $list_names, array $tag_names ): void {
		$groups = array(
			array(
				'label' => __( '加入名單', 'now-crm' ),
				'ids'   => (array) $link['apply_lists'],
				'names' => $list_names,
			),
			array(
				'label' => __( '加入標籤', 'now-crm' ),
				'ids'   => (array) $link['apply_tags'],
				'names' => $tag_names,
			),
			array(
				'label' => __( '移出名單', 'now-crm' ),
				'ids'   => (array) $link['remove_lists'],
				'names' => $list_names,
			),
			array(
				'label' => __( '移除標籤', 'now-crm' ),
				'ids'   => (array) $link['remove_tags'],
				'names' => $tag_names,
			),
		);

		$printed = 0;

		foreach ( $groups as $group ) {
			$names = self::action_term_names( $group['ids'], $group['names'] );

			if ( array() === $names ) {
				continue;
			}

			++$printed;
			?>
			<div class="nowcrm-smart-audience__group">
				<span class="nowcrm-smart-audience__label"><?php echo esc_html( $group['label'] ); ?></span>
				<span class="nowcrm-chip-list">
					<?php foreach ( $names as $name ) : ?>
						<span class="nowcrm-chip"><?php echo esc_html( $name ); ?></span>
					<?php endforeach; ?>
				</span>
			</div>
			<?php
		}

		if ( 0 === $printed ) {
			?>
			<span class="description"><?php esc_html_e( '無', 'now-crm' ); ?></span>
			<?php
		}
	}

	/**
	 * Term names for a stored ID list. Deleted terms stay visible as an ID.
	 *
	 * @param array<int, mixed>  $ids   Stored term IDs.
	 * @param array<int, string> $names Term ID to name map.
	 * @return string[]
	 */
	private static function action_term_names( array $ids, array $names ): array {
		$resolved = array();

		foreach ( array_unique( array_map( 'absint', $ids ) ) as $id ) {
			if ( $id <= 0 ) {
				continue;
			}

			$resolved[] = $names[ $id ] ?? sprintf(
				/* translators: %d: deleted term ID. */
				__( '已刪除 (#%d)', 'now-crm' ),
				$id
			);
		}

		return $resolved;
	}

	/**
	 * Term ID to name map for both resource types.
	 *
	 * @param array<int, array<string, mixed>> $terms Term rows.
	 * @return array<int, string>
	 */
	private static function term_names( array $terms ): array {
		$names = array();

		foreach ( $terms as $term ) {
			$names[ (int) $term['id'] ] = (string) $term['name'];
		}

		return $names;
	}

	/**
	 * Create a link.
	 */
	public static function handle_create(): void {
		self::guard( 'nowcrm_create_smart_link' );

		$data    = self::payload();
		$created = ( new SmartLinkRepository() )->create( $data );

		if ( is_wp_error( $created ) ) {
			self::remember_draft( 'create', $data );
			self::redirect(
				self::notice_from_error( $created ),
				array_merge( array( 'new' => '1' ), self::list_scope_args() )
			);
		}

		self::redirect( 'created', array_merge( array( 'edit' => (int) $created ), self::list_scope_args() ) );
	}

	/**
	 * Update a link.
	 */
	public static function handle_update(): void {
		self::guard( 'nowcrm_update_smart_link' );

		$link_id = absint( self::post_raw( 'link_id' ) );
		$data    = self::payload();
		$updated = $link_id > 0
			? ( new SmartLinkRepository() )->update( $link_id, $data )
			: new WP_Error( 'nowcrm_smart_not_found' );

		if ( is_wp_error( $updated ) ) {
			$notice = self::notice_from_error( $updated );

			if ( $link_id > 0 ) {
				self::remember_draft( 'edit:' . $link_id, $data );
				self::redirect( $notice, array_merge( array( 'edit' => $link_id ), self::list_scope_args() ) );
			}

			self::redirect( $notice, self::list_scope_args() );
		}

		self::redirect( 'saved', array_merge( array( 'edit' => $link_id ), self::list_scope_args() ) );
	}

	/**
	 * Pause or activate a link.
	 */
	public static function handle_status(): void {
		self::guard( 'nowcrm_set_smart_link_status' );

		$link_id = absint( self::post_raw( 'link_id' ) );
		$status  = sanitize_key( self::post_raw( 'status' ) );
		$result  = ( new SmartLinkRepository() )->set_status( $link_id, $status );

		if ( is_wp_error( $result ) ) {
			self::redirect( self::notice_from_error( $result ), self::list_scope_args() );
		}

		self::redirect(
			SmartLinkRepository::STATUS_PAUSED === $status ? 'paused' : 'activated',
			self::list_scope_args()
		);
	}

	/**
	 * Delete a link.
	 */
	public static function handle_delete(): void {
		self::guard( 'nowcrm_delete_smart_link' );

		$link_id = absint( self::post_raw( 'link_id' ) );
		$deleted = $link_id > 0 && ( new SmartLinkRepository() )->delete( $link_id );

		self::redirect( $deleted ? 'deleted' : 'not_found', self::list_scope_args() );
	}

	/**
	 * Current search and page scope carried by a list action or form.
	 *
	 * @return array<string, string>
	 */
	private static function list_scope_args(): array {
		$args   = array();
		$search = sanitize_text_field( self::post_raw( 's' ) );

		if ( '' !== $search ) {
			$args['s'] = $search;
		}

		$paged = absint( self::post_raw( 'paged' ) );

		if ( $paged > 1 ) {
			$args['paged'] = (string) $paged;
		}

		return $args;
	}

	/**
	 * Preserve one rejected submission for the current operator and form.
	 *
	 * @param string               $route  Form route key.
	 * @param array<string, mixed> $fields Sanitized, allow-listed form values.
	 */
	private static function remember_draft( string $route, array $fields ): void {
		set_transient(
			self::draft_key(),
			array( 'route' => $route, 'fields' => $fields ),
			5 * MINUTE_IN_SECONDS
		);
	}

	private static function draft_key(): string {
		return 'nowcrm_smart_link_draft_' . get_current_user_id();
	}

	/**
	 * One-time draft for this route after a rejected submission.
	 *
	 * @param string $route Form route key.
	 * @return array<string, mixed>|null
	 */
	private static function take_draft( string $route ): ?array {
		if ( ! in_array( sanitize_key( Menu::query( self::NOTICE_QUERY ) ), self::ERROR_CODES, true ) ) {
			return null;
		}

		$draft = get_transient( self::draft_key() );

		if ( ! is_array( $draft ) || $route !== (string) ( $draft['route'] ?? '' ) || ! is_array( $draft['fields'] ?? null ) ) {
			return null;
		}

		delete_transient( self::draft_key() );

		return (array) $draft['fields'];
	}

	/**
	 * Capability + nonce gate shared by every handler.
	 */
	private static function guard( string $action ): void {
		if ( ! Capabilities::can_manage_contacts() ) {
			wp_die(
				esc_html__( '您沒有權限執行此操作。', 'now-crm' ),
				'',
				array( 'response' => 403 )
			);
		}

		check_admin_referer( $action );
	}

	/**
	 * Allow-listed notice messages.
	 *
	 * @return array<string, string>
	 */
	private static function messages(): array {
		return array(
			'created'        => __( '智慧連結已建立。', 'now-crm' ),
			'saved'          => __( '智慧連結已儲存。', 'now-crm' ),
			'paused'         => __( '智慧連結已暫停，短網址會回傳 404。', 'now-crm' ),
			'activated'      => __( '智慧連結已啟用。', 'now-crm' ),
			'deleted'        => __( '智慧連結已刪除。', 'now-crm' ),
			'title_required' => __( '請輸入智慧連結名稱。', 'now-crm' ),
			'invalid_target' => __( '目標網址必須是完整的 http 或 https 網址。', 'now-crm' ),
			'invalid_slug'   => __( 'Slug 只能使用小寫英數字與連字號，長度 3 到 64 個字元。', 'now-crm' ),
			'duplicate_slug' => __( '這個 Slug 已被其他智慧連結使用。', 'now-crm' ),
			'invalid_term'   => __( '選取的名單或標籤已不存在。', 'now-crm' ),
			'invalid_status' => __( '連結狀態不正確。', 'now-crm' ),
			'not_found'      => __( '找不到這個智慧連結。', 'now-crm' ),
			'db_error'       => __( '資料庫寫入失敗，請再試一次。', 'now-crm' ),
		);
	}

	/**
	 * Print the notice requested by the current request.
	 */
	private static function render_notice(): void {
		$raw = Menu::query( self::NOTICE_QUERY );

		if ( '' === $raw ) {
			return;
		}

		$code     = sanitize_key( $raw );
		$messages = self::messages();

		if ( ! array_key_exists( $code, $messages ) ) {
			return;
		}

		printf(
			'<div class="notice %1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( in_array( $code, self::ERROR_CODES, true ) ? 'notice-error' : 'notice-success' ),
			esc_html( $messages[ $code ] )
		);
	}

	/**
	 * Translate a repository error into an allow-listed notice code.
	 */
	private static function notice_from_error( WP_Error $error ): string {
		$code = preg_replace( '/^nowcrm_smart_/', '', (string) $error->get_error_code() );

		return is_string( $code ) && array_key_exists( $code, self::messages() ) ? $code : 'db_error';
	}

	/**
	 * Redirect back to the screen with a notice.
	 *
	 * @param array<string, mixed> $args Extra query arguments.
	 */
	private static function redirect( string $notice, array $args = array() ): void {
		wp_safe_redirect(
			Menu::page_url(
				self::SLUG,
				array_merge( array( self::NOTICE_QUERY => $notice ), $args )
			)
		);

		exit;
	}

	/**
	 * Shared title, target, slug, and status fields.
	 *
	 * @param array<string, mixed>|null $link Stored link or preserved submission values.
	 */
	private static function fields( ?array $link ): void {
		$title  = $link ? (string) $link['title'] : '';
		$target = $link ? (string) $link['target_url'] : '';
		$slug   = $link ? (string) $link['slug'] : '';
		$status = $link ? (string) $link['status'] : SmartLinkRepository::STATUS_ACTIVE;
		?>
		<div class="nowcrm-contact-fields">
			<label class="nowcrm-field">
				<span><?php esc_html_e( '名稱', 'now-crm' ); ?></span>
				<input type="text" name="title" class="regular-text" required="required"
					value="<?php echo esc_attr( $title ); ?>" />
			</label>

			<label class="nowcrm-field">
				<span><?php esc_html_e( '目標網址', 'now-crm' ); ?></span>
				<input type="url" name="target_url" class="large-text code" required="required"
					placeholder="https://example.com/offer"
					value="<?php echo esc_attr( $target ); ?>" />
			</label>

			<label class="nowcrm-field">
				<span><?php echo esc_html( $link ? __( 'Slug', 'now-crm' ) : __( 'Slug（選填）', 'now-crm' ) ); ?></span>
				<input type="text" name="slug" class="regular-text code"
					value="<?php echo esc_attr( $slug ); ?>" />
			</label>

			<label class="nowcrm-field">
				<span><?php esc_html_e( '狀態', 'now-crm' ); ?></span>
				<select name="status">
					<option value="<?php echo esc_attr( SmartLinkRepository::STATUS_ACTIVE ); ?>" <?php selected( SmartLinkRepository::STATUS_ACTIVE, $status ); ?>>
						<?php esc_html_e( '啟用中', 'now-crm' ); ?>
					</option>
					<option value="<?php echo esc_attr( SmartLinkRepository::STATUS_PAUSED ); ?>" <?php selected( SmartLinkRepository::STATUS_PAUSED, $status ); ?>>
						<?php esc_html_e( '已暫停', 'now-crm' ); ?>
					</option>
				</select>
			</label>
		</div>
		<p class="description">
			<?php esc_html_e( '目標網址只接受完整的 http 或 https 網址，且只作為轉址目的地，後台不會開啟。', 'now-crm' ); ?>
		</p>
		<p class="description">
			<?php esc_html_e( '暫停後短網址回傳 404，且不計入點擊。', 'now-crm' ); ?>
		</p>
		<?php
	}

	/**
	 * Four membership groups: apply/remove lists and tags.
	 *
	 * @param array<int, array<string, mixed>> $lists Lists.
	 * @param array<int, array<string, mixed>> $tags  Tags.
	 * @param array<string, mixed>|null        $link  Stored link or preserved submission values.
	 */
	private static function terms_fields( array $lists, array $tags, ?array $link ): void {
		$groups = array(
			array(
				'field'    => 'apply_lists',
				'label'    => __( '加入名單', 'now-crm' ),
				'terms'    => $lists,
				'selected' => $link ? (array) $link['apply_lists'] : array(),
				'empty'    => __( '尚無名單。', 'now-crm' ),
			),
			array(
				'field'    => 'apply_tags',
				'label'    => __( '加入標籤', 'now-crm' ),
				'terms'    => $tags,
				'selected' => $link ? (array) $link['apply_tags'] : array(),
				'empty'    => __( '尚無標籤。', 'now-crm' ),
			),
			array(
				'field'    => 'remove_lists',
				'label'    => __( '移出名單', 'now-crm' ),
				'terms'    => $lists,
				'selected' => $link ? (array) $link['remove_lists'] : array(),
				'empty'    => __( '尚無名單。', 'now-crm' ),
			),
			array(
				'field'    => 'remove_tags',
				'label'    => __( '移除標籤', 'now-crm' ),
				'terms'    => $tags,
				'selected' => $link ? (array) $link['remove_tags'] : array(),
				'empty'    => __( '尚無標籤。', 'now-crm' ),
			),
		);
		?>
		<h2><?php esc_html_e( '對象動作', 'now-crm' ); ?></h2>
		<p class="description">
			<?php esc_html_e( '只有帶有效聯絡人簽章的點擊才會加入或移除下列名單與標籤。', 'now-crm' ); ?>
		</p>
		<div class="nowcrm-smart-links__terms">
			<?php foreach ( $groups as $group ) : ?>
				<fieldset data-nowcrm-smart-link-field="<?php echo esc_attr( $group['field'] ); ?>">
					<legend><?php echo esc_html( $group['label'] ); ?></legend>
					<?php if ( array() === $group['terms'] ) : ?>
						<p class="description"><?php echo esc_html( $group['empty'] ); ?></p>
					<?php else : ?>
						<?php foreach ( $group['terms'] as $term ) : ?>
							<?php $term_id = (int) $term['id']; ?>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( $group['field'] ); ?>[]"
									value="<?php echo esc_attr( (string) $term_id ); ?>"
									<?php checked( in_array( $term_id, $group['selected'], true ), true ); ?> />
								<?php echo esc_html( (string) $term['name'] ); ?>
							</label>
						<?php endforeach; ?>
					<?php endif; ?>
				</fieldset>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Page-local layout rules. Scoped to this screen's wrapper.
	 */
	private static function styles(): string {
		return '
#nowcrm-smart-links .nowcrm-smart-links__usage {
	margin: 0 0 8px;
}
#nowcrm-smart-links .nowcrm-contacts-table {
	min-width: 900px;
}
#nowcrm-smart-links .nowcrm-smart-links__short {
	width: 100%;
	max-width: 320px;
	min-height: 28px;
	font-size: 12px;
}
#nowcrm-smart-links .nowcrm-smart-audience__group {
	display: flex;
	flex-wrap: wrap;
	gap: 4px 8px;
	align-items: center;
	margin-top: 4px;
}
#nowcrm-smart-links .nowcrm-smart-audience__group:first-child {
	margin-top: 0;
}
#nowcrm-smart-links .nowcrm-smart-audience__label {
	color: var(--nowcrm-muted);
	font-size: 12px;
	white-space: nowrap;
}
#nowcrm-smart-links .nowcrm-smart-links__back {
	margin: 4px 0 8px;
}
#nowcrm-smart-links .nowcrm-smart-links__back a {
	color: var(--nowcrm-muted);
	font-size: 12px;
	text-decoration: none;
}
#nowcrm-smart-links .nowcrm-smart-links__back a:hover {
	color: var(--nowcrm-ink);
	text-decoration: underline;
}
#nowcrm-smart-links .nowcrm-smart-links__heading {
	max-width: 60ch;
	overflow-wrap: anywhere;
}
#nowcrm-smart-links .nowcrm-smart-links__body {
	display: grid;
	grid-template-columns: minmax(0, 1fr) minmax(260px, 320px);
	gap: 16px;
	align-items: start;
	margin-top: 12px;
}
#nowcrm-smart-links .nowcrm-smart-links__body .nowcrm-panel {
	margin: 0 0 16px;
}
#nowcrm-smart-links .nowcrm-smart-links__side .nowcrm-panel:last-child {
	margin-bottom: 0;
}
#nowcrm-smart-links .nowcrm-smart-links__metrics {
	display: grid;
	grid-template-columns: auto 1fr;
	gap: 6px 12px;
	margin: 0 0 12px;
}
#nowcrm-smart-links .nowcrm-smart-links__metrics dt {
	color: var(--nowcrm-muted);
	font-size: 12px;
}
#nowcrm-smart-links .nowcrm-smart-links__metrics dd {
	margin: 0;
	color: var(--nowcrm-ink);
	font-size: 13px;
	font-weight: 600;
}
#nowcrm-smart-links .nowcrm-smart-links__buttons {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	align-items: center;
	margin: 16px 0 0;
}
#nowcrm-smart-links .nowcrm-smart-links__terms {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
	gap: 12px;
}
#nowcrm-smart-links .nowcrm-smart-links__terms fieldset {
	min-width: 0;
	margin: 0;
	padding: 8px 10px;
	border: 1px solid var(--nowcrm-line);
	border-radius: 4px;
}
#nowcrm-smart-links .nowcrm-smart-links__terms legend {
	padding: 0 4px;
	color: var(--nowcrm-ink);
	font-size: 12px;
	font-weight: 600;
}
#nowcrm-smart-links .nowcrm-smart-links__terms label {
	display: flex;
	gap: 6px;
	align-items: flex-start;
	margin: 4px 0;
	font-size: 12px;
}
@media (max-width: 960px) {
	#nowcrm-smart-links .nowcrm-smart-links__body {
		grid-template-columns: minmax(0, 1fr);
	}
}
';
	}

	/**
	 * Link payload from the current POST request.
	 *
	 * @return array<string, mixed>
	 */
	private static function payload(): array {
		$data = array(
			'title'        => sanitize_text_field( self::post_raw( 'title' ) ),
			'target_url'   => trim( self::post_raw( 'target_url' ) ),
			'slug'         => sanitize_text_field( self::post_raw( 'slug' ) ),
			'apply_lists'  => self::post_ids( 'apply_lists' ),
			'apply_tags'   => self::post_ids( 'apply_tags' ),
			'remove_lists' => self::post_ids( 'remove_lists' ),
			'remove_tags'  => self::post_ids( 'remove_tags' ),
		);

		$status = sanitize_key( self::post_raw( 'status' ) );

		if ( '' !== $status ) {
			$data['status'] = $status;
		}

		return $data;
	}

	/**
	 * Safely read an ID checkbox group from POST.
	 *
	 * @return int[]
	 */
	private static function post_ids( string $key ): array {
		if ( ! isset( $_POST[ $key ] ) || ! is_array( $_POST[ $key ] ) ) {
			return array();
		}

		$ids = array();

		foreach ( wp_unslash( $_POST[ $key ] ) as $value ) {
			if ( is_scalar( $value ) ) {
				$ids[] = absint( (string) $value );
			}
		}

		return array_values( array_filter( array_unique( $ids ) ) );
	}

	/**
	 * Raw unslashed string from the current POST request (arrays ignored).
	 */
	private static function post_raw( string $key ): string {
		if ( ! isset( $_POST[ $key ] ) || ! is_string( $_POST[ $key ] ) ) {
			return '';
		}

		return wp_unslash( $_POST[ $key ] );
	}
}
