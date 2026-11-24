<?php
/**
 * Abandoned-cart persistence. One row per cart cycle, never keyed by email.
 *
 * @package NowCRM
 */

namespace NowCRM\Cart;

use NowCRM\Automation\RunRepository;
use NowCRM\Email\EmailJobRepository;
use NowCRM\Support\Schema;
use WP_Error;

defined( 'ABSPATH' ) || exit;

final class CartRepository {

	public const STATUS_ACTIVE        = 'active';
	public const STATUS_ABANDONED     = 'abandoned';
	public const STATUS_LOST          = 'lost';
	public const STATUS_COMPLETED     = 'completed';
	public const STATUS_RECOVERED     = 'recovered';
	public const STATUS_ORDER_PENDING = 'order_pending';

	/**
	 * Bounded wait for a normal transition that must not be dropped.
	 */
	private const TRANSITION_LOCK_TIMEOUT = 10;

	/**
	 * Server-side heartbeat window. A touch inside it changes nothing.
	 */
	private const ACTIVITY_TOUCH_SECONDS = 60;

	/**
	 * Locks held by this PHP process, so nested use never releases early.
	 *
	 * @var array<string, int>
	 */
	private static array $held_locks = array();

	/**
	 * Serialize every cart transition, step execution, and the final delivery
	 * check for one cart.
	 *
	 * The lock name matches Reminder::acquire_lock(), so enqueue and send cannot
	 * race a Cart state change. A nested call in the same process shares the
	 * outer lock and never releases it early.
	 *
	 * @param int      $id       Cart ID.
	 * @param callable $callback Work to run while the lock is held.
	 * @param int      $timeout  Seconds to wait for the lock. Zero fails fast.
	 * @return mixed|WP_Error Callback result, or WP_Error when the lock is busy.
	 */
	public static function with_lock( int $id, callable $callback, int $timeout = 0 ) {
		global $wpdb;

		if ( $id <= 0 ) {
			return new WP_Error( 'nowcrm_cart_invalid', 'invalid_cart' );
		}

		$key = 'nowcrm-cart-' . $id . '-' . md5( DB_NAME . ':' . $wpdb->prefix );

		if ( isset( self::$held_locks[ $key ] ) ) {
			++self::$held_locks[ $key ];

			try {
				return $callback();
			} finally {
				if ( 0 >= --self::$held_locks[ $key ] ) {
					unset( self::$held_locks[ $key ] );
				}
			}
		}

		$owned = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $key, max( 0, min( 30, $timeout ) ) ) );

		if ( '1' !== (string) $owned ) {
			return new WP_Error( 'nowcrm_cart_locked', 'cart_locked' );
		}

		self::$held_locks[ $key ] = 1;

		try {
			return $callback();
		} finally {
			unset( self::$held_locks[ $key ] );
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $key ) );
		}
	}

	/**
	 * Record or refresh checkout activity.
	 *
	 * Activity is ordered by the client observation time when it exists. A
	 * newer observation wins; an older or replayed observation never changes
	 * status, version, or the stored snapshot. A cycle with a pending order
	 * cannot return to active.
	 *
	 * @param array<string, mixed> $data cycle_key, session_key, user_id, email, snapshot, cart_hash, item_count, total, currency, activity_order_ms, woo_mutation.
	 * @return array<string, mixed>|WP_Error
	 */
	public function record_activity( array $data ) {
		global $wpdb;

		$cycle     = substr( (string) ( $data['cycle_key'] ?? '' ), 0, 64 );
		$email     = mb_strtolower( trim( (string) ( $data['email'] ?? '' ) ), 'UTF-8' );
		$order_ms  = max( 0, (int) ( $data['activity_order_ms'] ?? 0 ) );
		$woo       = ! empty( $data['woo_mutation'] );

		if ( '' === $cycle || ( ! $woo && ! is_email( $email ) ) ) {
			return new WP_Error( 'nowcrm_cart_invalid', 'invalid_cart_activity' );
		}

		$existing = $this->find_by_cycle( $cycle );

		if ( $existing ) {
			// Serialize with Engine step execution. A busy lock is retried
			// briefly so normal checkout activity is not dropped.
			$result = self::with_lock(
				(int) $existing['id'],
				function () use ( $cycle, $data, $woo ) {
					return $this->record_existing_activity( $cycle, $data, $woo );
				},
				2
			);

			if ( is_wp_error( $result ) && 'nowcrm_cart_locked' === $result->get_error_code() ) {
				$existing['activity_stale'] = true;

				return $existing;
			}

			return $result;
		}

		// A Woo mutation may only refresh a cart this store already captured.
		// It never starts a cycle and never resurrects a terminal row.
		if ( $woo ) {
			return new WP_Error( 'nowcrm_cart_missing', 'cart_missing' );
		}

		$now   = gmdate( 'Y-m-d H:i:s' );
		$table = Schema::table( 'carts' );

		$snapshot = wp_json_encode( $this->snapshot_envelope( $data['snapshot'] ?? array() ) );

		$inserted = $wpdb->insert(
			$table,
			array(
				'cart_cycle_key'    => $cycle,
				'session_key'       => substr( (string) ( $data['session_key'] ?? '' ), 0, 191 ),
				'user_id'           => ! empty( $data['user_id'] ) ? absint( $data['user_id'] ) : null,
				'email'             => substr( (string) ( $data['email'] ?? '' ), 0, 191 ),
				'email_normalized'  => $email,
				'status'            => self::STATUS_ACTIVE,
				'cart_snapshot'     => $snapshot,
				'cart_hash'         => substr( (string) ( $data['cart_hash'] ?? '' ), 0, 64 ),
				'item_count'        => absint( $data['item_count'] ?? 0 ),
				'total'             => (float) ( $data['total'] ?? 0 ),
				'currency'          => substr( strtoupper( (string) ( $data['currency'] ?? '' ) ), 0, 3 ),
				'version'           => 1,
				'last_activity_at'  => $now,
				'activity_order_ms' => $order_ms,
				'created_at'        => $now,
				'updated_at'        => $now,
			),
			array( '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%f', '%s', '%d', '%s', '%d', '%s', '%s' )
		);

		if ( ! $inserted ) {
			$race = $this->find_by_cycle( $cycle );

			return $race ? $race : new WP_Error( 'nowcrm_cart_insert', $wpdb->last_error ?: 'cart_insert_failed' );
		}

		$cart_id = (int) $wpdb->insert_id;

		$this->record_event( $cart_id, 'captured', array( 'item_count' => absint( $data['item_count'] ?? 0 ) ) );

		return $this->get( $cart_id );
	}

	/**
	 * Stop using one cart's address after the checkout field was cleared.
	 *
	 * Only the session that owns the cycle may clear it, and only a newer
	 * observation than the stored one wins. History stays intact; the queued
	 * reminders for this cart are cancelled and the row no longer has a
	 * deliverable address, so a later sweep cannot queue a reminder for the
	 * abandoned value.
	 *
	 * @param string $cycle       Cart cycle key.
	 * @param string $session_key Bound Woo customer id.
	 * @param int    $order_ms    Client observation time.
	 * @return array<string, mixed>|WP_Error
	 */
	public function clear_email( string $cycle, string $session_key, int $order_ms ) {
		global $wpdb;

		$cycle = substr( $cycle, 0, 64 );

		if ( '' === $cycle ) {
			return new WP_Error( 'nowcrm_cart_invalid', 'invalid_cart_activity' );
		}

		$existing = $this->find_by_cycle( $cycle );

		if ( ! $existing ) {
			return new WP_Error( 'nowcrm_cart_missing', 'cart_missing' );
		}

		$result = self::with_lock(
			(int) $existing['id'],
			function () use ( $cycle, $session_key, $order_ms ) {
				global $wpdb;

				$row = $this->find_by_cycle( $cycle );

				if ( ! $row ) {
					return new WP_Error( 'nowcrm_cart_missing', 'cart_missing' );
				}

				$status = (string) $row['status'];

				if ( in_array( $status, array( self::STATUS_COMPLETED, self::STATUS_RECOVERED ), true ) ) {
					return new WP_Error( 'nowcrm_cart_completed', 'cart_cycle_completed' );
				}

				if ( self::STATUS_ORDER_PENDING === $status ) {
					return new WP_Error( 'nowcrm_cart_order_pending', 'cart_order_pending' );
				}

				$session = substr( trim( $session_key ), 0, 191 );

				if ( '' === $session || $session !== (string) $row['session_key'] ) {
					return new WP_Error( 'nowcrm_cart_session_mismatch', 'cart_session_mismatch' );
				}

				$stored_ms = (int) ( $row['activity_order_ms'] ?? 0 );

				if ( $order_ms > 0 && $stored_ms > 0 && $order_ms <= $stored_ms ) {
					$row['activity_stale'] = true;

					return $row;
				}

				if ( '' === (string) $row['email_normalized'] ) {
					return $row;
				}

				$version = (int) $row['version'];
				$now     = gmdate( 'Y-m-d H:i:s' );
				$table   = Schema::table( 'carts' );

				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- fixed table and placeholders.
				$updated = $wpdb->update(
					$table,
					array(
						'email'             => '',
						'email_normalized'  => '',
						'version'           => $version + 1,
						'last_activity_at'  => $now,
						'activity_order_ms' => max( $stored_ms, $order_ms ),
						'reminder_due_at'   => null,
						'updated_at'        => $now,
					),
					array(
						'id'      => (int) $row['id'],
						'version' => $version,
						'status'  => $status,
					),
					array( '%s', '%s', '%d', '%s', '%d', '%s', '%s' ),
					array( '%d', '%d', '%s' )
				);

				if ( 1 !== (int) $updated ) {
					$current = $this->get( (int) $row['id'] );

					if ( $current ) {
						$current['activity_stale'] = true;

						return $current;
					}

					return new WP_Error( 'nowcrm_cart_race', 'cart_activity_race' );
				}

				$this->invalidate_source( (int) $row['id'], $version, 'email_cleared' );

				return $this->get( (int) $row['id'] );
			},
			2
		);

		if ( is_wp_error( $result ) && 'nowcrm_cart_locked' === $result->get_error_code() ) {
			$existing['activity_stale'] = true;

			return $existing;
		}

		return $result;
	}

	/**
	 * Extend the idle window for a live checkout without changing its data.
	 *
	 * Only the owning session may touch the row, the observation must be newer
	 * than the stored one, and an inactive row is left to the capture path so
	 * reactivation keeps its version and job invalidation rules.
	 *
	 * @param string $cycle       Cart cycle key.
	 * @param string $session_key Bound Woo customer id.
	 * @param int    $order_ms    Client observation time.
	 * @return array<string, mixed>|WP_Error
	 */
	public function touch_activity( string $cycle, string $session_key, int $order_ms ) {
		global $wpdb;

		$cycle = substr( $cycle, 0, 64 );

		if ( '' === $cycle ) {
			return new WP_Error( 'nowcrm_cart_invalid', 'invalid_cart_activity' );
		}

		$existing = $this->find_by_cycle( $cycle );

		if ( ! $existing ) {
			return new WP_Error( 'nowcrm_cart_missing', 'cart_missing' );
		}

		$result = self::with_lock(
			(int) $existing['id'],
			function () use ( $cycle, $session_key, $order_ms ) {
				global $wpdb;

				$row = $this->find_by_cycle( $cycle );

				if ( ! $row ) {
					return new WP_Error( 'nowcrm_cart_missing', 'cart_missing' );
				}

				$status = (string) $row['status'];

				if ( in_array( $status, array( self::STATUS_COMPLETED, self::STATUS_RECOVERED ), true ) ) {
					return new WP_Error( 'nowcrm_cart_completed', 'cart_cycle_completed' );
				}

				if ( self::STATUS_ORDER_PENDING === $status ) {
					return new WP_Error( 'nowcrm_cart_order_pending', 'cart_order_pending' );
				}

				$session = substr( trim( $session_key ), 0, 191 );

				if ( '' === $session || $session !== (string) $row['session_key'] ) {
					return new WP_Error( 'nowcrm_cart_session_mismatch', 'cart_session_mismatch' );
				}

				$stored_ms = (int) ( $row['activity_order_ms'] ?? 0 );

				if ( $order_ms > 0 && $stored_ms > 0 && $order_ms <= $stored_ms ) {
					$row['activity_stale'] = true;

					return $row;
				}

				if ( self::STATUS_ACTIVE !== $status ) {
					return new WP_Error( 'nowcrm_cart_not_active', 'cart_not_active' );
				}

				$last = strtotime( (string) $row['last_activity_at'] . ' UTC' );

				// A heartbeat inside the server window changes nothing.
				if ( $last && $last > time() - self::ACTIVITY_TOUCH_SECONDS ) {
					return $row;
				}

				$now = gmdate( 'Y-m-d H:i:s' );

				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- fixed table and placeholders.
				$updated = $wpdb->update(
					Schema::table( 'carts' ),
					array(
						'last_activity_at'  => $now,
						'activity_order_ms' => max( $stored_ms, $order_ms ),
						'updated_at'        => $now,
					),
					array(
						'id'      => (int) $row['id'],
						'version' => (int) $row['version'],
					),
					array( '%s', '%d', '%s' ),
					array( '%d', '%d' )
				);

				if ( 1 !== (int) $updated ) {
					$current = $this->get( (int) $row['id'] );

					if ( $current ) {
						$current['activity_stale'] = true;

						return $current;
					}

					return new WP_Error( 'nowcrm_cart_race', 'cart_activity_race' );
				}

				return $this->get( (int) $row['id'] );
			},
			2
		);

		if ( is_wp_error( $result ) && 'nowcrm_cart_locked' === $result->get_error_code() ) {
			$existing['activity_stale'] = true;

			return $existing;
		}

		return $result;
	}

	/**
	 * Refresh one existing cart row while its cart lock is held.
	 *
	 * A Woo mutation must belong to the captured session, keeps the stored
	 * email and client watermark, and is a no-op when the server cart hash
	 * did not change. Ordered client activity keeps the newest observation.
	 *
	 * @param array<string, mixed> $data Activity payload.
	 * @return array<string, mixed>|WP_Error
	 */
	private function record_existing_activity( string $cycle, array $data, bool $woo ) {
		global $wpdb;

		$existing = $this->find_by_cycle( $cycle );

		if ( ! $existing ) {
			return new WP_Error( 'nowcrm_cart_missing', 'cart_missing' );
		}

		$status = (string) $existing['status'];

		if ( in_array( $status, array( self::STATUS_COMPLETED, self::STATUS_RECOVERED ), true ) ) {
			return new WP_Error( 'nowcrm_cart_completed', 'cart_cycle_completed' );
		}

		if ( self::STATUS_ORDER_PENDING === $status ) {
			return new WP_Error( 'nowcrm_cart_order_pending', 'cart_order_pending' );
		}

		$session = substr( trim( (string) ( $data['session_key'] ?? '' ) ), 0, 191 );
		$hash    = substr( (string) ( $data['cart_hash'] ?? '' ), 0, 64 );

		if ( $woo && '' === $hash ) {
			$hash = (string) $existing['cart_hash'];
		}

		if ( $woo ) {
			if ( '' === $session || $session !== (string) $existing['session_key'] ) {
				return new WP_Error( 'nowcrm_cart_session_mismatch', 'cart_session_mismatch' );
			}

			if ( $hash === (string) $existing['cart_hash'] ) {
				return $existing;
			}
		}

		$stored_ms = (int) ( $existing['activity_order_ms'] ?? 0 );
		$order_ms  = max( 0, (int) ( $data['activity_order_ms'] ?? 0 ) );

		if ( ! $woo && $order_ms > 0 && $stored_ms > 0 && $order_ms <= $stored_ms ) {
			$existing['activity_stale'] = true;

			return $existing;
		}

		$reactivating = in_array( $status, array( self::STATUS_ABANDONED, self::STATUS_LOST ), true );
		$version      = (int) $existing['version'];
		$now          = gmdate( 'Y-m-d H:i:s' );
		$table        = Schema::table( 'carts' );
		$email        = $woo ? (string) $existing['email'] : substr( (string) ( $data['email'] ?? '' ), 0, 191 );
		$normalized   = $woo ? (string) $existing['email_normalized'] : mb_strtolower( trim( (string) ( $data['email'] ?? '' ) ), 'UTF-8' );
		$email_change = ! $woo && $normalized !== mb_strtolower( trim( (string) $existing['email_normalized'] ), 'UTF-8' );

		// Replacing the address needs the session that bound this cycle. A
		// same-address snapshot refresh may still follow a rotated session.
		if ( $email_change && '' !== $session && '' !== (string) $existing['session_key'] && $session !== (string) $existing['session_key'] ) {
			return new WP_Error( 'nowcrm_cart_session_mismatch', 'cart_session_mismatch' );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- fixed table and placeholders.
		$updated = $wpdb->update(
			$table,
			array(
				'session_key'       => $woo ? (string) $existing['session_key'] : $session,
				'user_id'           => $woo ? ( $existing['user_id'] ?: null ) : ( ! empty( $data['user_id'] ) ? absint( $data['user_id'] ) : null ),
				'email'             => $email,
				'email_normalized'  => $normalized,
				'status'            => self::STATUS_ACTIVE,
				'cart_snapshot'     => wp_json_encode( $this->snapshot_envelope( $data['snapshot'] ?? array() ) ),
				'cart_hash'         => $hash,
				'item_count'        => absint( $data['item_count'] ?? 0 ),
				'total'             => (float) ( $data['total'] ?? 0 ),
				'currency'          => substr( strtoupper( (string) ( $data['currency'] ?? '' ) ), 0, 3 ),
				'version'           => $version + 1,
				'last_activity_at'  => $now,
				'abandoned_at'      => null,
				'reminder_due_at'   => null,
				'lost_at'           => null,
				'updated_at'        => $now,
				'activity_order_ms' => $woo ? $stored_ms : max( $stored_ms, $order_ms ),
			),
			array(
				'id'      => (int) $existing['id'],
				'version' => $version,
				'status'  => $status,
			),
			array( '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%f', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%d' ),
			array( '%d', '%d', '%s' )
		);

		if ( 1 !== (int) $updated ) {
			$current = $this->get( (int) $existing['id'] );

			if ( $current ) {
				$current['activity_stale'] = true;

				return $current;
			}

			return new WP_Error( 'nowcrm_cart_race', 'cart_activity_race' );
		}

		if ( $reactivating ) {
			$this->invalidate_source( (int) $existing['id'], $version, 'reactivated' );
		} elseif ( $email_change ) {
			// The old address in a queued job can no longer represent this cart.
			$this->invalidate_source( (int) $existing['id'], $version, 'email_changed' );
		}

		return $this->get( (int) $existing['id'] );
	}

	/**
	 * Store a canonical snapshot envelope and still accept a legacy list.
	 *
	 * @param mixed $snapshot List items or an envelope.
	 * @return array{items: array<int, mixed>, coupons?: array<int, string>}
	 */
	private function snapshot_envelope( $snapshot ): array {
		$snapshot = is_array( $snapshot ) ? $snapshot : array();
		$envelope = array();

		if ( array_key_exists( 'items', $snapshot ) || array_key_exists( 'coupons', $snapshot ) ) {
			$envelope['items'] = array_values( (array) ( $snapshot['items'] ?? array() ) );

			if ( array_key_exists( 'coupons', $snapshot ) ) {
				$envelope['coupons'] = array_values( (array) $snapshot['coupons'] );
			}

			return $envelope;
		}

		$envelope['items'] = array_values( $snapshot );

		return $envelope;
	}

	/**
	 * Fetch one cart.
	 *
	 * @return array<string, mixed>|null
	 */
	public function get( int $id ): ?array {
		global $wpdb;

		$table = Schema::table( 'carts' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );

		return $row ? $this->cast( $row ) : null;
	}

	/**
	 * Fetch by stable cycle key.
	 *
	 * @return array<string, mixed>|null
	 */
	public function find_by_cycle( string $cycle ): ?array {
		global $wpdb;

		$table = Schema::table( 'carts' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE cart_cycle_key = %s", $cycle ), ARRAY_A );

		return $row ? $this->cast( $row ) : null;
	}

	/**
	 * Active carts idle long enough to be abandoned.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function due_for_abandon( int $idle_minutes, int $limit = 100 ): array {
		global $wpdb;

		$table   = Schema::table( 'carts' );
		$cutoff  = gmdate( 'Y-m-d H:i:s', time() - max( 1, $idle_minutes ) * MINUTE_IN_SECONDS );
		$limit   = max( 1, min( 500, $limit ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- trusted table and integer limit.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE status = %s AND item_count > 0 AND last_activity_at <= %s ORDER BY last_activity_at ASC LIMIT {$limit}",
				self::STATUS_ACTIVE,
				$cutoff
			),
			ARRAY_A
		);

		return array_map( array( $this, 'cast' ), (array) $rows );
	}

	/**
	 * Abandoned carts whose reminder time has arrived.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function due_for_reminder( int $limit = 100 ): array {
		global $wpdb;

		$table = Schema::table( 'carts' );
		$now   = gmdate( 'Y-m-d H:i:s' );
		$limit = max( 1, min( 500, $limit ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- trusted table and integer limit.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table}
				WHERE status = %s AND item_count > 0 AND reminder_due_at IS NOT NULL AND reminder_due_at <= %s
				ORDER BY reminder_due_at ASC LIMIT {$limit}",
				self::STATUS_ABANDONED,
				$now
			),
			ARRAY_A
		);

		return array_map( array( $this, 'cast' ), (array) $rows );
	}

	/**
	 * Abandoned carts older than the lost window.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function due_for_lost( int $lost_days, int $limit = 100 ): array {
		global $wpdb;

		$table  = Schema::table( 'carts' );
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - max( 1, $lost_days ) * DAY_IN_SECONDS );
		$limit  = max( 1, min( 500, $limit ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- trusted table and integer limit.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE status = %s AND item_count > 0 AND abandoned_at IS NOT NULL AND abandoned_at <= %s ORDER BY abandoned_at ASC LIMIT {$limit}",
				self::STATUS_ABANDONED,
				$cutoff
			),
			ARRAY_A
		);

		return array_map( array( $this, 'cast' ), (array) $rows );
	}

	/**
	 * Mark a cart abandoned and set its reminder time.
	 *
	 * The update only wins while the row is still active at the selected
	 * version (and before the cutoff). Only the winner records the event.
	 *
	 * @param int    $id               Cart ID.
	 * @param int    $expected_version Version selected by the sweeper, 0 to use the current row.
	 * @param string $cutoff           UTC "Y-m-d H:i:s" last-activity cutoff, empty to skip.
	 */
	public function mark_abandoned( int $id, int $expected_version = 0, string $cutoff = '' ): bool {
		global $wpdb;

		$cart = $this->get( $id );

		if ( ! $cart || self::STATUS_ACTIVE !== (string) $cart['status'] ) {
			return false;
		}

		$version = (int) $cart['version'];

		if ( $expected_version > 0 && $expected_version !== $version ) {
			return false;
		}

		$table = Schema::table( 'carts' );
		$now   = gmdate( 'Y-m-d H:i:s' );
		$sql   = "UPDATE {$table}
			SET status = %s, abandoned_at = %s, reminder_due_at = %s, updated_at = %s
			WHERE id = %d AND status = %s AND version = %d";
		$args  = array(
			self::STATUS_ABANDONED,
			$now,
			gmdate( 'Y-m-d H:i:s', time() + \NowCRM\Support\Settings::int( 'cart_reminder_delay_minutes' ) * MINUTE_IN_SECONDS ),
			$now,
			$id,
			self::STATUS_ACTIVE,
			$version,
		);

		if ( '' !== $cutoff ) {
			$sql   .= ' AND last_activity_at <= %s';
			$args[] = $cutoff;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- fixed table and placeholders.
		$updated = $wpdb->query( $wpdb->prepare( $sql, $args ) );

		if ( 1 !== (int) $updated ) {
			return false;
		}

		$this->record_event( $id, 'abandoned', array( 'version' => $version ) );

		return true;
	}

	/**
	 * Mark a cart lost and stop reminders.
	 *
	 * @param int $id               Cart ID.
	 * @param int $expected_version Version selected by the sweeper, 0 to use the current row.
	 */
	public function mark_lost( int $id, int $expected_version = 0 ): bool {
		$result = self::with_lock(
			$id,
			function () use ( $id, $expected_version ): bool {
				global $wpdb;

				$cart = $this->get( $id );

				if ( ! $cart || self::STATUS_ABANDONED !== (string) $cart['status'] ) {
					return false;
				}

				$version = (int) $cart['version'];

				if ( $expected_version > 0 && $expected_version !== $version ) {
					return false;
				}

				$table = Schema::table( 'carts' );
				$now   = gmdate( 'Y-m-d H:i:s' );

				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- fixed table and placeholders.
				$updated = $wpdb->query(
					$wpdb->prepare(
						"UPDATE {$table}
						SET status = %s, lost_at = %s, reminder_due_at = NULL, updated_at = %s
						WHERE id = %d AND status = %s AND version = %d",
						self::STATUS_LOST,
						$now,
						$now,
						$id,
						self::STATUS_ABANDONED,
						$version
					)
				);

				if ( 1 !== (int) $updated ) {
					return false;
				}

				$this->record_event( $id, 'lost', array( 'version' => $version ) );

				// The lost transition is the single cancellation owner. The
				// sweeper does not cancel again, so no duplicate race exists.
				( new EmailJobRepository() )->cancel_cart( $id, 'cart_lost' );
				( new RunRepository() )->cancel_open_for_cart( $id, 'cart_lost' );

				return true;
			},
			self::TRANSITION_LOCK_TIMEOUT
		);

		return true === $result;
	}

	/**
	 * Mark a reminder as sent.
	 */
	public function mark_reminder_sent( int $id ): void {
		global $wpdb;

		$table  = Schema::table( 'carts' );
		$now    = gmdate( 'Y-m-d H:i:s' );
		$cart   = $this->get( $id );

		if ( ! $cart ) {
			return;
		}

		$wpdb->update(
			$table,
			array(
				'reminders_sent'  => (int) $cart['reminders_sent'] + 1,
				'last_reminder_at' => $now,
				'reminder_due_at' => null,
				'updated_at'      => $now,
			),
			array( 'id' => $id ),
			array( '%d', '%s', '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Store a hashed recovery token.
	 */
	public function set_recovery_token( int $id, string $hash, string $expires_at ): void {
		global $wpdb;

		$wpdb->update(
			Schema::table( 'carts' ),
			array(
				'recovery_token_hash'    => $hash,
				'recovery_token_expires' => $expires_at,
				'updated_at'             => gmdate( 'Y-m-d H:i:s' ),
			),
			array( 'id' => $id ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Find a cart by raw recovery token.
	 *
	 * @return array<string, mixed>|null
	 */
	public function find_by_recovery_token( string $raw ): ?array {
		global $wpdb;

		if ( '' === $raw ) {
			return null;
		}

		$table = Schema::table( 'carts' );

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE recovery_token_hash = %s AND recovery_token_expires >= %s",
				hash( 'sha256', $raw ),
				gmdate( 'Y-m-d H:i:s' )
			),
			ARRAY_A
		);

		return $row ? $this->cast( $row ) : null;
	}

	/**
	 * Move a cart to order_pending when Woo creates the exact linked order.
	 *
	 * The state is not paid. It stops reminders and blocks late activity until
	 * an explicit payment confirmation arrives.
	 *
	 * @param int    $id               Cart ID.
	 * @param int    $order_id         Woo order ID.
	 * @param string $source           natural or email_click.
	 * @param int    $expected_version Optional immutable version precondition.
	 */
	public function mark_order_pending( int $id, int $order_id, string $source, int $expected_version = 0 ): bool {
		if ( $order_id <= 0 ) {
			return false;
		}

		$source = 'email_click' === $source ? 'email_click' : 'natural';

		$result = self::with_lock(
			$id,
			function () use ( $id, $order_id, $source, $expected_version ): bool {
				global $wpdb;

				$cart = $this->get( $id );

				if ( ! $cart ) {
					return false;
				}

				$status = (string) $cart['status'];

				if ( self::STATUS_ORDER_PENDING === $status ) {
					return (int) $cart['recovered_order_id'] === $order_id;
				}

				if ( ! in_array( $status, array( self::STATUS_ACTIVE, self::STATUS_ABANDONED, self::STATUS_LOST ), true ) ) {
					return false;
				}

				$version = (int) $cart['version'];

				if ( $expected_version > 0 && $expected_version !== $version ) {
					return false;
				}

				$table = Schema::table( 'carts' );
				$now   = gmdate( 'Y-m-d H:i:s' );

				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- fixed table and placeholders.
				$updated = $wpdb->query(
					$wpdb->prepare(
						"UPDATE {$table}
						SET status = %s, recovered_order_id = %d, recovery_source = %s,
							reminder_due_at = NULL, version = %d, updated_at = %s
						WHERE id = %d AND status = %s AND version = %d",
						self::STATUS_ORDER_PENDING,
						$order_id,
						$source,
						$version + 1,
						$now,
						$id,
						$status,
						$version
					)
				);

				if ( 1 !== (int) $updated ) {
					return false;
				}

				( new EmailJobRepository() )->cancel_cart( $id, 'cart_order_pending' );
				( new RunRepository() )->cancel_open_for_cart( $id, 'cart_order_pending' );

				$this->record_event(
					$id,
					'order_placed',
					array(
						'order_id' => $order_id,
						'source'   => $source,
						'version'  => $version + 1,
					)
				);

				do_action( 'nowcrm_cart_order_placed', $id, $order_id, $source );

				return true;
			},
			self::TRANSITION_LOCK_TIMEOUT
		);

		return true === $result;
	}

	/**
	 * Complete a cart with a paid order.
	 *
	 * Call this only after Woo evidence proves payment. Only a link click may
	 * produce "recovered"; a natural order is "completed". The completion hook
	 * fires once, from the winning transition.
	 *
	 * @param int    $id               Cart ID.
	 * @param int    $order_id         Woo order ID.
	 * @param string $source           natural or email_click.
	 * @param int    $expected_version Optional immutable version precondition.
	 */
	public function complete_with_order( int $id, int $order_id, string $source, int $expected_version = 0 ): bool {
		if ( $order_id <= 0 ) {
			return false;
		}

		$source = 'email_click' === $source ? 'email_click' : 'natural';

		$result = self::with_lock(
			$id,
			function () use ( $id, $order_id, $source, $expected_version ): bool {
				global $wpdb;

				$cart = $this->get( $id );

				if ( ! $cart ) {
					return false;
				}

				$status = (string) $cart['status'];

				if ( in_array( $status, array( self::STATUS_COMPLETED, self::STATUS_RECOVERED ), true ) ) {
					return (int) $cart['recovered_order_id'] === $order_id;
				}

				if ( self::STATUS_ORDER_PENDING === $status ) {
					if ( (int) $cart['recovered_order_id'] !== $order_id ) {
						return false;
					}
				} elseif ( ! in_array( $status, array( self::STATUS_ACTIVE, self::STATUS_ABANDONED, self::STATUS_LOST ), true ) ) {
					return false;
				}

				$version = (int) $cart['version'];

				if ( $expected_version > 0 && $expected_version !== $version ) {
					return false;
				}

				$table = Schema::table( 'carts' );
				$now   = gmdate( 'Y-m-d H:i:s' );
				$state = 'email_click' === $source ? self::STATUS_RECOVERED : self::STATUS_COMPLETED;

				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- fixed table and placeholders.
				$updated = $wpdb->query(
					$wpdb->prepare(
						"UPDATE {$table}
						SET status = %s, recovered_order_id = %d, recovery_source = %s,
							reminder_due_at = NULL, version = %d, updated_at = %s
						WHERE id = %d AND status = %s AND version = %d",
						$state,
						$order_id,
						$source,
						$version + 1,
						$now,
						$id,
						$status,
						$version
					)
				);

				if ( 1 !== (int) $updated ) {
					return false;
				}

				( new EmailJobRepository() )->cancel_cart( $id, 'cart_completed' );
				( new RunRepository() )->cancel_open_for_cart( $id, 'cart_completed' );

				$this->record_event(
					$id,
					'order_paid',
					array(
						'order_id' => $order_id,
						'source'   => $source,
						'version'  => $version + 1,
					)
				);
				$this->record_event( $id, 'order_' . $source, array( 'order_id' => $order_id ) );

				do_action( 'nowcrm_cart_completed', $id, $order_id, $source );

				return true;
			},
			self::TRANSITION_LOCK_TIMEOUT
		);

		return true === $result;
	}

	/**
	 * Record a failed or cancelled payment for the linked pending order.
	 *
	 * The cart stays order_pending so queued work cannot revive and no new
	 * email starts. A later payment callback can still complete it exactly.
	 *
	 * @param int    $id       Cart ID.
	 * @param int    $order_id Woo order ID.
	 * @param string $reason   Woo status or gateway reason.
	 */
	public function mark_order_failed( int $id, int $order_id, string $reason ): bool {
		if ( $order_id <= 0 ) {
			return false;
		}

		$result = self::with_lock(
			$id,
			function () use ( $id, $order_id, $reason ): bool {
				$cart = $this->get( $id );

				if ( ! $cart || (int) $cart['recovered_order_id'] !== $order_id ) {
					return false;
				}

				$status = (string) $cart['status'];

				if ( self::STATUS_ORDER_PENDING !== $status ) {
					return false;
				}

				if ( $this->has_event( $id, 'order_failed' ) ) {
					return false;
				}

				$this->cancel_queued( $id, 'cart_order_failed' );
				$this->record_event(
					$id,
					'order_failed',
					array(
						'order_id' => $order_id,
						'reason'   => substr( sanitize_key( $reason ), 0, 40 ),
					)
				);

				return true;
			},
			self::TRANSITION_LOCK_TIMEOUT
		);

		return true === $result;
	}

	/**
	 * Adopt a verified recovery link into the current Woo session.
	 *
	 * The caller must pass the exact row it resolved from the recovery token.
	 * A stale copy, another status, or another token hash is refused without a
	 * write. The winner makes the cart active, transfers the session key, and
	 * invalidates the unsent source.
	 *
	 * @param array<string, mixed> $cart        Row resolved from the token.
	 * @param string               $session_key Woo customer ID of the adopting session.
	 */
	public function adopt_recovery( array $cart, string $session_key ): bool {
		$id          = (int) ( $cart['id'] ?? 0 );
		$expected    = (int) ( $cart['version'] ?? 0 );
		$session_key = substr( trim( $session_key ), 0, 191 );

		if ( $id <= 0 || $expected <= 0 || '' === $session_key ) {
			return false;
		}

		$result = self::with_lock(
			$id,
			function () use ( $id, $expected, $session_key, $cart ): bool {
				global $wpdb;

				$current = $this->get( $id );

				if ( ! $current || (int) $current['version'] !== $expected ) {
					return false;
				}

				if ( isset( $cart['cart_cycle_key'] ) && (string) $cart['cart_cycle_key'] !== (string) $current['cart_cycle_key'] ) {
					return false;
				}

				if ( isset( $cart['status'] ) && (string) $cart['status'] !== (string) $current['status'] ) {
					return false;
				}

				$hash = (string) ( $cart['recovery_token_hash'] ?? '' );

				if ( '' !== $hash && $hash !== (string) $current['recovery_token_hash'] ) {
					return false;
				}

				$status = (string) $current['status'];

				if ( ! in_array( $status, array( self::STATUS_ACTIVE, self::STATUS_ABANDONED, self::STATUS_LOST ), true ) ) {
					return false;
				}

				$table   = Schema::table( 'carts' );
				$now     = gmdate( 'Y-m-d H:i:s' );
				$version = (int) $current['version'];

				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- fixed table and placeholders.
				$updated = $wpdb->query(
					$wpdb->prepare(
						"UPDATE {$table}
						SET status = %s, session_key = %s, version = %d,
							abandoned_at = NULL, reminder_due_at = NULL, lost_at = NULL,
							last_activity_at = %s, activity_order_ms = %d, updated_at = %s
						WHERE id = %d AND status = %s AND version = %d",
						self::STATUS_ACTIVE,
						$session_key,
						$version + 1,
						$now,
						max( (int) $current['activity_order_ms'], (int) floor( microtime( true ) * 1000 ) ),
						$now,
						$id,
						$status,
						$version
					)
				);

				if ( 1 !== (int) $updated ) {
					return false;
				}

				$this->cancel_queued( $id, 'cart_recovered' );
				$this->record_event( $id, 'cart_restored', array( 'version' => $version + 1 ) );
				do_action( 'nowcrm_cart_source_invalidated', $id, $version, 'recovered' );

				return true;
			},
			self::TRANSITION_LOCK_TIMEOUT
		);

		return true === $result;
	}

	/**
	 * Cancel unsent cart jobs and tell the automation owner that the source is
	 * no longer valid.
	 */
	private function invalidate_source( int $id, int $source_version, string $reason ): void {
		$this->cancel_queued( $id, 'cart_' . $reason );
		$this->record_event( $id, $reason, array( 'version' => $source_version ) );
		do_action( 'nowcrm_cart_source_invalidated', $id, $source_version, $reason );
	}

	/**
	 * Cancel queued or sending jobs for one cart only.
	 */
	private function cancel_queued( int $id, string $reason ): void {
		( new EmailJobRepository() )->cancel_cart( $id, $reason );
	}

	/**
	 * Whether one event type already exists for a cart.
	 */
	private function has_event( int $id, string $type ): bool {
		global $wpdb;

		$table = Schema::table( 'cart_events' );

		return (bool) $wpdb->get_var(
			$wpdb->prepare( "SELECT 1 FROM {$table} WHERE cart_id = %d AND type = %s LIMIT 1", $id, $type )
		);
	}

	/**
	 * Append a cart event.
	 */
	public function record_event( int $cart_id, string $type, array $meta = array() ): void {
		global $wpdb;

		$wpdb->insert(
			Schema::table( 'cart_events' ),
			array(
				'cart_id'     => $cart_id,
				'type'        => substr( sanitize_key( $type ), 0, 30 ),
				'meta'        => wp_json_encode( $meta ),
				'occurred_at' => gmdate( 'Y-m-d H:i:s' ),
			),
			array( '%d', '%s', '%s', '%s' )
		);
	}

	/**
	 * Events recorded for one cart, newest first.
	 *
	 * Event meta is deliberately not returned: it can contain recovery job and
	 * order references that the detail screen does not need to expose.
	 *
	 * @return array<int, array{id: int, cart_id: int, type: string, occurred_at: string}>
	 */
	public function events( int $cart_id, int $limit = 50 ): array {
		global $wpdb;

		if ( $cart_id <= 0 ) {
			return array();
		}

		$table = Schema::table( 'cart_events' );
		$limit = max( 1, min( 200, $limit ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- trusted table and integer limit.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, cart_id, type, occurred_at FROM {$table} WHERE cart_id = %d ORDER BY occurred_at DESC, id DESC LIMIT {$limit}",
				$cart_id
			),
			ARRAY_A
		);

		return array_map(
			static function ( array $row ): array {
				return array(
					'id'          => (int) $row['id'],
					'cart_id'     => (int) $row['cart_id'],
					'type'        => (string) $row['type'],
					'occurred_at' => (string) $row['occurred_at'],
				);
			},
			(array) $rows
		);
	}

	/**
	 * Recent carts for the admin screen.
	 *
	 * @param array<string, mixed> $args status, email, limit, offset.
	 * @return array{items: array<int, array<string, mixed>>, total: int}
	 */
	public function search( array $args = array() ): array {
		global $wpdb;

		$table  = Schema::table( 'carts' );
		$where  = array( '1=1' );
		$params = array();

		if ( ! empty( $args['status'] ) ) {
			$where[]  = 'status = %s';
			$params[] = sanitize_key( (string) $args['status'] );
		}

		if ( ! empty( $args['email'] ) ) {
			$where[]  = 'email_normalized = %s';
			$params[] = mb_strtolower( trim( (string) $args['email'] ), 'UTF-8' );
		}

		$clause = implode( ' AND ', $where );

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- clause is built from fixed fragments; table name is trusted and values use placeholders.
		$total = (int) $wpdb->get_var(
			$params ? $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$clause}", $params ) : "SELECT COUNT(*) FROM {$table} WHERE {$clause}"
		);
		// phpcs:enable

		$limit  = max( 1, min( 200, (int) ( $args['limit'] ?? 50 ) ) );
		$offset = max( 0, (int) ( $args['offset'] ?? 0 ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- fixed fragments.
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
	 * Funnel counters.
	 *
	 * @return array<string, int>
	 */
	public function stats(): array {
		global $wpdb;

		$table = Schema::table( 'carts' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- trusted table.
		$rows = $wpdb->get_results( "SELECT status, COUNT(*) AS total FROM {$table} GROUP BY status", ARRAY_A );

		$stats = array(
			self::STATUS_ACTIVE    => 0,
			self::STATUS_ABANDONED => 0,
			self::STATUS_LOST      => 0,
			self::STATUS_COMPLETED => 0,
			self::STATUS_RECOVERED => 0,
			'total'                => 0,
		);

		foreach ( (array) $rows as $row ) {
			$stats[ (string) $row['status'] ]  = (int) $row['total'];
			$stats['total']                   += (int) $row['total'];
		}

		return $stats;
	}

	/**
	 * Last reminder time for an address across all cart cycles.
	 */
	public function last_reminder_at( string $email ): ?string {
		global $wpdb;

		$table = Schema::table( 'carts' );

		$value = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT MAX(last_reminder_at) FROM {$table} WHERE email_normalized = %s",
				mb_strtolower( trim( $email ), 'UTF-8' )
			)
		);

		return $value ? (string) $value : null;
	}

	/**
	 * Cast a row.
	 *
	 * @param array<string, mixed> $row Raw row.
	 * @return array<string, mixed>
	 */
	public function cast( array $row ): array {
		foreach ( array( 'id', 'user_id', 'item_count', 'version', 'reminders_sent', 'recovered_order_id', 'activity_order_ms' ) as $key ) {
			$row[ $key ] = isset( $row[ $key ] ) ? (int) $row[ $key ] : 0;
		}

		$row['total'] = isset( $row['total'] ) ? (float) $row['total'] : 0.0;
		$snapshot     = json_decode( (string) ( $row['cart_snapshot'] ?? '' ), true );
		$snapshot     = is_array( $snapshot ) ? $snapshot : array();

		if ( array_key_exists( 'items', $snapshot ) || array_key_exists( 'coupons', $snapshot ) ) {
			$row['items'] = array_values( (array) ( $snapshot['items'] ?? array() ) );

			if ( array_key_exists( 'coupons', $snapshot ) ) {
				$row['coupons'] = array_values( (array) $snapshot['coupons'] );
			}
		} else {
			// Pre-envelope rows stored a bare item list.
			$row['items'] = array_values( $snapshot );
		}

		return $row;
	}
}
