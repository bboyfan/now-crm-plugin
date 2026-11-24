<?php
/**
 * Built-in segment definitions.
 *
 * A system segment has no stored row. It names a fixed predicate over the
 * native contact tables, so its membership is always live and it is never
 * edited or deleted. Every definition uses evidence that exists in this
 * plugin: the contact status column, or a counted order link. There is no
 * WooCommerce customer-lookup or provider predicate here.
 *
 * @package NowCRM
 */

namespace NowCRM\Segments;

defined( 'ABSPATH' ) || exit;

final class SystemSegments {

	public const ALL_CONTACTS = 'all-contacts';
	public const SUBSCRIBED   = 'subscribed';
	public const CUSTOMERS    = 'customers';

	/**
	 * Every system segment, keyed by key.
	 *
	 * `predicate` is the resolver contract: all, subscribed, or customers.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function all(): array {
		return array(
			self::ALL_CONTACTS => self::definition(
				self::ALL_CONTACTS,
				__( '所有聯絡人', 'now-crm' ),
				__( '通訊錄內所有聯絡人，不論訂閱狀態。', 'now-crm' ),
				'all'
			),
			self::SUBSCRIBED   => self::definition(
				self::SUBSCRIBED,
				__( '已訂閱聯絡人', 'now-crm' ),
				__( '狀態為已訂閱的聯絡人。', 'now-crm' ),
				'subscribed'
			),
			self::CUSTOMERS    => self::definition(
				self::CUSTOMERS,
				__( '已下單顧客', 'now-crm' ),
				__( '至少有一筆計入營收的 WooCommerce 訂單的聯絡人。', 'now-crm' ),
				'customers'
			),
		);
	}

	/**
	 * One system segment by key.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function get( string $key ): ?array {
		return self::all()[ $key ] ?? null;
	}

	/**
	 * Whether a key belongs to a system segment.
	 */
	public static function is_key( string $key ): bool {
		return isset( self::all()[ $key ] );
	}

	/**
	 * Build the public shape shared with stored segments.
	 *
	 * @return array<string, mixed>
	 */
	private static function definition( string $key, string $name, string $description, string $predicate ): array {
		return array(
			'id'          => 0,
			'key'         => $key,
			'ref'         => 'system:' . $key,
			'name'        => $name,
			'description' => $description,
			'status'      => 'active',
			'is_system'   => true,
			'predicate'   => $predicate,
			'filters'     => null,
			'created_at'  => null,
			'updated_at'  => null,
		);
	}
}
