<?php
/**
 * Editor for one reusable email template.
 *
 * Gutenberg blocks reuse the WordPress block editor packages already loaded by
 * the campaign workspace. Raw HTML stays a plain textarea; no second rich text
 * editor is introduced.
 *
 * @package NowCRM
 */

namespace NowCRM\Admin;

use NowCRM\Campaign\Campaigns;
use NowCRM\Support\Capabilities;
use NowCRM\Templates\TemplateRepository;

defined( 'ABSPATH' ) || exit;

final class TemplatePage {

	private const ACTION_SAVE = 'nowcrm_save_template';

	public static function url( int $id ): string {
		return Menu::page_url( Menu::TEMPLATES_SLUG, array( 'template' => $id ) );
	}

	/**
	 * Register the save handler.
	 */
	public static function register(): void {
		add_action( 'admin_post_' . self::ACTION_SAVE, array( __CLASS__, 'save_template' ) );
	}

	/**
	 * Render the editor for one template.
	 */
	public static function render( int $id ): void {
		$repo     = new TemplateRepository();
		$template = $repo->get( $id );

		if ( ! $template || ! $repo->can_edit( $id ) ) {
			wp_die( esc_html__( '無法開啟這個電子報範本。', 'now-crm' ), '', array( 'response' => 403 ) );
		}

		// A failed save keeps the submitted values for one retry. The embedded
		// block editor reads the same draft so unsaved blocks survive the round
		// trip instead of falling back to the last stored body.
		$draft = '1' === Menu::query( 'nowcrm_draft' ) ? self::draft_for_editor( $id ) : null;

		if ( ! is_array( $draft ) ) {
			delete_transient( self::draft_key( $id ) );
			$draft = null;
		}

		$view = array_merge( $template, is_array( $draft ) ? $draft : array() );
		$embed_url = ContentEditorPage::url( 'template', $id, true, is_array( $draft ) );
		?>
		<div class="wrap nowcrm-wrap nowcrm-workspace">
			<header class="nowcrm-workspace__header">
				<div class="nowcrm-workspace__title">
					<h1><?php
						/* translators: %s: template name. */
						echo esc_html( sprintf( __( '編輯範本：%s', 'now-crm' ), $template['name'] ) );
					?></h1>
					<a class="button" href="<?php echo esc_url( Menu::page_url( Menu::TEMPLATES_SLUG ) ); ?>"><?php esc_html_e( '返回範本列表', 'now-crm' ); ?></a>
				</div>
			</header>

			<?php TemplateListPage::render_notice(); ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="nowcrm-panel" id="nowcrm-template-editor" data-workflow-email="template" data-workflow-source-id="<?php echo esc_attr( (string) $template['id'] ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_SAVE ); ?>">
				<input type="hidden" name="template_id" value="<?php echo esc_attr( (string) $template['id'] ); ?>">
				<?php wp_nonce_field( self::ACTION_SAVE ); ?>

				<div class="nowcrm-workspace__fields">
					<label class="nowcrm-workspace__field" for="nowcrm-template-name">
						<span><?php esc_html_e( '範本名稱', 'now-crm' ); ?></span>
						<input type="text" id="nowcrm-template-name" name="name" value="<?php echo esc_attr( $view['name'] ); ?>" required>
					</label>
					<label class="nowcrm-workspace__field" for="nowcrm-template-subject">
						<span><?php esc_html_e( '郵件主旨', 'now-crm' ); ?></span>
						<input type="text" id="nowcrm-template-subject" name="subject" value="<?php echo esc_attr( $view['subject'] ); ?>" data-workflow-field="subject">
					</label>
					<label class="nowcrm-workspace__field" for="nowcrm-template-preheader">
						<span><?php esc_html_e( '預覽文字', 'now-crm' ); ?></span>
						<input type="text" id="nowcrm-template-preheader" name="preheader" value="<?php echo esc_attr( $view['preheader'] ); ?>" data-workflow-field="preheader">
					</label>
				</div>

				<fieldset class="nowcrm-mode-fieldset">
					<legend><?php esc_html_e( '編輯方式', 'now-crm' ); ?></legend>
					<div class="nowcrm-mode-cards">
						<label class="nowcrm-mode-card">
							<input type="radio" name="mode" value="<?php echo esc_attr( Campaigns::MODE_BLOCKS ); ?>" <?php checked( $view['mode'], Campaigns::MODE_BLOCKS ); ?> data-workflow-field="mode">
							<span><?php esc_html_e( 'Gutenberg 編輯器', 'now-crm' ); ?></span>
						</label>
						<label class="nowcrm-mode-card">
							<input type="radio" name="mode" value="<?php echo esc_attr( Campaigns::MODE_HTML ); ?>" <?php checked( $view['mode'], Campaigns::MODE_HTML ); ?> data-workflow-field="mode">
							<span><?php esc_html_e( 'Raw HTML', 'now-crm' ); ?></span>
						</label>
					</div>
				</fieldset>

				<div id="nowcrm-template-blocks-pane" data-workflow-mode-pane="blocks" <?php echo Campaigns::MODE_HTML === $view['mode'] ? 'hidden' : ''; ?>>
					<h2><?php esc_html_e( '正文（Gutenberg 區塊）', 'now-crm' ); ?></h2>
					<p class="description"><?php esc_html_e( '正文直接在這個頁面使用 WordPress 原生 Gutenberg 編輯器；切換模式不會丟棄未儲存的區塊。', 'now-crm' ); ?></p>
					<textarea id="nowcrm-template-blocks-source" name="blocks" data-workflow-blocks hidden><?php echo esc_textarea( $view['blocks'] ); ?></textarea>
					<iframe
						class="nowcrm-template-frame"
						data-workflow-frame
						src="<?php echo esc_url( $embed_url ); ?>"
						title="<?php esc_attr_e( 'Gutenberg 內容編輯器', 'now-crm' ); ?>"
					></iframe>
				</div>

				<div id="nowcrm-template-html-pane" data-workflow-mode-pane="html" <?php echo Campaigns::MODE_HTML === $view['mode'] ? '' : 'hidden'; ?>>
					<label class="nowcrm-workspace__field" for="nowcrm-template-html">
						<span><?php esc_html_e( '正文（Raw HTML）', 'now-crm' ); ?></span>
						<textarea id="nowcrm-template-html" name="html" rows="20" class="large-text code" data-workflow-field="content"><?php echo esc_textarea( $view['html'] ); ?></textarea>
					</label>
					<p class="description"><?php esc_html_e( '儲存時會移除 script、iframe、表單與事件屬性；主旨與範本名稱不影響此段 HTML。', 'now-crm' ); ?></p>
				</div>

				<fieldset class="nowcrm-mode-fieldset">
					<legend><?php esc_html_e( '狀態', 'now-crm' ); ?></legend>
					<div class="nowcrm-mode-cards">
						<label class="nowcrm-mode-card">
							<input type="radio" name="status" value="<?php echo esc_attr( TemplateRepository::STATUS_DRAFT ); ?>" <?php checked( $view['status'], TemplateRepository::STATUS_DRAFT ); ?>>
							<span><?php esc_html_e( '草稿', 'now-crm' ); ?></span>
						</label>
						<label class="nowcrm-mode-card">
							<input type="radio" name="status" value="<?php echo esc_attr( TemplateRepository::STATUS_PUBLISH ); ?>" <?php checked( $view['status'], TemplateRepository::STATUS_PUBLISH ); ?>>
							<span><?php esc_html_e( '已發佈', 'now-crm' ); ?></span>
						</label>
					</div>
					<p class="description"><?php esc_html_e( '只有已發佈的範本會出現在新增電子報的範本選單。', 'now-crm' ); ?></p>
				</fieldset>

				<?php WorkflowEmail::render_tools( 'template' ); ?>

				<p class="nowcrm-workspace__footer">
					<button type="submit" class="button button-primary"><?php esc_html_e( '儲存範本', 'now-crm' ); ?></button>
				</p>
			</form>

			<section class="nowcrm-panel" aria-labelledby="nowcrm-template-more">
				<h2 id="nowcrm-template-more"><?php esc_html_e( '更多操作', 'now-crm' ); ?></h2>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="nowcrm-inline-form">
					<input type="hidden" name="action" value="nowcrm_duplicate_template">
					<input type="hidden" name="template_id" value="<?php echo esc_attr( (string) $template['id'] ); ?>">
					<?php wp_nonce_field( 'nowcrm_duplicate_template' ); ?>
					<button type="submit" class="button"><?php esc_html_e( '複製範本', 'now-crm' ); ?></button>
				</form>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="nowcrm-inline-form">
					<input type="hidden" name="action" value="nowcrm_delete_template">
					<input type="hidden" name="template_id" value="<?php echo esc_attr( (string) $template['id'] ); ?>">
					<?php wp_nonce_field( 'nowcrm_delete_template' ); ?>
					<button type="submit" class="button button-link-delete" onclick="return window.confirm('<?php echo esc_js( __( '永久刪除此範本？此操作無法復原。', 'now-crm' ) ); ?>');"><?php esc_html_e( '永久刪除', 'now-crm' ); ?></button>
				</form>
			</section>
		</div>
		<?php
	}

