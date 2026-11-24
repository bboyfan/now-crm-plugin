<?php
/**
 * Form field definitions, normalization, and per-type validation.
 *
 * @package NowCRM
 */

namespace NowCRM\Forms;

use WP_Error;

defined( 'ABSPATH' ) || exit;

final class Fields {

	public const TEXT     = 'text';
	public const EMAIL    = 'email';
	public const TEXTAREA = 'textarea';
	public const SELECT   = 'select';
	public const RADIO    = 'radio';
	public const CHECKBOX = 'checkbox';
	public const NUMBER   = 'number';
	public const DATE     = 'date';

	/**
	 * Supported field types.
	 *
	 * @var string[]
	 */
	public const TYPES = array(
		self::TEXT,
		self::EMAIL,
		self::TEXTAREA,
		self::SELECT,
		self::RADIO,
		self::CHECKBOX,
		self::NUMBER,
		self::DATE,
	);

	/**
	 * Hard bounds that keep one form editable by hand.
	 */
	private const MAX_FIELDS  = 100;
	private const MAX_OPTIONS = 200;

	/**
	 * Human labels for the editor and fallback field names.
	 *
	 * @return array<string, string>
	 */
	public static function labels(): array {
		return array(
			self::TEXT     => __( '單行文字', 'now-crm' ),
			self::EMAIL    => __( 'Email', 'now-crm' ),
			self::TEXTAREA => __( '多行文字', 'now-crm' ),
			self::SELECT   => __( '下拉選單', 'now-crm' ),
			self::RADIO    => __( '單選', 'now-crm' ),
			self::CHECKBOX => __( '核取方塊（可多選）', 'now-crm' ),
			self::NUMBER   => __( '數字', 'now-crm' ),
			self::DATE     => __( '日期', 'now-crm' ),
		);
	}

	/**
	 * Normalize editor rows or stored definitions into a stable field list.
	 *
	 * Keys are sanitized and unique. Blank labels fall back to a type label.
	 * Options are kept only for select, radio, and checkbox fields. Rows marked
	 * for deletion are dropped. Array order is the field order.
	 *
	 * @param array<int|string, mixed> $rows Raw rows.
	 * @return array<int, array{key: string, label: string, type: string, required: bool, options: string[]}>
	 */
	public static function normalize_definitions( array $rows ): array {
		$fields = array();
		$labels = self::labels();

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || count( $fields ) >= self::MAX_FIELDS ) {
				continue;
			}

			if ( ! empty( $row['delete'] ) ) {
				continue;
			}

			$type = isset( $row['type'] ) && is_string( $row['type'] )
				? strtolower( trim( $row['type'] ) )
				: self::TEXT;

			if ( ! in_array( $type, self::TYPES, true ) ) {
				$type = self::TEXT;
			}

			$position = count( $fields ) + 1;
			$raw_key  = isset( $row['key'] ) && is_string( $row['key'] ) ? sanitize_key( $row['key'] ) : '';

			if ( '' === $raw_key ) {
				$raw_key = 'field_' . $position;
			}

			$label = isset( $row['label'] ) && is_string( $row['label'] )
				? sanitize_text_field( $row['label'] )
				: '';

			if ( '' === $label ) {
				$label = $labels[ $type ] . ' ' . $position;
			}

			$options = in_array( $type, array( self::SELECT, self::RADIO, self::CHECKBOX ), true )
				? self::parse_options( $row['options'] ?? array() )
				: array();

