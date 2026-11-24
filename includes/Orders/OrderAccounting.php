<?php
/**
 * The single source of truth for what counts as a purchase.
 *
 * @package NowCRM
 */

namespace NowCRM\Orders;

defined( 'ABSPATH' ) || exit;

final class OrderAccounting {

	/**
	 * Statuses where money has been collected and the order is being fulfilled.
	 *
	 * Deliberately narrow: pending/on-hold are unpaid, failed/cancelled never
	 * happened, refunded was returned, checkout-draft is not an order yet.
	 * Custom post-payment statuses (for example the RY convenience-store flow)
	 * must be opted in explicitly through the filter after reconciliation.
	 *
	 * @var string[]
	 */
	public const DEFAULT_COUNTED_STATUSES = array( 'processing', 'completed' );

	/**
	 * Counted statuses for this install.
	 *
	 * @return string[]
	 */
	public static function counted_statuses(): array {
		$statuses = (array) apply_filters( 'nowcrm_counted_order_statuses', self::DEFAULT_COUNTED_STATUSES );

		return array_values( array_unique( array_map( 'strval', $statuses ) ) );
	}

	/**
	 * Whether a WooCommerce status (with or without the wc- prefix) counts.
	 */
	public static function is_counted_status( string $status ): bool {
		$status = str_starts_with( $status, 'wc-' ) ? substr( $status, 3 ) : $status;

		return in_array( $status, self::counted_statuses(), true );
	}

	/**
	 * Whether an order counts toward order_count / total_spent.
	 */
	public static function is_counted_order( \WC_Order $order ): bool {
		return self::is_counted_status( (string) $order->get_status() );
	}

	/**
	 * Order total minus refunds, never negative.
	 */
	public static function net_total( \WC_Order $order ): float {
		$total    = (float) $order->get_total();
		$refunded = abs( (float) $order->get_total_refunded() );

		return max( 0.0, $total - $refunded );
	}

	/**
	 * Build the order link payload from a WooCommerce order.
	 *
	 * @return array<string, mixed>
	 */
	public static function map_order( \WC_Order $order ): array {
		$status = (string) $order->get_status();

		return array(
			'order_id'      => (int) $order->get_id(),
			'billing_email' => (string) $order->get_billing_email(),
			'status'        => $status,
			'total'         => (float) $order->get_total(),
			'net_total'     => self::net_total( $order ),
			'currency'      => (string) $order->get_currency(),
			'is_counted'    => self::is_counted_order( $order ) ? 1 : 0,
			'date_created'  => self::utc( $order->get_date_created() ),
			'date_paid'     => self::utc( $order->get_date_paid() ),
			'date_completed' => self::utc( $order->get_date_completed() ),
		);
	}

	/**
	 * Convert a WooCommerce date to a UTC MySQL timestamp.
	 *
	 * @param \WC_DateTime|null $date WooCommerce date.
	 */
	private static function utc( $date ): ?string {
		if ( ! $date instanceof \DateTimeInterface ) {
			return null;
		}

		$copy = clone $date;

		return $copy->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
	}
}
