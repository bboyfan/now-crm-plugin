<?php
/**
 * Purchase accounting rules.
 *
 * @package NowCRM
 */

use NowCRM\Orders\OrderAccounting;

/**
 * @covers \NowCRM\Orders\OrderAccounting
 */
final class OrderAccountingTest extends NowCrmTestCase {

	public function test_default_counted_statuses_are_processing_and_completed(): void {
		$this->assertSame( array( 'processing', 'completed' ), OrderAccounting::counted_statuses() );
	}

	public function test_status_prefix_is_ignored(): void {
		$this->assertTrue( OrderAccounting::is_counted_status( 'completed' ) );
		$this->assertTrue( OrderAccounting::is_counted_status( 'wc-processing' ) );
		$this->assertFalse( OrderAccounting::is_counted_status( 'wc-pending' ) );
		$this->assertFalse( OrderAccounting::is_counted_status( 'on-hold' ) );
		$this->assertFalse( OrderAccounting::is_counted_status( 'checkout-draft' ) );
	}

	public function test_unpaid_and_dead_statuses_do_not_count(): void {
		foreach ( array( 'pending', 'on-hold', 'failed', 'cancelled', 'refunded', 'checkout-draft', 'ry-at-cvs' ) as $status ) {
			$this->assertFalse( OrderAccounting::is_counted_status( $status ), "{$status} must not count by default." );
		}
	}

	public function test_custom_post_payment_status_needs_an_explicit_filter(): void {
		add_filter(
			'nowcrm_counted_order_statuses',
			static function ( array $statuses ): array {
				$statuses[] = 'ry-at-cvs';

				return $statuses;
			}
		);

		$this->assertTrue( OrderAccounting::is_counted_status( 'ry-at-cvs' ) );
	}

	public function test_net_total_subtracts_refunds(): void {
		$order = $this->make_order(
			array(
				'billing_email' => 'refund@example.test',
				'total'         => 1000,
				'status'        => 'completed',
			)
		);

		$this->assertSame( 1000.0, OrderAccounting::net_total( $order ) );

		wc_create_refund(
			array(
				'order_id' => $order->get_id(),
				'amount'   => 250,
				'reason'   => 'fixture refund',
			)
		);

		$order = wc_get_order( $order->get_id() );

		$this->assertSame( 750.0, OrderAccounting::net_total( $order ) );
	}

	public function test_map_order_contains_the_link_payload(): void {
		$order = $this->make_order(
			array(
				'billing_email' => 'map@example.test',
				'total'         => 320,
				'status'        => 'completed',
			)
		);

		$payload = OrderAccounting::map_order( $order );

		$this->assertSame( $order->get_id(), $payload['order_id'] );
		$this->assertSame( 'map@example.test', $payload['billing_email'] );
		$this->assertSame( 'completed', $payload['status'] );
		$this->assertSame( 320.0, $payload['total'] );
		$this->assertSame( 320.0, $payload['net_total'] );
		$this->assertSame( 1, $payload['is_counted'] );
		$this->assertSame( $order->get_currency(), $payload['currency'] );
		$this->assertNotEmpty( $payload['currency'] );
		$this->assertNotNull( $payload['date_created'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $payload['date_created'] );
	}
}
