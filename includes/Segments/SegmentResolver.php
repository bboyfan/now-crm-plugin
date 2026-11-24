<?php
/**
 * Segment evaluation: members, counts, and consumer queries.
 *
 * A stored segment is evaluated from its filter plan with AdvancedFilters. A
 * system segment maps to one fixed predicate over the native tables. Nothing is
 * cached, so a saved edit applies to the next call in the same request.
 *
 * Every public method fails closed. A missing segment, an archived segment, or
 * a plan that no longer validates returns WP_Error. A consumer must stop when
 * it gets an error; it never falls back to "all contacts".
 *
 * Consumer seams:
 * - Campaign recipients: recipients() and recipient_count() apply the same
 *   eligibility rules as Campaign\Audience: subscribed status and no
 *   suppression.
 * - Export: ids() returns live membership in ascending ID order with a keyset
 *   cursor, so an export can stream without loading every row.
 * - Automation: contains() answers membership for one contact.
 *
 * All SQL uses contact alias `c`.
 *
 * @package NowCRM
 */

namespace NowCRM\Segments;

use NowCRM\Contacts\AdvancedFilters;
use NowCRM\Contacts\ContactRepository;
use NowCRM\Contacts\Status;
use NowCRM\Support\Schema;
use WP_Error;

defined( 'ABSPATH' ) || exit;

final class SegmentResolver {

	public const MAX_PAGE = 500;

	private SegmentRepository $segments;
	private ContactRepository $contacts;

	public function __construct( ?SegmentRepository $segments = null, ?ContactRepository $contacts = null ) {
		$this->segments = $segments ?? new SegmentRepository();
		$this->contacts = $contacts ?? new ContactRepository();
	}

	/**
	 * Whether one contact is in the segment.
	 *
	 * @param int|string $ref Segment reference.
	 * @return bool|WP_Error
	 */
	public function contains( int|string $ref, int $contact_id ) {
		$resolved = $this->resolve( $ref );

		if ( is_wp_error( $resolved ) ) {
			return $resolved;
		}

		global $wpdb;

		$table  = Schema::table( 'contacts' );
		$params = array_merge( array( max( 0, $contact_id ) ), $resolved['params'] );
		$sql    = "SELECT 1 FROM {$table} c WHERE c.id = %d AND ({$resolved['sql']}) LIMIT 1";

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders -- table name is trusted, values use placeholders.
		return (bool) $wpdb->get_var( $wpdb->prepare( $sql, $params ) );
	}

	/**
	 * Matching contact count.
	 *
	 * @param int|string $ref           Segment reference.
	 * @param bool       $allow_archived Read archived definitions, for admin
	 *                                   preview only.
	 * @return int|WP_Error
	 */
	public function count( int|string $ref, bool $allow_archived = false ) {
		$resolved = $this->resolve( $ref, $allow_archived );

		return is_wp_error( $resolved ) ? $resolved : $this->count_resolved( $resolved );
	}

	/**
	 * Matching contact IDs in ascending order, after a cursor.
	 *
	 * @param int|string $ref           Segment reference.
	 * @param int        $after_id      Last processed ID.
	 * @param int        $max_id        Inclusive ceiling; 0 means none.
	 * @param int        $limit         Batch size.
	 * @param bool       $allow_archived Read archived definitions, for admin
	 *                                   preview only.
	 * @return int[]|WP_Error
	 */
	public function ids( int|string $ref, int $after_id = 0, int $max_id = 0, int $limit = 500, bool $allow_archived = false ) {
		$resolved = $this->resolve( $ref, $allow_archived );

		return is_wp_error( $resolved ) ? $resolved : $this->ids_resolved( $resolved, $after_id, $max_id, $limit );
	}

