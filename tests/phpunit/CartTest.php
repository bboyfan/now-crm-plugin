<?php
/**
 * Cart cycle, abandonment policy, reminder and recovery.
 *
 * @package NowCRM
 */

use NowCRM\Cart\CartRepository;
use NowCRM\Cart\Automation as CartAutomation;
use NowCRM\Cart\Recovery;
use NowCRM\Cart\Reminder;
use NowCRM\Cart\Sweeper;
use NowCRM\Automation\Engine;
use NowCRM\Campaign\Campaigns;
use NowCRM\Contacts\Status;
use NowCRM\Email\EmailDispatcher;
use NowCRM\Email\EmailJobRepository;
use NowCRM\Email\EmailTemplateRepository;
use NowCRM\Email\Queue;

/**
 * @covers \NowCRM\Cart\CartRepository
 * @covers \NowCRM\Cart\Sweeper
 * @covers \NowCRM\Cart\Reminder
 * @covers \NowCRM\Cart\Recovery
 */
final class CartTest extends NowCrmTestCase {

	private CartRepository $carts;

	public function set_up(): void {
		parent::set_up();

		$this->carts = new CartRepository();
		$this->set_settings(
			array(
				'abandoned_cart_enabled'      => true,
				'email_sending_enabled'       => true,
				'email_transport'             => 'capture',
				'email_throttle_per_second'   => 100,
				'cart_idle_minutes'           => 20,
				'cart_reminder_delay_minutes' => 0,
				'cart_lost_days'              => 5,
				'cart_cooldown_days'          => 3,
				'cart_max_reminders'          => 1,
				'cart_recovery_days'          => 7,
			)
		);

		if ( function_exists( 'WC' ) && ! WC()->cart && function_exists( 'wc_load_cart' ) ) {
			wc_load_cart();
		}

		if ( function_exists( 'WC' ) && WC()->cart ) {
			WC()->cart->empty_cart();
		}
	}

	/**
	 * Queue one cart reminder through the single Automation producer.
	 *
	 * Returns true only when this call created a new run and job, preserving
	 * the old "was a reminder enqueued now" assertion shape.
	 *
	 * @param array<string, mixed> $cart Cart row.
	 */
	private function queue_reminder( array $cart ): bool {
		$result = CartAutomation::start_for_cart( (int) $cart['id'], 'cart:' . (int) $cart['id'] );

		if ( is_wp_error( $result ) ) {
			return false;
		}

		( new Engine() )->run_due();

		return ! empty( $result['started'] );
	}

	/**
	 * Simple in-stock product.
	 */
	private function make_product( float $price = 100, int $stock = 10 ): \WC_Product {
		$product = new \WC_Product_Simple();
		$product->set_name( 'Fixture product ' . wp_generate_password( 5, false ) );
		$product->set_regular_price( (string) $price );
		$product->set_manage_stock( true );
		$product->set_stock_quantity( $stock );
		$product->set_status( 'publish' );
		$product->save();

		return $product;
	}

	public function test_activity_is_keyed_by_cycle_and_bumps_version(): void {
		$cart = $this->make_cart();
		$id   = (int) $cart['id'];
		$key  = 'cycle' . bin2hex( random_bytes( 8 ) );

		$first  = $this->carts->record_activity( array( 'cycle_key' => $key, 'email' => 'a@example.test', 'snapshot' => array(), 'item_count' => 1, 'total' => 10 ) );
		$second = $this->carts->record_activity( array( 'cycle_key' => $key, 'email' => 'a@example.test', 'snapshot' => array(), 'item_count' => 2, 'total' => 20 ) );

		$this->assertSame( (int) $first['id'], (int) $second['id'] );
		$this->assertSame( 2, (int) $second['version'] );
		$this->assertSame( 2, (int) $second['item_count'] );
	}

	public function test_completed_cycle_cannot_be_revived_by_late_activity(): void {
		$key  = 'done' . bin2hex( random_bytes( 8 ) );
		$cart = $this->carts->record_activity( array( 'cycle_key' => $key, 'email' => 'done@example.test', 'snapshot' => array(), 'item_count' => 1, 'total' => 10 ) );

		$this->carts->complete_with_order( (int) $cart['id'], 123, 'natural' );

		$result = $this->carts->record_activity( array( 'cycle_key' => $key, 'email' => 'done@example.test', 'snapshot' => array(), 'item_count' => 1, 'total' => 10 ) );

		$this->assertWPError( $result );
		$this->assertSame( CartRepository::STATUS_COMPLETED, $this->carts->get( (int) $cart['id'] )['status'] );
	}

