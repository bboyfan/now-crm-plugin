<?php
/**
 * Shared workflow email tools: gates, copy semantics, preview and test queue.
 *
 * @package NowCRM
 */

use NowCRM\Admin\ContentEditorPage;
use NowCRM\Admin\WorkflowEmail;
use NowCRM\Campaign\Campaigns;
use NowCRM\Email\EmailDispatcher;
use NowCRM\Email\EmailJobRepository;
use NowCRM\Email\Queue;
use NowCRM\Support\Settings;
use NowCRM\Templates\TemplateRepository;

/**
 * Sentinel thrown by the redirect filter so a handler never reaches exit().
 */
final class NowCrm_Workflow_Redirect_Captured extends \RuntimeException {}

/**
 * @covers \NowCRM\Admin\WorkflowEmail
 * @covers \NowCRM\Admin\TemplatePage
 * @covers \NowCRM\Admin\ContentEditorPage
 */
final class WorkflowEmailTest extends NowCrmTestCase {

	private TemplateRepository $templates;
	private string $owner_email = 'owner@example.test';

	public function set_up(): void {
		parent::set_up();

		wp_set_current_user(
			self::factory()->user->create(
				array(
					'role'       => 'administrator',
					'user_email' => $this->owner_email,
				)
			)
		);

		$this->templates = new TemplateRepository();
		$this->set_settings(
			array(
				'email_sending_enabled'     => true,
				'email_transport'           => 'capture',
				'email_from_address'        => 'sender@example.test',
				'email_throttle_per_second' => 100,
			)
		);
	}

	public function tear_down(): void {
		$_POST    = array();
		$_REQUEST = array();
		$_GET     = array();
		delete_option( EmailDispatcher::CAPTURE_OPTION );
		delete_option( 'nowcrm_captured_mail' );

		parent::tear_down();
	}

	public function test_tools_require_manage_capability_and_nonce(): void {
		$id = $this->make_template();

		$_POST    = array(
			'source'    => 'template',
			'source_id' => (string) $id,
			'mode'      => Campaigns::MODE_BLOCKS,
			'content'   => '<!-- wp:paragraph --><p>Guard</p><!-- /wp:paragraph -->',
			'nonce'     => wp_create_nonce( WorkflowEmail::NONCE ),
		);
		$_REQUEST = $_POST;

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$request = WorkflowEmail::request();
		$this->assertWPError( $request );
		$this->assertSame( 403, (int) ( ( (array) $request->get_error_data() )['status'] ?? 0 ) );

		$denial = $this->capture_json_denial( static fn() => WorkflowEmail::handle_preview() );
		$this->assertSame( 'nowcrm_workflow_forbidden', $denial['code'] ?? '' );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$_POST    = array(
			'source'    => 'template',
			'source_id' => (string) $id,
			'mode'      => Campaigns::MODE_BLOCKS,
			'content'   => '<!-- wp:paragraph --><p>Guard</p><!-- /wp:paragraph -->',
		);
		$_REQUEST = $_POST;

		$request = WorkflowEmail::request();
		$this->assertWPError( $request );
		$this->assertSame( 403, (int) ( ( (array) $request->get_error_data() )['status'] ?? 0 ) );

		$denial = $this->capture_json_denial( static fn() => WorkflowEmail::handle_preview() );
		$this->assertSame( 'nowcrm_workflow_nonce', $denial['code'] ?? '' );

		$result = WorkflowEmail::locate( 'template', $id );
		$this->assertIsArray( $result, 'The owner must pass the template edit ACL.' );
		$this->assertWPError( WorkflowEmail::locate( 'template', $this->make_campaign() ), 'A campaign is not a template source.' );
		$this->assertWPError( WorkflowEmail::locate( 'automation', 999999 ), 'A missing automation must be rejected.' );

		$_POST    = array(
			'source'      => 'template',
			'source_id'   => (string) $id,
			'template_id' => '0',
			'nonce'       => wp_create_nonce( WorkflowEmail::NONCE ),
		);
		$_REQUEST = $_POST;
		$request  = WorkflowEmail::request();
		$this->assertIsArray( $request );
		$this->assertSame( 'template', $request['source'] );
	}

