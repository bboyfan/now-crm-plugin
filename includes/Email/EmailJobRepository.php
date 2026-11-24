<?php
/**
 * Persistent email queue. One row per recipient job, deduplicated by job_key.
 *
 * Action Scheduler only wakes the worker; this table is the source of truth.
 *
 * @package NowCRM
 */

namespace NowCRM\Email;

use NowCRM\Campaign\Audience;
use NowCRM\Campaign\Campaigns;
use NowCRM\Support\Schema;
use NowCRM\Support\Settings;
use WP_Error;

defined( 'ABSPATH' ) || exit;

final class EmailJobRepository {

	public const STATUS_QUEUED     = 'queued';
	public const STATUS_SENDING    = 'sending';
	public const STATUS_ACCEPTED   = 'accepted';
	public const STATUS_FAILED     = 'failed';
	public const STATUS_SUPPRESSED = 'suppressed';
	public const STATUS_UNKNOWN    = 'unknown';
	public const STATUS_CANCELLED  = 'cancelled';

	public const DELIVERY_DEFERRED  = 'deferred';
	public const DELIVERY_DELIVERED = 'delivered';
	public const DELIVERY_BOUNCED   = 'bounced';
	public const DELIVERY_COMPLAINED = 'complained';

	/**
	 * Insert a job once. A duplicate job_key returns the existing row ID and
	 * never rewinds a terminal status.
	 *
	 * @param array<string, mixed> $job job_key, kind, recipient, subject, campaign_id, cart_id, contact_id, recipient_name, payload, priority, available_at, max_attempts.
	 * @return int|WP_Error
	 */
	public function enqueue( array $job ) {
		global $wpdb;

		$job_key   = substr( (string) ( $job['job_key'] ?? '' ), 0, 191 );
		$recipient = (string) ( $job['recipient'] ?? '' );

		if ( '' === $job_key || ! is_email( $recipient ) ) {
			return new WP_Error( 'nowcrm_invalid_job', __( 'Email Job 資料不完整。', 'now-crm' ) );
		}

		$available = $this->utc( $job['available_at'] ?? null );
		$now       = gmdate( 'Y-m-d H:i:s' );
		$payload   = $job['payload'] ?? array();

		// The configured attempt budget is the default for every new job. A
		// caller can still pass an explicit value for an intentional override.
		$max_attempts = (int) ( $job['max_attempts'] ?? Settings::int( 'email_max_attempts' ) );
		$max_attempts = max( 1, min( 10, $max_attempts > 0 ? $max_attempts : 3 ) );

		$table = Schema::table( 'email_jobs' );

		$campaign_id = absint( $job['campaign_id'] ?? 0 );
		$cart_id     = absint( $job['cart_id'] ?? 0 );
		$contact_id  = absint( $job['contact_id'] ?? 0 );
		$run_id      = absint( $job['run_id'] ?? 0 );
		$source_key  = substr( (string) ( $job['source_key'] ?? '' ), 0, 191 );

		$sql = "INSERT INTO {$table}
			(job_key, kind, campaign_id, cart_id, contact_id, run_id, source_key, recipient, recipient_name, subject, payload, status, priority, available_at, max_attempts, created_at, updated_at)
			VALUES (%s, %s, " . ( $campaign_id ? '%d' : 'NULL' ) . ', ' . ( $cart_id ? '%d' : 'NULL' ) . ', ' . ( $contact_id ? '%d' : 'NULL' ) . ', ' . ( $run_id ? '%d' : 'NULL' ) . ', ' . ( '' !== $source_key ? '%s' : 'NULL' ) . ", %s, %s, %s, %s, %s, %d, %s, %d, %s, %s)
			ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)";

		$params = array(
			$job_key,
			substr( sanitize_key( (string) ( $job['kind'] ?? 'campaign' ) ), 0, 30 ),
			substr( (string) $recipient, 0, 191 ),
			substr( sanitize_text_field( (string) ( $job['recipient_name'] ?? '' ) ), 0, 191 ),
			substr( sanitize_text_field( (string) ( $job['subject'] ?? '' ) ), 0, 255 ),
			wp_json_encode( $payload ),
			self::STATUS_QUEUED,
			(int) ( $job['priority'] ?? 10 ),
			$available,
			$max_attempts,
			$now,
			$now,
		);

		if ( $campaign_id ) {
			array_splice( $params, 2, 0, array( $campaign_id ) );
		}

		if ( $cart_id ) {
			$offset = $campaign_id ? 3 : 2;
			array_splice( $params, $offset, 0, array( $cart_id ) );
		}

		if ( $contact_id ) {
			$offset = 2 + ( $campaign_id ? 1 : 0 ) + ( $cart_id ? 1 : 0 );
			array_splice( $params, $offset, 0, array( $contact_id ) );
		}

		if ( $run_id ) {
			$offset = 2 + ( $campaign_id ? 1 : 0 ) + ( $cart_id ? 1 : 0 ) + ( $contact_id ? 1 : 0 );
			array_splice( $params, $offset, 0, array( $run_id ) );
		}

