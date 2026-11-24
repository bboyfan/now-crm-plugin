<?php
/**
 * Automation detail workspace: compact cards, drawer markup and header activation.
 *
 * These tests render the editor and call the admin-post save handler directly.
 * Redirects are replaced with a throwing sentinel so the test process never exits.
 *
 * @package NowCRM
 */

use NowCRM\Admin\Actions;
use NowCRM\Admin\AutomationEditorPage;
use NowCRM\Admin\ContentEditorPage;
use NowCRM\Admin\Menu;
use NowCRM\Editor\DummyPost;
use NowCRM\Automation\AutomationRepository;
use NowCRM\Automation\RunRepository;
use NowCRM\Automation\TriggerRegistry;
use NowCRM\Support\Schema;
use NowCRM\Sequences\SequenceRepository;
use NowCRM\Terms\TermRepository;

/**
 * Sentinel thrown by the redirect filter so a handler never reaches exit().
 */
final class NowCrm_AutomationEditor_Redirect_Captured extends \RuntimeException {}

/**
 * @covers \NowCRM\Admin\AutomationEditorPage
 * @covers \NowCRM\Admin\Actions
 */
final class AutomationEditorTest extends NowCrmTestCase {

	private AutomationRepository $automations;
	private int $actor;

	public function set_up(): void {
		parent::set_up();

		$this->automations = new AutomationRepository();
		$this->actor       = (int) self::factory()->user->create( array( 'role' => 'administrator' ) );

		wp_set_current_user( $this->actor );
	}

	public function tear_down(): void {
		$_GET     = array();
		$_POST    = array();
		$_REQUEST = array();
		wp_set_current_user( 0 );

		parent::tear_down();
	}

	/**
	 * Create one automation with the supplied steps.
	 *
	 * @param array<int, array<string, mixed>> $steps     Nested steps.
	 * @param array<string, mixed>             $overrides Repository overrides.
	 */
	private function make_automation( array $steps, array $overrides = array() ): int {
		$id = $this->automations->save(
			array_merge(
				array(
					'name'         => 'Editor fixture ' . wp_generate_password( 6, false ),
					'status'       => AutomationRepository::STATUS_DRAFT,
					'trigger_key'  => TriggerRegistry::ORDER_COMPLETED,
					'entry_config' => array( 'run_once' => true ),
					'steps'        => $steps,
				),
				$overrides
			)
		);

		$this->assertIsInt( $id );

		return (int) $id;
	}

	/**
	 * Render the editor and capture its markup.
	 *
	 * @param int $automation Automation ID.
	 */
	private function render_editor( int $automation ): string {
		$_GET     = array(
			'page' => Menu::AUTOMATION_SLUG,
			'id'   => (string) $automation,
		);
		$_REQUEST = $_GET;

		$die    = static function ( $message = '', $title = '', $args = array() ): void {
			throw new WPDieException( 'render_died' );
		};
		$filter = static fn() => $die;

		add_filter( 'wp_die_handler', $filter, 99 );
		ob_start();

		try {
			AutomationEditorPage::render();
		} catch ( WPDieException $exception ) {
			$this->fail( 'The automation editor must not die: ' . $exception->getMessage() );
		} finally {
			$html = (string) ob_get_clean();
			remove_filter( 'wp_die_handler', $filter, 99 );
		}

		return $html;
	}

	/**
	 * Capture the wp_safe_redirect() target of a handler.
	 */
	private function capture_redirect( callable $handler ): string {
		$captured = '';
		$filter   = static function ( $location ) use ( &$captured ): void {
			$captured = (string) $location;
			throw new NowCrm_AutomationEditor_Redirect_Captured( $captured );
		};

		add_filter( 'wp_redirect', $filter, 1 );

		try {
			$handler();
		} catch ( NowCrm_AutomationEditor_Redirect_Captured $exception ) {
			$captured = $exception->getMessage();
		} finally {
			remove_filter( 'wp_redirect', $filter, 1 );
		}

		$this->assertNotSame( '', $captured, 'The handler did not redirect.' );

		return $captured;
	}