	public function test_sweep_abandons_idle_cart_and_queues_one_reminder_for_subscribed_contact(): void {
		global $wpdb;

		$product = $this->make_product();
		$email   = 'abandon@example.test';

		$this->make_contact( array( 'email' => $email ) );
		$cart = $this->make_cart(
			array(
				'email'    => $email,
				'snapshot' => array( array( 'product_id' => $product->get_id(), 'variation_id' => 0, 'quantity' => 1, 'variation' => array() ) ),
			)
		);

		$wpdb->update(
			\NowCRM\Support\Schema::table( 'carts' ),
			array( 'last_activity_at' => gmdate( 'Y-m-d H:i:s', time() - 3600 ) ),
			array( 'id' => (int) $cart['id'] )
		);

		$result = ( new Sweeper() )->run();

		$this->assertSame( 1, $result['abandoned'] );

		( new Engine() )->run_due();

		$job = ( new EmailJobRepository() )->search( array( 'cart_id' => (int) $cart['id'] ) );

		$this->assertSame( 1, $job['total'] );
		$this->assertSame( 'automation', $job['items'][0]['kind'] );
	}

	public function test_transactional_contact_receives_the_cart_reminder(): void {
		$email   = 'transactional@example.test';
		$contact = $this->make_contact(
			array(
				'email'  => $email,
				'status' => Status::TRANSACTIONAL,
			)
		);

		$cart = $this->make_cart( array( 'email' => $email ) );
		$this->carts->mark_abandoned( (int) $cart['id'] );

		$this->assertTrue( $this->queue_reminder( $this->carts->get( (int) $cart['id'] ) ) );

		$jobs = ( new EmailJobRepository() )->search( array( 'cart_id' => (int) $cart['id'] ) );

		$this->assertSame( 1, $jobs['total'] );
		$this->assertSame( Status::TRANSACTIONAL, $this->contacts->get( $contact )['status'] );

		( new Queue() )->run();

		$this->assertCount( 1, get_option( EmailDispatcher::CAPTURE_OPTION, array() ) );
	}

	public function test_unsubscribed_contact_does_not_receive_cart_marketing(): void {
		$email = 'cart-unsubscribed@example.test';
		$this->make_contact(
			array(
				'email'  => $email,
				'status' => Status::UNSUBSCRIBED,
			)
		);

		$cart = $this->make_cart( array( 'email' => $email ) );
		$this->carts->mark_abandoned( (int) $cart['id'] );

		$this->assertTrue( $this->queue_reminder( $this->carts->get( (int) $cart['id'] ) ) );

		$this->assertSame( 0, ( new EmailJobRepository() )->search( array( 'cart_id' => (int) $cart['id'] ) )['total'] );

		( new Queue() )->run();

		$this->assertSame( array(), get_option( EmailDispatcher::CAPTURE_OPTION, array() ) );
	}

	public function test_managed_flow_seeds_from_a_cart_template_once(): void {
		$templates = new EmailTemplateRepository();

		$template = $templates->create(
			array(
				'kind'    => 'cart',
				'name'    => 'abandoned-cart',
				'subject' => 'Fixture cart template subject',
				'content' => '<p>Fixture cart template body</p>{{cart_items}}<p>{{recovery_url}}</p>',
			)
		);

		$this->assertIsInt( $template );
		$this->set_settings( array( 'cart_reminder_template_id' => (int) $template ) );
		delete_option( CartAutomation::MANAGED_OPTION );
		delete_option( CartAutomation::MIGRATED_OPTION );

		$email = 'template-seed@example.test';
		$this->make_contact( array( 'email' => $email ) );
		$cart = $this->make_cart( array( 'email' => $email ) );
		$this->carts->mark_abandoned( (int) $cart['id'] );

		$this->assertTrue( $this->queue_reminder( $this->carts->get( (int) $cart['id'] ) ) );

		$job = ( new EmailJobRepository() )->search( array( 'cart_id' => (int) $cart['id'] ) );

		$this->assertSame( 1, $job['total'] );
		$this->assertSame( 'Fixture cart template subject', $job['items'][0]['subject'] );
		$this->assertStringContainsString( 'Fixture cart template body', (string) ( $job['items'][0]['payload']['template']['content'] ?? '' ) );

		// Once the managed flow exists, editing the legacy template must not
		// silently change the automation-owned email.
		$this->assertTrue(
			$templates->update(
				(int) $template,
				array(
					'name'    => 'abandoned-cart',
					'subject' => 'Changed template subject',
					'content' => '<p>Changed template body</p>',
					'mode'    => 'html',
				)
			)
		);

		$second_email = 'template-after-seed@example.test';
		$this->make_contact( array( 'email' => $second_email ) );
		$second = $this->make_cart( array( 'email' => $second_email ) );
		$this->carts->mark_abandoned( (int) $second['id'] );

		$this->assertTrue( $this->queue_reminder( $this->carts->get( (int) $second['id'] ) ) );

		$job = ( new EmailJobRepository() )->search( array( 'cart_id' => (int) $second['id'] ) );

		$this->assertSame( 1, $job['total'] );
		$this->assertSame( 'Fixture cart template subject', $job['items'][0]['subject'] );
		$this->assertStringNotContainsString( 'Changed template body', (string) ( $job['items'][0]['payload']['template']['content'] ?? '' ) );
	}

