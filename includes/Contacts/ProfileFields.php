<?php
/**
 * Contact profile field vocabulary: editable columns, validation, and values.
 *
 * Field keys are contact column names, so repository writes can pass a
 * normalized patch straight to `ContactRepository::update()`. Every helper
 * works on a partial patch: a key that is missing from the input keeps its
 * stored value.
 *
 * @package NowCRM
 */

namespace NowCRM\Contacts;

use WP_Error;

defined( 'ABSPATH' ) || exit;

final class ProfileFields {

	/** Plain single-line text. */
	public const TYPE_TEXT = 'text';

	/** One value chosen from a fixed option list. */
	public const TYPE_SELECT = 'select';

	/** Calendar date stored as `Y-m-d`, or NULL when unset. */
	public const TYPE_DATE = 'date';

	/** IANA timezone identifier. */
	public const TYPE_TIMEZONE = 'timezone';

	/** Default contact type for new rows, matching the reference UI. */
	public const DEFAULT_CONTACT_TYPE = 'lead';

	/**
	 * ISO 3166-1 alpha-2 codes for the standalone fallback.
	 *
	 * Validation never loads WooCommerce: it checks this list, plus codes added
	 * by the `nowcrm_contact_countries` filter. WooCommerce only supplies the
	 * select labels when it is already loaded.
	 *
	 * @var string
	 */
	private const ISO_COUNTRIES = 'AF AX AL DZ AS AD AO AI AQ AG AR AM AW AU AT AZ BS BH BD BB BY BE PW BZ BJ BM BT BO BQ BA BW BV BR IO BN BG BF BI KH CM CA CV KY CF TD CL CN CX CC CO KM CG CD CK CR HR CU CW CY CZ DK DJ DM DO EC EG SV GQ ER EE ET FK FO FJ FI FR GF PF TF GA GM GE DE GH GI GR GL GD GP GU GT GG GN GW GY HT HM HN HK HU IS IN ID IR IQ IE IM IL IT CI JM JP JE JO KZ KE KI XK KW KG LA LV LB LS LR LY LI LT LU MO MK MG MW MY MV ML MT MH MQ MR MU YT MX FM MD MC MN ME MS MA MZ MM NA NR NP NL NC NZ NI NE NG NU NF MP KP NO OM PK PS PA PG PY PE PH PN PL PT PR QA RE RO RU RW BL SH KN LC MF SX PM VC SM ST SA SN RS SC SL SG SK SI SB SO ZA GS KR SS ES LK SD SR SJ SZ SE CH SY TW TJ TZ TH TL TG TK TO TT TN TR TM TC TV UG UA AE GB US UM UY UZ VU VA VE VN VG VI WF EH WS YE ZM ZW';

	/**
	 * Cached countries for the select: code => name.
	 *
	 * @var array<string, string>|null
	 */
	private static ?array $countries = null;

	/**
	 * Cached ISO codes for validation.
	 *
	 * @var string[]|null
	 */
	private static ?array $iso_codes = null;

	/**
	 * Cached IANA timezone identifiers.
	 *
	 * @var string[]|null
	 */
	private static ?array $timezones = null;

