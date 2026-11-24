<?php
/**
 * Bounded executor for contact bulk actions.
 *
 * @package NowCRM
 */

namespace NowCRM\Contacts;

use NowCRM\Terms\TermRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Applies one bulk action to a resolved set of contacts.
 *
 * Targets are either explicit selected IDs or every contact matching the list
 * filters. Filter-wide runs read IDs with a keyset cursor and an ID ceiling, so
 * writes never skip a row and never touch a contact created after the run
 * started.
 */
final class BulkContactAction {

	public const ATTACH_LIST = 'attach_list';
	public const DETACH_LIST = 'detach_list';
	public const ATTACH_TAG  = 'attach_tag';
	public const DETACH_TAG  = 'detach_tag';
	public const SET_STATUS  = 'set_status';
	public const DELETE      = 'delete';

	/**
	 * IDs per write batch.
	 */
	public const CHUNK = 200;

	/**
	 * Maximum IDs accepted from one selected-rows request.
	 */
	public const MAX_SELECTED = 200;

	/**
	 * Maximum terms accepted by one request.
	 */
	private const MAX_TERMS = 100;

	/**
	 * Maximum blocked IDs retained for the result notice.
	 */
	private const MAX_BLOCKED_IDS = 20;

	private ContactRepository $contacts;
	private TermRepository $terms;

	public function __construct( ?ContactRepository $contacts = null, ?TermRepository $terms = null ) {
		$this->contacts = $contacts ?? new ContactRepository();
		$this->terms    = $terms ?? new TermRepository();
	}

	/**
	 * Every supported bulk operation.
	 *
	 * @return string[]
	 */
	public static function operations(): array {
		return array(
			self::ATTACH_LIST,
			self::DETACH_LIST,
			self::ATTACH_TAG,
			self::DETACH_TAG,
			self::SET_STATUS,
			self::DELETE,
		);
	}

	/**
	 * Term type required by an operation, or an empty string when the
	 * operation does not touch terms.
	 */
	public static function term_type( string $operation ): string {
		return match ( $operation ) {
			self::ATTACH_LIST, self::DETACH_LIST => TermRepository::TYPE_LIST,
			self::ATTACH_TAG, self::DETACH_TAG   => TermRepository::TYPE_TAG,
			default                              => '',
		};
	}

	/**
	 * Whether the operation permanently deletes contacts.
	 */
	public static function is_destructive( string $operation ): bool {
		return self::DELETE === $operation;
	}

	/**
	 * Run one validated bulk request.
	 *
	 * @param array<string, mixed> $request operation, scope, ids, filters, expected_total, term_ids, status, actor_id.
	 * @return array<string, mixed> Result fields used by the admin notice.
	 */
	public function run( array $request ): array {
		$operation = sanitize_key( (string) ( $request['operation'] ?? '' ) );

		if ( ! in_array( $operation, self::operations(), true ) ) {
			return $this->result( 'invalid', $operation, array( 'reason' => 'operation' ) );
		}

		$term_ids = array();

		if ( '' !== self::term_type( $operation ) ) {
			$term_ids = $this->clean_ids( (array) ( $request['term_ids'] ?? array() ) );
			$term_ids = array_slice( $term_ids, 0, self::MAX_TERMS );

			if ( array() === $term_ids || ! $this->terms_exist( $term_ids, self::term_type( $operation ) ) ) {
				return $this->result( 'invalid', $operation, array( 'reason' => 'term' ) );
			}
		}

		$target_status = '';

		if ( self::SET_STATUS === $operation ) {
			$target_status = sanitize_key( (string) ( $request['status'] ?? '' ) );

			if ( ! Status::is_valid( $target_status ) ) {
				return $this->result( 'invalid', $operation, array( 'reason' => 'status' ) );
			}
		}

		$totals = $this->result( 'done', $operation, array( 'target_status' => $target_status ) );
		$actor  = absint( $request['actor_id'] ?? 0 );

		if ( 'all' === ( $request['scope'] ?? 'selected' ) ) {
			return $this->run_filtered(
				$operation,
				(array) ( $request['filters'] ?? array() ),
				absint( $request['expected_total'] ?? 0 ),
				$term_ids,
				$target_status,
				$actor,
				absint( $request['cursor'] ?? 0 ),
				array_key_exists( 'max_id', $request ) ? absint( $request['max_id'] ) : null,
				$totals
			);
		}

		$ids = $this->clean_ids( (array) ( $request['ids'] ?? array() ) );
		$ids = array_slice( $ids, 0, self::MAX_SELECTED );

		if ( array() === $ids ) {
			$totals['status'] = 'empty';
			$totals['reason'] = 'selection';

			return $totals;
		}

		foreach ( array_chunk( $ids, self::CHUNK ) as $chunk ) {
			$this->apply_chunk( $operation, $chunk, $term_ids, $target_status, $actor, $totals );
		}

		return $totals;
	}

