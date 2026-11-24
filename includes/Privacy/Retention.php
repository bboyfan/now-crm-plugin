<?php
/**
 * Audit retention for historical email payloads.
 *
 * Policy contract:
 * - Rows are never deleted. Only payload bodies are cleared.
 * - Jobs are eligible when they are terminal (accepted or cancelled), older
 *   than the cutoff, and still carry a payload. Queued, sending, failed,
 *   suppressed and unknown jobs stay untouched because they can still be
 *   retried or resolved.
 * - SES events are eligible only when processed and older than the cutoff.
 *   Pending or failed events keep their raw payload for diagnosis and replay.
 * - Automation and cart jobs keep the linkage keys that read paths use
 *   (automation_id, run_id, step_id, cart_id, cart_version), so run details and
 *   admin links still work. The reduced JSON starts with the reserved
 *   `_nowcrm_retained` marker; later previews and batches skip it by prefix
 *   predicate, so linked rows never block newer plain rows. The frozen body
 *   snapshot and signed recovery URL are removed, and the existing preview
 *   screen reports the body as unavailable.
 * - email_events, campaign metrics, job keys, recipients and token relations
 *   are immutable audit records and are not changed. Every Tracking::record()
 *   call leaves meta empty and no read path selects it, but the column stays
 *   with the event row as audit policy.
 * - Preview and apply use the same WHERE clauses and the same cutoff formula.
 *
 * @package NowCRM
 */

namespace NowCRM\Privacy;

use NowCRM\Support\Schema;

defined( 'ABSPATH' ) || exit;

final class Retention {

	public const DEFAULT_DAYS = 90;
	public const MIN_DAYS     = 30;
	public const DEFAULT_BATCH = 200;
	public const MAX_BATCH    = 500;

	/**
	 * Payload keys that read paths still need after a job becomes terminal.
	 *
	 * @var string[]
	 */
	private const JOB_PAYLOAD_KEYS = array( 'automation_id', 'run_id', 'step_id', 'cart_id', 'cart_version' );

	/**
	 * Canonical first key of a reduced payload. Its presence marks the row as
	 * already processed so later batches skip it and move on to newer rows.
	 */
	private const RETENTION_MARKER = '_nowcrm_retained';

	/**
	 * Count eligible payloads without writing.
	 *
	 * @param int $days Retention window in days.
	 * @return array{cutoff: string, jobs: int, ses: int}
	 */
	public function preview( int $days ): array {
		$cutoff = $this->cutoff( $days );

		return array(
			'cutoff' => $cutoff,
			'jobs'   => $this->count_jobs( $cutoff ),
			'ses'    => $this->count_ses( $cutoff ),
		);
	}

	/**
	 * Clear one bounded batch of eligible payloads.
	 *
	 * @param int $days  Retention window in days.
	 * @param int $batch Maximum rows per store in this run.
	 * @return array{cutoff: string, jobs: array{eligible: int, cleared: int}, ses: array{eligible: int, cleared: int}}
	 */
	public function apply( int $days, int $batch = self::DEFAULT_BATCH ): array {
		global $wpdb;

		$cutoff = $this->cutoff( $days );
		$batch  = max( 1, min( self::MAX_BATCH, $batch ) );

		$result = array(
			'cutoff' => $cutoff,
			'jobs'   => array(
				'eligible' => $this->count_jobs( $cutoff ),
				'cleared'  => 0,
			),
			'ses'    => array(
				'eligible' => $this->count_ses( $cutoff ),
				'cleared'  => 0,
			),
		);

		$jobs = Schema::table( 'email_jobs' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name is trusted; clauses are fixed and values use placeholders.
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, payload FROM {$jobs}
				 WHERE status IN (%s, %s) AND updated_at < %s AND payload IS NOT NULL AND payload NOT LIKE %s
				 ORDER BY id ASC LIMIT %d",
				'accepted',
				'cancelled',
				$cutoff,
				$this->reduced_pattern(),
				$batch
			),
			ARRAY_A
		);

		foreach ( $rows as $row ) {
			if ( $this->clear_job( (int) $row['id'], (string) $row['payload'], $cutoff ) ) {
				++$result['jobs']['cleared'];
			}
		}