	public function test_apply_copies_the_selected_mode_and_never_persists(): void {
		$blocks = $this->make_template(
			array(
				'subject'   => 'Block subject',
				'preheader' => 'Block preview',
				'mode'      => Campaigns::MODE_BLOCKS,
				'blocks'    => '<!-- wp:paragraph --><p>Block body</p><!-- /wp:paragraph -->',
			)
		);
		$raw = $this->make_template(
			array(
				'subject'   => 'HTML subject',
				'mode'      => Campaigns::MODE_HTML,
				'blocks'    => '<!-- wp:paragraph --><p>Hidden block</p><!-- /wp:paragraph -->',
				'html'      => '<p>Raw body</p>',
			)
		);
		$draft = $this->make_template(
			array(
				'status' => TemplateRepository::STATUS_DRAFT,
			)
		);

		$automation = $this->make_automation();

		$copied = WorkflowEmail::apply_payload(
			array(
				'source'      => 'automation',
				'source_id'   => $automation,
				'template_id' => $blocks,
			)
		);
		$this->assertSame( Campaigns::MODE_BLOCKS, $copied['mode'] );
		$this->assertSame( 'Block subject', $copied['subject'] );
		$this->assertSame( 'Block preview', $copied['preheader'] );
		$this->assertSame( '<!-- wp:paragraph --><p>Block body</p><!-- /wp:paragraph -->', $copied['content'] );

		$copied = WorkflowEmail::apply_payload(
			array(
				'source'      => 'automation',
				'source_id'   => $automation,
				'template_id' => $raw,
			)
		);
		$this->assertSame( Campaigns::MODE_HTML, $copied['mode'] );
		$this->assertSame( '<p>Raw body</p>', $copied['content'], 'HTML mode copies the HTML body, not the block source.' );

		$this->assertWPError(
			WorkflowEmail::apply_payload(
				array(
					'source'      => 'automation',
					'source_id'   => $automation,
					'template_id' => $draft,
				)
			),
			'Only published templates can be applied.'
		);

		$this->assertSame( 'Block subject', $this->templates->get( $blocks )['subject'], 'Apply must not change the template.' );
	}

	public function test_preview_uses_unsaved_fields_in_both_modes(): void {
		$id = $this->make_template( array( 'blocks' => '<!-- wp:paragraph --><p>Stored</p><!-- /wp:paragraph -->' ) );

		$blocks = WorkflowEmail::preview_payload(
			array(
				'source'    => 'template',
				'source_id' => $id,
				'subject'   => 'Unsaved subject',
				'preheader' => 'Unsaved preview',
				'mode'      => Campaigns::MODE_BLOCKS,
				'content'   => '<!-- wp:paragraph --><p>Unsaved block {{first_name}}</p><!-- /wp:paragraph -->',
			)
		);

		$this->assertSame( 'Unsaved subject', $blocks['subject'] );
		$this->assertStringContainsString( 'Unsaved block', $blocks['html'] );
		$this->assertStringContainsString( 'nowcrm-card', $blocks['html'], 'Preview must use the normal renderer.' );
		$this->assertStringContainsString( 'Unsaved preview', $blocks['html'], 'Preview must include the preheader.' );
		$this->assertStringContainsString( 'Content-Security-Policy', $blocks['html'] );

		$html = WorkflowEmail::preview_payload(
			array(
				'source'    => 'template',
				'source_id' => $id,
				'subject'   => 'Raw unsaved',
				'mode'      => Campaigns::MODE_HTML,
				'content'   => '<p>Raw body</p><script>alert(1)</script>',
			)
		);
		$this->assertStringContainsString( 'Raw body', $html['html'] );
		$this->assertStringNotContainsString( '<script', $html['html'], 'Preview must sanitize raw HTML.' );
		$this->assertSame( '<!-- wp:paragraph --><p>Stored</p><!-- /wp:paragraph -->', $this->templates->get( $id )['blocks'], 'Preview must not save.' );
	}

