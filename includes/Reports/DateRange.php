<?php
/**
 * Bounded reporting range.
 *
 * The operator picks site-local days. Stored timestamps are UTC, so the range
 * exposes inclusive local dates plus a half-open UTC window: from_utc is the
 * first second of the first local day and to_utc is the first second after the
 * last local day. The span is capped at MAX_DAYS so one request cannot scan an
 * unbounded history.
 *
 * @package NowCRM
 */

namespace NowCRM\Reports;

defined( 'ABSPATH' ) || exit;

final class DateRange {

	/**
	 * Largest span a report can cover, in local days.
	 */
	public const MAX_DAYS = 366;

	/**
	 * Span used when the request names no valid date.
	 */
	public const DEFAULT_DAYS = 30;

	public function __construct(
		public readonly string $from,
		public readonly string $to,
		public readonly string $from_utc,
		public readonly string $to_utc,
		public readonly \DateTimeZone $timezone = new \DateTimeZone( 'UTC' )
	) {}

	/**
	 * Build a range from request values.
	 *
	 * Invalid values fall back to DEFAULT_DAYS anchored on the other end or on
	 * today. Reversed inputs are swapped. A span above MAX_DAYS keeps the end
	 * date and moves the start date forward.
	 *
	 * @param string|null           $from     Local start date (Y-m-d).
	 * @param string|null           $to       Local end date (Y-m-d).
	 * @param \DateTimeZone|null    $timezone Site timezone; defaults to WordPress.
	 * @param \DateTimeImmutable|null $now    Reference instant for defaults.
	 */
	public static function parse( ?string $from, ?string $to, ?\DateTimeZone $timezone = null, ?\DateTimeImmutable $now = null ): self {
		$timezone = $timezone ?? self::timezone();
		$now      = $now ?? new \DateTimeImmutable( 'now', $timezone );

		$from = self::valid( $from ) ? (string) $from : null;
		$to   = self::valid( $to ) ? (string) $to : null;

		if ( null === $from && null === $to ) {
			$to   = $now->format( 'Y-m-d' );
			$from = self::shift( $to, -( self::DEFAULT_DAYS - 1 ) );
		} elseif ( null === $from ) {
			$from = self::shift( (string) $to, -( self::DEFAULT_DAYS - 1 ) );
		} elseif ( null === $to ) {
			$to = self::shift( $from, self::DEFAULT_DAYS - 1 );
		}

		if ( $from > $to ) {
			[ $from, $to ] = [ $to, $from ];
		}

		if ( self::day_count( (string) $from, (string) $to ) > self::MAX_DAYS ) {
			$from = self::shift( (string) $to, -( self::MAX_DAYS - 1 ) );
		}

		$start = self::local_midnight( (string) $from, $timezone );
		$end   = self::local_midnight( self::shift( (string) $to, 1 ), $timezone );

		return new self(
			(string) $from,
			(string) $to,
			$start->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ),
			$end->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ),
			$timezone
		);
	}

	/**
	 * Shift a plain local date by whole months.
	 *
	 * The day of month is clamped to the target month. PHP's `modify('-1
	 * month')` overflows 2026-03-31 into 2026-03-03, which would put a
	 * comparison start after the end of its own month. This helper never
	 * produces a date outside the target month.
	 *
	 * @param string $date   Valid Y-m-d date.
	 * @param int    $months Signed month count.
	 */
	public static function shift_months( string $date, int $months ): string {
		$value = \DateTimeImmutable::createFromFormat( '!Y-m-d', $date, new \DateTimeZone( 'UTC' ) );

		if ( false === $value ) {
			$value = new \DateTimeImmutable( $date, new \DateTimeZone( 'UTC' ) );
		}

		$day   = (int) $value->format( 'j' );
		$first = $value->modify( 'first day of this month' );

		if ( 0 !== $months ) {
			$first = $first->modify( sprintf( '%+d months', $months ) );
		}

		$day = min( $day, (int) $first->format( 't' ) );

		return $first->setDate( (int) $first->format( 'Y' ), (int) $first->format( 'n' ), $day )->format( 'Y-m-d' );
	}

	/**
	 * SQL expression that turns a UTC datetime column into the exact local
	 * date in this range's timezone.
	 *
	 * The expression uses one constant offset per offset segment, derived in
	 * PHP from the timezone transition table. It does not rely on MySQL
	 * timezone tables. A range usually has one or two segments.
	 *
	 * @param string $column Trusted UTC datetime column reference.
	 * @return array{sql: string, params: array<int, string>} Expression and its placeholders.
	 */
	public function local_day_expression( string $column ): array {
		$segments = $this->offset_segments();

		if ( 1 === count( $segments ) ) {
			$offset = (int) $segments[0]['offset'];

			return array(
				'sql'    => 0 === $offset
					? 'DATE(' . $column . ')'
					: 'DATE(DATE_ADD(' . $column . ', INTERVAL ' . $offset . ' SECOND))',
				'params' => array(),
			);
		}

		$sql    = 'DATE(CASE';
		$params = array();

		// Check the latest transition first so the CASE is a short ladder.
		for ( $index = count( $segments ) - 1; $index >= 1; $index-- ) {
			$sql     .= ' WHEN ' . $column . ' >= %s THEN DATE_ADD(' . $column . ', INTERVAL ' . (int) $segments[ $index ]['offset'] . ' SECOND)';
			$params[] = $segments[ $index ]['utc'];
		}

		$sql .= ' ELSE DATE_ADD(' . $column . ', INTERVAL ' . (int) $segments[0]['offset'] . ' SECOND) END)';

		return array(
			'sql'    => $sql,
			'params' => $params,
		);
	}

	/**
	 * Inclusive number of local days in the range.
	 */
	public function days(): int {
		return self::day_count( $this->from, $this->to );
	}

	/**
	 * Site timezone for the report.
	 */
	private static function timezone(): \DateTimeZone {
		return function_exists( 'wp_timezone' ) ? wp_timezone() : new \DateTimeZone( 'UTC' );
	}

	/**
	 * Constant-offset segments inside the UTC window.
	 *
	 * The first entry applies before its first boundary. Later entries start at
	 * the UTC instant of a DST transition. At most two transitions exist inside
	 * a 366-day range.
	 *
	 * @return array<int, array{utc: string, offset: int}>
	 */
	private function offset_segments(): array {
		$start = strtotime( $this->from_utc . ' UTC' );
		$end   = strtotime( $this->to_utc . ' UTC' );

		if ( false === $start || false === $end ) {
			return array(
				array(
					'utc'    => $this->from_utc,
					'offset' => 0,
				),
			);
		}

		$instant  = new \DateTimeImmutable( $this->from_utc, new \DateTimeZone( 'UTC' ) );
		$segments = array(
			array(
				'utc'    => $this->from_utc,
				'offset' => $this->timezone->getOffset( $instant ),
			),
		);

		foreach ( ( $this->timezone->getTransitions( $start, $end ) ?: array() ) as $transition ) {
			$timestamp = (int) $transition['ts'];

			if ( $timestamp <= $start || $timestamp >= $end ) {
				continue;
			}

			$segments[] = array(
				'utc'    => gmdate( 'Y-m-d H:i:s', $timestamp ),
				'offset' => (int) $transition['offset'],
			);
		}

		return $segments;
	}

	/**
	 * Strict Y-m-d validation.
	 *
	 * @param string|null $value Candidate date.
	 */
	private static function valid( ?string $value ): bool {
		if ( null === $value || 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
			return false;
		}

		[ $year, $month, $day ] = array_map( 'intval', explode( '-', $value ) );

		return checkdate( $month, $day, $year );
	}

	/**
	 * Midnight of a local date.
	 */
	private static function local_midnight( string $date, \DateTimeZone $timezone ): \DateTimeImmutable {
		$value = \DateTimeImmutable::createFromFormat( '!Y-m-d', $date, $timezone );

		if ( false === $value ) {
			$value = new \DateTimeImmutable( $date . ' 00:00:00', $timezone );
		}

		return $value;
	}

	/**
	 * Add days to a plain date without DST effects.
	 */
	private static function shift( string $date, int $days ): string {
		$value = \DateTimeImmutable::createFromFormat( '!Y-m-d', $date, new \DateTimeZone( 'UTC' ) );

		if ( false === $value ) {
			$value = new \DateTimeImmutable( $date, new \DateTimeZone( 'UTC' ) );
		}

		return $value->modify( sprintf( '%+d days', $days ) )->format( 'Y-m-d' );
	}

	/**
	 * Inclusive day count between two plain dates.
	 */
	private static function day_count( string $from, string $to ): int {
		$start = new \DateTimeImmutable( $from, new \DateTimeZone( 'UTC' ) );
		$end   = new \DateTimeImmutable( $to, new \DateTimeZone( 'UTC' ) );

		return (int) $start->diff( $end )->days + 1;
	}
}
