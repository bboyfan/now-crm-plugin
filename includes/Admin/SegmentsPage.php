<?php
/**
 * Saved segments screen.
 *
 * Lists system and stored segments with live member counts and usage, and
 * provides create, edit, duplicate, archive, restore, and delete actions.
 * Create and edit reuse the contact advanced-filter controls, so a segment
 * stores the same typed plan that the contact list and export accept.
 *
 * The screen registers its own admin-post handlers. Add the class to
 * Plugin::modules() (through Segments\Module) and its submenu entry to
 * Menu::add_pages() to expose it.
 *
 * Stable selectors:
 * - Form: #nowcrm-segment-form
 * - Fields: #nowcrm-segment-name, #nowcrm-segment-key,
 *   #nowcrm-segment-description
 * - Save: #nowcrm-segment-save
 * - Filter controls: #nowcrm-adv-filters (reused renderer)
 * - List: #nowcrm-segments, rows [data-segment-ref]
 * - List filters: #nowcrm-segments-filters (s, type, status)
 * - Row actions: #nowcrm-segment-preview-<ref>, -export-, -edit-,
 *   -duplicate-, -archive-, -restore-, -delete-
 * - Preview: #nowcrm-segment-preview, #nowcrm-segment-preview-table
 * - Audience link: #nowcrm-segment-audience
 *
 * Routes on this one slug; old URLs keep working:
 * - no extra args: list
 * - `new=1`: create form only
 * - `edit=<id>`: edit form only
 * - `segment=<ref>`: read-only preview only
 *
 * @package NowCRM
 */

namespace NowCRM\Admin;

use NowCRM\Contacts\AdvancedFilters;
use NowCRM\Segments\SegmentRepository;
use NowCRM\Segments\SegmentResolver;
use NowCRM\Support\Capabilities;
use WP_Error;

defined( 'ABSPATH' ) || exit;

final class SegmentsPage {

	public const SLUG = 'nowcrm-segments';

	private const NOTICE      = 'nowcrm_segment_notice';
	private const ERROR_STORE = 'nowcrm_segment_error_';
	private const PREVIEW     = 20;
	private const PER_PAGE    = 10;

	/**
	 * Register admin-post handlers.
	 */
	public static function register(): void {
		$handlers = array(
			'nowcrm_segment_save'      => 'save',
			'nowcrm_segment_duplicate' => 'duplicate',
			'nowcrm_segment_archive'   => 'archive',
			'nowcrm_segment_restore'   => 'restore',
			'nowcrm_segment_delete'    => 'delete',
		);

		foreach ( $handlers as $action => $method ) {
			add_action( 'admin_post_' . $action, array( __CLASS__, $method ) );
		}
	}

	/**
	 * Render one discrete screen: list, create, edit, or preview.
	 */
	public static function render(): void {
		if ( ! Capabilities::can_manage_contacts() ) {
			wp_die(
				esc_html__( '您沒有權限檢視分眾。', 'now-crm' ),
				'',
				array( 'response' => 403 )
			);
		}

		$repository  = new SegmentRepository();
		$resolver    = new SegmentResolver( $repository );
		$creating    = '1' === Menu::query( 'new' );
		$edit_id     = absint( Menu::query( 'edit' ) );
		$preview_ref = sanitize_text_field( Menu::query( 'segment' ) );

		if ( $creating ) {
			self::render_form( null, $repository, $resolver, true );

			return;
		}

		if ( $edit_id > 0 ) {
			$editing = $repository->get( $edit_id );

			if ( null === $editing ) {
				wp_die(
					esc_html__( '找不到這個分眾。', 'now-crm' ),
					'',
					array( 'response' => 404 )
				);
			}

			self::render_form( $editing, $repository, $resolver );

			return;
		}

		if ( '' !== $preview_ref ) {
			self::render_preview( $repository, $resolver, $preview_ref );

			return;
		}

		self::render_list( $repository, $resolver );
	}

