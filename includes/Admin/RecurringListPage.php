<?php
/**
 * Recurring campaign list, inline draft creation, and bulk trash.
 *
 * The schedule detail and the content workspace both live under this one
 * visible slug. A recurring parent is a campaign that carries the recurring
 * status meta; children and normal campaigns never appear in this list.
 *
 * @package NowCRM
 */

namespace NowCRM\Admin;

use NowCRM\Campaign\Campaigns;
use NowCRM\Campaign\Recurring;
use NowCRM\Support\Capabilities;

defined( 'ABSPATH' ) || exit;

final class RecurringListPage {

	public const CREATE_ACTION = 'nowcrm_create_recurring';
	public const BULK_ACTION   = 'nowcrm_bulk_recurring';

	private const NOTICE_KEY = 'nowcrm_recurring_create_';

	/**
	 * Register the admin-post handlers owned by this screen.
	 */
	public static function register(): void {
		add_action( 'admin_post_' . self::CREATE_ACTION, array( __CLASS__, 'handle_create' ) );
		add_action( 'admin_post_' . self::BULK_ACTION, array( __CLASS__, 'handle_bulk' ) );
	}

	/**
	 * Render the list, the detail, or the campaign content workspace.
	 */
	public static function render(): void {
		if ( ! Capabilities::can_manage_contacts() ) {
			wp_die( esc_html__( '沒有權限。', 'now-crm' ), '', array( 'response' => 403 ) );
		}

		$id = absint( Menu::query( 'campaign' ) );

		if ( $id ) {
			// The schedule detail and the content view are the same campaign
			// workspace; the 排程 step holds everything RecurringPage used to.
			if ( null === RecurringPage::parent( $id ) ) {
				wp_die( esc_html__( '找不到定期電子報。', 'now-crm' ), '', array( 'response' => 404 ) );
			}

			CampaignPage::render( $id );
			return;
		}

		$post_type = get_post_type_object( Campaigns::POST_TYPE );

		if ( ! $post_type || ! current_user_can( $post_type->cap->edit_posts ) ) {
			wp_die( esc_html__( '沒有權限。', 'now-crm' ), '', array( 'response' => 403 ) );
		}

		$search = sanitize_text_field( Menu::query( 's' ) );
		$status = sanitize_key( Menu::query( 'status', 'all' ) );
		$result = Recurring::paginate(
			$search,
			$status,
			max( 1, absint( Menu::query( 'paged', '1' ) ) ),
			WorkflowCatalog::PER_PAGE
		);

		$counts  = $result['counts'];
		$total   = (int) $result['total'];
		$page    = (int) $result['page'];
		$pages   = (int) $result['pages'];
		$library = '' === $search ? (int) $counts['all'] : (int) Recurring::counts( '' )['all'];
		$status  = in_array( $status, array_keys( Recurring::status_labels() ), true ) ? $status : 'all';

		$state = get_transient( self::NOTICE_KEY . get_current_user_id() );
		$values = array(
			'title'     => '',
			'mode'      => Campaigns::MODE_BLOCKS,
			'frequency' => 'daily',
			'time'      => '09:00',
			'day'       => '',
			'auto_send' => false,
		);
		$error     = '';
		$show_form = '1' === Menu::query( 'new' );

		if ( is_array( $state ) ) {
			delete_transient( self::NOTICE_KEY . get_current_user_id() );
			$values    = wp_parse_args( is_array( $state['values'] ?? null ) ? $state['values'] : array(), $values );
			$error     = (string) ( $state['error'] ?? '' );
			$show_form = true;
		}

		$can_create = current_user_can( $post_type->cap->create_posts );
		$can_bulk   = current_user_can( $post_type->cap->delete_posts );
		$rows       = array();

		foreach ( $result['items'] as $post ) {
			$settings   = Recurring::settings( $post->ID );
			$detail_url = RecurringPage::detail_url( $post->ID );
			$settings_url = RecurringPage::settings_url( $post->ID );
			$content_url = RecurringPage::content_url( $post->ID );
			$count      = Recurring::child_count( $post->ID );
			$next       = '' !== $settings['next_run_at']
				? get_date_from_gmt( $settings['next_run_at'], 'Y-m-d H:i' )
				: '—';
			$modified   = (string) get_post_modified_time( 'Y-m-d H:i', false, $post );
			$can_delete = current_user_can( 'delete_post', $post->ID );

			ob_start();
			?>
			<a class="nowcrm-catalog__link" href="<?php echo esc_url( $detail_url ); ?>">
				<?php echo esc_html( get_the_title( $post ) ); ?>
			</a>
			<div class="nowcrm-catalog__meta"><?php echo esc_html( RecurringPage::frequency_label( $settings ) ); ?></div>
			<div class="nowcrm-catalog__row-actions">
				<a class="nowcrm-catalog__row-action" href="<?php echo esc_url( $settings_url ); ?>"><?php esc_html_e( '編輯設定', 'now-crm' ); ?></a>
				<a class="nowcrm-catalog__row-action" href="<?php echo esc_url( $content_url ); ?>"><?php esc_html_e( '編輯內容', 'now-crm' ); ?></a>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="nowcrm-catalog__row-form">
					<input type="hidden" name="action" value="<?php echo esc_attr( RecurringPage::STATUS_ACTION ); ?>">
					<input type="hidden" name="campaign_id" value="<?php echo esc_attr( (string) $post->ID ); ?>">
					<input type="hidden" name="redirect" value="list">
					<input type="hidden" name="filter_search" value="<?php echo esc_attr( $search ); ?>">
					<input type="hidden" name="filter_status" value="<?php echo esc_attr( $status ); ?>">
					<input type="hidden" name="filter_paged" value="<?php echo esc_attr( (string) $page ); ?>">
					<?php wp_nonce_field( RecurringPage::STATUS_ACTION ); ?>
					<?php if ( Recurring::STATUS_ACTIVE === $settings['status'] ) : ?>
						<input type="hidden" name="op" value="pause">
						<button type="submit" class="nowcrm-catalog__row-action"><?php esc_html_e( '暫停', 'now-crm' ); ?></button>
					<?php else : ?>
						<input type="hidden" name="op" value="resume">
						<button type="submit" class="nowcrm-catalog__row-action"><?php echo esc_html( Recurring::STATUS_PAUSED === $settings['status'] ? __( '恢復', 'now-crm' ) : __( '啟用', 'now-crm' ) ); ?></button>
					<?php endif; ?>
				</form>
			</div>
			<?php
			$title_cell = (string) ob_get_clean();

			$rows[] = array(
				'id'       => (int) $post->ID,
				'label'    => get_the_title( $post ),
				'eligible' => $can_delete,
				'reason'   => __( '您沒有刪除這封定期電子報的權限。', 'now-crm' ),
				'cells'    => array(
					$title_cell,
					'<span class="nowcrm-catalog__muted">' . esc_html( RecurringPage::frequency_text( $settings ) ) . '</span>',
					'<span class="nowcrm-catalog__time">' . esc_html( $next ) . '</span>',
					'<span class="nowcrm-catalog__number">' . esc_html( (string) $count ) . '</span>',
					RecurringPage::status_pill( $settings['status'] ),
					'<span class="nowcrm-catalog__time">' . esc_html( $modified ) . '</span>',
				),
			);
		}

		ob_start();
		?>
		<?php if ( $show_form ) : ?>
			<div class="nowcrm-panel" id="nowcrm-recurring-create">
				<h2><?php esc_html_e( '新增定期寄送', 'now-crm' ); ?></h2>
				<p class="description"><?php esc_html_e( '先建立未啟用的草稿，再到「編輯內容與受眾」填寫主旨與正文，最後啟用定期寄送。', 'now-crm' ); ?></p>

				<?php if ( '' !== $error ) : ?>
					<div class="notice notice-error"><p><?php echo esc_html( $error ); ?></p></div>
				<?php endif; ?>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="nowcrm-inline-form">
					<input type="hidden" name="action" value="<?php echo esc_attr( self::CREATE_ACTION ); ?>">
					<?php wp_nonce_field( self::CREATE_ACTION ); ?>

					<label>
						<span><?php esc_html_e( '名稱', 'now-crm' ); ?></span>
						<input type="text" name="title" value="<?php echo esc_attr( (string) $values['title'] ); ?>" maxlength="160" required="required">
					</label>

					<label>
						<span><?php esc_html_e( '編輯方式', 'now-crm' ); ?></span>
						<select name="mode">
							<option value="<?php echo esc_attr( Campaigns::MODE_BLOCKS ); ?>" <?php selected( $values['mode'], Campaigns::MODE_BLOCKS ); ?>><?php esc_html_e( 'Gutenberg', 'now-crm' ); ?></option>
							<option value="<?php echo esc_attr( Campaigns::MODE_HTML ); ?>" <?php selected( $values['mode'], Campaigns::MODE_HTML ); ?>><?php esc_html_e( 'Raw HTML', 'now-crm' ); ?></option>
						</select>
					</label>

					<?php
					RecurringPage::render_schedule_inputs(
						array(
							'frequency' => (string) $values['frequency'],
							'time'      => (string) $values['time'],
							'day'       => (string) $values['day'],
							'auto_send' => ! empty( $values['auto_send'] ),
						)
					);
					?>

					<button type="submit" class="button button-primary"><?php esc_html_e( '建立草稿', 'now-crm' ); ?></button>
					<a class="button" href="<?php echo esc_url( RecurringPage::list_url() ); ?>"><?php esc_html_e( '取消', 'now-crm' ); ?></a>
				</form>
			</div>
		<?php endif; ?>
		<?php
		$panel = (string) ob_get_clean();

		WorkflowCatalog::render_notice( __( '定期寄送', 'now-crm' ) );
		RecurringPage::render_notice();

		WorkflowCatalog::render(
			array(
				'slug'               => Menu::RECURRING_SLUG,
				'title'              => __( '定期寄送', 'now-crm' ),
				'noun'               => __( '定期寄送', 'now-crm' ),
				'search'             => $search,
				'status'             => $status,
				'statuses'           => Recurring::status_labels(),
				'counts'             => $counts,
				'library_total'      => $library,
				'total'              => $total,
				'page'               => $page,
				'pages'              => $pages,
				'panel'              => $panel,
				'search_placeholder' => __( '搜尋名稱', 'now-crm' ),
				'create'             => $can_create ? array(
					'url'   => Menu::page_url( Menu::RECURRING_SLUG, array( 'new' => '1' ) ),
					'label' => __( '新增定期寄送', 'now-crm' ),
				) : null,
				'testid'             => 'recurring-list',
				'columns'            => array(
					array( 'label' => __( '名稱', 'now-crm' ), 'class' => 'nowcrm-catalog__col-main' ),
					array( 'label' => __( '頻率', 'now-crm' ) ),
					array( 'label' => __( '下次產生', 'now-crm' ), 'class' => 'nowcrm-catalog__col-time' ),
					array( 'label' => __( '已產生', 'now-crm' ), 'class' => 'num' ),
					array( 'label' => __( '狀態', 'now-crm' ), 'class' => 'nowcrm-catalog__col-status' ),
					array( 'label' => __( '更新時間', 'now-crm' ), 'class' => 'nowcrm-catalog__col-time' ),
				),
				'rows'               => $rows,
				'bulk'               => $can_bulk ? array(
					'action'  => self::BULK_ACTION,
					'nonce'   => self::BULK_ACTION,
					'field'   => 'recurring_ids',
					'options' => array( 'trash' => __( '刪除', 'now-crm' ) ),
					'confirm' => array(
						'trash' => __( '確定刪除選取的定期電子報？子電子報會一併移除；有子電子報在排程、寄送或暫停中時，該筆不會刪除。', 'now-crm' ),
					),
					'note'    => __( '只處理定期寄送的家長電子報，並保留仍在排程、寄送或暫停中的子電子報。', 'now-crm' ),
				) : null,
				'empty'              => array(
					'filtered' => __( '沒有符合條件的定期寄送。', 'now-crm' ),
					'page'     => __( '這一頁沒有定期寄送。', 'now-crm' ),
					'library'  => __( '還沒有定期寄送，先建立第一個排程。', 'now-crm' ),
				),
			)
		);
	}

