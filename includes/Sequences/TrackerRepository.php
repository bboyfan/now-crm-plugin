<?php
/**
 * Sequence trackers: one active enrollment per sequence and contact.
 *
 * `active_key` is 1 while the enrollment runs and NULL after it completes or
 * is cancelled. The unique key `(sequence_id, contact_id, active_key)` therefore
 * allows history plus at most one live enrollment for a contact.
 *
 * @package NowCRM
 */

namespace NowCRM\Sequences;

use NowCRM\Support\Schema;
use WP_Error;

defined( 'ABSPATH' ) || exit;

final class TrackerRepository {

	public const STATUS_ACTIVE    = 'active';
	public const STATUS_COMPLETED = 'completed';
	public const STATUS_CANCELLED = 'cancelled';

	/**
	 * Start a tracker. The next cycle number follows the latest tracker.
	 *
	 * @return int|WP_Error
	 */
	public function create( int $sequence_id, int $contact_id, ?string $now = null ) {
		global $wpdb;

		$table = Schema::table( 'sequence_trackers' );
		$now   = $now ?: gmdate( 'Y-m-d H:i:s' );
		$cycle = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT MAX(cycle) FROM {$table} WHERE sequence_id = %d AND contact_id = %d", $sequence_id, $contact_id )
		) + 1;

		$inserted = $wpdb->insert(
			$table,
			array(
				'sequence_id' => $sequence_id,
				'contact_id'  => $contact_id,
				'status'      => self::STATUS_ACTIVE,
				'cycle'       => $cycle,
				'next_index'  => 0,
				'next_run_at' => null,
				'active_key'  => 1,
				'started_at'  => $now,
				'created_at'  => $now,
				'updated_at'  => $now,
			),
			array( '%d', '%d', '%s', '%d', '%d', '%s', '%d', '%s', '%s', '%s' )
		);

		if ( false === $inserted ) {
			if ( $this->find_active( $sequence_id, $contact_id ) ) {
				return new WP_Error( 'nowcrm_duplicate_enrollment', __( '這個聯絡人已在序列中。', 'now-crm' ) );
			}

			return new WP_Error( 'nowcrm_sequence_tracker_failed', __( '無法建立序列追蹤記錄。', 'now-crm' ) );
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Fetch one tracker.
	 *
	 * @return array<string, mixed>|null
	 */
	public function get( int $id ): ?array {
		global $wpdb;

		$table = Schema::table( 'sequence_trackers' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );

		return $row ? $this->cast( $row ) : null;
	}

	/**
	 * Active tracker for one sequence and contact.
	 *
	 * @return array<string, mixed>|null
	 */
	public function find_active( int $sequence_id, int $contact_id ): ?array {
		global $wpdb;

		$table = Schema::table( 'sequence_trackers' );
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE sequence_id = %d AND contact_id = %d AND status = %s AND active_key = 1 LIMIT 1",
				$sequence_id,
				$contact_id,
				self::STATUS_ACTIVE
			),
			ARRAY_A
		);

