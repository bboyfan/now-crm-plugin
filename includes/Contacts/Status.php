<?php
/**
 * Contact status vocabulary.
 *
 * @package NowCRM
 */

namespace NowCRM\Contacts;

defined( 'ABSPATH' ) || exit;

final class Status {

	public const SUBSCRIBED    = 'subscribed';
	public const PENDING       = 'pending';
	public const UNSUBSCRIBED  = 'unsubscribed';
	public const TRANSACTIONAL = 'transactional';
	public const BOUNCED       = 'bounced';
	public const COMPLAINED    = 'complained';
	public const SPAMMED       = 'spammed';

	/**
	 * Every allowed status.
	 *
	 * @return string[]
	 */
	public static function all(): array {
		return array(
			self::SUBSCRIBED,
			self::PENDING,
			self::UNSUBSCRIBED,
			self::TRANSACTIONAL,
			self::BOUNCED,
			self::COMPLAINED,
			self::SPAMMED,
		);
	}

	/**
	 * Validate a status value.
	 */
	public static function is_valid( string $status ): bool {
		return in_array( $status, self::all(), true );
	}

	/**
	 * Human readable label.
	 */
	public static function label( string $status ): string {
		$labels = array(
			self::SUBSCRIBED    => __( '已訂閱', 'now-crm' ),
			self::PENDING       => __( '待確認', 'now-crm' ),
			self::UNSUBSCRIBED  => __( '已退訂', 'now-crm' ),
			self::TRANSACTIONAL => __( '僅交易信', 'now-crm' ),
			self::BOUNCED       => __( '退信', 'now-crm' ),
			self::COMPLAINED    => __( '投訴', 'now-crm' ),
			self::SPAMMED       => __( '垃圾信投訴', 'now-crm' ),
		);

		return $labels[ $status ] ?? $status;
	}

	/**
	 * Statuses that must never be promoted back to a marketing subscription by
	 * an automated sync. Only a human may lift them. Pending is excluded: it
	 * is non-marketing because it is not subscribed, but a confirmation link
	 * may still promote it.
	 */
	public static function is_suppressed( string $status ): bool {
		return in_array(
			$status,
			array( self::UNSUBSCRIBED, self::BOUNCED, self::COMPLAINED, self::SPAMMED ),
			true
		);
	}

	/**
	 * Whether a contact may receive an abandoned-cart reminder.
	 *
	 * This is the cart-specific policy, not the marketing gate: a subscribed
	 * contact opted in, and a transactional contact exists because it supplied
	 * the cart address. Every other status was explicitly suppressed and never
	 * receives cart mail, even through a forced run.
	 */
	public static function allows_cart_email( string $status ): bool {
		return in_array( $status, array( self::SUBSCRIBED, self::TRANSACTIONAL ), true );
	}

	/**
	 * Default status for a contact created by a WooCommerce order.
	 */
	public static function for_new_woo_contact( bool $auto_subscribe ): string {
		return $auto_subscribe ? self::SUBSCRIBED : self::TRANSACTIONAL;
	}
}
