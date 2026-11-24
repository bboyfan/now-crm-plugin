<?php
/**
 * Consent-layer configuration.
 *
 * Global settings live in the `nowcrm_consent` option; per-list overrides live
 * in `nowcrm_consent_lists` keyed by term ID. NOW lists are rows in the custom
 * `nowcrm_terms` table, not WordPress taxonomy terms, so `get_term_meta()` can
 * never apply to them — the keyed option is the term-meta equivalent and needs
 * no schema change. Pending double opt-in state lives in
 * `nowcrm_consent_pending` keyed by contact ID.
 *
 * SEAM: `Admin\ConsentSettings` renders and saves this; `Admin\SettingsPage`
 * should link to it and `Admin\Menu::order_submenu()` should place its slug.
 *
 * @package NowCRM
 */

namespace NowCRM\Consent;

use NowCRM\Email\EmailRenderer;
use NowCRM\Terms\TermRepository;
use WP_Error;

defined( 'ABSPATH' ) || exit;

final class Config {

	public const OPTION         = 'nowcrm_consent';
	public const LISTS_OPTION   = 'nowcrm_consent_lists';
	public const PENDING_OPTION = 'nowcrm_consent_pending';

	public const MODE_ALL      = 'all';
	public const MODE_NONE     = 'none';
	public const MODE_SELECTED = 'selected';

	public const LIST_INHERIT = 'inherit';
	public const LIST_REQUIRE = 'require';
	public const LIST_SKIP    = 'skip';

	/**
	 * Canonical defaults. Everything is off or empty until an operator opts in,
	 * except the manage-token lifetime and the request throttle.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			'double_optin_enabled' => false,
			'double_optin'         => array(
				'email_subject'          => __( '請確認您的訂閱', 'now-crm' ),
				'email_preheader'        => '',
				'email_body'             => self::default_body(),
				'after_confirm_message'  => self::default_after_message(),
				'resend_throttle_minutes' => 5,
			),
			'public_lists_mode'              => self::MODE_NONE,
			'public_list_ids'                => array(),
			'footer_text'                    => '',
			'manage_token_days'              => 90,
			'confirm_token_days'             => 7,
			'email_change_token_days'        => 2,
			'public_request_throttle_minutes' => 5,
		);
	}

	/**
	 * All settings, defaults merged over the stored values.
	 *
	 * @return array<string, mixed>
	 */
	public static function all(): array {
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();

		$settings = self::defaults();

		foreach ( $settings as $key => $default ) {
			if ( ! array_key_exists( $key, $stored ) ) {
				continue;
			}

			if ( 'double_optin' === $key ) {
				$settings[ $key ] = self::merge_double_optin( is_array( $stored[ $key ] ) ? $stored[ $key ] : array() );
				continue;
			}

			$settings[ $key ] = self::cast( $key, $default, $stored[ $key ] );
		}

		return $settings;
	}

	/**
	 * Single setting value.
	 *
	 * @param mixed $default Fallback when the key is unknown.
	 * @return mixed
	 */
	public static function get( string $key, $default = null ) {
		$all = self::all();

		return array_key_exists( $key, $all ) ? $all[ $key ] : $default;
	}

	/**
	 * Merge a patch into the settings. Unknown keys are ignored.
	 *
	 * @param array<string, mixed> $patch Partial settings.
	 * @return array<string, mixed>
	 */
	public static function update( array $patch ): array {
		$settings = self::all();

		foreach ( $patch as $key => $value ) {
			if ( ! array_key_exists( $key, self::defaults() ) ) {
				continue;
			}

			if ( 'double_optin' === $key && is_array( $value ) ) {
				$settings[ $key ] = self::merge_double_optin( $value );
				continue;
			}

			$settings[ $key ] = self::cast( $key, self::defaults()[ $key ], $value );
		}

		update_option( self::OPTION, $settings, false );

		return $settings;
	}

