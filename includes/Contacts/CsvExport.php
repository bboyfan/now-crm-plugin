<?php
/**
 * Contact CSV export over the native repositories.
 *
 * A run exports either the contacts matching the list filters or an explicit
 * set of contact IDs. Rows stream in pages, so the exporter never hydrates
 * every contact. Group membership comes from the contact term summary and
 * custom fields from the typed value store.
 *
 * Every cell passes through safe_cell(): a value that starts with a formula
 * character is prefixed with an apostrophe, so a spreadsheet cannot execute
 * contact data.
 *
 * @package NowCRM
 */

namespace NowCRM\Contacts;

use NowCRM\Segments\SegmentRepository;
use NowCRM\Segments\SegmentResolver;
use NowCRM\Terms\TermRepository;
use WP_Error;

defined( 'ABSPATH' ) || exit;

final class CsvExport {

	/**
	 * Hard upper bound for one export.
	 */
	public const MAX_ROWS = 100000;

	/**
	 * Maximum selected contact IDs.
	 */
	public const MAX_SELECTED = 500;

	/**
	 * Contacts per page.
	 */
	public const PER_PAGE = 100;

	/**
	 * Maximum selected columns.
	 */
	public const MAX_COLUMNS = 50;

	/**
	 * Maximum selected custom fields.
	 */
	public const MAX_CUSTOM = 100;

	private ContactRepository $contacts;
	private TermRepository $terms;
	private CustomFieldValues $values;

	public function __construct(
		?ContactRepository $contacts = null,
		?TermRepository $terms = null,
		?CustomFieldValues $values = null
	) {
		$this->contacts = $contacts ?? new ContactRepository();
		$this->terms    = $terms ?? new TermRepository();
		$this->values   = $values ?? new CustomFieldValues();
	}

	/**
	 * Standard export columns, in menu order. Read-only derived columns
	 * (aggregate, terms) are represented by explicit list/tag columns.
	 *
	 * @return array<string, string>
	 */
	public static function columns(): array {
		$columns = array(
			'id'             => __( 'ID', 'now-crm' ),
			'email'          => __( 'Email', 'now-crm' ),
			'first_name'     => __( '名字', 'now-crm' ),
			'last_name'      => __( '姓氏', 'now-crm' ),
			'prefix'         => __( '稱謂', 'now-crm' ),
			'contact_type'   => __( '聯絡人類型', 'now-crm' ),
			'date_of_birth'  => __( '出生日期', 'now-crm' ),
			'timezone'       => __( '時區', 'now-crm' ),
			'address_line_1' => __( '地址第 1 行', 'now-crm' ),
			'address_line_2' => __( '地址第 2 行', 'now-crm' ),
			'city'           => __( '城市', 'now-crm' ),
			'state'          => __( '州／省／地區', 'now-crm' ),
			'postal_code'    => __( '郵遞區號', 'now-crm' ),
			'country'        => __( '國家代碼', 'now-crm' ),
			'phone'          => __( '電話', 'now-crm' ),
			'status'         => __( '狀態', 'now-crm' ),
			'source'         => __( '來源', 'now-crm' ),
			'created_at'     => __( '建立時間', 'now-crm' ),
			'updated_at'     => __( '更新時間', 'now-crm' ),
			'lists'          => __( '名單', 'now-crm' ),
			'tags'           => __( '標籤', 'now-crm' ),
		);

		return (array) apply_filters( 'nowcrm_csv_export_columns', $columns );
	}

	/**
	 * Custom field definitions available as columns.
	 *
	 * @return array<string, string>
	 */
	public static function custom_columns(): array {
		$columns = array();

		foreach ( CustomFieldDefinitions::all() as $field ) {
			$columns[ $field['slug'] ] = $field['label'];
		}

		return $columns;
	}

