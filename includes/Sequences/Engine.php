<?php
/**
 * Sequence runtime: enrollment, due-step processing, cancellation, and stats.
 *
 * The engine only writes email Jobs. It never calls a transport. The shared
 * Queue worker rechecks the subscribed and suppression gates before sending.
 *
 * @package NowCRM
 */

namespace NowCRM\Sequences;

use NowCRM\Contacts\ContactRepository;
use NowCRM\Contacts\Status;
use NowCRM\Email\EmailJobRepository;
use NowCRM\Email\SuppressionRepository;
use NowCRM\Support\Schema;
use NowCRM\Support\Settings;
use Throwable;
use WP_Error;

defined( 'ABSPATH' ) || exit;

final class Engine {

	public const BATCH_LIMIT  = 100;
	public const MAX_SECONDS  = 50;
	public const DRAIN_DELAY  = 300;

	private SequenceRepository $sequences;
	private AudienceResolver $audience;
	private TrackerRepository $trackers;
	private ContactRepository $contacts;
	private SuppressionRepository $suppressions;
	private EmailJobRepository $jobs;

	public function __construct() {
		$this->sequences    = new SequenceRepository();
		$this->audience     = new AudienceResolver();
		$this->trackers     = new TrackerRepository();
		$this->contacts     = new ContactRepository();
		$this->suppressions = new SuppressionRepository();
		$this->jobs         = new EmailJobRepository();
	}

	/**
	 * Enroll one batch.
	 *
	 * Without explicit IDs the next audience page is used, starting after
	 * `$after_id`. Every call handles at most 100 contacts.
	 *
	 * @param int   $sequence_id  Sequence ID.
	 * @param int[] $contact_ids  Explicit contacts, or empty for the stored audience.
	 * @param int   $after_id     Audience cursor.
	 * @return array<string, mixed>|WP_Error
	 */
	public function enroll( int $sequence_id, array $contact_ids = array(), int $after_id = 0 ) {
		$sequence = $this->sequences->get( $sequence_id );

		if ( ! $sequence ) {
			return new WP_Error( 'nowcrm_sequence_not_found', __( '找不到這個序列。', 'now-crm' ) );
		}

		if ( SequenceRepository::STATUS_ACTIVE !== $sequence['status'] ) {
			return new WP_Error( 'nowcrm_sequence_inactive', __( '只有啟用中的序列可以加入聯絡人。', 'now-crm' ) );
		}

		if ( array() === $sequence['steps'] ) {
			return new WP_Error( 'nowcrm_sequence_steps', __( '這個序列沒有步驟。', 'now-crm' ) );
		}

		$audience = $this->audience->normalize( $sequence['audience'] );

		if ( is_wp_error( $audience ) ) {
			return $audience;
		}

		$explicit    = array_values( array_unique( array_filter( array_map( 'absint', $contact_ids ) ) ) );
		$next_after  = null;

		if ( array() !== $explicit ) {
			$ids = array_slice( $explicit, 0, self::BATCH_LIMIT );
		} else {
			$recipients = $this->audience->recipients( $audience, max( 0, $after_id ), self::BATCH_LIMIT );

			if ( is_wp_error( $recipients ) ) {
				return $recipients;
			}

			$ids = array_map( 'intval', array_column( $recipients, 'contact_id' ) );

			if ( count( $ids ) >= self::BATCH_LIMIT ) {
				$next_after = (int) end( $ids );
			}
		}

		$now    = gmdate( 'Y-m-d H:i:s' );
		$result = array(
			'enrolled'                => 0,
			'queued'                  => 0,
			'duplicates'              => 0,
			'failed'                  => 0,
			'skipped_missing'         => 0,
			'skipped_not_subscribed'  => 0,
			'skipped_suppressed'      => 0,
			'next_after_id'           => $next_after,
		);

		$seen = array();

		foreach ( $ids as $contact_id ) {
			if ( isset( $seen[ $contact_id ] ) ) {
				continue;
			}

			$seen[ $contact_id ] = true;

			$contact = $this->contacts->get( $contact_id );

			if ( ! $contact ) {
				++$result['skipped_missing'];
				continue;
			}

			if ( Status::SUBSCRIBED !== $contact['status'] ) {
				++$result['skipped_not_subscribed'];
				continue;
			}

			if ( $this->suppressions->is_suppressed( (string) $contact['email'] ) ) {
				++$result['skipped_suppressed'];
				continue;
			}

			$latest = $this->trackers->latest( $sequence_id, $contact_id );

			if ( $latest ) {
				$blocked = TrackerRepository::STATUS_ACTIVE === $latest['status']
					|| ( TrackerRepository::STATUS_COMPLETED === $latest['status'] && ! $sequence['allow_reentry'] );

				if ( $blocked ) {
					++$result['duplicates'];
					continue;
				}
			}

			$tracker_id = $this->trackers->create( $sequence_id, $contact_id, $now );

			if ( is_wp_error( $tracker_id ) ) {
				if ( 'nowcrm_duplicate_enrollment' === $tracker_id->get_error_code() ) {
					++$result['duplicates'];
				} else {
					++$result['failed'];
				}

				continue;
			}

			$tracker = $this->trackers->get( (int) $tracker_id );

			if ( ! $tracker ) {
				++$result['failed'];
				continue;
			}

			$queued = $this->queue_group( $sequence, $tracker, 0, $now );

			if ( is_wp_error( $queued ) ) {
				$this->cancel_tracker( $tracker, 'enqueue_failed' );
				++$result['failed'];
				continue;
			}

			++$result['enrolled'];
			$result['queued'] += $queued['queued'];
		}

		return $result;
	}

