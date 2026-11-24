<?php
/**
 * Per-contact custom-field values.
 *
 * Values live in one row per contact and field key. The value column stores
 * JSON so a scalar and a list keep their type:
 *
 *     array( 'company_size' => '11-50', 'interests' => array( 'News', 'Events' ) )
 *
 * The table is `Schema::table( 'contact_field_values' )`, created by
 * Schema::install() with this shape:
 *
 *     CREATE TABLE {$prefix}nowcrm_contact_field_values (
 *         id bigint(20) unsigned NOT NULL auto_increment,
 *         contact_id bigint(20) unsigned NOT NULL,
 *         field_key varchar(64) NOT NULL,
 *         value longtext NULL,
 *         updated_at datetime NOT NULL,
 *         PRIMARY KEY  (id),
 *         UNIQUE KEY contact_field (contact_id,field_key),
 *         KEY field_key (field_key)
 *     ) {$charset};
 *
 * Reads always use one query per contact, or one query per page of contacts.
 * A missing definition rejects the key, so a partial patch cannot create orphan
 * rows. Every write validates the complete submitted subset first. An empty
 * value, or an empty list, deletes the row: that is the explicit clear.
 *
 * Regular WordPress form submit: two inputs per field keep "untouched" and
 * "cleared" apart, because an unchecked checkbox group is absent from POST.
 *
 *     <input type="hidden" name="nowcrm_cf_present[]" value="interests" />
 *     <input type="text" name="nowcrm_cf[interests]" ... />
 *
 * The marker list names the submitted fields. parse_request() returns only
 * marked fields, so an unmarked field keeps its stored value and a marked field
 * with no checked boxes clears. Use form_name() for the input name.
 *
 * @package NowCRM
 */

namespace NowCRM\Contacts;

use NowCRM\Support\Schema;
use WP_Error;

defined( 'ABSPATH' ) || exit;

final class CustomFieldValues {

	/**
	 * Logical table name in Schema::table().
	 */
	public const TABLE = 'contact_field_values';

	/**
	 * Input name prefix for submitted values.
	 */
	public const FORM_FIELD = 'nowcrm_cf';

	/**
	 * Input name prefix for the submitted-field markers.
	 */
	public const FORM_MARKER = 'nowcrm_cf_present';

	/**
	 * Input name for one field. Multi kinds append [] so PHP keeps the array.
	 */
	public static function form_name( string $field_key, bool $multiple = false ): string {
		return self::FORM_FIELD . '[' . $field_key . ']' . ( $multiple ? '[]' : '' );
	}

	/**
	 * Stored values for one contact, keyed by field key.
	 *
	 * @return array<string, mixed> Cleared and never-set fields are absent.
	 */
	public function for_contact( int $contact_id ): array {
		$rows = $this->rows_for_contacts( array( $contact_id ) );

		return $rows[ $contact_id ] ?? array();
	}

	/**
	 * Stored values for a page of contacts with one query.
	 *
	 * @param int[] $contact_ids Contact IDs.
	 * @return array<int, array<string, mixed>> Every requested ID is present.
	 */
	public function for_contacts( array $contact_ids ): array {
		$ids  = array();
		$rows = array();

		foreach ( $contact_ids as $contact_id ) {
			$contact_id = (int) $contact_id;

			if ( $contact_id > 0 ) {
				$ids[ $contact_id ] = $contact_id;
				$rows[ $contact_id ] = array();
			}
		}

		if ( array() === $ids ) {
			return array();
		}

		$stored = $this->rows_for_contacts( array_values( $ids ) );

		foreach ( $ids as $contact_id ) {
			$rows[ $contact_id ] = $stored[ $contact_id ] ?? array();
		}

		return $rows;
	}

	/**
	 * One value with a case-independent key.
	 *
	 * @param array<string, mixed> $values Values from for_contact().
	 * @param mixed                $default Value when the key is absent.
	 * @return mixed
	 */
	public static function find( array $values, string $field_key, $default = null ) {
		$needle = strtolower( $field_key );

		foreach ( $values as $key => $value ) {
			if ( strtolower( (string) $key ) === $needle ) {
				return $value;
			}
		}

		return $default;
	}

	/**
	 * Save a partial set of values.
	 *
	 * Keys outside the definitions are an error, so a CSV header or a forged
	 * form field cannot write an unknown key. Fields not in the patch keep their
	 * stored value unless $replace_missing is true.
	 *
	 * @param int                  $contact_id      Contact ID.
	 * @param array<string, mixed> $values          Field key => raw value.
	 * @param bool                 $replace_missing Delete stored fields absent from $values.
	 * @return array<string, mixed>|WP_Error Saved values, cleared ones omitted.
	 */
	public function save( int $contact_id, array $values, bool $replace_missing = false ) {
		$prepared = $this->prepare( $contact_id, $values );

		if ( is_wp_error( $prepared ) ) {
			return $prepared;
		}

		return $this->commit( $contact_id, $prepared, $replace_missing );
	}

