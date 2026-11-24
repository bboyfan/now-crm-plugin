<?php
/**
 * Workflow catalog shell: empty states, selection markup, notices, pagination.
 *
 * @package NowCRM
 */

use NowCRM\Admin\Menu;
use NowCRM\Admin\WorkflowCatalog;

/**
 * @covers \NowCRM\Admin\WorkflowCatalog
 */
final class WorkflowCatalogTest extends NowCrmTestCase {

	public function tear_down(): void {
		$_GET = array();
		wp_set_current_user( 0 );

		parent::tear_down();
	}

	public function test_empty_library_shows_a_create_affordance_and_no_bulk_form(): void {
		$html = $this->render(
			array(
				'library_total' => 0,
				'create'        => array(
					'url'   => Menu::page_url( Menu::SEQUENCES_SLUG, array( 'new' => '1' ) ),
					'label' => '新增序列',
				),
				'empty'         => array( 'library' => '還沒有序列，先新增第一個。' ),
			)
		);

		$this->assertStringContainsString( '還沒有序列，先新增第一個。', $html );
		$this->assertStringContainsString( '新增序列', $html );
		$this->assertStringNotContainsString( 'nowcrm-catalog__bulk', $html );
	}

	public function test_empty_result_shows_filter_reset_instead_of_the_library_state(): void {
		$html = $this->render(
			array(
				'search'        => '0',
				'library_total' => 4,
				'total'         => 0,
			)
		);

		$this->assertStringContainsString( '沒有符合條件的', $html );
		$this->assertStringContainsString( '清除篩選', $html );
		$this->assertStringContainsString( 'value="0"', $html, 'A search for 0 must survive the render.' );
	}

	public function test_rows_render_selection_markup_and_disabled_ineligible_rows(): void {
		$html = $this->render(
			array(
				'library_total' => 2,
				'total'         => 2,
				'page'          => 1,
				'pages'         => 1,
				'rows'          => array(
					array(
						'id'       => 11,
						'label'    => 'Editable',
						'eligible' => true,
						'cells'    => array( 'Editable' ),
					),
					array(
						'id'       => 12,
						'label'    => 'Locked',
						'eligible' => false,
						'reason'   => '狀態不允許批次處理。',
						'cells'    => array( 'Locked' ),
					),
				),
				'bulk'          => array(
					'action'  => 'nowcrm_bulk_campaigns',
					'nonce'   => 'nowcrm_bulk_campaigns',
					'field'   => 'campaign_ids',
					'options' => array( 'trash' => '移至回收桶' ),
				),
			)
		);

		$this->assertStringContainsString( 'data-nowcrm-row-check', $html );
		$this->assertStringContainsString( 'name="campaign_ids[]"', $html );
		$this->assertStringContainsString( 'value="11"', $html );
		$this->assertStringContainsString( 'disabled="disabled"', $html );
		$this->assertStringContainsString( '狀態不允許批次處理。', $html );
		$this->assertStringContainsString( 'data-nowcrm-check-page', $html );
		$this->assertStringContainsString( 'nowcrm-catalog__bulk', $html );
	}

	public function test_status_tabs_keep_the_current_search(): void {
		$html = $this->render(
			array(
				'search'        => 'spring',
				'status'        => 'draft',
				'statuses'      => array( 'draft' => '草稿', 'publish' => '已發佈' ),
				'counts'        => array( 'all' => 3, 'draft' => 2, 'publish' => 1 ),
				'library_total' => 3,
				'total'         => 2,
				'pages'         => 1,
			)
		);

		$this->assertStringContainsString( 'aria-current="page"', $html );
		$this->assertStringContainsString( 's=spring', $html );
		$this->assertStringContainsString( 'status=publish', $html );
		$this->assertStringContainsString( '2', $html );
	}