	/**
	 * Count and first page of contacts for the admin preview.
	 *
	 * @param int|string $ref           Segment reference.
	 * @param int        $limit         Preview size.
	 * @param bool       $allow_archived Read archived definitions.
	 * @return array{segment: array<string, mixed>, items: array<int, array<string, mixed>>, total: int}|WP_Error
	 */
	public function preview( int|string $ref, int $limit = 20, bool $allow_archived = false ) {
		$resolved = $this->resolve( $ref, $allow_archived );

		if ( is_wp_error( $resolved ) ) {
			return $resolved;
		}

		$items = array();
		$ids   = $this->ids_resolved( $resolved, 0, 0, $limit );

		if ( is_wp_error( $ids ) ) {
			return $ids;
		}

		foreach ( $ids as $id ) {
			$contact = $this->contacts->get( (int) $id );

			if ( null !== $contact ) {
				$items[] = $contact;
			}
		}

		return array(
			'segment' => $resolved['segment'],
			'items'   => $items,
			'total'   => $this->count_resolved( $resolved ),
		);
	}

	/**
	 * WHERE fragment and positional parameters for a consumer query.
	 *
	 * @param int|string $ref           Segment reference.
	 * @param bool       $allow_archived Read archived definitions.
	 * @return array{sql: string, params: array<int, mixed>}|WP_Error
	 */
	public function where( int|string $ref, bool $allow_archived = false ) {
		$resolved = $this->resolve( $ref, $allow_archived );

		if ( is_wp_error( $resolved ) ) {
			return $resolved;
		}

		return array(
			'sql'    => $resolved['sql'],
			'params' => $resolved['params'],
		);
	}

	/**
	 * Campaign recipients: membership, subscribed, not suppressed.
	 *
	 * @param int|string $ref      Segment reference.
	 * @param int        $after_id Last processed ID.
	 * @param int        $max_id   Inclusive ceiling; 0 means none.
	 * @param int        $limit    Batch size.
	 * @return array<int, array{contact_id: int, recipient: string}>|WP_Error
	 */
	public function recipients( int|string $ref, int $after_id = 0, int $max_id = 0, int $limit = 500 ) {
		$resolved = $this->resolve( $ref );

		if ( is_wp_error( $resolved ) ) {
			return $resolved;
		}

		global $wpdb;

		$table   = Schema::table( 'contacts' );
		$supp    = Schema::table( 'suppressions' );
		$params  = $resolved['params'];
		$params[] = Status::SUBSCRIBED;
		$where    = "({$resolved['sql']}) AND c.status = %s
			AND NOT EXISTS (SELECT 1 FROM {$supp} s WHERE s.email_normalized = c.email_normalized)
			AND c.id > %d";

		$params[] = max( 0, $after_id );

		if ( $max_id > 0 ) {
			$where   .= ' AND c.id <= %d';
			$params[] = $max_id;
		}

		$limit    = max( 1, min( self::MAX_PAGE, $limit ) );
		$params[] = $limit;

		$sql = "SELECT c.id AS contact_id, c.email AS recipient FROM {$table} c
			WHERE {$where} ORDER BY c.id ASC LIMIT %d";

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders -- table names are trusted, values use placeholders.
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
		// phpcs:enable

		return array_map(
			static function ( array $row ): array {
				return array(
					'contact_id' => (int) $row['contact_id'],
					'recipient'  => (string) $row['recipient'],
				);
			},
			(array) $rows
		);
	}

	/**
	 * Campaign recipient count with the same eligibility rules.
	 *
	 * @param int|string $ref Segment reference.
	 * @return int|WP_Error
	 */
	public function recipient_count( int|string $ref ) {
		$resolved = $this->resolve( $ref );

		if ( is_wp_error( $resolved ) ) {
			return $resolved;
		}

		global $wpdb;

		$table  = Schema::table( 'contacts' );
		$supp   = Schema::table( 'suppressions' );
		$params = $resolved['params'];
		$params[] = Status::SUBSCRIBED;
		$sql    = "SELECT COUNT(*) FROM {$table} c WHERE ({$resolved['sql']}) AND c.status = %s
			AND NOT EXISTS (SELECT 1 FROM {$supp} s WHERE s.email_normalized = c.email_normalized)";

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders -- table names are trusted, values use placeholders.
		return (int) $wpdb->get_var( $wpdb->prepare( $sql, $params ) );
	}