			$fields[] = array(
				'key'      => self::unique_key( $raw_key, $fields ),
				'label'    => $label,
				'type'     => $type,
				'required' => ! empty( $row['required'] ),
				'options'  => $options,
			);
		}

		return $fields;
	}

	/**
	 * Validate raw submitted values against stored definitions.
	 *
	 * Values are sanitized per type. Unknown values for select, radio, and
	 * checkbox fields are rejected. Every definition produces a value so the
	 * entry keeps the full field set, including empty optional answers.
	 *
	 * @param array<int, array<string, mixed>> $definitions Stored field definitions.
	 * @param array<string, mixed>             $raw         Raw submitted map keyed by field key.
	 * @return array<string, mixed>|WP_Error Sanitized values keyed by field key.
	 */
	public static function validate( array $definitions, array $raw ) {
		$values = array();
		$errors = array();

		foreach ( $definitions as $definition ) {
			if ( ! is_array( $definition ) ) {
				continue;
			}

			$key = isset( $definition['key'] ) && is_string( $definition['key'] ) ? sanitize_key( $definition['key'] ) : '';

			if ( '' === $key ) {
				continue;
			}

			$type   = isset( $definition['type'] ) && is_string( $definition['type'] ) ? $definition['type'] : self::TEXT;
			$label  = isset( $definition['label'] ) && is_string( $definition['label'] ) ? $definition['label'] : $key;
			$needed = ! empty( $definition['required'] );
			$option = array_values(
				array_filter(
					array_map( 'strval', (array) ( $definition['options'] ?? array() ) ),
					static fn( string $value ): bool => '' !== $value
				)
			);
			$input  = $raw[ $key ] ?? null;

			switch ( $type ) {
				case self::CHECKBOX:
					$candidates = is_array( $input )
						? $input
						: ( is_scalar( $input ) && '' !== (string) $input ? array( $input ) : array() );

					$selected = array();
					$invalid  = false;

					foreach ( $candidates as $candidate ) {
						if ( ! is_scalar( $candidate ) ) {
							$invalid = true;
							break;
						}

						$candidate = sanitize_text_field( trim( (string) $candidate ) );

						if ( '' === $candidate ) {
							continue;
						}

						if ( ! in_array( $candidate, $option, true ) ) {
							$invalid = true;
							break;
						}

						if ( ! in_array( $candidate, $selected, true ) ) {
							$selected[] = $candidate;
						}
					}

					if ( $invalid ) {
						$errors[ $key ] = sprintf(
							/* translators: %s: field label. */
							__( '%s 的選項不正確。', 'now-crm' ),
							$label
						);
					} elseif ( $needed && array() === $selected ) {
						$errors[ $key ] = sprintf(
							/* translators: %s: field label. */
							__( '%s 為必填。', 'now-crm' ),
							$label
						);
					}

					$values[ $key ] = $selected;
					continue 2;

				case self::SELECT:
				case self::RADIO:
					$value = is_scalar( $input ) ? sanitize_text_field( trim( (string) $input ) ) : '';

					if ( '' !== $value && ! in_array( $value, $option, true ) ) {
						$errors[ $key ] = sprintf(
							/* translators: %s: field label. */
							__( '%s 的選項不正確。', 'now-crm' ),
							$label
						);
					} elseif ( $needed && '' === $value ) {
						$errors[ $key ] = sprintf(
							/* translators: %s: field label. */
							__( '%s 為必填。', 'now-crm' ),
							$label
						);
					}

					$values[ $key ] = $value;
					continue 2;

				case self::EMAIL:
					$candidate = is_scalar( $input ) ? trim( (string) $input ) : '';
					$value     = sanitize_email( $candidate );

					if ( '' !== $candidate && ( '' === $value || ! is_email( $value ) ) ) {
						$errors[ $key ] = sprintf(
							/* translators: %s: field label. */
							__( '%s 的 Email 格式不正確。', 'now-crm' ),
							$label
						);
					} elseif ( $needed && '' === $value ) {
						$errors[ $key ] = sprintf(
							/* translators: %s: field label. */
							__( '%s 為必填。', 'now-crm' ),
							$label
						);
					}

					$values[ $key ] = $value;
					continue 2;

				case self::NUMBER:
					$value = is_scalar( $input ) ? trim( (string) $input ) : '';

					if ( '' !== $value && ! is_numeric( $value ) ) {
						$errors[ $key ] = sprintf(
							/* translators: %s: field label. */
							__( '%s 必須是數字。', 'now-crm' ),
							$label
						);
					} elseif ( $needed && '' === $value ) {
						$errors[ $key ] = sprintf(
							/* translators: %s: field label. */
							__( '%s 為必填。', 'now-crm' ),
							$label
						);
					}

					$values[ $key ] = $value;
					continue 2;

				case self::DATE:
					$value = is_scalar( $input ) ? trim( (string) $input ) : '';

					if ( '' !== $value && ! self::is_date( $value ) ) {
						$errors[ $key ] = sprintf(
							/* translators: %s: field label. */
							__( '%s 的日期必須是 YYYY-MM-DD。', 'now-crm' ),
							$label
						);
					} elseif ( $needed && '' === $value ) {
						$errors[ $key ] = sprintf(
							/* translators: %s: field label. */
							__( '%s 為必填。', 'now-crm' ),
							$label
						);
					}

					$values[ $key ] = $value;
					continue 2;

				default:
					$value = is_scalar( $input ) ? (string) $input : '';
					$value = self::TEXTAREA === $type
						? sanitize_textarea_field( $value )
						: sanitize_text_field( $value );

					if ( $needed && '' === trim( $value ) ) {
						$errors[ $key ] = sprintf(
							/* translators: %s: field label. */
							__( '%s 為必填。', 'now-crm' ),
							$label
						);
					}

					$values[ $key ] = $value;
			}
		}

		if ( array() !== $errors ) {
			return new WP_Error(
				'nowcrm_form_invalid',
				__( '表單資料不正確，請檢查標示的欄位。', 'now-crm' ),
				array(
					'field_errors' => $errors,
					'values'       => $values,
				)
			);
		}

		return $values;
	}

	/**
	 * Key of the contact email field, or an empty string.
	 *
	 * @param array<int, array<string, mixed>> $definitions Field definitions.
	 */
	public static function email_key( array $definitions ): string {
		foreach ( $definitions as $definition ) {
			if ( is_array( $definition ) && self::EMAIL === ( $definition['type'] ?? '' ) ) {
				return (string) ( $definition['key'] ?? '' );
			}
		}

		return '';
	}

	/**
	 * Count email fields.
	 *
	 * @param array<int, array<string, mixed>> $definitions Field definitions.
	 */
	public static function email_count( array $definitions ): int {
		$count = 0;

		foreach ( $definitions as $definition ) {
			if ( is_array( $definition ) && self::EMAIL === ( $definition['type'] ?? '' ) ) {
				++$count;
			}
		}

		return $count;
	}

	/**
	 * Parse one option per line (or an array of options).
	 *
	 * @param mixed $raw Raw options.
	 * @return string[]
	 */
	private static function parse_options( $raw ): array {
		$lines = is_array( $raw ) ? $raw : preg_split( '/\r\n|\r|\n/', (string) $raw );
		$lines = is_array( $lines ) ? $lines : array();
		$clean = array();

		foreach ( $lines as $line ) {
			if ( ! is_scalar( $line ) ) {
				continue;
			}

			$option = sanitize_text_field( trim( (string) $line ) );

			if ( '' === $option || in_array( $option, $clean, true ) ) {
				continue;
			}

			$clean[] = $option;

			if ( count( $clean ) >= self::MAX_OPTIONS ) {
				break;
			}
		}

		return $clean;
	}

	/**
	 * Keep keys unique without changing an existing key.
	 *
	 * @param string                              $key    Candidate key.
	 * @param array<int, array<string, mixed>>    $fields Fields normalized so far.
	 */
	private static function unique_key( string $key, array $fields ): string {
		$key  = substr( $key, 0, 64 );
		$used = array_column( $fields, 'key' );

		if ( ! in_array( $key, $used, true ) ) {
			return $key;
		}

		$base = substr( $key, 0, 58 );
		$next = 2;

		while ( in_array( $key, $used, true ) ) {
			$key = $base . '_' . $next;
			++$next;
		}

		return $key;
	}

	/**
	 * Strict YYYY-MM-DD validation.
	 */
	private static function is_date( string $value ): bool {
		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $value, $matches ) ) {
			return false;
		}

		return checkdate( (int) $matches[2], (int) $matches[3], (int) $matches[1] );
	}
}