	/**
	 * Create an inactive parent from the inline form.
	 */
	public static function handle_create(): void {
		self::guard( self::CREATE_ACTION );

		$post_type = get_post_type_object( Campaigns::POST_TYPE );

		if ( ! $post_type || ! current_user_can( $post_type->cap->create_posts ) ) {
			wp_die( esc_html__( '您沒有權限執行此操作。', 'now-crm' ), '', array( 'response' => 403 ) );
		}

		$title = trim( sanitize_text_field( self::post_string( 'title' ) ) );
		$mode  = Campaigns::MODE_HTML === sanitize_key( self::post_string( 'mode' ) )
			? Campaigns::MODE_HTML
			: Campaigns::MODE_BLOCKS;
		$input = array(
			'status'    => Recurring::STATUS_INACTIVE,
			'frequency' => sanitize_key( self::post_string( 'frequency' ) ),
			'time'      => sanitize_text_field( self::post_string( 'time' ) ),
			'day'       => sanitize_text_field( self::post_string( 'day' ) ),
			'auto_send' => isset( $_POST['auto_send'] ),
		);
		$values = array(
			'title'     => $title,
			'mode'      => $mode,
			'frequency' => $input['frequency'],
			'time'      => $input['time'],
			'day'       => $input['day'],
			'auto_send' => $input['auto_send'],
		);

		if ( '' === $title ) {
			self::fail( $values, __( '請輸入名稱。', 'now-crm' ) );
		}

		$settings = Recurring::validate( $input );

		if ( is_wp_error( $settings ) ) {
			self::fail( $values, $settings->get_error_message() );
		}

		if ( mb_strlen( $title, 'UTF-8' ) > 160 ) {
			$title          = mb_substr( $title, 0, 160, 'UTF-8' );
			$values['title'] = $title;
		}

		$id = Campaigns::create_draft( $title, $mode );

		if ( is_wp_error( $id ) || ! $id ) {
			self::fail(
				$values,
				is_wp_error( $id ) ? $id->get_error_message() : __( '無法建立電子報，請再試一次。', 'now-crm' )
			);
		}

		$id     = (int) $id;
		$result = Recurring::save( $id, $input );

		if ( is_wp_error( $result ) ) {
			wp_trash_post( $id );
			self::fail( $values, $result->get_error_message() );
		}

		wp_safe_redirect( add_query_arg( 'nowcrm_notice', 'recurring_created', RecurringPage::detail_url( $id ) ) );
		exit;
	}

