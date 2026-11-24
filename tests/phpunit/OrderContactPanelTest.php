<?php
/**
 * Read-only NOW CRM contact panel on the WooCommerce order screen.
 *
 * @package NowCRM
 */

use NowCRM\Admin\OrderContactPanel;
use NowCRM\Email\SuppressionRepository;
use NowCRM\Support\Capabilities;
use NowCRM\Support\Schema;
use NowCRM\Terms\TermRepository;

/**
 * @covers \NowCRM\Admin\OrderContactPanel
 */
final class OrderContactPanelTest extends NowCrmTestCase {

	public function set_up(): void {
		parent::set_up();

		require_once ABSPATH . 'wp-admin/includes/template.php';

		Capabilities::grant();

		// The test install loads WooCommerce without running its activation
		// routine, so the store capabilities are added here.
		$role = get_role( 'administrator' );

		if ( $role ) {
			foreach ( array( 'edit_shop_orders', 'edit_others_shop_orders', 'manage_woocommerce' ) as $cap ) {
				$role->add_cap( $cap );
			}
		}

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$GLOBALS['wp_meta_boxes'] = array();
	}

	public function tear_down(): void {
		$GLOBALS['wp_meta_boxes'] = array();

		parent::tear_down();
	}

	public function test_metabox_registers_for_hpos_and_legacy_screens(): void {
		$order = $this->make_linked_order( 'panel-screens@example.test' );
		$post  = new WP_Post( (object) array( 'ID' => $order->get_id() ) );

		$this->add( 'woocommerce_page_wc-orders', $order );
		$this->assertTrue( $this->has_box( 'woocommerce_page_wc-orders' ) );

		$this->add( 'shop_order', $post );
		$this->assertTrue( $this->has_box( 'shop_order' ) );
	}

	public function test_panel_shows_identity_purchases_and_profile_link(): void {
		$order = $this->make_linked_order( 'panel-content@example.test', array( 'marketing_optin' => 1 ) );

		$this->add( 'shop_order', new WP_Post( (object) array( 'ID' => $order->get_id() ) ) );
		$html = $this->render( 'shop_order' );

		$this->assertStringContainsString( 'Ada Lovelace', $html );
		$this->assertStringContainsString( 'panel-content@example.test', $html );
		$this->assertStringContainsString( '已訂閱', $html );
		$this->assertStringContainsString( '已同意', $html );
		$this->assertStringContainsString( '120.00 TWD', $html );
		$this->assertStringContainsString( '2026-11-01', $html );
		$this->assertStringContainsString( 'page=nowcrm-contact', $html );
		$this->assertStringContainsString( 'tab=purchases', $html );
	}