	/**
	 * Validate an export request and resolve its scope.
	 *
	 * @param array<string, mixed> $args scope, search, status, term_id, ids, columns, custom_fields.
	 * @return array<string, mixed>|WP_Error
	 */
	public function plan( array $args ) {
		$available = self::columns();
		$columns   = array();

		foreach ( (array) ( $args['columns'] ?? array() ) as $column ) {
			if ( ! is_scalar( $column ) ) {
				continue;
			}

			$column = sanitize_key( (string) $column );

			if ( isset( $available[ $column ] ) ) {
				$columns[ $column ] = $column;
			}
		}

		if ( array() === $columns ) {
			return new WP_Error( 'nowcrm_csv_invalid_columns', __( '請至少選擇一個匯出欄位。', 'now-crm' ) );
		}

		if ( count( $columns ) > self::MAX_COLUMNS ) {
			return new WP_Error( 'nowcrm_csv_invalid_columns', __( '匯出欄位過多。', 'now-crm' ) );
		}

		$custom = array();

		foreach ( (array) ( $args['custom_fields'] ?? array() ) as $slug ) {
			if ( ! is_scalar( $slug ) ) {
				continue;
			}

			$field = CustomFieldDefinitions::get( (string) $slug );

			if ( null !== $field ) {
				$custom[ $field['slug'] ] = $field['slug'];
			}
		}

		if ( count( $custom ) > self::MAX_CUSTOM ) {
			return new WP_Error( 'nowcrm_csv_invalid_columns', __( '自訂欄位過多。', 'now-crm' ) );
		}

		$scope = 'selected' === ( $args['scope'] ?? '' ) ? 'selected' : 'filtered';

		if ( 'selected' === $scope ) {
			$ids = array();

			foreach ( (array) ( $args['ids'] ?? array() ) as $id ) {
				if ( is_scalar( $id ) ) {
					$id = absint( $id );

					if ( $id > 0 ) {
						$ids[ $id ] = $id;
					}
				}
			}

			if ( array() === $ids ) {
				return new WP_Error( 'nowcrm_csv_no_selection', __( '請選擇要匯出的聯絡人。', 'now-crm' ) );
			}

			if ( count( $ids ) > self::MAX_SELECTED ) {
				return new WP_Error( 'nowcrm_csv_too_many_selected', __( '一次最多匯出 500 位選取的聯絡人；大量匯出請用篩選範圍。', 'now-crm' ) );
			}

			$existing = $this->contacts->existing_ids( array_values( $ids ) );

			if ( array() === $existing ) {
				return new WP_Error( 'nowcrm_csv_no_contacts', __( '找不到可匯出的聯絡人。', 'now-crm' ) );
			}

			return array(
				'scope'         => 'selected',
				'ids'           => $existing,
				'columns'       => array_values( $columns ),
				'custom_fields' => array_values( $custom ),
				'total'         => count( $existing ),
			);
		}

		$status = sanitize_key( (string) ( $args['status'] ?? '' ) );
		$status = Status::is_valid( $status ) ? $status : '';

		// A segment reference streams live membership, including system
		// segments that have no stored filter plan.
		$segment = trim( sanitize_text_field( (string) ( $args['segment'] ?? '' ) ) );

		if ( '' !== $segment ) {
			$segments = new SegmentRepository();

			if ( null === $segments->find( $segment ) ) {
				return new WP_Error( 'nowcrm_csv_invalid_segment', __( '找不到這個分眾。', 'now-crm' ) );
			}

			$total = ( new SegmentResolver( $segments ) )->count( $segment );

			if ( is_wp_error( $total ) ) {
				return $total;
			}

			if ( $total < 1 ) {
				return new WP_Error( 'nowcrm_csv_no_contacts', __( '目前篩選沒有可匯出的聯絡人。', 'now-crm' ) );
			}

			if ( $total > self::MAX_ROWS ) {
				return new WP_Error( 'nowcrm_csv_too_many_rows', __( '匯出超過 100,000 筆，請縮小篩選範圍。', 'now-crm' ) );
			}

			return array(
				'scope'         => 'filtered',
				'segment'       => $segment,
				'filters'       => array(),
				'columns'       => array_values( $columns ),
				'custom_fields' => array_values( $custom ),
				'total'         => (int) $total,
			);
		}

		$filters = array(
			'search'  => sanitize_text_field( (string) ( $args['search'] ?? '' ) ),
			'status'  => $status,
			'term_id' => absint( $args['term_id'] ?? 0 ),
		);

		// The list form sends the plan as JSON. A rejected plan stops the
		// export; it must not fall back to an unfiltered file.
		if ( array_key_exists( 'filters', $args ) ) {
			$advanced = AdvancedFilters::from_json( $args['filters'] );

			if ( is_wp_error( $advanced ) ) {
				return $advanced;
			}

			if ( null !== $advanced ) {
				$filters['filters'] = $advanced;
			}
		}

		$bounds = $this->contacts->search_bounds( $filters );

		if ( (int) $bounds['matching'] < 1 ) {
			return new WP_Error( 'nowcrm_csv_no_contacts', __( '目前篩選沒有可匯出的聯絡人。', 'now-crm' ) );
		}

		if ( (int) $bounds['matching'] > self::MAX_ROWS ) {
			return new WP_Error( 'nowcrm_csv_too_many_rows', __( '匯出超過 100,000 筆，請縮小篩選範圍。', 'now-crm' ) );
		}

		return array(
			'scope'         => 'filtered',
			'filters'       => $filters,
			'columns'       => array_values( $columns ),
			'custom_fields' => array_values( $custom ),
			'total'         => (int) $bounds['matching'],
		);
	}

	/**
	 * Header labels for a plan.
	 *
	 * @param array<string, mixed> $plan Validated plan.
	 * @return string[]
	 */
	public function header( array $plan ): array {
		$available = self::columns();
		$custom    = self::custom_columns();
		$labels    = array();

		foreach ( (array) $plan['columns'] as $column ) {
			$labels[] = $available[ $column ] ?? $column;
		}

		foreach ( (array) $plan['custom_fields'] as $slug ) {
			$labels[] = $custom[ $slug ] ?? $slug;
		}

		return $labels;
	}