	/**
	 * Process due trackers.
	 *
	 * @param string|null $now Optional UTC clock for tests and replays.
	 * @return array<string, mixed>
	 */
	public function process_due( ?string $now = null ): array {
		$now    = $now ?: gmdate( 'Y-m-d H:i:s' );
		$result = array(
			'locked'    => false,
			'processed' => 0,
			'queued'    => 0,
			'completed' => 0,
			'cancelled' => 0,
			'waiting'   => 0,
			'skipped'   => 0,
			'failed'    => 0,
			'due'       => 0,
		);

		$lock = $this->acquire_lock();

		if ( ! $lock ) {
			$result['locked'] = true;

			return $result;
		}

		$started = microtime( true );

		try {
			foreach ( $this->trackers->due( $now, self::BATCH_LIMIT ) as $tracker ) {
				if ( microtime( true ) - $started >= self::MAX_SECONDS ) {
					break;
				}

				try {
					$outcome = $this->process_tracker( $tracker, $now );
				} catch ( Throwable $error ) {
					$this->trackers->fail( (int) $tracker['id'], $error->getMessage(), $this->plus( $now, self::DRAIN_DELAY ) );
					$outcome = array( 'outcome' => 'failed', 'queued' => 0 );
				}

				++$result['processed'];

				$key = (string) $outcome['outcome'];

				if ( 'queued' === $key ) {
					$result['queued'] += (int) $outcome['queued'];
				} elseif ( array_key_exists( $key, $result ) ) {
					++$result[ $key ];
				}
			}
		} finally {
			$this->release_lock( $lock );
		}

		$result['due'] = $this->trackers->due_count( $now );

		return $result;
	}

	/**
	 * Cancel active enrollments, for one contact or the whole sequence.
	 *
	 * @return int Number of cancelled trackers.
	 */
	public function cancel( int $sequence_id, int $contact_id = 0 ): int {
		$active = $contact_id > 0
			? array_filter( array( $this->trackers->find_active( $sequence_id, $contact_id ) ) )
			: $this->trackers->active_for_sequence( $sequence_id );

		$cancelled = 0;

		foreach ( $active as $tracker ) {
			$this->cancel_tracker( $tracker, 'sequence_cancelled' );
			++$cancelled;
		}

		return $cancelled;
	}

