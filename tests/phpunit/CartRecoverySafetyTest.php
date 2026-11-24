<?php
/**
 * Cart recovery safety: preflight, exact rollback, adoption gate, and the
 * separation between a recovery click and a restored cart.
 *
 * @package NowCRM
 */

use NowCRM\Cart\CartRepository;
use NowCRM\Cart\Recovery;

/**
 * @covers \NowCRM\Cart\Recovery
 */
final class CartRecoverySafetyTest extends NowCrmTestCase {

	private Recovery $recovery;

	private mixed $manage_stock_option = null;

	public function set_up(): void {
		parent::set_up();

		$this->recovery = new Recovery();

		$this->set_settings( array( 'abandoned_cart_enabled' => true ) );

		$this->manage_stock_option = get_option( 'woocommerce_manage_stock' );
		update_option( 'woocommerce_manage_stock', 'yes' );

		if ( function_exists( 'WC' ) && ! WC()->cart && function_exists( 'wc_load_cart' ) ) {
			wc_load_cart();
		}

		if ( function_exists( 'WC' ) && WC()->cart ) {
			WC()->cart->empty_cart();
		}

		if ( function_exists( 'WC' ) && ! WC()->session && method_exists( WC(), 'initialize_session' ) ) {
			WC()->initialize_session();
		}
	}

	public function tear_down(): void {
		if ( function_exists( 'WC' ) && WC()->cart ) {
			WC()->cart->empty_cart();
		}

		// Never leave fake recovered provenance in the shared Woo session; the
		// session table is not rolled back with the per-test transaction.
		if ( function_exists( 'WC' ) && WC()->session ) {
			WC()->session->set( 'nowcrm_cart_cycle', '' );
			WC()->session->set( 'nowcrm_recovered_cart_id', 0 );

			if ( method_exists( WC()->session, 'save_data' ) ) {
				WC()->session->save_data();
			}
		}

		if ( false === $this->manage_stock_option ) {
			delete_option( 'woocommerce_manage_stock' );
		} else {
			update_option( 'woocommerce_manage_stock', $this->manage_stock_option );
		}

		parent::tear_down();
	}

	/**
	 * Simple in-stock product.
	 */
	private function make_product( float $price = 100, int $stock = 10, bool $sold_individually = false ): \WC_Product {
		$product = new \WC_Product_Simple();
		$product->set_name( 'Recovery fixture ' . wp_generate_password( 5, false ) );
		$product->set_regular_price( (string) $price );
		$product->set_manage_stock( true );
		$product->set_stock_quantity( $stock );
		$product->set_sold_individually( $sold_individually );
		$product->set_status( 'publish' );
		$product->save();

		return $product;
	}

	/**
	 * Custom-attribute variable product with one variation per colour.
	 *
	 * @return array{parent: \WC_Product_Variable, variations: array<string, \WC_Product_Variation>}
	 */
	private function make_variation_product(): array {
		$parent = new \WC_Product_Variable();
		$parent->set_name( 'Recovery variable ' . wp_generate_password( 5, false ) );
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

		$variation_ids = array();

		foreach ( array( 'Red', 'Blue' ) as $color ) {
			$variation = new \WC_Product_Variation();
			$variation->set_parent_id( $parent->get_id() );
			$variation->set_attributes( array( 'color' => $color ) );
			$variation->set_regular_price( '120' );
			$variation->set_status( 'publish' );
			$variation->save();

			$variation_ids[ $color ] = $variation->get_id();
		}

		// Persist the parent price range, stock status and attribute map the
		// same way an admin save does before anything reads the variations.
		\WC_Product_Variable::sync( $parent->get_id() );

		$variations = array();

		foreach ( $variation_ids as $color => $id ) {
			$variations[ $color ] = wc_get_product( $id );
		}

		return array(
			'parent'     => wc_get_product( $parent->get_id() ),
			'variations' => $variations,
		);
	}

	/**
	 * Percent coupon fixture.
	 */
	private function make_coupon( string $code ): void {
		$coupon = new \WC_Coupon();
		$coupon->set_code( $code );
		$coupon->set_discount_type( 'percent' );
		$coupon->set_amount( 10 );
		$coupon->set_usage_limit( 0 );
		$coupon->save();
	}