	/**
	 * Editable field definitions, in form order.
	 *
	 * `default` is the stored value for a new row. `nullable` says whether the
	 * column stores NULL, which only `date_of_birth` does. `max_length` is the
	 * column width, used to cap text before the write.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function fields(): array {
		return array(
			'prefix'         => array(
				'type'       => self::TYPE_SELECT,
				'label'      => __( '稱謂', 'now-crm' ),
				'nullable'   => false,
				'default'    => '',
				'options'    => self::prefixes(),
			),
			'contact_type'   => array(
				'type'       => self::TYPE_SELECT,
				'label'      => __( '聯絡人類型', 'now-crm' ),
				'nullable'   => false,
				'default'    => self::DEFAULT_CONTACT_TYPE,
				'options'    => self::contact_types(),
			),
			'date_of_birth'  => array(
				'type'       => self::TYPE_DATE,
				'label'      => __( '出生日期', 'now-crm' ),
				'nullable'   => true,
				'default'    => null,
			),
			'timezone'       => array(
				'type'       => self::TYPE_TIMEZONE,
				'label'      => __( '時區', 'now-crm' ),
				'nullable'   => false,
				'default'    => '',
			),
			'address_line_1' => array(
				'type'       => self::TYPE_TEXT,
				'label'      => __( '地址第 1 行', 'now-crm' ),
				'nullable'   => false,
				'default'    => '',
				'max_length' => 191,
			),
			'address_line_2' => array(
				'type'       => self::TYPE_TEXT,
				'label'      => __( '地址第 2 行', 'now-crm' ),
				'nullable'   => false,
				'default'    => '',
				'max_length' => 191,
			),
			'city'           => array(
				'type'       => self::TYPE_TEXT,
				'label'      => __( '城市', 'now-crm' ),
				'nullable'   => false,
				'default'    => '',
				'max_length' => 100,
			),
			'state'          => array(
				'type'       => self::TYPE_TEXT,
				'label'      => __( '州／省／地區', 'now-crm' ),
				'nullable'   => false,
				'default'    => '',
				'max_length' => 100,
			),
			'postal_code'    => array(
				'type'       => self::TYPE_TEXT,
				'label'      => __( '郵遞區號', 'now-crm' ),
				'nullable'   => false,
				'default'    => '',
				'max_length' => 20,
			),
			'country'        => array(
				'type'       => self::TYPE_SELECT,
				'label'      => __( '國家', 'now-crm' ),
				'nullable'   => false,
				'default'    => '',
				'options'    => self::countries(),
			),
		);
	}

	/**
	 * Editable field keys, in form order.
	 *
	 * @return string[]
	 */
	public static function keys(): array {
		return array_keys( self::fields() );
	}

	/**
	 * Definition for one field key.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function definition( string $key ): ?array {
		return self::fields()[ $key ] ?? null;
	}

	/**
	 * Canonical stored defaults for a new contact row.
	 *
	 * @return array<string, string|null>
	 */
	public static function defaults(): array {
		$defaults = array();

		foreach ( self::fields() as $key => $definition ) {
			$defaults[ $key ] = $definition['default'];
		}

		return $defaults;
	}

	/**
	 * Validate and sanitize the profile keys present in a request.
	 *
	 * Unknown keys are ignored, so a handler can pass the whole POST array.
	 * Omitted keys are absent from the result, which keeps partial updates.
	 *
	 * @param array<string, mixed> $input Request data.
	 * @return array<string, string|null>|WP_Error
	 */
	public static function normalize( array $input ) {
		$patch = array();

		foreach ( self::fields() as $key => $definition ) {
			if ( ! array_key_exists( $key, $input ) ) {
				continue;
			}

			$value = self::normalize_value( $key, $definition, $input[ $key ] );

			if ( is_wp_error( $value ) ) {
				return $value;
			}

			$patch[ $key ] = $value;
		}

		return $patch;
	}

	/**
	 * Validate and sanitize one field value.
	 *
	 * Empty text clears to `''`; an empty date clears to NULL; an empty contact
	 * type falls back to the stored default `lead`.
	 *
	 * @param mixed $value Raw value.
	 * @return string|null|WP_Error
	 */
	public static function normalize_field( string $key, $value ) {
		$definition = self::definition( $key );

		if ( null === $definition ) {
			return new WP_Error( 'nowcrm_unknown_profile_field', __( '未知的聯絡人欄位。', 'now-crm' ) );
		}

		return self::normalize_value( $key, $definition, $value );
	}