	public function test_test_send_enqueues_one_snapshot_for_the_current_user_only(): void {
		$id = $this->make_template(
			array(
				'subject' => 'Stored subject',
				'mode'    => Campaigns::MODE_HTML,
				'html'    => '<p>Stored body</p>',
			)
		);

		$result = WorkflowEmail::test_payload(
			array(
				'source'    => 'template',
				'source_id' => $id,
				'subject'   => 'Unsaved test',
				'preheader' => 'Preview',
				'mode'      => Campaigns::MODE_HTML,
				'content'   => '<p>Unsaved test body</p>',
				'confirm'   => '1',
				'email'     => 'attacker@example.test',
			)
		);

		$this->assertSame( $this->owner_email, $result['recipient'], 'The posted recipient must be ignored.' );
		$this->assertSame( '[測試] Unsaved test', $result['subject'] );

		$jobs  = new EmailJobRepository();
		$job   = $jobs->get( (int) $result['job_id'] );
		$this->assertSame( 'test', $job['kind'] );
		$this->assertSame( 0, $job['campaign_id'], 'A template test must not invent a campaign.' );
		$this->assertSame( $this->owner_email, $job['recipient'] );
		$this->assertSame( '<p>Unsaved test body</p>', $job['payload']['template']['content'], 'The job freezes the unsaved content.' );

		( new Queue() )->run();

		$job = $jobs->get( (int) $result['job_id'] );
		$this->assertSame( EmailJobRepository::STATUS_ACCEPTED, $job['status'] );

		$log = get_option( EmailDispatcher::CAPTURE_OPTION, array() );
		$this->assertCount( 1, $log );
		$this->assertSame( $this->owner_email, $log[0]['to'] );
		$this->assertSame( '[測試] Unsaved test', $log[0]['subject'] );
		$this->assertStringContainsString( 'Unsaved test body', $log[0]['html'] );
		$this->assertSame( 0, $this->table_count( 'automation_runs' ), 'A test must not create an automation run.' );
		$this->assertSame( 0, $this->table_count( 'sequence_trackers' ), 'A test must not create a sequence tracker.' );

		( new \NowCRM\Email\SuppressionRepository() )->add( $this->owner_email, 'complaint', 'test' );
		$blocked = WorkflowEmail::test_payload(
			array(
				'source'    => 'template',
				'source_id' => $id,
				'subject'   => 'Suppressed test',
				'mode'      => Campaigns::MODE_HTML,
				'content'   => '<p>Body</p>',
				'confirm'   => '1',
			)
		);
		( new Queue() )->run();
		$this->assertSame( EmailJobRepository::STATUS_SUPPRESSED, $jobs->get( (int) $blocked['job_id'] )['status'], 'The Queue suppression gate still applies to tests.' );
		$this->assertCount( 1, get_option( EmailDispatcher::CAPTURE_OPTION, array() ) );

		$this->assertWPError(
			WorkflowEmail::test_payload(
				array(
					'source'    => 'template',
					'source_id' => $id,
					'subject'   => 'No confirmation',
					'mode'      => Campaigns::MODE_HTML,
					'content'   => '<p>Body</p>',
				)
			),
			'A test needs explicit confirmation.'
		);

		$this->set_settings( array( 'email_sending_enabled' => false ) );
		$this->assertWPError(
			WorkflowEmail::test_payload(
				array(
					'source'    => 'template',
					'source_id' => $id,
					'subject'   => 'Disabled',
					'mode'      => Campaigns::MODE_HTML,
					'content'   => '<p>Body</p>',
					'confirm'   => '1',
				)
			),
			'Sending must be enabled.'
		);

		$this->set_settings(
			array(
				'email_sending_enabled' => true,
				'email_from_address'    => '',
			)
		);
		$missing = WorkflowEmail::test_payload(
			array(
				'source'    => 'template',
				'source_id' => $id,
				'subject'   => 'No sender',
				'mode'      => Campaigns::MODE_HTML,
				'content'   => '<p>Body</p>',
				'confirm'   => '1',
			)
		);
		$this->assertWPError( $missing );
		$this->assertSame( 'nowcrm_workflow_sender_missing', $missing->get_error_code() );
	}