	public function test_managed_flow_uses_the_builtin_body_for_a_non_cart_template(): void {
		$templates = new EmailTemplateRepository();

		$wrong = $templates->create(
			array(
				'kind'    => 'custom',
				'name'    => 'not-a-cart-template',
				'subject' => 'Wrong template subject',
				'content' => '<p>Wrong template body</p>',
			)
		);

		$this->assertIsInt( $wrong );
		$this->set_settings( array( 'cart_reminder_template_id' => (int) $wrong ) );
		delete_option( CartAutomation::MANAGED_OPTION );
		delete_option( CartAutomation::MIGRATED_OPTION );

		$email = 'template-kind@example.test';
		$this->make_contact( array( 'email' => $email ) );
		$cart = $this->make_cart( array( 'email' => $email ) );
		$this->carts->mark_abandoned( (int) $cart['id'] );

		$this->assertTrue( $this->queue_reminder( $this->carts->get( (int) $cart['id'] ) ) );

		$job = ( new EmailJobRepository() )->search( array( 'cart_id' => (int) $cart['id'] ) );

		$this->assertSame( 1, $job['total'] );
		$this->assertSame( '{{first_name}}，您的購物車還留著', $job['items'][0]['subject'] );
		$this->assertStringNotContainsString( 'Wrong template body', (string) ( $job['items'][0]['payload']['template']['content'] ?? '' ) );
	}

	public function test_reminder_send_marks_cart_reminders_sent(): void {
		$this->set_settings( array( 'campaign_tracking_enabled' => true ) );

		$email   = 'send-cart@example.test';
		$product = $this->make_product();

		$this->make_contact( array( 'email' => $email ) );
		$cart = $this->make_cart(
			array(
				'email'    => $email,
				'snapshot' => array( array( 'product_id' => $product->get_id(), 'variation_id' => 0, 'quantity' => 1, 'variation' => array() ) ),
			)
		);

		$this->carts->mark_abandoned( (int) $cart['id'] );
		$this->assertTrue( $this->queue_reminder( $this->carts->get( (int) $cart['id'] ) ) );

		( new Queue() )->run();

		$job  = ( new EmailJobRepository() )->search( array( 'cart_id' => (int) $cart['id'] ) );
		$cart = $this->carts->get( (int) $cart['id'] );

		$this->assertSame( EmailJobRepository::STATUS_ACCEPTED, $job['items'][0]['status'] );
		$this->assertSame( 1, (int) $cart['reminders_sent'] );

		$log = get_option( EmailDispatcher::CAPTURE_OPTION, array() );
		$this->assertCount( 1, $log );
		$this->assertStringContainsString( '/nowcrm/v1/click/', $log[0]['html'] );
	}

	public function test_second_reminder_is_blocked_by_max_reminders(): void {
		$email = 'max@example.test';
		$this->make_contact( array( 'email' => $email ) );

		$cart = $this->make_cart( array( 'email' => $email ) );
		$this->carts->mark_abandoned( (int) $cart['id'] );
		$this->assertTrue( $this->queue_reminder( $this->carts->get( (int) $cart['id'] ) ) );

		( new Queue() )->run();

		$cart = $this->carts->get( (int) $cart['id'] );
		$this->assertFalse( $this->queue_reminder( $cart ) );
	}

