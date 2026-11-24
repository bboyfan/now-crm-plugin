<?php
/**
 * Company field vocabulary: types, industry suggestions, and sanitizers.
 *
 * @package NowCRM
 */

namespace NowCRM\Companies;

defined( 'ABSPATH' ) || exit;

final class CompanyFields {

	/**
	 * Relationship types, matching the installed reference UI.
	 *
	 * @var string[]
	 */
	private const TYPES = array(
		'Prospect',
		'Partner',
		'Reseller',
		'Vendor',
		'Other',
	);

	/**
	 * Fallback industry suggestions. Industry accepts free text in the
	 * reference UI; this list only seeds the datalist.
	 *
	 * @var string[]
	 */
	private const INDUSTRIES = array(
		'Accounting',
		'Agriculture',
		'Automotive',
		'Banking',
		'Construction',
		'Consulting',
		'Education',
		'Finance',
		'Government',
		'Healthcare',
		'Hospitality',
		'Legal',
		'Manufacturing',
		'Media',
		'Nonprofit',
		'Real Estate',
		'Retail',
		'Technology',
		'Transportation',
	);

	/**
	 * Columns allowed in ORDER BY. Maps request keys to expressions.
	 *
	 * @var array<string, string>
	 */
	private const ORDER_COLUMNS = array(
		'id'               => 'c.id',
		'name'             => 'c.name',
		'industry'         => 'c.industry',
		'type'             => 'c.type',
		'employees_number' => 'c.employees_number',
		'contacts_count'   => 'contact_count',
		'created_at'       => 'c.created_at',
		'updated_at'       => 'c.updated_at',
	);

	/**
	 * Mass-assignable company columns, excluding the primary key.
	 *
	 * @var string[]
	 */
	public const EDITABLE = array(
		'name',
		'owner_id',
		'industry',
		'type',
		'email',
		'phone',
		'address_line_1',
		'address_line_2',
		'postal_code',
		'city',
		'state',
		'country',
		'employees_number',
		'description',
		'logo',
		'website',
		'linkedin_url',
		'facebook_url',
		'twitter_url',
	);

	/**
	 * Relationship types available for select inputs.
	 *
	 * @return string[]
	 */
	public static function types(): array {
		return (array) apply_filters( 'nowcrm_company_types', self::TYPES );
	}

	/**
	 * Whether a type value is allowed. Empty means unset.
	 */
	public static function is_valid_type( string $type ): bool {
		if ( '' === $type ) {
			return true;
		}

		return in_array( $type, self::types(), true );
	}

	/**
	 * Industry suggestions for the datalist input.
	 *
	 * @return string[]
	 */
	public static function industries(): array {
		return (array) apply_filters( 'nowcrm_company_industries', self::INDUSTRIES );
	}

	/**
	 * Resolve an orderby request key to a trusted SQL expression.
	 */
	public static function order_column( string $orderby ): string {
		return self::ORDER_COLUMNS[ $orderby ] ?? self::ORDER_COLUMNS['id'];
	}

	/**
	 * Whether an orderby request key is supported.
	 */
	public static function is_valid_orderby( string $orderby ): bool {
		return array_key_exists( $orderby, self::ORDER_COLUMNS );
	}

	/**
	 * Sanitize a company website or social URL. Adds a scheme when missing.
	 */
	public static function sanitize_url( string $url ): string {
		$url = trim( $url );

		if ( '' === $url ) {
			return '';
		}

		if ( ! preg_match( '#^[a-z][a-z0-9+.-]*://#i', $url ) ) {
			$url = 'https://' . $url;
		}

		$clean = esc_url_raw( $url );

		return '' !== $clean && wp_http_validate_url( $clean ) ? $clean : '';
	}

	/**
	 * One-line summary of the stored address, skipping empty parts.
	 */
	public static function address_summary( array $company ): string {
		$parts = array();

		foreach ( array( 'address_line_1', 'address_line_2', 'city', 'state', 'postal_code', 'country' ) as $key ) {
			$value = trim( (string) ( $company[ $key ] ?? '' ) );

			if ( '' !== $value ) {
				$parts[] = $value;
			}
		}

		return '' !== ( $summary = implode( ', ', $parts ) ) ? $summary : '—';
	}
}
