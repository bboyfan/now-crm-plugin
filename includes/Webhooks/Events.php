<?php
/**
 * Outbound webhook event allowlist.
 *
 * Event names are the persisted contract used by saved webhooks. Every key here
 * maps to a native NOW CRM action that really fires; unknown names are rejected
 * before a webhook is stored.
 *
 * @package NowCRM
 */

namespace NowCRM\Webhooks;

defined( 'ABSPATH' ) || exit;

final class Events {

	public const CONTACT_CREATED       = 'contact.created';
	public const CONTACT_UPDATED       = 'contact.updated';
	public const CONTACT_STATUS        = 'contact.status_changed';
	public const CONTACT_LIST_ADDED    = 'contact.list_added';
	public const CONTACT_LIST_REMOVED  = 'contact.list_removed';
	public const CONTACT_TAG_ADDED     = 'contact.tag_added';
	public const CONTACT_TAG_REMOVED   = 'contact.tag_removed';
	public const CONTACT_CSV_IMPORTED  = 'contact.csv_imported';
	public const AUTOMATION_TRIGGERED  = 'automation.triggered';
	public const ORDER_COMPLETED       = 'order.completed';
	public const ORDER_REFUNDED        = 'order.refunded';
	public const ORDER_CANCELLED       = 'order.cancelled';
	public const CART_ABANDONED        = 'cart.abandoned';
	public const CART_COMPLETED        = 'cart.completed';
	public const EMAIL_ACCEPTED        = 'email.accepted';
	public const CAMPAIGN_EMAIL_ACCEPTED = 'campaign.email_accepted';

	/**
	 * Every supported event and its admin label.
	 *
	 * @return array<string, string>
	 */
	public static function all(): array {
		return array(
			self::CONTACT_CREATED         => __( '聯絡人建立', 'now-crm' ),
			self::CONTACT_UPDATED         => __( '聯絡人更新', 'now-crm' ),
			self::CONTACT_STATUS          => __( '聯絡人狀態變更', 'now-crm' ),
			self::CONTACT_LIST_ADDED      => __( '加入名單', 'now-crm' ),
			self::CONTACT_LIST_REMOVED    => __( '移出名單', 'now-crm' ),
			self::CONTACT_TAG_ADDED       => __( '加入標籤', 'now-crm' ),
			self::CONTACT_TAG_REMOVED     => __( '移出標籤', 'now-crm' ),
			self::CONTACT_CSV_IMPORTED    => __( 'CSV 匯入聯絡人', 'now-crm' ),
			self::AUTOMATION_TRIGGERED    => __( '自動化觸發', 'now-crm' ),
			self::ORDER_COMPLETED         => __( '訂單完成', 'now-crm' ),
			self::ORDER_REFUNDED          => __( '訂單退款', 'now-crm' ),
			self::ORDER_CANCELLED         => __( '訂單取消', 'now-crm' ),
			self::CART_ABANDONED          => __( '購物車放棄', 'now-crm' ),
			self::CART_COMPLETED          => __( '購物車完成', 'now-crm' ),
			self::EMAIL_ACCEPTED          => __( '郵件已接受', 'now-crm' ),
			self::CAMPAIGN_EMAIL_ACCEPTED => __( '電子報郵件已接受', 'now-crm' ),
		);
	}

	/**
	 * Whether an event name is supported.
	 */
	public static function is_valid( string $event ): bool {
		return isset( self::all()[ $event ] );
	}

	/**
	 * Admin label, or the raw name for stored legacy values.
	 */
	public static function label( string $event ): string {
		return self::all()[ $event ] ?? $event;
	}
}
