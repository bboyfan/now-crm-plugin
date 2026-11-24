<?php
/**
 * Checkout capture state: failed-observation recovery, email clearing and
 * checkout activity heartbeats. Synthetic carts only; no live mail.
 *
 * @package NowCRM
 */

use NowCRM\Automation\Engine;
use NowCRM\Cart\Automation as CartAutomation;
use NowCRM\Cart\CartRepository;
use NowCRM\Cart\Reminder;
use NowCRM\Cart\Tracker;
use NowCRM\Contacts\Status;
use NowCRM\Email\EmailJobRepository;

/**
 * @covers \NowCRM\Cart\Tracker
 * @covers \NowCRM\Cart\CartRepository
 */
final class CartCaptureStateTest extends NowCrmTestCase {

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

	private function reset_cart_session(): void {
		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return;
		}

		WC()->session->set( 'nowcrm_cart_cycle', '' );
		WC()->session->set( 'nowcrm_recovered_cart_id', 0 );
		WC()->session->set( 'nowcrm_cycle_generation', 0 );
		WC()->session->set( 'nowcrm_cycle_rotated_at', 0 );
	}

	private function make_product( float $price = 100, int $stock = 10 ): \WC_Product {
		$product = new \WC_Product_Simple();
		$product->set_name( 'Capture fixture ' . wp_generate_password( 5, false ) );
		$product->set_regular_price( (string) $price );
		$product->set_manage_stock( true );
		$product->set_stock_quantity( $stock );
		$product->set_status( 'publish' );
		$product->save();

		return $product;
	}

	private function fill_cart(): void {
		$product = $this->make_product();

		if ( function_exists( 'WC' ) && WC()->cart ) {
			WC()->cart->add_to_cart( (int) $product->get_id(), 1 );
		}
	}

	private function capture_request( array $params ): \WP_REST_Request {
		$request = new \WP_REST_Request( 'POST', '/nowcrm/v1/cart/track' );
		$request->set_body_params( $params );

		$home = (string) wp_parse_url( home_url( '/' ), PHP_URL_SCHEME ) . '://' . (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		$port = wp_parse_url( home_url( '/' ), PHP_URL_PORT );

		$request->set_header( 'origin', $home . ( $port ? ':' . $port : '' ) );
		$request->set_header( 'x-wp-nonce', wp_create_nonce( 'wp_rest' ) );

		return $request;
	}

	/**
	 * @param array<string, mixed> $params Body parameters.
	 * @return array<string, mixed>
	 */
	private function track( array $params ): array {
		return ( new Tracker() )->track( $this->capture_request( $params ) )->get_data();
	}

	private function order_ms(): int {
		return (int) ( microtime( true ) * 1000 );
	}

	/**
	 * Observation strictly newer than the cart's stored watermark.
	 */
	private function order_after( array $row ): int {
		return max( $this->order_ms(), (int) ( $row['activity_order_ms'] ?? 0 ) + 1 );
	}

	/**
	 * Capture one address and return the bound cycle.
	 */
	private function capture( string $email ): string {
		$data = $this->track(
			array(
				'email'          => $email,
				'activity_order' => (string) $this->order_ms(),
			)
		);

		$this->assertTrue( $data['ok'] ?? false, 'Capture failed: ' . (string) ( $data['reason'] ?? 'unknown' ) );

		return (string) ( $data['cycle'] ?? '' );
	}

	/**
	 * Queue one cart reminder through the single Automation producer.
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
	 * @param int $cart_id Cart row.
	 * @return array<int, array<string, mixed>>
	 */
	private function cart_jobs( int $cart_id ): array {
		return (array) ( new EmailJobRepository() )->search( array( 'cart_id' => $cart_id ) )['items'];
	}

	public function test_cleared_email_stops_the_abandoned_cart_and_cancels_its_job(): void {
		$this->fill_cart();

		$email = 'clear-me-' . wp_generate_password( 6, false ) . '@example.test';
		$this->make_contact( array( 'email' => $email ) );

		$cycle = $this->capture( $email );
		$row   = $this->carts->find_by_cycle( $cycle );

		$this->assertNotNull( $row );
		$this->assertTrue( $this->carts->mark_abandoned( (int) $row['id'] ) );
		$this->assertTrue( $this->queue_reminder( $this->carts->get( (int) $row['id'] ) ) );

		$data = $this->track(
			array(
				'email'          => '',
				'email_state'    => 'none',
				'kind'           => 'capture',
				'activity_order' => (string) $this->order_after( $row ),
				'previous_cycle' => $cycle,
			)
		);

		$this->assertTrue( $data['ok'] ?? false, 'Clear failed: ' . (string) ( $data['reason'] ?? 'unknown' ) );
		$this->assertSame( $cycle, (string) ( $data['cycle'] ?? '' ) );

		$row = $this->carts->find_by_cycle( $cycle );

		$this->assertSame( '', (string) $row['email'] );
		$this->assertSame( '', (string) $row['email_normalized'] );
		$this->assertNull( $row['reminder_due_at'] );
		$this->assertSame( 2, (int) $row['version'] );

		$jobs = $this->cart_jobs( (int) $row['id'] );

		$this->assertCount( 1, $jobs );
		$this->assertSame( EmailJobRepository::STATUS_CANCELLED, $jobs[0]['status'] );

		$contact = ( new \NowCRM\Contacts\ContactRepository() )->find_by_email( $email );

		$this->assertNotTrue( Reminder::can_send( $jobs[0], $contact ) );
	}

	public function test_clear_without_a_bound_cycle_never_creates_a_cart(): void {
		$this->fill_cart();

		$before = $this->table_count( 'carts' );

		$data = $this->track(
			array(
				'email'          => '',
				'email_state'    => 'none',
				'kind'           => 'capture',
				'activity_order' => (string) $this->order_ms(),
			)
		);

		$this->assertFalse( $data['ok'] ?? true );
		$this->assertSame( 'no_capture', $data['reason'] ?? '' );
		$this->assertSame( $before, $this->table_count( 'carts' ) );
	}

	public function test_an_older_clear_cannot_override_a_newer_email(): void {
		$this->fill_cart();

		$email = 'newer-' . wp_generate_password( 6, false ) . '@example.test';
		$order = $this->order_ms();
		$cycle = $this->capture( $email );

		$data = $this->track(
			array(
				'email'          => '',
				'email_state'    => 'none',
				'kind'           => 'capture',
				'activity_order' => (string) ( $order - 1000 ),
				'previous_cycle' => $cycle,
			)
		);

		$this->assertTrue( $data['ok'] ?? false );
		$this->assertTrue( $data['stale'] ?? false );

		$row = $this->carts->find_by_cycle( $cycle );

		$this->assertSame( mb_strtolower( $email ), (string) $row['email_normalized'] );
	}

	public function test_a_foreign_session_cannot_clear_the_address(): void {
		$this->fill_cart();

		$email = 'owner-' . wp_generate_password( 6, false ) . '@example.test';
		$cycle = $this->capture( $email );

		$result = $this->carts->clear_email( $cycle, 'someone-else', $this->order_ms() );

		$this->assertWPError( $result );
		$this->assertSame( 'nowcrm_cart_session_mismatch', $result->get_error_code() );

		$row = $this->carts->find_by_cycle( $cycle );

		$this->assertSame( mb_strtolower( $email ), (string) $row['email_normalized'] );
	}

	public function test_checkout_heartbeat_extends_the_idle_window_without_a_version_change(): void {
		global $wpdb;

		$this->fill_cart();

		$email = 'heartbeat-' . wp_generate_password( 6, false ) . '@example.test';
		$cycle = $this->capture( $email );
		$row   = $this->carts->find_by_cycle( $cycle );

		$old = gmdate( 'Y-m-d H:i:s', time() - 3600 );

		$wpdb->update(
			\NowCRM\Support\Schema::table( 'carts' ),
			array( 'last_activity_at' => $old ),
			array( 'id' => (int) $row['id'] )
		);

		$data = $this->track(
			array(
				'email'          => $email,
				'email_state'    => 'valid',
				'kind'           => 'activity',
				'activity_order' => (string) $this->order_after( $row ),
				'previous_cycle' => $cycle,
			)
		);

		$this->assertTrue( $data['ok'] ?? false, 'Heartbeat failed: ' . (string) ( $data['reason'] ?? 'unknown' ) );

		$row = $this->carts->find_by_cycle( $cycle );

		$this->assertSame( 1, (int) $row['version'] );
		$this->assertNotSame( $old, (string) $row['last_activity_at'] );
		$this->assertSame( CartRepository::STATUS_ACTIVE, (string) $row['status'] );
	}

	public function test_returning_activity_reactivates_an_abandoned_cart_and_cancels_the_job(): void {
		$this->fill_cart();

		$email = 'returning-' . wp_generate_password( 6, false ) . '@example.test';
		$this->make_contact( array( 'email' => $email ) );

		$cycle = $this->capture( $email );
		$row   = $this->carts->find_by_cycle( $cycle );

		$this->assertTrue( $this->carts->mark_abandoned( (int) $row['id'] ) );
		$this->assertTrue( $this->queue_reminder( $this->carts->get( (int) $row['id'] ) ) );

		$data = $this->track(
			array(
				'email'          => $email,
				'email_state'    => 'valid',
				'kind'           => 'activity',
				'activity_order' => (string) $this->order_after( $row ),
				'previous_cycle' => $cycle,
			)
		);

		$this->assertTrue( $data['ok'] ?? false, 'Reactivation failed: ' . (string) ( $data['reason'] ?? 'unknown' ) );

		$row = $this->carts->find_by_cycle( $cycle );

		$this->assertSame( CartRepository::STATUS_ACTIVE, (string) $row['status'] );
		$this->assertSame( 2, (int) $row['version'] );
		$this->assertNull( $row['reminder_due_at'] );

		$jobs = $this->cart_jobs( (int) $row['id'] );

		$this->assertCount( 1, $jobs );
		$this->assertSame( EmailJobRepository::STATUS_CANCELLED, $jobs[0]['status'] );
	}

	public function test_changing_the_address_cancels_the_job_for_the_old_address(): void {
		$this->fill_cart();

		$old_email = 'old-' . wp_generate_password( 6, false ) . '@example.test';
		$new_email = 'new-' . wp_generate_password( 6, false ) . '@example.test';

		$this->make_contact( array( 'email' => $old_email ) );

		$cycle = $this->capture( $old_email );
		$row   = $this->carts->find_by_cycle( $cycle );

		$this->assertTrue( $this->carts->mark_abandoned( (int) $row['id'] ) );
		$this->assertTrue( $this->queue_reminder( $this->carts->get( (int) $row['id'] ) ) );

		$data = $this->track(
			array(
				'email'          => $new_email,
				'activity_order' => (string) $this->order_after( $row ),
				'previous_cycle' => $cycle,
			)
		);

		$this->assertTrue( $data['ok'] ?? false, 'Re-capture failed: ' . (string) ( $data['reason'] ?? 'unknown' ) );

		$row = $this->carts->find_by_cycle( $cycle );

		$this->assertSame( mb_strtolower( $new_email ), (string) $row['email_normalized'] );

		$jobs = $this->cart_jobs( (int) $row['id'] );

		$this->assertCount( 1, $jobs );
		$this->assertSame( EmailJobRepository::STATUS_CANCELLED, $jobs[0]['status'] );
		$this->assertSame( mb_strtolower( $old_email ), (string) $jobs[0]['recipient'] );

		$contact = ( new \NowCRM\Contacts\ContactRepository() )->find_by_email( $old_email );

		$this->assertNotTrue( Reminder::can_send( $jobs[0], $contact ) );
	}

	public function test_a_completed_cycle_rejects_late_activity(): void {
		$this->fill_cart();

		$email = 'done-' . wp_generate_password( 6, false ) . '@example.test';
		$cycle = $this->capture( $email );
		$row   = $this->carts->find_by_cycle( $cycle );

		$this->carts->complete_with_order( (int) $row['id'], 123, 'natural' );

		$data = $this->track(
			array(
				'email'          => $email,
				'email_state'    => 'valid',
				'kind'           => 'activity',
				'activity_order' => (string) $this->order_after( $row ),
				'previous_cycle' => $cycle,
			)
		);

		$this->assertFalse( $data['ok'] ?? true );
		$this->assertSame( 'completed', $data['reason'] ?? '' );
		$this->assertSame( CartRepository::STATUS_COMPLETED, (string) $this->carts->get( (int) $row['id'] )['status'] );
	}

	public function test_an_invalid_email_state_keeps_the_captured_address(): void {
		$this->fill_cart();

		$email = 'keep-' . wp_generate_password( 6, false ) . '@example.test';
		$cycle = $this->capture( $email );

		$data = $this->track(
			array(
				'email'          => 'partial@',
				'activity_order' => (string) $this->order_ms(),
				'previous_cycle' => $cycle,
			)
		);

		$this->assertFalse( $data['ok'] ?? true );
		$this->assertSame( 'invalid_email', $data['reason'] ?? '' );

		$row = $this->carts->find_by_cycle( $cycle );

		$this->assertSame( mb_strtolower( $email ), (string) $row['email_normalized'] );
	}
}
