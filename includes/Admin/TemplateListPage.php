<?php
/**
 * Reusable email template directory and admin-post handlers.
 *
 * @package NowCRM
 */

namespace NowCRM\Admin;

use NowCRM\Support\Capabilities;
use NowCRM\Templates\TemplateRepository;
use WP_Error;

defined( 'ABSPATH' ) || exit;

final class TemplateListPage {

	private const ACTION_CREATE   = 'nowcrm_create_template';
	private const ACTION_DUPLICATE = 'nowcrm_duplicate_template';
	private const ACTION_DELETE   = 'nowcrm_delete_template';
	private const ACTION_BULK     = 'nowcrm_bulk_delete_templates';

	/**
	 * Notices used by the template screens.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	private static function messages(): array {
		return array(
			'created'        => array( 'success', __( '已建立範本。', 'now-crm' ) ),
			'saved'          => array( 'success', __( '已儲存範本。', 'now-crm' ) ),
			'duplicated'     => array( 'success', __( '已複製範本。', 'now-crm' ) ),
			'deleted'        => array( 'success', __( '已永久刪除範本。', 'now-crm' ) ),
			'invalid_name'   => array( 'error', __( '請輸入範本名稱。', 'now-crm' ) ),
			'invalid_mode'   => array( 'error', __( '請選擇 Gutenberg 或 Raw HTML 編輯方式。', 'now-crm' ) ),
			'unsupported_block' => array( 'error', __( '正文內含不支援電子報的區塊。', 'now-crm' ) ),
			'not_found'      => array( 'error', __( '找不到電子報範本。', 'now-crm' ) ),
			'forbidden'      => array( 'error', __( '您沒有執行此操作的權限。', 'now-crm' ) ),
			'db_error'       => array( 'error', __( '無法儲存電子報範本。請再試一次。', 'now-crm' ) ),
			'no_selection'   => array( 'error', __( '請先選取範本。', 'now-crm' ) ),
		);
	}

	/**
	 * Print an allow-listed template notice.
	 */
	public static function render_notice(): void {
		if ( ! isset( $_GET['nowcrm_notice'] ) || ! is_string( $_GET['nowcrm_notice'] ) ) {
			return;
		}

		$code     = sanitize_key( wp_unslash( $_GET['nowcrm_notice'] ) );
		$messages = self::messages();

		if ( ! array_key_exists( $code, $messages ) ) {
			return;
		}

		$message = $messages[ $code ][1];

		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $messages[ $code ][0] ),
			esc_html( $message )
		);
	}

	/**
	 * Register the directory's admin-post handlers.
	 */
	public static function register(): void {
		add_action( 'admin_post_' . self::ACTION_CREATE, array( __CLASS__, 'handle_create' ) );
		add_action( 'admin_post_' . self::ACTION_DUPLICATE, array( __CLASS__, 'handle_duplicate' ) );
		add_action( 'admin_post_' . self::ACTION_DELETE, array( __CLASS__, 'handle_delete' ) );
		add_action( 'admin_post_' . self::ACTION_BULK, array( __CLASS__, 'handle_bulk_delete' ) );
	}

	/**
	 * Render the template directory, or delegate to the editor.
	 */
	public static function render(): void {
		if ( ! Capabilities::can_manage_contacts() ) {
			wp_die( esc_html__( '沒有權限。', 'now-crm' ), '', array( 'response' => 403 ) );
		}

		$template_id = absint( Menu::query( 'template' ) );

		if ( $template_id ) {
			TemplatePage::render( $template_id );
			return;
		}

		$repo       = new TemplateRepository();
		$can_create = $repo->can_create();
		$search     = sanitize_text_field( Menu::query( 's' ) );
		$status     = sanitize_key( Menu::query( 'status' ) );

		if ( ! in_array( $status, array( TemplateRepository::STATUS_DRAFT, TemplateRepository::STATUS_PUBLISH ), true ) ) {
			$status = '';
		}

		$page   = max( 1, absint( Menu::query( 'paged' ) ) );
		$result = $repo->paginate(
			array(
				'page'     => $page,
				'per_page' => WorkflowCatalog::PER_PAGE,
				'search'   => $search,
				'status'   => $status,
			)
		);

		if ( (int) $result['pages'] > 0 && $page > (int) $result['pages'] ) {
			$page   = (int) $result['pages'];
			$result = $repo->paginate(
				array(
					'page'     => $page,
					'per_page' => WorkflowCatalog::PER_PAGE,
					'search'   => $search,
					'status'   => $status,
				)
			);
		}

		if ( 0 === (int) $result['total'] ) {
			$page = 1;
		}

		$counts = array(
			'all'     => self::count_templates( $repo, $search, '' ),
			'draft'   => self::count_templates( $repo, $search, TemplateRepository::STATUS_DRAFT ),
			'publish' => self::count_templates( $repo, $search, TemplateRepository::STATUS_PUBLISH ),
		);

		$library   = '' === $search ? $counts['all'] : self::count_templates( $repo, '', '' );
		$show_form = '1' === Menu::query( 'new' );
		$rows      = array();

		foreach ( $result['items'] as $template ) {
			$id         = (int) $template['id'];
			$edit_url   = TemplatePage::url( $id );
			$can_edit   = $repo->can_edit( $id );
			$can_delete = $repo->can_delete( $id );
			$is_publish = TemplateRepository::STATUS_PUBLISH === $template['status'];

			ob_start();
			?>
			<?php if ( $can_edit ) : ?>
				<a class="nowcrm-catalog__link" href="<?php echo esc_url( $edit_url ); ?>"><?php echo esc_html( $template['name'] ); ?></a>
			<?php else : ?>
				<span class="nowcrm-catalog__link"><?php echo esc_html( $template['name'] ); ?></span>
			<?php endif; ?>
			<div class="nowcrm-catalog__meta">
				<span><?php echo esc_html( '' !== $template['subject'] ? $template['subject'] : '—' ); ?></span>
				<span class="nowcrm-catalog__separator" aria-hidden="true">·</span>
				<span><?php echo esc_html( \NowCRM\Campaign\Campaigns::MODE_HTML === $template['mode'] ? __( 'Raw HTML', 'now-crm' ) : __( 'Gutenberg', 'now-crm' ) ); ?></span>
			</div>
			<div class="nowcrm-catalog__row-actions">
				<?php if ( $can_edit ) : ?>
					<a class="nowcrm-catalog__row-action" href="<?php echo esc_url( $edit_url ); ?>"><?php esc_html_e( '編輯', 'now-crm' ); ?></a>
				<?php endif; ?>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="nowcrm-catalog__row-form">
					<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_DUPLICATE ); ?>">
					<input type="hidden" name="template_id" value="<?php echo esc_attr( (string) $id ); ?>">
					<?php wp_nonce_field( self::ACTION_DUPLICATE ); ?>
					<button type="submit" class="nowcrm-catalog__row-action"><?php esc_html_e( '複製', 'now-crm' ); ?></button>
				</form>

				<?php if ( $can_delete ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="nowcrm-catalog__row-form">
						<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_DELETE ); ?>">
						<input type="hidden" name="template_id" value="<?php echo esc_attr( (string) $id ); ?>">
						<?php wp_nonce_field( self::ACTION_DELETE ); ?>
						<button type="submit" class="nowcrm-catalog__row-action nowcrm-catalog__row-action--danger" onclick="return window.confirm('<?php echo esc_js( __( '永久刪除此範本？此操作無法復原。', 'now-crm' ) ); ?>');"><?php esc_html_e( '永久刪除', 'now-crm' ); ?></button>
					</form>
				<?php endif; ?>
			</div>
			<?php
			$title_cell = (string) ob_get_clean();

			$status_cell = '<span class="nowcrm-status-pill' . ( $is_publish ? ' nowcrm-status-pill--active' : '' ) . '">'
				. esc_html( $is_publish ? __( '已發佈', 'now-crm' ) : __( '草稿', 'now-crm' ) )
				. '</span>';

			$rows[] = array(
				'id'       => $id,
				'label'    => (string) $template['name'],
				'eligible' => $can_delete,
				'reason'   => __( '您沒有刪除此範本的權限。', 'now-crm' ),
				'cells'    => array(
					$title_cell,
					$status_cell,
					'<span class="nowcrm-catalog__time">' . esc_html( mysql2date( 'Y-m-d H:i', (string) $template['modified'] ) ) . '</span>',
				),
			);
		}

		self::render_notice();
		WorkflowCatalog::render_notice( __( '範本', 'now-crm' ) );

		$panel = '';

		if ( $show_form && $can_create ) {
			ob_start();
			?>
				<section class="nowcrm-panel" id="nowcrm-template-create">
					<h2><?php esc_html_e( '新增範本', 'now-crm' ); ?></h2>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_CREATE ); ?>">
						<?php wp_nonce_field( self::ACTION_CREATE ); ?>

						<label class="nowcrm-campaigns__field" for="nowcrm-template-name">
							<span><?php esc_html_e( '範本名稱', 'now-crm' ); ?></span>
							<input type="text" id="nowcrm-template-name" name="name" required>
						</label>

						<fieldset class="nowcrm-mode-fieldset">
							<legend><?php esc_html_e( '編輯方式', 'now-crm' ); ?></legend>
							<div class="nowcrm-mode-cards">
								<label class="nowcrm-mode-card">
									<input type="radio" name="mode" value="<?php echo esc_attr( \NowCRM\Campaign\Campaigns::MODE_BLOCKS ); ?>" checked>
									<span><?php esc_html_e( 'Gutenberg 編輯器', 'now-crm' ); ?></span>
								</label>
								<label class="nowcrm-mode-card">
									<input type="radio" name="mode" value="<?php echo esc_attr( \NowCRM\Campaign\Campaigns::MODE_HTML ); ?>">
									<span><?php esc_html_e( 'Raw HTML', 'now-crm' ); ?></span>
								</label>
							</div>
						</fieldset>

						<p class="nowcrm-campaigns__submit">
							<button type="submit" class="button button-primary"><?php esc_html_e( '建立範本', 'now-crm' ); ?></button>
							<a class="button" href="<?php echo esc_url( Menu::page_url( Menu::TEMPLATES_SLUG ) ); ?>"><?php esc_html_e( '取消', 'now-crm' ); ?></a>
						</p>
					</form>
				</section>
			<?php
			$panel = (string) ob_get_clean();
		}

		WorkflowCatalog::render(
			array(
				'slug'               => Menu::TEMPLATES_SLUG,
				'title'              => __( '電子報範本', 'now-crm' ),
				'noun'               => __( '範本', 'now-crm' ),
				'search'             => $search,
				'status'             => '' === $status ? 'all' : $status,
				'statuses'           => array(
					TemplateRepository::STATUS_DRAFT   => __( '草稿', 'now-crm' ),
					TemplateRepository::STATUS_PUBLISH => __( '已發佈', 'now-crm' ),
				),
				'counts'             => $counts,
				'library_total'      => $library,
				'total'              => (int) $result['total'],
				'page'                => $page,
				'pages'              => (int) $result['pages'],
				'panel'              => $panel,
				'search_placeholder' => __( '搜尋範本名稱', 'now-crm' ),
				'create'             => $can_create ? array(
					'url'   => Menu::page_url( Menu::TEMPLATES_SLUG, array( 'new' => '1' ) ),
					'label' => __( '新增範本', 'now-crm' ),
				) : null,
				'columns'            => array(
					array( 'label' => __( '名稱', 'now-crm' ), 'class' => 'nowcrm-catalog__col-main' ),
					array( 'label' => __( '狀態', 'now-crm' ), 'class' => 'nowcrm-catalog__col-status' ),
					array( 'label' => __( '更新時間', 'now-crm' ), 'class' => 'nowcrm-catalog__col-time' ),
				),
				'rows'               => $rows,
				'bulk'               => array(
					'action'  => self::ACTION_BULK,
					'nonce'   => self::ACTION_BULK,
					'field'   => 'template_ids',
					'options' => array(),
					'scope'   => true,
					'confirm' => array(
						'' => __( '確定永久刪除選取的範本？此操作無法復原。', 'now-crm' ),
					),
					'note'     => sprintf(
						/* translators: %d: maximum number of templates in one request. */
						__( '可只刪除本頁，或選取全部符合篩選的結果；每次最多 %d 筆。', 'now-crm' ),
						absint( TemplateRepository::BULK_LIMIT )
					),
					'submit'   => __( '永久刪除選取', 'now-crm' ),
				),
				'empty'              => array(
					'filtered' => __( '沒有符合條件的範本。', 'now-crm' ),
					'page'     => __( '這一頁沒有範本。', 'now-crm' ),
					'library'  => __( '還沒有範本，先建立第一個。', 'now-crm' ),
				),
			)
		);
	}

	/**
	 * Number of templates matching a search and status.
	 *
	 * @param TemplateRepository $repo   Repository.
	 * @param string             $search Search term.
	 * @param string             $status Status, or an empty string for all.
	 */
	private static function count_templates( TemplateRepository $repo, string $search, string $status ): int {
		$result = $repo->paginate(
			array(
				'page'     => 1,
				'per_page' => 1,
				'search'   => $search,
				'status'   => $status,
			)
		);

		return (int) $result['total'];
	}

	/**
	 * Create a draft template and open its editor.
	 */
	public static function handle_create(): void {
		self::guard( self::ACTION_CREATE );

		$repo = new TemplateRepository();
		$id   = $repo->create(
			array(
				'name'   => self::post_string( 'name' ),
				'mode'   => self::post_string( 'mode' ),
				'status' => TemplateRepository::STATUS_DRAFT,
			)
		);

		if ( is_wp_error( $id ) ) {
			self::redirect( array( 'nowcrm_notice' => self::notice_from_error( $id ) ) );
		}

		self::redirect(
			array(
				'template'      => (int) $id,
				'nowcrm_notice' => 'created',
			)
		);
	}

	/**
	 * Copy one template.
	 */
	public static function handle_duplicate(): void {
		self::guard( self::ACTION_DUPLICATE );

		$id     = absint( self::post_string( 'template_id' ) );
		$result = ( new TemplateRepository() )->duplicate( $id );

		if ( is_wp_error( $result ) ) {
			self::redirect( array( 'nowcrm_notice' => self::notice_from_error( $result ) ) );
		}

		self::redirect(
			array(
				'template'      => (int) $result,
				'nowcrm_notice' => 'duplicated',
			)
		);
	}

	/**
	 * Permanently delete one template.
	 */
	public static function handle_delete(): void {
		self::guard( self::ACTION_DELETE );

		$id     = absint( self::post_string( 'template_id' ) );
		$result = ( new TemplateRepository() )->delete( $id );

		self::redirect( array( 'nowcrm_notice' => is_wp_error( $result ) ? self::notice_from_error( $result ) : 'deleted' ) );
	}

	/**
	 * Permanently delete the selected templates.
	 *
	 * Kept as the registered route for the template bulk form. Selection and
	 * write policy live in WorkflowCatalogActions.
	 */
	public static function handle_bulk_delete(): void {
		WorkflowCatalogActions::templates();
	}

	/**
	 * Capability and nonce gate shared by the handlers.
	 */
	private static function guard( string $action ): void {
		if ( ! Capabilities::can_manage_contacts() ) {
			wp_die( esc_html__( '您沒有權限執行此操作。', 'now-crm' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( $action );
	}

	private static function post_string( string $key ): string {
		return isset( $_POST[ $key ] ) && is_string( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : '';
	}

	/**
	 * Redirect to an allow-listed template notice screen.
	 *
	 * @param array<string, mixed> $args Query arguments.
	 */
	private static function redirect( array $args ): void {
		wp_safe_redirect( Menu::page_url( Menu::TEMPLATES_SLUG, $args ) );
		exit;
	}

	/**
	 * Map a repository error to an allow-listed notice code.
	 */
	private static function notice_from_error( WP_Error $error ): string {
		$code = str_replace( 'nowcrm_template_', '', (string) $error->get_error_code() );

		return array_key_exists( $code, self::messages() ) ? $code : 'db_error';
	}
}
