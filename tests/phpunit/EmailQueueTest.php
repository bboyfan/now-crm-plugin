<?php
/**
 * Worker gates and transport handoff.
 *
 * @package NowCRM
 */

use NowCRM\Automation\AutomationRepository;
use NowCRM\Automation\Engine;
use NowCRM\Automation\TriggerRegistry;
use NowCRM\Cart\Automation as CartAutomation;
use NowCRM\Cart\CartRepository;
use NowCRM\Campaign\Campaigns;
use NowCRM\Contacts\Status;
use NowCRM\Email\EmailDispatcher;
use NowCRM\Email\EmailJobRepository;
use NowCRM\Email\EmailRenderer;
use NowCRM\Email\Queue;
use NowCRM\Email\SuppressionRepository;
use NowCRM\Email\WorkerHealth;
use NowCRM\Ses\Processor;
use NowCRM\Support\Schema;

/**
 * @covers \NowCRM\Email\Queue
 */
final class EmailQueueTest extends NowCrmTestCase {

	private EmailJobRepository $jobs;

	public function set_up(): void {
		parent::set_up();

		$this->jobs = new EmailJobRepository();
		$this->set_settings(
			array(
				'email_sending_enabled'    => true,
				'email_transport'          => 'capture',
				'email_throttle_per_second' => 100,
				'campaign_tracking_enabled' => true,
			)
		);
	}

	/**
	 * Put a campaign into a sendable state with a frozen template.
	 */
	private function activate_campaign( int $id ): void {
		update_post_meta( $id, Campaigns::META_STATUS, Campaigns::STATUS_SENDING );
		update_post_meta( $id, Campaigns::META_SNAPSHOT, wp_slash( (string) wp_json_encode( Campaigns::snapshot( $id, Campaigns::draft( get_post( $id ) ) ) ) ) );
	}

	/**
	 * Enqueue one campaign job.
	 */
	private function enqueue_job( int $campaign_id, int $contact_id, string $email, string $key = '' ): int {
		$id = $this->jobs->enqueue(
			array(
				'job_key'     => $key ?: 'campaign:' . $campaign_id . ':contact:' . $contact_id,
				'kind'        => 'campaign',
				'campaign_id' => $campaign_id,
				'contact_id'  => $contact_id,
				'recipient'   => $email,
				'subject'     => 'Fixture',
			)
		);

		$this->assertIsInt( $id );

		return (int) $id;
	}