	/**
	 * Post one save_automation request.
	 *
	 * @param int                  $automation Automation ID.
	 * @param array<string, mixed> $steps      Step array.
	 * @param array<string, mixed> $extra      Extra POST fields.
	 */
	private function post_save( int $automation, array $steps, array $extra = array() ): string {
		$_POST = array_merge(
			array(
				'_wpnonce'    => wp_create_nonce( 'nowcrm_save_automation' ),
				'action'      => 'nowcrm_save_automation',
				'id'          => (string) $automation,
				'name'        => 'Edited automation',
				'trigger_key' => TriggerRegistry::ORDER_COMPLETED,
				'status'      => AutomationRepository::STATUS_DRAFT,
				'steps_json'  => (string) wp_json_encode( $steps ),
			),
			$extra
		);
		$_REQUEST = $_POST;

		return $this->capture_redirect(
			static function (): void {
				Actions::save_automation();
			}
		);
	}

	public function test_editor_renders_compact_cards_drawer_and_units(): void {
		$list = $this->terms->create( TermRepository::TYPE_LIST, 'VIP 名單' );
		$this->assertIsInt( $list );

		$plan = array(
			'version' => 1,
			'groups'  => array(
				array(
					array(
						'provider' => 'contact',
						'field'    => 'first_name',
						'operator' => '=',
						'value'    => 'Yes',
					),
				),
			),
		);

		$automation = $this->make_automation(
			array(
				array( 'kind' => 'action', 'action_key' => 'apply_list', 'config' => array( 'term_ids' => array( (int) $list ) ) ),
				array( 'kind' => 'wait', 'delay_seconds' => 3661 ),
				array( 'kind' => 'email', 'config' => array( 'subject' => '歡迎信', 'mode' => 'html', 'content' => '<p>Hi</p>' ) ),
				array(
					'kind'   => 'condition',
					'config' => array( 'conditions' => $plan ),
					'yes'    => array( array( 'kind' => 'end' ) ),
					'no'     => array( array( 'kind' => 'wait', 'delay_seconds' => 86400 ) ),
				),
				array(
					'kind'   => 'split',
					'config' => array( 'weight_a' => 40, 'weight_b' => 60 ),
					'a'      => array( array( 'kind' => 'end' ) ),
					'b'      => array( array( 'kind' => 'end' ) ),
				),
				array(
					'kind'   => 'goal',
					'config' => array(
						'event'           => AutomationRepository::GOAL_TERM_ATTACHED,
						'term_ids'        => array( (int) $list ),
						'mode'            => AutomationRepository::GOAL_MODE_REQUIRED,
						'timeout_seconds' => 3600,
					),
					'timeout' => array( array( 'kind' => 'end' ) ),
				),
				array( 'kind' => 'end' ),
			)
		);

		$html = $this->render_editor( $automation );

		$this->assertStringContainsString( 'id="nowcrm-automation-form"', $html, 'The editor must keep the main form.' );
		$this->assertStringContainsString( 'data-testid="automation-steps"', $html, 'The step list must keep its test id.' );
		$this->assertStringContainsString( 'data-testid="automation-add-step"', $html, 'The add-step button must keep its test id.' );
		$this->assertStringContainsString( 'id="nowcrm-step-drawer"', $html, 'The drawer must be rendered outside the form.' );
		$this->assertStringContainsString( 'id="nowcrm-step-picker"', $html, 'The categorized picker must be rendered.' );
		$this->assertStringContainsString( 'name="save_status"', $html, 'A draft automation must offer enable-with-save.' );
		$this->assertStringContainsString( 'data-role="live-indicator"', $html, 'The header must render the live run indicator.' );

		$this->assertStringContainsString( 'data-role="step-title"', $html, 'Every card must render its summary title.' );
		$this->assertStringContainsString( 'data-role="step-icon"', $html, 'Every card must render its icon.' );
		$this->assertStringContainsString( '加入名單：VIP 名單', $html, 'Action summaries must list selected terms.' );
		$this->assertStringContainsString( '等待 1 小時 1 分 1 秒', $html, 'Wait summaries must be human readable.' );
		$this->assertStringContainsString( '歡迎信', $html, 'Email summaries must show the subject.' );
		$this->assertStringContainsString( '1 組條件 · 1 個條件', $html, 'Condition summaries must count groups and rows.' );
		$this->assertStringContainsString( 'A 40% / B 60%', $html, 'Split summaries must show both weights.' );
		$this->assertStringContainsString( '加入名單或標籤 · VIP 名單 · 必經 · 逾時 1 小時', $html, 'Goal summaries must include event, target, mode and timeout.' );
		$this->assertStringContainsString( '流程結束', $html, 'End summaries must be rendered.' );

		$this->assertStringContainsString( 'data-field="delay_value" value="3661"', $html, '3661 seconds must initialize in the seconds control.' );
		$this->assertStringContainsString( 'data-field="delay_seconds" value="3661"', $html, 'The hidden seconds field must keep the stored contract.' );
		$this->assertStringContainsString( 'data-field="delay_unit"', $html, 'The wait unit control must render.' );
		$this->assertStringContainsString( 'data-field="goal_timeout_value" value="1"', $html, 'Exact hours must initialize in the goal timeout control.' );
		$this->assertStringContainsString( 'data-field="goal_timeout" value="3600"', $html, 'The goal timeout hidden field must keep the stored contract.' );

		$this->assertStringContainsString( 'data-workflow-email="automation"', $html, 'Workflow email tools must stay inside the email step body.' );
		$this->assertStringContainsString( 'data-workflow-mode-pane="blocks"', $html, 'The email step must expose a block canvas pane.' );
		$this->assertStringContainsString( 'data-workflow-frame-host', $html, 'The block canvas must be mounted lazily inside the drawer.' );
		$this->assertStringContainsString( 'data-workflow-frame-status', $html, 'The block canvas must expose its loading/error status.' );
		$this->assertStringContainsString( 'target=automation', $html, 'The lazy iframe must use the automation canvas target.' );
		$this->assertStringContainsString( 'data-workflow-mode-pane="html"', $html, 'The raw HTML pane must remain available.' );
		$this->assertStringContainsString( 'data-branch-block="yes"', $html, 'Condition branches must stay nested.' );
		$this->assertStringContainsString( 'data-branch-block="timeout"', $html, 'Goal timeout branches must stay nested.' );
	}

