<?php
/**
 * Append-only contact status history.
 *
 * @package NowCRM
 */

namespace NowCRM\Contacts;

use NowCRM\Support\Schema;

defined( 'ABSPATH' ) || exit;

final class StatusHistoryRepository {

	/**
	 * Append one status event.
	 *
	 * @param int         $contact_id Contact ID.
	 * @param string|null $old        Previous status (null on creation).
	 * @param string      $new        New status.
	 * @param string      $source     Machine readable origin, e.g. woocommerce:new-order.
	 * @param string      $note       Optional human note.
	 * @param int|null    $actor_id   WP user ID responsible, when applicable.
	 */
	public function record(
		int $contact_id,
		?string $old,
		string $new,
		string $source,
		string $note = '',
		?int $actor_id = null
	): int {
		global $wpdb;

		$inserted = $wpdb->insert(
			Schema::table( 'contact_status_events' ),
			array(
				'contact_id'  => $contact_id,
				'old_status'  => $old,
				'new_status'  => $new,
				'source'      => substr( sanitize_text_field( $source ), 0, 191 ),
				'note'        => sanitize_textarea_field( $note ),
				'actor_id'    => $actor_id,
				'occurred_at' => gmdate( 'Y-m-d H:i:s' ),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%d', '%s' )
		);

		return $inserted ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * History for a contact, newest first.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function for_contact( int $contact_id, int $limit = 50 ): array {
		global $wpdb;

		$limit = max( 1, min( 500, $limit ) );
		$table = Schema::table( 'contact_status_events' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is built from $wpdb->prefix.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE contact_id = %d ORDER BY occurred_at DESC, id DESC LIMIT %d",
				$contact_id,
				$limit
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Delete the history of a contact (used when the contact is deleted).
	 */
	public function delete_for_contact( int $contact_id ): void {
		global $wpdb;

		$wpdb->delete( Schema::table( 'contact_status_events' ), array( 'contact_id' => $contact_id ), array( '%d' ) );
	}
}
