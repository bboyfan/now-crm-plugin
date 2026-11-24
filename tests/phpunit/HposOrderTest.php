<?php
/**
 * The same order lifecycle under both WooCommerce storage modes.
 *
 * Run once with `tests/bin/run-tests.sh` (legacy posts) and once with
 * `--hpos` (custom order tables). The assertion on storage mode proves which
 * mode actually executed.
 *
 * @package NowCRM
 */

/**
 * @covers \NowCRM\Woo\OrderSync
 */
final class HposOrderTest extends NowCrmTestCase {

	public function set_up(): void {
		parent::set_up();
		$this->set_settings( array( 'woo_sync_enabled' => true ) );
	}

	public function test_runner_storage_mode_matches_expectation(): void {
		if ( ! class_exists( \Automattic\WooCommerce\Utilities\OrderUtil::class ) ) {
			$this->markTestSkipped( 'OrderUtil is not available in this WooCommerce version.' );
		}

		$expected = '1' === (string) getenv( 'NOWCRM_HPOS' );

		$this->assertSame(
			$expected,
			\Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled(),
			'Run the suite with --hpos to exercise the custom order tables.'
		);
	}

	public function test_create_change_email_refund_and_delete(): void {
		$order = $this->make_order(
			array(
				'billing_email' => 'hpos-life@example.test',
				'total'         => 1000,
			)
		);

		$order->update_status( 'processing' );

		$link = $this->links->find_by_order( $order->get_id() );
		$this->assertNotNull( $link );
		$this->assertSame( 1000.0, (float) $link['total'] );
		$this->assertSame( 1000.0, (float) $link['net_total'] );
		$this->assertSame( 1, (int) $link['is_counted'] );

		// Billing email change must move the link to the new contact.
		$order->set_billing_email( 'hpos-changed@example.test' );
		$order->save();

		$new_contact = $this->contacts->find_by_email( 'hpos-changed@example.test' );
		$old_contact = $this->contacts->find_by_email( 'hpos-life@example.test' );

		$this->assertNotNull( $new_contact );
		$this->assertNotNull( $old_contact, 'The contact row is kept; only the order link moves.' );
		$this->assertSame( 0, (int) $this->contacts->aggregate( (int) $old_contact['id'] )['order_count'] );

		$link = $this->links->find_by_order( $order->get_id() );
		$this->assertSame( (int) $new_contact['id'], $link['contact_id'] );

		// Partial refund updates net total on the same row.
		$refund = wc_create_refund(
			array(
				'order_id' => $order->get_id(),
				'amount'   => 200,
				'reason'   => 'Fixture refund',
			)
		);

		$this->assertNotWPError( $refund );

		$order = wc_get_order( $order->get_id() );
		$link  = $this->links->find_by_order( $order->get_id() );

		$this->assertSame( 800.0, (float) $link['net_total'] );

		$contact = $this->contacts->find_by_email( 'hpos-changed@example.test' );
		$agg     = $this->contacts->aggregate( (int) $contact['id'] );

		$this->assertSame( 800.0, (float) $agg['total_spent'] );

		// Deleting the order removes the link but keeps the contact.
		$order->delete( true );

		$this->assertNull( $this->links->find_by_order( $order->get_id() ) );
		$this->assertNotNull( $this->contacts->find_by_email( 'hpos-changed@example.test' ) );
	}
}