	/**
	 * Move selected parents and their children to the trash.
	 */
	public static function handle_bulk(): void {
		self::guard( self::BULK_ACTION );

		$filters   = self::filters();
		$operation = sanitize_key( self::post_string( 'bulk_operation' ) );

		if ( 'trash' !== $operation ) {
			self::bulk_redirect( array( 'nowcrm_notice' => 'invalid_operation' ), $filters );
		}

		$ids = self::posted_ids( 'recurring_ids' );

		if ( ! $ids ) {
			self::bulk_redirect( array( 'nowcrm_notice' => 'no_selection' ), $filters );
		}

		$deleted = 0;
		$skipped = 0;

		foreach ( $ids as $id ) {
			if ( is_wp_error( RecurringPage::trash_parent( $id ) ) ) {
				++$skipped;
			} else {
				++$deleted;
			}
		}

		if ( $deleted > 0 ) {
			Recurring::sync();
		}

		self::bulk_redirect(
			array(
				'nowcrm_notice'  => 'bulk_deleted',
				'nowcrm_deleted' => $deleted,
				'nowcrm_skipped' => $skipped,
			),
			$filters
		);
	}

	/**
	 * Store the draft values and reopen the create panel.
	 *
	 * @param array<string, mixed> $values Submitted values.
	 * @param string               $message Error message.
	 */
	private static function fail( array $values, string $message ): void {
		set_transient(
			self::NOTICE_KEY . get_current_user_id(),
			array(
				'values' => $values,
				'error'  => $message,
			),
			MINUTE_IN_SECONDS
		);

		wp_safe_redirect( Menu::page_url( Menu::RECURRING_SLUG, array( 'new' => '1' ) ) );
		exit;
	}

