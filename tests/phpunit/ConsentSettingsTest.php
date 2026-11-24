<?php
/**
 * Consent settings defaults and authorization.
 *
 * @package NowCRM
 */

use NowCRM\Admin\ConsentSettings;
use NowCRM\Consent\Config;
use NowCRM\Support\Capabilities;

/**
 * @covers \NowCRM\Admin\ConsentSettings
 * @covers \NowCRM\Consent\Config
 */
final class ConsentSettingsTest extends NowCrmTestCase {

	public function set_up(): void {
		parent::set_up();

		Config::reset();
	}

	public function tear_down(): void {
		$_GET     = array();
		$_POST    = array();
		$_REQUEST = array();
		wp_set_current_user( 0 );

		parent::tear_down();
	}

	public function test_unconfigured_public_lists_are_not_public(): void {
		$this->assertSame( Config::MODE_NONE, Config::all()['public_lists_mode'] );
		$this->assertSame( array(), Config::public_list_ids() );
	}

	public function test_malformed_public_mode_is_saved_as_none_and_explicit_all_is_preserved(): void {
		Config::update( array( 'public_lists_mode' => 'malformed' ) );
		$this->assertSame( Config::MODE_NONE, Config::all()['public_lists_mode'] );

		Config::update( array( 'public_lists_mode' => 'all' ) );
		$this->assertSame( Config::MODE_ALL, Config::all()['public_lists_mode'] );

		Config::update( array( 'public_lists_mode' => 'selected', 'public_list_ids' => array( 12 ) ) );
		$this->assertSame( Config::MODE_SELECTED, Config::all()['public_lists_mode'] );
	}

	public function test_screen_marks_none_as_default_and_explains_public_scope(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		Capabilities::grant();

		$html = $this->render_screen();

		$this->assertMatchesRegularExpression( '/value="none"[^>]*checked/', $html );
		$this->assertStringContainsString( '不會改變聯絡人的訂閱狀態', $html );
	}

	public function test_unauthorized_render_is_denied(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$this->assertDenied( static function (): void {
			( new ConsentSettings() )->render();
		} );
	}

	public function test_unauthorized_save_is_denied_and_writes_nothing(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$_POST = array(
			'_wpnonce'          => wp_create_nonce( 'nowcrm_save_consent' ),
			'public_lists_mode' => 'all',
			'footer_text'       => 'Changed',
		);

		$before = Config::all();

		$this->assertDenied( static function (): void {
			( new ConsentSettings() )->save();
		} );

		$this->assertSame( $before, Config::all() );
	}

	private function render_screen(): string {
		ob_start();

		try {
			( new ConsentSettings() )->render();
		} finally {
			$html = (string) ob_get_clean();
		}

		return $html;
	}

	/**
	 * Assert a handler calls wp_die().
	 */
	private function assertDenied( callable $handler ): void {
		$filter = static function () {
			throw new WPDieException( 'denied' );
		};

		add_filter( 'wp_die_handler', $filter, 99 );

		try {
			$handler();
			$this->fail( 'Expected wp_die().' );
		} catch ( WPDieException $exception ) {
			$this->assertSame( 'denied', $exception->getMessage() );
		} finally {
			remove_filter( 'wp_die_handler', $filter, 99 );
		}
	}
}