	/**
	 * Validate and sanitize one value against its definition.
	 *
	 * @param array<string, mixed> $definition Field definition.
	 * @param mixed                $value      Raw value.
	 * @return string|null|WP_Error
	 */
	private static function normalize_value( string $key, array $definition, $value ) {
		if ( is_array( $value ) || is_object( $value ) ) {
			return self::invalid( $key );
		}

		$value = is_scalar( $value ) ? (string) $value : '';

		switch ( $definition['type'] ) {
			case self::TYPE_TEXT:
				return self::sanitize_text( $value, (int) ( $definition['max_length'] ?? 0 ) );

			case self::TYPE_SELECT:
				$value = trim( sanitize_text_field( $value ) );

				if ( 'prefix' === $key ) {
					return '' === $value || self::is_valid_prefix( $value ) ? $value : self::invalid( $key );
				}

				if ( 'contact_type' === $key ) {
					$value = strtolower( $value );

					if ( '' === $value ) {
						return self::DEFAULT_CONTACT_TYPE;
					}

					return self::is_valid_contact_type( $value ) ? $value : self::invalid( $key );
				}

				if ( 'country' === $key ) {
					$value = strtoupper( $value );

					return '' === $value || self::is_valid_country( $value ) ? $value : self::invalid( $key );
				}

				return self::invalid( $key );

			case self::TYPE_DATE:
				$value = trim( $value );

				if ( '' === $value ) {
					return null;
				}

				return self::is_valid_date( $value ) ? $value : self::invalid( $key );

			case self::TYPE_TIMEZONE:
				$value = trim( sanitize_text_field( $value ) );

				return '' === $value || self::is_valid_timezone( $value ) ? $value : self::invalid( $key );
		}

		return self::invalid( $key );
	}

	/**
	 * Raw value for a form control, falling back to the canonical default.
	 *
	 * @param array<string, mixed> $contact Contact row.
	 */
	public static function form_value( array $contact, string $key ): string {
		$definition = self::definition( $key );

		if ( null === $definition ) {
			return '';
		}

		$value = array_key_exists( $key, $contact ) ? $contact[ $key ] : $definition['default'];

		if ( null === $value ) {
			$value = $definition['default'];
		}

		return null === $value ? '' : (string) $value;
	}

	/**
	 * Human readable value: option labels for selects, raw text otherwise.
	 *
	 * @param array<string, mixed> $contact Contact row.
	 */
	public static function display_value( array $contact, string $key ): string {
		$value = self::form_value( $contact, $key );

		if ( 'contact_type' === $key ) {
			return self::contact_type_label( $value );
		}

		if ( 'country' === $key ) {
			return self::countries()[ $value ] ?? $value;
		}

		return $value;
	}

	/**
	 * Name prefixes offered by the select.
	 *
	 * @return string[]
	 */
	public static function prefixes(): array {
		$prefixes = (array) apply_filters( 'nowcrm_contact_prefixes', array( 'Mr', 'Mrs', 'Ms' ) );
		$clean    = array();

		foreach ( $prefixes as $prefix ) {
			if ( ! is_scalar( $prefix ) ) {
				continue;
			}

			$prefix = trim( sanitize_text_field( (string) $prefix ) );

			if ( '' !== $prefix && ! in_array( $prefix, $clean, true ) ) {
				$clean[] = $prefix;
			}
		}

		return $clean;
	}

	/**
	 * Contact type keys and labels.
	 *
	 * @return array<string, string>
	 */
	public static function contact_types(): array {
		return (array) apply_filters(
			'nowcrm_contact_types',
			array(
				'lead'     => __( '潛在客戶', 'now-crm' ),
				'customer' => __( '客戶', 'now-crm' ),
			)
		);
	}

	/**
	 * Label for a contact type key.
	 */
	public static function contact_type_label( string $type ): string {
		return self::contact_types()[ $type ] ?? '';
	}

	/**
	 * Countries for the select: ISO code => name.
	 *
	 * WooCommerce labels are used when it is already loaded; otherwise the code
	 * is the label. The list is cached for the request.
	 *
	 * @return array<string, string>
	 */
	public static function countries(): array {
		if ( null !== self::$countries ) {
			return self::$countries;
		}

		$countries = array();

		if ( function_exists( 'WC' ) ) {
			$woo = WC();

			if ( is_object( $woo ) && isset( $woo->countries ) && is_object( $woo->countries )
				&& method_exists( $woo->countries, 'get_countries' ) ) {
				$countries = (array) $woo->countries->get_countries();
			}
		}

		if ( array() === $countries ) {
			foreach ( self::iso_codes() as $code ) {
				$countries[ $code ] = $code;
			}
		}

		$labels = array();

		foreach ( $countries as $code => $label ) {
			if ( ! is_string( $code ) ) {
				continue;
			}

			$label = html_entity_decode( trim( (string) $label ), ENT_QUOTES, 'UTF-8' );

			if ( '' !== $label ) {
				$labels[ $code ] = $label;
			}
		}

		self::$countries = (array) apply_filters( 'nowcrm_contact_countries', $labels );

		return self::$countries;
	}