	public function test_block_test_snapshot_keeps_raw_blocks_and_rejects_unknown_blocks(): void {
		$id     = $this->make_template();
		$blocks = '<!-- wp:paragraph --><p>Raw block test</p><!-- /wp:paragraph -->';

		$result = WorkflowEmail::test_payload(
			array(
				'source'    => 'template',
				'source_id' => $id,
				'subject'   => 'Block test',
				'mode'      => Campaigns::MODE_BLOCKS,
				'content'   => $blocks,
				'confirm'   => '1',
			)
		);

		$job = ( new EmailJobRepository() )->get( (int) $result['job_id'] );
		$this->assertSame( Campaigns::MODE_BLOCKS, $job['payload']['template']['mode'], 'The test freezes raw blocks for the renderer.' );
		$this->assertSame( $blocks, $job['payload']['template']['content'] );

		$invalid = WorkflowEmail::test_payload(
			array(
				'source'    => 'template',
				'source_id' => $id,
				'subject'   => 'Block test',
				'mode'      => Campaigns::MODE_BLOCKS,
				'content'   => '<!-- wp:video --><figure></figure><!-- /wp:video -->',
				'confirm'   => '1',
			)
		);
		$this->assertWPError( $invalid );
		$this->assertSame( 'nowcrm_workflow_unsupported_block', $invalid->get_error_code() );

		$this->set_settings( array( 'email_transport' => 'wp_mail' ) );
		$local = WorkflowEmail::test_payload(
			array(
				'source'    => 'template',
				'source_id' => $id,
				'subject'   => 'Block test',
				'mode'      => Campaigns::MODE_HTML,
				'content'   => '<p>Body</p>',
				'confirm'   => '1',
			)
		);

		if ( 'local' === wp_get_environment_type() ) {
			$this->assertWPError( $local );
			$this->assertSame( 'nowcrm_workflow_local_transport', $local->get_error_code() );
		} else {
			$this->assertIsArray( $local );
		}
	}

	public function test_template_page_embeds_the_block_editor_and_keeps_a_failed_draft(): void {
		$blocks = '<!-- wp:paragraph --><p>Preserved block</p><!-- /wp:paragraph -->';
		$id     = $this->make_template( array( 'blocks' => $blocks ) );

		$_GET  = array( 'page' => \NowCRM\Admin\Menu::TEMPLATES_SLUG, 'template' => (string) $id );
		$html  = $this->render_template_page( $id );

		$this->assertStringContainsString( 'data-workflow-email="template"', $html );
		$this->assertStringContainsString( 'data-workflow-source-id="' . $id . '"', $html );
		$this->assertStringContainsString( 'data-workflow-field="subject"', $html );
		$this->assertStringContainsString( 'data-workflow-field="mode"', $html );
		$this->assertStringContainsString( 'data-workflow-field="content"', $html );
		$this->assertStringContainsString( 'data-workflow-frame', $html );
		$this->assertStringContainsString( 'data-workflow-tools', $html, 'Preview and test controls render in the same page.' );
		$this->assertStringContainsString( 'embed=1', $html, 'Blocks use the same-origin embedded editor.' );
		$this->assertStringNotContainsString( '開啟 Gutenberg 編輯器', $html );

		$_POST    = array(
			'_wpnonce'    => wp_create_nonce( 'nowcrm_save_template' ),
			'template_id' => (string) $id,
			'name'        => '',
			'subject'     => 'Kept subject',
			'preheader'   => 'Kept preview',
			'mode'        => Campaigns::MODE_BLOCKS,
			'blocks'      => $blocks,
			'html'        => '',
			'status'      => TemplateRepository::STATUS_PUBLISH,
		);
		$_REQUEST = $_POST;

		$location = $this->capture_redirect( static fn() => \NowCRM\Admin\TemplatePage::save_template() );
		$args     = $this->query_args( $location );
		$this->assertSame( '1', $args['nowcrm_draft'] ?? '' );
		$this->assertSame( 'invalid_name', $args['nowcrm_notice'] ?? '' );

		$_GET['nowcrm_draft'] = '1';
		$draft = \NowCRM\Admin\TemplatePage::draft_for_editor( $id );
		$this->assertIsArray( $draft );
		$this->assertSame( $blocks, $draft['blocks'] );

		$html = $this->render_template_page( $id );
		$this->assertStringContainsString( 'Preserved block', $html );
		$this->assertStringContainsString( 'draft=1', $html, 'The embedded editor must load the failed draft.' );
	}

