<?php
/**
 * Advanced contact filters: typed condition contract, validation, and SQL.
 *
 * A filter is a list of groups. Conditions inside one group are AND-ed. Groups
 * are OR-ed. Every condition names one supported field, one operator for that
 * field, and a value.
 *
 * The parser returns a stable, typed plan:
 *
 *     array(
 *         'version' => 1,
 *         'groups'  => array(
 *             array(
 *                 array(
 *                     'provider' => 'contact',
 *                     'field'    => 'city',
 *                     'operator' => 'contains',
 *                     'value'    => 'Taipei',
 *                 ),
 *             ),
 *         ),
 *     )
 *
 * `compile()` turns a plan into a `(sql, params)` pair. The SQL uses the
 * contact alias `c`, so the repository can place it inside its own WHERE
 * clause. Pass the params to `$wpdb->prepare()`.
 *
 * Safety rules:
 * - A condition the compiler does not recognize removes its whole group, so an
 *   unknown condition can never widen a bulk, export, or delete run.
 * - The parser rejects an unknown field, an operator the field does not offer,
 *   or an invalid value before any query runs.
 * - Column names come from this class only. Values use placeholders.
 * - Term IDs must exist and have the expected list or tag type.
 *
 * Custom-field values are stored as JSON text by CustomFieldValues. Membership
 * and equality compare the JSON encoding of a string, so they match a scalar
 * and one element of a list. Typed comparisons (number, date) unquote the JSON
 * and compare as numbers or date text. `JSON_VALID` guards both, so a row that
 * is not valid JSON never matches or joins a negation. This is the actual
 * behaviour on the MariaDB and MySQL versions that WordPress supports.
 *
 * @package NowCRM
 */

namespace NowCRM\Contacts;

use DateTimeImmutable;
use NowCRM\Support\Schema;
use NowCRM\Terms\TermRepository;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	return;
}

final class AdvancedFilters {

	/**
	 * Plan format version. Bump this when the normalized shape changes.
	 */
	public const VERSION = 1;

	/**
	 * Most OR groups in one filter.
	 */
	public const MAX_GROUPS = 5;

	/**
	 * Most conditions in one group.
	 */
	public const MAX_CONDITIONS = 10;

	/**
	 * Most values in one set condition.
	 */
	public const MAX_VALUES = 100;

	/**
	 * Largest accepted day count for the day-based date operators.
	 */
	public const MAX_DAYS = 36500;

	public const PROVIDER_CONTACT = 'contact';
	public const PROVIDER_SEGMENT = 'segment';
	public const PROVIDER_CUSTOM  = 'custom_fields';

	/**
	 * Operand text pattern for a custom-field number.
	 */
	private const NUMBER_PATTERN = '^[+-]?([0-9]+([.][0-9]*)?|[.][0-9]+)$';

	/**
	 * Operand text pattern for a custom-field date or date-time.
	 */
	private const DATE_PATTERN = '^[0-9]{4}-[0-9]{2}-[0-9]{2}';

	/**
	 * Operators that carry no value.
	 *
	 * @var string[]
	 */
	private const VALUELESS = array( 'is_null', 'not_null' );

	/**
	 * Human labels for the operators this class compiles.
	 *
	 * @return array<string, string>
	 */
	public static function operator_labels(): array {
		return array(
			'='            => __( '等於', 'now-crm' ),
			'!='           => __( '不等於', 'now-crm' ),
			'contains'     => __( '包含', 'now-crm' ),
			'not_contains' => __( '不包含', 'now-crm' ),
			'>'            => __( '大於', 'now-crm' ),
			'<'            => __( '小於', 'now-crm' ),
			'before'       => __( '早於日期', 'now-crm' ),
			'after'        => __( '晚於日期', 'now-crm' ),
			'date_equal'   => __( '在當天', 'now-crm' ),
			'days_before'  => __( '早於（天數）', 'now-crm' ),
			'days_within'  => __( '最近（天數）內', 'now-crm' ),
			'in'           => __( '是其中之一', 'now-crm' ),
			'not_in'       => __( '不是其中之一', 'now-crm' ),
			'in_all'       => __( '包含全部', 'now-crm' ),
			'not_in_all'   => __( '未包含全部', 'now-crm' ),
			'is_null'      => __( '空白', 'now-crm' ),
			'not_null'     => __( '非空白', 'now-crm' ),
		);
	}

	/**
	 * Provider labels for grouped controls.
	 *
	 * @return array<string, string>
	 */
	public static function provider_labels(): array {
		return array(
			self::PROVIDER_CONTACT => __( '聯絡人欄位', 'now-crm' ),
			self::PROVIDER_SEGMENT => __( '狀態、名單與標籤', 'now-crm' ),
			self::PROVIDER_CUSTOM  => __( '自訂欄位', 'now-crm' ),
		);
	}

	/**
	 * Whether an operator takes no value.
	 */
	public static function is_valueless( string $operator ): bool {
		return in_array( $operator, self::VALUELESS, true );
	}