	/**
	 * Stream export rows. Each yield is one complete row of raw values.
	 *
	 * @param array<string, mixed> $plan Validated plan.
	 * @return \Generator<int, string[]>
	 */
	public function rows( array $plan ): \Generator {
		$columns       = (array) $plan['columns'];
		$custom_fields = (array) $plan['custom_fields'];
		$exported      = 0;

		if ( 'selected' === ( $plan['scope'] ?? '' ) ) {
			foreach ( array_chunk( (array) $plan['ids'], self::PER_PAGE ) as $chunk ) {
				$custom_values = $this->values->for_contacts( $chunk );

				foreach ( $chunk as $id ) {
					if ( $exported >= self::MAX_ROWS ) {
						return;
					}

					$contact = $this->contacts->get( (int) $id );

					if ( ! $contact ) {
						continue;
					}

					$contact['terms'] = $this->terms->for_contact( (int) $id );

					yield $this->build_row( $contact, $columns, $custom_fields, $custom_values[ (int) $id ] ?? array() );
					++$exported;
				}
			}

			return;
		}

		$filters = (array) $plan['filters'];
		$total   = (int) $plan['total'];
		$page    = 1;

		if ( '' !== (string) ( $plan['segment'] ?? '' ) ) {
			$resolver = new SegmentResolver();
			$after    = 0;

			while ( $exported < $total && $exported < self::MAX_ROWS ) {
				$ids = $resolver->ids( (string) $plan['segment'], $after, 0, self::PER_PAGE );

				if ( is_wp_error( $ids ) || array() === $ids ) {
					return;
				}

				$custom_values = $this->values->for_contacts( $ids );

				foreach ( $ids as $id ) {
					if ( $exported >= self::MAX_ROWS ) {
						return;
					}

					$contact = $this->contacts->get( (int) $id );

					if ( ! $contact ) {
						continue;
					}

					$contact['terms'] = $this->terms->for_contact( (int) $id );

					yield $this->build_row( $contact, $columns, $custom_fields, $custom_values[ (int) $id ] ?? array() );
					++$exported;
				}

				$after = (int) end( $ids );
			}

			return;
		}

		while ( $exported < $total && $exported < self::MAX_ROWS ) {
			$result = $this->contacts->search(
				array_merge(
					$filters,
					array(
						'page'     => $page,
						'per_page' => self::PER_PAGE,
						'orderby'  => 'id',
						'order'    => 'ASC',
					)
				)
			);

			$items = (array) $result['items'];

			if ( array() === $items ) {
				return;
			}

			$ids           = array_map( static fn( array $item ): int => (int) $item['id'], $items );
			$custom_values = $this->values->for_contacts( $ids );

			foreach ( $items as $item ) {
				if ( $exported >= self::MAX_ROWS ) {
					return;
				}

				yield $this->build_row(
					$item,
					$columns,
					$custom_fields,
					$custom_values[ (int) $item['id'] ] ?? array()
				);
				++$exported;
			}

			++$page;
		}
	}

	/**
	 * Build one raw row.
	 *
	 * @param array<string, mixed> $contact       Contact row, possibly with terms.
	 * @param string[]             $columns       Standard columns.
	 * @param string[]             $custom_fields Custom slugs.
	 * @param array<string, mixed> $custom_values Custom values for the contact.
	 * @return string[]
	 */
	private function build_row( array $contact, array $columns, array $custom_fields, array $custom_values ): array {
		$row = array();

		foreach ( $columns as $column ) {
			if ( 'lists' === $column || 'tags' === $column ) {
				$type  = 'lists' === $column ? TermRepository::TYPE_LIST : TermRepository::TYPE_TAG;
				$names = array();

				foreach ( (array) ( $contact['terms'] ?? array() ) as $term ) {
					if ( (string) ( $term['type'] ?? '' ) === $type ) {
						$names[] = (string) ( $term['name'] ?? '' );
					}
				}

				$row[] = implode( ', ', $names );
				continue;
			}

			$value = $contact[ $column ] ?? '';
			$row[] = null === $value ? '' : (string) $value;
		}

		foreach ( $custom_fields as $slug ) {
			$row[] = CustomFieldValues::to_csv( $custom_values[ $slug ] ?? '' );
		}

		return $row;
	}

	/**
	 * Spreadsheet formula safety for one cell.
	 *
	 * @param mixed $value Raw value.
	 */
	public static function safe_cell( $value ): string {
		if ( null === $value || '' === $value ) {
			return '';
		}

		if ( ! is_scalar( $value ) ) {
			return '';
		}

		$value = (string) $value;

		if ( '' === $value ) {
			return '';
		}

		if ( in_array( $value[0], array( '=', '-', '+', '@', "\t", "\r" ), true ) ) {
			$value = "'" . $value;
		}

		return $value;
	}

	/**
	 * Download file name.
	 */
	public static function filename(): string {
		return 'nowcrm-contacts-' . gmdate( 'Ymd-Hi' ) . '.csv';
	}
}
