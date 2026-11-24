<?php
/**
 * Sequence audience: validation, member count, and recipient batches.
 *
 * A sequence audience is one of: every subscribed contact, one list, one tag,
 * one stored or system segment, or an inline advanced-filter plan. Every query
 * applies the same gate: status subscribed and no suppression row.
 *
 * All SQL uses contact alias `c`.
 *
 * @package NowCRM
 */

namespace NowCRM\Sequences;

use NowCRM\Contacts\AdvancedFilters;
use NowCRM\Contacts\Status;
use NowCRM\Segments\SegmentResolver;
use NowCRM\Support\Schema;
use NowCRM\Terms\TermRepository;
use WP_Error;

defined( 'ABSPATH' ) || exit;

final class AudienceResolver {

	public const TYPE_ALL     = 'all';
	public const TYPE_LIST    = 'list';
	public const TYPE_TAG     = 'tag';
	public const TYPE_SEGMENT = 'segment';
	public const TYPE_FILTERS = 'filters';

	public const MAX_BATCH = 100;

	private SegmentResolver $segments;
	private TermRepository $terms;

	public function __construct( ?SegmentResolver $segments = null, ?TermRepository $terms = null ) {
		$this->segments = $segments ?? new SegmentResolver();
		$this->terms    = $terms ?? new TermRepository();
	}

	/**
	 * Validate a stored or submitted audience.
	 *
	 * @param mixed $raw Audience array, or JSON text.
	 * @return array<string, mixed>|WP_Error
	 */
	public function normalize( $raw ) {
		if ( is_string( $raw ) ) {
			$decoded = json_decode( $raw, true );
			$raw     = is_array( $decoded ) ? $decoded : null;
		}

		if ( ! is_array( $raw ) ) {
			return $this->error( __( '請選擇序列的受眾。', 'now-crm' ) );
		}

		$type = sanitize_key( (string) ( $raw['type'] ?? '' ) );

		if ( self::TYPE_ALL === $type ) {
			return array( 'type' => self::TYPE_ALL );
		}

		if ( self::TYPE_LIST === $type || self::TYPE_TAG === $type ) {
			$term_id = absint( $raw['term_id'] ?? 0 );
			$term    = $term_id > 0 ? $this->terms->get( $term_id ) : null;

			if ( ! $term || $type !== (string) $term['type'] ) {
				return $this->error( __( '找不到可用的名單或標籤。', 'now-crm' ) );
			}

			return array(
				'type'    => $type,
				'term_id' => $term_id,
			);
		}

		if ( self::TYPE_SEGMENT === $type ) {
			$ref = $raw['segment_id'] ?? 0;

			if ( is_int( $ref ) || ( is_string( $ref ) && preg_match( '/^\d+$/', $ref ) ) ) {
				$ref = (int) $ref;
			} elseif ( is_string( $ref ) ) {
				$ref = trim( $ref );
			} else {
				$ref = '';
			}

			if ( ( is_int( $ref ) && $ref < 1 ) || ( is_string( $ref ) && '' === $ref ) ) {
				return $this->error( __( '請選擇有效的分眾。', 'now-crm' ) );
			}

			$where = $this->segments->where( $ref );

			if ( is_wp_error( $where ) ) {
				return $this->error( $where->get_error_message() );
			}

			return array(
				'type'       => self::TYPE_SEGMENT,
				'segment_id' => $ref,
			);
		}

		if ( self::TYPE_FILTERS === $type ) {
			$plan = AdvancedFilters::from_json( $raw['filters'] ?? null );

			if ( is_wp_error( $plan ) ) {
				return $this->error( $plan->get_error_message() );
			}

			if ( null === $plan || array() === (array) ( $plan['groups'] ?? array() ) ) {
				return $this->error( __( '請至少加入一個篩選條件。', 'now-crm' ) );
			}

			return array(
				'type'    => self::TYPE_FILTERS,
				'filters' => $plan,
			);
		}

		return $this->error( __( '序列受眾類型不正確。', 'now-crm' ) );
	}

