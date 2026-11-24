<?php
/**
 * Product-level purchase history for the contact detail screen.
 *
 * The order link table stores accounting rows only. This presenter adds the
 * line items from the live WooCommerce order. Every order loads once through
 * wc_get_order(), which works with both HPOS and legacy post storage.
 *
 * Item names and options come from the order snapshot, so a deleted product
 * still renders the name and variation that were bought.
 *
 * @package NowCRM
 */

namespace NowCRM\Woo;

use WC_Order;
use WC_Order_Item_Product;

defined( 'ABSPATH' ) || exit;

final class PurchaseHistory {

	/**
	 * Add purchased items and the order edit URL to order link rows.
	 *
	 * @param array<int, array<string, mixed>> $links Rows from OrderLinkRepository::links_for_contact().
	 * @return array<int, array<string, mixed>>
	 */
	public static function enrich( array $links ): array {
		$rows = array();

		foreach ( $links as $link ) {
			$order_id = (int) ( $link['order_id'] ?? 0 );

			if ( $order_id <= 0 ) {
				continue;
			}

			$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;

			if ( ! $order instanceof WC_Order ) {
				$order = null;
			}

			$link['number']   = $order ? (string) $order->get_order_number() : (string) $order_id;
			$link['edit_url'] = $order ? (string) $order->get_edit_order_url() : '';
			$link['items']    = $order ? self::items( $order ) : array();

			$rows[] = $link;
		}

		return $rows;
	}

	/**
	 * Line items of one order, using the stored order item data.
	 *
	 * @return array<int, array{name: string, options: string, quantity: float, total: float}>
	 */
	private static function items( WC_Order $order ): array {
		$items = array();

		foreach ( $order->get_items( 'line_item' ) as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}

			$options = array();

			// The default underscore prefix hides internal meta such as
			// _reduced_stock. include_all keeps variation values even when the
			// product name happens to repeat them.
			foreach ( $item->get_formatted_meta_data( '_', true ) as $meta ) {
				$key   = trim( wp_strip_all_tags( (string) $meta->display_key ) );
				$value = trim( wp_strip_all_tags( (string) $meta->display_value ) );

				if ( '' === $key && '' === $value ) {
					continue;
				}

				$options[] = '' !== $key ? $key . ': ' . $value : $value;
			}

			$items[] = array(
				'name'     => (string) $item->get_name(),
				'options'  => implode( ', ', $options ),
				'quantity' => (float) $item->get_quantity(),
				'total'    => (float) $item->get_total(),
			);
		}

		return $items;
	}
}
