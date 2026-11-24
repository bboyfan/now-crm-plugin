<?php
/**
 * Order ↔ contact links. One row per WooCommerce order, ever.
 *
 * @package NowCRM
 */

namespace NowCRM\Orders;

use NowCRM\Support\Schema;
use WP_Error;

defined( 'ABSPATH' ) || exit;

final class OrderLinkRepository {

	/**
	 * Insert or update the link of an order. Idempotent by the order_id unique key.
	 *
	 * @param array<string, mixed> $data order_id, contact_id, billing_email, status, total, net_total, currency, is_counted, marketing_optin, date_created, date_paid, date_completed.
	 * @return bool|WP_Error
	 */
	public function upsert( array $data ) {
		global $wpdb;

		$order_id   = absint( $data['order_id'] ?? 0 );
		$contact_id = absint( $data['contact_id'] ?? 0 );

		if ( $order_id <= 0 || $contact_id <= 0 ) {
			return new WP_Error( 'nowcrm_invalid_link', __( '訂單與聯絡人關聯資料不完整。', 'now-crm' ) );
		}

		$table = Schema::table( 'order_links' );

		$optin  = $data['marketing_optin'] ?? null;
		$dates  = array(
			'date_created'   => $data['date_created'] ?? null,
			'date_paid'      => $data['date_paid'] ?? null,
			'date_completed' => $data['date_completed'] ?? null,
		);

		// Missing dates and consent must be stored as SQL NULL: binding null or ''
		// through %s produces a zero date, which MySQL in strict mode rejects.
		$optin_sql = null === $optin ? 'NULL' : '%d';
		$date_sql  = array();

		foreach ( $dates as $date ) {
			$date_sql[] = $date ? '%s' : 'NULL';
		}

		$sql = "INSERT INTO {$table}
			(order_id, contact_id, billing_email, status, total, net_total, currency, is_counted, marketing_optin, date_created, date_paid, date_completed, synced_at, sync_version)
			VALUES (%d, %d, %s, %s, %f, %f, %s, %d, {$optin_sql}, {$date_sql[0]}, {$date_sql[1]}, {$date_sql[2]}, %s, 1)
			ON DUPLICATE KEY UPDATE
				contact_id = VALUES(contact_id),
				billing_email = VALUES(billing_email),
				status = VALUES(status),
				total = VALUES(total),
				net_total = VALUES(net_total),
				currency = VALUES(currency),
				is_counted = VALUES(is_counted),
				marketing_optin = VALUES(marketing_optin),
				date_created = VALUES(date_created),
				date_paid = VALUES(date_paid),
				date_completed = VALUES(date_completed),
				synced_at = VALUES(synced_at),
				sync_version = sync_version + 1";

		$params = array(
			$order_id,
			$contact_id,
			substr( (string) ( $data['billing_email'] ?? '' ), 0, 191 ),
			substr( (string) ( $data['status'] ?? '' ), 0, 50 ),
			(float) ( $data['total'] ?? 0 ),
			(float) ( $data['net_total'] ?? 0 ),
			substr( (string) ( $data['currency'] ?? '' ), 0, 3 ),
			! empty( $data['is_counted'] ) ? 1 : 0,
		);

		if ( null !== $optin ) {
			$params[] = (int) $optin;
		}

		foreach ( $dates as $date ) {
			if ( $date ) {
				$params[] = $date;
			}
		}

		$params[] = gmdate( 'Y-m-d H:i:s' );

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- table name and NULL tokens are generated above, values use placeholders.
		$result = $wpdb->query( $wpdb->prepare( $sql, $params ) );
		// phpcs:enable

		if ( false === $result ) {
			return new WP_Error( 'nowcrm_db_error', $wpdb->last_error ?: __( '無法寫入訂單關聯。', 'now-crm' ) );
		}

		return true;
	}

	/**
	 * Link of one order.
	 *
	 * @return array<string, mixed>|null
	 */
	public function find_by_order( int $order_id ): ?array {
		global $wpdb;

		$table = Schema::table( 'order_links' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is trusted.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE order_id = %d", $order_id ), ARRAY_A );

		return $row ? $this->cast( $row ) : null;
	}

	/**
	 * Orders of one contact, newest first.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function links_for_contact( int $contact_id, int $limit = 50 ): array {
		global $wpdb;

		$limit = max( 1, min( 200, $limit ) );
		$table = Schema::table( 'order_links' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is trusted.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE contact_id = %d ORDER BY date_created DESC, order_id DESC LIMIT %d",
				$contact_id,
				$limit
			),
			ARRAY_A
		);

		return array_map( array( $this, 'cast' ), (array) $rows );
	}

	/**
	 * Remove the link of an order (permanent deletion path).
	 */
	public function delete_by_order( int $order_id ): bool {
		global $wpdb;

		return (bool) $wpdb->delete( Schema::table( 'order_links' ), array( 'order_id' => $order_id ), array( '%d' ) );
	}

	/**
	 * Count all links, optionally only counted ones.
	 */
	public function count( bool $counted_only = false ): int {
		global $wpdb;

		$table = Schema::table( 'order_links' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is trusted.
		$sql = $counted_only
			? "SELECT COUNT(*) FROM {$table} WHERE is_counted = 1"
			: "SELECT COUNT(*) FROM {$table}";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name is trusted and the query has no user input.
		return (int) $wpdb->get_var( $sql );
	}

	/**
	 * Number of distinct contacts that have at least one counted order.
	 */
	public function count_customers(): int {
		global $wpdb;

		$table = Schema::table( 'order_links' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is trusted.
		return (int) $wpdb->get_var( "SELECT COUNT(DISTINCT contact_id) FROM {$table} WHERE is_counted = 1" );
	}

	/**
	 * Sum of counted net totals.
	 */
	public function total_spent(): float {
		global $wpdb;

		$table = Schema::table( 'order_links' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is trusted.
		return (float) $wpdb->get_var( "SELECT COALESCE(SUM(net_total), 0) FROM {$table} WHERE is_counted = 1" );
	}

	/**
	 * Cast numeric columns.
	 *
	 * @param array<string, mixed> $row Raw row.
	 * @return array<string, mixed>
	 */
	public function cast( array $row ): array {
		$row['id']              = (int) $row['id'];
		$row['order_id']        = (int) $row['order_id'];
		$row['contact_id']      = (int) $row['contact_id'];
		$row['total']           = (float) $row['total'];
		$row['net_total']       = (float) $row['net_total'];
		$row['is_counted']      = (int) $row['is_counted'];
		$row['sync_version']    = (int) $row['sync_version'];
		$row['marketing_optin'] = null === $row['marketing_optin'] ? null : (int) $row['marketing_optin'];

		foreach ( array( 'date_created', 'date_paid', 'date_completed' ) as $column ) {
			if ( empty( $row[ $column ] ) || str_starts_with( (string) $row[ $column ], '0000-00-00' ) ) {
				$row[ $column ] = null;
			}
		}

		return $row;
	}
}
