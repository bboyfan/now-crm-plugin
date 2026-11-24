<?php
/**
 * CSV shape for the campaign report table.
 *
 * Cell output reuses the contact exporter's safe_cell(), so a campaign title
 * that starts with a formula character cannot execute inside a spreadsheet.
 * Rates print as percent numbers; an unavailable rate prints as an empty cell.
 *
 * @package NowCRM
 */

namespace NowCRM\Reports;

use NowCRM\Campaign\Campaigns;
use NowCRM\Contacts\CsvExport;

defined( 'ABSPATH' ) || exit;

final class ReportCsv {

	/**
	 * Column labels in export order.
	 *
	 * @return string[]
	 */
	public static function header(): array {
		return array(
			__( '電子報 ID', 'now-crm' ),
			__( '名稱', 'now-crm' ),
			__( '狀態', 'now-crm' ),
			__( '已交付', 'now-crm' ),
			__( '開信', 'now-crm' ),
			__( '點擊', 'now-crm' ),
			__( '退信', 'now-crm' ),
			__( '投訴', 'now-crm' ),
			__( '退訂', 'now-crm' ),
			__( '開信率 (%)', 'now-crm' ),
			__( '點擊率 (%)', 'now-crm' ),
			__( '退信率 (%)', 'now-crm' ),
			__( '投訴率 (%)', 'now-crm' ),
			__( '退訂率 (%)', 'now-crm' ),
		);
	}

	/**
	 * One safe CSV row.
	 *
	 * @param array<string, mixed> $row Campaign row from ReportService.
	 * @return string[]
	 */
	public static function row( array $row ): array {
		$cells = array(
			(int) ( $row['campaign_id'] ?? 0 ),
			(string) ( $row['name'] ?? '' ),
			Campaigns::status_label( (string) ( $row['status'] ?? '' ) ),
			(int) ( $row['accepted'] ?? 0 ),
			(int) ( $row['opens'] ?? 0 ),
			(int) ( $row['clicks'] ?? 0 ),
			(int) ( $row['bounces'] ?? 0 ),
			(int) ( $row['complaints'] ?? 0 ),
			(int) ( $row['unsubscribes'] ?? 0 ),
			self::percent( $row['open_rate'] ?? null ),
			self::percent( $row['click_rate'] ?? null ),
			self::percent( $row['bounce_rate'] ?? null ),
			self::percent( $row['complaint_rate'] ?? null ),
			self::percent( $row['unsubscribe_rate'] ?? null ),
		);

		return array_map( array( CsvExport::class, 'safe_cell' ), $cells );
	}

	/**
	 * Export filename for the range.
	 */
	public static function filename( DateRange $range ): string {
		return 'nowcrm-report-' . $range->from . '-' . $range->to . '.csv';
	}

	/**
	 * Percent cell. A null rate means the denominator was zero.
	 *
	 * @param mixed $rate Rate between 0 and 1, or null.
	 */
	private static function percent( $rate ): string {
		if ( null === $rate || ! is_numeric( $rate ) ) {
			return '';
		}

		return number_format( (float) $rate * 100, 2, '.', '' );
	}
}
