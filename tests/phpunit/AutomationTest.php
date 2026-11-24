<?php
/**
 * Automation persistence, triggers, runs, branches, and email queueing.
 *
 * @package NowCRM
 */

use NowCRM\Automation\AutomationRepository;
use NowCRM\Automation\AutomationScheduler;
use NowCRM\Automation\Engine;
use NowCRM\Automation\RunRepository;
use NowCRM\Automation\TriggerRegistry;
use NowCRM\Contacts\Status;
use NowCRM\Email\EmailJobRepository;
use NowCRM\Email\Queue;
use NowCRM\Email\SuppressionRepository;
use NowCRM\Sequences\SequenceRepository;
use NowCRM\Sequences\TrackerRepository;
use NowCRM\Support\Schema;
use NowCRM\Terms\TermRepository;

/**
 * @covers \NowCRM\Automation\Engine
 * @covers \NowCRM\Automation\AutomationRepository
 */
final class AutomationTest extends NowCrmTestCase {

	private AutomationRepository $automations;
	private RunRepository $runs;
	private Engine $engine;

	public function set_up(): void {
		parent::set_up();

		$this->automations = new AutomationRepository();
		$this->runs        = new RunRepository();
		$this->engine      = new Engine();

		$this->set_settings( array( 'email_sending_enabled' => false ) );
		wp_clear_scheduled_hook( AutomationScheduler::HOOK );
		self::clear_birthday_schedule();
	}

	/**
	 * Drop any pending birthday scan, including Action Scheduler rows that the
	 * test transaction cannot roll back.
	 */
	private static function clear_birthday_schedule(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( AutomationScheduler::BIRTHDAY_HOOK, array(), AutomationScheduler::GROUP );
		}