	/**
	 * Audience size and per-status counts.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public function stats( int $sequence_id ) {
		$sequence = $this->sequences->get( $sequence_id );

		if ( ! $sequence ) {
			return new WP_Error( 'nowcrm_sequence_not_found', __( '找不到這個序列。', 'now-crm' ) );
		}

		$members = $this->sequences->members( $sequence_id );

		return array_merge(
			array(
				'sequence_id' => $sequence_id,
				'status'      => $sequence['status'],
				'members'     => is_wp_error( $members ) ? 0 : $members,
			),
			$this->trackers->stats( $sequence_id )
		);
	}

	/**
	 * Handle one due tracker.
	 *
	 * @param array<string, mixed> $tracker Tracker row.
	 * @param string               $now     UTC clock.
	 * @return array{outcome: string, queued: int}
	 */
	private function process_tracker( array $tracker, string $now ): array {
		$sequence = $this->sequences->get( (int) $tracker['sequence_id'] );

		if ( ! $sequence ) {
			$this->cancel_tracker( $tracker, 'sequence_missing' );

			return array( 'outcome' => 'cancelled', 'queued' => 0 );
		}

		if ( SequenceRepository::STATUS_ACTIVE !== $sequence['status'] ) {
			$this->trackers->defer( (int) $tracker['id'], $this->plus( $now, self::DRAIN_DELAY ) );

			return array( 'outcome' => 'skipped', 'queued' => 0 );
		}

		$contact = $this->contacts->get( (int) $tracker['contact_id'] );

		if ( ! $contact || Status::SUBSCRIBED !== $contact['status'] || $this->suppressions->is_suppressed( (string) $contact['email'] ) ) {
			$this->cancel_tracker( $tracker, $contact ? 'not_subscribed' : 'contact_missing' );

			return array( 'outcome' => 'cancelled', 'queued' => 0 );
		}

		$steps = $sequence['steps'];
		$index = (int) $tracker['next_index'];

		if ( array() === $steps ) {
			$this->cancel_tracker( $tracker, 'sequence_empty' );

			return array( 'outcome' => 'cancelled', 'queued' => 0 );
		}

		if ( $index < count( $steps ) ) {
			$queued = $this->queue_group( $sequence, $tracker, $index, $now );

			if ( is_wp_error( $queued ) ) {
				$this->trackers->fail( (int) $tracker['id'], $queued->get_error_message(), $this->plus( $now, self::DRAIN_DELAY ) );

				return array( 'outcome' => 'failed', 'queued' => 0 );
			}

			return array( 'outcome' => 'queued', 'queued' => $queued['queued'] );
		}

		if ( $this->pending_jobs( (int) $tracker['sequence_id'], (int) $tracker['id'] ) > 0 ) {
			$this->trackers->defer( (int) $tracker['id'], $this->plus( $now, self::DRAIN_DELAY ) );

			return array( 'outcome' => 'waiting', 'queued' => 0 );
		}

		$this->trackers->complete( (int) $tracker['id'] );

		return array( 'outcome' => 'completed', 'queued' => 0 );
	}

	/**
	 * Queue every step in the delay group that starts at $start_index.
	 *
	 * @param array<string, mixed> $sequence Sequence shape.
	 * @param array<string, mixed> $tracker  Tracker row.
	 * @param int                  $start    First step index.
	 * @param string               $now      UTC clock.
	 * @return array{next_index: int, queued: int}|WP_Error
	 */
	private function queue_group( array $sequence, array $tracker, int $start, string $now ) {
		$steps = $sequence['steps'];
		$count = count( $steps );

		if ( $start >= $count ) {
			return array(
				'next_index' => $count,
				'queued'     => 0,
			);
		}

		$delay = (int) ( $steps[ $start ]['delay_seconds'] ?? 0 );
		$end   = $start;

		while ( $end < $count && (int) ( $steps[ $end ]['delay_seconds'] ?? 0 ) === $delay ) {
			++$end;
		}

		$started = strtotime( (string) $tracker['started_at'] . ' UTC' );
		$started = $started ? $started : time();
		$now_ts  = strtotime( $now . ' UTC' );
		$queued  = 0;
		$latest  = $now_ts;

		for ( $index = $start; $index < $end; $index++ ) {
			$target   = $started + (int) ( $steps[ $index ]['delay_seconds'] ?? 0 );
			$due_ts   = max( $now_ts, $target );
			$due      = gmdate( 'Y-m-d H:i:s', $due_ts );
			$latest   = max( $latest, $due_ts );
			$job      = $this->queue_step( $sequence, $tracker, $index, $due );

			if ( is_wp_error( $job ) ) {
				return $job;
			}

			++$queued;
		}

		if ( $end < $count ) {
			$next_due = max( $now_ts, $started + (int) ( $steps[ $end ]['delay_seconds'] ?? 0 ) );
			$next_run = gmdate( 'Y-m-d H:i:s', $next_due );
		} else {
			$next_run = gmdate( 'Y-m-d H:i:s', $latest );
		}

		$this->trackers->advance( (int) $tracker['id'], $end, $next_run );

		return array(
			'next_index' => $end,
			'queued'     => $queued,
		);
	}