	public function test_metabox_is_hidden_without_the_now_crm_capability(): void {
		add_role(
			'nowcrm_order_panel_probe',
			'Order panel probe',
			array(
				'read'                     => true,
				'edit_shop_orders'         => true,
				'edit_others_shop_orders'  => true,
			)
		);

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'nowcrm_order_panel_probe' ) ) );

		$order = $this->make_linked_order( 'panel-nocap@example.test' );

		$this->add( 'shop_order', new WP_Post( (object) array( 'ID' => $order->get_id() ) ) );
		$this->assertFalse( $this->has_box( 'shop_order' ) );

		remove_role( 'nowcrm_order_panel_probe' );
	}

	public function test_link_to_a_deleted_contact_hides_the_panel_without_rebinding(): void {
		$this->make_contact( array( 'email' => 'panel-ghost@example.test' ) );

		$order = $this->make_order( array( 'billing_email' => 'panel-ghost@example.test' ) );

		$this->assertNotWPError(
			$this->links->upsert(
				array(
					'order_id'      => $order->get_id(),
					'contact_id'    => 999999,
					'billing_email' => 'panel-ghost@example.test',
					'status'        => 'processing',
				)
			)
		);

		$this->add( 'shop_order', new WP_Post( (object) array( 'ID' => $order->get_id() ) ) );
		$this->assertFalse( $this->has_box( 'shop_order' ) );
	}

	public function test_email_fallback_renders_a_notice_and_never_writes(): void {
		$contact_id = $this->make_contact( array( 'email' => 'panel-fallback@example.test' ) );
		$order      = $this->make_order( array( 'billing_email' => 'panel-fallback@example.test' ) );

		$before = $this->contacts->get( $contact_id );

		$this->add( 'shop_order', new WP_Post( (object) array( 'ID' => $order->get_id() ) ) );
		$html = $this->render( 'shop_order' );

		$this->assertStringContainsString( '尚未建立 NOW 關聯', $html );
		$this->assertNull( $this->links->find_by_order( $order->get_id() ) );

		$after = $this->contacts->get( $contact_id );
		$this->assertSame( $before['wp_user_id'], $after['wp_user_id'] );
		$this->assertSame( $before['updated_at'], $after['updated_at'] );
	}

	public function test_refund_objects_do_not_get_the_panel(): void {
		$this->add( 'shop_order', new WC_Order_Refund() );

		$this->assertFalse( $this->has_box( 'shop_order' ) );
	}

	public function test_suppression_and_terms_are_rendered(): void {
		$order = $this->make_linked_order(
			'panel-terms@example.test',
			array(
				'marketing_optin' => 0,
			)
		);

		$contact_id = (int) $this->links->find_by_order( $order->get_id() )['contact_id'];

		$this->terms->attach( $contact_id, $this->terms->create( TermRepository::TYPE_LIST, 'VIP' ) );

		for ( $index = 1; $index <= 4; $index++ ) {
			$this->terms->attach( $contact_id, $this->terms->create( TermRepository::TYPE_TAG, 'tag-' . $index ) );
		}

		( new SuppressionRepository() )->add(
			'panel-terms@example.test',
			SuppressionRepository::REASON_BOUNCED,
			'test',
			$contact_id
		);

		$this->add( 'shop_order', new WP_Post( (object) array( 'ID' => $order->get_id() ) ) );
		$html = $this->render( 'shop_order' );

		$this->assertStringContainsString( '未同意', $html );
		$this->assertStringContainsString( '已抑制行銷信', $html );
		$this->assertStringContainsString( '退信', $html );
		$this->assertStringContainsString( 'VIP', $html );
		$this->assertStringContainsString( 'tag-1', $html );
		$this->assertStringContainsString( '+1', $html );
	}

	public function test_contact_name_is_escaped(): void {
		global $wpdb;

		$order      = $this->make_linked_order( 'panel-escape@example.test' );
		$contact_id = (int) $this->links->find_by_order( $order->get_id() )['contact_id'];

		$wpdb->update(
			Schema::table( 'contacts' ),
			array( 'first_name' => '<b>Bold</b>' ),
			array( 'id' => $contact_id ),
			array( '%s' ),
			array( '%d' )
		);

		$this->add( 'shop_order', new WP_Post( (object) array( 'ID' => $order->get_id() ) ) );
		$html = $this->render( 'shop_order' );

		$this->assertStringContainsString( '&lt;b&gt;Bold&lt;/b&gt;', $html );
		$this->assertStringNotContainsString( '<b>Bold</b>', $html );
	}

	public function test_panel_query_count_is_bounded(): void {
		$order = $this->make_linked_order( 'panel-queries@example.test' );

		$count  = 0;
		$filter = static function ( $query ) use ( &$count ): string {
			if ( false !== strpos( $query, 'nowcrm_' ) ) {
				++$count;
			}

			return $query;
		};

		add_filter( 'query', $filter );
		$this->add( 'shop_order', new WP_Post( (object) array( 'ID' => $order->get_id() ) ) );
		remove_filter( 'query', $filter );

		$this->assertTrue( $this->has_box( 'shop_order' ) );
		$this->assertLessThanOrEqual( 5, $count );
	}

	/**
	 * Create a contact and link one counted order to it.
	 *
	 * @param string               $email Contact and billing email.
	 * @param array<string, mixed> $link  Extra order link fields.
	 */
	private function make_linked_order( string $email, array $link = array() ): \WC_Order {
		$contact_id = $this->make_contact(
			array(
				'email'      => $email,
				'first_name' => 'Ada',
				'last_name'  => 'Lovelace',
			)
		);

		$order = $this->make_order( array( 'billing_email' => $email ) );

		$result = $this->links->upsert(
			array_merge(
				array(
					'order_id'      => $order->get_id(),
					'contact_id'    => $contact_id,
					'billing_email' => $email,
					'status'        => 'processing',
					'total'         => 120,
					'net_total'     => 120,
					'currency'      => 'TWD',
					'is_counted'    => 1,
					'date_created'  => '2026-11-01 00:00:00',
				),
				$link
			)
		);

		$this->assertTrue( $result, 'Fixture order link failed.' );

		return $order;
	}

	/**
	 * Invoke the panel registration for one screen.
	 *
	 * @param string $screen_id Screen ID.
	 * @param mixed  $object    Order or post object.
	 */
	private function add( string $screen_id, $object ): void {
		( new OrderContactPanel() )->maybe_add( $screen_id, $object );
	}

	/**
	 * Whether the panel metabox exists for one screen.
	 */
	private function has_box( string $screen_id ): bool {
		return isset( $GLOBALS['wp_meta_boxes'][ $screen_id ]['side']['low'][ OrderContactPanel::ID ] );
	}

	/**
	 * Render the registered panel and return its markup.
	 */
	private function render( string $screen_id ): string {
		$box = $GLOBALS['wp_meta_boxes'][ $screen_id ]['side']['low'][ OrderContactPanel::ID ] ?? null;

		$this->assertIsArray( $box, 'Panel metabox was not registered.' );

		ob_start();
		call_user_func( $box['callback'], null );

		return (string) ob_get_clean();
	}
}
