<?php
/**
 * Reconcile historical WooCommerce billing emails that have no CRM contact.
 * These contacts are transactional, never subscribed: an order is not consent.
 *
 * @package NowCRM
 */

namespace NowCRM\Woo;

use NowCRM\Contacts\ContactRepository;
use NowCRM\Contacts\Status;
use NowCRM\Orders\OrderLinkRepository;

defined( 'ABSPATH' ) || exit;

final class WooReconciler {

	/**
	 * Scan WooCommerce orders and create missing contacts without consent.
	 *
	 * @param array<string, mixed> $options mode, batch, limit.
	 * @return array<string, mixed>
	 */
	public function run( array $options = array() ): array {
		$apply = 'apply' === ( $options['mode'] ?? '' );
		$batch = max( 1, min( 500, (int) ( $options['batch'] ?? 100 ) ) );
		$limit = max( 0, (int) ( $options['limit'] ?? 0 ) );

		$contacts = new ContactRepository();
		$links    = new OrderLinkRepository();
		$sync     = new OrderSync();

		$result = array(
			'ok'              => true,
			'mode'            => $apply ? 'apply' : 'dry_run',
			'orders_scanned'  => 0,
			'without_contact' => 0,
			'created'         => 0,
			'linked'          => 0,
			'skipped'         => 0,
		);

		$page  = 1;
		$seen  = 0;
		$guard = 0;

		do {
			$query = wc_get_orders(
				array(
					'limit'    => $batch,
					'page'     => $page,
					'orderby'  => 'ID',
					'order'    => 'ASC',
					'paginate' => true,
					'type'     => 'shop_order',
				)
			);

			$orders = is_object( $query ) ? $query->orders : array();
			$pages  = is_object( $query ) ? (int) $query->max_num_pages : 0;

			foreach ( $orders as $order ) {
				if ( ! $order instanceof \WC_Order ) {
					continue;
				}

				$email = $contacts->normalize_email( (string) $order->get_billing_email() );

				++$result['orders_scanned'];
				++$seen;

				if ( '' === $email || ! is_email( $email ) || 'checkout-draft' === $order->get_status() ) {
					++$result['skipped'];
					continue;
				}

				$existing = $contacts->find_by_email( $email );

				if ( $existing ) {
					++$result['skipped'];

					if ( ! $links->find_by_order( (int) $order->get_id() ) ) {
						++$result['linked'];

						if ( $apply ) {
							$sync->sync_order( $order );
						}
					}

					continue;
				}

				++$result['without_contact'];

				if ( ! $apply ) {
					continue;
				}

				$created = $contacts->create(
					array(
						'email'      => $email,
						'first_name' => (string) $order->get_billing_first_name(),
						'last_name'  => (string) $order->get_billing_last_name(),
						'phone'      => (string) $order->get_billing_phone(),
						'wp_user_id' => $order->get_customer_id() ? (int) $order->get_customer_id() : null,
						'status'     => Status::TRANSACTIONAL,
						'source'     => 'woocommerce-backfill',
					)
				);

				if ( is_wp_error( $created ) ) {
					++$result['skipped'];
					continue;
				}

				++$result['created'];

				$linked = $sync->sync_order( $order );

				if ( ! is_wp_error( $linked ) ) {
					++$result['linked'];
				}
			}

			++$page;
			++$guard;
		} while ( $page <= $pages && $guard < 1000 && ( 0 === $limit || $seen < $limit ) );

		$result['total_contacts'] = $contacts->count_by_status();

		return $result;
	}
}
