<?php
/**
 * Cart → Automation single-owner source identity baseline fixtures.
 *
 * Narrow fail-before coverage for the abandoned-cart integration:
 * one producer, one run per cart source, durable replay, and invalidation
 * of an unsent job. Broader cases live in the follow-up slice.
 *
 * @package NowCRM
 */

use NowCRM\Automation\AutomationRepository;
use NowCRM\Automation\Engine;
use NowCRM\Automation\RunRepository;
use NowCRM\Automation\TriggerRegistry;
use NowCRM\Cart\Automation as CartAutomation;
use NowCRM\Cart\CartRepository;
use NowCRM\Cart\Sweeper;
use NowCRM\Cart\Tracker;
use NowCRM\Contacts\Status;
use NowCRM\Email\EmailDispatcher;
use NowCRM\Email\EmailJobRepository;
use NowCRM\Email\Queue;
use NowCRM\Sequences\SequenceRepository;
use NowCRM\Support\Schema;

/**
 * @covers \NowCRM\Cart\Automation
 * @covers \NowCRM\Cart\Sweeper
 * @covers \NowCRM\Automation\RunRepository
 * @covers \NowCRM\Automation\ActionExecutor
 * @covers \NowCRM\Email\Queue
 */
final class CartAutomationIntegrationTest extends NowCrmTestCase {

	private CartRepository $carts;
	private RunRepository $runs;

	public function set_up(): void {
		parent::set_up();

		$this->carts = new CartRepository();
		$this->runs  = new RunRepository();

		$this->set_settings(
			array(
				'abandoned_cart_enabled'      => true,
				'email_sending_enabled'       => true,
				'email_transport'             => 'capture',
				'email_throttle_per_second'   => 100,
				'cart_idle_minutes'           => 20,
				'cart_reminder_delay_minutes' => 0,
				'cart_lost_days'              => 5,
				'cart_cooldown_days'          => 0,
				'cart_max_reminders'          => 1,
				'cart_recovery_days'          => 7,
				'cart_automation_id'          => 0,
			)
		);

		delete_option( CartAutomation::MANAGED_OPTION );
	}

	/**
	 * One abandoned cart with a real product snapshot.
	 *
	 * @return array<string, mixed>
	 */
	private function abandoned_cart( string $email ): array {
		$cart = $this->make_cart(
			array(
				'email'    => $email,
				'snapshot' => array(
					array( 'product_id' => 1, 'variation_id' => 0, 'quantity' => 1, 'variation' => array() ),
				),
			)
		);

		$this->carts->mark_abandoned( (int) $cart['id'], (int) $cart['version'], gmdate( 'Y-m-d H:i:s' ) );

		return $this->carts->get( (int) $cart['id'] );
	}

	/**
	 * Source identity of one cart row.
	 *
	 * @param array<string, mixed> $cart Cart row.
	 */
	private function source_key( array $cart ): string {
		return 'cart:' . (int) $cart['id'] . ':' . (int) $cart['version'];
	}

	/**
	 * Jobs linked to one cart through the cart column.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function cart_jobs( int $cart_id ): array {
		return CartAutomation::jobs_for_cart( $cart_id );
	}

	/**
	 * A custom active automation bound to the cart trigger.
	 */
	private function make_cart_automation( string $subject = 'Custom cart flow', bool $run_once = false, array $steps = array(), bool $force = false ): int {
		$id = ( new AutomationRepository() )->save(
			array(
				'name'         => 'Cart flow ' . wp_generate_password( 6, false ),
				'status'       => AutomationRepository::STATUS_ACTIVE,
				'trigger_key'  => TriggerRegistry::CART_ABANDONED,
				'entry_config' => array( 'run_once' => $run_once, 'force_run' => $force ),
				'steps'        => $steps ?: array(
					array(
						'kind'   => 'email',
						'config' => array(
							'subject' => $subject,
							'mode'    => 'html',
							'content' => '<p>Custom</p><a href="{{recovery_url}}">Return</a>',
						),
					),
					array( 'kind' => 'end' ),
				),
			)
		);

		$this->assertIsInt( $id );

		return (int) $id;
	}

	/**
	 * Active sequence used to prove no action runs after an order.
	 */
	private function make_sequence(): int {
		$id = ( new SequenceRepository() )->create(
			array(
				'name'          => 'Cart guard ' . wp_generate_password( 6, false ),
				'status'        => SequenceRepository::STATUS_ACTIVE,
				'allow_reentry' => false,
				'audience'      => array( 'type' => 'all' ),
				'steps'         => array(
					array(
						'name'          => 'One',
						'subject'       => 'Step one',
						'preheader'     => '',
						'mode'          => 'html',
						'content'       => '<p>One</p>',
						'delay_seconds' => DAY_IN_SECONDS,
					),
				),
			)
		);

		$this->assertIsInt( $id );

		return (int) $id;
	}