	/**
	 * Parse a plan from a hidden JSON field.
	 *
	 * An empty string means "no advanced filter". Invalid JSON is an error, so
	 * a caller never runs a wider query than the user asked for.
	 *
	 * @param mixed $json JSON text, or an array that is already a plan.
	 * @return array{version: int, groups: array<int, array<int, array<string, mixed>>>}|WP_Error|null
	 */
	public static function from_json( $json ) {
		if ( is_array( $json ) ) {
			return self::normalize( $json );
		}

		if ( ! is_string( $json ) ) {
			return new WP_Error(
				'nowcrm_advanced_filter_invalid',
				__( '進階篩選資料格式不正確。', 'now-crm' )
			);
		}

		$json = trim( $json );

		if ( '' === $json ) {
			return null;
		}

		$decoded = json_decode( $json, true );

		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $decoded ) ) {
			return new WP_Error(
				'nowcrm_advanced_filter_invalid',
				__( '進階篩選資料格式不正確。', 'now-crm' )
			);
		}

		return self::normalize( $decoded );
	}

	/**
	 * Groups of a plan as request rows, for links and hidden fields.
	 *
	 * @param array<string, mixed>|null $plan Validated plan.
	 * @return array<int, array<int, array<string, mixed>>>
	 */
	public static function to_query( ?array $plan ): array {
		$plan   = (array) $plan;
		$groups = array();

		foreach ( (array) ( $plan['groups'] ?? array() ) as $group ) {
			$rows = array();

			foreach ( (array) $group as $condition ) {
				$rows[] = array(
					'provider' => (string) $condition['provider'],
					'field'    => (string) $condition['field'],
					'operator' => (string) $condition['operator'],
					'value'    => $condition['value'],
				);
			}

			if ( array() !== $rows ) {
				$groups[] = $rows;
			}
		}

		return $groups;
	}

	/**
	 * JSON text for a plan, for a hidden transport field.
	 *
	 * @param array<string, mixed>|null $plan Validated plan.
	 */
	public static function to_json( ?array $plan ): string {
		$json = wp_json_encode(
			array(
				'version' => self::VERSION,
				'groups'  => self::to_query( $plan ),
			)
		);

		return is_string( $json ) ? $json : '';
	}

	/**
	 * Every supported field, keyed by `provider.field`.
	 *
	 * A definition has:
	 * - provider, field: canonical condition names.
	 * - label: text for a control.
	 * - type: text, number, date, select, terms, custom_text, custom_number,
	 *   custom_select, custom_multi, custom_date.
	 * - ops: the operators this field accepts.
	 * - column: contact column, for contact and segment fields.
	 * - column_kind: date or datetime, for date columns.
	 * - term_type: tag or list, for term fields.
	 * - options: value => label, for select fields.
	 * - number_kind: integer or decimal, for number fields.
	 * - value_check: validator name, for enumerated values.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function fields(): array {
		$text   = array( '=', '!=', 'contains', 'not_contains' );
		$number = array( '>', '<', '=', '!=' );
		$date   = array( 'before', 'after', 'date_equal', 'days_before', 'days_within' );
		$choice = array( 'in', 'not_in', '=', '!=', 'is_null', 'not_null' );
		$terms  = array( 'in', 'not_in', 'in_all', 'not_in_all', 'is_null', 'not_null' );

		$fields = array();

		$text_columns = array(
			'first_name'     => __( '名字', 'now-crm' ),
			'last_name'      => __( '姓氏', 'now-crm' ),
			'email'          => __( 'Email', 'now-crm' ),
			'phone'          => __( '電話', 'now-crm' ),
			'address_line_1' => __( '地址第 1 行', 'now-crm' ),
			'address_line_2' => __( '地址第 2 行', 'now-crm' ),
			'city'           => __( '城市', 'now-crm' ),
			'state'          => __( '州／省／地區', 'now-crm' ),
			'postal_code'    => __( '郵遞區號', 'now-crm' ),
			'source'         => __( '來源', 'now-crm' ),
		);

		foreach ( $text_columns as $column => $label ) {
			$fields[ self::PROVIDER_CONTACT . '.' . $column ] = array(
				'provider' => self::PROVIDER_CONTACT,
				'field'    => $column,
				'label'    => $label,
				'type'     => 'text',
				'ops'      => $text,
				'column'   => $column,
			);
		}

		$select_fields = array(
			'country' => array(
				'label'       => __( '國家', 'now-crm' ),
				'options'     => ProfileFields::countries(),
				'ops'         => array( 'in', 'not_in', 'is_null', 'not_null' ),
				'value_check' => 'country',
			),
			'prefix'  => array(
				'label'       => __( '稱謂', 'now-crm' ),
				'options'     => self::label_list( ProfileFields::prefixes() ),
				'ops'         => array( 'in' ),
				'value_check' => 'prefix',
			),
			'timezone' => array(
				'label'       => __( '時區', 'now-crm' ),
				'options'     => self::label_list( ProfileFields::timezones() ),
				'ops'         => array( 'in', 'not_in' ),
				'value_check' => 'timezone',
			),
		);

		foreach ( $select_fields as $column => $definition ) {
			$fields[ self::PROVIDER_CONTACT . '.' . $column ] = array_merge(
				array(
					'provider' => self::PROVIDER_CONTACT,
					'field'    => $column,
					'type'     => 'select',
					'column'   => $column,
				),
				$definition
			);
		}

		$fields[ self::PROVIDER_CONTACT . '.wp_user_id' ] = array(
			'provider'    => self::PROVIDER_CONTACT,
			'field'       => 'wp_user_id',
			'label'       => __( 'WP 使用者 ID', 'now-crm' ),
			'type'        => 'number',
			'ops'         => $number,
			'column'      => 'wp_user_id',
			'number_kind' => 'integer',
		);

		$fields[ self::PROVIDER_CONTACT . '.date_of_birth' ] = array(
			'provider'    => self::PROVIDER_CONTACT,
			'field'       => 'date_of_birth',
			'label'       => __( '出生日期', 'now-crm' ),
			'type'        => 'date',
			'ops'         => $date,
			'column'      => 'date_of_birth',
			'column_kind' => 'date',
		);

		$fields[ self::PROVIDER_CONTACT . '.created_at' ] = array(
			'provider'    => self::PROVIDER_CONTACT,
			'field'       => 'created_at',
			'label'       => __( '建立時間', 'now-crm' ),
			'type'        => 'date',
			'ops'         => $date,
			'column'      => 'created_at',
			'column_kind' => 'datetime',
		);

		$fields[ self::PROVIDER_SEGMENT . '.status' ] = array(
			'provider'    => self::PROVIDER_SEGMENT,
			'field'       => 'status',
			'label'       => __( '狀態', 'now-crm' ),
			'type'        => 'select',
			'ops'         => array( 'in', 'not_in' ),
			'column'      => 'status',
			'options'     => self::status_options(),
			'value_check' => 'status',
		);

		$fields[ self::PROVIDER_SEGMENT . '.contact_type' ] = array(
			'provider'    => self::PROVIDER_SEGMENT,
			'field'       => 'contact_type',
			'label'       => __( '聯絡人類型', 'now-crm' ),
			'type'        => 'select',
			'ops'         => array( 'in', 'not_in' ),
			'column'      => 'contact_type',
			'options'     => ProfileFields::contact_types(),
			'value_check' => 'contact_type',
		);

		$fields[ self::PROVIDER_SEGMENT . '.tags' ] = array(
			'provider'  => self::PROVIDER_SEGMENT,
			'field'     => 'tags',
			'label'     => __( '標籤', 'now-crm' ),
			'type'      => 'terms',
			'ops'       => $terms,
			'term_type' => TermRepository::TYPE_TAG,
		);

		$fields[ self::PROVIDER_SEGMENT . '.lists' ] = array(
			'provider'  => self::PROVIDER_SEGMENT,
			'field'     => 'lists',
			'label'     => __( '名單', 'now-crm' ),
			'type'      => 'terms',
			'ops'       => $terms,
			'term_type' => TermRepository::TYPE_LIST,
		);

		foreach ( self::custom_fields() as $key => $definition ) {
			$fields[ $key ] = $definition;
		}

		return $fields;
	}

	/**
	 * Definitions for the custom fields that exist now.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function custom_fields(): array {
		$sets = array(
			'text'          => array( 'custom_text', array( '=', '!=', 'contains', 'not_contains', 'is_null', 'not_null' ) ),
			'textarea'      => array( 'custom_text', array( '=', '!=', 'contains', 'not_contains', 'is_null', 'not_null' ) ),
			'number'        => array( 'custom_number', array( '>', '<', '=', '!=', 'is_null', 'not_null' ) ),
			'select-one'    => array( 'custom_select', array( 'in', 'not_in', '=', '!=', 'is_null', 'not_null' ) ),
			'radio'         => array( 'custom_select', array( 'in', 'not_in', '=', '!=', 'is_null', 'not_null' ) ),
			'select-multi'  => array( 'custom_multi', array( 'in', 'not_in', '=', '!=', 'is_null', 'not_null' ) ),
			'checkbox'      => array( 'custom_multi', array( 'in', 'not_in', '=', '!=', 'is_null', 'not_null' ) ),
			'date'          => array( 'custom_date', array( 'before', 'after', 'date_equal', 'days_before', 'days_within', 'is_null', 'not_null' ) ),
			'date_time'     => array( 'custom_date', array( 'before', 'after', 'date_equal', 'days_before', 'days_within', 'is_null', 'not_null' ) ),
		);

		$fields = array();

		foreach ( CustomFieldDefinitions::all() as $field ) {
			$type = (string) $field['type'];
			$kind = $sets[ $type ] ?? null;

			if ( null === $kind ) {
				// A definition with an unknown type is not filterable. Do not
				// invent an operator set for it.
				continue;
			}

			$slug = strtolower( (string) $field['slug'] );

			$fields[ self::PROVIDER_CUSTOM . '.' . $slug ] = array(
				'provider'     => self::PROVIDER_CUSTOM,
				'field'        => $slug,
				'label'        => (string) $field['label'],
				'type'         => $kind[0],
				'ops'          => $kind[1],
				'options'      => self::label_list( (array) $field['options'] ),
				'value_check'  => 'custom_option',
				'field_type'   => $type,
				'number_kind'  => 'decimal',
			);
		}

		return $fields;
	}

	/**
	 * Value => label map from a list of strings.
	 *
	 * @param string[] $values Values.
	 * @return array<string, string>
	 */
	private static function label_list( array $values ): array {
		$options = array();

		foreach ( $values as $value ) {
			if ( is_scalar( $value ) ) {
				$value            = (string) $value;
				$options[ $value ] = $value;
			}
		}

		return $options;
	}

	/**
	 * Status options for a control.
	 *
	 * @return array<string, string>
	 */
	private static function status_options(): array {
		$options = array();

		foreach ( Status::all() as $status ) {
			$options[ $status ] = Status::label( $status );
		}

		return $options;
	}

	// ---------------------------------------------------------------------
	// Parsing and validation
	// ---------------------------------------------------------------------

	/**
	 * Validate raw groups and return the typed plan.
	 *
	 * Accepts a list of groups, or a plan from this method.
	 *
	 * @param mixed $raw Groups of conditions.
	 * @param array<string, array<string, mixed>> $extra_fields Extra field definitions keyed by `provider.field`. Lets campaign recipients add email, order, automation and sequence fields without exposing them to Contacts or Segments.
	 * @return array{version: int, groups: array<int, array<int, array<string, mixed>>>}|WP_Error
	 */
	public static function normalize( $raw, array $extra_fields = array() ) {
		$groups = self::unwrap( $raw );

		if ( is_wp_error( $groups ) ) {
			return $groups;
		}

		if ( array() === $groups ) {
			return array(
				'version' => self::VERSION,
				'groups'  => array(),
			);
		}

		if ( count( $groups ) > self::MAX_GROUPS ) {
			return self::error(
				sprintf(
					/* translators: %d: maximum number of filter groups. */
					__( '最多只能有 %d 個篩選群組。', 'now-crm' ),
					self::MAX_GROUPS
				)
			);
		}

		$fields  = self::with_extra( self::fields(), $extra_fields );
		$plan    = array();
		$groupNo = 0;

		foreach ( $groups as $group ) {
			$groupNo++;

			if ( ! is_array( $group ) ) {
				return self::error(
					sprintf(
						/* translators: %d: group number. */
						__( '第 %d 個群組的格式不正確。', 'now-crm' ),
						$groupNo
					)
				);
			}

			$group = array_values( $group );

			if ( array() === $group ) {
				return self::error(
					sprintf(
						/* translators: %d: group number. */
						__( '第 %d 個群組沒有條件。請移除空群組，或加入條件。', 'now-crm' ),
						$groupNo
					)
				);
			}

			if ( count( $group ) > self::MAX_CONDITIONS ) {
				return self::error(
					sprintf(
						/* translators: 1: group number, 2: maximum conditions. */
						__( '第 %1$d 個群組最多只能有 %2$d 個條件。', 'now-crm' ),
						$groupNo,
						self::MAX_CONDITIONS
					)
				);
			}

			$conditions = array();
			$rowNo      = 0;

			foreach ( $group as $condition ) {
				$rowNo++;
				$normalized = self::normalize_condition( $condition, $fields, $groupNo, $rowNo );

				if ( is_wp_error( $normalized ) ) {
					return $normalized;
				}

				$conditions[] = $normalized;
			}

			$plan[] = $conditions;
		}

		return array(
			'version' => self::VERSION,
			'groups'  => $plan,
		);
	}

	/**
	 * Field catalog with appended definitions for one call only.
	 *
	 * The extra map is never stored, so Contacts and Segments keep their own
	 * fields unless the caller passes the same map again.
	 *
	 * @param array<string, array<string, mixed>> $fields Field catalog.
	 * @param array<string, array<string, mixed>> $extra  Extra definitions keyed by `provider.field`.
	 * @return array<string, array<string, mixed>>
	 */
	private static function with_extra( array $fields, array $extra ): array {
		foreach ( $extra as $key => $definition ) {
			if ( ! is_string( $key ) || '' === $key || ! is_array( $definition ) ) {
				continue;
			}

			if ( ! isset( $definition['provider'], $definition['field'], $definition['ops'] ) ) {
				continue;
			}

			$fields[ $key ] = $definition;

			$lower = strtolower( $key );

			if ( $lower !== $key && ! isset( $fields[ $lower ] ) ) {
				$fields[ $lower ] = $definition;
			}
		}

		return $fields;
	}

	/**
	 * Groups list from a plan or a bare group list.
	 *
	 * @param mixed $raw Raw input.
	 * @return array<int, mixed>|WP_Error
	 */
	private static function unwrap( $raw ) {
		if ( ! is_array( $raw ) ) {
			return self::error( __( '進階篩選的格式不正確。', 'now-crm' ) );
		}

		if ( isset( $raw['groups'] ) ) {
			if ( ! is_array( $raw['groups'] ) ) {
				return self::error( __( '進階篩選的群組格式不正確。', 'now-crm' ) );
			}

			return array_values( $raw['groups'] );
		}

		return array_values( $raw );
	}

	/**
	 * Validate one condition.
	 *
	 * @param mixed                          $condition Raw condition.
	 * @param array<string, array<string, mixed>> $fields Field catalog.
	 * @param int                            $groupNo   Group number, for the message.
	 * @param int                            $rowNo     Row number, for the message.
	 * @return array<string, mixed>|WP_Error
	 */
	private static function normalize_condition( $condition, array $fields, int $groupNo, int $rowNo ) {
		if ( ! is_array( $condition ) ) {
			return self::row_error( 'condition_format', '條件格式不正確。', $groupNo, $rowNo );
		}

		list( $provider, $field ) = self::condition_names( $condition );

		if ( '' === $provider || '' === $field ) {
			return self::row_error( 'condition_field', '請選擇篩選欄位。', $groupNo, $rowNo );
		}

		$key        = $provider . '.' . $field;
		$definition = $fields[ $key ] ?? ( $fields[ strtolower( $key ) ] ?? null );

		if ( null === $definition ) {
			return self::row_error(
				'unknown_field',
				sprintf(
					/* translators: %s: field name from the request. */
					__( '不支援的篩選欄位：%s。', 'now-crm' ),
					$key
				),
				$groupNo,
				$rowNo
			);
		}

		$operator = isset( $condition['operator'] ) && is_scalar( $condition['operator'] )
			? (string) $condition['operator']
			: '';

		if ( '' === $operator || ! in_array( $operator, $definition['ops'], true ) ) {
			return self::row_error(
				'unknown_operator',
				sprintf(
					/* translators: 1: field label, 2: operator from the request. */
					__( '「%1$s」不支援運算子「%2$s」。', 'now-crm' ),
					(string) $definition['label'],
					'' === $operator ? '—' : $operator
				),
				$groupNo,
				$rowNo
			);
		}

		// An appended definition validates its own value. The group and row
		// numbers keep the error in the same shape as the built-in fields.
		if ( isset( $definition['validate'] ) && is_callable( $definition['validate'] ) ) {
			$value = call_user_func( $definition['validate'], $condition['value'] ?? null, $operator, $groupNo, $rowNo );

			if ( is_wp_error( $value ) ) {
				return $value;
			}

			return array(
				'provider' => (string) $definition['provider'],
				'field'    => (string) $definition['field'],
				'operator' => $operator,
				'value'    => $value,
			);
		}

		$value = self::normalize_value( $definition, $operator, $condition['value'] ?? null, $groupNo, $rowNo );

		if ( is_wp_error( $value ) ) {
			return $value;
		}

		return array(
			'provider' => (string) $definition['provider'],
			'field'    => (string) $definition['field'],
			'operator' => $operator,
			'value'    => $value,
		);
	}

	/**
	 * Provider and field names from a condition.
	 *
	 * Accepts explicit provider/field, `property` ("provider.field"), or the
	 * reference-style `source` pair.
	 *
	 * @param array<string, mixed> $condition Raw condition.
	 * @return array{0: string, 1: string}
	 */
	private static function condition_names( array $condition ): array {
		$provider = isset( $condition['provider'] ) && is_scalar( $condition['provider'] )
			? strtolower( trim( (string) $condition['provider'] ) )
			: '';
		$field    = isset( $condition['field'] ) && is_scalar( $condition['field'] )
			? trim( (string) $condition['field'] )
			: '';

		if ( '' === $provider || '' === $field ) {
			$property = isset( $condition['property'] ) && is_scalar( $condition['property'] )
				? trim( (string) $condition['property'] )
				: '';

			if ( '' !== $property && str_contains( $property, '.' ) ) {
				list( $provider, $field ) = explode( '.', $property, 2 );
				$provider                = strtolower( trim( $provider ) );
				$field                   = trim( $field );
			}
		}

		if ( '' === $provider || '' === $field ) {
			$source = $condition['source'] ?? null;

			if ( is_array( $source ) && 2 === count( $source ) ) {
				$source   = array_values( $source );
				$provider = is_scalar( $source[0] ) ? strtolower( trim( (string) $source[0] ) ) : '';
				$field    = is_scalar( $source[1] ) ? trim( (string) $source[1] ) : '';
			}
		}

		return array( $provider, $field );
	}

	/**
	 * Validate and normalize a condition value.
	 *
	 * @param array<string, mixed> $definition Field definition.
	 * @param string               $operator   Operator.
	 * @param mixed                $value      Raw value.
	 * @param int                  $groupNo    Group number.
	 * @param int                  $rowNo      Row number.
	 * @return mixed|WP_Error
	 */
	private static function normalize_value( array $definition, string $operator, $value, int $groupNo, int $rowNo ) {
		if ( self::is_valueless( $operator ) ) {
			return null;
		}

		$type = (string) $definition['type'];

		switch ( $type ) {
			case 'text':
			case 'custom_text':
				if ( ! is_scalar( $value ) ) {
					return self::row_error( 'value_format', '此欄位需要單一文字值。', $groupNo, $rowNo );
				}

				$value = trim( (string) $value );

				if ( '' === $value ) {
					return self::row_error(
						'empty_value',
						__( '請輸入值。空白欄位請使用「空白」或「非空白」運算子。', 'now-crm' ),
						$groupNo,
						$rowNo
					);
				}

				if ( ! preg_match( '//u', $value ) ) {
					return self::row_error( 'value_encoding', '值含有無效的字元編碼。', $groupNo, $rowNo );
				}

				return $value;

			case 'number':
			case 'custom_number':
				if ( ! is_scalar( $value ) ) {
					return self::row_error( 'value_format', '此欄位需要單一數字。', $groupNo, $rowNo );
				}

				$value = trim( (string) $value );

				if ( 'integer' === ( $definition['number_kind'] ?? '' ) ) {
					if ( ! preg_match( '/^\d{1,15}$/', $value ) ) {
						return self::row_error( 'value_number', '請輸入正整數。', $groupNo, $rowNo );
					}

					return (int) $value;
				}

				if ( ! preg_match( '/^-?\d{1,14}(\.\d{1,6})?$/', $value ) ) {
					return self::row_error( 'value_number', '請輸入有效數字（最多 14 位整數、6 位小數）。', $groupNo, $rowNo );
				}

				return $value;

			case 'date':
			case 'custom_date':
				if ( ! is_scalar( $value ) ) {
					return self::row_error( 'value_format', '此欄位需要單一日期或天數。', $groupNo, $rowNo );
				}

				$value = trim( (string) $value );

				if ( in_array( $operator, array( 'days_before', 'days_within' ), true ) ) {
					if ( ! preg_match( '/^\d{1,5}$/', $value ) ) {
						return self::row_error( 'value_days', '請輸入 0 到 36500 的天數。', $groupNo, $rowNo );
					}

					$days = (int) $value;

					if ( $days > self::MAX_DAYS ) {
						return self::row_error( 'value_days', '請輸入 0 到 36500 的天數。', $groupNo, $rowNo );
					}

					return $days;
				}

				if ( ! self::is_date( $value ) ) {
					return self::row_error( 'value_date', '請輸入有效日期（YYYY-MM-DD）。', $groupNo, $rowNo );
				}

				return $value;

			case 'select':
			case 'terms':
			case 'custom_select':
			case 'custom_multi':
				if ( in_array( $operator, array( 'in', 'not_in', 'in_all', 'not_in_all' ), true ) ) {
					return self::normalize_set( $definition, $value, $groupNo, $rowNo );
				}

				$single = self::normalize_set( $definition, $value, $groupNo, $rowNo );

				if ( is_wp_error( $single ) ) {
					return $single;
				}

				if ( 1 !== count( $single ) ) {
					return self::row_error( 'value_single', '此運算子需要單一值。', $groupNo, $rowNo );
				}

				return $single[0];
		}

		return self::row_error( 'value_format', '此欄位不支援篩選值。', $groupNo, $rowNo );
	}

	/**
	 * Validate a set of values for one field.
	 *
	 * @param array<string, mixed> $definition Field definition.
	 * @param mixed                $value      Raw value.
	 * @param int                  $groupNo    Group number.
	 * @param int                  $rowNo      Row number.
	 * @return string[]|int[]|WP_Error
	 */
	private static function normalize_set( array $definition, $value, int $groupNo, int $rowNo ) {
		$items = is_array( $value ) ? $value : array( $value );
		$clean = array();

		foreach ( $items as $item ) {
			if ( ! is_scalar( $item ) ) {
				return self::row_error( 'value_format', '值必須是文字。', $groupNo, $rowNo );
			}

			$item = trim( (string) $item );

			if ( '' === $item ) {
				continue;
			}

			if ( ! preg_match( '//u', $item ) ) {
				return self::row_error( 'value_encoding', '值含有無效的字元編碼。', $groupNo, $rowNo );
			}

			if ( ! in_array( $item, array_map( 'strval', $clean ), true ) ) {
				$clean[] = $item;
			}
		}

		if ( array() === $clean ) {
			return self::row_error( 'empty_value', '請至少選擇一個值。', $groupNo, $rowNo );
		}

		if ( count( $clean ) > self::MAX_VALUES ) {
			return self::row_error(
				'value_limit',
				sprintf(
					/* translators: %d: maximum number of values. */
					__( '一個條件最多只能有 %d 個值。', 'now-crm' ),
					self::MAX_VALUES
				),
				$groupNo,
				$rowNo
			);
		}

		$checked = array();

		foreach ( $clean as $item ) {
			$result = self::check_value( $definition, (string) $item );

			if ( is_wp_error( $result ) ) {
				return self::row_error(
					'value_unknown',
					sprintf(
						/* translators: 1: field label, 2: value from the request. */
						__( '「%1$s」沒有這個值：%2$s。', 'now-crm' ),
						(string) $definition['label'],
						(string) $item
					),
					$groupNo,
					$rowNo
				);
			}

			$checked[] = $result;
		}

		return $checked;
	}

	/**
	 * Validate one enumerated value against its field.
	 *
	 * @param array<string, mixed> $definition Field definition.
	 * @param string               $item       Raw value.
	 * @return int|string|WP_Error Normalized value.
	 */
	private static function check_value( array $definition, string $item ) {
		$check = (string) ( $definition['value_check'] ?? '' );

		switch ( $check ) {
			case 'status':
				return Status::is_valid( $item ) ? $item : new WP_Error( 'nowcrm_invalid_status' );

			case 'contact_type':
				return ProfileFields::is_valid_contact_type( $item ) ? $item : new WP_Error( 'nowcrm_invalid_contact_type' );

			case 'prefix':
				return ProfileFields::is_valid_prefix( $item ) ? $item : new WP_Error( 'nowcrm_invalid_prefix' );

			case 'country':
				$item = strtoupper( $item );

				return ProfileFields::is_valid_country( $item ) ? $item : new WP_Error( 'nowcrm_invalid_country' );

			case 'timezone':
				return in_array( $item, ProfileFields::timezones(), true ) ? $item : new WP_Error( 'nowcrm_invalid_timezone' );

			case 'custom_option':
				$options = array_map( 'strval', array_keys( (array) $definition['options'] ) );

				return in_array( $item, $options, true ) ? $item : new WP_Error( 'nowcrm_invalid_option' );

			case 'terms':
				break;
		}

		if ( 'terms' === ( $definition['type'] ?? '' ) ) {
			if ( ! preg_match( '/^\d{1,15}$/', $item ) ) {
				return new WP_Error( 'nowcrm_invalid_term' );
			}

			$term = ( new TermRepository() )->get( (int) $item );

			if ( ! $term || (string) $term['type'] !== (string) $definition['term_type'] ) {
				return new WP_Error( 'nowcrm_invalid_term' );
			}

			return (int) $item;
		}

		return new WP_Error( 'nowcrm_invalid_value' );
	}

	/**
	 * Strict calendar date.
	 */
	private static function is_date( string $value ): bool {
		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $value, $match ) ) {
			return false;
		}

		return checkdate( (int) $match[2], (int) $match[3], (int) $match[1] );
	}

	/**
	 * A validation error with the group and row it belongs to.
	 */
	private static function row_error( string $reason, string $message, int $groupNo, int $rowNo ): WP_Error {
		return new WP_Error(
			'nowcrm_advanced_filter_invalid',
			sprintf(
				/* translators: 1: group number, 2: row number, 3: reason. */
				__( '第 %1$d 組第 %2$d 個條件：%3$s', 'now-crm' ),
				$groupNo,
				$rowNo,
				$message
			),
			array(
				'group'  => $groupNo,
				'row'    => $rowNo,
				'reason' => $reason,
			)
		);
	}

	/**
	 * A plan-level error.
	 */
	private static function error( string $message ): WP_Error {
		return new WP_Error( 'nowcrm_advanced_filter_invalid', $message );
	}

	// ---------------------------------------------------------------------
	// Compilation
	// ---------------------------------------------------------------------

	/**
	 * Compile a plan into a WHERE fragment with contact alias `c`.
	 *
	 * A group that contains any condition the compiler cannot resolve becomes
	 * `1=0`. That keeps an unknown condition from widening the result.
	 *
	 * @param mixed $plan Plan from normalize(), a bare group list, or a plan-like array.
	 * @param array<string, array<string, mixed>> $extra_fields Extra field definitions keyed by `provider.field`.
	 * @return array{sql: string, params: array<int, mixed>}
	 */
	public static function compile( $plan, array $extra_fields = array() ): array {
		$groups = self::compile_groups( $plan );

		if ( null === $groups || array() === $groups ) {
			return array(
				'sql'    => '1=1',
				'params' => array(),
			);
		}

		$fields = self::with_extra( self::fields(), $extra_fields );
		$parts  = array();
		$params = array();

		foreach ( $groups as $group ) {
			if ( ! is_array( $group ) || array() === $group ) {
				$parts[] = '1=0';
				continue;
			}

			$conditions = array();
			$local      = array();
			$valid      = true;

			foreach ( $group as $condition ) {
				$compiled = self::compile_condition( $condition, $fields );

				if ( null === $compiled ) {
					$valid = false;
					break;
				}

				$conditions[] = '(' . $compiled['sql'] . ')';

				foreach ( $compiled['params'] as $param ) {
					$local[] = $param;
				}
			}

			if ( ! $valid || array() === $conditions ) {
				$parts[] = '1=0';
				continue;
			}

			foreach ( $local as $param ) {
				$params[] = $param;
			}

			$parts[] = '(' . implode( ' AND ', $conditions ) . ')';
		}

		if ( array() === $parts ) {
			return array(
				'sql'    => '1=1',
				'params' => array(),
			);
		}

		return array(
			'sql'    => '(' . implode( ' OR ', $parts ) . ')',
			'params' => $params,
		);
	}

	/**
	 * Group list from a compiled input.
	 *
	 * @param mixed $plan Plan or group list.
	 * @return array<int, mixed>|null
	 */
	private static function compile_groups( $plan ): ?array {
		if ( ! is_array( $plan ) ) {
			return null;
		}

		if ( isset( $plan['groups'] ) ) {
			return is_array( $plan['groups'] ) ? array_values( $plan['groups'] ) : null;
		}

		return array_values( $plan );
	}

	/**
	 * Compile one condition. Null means the condition is not usable.
	 *
	 * @param mixed                               $condition Condition from a plan.
	 * @param array<string, array<string, mixed>> $fields    Field catalog.
	 * @return array{sql: string, params: array<int, mixed>}|null
	 */
	private static function compile_condition( $condition, array $fields ): ?array {
		if ( ! is_array( $condition ) ) {
			return null;
		}

		list( $provider, $field ) = self::condition_names( $condition );

		$key        = $provider . '.' . $field;
		$definition = $fields[ $key ] ?? ( $fields[ strtolower( $key ) ] ?? null );

		if ( null === $definition ) {
			return null;
		}

		$operator = isset( $condition['operator'] ) && is_scalar( $condition['operator'] )
			? (string) $condition['operator']
			: '';

		if ( ! in_array( $operator, $definition['ops'], true ) ) {
			return null;
		}

		$value = $condition['value'] ?? null;

		// An appended definition compiles its own SQL. It sees the raw plan
		// value and must return null when it cannot resolve the condition, so
		// the group fails closed to `1=0`.
		if ( isset( $definition['compile'] ) && is_callable( $definition['compile'] ) ) {
			return call_user_func( $definition['compile'], $definition, $operator, $condition['value'] ?? null );
		}

		if ( self::is_valueless( $operator ) ) {
			$value = null;
		} elseif ( null === $value || '' === $value || array() === $value ) {
			return null;
		}

		$type = (string) $definition['type'];

		if ( in_array( $type, array( 'select', 'terms' ), true ) ) {
			return self::compile_set( $definition, $operator, $value );
		}

		if ( in_array( $type, array( 'custom_select', 'custom_multi' ), true ) ) {
			return self::compile_custom_set( $definition, $operator, $value );
		}

		switch ( $type ) {
			case 'text':
				return self::compile_text( (string) $definition['column'], $operator, $value );

			case 'number':
				return self::compile_number_column( (string) $definition['column'], $operator, $value );

			case 'date':
				return self::compile_date_column( $definition, $operator, $value );

			case 'custom_text':
				return self::compile_custom_text( $definition, $operator, $value );

			case 'custom_number':
				return self::compile_custom_number( $definition, $operator, $value );

			case 'custom_date':
				return self::compile_custom_date( $definition, $operator, $value );
		}

		return null;
	}

	/**
	 * Text comparison against a contact column.
	 *
	 * @param string $column   Column name from the catalog.
	 * @param string $operator Operator.
	 * @param mixed  $value    Normalized value.
	 * @return array{sql: string, params: array<int, mixed>}|null
	 */
	private static function compile_text( string $column, string $operator, $value ): ?array {
		$column = 'c.' . $column;

		if ( 'is_null' === $operator ) {
			return self::fragment( "({$column} IS NULL OR {$column} = '')", array() );
		}

		if ( 'not_null' === $operator ) {
			return self::fragment( "({$column} IS NOT NULL AND {$column} <> '')", array() );
		}

		if ( ! is_scalar( $value ) ) {
			return null;
		}

		$value = (string) $value;

		switch ( $operator ) {
			case '=':
				return self::fragment( "{$column} = %s", array( $value ) );

			case '!=':
				return self::fragment( "({$column} IS NULL OR {$column} <> %s)", array( $value ) );

			case 'contains':
				return self::fragment( "{$column} LIKE %s", array( self::like( $value ) ) );

			case 'not_contains':
				return self::fragment( "({$column} IS NULL OR {$column} NOT LIKE %s)", array( self::like( $value ) ) );
		}

		return null;
	}

	/**
	 * Numeric comparison against a contact column.
	 *
	 * @param string $column   Column name from the catalog.
	 * @param string $operator Operator.
	 * @param mixed  $value    Normalized value.
	 * @return array{sql: string, params: array<int, mixed>}|null
	 */
	private static function compile_number_column( string $column, string $operator, $value ): ?array {
		if ( ! is_scalar( $value ) || ! preg_match( '/^\d{1,15}$/', (string) $value ) ) {
			return null;
		}

		$column = 'c.' . $column;
		$number = (int) $value;

		switch ( $operator ) {
			case '>':
				return self::fragment( "{$column} > %d", array( $number ) );

			case '<':
				return self::fragment( "{$column} < %d", array( $number ) );

			case '=':
				return self::fragment( "{$column} = %d", array( $number ) );

			case '!=':
				return self::fragment( "({$column} IS NULL OR {$column} <> %d)", array( $number ) );
		}

		return null;
	}

	/**
	 * Date comparison against a contact column.
	 *
	 * @param array<string, mixed> $definition Field definition.
	 * @param string               $operator   Operator.
	 * @param mixed                $value      Normalized value.
	 * @return array{sql: string, params: array<int, mixed>}|null
	 */
	private static function compile_date_column( array $definition, string $operator, $value ): ?array {
		$column = 'c.' . (string) $definition['column'];
		$is_day = 'date' === ( $definition['column_kind'] ?? '' );

		if ( in_array( $operator, array( 'days_before', 'days_within' ), true ) ) {
			if ( ! is_int( $value ) ) {
				return null;
			}

			$cutoff = self::cutoff_date( $value );

			if ( 'days_before' === $operator ) {
				return self::fragment( "{$column} < %s", array( $cutoff ) );
			}

			return self::fragment(
				"({$column} >= %s AND {$column} <= %s)",
				array( $cutoff, self::today() . ' 23:59:59' )
			);
		}

		if ( ! is_string( $value ) || ! self::is_date( $value ) ) {
			return null;
		}

		switch ( $operator ) {
			case 'before':
				return self::fragment( "{$column} < %s", array( $value ) );

			case 'after':
				return self::fragment( "{$column} > %s", array( $value ) );

			case 'date_equal':
				if ( $is_day ) {
					return self::fragment( "{$column} = %s", array( $value ) );
				}

				return self::fragment(
					"({$column} >= %s AND {$column} <= %s)",
					array( $value . ' 00:00:00', $value . ' 23:59:59' )
				);
		}

		return null;
	}

	/**
	 * Select and term conditions.
	 *
	 * @param array<string, mixed> $definition Field definition.
	 * @param string               $operator   Operator.
	 * @param mixed                $value      Normalized value.
	 * @return array{sql: string, params: array<int, mixed>}|null
	 */
	private static function compile_set( array $definition, string $operator, $value ): ?array {
		if ( 'terms' === ( $definition['type'] ?? '' ) ) {
			return self::compile_terms( $definition, $operator, $value );
		}

		$column = 'c.' . (string) $definition['column'];

		if ( in_array( $operator, array( 'is_null', 'not_null' ), true ) ) {
			return self::compile_text( (string) $definition['column'], $operator, null );
		}

		$values = self::string_list( $value );

		if ( null === $values ) {
			return null;
		}

		$placeholders = implode( ',', array_fill( 0, count( $values ), '%s' ) );

		switch ( $operator ) {
			case 'in':
				return self::fragment( "{$column} IN ({$placeholders})", $values );

			case 'not_in':
				return self::fragment( "({$column} IS NULL OR {$column} NOT IN ({$placeholders}))", $values );
		}

		return null;
	}

	/**
	 * Term membership conditions.
	 *
	 * `in_all` requires every listed term. `not_in_all` requires fewer than all
	 * of them, so a contact with none of them matches. Both mirror the
	 * reference relation query.
	 *
	 * @param array<string, mixed> $definition Field definition.
	 * @param string               $operator   Operator.
	 * @param mixed                $value      Normalized value.
	 * @return array{sql: string, params: array<int, mixed>}|null
	 */
	private static function compile_terms( array $definition, string $operator, $value ): ?array {
		$links = Schema::table( 'contact_terms' );
		$terms = Schema::table( 'terms' );
		$type  = (string) $definition['term_type'];

		if ( in_array( $operator, array( 'is_null', 'not_null' ), true ) ) {
			$exists = "EXISTS (SELECT 1 FROM {$links} ct JOIN {$terms} t ON t.id = ct.term_id"
				. ' WHERE ct.contact_id = c.id AND t.type = %s)';

			return self::fragment(
				'not_null' === $operator ? $exists : 'NOT ' . $exists,
				array( $type )
			);
		}

		$ids = self::int_list( $value );

		if ( null === $ids ) {
			return null;
		}

		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$exists       = "EXISTS (SELECT 1 FROM {$links} ct JOIN {$terms} t ON t.id = ct.term_id"
			. " WHERE ct.contact_id = c.id AND t.type = %s AND ct.term_id IN ({$placeholders}))";

		switch ( $operator ) {
			case 'in':
				return self::fragment( $exists, array_merge( array( $type ), $ids ) );

			case 'not_in':
				return self::fragment( 'NOT ' . $exists, array_merge( array( $type ), $ids ) );

			case 'in_all':
			case 'not_in_all':
				$count = "SELECT COUNT(DISTINCT ct.term_id) FROM {$links} ct JOIN {$terms} t ON t.id = ct.term_id"
					. " WHERE ct.contact_id = c.id AND t.type = %s AND ct.term_id IN ({$placeholders})";

				$sql = 'in_all' === $operator
					? "({$count}) = %d"
					: "({$count}) < %d";

				return self::fragment( $sql, array_merge( array( $type ), $ids, array( count( $ids ) ) ) );
		}

		return null;
	}

	/**
	 * Text and membership comparison against a JSON custom-field value.
	 *
	 * @param array<string, mixed> $definition Field definition.
	 * @param string               $operator   Operator.
	 * @param mixed                $value      Normalized value.
	 * @return array{sql: string, params: array<int, mixed>}|null
	 */
	private static function compile_custom_text( array $definition, string $operator, $value ): ?array {
		$values = Schema::table( 'contact_field_values' );
		$field  = (string) $definition['field'];

		if ( 'is_null' === $operator || 'not_null' === $operator ) {
			$exists = "EXISTS (SELECT 1 FROM {$values} v WHERE v.contact_id = c.id AND v.field_key = %s)";

			return self::fragment(
				'not_null' === $operator ? $exists : 'NOT ' . $exists,
				array( $field )
			);
		}

		if ( in_array( $operator, array( 'contains', 'not_contains' ), true ) ) {
			if ( ! is_scalar( $value ) ) {
				return null;
			}

			$match = 'v.value LIKE %s';
			$args  = array( self::like( (string) $value ) );
		} else {
			$items = self::string_list( $value );

			if ( null === $items ) {
				return null;
			}

			$match = array();
			$args  = array();

			foreach ( $items as $item ) {
				$literal = self::json_literal( $item );

				if ( '' === $literal ) {
					return null;
				}

				$match[] = 'v.value LIKE %s';
				$args[]  = self::like( $literal );
			}

			$match = '(' . implode( ' OR ', $match ) . ')';
		}

		$negative = in_array( $operator, array( '!=', 'not_contains', 'not_in' ), true );

		$sql = $negative
			? "NOT EXISTS (SELECT 1 FROM {$values} v WHERE v.contact_id = c.id AND v.field_key = %s AND {$match})"
			: "EXISTS (SELECT 1 FROM {$values} v WHERE v.contact_id = c.id AND v.field_key = %s AND {$match})";

		return self::fragment( $sql, array_merge( array( $field ), $args ) );
	}

	/**
	 * Membership comparison against a JSON custom-field value.
	 *
	 * A scalar and one element of a stored list compare the same way: the JSON
	 * encoding of the value must appear between two quotes in the stored text.
	 *
	 * @param array<string, mixed> $definition Field definition.
	 * @param string               $operator   Operator.
	 * @param mixed                $value      Normalized value.
	 * @return array{sql: string, params: array<int, mixed>}|null
	 */
	private static function compile_custom_set( array $definition, string $operator, $value ): ?array {
		if ( ! in_array( $operator, array( 'in', 'not_in', '=', '!=', 'is_null', 'not_null' ), true ) ) {
			return null;
		}

		return self::compile_custom_text( $definition, $operator, $value );
	}

	/**
	 * Numeric comparison against a JSON custom-field value.
	 *
	 * @param array<string, mixed> $definition Field definition.
	 * @param string               $operator   Operator.
	 * @param mixed                $value      Normalized value.
	 * @return array{sql: string, params: array<int, mixed>}|null
	 */
	private static function compile_custom_number( array $definition, string $operator, $value ): ?array {
		$values = Schema::table( 'contact_field_values' );
		$field  = (string) $definition['field'];

		if ( 'is_null' === $operator || 'not_null' === $operator ) {
			return self::compile_custom_text( $definition, $operator, null );
		}

		if ( ! is_scalar( $value ) || ! preg_match( '/^-?\d{1,14}(\.\d{1,6})?$/', (string) $value ) ) {
			return null;
		}

		$guard = 'v.value IS NOT NULL AND JSON_VALID(v.value) AND JSON_UNQUOTE(v.value) REGEXP %s';
		$args  = array( $field, self::NUMBER_PATTERN );

		if ( '!=' === $operator ) {
			$sql = "NOT EXISTS (SELECT 1 FROM {$values} v WHERE v.contact_id = c.id AND v.field_key = %s AND {$guard}"
				. ' AND CAST(JSON_UNQUOTE(v.value) AS DECIMAL(20,6)) = CAST(%s AS DECIMAL(20,6)))';

			return self::fragment( $sql, array_merge( $args, array( (string) $value ) ) );
		}

		if ( ! in_array( $operator, array( '>', '<', '=' ), true ) ) {
			return null;
		}

		$sql = "EXISTS (SELECT 1 FROM {$values} v WHERE v.contact_id = c.id AND v.field_key = %s AND {$guard}"
			. ' AND CAST(JSON_UNQUOTE(v.value) AS DECIMAL(20,6)) ' . $operator . ' CAST(%s AS DECIMAL(20,6)))';

		return self::fragment( $sql, array_merge( $args, array( (string) $value ) ) );
	}

	/**
	 * Date comparison against a JSON custom-field value.
	 *
	 * @param array<string, mixed> $definition Field definition.
	 * @param string               $operator   Operator.
	 * @param mixed                $value      Normalized value.
	 * @return array{sql: string, params: array<int, mixed>}|null
	 */
	private static function compile_custom_date( array $definition, string $operator, $value ): ?array {
		$values = Schema::table( 'contact_field_values' );
		$field  = (string) $definition['field'];

		if ( 'is_null' === $operator || 'not_null' === $operator ) {
			return self::compile_custom_text( $definition, $operator, null );
		}

		$guard = 'v.value IS NOT NULL AND JSON_VALID(v.value) AND JSON_UNQUOTE(v.value) REGEXP %s';
		$args  = array( $field, self::DATE_PATTERN );

		if ( in_array( $operator, array( 'days_before', 'days_within' ), true ) ) {
			if ( ! is_int( $value ) ) {
				return null;
			}

			$cutoff = self::cutoff_date( $value );

			if ( 'days_before' === $operator ) {
				$match = 'JSON_UNQUOTE(v.value) < %s';
				$args  = array_merge( $args, array( $cutoff ) );
			} else {
				$match = '(JSON_UNQUOTE(v.value) >= %s AND JSON_UNQUOTE(v.value) <= %s)';
				$args  = array_merge( $args, array( $cutoff, self::today() . ' 23:59:59' ) );
			}
		} elseif ( ! is_string( $value ) || ! self::is_date( $value ) ) {
			return null;
		} elseif ( 'date_equal' === $operator ) {
			$match = 'LEFT(JSON_UNQUOTE(v.value), 10) = %s';
			$args  = array_merge( $args, array( $value ) );
		} elseif ( 'before' === $operator ) {
			$match = 'JSON_UNQUOTE(v.value) < %s';
			$args  = array_merge( $args, array( $value ) );
		} elseif ( 'after' === $operator ) {
			$match = 'JSON_UNQUOTE(v.value) > %s';
			$args  = array_merge( $args, array( $value ) );
		} else {
			return null;
		}

		$sql = "EXISTS (SELECT 1 FROM {$values} v WHERE v.contact_id = c.id AND v.field_key = %s AND {$guard} AND {$match})";

		return self::fragment( $sql, $args );
	}

	/**
	 * Wrap one SQL fragment and its parameters.
	 *
	 * @param string $sql    SQL fragment with placeholders.
	 * @param array<int, mixed> $params Parameters in placeholder order.
	 * @return array{sql: string, params: array<int, mixed>}
	 */
	private static function fragment( string $sql, array $params ): array {
		return array(
			'sql'    => $sql,
			'params' => $params,
		);
	}

	/**
	 * LIKE pattern for a substring match.
	 */
	private static function like( string $value ): string {
		global $wpdb;

		return '%' . $wpdb->esc_like( $value ) . '%';
	}

	/**
	 * JSON encoding of a string, the same way CustomFieldValues stores it.
	 */
	private static function json_literal( string $value ): string {
		$json = wp_json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

		return is_string( $json ) ? $json : '';
	}

	/**
	 * Validate a value list as strings.
	 *
	 * @param mixed $value Normalized value.
	 * @return string[]|null
	 */
	private static function string_list( $value ): ?array {
		$items = is_array( $value ) ? $value : array( $value );
		$clean = array();

		foreach ( $items as $item ) {
			if ( ! is_scalar( $item ) ) {
				return null;
			}

			$item = trim( (string) $item );

			if ( '' !== $item ) {
				$clean[] = $item;
			}
		}

		return array() === $clean ? null : $clean;
	}

	/**
	 * Validate a value list as positive integers.
	 *
	 * @param mixed $value Normalized value.
	 * @return int[]|null
	 */
	private static function int_list( $value ): ?array {
		$items = is_array( $value ) ? $value : array( $value );
		$clean = array();

		foreach ( $items as $item ) {
			if ( ! is_scalar( $item ) || ! preg_match( '/^\d{1,15}$/', (string) $item ) ) {
				return null;
			}

			$id = (int) $item;

			if ( $id > 0 && ! in_array( $id, $clean, true ) ) {
				$clean[] = $id;
			}
		}

		return array() === $clean ? null : $clean;
	}

	/**
	 * Site-local date of "today".
	 */
	private static function today(): string {
		return (string) current_time( 'Y-m-d' );
	}

	/**
	 * Site-local date N days before today.
	 */
	private static function cutoff_date( int $days ): string {
		$today = self::today();
		$start = DateTimeImmutable::createFromFormat( '!Y-m-d', $today, wp_timezone() );

		if ( ! $start ) {
			return $today;
		}

		return $start->modify( '-' . $days . ' days' )->format( 'Y-m-d' );
	}
}