	/**
	 * Validate a value patch without writing anything.
	 *
	 * Use this before other mutations so an invalid custom value cannot leave a
	 * half-updated contact. Pass the result to commit() afterwards.
	 *
	 * @param int                  $contact_id Contact ID.
	 * @param array<string, mixed> $values     Field key => raw value.
	 * @return array{values: array<string, mixed>, clear: string[]}|WP_Error
	 */
	public function prepare( int $contact_id, array $values ) {
		if ( $contact_id <= 0 ) {
			return new WP_Error( 'nowcrm_not_found', __( '找不到聯絡人。', 'now-crm' ) );
		}

		$prepared = self::validate( $values );

		if ( is_wp_error( $prepared ) ) {
			return $prepared;
		}

		if ( ! ( new ContactRepository() )->get( $contact_id ) ) {
			return new WP_Error( 'nowcrm_not_found', __( '找不到聯絡人。', 'now-crm' ) );
		}

		return $prepared;
	}

	/**
	 * Validate a value patch without a contact or any write.
	 *
	 * Creation uses this before inserting the row so an invalid custom value
	 * cannot leave a partial contact behind.
	 *
	 * @param array<string, mixed> $values Field key => raw value.
	 * @return array{values: array<string, mixed>, clear: string[]}|WP_Error
	 */
	public static function validate( array $values ) {
		$definitions = array();

		foreach ( CustomFieldDefinitions::all() as $field ) {
			$definitions[ strtolower( $field['slug'] ) ] = $field;
		}

		$normalized = array();
		$errors     = array();
		$cleared    = array();

		foreach ( $values as $key => $raw ) {
			$lookup = strtolower( trim( (string) $key ) );

			if ( '' === $lookup || ! isset( $definitions[ $lookup ] ) ) {
				$errors[ (string) $key ] = __( '此自訂欄位不存在。', 'now-crm' );
				continue;
			}

			$field  = $definitions[ $lookup ];
			$result = self::normalize( $field['type'], $raw );

			if ( is_wp_error( $result ) ) {
				$errors[ $field['slug'] ] = $result->get_error_message();
				continue;
			}

			if ( null === $result ) {
				$cleared[] = $field['slug'];
			} else {
				$normalized[ $field['slug'] ] = $result;
			}
		}

		if ( array() !== $errors ) {
			return new WP_Error(
				'nowcrm_invalid_custom_values',
				__( '自訂欄位值不正確，尚未儲存。', 'now-crm' ),
				array( 'errors' => $errors )
			);
		}

		return array(
			'values' => $normalized,
			'clear'  => $cleared,
		);
	}

	/**
	 * Write a prepared patch. Validation must already have run.
	 *
	 * @param int                                   $contact_id      Contact ID.
	 * @param array{values: array<string, mixed>, clear: string[]} $prepared Result of prepare().
	 * @param bool                                  $replace_missing Delete stored fields absent from the patch.
	 * @return array<string, mixed>|WP_Error Saved values, cleared ones omitted.
	 */
	public function commit( int $contact_id, array $prepared, bool $replace_missing = false ) {
		global $wpdb;

		if ( $contact_id <= 0 ) {
			return new WP_Error( 'nowcrm_not_found', __( '找不到聯絡人。', 'now-crm' ) );
		}

		if ( ! isset( $prepared['values'], $prepared['clear'] )
			|| ! is_array( $prepared['values'] )
			|| ! is_array( $prepared['clear'] )
		) {
			return new WP_Error( 'nowcrm_invalid_custom_values', __( '自訂欄位值不正確，尚未儲存。', 'now-crm' ) );
		}

		$normalized = $prepared['values'];
		$cleared    = $prepared['clear'];
		$table      = Schema::table( self::TABLE );
		$existing   = $this->keyed_rows( $contact_id );
		$now        = gmdate( 'Y-m-d H:i:s' );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is trusted.
		foreach ( $cleared as $field_key ) {
			if ( isset( $existing[ strtolower( $field_key ) ] ) ) {
				$wpdb->delete( $table, array( 'contact_id' => $contact_id, 'field_key' => $field_key ), array( '%d', '%s' ) );
			}
		}

		foreach ( $normalized as $field_key => $value ) {
			$json = wp_json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

			if ( ! is_string( $json ) ) {
				return new WP_Error( 'nowcrm_db_error', __( '無法編碼自訂欄位值。', 'now-crm' ) );
			}

			if ( isset( $existing[ strtolower( $field_key ) ] ) ) {
				$saved = $wpdb->update(
					$table,
					array(
						'value'      => $json,
						'updated_at' => $now,
					),
					array(
						'contact_id' => $contact_id,
						'field_key'  => $field_key,
					),
					array( '%s', '%s' ),
					array( '%d', '%s' )
				);
			} else {
				$saved = $wpdb->insert(
					$table,
					array(
						'contact_id' => $contact_id,
						'field_key'  => $field_key,
						'value'      => $json,
						'updated_at' => $now,
					),
					array( '%d', '%s', '%s', '%s' )
				);
			}

			if ( false === $saved ) {
				return new WP_Error( 'nowcrm_db_error', $wpdb->last_error ?: __( '無法寫入自訂欄位值。', 'now-crm' ) );
			}
		}

		if ( $replace_missing ) {
			$keep = array_map( 'strtolower', array_keys( $normalized ) );

			foreach ( $existing as $lookup => $row ) {
				if ( ! in_array( $lookup, $keep, true ) ) {
					$wpdb->delete( $table, array( 'contact_id' => $contact_id, 'field_key' => $row['field_key'] ), array( '%d', '%s' ) );
				}
			}
		}
		// phpcs:enable

		return $normalized;
	}