	/**
	 * Restore defaults.
	 *
	 * @return array<string, mixed>
	 */
	public static function reset(): array {
		update_option( self::OPTION, self::defaults(), false );

		return self::defaults();
	}

	/**
	 * Public list IDs: every list, none, or the selected set.
	 *
	 * @return int[]
	 */
	public static function public_list_ids(): array {
		$all  = self::all();
		$mode = (string) ( $all['public_lists_mode'] ?? self::MODE_NONE );

		if ( self::MODE_NONE === $mode ) {
			return array();
		}

		if ( self::MODE_SELECTED === $mode ) {
			$ids = array();

			foreach ( (array) ( $all['public_list_ids'] ?? array() ) as $id ) {
				$id = (int) $id;

				if ( $id > 0 ) {
					$ids[] = $id;
				}
			}

			return array_values( array_unique( $ids ) );
		}

		$ids = array();

		foreach ( ( new TermRepository() )->all( TermRepository::TYPE_LIST ) as $term ) {
			$ids[] = (int) $term['id'];
		}

		return $ids;
	}

	/**
	 * Whether a list is visible in the public preference center.
	 */
	public static function is_list_public( int $term_id ): bool {
		return in_array( $term_id, self::public_list_ids(), true );
	}

	/**
	 * Per-list override merged over the global double opt-in settings.
	 *
	 * @return array{mode: string, email_subject: string, email_preheader: string, email_body: string, after_confirm_message: string, resend_throttle_minutes: int}
	 */
	public static function list_config( int $term_id ): array {
		$global = self::all()['double_optin'];
		$stored = get_option( self::LISTS_OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();
		$override = isset( $stored[ $term_id ] ) && is_array( $stored[ $term_id ] ) ? $stored[ $term_id ] : array();

		$mode = (string) ( $override['mode'] ?? self::LIST_INHERIT );

		if ( ! in_array( $mode, array( self::LIST_INHERIT, self::LIST_REQUIRE, self::LIST_SKIP ), true ) ) {
			$mode = self::LIST_INHERIT;
		}

		return array(
			'mode'                    => $mode,
			'email_subject'           => isset( $override['email_subject'] ) && '' !== trim( (string) $override['email_subject'] )
				? sanitize_text_field( (string) $override['email_subject'] )
				: (string) $global['email_subject'],
			'email_preheader'         => isset( $override['email_preheader'] ) ? sanitize_text_field( (string) $override['email_preheader'] ) : (string) $global['email_preheader'],
			'email_body'              => isset( $override['email_body'] ) && '' !== trim( (string) $override['email_body'] )
				? (string) $override['email_body']
				: (string) $global['email_body'],
			'after_confirm_message'   => isset( $override['after_confirm_message'] ) && '' !== trim( (string) $override['after_confirm_message'] )
				? (string) $override['after_confirm_message']
				: (string) $global['after_confirm_message'],
			'resend_throttle_minutes' => (int) $global['resend_throttle_minutes'],
		);
	}

	/**
	 * Save a per-list override. Unknown keys are ignored.
	 *
	 * @param array<string, mixed> $patch mode, email_subject, email_preheader, email_body, after_confirm_message.
	 * @return array<string, mixed>
	 */
	public static function set_list_config( int $term_id, array $patch ) {
		if ( $term_id <= 0 ) {
			return new WP_Error( 'nowcrm_invalid_term', __( '名單不正確。', 'now-crm' ) );
		}

		$stored = get_option( self::LISTS_OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();

		$current = isset( $stored[ $term_id ] ) && is_array( $stored[ $term_id ] ) ? $stored[ $term_id ] : array();

		if ( array_key_exists( 'mode', $patch ) ) {
			$mode = (string) $patch['mode'];
			$current['mode'] = in_array( $mode, array( self::LIST_INHERIT, self::LIST_REQUIRE, self::LIST_SKIP ), true )
				? $mode
				: self::LIST_INHERIT;
		}

		foreach ( array( 'email_subject', 'email_preheader' ) as $key ) {
			if ( array_key_exists( $key, $patch ) ) {
				$current[ $key ] = sanitize_text_field( (string) $patch[ $key ] );
			}
		}

		foreach ( array( 'email_body', 'after_confirm_message' ) as $key ) {
			if ( array_key_exists( $key, $patch ) ) {
				$current[ $key ] = ( new EmailRenderer() )->sanitize_raw_html( (string) $patch[ $key ] );
			}
		}

		$stored[ $term_id ] = $current;
		update_option( self::LISTS_OPTION, $stored, false );

		return self::list_config( $term_id );
	}

	/**
	 * Drop a per-list override.
	 */
	public static function clear_list_config( int $term_id ): void {
		$stored = get_option( self::LISTS_OPTION, array() );

		if ( is_array( $stored ) && array_key_exists( $term_id, $stored ) ) {
			unset( $stored[ $term_id ] );
			update_option( self::LISTS_OPTION, $stored, false );
		}
	}

	/**
	 * Whether double opt-in is required for a set of lists. Any list set to
	 * `require` wins; `skip` only opts out when nothing requires and the global
	 * switch is off.
	 *
	 * @param int[] $list_ids
	 */
	public static function is_double_optin_required( array $list_ids ): bool {
		$list_ids = array_values( array_unique( array_map( 'intval', $list_ids ) ) );
		$global   = (bool) self::all()['double_optin_enabled'];

		foreach ( $list_ids as $id ) {
			if ( $id <= 0 ) {
				continue;
			}

			$mode = self::list_config( $id )['mode'];

			if ( self::LIST_REQUIRE === $mode ) {
				return true;
			}
		}

		if ( $global ) {
			foreach ( $list_ids as $id ) {
				if ( $id > 0 && self::LIST_SKIP === self::list_config( $id )['mode'] ) {
					return false;
				}
			}

			return true;
		}

		return false;
	}

	/**
	 * Pending double opt-in state for a contact, if any.
	 *
	 * @return array{email: string, lists: int[], created: int, last_sent: int}|null
	 */
	public static function pending_get( int $contact_id ): ?array {
		$stored = get_option( self::PENDING_OPTION, array() );

		if ( ! is_array( $stored ) || ! isset( $stored[ $contact_id ] ) || ! is_array( $stored[ $contact_id ] ) ) {
			return null;
		}

		$record = $stored[ $contact_id ];

		if ( ! isset( $record['email'] ) || ! is_email( (string) $record['email'] ) ) {
			return null;
		}

		return array(
			'email'     => Tokens::normalize_email( (string) $record['email'] ),
			'lists'     => array_values( array_unique( array_map( 'intval', (array) ( $record['lists'] ?? array() ) ) ) ),
			'created'   => (int) ( $record['created'] ?? 0 ),
			'last_sent' => (int) ( $record['last_sent'] ?? 0 ),
		);
	}

	/**
	 * Record pending double opt-in state for a contact.
	 *
	 * @param int[] $list_ids
	 */
	public static function pending_set( int $contact_id, string $email, array $list_ids ): void {
		$stored = get_option( self::PENDING_OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();

		$previous = isset( $stored[ $contact_id ] ) && is_array( $stored[ $contact_id ] ) ? $stored[ $contact_id ] : array();

		$stored[ $contact_id ] = array(
			'email'     => Tokens::normalize_email( $email ),
			'lists'     => array_values( array_unique( array_map( 'intval', $list_ids ) ) ),
			'created'   => (int) ( $previous['created'] ?? time() ),
			'last_sent' => time(),
		);

		update_option( self::PENDING_OPTION, $stored, false );
	}

	/**
	 * Clear pending double opt-in state for a contact.
	 */
	public static function pending_clear( int $contact_id ): void {
		$stored = get_option( self::PENDING_OPTION, array() );

		if ( is_array( $stored ) && array_key_exists( $contact_id, $stored ) ) {
			unset( $stored[ $contact_id ] );
			update_option( self::PENDING_OPTION, $stored, false );
		}
	}

	/**
	 * Merge stored double opt-in content over the defaults.
	 *
	 * @param array<string, mixed> $stored
	 * @return array<string, mixed>
	 */
	private static function merge_double_optin( array $stored ): array {
		$defaults = self::defaults()['double_optin'];
		$merged   = $defaults;

		foreach ( array( 'email_subject', 'email_preheader' ) as $key ) {
			if ( array_key_exists( $key, $stored ) ) {
				$merged[ $key ] = sanitize_text_field( (string) $stored[ $key ] );
			}
		}

		foreach ( array( 'email_body', 'after_confirm_message' ) as $key ) {
			if ( array_key_exists( $key, $stored ) ) {
				$merged[ $key ] = ( new EmailRenderer() )->sanitize_raw_html( (string) $stored[ $key ] );
			}
		}

		if ( array_key_exists( 'resend_throttle_minutes', $stored ) ) {
			$merged['resend_throttle_minutes'] = max( 1, min( 1440, (int) $stored['resend_throttle_minutes'] ) );
		}

		if ( '' === trim( (string) $merged['email_subject'] ) ) {
			$merged['email_subject'] = (string) $defaults['email_subject'];
		}

		if ( '' === trim( (string) $merged['email_body'] ) ) {
			$merged['email_body'] = (string) $defaults['email_body'];
		}

		if ( '' === trim( (string) $merged['after_confirm_message'] ) ) {
			$merged['after_confirm_message'] = (string) $defaults['after_confirm_message'];
		}

		return $merged;
	}

	/**
	 * Cast one top-level value using the default's type.
	 *
	 * @param mixed $value Raw value.
	 * @return mixed
	 */
	private static function cast( string $key, $default, $value ) {
		if ( is_bool( $default ) ) {
			if ( is_string( $value ) ) {
				return (bool) filter_var( $value, FILTER_VALIDATE_BOOLEAN );
			}

			return (bool) $value;
		}

		if ( is_int( $default ) ) {
			$int = (int) $value;

			$min = 0;
			$max = 3650;

			if ( 'resend_throttle_minutes' === $key || 'public_request_throttle_minutes' === $key ) {
				$min = 1;
				$max = 1440;
			} elseif ( str_ends_with( $key, '_days' ) ) {
				$min = 1;
			}

			return max( $min, min( $max, $int ) );
		}

		if ( 'public_lists_mode' === $key ) {
			$value = (string) $value;

			return in_array( $value, array( self::MODE_ALL, self::MODE_NONE, self::MODE_SELECTED ), true )
				? $value
				: self::MODE_NONE;
		}

		if ( 'public_list_ids' === $key ) {
			$ids = array();

			foreach ( (array) $value as $id ) {
				$id = (int) $id;

				if ( $id > 0 ) {
					$ids[] = $id;
				}
			}

			return array_values( array_unique( $ids ) );
		}

		return sanitize_textarea_field( (string) $value );
	}

	private static function default_body(): string {
		return '<h2>' . esc_html__( '請確認您的訂閱', 'now-crm' ) . '</h2>'
			. '<p>' . esc_html__( '感謝您的訂閱，請點擊下方按鈕完成確認：', 'now-crm' ) . '</p>'
			. '<p><a href="{{confirm_url}}">' . esc_html__( '確認訂閱', 'now-crm' ) . '</a></p>'
			. '<p>' . esc_html__( '如果這不是您本人操作，請忽略此信。', 'now-crm' ) . '</p>';
	}

	private static function default_after_message(): string {
		return '<h2>' . esc_html__( '訂閱已確認', 'now-crm' ) . '</h2>'
			. '<p>' . esc_html__( '感謝您的確認，歡迎加入。', 'now-crm' ) . '</p>';
	}
}