	/**
	 * Queue one frozen step email.
	 *
	 * @param array<string, mixed> $sequence Sequence shape.
	 * @param array<string, mixed> $tracker  Tracker row.
	 * @param int                  $index    Step index.
	 * @param string               $due      UTC availability.
	 * @return int|WP_Error Job ID.
	 */
	private function queue_step( array $sequence, array $tracker, int $index, string $due ) {
		$step    = $sequence['steps'][ $index ];
		$contact = $this->contacts->get( (int) $tracker['contact_id'] );

		if ( ! $contact ) {
			return new WP_Error( 'nowcrm_sequence_contact_missing', __( '找不到序列中的聯絡人。', 'now-crm' ) );
		}

		$template = array(
			'mode'         => (string) $step['mode'],
			'content'      => (string) $step['content'],
			'subject'      => (string) $step['subject'],
			'preheader'    => (string) $step['preheader'],
			'from_name'    => Settings::string( 'email_from_name' ),
			'from_address' => Settings::string( 'email_from_address' ),
			'reply_to'     => Settings::string( 'email_reply_to' ),
		);

		$job_key = 'sequence:' . (int) $sequence['id'] . ':tracker:' . (int) $tracker['id'] . ':step:' . $index;

		return $this->jobs->enqueue(
			array(
				'job_key'        => $job_key,
				'kind'           => 'sequence',
				'contact_id'     => (int) $tracker['contact_id'],
				'recipient'      => (string) $contact['email'],
				'recipient_name' => trim( (string) $contact['first_name'] . ' ' . (string) $contact['last_name'] ),
				'subject'        => (string) $step['subject'],
				'payload'        => array(
					'sequence_id' => (int) $sequence['id'],
					'tracker_id'  => (int) $tracker['id'],
					'step_index'  => $index,
					'step_name'   => (string) $step['name'],
					'step_uuid'   => (string) ( $step['uuid'] ?? '' ),
					'tracking'    => array( 'sequence' => Settings::bool( 'sequence_tracking_enabled' ) ),
					'template'    => $template,
				),
				'priority'       => 10,
				'available_at'   => $due,
				'max_attempts'   => Settings::int( 'email_max_attempts' ),
			)
		);
	}

	/**
	 * Count still-pending jobs for one tracker.
	 */
	private function pending_jobs( int $sequence_id, int $tracker_id ): int {
		global $wpdb;

		$table  = Schema::table( 'email_jobs' );
		$prefix = $this->job_prefix( $sequence_id, $tracker_id );

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table}
				WHERE kind = 'sequence' AND job_key LIKE %s AND status IN (%s, %s)",
				$prefix,
				EmailJobRepository::STATUS_QUEUED,
				EmailJobRepository::STATUS_SENDING
			)
		);
	}

	/**
	 * Cancel queued (not claimed) jobs of one tracker.
	 */
	private function cancel_jobs( int $sequence_id, int $tracker_id, string $reason ): int {
		global $wpdb;

		$table = Schema::table( 'email_jobs' );
		$now   = gmdate( 'Y-m-d H:i:s' );

		return (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table}
				SET status = %s, last_error = %s, updated_at = %s
				WHERE kind = 'sequence' AND job_key LIKE %s AND status = %s",
				EmailJobRepository::STATUS_CANCELLED,
				substr( $reason, 0, 1000 ),
				$now,
				$this->job_prefix( $sequence_id, $tracker_id ),
				EmailJobRepository::STATUS_QUEUED
			)
		);
	}

	/**
	 * Job-key prefix for one tracker.
	 */
	private function job_prefix( int $sequence_id, int $tracker_id ): string {
		global $wpdb;

		return $wpdb->esc_like( 'sequence:' . $sequence_id . ':tracker:' . $tracker_id . ':step:' ) . '%';
	}

	/**
	 * Cancel one tracker and its queued jobs.
	 *
	 * @param array<string, mixed> $tracker Tracker row.
	 */
	private function cancel_tracker( array $tracker, string $reason ): void {
		$this->trackers->cancel( (int) $tracker['id'] );
		$this->cancel_jobs( (int) $tracker['sequence_id'], (int) $tracker['id'], $reason );
	}

	/**
	 * Add seconds to a UTC SQL time.
	 */
	private function plus( string $now, int $seconds ): string {
		$timestamp = strtotime( $now . ' UTC' );

		return gmdate( 'Y-m-d H:i:s', ( $timestamp ? $timestamp : time() ) + $seconds );
	}

	/**
	 * Serialize workers with a connection-scoped lock.
	 */
	private function acquire_lock(): ?string {
		global $wpdb;

		$key = 'nowcrm-sequences-' . md5( DB_NAME . ':' . $wpdb->prefix );

		return '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $key ) )
			? $key
			: null;
	}

	/**
	 * Release the worker lock.
	 */
	private function release_lock( string $key ): void {
		global $wpdb;

		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $key ) );
	}
}