	/**
	 * Delete all values of a contact. Call this from contact deletion.
	 */
	public function delete_for_contact( int $contact_id ): void {
		global $wpdb;

		$wpdb->delete( Schema::table( self::TABLE ), array( 'contact_id' => $contact_id ), array( '%d' ) );
	}

	/**
	 * Values for CSV export. Lists join with ", " like the import side splits.
	 *
	 * @return array<string, string>
	 */
	public function for_csv( int $contact_id ): array {
		$csv = array();

		foreach ( $this->for_contact( $contact_id ) as $key => $value ) {
			$csv[ $key ] = self::to_csv( $value );
		}

		return $csv;
	}

	/**
	 * One value as CSV text.
	 *
	 * @param mixed $value Stored value.
	 */
	public static function to_csv( $value ): string {
		if ( is_array( $value ) ) {
			return implode( ', ', array_map( 'strval', $value ) );
		}

		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * Read submitted values from a regular WordPress form POST.
	 *
	 * Only fields named by a marker are returned. A marked multi-value field
	 * with no checked boxes returns '', which the save path treats as a clear.
	 *
	 * @param array<string, mixed> $post Unslashed or slashed POST data.
	 * @return array<string, mixed> Field key => raw value.
	 */
	public static function parse_request( array $post ): array {
		$markers = $post[ self::FORM_MARKER ] ?? array();
		$fields  = $post[ self::FORM_FIELD ] ?? array();

		if ( ! is_array( $markers ) ) {
			return array();
		}

		$markers = wp_unslash( $markers );
		$fields  = is_array( $fields ) ? wp_unslash( $fields ) : array();
		$values  = array();

		foreach ( $markers as $marker ) {
			if ( ! is_scalar( $marker ) ) {
				continue;
			}

			$field_key = trim( (string) $marker );

			if ( '' === $field_key ) {
				continue;
			}

			$values[ $field_key ] = $fields[ $field_key ] ?? '';
		}

		return $values;
	}

	/**
	 * Normalize one raw value for its field kind.
	 *
	 * @param string $type Stored field type.
	 * @param mixed  $raw  Raw submitted value.
	 * @return mixed|WP_Error|null Null means clear the stored row.
	 */
	private static function normalize( string $type, $raw ) {
		if ( ! CustomFieldDefinitions::supports( $type ) ) {
			return new WP_Error( 'nowcrm_invalid_field_type', __( '欄位類型不支援。', 'now-crm' ) );
		}

		if ( CustomFieldDefinitions::is_multi( $type ) ) {
			return self::normalize_list( $raw );
		}

		if ( null === $raw || '' === $raw ) {
			return null;
		}

		if ( is_array( $raw ) || ! is_scalar( $raw ) ) {
			return new WP_Error( 'nowcrm_invalid_value', __( '此欄位需要單一值。', 'now-crm' ) );
		}

		$value = trim( (string) $raw );

		if ( '' === $value ) {
			return null;
		}

		switch ( $type ) {
			case 'number':
				return self::normalize_number( $value );

			case 'date':
				return self::normalize_date( $value );

			case 'date_time':
				return self::normalize_datetime( $value );

			case 'textarea':
				return wp_kses_post( $value );

			default:
				return sanitize_text_field( $value );
		}
	}

	/**
	 * Normalize a list value. A scalar comma string is split, matching the
	 * reference CSV mapping (`formatCustomFieldValues`).
	 *
	 * @param mixed $raw Raw value.
	 * @return string[]|WP_Error|null
	 */
	private static function normalize_list( $raw ) {
		if ( null === $raw || '' === $raw ) {
			return null;
		}

		if ( is_scalar( $raw ) ) {
			$raw = explode( ',', (string) $raw );
		}

		if ( ! is_array( $raw ) ) {
			return new WP_Error( 'nowcrm_invalid_value', __( '此欄位需要一組值。', 'now-crm' ) );
		}

		$items = array();

		foreach ( $raw as $item ) {
			if ( null !== $item && ! is_scalar( $item ) ) {
				return new WP_Error( 'nowcrm_invalid_value', __( '此欄位需要一組值。', 'now-crm' ) );
			}

			$item = trim( sanitize_text_field( (string) $item ) );

			if ( '' !== $item ) {
				$items[] = $item;
			}
		}

		if ( array() === $items ) {
			return null;
		}

		return array_values( array_unique( $items ) );
	}

	/**
	 * Strict number with optional thousands separators.
	 *
	 * @return int|float|WP_Error
	 */
	private static function normalize_number( string $value ) {
		if ( preg_match( '/^-?\d{1,3}(,\d{3})+(\.\d+)?$/', $value ) ) {
			$value = str_replace( ',', '', $value );
		}

		if ( ! is_numeric( $value ) ) {
			return new WP_Error( 'nowcrm_invalid_number', __( '請輸入有效的數字。', 'now-crm' ) );
		}

		return $value + 0;
	}

	/**
	 * Date in Y-m-d.
	 *
	 * @return string|WP_Error
	 */
	private static function normalize_date( string $value ) {
		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $value, $match ) ) {
			return new WP_Error( 'nowcrm_invalid_date', __( '請輸入有效的日期。', 'now-crm' ) );
		}

