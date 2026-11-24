<?php
/**
 * Automation run controls and the live-save JSON error path.
 *
 * These tests call the admin-post handlers directly. Redirects and wp_die are
 * replaced with throwing sentinels so the test process never exits.
 *
 * @package NowCRM
 */

use NowCRM\Admin\Actions;
use NowCRM\Admin\AutomationRunsPage;
use NowCRM\Admin\AutomationWorkflowActions;
use NowCRM\Admin\Menu;
use NowCRM\Automation\AutomationRepository;
use NowCRM\Automation\RunRepository;
use NowCRM\Automation\TriggerRegistry;
use NowCRM\Cart\CartRepository;
use NowCRM\Email\EmailJobRepository;
use NowCRM\Sequences\SequenceRepository;
use NowCRM\Support\Schema;
use NowCRM\Terms\TermRepository;

/**
 * Sentinel thrown by the redirect filter so a handler never reaches exit().
 */
final class NowCrm_AutomationWorkflow_Redirect_Captured extends \RuntimeException {}

/**
 * @covers \NowCRM\Admin\AutomationWorkflowActions
 * @covers \NowCRM\Admin\Actions
 */
final class AutomationWorkflowTest extends NowCrmTestCase {

	private AutomationRepository $automations;
	private RunRepository $runs;
	private int $actor;