	/**
	 * Resolve one reference to a segment and its SQL predicate.
	 *
	 * @param int|string $ref            Segment reference.
	 * @param bool       $allow_archived Read archived definitions.
	 * @return array{segment: array<string, mixed>, sql: string, params: array<int, mixed>, args: array<string, mixed>|null}|WP_Error
	 */
	private function resolve( int|string $ref, bool $allow_archived = false ) {
		$segment = $this->segments->find( $ref );

		if ( null === $segment ) {
			return new WP_Error( 'nowcrm_segment_not_found', __( '找不到這個分眾。', 'now-crm' ) );
		}

		if ( ! $allow_archived && SegmentRepository::STATUS_ACTIVE !== $segment['status'] ) {
			return new WP_Error( 'nowcrm_segment_inactive', __( '這個分眾已封存，無法用於寄送或匯出。', 'now-crm' ) );
		}

		$predicate = (string) ( $segment['predicate'] ?? '' );

		if ( 'all' === $predicate ) {
			return array(
				'segment' => $segment,
				'sql'     => '1=1',
				'params'  => array(),
				'args'    => array(),
			);
		}

		if ( 'subscribed' === $predicate ) {
			return array(
				'segment' => $segment,
				'sql'     => 'c.status = %s',
				'params'  => array( Status::SUBSCRIBED ),
				'args'    => array( 'status' => Status::SUBSCRIBED ),
			);
		}

		if ( 'customers' === $predicate ) {
			$orders = Schema::table( 'order_links' );

			return array(
				'segment' => $segment,
				'sql'     => "EXISTS (SELECT 1 FROM {$orders} ol WHERE ol.contact_id = c.id AND ol.is_counted = 1)",
				'params'  => array(),
				'args'    => null,
			);
		}

		$plan = AdvancedFilters::normalize( $segment['filters'] );

		if ( is_wp_error( $plan ) ) {
			return new WP_Error( 'nowcrm_segment_invalid', $plan->get_error_message() );
		}

		if ( array() === (array) ( $plan['groups'] ?? array() ) ) {
			return new WP_Error( 'nowcrm_segment_empty', __( '這個分眾沒有篩選條件，已停止解析。', 'now-crm' ) );
		}

		$compiled = AdvancedFilters::compile( $plan );

		return array(
			'segment' => $segment,
			'sql'     => $compiled['sql'],
			'params'  => $compiled['params'],
			'args'    => array( 'filters' => $plan ),
		);
	}

	/**
	 * Count from a resolved segment.
	 *
	 * @param array<string, mixed> $resolved Resolver output.
	 * @return int|WP_Error
	 */
	private function count_resolved( array $resolved ) {
		if ( null === $resolved['args'] ) {
			global $wpdb;

			$table = Schema::table( 'contacts' );

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is trusted, predicate has no values.
			return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} c WHERE {$resolved['sql']}" );
		}

		return (int) $this->contacts->search_bounds( $resolved['args'] )['matching'];
	}

	/**
	 * ID page from a resolved segment.
	 *
	 * @param array<string, mixed> $resolved Resolver output.
	 * @param int                  $after_id Last processed ID.
	 * @param int                  $max_id   Inclusive ceiling.
	 * @param int                  $limit    Batch size.
	 * @return int[]|WP_Error
	 */
	private function ids_resolved( array $resolved, int $after_id, int $max_id, int $limit ) {
		$limit = max( 1, min( self::MAX_PAGE, $limit ) );

		if ( null !== $resolved['args'] ) {
			return $this->contacts->search_ids( $resolved['args'], max( 0, $after_id ), max( 0, $max_id ), $limit );
		}

		global $wpdb;

		$table  = Schema::table( 'contacts' );
		$sql    = "SELECT c.id FROM {$table} c WHERE {$resolved['sql']} AND c.id > %d";
		$params = array_merge( $resolved['params'], array( max( 0, $after_id ) ) );

		if ( $max_id > 0 ) {
			$sql     .= ' AND c.id <= %d';
			$params[] = $max_id;
		}

		$sql     .= ' ORDER BY c.id ASC LIMIT %d';
		$params[] = $limit;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders -- table name is trusted, values use placeholders.
		$ids = $wpdb->get_col( $wpdb->prepare( $sql, $params ) );

		return array_map( 'intval', (array) $ids );
	}
}