		return $row ? $this->cast( $row ) : null;
	}

	/**
	 * Most recent tracker for one sequence and contact.
	 *
	 * @return array<string, mixed>|null
	 */
	public function latest( int $sequence_id, int $contact_id ): ?array {
		global $wpdb;

		$table = Schema::table( 'sequence_trackers' );
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE sequence_id = %d AND contact_id = %d ORDER BY cycle DESC, id DESC LIMIT 1",
				$sequence_id,
				$contact_id
			),
			ARRAY_A
		);

		return $row ? $this->cast( $row ) : null;
	}

	/**
	 * Delete every tracker row of a sequence.
	 */
	public function delete_for_sequence( int $sequence_id ): int {
		global $wpdb;

		return (int) $wpdb->delete(
			Schema::table( 'sequence_trackers' ),
			array( 'sequence_id' => $sequence_id ),
			array( '%d' )
		);
	}

	/**
	 * Trackers for one sequence, newest first.
	 *
	 * @return array{items: array<int, array<string, mixed>>, total: int}
	 */
	public function paginate( int $sequence_id, int $page = 1, int $per_page = 25 ): array {
		global $wpdb;

		$table = Schema::table( 'sequence_trackers' );
		$page  = max( 1, $page );
		$limit = max( 1, min( 100, $per_page ) );
		$total = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE sequence_id = %d", $sequence_id )
		);
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE sequence_id = %d ORDER BY id DESC LIMIT %d OFFSET %d",
				$sequence_id,
				$limit,
				( $page - 1 ) * $limit
			),
			ARRAY_A
		);

		return array(
			'items' => array_map( array( $this, 'cast' ), (array) $rows ),
			'total' => $total,
		);
	}

	/**
	 * Due active trackers.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function due( string $now, int $limit = Engine::BATCH_LIMIT ): array {
		global $wpdb;

		$table = Schema::table( 'sequence_trackers' );
		$limit = max( 1, min( Engine::BATCH_LIMIT, $limit ) );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table}
				WHERE status = %s AND next_run_at IS NOT NULL AND next_run_at <= %s
				ORDER BY next_run_at ASC, id ASC LIMIT %d",
				self::STATUS_ACTIVE,
				$now,
				$limit
			),
			ARRAY_A
		);

		return array_map( array( $this, 'cast' ), (array) $rows );
	}

	/**
	 * Number of due active trackers.
	 */
	public function due_count( string $now ): int {
		global $wpdb;

		$table = Schema::table( 'sequence_trackers' );

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE status = %s AND next_run_at IS NOT NULL AND next_run_at <= %s",
				self::STATUS_ACTIVE,
				$now
			)
		);
	}

	/**
	 * Active trackers for a sequence.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function active_for_sequence( int $sequence_id ): array {
		global $wpdb;

		$table = Schema::table( 'sequence_trackers' );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE sequence_id = %d AND status = %s AND active_key = 1 ORDER BY id ASC",
				$sequence_id,
				self::STATUS_ACTIVE
			),
			ARRAY_A
		);

		return array_map( array( $this, 'cast' ), (array) $rows );
	}

	/**
	 * Move a tracker forward after a delay group was queued.
	 */
	public function advance( int $id, int $next_index, ?string $next_run_at, ?string $last_error = null ): bool {
		global $wpdb;

		return false !== $wpdb->update(
			Schema::table( 'sequence_trackers' ),
			array(
				'next_index'  => max( 0, $next_index ),
				'next_run_at' => $next_run_at,
				'last_error'  => $last_error,
				'updated_at'  => gmdate( 'Y-m-d H:i:s' ),
			),
			array(
				'id'     => $id,
				'status' => self::STATUS_ACTIVE,
			),
			array( '%d', '%s', '%s', '%s' ),
			array( '%d', '%s' )
		);
	}

	/**
	 * Defer a tracker without changing its next step.
	 */
	public function defer( int $id, string $next_run_at ): bool {
		global $wpdb;

		return false !== $wpdb->update(
			Schema::table( 'sequence_trackers' ),
			array(
				'next_run_at' => $next_run_at,
				'updated_at'  => gmdate( 'Y-m-d H:i:s' ),
			),
			array(
				'id'     => $id,
				'status' => self::STATUS_ACTIVE,
			),
			array( '%s', '%s' ),
			array( '%d', '%s' )
		);
	}

	/**
	 * Record a processing error and retry later.
	 */
	public function fail( int $id, string $message, string $next_run_at ): bool {
		global $wpdb;

		return false !== $wpdb->update(
			Schema::table( 'sequence_trackers' ),
			array(
				'last_error'  => substr( $message, 0, 1000 ),
				'next_run_at' => $next_run_at,
				'updated_at'  => gmdate( 'Y-m-d H:i:s' ),
			),
			array(
				'id'     => $id,
				'status' => self::STATUS_ACTIVE,
			),
			array( '%s', '%s', '%s' ),
			array( '%d', '%s' )
		);
	}

	/**
	 * Close a tracker as completed.
	 */
	public function complete( int $id ): bool {
		return $this->close( $id, self::STATUS_COMPLETED );
	}

	/**
	 * Close a tracker as cancelled.
	 */
	public function cancel( int $id ): bool {
		return $this->close( $id, self::STATUS_CANCELLED );
	}

	/**
	 * Bring deferred trackers forward after a resume.
	 */
	public function wake( int $sequence_id ): int {
		global $wpdb;

		$table = Schema::table( 'sequence_trackers' );
		$now   = gmdate( 'Y-m-d H:i:s' );

		return (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table}
				SET next_run_at = %s, updated_at = %s
				WHERE sequence_id = %d AND status = %s AND active_key = 1
					AND (next_run_at IS NULL OR next_run_at > %s)",
				$now,
				$now,
				$sequence_id,
				self::STATUS_ACTIVE,
				$now
			)
		);
	}

	/**
	 * Tracker and sequence-job counts for one sequence.
	 *
	 * @return array{trackers: array<string, int>, jobs: array<string, int>}
	 */
	public function stats( int $sequence_id ): array {
		global $wpdb;

		$trackers = Schema::table( 'sequence_trackers' );
		$jobs     = Schema::table( 'email_jobs' );
		$tracker  = array(
			'active'    => 0,
			'completed' => 0,
			'cancelled' => 0,
			'total'     => 0,
		);

		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT status, COUNT(*) AS total FROM {$trackers} WHERE sequence_id = %d GROUP BY status", $sequence_id ),
			ARRAY_A
		);

		foreach ( (array) $rows as $row ) {
			$status = (string) $row['status'];

			if ( array_key_exists( $status, $tracker ) ) {
				$tracker[ $status ] = (int) $row['total'];
			}

			$tracker['total'] += (int) $row['total'];
		}

		$job_counts = array(
			'queued'     => 0,
			'sending'    => 0,
			'accepted'   => 0,
			'failed'     => 0,
			'suppressed' => 0,
			'unknown'    => 0,
			'cancelled'  => 0,
			'total'      => 0,
		);

		$prefix = 'sequence:' . $sequence_id . ':tracker:%';
		$rows   = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT status, COUNT(*) AS total FROM {$jobs} WHERE kind = 'sequence' AND job_key LIKE %s GROUP BY status",
				$prefix
			),
			ARRAY_A
		);

		foreach ( (array) $rows as $row ) {
			$status = (string) $row['status'];

			if ( array_key_exists( $status, $job_counts ) ) {
				$job_counts[ $status ] = (int) $row['total'];
			}

			$job_counts['total'] += (int) $row['total'];
		}

		return array(
			'trackers' => $tracker,
			'jobs'     => $job_counts,
		);
	}

	/**
	 * Close one active tracker.
	 */
	private function close( int $id, string $status ): bool {
		global $wpdb;

		$now = gmdate( 'Y-m-d H:i:s' );

		return false !== $wpdb->update(
			Schema::table( 'sequence_trackers' ),
			array(
				'status'       => $status,
				'active_key'   => null,
				'next_run_at'  => null,
				'completed_at' => self::STATUS_COMPLETED === $status ? $now : null,
				'cancelled_at' => self::STATUS_CANCELLED === $status ? $now : null,
				'updated_at'   => $now,
			),
			array(
				'id'     => $id,
				'status' => self::STATUS_ACTIVE,
			),
			array( '%s', '%d', '%s', '%s', '%s', '%s' ),
			array( '%d', '%s' )
		);
	}

	/**
	 * Cast a tracker row.
	 *
	 * @param array<string, mixed> $row Raw row.
	 * @return array<string, mixed>
	 */
	private function cast( array $row ): array {
		foreach ( array( 'id', 'sequence_id', 'contact_id', 'cycle', 'next_index' ) as $key ) {
			$row[ $key ] = (int) $row[ $key ];
		}

		$row['active_key'] = null === $row['active_key'] ? null : (int) $row['active_key'];

		return $row;
	}
}
