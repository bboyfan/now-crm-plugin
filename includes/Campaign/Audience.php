<?php
/**
 * Campaign recipient rules and the query shared by review and queue creation.
 *
 * @package NowCRM
 */

namespace NowCRM\Campaign;

use NowCRM\Contacts\Status;
use NowCRM\Segments\SegmentRepository;
use NowCRM\Segments\SegmentResolver;
use NowCRM\Support\Schema;
use NowCRM\Terms\TermRepository;

defined( 'ABSPATH' ) || exit;

final class Audience {

	public const PER_PAGE = 20;

	private const MAX_ROWS = 50;

	private const SCOPES = array( 'all', 'rules', 'segments', 'advanced' );

	/**
	 * Draft audience as the editor saved it.
	 *
	 * Read-only: stale references and saved advanced plans stay intact so the
	 * editor can repair them. Validation and normalization happen on save,
	 * preview, review, and confirmation, never here.
	 */
	public static function stored( $value ): array {
		$value = is_string( $value ) ? json_decode( $value, true ) : $value;

		if ( ! is_array( $value ) ) {
			$value = array();
		}

		$scope = $value['scope'] ?? '';
		$scope = in_array( $scope, self::SCOPES, true ) ? $scope : 'rules';

		$stored = array(
			'scope'   => $scope,
			'include' => self::rows( $value['include'] ?? array() ),
			'exclude' => self::rows( $value['exclude'] ?? array() ),
		);

		if ( 'segments' === $scope ) {
			$stored['segment'] = self::segment_ref( $value['segment'] ?? 0 );
		} elseif ( 'advanced' === $scope ) {
			$stored['filters'] = isset( $value['filters'] ) && is_array( $value['filters'] ) ? $value['filters'] : null;
		}

		return $stored;
	}

