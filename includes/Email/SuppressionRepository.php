<?php
/**
 * Global email suppression list. Status alone is not enough: a hard bounce or
 * complaint must block an address even when no contact row exists yet.
 *
 * @package NowCRM
 */

namespace NowCRM\Email;

use NowCRM\Support\Schema;

defined( 'ABSPATH' ) || exit;

final class SuppressionRepository {

	public const REASON_UNSUBSCRIBED = 'unsubscribed';
	public const REASON_BOUNCED      = 'bounced';
	public const REASON_COMPLAINED   = 'complained';
	public const REASON_MANUAL       = 'manual';

	/**
	 * Normalize an address the same way contacts do.
	 */
	public function normalize( string $email ): string {
		return mb_strtolower( trim( $email ), 'UTF-8' );
	}

	/**
	 * Add or refresh a suppression entry.
	 *
	 * @param string $email      Address.
	 * @param string $reason     unsubscribed|bounced|complained|manual.
	 * @param string $source     Where the suppression came from.
	 * @param int|null $contact_id Optional contact.
	 */
	public function add( string $email, string $reason, string $source = '', ?int $contact_id = null ): bool {
		global $wpdb;

		$normalized = $this->normalize( $email );

		if ( '' === $normalized || ! is_email( $normalized ) ) {
			return false;
		}

		$now   = gmdate( 'Y-m-d H:i:s' );
		$table = Schema::table( 'suppressions' );

		$sql = "INSERT INTO {$table} (email_normalized, reason, source, contact_id, created_at, updated_at)
			VALUES (%s, %s, %s, %d, %s, %s)
			ON DUPLICATE KEY UPDATE
				reason = VALUES(reason),
				source = VALUES(source),
				contact_id = COALESCE(VALUES(contact_id), contact_id),
				updated_at = VALUES(updated_at)";

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- table name is trusted and every value uses a prepare() placeholder.
		$result = $wpdb->query(
			$wpdb->prepare(
				$sql,
				$normalized,
				substr( sanitize_key( $reason ), 0, 30 ),
				substr( sanitize_text_field( $source ), 0, 191 ),
				$contact_id ? absint( $contact_id ) : 0,
				$now,
				$now
			)
		);
		// phpcs:enable

		return false !== $result;
	}

	/**
	 * Remove a suppression. Only used for explicit operator corrections.
	 */
	public function remove( string $email ): bool {
		global $wpdb;

		return (bool) $wpdb->delete(
			Schema::table( 'suppressions' ),
			array( 'email_normalized' => $this->normalize( $email ) ),
			array( '%s' )
		);
	}

	/**
	 * Whether the address may not receive marketing mail.
	 */
	public function is_suppressed( string $email ): bool {
		global $wpdb;

		$table = Schema::table( 'suppressions' );
		$found = $wpdb->get_var(
			$wpdb->prepare( "SELECT id FROM {$table} WHERE email_normalized = %s", $this->normalize( $email ) )
		);

		return null !== $found;
	}

	/**
	 * Fetch one entry.
	 *
	 * @return array<string, mixed>|null
	 */
	public function find( string $email ): ?array {
		global $wpdb;

		$table = Schema::table( 'suppressions' );

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE email_normalized = %s", $this->normalize( $email ) ),
			ARRAY_A
		);

		return $row ?: null;
	}

	/**
	 * Recent entries.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function recent( int $limit = 100 ): array {
		global $wpdb;

		$table = Schema::table( 'suppressions' );
		$limit = max( 1, min( 500, $limit ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- trusted table.
		return (array) $wpdb->get_results( "SELECT * FROM {$table} ORDER BY updated_at DESC LIMIT {$limit}", ARRAY_A );
	}

	/**
	 * Count by reason.
	 *
	 * @return array<string, int>
	 */
	public function counts(): array {
		global $wpdb;

		$table = Schema::table( 'suppressions' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- trusted table.
		$rows = (array) $wpdb->get_results( "SELECT reason, COUNT(*) AS total FROM {$table} GROUP BY reason", ARRAY_A );

		$counts = array();

		foreach ( $rows as $row ) {
			$counts[ (string) $row['reason'] ] = (int) $row['total'];
		}

		return $counts;
	}
}
