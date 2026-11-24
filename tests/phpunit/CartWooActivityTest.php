<?php
/**
 * Native Woo cart mutations refresh the session-bound cart activity without a
 * new email observation. Synthetic fixtures only; no live orders or mail.
 *
 * @package NowCRM
 */

use NowCRM\Cart\CartRepository;
use NowCRM\Cart\Recovery;
use NowCRM\Cart\Tracker;
use NowCRM\Email\EmailJobRepository;

/**
 * @covers \NowCRM\Cart\Tracker
 * @covers \NowCRM\Cart\Recovery
 */
final class CartWooActivityTest extends NowCrmTestCase {

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

		if ( function_exists( 'WC' ) && ! WC()->session && method_exists( WC(), 'initialize_session' ) ) {
			WC()->initialize_session();
		}

		$this->reset_cart_session();

		if ( function_exists( 'WC' ) && WC()->cart ) {
			WC()->cart->empty_cart();
		}

		// Drop any coalesced mutation a previous test left behind.
		do_action( 'nowcrm_cart_flush_mutation' );

		if ( ! has_action( 'nowcrm_cart_flush_mutation' ) ) {
			( new Tracker() )->register();
		}
	}

	public function tear_down(): void {
		$this->reset_cart_session();

		if ( function_exists( 'WC' ) && WC()->cart ) {
			WC()->cart->empty_cart();
		}

		do_action( 'nowcrm_cart_flush_mutation' );

		parent::tear_down();
	}

	/**
	 * Clear only the NOW CRM session keys so another Woo fixture cannot leak
	 * a bound cycle into these assertions.
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
	private function make_product( float $price = 100, int $stock = 10 ): \WC_Product {
		$product = new \WC_Product_Simple();
		$product->set_name( 'Activity fixture ' . wp_generate_password( 5, false ) );
		$product->set_regular_price( (string) $price );
		$product->set_manage_stock( true );
		$product->set_stock_quantity( $stock );
		$product->set_status( 'publish' );
		$product->save();

		return $product;
	}

	/**
	 * Variable product with one colour variation.
	 */
	private function make_variation_product(): array {
		$parent = new \WC_Product_Variable();
		$parent->set_name( 'Activity variable ' . wp_generate_password( 5, false ) );
		$parent->set_status( 'publish' );
		$parent->set_manage_stock( true );
		$parent->set_stock_quantity( 20 );

		$attribute = new \WC_Product_Attribute();
		$attribute->set_id( 0 );
		$attribute->set_name( 'Color' );
		$attribute->set_options( array( 'Red', 'Blue' ) );
		$attribute->set_position( 0 );
		$attribute->set_visible( true );
		$attribute->set_variation( true );
		$parent->set_attributes( array( $attribute ) );
		$parent->save();

		$variations = array();

		foreach ( array( 'Red', 'Blue' ) as $color ) {
			$variation = new \WC_Product_Variation();
			$variation->set_parent_id( $parent->get_id() );
			$variation->set_attributes( array( 'color' => $color ) );
			$variation->set_regular_price( '100' );
			$variation->set_status( 'publish' );
			$variation->save();

			$variations[ $color ] = (int) $variation->get_id();
		}

		\WC_Product_Variable::sync( $parent->get_id() );

		return array(
			'parent'     => (int) $parent->get_id(),
			'variations' => $variations,
		);
	}

	/**
	 * Zero-amount coupon: valid to apply, but the cart total does not change.
	 */
	private function make_zero_coupon(): string {
		$code = 'activity-' . bin2hex( random_bytes( 4 ) );

		$coupon = new \WC_Coupon();
		$coupon->set_code( $code );
		$coupon->set_discount_type( 'fixed_cart' );
		$coupon->set_amount( 0 );
		$coupon->save();

		return $code;
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
	 * Capture one email observation and return the response data plus time.
	 *
	 * @return array{data: array<string, mixed>, order_ms: int}
	 */
	private function seed_capture( string $email ): array {
		$order_ms = (int) ( microtime( true ) * 1000 );

		$response = ( new Tracker() )->track(
			$this->capture_request(
				array(
					'email'          => $email,
					'activity_order' => (string) $order_ms,
				)
			)
		);

		$data = $response->get_data();

		$this->assertTrue( $data['ok'] ?? false, 'Seed capture failed: ' . (string) ( $data['reason'] ?? 'unknown' ) );

		return array(
			'data'     => $data,
			'order_ms' => $order_ms,
		);
	}

	/**
	 * First cart item key of the live cart.
	 */
	private function first_key(): string {
		$keys = array_keys( WC()->cart->get_cart() );

		$this->assertNotEmpty( $keys );

		return (string) $keys[0];
	}

	/**
	 * Backdate the idle clock through the database, never a sleep.
	 */
	private function backdate( int $cart_id, int $seconds ): void {
		global $wpdb;

		$wpdb->update(
			\NowCRM\Support\Schema::table( 'carts' ),
			array( 'last_activity_at' => gmdate( 'Y-m-d H:i:s', time() - $seconds ) ),
			array( 'id' => $cart_id ),
			array( '%s' ),
			array( '%d' )
		);
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

	/**
	 * Status of the first queued job for one cart.
	 */
	private function job_status( int $cart_id ): string {
		$job = ( new EmailJobRepository() )->search( array( 'cart_id' => $cart_id ) );

		return (string) ( $job['items'][0]['status'] ?? '' );
	}

	public function test_item_and_quantity_mutations_refresh_the_snapshot_without_a_new_email(): void {
		$product = $this->make_product();
		WC()->cart->add_to_cart( $product->get_id(), 1 );

		$seed      = $this->seed_capture( 'mutate@example.test' );
		$cart_id   = (int) $seed['data']['cart_id'];
		$version   = (int) $seed['data']['version'];
		$order_ms  = (int) $seed['order_ms'];

		$this->assertSame( 1, $version );

		$this->backdate( $cart_id, 21 * MINUTE_IN_SECONDS );

		WC()->cart->set_quantity( $this->first_key(), 3 );

		do_action( 'nowcrm_cart_flush_mutation' );

		$row = $this->carts->get( $cart_id );

		$this->assertSame( (string) $seed['data']['cycle'], (string) $row['cart_cycle_key'] );
		$this->assertSame( 'mutate@example.test', (string) $row['email'], 'The mutation must keep the captured email.' );
		$this->assertSame( 3, (int) $row['item_count'] );
		$this->assertEqualsWithDelta( 300.0, (float) $row['total'], 0.01 );
		$this->assertSame( $version + 1, (int) $row['version'] );
		$this->assertSame( $order_ms, (int) $row['activity_order_ms'], 'A server mutation must not change the client watermark.' );
		$this->assertCount( 1, (array) $row['items'] );
		$this->assertSame( $product->get_id(), (int) $row['items'][0]['product_id'] );
		$this->assertSame( 3.0, (float) $row['items'][0]['quantity'] );
		$this->assertSame( array(), (array) $row['coupons'] );
		$this->assertGreaterThan( gmdate( 'Y-m-d H:i:s', time() - 20 * MINUTE_IN_SECONDS ), (string) $row['last_activity_at'], 'The 20-minute idle clock must restart.' );
		$this->assertSame( array(), $this->carts->due_for_abandon( 20 ) );
	}

	public function test_variation_selection_is_refreshed_from_the_live_cart(): void {
		$variable = $this->make_variation_product();

		WC()->cart->add_to_cart( $variable['parent'], 1, $variable['variations']['Red'], array( 'attribute_color' => 'Red' ) );

		$seed    = $this->seed_capture( 'variation@example.test' );
		$cart_id = (int) $seed['data']['cart_id'];

		WC()->cart->add_to_cart( $variable['parent'], 1, $variable['variations']['Blue'], array( 'attribute_color' => 'Blue' ) );

		do_action( 'nowcrm_cart_flush_mutation' );

		$row = $this->carts->get( $cart_id );

		$this->assertCount( 2, (array) $row['items'] );
		$this->assertSame( 2, (int) $row['item_count'] );

		$variation_ids = array_map(
			static fn( array $item ): int => (int) $item['variation_id'],
			(array) $row['items']
		);
		sort( $variation_ids );

		$expected = array( $variable['variations']['Red'], $variable['variations']['Blue'] );
		sort( $expected );

		$this->assertSame( $expected, $variation_ids );

		$attributes = array_map(
			static fn( array $item ): string => (string) ( $item['variation']['attribute_color'] ?? '' ),
			(array) $row['items']
		);
		sort( $attributes );

		$this->assertSame( array( 'Blue', 'Red' ), $attributes );
	}

	public function test_coupon_change_updates_the_hash_when_the_total_does_not_move(): void {
		$product = $this->make_product();
		WC()->cart->add_to_cart( $product->get_id(), 1 );

		$seed    = $this->seed_capture( 'coupon@example.test' );
		$cart_id = (int) $seed['data']['cart_id'];
		$before  = $this->carts->get( $cart_id );
		$code    = $this->make_zero_coupon();

		$this->assertTrue( WC()->cart->apply_coupon( $code ) );

		do_action( 'nowcrm_cart_flush_mutation' );

		$row = $this->carts->get( $cart_id );

		$this->assertSame( (int) $before['version'] + 1, (int) $row['version'] );
		$this->assertEqualsWithDelta( (float) $before['total'], (float) $row['total'], 0.01, 'A zero coupon must not change the total.' );
		$this->assertNotSame( (string) $before['cart_hash'], (string) $row['cart_hash'], 'The hash must include applied coupons.' );
		$this->assertSame( array( $code ), (array) $row['coupons'] );
	}

	public function test_identical_mutation_is_a_no_op(): void {
		$product = $this->make_product();
		WC()->cart->add_to_cart( $product->get_id(), 2 );

		$seed    = $this->seed_capture( 'noop@example.test' );
		$cart_id = (int) $seed['data']['cart_id'];
		$before  = $this->carts->get( $cart_id );

		WC()->cart->set_quantity( $this->first_key(), 2 );

		do_action( 'nowcrm_cart_flush_mutation' );

		$row = $this->carts->get( $cart_id );

		$this->assertSame( (int) $before['version'], (int) $row['version'], 'An identical server hash must not bump the version.' );
		$this->assertSame( (string) $before['cart_hash'], (string) $row['cart_hash'] );
		$this->assertSame( (string) $before['last_activity_at'], (string) $row['last_activity_at'] );
	}

	public function test_abandoned_cart_mutation_invalidates_the_queued_job_and_restarts_the_idle_window(): void {
		$product = $this->make_product();
		WC()->cart->add_to_cart( $product->get_id(), 1 );

		$seed    = $this->seed_capture( 'revive@example.test' );
		$cart_id = (int) $seed['data']['cart_id'];
		$version = (int) $seed['data']['version'];

		$this->assertTrue(
			$this->carts->mark_abandoned( $cart_id, $version, gmdate( 'Y-m-d H:i:s', time() + MINUTE_IN_SECONDS ) )
		);
		$this->queued_job( $cart_id, 'revive@example.test' );
		$this->backdate( $cart_id, 30 * MINUTE_IN_SECONDS );

		WC()->cart->set_quantity( $this->first_key(), 2 );

		do_action( 'nowcrm_cart_flush_mutation' );

		$row = $this->carts->get( $cart_id );

		$this->assertSame( CartRepository::STATUS_ACTIVE, (string) $row['status'] );
		$this->assertSame( $version + 1, (int) $row['version'] );
		$this->assertNull( $row['reminder_due_at'] );
		$this->assertSame( EmailJobRepository::STATUS_CANCELLED, $this->job_status( $cart_id ) );
		$this->assertSame( array(), $this->carts->due_for_abandon( 20 ) );
	}

	public function test_clear_cart_records_empty_activity_and_stays_out_of_due(): void {
		$product = $this->make_product();
		WC()->cart->add_to_cart( $product->get_id(), 1 );

		$seed    = $this->seed_capture( 'clear@example.test' );
		$cart_id = (int) $seed['data']['cart_id'];

		WC()->cart->empty_cart();

		do_action( 'nowcrm_cart_flush_mutation' );

		$row = $this->carts->get( $cart_id );

		$this->assertSame( 0, (int) $row['item_count'] );
		$this->assertEqualsWithDelta( 0.0, (float) $row['total'], 0.01 );
		$this->assertSame( array(), (array) $row['items'] );
		$this->assertSame( array(), (array) $row['coupons'] );
		$this->assertSame( CartRepository::STATUS_ACTIVE, (string) $row['status'] );
		$this->assertSame( (int) $seed['data']['version'] + 1, (int) $row['version'] );

		$this->backdate( $cart_id, HOUR_IN_SECONDS );

		$this->assertSame( array(), $this->carts->due_for_abandon( 20 ), 'An empty cart must never become due.' );
	}

	public function test_store_api_cart_controller_mutations_are_coalesced(): void {
		$product = $this->make_product();
		WC()->cart->add_to_cart( $product->get_id(), 1 );

		$seed    = $this->seed_capture( 'storeapi@example.test' );
		$cart_id = (int) $seed['data']['cart_id'];
		$code    = $this->make_zero_coupon();

		$controller = new \Automattic\WooCommerce\StoreApi\Utilities\CartController();
		$controller->set_cart_item_quantity( $this->first_key(), 2 );
		$controller->apply_coupon( $code );

		do_action( 'nowcrm_cart_flush_mutation' );

		$row = $this->carts->get( $cart_id );

		$this->assertSame( 2, (int) $row['item_count'] );
		$this->assertSame( 2.0, (float) $row['items'][0]['quantity'] );
		$this->assertSame( array( $code ), (array) $row['coupons'] );
		$this->assertSame( (int) $seed['data']['version'] + 1, (int) $row['version'], 'Two mutations in one request must collapse into one write.' );
	}

	public function test_rotated_or_missing_cycle_is_ignored(): void {
		$product = $this->make_product();
		WC()->cart->add_to_cart( $product->get_id(), 1 );

		$seed    = $this->seed_capture( 'rotate@example.test' );
		$cart_id = (int) $seed['data']['cart_id'];

		WC()->cart->set_quantity( $this->first_key(), 2 );

		// A checkout in the same request can rotate the session cycle.
		WC()->session->set( 'nowcrm_cart_cycle', str_repeat( 'd', 32 ) );

		do_action( 'nowcrm_cart_flush_mutation' );

		$this->assertSame( (int) $seed['data']['version'], (int) $this->carts->get( $cart_id )['version'] );

		// No established cycle must not create a row.
		$before = $this->table_count( 'carts' );
		WC()->session->set( 'nowcrm_cart_cycle', '' );
		WC()->cart->add_to_cart( $this->make_product()->get_id(), 1 );

		do_action( 'nowcrm_cart_flush_mutation' );

		$this->assertSame( $before, $this->table_count( 'carts' ) );
	}

	public function test_recovery_reconstruction_and_rollback_are_not_mutations(): void {
		$product = $this->make_product();
		$cycle   = str_repeat( 'e', 32 );
		$row     = array(
			'items' => array(
				array(
					'product_id'   => $product->get_id(),
					'variation_id' => 0,
					'quantity'     => 1,
					'variation'    => array(),
				),
			),
		);

		WC()->session->set( 'nowcrm_cart_cycle', $cycle );

		$recovery = new Recovery();
		$restored = $recovery->restore( $row );

		$this->assertTrue( $restored['ok'] );
		$this->assertFalse( Recovery::is_restoring() );

		do_action( 'nowcrm_cart_flush_mutation' );

		$this->assertNull( $this->carts->find_by_cycle( $cycle ), 'Rebuilding a cart must not record activity.' );

		// Emptying here is test setup, not a customer action: drop the cycle
		// first so the live listener records no empty-cart mutation.
		WC()->session->set( 'nowcrm_cart_cycle', '' );
		WC()->cart->empty_cart();

		$rolled_back = $recovery->restore(
			$row,
			static function (): bool {
				return false;
			}
		);

		$this->assertFalse( $rolled_back['ok'] );
		$this->assertFalse( Recovery::is_restoring() );

		do_action( 'nowcrm_cart_flush_mutation' );

		$this->assertNull( $this->carts->find_by_cycle( $cycle ), 'A rollback must not record activity.' );
	}
}