	/**
	 * Resolve and process one bounded batch of contacts matching the filters.
	 *
	 * The first call fixes the ID ceiling and checks the confirmed total for a
	 * destructive run. Later calls use the same ceiling and a keyset cursor, so
	 * one HTTP request never processes more than CHUNK contacts.
	 *
	 * @param array<string, mixed> $filters        search, status, term_id.
	 * @param int|null             $max_id         Ceiling from the first call; null computes it.
	 * @param array<string, mixed> $totals         Result, modified in place.
	 * @return array<string, mixed>
	 */
	private function run_filtered(
		string $operation,
		array $filters,
		int $expected_total,
		array $term_ids,
		string $target_status,
		int $actor,
		int $cursor,
		?int $max_id,
		array $totals
	): array {
		if ( null === $max_id ) {
			$bounds = $this->contacts->search_bounds( $filters );

			$totals['matching']       = (int) $bounds['matching'];
			$totals['expected_total'] = $expected_total;
			$max_id                   = (int) $bounds['max_id'];

			// Select-all delete refuses to run when the list grew after confirmation.
			if ( self::is_destructive( $operation ) && $totals['matching'] > $expected_total ) {
				$totals['status']    = 'changed';
				$totals['completed'] = true;

				return $totals;
			}

			if ( $totals['matching'] < 1 || $max_id < 1 ) {
				$totals['status']    = 'empty';
				$totals['completed'] = true;
				$totals['max_id']    = 0;

				return $totals;
			}
		}

		$max_id = max( 0, $max_id );

		if ( $max_id < 1 || $cursor >= $max_id ) {
			$totals['completed'] = true;
			$totals['cursor']    = $cursor;
			$totals['max_id']    = $max_id;

			return $totals;
		}

		$chunk = $this->contacts->search_ids( $filters, $cursor, $max_id, self::CHUNK );

		if ( array() === $chunk ) {
			$totals['completed'] = true;
			$totals['cursor']    = $cursor;
			$totals['max_id']    = $max_id;

			return $totals;
		}

		$cursor = (int) end( $chunk );

		// The ceiling may be lower than CHUNK when the first pass is small.
		$totals['completed'] = count( $chunk ) < self::CHUNK || $cursor >= $max_id;
		$totals['cursor']    = $cursor;
		$totals['max_id']    = $max_id;

		$this->apply_chunk( $operation, $chunk, $term_ids, $target_status, $actor, $totals );

		return $totals;
	}

	/**
	 * Apply the operation to one bounded chunk.
	 *
	 * @param string               $operation     Bulk operation.
	 * @param int[]                $ids           Contact IDs.
	 * @param int[]                $term_ids      Term IDs.
	 * @param string               $target_status New status for set_status.
	 * @param int                  $actor         Current user ID.
	 * @param array<string, mixed> $totals        Result, modified in place.
	 */
	private function apply_chunk(
		string $operation,
		array $ids,
		array $term_ids,
		string $target_status,
		int $actor,
		array &$totals
	): void {
		if ( self::is_destructive( $operation ) ) {
			foreach ( $ids as $id ) {
				$deleted = $this->contacts->delete( $id );

				if ( true === $deleted ) {
					++$totals['processed'];
					continue;
				}

				if ( is_wp_error( $deleted ) && 'nowcrm_has_orders' === $deleted->get_error_code() ) {
					++$totals['blocked'];

					if ( count( $totals['blocked_ids'] ) < self::MAX_BLOCKED_IDS ) {
						$totals['blocked_ids'][] = (int) $id;
					}

					continue;
				}

				if ( is_wp_error( $deleted ) && 'nowcrm_not_found' === $deleted->get_error_code() ) {
					++$totals['skipped'];
					continue;
				}

				++$totals['errors'];
			}

			return;
		}

		$existing = array_fill_keys( $this->contacts->existing_ids( $ids ), true );
		$attaching = in_array( $operation, array( self::ATTACH_LIST, self::ATTACH_TAG ), true );

		foreach ( $ids as $id ) {
			if ( ! isset( $existing[ $id ] ) ) {
				++$totals['skipped'];
				continue;
			}

			if ( self::SET_STATUS === $operation ) {
				$updated = $this->contacts->set_status( $id, $target_status, 'admin:bulk', '', $actor );

				if ( is_wp_error( $updated ) ) {
					++$totals['errors'];
				} else {
					++$totals['processed'];
				}

				continue;
			}

			foreach ( $term_ids as $term_id ) {
				if ( $attaching ) {
					$this->terms->attach( $id, (int) $term_id );
				} else {
					$this->terms->detach( $id, (int) $term_id );
				}
			}

			++$totals['processed'];
		}
	}

	/**
	 * Validate that every term exists with the required type.
	 *
	 * @param int[] $term_ids Term IDs.
	 */
	private function terms_exist( array $term_ids, string $type ): bool {
		foreach ( $term_ids as $term_id ) {
			$term = $this->terms->get( (int) $term_id );

			if ( ! $term || (string) $term['type'] !== $type ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Positive unique integers from an untrusted list.
	 *
	 * @param array<int|string, mixed> $ids Raw values.
	 * @return int[]
	 */
	private function clean_ids( array $ids ): array {
		$clean = array();

		foreach ( $ids as $id ) {
			if ( ! is_scalar( $id ) ) {
				continue;
			}

			$id = absint( $id );

			if ( $id > 0 ) {
				$clean[ $id ] = $id;
			}
		}

		return array_values( $clean );
	}

	/**
	 * Default result shape.
	 *
	 * @param array<string, mixed> $overrides Fields to replace.
	 * @return array<string, mixed>
	 */
	private function result( string $status, string $operation, array $overrides = array() ): array {
		return array_merge(
			array(
				'status'         => $status,
				'operation'      => $operation,
				'processed'      => 0,
				'skipped'        => 0,
				'blocked'        => 0,
				'blocked_ids'    => array(),
				'errors'         => 0,
				'matching'       => 0,
				'expected_total' => 0,
				'target_status'  => '',
				'reason'         => '',
				'completed'      => true,
				'cursor'         => 0,
				'max_id'         => 0,
			),
			$overrides
		);
	}
}
