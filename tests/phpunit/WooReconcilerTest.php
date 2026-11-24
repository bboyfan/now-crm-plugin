<?php
/**
 * Historical WooCommerce email gap reconciliation.
 *
 * @package NowCRM
 */

use NowCRM\Contacts\Status;
use NowCRM\Woo\WooReconciler;

/**
 * @covers \NowCRM\Woo\WooReconciler
 */
final class WooReconcilerTest extends NowCrmTestCase {

	public function test_dry_run_counts_without_writing(): void {
		$order = $this->make_order( array( 'billing_email' => 'gap-dry@example.test' ) );

		$report = ( new WooReconciler() )->run( array( 'mode' => 'dry_run' ) );

		$this->assertGreaterThanOrEqual( 1, $report['without_contact'] );
		$this->assertNull( $this->contacts->find_by_email( 'gap-dry@example.test' ) );
		$this->assertNull( $this->links->find_by_order( $order->get_id() ) );
	}

	public function test_apply_creates_transactional_contacts_and_links_orders(): void {
		$order = $this->make_order( array( 'billing_email' => 'gap-apply@example.test' ) );

		$report = ( new WooReconciler() )->run( array( 'mode' => 'apply', 'batch' => 20 ) );

		$this->assertGreaterThanOrEqual( 1, $report['created'] );

		$contact = $this->contacts->find_by_email( 'gap-apply@example.test' );

		$this->assertNotNull( $contact );
		$this->assertSame( Status::TRANSACTIONAL, $contact['status'], 'An order is not marketing consent.' );
		$this->assertSame( 'woocommerce-backfill', $contact['source'] );

		$link = $this->links->find_by_order( $order->get_id() );

		$this->assertNotNull( $link );
		$this->assertSame( (int) $contact['id'], $link['contact_id'] );
	}

	public function test_existing_contact_status_is_not_changed(): void {
		$this->make_contact( array( 'email' => 'keep-status@example.test', 'status' => Status::UNSUBSCRIBED ) );
		$this->make_order( array( 'billing_email' => 'keep-status@example.test' ) );

		( new WooReconciler() )->run( array( 'mode' => 'apply' ) );

		$this->assertSame( Status::UNSUBSCRIBED, $this->contacts->find_by_email( 'keep-status@example.test' )['status'] );
	}
}