	/**
	 * Capability and nonce gate shared by the recurring list handlers.
	 */
	private static function guard( string $action ): void {
		if ( ! Capabilities::can_manage_contacts() ) {
			wp_die( esc_html__( '您沒有執行此操作的權限。', 'now-crm' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( $action );
	}

	/**
	 * Safe list filters used by redirects.
	 *
	 * @return array{search: string, status: string, page: int}
	 */
	private static function filters(): array {
		$search = sanitize_text_field( self::post_string( 'filter_search' ) );
		$status = sanitize_key( self::post_string( 'filter_status' ) );
		$page   = max( 1, absint( self::post_string( 'filter_paged' ) ) );

		if ( ! in_array( $status, array_merge( array( 'all' ), array_keys( Recurring::status_labels() ) ), true ) ) {
			$status = 'all';
		}

		return array(
			'search' => $search,
			'status' => $status,
			'page'   => $page,
		);
	}

	/**
	 * Redirect to the list with the notice and the safe filters.
	 *
	 * @param array<string, mixed> $notice  Notice arguments.
	 * @param array<string, mixed> $filters Safe filters.
	 */
	private static function bulk_redirect( array $notice, array $filters ): void {
		$args = array();

		if ( '' !== (string) $filters['search'] ) {
			$args['s'] = (string) $filters['search'];
		}

		if ( 'all' !== (string) $filters['status'] ) {
			$args['status'] = (string) $filters['status'];
		}

		if ( (int) $filters['page'] > 1 ) {
			$args['paged'] = (int) $filters['page'];
		}

		wp_safe_redirect( Menu::page_url( Menu::RECURRING_SLUG, array_merge( $args, $notice ) ) );
		exit;
	}

	/**
	 * Read one scalar POST value.
	 *
	 * @param string $key Field name.
	 */
	private static function post_string( string $key ): string {
		return isset( $_POST[ $key ] ) && is_string( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : '';
	}

	/**
	 * Read, validate, and de-duplicate strict positive integer IDs.
	 *
	 * @param string $field Field name.
	 * @return int[]
	 */
	private static function posted_ids( string $field ): array {
		if ( ! isset( $_POST[ $field ] ) || ! is_array( $_POST[ $field ] ) ) {
			return array();
		}

		$ids = array();

		foreach ( wp_unslash( $_POST[ $field ] ) as $value ) {
			if ( ! is_scalar( $value ) ) {
				continue;
			}

			$id = filter_var(
				(string) $value,
				FILTER_VALIDATE_INT,
				array( 'options' => array( 'min_range' => 1 ) )
			);

			if ( false !== $id ) {
				$ids[ (int) $id ] = (int) $id;
			}
		}

		return array_values( $ids );
	}
}