	/**
	 * One batch of eligible recipients in ascending contact-ID order.
	 *
	 * @param array<string, mixed> $audience Valid audience.
	 * @param int                  $after_id Last processed contact ID.
	 * @param int                  $limit    Batch size, at most 100.
	 * @return array<int, array{contact_id: int, recipient: string}>|WP_Error
	 */
	public function recipients( array $audience, int $after_id = 0, int $limit = self::MAX_BATCH ) {
		$limit = max( 1, min( self::MAX_BATCH, $limit ) );

		if ( self::TYPE_SEGMENT === ( $audience['type'] ?? '' ) ) {
			$rows = $this->segments->recipients( $audience['segment_id'], max( 0, $after_id ), 0, $limit );

			return is_wp_error( $rows ) ? $this->error( $rows->get_error_message() ) : $rows;
		}

		$built = $this->where( $audience, $after_id );

		if ( is_wp_error( $built ) ) {
			return $built;
		}

		global $wpdb;

		$contacts = Schema::table( 'contacts' );
		$params   = $built['params'];
		$params[] = $limit;

		$sql = "SELECT c.id AS contact_id, c.email AS recipient
			FROM {$contacts} c
			WHERE {$built['sql']}
			ORDER BY c.id ASC
			LIMIT %d";

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
	 * Number of eligible members.
	 *
	 * @param array<string, mixed> $audience Valid audience.
	 * @return int|WP_Error
	 */
	public function count( array $audience ) {
		if ( self::TYPE_SEGMENT === ( $audience['type'] ?? '' ) ) {
			$count = $this->segments->recipient_count( $audience['segment_id'] );

			return is_wp_error( $count ) ? $this->error( $count->get_error_message() ) : $count;
		}

		$built = $this->where( $audience, 0, false );

		if ( is_wp_error( $built ) ) {
			return $built;
		}

		global $wpdb;

		$contacts = Schema::table( 'contacts' );
		$sql      = "SELECT COUNT(*) FROM {$contacts} c WHERE {$built['sql']}";

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders -- table names are trusted, values use placeholders.
		return (int) ( $built['params'] ? $wpdb->get_var( $wpdb->prepare( $sql, $built['params'] ) ) : $wpdb->get_var( $sql ) );
	}

	/**
	 * WHERE fragment with the subscribed and suppression gates.
	 *
	 * @param array<string, mixed> $audience Valid audience.
	 * @param int                  $after_id Cursor.
	 * @param bool                 $apply_cursor Whether to add the cursor.
	 * @return array{sql: string, params: array<int, mixed>}|WP_Error
	 */
	private function where( array $audience, int $after_id, bool $apply_cursor = true ) {
		$links        = Schema::table( 'contact_terms' );
		$terms        = Schema::table( 'terms' );
		$suppressions = Schema::table( 'suppressions' );

		$where  = array(
			'c.status = %s',
			"NOT EXISTS (SELECT 1 FROM {$suppressions} s WHERE s.email_normalized = c.email_normalized)",
		);
		$params = array( Status::SUBSCRIBED );

		$type = (string) ( $audience['type'] ?? '' );

		if ( self::TYPE_LIST === $type || self::TYPE_TAG === $type ) {
			$where[]  = "EXISTS (SELECT 1 FROM {$links} ct INNER JOIN {$terms} t ON t.id = ct.term_id
				WHERE ct.contact_id = c.id AND t.type = %s AND ct.term_id = %d)";
			$params[] = $type;
			$params[] = (int) $audience['term_id'];
		} elseif ( self::TYPE_FILTERS === $type ) {
			$plan = AdvancedFilters::normalize( $audience['filters'] );

			if ( is_wp_error( $plan ) ) {
				return $this->error( $plan->get_error_message() );
			}

			if ( array() === (array) ( $plan['groups'] ?? array() ) ) {
				return $this->error( __( '序列的篩選條件已失效。', 'now-crm' ) );
			}

			$compiled = AdvancedFilters::compile( $plan );
			$where[]  = '(' . $compiled['sql'] . ')';

			foreach ( $compiled['params'] as $param ) {
				$params[] = $param;
			}
		} elseif ( self::TYPE_ALL !== $type ) {
			return $this->error( __( '序列受眾類型不正確。', 'now-crm' ) );
		}

		if ( $apply_cursor ) {
			$where[]  = 'c.id > %d';
			$params[] = max( 0, $after_id );
		}

		return array(
			'sql'    => implode( ' AND ', $where ),
			'params' => $params,
		);
	}

	/**
	 * Stable audience error.
	 */
	private function error( string $message ): WP_Error {
		return new WP_Error( 'nowcrm_sequence_audience', $message );
	}
}