	/**
	 * Enable the cart reminder path without cooldown noise.
	 */
	private function enable_cart_sending(): void {
		$this->set_settings(
			array(
				'abandoned_cart_enabled'      => true,
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
	 * Create one abandoned cart.
	 *
	 * @return array<string, mixed>
	 */
	private function abandoned_cart( string $email ): array {
		$cart = $this->make_cart( array( 'email' => $email ) );
		$repo = new CartRepository();

		$this->assertTrue( $repo->mark_abandoned( (int) $cart['id'], (int) $cart['version'], gmdate( 'Y-m-d H:i:s' ) ) );

		return (array) $repo->get( (int) $cart['id'] );
	}

	/**
	 * Enqueue one legacy cart reminder.
	 *
	 * @param array<string, mixed> $cart       Cart row.
	 * @param int                  $contact_id Contact ID.
	 * @param string               $email      Frozen recipient.
	 * @param array<string, mixed> $payload    Extra payload fields.
	 */
	private function legacy_cart_job( array $cart, int $contact_id, string $email, array $payload = array() ): int {
		$subject = 'Legacy cart reminder';
		$id      = $this->jobs->enqueue(
			array(
				'job_key'    => 'cart:' . (int) $cart['id'] . ':reminder:' . wp_generate_password( 6, false ),
				'kind'       => 'cart',
				'cart_id'    => (int) $cart['id'],
				'contact_id' => $contact_id,
				'recipient'  => $email,
				'subject'    => $subject,
				'priority'   => 5,
				'payload'    => array_merge(
					array(
						'cart_version' => (int) $cart['version'],
						'template'     => array(
							'mode'    => 'html',
							'content' => '<p>Legacy cart body</p>',
							'subject' => $subject,
						),
					),
					$payload
				),
			)
		);

		$this->assertIsInt( $id );

		return (int) $id;
	}

	/**
	 * Active custom cart automation with one enqueued versioned job.
	 *
	 * @return array{job: array<string, mixed>, cart: array<string, mixed>, contact: int}
	 */
	private function cart_automation_fixture( string $email, string $status = Status::SUBSCRIBED ): array {
		$contact = $this->make_contact( array( 'email' => $email, 'status' => $status ) );
		$owner   = ( new AutomationRepository() )->save(
			array(
				'name'         => 'Queue cart flow ' . wp_generate_password( 6, false ),
				'status'       => AutomationRepository::STATUS_ACTIVE,
				'trigger_key'  => TriggerRegistry::CART_ABANDONED,
				'entry_config' => array( 'run_once' => false ),
				'steps'        => array(
					array(
						'kind'   => 'email',
						'config' => array(
							'subject' => 'Versioned cart reminder',
							'mode'    => 'html',
							'content' => '<p>Versioned cart body</p>',
						),
					),
					array( 'kind' => 'end' ),
				),
			)
		);

		$this->assertIsInt( $owner );
		$this->set_settings( array( 'cart_automation_id' => (int) $owner ) );

		$cart = $this->abandoned_cart( $email );
		$run  = CartAutomation::start_for_cart( (int) $cart['id'], 'cart:' . (int) $cart['id'] );

		$this->assertIsArray( $run );

		( new Engine() )->run_due();

		$jobs = $this->jobs->for_cart( (int) $cart['id'] );
		$this->assertCount( 1, $jobs );

		global $wpdb;
		$wpdb->update(
			Schema::table( 'email_jobs' ),
			array( 'available_at' => gmdate( 'Y-m-d H:i:s', time() - 1 ) ),
			array( 'id' => (int) $jobs[0]['id'] )
		);

		return array(
			'job'     => $jobs[0],
			'cart'    => (array) ( new CartRepository() )->get( (int) $cart['id'] ),
			'contact' => $contact,
		);
	}

	/**
	 * Create a pending COD order and link the exact cart.
	 */
	private function place_cod_order( int $cart_id, string $email ): \WC_Order {
		$order = $this->make_order(
			array(
				'billing_email' => $email,
				'total'         => 100,
				'status'        => 'pending',
			)
		);

		$order->set_payment_method( 'cod' );
		$order->set_payment_method_title( 'Cash on delivery' );
		$order->save();

		$this->assertTrue( ( new CartRepository() )->mark_order_pending( $cart_id, (int) $order->get_id(), 'natural' ) );

		return $order;
	}

	public function test_capture_transport_accepts_a_subscribed_contact_with_tracking(): void {
		$campaign = $this->make_campaign();
		update_post_meta( $campaign, Campaigns::META_TRACKING, wp_slash( (string) wp_json_encode( array( 'open' => true, 'click' => true ) ) ) );
		$this->activate_campaign( $campaign );

		$email   = 'queue@example.test';
		$contact = $this->make_contact( array( 'email' => $email ) );
		$job_id  = $this->enqueue_job( $campaign, $contact, $email );

		( new Queue() )->run();

		$job = $this->jobs->get( $job_id );

		$this->assertSame( EmailJobRepository::STATUS_ACCEPTED, $job['status'] );
		$this->assertNotEmpty( $job['message_id'] );

		$log = get_option( EmailDispatcher::CAPTURE_OPTION, array() );
		$this->assertCount( 1, $log );
		$this->assertSame( $email, $log[0]['to'] );
		$this->assertStringContainsString( 'Hello', $log[0]['html'] );
		$this->assertStringContainsString( 'nowcrm_unsubscribe', $log[0]['html'] );
		$this->assertStringContainsString( '/nowcrm/v1/open/', $log[0]['html'] );
		$this->assertContains( 'List-Unsubscribe-Post: List-Unsubscribe=One-Click', $log[0]['headers'] );
	}

	public function test_wp_mail_handoff_emits_exactly_one_message_id(): void {
		$this->set_settings(
			array(
				'email_transport'    => 'wp_mail',
				'email_from_name'    => 'NOW CRM Fixture',
				'email_from_address' => 'sender@example.test',
				'email_reply_to'     => 'reply@example.test',
			)
		);

		// Activation freezes the sender snapshot, so the valid sender must exist first.
		$campaign = $this->make_campaign();
		$this->activate_campaign( $campaign );

		$email   = 'mime@example.test';
		$contact = $this->make_contact( array( 'email' => $email ) );
		$job_id  = $this->enqueue_job( $campaign, $contact, $email );

		$mailer = tests_retrieve_phpmailer_instance();
		$this->assertInstanceOf( \MockPHPMailer::class, $mailer );
		$mailer->mock_sent = array();

		( new Queue() )->run();

		$job = $this->jobs->get( $job_id );
		$this->assertSame( EmailJobRepository::STATUS_ACCEPTED, $job['status'] );
		$this->assertNotEmpty( $job['message_id'] );

		$sent = $mailer->get_sent( 0 );
		$this->assertNotFalse( $sent, 'The wp_mail transport must hand the message to PHPMailer.' );

		$headers = (string) $sent->header;
		$this->assertSame( 1, preg_match_all( '/^Message-ID:/mi', $headers ), 'A message must carry exactly one Message-ID header.' );
		$this->assertMatchesRegularExpression(
			'/^Message-ID:\s*<' . preg_quote( (string) $job['message_id'], '/' ) . '>\s*$/mi',
			$headers
		);
	}

	public function test_disabled_sending_leaves_jobs_queued(): void {
		$campaign = $this->make_campaign();
		$this->activate_campaign( $campaign );
		$contact = $this->make_contact();
		$job_id  = $this->enqueue_job( $campaign, $contact, $this->contacts->get( $contact )['email'] );

		$this->set_settings( array( 'email_sending_enabled' => false ) );

		( new Queue() )->run();

		$this->assertSame( EmailJobRepository::STATUS_QUEUED, $this->jobs->get( $job_id )['status'] );
		$this->assertSame( array(), get_option( EmailDispatcher::CAPTURE_OPTION, array() ) );
	}

	public function test_unsubscribed_contact_is_suppressed_at_send_time(): void {
		$campaign = $this->make_campaign();
		$this->activate_campaign( $campaign );

		$email   = 'later-unsub@example.test';
		$contact = $this->make_contact( array( 'email' => $email ) );
		$job_id  = $this->enqueue_job( $campaign, $contact, $email );

		$this->contacts->set_status( $contact, Status::UNSUBSCRIBED, 'test' );

		( new Queue() )->run();

		$this->assertSame( EmailJobRepository::STATUS_SUPPRESSED, $this->jobs->get( $job_id )['status'] );
		$this->assertSame( array(), get_option( EmailDispatcher::CAPTURE_OPTION, array() ) );
	}

	public function test_global_suppression_blocks_before_rendering(): void {
		$campaign = $this->make_campaign();
		$this->activate_campaign( $campaign );

		$email   = 'hardbounce@example.test';
		$contact = $this->make_contact( array( 'email' => $email ) );
		$job_id  = $this->enqueue_job( $campaign, $contact, $email );

		( new SuppressionRepository() )->add( $email, 'bounced', 'test' );

		( new Queue() )->run();

		$this->assertSame( EmailJobRepository::STATUS_SUPPRESSED, $this->jobs->get( $job_id )['status'] );
	}

	public function test_paused_campaign_defers_without_consuming_attempts(): void {
		$campaign = $this->make_campaign();
		$this->activate_campaign( $campaign );

		$contact = $this->make_contact();
		$job_id  = $this->enqueue_job( $campaign, $contact, $this->contacts->get( $contact )['email'] );

		$rows = $this->jobs->claim( 1, 'paused-claim' );
		update_post_meta( $campaign, Campaigns::META_STATUS, Campaigns::STATUS_PAUSED );

		( new Queue() )->process_rows( $rows, 'paused-claim' );

		$job = $this->jobs->get( $job_id );

		$this->assertSame( EmailJobRepository::STATUS_QUEUED, $job['status'] );
		$this->assertSame( 0, $job['attempts'] );
		$this->assertSame( 'campaign_paused', $job['last_error'] );
	}

	public function test_missing_campaign_is_never_claimed(): void {
		$contact = $this->make_contact();
		$id      = $this->jobs->enqueue(
			array(
				'job_key'     => 'campaign:9999:contact:' . $contact,
				'kind'        => 'campaign',
				'campaign_id' => 9999,
				'contact_id'  => $contact,
				'recipient'   => $this->contacts->get( $contact )['email'],
				'subject'     => 'Missing',
			)
		);

		// A campaign row that no longer exists is never sendable.
		update_post_meta( $this->make_campaign(), Campaigns::META_STATUS, Campaigns::STATUS_SENDING );

		( new Queue() )->run();

		$this->assertSame( EmailJobRepository::STATUS_QUEUED, $this->jobs->get( (int) $id )['status'] );
		$this->assertSame( 0, $this->jobs->get( (int) $id )['attempts'] );
	}

	public function test_test_send_bypasses_marketing_status_but_still_sends(): void {
		$campaign = $this->make_campaign();
		$id = ( new \NowCRM\Campaign\Delivery() )->test( get_post( $campaign ), 'admin@example.test' );
		$this->assertIsInt( $id );

		( new Queue() )->run();

		$this->assertSame( EmailJobRepository::STATUS_ACCEPTED, $this->jobs->get( (int) $id )['status'] );
	}

	public function test_automation_suppresses_when_contact_address_changed_after_enqueue(): void {
		$email   = 'automation-before@example.test';
		$contact = $this->make_contact( array( 'email' => $email ) );

		$id = $this->jobs->enqueue(
			array(
				'job_key'     => 'automation:1:run:1:start:fixture:step:1',
				'kind'        => 'automation',
				'contact_id'  => $contact,
				'recipient'   => $email,
				'subject'     => 'Automation fixture',
				'payload'     => array(
					'template' => array(
						'mode'    => 'html',
						'content' => '<p>Hello</p>',
						'subject' => 'Automation fixture',
					),
				),
			)
		);
		$this->assertIsInt( $id );

		$this->assertNotWPError( $this->contacts->update( $contact, array( 'email' => 'automation-after@example.test' ) ) );

		( new Queue() )->run();

		$job = $this->jobs->get( (int) $id );

		$this->assertSame( EmailJobRepository::STATUS_SUPPRESSED, $job['status'] );
		$this->assertSame( 'recipient_no_longer_eligible', $job['last_error'] );
		$this->assertSame( array(), get_option( EmailDispatcher::CAPTURE_OPTION, array() ) );
	}

	public function test_legacy_cart_order_during_handoff_keeps_claim_and_accepts(): void {
		$this->enable_cart_sending();

		$email   = 'legacy-handoff@example.test';
		$contact = $this->make_contact( array( 'email' => $email ) );
		$cart    = $this->abandoned_cart( $email );
		$job_id  = $this->legacy_cart_job( $cart, $contact, $email );
		$cart_id = (int) $cart['id'];

		$this->set_settings( array( 'email_transport' => 'wp_mail' ) );

		$order_hook = function ( $short_circuit, $atts ) use ( $job_id, $cart_id, $email ) {
			$this->assertNull( $short_circuit );

			$before = $this->jobs->get( $job_id );
			$this->assertSame( EmailJobRepository::STATUS_SENDING, $before['status'] );
			$this->assertNotEmpty( (string) $before['claim_token'] );

			$this->place_cod_order( $cart_id, $email );

			$after = $this->jobs->get( $job_id );
			$this->assertSame( EmailJobRepository::STATUS_SENDING, $after['status'] );
			$this->assertSame( $before['claim_token'], $after['claim_token'] );

			return true;
		};

		add_filter( 'pre_wp_mail', $order_hook, 10, 2 );
		( new Queue() )->run();
		remove_filter( 'pre_wp_mail', $order_hook, 10 );

		$job = $this->jobs->get( $job_id );

		$this->assertSame( EmailJobRepository::STATUS_ACCEPTED, $job['status'] );
		$this->assertSame( 1, $job['attempts'] );
		$this->assertNull( $job['claim_token'] );
		$this->assertSame( 1, (int) ( new CartRepository() )->get( $cart_id )['reminders_sent'] );
		$this->assertSame( array(), get_option( EmailDispatcher::CAPTURE_OPTION, array() ) );
	}

	public function test_cart_automation_order_during_handoff_keeps_claim_and_accepts(): void {
		$this->enable_cart_sending();

		$email   = 'versioned-handoff@example.test';
		$fixture = $this->cart_automation_fixture( $email );
		$job_id  = (int) $fixture['job']['id'];
		$cart_id = (int) $fixture['cart']['id'];

		$this->set_settings( array( 'email_transport' => 'wp_mail' ) );

		$order_hook = function ( $short_circuit, $atts ) use ( $job_id, $cart_id, $email ) {
			$this->assertNull( $short_circuit );

			$before = $this->jobs->get( $job_id );
			$this->assertSame( EmailJobRepository::STATUS_SENDING, $before['status'] );
			$this->assertNotEmpty( (string) $before['claim_token'] );

			$this->place_cod_order( $cart_id, $email );

			$after = $this->jobs->get( $job_id );
			$this->assertSame( EmailJobRepository::STATUS_SENDING, $after['status'] );
			$this->assertSame( $before['claim_token'], $after['claim_token'] );

			return true;
		};

		add_filter( 'pre_wp_mail', $order_hook, 10, 2 );
		( new Queue() )->run();
		remove_filter( 'pre_wp_mail', $order_hook, 10 );

		$job = $this->jobs->get( $job_id );

		$this->assertSame( EmailJobRepository::STATUS_ACCEPTED, $job['status'] );
		$this->assertSame( 1, $job['attempts'] );
		$this->assertNull( $job['claim_token'] );
		$this->assertSame( 1, (int) ( new CartRepository() )->get( $cart_id )['reminders_sent'] );
		$this->assertSame( array(), get_option( EmailDispatcher::CAPTURE_OPTION, array() ) );
	}

	public function test_stale_legacy_payload_version_is_suppressed_before_transport(): void {
		$this->enable_cart_sending();

		$email   = 'legacy-version@example.test';
		$contact = $this->make_contact( array( 'email' => $email ) );
		$cart    = $this->abandoned_cart( $email );
		$job_id  = $this->legacy_cart_job(
			$cart,
			$contact,
			$email,
			array( 'cart_version' => (int) $cart['version'] + 1 )
		);

		( new Queue() )->run();

		$job = $this->jobs->get( $job_id );

		$this->assertSame( EmailJobRepository::STATUS_SUPPRESSED, $job['status'] );
		$this->assertSame( 'cart_version_changed', $job['last_error'] );
		$this->assertSame( 1, $job['attempts'] );
		$this->assertSame( array(), get_option( EmailDispatcher::CAPTURE_OPTION, array() ) );
	}

	public function test_stale_persisted_source_is_suppressed_before_transport(): void {
		global $wpdb;

		$this->enable_cart_sending();

		$fixture = $this->cart_automation_fixture( 'versioned-source@example.test' );
		$job_id  = (int) $fixture['job']['id'];
		$cart_id = (int) $fixture['cart']['id'];

		// The frozen payload stays current; only the persisted row source is stale.
		$wpdb->update(
			Schema::table( 'email_jobs' ),
			array( 'source_key' => 'cart:' . $cart_id . ':999' ),
			array( 'id' => $job_id )
		);

		( new Queue() )->run();

		$job = $this->jobs->get( $job_id );

		$this->assertSame( EmailJobRepository::STATUS_SUPPRESSED, $job['status'] );
		$this->assertSame( 'cart_version_changed', $job['last_error'] );
		$this->assertSame( array(), get_option( EmailDispatcher::CAPTURE_OPTION, array() ) );
	}

	public function test_pending_order_before_handoff_suppresses_without_transport(): void {
		$this->enable_cart_sending();

		$email   = 'pending-before@example.test';
		$contact = $this->make_contact( array( 'email' => $email ) );
		$cart    = $this->abandoned_cart( $email );
		$cart_id = (int) $cart['id'];
		$job_id  = $this->legacy_cart_job( $cart, $contact, $email );

		$rows = $this->jobs->claim( 1, 'pending-claim' );

		$this->assertCount( 1, $rows );
		$this->assertSame( EmailJobRepository::STATUS_SENDING, $this->jobs->get( $job_id )['status'] );

		$this->place_cod_order( $cart_id, $email );

		$result = ( new Queue() )->process_rows( $rows, 'pending-claim' );

		$this->assertSame( 1, $result['suppressed'] );
		$this->assertSame( 0, $result['accepted'] );

		$job = $this->jobs->get( $job_id );

		$this->assertSame( EmailJobRepository::STATUS_SUPPRESSED, $job['status'] );
		$this->assertSame( 'cart_not_abandoned', $job['last_error'] );
		$this->assertSame( array(), get_option( EmailDispatcher::CAPTURE_OPTION, array() ) );
	}

	public function test_cart_wp_mail_false_is_unknown_for_legacy_and_versioned_jobs(): void {
		$this->enable_cart_sending();

		$legacy_email   = 'legacy-uncertain@example.test';
		$legacy_contact = $this->make_contact( array( 'email' => $legacy_email ) );
		$legacy_cart    = $this->abandoned_cart( $legacy_email );
		$legacy_job     = $this->legacy_cart_job( $legacy_cart, $legacy_contact, $legacy_email );

		$versioned       = $this->cart_automation_fixture( 'versioned-uncertain@example.test' );
		$versioned_job   = (int) $versioned['job']['id'];
		$versioned_cart  = (int) $versioned['cart']['id'];

		$this->set_settings( array( 'email_transport' => 'wp_mail' ) );

		$calls = 0;
		$fail  = function ( $short_circuit ) use ( &$calls ) {
			$this->assertNull( $short_circuit );
			++$calls;

			return false;
		};

		add_filter( 'pre_wp_mail', $fail, 10, 2 );
		( new Queue() )->run();
		remove_filter( 'pre_wp_mail', $fail, 10 );

		$this->assertSame( 2, $calls );

		foreach ( array( $legacy_job, $versioned_job ) as $job_id ) {
			$job = $this->jobs->get( $job_id );

			$this->assertSame( EmailJobRepository::STATUS_UNKNOWN, $job['status'] );
			$this->assertSame( 'wp_mail_returned_false', $job['last_error'] );
			$this->assertSame( 1, $job['attempts'] );
			$this->assertFalse( $this->jobs->retry( $job_id ) );
		}

		$this->assertSame( 0, (int) ( new CartRepository() )->get( (int) $legacy_cart['id'] )['reminders_sent'] );
		$this->assertSame( 0, (int) ( new CartRepository() )->get( $versioned_cart )['reminders_sent'] );
		$this->assertSame( array(), $this->jobs->claim( 10, 'after-unknown' ) );
		$this->assertSame( array(), get_option( EmailDispatcher::CAPTURE_OPTION, array() ) );
	}

	public function test_expired_cart_leases_become_unknown_and_are_never_reclaimed(): void {
		global $wpdb;

		$this->enable_cart_sending();

		$legacy_email   = 'legacy-lease@example.test';
		$legacy_contact = $this->make_contact( array( 'email' => $legacy_email ) );
		$legacy_cart    = $this->abandoned_cart( $legacy_email );
		$legacy_job     = $this->legacy_cart_job( $legacy_cart, $legacy_contact, $legacy_email );

		$versioned      = $this->cart_automation_fixture( 'versioned-lease@example.test' );
		$versioned_job  = (int) $versioned['job']['id'];

		$rows = $this->jobs->claim( 10, 'lease-claim' );

		$this->assertCount( 2, $rows );

		$wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . Schema::table( 'email_jobs' ) . ' SET lease_expires_at = %s WHERE id IN (%d, %d)',
				gmdate( 'Y-m-d H:i:s', time() - 60 ),
				$legacy_job,
				$versioned_job
			)
		);

		$this->assertSame( 2, $this->jobs->release_stale() );

		foreach ( array( $legacy_job, $versioned_job ) as $job_id ) {
			$job = $this->jobs->get( $job_id );

			$this->assertSame( EmailJobRepository::STATUS_UNKNOWN, $job['status'] );
			$this->assertSame( 1, $job['attempts'] );
			$this->assertFalse( $this->jobs->retry( $job_id ) );
		}

		$this->assertSame( array(), $this->jobs->claim( 10, 'lease-after' ) );
	}

	public function test_cancel_operations_never_overwrite_a_sending_cart_claim(): void {
		$this->enable_cart_sending();

		$fixture = $this->cart_automation_fixture( 'cancel-fence@example.test' );
		$job_id  = (int) $fixture['job']['id'];
		$cart_id = (int) $fixture['cart']['id'];

		$rows = $this->jobs->claim( 1, 'cancel-claim' );

		$this->assertCount( 1, $rows );
		$this->assertSame( EmailJobRepository::STATUS_SENDING, $this->jobs->get( $job_id )['status'] );
		$token = (string) $this->jobs->get( $job_id )['claim_token'];

		$this->assertSame( 0, $this->jobs->cancel_cart( $cart_id, 'cancel_probe' ) );
		$this->assertFalse( $this->jobs->cancel_job( $job_id, 'cancel_probe' ) );
		$this->assertSame( 0, $this->jobs->cancel_source( $cart_id, 'cart:' . $cart_id . ':999', 'cancel_probe' ) );

		$job = $this->jobs->get( $job_id );

		$this->assertSame( EmailJobRepository::STATUS_SENDING, $job['status'] );
		$this->assertSame( $token, $job['claim_token'] );
	}

	public function test_wp_mail_handoff_emits_correlation_tag_and_configuration_set(): void {
		$this->set_settings(
			array(
				'email_transport'       => 'wp_mail',
				'email_from_name'       => 'NOW CRM Fixture',
				'email_from_address'    => 'sender@example.test',
				'ses_configuration_set' => 'bad name!@#-ok',
			)
		);

		$campaign = $this->make_campaign();
		$this->activate_campaign( $campaign );

		$email   = 'correlation@example.test';
		$contact = $this->make_contact( array( 'email' => $email ) );
		$job_id  = $this->enqueue_job( $campaign, $contact, $email );

		$sent_headers = array();
		$capture      = static function ( $short_circuit, $atts ) use ( &$sent_headers ) {
			foreach ( (array) ( $atts['headers'] ?? array() ) as $header ) {
				$sent_headers[] = trim( (string) $header );
			}

			return true;
		};

		add_filter( 'pre_wp_mail', $capture, 10, 2 );
		( new Queue() )->run();
		remove_filter( 'pre_wp_mail', $capture, 10 );

		$job = $this->jobs->get( $job_id );
		$this->assertSame( EmailJobRepository::STATUS_ACCEPTED, $job['status'] );
		$this->assertMatchesRegularExpression( '/^[1-9][0-9]*-[A-Za-z0-9_-]{16,64}$/', $job['ses_correlation'] );

		$joined = implode( "\n", $sent_headers );

		$this->assertStringContainsString( 'X-NowCRM-Job: ' . $job['ses_correlation'], $joined );
		$this->assertStringContainsString( 'X-SES-MESSAGE-TAGS: nowcrm_job=' . $job['ses_correlation'], $joined );
		$this->assertStringContainsString( 'X-SES-CONFIGURATION-SET: badname-ok', $joined );

		// Feed the real captured tag back through the SNS path.
		$result = ( new Processor() )->process(
			array(
				'Type'      => 'Notification',
				'MessageId' => 'sns-captured-' . wp_generate_password( 6, false ),
				'TopicArn'  => 'arn:aws:sns:us-west-2:123456789012:nowcrm-test',
				'Timestamp' => gmdate( 'c' ),
				'Message'   => wp_json_encode(
					array(
						'notificationType' => 'Delivery',
						'mail'             => array(
							'messageId' => 'ses-captured',
							'tags'      => array( 'nowcrm_job' => array( $job['ses_correlation'] ) ),
						),
						'delivery'         => array( 'recipients' => array( array( 'emailAddress' => $email ) ) ),
					)
				),
			)
		);

		$this->assertSame( 'delivery', $result['reason'] );
		$this->assertSame(
			EmailJobRepository::DELIVERY_DELIVERED,
			$this->jobs->get( $job_id )['delivery_status']
		);
	}

	public function test_automation_and_sequence_jobs_get_real_manage_tokens(): void {
		$email   = 'manage-links@example.test';
		$contact = $this->make_contact( array( 'email' => $email ) );
		$template = array(
			'mode'    => 'html',
			'content' => '<p>Hello</p>',
			'subject' => 'Fixture',
		);

		$kinds = array( 'automation', 'sequence' );

		foreach ( $kinds as $kind ) {
			delete_option( EmailDispatcher::CAPTURE_OPTION );

			$id = $this->jobs->enqueue(
				array(
					'job_key'    => $kind . ':manage:' . wp_generate_password( 6, false ),
					'kind'       => $kind,
					'contact_id' => $contact,
					'recipient'  => $email,
					'subject'    => 'Fixture',
					'payload'    => array( 'template' => $template ),
				)
			);

			$this->assertIsInt( $id );

			( new Queue() )->run();

			$this->assertSame( EmailJobRepository::STATUS_ACCEPTED, $this->jobs->get( (int) $id )['status'] );

			$log = get_option( EmailDispatcher::CAPTURE_OPTION, array() );
			$this->assertCount( 1, $log );

			$html = (string) $log[0]['html'];
			$this->assertMatchesRegularExpression( '/href="[^"]*nowcrm_preferences[^"]*token=[^"&]+/', $html, $kind );
			$this->assertMatchesRegularExpression( '/nowcrm_unsubscribe[^"]*token=[^"&]+/', $html, $kind );
		}
	}

	public function test_missing_manage_token_never_labels_unsubscribe_as_preferences(): void {
		$rendered = ( new EmailRenderer() )->render(
			array(
				'mode'        => 'html',
				'content'     => '<p>Hello</p>',
				'subject'     => 'Fallback',
				'footer_text' => '{{unsubscribe_url}} / {{manage_url}}',
			),
			array(
				'email'           => 'fallback@example.test',
				'unsubscribe_url' => 'https://example.test/?nowcrm_unsubscribe=1&token=unsub',
				'manage_url'      => '',
				'site_name'       => 'Fixture',
				'site_url'        => home_url( '/' ),
			)
		);

		preg_match_all( '/href="([^"]+)"/', $rendered['html'], $matches );

		$hrefs    = array_map( 'html_entity_decode', $matches[1] );
		$prefs    = array_values( array_filter( $hrefs, static fn( string $url ): bool => false !== strpos( $url, 'nowcrm_preferences' ) ) );
		$unsubs   = array_values( array_filter( $hrefs, static fn( string $url ): bool => false !== strpos( $url, 'nowcrm_unsubscribe' ) ) );

		$this->assertNotEmpty( $prefs );
		$this->assertNotEmpty( $unsubs );
		$this->assertNotSame( $unsubs[0], $prefs[0] );
		$this->assertStringNotContainsString( 'nowcrm_unsubscribe', $prefs[0] );
	}

	public function test_legacy_cart_job_reaches_a_transactional_contact(): void {
		$this->enable_cart_sending();

		$email   = 'guest-legacy@example.test';
		$contact = $this->make_contact(
			array(
				'email'  => $email,
				'status' => Status::TRANSACTIONAL,
			)
		);
		$cart    = $this->abandoned_cart( $email );
		$job_id  = $this->legacy_cart_job( $cart, $contact, $email );

		( new Queue() )->run();

		$job = $this->jobs->get( $job_id );

		$this->assertSame( EmailJobRepository::STATUS_ACCEPTED, $job['status'] );
		$this->assertSame( Status::TRANSACTIONAL, $this->contacts->get( $contact )['status'] );

		$log = get_option( EmailDispatcher::CAPTURE_OPTION, array() );
		$this->assertCount( 1, $log );
		$this->assertSame( $email, $log[0]['to'] );
	}

	public function test_versioned_cart_job_reaches_a_transactional_contact(): void {
		$this->enable_cart_sending();

		$email   = 'guest-versioned@example.test';
		$fixture = $this->cart_automation_fixture( $email, Status::TRANSACTIONAL );

		( new Queue() )->run();

		$job = $this->jobs->get( (int) $fixture['job']['id'] );

		$this->assertSame( EmailJobRepository::STATUS_ACCEPTED, $job['status'] );
		$this->assertSame( Status::TRANSACTIONAL, $this->contacts->get( $fixture['contact'] )['status'] );

		$log = get_option( EmailDispatcher::CAPTURE_OPTION, array() );
		$this->assertCount( 1, $log );
		$this->assertSame( $email, $log[0]['to'] );
	}

	public function test_cart_policy_blocks_every_other_status(): void {
		$this->enable_cart_sending();

		$blocked = array( Status::PENDING, Status::UNSUBSCRIBED, Status::BOUNCED, Status::COMPLAINED, Status::SPAMMED );

		foreach ( $blocked as $status ) {
			$email   = 'cart-blocked-' . $status . '@example.test';
			$contact = $this->make_contact(
				array(
					'email'  => $email,
					'status' => $status,
				)
			);
			$cart    = $this->abandoned_cart( $email );
			$job_id  = $this->legacy_cart_job( $cart, $contact, $email );

			( new Queue() )->run();

			$job = $this->jobs->get( $job_id );

			$this->assertSame( EmailJobRepository::STATUS_SUPPRESSED, $job['status'], $status );
			$this->assertSame( 'not_subscribed', $job['last_error'], $status );
		}

		$this->assertSame( array(), get_option( EmailDispatcher::CAPTURE_OPTION, array() ) );
	}

	public function test_campaign_keeps_requiring_subscription_for_a_transactional_contact(): void {
		$campaign = $this->make_campaign();
		$this->activate_campaign( $campaign );

		$email   = 'marketing-transactional@example.test';
		$contact = $this->make_contact(
			array(
				'email'  => $email,
				'status' => Status::TRANSACTIONAL,
			)
		);
		$job_id  = $this->enqueue_job( $campaign, $contact, $email );

		( new Queue() )->run();

		$job = $this->jobs->get( $job_id );

		$this->assertSame( EmailJobRepository::STATUS_SUPPRESSED, $job['status'] );
		$this->assertSame( 'not_subscribed', $job['last_error'] );
		$this->assertSame( array(), get_option( EmailDispatcher::CAPTURE_OPTION, array() ) );
	}

	public function test_generic_automation_keeps_requiring_subscription_for_a_transactional_contact(): void {
		$email   = 'automation-transactional@example.test';
		$contact = $this->make_contact(
			array(
				'email'  => $email,
				'status' => Status::TRANSACTIONAL,
			)
		);

		$job_id = $this->jobs->enqueue(
			array(
				'job_key'    => 'automation:marketing:transactional',
				'kind'       => 'automation',
				'contact_id' => $contact,
				'recipient'  => $email,
				'subject'    => 'Marketing automation',
				'payload'    => array(
					'template' => array(
						'mode'    => 'html',
						'content' => '<p>Hello</p>',
						'subject' => 'Marketing automation',
					),
				),
			)
		);

		$this->assertIsInt( $job_id );

		( new Queue() )->run();

		$job = $this->jobs->get( (int) $job_id );

		$this->assertSame( EmailJobRepository::STATUS_SUPPRESSED, $job['status'] );
		$this->assertSame( 'not_subscribed', $job['last_error'] );
		$this->assertSame( array(), get_option( EmailDispatcher::CAPTURE_OPTION, array() ) );
	}

	public function test_cart_job_is_suppressed_when_the_contact_unsubscribes_after_enqueue(): void {
		$this->enable_cart_sending();

		$email   = 'cart-later-unsub@example.test';
		$contact = $this->make_contact(
			array(
				'email'  => $email,
				'status' => Status::TRANSACTIONAL,
			)
		);
		$cart    = $this->abandoned_cart( $email );
		$job_id  = $this->legacy_cart_job( $cart, $contact, $email );

		$this->assertTrue( $this->contacts->set_status( $contact, Status::UNSUBSCRIBED, 'test' ) );

		( new Queue() )->run();

		$job = $this->jobs->get( $job_id );

		$this->assertSame( EmailJobRepository::STATUS_SUPPRESSED, $job['status'] );
		$this->assertSame( 'not_subscribed', $job['last_error'] );
		$this->assertSame( array(), get_option( EmailDispatcher::CAPTURE_OPTION, array() ) );
	}

	public function test_cart_job_is_suppressed_when_the_address_is_suppressed_after_enqueue(): void {
		$this->enable_cart_sending();

		$email   = 'cart-later-bounce@example.test';
		$contact = $this->make_contact(
			array(
				'email'  => $email,
				'status' => Status::TRANSACTIONAL,
			)
		);
		$cart    = $this->abandoned_cart( $email );
		$job_id  = $this->legacy_cart_job( $cart, $contact, $email );

		( new SuppressionRepository() )->add( $email, 'bounced', 'test' );

		( new Queue() )->run();

		$job = $this->jobs->get( $job_id );

		$this->assertSame( EmailJobRepository::STATUS_SUPPRESSED, $job['status'] );
		$this->assertSame( 'suppressed', $job['last_error'] );
		$this->assertSame( array(), get_option( EmailDispatcher::CAPTURE_OPTION, array() ) );
	}

	public function test_cart_job_is_suppressed_when_the_status_is_blocked_after_enqueue(): void {
		$this->enable_cart_sending();

		$blocked = array( Status::PENDING, Status::BOUNCED, Status::COMPLAINED, Status::SPAMMED );

		foreach ( $blocked as $status ) {
			$email   = 'cart-later-' . $status . '@example.test';
			$contact = $this->make_contact(
				array(
					'email'  => $email,
					'status' => Status::TRANSACTIONAL,
				)
			);
			$cart    = $this->abandoned_cart( $email );
			$job_id  = $this->legacy_cart_job( $cart, $contact, $email );

			$this->assertTrue( $this->contacts->set_status( $contact, $status, 'test' ) );

			( new Queue() )->run();

			$job = $this->jobs->get( $job_id );

			$this->assertSame( EmailJobRepository::STATUS_SUPPRESSED, $job['status'], $status );
			$this->assertSame( 'not_subscribed', $job['last_error'], $status );
		}

		$this->assertSame( array(), get_option( EmailDispatcher::CAPTURE_OPTION, array() ) );
	}

	public function test_versioned_cart_job_is_suppressed_after_the_contact_unsubscribes(): void {
		$this->enable_cart_sending();

		$email   = 'versioned-later-unsub@example.test';
		$fixture = $this->cart_automation_fixture( $email, Status::TRANSACTIONAL );

		$this->assertTrue( $this->contacts->set_status( $fixture['contact'], Status::UNSUBSCRIBED, 'test' ) );

		( new Queue() )->run();

		$job = $this->jobs->get( (int) $fixture['job']['id'] );

		$this->assertSame( EmailJobRepository::STATUS_SUPPRESSED, $job['status'] );
		$this->assertSame( 'not_subscribed', $job['last_error'] );
		$this->assertSame( array(), get_option( EmailDispatcher::CAPTURE_OPTION, array() ) );
	}

	public function test_claim_sql_failure_does_not_record_worker_success(): void {
		$campaign = $this->make_campaign();
		$this->activate_campaign( $campaign );

		$contact = $this->make_contact();
		$email   = $this->contacts->get( $contact )['email'];
		$job_id  = $this->enqueue_job( $campaign, $contact, $email );

		update_option( WorkerHealth::OPTION, 4242, false );

		global $wpdb;

		$fail = static function ( $query ) use ( $wpdb ) {
			if ( false !== strpos( (string) $query, 'ses_correlation = CONCAT' ) ) {
				// Deterministic invalid SQL: the claim UPDATE must report a
				// database failure, not an idle tick.
				return 'UPDATE ' . $wpdb->prefix . 'nowcrm_claim_failure_probe SET nope = 1';
			}

			return $query;
		};

		add_filter( 'query', $fail );

		try {
			( new Queue() )->run();
			$this->fail( 'A failed claim UPDATE must abort the tick.' );
		} catch ( \RuntimeException $error ) {
			$this->assertSame( 'email_job_claim_update_failed', $error->getMessage() );
		} finally {
			remove_filter( 'query', $fail );
		}

		$this->assertSame( 4242, (int) get_option( WorkerHealth::OPTION ) );

		$job = $this->jobs->get( $job_id );

		$this->assertSame( EmailJobRepository::STATUS_QUEUED, $job['status'] );
		$this->assertSame( 0, $job['attempts'] );
		$this->assertSame( '', $job['ses_correlation'] );
	}
}
