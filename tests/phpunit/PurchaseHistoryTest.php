<?php
/**
 * Product-level purchase history rows for the contact detail screen.
 *
 * @package NowCRM
 */

use NowCRM\Woo\PurchaseHistory;

/**
 * @covers \NowCRM\Woo\PurchaseHistory
 */
final class PurchaseHistoryTest extends NowCrmTestCase {

	public function test_items_keep_variation_quantity_and_line_total(): void {
		$product = new \WC_Product_Variable();
		$product->set_name( 'Fixture variable product' );
		$product->set_status( 'publish' );

		$attribute = new \WC_Product_Attribute();
		$attribute->set_id( 0 );
		$attribute->set_name( 'Color' );
		$attribute->set_options( array( 'Red', 'Blue' ) );
		$attribute->set_position( 0 );
		$attribute->set_visible( true );
		$attribute->set_variation( true );
		$product->set_attributes( array( $attribute ) );
		$product->save();

		$variation = new \WC_Product_Variation();
		$variation->set_parent_id( $product->get_id() );
		$variation->set_attributes( array( 'color' => 'Red' ) );
		$variation->set_regular_price( '120' );
		$variation->set_status( 'publish' );
		$variation->save();

		$order = $this->make_order_with_line_item( wc_get_product( $variation->get_id() ), 2, 'purchase-history@example.test' );

		$this->assertCount( 1, $order->get_items( 'line_item' ), 'Fixture must persist exactly one line item.' );

		$rows = PurchaseHistory::enrich(
			array(
				array(
					'order_id'     => $order->get_id(),
					'status'       => 'completed',
					'net_total'    => 240,
					'currency'     => 'TWD',
					'is_counted'   => 1,
					'date_created' => gmdate( 'Y-m-d H:i:s' ),
				),
			)
		);

		$this->assertCount( 1, $rows );
		$this->assertSame( (string) $order->get_order_number(), $rows[0]['number'] );
		$this->assertNotSame( '', $rows[0]['edit_url'] );
		$this->assertCount( 1, $rows[0]['items'] );

		$item = $rows[0]['items'][0];

		$this->assertSame( 'Fixture variable product - Red', $item['name'] );
		$this->assertStringContainsString( 'Red', $item['options'] );
		$this->assertSame( 2.0, $item['quantity'] );
		$this->assertSame( 240.0, $item['total'] );
	}

	public function test_deleted_product_keeps_the_order_snapshot(): void {
		$product = new \WC_Product_Simple();
		$product->set_name( 'Fixture deleted product' );
		$product->set_regular_price( '75' );
		$product->set_status( 'publish' );
		$product->save();

		$order = $this->make_order_with_line_item( $product, 2, 'deleted-product@example.test' );

		$this->assertCount( 1, $order->get_items( 'line_item' ), 'Fixture must persist exactly one line item.' );

		$product->delete( true );

		$rows = PurchaseHistory::enrich(
			array(
				array(
					'order_id'     => $order->get_id(),
					'status'       => 'completed',
					'net_total'    => 150,
					'currency'     => 'TWD',
					'is_counted'   => 1,
					'date_created' => gmdate( 'Y-m-d H:i:s' ),
				),
			)
		);

		$this->assertCount( 1, $rows[0]['items'] );
		$this->assertSame( 'Fixture deleted product', $rows[0]['items'][0]['name'] );
		$this->assertSame( 2.0, $rows[0]['items'][0]['quantity'] );
		$this->assertSame( 150.0, $rows[0]['items'][0]['total'] );
	}

	public function test_missing_order_keeps_the_accounting_row(): void {
		$order = $this->make_order(
			array(
				'billing_email' => 'deleted-order@example.test',
				'total'         => 100,
			)
		);

		$order_id = $order->get_id();
		$order->delete( true );

		$rows = PurchaseHistory::enrich(
			array(
				array(
					'order_id'     => $order_id,
					'status'       => 'completed',
					'net_total'    => 100,
					'currency'     => 'TWD',
					'is_counted'   => 1,
					'date_created' => gmdate( 'Y-m-d H:i:s' ),
				),
			)
		);

		$this->assertCount( 1, $rows );
		$this->assertSame( (string) $order_id, $rows[0]['number'] );
		$this->assertSame( '', $rows[0]['edit_url'] );
		$this->assertSame( array(), $rows[0]['items'] );
		$this->assertSame( 100.0, (float) $rows[0]['net_total'] );
	}

	/**
	 * Persist an order with exactly one product line item.
	 *
	 * Attach the item before the first save. Adding a product to an already
	 * persisted order and then saving again stores the line item twice in
	 * HPOS mode, which would make the presenter assertions meaningless.
	 *
	 * @param \WC_Product $product  Product or variation.
	 * @param int         $quantity Quantity.
	 * @param string      $email    Billing email.
	 * @return \WC_Order Reloaded order with one line item.
	 */
	private function make_order_with_line_item( \WC_Product $product, int $quantity, string $email ): \WC_Order {
		$line_total = (float) $product->get_price() * $quantity;

		$order = new \WC_Order();
		$order->set_billing_email( $email );
		$order->set_currency( 'TWD' );
		$order->set_total( $line_total );

		$item = new \WC_Order_Item_Product();
		$item->set_props(
			array(
				'name'         => $product->get_name(),
				'tax_class'    => $product->get_tax_class(),
				'product_id'   => $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id(),
				'variation_id' => $product->is_type( 'variation' ) ? $product->get_id() : 0,
				'variation'    => $product->is_type( 'variation' ) ? $product->get_attributes() : array(),
				'subtotal'     => $line_total,
				'total'        => $line_total,
				'quantity'     => $quantity,
			)
		);

		$order->add_item( $item );
		$order->save();

		$order = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( \WC_Order::class, $order );

		return $order;
	}
}