		$ses = Schema::table( 'ses_events' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name is trusted; clauses are fixed and values use placeholders.
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id FROM {$ses}
				 WHERE processed = 1 AND received_at < %s AND payload IS NOT NULL
				 ORDER BY id ASC LIMIT %d",
				$cutoff,
				$batch
			),
			ARRAY_A
		);

		foreach ( $rows as $row ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name is trusted; values use placeholders.
			$updated = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$ses} SET payload = NULL
					 WHERE id = %d AND processed = 1 AND received_at < %s AND payload IS NOT NULL",
					(int) $row['id'],
					$cutoff
				)
			);

			if ( $updated ) {
				++$result['ses']['cleared'];
			}
		}

		return $result;
	}

	/**
	 * UTC cutoff shared by preview and apply.
	 */
	public function cutoff( int $days ): string {
		return gmdate( 'Y-m-d H:i:s', time() - max( 1, $days ) * DAY_IN_SECONDS );
	}

	/**
	 * Parse the CLI --apply flag without truthy string coercion.
	 *
	 * @param mixed $value Absent value is false; WP-CLI passes a string for
	 *                     `--apply=<value>` and true for a bare `--apply`.
	 * @return bool|null True to apply, false for preview, null when malformed.
	 */
	public static function parse_apply_flag( $value ): ?bool {
		if ( true === $value ) {
			return true;
		}

		if ( false === $value ) {
			return false;
		}

		if ( is_string( $value ) ) {
			$flag = strtolower( trim( $value ) );

			if ( 'true' === $flag ) {
				return true;
			}

			if ( 'false' === $flag ) {
				return false;
			}
		}

		return null;
	}

	/**
	 * Count terminal historical jobs that still carry a payload.
	 */
	private function count_jobs( string $cutoff ): int {
		global $wpdb;

		$table = Schema::table( 'email_jobs' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name is trusted; values use placeholders.
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table}
				 WHERE status IN (%s, %s) AND updated_at < %s AND payload IS NOT NULL AND payload NOT LIKE %s",
				'accepted',
				'cancelled',
				$cutoff,
				$this->reduced_pattern()
			)
		);
	}

	/**
	 * Count processed historical SES events that still carry a payload.
	 */
	private function count_ses( string $cutoff ): int {
		global $wpdb;

		$table = Schema::table( 'ses_events' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name is trusted; values use placeholders.
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table}
				 WHERE processed = 1 AND received_at < %s AND payload IS NOT NULL",
				$cutoff
			)
		);
	}

	/**
	 * Clear one job payload while reasserting the eligibility clause.
	 *
	 * @param int    $id      Job row ID.
	 * @param string $payload Stored JSON payload.
	 * @param string $cutoff  UTC cutoff.
	 */
	private function clear_job( int $id, string $payload, string $cutoff ): bool {
		global $wpdb;

		$table = Schema::table( 'email_jobs' );
		$kept  = $this->keep_job_links( $payload );

		if ( null === $kept ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name is trusted; values use placeholders.
			$updated = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$table} SET payload = NULL
					 WHERE id = %d AND status IN (%s, %s) AND updated_at < %s AND payload IS NOT NULL AND payload NOT LIKE %s",
					$id,
					'accepted',
					'cancelled',
					$cutoff,
					$this->reduced_pattern()
				)
			);
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name is trusted; values use placeholders.
			$updated = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$table} SET payload = %s
					 WHERE id = %d AND status IN (%s, %s) AND updated_at < %s AND payload IS NOT NULL AND payload NOT LIKE %s",
					$kept,
					$id,
					'accepted',
					'cancelled',
					$cutoff,
					$this->reduced_pattern()
				)
			);
		}

		return (bool) $updated;
	}

	/**
	 * Escape-safe prefix pattern of a reduced payload.
	 *
	 * The marker is the first top-level key. A LIKE prefix therefore cannot be
	 * triggered by the same text nested inside a template body.
	 */
	private function reduced_pattern(): string {
		global $wpdb;

		return $wpdb->esc_like( '{"' . self::RETENTION_MARKER . '":1,' ) . '%';
	}

	/**
	 * Reduce a job payload to the linkage keys read paths still use.
	 *
	 * @param string $payload Stored JSON payload.
	 * @return string|null JSON for link-only payloads, null when nothing remains.
	 */
	private function keep_job_links( string $payload ): ?string {
		$decoded = json_decode( $payload, true );

		if ( ! is_array( $decoded ) ) {
			return null;
		}

		$kept = array( self::RETENTION_MARKER => 1 );

		foreach ( self::JOB_PAYLOAD_KEYS as $key ) {
			if ( array_key_exists( $key, $decoded ) ) {
				$kept[ $key ] = $decoded[ $key ];
			}
		}

		if ( 1 === count( $kept ) ) {
			return null;
		}

		$json = wp_json_encode( $kept );

		return is_string( $json ) ? $json : null;
	}
}