	public function test_pagination_keeps_filters_and_reports_the_visible_range(): void {
		$html = $this->render(
			array(
				'search'        => 'spring',
				'status'        => 'active',
				'library_total' => 25,
				'total'         => 25,
				'page'          => 2,
				'pages'         => 3,
				'rows'          => array(
					array(
						'id'    => 21,
						'label' => 'Row',
						'cells' => array( 'Row' ),
					),
				),
			)
		);

		$this->assertStringContainsString( 'paged=1', $html );
		$this->assertStringContainsString( 'paged=3', $html );
		$this->assertStringContainsString( 's=spring', $html );
		$this->assertStringContainsString( 'status=active', $html );
		$this->assertStringContainsString( '11–20', $html );
	}

	public function test_bulk_notice_reports_success_and_skipped_counts(): void {
		$_GET = array(
			'nowcrm_notice'  => 'bulk_deleted',
			'nowcrm_deleted' => '2',
			'nowcrm_skipped' => '1',
		);

		ob_start();
		WorkflowCatalog::render_notice( '電子報' );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( '已刪除 2 筆電子報。', $html );
		$this->assertStringContainsString( '另有 1 筆未處理', $html );
		$this->assertStringContainsString( 'notice-success', $html );
	}

	public function test_no_selection_notice_is_an_error_and_unknown_codes_stay_silent(): void {
		$_GET = array( 'nowcrm_notice' => 'no_selection' );

		ob_start();
		WorkflowCatalog::render_notice( '範本' );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( '請先選取範本。', $html );
		$this->assertStringContainsString( 'notice-error', $html );

		$_GET = array( 'nowcrm_notice' => 'not_a_code' );

		ob_start();
		WorkflowCatalog::render_notice( '範本' );
		$this->assertSame( '', (string) ob_get_clean() );
	}

	public function test_too_many_notice_names_the_limit(): void {
		$_GET = array(
			'nowcrm_notice' => 'too_many',
			'nowcrm_limit'  => '100',
		);

		ob_start();
		WorkflowCatalog::render_notice( '範本' );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( '上限 100 筆', $html );
	}

	public function test_bulk_submit_names_the_single_action_and_uses_apply_for_choices(): void {
		$row = array(
			'id'    => 21,
			'label' => 'Selected row',
			'cells' => array( 'Selected row' ),
		);

		$single = $this->render(
			array(
				'library_total' => 1,
				'total'         => 1,
				'pages'         => 1,
				'rows'          => array( $row ),
				'bulk'          => array(
					'action'  => 'nowcrm_bulk_campaigns',
					'nonce'   => 'nowcrm_bulk_campaigns',
					'field'   => 'campaign_ids',
					'options' => array( 'trash' => '刪除' ),
				),
			)
		);

		$this->assertMatchesRegularExpression( '/button button-primary[^>]*>\s*刪除\s*<\/button>/', $single );
		$this->assertStringNotContainsString( '>執行<', $single );

		$multiple = $this->render(
			array(
				'library_total' => 1,
				'total'         => 1,
				'pages'         => 1,
				'rows'          => array( $row ),
				'bulk'          => array(
					'action'  => 'nowcrm_bulk_automations',
					'nonce'   => 'nowcrm_bulk_automations',
					'field'   => 'automation_ids',
					'options' => array( 'status' => '變更狀態', 'delete' => '刪除' ),
				),
			)
		);

		$this->assertMatchesRegularExpression( '/button button-primary[^>]*>\s*套用\s*<\/button>/', $multiple );
	}

	/**
	 * Render the catalog with one column and one row per supplied row.
	 *
	 * @param array<string, mixed> $overrides Configuration overrides.
	 */
	private function render( array $overrides ): string {
		$config = array_merge(
			array(
				'slug'     => Menu::CAMPAIGNS_SLUG,
				'title'    => '電子報',
				'noun'     => '電子報',
				'columns'  => array( array( 'label' => '名稱' ) ),
				'rows'     => array(),
			),
			$overrides
		);

		ob_start();
		WorkflowCatalog::render( $config );

		return (string) ob_get_clean();
	}
}
