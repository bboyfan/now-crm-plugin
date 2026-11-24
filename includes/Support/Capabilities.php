<?php
/**
 * Capability constants and role wiring.
 *
 * @package NowCRM
 */

namespace NowCRM\Support;

defined( 'ABSPATH' ) || exit;

final class Capabilities {

	public const MANAGE_CONTACTS = 'nowcrm_manage_contacts';
	public const MANAGE_SETTINGS = 'nowcrm_manage_settings';

	/**
	 * Capabilities granted by default, mapped to the roles receiving them.
	 *
	 * @return array<string, string[]>
	 */
	public static function map(): array {
		$map = array(
			'administrator' => array( self::MANAGE_CONTACTS, self::MANAGE_SETTINGS ),
		);

		return (array) apply_filters( 'nowcrm_capabilities', $map );
	}

	/**
	 * Grant capabilities to roles. Safe to run repeatedly.
	 */
	public static function grant(): void {
		foreach ( self::map() as $role_name => $caps ) {
			$role = get_role( $role_name );

			if ( ! $role ) {
				continue;
			}

			foreach ( (array) $caps as $cap ) {
				if ( ! $role->has_cap( $cap ) ) {
					$role->add_cap( $cap );
				}
			}
		}
	}

	/**
	 * Current user may manage contacts.
	 */
	public static function can_manage_contacts(): bool {
		return current_user_can( self::MANAGE_CONTACTS );
	}

	/**
	 * Current user may change plugin settings.
	 */
	public static function can_manage_settings(): bool {
		return current_user_can( self::MANAGE_SETTINGS );
	}
}