	/**
	 * Force a waiting run due without waiting for wall-clock time.
	 */
	private function make_run_due( int $run_id ): void {
		global $wpdb;

		$wpdb->update(
			Schema::table( 'automation_runs' ),
			array( 'next_run_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ),
			array( 'id' => $run_id )
		);
	}

	public function test_explicit_active_cart_owner_is_used(): void {
		$email = 'explicit-owner@example.test';
		$this->make_contact( array( 'email' => $email ) );

		$owner = $this->make_cart_automation( 'Explicit owner subject' );
		$this->set_settings( array( 'cart_automation_id' => $owner ) );

		$cart = $this->abandoned_cart( $email );
		$run  = CartAutomation::start_for_cart( (int) $cart['id'], 'cart:' . (int) $cart['id'] );

		$this->assertIsArray( $run );
		$this->assertSame( $owner, (int) $run['run']['automation_id'] );

		( new Engine() )->run_due();

		$jobs = $this->cart_jobs( (int) $cart['id'] );
		$this->assertCount( 1, $jobs );
		$this->assertSame( 'Explicit owner subject', $jobs[0]['subject'] );
	}

	public function test_legacy_queued_job_blocks_a_new_producer(): void {
		$email = 'legacy@example.test';
		$contact = $this->make_contact( array( 'email' => $email ) );
		$cart    = $this->abandoned_cart( $email );

		$legacy = ( new EmailJobRepository() )->enqueue(
			array(
				'job_key'    => 'cart:' . (int) $cart['id'] . ':reminder:1',
				'kind'       => 'cart',
				'cart_id'    => (int) $cart['id'],
				'contact_id' => $contact,
				'recipient'  => $email,
				'subject'    => 'Legacy',
				'payload'    => array(
					'cart_version' => (int) $cart['version'],
					'recovery_url' => home_url( '/?nowcrm_recover=1&token=legacy' ),
					'template'     => array( 'mode' => 'html', 'content' => '<p>Legacy</p>', 'subject' => 'Legacy' ),
				),
			)
		);

		$this->assertIsInt( $legacy );

		$result = CartAutomation::start_for_cart( (int) $cart['id'], 'cart:' . (int) $cart['id'] );

		$this->assertWPError( $result );
		$this->assertSame( 'legacy_owner', $result->get_error_code() );
		$this->assertSame( 0, $this->table_count( 'automation_runs' ) );
		$this->assertSame( EmailJobRepository::STATUS_QUEUED, ( new EmailJobRepository() )->get( (int) $legacy )['status'] );
	}

	public function test_accepted_handoff_records_outcome_and_counts_once(): void {
		$email = 'accepted@example.test';
		$this->make_contact( array( 'email' => $email ) );
		$cart = $this->abandoned_cart( $email );

		$run = CartAutomation::start_for_cart( (int) $cart['id'], 'cart:' . (int) $cart['id'] );
		$this->assertIsArray( $run );

		( new Engine() )->run_due();
		( new Queue() )->run();

		$job = $this->cart_jobs( (int) $cart['id'] )[0];
		$this->assertSame( EmailJobRepository::STATUS_ACCEPTED, $job['status'] );
		$this->assertCount( 1, (array) get_option( EmailDispatcher::CAPTURE_OPTION, array() ) );

		$step  = ( new AutomationRepository() )->steps( (int) $run['run']['automation_id'] )[0];
		$event = $this->runs->event_for_step( (int) $run['run_id'], (int) $step['id'] );

		$this->assertNotNull( $event );
		$this->assertSame( 'accepted', $event['payload']['outcome'] ?? null );
		$this->assertSame( (int) $job['id'], (int) ( $event['payload']['job_id'] ?? 0 ) );
		$this->assertSame( 1, (int) $this->carts->get( (int) $cart['id'] )['reminders_sent'] );

		( new Queue() )->run();

		$this->assertCount( 1, (array) get_option( EmailDispatcher::CAPTURE_OPTION, array() ) );
		$this->assertSame( 1, (int) $this->carts->get( (int) $cart['id'] )['reminders_sent'] );
	}

	public function test_unknown_and_cancelled_cart_jobs_are_not_retried(): void {
		$email = 'no-retry@example.test';
		$this->make_contact( array( 'email' => $email ) );
		$cart = $this->abandoned_cart( $email );

		$jobs = new EmailJobRepository();

		$unknown = $jobs->enqueue(
			array(
				'job_key'   => 'cart:' . (int) $cart['id'] . ':v' . (int) $cart['version'] . ':run:1:step:1',
				'kind'      => 'automation',
				'cart_id'   => (int) $cart['id'],
				'recipient' => $email,
				'payload'   => array( 'run_id' => 1, 'step_id' => 1 ),
			)
		);
		$cancelled = $jobs->enqueue(
			array(
				'job_key'   => 'cart:' . (int) $cart['id'] . ':v' . (int) $cart['version'] . ':run:2:step:2',
				'kind'      => 'automation',
				'cart_id'   => (int) $cart['id'],
				'recipient' => $email,
				'payload'   => array( 'run_id' => 2, 'step_id' => 2 ),
			)
		);

		$this->assertIsInt( $unknown );
		$this->assertIsInt( $cancelled );

		global $wpdb;
		$table = Schema::table( 'email_jobs' );

		$wpdb->update( $table, array( 'status' => EmailJobRepository::STATUS_UNKNOWN ), array( 'id' => (int) $unknown ) );
		$wpdb->update( $table, array( 'status' => EmailJobRepository::STATUS_CANCELLED ), array( 'id' => (int) $cancelled ) );

		$this->assertFalse( $jobs->retry( (int) $unknown ) );
		$this->assertFalse( $jobs->retry( (int) $cancelled ) );
		$this->assertSame( EmailJobRepository::STATUS_UNKNOWN, $jobs->get( (int) $unknown )['status'] );
		$this->assertSame( EmailJobRepository::STATUS_CANCELLED, $jobs->get( (int) $cancelled )['status'] );
	}

	public function test_structural_edit_is_rejected_while_a_cart_step_is_waiting(): void {
		$email = 'edit-guard@example.test';
		$this->make_contact( array( 'email' => $email ) );

		$owner = $this->make_cart_automation( 'Guarded subject' );
		$this->set_settings( array( 'cart_automation_id' => $owner ) );

		$cart = $this->abandoned_cart( $email );
		$run  = CartAutomation::start_for_cart( (int) $cart['id'], 'cart:' . (int) $cart['id'] );

		$this->assertIsArray( $run );

		( new Engine() )->run_due();

		$before = $this->runs->get( (int) $run['run_id'] );
		$job    = $this->cart_jobs( (int) $cart['id'] )[0];
		$repo   = new AutomationRepository();
		$stored = $repo->get( $owner );

		$rejected = $repo->save(
			array(
				'name'        => (string) $stored['name'],
				'status'      => AutomationRepository::STATUS_ACTIVE,
				'trigger_key' => TriggerRegistry::CART_ABANDONED,
				'steps'       => array(
					array(
						'kind'   => 'email',
						'config' => array( 'subject' => 'Changed', 'content' => '<p>Changed</p>' ),
					),
					array( 'kind' => 'wait', 'delay_seconds' => 600 ),
					array( 'kind' => 'end' ),
				),
			),
			$owner
		);

		$this->assertWPError( $rejected );
		$this->assertSame( 'nowcrm_automation_live_runs', $rejected->get_error_code() );

		$after = $this->runs->get( (int) $run['run_id'] );

		$this->assertSame( (int) $before['next_step_id'], (int) $after['next_step_id'] );
		$this->assertSame( (int) $job['payload']['step_id'], (int) ( $this->cart_jobs( (int) $cart['id'] )[0]['payload']['step_id'] ?? 0 ) );

		// Metadata-only edits remain allowed while the source run is open.
		$this->assertIsInt(
			$repo->save(
				array(
					'name'        => 'Renamed cart flow',
					'status'      => AutomationRepository::STATUS_ACTIVE,
					'trigger_key' => TriggerRegistry::CART_ABANDONED,
					'steps'       => array(
						array(
							'kind'   => 'email',
							'config' => array( 'subject' => 'Guarded subject', 'content' => '<p>Custom</p><a href="{{recovery_url}}">Return</a>' ),
						),
						array( 'kind' => 'end' ),
					),
				),
				$owner
			)
		);
	}

	public function test_legacy_generic_cart_trigger_job_cannot_mail_on_cutover(): void {
		$email   = 'legacy-generic@example.test';
		$contact = $this->make_contact( array( 'email' => $email ) );
		$cart    = $this->abandoned_cart( $email );

		global $wpdb;

		$now = gmdate( 'Y-m-d H:i:s' );

		$wpdb->insert(
			Schema::table( 'automation_runs' ),
			array(
				'automation_id'  => 999,
				'contact_id'     => $contact,
				'status'         => 'completed',
				'source_trigger' => TriggerRegistry::CART_ABANDONED,
				'source_ref'     => 'cart:' . (int) $cart['id'],
				'started_at'     => $now,
				'updated_at'     => $now,
			),
			array( '%d', '%d', '%s', '%s', '%s', '%s', '%s' )
		);

		$run_id = (int) $wpdb->insert_id;
		$this->assertGreaterThan( 0, $run_id );

		$job_id = ( new EmailJobRepository() )->enqueue(
			array(
				'job_key'    => 'automation:999:run:' . $run_id . ':start:legacy:step:1',
				'kind'       => 'automation',
				'contact_id' => $contact,
				'recipient'  => $email,
				'subject'    => 'Legacy generic',
				'payload'    => array(
					'automation_id' => 999,
					'run_id'        => $run_id,
					'step_id'       => 1,
					'template'      => array( 'mode' => 'html', 'content' => '<p>Legacy</p>', 'subject' => 'Legacy generic' ),
				),
			)
		);

		$this->assertIsInt( $job_id );

		( new Queue() )->run();

		$job = ( new EmailJobRepository() )->get( (int) $job_id );

		$this->assertSame( EmailJobRepository::STATUS_SUPPRESSED, $job['status'] );
		$this->assertSame( 'legacy_cart_automation', $job['last_error'] );
		$this->assertSame( array(), (array) get_option( EmailDispatcher::CAPTURE_OPTION, array() ) );
	}

	public function test_legacy_active_cart_run_is_cancelled_without_a_new_job(): void {
		$email   = 'legacy-run@example.test';
		$contact = $this->make_contact( array( 'email' => $email ) );
		$cart    = $this->abandoned_cart( $email );
		$owner   = $this->make_cart_automation( 'Guard legacy run' );
		$step    = ( new AutomationRepository() )->steps( $owner )[0];

		global $wpdb;

		$now = gmdate( 'Y-m-d H:i:s' );

		$wpdb->insert(
			Schema::table( 'automation_runs' ),
			array(
				'automation_id' => $owner,
				'contact_id'    => $contact,
				'status'        => 'active',
				'next_step_id'  => (int) $step['id'],
				'next_run_at'   => $now,
				'source_trigger' => TriggerRegistry::CART_ABANDONED,
				'source_ref'    => 'cart:' . (int) $cart['id'],
				'started_at'    => $now,
				'updated_at'    => $now,
			),
			array( '%d', '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s' )
		);

		$run_id = (int) $wpdb->insert_id;
		$this->assertGreaterThan( 0, $run_id );

		( new Engine() )->run_due();

		$run = $this->runs->get( $run_id );

		$this->assertSame( RunRepository::STATUS_CANCELLED, $run['status'] );
		$this->assertSame( 'legacy_cart_automation', $run['last_error'] );
		$this->assertSame( array(), $this->cart_jobs( (int) $cart['id'] ) );
	}

	public function test_legacy_accepted_generic_job_blocks_replacement(): void {
		$email   = 'legacy-accepted@example.test';
		$contact = $this->make_contact( array( 'email' => $email ) );
		$cart    = $this->abandoned_cart( $email );
		$owner   = $this->make_cart_automation( 'Guard accepted legacy' );

		global $wpdb;

		$now = gmdate( 'Y-m-d H:i:s' );

		$wpdb->insert(
			Schema::table( 'automation_runs' ),
			array(
				'automation_id' => $owner,
				'contact_id'    => $contact,
				'status'        => 'completed',
				'source_trigger' => TriggerRegistry::CART_ABANDONED,
				'source_ref'    => 'cart:' . (int) $cart['id'],
				'started_at'    => $now,
				'updated_at'    => $now,
			),
			array( '%d', '%d', '%s', '%s', '%s', '%s', '%s' )
		);

		$run_id = (int) $wpdb->insert_id;

		$job_id = ( new EmailJobRepository() )->enqueue(
			array(
				'job_key'    => sprintf(
					'automation:%d:run:%d:start:%s:step:1',
					$owner,
					$run_id,
					substr( md5( $now ), 0, 12 )
				),
				'kind'       => 'automation',
				'contact_id' => $contact,
				'recipient'  => $email,
				'subject'    => 'Legacy accepted',
				'payload'    => array(
					'automation_id' => $owner,
					'run_id'        => $run_id,
					'step_id'       => 1,
					'template'      => array( 'mode' => 'html', 'content' => '<p>Legacy</p>', 'subject' => 'Legacy accepted' ),
				),
			)
		);

		$this->assertIsInt( $job_id );

		$wpdb->update(
			Schema::table( 'email_jobs' ),
			array( 'status' => EmailJobRepository::STATUS_ACCEPTED ),
			array( 'id' => (int) $job_id )
		);

		$result = CartAutomation::start_for_cart( (int) $cart['id'], 'cart:' . (int) $cart['id'] );

		$this->assertWPError( $result );
		$this->assertSame( 'legacy_owner', $result->get_error_code() );
		$this->assertSame( 0, $this->table_count( 'automation_runs' ) - 1, 'No replacement run may be created.' );
	}

	public function test_abandoned_cart_has_exactly_one_automation_owner(): void {
		$email = 'single-owner@example.test';
		$this->make_contact( array( 'email' => $email ) );

		$cart = $this->make_cart( array( 'email' => $email ) );

		global $wpdb;
		$wpdb->update(
			Schema::table( 'carts' ),
			array( 'last_activity_at' => gmdate( 'Y-m-d H:i:s', time() - 3600 ) ),
			array( 'id' => (int) $cart['id'] )
		);

		$result = ( new Sweeper() )->run();

		$this->assertSame( 1, $result['abandoned'] );
		$this->assertLessThanOrEqual( 1, $result['reminders'], 'Only the automation owner may create a reminder.' );

		( new Engine() )->run_due();

		$fresh = $this->carts->get( (int) $cart['id'] );
		$run   = $this->runs->find_by_source_key( $this->source_key( $fresh ) );

		$this->assertNotNull( $run, 'The cart trigger must create a source-scoped run.' );
		$this->assertSame( (int) $fresh['version'], (int) $run['cart_version'] );

		$kinds = array_map(
			static fn( array $job ): string => (string) $job['kind'],
			$this->cart_jobs( (int) $fresh['id'] )
		);

		$this->assertNotContains( 'cart', $kinds, 'Legacy kind=cart must not be produced.' );
		$this->assertSame( array( 'automation' ), array_values( array_unique( $kinds ) ) );
		$this->assertCount( 1, $kinds );
	}

	public function test_two_carts_for_the_same_contact_get_independent_runs(): void {
		$email = 'two-carts@example.test';
		$this->make_contact( array( 'email' => $email ) );

		$first  = $this->abandoned_cart( $email );
		$second = $this->abandoned_cart( $email );

		$this->assertIsArray( CartAutomation::start_for_cart( (int) $first['id'], 'cart:' . (int) $first['id'] ) );
		$this->assertIsArray( CartAutomation::start_for_cart( (int) $second['id'], 'cart:' . (int) $second['id'] ) );

		$row_a = $this->runs->find_by_source_key( $this->source_key( $first ) );
		$row_b = $this->runs->find_by_source_key( $this->source_key( $second ) );

		$this->assertNotNull( $row_a );
		$this->assertNotNull( $row_b );
		$this->assertNotSame( (int) $row_a['id'], (int) $row_b['id'] );
		$this->assertSame( 2, $this->table_count( 'automation_runs' ) );
	}

	public function test_replayed_abandoned_event_reuses_the_same_run_and_job(): void {
		$email = 'replay@example.test';
		$this->make_contact( array( 'email' => $email ) );
		$cart = $this->abandoned_cart( $email );

		$first = CartAutomation::start_for_cart( (int) $cart['id'], 'cart:' . (int) $cart['id'] );
		$this->assertIsArray( $first );

		( new Engine() )->run_due();

		$second = CartAutomation::start_for_cart( (int) $cart['id'], 'cart:' . (int) $cart['id'] );
		$this->assertIsArray( $second );
		$this->assertSame( (int) $first['run_id'], (int) $second['run_id'] );

		( new Engine() )->run_due();

		$this->assertSame( 1, $this->table_count( 'automation_runs' ) );
		$this->assertCount( 1, $this->cart_jobs( (int) $cart['id'] ) );
	}

	public function test_reactivation_cancels_the_unsent_job_via_the_source_event(): void {
		$email = 'reactivated@example.test';
		$this->make_contact( array( 'email' => $email ) );
		$cart = $this->abandoned_cart( $email );

		CartAutomation::start_for_cart( (int) $cart['id'], 'cart:' . (int) $cart['id'] );
		( new Engine() )->run_due();

		$this->assertCount( 1, $this->cart_jobs( (int) $cart['id'] ) );

		$this->carts->record_activity(
			array(
				'cycle_key'  => (string) $cart['cart_cycle_key'],
				'email'      => $email,
				'snapshot'   => array(),
				'item_count' => 1,
				'total'      => 100,
				'currency'   => 'TWD',
				'cart_hash'  => 'hash-after-return',
			)
		);

		$this->assertSame( EmailJobRepository::STATUS_CANCELLED, $this->cart_jobs( (int) $cart['id'] )[0]['status'] );

		( new \NowCRM\Email\Queue() )->run();

		$this->assertSame( array(), (array) get_option( EmailDispatcher::CAPTURE_OPTION, array() ) );
	}

	public function test_order_during_wait_stops_resume_and_keeps_sibling_cart_live(): void {
		$email    = 'cod-wait@example.test';
		$sequence = $this->make_sequence();
		$owner    = $this->make_cart_automation(
			'After wait',
			false,
			array(
				array( 'kind' => 'wait', 'delay_seconds' => 600 ),
				array( 'kind' => 'action', 'action_key' => 'add_to_sequence', 'config' => array( 'sequence_id' => $sequence ) ),
				array(
					'kind'   => 'email',
					'config' => array( 'subject' => 'After wait', 'mode' => 'html', 'content' => '<p>After</p>' ),
				),
				array( 'kind' => 'end' ),
			)
		);
		$this->set_settings( array( 'cart_automation_id' => $owner ) );

		$this->make_contact( array( 'email' => $email ) );

		$first  = $this->abandoned_cart( $email );
		$second = $this->abandoned_cart( $email );

		$run_a = CartAutomation::start_for_cart( (int) $first['id'], 'cart:' . (int) $first['id'] );
		$run_b = CartAutomation::start_for_cart( (int) $second['id'], 'cart:' . (int) $second['id'] );

		$this->assertIsArray( $run_a );
		$this->assertIsArray( $run_b );

		( new Engine() )->run_due();

		$this->assertSame( RunRepository::STATUS_WAITING, $this->runs->get( (int) $run_a['run_id'] )['status'] );

		// Real COD checkout: the exact first cycle is bound to the session.
		if ( function_exists( 'WC' ) && ! WC()->cart && function_exists( 'wc_load_cart' ) ) {
			wc_load_cart();
		}

		if ( function_exists( 'WC' ) && WC()->cart ) {
			WC()->cart->empty_cart();
		}

		if ( function_exists( 'WC' ) && ! WC()->session && method_exists( WC(), 'initialize_session' ) ) {
			WC()->initialize_session();
		}

		WC()->session->set( 'nowcrm_cart_cycle', (string) $first['cart_cycle_key'] );
		WC()->session->set( 'nowcrm_recovered_cart_id', 0 );

		$order = $this->make_order( array( 'billing_email' => $email ) );
		$order->set_payment_method( 'cod' );
		$order->save();

		( new Tracker() )->on_order_processed( (int) $order->get_id(), array(), wc_get_order( (int) $order->get_id() ) );

		$this->assertSame( CartRepository::STATUS_ORDER_PENDING, $this->carts->get( (int) $first['id'] )['status'] );
		$this->assertSame( RunRepository::STATUS_CANCELLED, $this->runs->get( (int) $run_a['run_id'] )['status'] );
		$this->assertSame(
			RunRepository::STATUS_WAITING,
			$this->runs->get( (int) $run_b['run_id'] )['status'],
			'Another cart with the same email must stay live.'
		);

		$this->make_run_due( (int) $run_a['run_id'] );
		( new Engine() )->run_due();
		( new Queue() )->run();

		$this->assertSame( 0, $this->table_count( 'sequence_trackers' ) );
		$this->assertSame( array(), $this->cart_jobs( (int) $first['id'] ) );
		$this->assertSame( array(), (array) get_option( EmailDispatcher::CAPTURE_OPTION, array() ) );

		$this->make_run_due( (int) $run_b['run_id'] );
		( new Engine() )->run_due();
		( new Queue() )->run();

		$this->assertCount( 1, $this->cart_jobs( (int) $second['id'] ) );
		$this->assertCount( 1, (array) get_option( EmailDispatcher::CAPTURE_OPTION, array() ) );
	}

	public function test_stale_engine_guard_cancels_when_invalidation_was_missed(): void {
		$email    = 'stale-guard@example.test';
		$sequence = $this->make_sequence();
		$owner    = $this->make_cart_automation(
			'Stale guard',
			false,
			array(
				array( 'kind' => 'wait', 'delay_seconds' => 600 ),
				array( 'kind' => 'action', 'action_key' => 'add_to_sequence', 'config' => array( 'sequence_id' => $sequence ) ),
				array(
					'kind'   => 'email',
					'config' => array( 'subject' => 'Stale guard', 'mode' => 'html', 'content' => '<p>Stale</p>' ),
				),
				array( 'kind' => 'end' ),
			)
		);
		$this->set_settings( array( 'cart_automation_id' => $owner ) );

		$cart = $this->abandoned_cart( $email );
		$run  = CartAutomation::start_for_cart( (int) $cart['id'], 'cart:' . (int) $cart['id'] );

		$this->assertIsArray( $run );

		( new Engine() )->run_due();

		$this->assertSame( RunRepository::STATUS_WAITING, $this->runs->get( (int) $run['run_id'] )['status'] );

		global $wpdb;

		// A terminal transition whose invalidation hook never ran.
		$wpdb->update(
			Schema::table( 'carts' ),
			array( 'status' => CartRepository::STATUS_ORDER_PENDING ),
			array( 'id' => (int) $cart['id'] )
		);

		$this->make_run_due( (int) $run['run_id'] );
		( new Engine() )->run_due();
		( new Queue() )->run();

		$fresh = $this->runs->get( (int) $run['run_id'] );

		$this->assertSame( RunRepository::STATUS_CANCELLED, $fresh['status'] );
		$this->assertSame( 'cart_not_abandoned', $fresh['last_error'] );
		$this->assertSame( 0, $this->table_count( 'sequence_trackers' ) );
		$this->assertSame( array(), $this->cart_jobs( (int) $cart['id'] ) );
	}

	public function test_lost_and_reactivated_carts_cancel_open_runs(): void {
		$email    = 'lost-react@example.test';
		$sequence = $this->make_sequence();
		$owner    = $this->make_cart_automation(
			'Lost flow',
			false,
			array(
				array( 'kind' => 'wait', 'delay_seconds' => 600 ),
				array( 'kind' => 'action', 'action_key' => 'add_to_sequence', 'config' => array( 'sequence_id' => $sequence ) ),
				array(
					'kind'   => 'email',
					'config' => array( 'subject' => 'Lost flow', 'mode' => 'html', 'content' => '<p>Lost</p>' ),
				),
				array( 'kind' => 'end' ),
			)
		);
		$this->set_settings( array( 'cart_automation_id' => $owner ) );

		$cart = $this->abandoned_cart( $email );
		$run  = CartAutomation::start_for_cart( (int) $cart['id'], 'cart:' . (int) $cart['id'] );

		$this->assertIsArray( $run );

		( new Engine() )->run_due();

		$this->assertTrue( $this->carts->mark_lost( (int) $cart['id'], (int) $cart['version'] ) );
		$this->assertSame( CartRepository::STATUS_LOST, $this->carts->get( (int) $cart['id'] )['status'] );
		$this->assertSame( RunRepository::STATUS_CANCELLED, $this->runs->get( (int) $run['run_id'] )['status'] );

		$reactivated = $this->carts->record_activity(
			array(
				'cycle_key'   => (string) $cart['cart_cycle_key'],
				'session_key' => (string) $cart['session_key'],
				'email'       => $email,
				'snapshot'    => array(),
				'item_count'  => 1,
				'total'       => 50,
				'currency'    => 'TWD',
			)
		);

		$this->assertNotWPError( $reactivated );
		$this->assertSame( CartRepository::STATUS_ACTIVE, $this->carts->get( (int) $cart['id'] )['status'] );

		$this->carts->mark_abandoned(
			(int) $cart['id'],
			(int) $reactivated['version'],
			gmdate( 'Y-m-d H:i:s', time() + MINUTE_IN_SECONDS )
		);

		$next = CartAutomation::start_for_cart( (int) $cart['id'], 'cart:' . (int) $cart['id'] );

		$this->assertIsArray( $next );
		$this->assertNotEmpty( $next['started'] );
		$this->assertNotSame( (int) $run['run_id'], (int) $next['run_id'] );
	}

	public function test_run_once_blocks_another_cart_and_a_new_version(): void {
		$email = 'run-once@example.test';
		$owner = $this->make_cart_automation( 'Run once', true );
		$this->set_settings( array( 'cart_automation_id' => $owner ) );

		$first  = $this->abandoned_cart( $email );
		$second = $this->abandoned_cart( $email );

		$one = CartAutomation::start_for_cart( (int) $first['id'], 'cart:' . (int) $first['id'] );

		$this->assertIsArray( $one );
		$this->assertNotEmpty( $one['started'] );

		$two = CartAutomation::start_for_cart( (int) $second['id'], 'cart:' . (int) $second['id'] );

		$this->assertIsArray( $two );
		$this->assertEmpty( $two['started'] );
		$this->assertSame( 'run_once', $two['reason'] );
		$this->assertSame( 1, $this->table_count( 'automation_runs' ) );

		$reactivated = $this->carts->record_activity(
			array(
				'cycle_key'   => (string) $first['cart_cycle_key'],
				'session_key' => (string) $first['session_key'],
				'email'       => $email,
				'snapshot'    => array(),
				'item_count'  => 1,
				'total'       => 100,
				'currency'    => 'TWD',
			)
		);

		$this->assertNotWPError( $reactivated );

		$this->carts->mark_abandoned(
			(int) $first['id'],
			(int) $reactivated['version'],
			gmdate( 'Y-m-d H:i:s', time() + MINUTE_IN_SECONDS )
		);

		$next = CartAutomation::start_for_cart( (int) $first['id'], 'cart:' . (int) $first['id'] );

		$this->assertIsArray( $next );
		$this->assertEmpty( $next['started'] );
		$this->assertSame( 'run_once', $next['reason'] );
		$this->assertSame( 1, $this->table_count( 'automation_runs' ) );

		// Explicitly off: each cart source keeps its own independent run.
		$independent = $this->make_cart_automation( 'Independent', false );
		$this->set_settings( array( 'cart_automation_id' => $independent ) );

		$third  = $this->abandoned_cart( 'independent@example.test' );
		$fourth = $this->abandoned_cart( 'independent@example.test' );

		$three = CartAutomation::start_for_cart( (int) $third['id'], 'cart:' . (int) $third['id'] );
		$four  = CartAutomation::start_for_cart( (int) $fourth['id'], 'cart:' . (int) $fourth['id'] );

		$this->assertIsArray( $three );
		$this->assertIsArray( $four );
		$this->assertNotEmpty( $three['started'] );
		$this->assertNotEmpty( $four['started'] );
		$this->assertSame( 3, $this->table_count( 'automation_runs' ) );
	}

	public function test_captured_guest_receives_exactly_one_transactional_reminder(): void {
		$this->set_settings( array( 'campaign_tracking_enabled' => true ) );

		$email = 'guest-first-capture@example.test';
		$cart  = $this->abandoned_cart( $email );

		$started = CartAutomation::start_for_cart( (int) $cart['id'], 'cart:' . (int) $cart['id'] );

		$this->assertIsArray( $started );
		$this->assertNotEmpty( $started['started'] );

		$contact_id = (int) $started['run']['contact_id'];
		$contact    = $this->contacts->get( $contact_id );

		$this->assertSame( Status::TRANSACTIONAL, $contact['status'], 'A captured guest stays transactional.' );

		$types = array_column( $this->carts->events( (int) $cart['id'] ), 'type' );

		$this->assertNotContains( 'not_eligible', $types, 'An allowed status must not be recorded as ineligible.' );

		( new Engine() )->run_due();

		$jobs = $this->cart_jobs( (int) $cart['id'] );

		$this->assertCount( 1, $jobs );
		$this->assertSame( EmailJobRepository::STATUS_QUEUED, $jobs[0]['status'] );

		global $wpdb;
		$wpdb->update(
			Schema::table( 'email_jobs' ),
			array( 'available_at' => gmdate( 'Y-m-d H:i:s', time() - 1 ) ),
			array( 'id' => (int) $jobs[0]['id'] )
		);

		( new Queue() )->run();

		$sent = $this->cart_jobs( (int) $cart['id'] )[0];

		$this->assertSame( EmailJobRepository::STATUS_ACCEPTED, $sent['status'] );
		$this->assertSame( 1, (int) $this->carts->get( (int) $cart['id'] )['reminders_sent'] );
		$this->assertSame( Status::TRANSACTIONAL, $this->contacts->get( $contact_id )['status'] );

		$log = get_option( EmailDispatcher::CAPTURE_OPTION, array() );

		$this->assertCount( 1, $log );
		$this->assertSame( $email, $log[0]['to'] );
		$this->assertNotEmpty( (string) $this->carts->get( (int) $cart['id'] )['recovery_token_hash'] );
		$this->assertStringContainsString( '/nowcrm/v1/click/', $log[0]['html'] );

		// Replaying the source must reuse the same run and never send twice.
		$replay = CartAutomation::start_for_cart( (int) $cart['id'], 'cart:' . (int) $cart['id'] );

		$this->assertIsArray( $replay );
		$this->assertEmpty( $replay['started'] );

		( new Engine() )->run_due();
		( new Queue() )->run();

		$this->assertCount( 1, $this->cart_jobs( (int) $cart['id'] ) );
		$this->assertSame( 1, $this->table_count( 'automation_runs' ) );
		$this->assertCount( 1, get_option( EmailDispatcher::CAPTURE_OPTION, array() ) );
	}

	public function test_forced_cart_run_still_blocks_an_unsubscribed_contact(): void {
		$owner = $this->make_cart_automation( 'Forced cart', false, array(), true );
		$this->set_settings( array( 'cart_automation_id' => $owner ) );

		$email   = 'forced-unsubscribed@example.test';
		$contact = $this->make_contact(
			array(
				'email'  => $email,
				'status' => Status::UNSUBSCRIBED,
			)
		);
		$cart    = $this->abandoned_cart( $email );

		$started = CartAutomation::start_for_cart( (int) $cart['id'], 'cart:' . (int) $cart['id'] );

		$this->assertIsArray( $started );
		$this->assertNotEmpty( $started['started'] );

		( new Engine() )->run_due();

		$this->assertSame( array(), $this->cart_jobs( (int) $cart['id'] ) );
		$this->assertSame( 0, (int) $this->carts->get( (int) $cart['id'] )['reminders_sent'] );
		$this->assertSame( Status::UNSUBSCRIBED, $this->contacts->get( $contact )['status'] );

		$types = array_column( $this->carts->events( (int) $cart['id'] ), 'type' );

		$this->assertContains( 'not_eligible', $types, 'A blocked status keeps its real block reason.' );

		( new Queue() )->run();

		$this->assertSame( array(), get_option( EmailDispatcher::CAPTURE_OPTION, array() ) );
	}
}
