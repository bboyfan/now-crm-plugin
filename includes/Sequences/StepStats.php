<?php
/**
 * Per-step delivery and unique engagement for one sequence.
 *
 * Only `sequence` jobs are counted. Opens and clicks are counted once per
 * accepted job, so repeat pixel loads and repeat link clicks do not inflate
 * the report. The denominator is the number of jobs the transport accepted.
 *
 * @package NowCRM
 */

namespace NowCRM\Sequences;

use NowCRM\Email\EmailJobRepository;
use NowCRM\Support\Schema;

defined( 'ABSPATH' ) || exit;

final class StepStats {

	/**
	 * Aggregate jobs and engagement by the step UUID recorded in the job payload.
	 *
	 * Legacy jobs without a UUID are returned under `legacy`, keyed by the
	 * historical position recorded in job_key. They are never merged into a
	 * current step.
	 *
	 * @param int $sequence_id Sequence post ID.
	 * @return array{steps: array<string, array<string, int>>, legacy: array<int, array<string, int>>, totals: array<string, int>}
	 */
	public function for_sequence( int $sequence_id ): array {
		global $wpdb;

		$steps  = array();
		$legacy = array();
		$totals = $this->empty_counts();
		$prefix = $wpdb->esc_like( 'sequence:' . $sequence_id . ':tracker:' ) . '%';

		if ( $sequence_id > 0 ) {
			$jobs = Schema::table( 'email_jobs' );
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT SUBSTRING_INDEX(job_key, ':step:', -1) AS step_key,
						COALESCE(NULLIF(CASE WHEN JSON_VALID(payload) THEN JSON_UNQUOTE(JSON_EXTRACT(payload, '$.step_uuid')) ELSE '' END, ''), '') AS step_uuid,
						status, COUNT(*) AS total
					FROM {$jobs}
					WHERE kind = 'sequence' AND job_key LIKE %s
					GROUP BY step_key, step_uuid, status",
					$prefix
				),
				ARRAY_A
			);

			foreach ( (array) $rows as $row ) {
				$key    = max( 0, (int) $row['step_key'] );
				$uuid   = (string) $row['step_uuid'];
				$status = (string) $row['status'];
				$count  = (int) $row['total'];

				if ( 'null' === strtolower( $uuid ) ) {
					$uuid = '';
				}

				if ( '' !== $uuid ) {
					if ( ! isset( $steps[ $uuid ] ) ) {
						$steps[ $uuid ] = $this->empty_counts();
					}

					$step_counts = &$steps[ $uuid ];
				} else {
					if ( ! isset( $legacy[ $key ] ) ) {
						$legacy[ $key ] = $this->empty_counts();
					}

					$step_counts = &$legacy[ $key ];
				}

				if ( array_key_exists( $status, $step_counts ) ) {
					$step_counts[ $status ] = $count;
				}

				if ( array_key_exists( $status, $totals ) ) {
					$totals[ $status ] += $count;
				}

				$step_counts['total'] += $count;
				$totals['total']       += $count;

				unset( $step_counts );
			}

			$this->add_engagement( $prefix, $steps, $legacy, $totals );
		}

		ksort( $steps );
		ksort( $legacy );

		return array(
			'steps'  => $steps,
			'legacy' => $legacy,
			'totals' => $totals,
		);
	}

	/**
	 * Add distinct opens and clicks from accepted jobs only.
	 *
	 * @param string                          $prefix Escaped job_key LIKE prefix.
	 * @param array<string, array<string, int>> $steps  UUID buckets, by reference.
	 * @param array<int, array<string, int>>   $legacy Position buckets, by reference.
	 * @param array<string, int>              $totals Totals, by reference.
	 */
	private function add_engagement( string $prefix, array &$steps, array &$legacy, array &$totals ): void {
		global $wpdb;

		$jobs   = Schema::table( 'email_jobs' );
		$events = Schema::table( 'email_events' );
		$accepted = EmailJobRepository::STATUS_ACCEPTED;
		$open     = 'open';
		$click    = 'click';

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT SUBSTRING_INDEX(j.job_key, ':step:', -1) AS step_key,
					COALESCE(NULLIF(CASE WHEN JSON_VALID(j.payload) THEN JSON_UNQUOTE(JSON_EXTRACT(j.payload, '$.step_uuid')) ELSE '' END, ''), '') AS step_uuid,
					COUNT(DISTINCT CASE WHEN e.type = %s THEN e.job_id END) AS opens,
					COUNT(DISTINCT CASE WHEN e.type = %s THEN e.job_id END) AS clicks
				FROM {$jobs} j
				INNER JOIN {$events} e ON e.job_id = j.id
				WHERE j.kind = 'sequence' AND j.job_key LIKE %s AND j.status = %s AND e.type IN (%s, %s)
				GROUP BY step_key, step_uuid",
				$open,
				$click,
				$prefix,
				$accepted,
				$open,
				$click
			),
			ARRAY_A
		);

		foreach ( (array) $rows as $row ) {
			$key  = max( 0, (int) $row['step_key'] );
			$uuid = (string) $row['step_uuid'];

			if ( 'null' === strtolower( $uuid ) ) {
				$uuid = '';
			}

			if ( '' !== $uuid ) {
				if ( ! isset( $steps[ $uuid ] ) ) {
					$steps[ $uuid ] = $this->empty_counts();
				}

				$step_counts = &$steps[ $uuid ];
			} else {
				if ( ! isset( $legacy[ $key ] ) ) {
					$legacy[ $key ] = $this->empty_counts();
				}

				$step_counts = &$legacy[ $key ];
			}

			$step_counts['opens']  = (int) $row['opens'];
			$step_counts['clicks'] = (int) $row['clicks'];

			$totals['opens']  += (int) $row['opens'];
			$totals['clicks'] += (int) $row['clicks'];

			unset( $step_counts );
		}
	}

	/**
	 * Empty counters for one step or the totals row.
	 *
	 * @return array<string, int>
	 */
	private function empty_counts(): array {
		return array(
			'total'      => 0,
			'queued'     => 0,
			'sending'    => 0,
			'accepted'   => 0,
			'failed'     => 0,
			'suppressed' => 0,
			'unknown'    => 0,
			'cancelled'  => 0,
			'opens'      => 0,
			'clicks'     => 0,
		);
	}

	/**
	 * Percentage of accepted jobs with at least one open or click.
	 *
	 * @param array<string, int> $counts Step counts.
	 * @return float|null Null when no job was accepted.
	 */
	public static function rate( array $counts, string $metric ): ?float {
		$accepted = (int) ( $counts['accepted'] ?? 0 );

		if ( $accepted < 1 ) {
			return null;
		}

		return ( (int) ( $counts[ $metric ] ?? 0 ) ) * 100 / $accepted;
	}
}