	public function test_automation_email_canvas_starts_blank(): void {
		$automation = $this->make_automation(
			array(
				array(
					'kind'   => 'email',
					'config' => array(
						'subject' => 'Canvas probe',
						'mode'    => 'blocks',
						'content' => '<!-- wp:paragraph --><p>Stored step</p><!-- /wp:paragraph -->',
					),
				),
				array( 'kind' => 'end' ),
			)
		);

		$dummy = DummyPost::ensure( 'automation', $automation );
		$this->assertIsInt( $dummy );
		$post = get_post( (int) $dummy );
		$this->assertSame( '', (string) $post->post_content, 'The automation canvas must not copy one step into the working post.' );
		$this->assertNotSame( '', (string) $post->post_title );

		$url = ContentEditorPage::url( 'automation', $automation, true );
		$this->assertStringContainsString( 'target=automation', $url );
		$this->assertStringContainsString( 'embed=1', $url );
	}

	public function test_paused_linked_sequence_stays_selected_and_labelled(): void {
		$sequences = new SequenceRepository();
		$sequence  = $sequences->create(
			array(
				'name'          => 'Paused editor sequence',
				'status'        => SequenceRepository::STATUS_ACTIVE,
				'allow_reentry' => false,
				'audience'      => array( 'type' => 'all' ),
				'steps'         => array(
					array(
						'name'          => 'Welcome',
						'subject'       => 'Paused welcome',
						'preheader'     => '',
						'mode'          => 'html',
						'content'       => '<p>Welcome</p>',
						'delay_seconds' => 0,
					),
				),
			)
		);
		$this->assertIsInt( $sequence );

		$automation = $this->make_automation(
			array(
				array( 'kind' => 'action', 'action_key' => 'add_to_sequence', 'config' => array( 'sequence_id' => (int) $sequence ) ),
			)
		);

		$this->assertTrue( $sequences->set_status( (int) $sequence, SequenceRepository::STATUS_PAUSED ) );

		$html = $this->render_editor( $automation );

		$this->assertMatchesRegularExpression(
			'/<option value="' . (int) $sequence . '"\s+selected="selected">\s*Paused editor sequence（已暫停）/u',
			$html,
			'A saved sequence that is no longer active must stay selected with its status label.'
		);
		$this->assertStringContainsString( '選擇已啟用的序列', $html, 'New choices must still come from the active sequence list.' );
	}

