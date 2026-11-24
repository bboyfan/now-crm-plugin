<?php
/**
 * Read-only Woo and cart reconciliation audit.
 *
 * @package NowCRM
 */

use NowCRM\Cart\Automation;
use NowCRM\Cart\CartRepository;
use NowCRM\Contacts\Status;
use NowCRM\Woo\WooAudit;

/**
 * @covers \NowCRM\Woo\WooAudit
 */
final class WooAuditTest extends NowCrmTestCase {

	public function test_order_linkage_reports_missing_contact_and_missing_link_without_writes(): void {
		$this->set_settings( array( 'woo_sync_enabled' => false ) );

		$this->make_order( array( 'billing_email' => 'audit-no-contact@example.test' ) );
		$contact_id = $this->make_contact( array( 'email' => 'audit-has-contact@example.test' ) );
		$this->make_order( array( 'billing_email' => 'audit-has-contact@example.test' ) );

		$before = $this->table_count( 'contacts' );
		$report = ( new WooAudit() )->order_linkage( 50 );

		$this->assertSame( 2, $report['orders_scanned'] );
		$this->assertSame( 1, $report['contact_missing'] );
		$this->assertSame( 1, $report['link_missing'] );
		$this->assertArrayNotHasKey( 'contact_without_link', $report );
		$this->assertSame( 0, $report['linked'] );
		$this->assertSame( $before, $this->table_count( 'contacts' ) );
		$this->assertNull( $this->links->find_by_order( $this->last_order_id() ) );
		$this->assertNotNull( $this->contacts->get( $contact_id ) );
	}

	public function test_order_limit_is_hard_when_batch_is_larger_and_when_limit_is_not_a_multiple(): void {
		$this->set_settings( array( 'woo_sync_enabled' => false ) );

		for ( $index = 1; $index <= 5; $index++ ) {
			$this->make_order( array( 'billing_email' => "audit-order-limit-{$index}@example.test" ) );
		}

		$small = ( new WooAudit() )->order_linkage( 50, 2 );

		$this->assertSame( 2, $small['orders_scanned'] );
		$this->assertSame( 2, $small['contact_missing'] );

		$partial = ( new WooAudit() )->order_linkage( 2, 3 );

		$this->assertSame( 3, $partial['orders_scanned'] );
		$this->assertSame( 3, $partial['contact_missing'] );
	}

	public function test_cart_limit_is_hard_across_scan_and_not_a_multiple(): void {
		$repo = new CartRepository();

		for ( $index = 1; $index <= 5; $index++ ) {
			$cart = $this->make_cart( array( 'email' => "audit-cart-limit-{$index}@example.test" ) );
			$this->assertTrue( $repo->mark_abandoned( (int) $cart['id'] ) );
		}

		$small = ( new WooAudit() )->cart_reminders( 50, 2 );

		$this->assertSame( 2, $small['carts_scanned'] );
		$this->assertSame( 2, $small['abandoned_scanned'] );

		$partial = ( new WooAudit() )->cart_reminders( 2, 5 );

		$this->assertSame( 5, $partial['carts_scanned'] );
		$this->assertSame( 5, $partial['abandoned_scanned'] );
	}

	public function test_abandoned_cart_eligibility_uses_existing_policy_facts(): void {
		$this->set_settings(
			array(
				'abandoned_cart_enabled' => true,
				'cart_max_reminders'     => 1,
				'cart_cooldown_days'     => 0,
			)
		);

		$this->assertNotWPError( Automation::ensure_managed() );

		$this->make_contact(
			array(
				'email'  => 'audit-eligible@example.test',
				'status' => Status::SUBSCRIBED,
			)
		);

		$cart = $this->make_cart( array( 'email' => 'audit-eligible@example.test' ) );
		$this->assertTrue( ( new CartRepository() )->mark_abandoned( (int) $cart['id'] ) );

		$report = ( new WooAudit() )->cart_reminders( 50 );

		$this->assertSame( 1, $report['abandoned_scanned'] );
		$this->assertSame( 1, $report['eligible'] );
		$this->assertSame( 0, $report['not_eligible'] );
	}