	public function test_repeated_sweep_keeps_the_queued_recovery_token(): void {
		$email = 'repeat-token@example.test';
		$this->make_contact( array( 'email' => $email ) );

		$cart = $this->make_cart( array( 'email' => $email ) );
		$this->carts->mark_abandoned( (int) $cart['id'] );

		$this->assertTrue( $this->queue_reminder( $this->carts->get( (int) $cart['id'] ) ) );

		$jobs   = new EmailJobRepository();
		$queued = $jobs->search( array( 'cart_id' => (int) $cart['id'] ) );
		$url    = (string) ( $queued['items'][0]['payload']['recovery_url'] ?? '' );
		$query  = array();

		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );

		$raw  = (string) ( $query['token'] ?? '' );
		$hash = (string) $this->carts->get( (int) $cart['id'] )['recovery_token_hash'];

		$this->assertNotSame( '', $raw );

		// A later due sweep must not rotate the token of the queued job.
		$this->assertFalse( $this->queue_reminder( $this->carts->get( (int) $cart['id'] ) ) );
		$this->assertSame( 0, ( new Sweeper() )->run()['reminders'] );

		$after = $this->carts->get( (int) $cart['id'] );

		$this->assertSame( $hash, (string) $after['recovery_token_hash'] );
		$this->assertSame( (int) $cart['id'], (int) ( $this->carts->find_by_recovery_token( $raw )['id'] ?? 0 ) );
		$this->assertSame( 1, $jobs->search( array( 'cart_id' => (int) $cart['id'] ) )['total'] );

		$queued_events = array_filter(
			$this->carts->events( (int) $cart['id'] ),
			static fn( array $event ): bool => 'automation_started' === $event['type']
		);

