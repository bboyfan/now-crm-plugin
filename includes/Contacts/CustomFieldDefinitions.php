<?php
/**
 * Contact custom-field definitions and named groups.
 *
 * Definitions live in one site-scoped, non-autoloaded option. The option holds
 * an ordered list so the settings screen and the contact form render fields in
 * the saved order. Each definition has five keys:
 *
 *     array(
 *         'label'   => 'Company size',       // required, plain text
 *         'slug'    => 'company_size',        // immutable field key, lowercase
 *         'type'    => 'select-one',          // one of self::KINDS
 *         'options' => array( '1-10', ... ),  // required for choice kinds
 *         'group'   => 'default',             // group slug
 *     )
 *
 * Groups live in a second option as an ordered list of `slug` / `title` rows.
 * A group slug is stable; only its title changes. A definition that references
 * a missing group keeps rendering, because groups() appends referenced groups.
 *
 * Reference behaviour (FluentCRM 3.3.1, CustomContactField and
 * CustomContactFieldsController): the settings screen posts the complete field
 * list, the server generates a slug when one is missing, drops duplicate slugs,
 * and keeps the submitted order. This class does the same, but it validates the
 * whole list before it writes and it never overwrites an existing field key.
 *
 * @package NowCRM
 */

namespace NowCRM\Contacts;

use WP_Error;

defined( 'ABSPATH' ) || exit;

final class CustomFieldDefinitions {

	public const OPTION = 'nowcrm_contact_fields';

	public const GROUPS_OPTION = 'nowcrm_contact_field_groups';

	public const DEFAULT_GROUP = 'default';

	public const MAX_FIELDS = 100;

	public const MAX_OPTIONS = 200;

	public const MAX_GROUPS = 50;

	public const MAX_KEY_LENGTH = 64;

	/**
	 * Supported field kinds. The key is the stored type and the value_type
	 * tells value storage what shape to expect.
	 *
	 * @var array<string, array{label: string, value_type: string}>
	 */
	public const KINDS = array(
		'text'          => array(
			'label'      => 'Single Line Text',
			'value_type' => 'string',
		),
		'textarea'      => array(
			'label'      => 'Multi Line Text',
			'value_type' => 'string',
		),
		'number'        => array(
			'label'      => 'Numeric Field',
			'value_type' => 'numeric',
		),
		'select-one'    => array(
			'label'      => 'Select Choice',
			'value_type' => 'string',
		),
		'select-multi'  => array(
			'label'      => 'Multiple Select Choice',
			'value_type' => 'array',
		),
		'radio'         => array(
			'label'      => 'Radio Choice',
			'value_type' => 'string',
		),
		'checkbox'      => array(
			'label'      => 'Checkboxes',
			'value_type' => 'array',
		),
		'date'          => array(
			'label'      => 'Date',
			'value_type' => 'date',
		),
		'date_time'     => array(
			'label'      => 'Date and Time',
			'value_type' => 'datetime',
		),
	);

	/**
	 * Keys that belong to native contact columns or to read models, so a custom
	 * field must not use them.
	 *
	 * @var string[]
	 */
	private const RESERVED_KEYS = array(
		'id',
		'email',
		'email_normalized',
		'first_name',
		'last_name',
		'phone',
		'wp_user_id',
		'status',
		'status_changed_at',
		'source',
		'created_at',
		'updated_at',
		'terms',
		'aggregate',
		'custom_fields',
		'contact_id',
		'field_key',
	);

	/**
	 * Kind metadata for the settings screen.
	 *
	 * @return array<string, array{label: string, value_type: string}>
	 */
	public static function kinds(): array {
		return self::KINDS;
	}

	/**
	 * Whether a type is supported.
	 */
	public static function supports( string $type ): bool {
		return isset( self::KINDS[ $type ] );
	}

	/**
	 * Value shape for a type: string, numeric, array, date, or datetime.
	 */
	public static function value_type( string $type ): string {
		return self::KINDS[ $type ]['value_type'] ?? 'string';
	}

	/**
	 * Whether a type stores a list of choice values.
	 */
	public static function is_multi( string $type ): bool {
		return in_array( $type, array( 'select-multi', 'checkbox' ), true );
	}

	/**
	 * Whether a type needs a non-empty options list.
	 */
	public static function needs_options( string $type ): bool {
		return in_array( $type, array( 'select-one', 'select-multi', 'radio', 'checkbox' ), true );
	}