	public function set_up(): void {
		parent::set_up();

		$this->automations = new AutomationRepository();
		$this->runs        = new RunRepository();
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
	 * Create active automation with one end step.
	 *
	 * These tests exercise admin-post handlers, not contact-creation
	 * triggers. Using contact_created here would start a run while the
	 * fixture builds its own row, which collides on automation_contact.
	 */
	private function make_automation( string $name = 'Workflow fixture' ): int {
		$id = $this->automations->save(
			array(
				'name'         => $name . ' ' . wp_generate_password( 4, false ),
				'status'       => AutomationRepository::STATUS_ACTIVE,
				'trigger_key'  => TriggerRegistry::ORDER_COMPLETED,
				'entry_config' => array( 'run_once' => false ),
				'steps'        => array( array( 'kind' => 'end' ) ),
			)
		);

		$this->assertIsInt( $id );

		return (int) $id;
	}

	/**
	 * Insert one run row for a contact.
	 */
	private function make_run( int $automation_id, string $status = RunRepository::STATUS_ACTIVE, ?int $contact_id = null, ?int $next_step_id = null ): int {
		global $wpdb;

		$now = gmdate( 'Y-m-d H:i:s' );

		$inserted = $wpdb->insert(
			Schema::table( 'automation_runs' ),
			array(
				'automation_id'  => $automation_id,
				'contact_id'     => $contact_id ?? $this->make_contact(),
				'status'         => $status,
				'next_step_id'   => $next_step_id,
				'next_run_at'    => RunRepository::STATUS_WAITING === $status ? null : $now,
				'source_trigger' => TriggerRegistry::CONTACT_CREATED,
				'started_at'     => $now,
				'updated_at'     => $now,
			),
			array( '%d', '%d', '%s', '%d', '%s', '%s', '%s', '%s' )
		);

		$this->assertTrue( (bool) $inserted );

		return (int) $wpdb->insert_id;
	}

	/**
	 * Capture the wp_safe_redirect() target of a handler.
	 */
	private function capture_redirect( callable $handler ): string {
		$captured = '';
		$filter   = static function ( $location ) use ( &$captured ): void {
			$captured = (string) $location;
			throw new NowCrm_AutomationWorkflow_Redirect_Captured( $captured );
		};

		add_filter( 'wp_redirect', $filter, 1 );

		try {
			$handler();
		} catch ( NowCrm_AutomationWorkflow_Redirect_Captured $exception ) {
			$captured = $exception->getMessage();
		} finally {
			remove_filter( 'wp_redirect', $filter, 1 );
		}

		$this->assertNotSame( '', $captured, 'The handler did not redirect.' );

		return $captured;
	}

	/**
	 * Run a handler that must call wp_die() and catch the die.
	 */
	private function capture_die( callable $handler ): void {
		$throw  = static function ( $message = '', $title = '', $args = array() ): void {
			throw new WPDieException( 'handler_died' );
		};
		$filter = static fn() => $throw;

		add_filter( 'wp_die_handler', $filter, 99 );

		try {
			$handler();
			$this->fail( 'The handler must stop on a rejected request.' );
		} catch ( WPDieException $exception ) {
			$this->addToAssertionCount( 1 );
		} finally {
			remove_filter( 'wp_die_handler', $filter, 99 );
		}
	}

	/**
	 * Run one wp_send_json error and decode the payload.
	 *
	 * Core uses a raw die on non-AJAX requests, so scope wp_doing_ajax and a
	 * throwing ajax die handler to this call.
	 *
	 * @return array<string, mixed> Decoded error data.
	 */
	private function capture_json_error( callable $handler ): array {
		$throw  = static function ( $message = '', $title = '', $args = array() ): void {
			throw new WPDieException( 'json_error' );
		};
		$filter = static fn() => $throw;

		add_filter( 'wp_doing_ajax', '__return_true' );
		add_filter( 'wp_die_ajax_handler', $filter, 99 );
		ob_start();

		try {
			$handler();
			$this->fail( 'The handler must return a JSON error.' );
		} catch ( WPDieException $exception ) {
			$this->addToAssertionCount( 1 );
		} finally {
			$output = (string) ob_get_clean();
			remove_filter( 'wp_doing_ajax', '__return_true' );
			remove_filter( 'wp_die_ajax_handler', $filter, 99 );
		}

		$payload = json_decode( $output, true );
		$this->assertIsArray( $payload, 'Expected a JSON error, got: ' . $output );
		$this->assertFalse( (bool) ( $payload['success'] ?? true ), 'The intercepted response must report failure.' );

		return is_array( $payload['data'] ?? null ) ? $payload['data'] : array();
	}

	/**
	 * Query arguments of a location.
	 *
	 * @return array<string, string>
	 */
	private function query_args( string $location ): array {
		$query = (string) wp_parse_url( $location, PHP_URL_QUERY );
		parse_str( $query, $args );

		return array_map( 'strval', $args );
	}

	/**
	 * Simulate an admin-post request with string form values.
	 *
	 * @param array<string, mixed> $fields Request fields.
	 */
	private function post( array $fields ): void {
		$payload = array();

		foreach ( $fields as $key => $value ) {
			$payload[ $key ] = is_scalar( $value ) ? (string) $value : $value;
		}

		$_POST    = $payload;
		$_REQUEST = $payload;
	}

	/**
	 * Render the run list for one query string.
	 *
	 * @param array<string, mixed> $query Query arguments.
	 */
	private function render_runs_page( array $query ): string {
		$_GET     = $query;
		$_REQUEST = $query;

		$die    = static function ( $message = '', $title = '', $args = array() ): void {
			throw new WPDieException( 'render_died' );
		};
		$filter = static fn() => $die;

		add_filter( 'wp_die_handler', $filter, 99 );
		ob_start();

		try {
			AutomationRunsPage::render();
		} catch ( WPDieException $exception ) {
			$this->fail( 'The runs page must not die: ' . $exception->getMessage() );
		} finally {
			$html = (string) ob_get_clean();
			remove_filter( 'wp_die_handler', $filter, 99 );
		}

		return $html;
	}

	public function test_run_control_rejects_a_bad_nonce_without_changing_the_run(): void {
		$automation = $this->make_automation();
		$run        = $this->make_run( $automation );

		$this->post(
			array(
				'action'        => AutomationWorkflowActions::CANCEL,
				'run_id'        => $run,
				'automation_id' => $automation,
				'_wpnonce'      => 'not-a-valid-nonce',
			)
		);

		$this->capture_die(
			static function (): void {
				AutomationWorkflowActions::cancel();
			}
		);

		$this->assertSame( RunRepository::STATUS_ACTIVE, $this->runs->get( $run )['status'], 'A rejected nonce must not change the run.' );
	}

	public function test_run_control_rejects_an_ownership_mismatch_and_keeps_runs(): void {
		$owner = $this->make_automation( 'Owner automation' );
		$other = $this->make_automation( 'Other automation' );
		$owned = $this->make_run( $owner );
		$run   = $this->make_run( $other );

		$this->post(
			array(
				'action'        => AutomationWorkflowActions::CANCEL,
				'run_id'        => $run,
				'automation_id' => $owner,
				'_wpnonce'      => wp_create_nonce( AutomationWorkflowActions::NONCE ),
			)
		);

		$location = $this->capture_redirect(
			static function (): void {
				AutomationWorkflowActions::cancel();
			}
		);
		$args = $this->query_args( $location );

		$this->assertSame( 'not_found', $args['nowcrm_run_notice'] ?? '' );
		$this->assertSame( RunRepository::STATUS_ACTIVE, $this->runs->get( $run )['status'], 'A run of another automation must not change.' );
		$this->assertSame( RunRepository::STATUS_ACTIVE, $this->runs->get( $owned )['status'] );
	}

	public function test_run_control_cancel_keeps_the_browse_filters(): void {
		$automation = $this->make_automation( 'Cancel automation' );
		$run        = $this->make_run( $automation );

		$this->post(
			array(
				'action'        => AutomationWorkflowActions::CANCEL,
				'run_id'        => $run,
				'automation_id' => $automation,
				'paged'         => 3,
				'run_status'    => RunRepository::STATUS_ACTIVE,
				'run_search'    => 'alice',
				'_wpnonce'      => wp_create_nonce( AutomationWorkflowActions::NONCE ),
			)
		);

		$location = $this->capture_redirect(
			static function (): void {
				AutomationWorkflowActions::cancel();
			}
		);
		$args = $this->query_args( $location );

		$this->assertSame( Menu::AUTOMATION_RUNS_SLUG, $args['page'] ?? '' );
		$this->assertSame( (string) $automation, $args['id'] ?? '' );
		$this->assertSame( '3', $args['paged'] ?? '' );
		$this->assertSame( RunRepository::STATUS_ACTIVE, $args['run_status'] ?? '' );
		$this->assertSame( 'alice', $args['run_search'] ?? '' );
		$this->assertSame( 'run_cancelled', $args['nowcrm_run_notice'] ?? '' );
		$this->assertSame( RunRepository::STATUS_CANCELLED, $this->runs->get( $run )['status'] );
	}

	public function test_live_save_returns_the_real_error_and_keeps_the_graph(): void {
		$automation = $this->make_automation( 'Live save fixture' );
		$before     = $this->automations->nested_steps( $automation );

		$steps = array(
			array(
				'kind'   => 'condition',
				'config' => array(
					'conditions' => array(
						'version' => 1,
						'groups'  => array(),
					),
				),
				'yes'    => array(),
				'no'     => array(),
			),
			array( 'kind' => 'end' ),
		);

		$this->post(
			array(
				'action'      => 'nowcrm_save_automation',
				'id'          => $automation,
				'name'        => 'Renamed by a failed save',
				'trigger_key' => TriggerRegistry::CONTACT_CREATED,
				'status'      => AutomationRepository::STATUS_DRAFT,
				'steps_json'  => (string) wp_json_encode( $steps ),
				'nowcrm_json' => '1',
				'_wpnonce'    => wp_create_nonce( 'nowcrm_save_automation' ),
			)
		);

		$data = $this->capture_json_error(
			static function (): void {
				Actions::save_automation();
			}
		);

		$this->assertNotSame( '', (string) ( $data['message'] ?? '' ), 'The live save must return the real validation message.' );
		$this->assertStringContainsString( '條件', (string) $data['message'] );
		$this->assertSame( $before, $this->automations->nested_steps( $automation ), 'A rejected save must not change the stored steps.' );
		$this->assertStringStartsWith( 'Live save fixture', (string) $this->automations->get( $automation )['name'], 'A rejected save must not rename the automation.' );
	}

	public function test_runs_page_renders_filters_actions_and_escapes_values(): void {
		$automation = $this->make_automation( 'Runs page fixture' );
		$needle     = 'A&B"c';
		$steps      = $this->automations->steps( $automation );
		$failed_step = (int) ( $steps[0]['id'] ?? 0 );
		$active     = $this->make_contact(
			array(
				'first_name' => $needle,
				'last_name'  => 'Active',
			)
		);
		$failed     = $this->make_contact(
			array(
				'first_name' => $needle,
				'last_name'  => 'Failed',
			)
		);

		$this->assertGreaterThan( 0, $failed_step, 'The failed-run fixture needs a real next step to offer retry.' );

		$this->make_run( $automation, RunRepository::STATUS_ACTIVE, $active );
		$this->make_run( $automation, RunRepository::STATUS_FAILED, $failed, $failed_step );

		$html = $this->render_runs_page(
			array(
				'page'       => Menu::AUTOMATION_RUNS_SLUG,
				'id'         => (string) $automation,
				'run_search' => $needle,
			)
		);

		$this->assertStringContainsString( '取消', $html, 'An active run must offer cancel.' );
		$this->assertStringContainsString( '重試', $html, 'A failed run must offer retry.' );
		$this->assertStringContainsString( 'name="_wpnonce"', $html, 'Run controls must carry a nonce field.' );
		$this->assertStringContainsString( 'name="run_status"', $html, 'The status filter must render.' );
		$this->assertStringContainsString( 'value="A&amp;B&quot;c"', $html, 'Filter values must escape through the form.' );

		$filtered = $this->render_runs_page(
			array(
				'page'       => Menu::AUTOMATION_RUNS_SLUG,
				'id'         => (string) $automation,
				'run_status' => RunRepository::STATUS_FAILED,
			)
		);

		$this->assertMatchesRegularExpression(
			'/<option value="failed"\s+selected=/',
			$filtered,
			'The status filter must show its current value.'
		);

		$no_next = $this->make_contact( array( 'first_name' => 'NoNextRetry' ) );
		$this->make_run( $automation, RunRepository::STATUS_FAILED, $no_next );

		$without_step = $this->render_runs_page(
			array(
				'page'       => Menu::AUTOMATION_RUNS_SLUG,
				'id'         => (string) $automation,
				'run_search' => 'NoNextRetry',
			)
		);

		$this->assertStringContainsString( 'NoNextRetry', $without_step, 'A failed run without a next step must still render.' );
		$this->assertStringNotContainsString( '重試', $without_step, 'A failed run without a next step must not offer retry.' );
	}

	public function test_run_view_requires_the_run_to_belong_to_the_automation(): void {
		$first   = $this->make_automation( 'Run owner A' );
		$second  = $this->make_automation( 'Run owner B' );
		$contact = $this->make_contact( array( 'first_name' => 'MismatchRun' ) );
		$run     = $this->make_run( $second, RunRepository::STATUS_ACTIVE, $contact );

		$html = $this->render_runs_page(
			array(
				'page'   => Menu::AUTOMATION_RUNS_SLUG,
				'id'     => (string) $first,
				'run_id' => (string) $run,
			)
		);

		$this->assertStringContainsString( '找不到屬於這項自動化的執行', $html );
		$this->assertStringNotContainsString( 'MismatchRun', $html );
		$this->assertStringNotContainsString( 'data-testid="automation-run-detail"', $html );
		$this->assertStringNotContainsString( 'name="_wpnonce"', $html, 'A mismatched run must not expose another run\'s controls.' );
	}

	public function test_run_view_links_the_exact_cart_and_only_verified_jobs(): void {
		global $wpdb;

		$automation = $this->make_automation( 'Cart run detail' );
		$contact    = $this->make_contact( array( 'first_name' => 'ExactRun' ) );
		$run        = $this->make_run( $automation, RunRepository::STATUS_ACTIVE, $contact );
		$cart       = $this->make_cart( array( 'email' => 'cart-run-view@example.test' ) );
		$steps      = $this->automations->steps( $automation );
		$step_id    = (int) ( $steps[0]['id'] ?? 0 );

		$this->assertGreaterThan( 0, $step_id );

		$wpdb->update(
			Schema::table( 'automation_runs' ),
			array(
				'cart_id'        => (int) $cart['id'],
				'cart_version'   => (int) $cart['version'],
				'source_trigger' => TriggerRegistry::CART_ABANDONED,
				'source_ref'     => 'cart:' . (int) $cart['id'] . ':' . (int) $cart['version'],
			),
			array( 'id' => $run )
		);

		$job_id = ( new EmailJobRepository() )->enqueue(
			array(
				'job_key'    => 'run-detail-' . wp_generate_password( 10, false ),
				'kind'       => 'automation',
				'recipient'  => 'automation-run@example.test',
				'subject'    => 'Run detail job',
				'cart_id'    => (int) $cart['id'],
				'contact_id' => $contact,
				'payload'    => array(
					'automation_id' => $automation,
					'run_id'        => $run,
					'step_id'       => $step_id,
				),
			)
		);

		$this->assertIsInt( $job_id );

		$this->runs->record_event(
			$run,
			$step_id,
			$contact,
			array(
				'job_id'        => (int) $job_id,
				'reason'        => 'queued',
				'recovery_token' => 'super-secret-token',
				'context'       => array( 'blob' => 'checkout-context-blob' ),
			)
		);

		$html = $this->render_runs_page(
			array(
				'page'   => Menu::AUTOMATION_RUNS_SLUG,
				'id'     => (string) $automation,
				'run_id' => (string) $run,
			)
		);

		$this->assertStringContainsString( 'data-testid="automation-run-detail"', $html );
		$this->assertStringContainsString( 'cart=' . (int) $cart['id'], $html );
		$this->assertStringContainsString( 'job=' . (int) $job_id, $html );
		$this->assertStringContainsString( 'reason：queued', $html );
		$this->assertStringNotContainsString( 'super-secret-token', $html );
		$this->assertStringNotContainsString( 'checkout-context-blob', $html );
	}

	/**
	 * One sequence fixture with a single step.
	 */
	private function make_sequence( string $status ): int {
		$id = ( new SequenceRepository() )->create(
			array(
				'name'          => 'Workflow sequence ' . wp_generate_password( 6, false ),
				'status'        => $status,
				'allow_reentry' => false,
				'audience'      => array( 'type' => 'all' ),
				'steps'         => array(
					array(
						'name'          => 'Welcome',
						'subject'       => 'Workflow welcome',
						'preheader'     => '',
						'mode'          => 'html',
						'content'       => '<p>Welcome</p>',
						'delay_seconds' => 0,
					),
				),
			)
		);

		$this->assertIsInt( $id );

		return (int) $id;
	}

	public function test_metadata_only_save_keeps_step_ids_and_run_identity(): void {
		global $wpdb;

		$tag        = (int) $this->terms->create( TermRepository::TYPE_TAG, 'Metadata tag' );
		$automation = (int) $this->automations->save(
			array(
				'name'         => 'Metadata fixture',
				'status'       => AutomationRepository::STATUS_ACTIVE,
				'trigger_key'  => TriggerRegistry::ORDER_COMPLETED,
				'entry_config' => array( 'run_once' => false ),
				'steps'        => array(
					array( 'kind' => 'action', 'action_key' => 'apply_tag', 'config' => array( 'term_ids' => array( $tag ) ) ),
					array(
						'kind'   => 'split',
						'config' => array( 'weight_a' => 50, 'weight_b' => 50 ),
						'a'      => array( array( 'kind' => 'wait', 'delay_seconds' => 90 ) ),
						'b'      => array( array( 'kind' => 'end' ) ),
					),
					array( 'kind' => 'end' ),
				),
			)
		);

		$before     = $this->automations->steps( $automation );
		$before_ids = array_column( $before, 'id' );
		$run        = $this->make_run( $automation );
		$run_row    = $this->runs->get( $run );
		$events     = Schema::table( 'automation_run_events' );

		$wpdb->update(
			Schema::table( 'automation_runs' ),
			array( 'next_step_id' => (int) $before[0]['id'] ),
			array( 'id' => $run ),
			array( '%d' ),
			array( '%d' )
		);

		$wpdb->insert(
			$events,
			array(
				'run_id'      => $run,
				'step_id'     => (int) $before[0]['id'],
				'contact_id'  => (int) $run_row['contact_id'],
				'status'      => 'completed',
				'occurred_at' => gmdate( 'Y-m-d H:i:s' ),
			),
			array( '%d', '%d', '%d', '%s', '%s' )
		);

		$graph = array(
			array( 'kind' => 'action', 'action_key' => 'apply_tag', 'config' => array( 'term_id' => $tag ) ),
			array(
				'kind'   => 'split',
				'config' => array( 'weight_b' => 50, 'weight_a' => 50 ),
				'a'      => array( array( 'kind' => 'wait', 'delay_seconds' => 90 ) ),
				'b'      => array( array( 'kind' => 'end' ) ),
			),
			array( 'kind' => 'end' ),
		);

		$this->post(
			array(
				'action'      => 'nowcrm_save_automation',
				'id'          => $automation,
				'name'        => 'Metadata renamed',
				'trigger_key' => TriggerRegistry::ORDER_COMPLETED,
				'steps_json'  => (string) wp_json_encode( $graph ),
				'_wpnonce'    => wp_create_nonce( 'nowcrm_save_automation' ),
			)
		);

		$location = $this->capture_redirect(
			static function (): void {
				Actions::save_automation();
			}
		);

		$this->assertSame( 'automation_saved', $this->query_args( $location )['nowcrm_notice'] ?? '' );
		$this->assertSame( 'Metadata renamed', $this->automations->get( $automation )['name'], 'A metadata save must update the name.' );
		$this->assertSame( $before_ids, array_column( $this->automations->steps( $automation ), 'id' ), 'An identical graph must keep the step IDs.' );

		$stored_run = $this->runs->get( $run );

		$this->assertSame( (int) $before[0]['id'], (int) $stored_run['next_step_id'], 'An identical graph must keep the run pointer.' );
		$this->assertSame( RunRepository::STATUS_ACTIVE, $stored_run['status'] );

		$kept_events = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$events} WHERE run_id = %d AND step_id = %d",
				$run,
				(int) $before[0]['id']
			)
		);

		$this->assertSame( 1, $kept_events, 'Run events must keep their step identity.' );
	}

	public function test_edited_graph_replaces_steps_and_remaps_the_run_pointer(): void {
		global $wpdb;

		$automation = $this->make_automation( 'Rewrite fixture' );
		$before     = $this->automations->steps( $automation );
		$run        = $this->make_run( $automation );

		$wpdb->update(
			Schema::table( 'automation_runs' ),
			array( 'next_step_id' => (int) $before[0]['id'] ),
			array( 'id' => $run ),
			array( '%d' ),
			array( '%d' )
		);

		$this->post(
			array(
				'action'      => 'nowcrm_save_automation',
				'id'          => $automation,
				'name'        => 'Rewrite fixture',
				'trigger_key' => TriggerRegistry::ORDER_COMPLETED,
				'steps_json'  => (string) wp_json_encode(
					array(
						array( 'kind' => 'wait', 'delay_seconds' => 60 ),
						array( 'kind' => 'end' ),
					)
				),
				'_wpnonce'    => wp_create_nonce( 'nowcrm_save_automation' ),
			)
		);

		$this->capture_redirect(
			static function (): void {
				Actions::save_automation();
			}
		);

		$after = $this->automations->steps( $automation );

		$this->assertCount( 2, $after );
		$this->assertNotContains( (int) $before[0]['id'], array_map( 'intval', array_column( $after, 'id' ) ), 'An edited graph must replace the step rows.' );
		$this->assertSame( (int) $after[0]['id'], (int) $this->runs->get( $run )['next_step_id'], 'A rewritten graph must remap the run pointer.' );
	}

	public function test_ordinary_save_keeps_paused_status_and_explicit_activation_wins(): void {
		$automation = $this->make_automation( 'Paused fixture' );
		$steps      = array( array( 'kind' => 'end' ) );

		$this->assertTrue( $this->automations->set_status( $automation, AutomationRepository::STATUS_PAUSED ) );

		$this->post(
			array(
				'action'      => 'nowcrm_save_automation',
				'id'          => $automation,
				'name'        => 'Paused rename',
				'trigger_key' => TriggerRegistry::ORDER_COMPLETED,
				'status'      => AutomationRepository::STATUS_ACTIVE,
				'steps_json'  => (string) wp_json_encode( $steps ),
				'_wpnonce'    => wp_create_nonce( 'nowcrm_save_automation' ),
			)
		);

		$this->capture_redirect(
			static function (): void {
				Actions::save_automation();
			}
		);

		$saved = $this->automations->get( $automation );

		$this->assertSame( AutomationRepository::STATUS_PAUSED, $saved['status'], 'A stale status field must not restart a paused flow.' );
		$this->assertSame( 'Paused rename', $saved['name'], 'An ordinary save must still update the metadata.' );

		$this->post(
			array(
				'action'      => 'nowcrm_save_automation',
				'id'          => $automation,
				'name'        => 'Paused rename',
				'trigger_key' => TriggerRegistry::ORDER_COMPLETED,
				'save_status' => AutomationRepository::STATUS_ACTIVE,
				'steps_json'  => (string) wp_json_encode( $steps ),
				'_wpnonce'    => wp_create_nonce( 'nowcrm_save_automation' ),
			)
		);

		$this->capture_redirect(
			static function (): void {
				Actions::save_automation();
			}
		);

		$this->assertSame( AutomationRepository::STATUS_ACTIVE, $this->automations->get( $automation )['status'], 'save_status=active must activate the flow.' );
	}

	public function test_paused_sequence_reference_survives_edit_and_copy(): void {
		$sequence = $this->make_sequence( SequenceRepository::STATUS_ACTIVE );
		$steps    = array(
			array( 'kind' => 'action', 'action_key' => 'add_to_sequence', 'config' => array( 'sequence_id' => $sequence ) ),
			array( 'kind' => 'end' ),
		);

		$automation = (int) $this->automations->save(
			array(
				'name'         => 'Paused sequence fixture',
				'status'       => AutomationRepository::STATUS_DRAFT,
				'trigger_key'  => TriggerRegistry::ORDER_COMPLETED,
				'entry_config' => array( 'run_once' => false ),
				'steps'        => $steps,
			)
		);

		$this->assertTrue( ( new SequenceRepository() )->set_status( $sequence, SequenceRepository::STATUS_PAUSED ) );

		$this->post(
			array(
				'action'      => 'nowcrm_save_automation',
				'id'          => $automation,
				'name'        => 'Paused sequence fixture',
				'trigger_key' => TriggerRegistry::ORDER_COMPLETED,
				'steps_json'  => (string) wp_json_encode( $steps ),
				'_wpnonce'    => wp_create_nonce( 'nowcrm_save_automation' ),
			)
		);

		$location = $this->capture_redirect(
			static function (): void {
				Actions::save_automation();
			}
		);

		$this->assertSame( 'automation_saved', $this->query_args( $location )['nowcrm_notice'] ?? '' );
		$this->assertSame( $sequence, (int) $this->automations->steps( $automation )[0]['config']['sequence_id'], 'An existing paused reference must survive an edit.' );

		$this->post(
			array(
				'action'   => 'nowcrm_duplicate_automation',
				'id'       => $automation,
				'_wpnonce' => wp_create_nonce( 'nowcrm_duplicate_automation' ),
			)
		);

		$copy_args = $this->query_args(
			$this->capture_redirect(
				static function (): void {
					Actions::duplicate_automation();
				}
			)
		);
		$copy      = (int) ( $copy_args['id'] ?? 0 );

		$this->assertSame( Menu::AUTOMATION_SLUG, $copy_args['page'] ?? '' );
		$this->assertGreaterThan( 0, $copy );
		$this->assertSame( $sequence, (int) $this->automations->steps( $copy )[0]['config']['sequence_id'], 'A copy must keep the paused reference.' );
		$this->assertSame( AutomationRepository::STATUS_DRAFT, $this->automations->get( $copy )['status'] );
	}

	public function test_new_inactive_sequence_choice_is_rejected(): void {
		$sequence   = $this->make_sequence( SequenceRepository::STATUS_ACTIVE );
		$automation = $this->make_automation( 'New inactive pick' );

		$this->assertTrue( ( new SequenceRepository() )->set_status( $sequence, SequenceRepository::STATUS_PAUSED ) );

		$before = $this->automations->steps( $automation );

		$this->post(
			array(
				'action'      => 'nowcrm_save_automation',
				'id'          => $automation,
				'name'        => 'New inactive pick',
				'trigger_key' => TriggerRegistry::ORDER_COMPLETED,
				'nowcrm_json' => '1',
				'steps_json'  => (string) wp_json_encode(
					array(
						array( 'kind' => 'action', 'action_key' => 'add_to_sequence', 'config' => array( 'sequence_id' => $sequence ) ),
						array( 'kind' => 'end' ),
					)
				),
				'_wpnonce'    => wp_create_nonce( 'nowcrm_save_automation' ),
			)
		);

		$data = $this->capture_json_error(
			static function (): void {
				Actions::save_automation();
			}
		);

		$this->assertStringContainsString( '序列', (string) ( $data['message'] ?? '' ) );
		$this->assertSame( array_column( $before, 'id' ), array_column( $this->automations->steps( $automation ), 'id' ), 'A rejected new pick must not replace the steps.' );
	}

	public function test_list_row_actions_keep_the_automation_filters(): void {
		$automation = $this->make_automation( 'Filter fixture' );

		$this->post(
			array(
				'action'        => 'nowcrm_set_automation_status',
				'id'            => $automation,
				'status'        => AutomationRepository::STATUS_PAUSED,
				'filter_search' => 'alice smith',
				'filter_status' => AutomationRepository::STATUS_PAUSED,
				'filter_paged'  => '3',
				'_wpnonce'      => wp_create_nonce( 'nowcrm_set_automation_status' ),
			)
		);

		$location = $this->capture_redirect(
			static function (): void {
				Actions::set_automation_status();
			}
		);
		$args     = $this->query_args( $location );

		$this->assertSame( Menu::AUTOMATIONS_SLUG, $args['page'] ?? '' );
		$this->assertSame( 'automation_status_saved', $args['nowcrm_notice'] ?? '' );
		$this->assertSame( 'alice smith', $args['filter_search'] ?? '' );
		$this->assertSame( AutomationRepository::STATUS_PAUSED, $args['filter_status'] ?? '' );
		$this->assertSame( '3', $args['filter_paged'] ?? '' );

		$this->post(
			array(
				'action'        => 'nowcrm_delete_automation',
				'id'            => $automation,
				'filter_search' => '',
				'filter_status' => 'all',
				'filter_paged'  => '1',
				'_wpnonce'      => wp_create_nonce( 'nowcrm_delete_automation' ),
			)
		);

		$location = $this->capture_redirect(
			static function (): void {
				Actions::delete_automation();
			}
		);
		$args     = $this->query_args( $location );

		$this->assertSame( 'deleted', $args['nowcrm_notice'] ?? '' );
		$this->assertArrayNotHasKey( 'filter_search', $args, 'An empty search must not survive the redirect.' );
		$this->assertArrayNotHasKey( 'filter_status', $args, 'The "all" status must not survive the redirect.' );
		$this->assertArrayNotHasKey( 'filter_paged', $args, 'The first page must not survive the redirect.' );
	}
}
