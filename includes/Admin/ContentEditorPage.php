<?php
/**
 * Full native block editor for campaign and template content.
 *
 * @package NowCRM
 */

namespace NowCRM\Admin;

use NowCRM\Automation\AutomationRepository;
use NowCRM\Campaign\Campaigns;
use NowCRM\Editor\DummyPost;
use NowCRM\Email\DesignTokens;
use NowCRM\Email\EmailDesign;
use NowCRM\Sequences\SequenceRepository;
use NowCRM\Support\Capabilities;
use NowCRM\Templates\TemplateRepository;

defined( 'ABSPATH' ) || exit;

final class ContentEditorPage {

	public const SLUG = 'nowcrm-content-editor';

	public static function url( string $type, int $id, bool $embed = false, bool $draft = false ): string {
		$args = array(
			'target' => $type,
			'id'     => $id,
		);

		if ( $embed ) {
			$args['embed'] = 1;
		}

		if ( $draft ) {
			$args['draft'] = 1;
		}

		return Menu::page_url( self::SLUG, $args );
	}

	/**
	 * Add the full-screen body class only on this screen.
	 *
	 * @param string $classes Existing body classes.
	 */
	public static function body_class( string $classes ): string {
		if ( self::SLUG !== Menu::query( 'page' ) ) {
			return $classes;
		}

		$classes .= ' nowcrm-content-editor';

		if ( '1' === Menu::query( 'embed' ) ) {
			$classes .= ' nowcrm-content-editor--embed';
		}

		return $classes;
	}