	public function test_transactional_abandoned_cart_is_not_eligible(): void {
		$this->set_settings( array( 'abandoned_cart_enabled' => true ) );
		$this->assertNotWPError( Automation::ensure_managed() );

		$this->make_contact(
			array(
				'email'  => 'audit-transactional@example.test',
				'status' => Status::TRANSACTIONAL,
			)
		);

		$cart = $this->make_cart( array( 'email' => 'audit-transactional@example.test' ) );
		$this->assertTrue( ( new CartRepository() )->mark_abandoned( (int) $cart['id'] ) );

		$report = ( new WooAudit() )->cart_reminders( 50 );

		$this->assertSame( 1, $report['not_eligible'] );
		$this->assertSame( 1, (int) ( $report['reasons']['not_subscribed'] ?? 0 ) );
	}

	public function test_exact_linked_order_payment_evidence_is_reported_for_a_custom_status(): void {
		$contact_id = $this->make_contact(
			array(
				'email'  => 'audit-paid@example.test',
				'status' => Status::SUBSCRIBED,
			)
		);

		$cart = $this->make_cart( array( 'email' => 'audit-paid@example.test' ) );
		$this->assertTrue( ( new CartRepository() )->mark_abandoned( (int) $cart['id'] ) );

		$order = new WC_Order();
		$order->set_billing_email( 'audit-paid@example.test' );
		$order->set_total( 321 );
		$order->set_status( 'audit-paid' );
		$order->set_date_paid( time() );
		$order->set_transaction_id( 'txn-audit-1' );
		$order->save();

		$repo = new CartRepository();
		$this->assertTrue( $repo->mark_order_pending( (int) $cart['id'], $order->get_id(), 'natural' ) );
		$this->assertTrue( $repo->complete_with_order( (int) $cart['id'], $order->get_id(), 'natural' ) );

		$report = ( new WooAudit() )->cart_reminders( 50 );

		$this->assertSame( 1, $report['linked_carts'] );
		$this->assertSame( 1, $report['paid_evidence'] );
		$this->assertSame( 0, $report['finalized_without_evidence'] );
		$this->assertSame( 1, (int) ( $report['attribution']['natural'] ?? 0 ) );
		$this->assertSame( Status::SUBSCRIBED, $this->contacts->get( $contact_id )['status'] );
	}

	public function test_custom_status_without_paid_evidence_is_not_counted_paid(): void {
		$this->make_contact(
			array(
				'email'  => 'audit-nonpaid@example.test',
				'status' => Status::SUBSCRIBED,
			)
		);

		$cart = $this->make_cart( array( 'email' => 'audit-nonpaid@example.test' ) );
		$this->assertTrue( ( new CartRepository() )->mark_abandoned( (int) $cart['id'] ) );

		$order = new WC_Order();
		$order->set_billing_email( 'audit-nonpaid@example.test' );
		$order->set_total( 99 );
		$order->set_status( 'audit-nonpaid' );
		$order->save();

		$this->assertTrue( ( new CartRepository() )->mark_order_pending( (int) $cart['id'], $order->get_id(), 'natural' ) );

		$report = ( new WooAudit() )->cart_reminders( 50 );

		$this->assertSame( 1, $report['linked_carts'] );
		$this->assertSame( 0, $report['paid_evidence'] );
		$this->assertSame( 0, $report['paid_not_finalized'] );
		$this->assertSame( CartRepository::STATUS_ORDER_PENDING, ( new CartRepository() )->get( (int) $cart['id'] )['status'] );
	}

	private function last_order_id(): int {
		$orders = wc_get_orders(
			array(
				'limit'   => 1,
				'orderby' => 'ID',
				'order'   => 'DESC',
				'return'  => 'ids',
			)
		);

		return (int) ( $orders[0] ?? 0 );
	}
}
