<?php
/**
 * Typed condition plans for automation branches.
 *
 * Plans reuse the advanced contact filter contract. The compiler uses the
 * contact alias `c`, so one contact row is tested with the same SQL the
 * contact list, export, and bulk actions use.
 *
 * @package NowCRM
 */

namespace NowCRM\Automation;

use NowCRM\Contacts\AdvancedFilters;
use NowCRM\Segments\SegmentResolver;
use NowCRM\Support\Schema;
use WP_Error;

defined( 'ABSPATH' ) || exit;

final class ConditionEvaluator {

	/**
	 * Validate a raw plan for a condition step.
	 *
	 * @param mixed $raw Groups or a normalized plan.
	 * @return array{version: int, groups: array<int, mixed>}|WP_Error
	 */
	public static function normalize( $raw ) {
		$plan = AdvancedFilters::normalize( $raw );

		if ( is_wp_error( $plan ) ) {
			return $plan;
		}

		if ( array() === ( $plan['groups'] ?? array() ) ) {
			return new WP_Error( 'nowcrm_automation_empty_condition', __( '條件步驟至少要有一個條件。', 'now-crm' ) );
		}

		return $plan;
	}

	/**
	 * Evaluate a plan against one contact.
	 *
	 * @param array<string, mixed> $plan       Validated plan.
	 * @param int                  $contact_id Contact ID.
	 * @return bool|WP_Error
	 */
	public static function matches( array $plan, int $contact_id ) {
		global $wpdb;

		$compiled = AdvancedFilters::compile( $plan );
		$table    = Schema::table( 'contacts' );

		$sql = "SELECT c.id FROM {$table} c WHERE c.id = %d AND {$compiled['sql']} LIMIT 1";
		$params = array_merge( array( $contact_id ), $compiled['params'] );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- table is trusted and the compiler returns placeholders only.
		$found = $wpdb->get_var( $wpdb->prepare( $sql, $params ) );
		// phpcs:enable

		if ( '' !== (string) $wpdb->last_error ) {
			return new WP_Error( 'nowcrm_condition_failed', $wpdb->last_error );
		}

		return null !== $found;
	}

	/**
	 * Evaluate a segment reference against one contact.
	 *
	 * A missing, archived, or invalid segment returns WP_Error. A caller must
	 * treat that as "do not continue"; it must never treat it as a match.
	 *
	 * @param int|string $ref        Segment reference.
	 * @param int        $contact_id Contact ID.
	 * @return bool|WP_Error
	 */
	public static function matches_segment( int|string $ref, int $contact_id ) {
		return ( new SegmentResolver() )->contains( $ref, $contact_id );
	}
}
