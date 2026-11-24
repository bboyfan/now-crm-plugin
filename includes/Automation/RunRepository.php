<?php
/**
 * Durable automation runs and step events.
 *
 * One row per automation, contact, and entry key. Ordinary contact triggers
 * reuse the empty entry key; cart runs use the immutable cart source key.
 * Re-entry resets a terminal ordinary row unless the automation requires
 * run-once. Step events are unique per run and step and are written before the
 * action executes, so a retry never repeats an action.
 *
 * @package NowCRM
 */

namespace NowCRM\Automation;

use NowCRM\Support\Schema;
use WP_Error;

defined( 'ABSPATH' ) || exit;

final class RunRepository {

	public const STATUS_ACTIVE    = 'active';
	public const STATUS_WAITING   = 'waiting';
	public const STATUS_COMPLETED = 'completed';
	public const STATUS_CANCELLED = 'cancelled';
	public const STATUS_FAILED    = 'failed';

	public const EVENT_COMPLETED   = 'completed';
	public const EVENT_WAITING     = 'waiting';
	public const EVENT_IN_PROGRESS = 'in_progress';
	public const EVENT_SKIPPED     = 'skipped';
	public const EVENT_FAILED      = 'failed';
	public const EVENT_TIMEOUT     = 'timeout';
	public const EVENT_INTERRUPTED = 'interrupted';

	/**
	 * Fetch one run.
	 *
	 * @return array<string, mixed>|null
	 */
	public function get( int $id ): ?array {
		global $wpdb;

		$table = Schema::table( 'automation_runs' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );

		return $row ? $this->cast( $row ) : null;
	}