	/**
	 * Render one template page into a string.
	 */
	private function render_template_page( int $id ): string {
		ob_start();
		\NowCRM\Admin\TemplatePage::render( $id );

		return (string) ob_get_clean();
	}

	/**
	 * @param array<string, mixed> $overrides Template overrides.
	 */
	private function make_template( array $overrides = array() ): int {
		$id = $this->templates->create(
			array_merge(
				array(
					'name'      => 'Workflow template ' . wp_generate_password( 6, false ),
					'subject'   => 'Subject',
					'preheader' => 'Preview',
					'mode'      => Campaigns::MODE_BLOCKS,
					'blocks'    => '<!-- wp:paragraph --><p>Body</p><!-- /wp:paragraph -->',
					'html'      => '',
					'status'    => TemplateRepository::STATUS_PUBLISH,
				),
				$overrides
			)
		);

		$this->assertIsInt( $id );

		return (int) $id;
	}

	/**
	 * Create a minimal automation row for source validation.
	 */
	private function make_automation(): int {
		global $wpdb;

		$table = \NowCRM\Support\Schema::table( 'automations' );
		$wpdb->insert(
			$table,
			array(
				'name'        => 'Workflow automation',
				'status'      => 'draft',
				'trigger_key' => 'contact_created',
				'created_at'  => gmdate( 'Y-m-d H:i:s' ),
				'updated_at'  => gmdate( 'Y-m-d H:i:s' ),
			)
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Run one wp_send_json denial as an AJAX request and decode its payload.
	 *
	 * Core uses a raw die on non-AJAX requests, so PHPUnit cannot catch the
	 * denial. Scope wp_doing_ajax and a throwing ajax die handler to this call.
	 *
	 * @param callable $handler Handler that must deny.
	 * @return array<string, mixed> Decoded wp_send_json_error data.
	 */
	private function capture_json_denial( callable $handler ): array {
		$throw  = static function ( $message = '', $title = '', $args = array() ): void {
			throw new WPDieException( 'json_denial' );
		};
		$filter = static fn() => $throw;

		add_filter( 'wp_doing_ajax', '__return_true' );
		add_filter( 'wp_die_ajax_handler', $filter, 99 );
		ob_start();

		try {
			$handler();
			$this->fail( 'The handler must deny this request.' );
		} catch ( WPDieException $exception ) {
			$this->addToAssertionCount( 1 );
		} finally {
			$output = (string) ob_get_clean();
			remove_filter( 'wp_doing_ajax', '__return_true' );
			remove_filter( 'wp_die_ajax_handler', $filter, 99 );
		}

		$payload = json_decode( $output, true );
		$this->assertIsArray( $payload, 'Expected a JSON denial, got: ' . $output );
		$this->assertFalse( $payload['success'] ?? null, 'The intercepted response must report failure.' );

		return is_array( $payload['data'] ?? null ) ? $payload['data'] : array();
	}

	private function query_args( string $location ): array {
		$query = (string) wp_parse_url( $location, PHP_URL_QUERY );
		parse_str( $query, $args );

		return array_map( 'strval', $args );
	}

	private function capture_redirect( callable $handler ): string {
		$captured = '';
		$filter   = static function ( $location ) use ( &$captured ): void {
			$captured = (string) $location;
			throw new NowCrm_Workflow_Redirect_Captured( $captured );
		};

		add_filter( 'wp_redirect', $filter, 1 );

		try {
			$handler();
		} catch ( NowCrm_Workflow_Redirect_Captured $exception ) {
			$captured = $exception->getMessage();
		} finally {
			remove_filter( 'wp_redirect', $filter, 1 );
		}

		$this->assertNotSame( '', $captured, 'The handler did not redirect.' );

		return $captured;
	}
}