	/**
	 * All definitions in saved order.
	 *
	 * @return array<int, array{label: string, slug: string, type: string, options: string[], group: string}>
	 */
	public static function all(): array {
		$stored = get_option( self::OPTION, array() );

		if ( ! is_array( $stored ) ) {
			return array();
		}

		$definitions = array();

		foreach ( $stored as $field ) {
			$field = self::present( $field );

			if ( null !== $field ) {
				$definitions[] = $field;
			}
		}

		return $definitions;
	}

	/**
	 * One definition by field key (case-independent), or null.
	 *
	 * @return array{label: string, slug: string, type: string, options: string[], group: string}|null
	 */
	public static function get( string $key ): ?array {
		$needle = strtolower( trim( $key ) );

		if ( '' === $needle ) {
			return null;
		}

		foreach ( self::all() as $field ) {
			if ( strtolower( $field['slug'] ) === $needle ) {
				return $field;
			}
		}

		return null;
	}

	/**
	 * Field keys in saved order. CSV mapping and shortcode registration use it.
	 *
	 * @return string[]
	 */
	public static function keys(): array {
		return array_map(
			static fn( array $field ): string => $field['slug'],
			self::all()
		);
	}

	/**
	 * Replace the definition list. Order is the submitted order.
	 *
	 * The whole list is validated first. A definition without a slug gets one
	 * from its label. A slug that already exists keeps its stored spelling, so
	 * a key never changes case. Unknown groups are registered.
	 *
	 * @param array<int, mixed> $definitions Submitted fields.
	 * @return array<int, array<string, mixed>>|WP_Error Saved fields.
	 */
	public static function save( array $definitions ) {
		$parsed = self::parse_fields( $definitions );

		if ( is_wp_error( $parsed ) ) {
			return $parsed;
		}

		$groups = self::stored_groups();

		foreach ( $parsed['groups'] as $slug => $title ) {
			if ( ! isset( $groups[ $slug ] ) ) {
				$groups[ $slug ] = $title;
			}
		}

		self::write_groups( $groups );
		update_option( self::OPTION, $parsed['fields'], false );

		return $parsed['fields'];
	}

	/**
	 * Replace definitions and group titles together.
	 *
	 * Both lists are validated before the first option write. Only groups that
	 * at least one saved field references are kept, so a rejected or empty form
	 * cannot leave a group with no field. Explicit titles win; a group that a
	 * field names but the group list omits keeps the field's group text.
	 *
	 * @param array<int, mixed> $definitions Submitted fields.
	 * @param array<int, mixed> $groups      Submitted groups (slug, title).
	 * @return array<int, array<string, mixed>>|WP_Error Saved fields.
	 */
	public static function save_with_groups( array $definitions, array $groups ) {
		$parsed = self::parse_fields( $definitions );

		if ( is_wp_error( $parsed ) ) {
			return $parsed;
		}

		$titles = self::parse_group_list( $groups );

		if ( is_wp_error( $titles ) ) {
			return $titles;
		}

		$referenced = array();

		foreach ( $parsed['fields'] as $field ) {
			$referenced[ $field['group'] ] = true;
		}

		$ordered = array();

		foreach ( $titles as $slug => $title ) {
			if ( isset( $referenced[ $slug ] ) ) {
				$ordered[ $slug ] = $title;
			}
		}

		$stored = self::stored_groups();

		foreach ( $parsed['groups'] as $slug => $title ) {
			if ( isset( $referenced[ $slug ] ) && ! isset( $ordered[ $slug ] ) ) {
				$ordered[ $slug ] = $stored[ $slug ] ?? $title;
			}
		}

		self::write_groups( $ordered );
		update_option( self::OPTION, $parsed['fields'], false );

		return $parsed['fields'];
	}

