<?php
/**
 * Shared email controls for templates, automation steps and sequence steps.
 *
 * A workflow email scope is one persisted owner (template, automation or
 * sequence) plus four fields: subject, preheader, mode and content. The tools
 * only copy template content into form fields, preview unsaved form values and
 * enqueue one queue test job. Applying a template never writes a live
 * reference, and previews never persist anything.
 *
 * @package NowCRM
 */

namespace NowCRM\Admin;

use NowCRM\Automation\AutomationRepository;
use NowCRM\Campaign\Campaigns;
use NowCRM\Email\EmailDispatcher;
use NowCRM\Email\EmailJobRepository;
use NowCRM\Email\EmailRenderer;
use NowCRM\Email\Queue;
use NowCRM\Sequences\SequenceRepository;
use NowCRM\Support\Capabilities;
use NowCRM\Support\Settings;
use NowCRM\Templates\TemplateRepository;
use WP_Error;

defined( 'ABSPATH' ) || exit;

final class WorkflowEmail {

	public const ACTION_APPLY    = 'nowcrm_workflow_email_apply';
	public const ACTION_PREVIEW  = 'nowcrm_workflow_email_preview';
	public const ACTION_TEST     = 'nowcrm_workflow_email_test';

	public const NONCE = 'nowcrm_workflow_email';
	public const HANDLE = 'nowcrm-workflow-email';

	private const SOURCES = array( 'template', 'automation', 'sequence' );

	/**
	 * Enqueue the shared footer script and stylesheet once per request.
	 */
	private static bool $assets = false;

	/**
	 * Published template options shared by every tools block on one page.
	 *
	 * @var array<int, array{id: int, name: string}>|null
	 */
	private static ?array $options = null;

	public static function register(): void {
		add_action( 'admin_post_' . self::ACTION_APPLY, array( __CLASS__, 'handle_apply' ) );
		add_action( 'admin_post_' . self::ACTION_PREVIEW, array( __CLASS__, 'handle_preview' ) );
		add_action( 'admin_post_' . self::ACTION_TEST, array( __CLASS__, 'handle_test' ) );
	}

