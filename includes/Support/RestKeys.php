<?php
/**
 * WordPress Application Password wrappers for NOW CRM API access.
 *
 * Keys are ordinary WordPress Application Passwords, so HTTP Basic auth and
 * revoke-on-password-change are handled by core. Only a user with
 * `manage_options` can mint or delete a key. The raw password is returned once
 * to the caller and is never stored.
 *
 * @package NowCRM
 */

namespace NowCRM\Support;

use WP_Error;
use WP_User;

defined( 'ABSPATH' ) || exit;

final class RestKeys {

	public const MAX_NAME = 100;

	/**
	 * Whether WordPress can issue Application Passwords on this site.
	 */
	public static function available(): bool {
		return function_exists( 'wp_is_application_passwords_available' )
			&& wp_is_application_passwords_available()
			&& class_exists( 'WP_Application_Passwords' );
	}

	/**
	 * Whether the current user may create or delete keys.
	 */
	public static function can_manage(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Users who hold at least one NOW CRM capability.
	 *
	 * @return WP_User[]
	 */
	public static function capable_users(): array {
		$candidates = get_users(
			array(
				'capability__in' => array(
					Capabilities::MANAGE_CONTACTS,
					Capabilities::MANAGE_SETTINGS,
					'manage_options',
				),
				'orderby'        => 'display_name',
				'order'          => 'ASC',
				'number'         => 200,
			)
		);

		$users = array();

		foreach ( $candidates as $user ) {
			if ( $user instanceof WP_User && self::is_capable( (int) $user->ID ) ) {
				$users[] = $user;
			}
		}

		/**
		 * Filter the users offered for API key creation.
		 *
		 * @param WP_User[] $users Capable users.
		 */
		$users = apply_filters( 'nowcrm_rest_key_users', $users );

		return array_values( array_filter( $users, static fn( $user ): bool => $user instanceof WP_User ) );
	}

	/**
	 * Whether a user has a NOW CRM capability.
	 */
	public static function is_capable( int $user_id ): bool {
		$user = $user_id > 0 ? get_user_by( 'ID', $user_id ) : false;

		if ( ! $user instanceof WP_User ) {
			return false;
		}

		return user_can( $user, Capabilities::MANAGE_CONTACTS )
			|| user_can( $user, Capabilities::MANAGE_SETTINGS )
			|| user_can( $user, 'manage_options' );
	}

	/**
	 * Safe metadata for one user's keys. Password hashes are never returned.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function keys_for( int $user_id ): array {
		if ( ! class_exists( 'WP_Application_Passwords' ) ) {
			return array();
		}

		$stored = \WP_Application_Passwords::get_user_application_passwords( $user_id );
		$keys   = array();

		foreach ( (array) $stored as $item ) {
			if ( ! is_array( $item ) || empty( $item['uuid'] ) ) {
				continue;
			}

			$keys[] = array(
				'uuid'      => (string) $item['uuid'],
				'name'      => (string) ( $item['name'] ?? '' ),
				'created'   => (int) ( $item['created'] ?? 0 ),
				'last_used' => isset( $item['last_used'] ) && null !== $item['last_used'] ? (int) $item['last_used'] : null,
				'last_ip'   => (string) ( $item['last_ip'] ?? '' ),
			);
		}

		usort(
			$keys,
			static fn( array $left, array $right ): int => $right['created'] <=> $left['created']
		);

		return $keys;
	}

	/**
	 * Capable users with their keys, ordered by display name.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function summary(): array {
		$rows = array();

		foreach ( self::capable_users() as $user ) {
			$rows[] = array(
				'id'           => (int) $user->ID,
				'user_login'   => (string) $user->user_login,
				'display_name' => (string) $user->display_name,
				'email'        => (string) $user->user_email,
				'keys'         => self::keys_for( (int) $user->ID ),
			);
		}

		return $rows;
	}

	/**
	 * Create one application password for a NOW-capable user.
	 *
	 * @param int    $user_id Target user.
	 * @param string $name    Key label.
	 * @return array{uuid: string, name: string, password: string, created: int, user_id: int}|WP_Error
	 */
	public static function create( int $user_id, string $name ) {
		if ( ! self::can_manage() ) {
			return new WP_Error( 'nowcrm_rest_keys_forbidden', __( '只有 manage_options 可以建立 API 金鑰。', 'now-crm' ) );
		}

		if ( ! self::available() ) {
			return new WP_Error( 'nowcrm_rest_keys_unavailable', __( '這個站台目前無法使用 WordPress Application Passwords。', 'now-crm' ) );
		}

		if ( ! self::is_capable( $user_id ) ) {
			return new WP_Error( 'nowcrm_rest_keys_user', __( '此使用者沒有 NOW CRM 權限。', 'now-crm' ) );
		}

		$user = get_user_by( 'ID', $user_id );

		if ( ! $user instanceof WP_User ) {
			return new WP_Error( 'nowcrm_rest_keys_user', __( '找不到此使用者。', 'now-crm' ) );
		}

		if ( ! wp_is_application_passwords_available_for_user( $user ) ) {
			return new WP_Error( 'nowcrm_rest_keys_unavailable', __( '此使用者目前無法使用 Application Passwords。', 'now-crm' ) );
		}

		$name = trim( sanitize_text_field( $name ) );

		if ( '' === $name || mb_strlen( $name, 'UTF-8' ) > self::MAX_NAME ) {
			return new WP_Error( 'nowcrm_rest_keys_name', __( '請輸入 100 字以內的金鑰名稱。', 'now-crm' ) );
		}

		$created = \WP_Application_Passwords::create_new_application_password(
			$user_id,
			array( 'name' => $name )
		);

		if ( is_wp_error( $created ) ) {
			return $created;
		}

		[ $password, $item ] = $created;

		return array(
			'uuid'     => (string) $item['uuid'],
			'name'     => (string) $item['name'],
			'password' => (string) $password,
			'created'  => (int) $item['created'],
			'user_id'  => $user_id,
		);
	}

	/**
	 * Delete one application password.
	 *
	 * @return true|WP_Error
	 */
	public static function delete( int $user_id, string $uuid ) {
		if ( ! self::can_manage() ) {
			return new WP_Error( 'nowcrm_rest_keys_forbidden', __( '只有 manage_options 可以刪除 API 金鑰。', 'now-crm' ) );
		}

		$user = get_user_by( 'ID', $user_id );

		if ( ! $user instanceof WP_User ) {
			return new WP_Error( 'nowcrm_rest_keys_user', __( '找不到此使用者。', 'now-crm' ) );
		}

		$uuid = sanitize_text_field( $uuid );

		if ( '' === $uuid ) {
			return new WP_Error( 'nowcrm_rest_keys_uuid', __( '金鑰 ID 不正確。', 'now-crm' ) );
		}

		return \WP_Application_Passwords::delete_application_password( $user_id, $uuid );
	}
}