		if ( '' !== $source_key ) {
			$offset = 2 + ( $campaign_id ? 1 : 0 ) + ( $cart_id ? 1 : 0 ) + ( $contact_id ? 1 : 0 ) + ( $run_id ? 1 : 0 );
			array_splice( $params, $offset, 0, array( $source_key ) );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table and NULL tokens are generated above.
		$result = $wpdb->query( $wpdb->prepare( $sql, $params ) );

		if ( false === $result ) {
			return new WP_Error( 'nowcrm_db_error', $wpdb->last_error ?: __( '無法建立 Email Job。', 'now-crm' ) );
		}

		$id = (int) $wpdb->insert_id;

		if ( $id > 0 ) {
			return $id;
		}

		$existing = $this->get_by_key( $job_key );

		return $existing ? (int) $existing['id'] : new WP_Error( 'nowcrm_job_failed', __( '無法建立 Email Job。', 'now-crm' ) );
	}

	/**
	 * Bounded lookup by immutable job-key prefix.
	 *
	 * The unique job_key index serves the range scan; callers pass a prefix
	 * that uniquely names one run and start hash.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function by_key_prefix( string $prefix, int $limit = 20 ): array {
		global $wpdb;

		if ( '' === $prefix ) {
			return array();
		}

		$table = Schema::table( 'email_jobs' );
		$limit = max( 1, min( 50, $limit ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- trusted table and integer limit.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE job_key LIKE %s ORDER BY id ASC LIMIT {$limit}",
				$wpdb->esc_like( $prefix ) . '%'
			),
			ARRAY_A
		);

		return array_map( array( $this, 'cast' ), (array) $rows );
	}

	/**
	 * Recently linked jobs for one cart.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function for_cart( int $cart_id, int $limit = 20 ): array {
		global $wpdb;

		if ( $cart_id <= 0 ) {
			return array();
		}

		$table = Schema::table( 'email_jobs' );
		$limit = max( 1, min( 100, $limit ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- trusted table and integer limit.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE cart_id = %d ORDER BY id DESC LIMIT {$limit}",
				$cart_id
			),
			ARRAY_A
		);

		return array_map( array( $this, 'cast' ), (array) $rows );
	}

	/**
	 * Accepted jobs of a cart, optionally limited to one immutable source.
	 *
	 * This is the persisted delivery fact behind max-reminder and cooldown
	 * decisions, so a crash between handoff and cart bookkeeping cannot allow
	 * a duplicate.
	 */
	public function accepted_count_for_cart( int $cart_id, string $source_key = '' ): int {
		global $wpdb;

		if ( $cart_id <= 0 ) {
			return 0;
		}

		$table = Schema::table( 'email_jobs' );

		if ( '' !== $source_key ) {
			return (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$table} WHERE cart_id = %d AND status = %s AND (source_key = %s OR (source_key IS NULL AND kind = 'cart'))",
					$cart_id,
					self::STATUS_ACCEPTED,
					$source_key
				)
			);
		}

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE cart_id = %d AND status = %s",
				$cart_id,
				self::STATUS_ACCEPTED
			)
		);
	}

	/**
	 * Frozen recovery URL of the first job for one cart source.
	 *
	 * Reused by a later Email step of the same source, so a token is never
	 * rotated while an earlier message may still be delivered.
	 */
	public function recovery_url_for_source( string $source_key ): string {
		global $wpdb;

		if ( '' === $source_key ) {
			return '';
		}

		$table = Schema::table( 'email_jobs' );

		$payload = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT payload FROM {$table} WHERE source_key = %s ORDER BY id ASC LIMIT 1",
				$source_key
			)
		);

		$data = is_string( $payload ) ? json_decode( $payload, true ) : null;

		return is_array( $data ) ? (string) ( $data['recovery_url'] ?? '' ) : '';
	}

	/**
	 * Cancel one still-queued job. Never touches a claimed or terminal job.
	 */
	public function cancel_job( int $id, string $reason = '' ): bool {
		global $wpdb;

		$table = Schema::table( 'email_jobs' );

		return 1 === (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = %s, last_error = %s, updated_at = %s WHERE id = %d AND status = %s",
				self::STATUS_CANCELLED,
				substr( $reason ?: __( 'Cart source invalidated.', 'now-crm' ), 0, 1000 ),
				gmdate( 'Y-m-d H:i:s' ),
				$id,
				self::STATUS_QUEUED
			)
		);
	}

	/**
	 * Cancel queued automation jobs whose cart source is no longer current.
	 *
	 * Claimed or terminal jobs are left untouched: their outcome is unknown or
	 * already accepted, so cancelling them could hide a real handoff.
	 */
	public function cancel_source( int $cart_id, string $current_source_key, string $reason = '' ): int {
		global $wpdb;

		if ( $cart_id <= 0 ) {
			return 0;
		}

		$table = Schema::table( 'email_jobs' );

		return (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = %s, last_error = %s, updated_at = %s
				WHERE cart_id = %d AND status = %s AND source_key IS NOT NULL AND source_key <> %s",
				self::STATUS_CANCELLED,
				substr( $reason ?: __( 'Cart source invalidated.', 'now-crm' ), 0, 1000 ),
				gmdate( 'Y-m-d H:i:s' ),
				$cart_id,
				self::STATUS_QUEUED,
				$current_source_key
			)
		);
	}

	/**
	 * Fetch one job.
	 *
	 * @return array<string, mixed>|null
	 */
	public function get( int $id ): ?array {
		global $wpdb;

		$table = Schema::table( 'email_jobs' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );

		return $row ? $this->cast( $row ) : null;
	}

	/**
	 * Fetch by unique key.
	 *
	 * @return array<string, mixed>|null
	 */
	public function get_by_key( string $job_key ): ?array {
		global $wpdb;

		$table = Schema::table( 'email_jobs' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE job_key = %s", $job_key ), ARRAY_A );

		return $row ? $this->cast( $row ) : null;
	}

	/** Create every broadcast job in the campaign confirmation transaction. */
	public function enqueue_campaign( int $id, array $audience, array $snapshot, string $due, int $seconds ) {
		global $wpdb;
		$table = Schema::table( 'email_jobs' );
		[ $selection, $params ] = Audience::selection( $audience );
		$now = gmdate( 'Y-m-d H:i:s' );

		$subject_a = mb_substr( (string) ( $snapshot['subject'] ?? '' ), 0, 255 );
		$ab        = isset( $snapshot['ab'] ) && is_array( $snapshot['ab'] ) ? $snapshot['ab'] : array();
		$split     = ! empty( $ab['enabled'] ) && '' !== trim( (string) ( $ab['subject_b'] ?? '' ) );
		$subject_b = $split ? mb_substr( (string) $ab['subject_b'], 0, 255 ) : $subject_a;

		// One stable hash per contact and campaign: MOD(CRC32(...), 2) is 50/50
		// and never changes when the campaign is enqueued again.
		$variant    = "MOD(CRC32(CONCAT(%d, ':', c.email_normalized)), 2)";
		$subject    = $split ? "CASE WHEN {$variant} = 0 THEN %s ELSE %s END" : '%s';
		$payload    = $split ? "CASE WHEN {$variant} = 0 THEN %s ELSE %s END" : '%s';
		$values     = array( $id, $id );

		if ( $split ) {
			$values = array_merge( $values, array( $id, $subject_a, $subject_b, $id, '{"ab":"a"}', '{"ab":"b"}' ) );
		} else {
			$values = array_merge( $values, array( $subject_a, '' ) );
		}

		$values = array_merge( $values, array( $id, $seconds, $due, Settings::int( 'email_max_attempts' ), $now, $now ) );

		return $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table}
					(job_key, kind, campaign_id, contact_id, recipient, recipient_name, subject, payload, status, priority, available_at, max_attempts, created_at, updated_at)
				 SELECT CONCAT('campaign:', %d, ':contact:', c.id), 'campaign', %d, c.id, c.email,
					LEFT(TRIM(CONCAT(c.first_name, ' ', c.last_name)), 191), {$subject}, {$payload}, 'queued', 10,
					TIMESTAMPADD(SECOND, MOD(CRC32(CONCAT(%d, ':', c.id)), %d), %s), %d, %s, %s
				 {$selection}",
				array_merge( $values, $params )
			)
		);
	}

	/** Identities written to the queue, in the same order as review. */
	public function campaign_recipients( int $id ): ?array {
		global $wpdb;
		$table = Schema::table( 'email_jobs' );
		return $wpdb->get_results(
			$wpdb->prepare( "SELECT contact_id, recipient FROM {$table} WHERE campaign_id = %d AND kind = 'campaign' ORDER BY contact_id", $id ),
			ARRAY_A
		);
	}

	/** Discard a future run before any job can be claimed. */
	public function unschedule_campaign( int $id ): bool {
		global $wpdb;
		return false !== $wpdb->delete( Schema::table( 'email_jobs' ), array( 'campaign_id' => $id, 'kind' => 'campaign' ) );
	}

	private function campaign_gate( string $table ): string {
		global $wpdb;
		return $wpdb->prepare(
			"({$table}.kind <> 'campaign' OR {$table}.campaign_id IS NULL OR EXISTS (
				SELECT 1 FROM {$wpdb->postmeta} state
				WHERE state.post_id = {$table}.campaign_id AND state.meta_key = %s AND state.meta_value = %s
			))",
			Campaigns::META_STATUS,
			Campaigns::STATUS_SENDING
		);
	}

	/**
	 * Atomically claim due jobs for one worker.
	 *
	 * @param int    $limit      Maximum jobs.
	 * @param string $claim_token Random per-worker token.
	 * @return array<int, array<string, mixed>>
	 * @throws \RuntimeException When the claim write or its read-back fails.
	 */
	public function claim( int $limit, string $claim_token ): array {
		global $wpdb;

		$limit = max( 1, min( 500, $limit ) );
		$now   = gmdate( 'Y-m-d H:i:s' );
		$lease = gmdate( 'Y-m-d H:i:s', time() + ( max( 1, (int) \NowCRM\Support\Settings::int( 'email_lease_minutes' ) ) * MINUTE_IN_SECONDS ) );
		$table = Schema::table( 'email_jobs' );

		$gate = $this->campaign_gate( $table );
		$sql = "UPDATE {$table}
			SET status = %s, claim_token = %s, claimed_at = %s, lease_expires_at = %s,
				attempts = attempts + 1,
				ses_correlation = CONCAT(id, '-', SUBSTRING(SHA2(CONCAT(%s, '-', id), 256), 1, 32)),
				updated_at = %s
			WHERE status = %s AND available_at <= %s AND attempts < max_attempts AND {$gate}
			ORDER BY priority ASC, available_at ASC, id ASC
			LIMIT %d";

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- SQL is built in this method and every value uses a prepare() placeholder.
		$updated = $wpdb->query(
			$wpdb->prepare(
				$sql,
				self::STATUS_SENDING,
				$claim_token,
				$now,
				$lease,
				$claim_token,
				$now,
				self::STATUS_QUEUED,
				$now,
				$limit
			)
		);
		// phpcs:enable

		if ( false === $updated ) {
			// A failed write is not "no work". The worker must not record a
			// healthy tick when the claim itself did not run.
			throw new \RuntimeException( 'email_job_claim_update_failed' );
		}

		if ( 0 === $updated ) {
			return array();
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE claim_token = %s AND status = %s ORDER BY priority ASC, id ASC",
				$claim_token,
				self::STATUS_SENDING
			),
			ARRAY_A
		);

		// After a successful claim update, a failed read-back would leave
		// claimed rows unprocessed on this tick. Fail the tick instead of
		// reporting success; the lease expiry path still makes the rows safe.
		if ( ! is_array( $rows ) || '' !== (string) $wpdb->last_error ) {
			throw new \RuntimeException( 'email_job_claim_select_failed' );
		}

		return array_map( array( $this, 'cast' ), $rows );
	}

	/**
	 * Mark a claimed job as accepted by the transport.
	 *
	 * @param string $claim_token Claim guard; stale workers cannot overwrite.
	 */
	public function mark_accepted( int $id, string $claim_token, string $message_id = '', string $transport = '' ): bool {
		global $wpdb;

		$table = Schema::table( 'email_jobs' );
		$now   = gmdate( 'Y-m-d H:i:s' );

		$data = array(
			'status'        => self::STATUS_ACCEPTED,
			'accepted_at'   => $now,
			'updated_at'    => $now,
			'message_id'    => $message_id ? substr( $message_id, 0, 191 ) : null,
			'claim_token'   => null,
			'claimed_at'    => null,
			'lease_expires_at' => null,
		);

		$where = array(
			'id'          => $id,
			'claim_token' => $claim_token,
			'status'      => self::STATUS_SENDING,
		);

		$formats = array( '%s', '%s', '%s', '%s', '%s', '%s', '%s' );

		return (bool) $wpdb->update( $table, $data, $where, $formats, array( '%d', '%s', '%s' ) );
	}

	/**
	 * Mark a claimed job as failed, retrying with exponential backoff while
	 * attempts remain. External uncertainty must use mark_unknown().
	 */
	public function mark_failed( int $id, string $claim_token, string $error, bool $retryable = true ): bool {
		global $wpdb;

		$job = $this->get( $id );

		if ( ! $job || $claim_token !== $job['claim_token'] || self::STATUS_SENDING !== $job['status'] ) {
			return false;
		}

		$table = Schema::table( 'email_jobs' );
		$now   = gmdate( 'Y-m-d H:i:s' );

		$can_retry = $retryable && (int) $job['attempts'] < (int) $job['max_attempts'];
		$delay     = min( HOUR_IN_SECONDS, 60 * ( 2 ** max( 0, (int) $job['attempts'] - 1 ) ) );
		$available = gmdate( 'Y-m-d H:i:s', time() + $delay );

		$sql = "UPDATE {$table}
			SET status = %s, last_error = %s, claim_token = NULL, claimed_at = NULL, lease_expires_at = NULL,
				ses_correlation = NULL, available_at = %s, updated_at = %s
			WHERE id = %d AND claim_token = %s AND status = %s";

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- table name is trusted and every value uses a prepare() placeholder.
		return (bool) $wpdb->query(
			$wpdb->prepare(
				$sql,
				$can_retry ? self::STATUS_QUEUED : self::STATUS_FAILED,
				substr( $error, 0, 1000 ),
				$available,
				$now,
				$id,
				$claim_token,
				self::STATUS_SENDING
			)
		);
		// phpcs:enable
	}

	/**
	 * Return a claimed job to the queue without treating it as a failure
	 * (paused campaign, worker shutdown). Attempts are not consumed.
	 */
	public function defer( int $id, string $claim_token, int $seconds, string $reason = '' ): bool {
		global $wpdb;

		$table = Schema::table( 'email_jobs' );
		$now   = gmdate( 'Y-m-d H:i:s' );
		$when  = gmdate( 'Y-m-d H:i:s', time() + max( 0, $seconds ) );

		return (bool) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table}
				SET status = %s, available_at = %s, claim_token = NULL, claimed_at = NULL,
					lease_expires_at = NULL, ses_correlation = NULL, last_error = %s, attempts = GREATEST(0, attempts - 1), updated_at = %s
				WHERE id = %d AND claim_token = %s AND status = %s",
				self::STATUS_QUEUED,
				$when,
				substr( $reason, 0, 1000 ),
				$now,
				$id,
				$claim_token,
				self::STATUS_SENDING
			)
		);
	}

	/**
	 * Mark a job as suppressed (unsubscribed, bounced, complaint, disabled).
	 */
	public function mark_suppressed( int $id, string $claim_token, string $reason ): bool {
		global $wpdb;

		$table = Schema::table( 'email_jobs' );
		$now   = gmdate( 'Y-m-d H:i:s' );

		$sql = "UPDATE {$table}
			SET status = %s, last_error = %s, claim_token = NULL, claimed_at = NULL, lease_expires_at = NULL, ses_correlation = NULL, updated_at = %s
			WHERE id = %d AND claim_token = %s AND status = %s";

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- table name is trusted and every value uses a prepare() placeholder.
		return (bool) $wpdb->query(
			$wpdb->prepare( $sql, self::STATUS_SUPPRESSED, substr( $reason, 0, 1000 ), $now, $id, $claim_token, self::STATUS_SENDING )
		);
		// phpcs:enable
	}

	/**
	 * Leave a job in an explicit unknown state. Never auto-retried.
	 */
	public function mark_unknown( int $id, string $claim_token, string $error ): bool {
		global $wpdb;

		$table = Schema::table( 'email_jobs' );
		$now   = gmdate( 'Y-m-d H:i:s' );

		// The handoff may already have reached the transport, so the
		// per-attempt correlation must survive: a later signed bounce or
		// complaint still needs it to bind to this exact job. Explicit retry
		// rotates it and lease expiry keeps it already.
		$sql = "UPDATE {$table}
			SET status = %s, last_error = %s, claim_token = NULL, claimed_at = NULL, lease_expires_at = NULL, updated_at = %s
			WHERE id = %d AND claim_token = %s AND status = %s";

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- table name is trusted and every value uses a prepare() placeholder.
		return (bool) $wpdb->query(
			$wpdb->prepare( $sql, self::STATUS_UNKNOWN, substr( $error, 0, 1000 ), $now, $id, $claim_token, self::STATUS_SENDING )
		);
		// phpcs:enable
	}

	/**
	 * Recover jobs whose worker crashed after claiming.
	 *
	 * @return int Number of rows moved to unknown.
	 */
	public function release_stale(): int {
		global $wpdb;

		$table = Schema::table( 'email_jobs' );
		$now   = gmdate( 'Y-m-d H:i:s' );

		return (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table}
				SET status = %s, last_error = %s, claim_token = NULL, claimed_at = NULL, lease_expires_at = NULL, updated_at = %s
				WHERE status = %s AND lease_expires_at IS NOT NULL AND lease_expires_at < %s",
				self::STATUS_UNKNOWN,
				__( 'Worker lease expired; delivery result unknown.', 'now-crm' ),
				$now,
				self::STATUS_SENDING,
				$now
			)
		);
	}

	/**
	 * Cancel every queued job of a campaign.
	 *
	 * @return int
	 */
	public function cancel_campaign( int $campaign_id, string $reason = '' ) {
		global $wpdb;
		$table = Schema::table( 'email_jobs' );
		return $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = %s, last_error = %s, updated_at = %s
				WHERE campaign_id = %d AND kind = 'campaign' AND status = %s",
				self::STATUS_CANCELLED, $reason, gmdate( 'Y-m-d H:i:s' ), $campaign_id, self::STATUS_QUEUED
			)
		);
	}

	/**
	 * Cancel still-queued cart reminders.
	 *
	 * A claimed row is already owned by a worker: its transport outcome is
	 * unknown until the worker stores it, so it must never be rewritten here.
	 */
	public function cancel_cart( int $cart_id, string $reason = '' ): int {
		global $wpdb;

		$table = Schema::table( 'email_jobs' );

		return (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = %s, last_error = %s, updated_at = %s
				WHERE cart_id = %d AND status = %s",
				self::STATUS_CANCELLED,
				substr( $reason ?: __( 'Cart no longer eligible.', 'now-crm' ), 0, 1000 ),
				gmdate( 'Y-m-d H:i:s' ),
				$cart_id,
				self::STATUS_QUEUED
			)
		);
	}

	/**
	 * Suppress every queued job of an address after a bounce/complaint/unsubscribe.
	 */
	public function suppress_email( string $email, string $reason ): int {
		global $wpdb;

		$table = Schema::table( 'email_jobs' );
		$norm  = mb_strtolower( trim( $email ), 'UTF-8' );

		return (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = %s, last_error = %s, updated_at = %s
				WHERE recipient = %s AND status IN (%s, %s)",
				self::STATUS_SUPPRESSED,
				substr( $reason, 0, 1000 ),
				gmdate( 'Y-m-d H:i:s' ),
				$norm,
				self::STATUS_QUEUED,
				self::STATUS_SENDING
			)
		);
	}

	/**
	 * Explicit operator retry of a failed or unknown job.
	 *
	 * A recorded provider result is evidence: such a job is never requeued.
	 * The delivery_status predicate makes that check atomic with the update.
	 */
	public function retry( int $id ): bool {
		global $wpdb;

		$job = $this->get( $id );

		if ( ! $job ) {
			return false;
		}

		// A cart-linked unknown outcome may already have reached the
		// transport. Only a definitive failure may be retried by an operator.
		if ( (int) $job['cart_id'] > 0 && self::STATUS_FAILED !== $job['status'] ) {
			return false;
		}

		$table = Schema::table( 'email_jobs' );
		$now   = gmdate( 'Y-m-d H:i:s' );

		return (bool) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table}
				SET status = %s, attempts = 0, available_at = %s, claim_token = NULL, claimed_at = NULL,
					lease_expires_at = NULL, ses_correlation = NULL, last_error = NULL, delivery_status = '', delivery_at = NULL,
					delivery_error = NULL, updated_at = %s
				WHERE id = %d AND (kind <> 'campaign' OR campaign_id IS NULL) AND status IN (%s, %s, %s) AND delivery_status = ''",
				self::STATUS_QUEUED,
				$now,
				$now,
				$id,
				self::STATUS_FAILED,
				self::STATUS_UNKNOWN,
				self::STATUS_SUPPRESSED
			)
		);
	}

	/**
	 * Apply a provider delivery result without changing the queue status.
	 *
	 * Delivery facts arrive out of order: a Delivery event may follow a Bounce
	 * for the same message. A lower-ranked result therefore never overwrites a
	 * higher one. The queue status remains the transport result (accepted is a
	 * handoff, not a delivery), so campaign and queue counts stay unchanged.
	 *
	 * @param int    $id          Job ID.
	 * @param string $status      One of the DELIVERY_* constants.
	 * @param string $error       Provider diagnostic, truncated to the column budget.
	 * @param int    $attempt     Expected attempt number; 0 skips the compare.
	 * @param string $correlation Expected per-attempt SES correlation; '' skips it.
	 * @throws \RuntimeException When the database write itself fails; a normal
	 *                           rank-loss or duplicate no-op returns false.
	 */
	public function mark_delivery_event( int $id, string $status, string $error = '', int $attempt = 0, string $correlation = '' ): bool {
		global $wpdb;

		$ranks = array(
			self::DELIVERY_DEFERRED   => 1,
			self::DELIVERY_DELIVERED  => 2,
			self::DELIVERY_BOUNCED    => 3,
			self::DELIVERY_COMPLAINED => 4,
		);

		if ( $id <= 0 || ! isset( $ranks[ $status ] ) ) {
			return false;
		}

		$job = $this->get( $id );

		if ( ! $job || ! in_array( $job['status'], array( self::STATUS_ACCEPTED, self::STATUS_SENDING, self::STATUS_UNKNOWN ), true ) ) {
			return false;
		}

		// A late event from an older attempt must never bind to a retried job.
		if ( ( $attempt > 0 && $attempt !== (int) $job['attempts'] )
			|| ( '' !== $correlation && ! hash_equals( (string) $job['ses_correlation'], $correlation ) ) ) {
			return false;
		}

		$current = (string) $job['delivery_status'];
		$error   = substr( trim( $error ), 0, 1000 );

		if ( $current === $status && ( '' === $error || $error === (string) $job['delivery_error'] ) ) {
			// Same result and no new diagnostic: nothing to record.
			return false;
		}

		$table = Schema::table( 'email_jobs' );
		$now   = gmdate( 'Y-m-d H:i:s' );

		$guard = '';

		if ( $attempt > 0 ) {
			$guard .= ' AND attempts = %d';
		}

		if ( '' !== $correlation ) {
			$guard .= ' AND ses_correlation = %s';
		}

		$sql = "UPDATE {$table}
			SET delivery_status = %s, delivery_at = %s, delivery_error = %s, updated_at = %s
			WHERE id = %d
				AND status IN (%s, %s, %s)
				AND ( delivery_status = '' OR FIELD(delivery_status, %s, %s, %s, %s) < %d OR delivery_status = %s )" . $guard;

		$params = array(
			$status,
			$now,
			$error,
			$now,
			$id,
			self::STATUS_ACCEPTED,
			self::STATUS_SENDING,
			self::STATUS_UNKNOWN,
			self::DELIVERY_DEFERRED,
			self::DELIVERY_DELIVERED,
			self::DELIVERY_BOUNCED,
			self::DELIVERY_COMPLAINED,
			$ranks[ $status ],
			$status,
		);

		if ( $attempt > 0 ) {
			$params[] = $attempt;
		}

		if ( '' !== $correlation ) {
			$params[] = $correlation;
		}

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- table name is trusted and every value uses a prepare() placeholder.
		$result = $wpdb->query( $wpdb->prepare( $sql, $params ) );
		// phpcs:enable

		if ( false === $result ) {
			// A real write failure must not look like a rank-loss no-op, or the
			// SNS event would be acknowledged without its provider result.
			throw new \RuntimeException( 'email_job_delivery_update_failed' );
		}

		return (bool) $result;
	}

	/**
	 * Delivery outcome counters.
	 *
	 * Every nonempty receipt counts regardless of the queue status, because a
	 * provider event can arrive after the row moved to unknown. `none` stays
	 * limited to accepted rows without a receipt.
	 *
	 * @return array<string, int>
	 */
	public function delivery_stats(): array {
		global $wpdb;

		$table = Schema::table( 'email_jobs' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- trusted table.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT status, delivery_status, COUNT(*) AS total FROM {$table}
				WHERE delivery_status <> '' OR status = %s
				GROUP BY status, delivery_status",
				self::STATUS_ACCEPTED
			),
			ARRAY_A
		);

		$stats = array(
			'delivered'  => 0,
			'bounced'    => 0,
			'complained' => 0,
			'deferred'   => 0,
			'none'       => 0,
		);

		foreach ( (array) $rows as $row ) {
			$key = (string) $row['delivery_status'];

			if ( '' === $key ) {
				if ( self::STATUS_ACCEPTED === (string) $row['status'] ) {
					$stats['none'] += (int) $row['total'];
				}

				continue;
			}

			if ( isset( $stats[ $key ] ) ) {
				$stats[ $key ] += (int) $row['total'];
			}
		}

		return $stats;
	}

	/**
	 * Queue counters across every campaign.
	 *
	 * @return array<string, int>
	 */
	public function global_stats(): array {
		global $wpdb;

		$table = Schema::table( 'email_jobs' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- trusted table.
		$rows = $wpdb->get_results( "SELECT status, COUNT(*) AS total FROM {$table} GROUP BY status", ARRAY_A );

		$stats = array(
			self::STATUS_QUEUED     => 0,
			self::STATUS_SENDING    => 0,
			self::STATUS_ACCEPTED   => 0,
			self::STATUS_FAILED     => 0,
			self::STATUS_SUPPRESSED => 0,
			self::STATUS_UNKNOWN    => 0,
			self::STATUS_CANCELLED  => 0,
			'total'                 => 0,
		);

		foreach ( (array) $rows as $row ) {
			$stats[ (string) $row['status'] ]  = (int) $row['total'];
			$stats['total']                   += (int) $row['total'];
		}

		return $stats;
	}

	/**
	 * Campaign counters derived from the queue.
	 *
	 * @return array<string, int>
	 */
	public function campaign_stats( int $campaign_id ): array {
		global $wpdb;

		$table = Schema::table( 'email_jobs' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- trusted table.
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT status, COUNT(*) AS total FROM {$table} WHERE campaign_id = %d AND kind = 'campaign' GROUP BY status", $campaign_id ),
			ARRAY_A
		);

		$stats = array(
			self::STATUS_QUEUED     => 0,
			self::STATUS_SENDING    => 0,
			self::STATUS_ACCEPTED   => 0,
			self::STATUS_FAILED     => 0,
			self::STATUS_SUPPRESSED => 0,
			self::STATUS_UNKNOWN    => 0,
			self::STATUS_CANCELLED  => 0,
			'total'                 => 0,
		);

		foreach ( (array) $rows as $row ) {
			$status                  = (string) $row['status'];
			$stats[ $status ]        = (int) $row['total'];
			$stats['total']         += (int) $row['total'];
		}

		return $stats;
	}

	/**
	 * Search jobs for the admin screen.
	 *
	 * @param array<string, mixed> $args status, delivery_status, kind, campaign_id, cart_id, email, limit, offset.
	 * @return array{items: array<int, array<string, mixed>>, total: int}
	 */
	public function search( array $args = array() ): array {
		global $wpdb;

		$table  = Schema::table( 'email_jobs' );
		$where  = array( '1=1' );
		$params = array();

		if ( ! empty( $args['status'] ) ) {
			$where[]  = 'status = %s';
			$params[] = sanitize_key( (string) $args['status'] );
		}

		if ( ! empty( $args['delivery_status'] ) ) {
			$delivery = sanitize_key( (string) $args['delivery_status'] );

			if ( 'none' === $delivery ) {
				$where[]  = "delivery_status = '' AND status = %s";
				$params[] = self::STATUS_ACCEPTED;
			} elseif ( in_array( $delivery, array( self::DELIVERY_DELIVERED, self::DELIVERY_BOUNCED, self::DELIVERY_COMPLAINED, self::DELIVERY_DEFERRED ), true ) ) {
				$where[]  = 'delivery_status = %s';
				$params[] = $delivery;
			}
		}

		if ( ! empty( $args['kind'] ) ) {
			$where[]  = 'kind = %s';
			$params[] = sanitize_key( (string) $args['kind'] );
		}

		if ( ! empty( $args['campaign_id'] ) ) {
			$where[]  = 'campaign_id = %d';
			$params[] = absint( $args['campaign_id'] );
		}

		if ( ! empty( $args['cart_id'] ) ) {
			$where[]  = 'cart_id = %d';
			$params[] = absint( $args['cart_id'] );
		}

		if ( ! empty( $args['email'] ) ) {
			$where[]  = 'recipient = %s';
			$params[] = mb_strtolower( trim( (string) $args['email'] ), 'UTF-8' );
		}

		$clause = implode( ' AND ', $where );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- clause is built from fixed fragments.
		$count_sql = $params
			? $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$clause}", $params )
			: "SELECT COUNT(*) FROM {$table} WHERE {$clause}";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- clause is built from fixed fragments; table name is trusted and values use placeholders.
		$total     = (int) $wpdb->get_var( $count_sql );

		$limit  = max( 1, min( 200, (int) ( $args['limit'] ?? 50 ) ) );
		$offset = max( 0, (int) ( $args['offset'] ?? 0 ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- clause is built from fixed fragments.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE {$clause} ORDER BY id DESC LIMIT %d OFFSET %d",
				array_merge( $params, array( $limit, $offset ) )
			),
			ARRAY_A
		);

		return array(
			'items' => array_map( array( $this, 'cast' ), (array) $rows ),
			'total' => $total,
		);
	}

	/**
	 * Recent jobs for one address.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function for_email( string $email, int $limit = 20 ): array {
		global $wpdb;

		$table = Schema::table( 'email_jobs' );
		$limit = max( 1, min( 100, $limit ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- trusted table and integer limit.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE recipient = %s ORDER BY id DESC LIMIT {$limit}",
				mb_strtolower( trim( $email ), 'UTF-8' )
			),
			ARRAY_A
		);

		return array_map( array( $this, 'cast' ), (array) $rows );
	}

	/**
	 * Number of queued jobs.
	 */
	public function due_count(): int {
		global $wpdb;
		$table = Schema::table( 'email_jobs' );
		$gate = $this->campaign_gate( $table );
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE status = %s AND available_at <= %s AND attempts < max_attempts AND {$gate}",
				self::STATUS_QUEUED, gmdate( 'Y-m-d H:i:s' )
			)
		);
	}

	/**
	 * Normalize a timestamp to UTC SQL.
	 *
	 * @param mixed $value Timestamp or SQL string.
	 */
	private function utc( $value ): string {
		if ( empty( $value ) ) {
			return gmdate( 'Y-m-d H:i:s' );
		}

		if ( is_numeric( $value ) ) {
			return gmdate( 'Y-m-d H:i:s', (int) $value );
		}

		return (string) $value;
	}

	public function get_by_message_id( string $message_id, string $recipient ): ?array {
		global $wpdb;
		$table = Schema::table( 'email_jobs' );
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE recipient = %s AND message_id = %s ORDER BY id DESC LIMIT 1", $recipient, $message_id ),
			ARRAY_A
		);
		return $row ? $this->cast( $row ) : null;
	}

	/**
	 * Resolve a job from the per-attempt SES correlation value.
	 *
	 * The recipient must match the stored job. Callers still compare the
	 * returned value with hash_equals() before trusting it.
	 */
	public function get_by_correlation( string $correlation, string $recipient ): ?array {
		global $wpdb;

		if ( '' === $correlation ) {
			return null;
		}

		$table = Schema::table( 'email_jobs' );
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE ses_correlation = %s AND recipient = %s ORDER BY id DESC LIMIT 1",
				$correlation,
				mb_strtolower( trim( $recipient ), 'UTF-8' )
			),
			ARRAY_A
		);

		return $row ? $this->cast( $row ) : null;
	}

	/**
	 * Cast a database row.
	 *
	 * @param array<string, mixed> $row Raw row.
	 * @return array<string, mixed>
	 */
	public function cast( array $row ): array {
		foreach ( array( 'id', 'campaign_id', 'cart_id', 'contact_id', 'run_id', 'priority', 'attempts', 'max_attempts' ) as $key ) {
			$row[ $key ] = isset( $row[ $key ] ) ? (int) $row[ $key ] : 0;
		}

		$row['source_key'] = (string) ( $row['source_key'] ?? '' );

		foreach ( array( 'delivery_status', 'delivery_at', 'delivery_error', 'ses_correlation' ) as $key ) {
			$row[ $key ] = (string) ( $row[ $key ] ?? '' );
		}

		$payload       = json_decode( (string) ( $row['payload'] ?? '' ), true );
		$row['payload'] = is_array( $payload ) ? $payload : array();

		return $row;
	}
}