		if ( ! checkdate( (int) $match[2], (int) $match[3], (int) $match[1] ) ) {
			return new WP_Error( 'nowcrm_invalid_date', __( '請輸入有效的日期。', 'now-crm' ) );
		}

		return $value;
	}

	/**
	 * Local date and time in Y-m-d H:i:s. Accepts the datetime-local separator.
	 *
	 * @return string|WP_Error
	 */
	private static function normalize_datetime( string $value ) {
		$value = str_replace( 'T', ' ', $value );

		if ( preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $value ) ) {
			$value .= ':00';
		}

		$parsed = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $value, new \DateTimeZone( 'UTC' ) );

		if ( ! $parsed || $parsed->format( 'Y-m-d H:i:s' ) !== $value ) {
			return new WP_Error( 'nowcrm_invalid_datetime', __( '請輸入有效的日期與時間。', 'now-crm' ) );
		}

		return $value;
	}

	/**
	 * Stored values for a set of contacts, one query.
	 *
	 * @param int[] $contact_ids Contact IDs.
	 * @return array<int, array<string, mixed>>
	 */
	private function rows_for_contacts( array $contact_ids ): array {
		global $wpdb;

		$ids = array();

		foreach ( $contact_ids as $contact_id ) {
			$contact_id = (int) $contact_id;

			if ( $contact_id > 0 ) {
				$ids[ $contact_id ] = $contact_id;
			}
		}

		if ( array() === $ids ) {
			return array();
		}

		$ids          = array_values( $ids );
		$table        = Schema::table( self::TABLE );
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders -- table name is trusted, placeholders are generated above.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT contact_id, field_key, value FROM {$table} WHERE contact_id IN ({$placeholders})",
				$ids
			),
			ARRAY_A
		);
		// phpcs:enable

		$values = array();

		foreach ( (array) $rows as $row ) {
			$decoded = json_decode( (string) $row['value'], true );
			$value   = JSON_ERROR_NONE === json_last_error() ? $decoded : (string) $row['value'];

			if ( null === $value ) {
				continue;
			}

			$values[ (int) $row['contact_id'] ][ (string) $row['field_key'] ] = $value;
		}

		return $values;
	}

	/**
	 * Existing rows of one contact keyed by lowercase field key.
	 *
	 * @return array<string, array{field_key: string, value: string}>
	 */
	private function keyed_rows( int $contact_id ): array {
		global $wpdb;

		$table = Schema::table( self::TABLE );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is trusted.
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT field_key, value FROM {$table} WHERE contact_id = %d", $contact_id ),
			ARRAY_A
		);

		$keyed = array();

		foreach ( (array) $rows as $row ) {
			$keyed[ strtolower( (string) $row['field_key'] ) ] = array(
				'field_key' => (string) $row['field_key'],
				'value'     => (string) $row['value'],
			);
		}

		return $keyed;
	}
}