	public function test_editor_initializes_exact_days_units_and_resource_metrics(): void {
		$automation = $this->make_automation(
			array( array( 'kind' => 'wait', 'delay_seconds' => 172800 ), array( 'kind' => 'end' ) ),
			array( 'status' => AutomationRepository::STATUS_ACTIVE )
		);

		global $wpdb;
		$now      = gmdate( 'Y-m-d H:i:s' );
		$inserted = $wpdb->insert(
			Schema::table( 'automation_runs' ),
			array(
				'automation_id'  => $automation,
				'contact_id'     => $this->make_contact(),
				'status'         => RunRepository::STATUS_WAITING,
				'next_step_id'   => null,
				'source_trigger' => TriggerRegistry::ORDER_COMPLETED,
				'started_at'     => $now,
				'updated_at'     => $now,
			),
			array( '%d', '%d', '%s', '%d', '%s', '%s', '%s' )
		);
		$this->assertTrue( (bool) $inserted );

		$html = $this->render_editor( $automation );

		$this->assertStringContainsString( 'data-field="delay_value" value="2"', $html, 'Two exact days must initialize as days.' );
		$this->assertMatchesRegularExpression( '/<option value="days"\s+selected=/', $html, 'The day unit must be selected.' );
		$this->assertStringContainsString( 'data-field="delay_seconds" value="172800"', $html, 'Stored seconds must stay unchanged.' );
		$this->assertStringContainsString( '1 執行中／等待中', $html, 'The live indicator must count waiting runs.' );
		$this->assertStringContainsString( 'run_status=waiting', $html, 'Run metrics must link into the matching runs filter.' );
	}

	public function test_header_enable_saves_unsaved_edits_and_activates(): void {
		$automation = $this->make_automation( array( array( 'kind' => 'end' ) ) );

		$location = $this->post_save(
			$automation,
			array( array( 'kind' => 'end' ) ),
			array( 'save_status' => AutomationRepository::STATUS_ACTIVE )
		);

		$this->assertStringContainsString( 'page=' . Menu::AUTOMATION_SLUG, $location, 'A successful save must return to the editor.' );

		$saved = $this->automations->get( $automation );

		$this->assertSame( AutomationRepository::STATUS_ACTIVE, $saved['status'], 'save_status=active must activate the automation.' );
		$this->assertSame( 'Edited automation', $saved['name'], 'The header enable path must save the pending edits.' );
	}

	public function test_invalid_save_status_is_ignored(): void {
		$automation = $this->make_automation(
			array( array( 'kind' => 'end' ) ),
			array( 'status' => AutomationRepository::STATUS_PAUSED )
		);

		$this->post_save(
			$automation,
			array( array( 'kind' => 'end' ) ),
			array(
				'status'      => AutomationRepository::STATUS_ACTIVE,
				'save_status' => 'deleted',
			)
		);

		$saved = $this->automations->get( $automation );

		$this->assertSame( AutomationRepository::STATUS_PAUSED, $saved['status'], 'Stale form status and unknown save_status values must not change state.' );
	}
}