	/**
	 * Validate and normalize a field list without writing anything.
	 *
	 * @param array<int, mixed> $definitions Submitted fields.
	 * @return array{fields: array<int, array<string, mixed>>, groups: array<string, string>}|WP_Error
	 */
	private static function parse_fields( array $definitions ) {
		$definitions = array_values( $definitions );

		if ( count( $definitions ) > self::MAX_FIELDS ) {
			return new WP_Error(
				'nowcrm_too_many_fields',
				sprintf(
					/* translators: %d: maximum number of custom fields. */
					__( '自訂欄位最多 %d 個。', 'now-crm' ),
					self::MAX_FIELDS
				)
			);
		}

		$existing = array();

		foreach ( self::all() as $field ) {
			$existing[ strtolower( $field['slug'] ) ] = $field['slug'];
		}

		$errors      = array();
		$fields      = array();
		$seen        = array();
		$group_slugs = array();

		foreach ( $definitions as $index => $raw ) {
			$position = $index + 1;

			if ( ! is_array( $raw ) ) {
				$errors[ $index ] = sprintf(
					/* translators: %d: field position. */
					__( '第 %d 個欄位格式不正確。', 'now-crm' ),
					$position
				);
				continue;
			}

			$label = sanitize_text_field( trim( (string) ( $raw['label'] ?? '' ) ) );

			if ( '' === $label ) {
				$errors[ $index ] = sprintf(
					/* translators: %d: field position. */
					__( '第 %d 個欄位缺少名稱。', 'now-crm' ),
					$position
				);
				continue;
			}

			$type = sanitize_key( (string) ( $raw['type'] ?? '' ) );

			if ( ! isset( self::KINDS[ $type ] ) ) {
				$errors[ $index ] = sprintf(
					/* translators: %s: field label. */
					__( '「%s」的欄位類型不支援。', 'now-crm' ),
					$label
				);
				continue;
			}

			$options = array();

			if ( self::needs_options( $type ) ) {
				$options = self::normalize_options( $raw['options'] ?? array() );

				if ( array() === $options ) {
					$errors[ $index ] = sprintf(
						/* translators: %s: field label. */
						__( '「%s」需要至少一個選項。', 'now-crm' ),
						$label
					);
					continue;
				}
			}

			$slug = self::normalize_key( (string) ( $raw['slug'] ?? '' ) );

			if ( '' === $slug ) {
				$slug = self::generate_key( $label, array_keys( $seen ) + $existing );
			} else {
				// Keep the stored spelling so the key is immutable.
				$slug = $existing[ $slug ] ?? $slug;

				if ( isset( $seen[ $slug ] ) ) {
					$errors[ $index ] = sprintf(
						/* translators: %s: field key. */
						__( '欄位代稱「%s」重複。', 'now-crm' ),
						$slug
					);
					continue;
				}
			}

			$group_raw  = trim( (string) ( $raw['group'] ?? '' ) );
			$group_slug = self::normalize_group( $group_raw );

			if ( '' === $group_slug ) {
				$group_slug = self::DEFAULT_GROUP;
			}

			$field = array(
				'label'   => $label,
				'slug'    => $slug,
				'type'    => $type,
				'options' => $options,
				'group'   => $group_slug,
			);

			$seen[ $slug ]           = true;
			$group_slugs[ $group_slug ] = '' !== $group_raw
				? sanitize_text_field( $group_raw )
				: ( self::DEFAULT_GROUP === $group_slug ? __( 'Custom Profile Data', 'now-crm' ) : ucfirst( $group_slug ) );
			$fields[]                = $field;
		}

		if ( array() !== $errors ) {
			return new WP_Error(
				'nowcrm_invalid_custom_fields',
				__( '自訂欄位資料不正確，尚未儲存。', 'now-crm' ),
				array( 'errors' => $errors )
			);
		}

		return array(
			'fields' => $fields,
			'groups' => $group_slugs,
		);
	}

	/**
	 * Groups in saved order. The default group is always available.
	 *
	 * @return array<int, array{slug: string, title: string}>
	 */
	public static function groups(): array {
		$stored = get_option( self::GROUPS_OPTION, array() );

		if ( ! is_array( $stored ) || array() === $stored ) {
			$stored = array( self::DEFAULT_GROUP => __( 'Custom Profile Data', 'now-crm' ) );
		}

		$groups = array();

		foreach ( $stored as $slug => $title ) {
			if ( is_array( $title ) ) {
				$slug  = (string) ( $title['slug'] ?? '' );
				$title = (string) ( $title['title'] ?? '' );
			}

			$slug = self::normalize_group( (string) $slug );

			if ( '' === $slug ) {
				continue;
			}

			$groups[ $slug ] = '' !== trim( (string) $title ) ? sanitize_text_field( (string) $title ) : ucfirst( $slug );
		}

		foreach ( self::all() as $field ) {
			if ( ! isset( $groups[ $field['group'] ] ) ) {
				$groups[ $field['group'] ] = ucfirst( $field['group'] );
			}
		}

		$result = array();

		foreach ( $groups as $slug => $title ) {
			$result[] = array(
				'slug'  => $slug,
				'title' => $title,
			);
		}

		return $result;
	}