	private static function rows( $rows ): array {
		$clean = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$list    = max( 0, (int) ( $row['list'] ?? 0 ) );
			$tag     = max( 0, (int) ( $row['tag'] ?? 0 ) );
			$segment = self::segment_ref( $row['segment'] ?? null );
			if ( $list || $tag || 0 !== $segment && '' !== $segment ) {
				$clean[ $list . ':' . $tag . ':' . $segment ] = array( 'list' => $list, 'tag' => $tag, 'segment' => $segment );
			}
		}
		return array_values( $clean );
	}

	/**
	 * Segment reference from a stored row.
	 *
	 * An integer is a stored segment ID. A string is `custom:<id>` or a system
	 * key. Anything else becomes an empty value. An unknown non-empty string is
	 * kept so validation can reject it instead of silently dropping the row.
	 *
	 * @param mixed $value Raw value.
	 * @return int|string
	 */
	private static function segment_ref( $value ) {
		if ( is_int( $value ) ) {
			return max( 0, $value );
		}

		if ( is_string( $value ) ) {
			$value = substr( trim( $value ), 0, 80 );

			if ( '' === $value ) {
				return 0;
			}

			return ctype_digit( $value ) ? (int) $value : $value;
		}

		return 0;
	}

	public static function validate( $value ) {
		$error = Campaigns::error( 'invalid_audience', __( '請檢查收件規則。名單、標籤或分眾可能已被刪除或封存。', 'now-crm' ) );
		if ( ! is_array( $value ) || ! in_array( $value['scope'] ?? '', self::SCOPES, true ) ) {
			return $error;
		}

		$scope = $value['scope'];

		// Exclusions always validate. Include rules validate only where they
		// are active; elsewhere they persist untouched so switching scopes
		// never loses rules or blocks an unrelated save.
		$exclude = self::validate_rows( $value['exclude'] ?? array(), true );
		if ( is_wp_error( $exclude ) ) {
			return $exclude;
		}
		$include = self::validate_rows( $value['include'] ?? array(), 'rules' === $scope );
		if ( is_wp_error( $include ) ) {
			return $include;
		}

		$stored = array(
			'scope'   => $scope,
			'include' => $include,
			'exclude' => $exclude,
		);

		if ( 'segments' === $scope ) {
			$segment = self::segment_ref( $value['segment'] ?? 0 );
			if ( ( 0 !== $segment && '' !== $segment ) && is_wp_error( ( new SegmentResolver() )->where( $segment ) ) ) {
				return $error;
			}
			$stored['segment'] = $segment;
		} elseif ( 'advanced' === $scope ) {
			if ( self::filters_empty( $value['filters'] ?? null ) ) {
				$stored['filters'] = null;
			} else {
				$plan = RecipientFilters::normalize( $value['filters'] );
				if ( is_wp_error( $plan ) ) {
					$message = $plan->get_error_message();
					return Campaigns::error(
						'invalid_audience',
						'' !== $message ? $message : __( '進階篩選條件不正確。請檢查後再儲存。', 'now-crm' )
					);
				}
				$stored['filters'] = $plan;
			}
		}

		return $stored;
	}

	/**
	 * Row references, checked only when the rules are active.
	 *
	 * @param mixed $rows   Raw rows.
	 * @param bool  $strict Check list, tag, and segment references.
	 * @return array|\WP_Error
	 */
	private static function validate_rows( $rows, bool $strict ) {
		$error = Campaigns::error( 'invalid_audience', __( '請檢查收件規則。名單、標籤或分眾可能已被刪除或封存。', 'now-crm' ) );
		if ( ! is_array( $rows ) || count( $rows ) > self::MAX_ROWS ) {
			return $error;
		}

		$clean = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				return $error;
			}
			$list    = $row['list'] ?? 0;
			$tag     = $row['tag'] ?? 0;
			$segment = $row['segment'] ?? 0;
			if ( ! is_int( $list ) || ! is_int( $tag ) || ( ! is_int( $segment ) && ! is_string( $segment ) ) ) {
				return $error;
			}
			if ( $strict ) {
				$terms    = new TermRepository();
				$segments = new SegmentResolver();
				foreach ( array( 'list' => $list, 'tag' => $tag ) as $type => $id ) {
					if ( $id < 0 || ( $id && $type !== ( $terms->get( $id )['type'] ?? '' ) ) ) {
						return $error;
					}
				}
				if ( ( 0 !== $segment && '' !== $segment ) && is_wp_error( $segments->where( $segment ) ) ) {
					return $error;
				}
			}
			$clean[] = array( 'list' => max( 0, $list ), 'tag' => max( 0, $tag ), 'segment' => $segment );
		}

		return self::rows( $clean );
	}

	/**
	 * Whether the advanced plan carries no conditions at all.
	 *
	 * An empty plan saves and selects nobody; only a populated plan can be
	 * malformed.
	 *
	 * @param mixed $filters Raw filters value.
	 */
	private static function filters_empty( $filters ): bool {
		if ( null === $filters || '' === $filters || array() === $filters ) {
			return true;
		}
		if ( ! is_array( $filters ) ) {
			return false;
		}
		$groups = $filters['groups'] ?? null;
		return is_array( $groups ) && array() === $groups;
	}

	/** Recipient selection with positional SQL parameters. */
	public static function selection( array $audience ): array {
		$contacts = Schema::table( 'contacts' );
		$links = Schema::table( 'contact_terms' );
		$terms = Schema::table( 'terms' );
		$suppressions = Schema::table( 'suppressions' );
		$where = array( 'c.status = %s', "NOT EXISTS (SELECT 1 FROM {$suppressions} s WHERE s.email_normalized = c.email_normalized)" );
		$params = array( Status::SUBSCRIBED );

		$population = self::population_sql( $audience );

		if ( null === $population ) {
			// Fail closed: an unknown qualifier selects nobody, never everyone.
			$where[] = '1 = 0';
		} else {
			$where[] = $population[0];
			foreach ( $population[1] as $param ) {
				$params[] = $param;
			}
		}

		$exclusion = self::exclusion_sql( $audience['exclude'] ?? array() );

		if ( null === $exclusion ) {
			// A stale exclusion must not silently stop excluding.
			$where[] = '1 = 0';
		} elseif ( '' !== $exclusion[0] ) {
			$where[] = 'NOT (' . $exclusion[0] . ')';
			foreach ( $exclusion[1] as $param ) {
				$params[] = $param;
			}
		}

		return array( "FROM {$contacts} c WHERE " . implode( ' AND ', $where ), $params );
	}

	/**
	 * Population predicate for the active scope. Null selects nobody.
	 *
	 * @return array{0: string, 1: array}|null
	 */
	private static function population_sql( array $audience ): ?array {
		switch ( $audience['scope'] ?? 'rules' ) {
			case 'all':
				return array( '1 = 1', array() );
			case 'segments':
				return self::segment_sql( $audience['segment'] ?? 0 );
			case 'advanced':
				return self::advanced_sql( $audience['filters'] ?? null );
			default:
				return self::include_sql( $audience['include'] ?? array() );
		}
	}

	/**
	 * List/tag/segment rules: AND within a row, OR across rows.
	 *
	 * @return array{0: string, 1: array}|null
	 */

	private static function include_sql( $rows ): ?array {
		$rules  = array();
		$params = array();

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$rule = self::rule_sql( $row );

			if ( null === $rule ) {
				$rules[] = '1 = 0';
				continue;
			}

			$rules[] = $rule[0];
			foreach ( $rule[1] as $param ) {
				$params[] = $param;
			}
		}

		if ( array() === $rules ) {
			return null;
		}

		return array( '(' . implode( ' OR ', $rules ) . ')', $params );
	}

	/**
	 * Exclusion predicate: OR across rows, negated by the caller.
	 *
	 * Null means a row cannot compile, so the caller selects nobody.
	 *
	 * @return array{0: string, 1: array}|null
	 */
	private static function exclusion_sql( $rows ): ?array {
		$rules  = array();
		$params = array();

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$rule = self::rule_sql( $row );

			if ( null === $rule ) {
				return null;
			}

			$rules[] = $rule[0];
			foreach ( $rule[1] as $param ) {
				$params[] = $param;
			}
		}

		if ( array() === $rules ) {
			return array( '', array() );
		}

		return array( implode( ' OR ', $rules ), $params );
	}

	/**
	 * One rule: AND across its list, tag, and segment conditions.
	 *
	 * Null means the row cannot compile: an empty row or a stale reference.
	 *
	 * @return array{0: string, 1: array}|null
	 */
	private static function rule_sql( $row ): ?array {
		if ( ! is_array( $row ) ) {
			return null;
		}

		$links    = Schema::table( 'contact_terms' );
		$terms    = Schema::table( 'terms' );

		$conditions = array();
		$params     = array();

		foreach ( array( 'list', 'tag' ) as $type ) {
			$id = isset( $row[ $type ] ) ? (int) $row[ $type ] : 0;
			if ( $id > 0 ) {
				$conditions[] = "EXISTS (SELECT 1 FROM {$links} ct INNER JOIN {$terms} t ON t.id = ct.term_id
					WHERE ct.contact_id = c.id AND t.type = %s AND ct.term_id = %d)";
				$params[] = $type;
				$params[] = $id;
			}
		}

		$segment = $row['segment'] ?? 0;

		if ( 0 !== $segment && '' !== $segment ) {
			if ( ! is_int( $segment ) && ! is_string( $segment ) ) {
				return null;
			}
			$compiled = ( new SegmentResolver() )->where( $segment );

			if ( is_wp_error( $compiled ) ) {
				// A stale or archived reference must not widen the rule.
				return null;
			}

			$conditions[] = '(' . $compiled['sql'] . ')';

			foreach ( $compiled['params'] as $param ) {
				$params[] = $param;
			}
		}

		if ( array() === $conditions ) {
			return null;
		}

		return array( '(' . implode( ' AND ', $conditions ) . ')', $params );
	}

	/**
	 * Segment qualifier predicate. Null selects nobody.
	 *
	 * @param mixed $segment Segment reference.
	 * @return array{0: string, 1: array}|null
	 */
	private static function segment_sql( $segment ): ?array {
		if ( ( ! is_int( $segment ) && ! is_string( $segment ) ) || 0 === $segment || '' === $segment ) {
			return null;
		}

		$compiled = ( new SegmentResolver() )->where( $segment );

		if ( is_wp_error( $compiled ) ) {
			return null;
		}

		return array( '(' . $compiled['sql'] . ')', array_values( (array) ( $compiled['params'] ?? array() ) ) );
	}

	/**
	 * Advanced plan predicate. Null selects nobody.
	 *
	 * The plan normalizes here too, so a stored draft that was never
	 * validated (or whose catalog changed) still fails closed at compile.
	 *
	 * @param mixed $filters Raw or normalized plan.
	 * @return array{0: string, 1: array}|null
	 */
	private static function advanced_sql( $filters ): ?array {
		if ( self::filters_empty( $filters ) ) {
			return null;
		}

		$plan = RecipientFilters::normalize( $filters );

		if ( is_wp_error( $plan ) || empty( $plan['groups'] ) ) {
			return null;
		}

		$compiled = RecipientFilters::compile( $plan );
		$sql      = is_array( $compiled ) ? trim( (string) ( $compiled['sql'] ?? '' ) ) : '';

		if ( '' === $sql || 1 === preg_match( '/^1\s*=\s*1$/', $sql ) ) {
			return null;
		}

		return array( "({$sql})", array_values( (array) ( $compiled['params'] ?? array() ) ) );
	}

	public static function recipients( array $audience ): ?array {
		global $wpdb;
		[ $sql, $params ] = self::selection( $audience );
		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- the fragment holds fixed literals and %s/%d placeholders; every value passes through prepare().
		return $wpdb->get_results( $wpdb->prepare( "SELECT c.id AS contact_id, c.email AS recipient {$sql} ORDER BY c.id", $params ), ARRAY_A );
	}

	/**
	 * Recipient total for an estimate. Null means the query failed.
	 */
	public static function count_recipients( array $audience ): ?int {
		global $wpdb;
		[ $sql, $params ] = self::selection( $audience );
		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- the fragment holds fixed literals and %s/%d placeholders; every value passes through prepare().
		$total = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) {$sql}", $params ) );

		if ( '' !== $wpdb->last_error ) {
			return null;
		}

		return max( 0, (int) $total );
	}

	/**
	 * One bounded preview page, clamped to the last valid page.
	 *
	 * Read-only: no writes, no snapshot, no content checks.
	 *
	 * @return array{count: int, page: int, per_page: int, recipients: array<int, array{id: int, email: string, name: string}>}|\WP_Error
	 */
	public static function preview_page( array $audience, int $page = 1 ) {
		global $wpdb;

		$total = self::count_recipients( $audience );

		if ( null === $total ) {
			return Campaigns::storage_error();
		}

		$pages = max( 1, (int) ceil( $total / self::PER_PAGE ) );
		$page  = min( max( 1, $page ), $pages );

		[ $sql, $params ] = self::selection( $audience );
		$params[] = self::PER_PAGE;
		$params[] = ( $page - 1 ) * self::PER_PAGE;

		// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter -- the fragment holds fixed literals and %s/%d placeholders; every value passes through prepare().
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT c.id AS id, c.email AS email, TRIM(CONCAT(c.first_name, ' ', c.last_name)) AS name {$sql} ORDER BY c.id ASC LIMIT %d OFFSET %d",
				$params
			),
			ARRAY_A
		);
		// phpcs:enable

		if ( ! is_array( $rows ) || '' !== $wpdb->last_error ) {
			return Campaigns::storage_error();
		}

		$recipients = array();
		foreach ( $rows as $row ) {
			$recipients[] = array(
				'id'    => (int) $row['id'],
				'email' => (string) $row['email'],
				'name'  => (string) ( $row['name'] ?? '' ),
			);
		}

		return array(
			'count'      => $total,
			'page'       => $page,
			'per_page'   => self::PER_PAGE,
			'recipients' => $recipients,
		);
	}

	public static function describe( array $audience ): string {
		$scope = $audience['scope'] ?? 'rules';

		switch ( $scope ) {
			case 'all':
				$label = __( '所有已訂閱聯絡人', 'now-crm' );
				break;
			case 'segments':
				$label = self::segment_label( $audience['segment'] ?? 0 );
				break;
			case 'advanced':
				$filters = $audience['filters'] ?? null;
				$groups  = is_array( $filters ) && is_array( $filters['groups'] ?? null ) ? count( $filters['groups'] ) : 0;
				/* translators: %d: number of advanced filter groups. */
				$label   = $groups ? sprintf( __( '進階篩選（%d 組）', 'now-crm' ), $groups ) : __( '待選擇收件人', 'now-crm' );
				break;
			default:
				$include = count( $audience['include'] ?? array() );
				/* translators: %d: number of include groups. */
				$label   = $include ? sprintf( __( '%d 組包含條件', 'now-crm' ), $include ) : __( '待選擇收件人', 'now-crm' );
		}

		$exclude = count( $audience['exclude'] ?? array() );

		/* translators: %d: number of exclude groups. */
		return $exclude ? $label . sprintf( __( '（排除 %d 組）', 'now-crm' ), $exclude ) : $label;
	}

	/**
	 * Segment qualifier label, tolerant of stale references.
	 *
	 * @param mixed $segment Segment reference.
	 */
	private static function segment_label( $segment ): string {
		if ( ( ! is_int( $segment ) && ! is_string( $segment ) ) || 0 === $segment || '' === $segment ) {
			return __( '待選擇收件人', 'now-crm' );
		}

		$found = ( new SegmentRepository() )->find( $segment );
		$name  = is_array( $found ) ? (string) ( $found['name'] ?? '' ) : '';

		if ( '' === $name ) {
			$name = (string) $segment;
		}

		/* translators: %s: segment name. */
		return sprintf( __( '分眾：%s', 'now-crm' ), $name );
	}
}
