<?php
/**
 * Multisite isolation: nothing may leak outside the allowed site IDs.
 *
 * @package NowCRM
 */

use NowCRM\Support\Multisite;
use NowCRM\Support\Schema;

/**
 * @covers \NowCRM\Support\Multisite
 */
final class MultisiteTest extends NowCrmTestCase {

	public function test_single_site_installs_are_supported(): void {
		if ( is_multisite() ) {
			$this->markTestSkipped( 'Single-site assertion; the multisite run covers the other branch.' );
		}

		$this->assertTrue( Multisite::is_supported() );
	}

	public function test_other_sites_are_not_supported(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Requires the multisite test run (tests/bin/run-tests.sh --multisite).' );
		}

		$this->assertTrue( Multisite::is_supported(), 'Site 1 must stay supported in the network.' );

		$blog_id = self::factory()->blog->create();

		switch_to_blog( $blog_id );

		try {
			$this->assertFalse( Multisite::is_supported() );
			$this->assertFalse( Schema::maybe_upgrade(), 'No migration may run on a foreign site.' );

			global $wpdb;

			$table = $wpdb->prefix . 'nowcrm_contacts';
			$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );

			$this->assertNotSame( $table, $found, 'A foreign site must not receive CRM tables.' );
		} finally {
			restore_current_blog();
		}
	}

	public function test_allowed_site_ids_are_filterable(): void {
		add_filter(
			'nowcrm_allowed_site_ids',
			static function ( array $ids ): array {
				$ids[] = 99;

				return $ids;
			}
		);

		$this->assertContains( 99, Multisite::allowed_site_ids() );
		$this->assertContains( 1, Multisite::allowed_site_ids() );
	}
}