		$this->assertCount( 1, $queued_events );
	}

	public function test_disabled_cart_reminders_are_not_queued_or_sent(): void {
		$email = 'disabled-cart@example.test';
		$this->make_contact( array( 'email' => $email ) );

		$cart = $this->make_cart( array( 'email' => $email ) );
		$this->carts->mark_abandoned( (int) $cart['id'] );

		$this->assertTrue( $this->queue_reminder( $this->carts->get( (int) $cart['id'] ) ) );

		$this->set_settings( array( 'abandoned_cart_enabled' => false ) );

		$other = $this->make_cart( array( 'email' => 'disabled-other@example.test' ) );
		$this->carts->mark_abandoned( (int) $other['id'] );

		$this->assertFalse( $this->queue_reminder( $this->carts->get( (int) $other['id'] ) ) );

		( new Queue() )->run();

		$job = ( new EmailJobRepository() )->search( array( 'cart_id' => (int) $cart['id'] ) );

		$this->assertSame( EmailJobRepository::STATUS_SUPPRESSED, $job['items'][0]['status'] );
		$this->assertSame( 'cart_disabled', $job['items'][0]['last_error'] );
		$this->assertSame( array(), get_option( EmailDispatcher::CAPTURE_OPTION, array() ) );
	}

	public function test_queued_job_with_a_stale_token_is_not_sent(): void {
		$email = 'stale-token@example.test';
		$this->make_contact( array( 'email' => $email ) );

		$cart = $this->make_cart( array( 'email' => $email ) );
		$this->carts->mark_abandoned( (int) $cart['id'] );

		$this->assertTrue( $this->queue_reminder( $this->carts->get( (int) $cart['id'] ) ) );

		// Simulate a job queued before the token was rotated by an older build.
		$this->carts->set_recovery_token(
			(int) $cart['id'],
			hash( 'sha256', 'a-token-the-job-does-not-have' ),
			gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS )
		);

		( new Queue() )->run();

		$job = ( new EmailJobRepository() )->search( array( 'cart_id' => (int) $cart['id'] ) );

		$this->assertSame( EmailJobRepository::STATUS_SUPPRESSED, $job['items'][0]['status'] );
		$this->assertSame( 'recovery_token_stale', $job['items'][0]['last_error'] );
		$this->assertSame( array(), get_option( EmailDispatcher::CAPTURE_OPTION, array() ) );

		// The send gate never rewrites the stored token of a legacy job.
		$this->assertSame(
			hash( 'sha256', 'a-token-the-job-does-not-have' ),
			(string) $this->carts->get( (int) $cart['id'] )['recovery_token_hash']
		);
	}

	public function test_cooldown_blocks_a_second_cart_for_the_same_address(): void {
		global $wpdb;

		$email = 'cooldown@example.test';
		$this->make_contact( array( 'email' => $email ) );

		$first = $this->make_cart( array( 'email' => $email ) );
		$this->carts->mark_abandoned( (int) $first['id'] );
		$this->assertTrue( $this->queue_reminder( $this->carts->get( (int) $first['id'] ) ) );

		( new Queue() )->run();

		$second = $this->make_cart( array( 'email' => $email ) );
		$this->carts->mark_abandoned( (int) $second['id'] );

		$this->assertSame( 'cooldown', Reminder::can_send(
			array(
				'id'         => 0,
				'cart_id'    => (int) $second['id'],
				'kind'       => 'cart',
				'contact_id' => 0,
				'recipient'  => $email,
			),
			$this->contacts->find_by_email( $email )
		) );
	}

	public function test_recovery_validation_rejects_missing_product_without_touching_the_cart(): void {
		$valid = $this->make_product();

		$this->assertTrue( WC()->cart->add_to_cart( $valid->get_id(), 1 ) !== false );

		$broken = array(
			'items' => array(
				array( 'product_id' => $valid->get_id(), 'variation_id' => 0, 'quantity' => 1, 'variation' => array() ),
				array( 'product_id' => 99999999, 'variation_id' => 0, 'quantity' => 1, 'variation' => array() ),
			),
		);

		$validation = ( new Recovery() )->validate_items( $broken);
		$this->assertFalse( $validation['ok'] );
		$this->assertNotEmpty( $validation['errors'] );

		$restored = ( new Recovery() )->restore( $broken );
		$this->assertFalse( $restored['ok'] );

		// The pre-existing cart must survive a failed restore.
		$this->assertSame( 1.0, (float) WC()->cart->get_cart_contents_count() );
	}

	public function test_recovery_replaces_the_cart_with_the_snapshot(): void {
		$keep = $this->make_product();
		$snap = $this->make_product();

		WC()->cart->empty_cart();
		WC()->cart->add_to_cart( $keep->get_id(), 1 );

		$result = ( new Recovery() )->restore(
			array(
				'items' => array(
					array( 'product_id' => $snap->get_id(), 'variation_id' => 0, 'quantity' => 1, 'variation' => array() ),
				),
			)
		);

		$this->assertTrue( $result['ok'] );

		$ids = array_map(
			static fn( $item ) => (int) $item['product_id'],
			array_values( WC()->cart->get_cart() )
		);

		$this->assertSame( array( $snap->get_id() ), $ids );
	}

	public function test_order_completion_attribution_separates_natural_from_email_click(): void {
		$cart = $this->make_cart( array( 'email' => 'attribute@example.test' ) );

		$this->carts->complete_with_order( (int) $cart['id'], 500, 'email_click' );
		$clicked = $this->carts->get( (int) $cart['id'] );

		$this->assertSame( CartRepository::STATUS_RECOVERED, $clicked['status'] );
		$this->assertSame( 'email_click', $clicked['recovery_source'] );

		$cart2 = $this->make_cart( array( 'email' => 'natural@example.test' ) );
		$this->carts->complete_with_order( (int) $cart2['id'], 501, 'natural' );
		$natural = $this->carts->get( (int) $cart2['id'] );

		$this->assertSame( CartRepository::STATUS_COMPLETED, $natural['status'] );
		$this->assertSame( 'natural', $natural['recovery_source'] );
	}

	public function test_lost_cart_cancels_queued_reminders(): void {
		$email = 'lost@example.test';
		$this->make_contact( array( 'email' => $email ) );

		$cart = $this->make_cart( array( 'email' => $email ) );
		$this->carts->mark_abandoned( (int) $cart['id'] );
		$this->assertTrue( $this->queue_reminder( $this->carts->get( (int) $cart['id'] ) ) );

		\NowCRM\Support\Settings::update( array( 'cart_lost_days' => 1 ) );

		global $wpdb;

		$wpdb->update(
			\NowCRM\Support\Schema::table( 'carts' ),
			array( 'abandoned_at' => gmdate( 'Y-m-d H:i:s', time() - 2 * DAY_IN_SECONDS ) ),
			array( 'id' => (int) $cart['id'] )
		);

		$result = ( new Sweeper() )->run();

		$this->assertSame( 1, $result['lost'] );

		$job = ( new EmailJobRepository() )->search( array( 'cart_id' => (int) $cart['id'] ) );

		$this->assertSame( EmailJobRepository::STATUS_CANCELLED, $job['items'][0]['status'] );
	}
}