	/**
	 * Save one template.
	 */
	public static function save_template(): void {
		if ( ! Capabilities::can_manage_contacts() ) {
			wp_die( esc_html__( '您沒有權限執行此操作。', 'now-crm' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( self::ACTION_SAVE );

		$id     = absint( isset( $_POST['template_id'] ) && is_scalar( $_POST['template_id'] ) ? wp_unslash( (string) $_POST['template_id'] ) : '' );
		$status = isset( $_POST['status'] ) && is_string( $_POST['status'] )
			? sanitize_key( wp_unslash( $_POST['status'] ) )
			: TemplateRepository::STATUS_DRAFT;

		$repo   = new TemplateRepository();
		$result = $repo->update(
			$id,
			array(
				'name'      => self::post_string( 'name' ),
				'subject'   => self::post_string( 'subject' ),
				'preheader' => self::post_string( 'preheader' ),
				'mode'      => self::post_string( 'mode' ),
				'blocks'    => self::post_string( 'blocks' ),
				'html'      => self::post_string( 'html' ),
				'status'    => $status,
			)
		);

		if ( is_wp_error( $result ) ) {
			$code = str_replace( 'nowcrm_template_', '', (string) $result->get_error_code() );

			if ( ! in_array( $code, array( 'not_found', 'forbidden' ), true ) ) {
				set_transient(
					self::draft_key( $id ),
					array(
						'name'      => self::post_string( 'name' ),
						'subject'   => self::post_string( 'subject' ),
						'preheader' => self::post_string( 'preheader' ),
						'mode'      => self::post_string( 'mode' ),
						'blocks'    => self::post_string( 'blocks' ),
						'html'      => self::post_string( 'html' ),
						'status'    => $status,
					),
					15 * MINUTE_IN_SECONDS
				);
			}

			wp_safe_redirect(
				Menu::page_url(
					Menu::TEMPLATES_SLUG,
					array(
						'template'      => $id,
						'nowcrm_notice' => in_array( $code, array( 'invalid_name', 'invalid_mode', 'unsupported_block', 'not_found', 'forbidden', 'db_error' ), true ) ? $code : 'db_error',
						'nowcrm_draft'  => '1',
					)
				)
			);
			exit;
		}

		delete_transient( self::draft_key( $id ) );

		wp_safe_redirect(
			Menu::page_url(
				Menu::TEMPLATES_SLUG,
				array(
					'template'      => $id,
					'nowcrm_notice' => 'saved',
				)
			)
		);
		exit;
	}

	/**
	 * Unsaved values kept by a failed save, for this user and template only.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function draft_for_editor( int $id ): ?array {
		$draft = get_transient( self::draft_key( $id ) );

		return is_array( $draft ) ? $draft : null;
	}

	private static function draft_key( int $id ): string {
		return 'nowcrm_template_draft_' . get_current_user_id() . '_' . $id;
	}

	private static function post_string( string $key ): string {
		return isset( $_POST[ $key ] ) && is_string( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : '';
	}
}