	/**
	 * Replace the group list. Group slugs are immutable once saved.
	 *
	 * @param array<int, mixed> $groups Rows with `slug` (optional) and `title`.
	 * @return array<int, array{slug: string, title: string}>|WP_Error
	 */
	public static function save_groups( array $groups ) {
		$saved = self::parse_group_list( $groups );

		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		if ( array() === $saved ) {
			$saved = array( self::DEFAULT_GROUP => __( 'Custom Profile Data', 'now-crm' ) );
		}

		self::write_groups( $saved );

		return self::groups();
	}

	/**
	 * Validate a group list without writing anything.
	 *
	 * @param array<int, mixed> $groups Rows with `slug` (optional) and `title`.
	 * @return array<string, string>|WP_Error Slug => title, in submitted order.
	 */
	private static function parse_group_list( array $groups ) {
		$groups = array_values( $groups );

		if ( count( $groups ) > self::MAX_GROUPS ) {
			return new WP_Error(
				'nowcrm_too_many_groups',
				sprintf(
					/* translators: %d: maximum number of groups. */
					__( '欄位群組最多 %d 個。', 'now-crm' ),
					self::MAX_GROUPS
				)
			);
		}

		$errors = array();
		$saved  = array();
		$seen   = array();

		foreach ( $groups as $index => $raw ) {
			if ( ! is_array( $raw ) ) {
				$errors[ $index ] = __( '群組格式不正確。', 'now-crm' );
				continue;
			}

			$title = sanitize_text_field( trim( (string) ( $raw['title'] ?? $raw['label'] ?? '' ) ) );

			if ( '' === $title ) {
				$errors[ $index ] = __( '群組缺少名稱。', 'now-crm' );
				continue;
			}

			$slug = self::normalize_group( (string) ( $raw['slug'] ?? '' ) );

			if ( '' === $slug ) {
				$slug = self::generate_group_key( $title, array_keys( $seen ) );
			}

			if ( isset( $seen[ $slug ] ) ) {
				$errors[ $index ] = sprintf(
					/* translators: %s: group key. */
					__( '群組代稱「%s」重複。', 'now-crm' ),
					$slug
				);
				continue;
			}

			$seen[ $slug ] = true;
			$saved[ $slug ] = $title;
		}

		if ( array() !== $errors ) {
			return new WP_Error(
				'nowcrm_invalid_field_groups',
				__( '欄位群組資料不正確，尚未儲存。', 'now-crm' ),
				array( 'errors' => $errors )
			);
		}

		return $saved;
	}

	/**
	 * Change one group title. The slug, and therefore every field reference,
	 * stays the same.
	 *
	 * @return array<int, array{slug: string, title: string}>|WP_Error
	 */
	public static function rename_group( string $slug, string $title ) {
		$slug  = self::normalize_group( $slug );
		$title = sanitize_text_field( trim( $title ) );

		if ( '' === $slug ) {
			return new WP_Error( 'nowcrm_invalid_group', __( '群組代稱不正確。', 'now-crm' ) );
		}

		if ( '' === $title ) {
			return new WP_Error( 'nowcrm_invalid_group', __( '請輸入群組名稱。', 'now-crm' ) );
		}

		$groups = self::stored_groups();

		if ( ! isset( $groups[ $slug ] ) ) {
			return new WP_Error( 'nowcrm_group_not_found', __( '找不到此欄位群組。', 'now-crm' ) );
		}

		$groups[ $slug ] = $title;

		self::write_groups( $groups );

		return self::groups();
	}

	/**
	 * Read the group option as slug => title, without the fallbacks.
	 *
	 * @return array<string, string>
	 */
	private static function stored_groups(): array {
		$stored = get_option( self::GROUPS_OPTION, array() );

		if ( ! is_array( $stored ) ) {
			return array();
		}

		$groups = array();

		foreach ( $stored as $slug => $title ) {
			if ( is_array( $title ) ) {
				$slug  = (string) ( $title['slug'] ?? '' );
				$title = (string) ( $title['title'] ?? '' );
			}

			$slug = self::normalize_group( (string) $slug );

			if ( '' !== $slug ) {
				$groups[ $slug ] = sanitize_text_field( (string) $title );
			}
		}

		return $groups;
	}

	/**
	 * Write the group option once, keeping its saved order.
	 *
	 * @param array<string, string> $groups Slug => title.
	 */
	private static function write_groups( array $groups ): void {
		update_option( self::GROUPS_OPTION, $groups, false );
	}