	/**
	 * IANA timezone identifiers.
	 *
	 * @return string[]
	 */
	public static function timezones(): array {
		if ( null === self::$timezones ) {
			self::$timezones = timezone_identifiers_list();
		}

		return self::$timezones;
	}

	/**
	 * Whether a contact type key is offered.
	 */
	public static function is_valid_contact_type( string $type ): bool {
		return array_key_exists( $type, self::contact_types() );
	}

	/**
	 * Whether a prefix is offered. Empty means unset and is handled by the caller.
	 */
	public static function is_valid_prefix( string $prefix ): bool {
		return in_array( $prefix, self::prefixes(), true );
	}

	/**
	 * Whether a value is an ISO 3166-1 alpha-2 code. Does not load WooCommerce.
	 */
	public static function is_valid_country( string $code ): bool {
		$code = strtoupper( trim( $code ) );

		if ( ! preg_match( '/^[A-Z]{2}$/', $code ) ) {
			return false;
		}

		if ( in_array( $code, self::iso_codes(), true ) ) {
			return true;
		}

		$custom = (array) apply_filters( 'nowcrm_contact_countries', array() );

		return array_key_exists( $code, $custom );
	}

	/**
	 * Whether a value is an IANA timezone identifier.
	 */
	public static function is_valid_timezone( string $timezone ): bool {
		return '' !== $timezone && in_array( $timezone, self::timezones(), true );
	}

	/**
	 * Whether a value is a real calendar date in `Y-m-d` format.
	 */
	public static function is_valid_date( string $date ): bool {
		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $date, $parts ) ) {
			return false;
		}

		return checkdate( (int) $parts[2], (int) $parts[3], (int) $parts[1] );
	}

	/**
	 * ISO codes parsed once per request.
	 *
	 * @return string[]
	 */
	private static function iso_codes(): array {
		if ( null === self::$iso_codes ) {
			self::$iso_codes = array_values( array_filter( explode( ' ', self::ISO_COUNTRIES ) ) );
		}

		return self::$iso_codes;
	}

	/**
	 * Sanitize one text value and cap it at the column width.
	 */
	private static function sanitize_text( string $value, int $max_length ): string {
		$value = sanitize_text_field( $value );

		if ( $max_length > 0 && mb_strlen( $value, 'UTF-8' ) > $max_length ) {
			$value = mb_substr( $value, 0, $max_length, 'UTF-8' );
		}

		return $value;
	}

	/**
	 * Error for an invalid field value.
	 */
	private static function invalid( string $key ): WP_Error {
		$messages = array(
			'prefix'         => __( '稱謂不正確。', 'now-crm' ),
			'contact_type'   => __( '聯絡人類型不正確。', 'now-crm' ),
			'date_of_birth'  => __( '出生日期格式不正確，請使用 YYYY-MM-DD。', 'now-crm' ),
			'timezone'       => __( '時區不正確。', 'now-crm' ),
			'address_line_1' => __( '地址格式不正確。', 'now-crm' ),
			'address_line_2' => __( '地址格式不正確。', 'now-crm' ),
			'city'           => __( '城市名稱不正確。', 'now-crm' ),
			'state'          => __( '州／省／地區名稱不正確。', 'now-crm' ),
			'postal_code'    => __( '郵遞區號格式不正確。', 'now-crm' ),
			'country'        => __( '國家代碼不正確。', 'now-crm' ),
		);

		return new WP_Error(
			'nowcrm_invalid_' . $key,
			$messages[ $key ] ?? __( '欄位內容不正確。', 'now-crm' )
		);
	}
}