	/**
	 * Render the template picker and the preview/test controls.
	 *
	 * The caller wraps this output in the surrounding [data-workflow-email]
	 * element that also carries the owner ID and the four field controls.
	 *
	 * @param string $source template, automation or sequence.
	 */
	public static function render_tools( string $source ): void {
		if ( ! Capabilities::can_manage_contacts() ) {
			return;
		}

		$source = in_array( $source, self::SOURCES, true ) ? $source : 'template';
		$templates = 'template' === $source ? array() : self::template_options();

		self::enqueue( $templates );
		?>
		<div class="nowcrm-workflow-tools" data-workflow-tools data-workflow-tools-source="<?php echo esc_attr( $source ); ?>">
			<?php if ( 'template' !== $source ) : ?>
				<div class="nowcrm-workflow-tools__picker">
					<label class="nowcrm-workflow-tools__select">
						<span><?php esc_html_e( '套用已發佈範本', 'now-crm' ); ?></span>
						<select data-workflow-template>
							<option value=""><?php esc_html_e( '選擇範本', 'now-crm' ); ?></option>
							<?php foreach ( $templates as $template ) : ?>
								<option value="<?php echo esc_attr( (string) $template['id'] ); ?>"><?php echo esc_html( $template['name'] ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>
					<button type="button" class="button" data-workflow-apply><?php esc_html_e( '套用', 'now-crm' ); ?></button>
					<?php if ( array() === $templates ) : ?>
						<span class="description"><?php esc_html_e( '尚無可用的已發佈範本。', 'now-crm' ); ?></span>
					<?php endif; ?>
				</div>
			<?php endif; ?>
			<div class="nowcrm-workflow-tools__actions">
				<button type="button" class="button" data-workflow-preview><?php esc_html_e( '預覽', 'now-crm' ); ?></button>
				<button type="button" class="button" data-workflow-test><?php esc_html_e( '寄送測試', 'now-crm' ); ?></button>
			</div>
			<p class="nowcrm-workflow-tools__status" role="status" aria-live="polite" data-workflow-status></p>
		</div>
		<?php
	}

	/**
	 * Apply one published template to the current unsaved form values.
	 */
	public static function handle_apply(): void {
		$request = self::request();
		self::respond( is_wp_error( $request ) ? $request : self::apply_payload( $request ) );
	}

	/**
	 * Preview the current unsaved form values with the normal renderer.
	 */
	public static function handle_preview(): void {
		$request = self::request();
		self::respond( is_wp_error( $request ) ? $request : self::preview_payload( $request ) );
	}

	/**
	 * Enqueue one test snapshot for the current user.
	 */
	public static function handle_test(): void {
		$request = self::request();
		self::respond( is_wp_error( $request ) ? $request : self::test_payload( $request ) );
	}

	/**
	 * Read and guard the shared form payload.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public static function request() {
		if ( ! Capabilities::can_manage_contacts() ) {
			return new WP_Error( 'nowcrm_workflow_forbidden', __( '您沒有權限執行此操作。', 'now-crm' ), array( 'status' => 403 ) );
		}

		if ( ! check_ajax_referer( self::NONCE, 'nonce', false ) ) {
			return new WP_Error( 'nowcrm_workflow_nonce', __( '工作階段已過期，請重新整理頁面。', 'now-crm' ), array( 'status' => 403 ) );
		}

		return array(
			'source'      => isset( $_POST['source'] ) && is_string( $_POST['source'] ) ? sanitize_key( wp_unslash( $_POST['source'] ) ) : '',
			'source_id'   => isset( $_POST['source_id'] ) && is_scalar( $_POST['source_id'] ) ? absint( wp_unslash( (string) $_POST['source_id'] ) ) : 0,
			'template_id' => isset( $_POST['template_id'] ) && is_scalar( $_POST['template_id'] ) ? absint( wp_unslash( (string) $_POST['template_id'] ) ) : 0,
			'subject'     => isset( $_POST['subject'] ) && is_string( $_POST['subject'] ) ? sanitize_text_field( wp_unslash( $_POST['subject'] ) ) : '',
			'preheader'   => isset( $_POST['preheader'] ) && is_string( $_POST['preheader'] ) ? sanitize_text_field( wp_unslash( $_POST['preheader'] ) ) : '',
			'mode'        => isset( $_POST['mode'] ) && is_string( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : '',
			'content'     => isset( $_POST['content'] ) && is_string( $_POST['content'] ) ? (string) wp_unslash( $_POST['content'] ) : '',
			'confirm'     => isset( $_POST['confirm'] ) && is_scalar( $_POST['confirm'] ) ? sanitize_key( wp_unslash( (string) $_POST['confirm'] ) ) : '',
		);
	}

	/**
	 * Copy one published template into the fields. This never persists.
	 *
	 * @param array<string, mixed> $input Shared payload.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function apply_payload( array $input ) {
		$source = (string) ( $input['source'] ?? '' );
		$owner  = self::locate( $source, (int) ( $input['source_id'] ?? 0 ) );
		if ( is_wp_error( $owner ) ) {
			return $owner;
		}

		if ( ! in_array( $source, array( 'automation', 'sequence' ), true ) ) {
			return self::error( 'invalid_source', __( '只有自動化或序列步驟可以套用範本。', 'now-crm' ) );
		}

		$repo     = new TemplateRepository();
		$template = $repo->get( (int) ( $input['template_id'] ?? 0 ) );

		if ( ! $template || TemplateRepository::STATUS_PUBLISH !== $template['status'] ) {
			return self::error( 'not_found', __( '找不到已發佈的電子報範本。', 'now-crm' ), 404 );
		}

		if ( ! $repo->can_edit( (int) $template['id'] ) ) {
			return self::error( 'forbidden', __( '您沒有使用此電子報範本的權限。', 'now-crm' ), 403 );
		}

		$mode = Campaigns::MODE_BLOCKS === $template['mode'] ? Campaigns::MODE_BLOCKS : Campaigns::MODE_HTML;

		return array(
			'id'        => (int) $template['id'],
			'name'      => (string) $template['name'],
			'subject'   => (string) $template['subject'],
			'preheader' => (string) $template['preheader'],
			'mode'      => $mode,
			'content'   => Campaigns::MODE_BLOCKS === $mode ? (string) $template['blocks'] : (string) $template['html'],
		);
	}

	/**
	 * Render the unsaved fields without touching the source or the settings.
	 *
	 * @param array<string, mixed> $input Shared payload.
	 * @return array<string, string>|WP_Error
	 */
	public static function preview_payload( array $input ) {
		$source = self::locate( (string) ( $input['source'] ?? '' ), (int) ( $input['source_id'] ?? 0 ) );
		if ( is_wp_error( $source ) ) {
			return $source;
		}

		$mode = self::mode( (string) ( $input['mode'] ?? '' ) );
		if ( is_wp_error( $mode ) ) {
			return $mode;
		}

		$content = (string) ( $input['content'] ?? '' );
		if ( Campaigns::MODE_BLOCKS === $mode ) {
			$content = self::validate_blocks( $content );
			if ( is_wp_error( $content ) ) {
				return $content;
			}
		}

		$user     = wp_get_current_user();
		$template = array(
			'mode'      => $mode,
			'content'   => $content,
			'subject'   => (string) ( $input['subject'] ?? '' ),
			'preheader' => (string) ( $input['preheader'] ?? '' ),
		);
		$rendered = ( new EmailRenderer() )->render(
			$template,
			array(
				'first_name'      => $user->first_name ?: __( '示範', 'now-crm' ),
				'last_name'       => $user->last_name,
				'email'           => $user->user_email,
				'site_name'       => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
				'site_url'        => home_url( '/' ),
				'unsubscribe_url' => '#unsubscribe',
			)
		);

		$csp = '<meta http-equiv="Content-Security-Policy" content="default-src \'none\'; img-src https: http: data:; style-src \'unsafe-inline\'; base-uri \'none\'; form-action \'none\'">';

		return array(
			'subject'   => $rendered['subject'],
			'preheader' => (string) ( $input['preheader'] ?? '' ),
			'html'      => (string) preg_replace( '/<head>/i', '<head>' . $csp, $rendered['html'], 1 ),
		);
	}

	/**
	 * Enqueue one test snapshot for the current user.
	 *
	 * The recipient is always the logged-in user; the posted content is frozen
	 * into the job payload and the existing Queue still applies suppression.
	 *
	 * @param array<string, mixed> $input Shared payload.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function test_payload( array $input ) {
		$source = self::locate( (string) ( $input['source'] ?? '' ), (int) ( $input['source_id'] ?? 0 ) );
		if ( is_wp_error( $source ) ) {
			return $source;
		}

		if ( '1' !== (string) ( $input['confirm'] ?? '' ) ) {
			return self::error( 'confirmation_required', __( '請先確認要寄送測試信。', 'now-crm' ) );
		}

		$mode = self::mode( (string) ( $input['mode'] ?? '' ) );
		if ( is_wp_error( $mode ) ) {
			return $mode;
		}

		$user  = wp_get_current_user();
		$email = sanitize_email( (string) $user->user_email );

		if ( ! is_email( $email ) ) {
			return self::error( 'invalid_recipient', __( '目前帳號沒有有效的電子郵件地址。', 'now-crm' ) );
		}

		if ( ! Settings::bool( 'email_sending_enabled' ) ) {
			return self::error( 'sending_disabled', __( '郵件寄送已關閉。請先到 NOW CRM 設定開啟寄送。', 'now-crm' ) );
		}

		if ( '' === trim( Settings::string( 'email_from_address' ) ) ) {
			return self::error( 'sender_missing', __( '尚未設定寄件者 Email。請先到 NOW CRM 設定填寫寄件者。', 'now-crm' ) );
		}

		// The development fixture may only capture mail. Never let a local
		// install hand a test job to the real wp_mail transport by accident.
		if ( 'local' === wp_get_environment_type() && 'capture' !== Settings::string( 'email_transport' ) ) {
			return self::error( 'local_transport', __( '本機環境的測試寄送只允許 capture 傳輸。請先切換寄送方式。', 'now-crm' ) );
		}

		$renderer  = new EmailRenderer();
		$content   = (string) ( $input['content'] ?? '' );
		$subject   = sanitize_text_field( (string) ( $input['subject'] ?? '' ) );
		$preheader = sanitize_text_field( (string) ( $input['preheader'] ?? '' ) );

		if ( Campaigns::MODE_BLOCKS === $mode ) {
			$content = self::validate_blocks( $content );
			if ( is_wp_error( $content ) ) {
				return $content;
			}
			$check = $renderer->render_blocks( $content );
		} else {
			$content = $renderer->sanitize_raw_html( $content );
			$check   = $content;
		}

		if ( '' === trim( $subject ) || ! self::has_content( $check ) ) {
			return self::error( 'invalid_test', __( '請填寫主旨和正文。', 'now-crm' ) );
		}

		$sender = EmailDispatcher::sender( array() );

		$job = ( new EmailJobRepository() )->enqueue(
			array(
				'job_key'    => 'test:' . wp_generate_uuid4(),
				'kind'       => 'test',
				'recipient'  => $email,
				'subject'    => '[測試] ' . $subject,
				'payload'    => array(
					'template' => array(
						'mode'         => Campaigns::MODE_BLOCKS === $mode ? Campaigns::MODE_BLOCKS : Campaigns::MODE_HTML,
						'content'      => $content,
						'subject'      => '[測試] ' . $subject,
						'preheader'    => $preheader,
						'from_name'    => $sender['from_name'],
						'from_address' => $sender['from_address'],
						'reply_to'     => $sender['reply_to'],
						'footer_text'  => '',
					),
				),
				'priority'   => 1,
			)
		);

		if ( is_wp_error( $job ) ) {
			return $job;
		}

		Queue::schedule();

		return array(
			'job_id'    => (int) $job,
			'recipient' => $email,
			'subject'   => '[測試] ' . $subject,
		);
	}

	/**
	 * Resolve the persisted owner. Templates also require the template ACL.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public static function locate( string $type, int $id ) {
		if ( $id <= 0 ) {
			return self::error( 'invalid_source', __( '找不到要使用的內容來源。', 'now-crm' ), 404 );
		}

		if ( 'template' === $type ) {
			$repo     = new TemplateRepository();
			$template = $repo->get( $id );

			if ( ! $template ) {
				return self::error( 'not_found', __( '找不到電子報範本。', 'now-crm' ), 404 );
			}

			if ( ! $repo->can_edit( $id ) ) {
				return self::error( 'forbidden', __( '您沒有編輯此電子報範本的權限。', 'now-crm' ), 403 );
			}

			return $template;
		}

		if ( 'automation' === $type ) {
			$automation = ( new AutomationRepository() )->get( $id );

			return $automation ? $automation : self::error( 'not_found', __( '找不到自動化。', 'now-crm' ), 404 );
		}

		if ( 'sequence' === $type ) {
			$sequence = ( new SequenceRepository() )->get( $id );

			return $sequence ? $sequence : self::error( 'not_found', __( '找不到序列。', 'now-crm' ), 404 );
		}

		return self::error( 'invalid_source', __( '不支援的內容來源。', 'now-crm' ) );
	}

	/**
	 * Validate unsaved blocks with the same allow list as persisted content.
	 *
	 * @return string|WP_Error
	 */
	private static function validate_blocks( string $content ) {
		return self::blocks_allowed( parse_blocks( $content ) )
			? $content
			: self::error( 'unsupported_block', __( '正文內含不支援電子報的區塊。', 'now-crm' ) );
	}

	/**
	 * @param array<int, array<string, mixed>> $blocks Parsed blocks.
	 */
	private static function blocks_allowed( array $blocks ): bool {
		foreach ( $blocks as $block ) {
			if ( ( $block['blockName'] && ! in_array( $block['blockName'], Campaigns::BLOCKS, true ) ) ||
				! self::blocks_allowed( $block['innerBlocks'] ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * @return string|WP_Error
	 */
	private static function mode( string $mode ) {
		return in_array( $mode, array( Campaigns::MODE_BLOCKS, Campaigns::MODE_HTML ), true )
			? $mode
			: self::error( 'invalid_mode', __( '請選擇 Gutenberg 或 Raw HTML 編輯方式。', 'now-crm' ) );
	}

	private static function has_content( string $html ): bool {
		return 1 === preg_match( '/[^\s\p{Z}]/u', html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) ||
			1 === preg_match( '/<img\b[^>]+\bsrc\s*=/i', $html );
	}

	/**
	 * Published templates the current user may use, shared per page.
	 *
	 * @return array<int, array{id: int, name: string}>
	 */
	private static function template_options(): array {
		if ( null !== self::$options ) {
			return self::$options;
		}

		$repo    = new TemplateRepository();
		$options = array();

		foreach ( $repo->published() as $template ) {
			if ( ! $repo->can_edit( (int) $template['id'] ) ) {
				continue;
			}

			$options[] = array(
				'id'   => (int) $template['id'],
				'name' => (string) $template['name'],
			);
		}

		self::$options = $options;

		return self::$options;
	}

	/**
	 * @param array<int, array{id: int, name: string}> $templates Template options.
	 */
	private static function enqueue( array $templates ): void {
		if ( self::$assets ) {
			return;
		}

		self::$assets = true;

		$style = NOWCRM_DIR . '/assets/css/workflow-email.css';
		$script = NOWCRM_DIR . '/assets/js/workflow-email.js';

		wp_enqueue_style(
			self::HANDLE,
			plugins_url( 'assets/css/workflow-email.css', NOWCRM_FILE ),
			array( 'nowcrm-admin' ),
			is_readable( $style ) ? (string) filemtime( $style ) : NOWCRM_VERSION
		);

		wp_enqueue_script(
			self::HANDLE,
			plugins_url( 'assets/js/workflow-email.js', NOWCRM_FILE ),
			array(),
			is_readable( $script ) ? (string) filemtime( $script ) : NOWCRM_VERSION,
			true
		);

		$user = wp_get_current_user();

		wp_localize_script(
			self::HANDLE,
			'nowcrmWorkflowEmail',
			array(
				'endpoint'   => admin_url( 'admin-post.php' ),
				'applyAction' => self::ACTION_APPLY,
				'previewAction' => self::ACTION_PREVIEW,
				'testAction' => self::ACTION_TEST,
				'nonce'      => wp_create_nonce( self::NONCE ),
				'email'      => sanitize_email( (string) $user->user_email ),
				'templates'  => $templates,
				'labels'     => array(
					'choose'    => __( '請先選擇範本。', 'now-crm' ),
					'overwrite' => __( '套用範本會覆寫目前的主旨、預覽文字與內容。要繼續嗎？', 'now-crm' ),
					'applied'   => __( '已套用範本。儲存後才會寫入這個項目。', 'now-crm' ),
					'preview'   => __( '郵件預覽', 'now-crm' ),
					'close'     => __( '關閉', 'now-crm' ),
					/* translators: %s: test recipient email address. */
					'test'      => __( '寄送測試信到 %s？', 'now-crm' ),
					'queued'    => __( '已排入測試寄送佇列。', 'now-crm' ),
					'loading'   => __( '處理中…', 'now-crm' ),
					'failed'    => __( '無法完成操作，請再試一次。', 'now-crm' ),
				),
			)
		);
	}

	/**
	 * @param array<string, mixed>|WP_Error $result Handler result.
	 */
	private static function respond( $result ): void {
		if ( is_wp_error( $result ) ) {
			self::fail( $result );
			return;
		}

		wp_send_json_success( $result );
	}

	private static function fail( WP_Error $error ): void {
		$data = $error->get_error_data();
		$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 422;

		wp_send_json_error(
			array(
				'code'    => (string) $error->get_error_code(),
				'message' => $error->get_error_message(),
			),
			$status
		);
	}

	private static function error( string $code, string $message, int $status = 422 ): WP_Error {
		return new WP_Error( 'nowcrm_workflow_' . $code, $message, array( 'status' => $status ) );
	}
}
