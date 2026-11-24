<?php
/**
 * Cart source identity, ordered activity, atomic transitions and exact Woo
 * payment attribution. Synthetic fixtures only; no live orders or mail.
 *
 * @package NowCRM
 */

use NowCRM\Cart\CartRepository;
use NowCRM\Cart\Reminder;
use NowCRM\Cart\Tracker;
use NowCRM\Email\EmailJobRepository;

/**
 * @covers \NowCRM\Cart\CartRepository
 * @covers \NowCRM\Cart\Tracker
 */
final class CartSourcePaymentTest extends NowCrmTestCase {

	private CartRepository $carts;

	public function set_up(): void {
		parent::set_up();

		$this->carts = new CartRepository();
		$this->set_settings(
			array(
				'abandoned_cart_enabled'      => true,
				'email_sending_enabled'       => true,
				'email_transport'             => 'capture',
				'cart_idle_minutes'           => 20,
				'cart_reminder_delay_minutes' => 0,
				'cart_lost_days'              => 5,
				'cart_cooldown_days'          => 0,
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

		if ( function_exists( 'WC' ) && ! WC()->session && method_exists( WC(), 'initialize_session' ) ) {
			WC()->initialize_session();
		}

		$this->reset_cart_session();
	}

	public function tear_down(): void {
		$this->reset_cart_session();

		parent::tear_down();
	}

	/**
	 * Clear only the NOW CRM session keys so another Woo fixture cannot leak
	 * a bound cycle or recovery state into these assertions.
	 */
	private function reset_cart_session(): void {
		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return;
		}

		WC()->session->set( 'nowcrm_cart_cycle', '' );
		WC()->session->set( 'nowcrm_recovered_cart_id', 0 );
		WC()->session->set( 'nowcrm_cycle_generation', 0 );
		WC()->session->set( 'nowcrm_cycle_rotated_at', 0 );
	}

	/**
	 * Simple in-stock product.
	 */
	private function make_product(): \WC_Product {
		$product = new \WC_Product_Simple();
		$product->set_name( 'Source fixture ' . wp_generate_password( 5, false ) );
		$product->set_regular_price( '100' );
		$product->set_manage_stock( true );
		$product->set_stock_quantity( 10 );
		$product->set_status( 'publish' );
		$product->save();

		return $product;
	}

	/**
	 * Bind a cart row to the current Woo session so Tracker sees it.
	 */
	private function bind_session( string $cycle, int $recovered_cart_id = 0 ): void {
		WC()->session->set( 'nowcrm_cart_cycle', $cycle );
		WC()->session->set( 'nowcrm_recovered_cart_id', $recovered_cart_id );
	}

	/**
	 * Same-origin capture request with a valid REST nonce.
	 *
	 * @param array<string, mixed> $params Body parameters.
	 */
	private function capture_request( array $params, string $origin = '', string $nonce = '' ): \WP_REST_Request {
		$request = new \WP_REST_Request( 'POST', '/nowcrm/v1/cart/track' );
		$request->set_body_params( $params );

		$home   = (string) wp_parse_url( home_url( '/' ), PHP_URL_SCHEME ) . '://' . (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		$port   = wp_parse_url( home_url( '/' ), PHP_URL_PORT );
		$origin = '' === $origin ? $home . ( $port ? ':' . $port : '' ) : $origin;

		$request->set_header( 'origin', $origin );
		$request->set_header( 'x-wp-nonce', '' === $nonce ? wp_create_nonce( 'wp_rest' ) : $nonce );

		return $request;
	}

	/**
	 * Cheap queued cart job used to prove invalidation.
	 */
	private function queued_job( int $cart_id, string $email ): void {
		$job = ( new EmailJobRepository() )->enqueue(
			array(
				'job_key'   => 'cart:' . $cart_id . ':reminder:1',
				'kind'      => 'cart',
				'cart_id'   => $cart_id,
				'recipient' => $email,
				'subject'   => 'Fixture cart job',
				'payload'   => array( 'cart_version' => 1 ),
				'priority'  => 5,
			)
		);

		$this->assertIsInt( $job );
	}

	private function job_status( int $cart_id ): string {
		$job = ( new EmailJobRepository() )->search( array( 'cart_id' => $cart_id ) );

		return (string) ( $job['items'][0]['status'] ?? '' );
	}

	/**
	 * Number of recorded events of one type.
	 */
	private function event_count( int $cart_id, string $type ): int {
		$events = array_filter(
			$this->carts->events( $cart_id, 200 ),
			static fn( array $event ): bool => $type === $event['type']
		);

		return count( $events );
	}

	public function test_out_of_order_activity_does_not_regress_the_cart(): void {
		$cart = $this->make_cart(
			array(
				'email'              => 'ordered@example.test',
				'item_count'         => 2,
				'activity_order_ms'  => 2000,
			)
		);

		$result = $this->carts->record_activity(
			array(
				'cycle_key'         => (string) $cart['cart_cycle_key'],
				'email'             => 'ordered@example.test',
				'item_count'        => 9,
				'snapshot'          => array(),
				'total'             => 999,
				'activity_order_ms' => 1000,
			)
		);

		$this->assertNotWPError( $result );

		$after = $this->carts->get( (int) $cart['id'] );

		$this->assertSame( 1, (int) $after['version'], 'A stale observation must not bump the version.' );
		$this->assertSame( 2, (int) $after['item_count'], 'A stale observation must not replace the snapshot.' );
		$this->assertSame( 2000, (int) $after['activity_order_ms'] );
		$this->assertTrue( ! empty( $result['activity_stale'] ) );
	}

	public function test_abandon_transition_is_cas_guarded_and_records_one_event(): void {
		$cart    = $this->make_cart( array( 'email' => 'cas@example.test' ) );
		$id      = (int) $cart['id'];
		$version = (int) $cart['version'];
		$cutoff  = gmdate( 'Y-m-d H:i:s', time() + MINUTE_IN_SECONDS );

		$this->assertTrue( $this->carts->mark_abandoned( $id, $version, $cutoff ) );
		$this->assertFalse( $this->carts->mark_abandoned( $id, $version, $cutoff ), 'A second sweep must lose the CAS.' );
		$this->assertSame( 1, $this->event_count( $id, 'abandoned' ) );
		$this->assertSame( CartRepository::STATUS_ABANDONED, $this->carts->get( $id )['status'] );

		$fresh = $this->make_cart( array( 'email' => 'cas2@example.test' ) );
		$fid   = (int) $fresh['id'];

		$this->assertFalse( $this->carts->mark_abandoned( $fid, (int) $fresh['version'], gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ) ), 'The cutoff must protect newer activity.' );
		$this->assertSame( CartRepository::STATUS_ACTIVE, $this->carts->get( $fid )['status'] );

		$old_version = (int) $fresh['version'];
		$this->carts->record_activity( array( 'cycle_key' => (string) $fresh['cart_cycle_key'], 'email' => 'cas2@example.test' ) );

		$this->assertFalse( $this->carts->mark_abandoned( $fid, $old_version, gmdate( 'Y-m-d H:i:s', time() + MINUTE_IN_SECONDS ) ), 'An old selected version must not abandon newer activity.' );
	}

	public function test_reactivation_invalidates_the_unsent_source_without_touching_other_carts(): void {
		$email = 'reactivate@example.test';
		$cart  = $this->make_cart( array( 'email' => $email ) );
		$other = $this->make_cart( array( 'email' => $email ) );
		$id    = (int) $cart['id'];

		$this->assertTrue( $this->carts->mark_abandoned( $id, (int) $cart['version'], gmdate( 'Y-m-d H:i:s', time() + MINUTE_IN_SECONDS ) ) );
		$this->queued_job( $id, $email );

		$invalidated = array();
		$listener    = static function ( $cart_id, $source_version, $reason ) use ( &$invalidated ): void {
			$invalidated[] = array( (int) $cart_id, (int) $source_version, (string) $reason );
		};
		add_action( 'nowcrm_cart_source_invalidated', $listener, 10, 3 );

		$result = $this->carts->record_activity(
			array(
				'cycle_key'         => (string) $cart['cart_cycle_key'],
				'email'             => $email,
				'activity_order_ms' => (int) ( microtime( true ) * 1000 ),
			)
		);

		remove_action( 'nowcrm_cart_source_invalidated', $listener, 10 );

		$this->assertNotWPError( $result );
		$this->assertSame( CartRepository::STATUS_ACTIVE, $this->carts->get( $id )['status'] );
		$this->assertSame( (int) $cart['version'] + 1, (int) $this->carts->get( $id )['version'] );
		$this->assertSame( EmailJobRepository::STATUS_CANCELLED, $this->job_status( $id ) );
		$this->assertSame( CartRepository::STATUS_ACTIVE, $this->carts->get( (int) $other['id'] )['status'], 'A second cart with the same email must stay independent.' );
		$this->assertCount( 1, $invalidated );
		$this->assertSame( $id, $invalidated[0][0] );
		$this->assertSame( (int) $cart['version'], $invalidated[0][1] );
		$this->assertSame( 'reactivated', $invalidated[0][2] );
	}

	public function test_order_pending_blocks_activity_and_sends_until_exact_payment(): void {
		$email = 'pending@example.test';
		$cart  = $this->make_cart( array( 'email' => $email ) );
		$id    = (int) $cart['id'];

		$this->assertTrue( $this->carts->mark_abandoned( $id, (int) $cart['version'], gmdate( 'Y-m-d H:i:s', time() + MINUTE_IN_SECONDS ) ) );
		$this->queued_job( $id, $email );

		$completed = array();
		$listener  = static function ( $cart_id, $order_id, $source ) use ( &$completed ): void {
			$completed[] = array( (int) $cart_id, (int) $order_id, (string) $source );
		};
		add_action( 'nowcrm_cart_completed', $listener, 10, 3 );

		$this->assertTrue( $this->carts->mark_order_pending( $id, 700, 'email_click' ) );

		$pending = $this->carts->get( $id );

		$this->assertSame( CartRepository::STATUS_ORDER_PENDING, $pending['status'] );
		$this->assertSame( 700, (int) $pending['recovered_order_id'] );
		$this->assertSame( 'email_click', $pending['recovery_source'] );
		$this->assertSame( EmailJobRepository::STATUS_CANCELLED, $this->job_status( $id ) );
		$this->assertSame( 1, $this->event_count( $id, 'order_placed' ) );
		$this->assertCount( 0, $completed, 'Order creation must not look paid.' );

		$late = $this->carts->record_activity(
			array(
				'cycle_key'         => (string) $cart['cart_cycle_key'],
				'email'             => $email,
				'activity_order_ms' => (int) ( microtime( true ) * 1000 ),
			)
		);

		$this->assertWPError( $late );
		$this->assertSame( 'nowcrm_cart_order_pending', $late->get_error_code() );
		$this->assertSame( CartRepository::STATUS_ORDER_PENDING, $this->carts->get( $id )['status'], 'Pending must not be reactivated by a stale tab.' );

		$version = (int) $this->carts->get( $id )['version'];

		$this->assertTrue( $this->carts->complete_with_order( $id, 700, 'email_click', $version ) );
		$this->assertSame( CartRepository::STATUS_RECOVERED, $this->carts->get( $id )['status'] );
		$this->assertCount( 1, $completed );
		$this->assertSame( array( $id, 700, 'email_click' ), $completed[0] );
		$this->assertSame( 1, $this->event_count( $id, 'order_paid' ) );
		$this->assertSame( 1, $this->event_count( $id, 'order_email_click' ) );

		$this->assertTrue( $this->carts->complete_with_order( $id, 700, 'email_click', $version ) );
		$this->assertCount( 1, $completed, 'A repeat payment callback must not fire a second completion.' );

		remove_action( 'nowcrm_cart_completed', $listener, 10 );

		$this->assertFalse( $this->carts->complete_with_order( $id, 701, 'natural' ), 'A different order must not overwrite exact attribution.' );
	}

	public function test_failed_order_keeps_pending_and_late_payment_still_completes(): void {
		$cart  = $this->make_cart( array( 'email' => 'failed@example.test' ) );
		$id    = (int) $cart['id'];
		$order = $this->make_order( array( 'billing_email' => 'failed@example.test', 'status' => 'pending' ) );

		$this->assertTrue( $this->carts->mark_order_pending( $id, (int) $order->get_id(), 'natural' ) );
		$this->queued_job( $id, 'failed@example.test' );

		$this->assertTrue( $this->carts->mark_order_failed( $id, (int) $order->get_id(), 'failed' ) );
		$this->assertSame( CartRepository::STATUS_ORDER_PENDING, $this->carts->get( $id )['status'] );
		$this->assertSame( EmailJobRepository::STATUS_CANCELLED, $this->job_status( $id ) );
		$this->assertSame( 1, $this->event_count( $id, 'order_failed' ) );
		$this->assertFalse( $this->carts->mark_order_failed( $id, (int) $order->get_id(), 'failed' ), 'Repeat failure callback must be idempotent.' );

		$this->assertTrue( $this->carts->complete_with_order( $id, (int) $order->get_id(), 'natural' ) );
		$this->assertSame( CartRepository::STATUS_COMPLETED, $this->carts->get( $id )['status'] );
	}

	public function test_adopt_recovery_requires_the_exact_row_and_version(): void {
		$email = 'adopt@example.test';
		$cart  = $this->make_cart( array( 'email' => $email ) );
		$id    = (int) $cart['id'];

		$this->assertTrue( $this->carts->mark_abandoned( $id, (int) $cart['version'], gmdate( 'Y-m-d H:i:s', time() + MINUTE_IN_SECONDS ) ) );
		$this->queued_job( $id, $email );

		$stale  = $this->carts->get( $id );
		$target = $this->carts->get( $id );

		$this->carts->record_activity( array( 'cycle_key' => (string) $cart['cart_cycle_key'], 'email' => $email ) );

		$invalidated = array();
		$listener    = static function ( $cart_id, $source_version, $reason ) use ( &$invalidated ): void {
			$invalidated[] = array( (int) $cart_id, (int) $source_version, (string) $reason );
		};
		add_action( 'nowcrm_cart_source_invalidated', $listener, 10, 3 );

		$current = $this->carts->get( $id );

		$this->assertFalse( $this->carts->adopt_recovery( $stale, 'session-new' ), 'An old snapshot version must not transfer the cart.' );
		$this->assertSame( (string) $current['session_key'], (string) $this->carts->get( $id )['session_key'] );

		$this->assertTrue( $this->carts->adopt_recovery( $current, 'session-new' ) );

		remove_action( 'nowcrm_cart_source_invalidated', $listener, 10 );

		$after = $this->carts->get( $id );

		$this->assertSame( 'session-new', $after['session_key'] );
		$this->assertSame( CartRepository::STATUS_ACTIVE, $after['status'] );
		$this->assertSame( (int) $current['version'] + 1, (int) $after['version'] );
		$this->assertSame( EmailJobRepository::STATUS_CANCELLED, $this->job_status( $id ) );
		$this->assertCount( 1, $invalidated );
		$this->assertSame( 'recovered', $invalidated[0][2] );

		$this->assertFalse( $this->carts->adopt_recovery( array( 'id' => 999999, 'version' => 1 ), 'session-x' ) );
	}

	public function test_two_carts_with_the_same_email_stay_independent(): void {
		$email = 'shared@example.test';
		$a     = $this->make_cart( array( 'email' => $email ) );
		$b     = $this->make_cart( array( 'email' => $email ) );

		$this->carts->record_activity(
			array(
				'cycle_key'         => (string) $a['cart_cycle_key'],
				'email'             => $email,
				'activity_order_ms' => (int) ( microtime( true ) * 1000 ),
			)
		);

		$this->assertSame( 1, (int) $this->carts->get( (int) $b['id'] )['version'], 'Activity on one cart must not touch the other.' );

		$this->assertTrue( $this->carts->mark_abandoned( (int) $a['id'], (int) $this->carts->get( (int) $a['id'] )['version'], gmdate( 'Y-m-d H:i:s', time() + MINUTE_IN_SECONDS ) ) );
		$this->assertSame( 1, $this->event_count( (int) $a['id'], 'abandoned' ) );
		$this->assertSame( 0, $this->event_count( (int) $b['id'], 'abandoned' ) );
		$this->assertSame( CartRepository::STATUS_ACTIVE, $this->carts->get( (int) $b['id'] )['status'] );

		$this->assertTrue( $this->carts->complete_with_order( (int) $b['id'], 900, 'natural' ) );
		$this->assertSame( CartRepository::STATUS_ABANDONED, $this->carts->get( (int) $a['id'] )['status'] );
	}

	public function test_can_send_is_blocked_for_every_non_abandoned_state(): void {
		$job_for = static fn( int $id, string $email ): array => array(
			'cart_id'   => $id,
			'recipient' => $email,
			'payload'   => array(),
		);

		$email = 'states@example.test';
		$cart  = $this->make_cart( array( 'email' => $email ) );
		$id    = (int) $cart['id'];

		$this->carts->mark_order_pending( $id, 901, 'natural' );
		$this->assertSame( 'cart_not_abandoned', Reminder::can_send( $job_for( $id, $email ), null ) );

		$paid = $this->make_cart( array( 'email' => $email ) );
		$this->carts->complete_with_order( (int) $paid['id'], 902, 'natural' );
		$this->assertSame( 'cart_not_abandoned', Reminder::can_send( $job_for( (int) $paid['id'], $email ), null ) );

		$lost = $this->make_cart( array( 'email' => $email ) );
		$this->carts->mark_abandoned( (int) $lost['id'], (int) $lost['version'], gmdate( 'Y-m-d H:i:s', time() + MINUTE_IN_SECONDS ) );
		$this->carts->mark_lost( (int) $lost['id'], (int) $this->carts->get( (int) $lost['id'] )['version'] );
		$this->assertSame( 'cart_not_abandoned', Reminder::can_send( $job_for( (int) $lost['id'], $email ), null ) );

		$reactivated = $this->make_cart( array( 'email' => $email ) );
		$this->carts->mark_abandoned( (int) $reactivated['id'], (int) $reactivated['version'], gmdate( 'Y-m-d H:i:s', time() + MINUTE_IN_SECONDS ) );
		$this->carts->record_activity( array( 'cycle_key' => (string) $reactivated['cart_cycle_key'], 'email' => $email ) );
		$this->assertSame( 'cart_not_abandoned', Reminder::can_send( $job_for( (int) $reactivated['id'], $email ), null ) );
	}

	public function test_with_lock_returns_the_callback_result_and_rejects_invalid_ids(): void {
		$this->assertSame( 'locked-result', CartRepository::with_lock( 123, static fn(): string => 'locked-result' ) );

		$error = CartRepository::with_lock( 0, static fn(): string => 'never' );

		$this->assertWPError( $error );
		$this->assertSame( 'nowcrm_cart_invalid', $error->get_error_code() );

		$this->assertSame( 'after-error', CartRepository::with_lock( 456, static fn(): string => 'after-error' ), 'The lock must be free after a failed call.' );
	}

	public function test_capture_requires_same_origin_and_a_valid_nonce(): void {
		$product = $this->make_product();
		WC()->cart->add_to_cart( $product->get_id(), 1 );

		$tracker = new Tracker();

		$cross = $tracker->track( $this->capture_request( array( 'email' => 'capture@example.test', 'activity_order' => (string) (int) ( microtime( true ) * 1000 ) ), 'https://evil.example' ) );

		$this->assertFalse( $cross->get_data()['ok'] );
		$this->assertSame( 'forbidden_origin', $cross->get_data()['reason'] );

		$bad_nonce = $tracker->track( $this->capture_request( array( 'email' => 'capture@example.test', 'activity_order' => (string) (int) ( microtime( true ) * 1000 ) ), '', 'invalid-nonce' ) );

		$this->assertFalse( $bad_nonce->get_data()['ok'] );
		$this->assertSame( 'invalid_nonce', $bad_nonce->get_data()['reason'] );

		$this->assertSame( '', (string) WC()->session->get( 'nowcrm_cart_cycle' ), 'A rejected capture must not bind a cycle.' );
	}

	public function test_first_guest_capture_uses_the_woo_session_and_rejects_stale_hints(): void {
		$product = $this->make_product();
		WC()->cart->add_to_cart( $product->get_id(), 1 );
		WC()->session->set( 'nowcrm_cart_cycle', '' );
		WC()->session->set( 'nowcrm_recovered_cart_id', 0 );

		$tracker = new Tracker();
		$now_ms  = (int) ( microtime( true ) * 1000 );

		$first = $tracker->track(
			$this->capture_request(
				array(
					'email'         => 'guest@example.test',
					'activity_order' => (string) $now_ms,
				)
			)
		);

		$data = $first->get_data();

		$this->assertTrue( $data['ok'] );
		$this->assertNotEmpty( $data['cycle'] );
		$this->assertSame( 1, (int) $data['version'] );
		$this->assertSame( (string) $data['cycle'], (string) WC()->session->get( 'nowcrm_cart_cycle' ) );

		$mismatch = $tracker->track(
			$this->capture_request(
				array(
					'email'           => 'guest@example.test',
					'activity_order'   => (string) ( $now_ms + 10 ),
					'previous_cycle'  => str_repeat( 'f', 32 ),
				)
			)
		);

		$this->assertFalse( $mismatch->get_data()['ok'] );
		$this->assertSame( 'stale_cycle', $mismatch->get_data()['reason'] );
		$this->assertSame( (string) $data['cycle'], (string) WC()->session->get( 'nowcrm_cart_cycle' ), 'A mismatched hint must not rebind.' );

		$replay = $tracker->track(
			$this->capture_request(
				array(
					'email'           => 'guest@example.test',
					'activity_order'   => (string) $now_ms,
					'previous_cycle'  => (string) $data['cycle'],
				)
			)
		);

		$replay_data = $replay->get_data();

		$this->assertTrue( $replay_data['ok'] );
		$this->assertTrue( ! empty( $replay_data['stale'] ) );
		$this->assertSame( 1, (int) $replay_data['version'] );
	}

	public function test_legacy_cycle_value_is_never_adopted_as_identity(): void {
		$product = $this->make_product();
		WC()->cart->add_to_cart( $product->get_id(), 1 );
		WC()->session->set( 'nowcrm_cart_cycle', '' );
		WC()->session->set( 'nowcrm_cycle_rotated_at', 0 );

		$legacy = str_repeat( 'a', 32 );

		$tracker = new Tracker();
		$result  = $tracker->track(
			$this->capture_request(
				array(
					'email' => 'legacy@example.test',
					'cycle' => $legacy,
				)
			)
		);

		$data = $result->get_data();

		$this->assertTrue( $data['ok'] );
		$this->assertNotSame( $legacy, (string) $data['cycle'], 'The server must mint its own cycle.' );
		$this->assertSame( (string) $data['cycle'], (string) WC()->session->get( 'nowcrm_cart_cycle' ) );
		$this->assertNull( $this->carts->find_by_cycle( $legacy ) );
		$this->assertNotNull( $this->carts->find_by_cycle( (string) $data['cycle'] ) );

		$explicit = $tracker->track(
			$this->capture_request(
				array(
					'email'          => 'legacy@example.test',
					'activity_order' => (string) (int) ( microtime( true ) * 1000 ),
					'previous_cycle' => $legacy,
				)
			)
		);

		$this->assertFalse( $explicit->get_data()['ok'] );
		$this->assertSame( 'stale_cycle', $explicit->get_data()['reason'] );
		$this->assertSame( (string) $data['cycle'], (string) WC()->session->get( 'nowcrm_cart_cycle' ) );
	}

	public function test_upgraded_capture_ignores_a_stale_legacy_cookie(): void {
		$product = $this->make_product();
		WC()->cart->add_to_cart( $product->get_id(), 1 );
		WC()->session->set( 'nowcrm_cart_cycle', '' );
		WC()->session->set( 'nowcrm_cycle_rotated_at', 0 );

		$legacy                 = str_repeat( 'b', 32 );
		$_COOKIE['nowcrm_cycle'] = $legacy;

		try {
			$tracker = new Tracker();
			$result  = $tracker->track(
				$this->capture_request(
					array(
						'email'          => 'upgraded@example.test',
						'activity_order' => (string) (int) ( microtime( true ) * 1000 ),
					)
				)
			);
		} finally {
			unset( $_COOKIE['nowcrm_cycle'] );
		}

		$data = $result->get_data();

		$this->assertTrue( $data['ok'], 'A stale persisted cookie must not block the upgraded client.' );
		$this->assertNotSame( $legacy, (string) $data['cycle'] );
		$this->assertSame( (string) $data['cycle'], (string) WC()->session->get( 'nowcrm_cart_cycle' ) );
		$this->assertNull( $this->carts->find_by_cycle( $legacy ) );
	}

	public function test_capture_after_checkout_rotation_does_not_open_a_cycle_from_a_stale_tab(): void {
		$product = $this->make_product();
		WC()->cart->add_to_cart( $product->get_id(), 1 );

		$rotation = (int) ( microtime( true ) * 1000 );

		WC()->session->set( 'nowcrm_cart_cycle', '' );
		WC()->session->set( 'nowcrm_cycle_rotated_at', $rotation );

		$tracker = new Tracker();

		$legacy = $tracker->track( $this->capture_request( array( 'email' => 'stale@example.test' ) ) );

		$this->assertFalse( $legacy->get_data()['ok'] );
		$this->assertSame( 'cycle_rotated', $legacy->get_data()['reason'] );
		$this->assertSame( '', (string) WC()->session->get( 'nowcrm_cart_cycle' ) );

		$old = $tracker->track(
			$this->capture_request(
				array(
					'email'          => 'stale@example.test',
					'activity_order' => (string) ( $rotation - 5000 ),
				)
			)
		);

		$this->assertFalse( $old->get_data()['ok'] );
		$this->assertSame( 'stale_cycle', $old->get_data()['reason'] );
		$this->assertSame( '', (string) WC()->session->get( 'nowcrm_cart_cycle' ) );

		$fresh = $tracker->track(
			$this->capture_request(
				array(
					'email'          => 'stale@example.test',
					'activity_order' => (string) ( $rotation + 1000 ),
				)
			)
		);

		$this->assertTrue( $fresh->get_data()['ok'] );
		$this->assertNotSame( '', (string) $fresh->get_data()['cycle'] );
	}

	public function test_capture_marks_line_data_that_cannot_be_replayed(): void {
		$product = $this->make_product();

		WC()->cart->add_to_cart( $product->get_id(), 1, 0, array(), array( 'fixture_extension' => 'opaque' ) );
		WC()->session->set( 'nowcrm_cart_cycle', '' );

		$tracker = new Tracker();
		$result  = $tracker->track(
			$this->capture_request(
				array(
					'email'          => 'items@example.test',
					'activity_order' => (string) (int) ( microtime( true ) * 1000 ),
				)
			)
		);

		$this->assertTrue( $result->get_data()['ok'] );

		$cart  = $this->carts->get( (int) $result->get_data()['cart_id'] );
		$items = (array) $cart['items'];

		$this->assertNotEmpty( $items );
		$this->assertTrue( ! empty( $items[0]['requires_item_data'] ) );
		$this->assertArrayNotHasKey( 'fixture_extension', $items[0], 'Opaque customer line data must not be persisted.' );
	}

	public function test_order_creation_links_the_exact_cart_and_payment_requires_paid_date(): void {
		$email = 'checkout@example.test';
		$cart  = $this->make_cart( array( 'email' => $email ) );
		$id    = (int) $cart['id'];

		$this->bind_session( (string) $cart['cart_cycle_key'], $id );

		$order = $this->make_order( array( 'billing_email' => $email, 'status' => 'pending' ) );
		$oid   = (int) $order->get_id();

		$completed = 0;
		$listener  = static function () use ( &$completed ): void {
			++$completed;
		};
		add_action( 'nowcrm_cart_completed', $listener, 10, 3 );

		( new Tracker() )->on_order_processed( $oid, array(), $order );

		$order = wc_get_order( $oid );
		$row   = $this->carts->get( $id );

		$this->assertSame( CartRepository::STATUS_ORDER_PENDING, $row['status'] );
		$this->assertSame( (string) $id, (string) $order->get_meta( '_nowcrm_cart_linked' ) );
		$this->assertSame( (string) $cart['cart_cycle_key'], (string) $order->get_meta( '_nowcrm_cart_cycle' ) );
		$this->assertSame( (int) $row['version'], (int) $order->get_meta( '_nowcrm_cart_version' ) );
		$this->assertSame( 'email_click', (string) $order->get_meta( '_nowcrm_cart_attribution' ) );
		$this->assertSame( '', (string) WC()->session->get( 'nowcrm_cart_cycle' ), 'Checkout must rotate the session cycle.' );
		$this->assertSame( 0, $completed );

		// Processing without a paid date is not payment evidence.
		$order = wc_get_order( $oid );
		$order->update_status( 'processing' );
		( new Tracker() )->on_status_changed( $oid, 'pending', 'processing', wc_get_order( $oid ) );

		$this->assertSame( CartRepository::STATUS_ORDER_PENDING, $this->carts->get( $id )['status'] );
		$this->assertSame( 0, $completed );

		$order = wc_get_order( $oid );
		$order->set_date_paid( time() );
		$order->set_transaction_id( 'fixture-txn-1' );
		$order->save();

		( new Tracker() )->on_status_changed( $oid, 'pending', 'processing', $order );

		$this->assertSame( CartRepository::STATUS_RECOVERED, $this->carts->get( $id )['status'] );
		$this->assertSame( 1, $completed );

		( new Tracker() )->on_payment_complete( $oid );
		( new Tracker() )->on_status_changed( $oid, 'processing', 'completed', wc_get_order( $oid ) );

		$this->assertSame( 1, $completed, 'Async callbacks must not complete the cart twice.' );

		remove_action( 'nowcrm_cart_completed', $listener, 10 );
	}

	public function test_store_api_draft_order_is_not_owned(): void {
		$email = 'draft@example.test';
		$cart  = $this->make_cart( array( 'email' => $email ) );
		$id    = (int) $cart['id'];

		$this->bind_session( (string) $cart['cart_cycle_key'] );

		$order = $this->make_order( array( 'billing_email' => $email, 'status' => 'checkout-draft' ) );

		( new Tracker() )->on_store_api_order( wc_get_order( $order->get_id() ) );

		$this->assertSame( CartRepository::STATUS_ACTIVE, $this->carts->get( $id )['status'] );
		$this->assertSame( '', (string) wc_get_order( $order->get_id() )->get_meta( '_nowcrm_cart_linked' ) );
	}

	public function test_paid_order_without_a_linked_cart_does_not_touch_any_cart(): void {
		$email = 'unlinked@example.test';
		$cart  = $this->make_cart( array( 'email' => $email ) );
		$id    = (int) $cart['id'];

		$order = $this->make_order( array( 'billing_email' => $email ) );
		$order->set_date_paid( time() );
		$order->update_status( 'processing' );

		( new Tracker() )->on_payment_complete( (int) $order->get_id() );

		$this->assertSame( CartRepository::STATUS_ACTIVE, $this->carts->get( $id )['status'], 'Same-email orders must never complete an unrelated cart.' );
	}

	public function test_cod_processing_with_a_synthesized_paid_date_stays_pending(): void {
		$email = 'cod@example.test';
		$cart  = $this->make_cart( array( 'email' => $email ) );
		$id    = (int) $cart['id'];

		$this->bind_session( (string) $cart['cart_cycle_key'] );

		$order = $this->make_order( array( 'billing_email' => $email, 'status' => 'pending' ) );
		$order->set_payment_method( 'cod' );
		$order->save();

		$oid = (int) $order->get_id();

		( new Tracker() )->on_order_processed( $oid, array(), wc_get_order( $oid ) );

		// Reproduce Woo's synthesized date ambiguity explicitly: a paid date
		// exists but no payment callback or transaction proves the money.
		$order = wc_get_order( $oid );
		$order->set_date_paid( time() );
		$order->save();
		$order->update_status( 'processing' );
		$order = wc_get_order( $oid );

		$this->assertNotEmpty( $order->get_date_paid(), 'Fixture must reproduce the synthesized date.' );

		( new Tracker() )->on_status_changed( $oid, 'pending', 'processing', $order );

		$this->assertSame( CartRepository::STATUS_ORDER_PENDING, $this->carts->get( $id )['status'], 'Fulfillment status is not cash evidence.' );
		$this->assertSame( 0, $this->event_count( $id, 'order_paid' ) );

		// An explicit cash confirmation is still needed.
		wc_get_order( $oid )->payment_complete( 'cod-cash-ref' );

		$this->assertSame( CartRepository::STATUS_COMPLETED, $this->carts->get( $id )['status'] );
		$this->assertSame( 1, $this->event_count( $id, 'order_paid' ) );

		( new Tracker() )->on_payment_complete( $oid );

		$this->assertSame( 1, $this->event_count( $id, 'order_paid' ), 'Repeat confirmation must not duplicate the paid event.' );
	}

	public function test_unlinked_paid_order_does_not_suppress_a_cart(): void {
		$email = 'nocard@example.test';
		$cart  = $this->make_cart( array( 'email' => $email ) );
		$id    = (int) $cart['id'];

		$this->assertTrue( $this->carts->mark_abandoned( $id, (int) $cart['version'], gmdate( 'Y-m-d H:i:s', time() + MINUTE_IN_SECONDS ) ) );
		$this->queued_job( $id, $email );

		$order = $this->make_order( array( 'billing_email' => $email ) );
		$order->set_date_paid( time() );
		$order->set_transaction_id( 'fixture-txn-other' );
		$order->update_status( 'processing' );

		( new Tracker() )->on_status_changed( (int) $order->get_id(), 'pending', 'processing', wc_get_order( $order->get_id() ) );

		$this->assertSame( CartRepository::STATUS_ABANDONED, $this->carts->get( $id )['status'], 'Only the exact linked cart may be suppressed.' );
		$this->assertSame( EmailJobRepository::STATUS_QUEUED, $this->job_status( $id ) );
	}

	public function test_refund_keeps_payment_evidence_and_records_the_reason(): void {
		$email = 'refund@example.test';
		$cart  = $this->make_cart( array( 'email' => $email ) );
		$id    = (int) $cart['id'];

		$this->assertTrue( $this->carts->complete_with_order( $id, 950, 'natural' ) );

		$order = $this->make_order( array( 'billing_email' => $email ) );
		$order->update_meta_data( '_nowcrm_cart_linked', (string) $id );
		$order->save();

		( new Tracker() )->on_refunded( (int) $order->get_id(), 1 );

		$after = $this->carts->get( $id );

		$this->assertSame( CartRepository::STATUS_COMPLETED, $after['status'] );
		$this->assertSame( 950, (int) $after['recovered_order_id'] );
		$this->assertSame( 1, $this->event_count( $id, 'order_refunded' ) );
	}
}