	/**
	 * Create or edit form with the advanced filter controls.
	 *
	 * @param array<string, mixed>|null $editing    Stored segment, or null when creating.
	 * @param SegmentRepository|null    $repository Repository for the side summary.
	 * @param SegmentResolver|null      $resolver   Resolver for the live count.
	 * @param bool                      $is_new     Force the create copy.
	 */
	private static function render_form( ?array $editing, ?SegmentRepository $repository = null, ?SegmentResolver $resolver = null, bool $is_new = false ): void {
		$id      = (int) ( $editing['id'] ?? 0 );
		$is_new  = $is_new || $id <= 0;
		$state   = self::take_error();
		$prefill = array(
			'name'        => (string) ( $editing['name'] ?? '' ),
			'key'         => (string) ( $editing['key'] ?? '' ),
			'description' => (string) ( $editing['description'] ?? '' ),
		);
		$plan          = $editing['filters'] ?? null;
		$filters_error = null;
		$list_args     = self::list_args();

		if ( is_array( $state ) ) {
			$prefill       = array_merge( $prefill, (array) ( $state['input'] ?? array() ) );
			$filters_error = new WP_Error(
				'nowcrm_segment_invalid',
				(string) ( $state['message'] ?? __( '請檢查分眾設定。', 'now-crm' ) ),
				array( 'groups' => (array) ( $state['groups'] ?? array() ) )
			);
			$plan          = null;
		}

		$archived = ! $is_new && SegmentRepository::STATUS_ARCHIVED === (string) ( $editing['status'] ?? '' );
		$usage    = array();
		$count    = null;

		if ( ! $is_new && null !== $repository && null !== $resolver ) {
			$usage = (array) $repository->usage( $id );
			$count = $resolver->count( (string) ( $editing['ref'] ?? '' ), true );
		}

		?>
		<div class="wrap nowcrm-wrap">
			<?php ContactsPage::render_nav( 'segments' ); ?>

			<div class="nowcrm-contacts__head">
				<div>
					<h1 class="wp-heading-inline">
						<?php
						echo $is_new
							? esc_html__( '新增分眾', 'now-crm' )
							: esc_html__( '編輯分眾', 'now-crm' );
						?>
					</h1>
				</div>
				<div class="nowcrm-contacts__toolbar">
					<a class="button" href="<?php echo esc_url( self::page_url( $list_args ) ); ?>"><?php esc_html_e( '回分眾列表', 'now-crm' ); ?></a>
				</div>
			</div>
			<hr class="wp-header-end" />

			<?php self::render_notice(); ?>

			<?php if ( $archived ) : ?>
				<div class="notice notice-warning inline">
					<p><?php esc_html_e( '這個分眾已封存。封存期間不會被寄送、匯出或自動化解析；儲存設定不會自動還原。', 'now-crm' ); ?></p>
				</div>
			<?php endif; ?>

			<div class="<?php echo $is_new ? '' : 'nowcrm-grid'; ?>">
				<div class="<?php echo $is_new ? '' : 'nowcrm-grid__main'; ?>">
					<div class="nowcrm-panel">
						<form id="nowcrm-segment-form" method="post" action="<?php echo esc_url( add_query_arg( $list_args, admin_url( 'admin-post.php' ) ) ); ?>">
							<input type="hidden" name="action" value="nowcrm_segment_save" />
							<input type="hidden" name="id" value="<?php echo esc_attr( (string) $id ); ?>" />
							<?php wp_nonce_field( 'nowcrm_segment_save' ); ?>

							<table class="form-table" role="presentation"><tbody>
								<tr>
									<th scope="row">
										<label for="nowcrm-segment-name"><?php esc_html_e( '名稱', 'now-crm' ); ?></label>
									</th>
									<td>
										<input type="text" id="nowcrm-segment-name" name="name" class="regular-text"
											maxlength="<?php echo esc_attr( (string) SegmentRepository::MAX_NAME ); ?>" required="required"
											value="<?php echo esc_attr( (string) ( $prefill['name'] ?? '' ) ); ?>" />
									</td>
								</tr>
								<tr>
									<th scope="row">
										<label for="nowcrm-segment-key"><?php esc_html_e( '識別碼', 'now-crm' ); ?></label>
									</th>
									<td>
										<input type="text" id="nowcrm-segment-key" name="key" class="regular-text"
											maxlength="<?php echo esc_attr( (string) SegmentRepository::MAX_KEY ); ?>"
											value="<?php echo esc_attr( (string) ( $prefill['key'] ?? '' ) ); ?>"
											placeholder="<?php esc_attr_e( '留空時依名稱自動產生', 'now-crm' ); ?>" />
										<p class="description"><?php esc_html_e( '識別碼供程式與 API 引用。修改後，既有引用會找不到這個分眾。', 'now-crm' ); ?></p>
									</td>
								</tr>
								<tr>
									<th scope="row">
										<label for="nowcrm-segment-description"><?php esc_html_e( '說明', 'now-crm' ); ?></label>
									</th>
									<td>
										<textarea id="nowcrm-segment-description" name="description" rows="2" class="large-text"
											maxlength="<?php echo esc_attr( (string) SegmentRepository::MAX_DESCRIPTION ); ?>"><?php echo esc_textarea( (string) ( $prefill['description'] ?? '' ) ); ?></textarea>
									</td>
								</tr>
							</tbody></table>

							<?php ContactAdvancedFilters::render( $plan, $filters_error ); ?>

							<p class="submit">
								<button type="submit" class="button button-primary" id="nowcrm-segment-save">
									<?php echo $is_new ? esc_html__( '建立', 'now-crm' ) : esc_html__( '儲存', 'now-crm' ); ?>
								</button>
								<a class="button" href="<?php echo esc_url( self::page_url( $list_args ) ); ?>"><?php esc_html_e( '取消', 'now-crm' ); ?></a>
							</p>
						</form>
					</div>
				</div>

				<?php if ( ! $is_new && null !== $repository && null !== $resolver ) : ?>
					<div class="nowcrm-grid__side">
						<div class="nowcrm-panel">
							<h2><?php esc_html_e( '分眾資訊', 'now-crm' ); ?></h2>
							<ul class="nowcrm-meta">
								<li>
									<span><?php esc_html_e( '類型', 'now-crm' ); ?></span>
									<strong><?php esc_html_e( '自訂', 'now-crm' ); ?></strong>
								</li>
								<li>
									<span><?php esc_html_e( '狀態', 'now-crm' ); ?></span>
									<strong><?php echo $archived ? esc_html__( '已封存', 'now-crm' ) : esc_html__( '啟用中', 'now-crm' ); ?></strong>
								</li>
								<li>
									<span><?php esc_html_e( '識別碼', 'now-crm' ); ?></span>
									<strong><code><?php echo esc_html( (string) ( $editing['key'] ?? '' ) ); ?></code></strong>
								</li>
								<li>
									<span><?php esc_html_e( '符合聯絡人', 'now-crm' ); ?></span>
									<strong>
										<?php if ( is_wp_error( $count ) ) : ?>
											<span title="<?php echo esc_attr( $count->get_error_message() ); ?>">—</span>
										<?php else : ?>
											<a href="<?php echo esc_url( self::page_url( array_merge( array( 'segment' => (string) ( $editing['ref'] ?? '' ) ), $list_args ) ) ); ?>">
												<?php echo esc_html( (string) (int) $count ); ?>
											</a>
										<?php endif; ?>
									</strong>
								</li>
								<li>
									<span><?php esc_html_e( '使用中', 'now-crm' ); ?></span>
									<strong<?php echo array() === $usage ? '' : ' title="' . esc_attr( implode( "\n", $usage ) ) . '"'; ?>>
										<?php echo array() === $usage ? '—' : esc_html( (string) count( $usage ) ); ?>
									</strong>
								</li>
								<li>
									<span><?php esc_html_e( '更新時間', 'now-crm' ); ?></span>
									<strong><?php echo esc_html( Menu::format_datetime( (string) ( $editing['updated_at'] ?? '' ) ) ); ?></strong>
								</li>
							</ul>
							<p>
								<a class="button" href="<?php echo esc_url( self::page_url( array_merge( array( 'segment' => (string) ( $editing['ref'] ?? '' ) ), $list_args ) ) ); ?>">
									<?php esc_html_e( '檢視符合聯絡人', 'now-crm' ); ?>
								</a>
							</p>
							<?php if ( '' !== (string) ( $editing['description'] ?? '' ) ) : ?>
								<p class="description"><?php echo esc_html( (string) $editing['description'] ); ?></p>
							<?php endif; ?>
						</div>
					</div>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Segment list: real rows first, filters and paging after.
	 *
	 * @param SegmentRepository $repository Repository.
	 * @param SegmentResolver   $resolver   Resolver.
	 */
	private static function render_list( SegmentRepository $repository, SegmentResolver $resolver ): void {
		$list_args = self::list_args();
		$search    = (string) ( $list_args['s'] ?? '' );
		$type      = (string) ( $list_args['type'] ?? 'all' );
		$status    = (string) ( $list_args['status'] ?? 'all' );
		$paged     = (int) ( $list_args['paged'] ?? 1 );

		$segments = $repository->all(
			array(
				'status'         => 'all' === $status ? 'all' : $status,
				'search'         => $search,
				'include_system' => 'custom' !== $type && 'archived' !== $status,
			)
		);

		if ( 'all' !== $type ) {
			$segments = array_values(
				array_filter(
					$segments,
					static fn( array $segment ): bool => ( 'system' === $type ) === (bool) $segment['is_system']
				)
			);
		}

		$total       = count( $segments );
		$total_pages = max( 1, (int) ceil( $total / self::PER_PAGE ) );
		$paged       = min( $paged, $total_pages );

		if ( $paged > 1 ) {
			$list_args['paged'] = $paged;
		} else {
			unset( $list_args['paged'] );
		}

		$items       = array_slice( $segments, ( $paged - 1 ) * self::PER_PAGE, self::PER_PAGE );
		$usage_map   = $repository->usage_map();
		$first_row   = $total > 0 ? ( ( $paged - 1 ) * self::PER_PAGE ) + 1 : 0;
		$last_row    = min( $total, $paged * self::PER_PAGE );
		$filtered    = '' !== $search || 'all' !== $type || 'all' !== $status;
		$state_args  = $list_args;

		unset( $state_args['paged'] );

		$clear_url = self::page_url();

		?>
		<div class="wrap nowcrm-wrap">
			<?php ContactsPage::render_nav( 'segments' ); ?>

			<div class="nowcrm-contacts__head">
				<div>
					<h1 class="wp-heading-inline"><?php esc_html_e( '分眾', 'now-crm' ); ?></h1>
					<span class="nowcrm-contacts__count">（<?php echo esc_html( (string) $total ); ?>）</span>
				</div>
				<div class="nowcrm-contacts__toolbar">
					<a class="button button-primary" href="<?php echo esc_url( self::page_url( array_merge( array( 'new' => '1' ), $list_args ) ) ); ?>">
						<?php esc_html_e( '新增分眾', 'now-crm' ); ?>
					</a>
				</div>
			</div>
			<hr class="wp-header-end" />

			<?php self::render_notice(); ?>

			<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>"
				class="nowcrm-filters" id="nowcrm-segments-filters">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>" />

				<label>
					<span><?php esc_html_e( '搜尋分眾', 'now-crm' ); ?></span>
					<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>"
						placeholder="<?php esc_attr_e( '搜尋名稱、識別碼或說明', 'now-crm' ); ?>" />
				</label>

				<label>
					<span><?php esc_html_e( '類型', 'now-crm' ); ?></span>
					<select name="type">
						<option value="all" <?php selected( $type, 'all' ); ?>><?php esc_html_e( '全部', 'now-crm' ); ?></option>
						<option value="custom" <?php selected( $type, 'custom' ); ?>><?php esc_html_e( '自訂分眾', 'now-crm' ); ?></option>
						<option value="system" <?php selected( $type, 'system' ); ?>><?php esc_html_e( '系統分眾', 'now-crm' ); ?></option>
					</select>
				</label>

				<label>
					<span><?php esc_html_e( '狀態', 'now-crm' ); ?></span>
					<select name="status">
						<option value="all" <?php selected( $status, 'all' ); ?>><?php esc_html_e( '全部', 'now-crm' ); ?></option>
						<option value="active" <?php selected( $status, 'active' ); ?>><?php esc_html_e( '啟用中', 'now-crm' ); ?></option>
						<option value="archived" <?php selected( $status, 'archived' ); ?>><?php esc_html_e( '已封存', 'now-crm' ); ?></option>
					</select>
				</label>

				<button type="submit" class="button"><?php esc_html_e( '套用', 'now-crm' ); ?></button>

				<?php if ( $filtered ) : ?>
					<a class="button button-link" href="<?php echo esc_url( $clear_url ); ?>"><?php esc_html_e( '清除條件', 'now-crm' ); ?></a>
				<?php endif; ?>
			</form>

			<p class="nowcrm-total">
				<?php
				if ( $total > 0 ) {
					printf(
						/* translators: 1: first row number, 2: last row number, 3: total segments. */
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

			<table id="nowcrm-segments" class="wp-list-table widefat fixed striped nowcrm-table">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( '名稱', 'now-crm' ); ?></th>
						<th scope="col" class="nowcrm-col-type"><?php esc_html_e( '類型', 'now-crm' ); ?></th>
						<th scope="col"><?php esc_html_e( '狀態', 'now-crm' ); ?></th>
						<th scope="col" class="nowcrm-col-number"><?php esc_html_e( '符合聯絡人', 'now-crm' ); ?></th>
						<th scope="col" class="nowcrm-col-number"><?php esc_html_e( '使用中', 'now-crm' ); ?></th>
						<th scope="col"><?php esc_html_e( '更新時間', 'now-crm' ); ?></th>
						<th scope="col" class="nowcrm-col-actions"><?php esc_html_e( '操作', 'now-crm' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( array() === $items ) : ?>
						<tr>
							<td colspan="7">
								<div class="nowcrm-empty">
									<?php if ( $filtered ) : ?>
										<p><?php esc_html_e( '沒有符合目前條件的分眾。', 'now-crm' ); ?></p>
										<p class="nowcrm-empty__actions">
											<a class="button" href="<?php echo esc_url( $clear_url ); ?>"><?php esc_html_e( '清除條件', 'now-crm' ); ?></a>
										</p>
									<?php else : ?>
										<p><?php esc_html_e( '尚未建立任何分眾。', 'now-crm' ); ?></p>
										<p class="nowcrm-empty__actions">
											<a class="button button-primary" href="<?php echo esc_url( self::page_url( array_merge( array( 'new' => '1' ), $list_args ) ) ); ?>">
												<?php esc_html_e( '新增分眾', 'now-crm' ); ?>
											</a>
										</p>
									<?php endif; ?>
								</div>
							</td>
						</tr>
					<?php else : ?>
						<?php foreach ( $items as $segment ) : ?>
							<?php
							$ref      = (string) $segment['ref'];
							$dom      = self::dom_id( $ref );
							$is_sys   = ! empty( $segment['is_system'] );
							$archived = SegmentRepository::STATUS_ARCHIVED === $segment['status'];
							$count    = $resolver->count( $ref, true );
							$usage    = $is_sys ? array() : (array) ( $usage_map[ (int) $segment['id'] ] ?? array() );
							$preview  = self::page_url( array_merge( array( 'segment' => $ref ), $list_args ) );
							$audience = self::audience_url( $segment );
							?>
							<tr data-segment-ref="<?php echo esc_attr( $ref ); ?>">
								<td>
									<strong><a href="<?php echo esc_url( $preview ); ?>"><?php echo esc_html( (string) $segment['name'] ); ?></a></strong>
									<code><?php echo esc_html( (string) $segment['key'] ); ?></code>
									<?php if ( '' !== (string) $segment['description'] ) : ?>
										<p class="description"><?php echo esc_html( wp_html_excerpt( (string) $segment['description'], 100, '…' ) ); ?></p>
									<?php endif; ?>
								</td>
								<td><?php echo $is_sys ? esc_html__( '系統', 'now-crm' ) : esc_html__( '自訂', 'now-crm' ); ?></td>
								<td>
									<span class="nowcrm-badge <?php echo $archived ? 'nowcrm-badge--unsubscribed' : 'nowcrm-badge--subscribed'; ?>">
										<?php echo $archived ? esc_html__( '已封存', 'now-crm' ) : esc_html__( '啟用中', 'now-crm' ); ?>
									</span>
								</td>
								<td>
									<?php if ( is_wp_error( $count ) ) : ?>
										<span title="<?php echo esc_attr( $count->get_error_message() ); ?>">—</span>
									<?php else : ?>
										<a href="<?php echo esc_url( $preview ); ?>"><?php echo esc_html( (string) (int) $count ); ?></a>
									<?php endif; ?>
								</td>
								<td>
									<?php if ( array() === $usage ) : ?>
										—
									<?php else : ?>
										<span title="<?php echo esc_attr( implode( "\n", $usage ) ); ?>"><?php echo esc_html( (string) count( $usage ) ); ?></span>
									<?php endif; ?>
								</td>
								<td><?php echo esc_html( Menu::format_datetime( $segment['updated_at'] ?? null ) ); ?></td>
								<td>
									<a class="button button-small" id="nowcrm-segment-preview-<?php echo esc_attr( $dom ); ?>"
										href="<?php echo esc_url( $preview ); ?>"><?php esc_html_e( '預覽', 'now-crm' ); ?></a>

									<details class="nowcrm-more">
										<summary class="button button-small" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: segment name. */ __( '更多操作：%s', 'now-crm' ), (string) $segment['name'] ) ); ?>">
											<?php esc_html_e( '更多', 'now-crm' ); ?>
										</summary>
										<div class="nowcrm-more__menu">
											<?php if ( '' !== $audience ) : ?>
												<a href="<?php echo esc_url( $audience ); ?>"><?php esc_html_e( '在聯絡人檢視', 'now-crm' ); ?></a>
											<?php endif; ?>

											<a id="nowcrm-segment-export-<?php echo esc_attr( $dom ); ?>"
												href="<?php echo esc_url( Menu::page_url( ContactCsvAdmin::SLUG, array( 'tab' => 'export', 'segment' => $ref ) ) ); ?>"><?php esc_html_e( '匯出', 'now-crm' ); ?></a>

											<?php if ( ! $is_sys ) : ?>
												<a id="nowcrm-segment-edit-<?php echo esc_attr( $dom ); ?>"
													href="<?php echo esc_url( self::page_url( array_merge( array( 'edit' => (int) $segment['id'] ), $list_args ) ) ); ?>"><?php esc_html_e( '編輯', 'now-crm' ); ?></a>

												<?php self::render_action_button( 'duplicate', (int) $segment['id'], $dom, __( '複製', 'now-crm' ), 'nowcrm-more__button', null, $list_args ); ?>

												<?php if ( $archived ) : ?>
													<?php self::render_action_button( 'restore', (int) $segment['id'], $dom, __( '還原', 'now-crm' ), 'nowcrm-more__button', null, $list_args ); ?>
												<?php else : ?>
													<?php self::render_action_button( 'archive', (int) $segment['id'], $dom, __( '封存', 'now-crm' ), 'nowcrm-more__button', null, $list_args ); ?>
												<?php endif; ?>

												<?php if ( array() === $usage ) : ?>
													<?php self::render_action_button( 'delete', (int) $segment['id'], $dom, __( '刪除', 'now-crm' ), 'nowcrm-more__button', __( '確定要永久刪除這個分眾嗎？', 'now-crm' ), $list_args ); ?>
												<?php endif; ?>
											<?php endif; ?>
										</div>
									</details>
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

			<p class="description"><?php esc_html_e( '分眾在每次寄送、匯出或自動化執行時即時解析。封存的分眾不會被解析；刪除前必須先移除所有引用。', 'now-crm' ); ?></p>
		</div>
		<?php
	}

	/**
	 * One admin-post action button.
	 *
	 * @param string      $action   Handler action without prefix.
	 * @param int         $id       Segment ID.
	 * @param string      $dom      DOM id suffix.
	 * @param string      $label    Button label.
	 * @param string      $class    Button classes.
	 * @param string|null          $confirm  Confirmation text.
	 * @param array<string, mixed> $args     Validated list context to return to.
	 */
	private static function render_action_button( string $action, int $id, string $dom, string $label, string $class, ?string $confirm = null, array $args = array() ): void {
		$hook = 'nowcrm_segment_' . $action;

		?>
		<form method="post" action="<?php echo esc_url( add_query_arg( $args, admin_url( 'admin-post.php' ) ) ); ?>" style="display:inline;">
			<input type="hidden" name="action" value="<?php echo esc_attr( $hook ); ?>" />
			<input type="hidden" name="id" value="<?php echo esc_attr( (string) $id ); ?>" />
			<?php wp_nonce_field( $hook ); ?>
			<button type="submit" class="button <?php echo esc_attr( $class ); ?>" id="nowcrm-segment-<?php echo esc_attr( $action ); ?>-<?php echo esc_attr( $dom ); ?>"
				<?php if ( null !== $confirm ) : ?>
					onclick="return confirm('<?php echo esc_js( $confirm ); ?>');"
				<?php endif; ?>
			><?php echo esc_html( $label ); ?></button>
		</form>
		<?php
	}

	/**
	 * Read-only preview screen for one segment.
	 *
	 * @param SegmentRepository $repository Repository.
	 * @param SegmentResolver   $resolver   Resolver.
	 * @param string            $ref        Segment reference.
	 */
	private static function render_preview( SegmentRepository $repository, SegmentResolver $resolver, string $ref ): void {
		$segment = $repository->find( $ref );

		if ( null === $segment ) {
			wp_die(
				esc_html__( '找不到這個分眾。', 'now-crm' ),
				'',
				array( 'response' => 404 )
			);
		}

		$result   = $resolver->preview( $ref, self::PREVIEW, true );
		$is_sys   = ! empty( $segment['is_system'] );
		$archived = SegmentRepository::STATUS_ARCHIVED === (string) $segment['status'];
		$id       = (int) $segment['id'];
		$dom      = self::dom_id( $ref );
		$usage    = $is_sys ? array() : (array) $repository->usage( $id );
		$audience = self::audience_url( $segment );
		$total     = is_wp_error( $result ) ? null : (int) $result['total'];
		$list_args = self::list_args();
		$back      = self::page_url( $list_args );
		$edit      = $is_sys ? '' : self::page_url( array_merge( array( 'edit' => $id ), $list_args ) );
		$export    = Menu::page_url( ContactCsvAdmin::SLUG, array( 'tab' => 'export', 'segment' => $ref ) );

		?>
		<div class="wrap nowcrm-wrap">
			<?php ContactsPage::render_nav( 'segments' ); ?>

			<div class="nowcrm-contacts__head">
				<div>
					<h1 class="wp-heading-inline"><?php echo esc_html( (string) $segment['name'] ); ?></h1>
					<?php if ( null !== $total ) : ?>
						<span class="nowcrm-contacts__count">（<?php echo esc_html( (string) $total ); ?>）</span>
					<?php endif; ?>
				</div>
				<div class="nowcrm-contacts__toolbar">
					<a class="button" href="<?php echo esc_url( $back ); ?>"><?php esc_html_e( '回分眾列表', 'now-crm' ); ?></a>
					<?php if ( '' !== $audience ) : ?>
						<a class="button" id="nowcrm-segment-audience" href="<?php echo esc_url( $audience ); ?>">
							<?php esc_html_e( '在聯絡人檢視', 'now-crm' ); ?>
						</a>
					<?php endif; ?>
					<?php if ( '' !== $edit ) : ?>
						<a class="button button-primary" href="<?php echo esc_url( $edit ); ?>"><?php esc_html_e( '編輯分眾', 'now-crm' ); ?></a>
					<?php endif; ?>

					<details class="nowcrm-more">
						<summary class="button" aria-label="<?php esc_attr_e( '更多操作', 'now-crm' ); ?>">
							<?php esc_html_e( '更多操作', 'now-crm' ); ?>
						</summary>
						<div class="nowcrm-more__menu">
							<a id="nowcrm-segment-export-<?php echo esc_attr( $dom ); ?>" href="<?php echo esc_url( $export ); ?>">
								<?php esc_html_e( '匯出', 'now-crm' ); ?>
							</a>
							<?php if ( ! $is_sys ) : ?>
								<?php self::render_action_button( 'duplicate', $id, $dom, __( '複製', 'now-crm' ), 'nowcrm-more__button', null, $list_args ); ?>
								<?php if ( $archived ) : ?>
									<?php self::render_action_button( 'restore', $id, $dom, __( '還原', 'now-crm' ), 'nowcrm-more__button', null, $list_args ); ?>
								<?php else : ?>
									<?php self::render_action_button( 'archive', $id, $dom, __( '封存', 'now-crm' ), 'nowcrm-more__button', null, $list_args ); ?>
								<?php endif; ?>
								<?php if ( array() === $usage ) : ?>
									<?php self::render_action_button( 'delete', $id, $dom, __( '刪除', 'now-crm' ), 'nowcrm-more__button', __( '確定要永久刪除這個分眾嗎？', 'now-crm' ), $list_args ); ?>
								<?php endif; ?>
							<?php endif; ?>
						</div>
					</details>
				</div>
			</div>
			<hr class="wp-header-end" />

			<?php self::render_notice(); ?>

			<?php if ( $archived ) : ?>
				<div class="notice notice-warning inline">
					<p><?php esc_html_e( '這個分眾已封存，不會被寄送、匯出或自動化解析。以下名單供檢視，不會成為寄送範圍。', 'now-crm' ); ?></p>
				</div>
			<?php endif; ?>

			<div class="nowcrm-grid">
				<div class="nowcrm-grid__main">
					<div class="nowcrm-panel" id="nowcrm-segment-preview">
						<h2><?php esc_html_e( '符合的聯絡人', 'now-crm' ); ?></h2>

						<?php if ( is_wp_error( $result ) ) : ?>
							<div class="notice notice-error inline"><p><?php echo esc_html( $result->get_error_message() ); ?></p></div>
						<?php else : ?>
							<p class="description">
								<?php
								printf(
									/* translators: 1: total contacts, 2: shown contacts. */
									esc_html__( '符合 %1$d 位聯絡人，以下顯示前 %2$d 位。', 'now-crm' ),
									(int) $result['total'],
									count( $result['items'] )
								);
								?>
								<?php if ( '' !== $audience ) : ?>
									<a href="<?php echo esc_url( $audience ); ?>"><?php esc_html_e( '在聯絡人檢視完整名單', 'now-crm' ); ?></a>
								<?php elseif ( (int) $result['total'] > count( $result['items'] ) ) : ?>
									<?php esc_html_e( '此頁只顯示前 20 位，無法在此分頁。', 'now-crm' ); ?>
								<?php endif; ?>
							</p>

							<table id="nowcrm-segment-preview-table" class="wp-list-table widefat fixed striped nowcrm-table">
								<thead>
									<tr>
										<th scope="col" class="nowcrm-col-id"><?php esc_html_e( 'ID', 'now-crm' ); ?></th>
										<th scope="col"><?php esc_html_e( 'Email', 'now-crm' ); ?></th>
										<th scope="col"><?php esc_html_e( '名稱', 'now-crm' ); ?></th>
										<th scope="col"><?php esc_html_e( '狀態', 'now-crm' ); ?></th>
									</tr>
								</thead>
								<tbody>
									<?php if ( array() === $result['items'] ) : ?>
										<tr>
											<td colspan="4">
												<div class="nowcrm-empty">
													<p><?php esc_html_e( '目前沒有符合的聯絡人。', 'now-crm' ); ?></p>
													<?php if ( '' !== $edit ) : ?>
														<p class="nowcrm-empty__actions">
															<a class="button" href="<?php echo esc_url( $edit ); ?>"><?php esc_html_e( '調整篩選條件', 'now-crm' ); ?></a>
														</p>
													<?php endif; ?>
												</div>
											</td>
										</tr>
									<?php else : ?>
										<?php foreach ( $result['items'] as $contact ) : ?>
											<tr data-contact-id="<?php echo esc_attr( (string) (int) $contact['id'] ); ?>">
												<td><?php echo esc_html( (string) (int) $contact['id'] ); ?></td>
												<td>
													<a href="<?php echo esc_url( Menu::page_url( Menu::CONTACT_SLUG, array( 'id' => (int) $contact['id'] ) ) ); ?>">
														<?php echo esc_html( (string) $contact['email'] ); ?>
													</a>
												</td>
												<td><?php echo esc_html( Menu::contact_name( $contact ) ); ?></td>
												<td><?php echo esc_html( \NowCRM\Contacts\Status::label( (string) $contact['status'] ) ); ?></td>
											</tr>
										<?php endforeach; ?>
									<?php endif; ?>
								</tbody>
							</table>
						<?php endif; ?>
					</div>
				</div>

				<div class="nowcrm-grid__side">
					<div class="nowcrm-panel">
						<h2><?php esc_html_e( '分眾資訊', 'now-crm' ); ?></h2>
						<ul class="nowcrm-meta">
							<li>
								<span><?php esc_html_e( '類型', 'now-crm' ); ?></span>
								<strong><?php echo $is_sys ? esc_html__( '系統', 'now-crm' ) : esc_html__( '自訂', 'now-crm' ); ?></strong>
							</li>
							<li>
								<span><?php esc_html_e( '狀態', 'now-crm' ); ?></span>
								<strong><?php echo $archived ? esc_html__( '已封存', 'now-crm' ) : esc_html__( '啟用中', 'now-crm' ); ?></strong>
							</li>
							<li>
								<span><?php esc_html_e( '識別碼', 'now-crm' ); ?></span>
								<strong><code><?php echo esc_html( (string) $segment['key'] ); ?></code></strong>
							</li>
							<li>
								<span><?php esc_html_e( '符合聯絡人', 'now-crm' ); ?></span>
								<strong><?php echo null === $total ? '—' : esc_html( (string) $total ); ?></strong>
							</li>
							<li>
								<span><?php esc_html_e( '使用中', 'now-crm' ); ?></span>
								<strong<?php echo array() === $usage ? '' : ' title="' . esc_attr( implode( "\n", $usage ) ) . '"'; ?>>
									<?php echo array() === $usage ? '—' : esc_html( (string) count( $usage ) ); ?>
								</strong>
							</li>
							<li>
								<span><?php esc_html_e( '更新時間', 'now-crm' ); ?></span>
								<strong><?php echo esc_html( Menu::format_datetime( $segment['updated_at'] ?? null ) ); ?></strong>
							</li>
						</ul>

						<?php if ( '' !== (string) $segment['description'] ) : ?>
							<p class="description"><?php echo esc_html( (string) $segment['description'] ); ?></p>
						<?php endif; ?>

						<?php if ( $is_sys ) : ?>
							<p class="description"><?php esc_html_e( '系統分眾由 NOW CRM 依既有資料即時計算，無法編輯或刪除。', 'now-crm' ); ?></p>
						<?php else : ?>
							<p class="description"><?php esc_html_e( '分眾在每次寄送、匯出或自動化執行時即時解析。封存的分眾不會被解析。', 'now-crm' ); ?></p>
							<?php if ( array() !== $usage ) : ?>
								<p class="description"><?php esc_html_e( '仍被引用，需先移除引用才能刪除；可改為封存。', 'now-crm' ); ?></p>
							<?php endif; ?>
						<?php endif; ?>
					</div>

					<?php if ( ! $is_sys && is_array( $segment['filters'] ) ) : ?>
						<div class="nowcrm-panel">
							<h2><?php esc_html_e( '篩選條件', 'now-crm' ); ?></h2>
							<?php self::render_plan_summary( $segment['filters'] ); ?>
						</div>
					<?php endif; ?>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Contacts URL only when the query yields the same audience.
	 *
	 * @param array<string, mixed> $segment Segment shape.
	 */
	private static function audience_url( array $segment ): string {
		if ( ! empty( $segment['is_system'] ) ) {
			return match ( (string) ( $segment['predicate'] ?? '' ) ) {
				'all'        => Menu::page_url( Menu::SLUG ),
				'subscribed' => Menu::page_url( Menu::SLUG, array( 'status' => \NowCRM\Contacts\Status::SUBSCRIBED ) ),
				default      => '',
			};
		}

		$plan = $segment['filters'] ?? null;

		if ( ! is_array( $plan ) || array() === (array) ( $plan['groups'] ?? array() ) ) {
			return '';
		}

		return Menu::page_url(
			Menu::SLUG,
			array(
				'mode'                     => 'advanced',
				ContactAdvancedFilters::INPUT => AdvancedFilters::to_query( $plan ),
			)
		);
	}

	/**
	 * Read-only list of stored conditions.
	 *
	 * @param array<string, mixed> $plan Stored plan.
	 */
	private static function render_plan_summary( array $plan ): void {
		$fields   = AdvancedFilters::fields();
		$labels   = AdvancedFilters::operator_labels();
		$terms    = new \NowCRM\Terms\TermRepository();
		$term_map = array();

		foreach ( array( \NowCRM\Terms\TermRepository::TYPE_LIST, \NowCRM\Terms\TermRepository::TYPE_TAG ) as $term_type ) {
			foreach ( $terms->all( $term_type ) as $term ) {
				$term_map[ (int) $term['id'] ] = (string) $term['name'];
			}
		}

		$groups = (array) ( $plan['groups'] ?? array() );

		if ( array() === $groups ) {
			return;
		}

		?>
		<ol class="nowcrm-meta">
			<?php foreach ( $groups as $group ) : ?>
				<?php
				$parts = array();

				foreach ( (array) $group as $condition ) {
					if ( ! is_array( $condition ) ) {
						continue;
					}

					$provider = (string) ( $condition['provider'] ?? '' );
					$field    = (string) ( $condition['field'] ?? '' );
					$operator = (string) ( $condition['operator'] ?? '' );
					$def      = (array) ( $fields[ $provider . '.' . $field ] ?? array() );
					$value    = $condition['value'] ?? '';
					$label    = (string) ( $def['label'] ?? $field );

					if ( AdvancedFilters::is_valueless( $operator ) ) {
						$parts[] = $label . ' ' . ( $labels[ $operator ] ?? $operator );
						continue;
					}

					$values = array_map(
						static function ( $item ) use ( $def, $term_map ) {
							if ( isset( $def['term_type'] ) ) {
								return $term_map[ absint( $item ) ] ?? '#' . absint( $item );
							}

							if ( isset( $def['options'] ) && is_array( $def['options'] ) ) {
								return (string) ( $def['options'][ $item ] ?? $item );
							}

							return (string) $item;
						},
						is_array( $value ) ? $value : array( $value )
					);

					$parts[] = $label . ' ' . ( $labels[ $operator ] ?? $operator ) . ' ' . implode( '、', $values );
				}

				if ( array() === $parts ) {
					continue;
				}
				?>
				<li>
					<span><?php esc_html_e( '群組', 'now-crm' ); ?></span>
					<strong><?php echo esc_html( implode( ' 且 ', $parts ) ); ?></strong>
				</li>
			<?php endforeach; ?>
		</ol>
		<p class="description"><?php esc_html_e( '群組內的條件必須全部符合；群組之間只要符合一個即可。', 'now-crm' ); ?></p>
		<?php
	}

	/**
	 * Save a new or existing segment.
	 */
	public static function save(): void {
		self::guard( 'nowcrm_segment_save' );

		$id    = absint( self::post_text( 'id' ) );
		$input = array(
			'name'        => self::post_text( 'name' ),
			'key'         => self::post_text( 'key' ),
			'description' => self::post_textarea( 'description' ),
		);
		$plan  = ContactAdvancedFilters::parse_request( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- guarded above.
		$back  = $id > 0 ? array( 'edit' => $id ) : array( 'new' => '1' );

		$groups = is_wp_error( $plan )
			? (array) ( $plan->get_error_data()['groups'] ?? array() )
			: AdvancedFilters::to_query( $plan );

		$repository = new SegmentRepository();
		$result     = $id > 0
			? $repository->update( $id, $input + array( 'filters' => $plan ) )
			: $repository->create( $input + array( 'filters' => $plan ) );

		if ( is_wp_error( $result ) ) {
			self::remember_error( $result, $input, $groups );
			self::redirect( self::notice_from_error( $result ), $back );
		}

		self::redirect( 'saved', array( 'segment' => 'custom:' . (int) $result ) );
	}

	/**
	 * Duplicate a segment.
	 */
	public static function duplicate(): void {
		self::guard( 'nowcrm_segment_duplicate' );

		$result = ( new SegmentRepository() )->duplicate( absint( self::post_text( 'id' ) ) );

		self::redirect( is_wp_error( $result ) ? self::notice_from_error( $result ) : 'duplicated' );
	}

	/**
	 * Archive a segment.
	 */
	public static function archive(): void {
		self::guard( 'nowcrm_segment_archive' );

		$result = ( new SegmentRepository() )->archive( absint( self::post_text( 'id' ) ) );

		self::redirect( is_wp_error( $result ) ? self::notice_from_error( $result ) : 'archived' );
	}

	/**
	 * Restore an archived segment.
	 */
	public static function restore(): void {
		self::guard( 'nowcrm_segment_restore' );

		$result = ( new SegmentRepository() )->restore( absint( self::post_text( 'id' ) ) );

		self::redirect( is_wp_error( $result ) ? self::notice_from_error( $result ) : 'restored' );
	}

	/**
	 * Delete a segment when no consumer references it.
	 */
	public static function delete(): void {
		self::guard( 'nowcrm_segment_delete' );

		$result = ( new SegmentRepository() )->delete( absint( self::post_text( 'id' ) ) );

		self::redirect( is_wp_error( $result ) ? self::notice_from_error( $result ) : 'deleted' );
	}

	/**
	 * Store form state for one render after a validation failure.
	 *
	 * @param WP_Error             $error  Failure.
	 * @param array<string, mixed> $input  Submitted name, key, description.
	 * @param array<int, mixed>    $groups Submitted condition rows.
	 */
	private static function remember_error( WP_Error $error, array $input, array $groups ): void {
		set_transient(
			self::error_key(),
			array(
				'message' => $error->get_error_message(),
				'input'   => $input,
				'groups'  => $groups,
			),
			60
		);
	}

	/**
	 * Read and clear the stored form state.
	 *
	 * @return array<string, mixed>|null
	 */
	private static function take_error(): ?array {
		$state = get_transient( self::error_key() );

		if ( ! is_array( $state ) ) {
			return null;
		}

		delete_transient( self::error_key() );

		return $state;
	}

	/**
	 * Per-user transient key.
	 */
	private static function error_key(): string {
		return self::ERROR_STORE . get_current_user_id();
	}

	/**
	 * Render the allow-listed notice.
	 */
	private static function render_notice(): void {
		if ( ! isset( $_GET[ self::NOTICE ] ) || ! is_string( $_GET[ self::NOTICE ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$code     = sanitize_key( wp_unslash( $_GET[ self::NOTICE ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$messages = self::messages();

		if ( ! isset( $messages[ $code ] ) ) {
			return;
		}

		$errors = array( 'in_use', 'not_found', 'invalid', 'error', 'name', 'description', 'name_exists', 'key_exists', 'key_reserved', 'limit', 'empty' );

		printf(
			'<div class="notice %s is-dismissible"><p>%s</p></div>',
			in_array( $code, $errors, true ) ? 'notice-error' : 'notice-success',
			esc_html( $messages[ $code ] )
		);
	}

	/**
	 * Allow-listed notice messages.
	 *
	 * @return array<string, string>
	 */
	private static function messages(): array {
		return array(
			'saved'        => __( '分眾已儲存。', 'now-crm' ),
			'duplicated'   => __( '分眾已複製。', 'now-crm' ),
			'archived'     => __( '分眾已封存，寄送與匯出將不再解析它。', 'now-crm' ),
			'restored'     => __( '分眾已還原。', 'now-crm' ),
			'deleted'      => __( '分眾已刪除。', 'now-crm' ),
			'in_use'       => __( '這個分眾仍被使用，請先移除引用或改為封存。', 'now-crm' ),
			'not_found'    => __( '找不到這個分眾。', 'now-crm' ),
			'invalid'      => __( '分眾設定不正確，請檢查欄位與篩選條件。', 'now-crm' ),
			'name'         => __( '請輸入有效的分眾名稱。', 'now-crm' ),
			'description'  => __( '分眾說明過長。', 'now-crm' ),
			'name_exists'  => __( '已有同名分眾，請換一個名稱。', 'now-crm' ),
			'key_exists'   => __( '這個識別碼已被其他分眾使用。', 'now-crm' ),
			'key_reserved' => __( '這個識別碼保留給系統分眾。', 'now-crm' ),
			'limit'        => __( '已達分眾數量上限。', 'now-crm' ),
			'empty'        => __( '請至少加入一個篩選條件。', 'now-crm' ),
			'error'        => __( '操作失敗，請再試一次。', 'now-crm' ),
		);
	}

	/**
	 * Map a WP_Error to an allowed notice code.
	 */
	private static function notice_from_error( WP_Error $error ): string {
		$code = preg_replace( '/^nowcrm_segment_/', '', (string) $error->get_error_code() );
		$code = is_string( $code ) ? $code : '';

		return isset( self::messages()[ $code ] ) ? $code : 'error';
	}

	/**
	 * Capability and nonce guard.
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
	 * Redirect back to the screen.
	 *
	 * @param string               $notice Notice code.
	 * @param array<string, mixed> $args   Extra query arguments.
	 */
	private static function redirect( string $notice, array $args = array() ): void {
		wp_safe_redirect(
			self::page_url(
				array_merge(
					self::list_args(),
					$args,
					array( self::NOTICE => $notice )
				)
			)
		);
		exit;
	}

	/**
	 * Validated segment-list filters from the current query string.
	 *
	 * Defaults are omitted so plain links stay plain.
	 *
	 * @return array<string, mixed>
	 */
	private static function list_args(): array {
		$args   = array();
		$search = sanitize_text_field( Menu::query( 's' ) );
		$type   = sanitize_key( Menu::query( 'type' ) );
		$status = sanitize_key( Menu::query( 'status' ) );

		if ( '' !== $search ) {
			$args['s'] = $search;
		}

		if ( in_array( $type, array( 'custom', 'system' ), true ) ) {
			$args['type'] = $type;
		}

		if ( in_array( $status, array( 'active', 'archived' ), true ) ) {
			$args['status'] = $status;
		}

		$paged = max( 1, absint( Menu::query( 'paged', '1' ) ) );

		if ( $paged > 1 ) {
			$args['paged'] = $paged;
		}

		return $args;
	}

	/**
	 * Screen URL.
	 *
	 * @param array<string, mixed> $args Extra query arguments.
	 */
	private static function page_url( array $args = array() ): string {
		return Menu::page_url( self::SLUG, $args );
	}

	/**
	 * DOM id suffix for a segment reference.
	 */
	private static function dom_id( string $ref ): string {
		return sanitize_html_class( str_replace( ':', '-', $ref ) );
	}

	/**
	 * Unslashed sanitized POST string.
	 */
	private static function post_text( string $key ): string {
		return isset( $_POST[ $key ] ) && is_string( $_POST[ $key ] )
			? sanitize_text_field( wp_unslash( $_POST[ $key ] ) )
			: '';
	}

	/**
	 * Unslashed sanitized POST textarea.
	 */
	private static function post_textarea( string $key ): string {
		return isset( $_POST[ $key ] ) && is_string( $_POST[ $key ] )
			? sanitize_textarea_field( wp_unslash( $_POST[ $key ] ) )
			: '';
	}
}