	/**
	 * Fill defaults for one stored definition, or null when it has no usable key.
	 *
	 * @param mixed $field Stored row.
	 * @return array{label: string, slug: string, type: string, options: string[], group: string}|null
	 */
	private static function present( $field ): ?array {
		if ( ! is_array( $field ) ) {
			return null;
		}

		$slug = self::normalize_key( (string) ( $field['slug'] ?? '' ) );

		if ( '' === $slug ) {
			return null;
		}

		$type = sanitize_key( (string) ( $field['type'] ?? 'text' ) );

		if ( ! isset( self::KINDS[ $type ] ) ) {
			$type = 'text';
		}

		$group = self::normalize_group( (string) ( $field['group'] ?? '' ) );

		return array(
			'label'   => sanitize_text_field( (string) ( $field['label'] ?? $slug ) ),
			'slug'    => $slug,
			'type'    => $type,
			'options' => self::normalize_options( $field['options'] ?? array() ),
			'group'   => '' !== $group ? $group : self::DEFAULT_GROUP,
		);
	}

	/**
	 * Ordered, unique, non-empty option labels.
	 *
	 * @param mixed $raw Submitted options.
	 * @return string[]
	 */
	private static function normalize_options( $raw ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$options = array();

		foreach ( $raw as $option ) {
			if ( ! is_scalar( $option ) ) {
				continue;
			}

			$option = sanitize_text_field( trim( (string) $option ) );

			if ( '' === $option || in_array( $option, $options, true ) ) {
				continue;
			}

			$options[] = $option;

			if ( count( $options ) >= self::MAX_OPTIONS ) {
				break;
			}
		}

		return $options;
	}

	/**
	 * Lowercase safe field key. Returns '' when nothing usable remains.
	 */
	private static function normalize_key( string $key ): string {
		return self::make_ascii_key( trim( $key ), self::MAX_KEY_LENGTH );
	}

	/**
	 * ASCII key from arbitrary text: spaces become underscores, other
	 * non-ASCII characters are dropped. Percent-encoded slugs from
	 * sanitize_title() are avoided so CSV headers and shortcodes stay readable.
	 */
	private static function make_ascii_key( string $text, int $max ): string {
		$text = str_replace( ' ', '_', $text );
		$text = preg_replace( '/[^A-Za-z0-9_\-]+/', '_', $text );
		$text = strtolower( trim( (string) $text, '_-' ) );
		$text = substr( $text, 0, $max );

		return trim( $text, '_-' );
	}

	/**
	 * Build a key from a label, avoiding reserved and existing keys.
	 *
	 * @param array<int, string> $taken Lowercase keys already in use.
	 */
	private static function generate_key( string $label, array $taken ): string {
		$base = self::make_ascii_key( $label, 48 );

		if ( '' === $base ) {
			$base = 'custom_field';
		}

		if ( is_numeric( $base ) || in_array( $base, self::RESERVED_KEYS, true ) ) {
			$base = 'cf_' . $base;
		}

		$taken = array_map( 'strtolower', $taken );
		$key   = $base;
		$index = 1;

		while ( in_array( $key, $taken, true ) || in_array( $key, self::RESERVED_KEYS, true ) ) {
			$key = $base . '_' . $index;
			++$index;
		}

		return $key;
	}

	/**
	 * Group slug. Group names are shown as titles, so the slug is derived.
	 */
	private static function normalize_group( string $group ): string {
		$group = trim( $group );

		if ( '' === $group ) {
			return '';
		}

		$slug = self::make_ascii_key( preg_replace( '/\s+/', '-', $group ), 64 );

		if ( '' === $slug ) {
			// Non-Latin titles keep a stable key instead of collapsing to the default group.
			$slug = 'group_' . substr( md5( $group ), 0, 8 );
		}

		return strtolower( substr( $slug, 0, 64 ) );
	}

	/**
	 * Build a group slug from its title.
	 *
	 * @param array<int, string> $taken Existing slugs.
	 */
	private static function generate_group_key( string $title, array $taken ): string {
		$base = self::normalize_group( $title );

		if ( '' === $base ) {
			$base = 'group';
		}

		$taken = array_map( 'strtolower', $taken );
		$key   = $base;
		$index = 1;

		while ( in_array( $key, $taken, true ) ) {
			$key = $base . '_' . $index;
			++$index;
		}

		return $key;
	}
}