	/**
	 * Fetch the ordinary run of one automation and contact.
	 *
	 * Cart runs are source-scoped and never reused as the contact-level run.
	 * Legacy rows keep their contact_id and history, but a row written by the
	 * cart trigger is not the ordinary re-entry target.
	 *
	 * @return array<string, mixed>|null
	 */
	public function find( int $automation_id, int $contact_id ): ?array {
		global $wpdb;

		$table = Schema::table( 'automation_runs' );
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE automation_id = %d AND contact_id = %d AND entry_key = %s AND source_trigger <> %s ORDER BY id DESC LIMIT 1",
				$automation_id,
				$contact_id,
				'',
				'cart_abandoned'
			),
			ARRAY_A
		);

		return $row ? $this->cast( $row ) : null;
	}

	/**
	 * Fetch the run of one immutable cart source.
	 *
	 * @return array<string, mixed>|null
	 */
	public function find_by_source_key( string $source_key ): ?array {
		global $wpdb;

		if ( '' === $source_key || strlen( $source_key ) > 191 ) {
			return null;
		}

		$table = Schema::table( 'automation_runs' );
		$row   = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE source_key = %s ORDER BY id ASC LIMIT 1", $source_key ),
			ARRAY_A
		);

		return $row ? $this->cast( $row ) : null;
	}

	/**
	 * Runs whose source is one cart, newest first.
	 *
	 * The cart column is the fast path. Legacy rows written before the column
	 * existed have no cart_id, so an exact `cart:<id>` source reference is
	 * checked only after the bounded column query returns fewer rows.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function for_cart( int $cart_id, int $limit = 20 ): array {
		global $wpdb;

		if ( $cart_id <= 0 ) {
			return array();
		}

		$table = Schema::table( 'automation_runs' );
		$limit = max( 1, min( 100, $limit ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is trusted.
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE cart_id = %d ORDER BY id DESC LIMIT %d", $cart_id, $limit ),
			ARRAY_A
		);

		$rows = (array) $rows;

		if ( count( $rows ) < $limit ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is trusted.
			$legacy = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE cart_id IS NULL AND source_trigger = %s AND source_ref = %s ORDER BY id DESC LIMIT %d",
					'cart_abandoned',
					'cart:' . $cart_id,
					$limit - count( $rows )
				),
				ARRAY_A
			);

			foreach ( (array) $legacy as $row ) {
				$rows[] = $row;
			}
		}

		usort(
			$rows,
			static fn( array $left, array $right ): int => (int) $right['id'] <=> (int) $left['id']
		);

		return array_map( array( $this, 'cast' ), $rows );
	}

	/**
	 * Every open run of one cart, including exact legacy `cart:<id>` rows.
	 *
	 * No fixed page size: a cart can own several versions and branches, and a
	 * terminal transition must cancel all of them.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function open_for_cart( int $cart_id ): array {
		global $wpdb;

		if ( $cart_id <= 0 ) {
			return array();
		}

		$table = Schema::table( 'automation_runs' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is trusted.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table}
				WHERE status IN (%s, %s)
					AND ( cart_id = %d OR ( cart_id IS NULL AND source_trigger = %s AND source_ref = %s ) )
				ORDER BY id ASC",
				self::STATUS_ACTIVE,
				self::STATUS_WAITING,
				$cart_id,
				'cart_abandoned',
				'cart:' . $cart_id
			),
			ARRAY_A
		);

		return array_map( array( $this, 'cast' ), (array) $rows );
	}

	/**
	 * Cancel every open run of one cart.
	 *
	 * The compare-and-set in cancel() keeps a terminal run untouched, so two
	 * cancellation callers cannot rewrite the same outcome.
	 */
	public function cancel_open_for_cart( int $cart_id, string $reason ): int {
		$cancelled = 0;

		foreach ( $this->open_for_cart( $cart_id ) as $run ) {
			if ( $this->cancel( (int) $run['id'], $reason ) ) {
				++$cancelled;
			}
		}

		return $cancelled;
	}

	/**
	 * Whether the automation already ran for this exact contact.
	 *
	 * run_once is enforced across ordinary and cart sources, so relevant
	 * prior rows of any source count.
	 */
	public function has_run( int $automation_id, int $contact_id ): bool {
		global $wpdb;

		if ( $automation_id <= 0 || $contact_id <= 0 ) {
			return false;
		}

		$table = Schema::table( 'automation_runs' );

		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT 1 FROM {$table} WHERE automation_id = %d AND contact_id = %d LIMIT 1",
				$automation_id,
				$contact_id
			)
		);
	}

	/**
	 * Newest run of one automation and contact, from any source.
	 *
	 * @return array<string, mixed>|null
	 */
	private function first_run( int $automation_id, int $contact_id ): ?array {
		global $wpdb;

		$table = Schema::table( 'automation_runs' );
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE automation_id = %d AND contact_id = %d ORDER BY id DESC LIMIT 1",
				$automation_id,
				$contact_id
			),
			ARRAY_A
		);

		return $row ? $this->cast( $row ) : null;
	}

	/**
	 * Paginated runs of one automation.
	 *
	 * @param int $automation_id Automation ID.
	 * @param int $page          Page number.
	 * @param int $per_page      Rows per page.
	 * @return array{items: array<int, array<string, mixed>>, total: int}
	 */
	public function paginate( int $automation_id, int $page = 1, int $per_page = 20 ): array {
		global $wpdb;

		$table    = Schema::table( 'automation_runs' );
		$page     = max( 1, $page );
		$per_page = max( 1, min( 100, $per_page ) );
		$offset   = ( $page - 1 ) * $per_page;

		$total = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE automation_id = %d", $automation_id )
		);

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE automation_id = %d ORDER BY id DESC LIMIT %d OFFSET %d",
				$automation_id,
				$per_page,
				$offset
			),
			ARRAY_A
		);

		return array(
			'items' => array_map( array( $this, 'cast' ), (array) $rows ),
			'total' => $total,
		);
	}

	/**
	 * Filtered, paginated runs of one automation.
	 *
	 * The contact search matches email, first name, and last name literally.
	 * A run outlives its contact, so the join is a LEFT JOIN: unfiltered and
	 * status-filtered history keeps runs whose contact is gone, while a search
	 * for a name or email still excludes them because no contact row matches.
	 * The page clamps to the last page so an out-of-range link never shows an
	 * empty table while rows exist.
	 *
	 * @param int                  $automation_id Automation ID.
	 * @param array<string, mixed> $args          status, search, page, per_page.
	 * @return array{items: array<int, array<string, mixed>>, total: int, page: int, pages: int}
	 */
	public function paginate_filtered( int $automation_id, array $args = array() ): array {
		global $wpdb;

		$runs     = Schema::table( 'automation_runs' );
		$contacts = Schema::table( 'contacts' );
		$status   = sanitize_key( (string) ( $args['status'] ?? '' ) );
		$search   = trim( (string) ( $args['search'] ?? '' ) );
		$page     = max( 1, (int) ( $args['page'] ?? 1 ) );
		$per_page = max( 1, min( 100, (int) ( $args['per_page'] ?? 25 ) ) );
		$where    = array( 'r.automation_id = %d' );
		$params   = array( $automation_id );

		if ( in_array( $status, array( self::STATUS_ACTIVE, self::STATUS_WAITING, self::STATUS_COMPLETED, self::STATUS_CANCELLED, self::STATUS_FAILED ), true ) ) {
			$where[]  = 'r.status = %s';
			$params[] = $status;
		} else {
			$status = '';
		}

		if ( '' !== $search ) {
			$like     = '%' . $wpdb->esc_like( $search ) . '%';
			$where[]  = '(c.email LIKE %s OR c.first_name LIKE %s OR c.last_name LIKE %s)';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		$clause = implode( ' AND ', $where );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- table names are trusted and the clause uses placeholders.
		$total = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$runs} r LEFT JOIN {$contacts} c ON c.id = r.contact_id WHERE {$clause}",
				$params
			)
		);

		$pages = (int) ceil( $total / $per_page );

		if ( $pages > 0 && $page > $pages ) {
			$page = $pages;
		}

		if ( 0 === $total ) {
			$page = 1;
		}

		$params[] = $per_page;
		$params[] = ( $page - 1 ) * $per_page;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT r.* FROM {$runs} r LEFT JOIN {$contacts} c ON c.id = r.contact_id WHERE {$clause} ORDER BY r.id DESC LIMIT %d OFFSET %d",
				$params
			),
			ARRAY_A
		);
		// phpcs:enable

		return array(
			'items' => array_map( array( $this, 'cast' ), (array) $rows ),
			'total' => $total,
			'page'  => $page,
			'pages' => $pages,
		);
	}

	/**
	 * Cancel one open run with a compare-and-set.
	 *
	 * Only active or waiting runs change. A terminal run keeps its state, so a
	 * second request cannot rewrite history.
	 */
	public function cancel_if_open( int $run_id ): bool {
		global $wpdb;

		$now     = gmdate( 'Y-m-d H:i:s' );
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name is trusted and every value uses a prepare() placeholder.
		$updated = $wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . Schema::table( 'automation_runs' ) . ' SET status = %s, next_run_at = NULL, last_error = %s, finished_at = %s, updated_at = %s WHERE id = %d AND status IN (%s, %s)',
				self::STATUS_CANCELLED,
				__( '由管理員取消。', 'now-crm' ),
				$now,
				$now,
				$run_id,
				self::STATUS_ACTIVE,
				self::STATUS_WAITING
			)
		);
		// phpcs:enable

		return 1 === (int) $updated;
	}

	/**
	 * Retry the failed next step of one run.
	 *
	 * The failed marker is removed first; completed step markers stay, so a
	 * retry never repeats an action that already succeeded. The status change
	 * is a compare-and-set, so two retries cannot both start the run.
	 *
	 * A step event that is still in progress or was interrupted has an unknown
	 * outcome: retrying could repeat effects that already happened, so the
	 * retry is rejected instead of replaying it.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public function retry_failed( int $run_id ) {
		global $wpdb;

		$run = $this->get( $run_id );

		if ( ! $run ) {
			return new WP_Error( 'nowcrm_run_not_found', __( '找不到執行紀錄。', 'now-crm' ) );
		}

		if ( self::STATUS_FAILED !== $run['status'] ) {
			return new WP_Error( 'nowcrm_run_not_retryable', __( '只有失敗的執行可以重試。', 'now-crm' ) );
		}

		$step_id = (int) ( $run['next_step_id'] ?? 0 );

		if ( $step_id <= 0 ) {
			return new WP_Error( 'nowcrm_run_no_step', __( '這次執行沒有可重試的步驟。', 'now-crm' ) );
		}

		$event = $this->event_for_step( $run_id, $step_id );

		if ( $event && in_array( (string) $event['status'], array( self::EVENT_IN_PROGRESS, self::EVENT_INTERRUPTED ), true ) ) {
			return new WP_Error(
				'nowcrm_run_outcome_unknown',
				__( '步驟結果不明，無法安全重試。請先確認動作是否已完成。', 'now-crm' )
			);
		}

		$wpdb->delete(
			Schema::table( 'automation_run_events' ),
			array(
				'run_id'  => $run_id,
				'step_id' => $step_id,
				'status'  => self::EVENT_FAILED,
			),
			array( '%d', '%d', '%s' )
		);

		$now     = gmdate( 'Y-m-d H:i:s' );
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name is trusted and every value uses a prepare() placeholder.
		$updated = $wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . Schema::table( 'automation_runs' ) . ' SET status = %s, next_run_at = %s, last_error = NULL, finished_at = NULL, updated_at = %s WHERE id = %d AND status = %s',
				self::STATUS_ACTIVE,
				$now,
				$now,
				$run_id,
				self::STATUS_FAILED
			)
		);
		// phpcs:enable

		if ( 1 !== (int) $updated ) {
			return new WP_Error( 'nowcrm_run_not_retryable', __( '這次執行狀態已改變，請重新整理。', 'now-crm' ) );
		}

		return $this->get( $run_id ) ?? array();
	}

	/**
	 * Branch entry and exit counts for every split step.
	 *
	 * A branch is entered when the split event stores that branch. It is exited
	 * when the same run has a later event on a step outside that branch's step
	 * subtree. Runs that end inside the branch never count as an exit, so these
	 * numbers are event-graph facts, not overall run status.
	 *
	 * @param int                              $automation_id Automation ID.
	 * @param array<int, array<string, mixed>> $steps         Every step row of the automation.
	 * @return array<int, array<string, array{entered: int, completed: int}>>
	 */
	public function branch_stats( int $automation_id, array $steps ): array {
		global $wpdb;

		$splits = array();
		$by_id  = array();

		foreach ( $steps as $step ) {
			$by_id[ (int) $step['id'] ] = $step;

			if ( AutomationRepository::KIND_SPLIT === ( $step['kind'] ?? '' ) ) {
				$splits[ (int) $step['id'] ] = $step;
			}
		}

		if ( array() === $splits ) {
			return array();
		}

		$stats = array();

		foreach ( $splits as $split_id => $split ) {
			$stats[ $split_id ] = array(
				AutomationRepository::BRANCH_A => array( 'entered' => 0, 'completed' => 0 ),
				AutomationRepository::BRANCH_B => array( 'entered' => 0, 'completed' => 0 ),
			);
		}

		// Step subtree of one branch, including nested branching steps.
		$subtree = static function ( int $split_id, string $branch ) use ( $by_id ): array {
			$children = array();

			foreach ( $by_id as $step_id => $step ) {
				if ( (int) ( $step['parent_step_id'] ?? 0 ) === $split_id && $branch === ( $step['branch'] ?? '' ) ) {
					$children[] = $step_id;
				}
			}

			return $children;
		};

		$subtrees = array();

		foreach ( $splits as $split_id => $split ) {
			foreach ( array( AutomationRepository::BRANCH_A, AutomationRepository::BRANCH_B ) as $branch ) {
				$pending   = $subtree( (int) $split_id, $branch );
				$collected = array();

				while ( array() !== $pending ) {
					$step_id = (int) array_pop( $pending );

					if ( isset( $collected[ $step_id ] ) ) {
						continue;
					}

					$collected[ $step_id ] = true;

					foreach ( $by_id as $child_id => $child ) {
						if ( (int) ( $child['parent_step_id'] ?? 0 ) === $step_id ) {
							$pending[] = $child_id;
						}
					}
				}

				$subtrees[ (int) $split_id ][ $branch ] = $collected;
			}
		}

		$step_ids     = array_keys( $splits );
		$placeholders = implode( ',', array_fill( 0, count( $step_ids ), '%d' ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders -- table name is trusted and the placeholders are generated.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT e.id, e.run_id, e.step_id, e.payload FROM " . Schema::table( 'automation_run_events' ) . " e INNER JOIN " . Schema::table( 'automation_runs' ) . " r ON r.id = e.run_id WHERE r.automation_id = %d AND e.step_id IN ({$placeholders}) ORDER BY e.id ASC",
				array_merge( array( $automation_id ), $step_ids )
			),
			ARRAY_A
		);
		// phpcs:enable

		$entries = array();
		$run_ids = array();

		foreach ( (array) $rows as $row ) {
			$payload = json_decode( (string) ( $row['payload'] ?? '' ), true );
			$branch  = is_array( $payload ) ? (string) ( $payload['branch'] ?? '' ) : '';

			if ( ! in_array( $branch, array( AutomationRepository::BRANCH_A, AutomationRepository::BRANCH_B ), true ) ) {
				continue;
			}

			$run_id = (int) $row['run_id'];
			$run_ids[ $run_id ] = true;
			$entries[ $run_id ][] = array(
				'event_id' => (int) $row['id'],
				'split_id' => (int) $row['step_id'],
				'branch'   => $branch,
			);
			++$stats[ (int) $row['step_id'] ][ $branch ]['entered'];
		}

		if ( array() === $entries ) {
			return $stats;
		}

		$run_list     = array_keys( $run_ids );
		$run_placeholders = implode( ',', array_fill( 0, count( $run_list ), '%d' ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders -- table name is trusted and the placeholders are generated.
		$event_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, run_id, step_id FROM " . Schema::table( 'automation_run_events' ) . " WHERE run_id IN ({$run_placeholders}) ORDER BY id ASC",
				$run_list
			),
			ARRAY_A
		);
		// phpcs:enable

		$events_by_run = array();

		foreach ( (array) $event_rows as $row ) {
			$events_by_run[ (int) $row['run_id'] ][] = array(
				'id'      => (int) $row['id'],
				'step_id' => (int) $row['step_id'],
			);
		}

		foreach ( $entries as $run_id => $run_entries ) {
			foreach ( $run_entries as $entry ) {
				foreach ( (array) ( $events_by_run[ $run_id ] ?? array() ) as $event ) {
					if ( $event['id'] <= $entry['event_id'] ) {
						continue;
					}

					$branch = $entry['branch'];

					if ( isset( $subtrees[ $entry['split_id'] ][ $branch ][ $event['step_id'] ] ) ) {
						continue;
					}

					// The first event outside the branch subtree is the exit.
					++$stats[ $entry['split_id'] ][ $branch ]['completed'];
					break;
				}
			}
		}

		return $stats;
	}

	/**
	 * Events grouped for one page of runs.
	 *
	 * @param int[] $run_ids Run IDs.
	 * @return array<int, array<int, array<string, mixed>>>
	 */
	public function events_for_runs( array $run_ids ): array {
		global $wpdb;

		$ids = array_values( array_unique( array_filter( array_map( 'absint', $run_ids ) ) ) );

		if ( array() === $ids ) {
			return array();
		}

		$table        = Schema::table( 'automation_run_events' );
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders -- table name is trusted, placeholders are generated.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE run_id IN ({$placeholders}) ORDER BY id ASC",
				$ids
			),
			ARRAY_A
		);
		// phpcs:enable

		$grouped = array();

		foreach ( (array) $rows as $row ) {
			$grouped[ (int) $row['run_id'] ][] = $this->cast_event( $row );
		}

		return $grouped;
	}

	/**
	 * Start, resume, or reset the run of one contact.
	 *
	 * @param array<string, mixed> $automation Automation row.
	 * @param int                  $contact_id Contact ID.
	 * @param int                  $first_step First step ID.
	 * @param string               $trigger    Trigger key.
	 * @param string               $source_ref Event reference, for duplicate suppression.
	 * @return array{started: bool, reason: string, run: array<string, mixed>}|WP_Error
	 */
	public function start( array $automation, int $contact_id, int $first_step, string $trigger, string $source_ref = '' ) {
		global $wpdb;

		$automation_id = (int) $automation['id'];
		$entry         = (array) ( $automation['entry_config'] ?? array() );
		$run_once      = ! empty( $entry['run_once'] );
		$now           = gmdate( 'Y-m-d H:i:s' );
		$existing      = $this->find( $automation_id, $contact_id );

		// run_once covers every prior source of this exact automation and
		// contact, including cart runs that the ordinary re-entry target
		// excludes. Lifecycle serialization makes the check and insert atomic
		// for cart starts; ordinary starts keep their existing contract.
		if ( $run_once && $this->has_run( $automation_id, $contact_id ) ) {
			$prior = $this->first_run( $automation_id, $contact_id );

			if ( $prior ) {
				return array(
					'started' => false,
					'reason'  => in_array( (string) $prior['status'], array( self::STATUS_ACTIVE, self::STATUS_WAITING ), true )
						? 'already_running'
						: 'run_once',
					'run'     => $prior,
				);
			}
		}

		if ( $existing ) {
			if ( in_array( $existing['status'], array( self::STATUS_ACTIVE, self::STATUS_WAITING ), true ) ) {
				return array(
					'started' => false,
					'reason'  => 'already_running',
					'run'     => $existing,
				);
			}

			if ( '' !== $source_ref && $source_ref === $existing['source_ref'] && $existing['source_trigger'] === $trigger ) {
				return array(
					'started' => false,
					'reason'  => 'duplicate_event',
					'run'     => $existing,
				);
			}

			if ( $run_once ) {
				return array(
					'started' => false,
					'reason'  => 'run_once',
					'run'     => $existing,
				);
			}

			$table = Schema::table( 'automation_runs' );

			if ( $now <= (string) $existing['started_at'] ) {
				$now = gmdate( 'Y-m-d H:i:s', strtotime( (string) $existing['started_at'] . ' UTC' ) + 1 );
			}

			$wpdb->query( 'START TRANSACTION' );
			$wpdb->delete( Schema::table( 'automation_run_events' ), array( 'run_id' => (int) $existing['id'] ), array( '%d' ) );

			$updated = $wpdb->update(
				$table,
				array(
					'status'                 => self::STATUS_ACTIVE,
					'last_completed_step_id' => null,
					'next_step_id'           => $first_step,
					'next_run_at'            => $now,
					'source_trigger'         => $trigger,
					'source_ref'             => substr( $source_ref, 0, 191 ),
					'last_error'             => null,
					'started_at'             => $now,
					'finished_at'            => null,
					'updated_at'             => $now,
				),
				array( 'id' => (int) $existing['id'] ),
				array( '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ),
				array( '%d' )
			);

			if ( false === $updated ) {
				$wpdb->query( 'ROLLBACK' );

				return new WP_Error( 'nowcrm_db_error', $wpdb->last_error ?: __( '無法重新啟動自動化。', 'now-crm' ) );
			}

			$wpdb->query( 'COMMIT' );

			return array(
				'started' => true,
				'reason'  => 'restarted',
				'run'     => $this->get( (int) $existing['id'] ),
			);
		}

		$inserted = $wpdb->insert(
			Schema::table( 'automation_runs' ),
			array(
				'automation_id' => $automation_id,
				'contact_id'    => $contact_id,
				'status'        => self::STATUS_ACTIVE,
				'next_step_id'  => $first_step,
				'next_run_at'   => $now,
				'source_trigger' => $trigger,
				'source_ref'    => substr( $source_ref, 0, 191 ),
				'started_at'    => $now,
				'updated_at'    => $now,
			),
			array( '%d', '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( ! $inserted ) {
			$existing = $this->find( $automation_id, $contact_id );

			if ( $existing && in_array( $existing['status'], array( self::STATUS_ACTIVE, self::STATUS_WAITING ), true ) ) {
				return array(
					'started' => false,
					'reason'  => 'already_running',
					'run'     => $existing,
				);
			}

			return new WP_Error( 'nowcrm_db_error', $wpdb->last_error ?: __( '無法建立自動化執行。', 'now-crm' ) );
		}

		return array(
			'started' => true,
			'reason'  => 'created',
			'run'     => $this->get( (int) $wpdb->insert_id ),
		);
	}

	/**
	 * Start or reuse the run of one immutable cart source.
	 *
	 * Cart runs are identified by `cart:<id>:<version>` and are always created
	 * with the trusted `cart_abandoned` trigger. A duplicate insert is read
	 * back instead of deleted, so a concurrent sweep or a replayed hook gets
	 * the one durable run for that source.
	 *
	 * @param array<string, mixed> $automation Automation row.
	 * @param int                  $contact_id Contact ID.
	 * @param int                  $first_step First step ID.
	 * @param int                  $cart_id    Cart ID.
	 * @param int                  $version    Immutable cart version.
	 * @return array{started: bool, reason: string, run: array<string, mixed>, run_id: int}|WP_Error
	 */
	public function start_source( array $automation, int $contact_id, int $first_step, int $cart_id, int $version ) {
		global $wpdb;

		$automation_id = (int) ( $automation['id'] ?? 0 );

		if ( $automation_id <= 0 || $contact_id <= 0 || $first_step <= 0 || $cart_id <= 0 || $version <= 0 ) {
			return new WP_Error( 'nowcrm_invalid_cart_source', __( '購物車來源資料不完整。', 'now-crm' ) );
		}

		$source  = 'cart:' . $cart_id . ':' . $version;
		$existing = $this->find_by_source_key( $source );

		if ( $existing ) {
			return $this->source_result( false, 'duplicate_source', $existing );
		}

		// Source deduplication runs first. run_once then blocks a second
		// version, a second cart, or an ordinary trigger row of this exact
		// automation and contact. The cart start holds the lifecycle lock, so
		// this check and the insert cannot interleave.
		if ( ! empty( $automation['entry_config']['run_once'] ) && $this->has_run( $automation_id, $contact_id ) ) {
			$prior = $this->first_run( $automation_id, $contact_id );

			if ( $prior ) {
				return $this->source_result( false, 'run_once', $prior );
			}
		}

		$now = gmdate( 'Y-m-d H:i:s' );

		$inserted = $wpdb->insert(
			Schema::table( 'automation_runs' ),
			array(
				'automation_id'  => $automation_id,
				'contact_id'     => $contact_id,
				'status'         => self::STATUS_ACTIVE,
				'next_step_id'   => $first_step,
				'next_run_at'    => $now,
				'source_trigger' => 'cart_abandoned',
				'source_ref'     => $source,
				'entry_key'      => $source,
				'source_key'     => $source,
				'cart_id'        => $cart_id,
				'cart_version'   => $version,
				'started_at'     => $now,
				'updated_at'     => $now,
			),
			array( '%d', '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s' )
		);

		if ( ! $inserted ) {
			$existing = $this->find_by_source_key( $source );

			if ( ! $existing ) {
				$existing = $this->find_by_entry_key( $automation_id, $contact_id, $source );
			}

			if ( $existing ) {
				return $this->source_result( false, 'duplicate_source', $existing );
			}

			return new WP_Error( 'nowcrm_db_error', $wpdb->last_error ?: __( '無法建立購物車執行紀錄。', 'now-crm' ) );
		}

		$run = $this->get( (int) $wpdb->insert_id );

		if ( ! $run ) {
			return new WP_Error( 'nowcrm_db_error', __( '無法讀取購物車執行紀錄。', 'now-crm' ) );
		}

		return $this->source_result( true, 'created', $run );
	}

	/**
	 * Shape the result of a cart source start.
	 *
	 * @param array<string, mixed> $run Run row.
	 * @return array{started: bool, reason: string, run: array<string, mixed>, run_id: int}
	 */
	private function source_result( bool $started, string $reason, array $run ): array {
		return array(
			'started' => $started,
			'reason'  => $reason,
			'run'     => $run,
			'run_id'  => (int) $run['id'],
		);
	}

	/**
	 * Fetch a run by the entry key that identifies one source inside the
	 * automation/contact pair.
	 *
	 * @return array<string, mixed>|null
	 */
	private function find_by_entry_key( int $automation_id, int $contact_id, string $entry_key ): ?array {
		global $wpdb;

		$table = Schema::table( 'automation_runs' );
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE automation_id = %d AND contact_id = %d AND entry_key = %s ORDER BY id ASC LIMIT 1",
				$automation_id,
				$contact_id,
				$entry_key
			),
			ARRAY_A
		);

		return $row ? $this->cast( $row ) : null;
	}

	/**
	 * Due runs of active automations.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function due( int $limit = 50 ): array {
		global $wpdb;

		$runs        = Schema::table( 'automation_runs' );
		$automations = Schema::table( 'automations' );
		$limit       = max( 1, min( 500, $limit ) );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT r.* FROM {$runs} r INNER JOIN {$automations} a ON a.id = r.automation_id
				WHERE a.status = %s AND r.status IN (%s, %s) AND r.next_run_at IS NOT NULL AND r.next_run_at <= %s
				ORDER BY r.next_run_at ASC, r.id ASC LIMIT %d",
				AutomationRepository::STATUS_ACTIVE,
				self::STATUS_ACTIVE,
				self::STATUS_WAITING,
				gmdate( 'Y-m-d H:i:s' ),
				$limit
			),
			ARRAY_A
		);

		return array_map( array( $this, 'cast' ), (array) $rows );
	}

	/**
	 * Number of due runs.
	 */
	public function due_count(): int {
		global $wpdb;

		$runs        = Schema::table( 'automation_runs' );
		$automations = Schema::table( 'automations' );

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$runs} r INNER JOIN {$automations} a ON a.id = r.automation_id
				WHERE a.status = %s AND r.status IN (%s, %s) AND r.next_run_at IS NOT NULL AND r.next_run_at <= %s",
				AutomationRepository::STATUS_ACTIVE,
				self::STATUS_ACTIVE,
				self::STATUS_WAITING,
				gmdate( 'Y-m-d H:i:s' )
			)
		);
	}

	/**
	 * Parked goal runs of one contact.
	 *
	 * A goal run is parked with `status=waiting` and `next_run_at=NULL`, and
	 * `last_completed_step_id` points at the goal step. The null due time keeps
	 * it out of the normal due query until an event claims it.
	 *
	 * @param int $contact_id Contact ID.
	 * @return array<int, array<string, mixed>>
	 */
	public function parked_goals( int $contact_id ): array {
		global $wpdb;

		$runs        = Schema::table( 'automation_runs' );
		$automations = Schema::table( 'automations' );
		$steps       = Schema::table( 'automation_steps' );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT r.id, r.automation_id, r.contact_id, s.id AS goal_step_id, s.config AS goal_config
				FROM {$runs} r
				INNER JOIN {$automations} a ON a.id = r.automation_id
				INNER JOIN {$steps} s ON s.id = r.last_completed_step_id
				WHERE r.contact_id = %d AND r.status = %s
					AND a.status = %s AND s.kind = %s
				ORDER BY r.id ASC",
				$contact_id,
				self::STATUS_WAITING,
				AutomationRepository::STATUS_ACTIVE,
				AutomationRepository::KIND_GOAL
			),
			ARRAY_A
		);

		return array_map(
			static function ( array $row ): array {
				$config = json_decode( (string) ( $row['goal_config'] ?? '' ), true );

				$row['id']            = (int) $row['id'];
				$row['automation_id'] = (int) $row['automation_id'];
				$row['contact_id']    = (int) $row['contact_id'];
				$row['goal_step_id']  = (int) $row['goal_step_id'];
				$row['goal_config']   = is_array( $config ) ? $config : array();

				return $row;
			},
			(array) $rows
		);
	}

	/**
	 * Atomically publish a goal match and wake its run.
	 *
	 * The waiting goal event and the run change in one multi-table statement,
	 * so a deadline worker can never expire a goal that just matched, and a
	 * matched goal can never be overwritten by a timeout. The guards make
	 * exactly one writer win: the same run and goal step, the run still
	 * waiting with an absent or unexpired due time, and the event still
	 * waiting. A repeated or late event matches no row and resumes nothing.
	 *
	 * @param int                  $run_id  Run ID.
	 * @param int                  $step_id Goal step ID.
	 * @param array<string, mixed> $payload Completed event payload.
	 * @return bool Whether the run was resumed.
	 */
	public function resume_goal( int $run_id, int $step_id, array $payload ): bool {
		global $wpdb;

		$now    = gmdate( 'Y-m-d H:i:s' );
		$runs   = Schema::table( 'automation_runs' );
		$events = Schema::table( 'automation_run_events' );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- table names are trusted and every value is a placeholder.
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$runs} r INNER JOIN {$events} e
					ON e.run_id = r.id AND e.step_id = r.last_completed_step_id
				SET r.status = %s, r.next_run_at = %s, r.updated_at = %s,
					e.status = %s, e.payload = %s
				WHERE r.id = %d AND r.status = %s AND r.last_completed_step_id = %d
					AND e.status = %s AND ( r.next_run_at IS NULL OR r.next_run_at > %s )",
				self::STATUS_ACTIVE,
				$now,
				$now,
				self::EVENT_COMPLETED,
				wp_json_encode( $payload ),
				$run_id,
				self::STATUS_WAITING,
				$step_id,
				self::EVENT_WAITING,
				$now
			)
		);
		// phpcs:enable

		return (int) $updated > 0;
	}

	/**
	 * Replace an event only while it still has the expected status.
	 *
	 * Two writers can race for one event: a native goal event and the deadline
	 * worker. The status predicate makes exactly one transition win, so a
	 * resumed goal is never rewritten as a timeout.
	 *
	 * @param int                  $event_id Event ID.
	 * @param string               $from     Expected current status.
	 * @param string               $to       New status.
	 * @param array<string, mixed> $payload  New payload.
	 */
	public function transition_event( int $event_id, string $from, string $to, array $payload ): bool {
		global $wpdb;

		$updated = $wpdb->update(
			Schema::table( 'automation_run_events' ),
			array(
				'status'  => $to,
				'payload' => wp_json_encode( $payload ),
			),
			array(
				'id'     => $event_id,
				'status' => $from,
			),
			array( '%s', '%s' ),
			array( '%d', '%s' )
		);

		return 1 === (int) $updated;
	}

	/**
	 * Write the idempotency event before an action runs.
	 *
	 * @param int                  $run_id     Run ID.
	 * @param int                  $step_id    Step ID.
	 * @param int                  $contact_id Contact ID.
	 * @param array<string, mixed> $payload    Event payload.
	 * @param string               $status     Initial status.
	 * @return array{created: bool, event: array<string, mixed>}
	 */
	public function record_event( int $run_id, int $step_id, int $contact_id, array $payload = array(), string $status = self::EVENT_COMPLETED ): array {
		global $wpdb;

		$table = Schema::table( 'automation_run_events' );
		$now   = gmdate( 'Y-m-d H:i:s' );

		$created = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$table} (run_id, step_id, contact_id, status, payload, occurred_at)
				VALUES (%d, %d, %d, %s, %s, %s)",
				$run_id,
				$step_id,
				$contact_id,
				$status,
				wp_json_encode( $payload ),
				$now
			)
		);

		$event = $this->event_for_step( $run_id, $step_id );

		return array(
			'created' => 1 === (int) $created,
			'event'   => $event ?? array(),
		);
	}

	/**
	 * Merge one queue outcome into the event of its matching job.
	 *
	 * The event payload keeps the job ID, so a late or duplicate queue result
	 * cannot overwrite another step's job. An accepted outcome is final: later
	 * failed or unknown states from a stale lease cannot replace it. Other
	 * event payload facts stay unchanged.
	 *
	 * @param int                  $run_id  Run ID.
	 * @param int                  $step_id Step ID.
	 * @param int                  $job_id  Email job ID.
	 * @param string               $outcome accepted, suppressed, cancelled, failed, or unknown.
	 * @param array<string, mixed> $payload Extra event facts.
	 */
	public function record_job_outcome( int $run_id, int $step_id, int $job_id, string $outcome, array $payload = array() ): bool {
		global $wpdb;

		if ( $run_id <= 0 || $step_id <= 0 || $job_id <= 0 ) {
			return false;
		}

		$outcome = sanitize_key( $outcome );
		$status  = match ( $outcome ) {
			'accepted'   => self::EVENT_COMPLETED,
			'suppressed', 'cancelled', 'skipped' => self::EVENT_SKIPPED,
			'failed', 'unknown' => self::EVENT_FAILED,
			default      => '',
		};

		if ( '' === $status ) {
			return false;
		}

		$event = $this->event_for_step( $run_id, $step_id );

		if ( ! $event ) {
			return false;
		}

		$existing = is_array( $event['payload'] ?? null ) ? $event['payload'] : array();
		$ledger   = (int) ( $existing['job_id'] ?? 0 );

		if ( $ledger > 0 && $ledger !== $job_id ) {
			return false;
		}

		if ( 'accepted' === (string) ( $existing['outcome'] ?? '' ) && 'accepted' !== $outcome ) {
			return false;
		}

		$merged               = array_merge( $existing, $payload );
		$merged['job_id']     = $job_id;
		$merged['outcome']    = $outcome;
		$merged['handoff']    = $outcome;
		$merged['settled_at'] = (string) ( $merged['settled_at'] ?? gmdate( 'Y-m-d H:i:s' ) );

		$updated = $wpdb->update(
			Schema::table( 'automation_run_events' ),
			array(
				'status'  => $status,
				'payload' => wp_json_encode( $merged ),
			),
			array(
				'id'     => (int) $event['id'],
				'status' => (string) $event['status'],
			),
			array( '%s', '%s' ),
			array( '%d', '%s' )
		);

		return 1 === (int) $updated;
	}

	/**
	 * Update an event after the action ran.
	 *
	 * @param int                  $event_id Event ID.
	 * @param array<string, mixed> $payload  Result payload.
	 * @param string               $status   completed, skipped, or failed.
	 */
	public function update_event( int $event_id, array $payload, string $status = self::EVENT_COMPLETED ): bool {
		global $wpdb;

		return (bool) $wpdb->update(
			Schema::table( 'automation_run_events' ),
			array(
				'status'  => $status,
				'payload' => wp_json_encode( $payload ),
			),
			array( 'id' => $event_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * One event row.
	 *
	 * @return array<string, mixed>|null
	 */
	public function event_for_step( int $run_id, int $step_id ): ?array {
		global $wpdb;

		$table = Schema::table( 'automation_run_events' );
		$row   = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE run_id = %d AND step_id = %d", $run_id, $step_id ),
			ARRAY_A
		);

		return $row ? $this->cast_event( $row ) : null;
	}

	/**
	 * Events of one run.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function events( int $run_id ): array {
		global $wpdb;

		$table = Schema::table( 'automation_run_events' );
		$rows  = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE run_id = %d ORDER BY id ASC", $run_id ),
			ARRAY_A
		);

		return array_map( array( $this, 'cast_event' ), (array) $rows );
	}

	/**
	 * Move a run to its next step.
	 *
	 * @param int                $run_id   Run ID.
	 * @param int|null           $last_id  Step that just finished.
	 * @param array|null         $next     Next step row.
	 * @param string             $status   active or waiting.
	 * @param string|null        $due_at   Next run time in UTC SQL.
	 */
	public function advance( int $run_id, ?int $last_id, ?array $next, string $status, ?string $due_at ): bool {
		$now = gmdate( 'Y-m-d H:i:s' );

		return $this->update_open(
			$run_id,
			array(
				'status'                 => $status,
				'last_completed_step_id' => $last_id,
				'next_step_id'           => $next ? (int) $next['id'] : null,
				'next_run_at'            => $next ? $due_at : null,
				'last_error'             => null,
				'updated_at'             => $now,
			)
		);
	}

	/**
	 * Park a run with no next step until a due time.
	 *
	 * A terminal wait keeps its wait step as the last completed step and owns
	 * no next pointer; when the due time passes the run completes on its own.
	 *
	 * @param int    $run_id  Run ID.
	 * @param int    $last_id Wait step that was reached.
	 * @param string $due_at  Due time in UTC SQL.
	 */
	public function park_until( int $run_id, int $last_id, string $due_at ): bool {
		$now = gmdate( 'Y-m-d H:i:s' );

		return $this->update_open(
			$run_id,
			array(
				'status'                 => self::STATUS_WAITING,
				'last_completed_step_id' => $last_id,
				'next_step_id'           => null,
				'next_run_at'            => $due_at,
				'last_error'             => null,
				'updated_at'             => $now,
			)
		);
	}

	/**
	 * Mark a run complete.
	 */
	public function complete( int $run_id, ?int $last_id = null ): bool {
		$now = gmdate( 'Y-m-d H:i:s' );

		return $this->update_open(
			$run_id,
			array(
				'status'                 => self::STATUS_COMPLETED,
				'last_completed_step_id' => $last_id,
				'next_step_id'           => null,
				'next_run_at'            => null,
				'finished_at'            => $now,
				'updated_at'             => $now,
			)
		);
	}

	/**
	 * Cancel a run.
	 */
	public function cancel( int $run_id, string $reason ): bool {
		return $this->finish( $run_id, self::STATUS_CANCELLED, $reason );
	}

	/**
	 * Fail a run.
	 */
	public function fail( int $run_id, string $reason ): bool {
		return $this->finish( $run_id, self::STATUS_FAILED, $reason );
	}

	/**
	 * Where one run stands.
	 */
	private function finish( int $run_id, string $status, string $reason ): bool {
		$now = gmdate( 'Y-m-d H:i:s' );

		return $this->update_open(
			$run_id,
			array(
				'status'      => $status,
				'next_run_at' => null,
				'last_error'  => substr( $reason, 0, 1000 ),
				'finished_at' => $now,
				'updated_at'  => $now,
			)
		);
	}

	/**
	 * Update one open run and never rewrite a terminal run.
	 *
	 * A run that a worker already read can be cancelled or completed by another
	 * request. The status predicate makes exactly one transition win, so a
	 * cancelled run cannot be moved forward again.
	 *
	 * @param array<string, mixed> $data Column => value. A null value clears the column.
	 */
	private function update_open( int $run_id, array $data ): bool {
		global $wpdb;

		$set    = array();
		$params = array();

		foreach ( $data as $column => $value ) {
			if ( null === $value ) {
				$set[] = $column . ' = NULL';
			} elseif ( is_int( $value ) ) {
				$set[]    = $column . ' = %d';
				$params[] = $value;
			} else {
				$set[]    = $column . ' = %s';
				$params[] = (string) $value;
			}
		}

		$params[] = $run_id;
		$params[] = self::STATUS_ACTIVE;
		$params[] = self::STATUS_WAITING;

		$table = Schema::table( 'automation_runs' );
		$sql   = 'UPDATE ' . $table . ' SET ' . implode( ', ', $set ) . ' WHERE id = %d AND status IN (%s, %s)';

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- column names are fixed fragments of this class.
		$updated = $wpdb->query( $wpdb->prepare( $sql, $params ) );

		if ( false === $updated ) {
			return false;
		}

		if ( 0 === (int) $updated ) {
			$current = $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$table} WHERE id = %d", $run_id ) );

			return in_array( (string) $current, array( self::STATUS_ACTIVE, self::STATUS_WAITING ), true );
		}

		return true;
	}

	/**
	 * Cast a run row.
	 *
	 * @param array<string, mixed> $row Row.
	 * @return array<string, mixed>
	 */
	private function cast( array $row ): array {
		$row['id']                       = (int) $row['id'];
		$row['automation_id']            = (int) $row['automation_id'];
		$row['contact_id']               = (int) $row['contact_id'];
		$row['last_completed_step_id']   = null === $row['last_completed_step_id'] ? null : (int) $row['last_completed_step_id'];
		$row['next_step_id']             = null === $row['next_step_id'] ? null : (int) $row['next_step_id'];
		$row['cart_id']                  = null === $row['cart_id'] ? null : (int) $row['cart_id'];
		$row['cart_version']             = null === $row['cart_version'] ? null : (int) $row['cart_version'];

		return $row;
	}

	/**
	 * Cast an event row.
	 *
	 * @param array<string, mixed> $row Row.
	 * @return array<string, mixed>
	 */
	private function cast_event( array $row ): array {
		$row['id']         = (int) $row['id'];
		$row['run_id']     = (int) $row['run_id'];
		$row['step_id']    = (int) $row['step_id'];
		$row['contact_id'] = (int) $row['contact_id'];
		$payload           = json_decode( (string) ( $row['payload'] ?? '' ), true );
		$row['payload']    = is_array( $payload ) ? $payload : array();

		return $row;
	}
}