	/**
	 * Cart rows keyed by cart item key, without recalculated line totals.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function cart_shape(): array {
		$shape = array();

		foreach ( (array) WC()->cart->cart_contents as $key => $item ) {
			$shape[ $key ] = array(
				'product_id'   => (int) ( $item['product_id'] ?? 0 ),
				'variation_id' => (int) ( $item['variation_id'] ?? 0 ),
				'quantity'     => (float) ( $item['quantity'] ?? 0 ),
				'variation'    => $item['variation'] ?? array(),
				'gift_note'    => $item['gift_note'] ?? null,
			);
		}

		return $shape;
	}

	public function test_status_gate_only_accepts_abandoned_carts(): void {
		$this->assertTrue( Recovery::supports_status( CartRepository::STATUS_ABANDONED ) );
		$this->assertFalse( Recovery::supports_status( CartRepository::STATUS_ACTIVE ) );
		$this->assertFalse( Recovery::supports_status( CartRepository::STATUS_LOST ) );
		$this->assertFalse( Recovery::supports_status( CartRepository::STATUS_COMPLETED ) );
		$this->assertFalse( Recovery::supports_status( CartRepository::STATUS_RECOVERED ) );
		$this->assertFalse( Recovery::supports_status( 'order_pending' ) );
	}

	public function test_preflight_refuses_lines_that_need_custom_item_data(): void {
		$keep = $this->make_product();
		$snap = $this->make_product();

		WC()->cart->add_to_cart( $keep->get_id(), 1 );
		$before = $this->cart_shape();

		$cart = array(
			'id'    => 0,
			'items' => array(
				array(
					'product_id'         => $snap->get_id(),
					'variation_id'       => 0,
					'quantity'           => 1,
					'variation'          => array(),
					'requires_item_data' => true,
				),
			),
		);

		$validation = $this->recovery->validate_items( $cart );

		$this->assertFalse( $validation['ok'] );
		$this->assertNotEmpty( $validation['errors'] );

		$restored = $this->recovery->restore( $cart );

		$this->assertFalse( $restored['ok'] );
		$this->assertSame( $before, $this->cart_shape(), 'A refused preflight must not touch the live cart.' );
	}

	public function test_preflight_refuses_unknown_snapshot_payload(): void {
		$snap = $this->make_product( 100, 10 );

		$cart = array(
			'items' => array(
				array(
					'product_id'    => $snap->get_id(),
					'variation_id'  => 0,
					'quantity'      => 1,
					'variation'     => array(),
					'cart_item_data' => array( 'vip_price' => 1 ),
				),
			),
		);

		$validation = $this->recovery->validate_items( $cart );

		$this->assertFalse( $validation['ok'] );
		$this->assertNotEmpty( $validation['errors'] );
	}

	public function test_preflight_aggregates_stock_across_duplicate_lines(): void {
		$product = $this->make_product( 100, 3 );

		$too_many = array(
			'items' => array(
				array( 'product_id' => $product->get_id(), 'variation_id' => 0, 'quantity' => 2, 'variation' => array() ),
				array( 'product_id' => $product->get_id(), 'variation_id' => 0, 'quantity' => 2, 'variation' => array() ),
			),
		);

		$validation = $this->recovery->validate_items( $too_many );

		$this->assertFalse( $validation['ok'], 'Two lines of 2 against stock 3 must not pass per-line checks.' );

		$within_stock = array(
			'items' => array(
				array( 'product_id' => $product->get_id(), 'variation_id' => 0, 'quantity' => 2, 'variation' => array() ),
			),
		);

		$this->assertTrue( $this->recovery->validate_items( $within_stock )['ok'] );
	}

	public function test_preflight_rejects_sold_individually_quantities(): void {
		$product = $this->make_product( 100, 10, true );

		$single = array(
			'items' => array(
				array( 'product_id' => $product->get_id(), 'variation_id' => 0, 'quantity' => 1, 'variation' => array() ),
			),
		);

		$this->assertTrue( $this->recovery->validate_items( $single )['ok'] );

		$double = array(
			'items' => array(
				array( 'product_id' => $product->get_id(), 'variation_id' => 0, 'quantity' => 2, 'variation' => array() ),
			),
		);

		$this->assertFalse( $this->recovery->validate_items( $double )['ok'] );
	}

	public function test_preflight_rejects_non_finite_or_non_positive_quantities(): void {
		$product = $this->make_product();

		foreach ( array( 0, -2, INF ) as $quantity ) {
			$validation = $this->recovery->validate_items(
				array(
					'items' => array(
						array( 'product_id' => $product->get_id(), 'variation_id' => 0, 'quantity' => $quantity, 'variation' => array() ),
					),
				)
			);

			$this->assertFalse( $validation['ok'], 'Quantity must be finite and positive.' );
		}
	}

	public function test_preflight_checks_variation_attributes(): void {
		$fixture = $this->make_variation_product();
		$parent  = $fixture['parent'];
		$red     = $fixture['variations']['Red'];

		$valid = array(
			'items' => array(
				array(
					'product_id'   => $parent->get_id(),
					'variation_id' => $red->get_id(),
					'quantity'     => 1,
					'variation'    => array( 'attribute_color' => 'Red' ),
				),
			),
		);

		$validation = $this->recovery->validate_items( $valid );

		$this->assertSame( array(), $validation['errors'], 'A valid custom-attribute variation must pass preflight.' );

		$mismatched = array(
			'items' => array(
				array(
					'product_id'   => $parent->get_id(),
					'variation_id' => $red->get_id(),
					'quantity'     => 1,
					'variation'    => array( 'attribute_color' => 'Blue' ),
				),
			),
		);

		$mismatch_errors = $this->recovery->validate_items( $mismatched )['errors'];

		$this->assertNotEmpty( $mismatch_errors );
		$this->assertStringContainsString( '與紀錄不符', implode( ' | ', $mismatch_errors ) );

		$missing = array(
			'items' => array(
				array(
					'product_id'   => $parent->get_id(),
					'variation_id' => $red->get_id(),
					'quantity'     => 1,
					'variation'    => array(),
				),
			),
		);

		$missing_errors = $this->recovery->validate_items( $missing )['errors'];

		$this->assertNotEmpty( $missing_errors );
		$this->assertStringContainsString( '缺少規格', implode( ' | ', $missing_errors ) );
	}

	public function test_preflight_keeps_a_live_coupon_but_refuses_an_unreplayable_one(): void {
		$product = $this->make_product();
		$code    = 'safety' . strtolower( wp_generate_password( 6, false, false ) );

		$this->make_coupon( $code );

		WC()->cart->add_to_cart( $product->get_id(), 1 );
		WC()->cart->apply_coupon( $code );
		$this->assertTrue( WC()->cart->has_discount( $code ) );

		$item = array( 'product_id' => $product->get_id(), 'variation_id' => 0, 'quantity' => 1, 'variation' => array() );

		$this->assertTrue( $this->recovery->validate_items( array( 'items' => array( $item ), 'coupons' => array( $code ) ) )['ok'] );

		$unknown = $this->recovery->validate_items( array( 'items' => array( $item ), 'coupons' => array( 'not-' . $code ) ) );

		$this->assertFalse( $unknown['ok'], 'A coupon the live cart does not carry must not be replayed silently.' );
	}

	public function test_restore_keeps_coupons_and_custom_line_data_exactly_when_a_line_fails(): void {
		$keep   = $this->make_product( 100, 10 );
		$snap   = $this->make_product( 100, 10 );
		$broken = $this->make_product( 100, 10 );
		$code   = 'keep' . strtolower( wp_generate_password( 6, false, false ) );

		$this->make_coupon( $code );

		WC()->cart->add_to_cart( $keep->get_id(), 2, 0, array(), array( 'gift_note' => 'keep-me' ) );
		WC()->cart->apply_coupon( $code );
		WC()->cart->calculate_totals();

		$this->assertTrue( WC()->cart->has_discount( $code ) );

		$before_keys = array_keys( WC()->cart->cart_contents );
		$before      = $this->cart_shape();
		$totals      = (float) WC()->cart->get_subtotal();

		$fail_id = $broken->get_id();

		// Validation passes for the line, then the add itself is refused: the
		// rollback must be structural, not a rebuilt approximation.
		$filter = static function ( $quantity, $product_id ) use ( $fail_id ) {
			return (int) $product_id === $fail_id ? 0 : $quantity;
		};

		add_filter( 'woocommerce_add_to_cart_quantity', $filter, 10, 2 );

		$result = $this->recovery->restore(
			array(
				'items' => array(
					array( 'product_id' => $snap->get_id(), 'variation_id' => 0, 'quantity' => 1, 'variation' => array() ),
					array( 'product_id' => $broken->get_id(), 'variation_id' => 0, 'quantity' => 1, 'variation' => array() ),
				),
			)
		);

		remove_filter( 'woocommerce_add_to_cart_quantity', $filter, 10 );

		$this->assertFalse( $result['ok'] );
		$this->assertStringContainsString( '已保留您原本的購物車', $result['error'] );
		$this->assertSame( $before_keys, array_keys( WC()->cart->cart_contents ), 'Rollback must restore the original cart item keys.' );
		$this->assertSame( $before, $this->cart_shape(), 'Rollback must restore exact items, quantities and custom line data.' );
		$this->assertTrue( WC()->cart->has_discount( $code ), 'Rollback must retain the applied coupons.' );
		$this->assertSame( $totals, (float) WC()->cart->get_subtotal(), 'Rollback must recalculate the original totals.' );

		$product_ids = array_map(
			static fn( array $item ): int => (int) $item['product_id'],
			array_values( WC()->cart->cart_contents )
		);

		$this->assertNotContains( $snap->get_id(), $product_ids );
		$this->assertNotContains( $broken->get_id(), $product_ids );
	}

	public function test_restore_rolls_back_when_the_adoption_gate_rejects(): void {
		$keep = $this->make_product();
		$snap = $this->make_product();

		WC()->cart->add_to_cart( $keep->get_id(), 1 );
		$before = $this->cart_shape();

		$seen = null;

		$result = $this->recovery->restore(
			array(
				'items' => array(
					array( 'product_id' => $snap->get_id(), 'variation_id' => 0, 'quantity' => 1, 'variation' => array() ),
				),
			),
			static function () use ( &$seen ): bool {
				$seen = array_map(
					static fn( array $item ): int => (int) $item['product_id'],
					array_values( WC()->cart->cart_contents )
				);

				return false;
			}
		);

		$this->assertFalse( $result['ok'] );
		$this->assertStringContainsString( '已保留', $result['error'] );
		$this->assertNotNull( $seen, 'The adoption gate must run only after every line was added.' );
		$this->assertSame( array( $snap->get_id() ), $seen, 'The gate must see the fully restored cart before commit.' );
		$this->assertSame( $before, $this->cart_shape() );
	}

	public function test_restore_commits_the_snapshot_but_never_sets_session_provenance(): void {
		$keep = $this->make_product();
		$snap = $this->make_product();

		$code = 'commit' . strtolower( wp_generate_password( 6, false, false ) );
		$this->make_coupon( $code );

		WC()->cart->add_to_cart( $keep->get_id(), 1 );
		WC()->cart->apply_coupon( $code );
		WC()->cart->calculate_totals();

		if ( ! WC()->session ) {
			$this->markTestSkipped( 'Woo session is unavailable in this suite.' );
		}

		WC()->session->set( 'nowcrm_cart_cycle', 'bound-before' );
		WC()->session->set( 'nowcrm_recovered_cart_id', 7 );

		$result = $this->recovery->restore(
			array(
				'id'              => 42,
				'cart_cycle_key'  => 'cycle-from-token',
				'items'           => array(
					array( 'product_id' => $snap->get_id(), 'variation_id' => 0, 'quantity' => 1, 'variation' => array() ),
				),
			),
			static fn(): bool => true
		);

		$this->assertTrue( $result['ok'] );

		$ids = array_map(
			static fn( array $item ): int => (int) $item['product_id'],
			array_values( WC()->cart->cart_contents )
		);

		$this->assertSame( array( $snap->get_id() ), $ids );
		$this->assertTrue( WC()->cart->has_discount( $code ), 'The live coupon policy keeps existing discounts on success.' );

		$this->assertSame( 'bound-before', WC()->session->get( 'nowcrm_cart_cycle' ), 'The public restore helper must not set provenance.' );
		$this->assertSame( 7, (int) WC()->session->get( 'nowcrm_recovered_cart_id' ), 'Only the verified URL flow may set recovered attribution.' );
	}

	public function test_restore_adds_the_recorded_variation_attributes(): void {
		$fixture = $this->make_variation_product();
		$parent  = $fixture['parent'];
		$red     = $fixture['variations']['Red'];

		WC()->cart->empty_cart();

		$result = $this->recovery->restore(
			array(
				'items' => array(
					array(
						'product_id'   => $parent->get_id(),
						'variation_id' => $red->get_id(),
						'quantity'     => 1,
						'variation'    => array( 'attribute_color' => 'Red' ),
					),
				),
			)
		);

		$this->assertTrue( $result['ok'], 'Restore error: ' . $result['error'] );

		$items = array_values( WC()->cart->get_cart() );

		$this->assertCount( 1, $items );
		$this->assertSame( $red->get_id(), (int) $items[0]['variation_id'] );
		$this->assertSame( array( 'attribute_color' => 'Red' ), $items[0]['variation'] );
	}
}