	public static function render(): void {
		if ( ! Capabilities::can_manage_contacts() ) {
			wp_die( esc_html__( '沒有權限。', 'now-crm' ), '', array( 'response' => 403 ) );
		}

		$type  = Menu::query( 'target' );
		$id    = absint( Menu::query( 'id' ) );
		$embed = '1' === Menu::query( 'embed' );

		$target = 'template' === $type
			? self::template( $id )
			: ( 'sequence' === $type
				? self::sequence( $id )
				: ( 'automation' === $type ? self::automation( $id ) : self::campaign( $id ) ) );
		if ( is_wp_error( $target ) ) {
			wp_die( esc_html( $target->get_error_message() ), '', array( 'response' => 403 ) );
		}

		// Campaign content is edited inside the campaign workspace. Only the
		// embedded frame keeps this hidden screen; a direct URL returns to the
		// workspace. Templates keep their standalone editor. Sequences and
		// automations always use the embedded frame inside their own editor.
		if ( ! $embed && 'campaign' === $type ) {
			wp_safe_redirect( CampaignPage::url( $id ) );
			exit;
		}

		if ( ! $embed && in_array( $type, array( 'sequence', 'automation' ), true ) ) {
			wp_safe_redirect( $target['return_url'] );
			exit;
		}

		// The native editor is only used for block content. Raw HTML keeps the
		// textarea surface in the workspace, so no working post is created. A
		// sequence canvas is always the block editor; the page selects the mode.
		if ( 'sequence' !== $type && Campaigns::MODE_HTML === $target['mode'] ) {
			wp_safe_redirect( $target['return_url'] );
			exit;
		}

		$draft_blocks = null;
		if ( 'template' === $type && '1' === Menu::query( 'draft' ) ) {
			$draft = TemplatePage::draft_for_editor( $id );
			if ( is_array( $draft ) && isset( $draft['blocks'] ) && is_string( $draft['blocks'] ) ) {
				$draft_blocks = $draft['blocks'];
			}
		}

		$dummy_id = DummyPost::ensure( $type, $id, $draft_blocks );
		if ( is_wp_error( $dummy_id ) ) {
			wp_die( esc_html( $dummy_id->get_error_message() ), '', array( 'response' => 500 ) );
		}

		$post     = get_post( $dummy_id );
		$context  = new \WP_Block_Editor_Context( array( 'post' => $post ) );
		$settings = get_block_editor_settings(
			array(
				'allowedBlockTypes' => Campaigns::BLOCKS,
				'autosaveInterval'  => AUTOSAVE_INTERVAL,
			),
			$context
		);

		$settings['styles'][] = array(
			'css'            => DesignTokens::editor_css(),
			'__unstableType' => 'theme',
			'isGlobalStyles' => false,
		);

		$design = EmailDesign::normalize( $target['design'] ?? array() );
		if ( 'campaign' === $type ) {
			// The email canvas keeps the theme content width so both sides stay
			// gray and the page scrolls from those margins. The rendered email
			// card itself stays 600px wide.
			$settings['styles'][] = array(
				'css'            => EmailDesign::canvas_css( $design ),
				'__unstableType' => 'theme',
				'isGlobalStyles' => false,
			);
		}

		wp_localize_script(
			'nowcrm-content-editor',
			'nowcrmContentEditor',
			array(
				'postType'   => DummyPost::POST_TYPE,
				'postId'     => (int) $dummy_id,
				'settings'   => $settings,
				'tokens'     => array(
					'fontSizes'    => DesignTokens::font_sizes(),
					'fontFamilies' => DesignTokens::font_families(),
					'spacingSizes' => DesignTokens::spacing_sizes(),
				),
				'initialEdits' => array(),
				'saveUrl'    => $target['save_url'],
				'returnUrl'  => $target['return_url'],
				'nonce'      => wp_create_nonce( 'wp_rest' ),
				'revision'   => $target['revision'],
				'title'      => $target['title'],
				'embed'      => $embed,
				'targetType' => $type,
				'campaignId' => 'campaign' === $type ? $id : 0,
				'design'     => $design,
				'smartcodes' => self::smartcodes(),
				'labels'     => array(
					'save'    => __( '儲存並返回', 'now-crm' ),
					'back'    => __( '返回', 'now-crm' ),
					'saving'  => __( '儲存中…', 'now-crm' ),
					'failed'  => __( '無法儲存內容，請再試一次。', 'now-crm' ),
					'changed' => __( '內容已在其他分頁變更。請重新整理後再編輯。', 'now-crm' ),
				),
			)
		);
		?>
		<style>
			html.wp-toolbar{padding-top:0!important}
			body.nowcrm-content-editor #wpcontent{padding-top:0!important}
			body.nowcrm-content-editor--embed #wpadminbar,
			body.nowcrm-content-editor--embed #adminmenumain,
			body.nowcrm-content-editor--embed #wpfooter,
			body.nowcrm-content-editor--embed #screen-meta-links,
			body.nowcrm-content-editor--embed #wpbody-content > .notice,
			body.nowcrm-content-editor--embed .editor-post-publish-button__button,
			body.nowcrm-content-editor--embed .editor-post-publish-panel__toggle,
			body.nowcrm-content-editor--embed .editor-post-save-draft,
			body.nowcrm-content-editor--embed .editor-layout__toggle-publish-panel,
			body.nowcrm-content-editor--embed .editor-post-preview{display:none!important}
			body.nowcrm-content-editor--embed #wpcontent{margin:0!important;padding:0!important}
			body.nowcrm-content-editor--embed #wpbody{padding-top:0!important}
			/* The dummy working post has no title, so the document bar must not show an empty one. */
			body.nowcrm-content-editor .editor-document-bar__post-title{display:none!important}
			/* An email canvas is never full width: keep gray margins so the page scrolls outside the iframe. */
			body.nowcrm-content-editor--embed .editor-visual-editor{background:#ddd}
			body.nowcrm-content-editor--embed .editor-visual-editor iframe{width:min(100%,680px)!important;margin-inline:auto;background:#fff}
			/* The fixed Gutenberg shell otherwise reserves space for the hidden WordPress menu and admin bar. */
			body.nowcrm-content-editor--embed .interface-interface-skeleton{inset:0}
			body.nowcrm-content-editor--embed .edit-post-layout__content{min-height:100vh}
			.nowcrm-panel-help{margin:0 0 12px;color:#50575e}
			.nowcrm-import-modal{width:min(760px,94vw)}
			.nowcrm-import-modal__source{box-sizing:border-box;width:100%;min-height:260px;padding:12px;border:1px solid #c3c4c7;border-radius:3px;font-family:"JetBrains Mono",Menlo,Consolas,monospace;font-size:13px;line-height:1.55;resize:vertical}
			.nowcrm-import-modal__note{margin:0 0 10px;color:#50575e}
			.nowcrm-import-modal__error{margin:10px 0 0;color:#b32d2e}
			.nowcrm-import-modal__actions{display:flex;justify-content:flex-end;gap:8px;margin-top:16px}
			.nowcrm-design-field{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:6px 10px;align-items:center;margin:0 0 10px}
			.nowcrm-design-field__control{display:flex;align-items:center;gap:8px}
			.nowcrm-design-field input[type=color]{width:36px;height:28px;padding:0;border:1px solid #c3c4c7;border-radius:3px;background:none}
			.nowcrm-design-field code{font-size:11px;color:#646970}
			.nowcrm-smartcodes__search{width:100%;margin:0 0 10px}
			.nowcrm-smartcodes__group{margin:0 0 12px}
			.nowcrm-smartcodes__group h3{margin:0 0 6px;font-size:12px;text-transform:uppercase;letter-spacing:.04em;color:#646970}
			.nowcrm-smartcodes__item{display:flex;align-items:center;justify-content:space-between;gap:8px;padding:4px 0}
			.nowcrm-smartcodes__item code{font-size:11px}
			.nowcrm-smartcodes__status{min-height:1.5em;margin:6px 0 0;color:#1e7b34}
		</style>
		<?php if ( ! $embed ) : ?>
			<div class="nowcrm-content-editor__bar">
				<a class="button" href="<?php echo esc_url( $target['return_url'] ); ?>"><?php esc_html_e( '返回', 'now-crm' ); ?></a>
				<strong><?php echo esc_html( $target['title'] ); ?></strong>
				<span class="nowcrm-content-editor__status" role="status" data-nowcrm-editor-status></span>
				<button type="button" class="button button-primary" data-nowcrm-editor-save><?php esc_html_e( '儲存並返回', 'now-crm' ); ?></button>
			</div>
		<?php endif; ?>
		<div id="editor" class="block-editor__container"></div>
		<?php
	}

	/**
	 * Enqueue the core editor and the small bridge script.
	 */
	public static function enqueue(): void {
		$type = Menu::query( 'target' );
		$id   = absint( Menu::query( 'id' ) );
		$target = 'template' === $type
			? self::template( $id )
			: ( 'sequence' === $type
				? self::sequence( $id )
				: ( 'automation' === $type ? self::automation( $id ) : self::campaign( $id ) ) );

		if ( is_wp_error( $target ) || ( 'sequence' !== $type && Campaigns::MODE_HTML === $target['mode'] ) ) {
			return;
		}

		wp_enqueue_media();
		wp_enqueue_editor();
		wp_enqueue_script( 'wp-edit-post' );
		wp_enqueue_style( 'wp-edit-post' );
		wp_enqueue_style( 'wp-block-library' );
		wp_enqueue_style( 'wp-edit-blocks' );
		wp_enqueue_style( 'wp-components' );

		$asset_file = NOWCRM_DIR . '/assets/build/content-editor.asset.php';
		$asset      = is_readable( $asset_file ) ? include $asset_file : array(
			'dependencies' => array( 'wp-api-fetch', 'wp-block-editor', 'wp-blocks', 'wp-components', 'wp-data', 'wp-edit-post', 'wp-element', 'wp-i18n', 'wp-plugins' ),
			'version'      => NOWCRM_VERSION,
		);

		wp_enqueue_script(
			'nowcrm-content-editor',
			plugins_url( 'assets/build/content-editor.js', NOWCRM_FILE ),
			array_unique( array_merge( $asset['dependencies'], array( 'wp-edit-post', 'wp-editor', 'wp-data' ) ) ),
			$asset['version'],
			true
		);
	}

	/**
	 * Campaign smartcodes that the renderer actually substitutes.
	 *
	 * Woo order and recovery codes are intentionally absent: campaign mail does
	 * not render them.
	 *
	 * @return array<int, array{label: string, items: array<int, array{token: string, label: string}>}>
	 */
	public static function smartcodes(): array {
		return array(
			array(
				'label' => __( '收件人', 'now-crm' ),
				'items' => array(
					array( 'token' => '{{first_name}}', 'label' => __( '名字', 'now-crm' ) ),
					array( 'token' => '{{last_name}}', 'label' => __( '姓氏', 'now-crm' ) ),
					array( 'token' => '{{full_name}}', 'label' => __( '全名', 'now-crm' ) ),
					array( 'token' => '{{email}}', 'label' => __( '電子郵件', 'now-crm' ) ),
				),
			),
			array(
				'label' => __( '網站', 'now-crm' ),
				'items' => array(
					array( 'token' => '{{site_name}}', 'label' => __( '網站名稱', 'now-crm' ) ),
					array( 'token' => '{{site_url}}', 'label' => __( '網站網址', 'now-crm' ) ),
				),
			),
			array(
				'label' => __( '訂閱管理', 'now-crm' ),
				'items' => array(
					array( 'token' => '{{unsubscribe_url}}', 'label' => __( '取消訂閱網址', 'now-crm' ) ),
				),
			),
		);
	}

	/**
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function campaign( int $id ) {
		if ( ! Campaigns::can_edit( $id ) ) {
			return Campaigns::error( 'forbidden', __( '無法編輯這封電子報。', 'now-crm' ), 403 );
		}
		if ( Campaigns::STATUS_DRAFT !== Campaigns::state( $id ) ) {
			return Campaigns::error( 'campaign_state', __( '只有草稿可以編輯內容。', 'now-crm' ), 409 );
		}

		$post  = Campaigns::get( $id );
		$draft = Campaigns::draft( $post );

		return array(
			'title'      => $post->post_title,
			'mode'       => $draft['mode'],
			'design'     => $draft['design'],
			'revision'   => Campaigns::revision( $draft ),
			'save_url'   => rest_url( 'nowcrm/v1/campaigns/' . $id . '/content' ),
			'return_url' => CampaignPage::url( $id ),
		);
	}

	/**
	 * Sequence canvas: the page owns every step, this frame only edits one.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function sequence( int $id ) {
		if ( ! Capabilities::can_manage_contacts() ) {
			return Campaigns::error( 'forbidden', __( '沒有權限。', 'now-crm' ), 403 );
		}

		$sequence = ( new SequenceRepository() )->get( $id );
		if ( ! $sequence ) {
			return Campaigns::error( 'not_found', __( '找不到序列。', 'now-crm' ), 404 );
		}

		return array(
			'title'      => (string) $sequence['name'],
			// The frame is always the block canvas; the sequence page decides
			// which step (and which mode) it shows.
			'mode'       => Campaigns::MODE_BLOCKS,
			'design'     => array(),
			'revision'   => '',
			'save_url'   => '',
			'return_url' => Menu::page_url( Menu::SEQUENCE_SLUG, array( 'id' => $id ) ),
		);
	}

	/**
	 * Automation canvas: the editor owns many email steps, this frame only
	 * edits one. The automation page seeds the canvas from the open step.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function automation( int $id ) {
		if ( ! Capabilities::can_manage_contacts() ) {
			return Campaigns::error( 'forbidden', __( '沒有權限。', 'now-crm' ), 403 );
		}

		$automation = ( new AutomationRepository() )->get( $id );
		if ( ! $automation ) {
			return Campaigns::error( 'not_found', __( '找不到自動化。', 'now-crm' ), 404 );
		}

		return array(
			'title'      => (string) $automation['name'],
			'mode'       => Campaigns::MODE_BLOCKS,
			'design'     => array(),
			'revision'   => '',
			'save_url'   => '',
			'return_url' => Menu::page_url( Menu::AUTOMATIONS_SLUG, array( 'id' => $id ) ),
		);
	}

	/**
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function template( int $id ) {
		$repo = new TemplateRepository();
		$template = $repo->get( $id );

		if ( ! $template || ! $repo->can_edit( $id ) ) {
			return Campaigns::error( 'forbidden', __( '無法編輯這個電子報範本。', 'now-crm' ), 403 );
		}

		return array(
			'title'      => (string) $template['name'],
			// The embedded frame is always the block canvas, even while the
			// parent form shows HTML. That keeps unsaved blocks alive across a
			// mode switch and a failed save.
			'mode'       => '1' === Menu::query( 'embed' ) ? Campaigns::MODE_BLOCKS : (string) $template['mode'],
			'revision'   => $repo->revision( $template ),
			'save_url'   => rest_url( 'nowcrm/v1/templates/' . $id . '/content' ),
			'return_url' => TemplatePage::url( $id ),
		);
	}
}
