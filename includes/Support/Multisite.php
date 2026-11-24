<?php
/**
 * Site scope guard. NOW CRM only operates on the allowed site IDs.
 *
 * @package NowCRM
 */

namespace NowCRM\Support;

defined( 'ABSPATH' ) || exit;

final class Multisite {

	/**
	 * Site IDs this plugin is allowed to operate on.
	 *
	 * On a Multisite network the plugin runs only on the IDs returned by the
	 * `nowcrm_allowed_site_ids` filter. The default is the main site (ID 1), so
	 * other sites never create or read CRM data.
	 *
	 * @return int[]
	 */
	public static function allowed_site_ids(): array {
		$ids = (array) apply_filters( 'nowcrm_allowed_site_ids', array( 1 ) );

		return array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
	}

	/**
	 * Whether the current site may run CRM code.
	 */
	public static function is_supported(): bool {
		if ( ! is_multisite() ) {
			return true;
		}

		return in_array( (int) get_current_blog_id(), self::allowed_site_ids(), true );
	}

	/**
	 * Abort CRM runtime on unsupported sites, leaving an admin notice behind.
	 */
	public static function guard(): void {
		if ( self::is_supported() ) {
			return;
		}

		if ( is_admin() ) {
			add_action(
				'admin_notices',
				static function (): void {
					printf(
						'<div class="notice notice-warning"><p>%s</p></div>',
						esc_html__( 'NOW CRM 僅在指定的站台（site ID 1）運作，此站台不會建立或讀取任何 CRM 資料。', 'now-crm' )
					);
				}
			);
		}
	}
}
