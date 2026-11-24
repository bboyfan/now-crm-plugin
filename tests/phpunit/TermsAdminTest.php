<?php
/**
 * Lists and tags admin screen tests.
 *
 * @package NowCRM
 */

use NowCRM\Admin\TermsPage;
use NowCRM\Contacts\Status;

/**
 * @covers \NowCRM\Admin\TermsPage
 */
final class TermsAdminTest extends NowCrmTestCase {

	private int $admin = 0;

	public function set_up(): void {
		parent::set_up();

		$this->admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin );

		$_GET = array();
	}

	public function tear_down(): void {
		$_GET = array();

		parent::tear_down();
	}

	/**
	 * Render one term screen and return its HTML.
	 *
	 * @param array<string, mixed> $query Query arguments.
	 */
	private function render_screen( string $type, array $query = array() ): string {
		$_GET = array_merge(
			array( 'page' => 'tag' === $type ? 'nowcrm-tags' : 'nowcrm-lists' ),
			$query
		);

		ob_start();
		TermsPage::render( $type );

		return (string) ob_get_clean();
	}

	public function test_lists_default_to_id_descending_with_ten_rows_and_count_links(): void {
		$ids = array();

		for ( $index = 1; $index <= 12; $index++ ) {
			$ids[] = $this->terms->create( 'list', 'List ' . $index );
		}

		$member = $this->make_contact();
		$gone   = $this->make_contact( array( 'status' => Status::UNSUBSCRIBED ) );

		$this->terms->attach( $member, $ids[11] );
		$this->terms->attach( $gone, $ids[11] );

		$html = $this->render_screen( 'list' );

		$newest = strpos( $html, 'value="' . $ids[11] . '"' );
		$next   = strpos( $html, 'value="' . $ids[10] . '"' );

		$this->assertNotFalse( $newest );
		$this->assertNotFalse( $next );
		$this->assertSame( 10, substr_count( $html, 'data-nowcrm-terms-row-check' ) );
		$this->assertLessThan( $next, $newest );
		$this->assertStringNotContainsString( 'value="' . $ids[1] . '"', $html );
		$this->assertStringContainsString( '共 12 筆', $html );
		$this->assertStringContainsString( '已訂閱 1 位聯絡人', $html );
		$this->assertStringContainsString( '共 2 位聯絡人', $html );
		$this->assertStringContainsString( 'status=subscribed', $html );
		$this->assertStringContainsString( 'term_id=' . $ids[11], $html );
	}

	public function test_cross_type_edit_does_not_open_the_edit_dialog(): void {
		$tag = $this->terms->create( 'tag', 'Tag Only' );

		$html = $this->render_screen( 'list', array( 'edit' => (string) $tag ) );

		$this->assertStringContainsString( 'data-mode="create"', $html );
		$this->assertStringNotContainsString( 'data-mode="edit"', $html );
		$this->assertStringContainsString( '找不到資料，可能已被刪除。', $html );
	}

	public function test_empty_states_distinguish_first_use_from_no_search_match(): void {
		$initial = $this->render_screen( 'tag' );

		$this->assertStringContainsString( '尚未建立任何標籤。', $initial );
		$this->assertStringContainsString( 'data-nowcrm-open-create', $initial );

		$filtered = $this->render_screen( 'tag', array( 's' => 'missing-term' ) );

		$this->assertStringContainsString( '沒有符合「missing-term」的項目。', $filtered );
		$this->assertStringContainsString( '清除搜尋', $filtered );
	}

	public function test_export_row_is_type_scoped_formula_safe_and_free_of_contact_fields(): void {
		$this->terms->create(
			'list',
			'=SUM(1,1)',
			array( 'description' => '@cmd' )
		);
		$this->terms->create( 'tag', 'Other Type' );

		$page = $this->terms->paginate(
			array(
				'type'     => 'list',
				'per_page' => 10,
			)
		);

		$row = TermsPage::export_row( $page['items'][0] );

		$this->assertSame( 'nowcrm_export_terms', TermsPage::ACTION_EXPORT );
		$this->assertCount( 9, TermsPage::export_header() );
		$this->assertSame( 'list', $row[1] );
		$this->assertSame( "'=SUM(1,1)", $row[2] );
		$this->assertSame( "'@cmd", $row[4] );
		$this->assertStringNotContainsString( 'Email', implode( ',', TermsPage::export_header() ) );
	}
}