		wp_clear_scheduled_hook( AutomationScheduler::BIRTHDAY_HOOK );
	}

	/**
	 * Whether a birthday scan is queued on either scheduler.
	 */
	private static function birthday_scan_scheduled(): bool {
		if ( function_exists( 'as_next_scheduled_action' ) && did_action( 'action_scheduler_init' ) ) {
			return false !== as_next_scheduled_action( AutomationScheduler::BIRTHDAY_HOOK, array(), AutomationScheduler::GROUP );
		}

		return false !== wp_next_scheduled( AutomationScheduler::BIRTHDAY_HOOK );
	}

	/**
	 * Today's date as the site renders it.
	 */
	private static function today(): string {
		return (string) wp_date( 'Y-m-d' );
	}

	/**
	 * A date-of-birth string on today's month and day, in a leap year.
	 */
	private static function birthday_today(): string {
		$bits = explode( '-', self::today() );

		return '2000-' . $bits[1] . '-' . $bits[2];
	}

	/**
	 * A date-of-birth string that cannot match today.
	 */
	private static function birthday_other_day(): string {
		$bits = explode( '-', self::today() );

		return '1995-' . ( '01' === $bits[1] ? '02' : '01' ) . '-15';
	}

	/**
	 * Create one active automation.
	 *
	 * @param array<int, mixed>    $steps     Steps.
	 * @param array<string, mixed> $overrides Row overrides.
	 */
	private function make_automation( array $steps, array $overrides = array() ): int {
		$id = $this->automations->save(
			array_merge(
				array(
					'name'        => 'Fixture automation ' . wp_generate_password( 6, false ),
					'status'      => AutomationRepository::STATUS_ACTIVE,
					'trigger_key' => TriggerRegistry::ORDER_COMPLETED,
					'entry_config' => array( 'run_once' => false ),
					'steps'       => $steps,
				),
				$overrides
			)
		);

		$this->assertIsInt( $id );

		return (int) $id;
	}

	/**
	 * Start one run directly and return it.
	 *
	 * @return array<string, mixed>
	 */
	private function start_run( int $automation_id, int $contact_id ): array {
		$automation = $this->automations->get( $automation_id );
		$contact    = $this->contacts->get( $contact_id );

		$this->assertIsArray( $automation );
		$this->assertIsArray( $contact );

		$result = $this->engine->start( $automation, $contact, TriggerRegistry::ORDER_COMPLETED, 'fixture:' . $automation_id );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['started'] );

		return $result['run'];
	}

	private function make_tag( string $name ): int {
		$id = $this->terms->create( TermRepository::TYPE_TAG, $name );

		$this->assertIsInt( $id );

		return (int) $id;
	}

	private function has_term( int $contact_id, int $term_id ): bool {
		global $wpdb;

		$table = Schema::table( 'contact_terms' );

		return (bool) $wpdb->get_var(
			$wpdb->prepare( "SELECT 1 FROM {$table} WHERE contact_id = %d AND term_id = %d", $contact_id, $term_id )
		);
	}

	/**
	 * Status plan used by branch tests.
	 *
	 * @param string $field Field.
	 * @param string $value Value.
	 * @return array<string, mixed>
	 */
	private function plan( string $field, string $value ): array {
		return array(
			'version' => 1,
			'groups'  => array(
				array(
					array(
						'provider' => 'contact',
						'field'    => $field,
						'operator' => '=',
						'value'    => $value,
					),
				),
			),
		);
	}

	public function test_trigger_starts_linear_run_and_actions_complete_it(): void {
		$tag = $this->make_tag( 'Linear tag' );

		$automation = $this->make_automation(
			array(
				array( 'kind' => 'action', 'action_key' => 'apply_tag', 'config' => array( 'term_id' => $tag ) ),
				array( 'kind' => 'end' ),
			),
			array( 'trigger_key' => TriggerRegistry::CONTACT_CREATED )
		);

		$contact = $this->make_contact();
		$run     = $this->runs->find( $automation, $contact );

		$this->assertIsArray( $run, 'The contact_created hook must create a durable run.' );
		$this->assertSame( RunRepository::STATUS_ACTIVE, $run['status'] );
		$this->assertFalse( $this->has_term( $contact, $tag ), 'No action may run at hook time.' );

		$this->engine->run_due();

		$run = $this->runs->get( (int) $run['id'] );

		$this->assertSame( RunRepository::STATUS_COMPLETED, $run['status'] );
		$this->assertTrue( $this->has_term( $contact, $tag ) );
		$this->assertCount( 2, $this->runs->events( (int) $run['id'] ) );
	}

	public function test_wait_pauses_then_resumes_when_due(): void {
		$tag    = $this->make_tag( 'Wait tag' );
		$contact = $this->make_contact();

		$automation = $this->make_automation(
			array(
				array( 'kind' => 'wait', 'delay_seconds' => 3600 ),
				array( 'kind' => 'action', 'action_key' => 'apply_tag', 'config' => array( 'term_id' => $tag ) ),
				array( 'kind' => 'end' ),
			)
		);

		$run = $this->start_run( $automation, $contact );
		$this->engine->run_due();

		$run = $this->runs->get( (int) $run['id'] );

		$this->assertSame( RunRepository::STATUS_WAITING, $run['status'] );
		$this->assertGreaterThan( time(), strtotime( $run['next_run_at'] . ' UTC' ) );
		$this->assertFalse( $this->has_term( $contact, $tag ) );

		global $wpdb;
		$wpdb->update(
			Schema::table( 'automation_runs' ),
			array( 'next_run_at' => gmdate( 'Y-m-d H:i:s', time() - 5 ) ),
			array( 'id' => (int) $run['id'] ),
			array( '%s' ),
			array( '%d' )
		);

		$this->engine->run_due();

		$run = $this->runs->get( (int) $run['id'] );

		$this->assertSame( RunRepository::STATUS_COMPLETED, $run['status'] );
		$this->assertTrue( $this->has_term( $contact, $tag ) );
	}

	public function test_yes_and_no_branches_and_branch_exhaustion(): void {
		$yes_tag = $this->make_tag( 'Yes tag' );
		$no_tag  = $this->make_tag( 'No tag' );
		$tail    = $this->make_tag( 'Tail tag' );

		$yes = $this->make_contact( array( 'first_name' => 'Yes' ) );
		$no  = $this->make_contact( array( 'first_name' => 'No' ) );

		$automation = $this->make_automation(
			array(
				array(
					'kind'    => 'condition',
					'config'  => array( 'conditions' => $this->plan( 'first_name', 'Yes' ) ),
					'yes'     => array(
						array( 'kind' => 'action', 'action_key' => 'apply_tag', 'config' => array( 'term_id' => $yes_tag ) ),
					),
					'no'      => array(
						array( 'kind' => 'action', 'action_key' => 'apply_tag', 'config' => array( 'term_id' => $no_tag ) ),
					),
				),
				array( 'kind' => 'action', 'action_key' => 'apply_tag', 'config' => array( 'term_id' => $tail ) ),
				array( 'kind' => 'end' ),
			)
		);

		$this->start_run( $automation, $yes );
		$this->start_run( $automation, $no );
		$this->engine->run_due();

		$this->assertTrue( $this->has_term( $yes, $yes_tag ) );
		$this->assertFalse( $this->has_term( $yes, $no_tag ) );
		$this->assertTrue( $this->has_term( $yes, $tail ), 'Traversal must resume after the parent condition.' );

		$this->assertTrue( $this->has_term( $no, $no_tag ) );
		$this->assertFalse( $this->has_term( $no, $yes_tag ) );
		$this->assertTrue( $this->has_term( $no, $tail ) );
	}

	public function test_run_once_blocks_and_default_reentry_resets(): void {
		$tag     = $this->make_tag( 'Reentry tag' );
		$contact = $this->make_contact();

		$once = $this->make_automation(
			array(
				array( 'kind' => 'action', 'action_key' => 'apply_tag', 'config' => array( 'term_id' => $tag ) ),
				array( 'kind' => 'end' ),
			),
			array( 'entry_config' => array( 'run_once' => true ) )
		);

		$this->start_run( $once, $contact );
		$this->engine->run_due();

		$before = $this->runs->find( $once, $contact );
		$count  = ( new TriggerRegistry() )->fire( TriggerRegistry::ORDER_COMPLETED, array( 'contact_id' => $contact, 'source_ref' => 'order:2' ) );

		$this->assertSame( 0, $count );
		$this->assertSame( $before['started_at'], $this->runs->find( $once, $contact )['started_at'] );

		$again = $this->make_automation(
			array(
				array( 'kind' => 'action', 'action_key' => 'apply_tag', 'config' => array( 'term_id' => $tag ) ),
				array( 'kind' => 'end' ),
			)
		);

		$this->start_run( $again, $contact );
		$this->engine->run_due();
		$first = $this->runs->find( $again, $contact );

		$result = ( new TriggerRegistry() )->fire( TriggerRegistry::ORDER_COMPLETED, array( 'contact_id' => $contact, 'source_ref' => 'order:3' ) );

		$this->assertSame( 1, $result );
		$this->assertSame( RunRepository::STATUS_ACTIVE, $this->runs->find( $again, $contact )['status'] );
		$this->assertGreaterThanOrEqual( $first['started_at'], $this->runs->find( $again, $contact )['started_at'] );
	}

	public function test_idempotent_retry_does_not_repeat_an_action(): void {
		$tag     = $this->make_tag( 'Idempotent tag' );
		$contact = $this->make_contact();

		$automation = $this->make_automation(
			array(
				array( 'kind' => 'action', 'action_key' => 'apply_tag', 'config' => array( 'term_id' => $tag ) ),
				array( 'kind' => 'end' ),
			)
		);

		$run    = $this->start_run( $automation, $contact );
		$step   = $this->automations->steps( $automation )[0];
		$this->engine->run_due();

		global $wpdb;
		$wpdb->update(
			Schema::table( 'automation_runs' ),
			array(
				'status'      => RunRepository::STATUS_ACTIVE,
				'next_step_id' => (int) $step['id'],
				'next_run_at' => gmdate( 'Y-m-d H:i:s' ),
			),
			array( 'id' => (int) $run['id'] ),
			array( '%s', '%d', '%s' ),
			array( '%d' )
		);

		$this->engine->run_due();

		$this->assertCount( 2, $this->runs->events( (int) $run['id'] ) );
		$this->assertTrue( $this->has_term( $contact, $tag ) );
	}

	public function test_unsubscribed_contact_cancels_before_actions(): void {
		$tag     = $this->make_tag( 'Cancelled tag' );
		$contact = $this->make_contact();

		$automation = $this->make_automation(
			array(
				array( 'kind' => 'action', 'action_key' => 'apply_tag', 'config' => array( 'term_id' => $tag ) ),
				array( 'kind' => 'end' ),
			)
		);

		$run = $this->start_run( $automation, $contact );
		$this->contacts->set_status( $contact, Status::UNSUBSCRIBED, 'test' );

		$this->engine->run_due();

		$run = $this->runs->get( (int) $run['id'] );

		$this->assertSame( RunRepository::STATUS_CANCELLED, $run['status'] );
		$this->assertSame( 'not_subscribed', $run['last_error'] );
		$this->assertFalse( $this->has_term( $contact, $tag ) );
	}

	public function test_list_status_and_tag_actions_mutate_the_contact(): void {
		$list = $this->terms->create( TermRepository::TYPE_LIST, 'Automation list' );
		$tag  = $this->make_tag( 'Remove me' );

		$this->assertIsInt( $list );

		$contact = $this->make_contact();
		$this->terms->attach( $contact, $tag );

		$automation = $this->make_automation(
			array(
				array( 'kind' => 'action', 'action_key' => 'apply_list', 'config' => array( 'term_id' => $list ) ),
				array( 'kind' => 'action', 'action_key' => 'set_status', 'config' => array( 'status' => Status::TRANSACTIONAL ) ),
				array( 'kind' => 'action', 'action_key' => 'remove_tag', 'config' => array( 'term_id' => $tag ) ),
				array( 'kind' => 'end' ),
			)
		);

		$run = $this->start_run( $automation, $contact );
		$this->engine->run_due();

		$this->assertTrue( $this->has_term( $contact, (int) $list ) );
		$this->assertFalse( $this->has_term( $contact, $tag ) );
		$this->assertSame( Status::TRANSACTIONAL, $this->contacts->get( $contact )['status'] );
		$this->assertSame( RunRepository::STATUS_COMPLETED, $this->runs->get( (int) $run['id'] )['status'] );
	}

	public function test_email_step_queues_one_automation_job_when_sending_is_disabled(): void {
		$contact = $this->make_contact();

		$automation = $this->make_automation(
			array(
				array(
					'kind'   => 'email',
					'config' => array(
						'subject' => 'Automation hello',
						'content' => '<p>Hello {{first_name}}</p>',
					),
				),
				array( 'kind' => 'end' ),
			)
		);

		$run = $this->start_run( $automation, $contact );
		$this->engine->run_due();

		global $wpdb;
		$table = Schema::table( 'email_jobs' );
		$jobs  = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE kind = %s AND contact_id = %d", 'automation', $contact ),
			ARRAY_A
		);

		$this->assertCount( 1, $jobs );
		$this->assertSame( EmailJobRepository::STATUS_QUEUED, $jobs[0]['status'] );
		$this->assertStringContainsString( 'Automation hello', $jobs[0]['payload'] );
		$this->assertStringContainsString( 'Hello {{first_name}}', $jobs[0]['payload'] );

		( new Queue() )->run();

		$job = ( new EmailJobRepository() )->get( (int) $jobs[0]['id'] );

		$this->assertSame( EmailJobRepository::STATUS_QUEUED, $job['status'] );
		$this->assertSame( RunRepository::STATUS_COMPLETED, $this->runs->get( (int) $run['id'] )['status'] );
	}

	public function test_term_attachment_hook_starts_a_run(): void {
		$tag = $this->make_tag( 'Hook tag' );
		$contact = $this->make_contact();

		$automation = $this->make_automation(
			array(
				array( 'kind' => 'action', 'action_key' => 'apply_tag', 'config' => array( 'term_id' => $tag ) ),
				array( 'kind' => 'end' ),
			),
			array(
				'trigger_key'    => TriggerRegistry::TERM_ATTACHED,
				'trigger_config' => array( 'term_ids' => array( $tag ) ),
			)
		);

		$this->terms->attach( $contact, $tag );

		$run = $this->runs->find( $automation, $contact );

		$this->assertIsArray( $run );

		$this->engine->run_due();

		$this->assertSame( RunRepository::STATUS_COMPLETED, $this->runs->get( (int) $run['id'] )['status'] );
	}

	public function test_editor_tree_round_trips_branches_and_email(): void {
		$tag       = $this->make_tag( 'Tree tag' );
		$automation = $this->make_automation(
			array(
				array(
					'kind'   => 'condition',
					'config' => array( 'conditions' => $this->plan( 'first_name', 'Yes' ) ),
					'yes'    => array(
						array( 'kind' => 'action', 'action_key' => 'apply_tag', 'config' => array( 'term_id' => $tag ) ),
						array( 'kind' => 'wait', 'delay_seconds' => 120 ),
					),
					'no'     => array(
						array(
							'kind'   => 'email',
							'config' => array(
								'subject' => 'No branch',
								'mode'    => 'html',
								'content' => '<p>No</p>',
							),
						),
					),
				),
				array( 'kind' => 'action', 'action_key' => 'set_status', 'config' => array( 'status' => 'transactional' ) ),
				array( 'kind' => 'end' ),
			)
		);

		$tree = $this->automations->nested_steps( $automation );

		$this->assertSame( 'condition', $tree[0]['kind'] );
		$this->assertSame( 'apply_tag', $tree[0]['yes'][0]['action_key'] );
		$this->assertSame( 120, $tree[0]['yes'][1]['delay_seconds'] );
		$this->assertSame( 'email', $tree[0]['no'][0]['kind'] );
		$this->assertSame( 'set_status', $tree[1]['action_key'] );

		$saved = $this->automations->save(
			array(
				'name'           => 'Edited tree',
				'status'         => AutomationRepository::STATUS_DRAFT,
				'trigger_key'    => TriggerRegistry::ORDER_COMPLETED,
				'trigger_config' => array( 'sources' => array( 'woocommerce' ) ),
				'entry_config'   => array( 'run_once' => true ),
				'steps'          => $tree,
			),
			$automation
		);

		$this->assertIsInt( $saved );

		$round = $this->automations->nested_steps( $automation );

		$this->assertSame( $tree, $round );
		$this->assertSame( 0, $this->automations->run_counts( $automation )[ RunRepository::STATUS_ACTIVE ] );
	}

	public function test_malformed_editor_payload_does_not_change_stored_steps(): void {
		$tag        = $this->make_tag( 'Stable tag' );
		$automation = $this->make_automation(
			array(
				array( 'kind' => 'action', 'action_key' => 'apply_tag', 'config' => array( 'term_id' => $tag ) ),
				array( 'kind' => 'end' ),
			),
			array( 'name' => 'Stable automation' )
		);

		$bad = $this->automations->save(
			array(
				'name'        => 'Broken automation',
				'status'      => AutomationRepository::STATUS_ACTIVE,
				'trigger_key' => TriggerRegistry::ORDER_COMPLETED,
				'steps'       => array(
					array(
						'kind'   => 'condition',
						'config' => array( 'conditions' => array( 'version' => 1, 'groups' => array() ) ),
					),
				),
			),
			$automation
		);

		$this->assertWPError( $bad );
		$this->assertSame( 'Stable automation', $this->automations->get( $automation )['name'] );
		$this->assertCount( 2, $this->automations->steps( $automation ) );

		$unknown = $this->automations->save(
			array(
				'name'        => 'Unknown step',
				'status'      => AutomationRepository::STATUS_DRAFT,
				'trigger_key' => TriggerRegistry::ORDER_COMPLETED,
				'steps'       => array( array( 'kind' => 'goal', 'action_key' => 'goal' ) ),
			),
			$automation
		);

		$this->assertWPError( $unknown );
		$this->assertCount( 2, $this->automations->steps( $automation ) );
	}

	public function test_birthday_and_login_triggers_are_registered(): void {
		$triggers = TriggerRegistry::triggers();

		$this->assertSame( 'contact_birthday', TriggerRegistry::CONTACT_BIRTHDAY );
		$this->assertSame( 'wp_login', TriggerRegistry::WP_LOGIN );
		$this->assertArrayHasKey( TriggerRegistry::CONTACT_BIRTHDAY, $triggers );
		$this->assertArrayHasKey( TriggerRegistry::WP_LOGIN, $triggers );
		$this->assertSame( '聯絡人生日', $triggers[ TriggerRegistry::CONTACT_BIRTHDAY ] );
		$this->assertSame( '使用者登入', $triggers[ TriggerRegistry::WP_LOGIN ] );
	}

	public function test_birthday_scan_fires_only_for_todays_contacts(): void {
		$tag = $this->make_tag( 'Birthday tag' );

		$automation = $this->make_automation(
			array(
				array( 'kind' => 'action', 'action_key' => 'apply_tag', 'config' => array( 'term_id' => $tag ) ),
				array( 'kind' => 'end' ),
			),
			array( 'trigger_key' => TriggerRegistry::CONTACT_BIRTHDAY )
		);

		$match = $this->make_contact( array( 'date_of_birth' => self::birthday_today() ) );
		$miss  = $this->make_contact( array( 'date_of_birth' => self::birthday_other_day() ) );
		$none  = $this->make_contact();

		$this->assertSame( 1, ( new TriggerRegistry() )->fire_birthdays() );

		$this->assertIsArray( $this->runs->find( $automation, $match ) );
		$this->assertNull( $this->runs->find( $automation, $miss ) );
		$this->assertNull( $this->runs->find( $automation, $none ) );

		$this->engine->run_due();

		$run = $this->runs->find( $automation, $match );

		$this->assertSame( RunRepository::STATUS_COMPLETED, $run['status'] );
		$this->assertSame( 'birthday:' . self::today(), $run['source_ref'] );
		$this->assertTrue( $this->has_term( $match, $tag ) );
		$this->assertFalse( $this->has_term( $miss, $tag ) );
	}

	public function test_birthday_scan_does_not_restart_the_same_day(): void {
		$tag = $this->make_tag( 'Birthday repeat tag' );

		$automation = $this->make_automation(
			array(
				array( 'kind' => 'action', 'action_key' => 'apply_tag', 'config' => array( 'term_id' => $tag ) ),
				array( 'kind' => 'end' ),
			),
			array( 'trigger_key' => TriggerRegistry::CONTACT_BIRTHDAY )
		);

		$contact  = $this->make_contact( array( 'date_of_birth' => self::birthday_today() ) );
		$triggers = new TriggerRegistry();

		$this->assertSame( 1, $triggers->fire_birthdays() );
		$this->engine->run_due();

		$run = $this->runs->find( $automation, $contact );

		$this->assertSame( RunRepository::STATUS_COMPLETED, $run['status'] );
		$this->assertSame( 0, $triggers->fire_birthdays(), 'A second scan on the same day must not restart the run.' );
		$this->assertSame( (int) $run['id'], (int) $this->runs->find( $automation, $contact )['id'] );

		$this->engine->run_due();

		$this->assertCount( 2, $this->runs->events( (int) $run['id'] ) );
		$this->assertSame( RunRepository::STATUS_COMPLETED, $this->runs->get( (int) $run['id'] )['status'] );
	}

	public function test_birthday_scan_is_scheduled_only_while_active(): void {
		$this->make_automation(
			array( array( 'kind' => 'end' ) ),
			array(
				'trigger_key' => TriggerRegistry::CONTACT_BIRTHDAY,
				'status'      => AutomationRepository::STATUS_PAUSED,
			)
		);

		AutomationScheduler::sync();

		$this->assertFalse( self::birthday_scan_scheduled() );

		$this->make_automation(
			array( array( 'kind' => 'end' ) ),
			array( 'trigger_key' => TriggerRegistry::CONTACT_BIRTHDAY )
		);

		AutomationScheduler::sync();

		$this->assertTrue( self::birthday_scan_scheduled() );
	}

	public function test_inactive_automations_do_not_fire(): void {
		$tag = $this->make_tag( 'Inactive tag' );

		$paused_birthday = $this->make_automation(
			array(
				array( 'kind' => 'action', 'action_key' => 'apply_tag', 'config' => array( 'term_id' => $tag ) ),
				array( 'kind' => 'end' ),
			),
			array(
				'trigger_key' => TriggerRegistry::CONTACT_BIRTHDAY,
				'status'      => AutomationRepository::STATUS_PAUSED,
			)
		);

		$paused_login = $this->make_automation(
			array( array( 'kind' => 'action', 'action_key' => 'apply_tag', 'config' => array( 'term_id' => $tag ) ) ),
			array(
				'trigger_key' => TriggerRegistry::WP_LOGIN,
				'status'      => AutomationRepository::STATUS_PAUSED,
			)
		);

		$birthday_contact = $this->make_contact( array( 'date_of_birth' => self::birthday_today() ) );
		$user_id          = self::factory()->user->create(
			array(
				'user_login' => 'nowcrm-inactive',
				'user_email' => 'inactive@example.test',
			)
		);
		$login_contact = $this->make_contact( array( 'wp_user_id' => (int) $user_id, 'email' => 'inactivelogin@example.test' ) );

		$this->assertSame( 0, ( new TriggerRegistry() )->fire_birthdays() );
		self::fire_login( get_userdata( $user_id ) );

		$this->assertNull( $this->runs->find( $paused_birthday, $birthday_contact ) );
		$this->assertNull( $this->runs->find( $paused_login, $login_contact ) );
	}

	public function test_birthday_run_respects_contact_eligibility(): void {
		$tag = $this->make_tag( 'Unsubscribed birthday tag' );

		$automation = $this->make_automation(
			array(
				array( 'kind' => 'action', 'action_key' => 'apply_tag', 'config' => array( 'term_id' => $tag ) ),
				array( 'kind' => 'end' ),
			),
			array( 'trigger_key' => TriggerRegistry::CONTACT_BIRTHDAY )
		);

		$contact = $this->make_contact(
			array(
				'date_of_birth' => self::birthday_today(),
				'status'        => Status::UNSUBSCRIBED,
			)
		);

		$this->assertSame( 1, ( new TriggerRegistry() )->fire_birthdays() );

		$run = $this->runs->find( $automation, $contact );

		$this->assertIsArray( $run );
		$this->assertFalse( $this->has_term( $contact, $tag ) );

		$this->engine->run_due();

		$this->assertSame( RunRepository::STATUS_CANCELLED, $this->runs->get( (int) $run['id'] )['status'] );
		$this->assertFalse( $this->has_term( $contact, $tag ) );
	}

	public function test_login_trigger_resolves_the_contact_and_ignores_unknown_users(): void {
		$tag = $this->make_tag( 'Login tag' );

		$automation = $this->make_automation(
			array(
				array( 'kind' => 'action', 'action_key' => 'apply_tag', 'config' => array( 'term_id' => $tag ) ),
				array( 'kind' => 'end' ),
			),
			array( 'trigger_key' => TriggerRegistry::WP_LOGIN )
		);

		$user_id = self::factory()->user->create(
			array(
				'user_login' => 'nowcrm-member',
				'user_email' => 'member@example.test',
			)
		);

		$by_id   = $this->make_contact( array( 'wp_user_id' => (int) $user_id, 'email' => 'alias@example.test' ) );
		$by_mail = $this->make_contact( array( 'email' => 'member@example.test' ) );

		self::fire_login( get_userdata( $user_id ) );

		$run = $this->runs->find( $automation, $by_id );

		$this->assertIsArray( $run, 'A successful login must resolve the contact by wp_user_id.' );
		$this->assertNull( $this->runs->find( $automation, $by_mail ), 'wp_user_id must win over an email match.' );

		$this->engine->run_due();

		$this->assertTrue( $this->has_term( $by_id, $tag ) );
		$this->assertFalse( $this->has_term( $by_mail, $tag ) );

		$stranger = self::factory()->user->create(
			array(
				'user_login' => 'nowcrm-stranger',
				'user_email' => 'stranger@example.test',
			)
		);

		self::fire_login( get_userdata( $stranger ) );
		do_action( 'wp_login_failed', 'nowcrm-member' );

		$this->assertSame( 1, $this->login_run_count( $automation ), 'Users without a contact and failed logins must not fire.' );
	}

	public function test_login_trigger_falls_back_to_email(): void {
		$tag = $this->make_tag( 'Login email tag' );

		$automation = $this->make_automation(
			array(
				array( 'kind' => 'action', 'action_key' => 'apply_tag', 'config' => array( 'term_id' => $tag ) ),
				array( 'kind' => 'end' ),
			),
			array( 'trigger_key' => TriggerRegistry::WP_LOGIN )
		);

		$user_id = self::factory()->user->create(
			array(
				'user_login' => 'nowcrm-emailonly',
				'user_email' => 'emailonly@example.test',
			)
		);

		$contact = $this->make_contact( array( 'email' => 'emailonly@example.test' ) );

		self::fire_login( get_userdata( $user_id ) );

		$this->assertIsArray( $this->runs->find( $automation, $contact ) );

		$this->engine->run_due();

		$this->assertTrue( $this->has_term( $contact, $tag ) );
	}

	/**
	 * Fire the WordPress login action the way core does.
	 */
	private static function fire_login( \WP_User $user ): void {
		do_action( 'wp_login', $user->user_login, $user );
	}

	public function test_goal_parks_until_matching_term_event_and_ignores_others(): void {
		$goal   = $this->make_tag( 'Goal tag' );
		$other  = $this->make_tag( 'Other tag' );
		$marker = $this->make_tag( 'Goal marker' );
		$contact = $this->make_contact();

		$automation = $this->make_automation(
			array(
				array( 'kind' => 'goal', 'config' => array( 'event' => 'term_attached', 'term_ids' => array( $goal ) ) ),
				array( 'kind' => 'action', 'action_key' => 'apply_tag', 'config' => array( 'term_id' => $marker ) ),
				array( 'kind' => 'end' ),
			)
		);

		$steps = $this->automations->steps( $automation );
		$run   = $this->start_run( $automation, $contact );

		$this->engine->run_due();

		$run = $this->runs->get( (int) $run['id'] );

		$this->assertSame( RunRepository::STATUS_WAITING, $run['status'] );
		$this->assertNull( $run['next_run_at'] );
		$this->assertFalse( $this->has_term( $contact, $marker ) );
		$this->assertCount( 1, $this->runs->parked_goals( $contact ) );

		$triggers = new TriggerRegistry();

		$this->assertSame( 0, $triggers->fire( TriggerRegistry::TERM_ATTACHED, array( 'contact_id' => $contact, 'term_id' => $other, 'term_type' => TermRepository::TYPE_TAG ) ) );
		$this->engine->run_due();

		$this->assertSame( RunRepository::STATUS_WAITING, $this->runs->get( (int) $run['id'] )['status'] );
		$this->assertFalse( $this->has_term( $contact, $marker ) );

		$triggers->fire( TriggerRegistry::TERM_ATTACHED, array( 'contact_id' => $contact, 'term_id' => $goal, 'term_type' => TermRepository::TYPE_TAG ) );
		$this->engine->run_due();

		$run = $this->runs->get( (int) $run['id'] );

		$this->assertSame( RunRepository::STATUS_COMPLETED, $run['status'] );
		$this->assertTrue( $this->has_term( $contact, $marker ) );
		$this->assertSame( array(), $this->runs->parked_goals( $contact ) );

		// A repeated event must not resume or replay the goal.
		$triggers->fire( TriggerRegistry::TERM_ATTACHED, array( 'contact_id' => $contact, 'term_id' => $goal, 'term_type' => TermRepository::TYPE_TAG ) );
		$this->engine->run_due();

		$this->assertSame( RunRepository::STATUS_COMPLETED, $this->runs->get( (int) $run['id'] )['status'] );
		$this->assertCount( 3, $this->runs->events( (int) $run['id'] ) );
		$this->assertSame( RunRepository::EVENT_COMPLETED, $this->runs->event_for_step( (int) $run['id'], (int) $steps[0]['id'] )['status'] );
	}

	public function test_goal_before_reaching_it_uses_current_term_evidence(): void {
		$goal    = $this->make_tag( 'Existing goal' );
		$marker  = $this->make_tag( 'Evidence marker' );
		$contact = $this->make_contact();

		$this->terms->attach( $contact, $goal );

		$automation = $this->make_automation(
			array(
				array( 'kind' => 'goal', 'config' => array( 'event' => 'term_attached', 'term_ids' => array( $goal ) ) ),
				array( 'kind' => 'action', 'action_key' => 'apply_tag', 'config' => array( 'term_id' => $marker ) ),
				array( 'kind' => 'end' ),
			)
		);

		$run  = $this->start_run( $automation, $contact );
		$step = $this->automations->steps( $automation )[0];

		$this->engine->run_due();

		$run = $this->runs->get( (int) $run['id'] );

		$this->assertSame( RunRepository::STATUS_COMPLETED, $run['status'] );
		$this->assertTrue( $this->has_term( $contact, $marker ) );

		$event = $this->runs->event_for_step( (int) $run['id'], (int) $step['id'] );

		$this->assertSame( RunRepository::EVENT_COMPLETED, $event['status'] );
		$this->assertTrue( (bool) $event['payload']['satisfied'] );
	}

	public function test_goal_status_change_resumes_and_a_second_change_does_nothing(): void {
		$marker  = $this->make_tag( 'Status goal marker' );
		$contact = $this->make_contact();

		$automation = $this->make_automation(
			array(
				array( 'kind' => 'goal', 'config' => array( 'event' => 'status_changed', 'statuses' => array( Status::TRANSACTIONAL ) ) ),
				array( 'kind' => 'action', 'action_key' => 'apply_tag', 'config' => array( 'term_id' => $marker ) ),
				array( 'kind' => 'end' ),
			)
		);

		$run = $this->start_run( $automation, $contact );

		$this->engine->run_due();
		$this->assertSame( RunRepository::STATUS_WAITING, $this->runs->get( (int) $run['id'] )['status'] );

		$this->contacts->set_status( $contact, Status::TRANSACTIONAL, 'test' );
		$this->engine->run_due();

		$this->assertSame( RunRepository::STATUS_COMPLETED, $this->runs->get( (int) $run['id'] )['status'] );
		$this->assertTrue( $this->has_term( $contact, $marker ) );

		$this->contacts->set_status( $contact, Status::SUBSCRIBED, 'test' );
		$this->contacts->set_status( $contact, Status::TRANSACTIONAL, 'test' );
		$this->engine->run_due();

		$this->assertCount( 3, $this->runs->events( (int) $run['id'] ) );
	}

	public function test_sequential_goals_resume_one_at_a_time_in_order(): void {
		$first   = $this->make_tag( 'First goal' );
		$second  = $this->make_tag( 'Second goal' );
		$marker1 = $this->make_tag( 'First marker' );
		$marker2 = $this->make_tag( 'Second marker' );
		$contact = $this->make_contact();

		$automation = $this->make_automation(
			array(
				array( 'kind' => 'goal', 'config' => array( 'event' => 'term_attached', 'term_ids' => array( $first ) ) ),
				array( 'kind' => 'action', 'action_key' => 'apply_tag', 'config' => array( 'term_id' => $marker1 ) ),
				array( 'kind' => 'goal', 'config' => array( 'event' => 'term_attached', 'term_ids' => array( $second ) ) ),
				array( 'kind' => 'action', 'action_key' => 'apply_tag', 'config' => array( 'term_id' => $marker2 ) ),
				array( 'kind' => 'end' ),
			)
		);

		$run      = $this->start_run( $automation, $contact );
		$triggers = new TriggerRegistry();

		$this->engine->run_due();
		$this->assertSame( RunRepository::STATUS_WAITING, $this->runs->get( (int) $run['id'] )['status'] );

		// The later goal's event must not skip the first goal.
		$triggers->fire( TriggerRegistry::TERM_ATTACHED, array( 'contact_id' => $contact, 'term_id' => $second ) );
		$this->engine->run_due();

		$this->assertFalse( $this->has_term( $contact, $marker1 ) );
		$this->assertSame( RunRepository::STATUS_WAITING, $this->runs->get( (int) $run['id'] )['status'] );

		$triggers->fire( TriggerRegistry::TERM_ATTACHED, array( 'contact_id' => $contact, 'term_id' => $first ) );
		$this->engine->run_due();

		$this->assertTrue( $this->has_term( $contact, $marker1 ) );
		$this->assertFalse( $this->has_term( $contact, $marker2 ) );
		$this->assertSame( RunRepository::STATUS_WAITING, $this->runs->get( (int) $run['id'] )['status'] );

		$triggers->fire( TriggerRegistry::TERM_ATTACHED, array( 'contact_id' => $contact, 'term_id' => $second ) );
		$this->engine->run_due();

		$this->assertTrue( $this->has_term( $contact, $marker2 ) );
		$this->assertSame( RunRepository::STATUS_COMPLETED, $this->runs->get( (int) $run['id'] )['status'] );
	}

	public function test_goal_config_is_validated_and_round_trips(): void {
		$tag = $this->make_tag( 'Goal validation' );

		$missing = $this->automations->save(
			array(
				'name'        => 'Goal without event',
				'status'      => AutomationRepository::STATUS_DRAFT,
				'trigger_key' => TriggerRegistry::ORDER_COMPLETED,
				'steps'       => array( array( 'kind' => 'goal', 'config' => array() ) ),
			)
		);

		$this->assertWPError( $missing );

		$bad_term = $this->automations->save(
			array(
				'name'        => 'Goal without term',
				'status'      => AutomationRepository::STATUS_DRAFT,
				'trigger_key' => TriggerRegistry::ORDER_COMPLETED,
				'steps'       => array( array( 'kind' => 'goal', 'config' => array( 'event' => 'term_attached' ) ) ),
			)
		);

		$this->assertWPError( $bad_term );

		$bad_status = $this->automations->save(
			array(
				'name'        => 'Goal with bad status',
				'status'      => AutomationRepository::STATUS_DRAFT,
				'trigger_key' => TriggerRegistry::ORDER_COMPLETED,
				'steps'       => array( array( 'kind' => 'goal', 'config' => array( 'event' => 'status_changed', 'statuses' => array( 'nope' ) ) ) ),
			)
		);

		$this->assertWPError( $bad_status );

		$saved = $this->make_automation(
			array(
				array( 'kind' => 'goal', 'config' => array( 'event' => 'term_attached', 'term_ids' => array( $tag ) ) ),
				array( 'kind' => 'end' ),
			),
			array( 'name' => 'Valid goal' )
		);

		$tree = $this->automations->nested_steps( $saved );

		$this->assertSame( 'goal', $tree[0]['kind'] );
		$this->assertSame( 'term_attached', $tree[0]['config']['event'] );
		$this->assertSame( array( $tag ), $tree[0]['config']['term_ids'] );
	}

	public function test_split_routes_each_run_to_one_branch_and_continues_after_it(): void {
		$a_tag = $this->make_tag( 'Split A' );
		$b_tag = $this->make_tag( 'Split B' );
		$tail  = $this->make_tag( 'Split tail' );

		$automation = $this->make_automation(
			array(
				array(
					'kind'   => 'split',
					'config' => array( 'weight_a' => 50, 'weight_b' => 50 ),
					'a'      => array(
						array( 'kind' => 'action', 'action_key' => 'apply_tag', 'config' => array( 'term_id' => $a_tag ) ),
					),
					'b'      => array(
						array( 'kind' => 'action', 'action_key' => 'apply_tag', 'config' => array( 'term_id' => $b_tag ) ),
					),
				),
				array( 'kind' => 'action', 'action_key' => 'apply_tag', 'config' => array( 'term_id' => $tail ) ),
				array( 'kind' => 'end' ),
			)
		);

		$split_id = 0;

		foreach ( $this->automations->steps( $automation ) as $step ) {
			if ( 'split' === $step['kind'] ) {
				$split_id = (int) $step['id'];
			}
		}

		$this->assertGreaterThan( 0, $split_id );

		$contacts = array();

		for ( $index = 0; $index < 6; $index++ ) {
			$contact    = $this->make_contact();
			$contacts[] = $contact;
			$this->start_run( $automation, $contact );
		}

		$this->engine->run_due();

		foreach ( $contacts as $contact ) {
			$run    = $this->runs->find( $automation, $contact );
			$event  = $this->runs->event_for_step( (int) $run['id'], $split_id );
			$branch = (string) ( $event['payload']['branch'] ?? '' );

			$this->assertContains( $branch, array( 'a', 'b' ) );
			$this->assertSame( 50, $event['payload']['weight_a'] );
			$this->assertSame( 50, $event['payload']['weight_b'] );
			$this->assertSame( 'a' === $branch, $this->has_term( $contact, $a_tag ) );
			$this->assertSame( 'b' === $branch, $this->has_term( $contact, $b_tag ) );
			$this->assertTrue( $this->has_term( $contact, $tail ), 'Traversal must resume after the split.' );
			$this->assertSame( RunRepository::STATUS_COMPLETED, $this->runs->get( (int) $run['id'] )['status'] );
		}
	}

	public function test_split_zero_and_full_weights_force_one_side(): void {
		$a_tag = $this->make_tag( 'Forced A' );
		$b_tag = $this->make_tag( 'Forced B' );

		$all_b = $this->make_automation(
			array(
				array(
					'kind'   => 'split',
					'config' => array( 'weight_a' => 0, 'weight_b' => 100 ),
					'a'      => array(
						array( 'kind' => 'action', 'action_key' => 'apply_tag', 'config' => array( 'term_id' => $a_tag ) ),
					),
					'b'      => array(
						array( 'kind' => 'action', 'action_key' => 'apply_tag', 'config' => array( 'term_id' => $b_tag ) ),
					),
				),
				array( 'kind' => 'end' ),
			)
		);

		$all_a = $this->make_automation(
			array(
				array(
					'kind'   => 'split',
					'config' => array( 'weight_a' => 100, 'weight_b' => 0 ),
					'a'      => array(
						array( 'kind' => 'action', 'action_key' => 'apply_tag', 'config' => array( 'term_id' => $a_tag ) ),
					),
					'b'      => array(
						array( 'kind' => 'action', 'action_key' => 'apply_tag', 'config' => array( 'term_id' => $b_tag ) ),
					),
				),
				array( 'kind' => 'end' ),
			)
		);

		$b_contacts = array();
		$a_contacts = array();

		for ( $index = 0; $index < 3; $index++ ) {
			$contact      = $this->make_contact();
			$b_contacts[] = $contact;
			$this->start_run( $all_b, $contact );

			$other      = $this->make_contact();
			$a_contacts[] = $other;
			$this->start_run( $all_a, $other );
		}

		$this->engine->run_due();

		foreach ( $b_contacts as $contact ) {
			$this->assertTrue( $this->has_term( $contact, $b_tag ) );
			$this->assertFalse( $this->has_term( $contact, $a_tag ) );
		}

		foreach ( $a_contacts as $contact ) {
			$this->assertTrue( $this->has_term( $contact, $a_tag ) );
			$this->assertFalse( $this->has_term( $contact, $b_tag ) );
		}
	}

	public function test_split_retry_keeps_the_recorded_branch(): void {
		$a_tag = $this->make_tag( 'Retry A' );
		$b_tag = $this->make_tag( 'Retry B' );

		$automation = $this->make_automation(
			array(
				array(
					'kind'   => 'split',
					'config' => array( 'weight_a' => 50, 'weight_b' => 50 ),
					'a'      => array(
						array( 'kind' => 'action', 'action_key' => 'apply_tag', 'config' => array( 'term_id' => $a_tag ) ),
					),
					'b'      => array(
						array( 'kind' => 'action', 'action_key' => 'apply_tag', 'config' => array( 'term_id' => $b_tag ) ),
					),
				),
				array( 'kind' => 'end' ),
			)
		);

		$contact = $this->make_contact();
		$run     = $this->start_run( $automation, $contact );
		$split   = 0;

		foreach ( $this->automations->steps( $automation ) as $step ) {
			if ( 'split' === $step['kind'] ) {
				$split = (int) $step['id'];
			}
		}

		$this->engine->run_due();

		$first = $this->runs->event_for_step( (int) $run['id'], $split );

		$this->assertIsArray( $first );
		$this->assertContains( $first['payload']['branch'], array( 'a', 'b' ) );

		global $wpdb;
		$wpdb->update(
			Schema::table( 'automation_runs' ),
			array(
				'status'       => RunRepository::STATUS_ACTIVE,
				'next_step_id' => $split,
				'next_run_at'  => gmdate( 'Y-m-d H:i:s' ),
			),
			array( 'id' => (int) $run['id'] ),
			array( '%s', '%d', '%s' ),
			array( '%d' )
		);

		$this->engine->run_due();

		$second = $this->runs->event_for_step( (int) $run['id'], $split );

		$this->assertSame( $first['payload']['branch'], $second['payload']['branch'] );
		$this->assertSame( $first['payload']['point'], $second['payload']['point'] );
		$this->assertSame( 'a' === $second['payload']['branch'], $this->has_term( $contact, $a_tag ) );
		$this->assertSame( 'b' === $second['payload']['branch'], $this->has_term( $contact, $b_tag ) );
		$this->assertSame( RunRepository::STATUS_COMPLETED, $this->runs->get( (int) $run['id'] )['status'] );
	}

	public function test_split_config_is_validated_and_round_trips_with_nested_steps(): void {
		$bad_sum = $this->automations->save(
			array(
				'name'        => 'Split bad sum',
				'status'      => AutomationRepository::STATUS_DRAFT,
				'trigger_key' => TriggerRegistry::ORDER_COMPLETED,
				'steps'       => array( array( 'kind' => 'split', 'config' => array( 'weight_a' => 60, 'weight_b' => 50 ) ) ),
			)
		);

		$this->assertWPError( $bad_sum );

		$bad_range = $this->automations->save(
			array(
				'name'        => 'Split bad range',
				'status'      => AutomationRepository::STATUS_DRAFT,
				'trigger_key' => TriggerRegistry::ORDER_COMPLETED,
				'steps'       => array( array( 'kind' => 'split', 'config' => array( 'weight_a' => 120, 'weight_b' => -20 ) ) ),
			)
		);

		$this->assertWPError( $bad_range );

		$half = $this->automations->save(
			array(
				'name'        => 'Split half config',
				'status'      => AutomationRepository::STATUS_DRAFT,
				'trigger_key' => TriggerRegistry::ORDER_COMPLETED,
				'steps'       => array( array( 'kind' => 'split', 'config' => array( 'weight_a' => 50 ) ) ),
			)
		);

		$this->assertWPError( $half );

		$tag = $this->make_tag( 'Split tree' );

		$automation = $this->make_automation(
			array(
				array(
					'kind'   => 'split',
					'config' => array( 'weight_a' => 70, 'weight_b' => 30 ),
					'a'      => array(
						array(
							'kind'   => 'condition',
							'config' => array( 'conditions' => $this->plan( 'first_name', 'Tree' ) ),
							'yes'    => array(
								array( 'kind' => 'action', 'action_key' => 'apply_tag', 'config' => array( 'term_id' => $tag ) ),
							),
							'no'     => array(),
						),
						array( 'kind' => 'wait', 'delay_seconds' => 60 ),
					),
					'b'      => array(
						array(
							'kind'   => 'email',
							'config' => array( 'subject' => 'Split B', 'content' => '<p>B</p>' ),
						),
					),
				),
				array( 'kind' => 'end' ),
			)
		);

		$tree = $this->automations->nested_steps( $automation );

		$this->assertSame( 'split', $tree[0]['kind'] );
		$this->assertSame( 70, $tree[0]['config']['weight_a'] );
		$this->assertSame( 30, $tree[0]['config']['weight_b'] );
		$this->assertSame( 'condition', $tree[0]['a'][0]['kind'] );
		$this->assertSame( 'apply_tag', $tree[0]['a'][0]['yes'][0]['action_key'] );
		$this->assertSame( 60, $tree[0]['a'][1]['delay_seconds'] );
		$this->assertSame( 'email', $tree[0]['b'][0]['kind'] );

		$saved = $this->automations->save(
			array(
				'name'        => 'Split round trip',
				'status'      => AutomationRepository::STATUS_DRAFT,
				'trigger_key' => TriggerRegistry::ORDER_COMPLETED,
				'steps'       => $tree,
			),
			$automation
		);

		$this->assertIsInt( $saved );
		$this->assertSame( $tree, $this->automations->nested_steps( $automation ) );
	}

	public function test_split_branch_email_and_empty_branch_exhaustion(): void {
		$tail = $this->make_tag( 'Empty branch tail' );

		$automation = $this->make_automation(
			array(
				array(
					'kind'   => 'split',
					'config' => array( 'weight_a' => 100, 'weight_b' => 0 ),
					'a'      => array(),
					'b'      => array(
						array(
							'kind'   => 'email',
							'config' => array( 'subject' => 'Split branch mail', 'content' => '<p>B</p>' ),
						),
					),
				),
				array( 'kind' => 'action', 'action_key' => 'apply_tag', 'config' => array( 'term_id' => $tail ) ),
				array( 'kind' => 'end' ),
			)
		);

		$contact = $this->make_contact();
		$this->start_run( $automation, $contact );
		$this->engine->run_due();

		global $wpdb;
		$jobs = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . Schema::table( 'email_jobs' ) . ' WHERE kind = %s AND contact_id = %d',
				'automation',
				$contact
			),
			ARRAY_A
		);

		$this->assertCount( 0, $jobs, 'The forced empty A branch must not run the B email.' );
		$this->assertTrue( $this->has_term( $contact, $tail ) );
		$this->assertSame( RunRepository::STATUS_COMPLETED, $this->runs->get( (int) $this->runs->find( $automation, $contact )['id'] )['status'] );
	}

	public function test_split_branch_can_send_email(): void {
		$automation = $this->make_automation(
			array(
				array(
					'kind'   => 'split',
					'config' => array( 'weight_a' => 0, 'weight_b' => 100 ),
					'a'      => array(),
					'b'      => array(
						array(
							'kind'   => 'email',
							'config' => array( 'subject' => 'Split B mail', 'content' => '<p>B</p>' ),
						),
					),
				),
				array( 'kind' => 'end' ),
			)
		);

		$contact = $this->make_contact();
		$this->start_run( $automation, $contact );
		$this->engine->run_due();

		global $wpdb;
		$jobs = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . Schema::table( 'email_jobs' ) . ' WHERE kind = %s AND contact_id = %d',
				'automation',
				$contact
			),
			ARRAY_A
		);

		$this->assertCount( 1, $jobs );
		$this->assertSame( EmailJobRepository::STATUS_QUEUED, $jobs[0]['status'] );
		$this->assertStringContainsString( 'Split B mail', $jobs[0]['payload'] );
	}

	/**
	 * One active single-step sequence for enrollment fixtures.
	 */
	private function make_active_sequence(): int {
		$id = ( new SequenceRepository() )->create(
			array(
				'name'          => 'Automation target ' . wp_generate_password( 6, false ),
				'status'        => SequenceRepository::STATUS_ACTIVE,
				'allow_reentry' => false,
				'audience'      => array( 'type' => 'all' ),
				'steps'         => array(
					array(
						'name'          => 'Welcome',
						'subject'       => 'Sequence welcome',
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

	public function test_add_to_sequence_action_enrolls_one_contact_and_skips_a_duplicate(): void {
		$sequence   = $this->make_active_sequence();
		$trackers   = new TrackerRepository();
		$automation = $this->make_automation(
			array(
				array( 'kind' => 'action', 'action_key' => 'add_to_sequence', 'config' => array( 'sequence_id' => $sequence ) ),
				array( 'kind' => 'end' ),
			)
		);

		$steps   = $this->automations->steps( $automation );
		$contact = $this->make_contact();
		$run     = $this->start_run( $automation, $contact );

		$this->engine->run_due();

		$event = $this->runs->event_for_step( (int) $run['id'], (int) $steps[0]['id'] );

		$this->assertSame( RunRepository::EVENT_COMPLETED, $event['status'] );
		$this->assertSame( $sequence, (int) $event['payload']['sequence_id'] );
		$this->assertNotNull( $trackers->find_active( $sequence, $contact ) );
		$this->assertSame( RunRepository::STATUS_COMPLETED, $this->runs->get( (int) $run['id'] )['status'] );

		$again = $this->engine->start( $this->automations->get( $automation ), $this->contacts->get( $contact ), TriggerRegistry::ORDER_COMPLETED, 'fixture-again' );

		$this->assertIsArray( $again );
		$this->assertTrue( $again['started'] );

		// The restart clock is monotonic, so the reset run can be one second
		// ahead. Make it due now instead of waiting for the clock.
		global $wpdb;

		$wpdb->update(
			Schema::table( 'automation_runs' ),
			array( 'next_run_at' => gmdate( 'Y-m-d H:i:s' ) ),
			array( 'id' => (int) $again['run']['id'] ),
			array( '%s' ),
			array( '%d' )
		);

		$this->engine->run_due();

		$event = $this->runs->event_for_step( (int) $again['run']['id'], (int) $steps[0]['id'] );

		$this->assertSame( RunRepository::EVENT_SKIPPED, $event['status'] );
		$this->assertSame( 'duplicate_enrollment', $event['payload']['reason'] );
		$this->assertCount( 1, $trackers->active_for_sequence( $sequence ) );
	}

	public function test_add_to_sequence_action_skips_unsubscribed_and_suppressed_contacts(): void {
		$sequence   = $this->make_active_sequence();
		$trackers   = new TrackerRepository();
		$automation = $this->make_automation(
			array(
				array( 'kind' => 'action', 'action_key' => 'add_to_sequence', 'config' => array( 'sequence_id' => $sequence ) ),
				array( 'kind' => 'end' ),
			),
			array( 'entry_config' => array( 'run_once' => false, 'force_run' => true ) )
		);

		$steps   = $this->automations->steps( $automation );
		$contact = $this->make_contact();

		$this->contacts->set_status( $contact, Status::UNSUBSCRIBED, 'test' );

		$run = $this->start_run( $automation, $contact );

		$this->engine->run_due();

		$event = $this->runs->event_for_step( (int) $run['id'], (int) $steps[0]['id'] );

		$this->assertSame( RunRepository::EVENT_SKIPPED, $event['status'] );
		$this->assertSame( 'not_subscribed', $event['payload']['reason'] );
		$this->assertNull( $trackers->find_active( $sequence, $contact ) );

		$suppressed = $this->make_contact();
		$email      = (string) $this->contacts->get( $suppressed )['email'];

		$this->assertTrue( ( new SuppressionRepository() )->add( $email, 'bounce', 'test', $suppressed ) );

		$run = $this->start_run( $automation, $suppressed );

		$this->engine->run_due();

		$event = $this->runs->event_for_step( (int) $run['id'], (int) $steps[0]['id'] );

		$this->assertSame( RunRepository::EVENT_SKIPPED, $event['status'] );
		$this->assertSame( 'suppressed_address', $event['payload']['reason'] );
		$this->assertNull( $trackers->find_active( $sequence, $suppressed ) );
	}

	public function test_catalog_searches_literally_counts_statuses_and_clamps_page(): void {
		$draft = $this->make_automation(
			array( array( 'kind' => 'end' ) ),
			array( 'name' => '0 onboarding', 'status' => AutomationRepository::STATUS_DRAFT )
		);
		$active = $this->make_automation(
			array( array( 'kind' => 'end' ) ),
			array( 'name' => 'Alpha route', 'status' => AutomationRepository::STATUS_ACTIVE )
		);
		$this->make_automation(
			array( array( 'kind' => 'end' ) ),
			array( 'name' => 'Beta route', 'status' => AutomationRepository::STATUS_PAUSED )
		);

		$all = $this->automations->catalog( '', '', 1, 2 );

		$this->assertSame( 3, $all['counts']['all'] );
		$this->assertSame( 1, $all['counts'][ AutomationRepository::STATUS_DRAFT ] );
		$this->assertSame( 1, $all['counts'][ AutomationRepository::STATUS_ACTIVE ] );
		$this->assertSame( 1, $all['counts'][ AutomationRepository::STATUS_PAUSED ] );
		$this->assertSame( 3, $all['total'] );
		$this->assertSame( 2, $all['pages'] );
		$this->assertCount( 2, $all['items'] );

		$clamped = $this->automations->catalog( '', '', 99, 2 );

		$this->assertSame( 2, $clamped['page'] );
		$this->assertCount( 1, $clamped['items'] );

		$found = $this->automations->catalog( '0', '', 1, 20 );

		$this->assertSame( 1, $found['total'] );
		$this->assertSame( $draft, (int) $found['items'][0]['id'] );

		$this->assertSame( 0, $this->automations->catalog( '%', '', 1, 20 )['total'], 'LIKE wildcards in the search text must be escaped.' );

		$active_only = $this->automations->catalog( 'route', AutomationRepository::STATUS_ACTIVE, 1, 20 );

		$this->assertSame( 1, $active_only['total'] );
		$this->assertSame( $active, (int) $active_only['items'][0]['id'] );
	}

	public function test_required_goal_timeout_takes_branch_and_records_timeout(): void {
		$goal    = $this->make_tag( 'Timeout goal' );
		$marker  = $this->make_tag( 'Timeout marker' );
		$contact = $this->make_contact();

		$automation = $this->make_automation(
			array(
				array(
					'kind'    => 'goal',
					'config'  => array(
						'event'           => AutomationRepository::GOAL_TERM_ATTACHED,
						'term_ids'        => array( $goal ),
						'mode'            => AutomationRepository::GOAL_MODE_REQUIRED,
						'timeout_seconds' => 600,
					),
					'timeout' => array(
						array( 'kind' => 'action', 'action_key' => 'apply_tag', 'config' => array( 'term_id' => $marker ) ),
					),
				),
				array( 'kind' => 'end' ),
			)
		);

		$steps = $this->automations->steps( $automation );
		$run   = $this->start_run( $automation, $contact );

		$this->engine->run_due();
		$run = $this->runs->get( (int) $run['id'] );

		$this->assertSame( RunRepository::STATUS_WAITING, $run['status'] );
		$this->assertNotNull( $run['next_run_at'], 'A required goal with a timeout must have a due time.' );
		$this->assertSame( (int) $steps[0]['id'], (int) $run['next_step_id'], 'The goal step stays next until the deadline.' );
		$this->assertFalse( $this->has_term( $contact, $marker ) );

		// Force the deadline into the past instead of waiting for it.
		global $wpdb;

		$event   = $this->runs->event_for_step( (int) $run['id'], (int) $steps[0]['id'] );
		$payload = (array) $event['payload'];
		$past    = gmdate( 'Y-m-d H:i:s', time() - 60 );
		$payload['deadline'] = $past;

		$wpdb->update(
			Schema::table( 'automation_run_events' ),
			array( 'payload' => wp_json_encode( $payload ) ),
			array( 'id' => (int) $event['id'] ),
			array( '%s' ),
			array( '%d' )
		);
		$wpdb->update(
			Schema::table( 'automation_runs' ),
			array( 'next_run_at' => $past ),
			array( 'id' => (int) $run['id'] ),
			array( '%s' ),
			array( '%d' )
		);

		$this->engine->run_due();

		$run = $this->runs->get( (int) $run['id'] );

		$this->assertSame( RunRepository::STATUS_COMPLETED, $run['status'] );
		$this->assertTrue( $this->has_term( $contact, $marker ) );

		$event = $this->runs->event_for_step( (int) $run['id'], (int) $steps[0]['id'] );

		$this->assertSame( RunRepository::EVENT_TIMEOUT, $event['status'] );
		$this->assertTrue( (bool) $event['payload']['timed_out'] );
	}

	public function test_goal_event_before_deadline_skips_the_timeout_branch(): void {
		$goal    = $this->make_tag( 'Early goal' );
		$marker  = $this->make_tag( 'Early marker' );
		$contact = $this->make_contact();

		$automation = $this->make_automation(
			array(
				array(
					'kind'    => 'goal',
					'config'  => array(
						'event'           => AutomationRepository::GOAL_TERM_ATTACHED,
						'term_ids'        => array( $goal ),
						'mode'            => AutomationRepository::GOAL_MODE_REQUIRED,
						'timeout_seconds' => 600,
					),
					'timeout' => array(
						array( 'kind' => 'action', 'action_key' => 'apply_tag', 'config' => array( 'term_id' => $marker ) ),
					),
				),
				array( 'kind' => 'end' ),
			)
		);

		$steps = $this->automations->steps( $automation );
		$run   = $this->start_run( $automation, $contact );

		$this->engine->run_due();

		( new TriggerRegistry() )->fire(
			TriggerRegistry::TERM_ATTACHED,
			array( 'contact_id' => $contact, 'term_id' => $goal, 'term_type' => TermRepository::TYPE_TAG )
		);
		$this->engine->run_due();

		$run = $this->runs->get( (int) $run['id'] );

		$this->assertSame( RunRepository::STATUS_COMPLETED, $run['status'] );
		$this->assertFalse( $this->has_term( $contact, $marker ), 'A goal reached before the deadline must not run the timeout branch.' );

		$event = $this->runs->event_for_step( (int) $run['id'], (int) $steps[0]['id'] );

		$this->assertSame( RunRepository::EVENT_COMPLETED, $event['status'] );
		$this->assertTrue( (bool) $event['payload']['satisfied'] );
	}

	public function test_optional_goal_continues_without_waiting(): void {
		$goal    = $this->make_tag( 'Optional goal' );
		$marker  = $this->make_tag( 'Optional marker' );
		$contact = $this->make_contact();

		$automation = $this->make_automation(
			array(
				array(
					'kind'   => 'goal',
					'config' => array(
						'event'    => AutomationRepository::GOAL_TERM_ATTACHED,
						'term_ids' => array( $goal ),
						'mode'     => AutomationRepository::GOAL_MODE_OPTIONAL,
					),
				),
				array( 'kind' => 'action', 'action_key' => 'apply_tag', 'config' => array( 'term_id' => $marker ) ),
				array( 'kind' => 'end' ),
			)
		);

		$run = $this->start_run( $automation, $contact );

		$this->engine->run_due();

		$run = $this->runs->get( (int) $run['id'] );

		$this->assertSame( RunRepository::STATUS_COMPLETED, $run['status'] );
		$this->assertTrue( $this->has_term( $contact, $marker ), 'A non-blocking goal must continue to the next step.' );

		$steps = $this->automations->steps( $automation );
		$event = $this->runs->event_for_step( (int) $run['id'], (int) $steps[0]['id'] );

		$this->assertSame( RunRepository::EVENT_SKIPPED, $event['status'] );
	}

	public function test_cancel_is_one_way_and_retry_repeats_only_the_failed_step(): void {
		$wait_tag = $this->make_tag( 'Cancel marker' );
		$contact  = $this->make_contact();

		$automation = $this->make_automation(
			array(
				array( 'kind' => 'wait', 'delay_seconds' => 3600 ),
				array( 'kind' => 'action', 'action_key' => 'apply_tag', 'config' => array( 'term_id' => $wait_tag ) ),
				array( 'kind' => 'end' ),
			)
		);

		$run = $this->start_run( $automation, $contact );

		$this->engine->run_due();
		$this->assertSame( RunRepository::STATUS_WAITING, $this->runs->get( (int) $run['id'] )['status'] );

		$this->assertTrue( $this->runs->cancel_if_open( (int) $run['id'] ) );
		$this->assertFalse( $this->runs->cancel_if_open( (int) $run['id'] ), 'A second cancel must not change a terminal run.' );

		// Make the run due again; a cancelled run must not move.
		global $wpdb;

		$wpdb->update(
			Schema::table( 'automation_runs' ),
			array( 'next_run_at' => gmdate( 'Y-m-d H:i:s', time() - 5 ) ),
			array( 'id' => (int) $run['id'] ),
			array( '%s' ),
			array( '%d' )
		);

		$this->engine->run_due();

		$this->assertSame( RunRepository::STATUS_CANCELLED, $this->runs->get( (int) $run['id'] )['status'] );
		$this->assertFalse( $this->has_term( $contact, $wait_tag ) );

		$retry_tag  = $this->make_tag( 'Retry marker' );
		$automation = $this->make_automation(
			array(
				array( 'kind' => 'action', 'action_key' => 'apply_tag', 'config' => array( 'term_id' => $retry_tag ) ),
				array( 'kind' => 'end' ),
			)
		);

		$steps   = $this->automations->steps( $automation );
		$contact = $this->make_contact();
		$run     = $this->start_run( $automation, $contact );

		$this->runs->record_event( (int) $run['id'], (int) $steps[0]['id'], $contact, array( 'kind' => 'action' ), RunRepository::EVENT_FAILED );
		$this->runs->fail( (int) $run['id'], 'fixture failure' );

		$this->assertSame( RunRepository::STATUS_FAILED, $this->runs->get( (int) $run['id'] )['status'] );

		$retried = $this->runs->retry_failed( (int) $run['id'] );

		$this->assertIsArray( $retried );
		$this->assertSame( RunRepository::STATUS_ACTIVE, $retried['status'] );

		$this->engine->run_due();

		$run = $this->runs->get( (int) $run['id'] );

		$this->assertSame( RunRepository::STATUS_COMPLETED, $run['status'] );
		$this->assertTrue( $this->has_term( $contact, $retry_tag ), 'Retry must run the failed step again.' );
		$this->assertCount( 2, $this->runs->events( (int) $run['id'] ), 'Retry must not duplicate completed step events.' );
		$this->assertWPError( $this->runs->retry_failed( (int) $run['id'] ), 'Only failed runs can be retried.' );
	}

	public function test_terminal_root_wait_parks_once_and_completes_when_due(): void {
		$contact    = $this->make_contact();
		$automation = $this->make_automation( array( array( 'kind' => 'wait', 'delay_seconds' => 3600 ) ) );
		$steps      = $this->automations->steps( $automation );
		$run        = $this->start_run( $automation, $contact );

		$this->engine->run_due();

		$run = $this->runs->get( (int) $run['id'] );

		$this->assertSame( RunRepository::STATUS_WAITING, $run['status'] );
		$this->assertNull( $run['next_step_id'], 'A terminal wait must not invent a next step.' );
		$this->assertSame( (int) $steps[0]['id'], (int) $run['last_completed_step_id'] );
		$this->assertGreaterThan( time(), strtotime( $run['next_run_at'] . ' UTC' ) );
		$this->assertCount( 1, $this->runs->events( (int) $run['id'] ) );

		global $wpdb;

		$wpdb->update(
			Schema::table( 'automation_runs' ),
			array( 'next_run_at' => gmdate( 'Y-m-d H:i:s', time() - 5 ) ),
			array( 'id' => (int) $run['id'] ),
			array( '%s' ),
			array( '%d' )
		);

		$this->engine->run_due();

		$run = $this->runs->get( (int) $run['id'] );

		$this->assertSame( RunRepository::STATUS_COMPLETED, $run['status'] );
		$this->assertSame( (int) $steps[0]['id'], (int) $run['last_completed_step_id'], 'Completion must keep the wait step.' );
		$this->assertCount( 1, $this->runs->events( (int) $run['id'] ), 'The wait event must be written once.' );
	}

	public function test_terminal_branch_wait_parks_once_and_completes_when_due(): void {
		$contact = $this->make_contact( array( 'first_name' => 'Wait' ) );

		$automation = $this->make_automation(
			array(
				array(
					'kind'   => 'condition',
					'config' => array( 'conditions' => $this->plan( 'first_name', 'Wait' ) ),
					'yes'    => array( array( 'kind' => 'wait', 'delay_seconds' => 1800 ) ),
					'no'     => array(),
				),
			)
		);

		$wait  = null;
		$cond  = null;

		foreach ( $this->automations->steps( $automation ) as $step ) {
			if ( 'wait' === (string) $step['kind'] ) {
				$wait = $step;
			}

			if ( 'condition' === (string) $step['kind'] ) {
				$cond = $step;
			}
		}

		$this->assertIsArray( $wait );
		$this->assertIsArray( $cond );

		$run = $this->start_run( $automation, $contact );

		$this->engine->run_due();

		$run = $this->runs->get( (int) $run['id'] );

		$this->assertSame( RunRepository::STATUS_WAITING, $run['status'] );
		$this->assertNull( $run['next_step_id'], 'A terminal branch wait must not fall through early.' );
		$this->assertSame( (int) $wait['id'], (int) $run['last_completed_step_id'] );
		$this->assertGreaterThan( time(), strtotime( $run['next_run_at'] . ' UTC' ) );
		$this->assertCount( 2, $this->runs->events( (int) $run['id'] ), 'Only the condition and the wait may record events.' );

		global $wpdb;

		$wpdb->update(
			Schema::table( 'automation_runs' ),
			array( 'next_run_at' => gmdate( 'Y-m-d H:i:s', time() - 5 ) ),
			array( 'id' => (int) $run['id'] ),
			array( '%s' ),
			array( '%d' )
		);

		$this->engine->run_due();

		$run = $this->runs->get( (int) $run['id'] );

		$this->assertSame( RunRepository::STATUS_COMPLETED, $run['status'] );
		$this->assertSame( (int) $wait['id'], (int) $run['last_completed_step_id'] );
		$this->assertCount( 2, $this->runs->events( (int) $run['id'] ) );
	}

	public function test_goal_resume_is_atomic_and_a_due_deadline_rejects_it(): void {
		$goal   = $this->make_tag( 'Atomic goal' );
		$marker = $this->make_tag( 'Atomic timeout marker' );

		$automation = $this->make_automation(
			array(
				array(
					'kind'    => 'goal',
					'config'  => array(
						'event'           => AutomationRepository::GOAL_TERM_ATTACHED,
						'term_ids'        => array( $goal ),
						'mode'            => AutomationRepository::GOAL_MODE_REQUIRED,
						'timeout_seconds' => 600,
					),
					'timeout' => array(
						array( 'kind' => 'action', 'action_key' => 'apply_tag', 'config' => array( 'term_id' => $marker ) ),
					),
				),
				array( 'kind' => 'end' ),
			)
		);

		$steps = $this->automations->steps( $automation );
		$early = $this->make_contact();
		$run   = $this->start_run( $automation, $early );

		$this->engine->run_due();
		$this->assertSame( RunRepository::STATUS_WAITING, $this->runs->get( (int) $run['id'] )['status'] );

		$context = array(
			'contact_id' => $early,
			'term_id'    => $goal,
			'term_type'  => TermRepository::TYPE_TAG,
		);

		$this->assertSame( 1, $this->engine->resume_goals( TriggerRegistry::TERM_ATTACHED, $context ), 'A predeadline event must resume the goal.' );

		$run   = $this->runs->get( (int) $run['id'] );
		$event = $this->runs->event_for_step( (int) $run['id'], (int) $steps[0]['id'] );

		$this->assertSame( RunRepository::STATUS_ACTIVE, $run['status'] );
		$this->assertSame( RunRepository::EVENT_COMPLETED, $event['status'] );
		$this->assertTrue( (bool) $event['payload']['satisfied'] );
		$this->assertSame( TriggerRegistry::TERM_ATTACHED, (string) $event['payload']['resumed_by'] );
		$this->assertSame( 0, $this->engine->resume_goals( TriggerRegistry::TERM_ATTACHED, $context ), 'A repeated event must not resume the goal twice.' );

		$this->engine->run_due();

		$this->assertSame( RunRepository::STATUS_COMPLETED, $this->runs->get( (int) $run['id'] )['status'] );
		$this->assertFalse( $this->has_term( $early, $marker ), 'A predeadline goal must not take the timeout branch.' );

		// At or after the due time the deadline owns the run.
		$late = $this->make_contact();
		$run  = $this->start_run( $automation, $late );

		$this->engine->run_due();

		$event   = $this->runs->event_for_step( (int) $run['id'], (int) $steps[0]['id'] );
		$payload = (array) $event['payload'];
		$past    = gmdate( 'Y-m-d H:i:s', time() - 60 );

		$payload['deadline'] = $past;

		global $wpdb;

		$wpdb->update(
			Schema::table( 'automation_run_events' ),
			array( 'payload' => wp_json_encode( $payload ) ),
			array( 'id' => (int) $event['id'] ),
			array( '%s' ),
			array( '%d' )
		);
		$wpdb->update(
			Schema::table( 'automation_runs' ),
			array( 'next_run_at' => $past ),
			array( 'id' => (int) $run['id'] ),
			array( '%s' ),
			array( '%d' )
		);

		$this->assertSame(
			0,
			$this->engine->resume_goals(
				TriggerRegistry::TERM_ATTACHED,
				array(
					'contact_id' => $late,
					'term_id'    => $goal,
					'term_type'  => TermRepository::TYPE_TAG,
				)
			),
			'A goal at or after its due time must not resume.'
		);

		$this->assertSame( RunRepository::STATUS_WAITING, $this->runs->get( (int) $run['id'] )['status'] );
		$this->assertSame( RunRepository::EVENT_WAITING, $this->runs->event_for_step( (int) $run['id'], (int) $steps[0]['id'] )['status'] );

		$this->engine->run_due();

		$this->assertSame( RunRepository::STATUS_COMPLETED, $this->runs->get( (int) $run['id'] )['status'] );
		$this->assertTrue( $this->has_term( $late, $marker ), 'The due goal must take the timeout branch.' );
		$this->assertSame( RunRepository::EVENT_TIMEOUT, $this->runs->event_for_step( (int) $run['id'], (int) $steps[0]['id'] )['status'] );
	}

	public function test_run_history_keeps_runs_of_deleted_contacts(): void {
		$automation = $this->make_automation( array( array( 'kind' => 'end' ) ) );
		$kept       = $this->make_contact( array( 'first_name' => 'Kept', 'last_name' => 'Person' ) );
		$gone       = $this->make_contact( array( 'first_name' => 'Gone', 'last_name' => 'Person' ) );

		$this->start_run( $automation, $kept );
		$this->start_run( $automation, $gone );

		$this->assertTrue( $this->contacts->delete( $gone ) );

		$all = $this->runs->paginate_filtered( $automation );

		$this->assertSame( 2, $all['total'], 'Unfiltered history must count a run whose contact is gone.' );
		$this->assertCount( 2, $all['items'] );

		$active = $this->runs->paginate_filtered( $automation, array( 'status' => RunRepository::STATUS_ACTIVE ) );

		$this->assertSame( 2, $active['total'], 'Status filtering must not drop a run whose contact is gone.' );
		$this->assertCount( 2, $active['items'] );

		$search = $this->runs->paginate_filtered( $automation, array( 'search' => 'Gone' ) );

		$this->assertSame( 0, $search['total'], 'A contact search must not match a deleted contact.' );
		$this->assertCount( 0, $search['items'] );

		$found = $this->runs->paginate_filtered( $automation, array( 'search' => 'Kept' ) );

		$this->assertSame( 1, $found['total'] );
	}

	public function test_in_progress_step_fails_visibly_without_replay_and_rejects_retry(): void {
		$tag     = $this->make_tag( 'Interrupted marker' );
		$contact = $this->make_contact();

		$automation = $this->make_automation(
			array(
				array( 'kind' => 'action', 'action_key' => 'apply_tag', 'config' => array( 'term_id' => $tag ) ),
				array( 'kind' => 'end' ),
			)
		);

		$steps = $this->automations->steps( $automation );
		$run   = $this->start_run( $automation, $contact );

		// A worker stopped after writing the in-progress marker but before the
		// action settled, so the outcome is unknown.
		$this->runs->record_event( (int) $run['id'], (int) $steps[0]['id'], $contact, array( 'kind' => 'action' ), RunRepository::EVENT_IN_PROGRESS );

		$this->engine->run_due();

		$run = $this->runs->get( (int) $run['id'] );

		$this->assertSame( RunRepository::STATUS_FAILED, $run['status'] );
		$this->assertNotSame( '', (string) $run['last_error'] );
		$this->assertFalse( $this->has_term( $contact, $tag ), 'An unknown step must not be replayed.' );
		$this->assertSame( RunRepository::EVENT_IN_PROGRESS, $this->runs->event_for_step( (int) $run['id'], (int) $steps[0]['id'] )['status'] );

		$retry = $this->runs->retry_failed( (int) $run['id'] );

		$this->assertWPError( $retry );
		$this->assertSame( 'nowcrm_run_outcome_unknown', $retry->get_error_code() );
		$this->assertSame( RunRepository::STATUS_FAILED, $this->runs->get( (int) $run['id'] )['status'], 'A rejected retry must not wake the run.' );
	}

	public function test_throwing_action_is_marked_interrupted_and_cannot_be_retried(): void {
		$tag     = $this->make_tag( 'Throwing marker' );
		$contact = $this->make_contact();

		$automation = $this->make_automation(
			array(
				array( 'kind' => 'action', 'action_key' => 'apply_tag', 'config' => array( 'term_id' => $tag ) ),
				array( 'kind' => 'end' ),
			)
		);

		$steps = $this->automations->steps( $automation );
		$run   = $this->start_run( $automation, $contact );

		// The fixture throws after the tag is attached, so the effect happened
		// and the outcome is unknown to the engine.
		$thrower = static function (): void {
			throw new RuntimeException( 'fixture boom' );
		};

		add_action( 'nowcrm_term_attached', $thrower, 1 );

		try {
			$this->engine->run_due();
		} finally {
			remove_action( 'nowcrm_term_attached', $thrower, 1 );
		}

		$run   = $this->runs->get( (int) $run['id'] );
		$event = $this->runs->event_for_step( (int) $run['id'], (int) $steps[0]['id'] );

		$this->assertSame( RunRepository::STATUS_FAILED, $run['status'] );
		$this->assertStringContainsString( 'fixture boom', (string) $run['last_error'] );
		$this->assertTrue( $this->has_term( $contact, $tag ), 'The fixture throws after its effect.' );
		$this->assertSame( RunRepository::EVENT_INTERRUPTED, $event['status'] );

		$retry = $this->runs->retry_failed( (int) $run['id'] );

		$this->assertWPError( $retry );
		$this->assertSame( 'nowcrm_run_outcome_unknown', $retry->get_error_code() );

		$this->engine->run_due();

		$this->assertCount( 1, $this->runs->events( (int) $run['id'] ), 'A terminated run must not execute the step again.' );
	}

	public function test_branch_stats_use_entry_and_exit_events(): void {
		$marker = $this->make_tag( 'Stats tail' );

		$automation = $this->make_automation(
			array(
				array(
					'kind'   => 'split',
					'config' => array( 'weight_a' => 50, 'weight_b' => 50 ),
					'a'      => array( array( 'kind' => 'end' ) ),
					'b'      => array( array( 'kind' => 'action', 'action_key' => 'apply_tag', 'config' => array( 'term_id' => $marker ) ) ),
				),
				array( 'kind' => 'end' ),
			)
		);

		for ( $index = 0; $index < 40; $index++ ) {
			$this->start_run( $automation, $this->make_contact() );
		}

		$this->engine->run_due();

		$steps = $this->automations->steps( $automation );
		$stats = $this->runs->branch_stats( $automation, $steps );
		$split = $stats[ (int) $steps[0]['id'] ];

		$this->assertGreaterThan( 0, $split[ AutomationRepository::BRANCH_A ]['entered'] );
		$this->assertGreaterThan( 0, $split[ AutomationRepository::BRANCH_B ]['entered'] );
		$this->assertSame( 40, $split[ AutomationRepository::BRANCH_A ]['entered'] + $split[ AutomationRepository::BRANCH_B ]['entered'] );
		$this->assertSame( 0, $split[ AutomationRepository::BRANCH_A ]['completed'], 'A run that ends inside the branch has no exit event.' );
		$this->assertSame( $split[ AutomationRepository::BRANCH_B ]['entered'], $split[ AutomationRepository::BRANCH_B ]['completed'] );
	}

	/**
	 * Login-triggered runs of one automation.
	 */
	private function login_run_count( int $automation_id ): int {
		global $wpdb;

		$table = Schema::table( 'automation_runs' );

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE automation_id = %d AND source_trigger = %s",
				$automation_id,
				TriggerRegistry::WP_LOGIN
			)
		);
	}
}
