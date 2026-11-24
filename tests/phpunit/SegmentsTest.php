<?php
/**
 * Saved segment storage, evaluation, and consumer seams.
 *
 * @package NowCRM
 */

use NowCRM\Automation\ConditionEvaluator;
use NowCRM\Admin\Menu;
use NowCRM\Admin\SegmentsPage;
use NowCRM\Campaign\Audience;
use NowCRM\Contacts\AdvancedFilters;
use NowCRM\Contacts\CsvExport;
use NowCRM\Contacts\Status;
use NowCRM\Email\SuppressionRepository;
use NowCRM\Segments\SegmentRepository;
use NowCRM\Segments\SegmentResolver;
use NowCRM\Support\Capabilities;

/**
 * Sentinel thrown by the redirect filter so a handler never reaches exit().
 */
final class NowCrm_Segments_Redirect_Captured extends \RuntimeException {}

/**
 * @covers \NowCRM\Segments\SegmentRepository
 * @covers \NowCRM\Segments\SegmentResolver
 * @covers \NowCRM\Segments\SystemSegments
 * @covers \NowCRM\Admin\SegmentsPage
 */
final class SegmentsTest extends NowCrmTestCase {

	private SegmentRepository $segments;
	private SegmentResolver $resolver;

	public function set_up(): void {
		parent::set_up();

		SegmentRepository::register_post_type();

		$this->segments = new SegmentRepository();
		$this->resolver = new SegmentResolver( $this->segments );
	}

	public function tear_down(): void {
		$_GET     = array();
		$_POST    = array();
		$_REQUEST = array();

		parent::tear_down();
	}

	/**
	 * Create a stored segment.
	 *
	 * @param array<string, mixed> $data Segment data.
	 */
	private function make_segment( array $data ): int {
		$id = $this->segments->create(
			array_merge(
				array(
					'name'        => 'Segment ' . wp_generate_password( 6, false ),
					'description' => '',
				),
				$data
			)
		);

		$this->assertIsInt( $id, 'Segment creation failed.' );

		return (int) $id;
	}

	/**
	 * One filter condition.
	 *
	 * @return array<string, mixed>
	 */
	private function condition( string $provider, string $field, string $operator, $value ): array {
		return array(
			'provider' => $provider,
			'field'    => $field,
			'operator' => $operator,
			'value'    => $value,
		);
	}

	public function test_and_within_a_group_or_between_groups_and_count_matches_preview(): void {
		$taipei_alice = $this->make_contact( array( 'first_name' => 'Alice', 'city' => 'Taipei' ) );
		$this->make_contact( array( 'first_name' => 'Bob', 'city' => 'Taipei' ) );
		$this->make_contact( array( 'first_name' => 'Alice', 'city' => 'Tainan' ) );
		$tainan_bob = $this->make_contact( array( 'last_name' => 'Bob', 'city' => 'Tainan' ) );

		$id = $this->make_segment(
			array(
				'name'    => 'Mixed groups',
				'filters' => array(
					array(
						$this->condition( 'contact', 'city', '=', 'Taipei' ),
						$this->condition( 'contact', 'first_name', '=', 'Alice' ),
					),
					array(
						$this->condition( 'contact', 'city', '=', 'Tainan' ),
						$this->condition( 'contact', 'last_name', '=', 'Bob' ),
					),
				),
			)
		);

		$expected = array( $taipei_alice, $tainan_bob );
		sort( $expected );

		$this->assertSame( 2, $this->resolver->count( $id ) );
		$this->assertSame( $expected, $this->resolver->ids( $id ) );

		$preview = $this->resolver->preview( $id );

		$this->assertNotWPError( $preview );
		$this->assertSame( 2, $preview['total'] );
		$this->assertSame( $expected, array_map( 'intval', array_column( $preview['items'], 'id' ) ) );
	}

	public function test_empty_and_unsupported_plans_fail_closed(): void {
		$empty = $this->segments->create(
			array(
				'name'    => 'Empty plan',
				'filters' => array(),
			)
		);

		$this->assertWPError( $empty );
		$this->assertSame( 'nowcrm_segment_empty', $empty->get_error_code() );

		$unsupported = $this->segments->create(
			array(
				'name'    => 'Provider predicate',
				'filters' => array(
					array( $this->condition( 'woocommerce', 'total_spent', '>', '100' ) ),
				),
			)
		);

		$this->assertWPError( $unsupported );
		$this->assertSame( 'nowcrm_segment_invalid', $unsupported->get_error_code() );

		$bad_operator = $this->segments->create(
			array(
				'name'    => 'Bad operator',
				'filters' => array(
					array( $this->condition( 'segment', 'status', 'contains', 'subscribed' ) ),
				),
			)
		);

		$this->assertWPError( $bad_operator );

		$id = $this->make_segment(
			array(
				'filters' => array(
					array( $this->condition( 'contact', 'city', '=', 'Taipei' ) ),
				),
			)
		);

		update_post_meta(
			$id,
			SegmentRepository::META_FILTERS,
			(string) wp_json_encode(
				array(
					'version' => 1,
					'groups'  => array(
						array( $this->condition( 'woocommerce', 'total_spent', '>', '100' ) ),
					),
				)
			)
		);

		$this->assertWPError( $this->resolver->count( $id ) );
		$this->assertWPError( $this->resolver->ids( $id ) );
		$this->assertWPError( $this->resolver->recipients( $id ) );
		$this->assertWPError( $this->resolver->where( $id ) );
	}

	public function test_crud_isolation_live_updates_and_duplicate_handling(): void {
		$taipei = $this->make_contact( array( 'city' => 'Taipei' ) );
		$tainan = $this->make_contact( array( 'city' => 'Tainan' ) );

		$north = $this->make_segment(
			array(
				'name'    => 'North',
				'filters' => array( array( $this->condition( 'contact', 'city', '=', 'Taipei' ) ) ),
			)
		);
		$south = $this->make_segment(
			array(
				'name'    => 'South',
				'filters' => array( array( $this->condition( 'contact', 'city', '=', 'Tainan' ) ) ),
			)
		);

		$this->assertSame( array( $taipei ), $this->resolver->ids( $north ) );
		$this->assertSame( array( $tainan ), $this->resolver->ids( $south ) );

		$updated = $this->segments->update(
			$north,
			array(
				'filters' => array( array( $this->condition( 'contact', 'city', '=', 'Tainan' ) ) ),
			)
		);

		$this->assertTrue( $updated );
		$this->assertSame( array( $tainan ), $this->resolver->ids( $north ), 'An edit must apply to the next resolution.' );
		$this->assertSame( array( $tainan ), $this->resolver->ids( $south ), 'Other segments must not change.' );

		$duplicate_name = $this->segments->create(
			array(
				'name'    => 'South',
				'filters' => array( array( $this->condition( 'contact', 'city', '=', 'Tainan' ) ) ),
			)
		);

		$this->assertWPError( $duplicate_name );
		$this->assertSame( 'nowcrm_segment_name_exists', $duplicate_name->get_error_code() );

		$south_key = (string) $this->segments->find( $south )['key'];
		$duplicate_key = $this->segments->create(
			array(
				'name'    => 'South copy',
				'key'     => $south_key,
				'filters' => array( array( $this->condition( 'contact', 'city', '=', 'Tainan' ) ) ),
			)
		);

		$this->assertWPError( $duplicate_key );
		$this->assertSame( 'nowcrm_segment_key_exists', $duplicate_key->get_error_code() );

		$copy = $this->segments->duplicate( $south );

		$this->assertIsInt( $copy );
		$this->assertNotSame( $south_key, $this->segments->find( (int) $copy )['key'] );
		$this->assertSame( $this->resolver->ids( $south ), $this->resolver->ids( (int) $copy ) );
	}

	public function test_archive_restore_and_delete_usage_safety(): void {
		$matching = $this->make_contact( array( 'city' => 'Taipei' ) );

		$id = $this->make_segment(
			array(
				'name'    => 'Archived later',
				'filters' => array( array( $this->condition( 'contact', 'city', '=', 'Taipei' ) ) ),
			)
		);

		$this->assertSame( array( $matching ), $this->resolver->ids( $id ) );

		$this->assertTrue( $this->segments->archive( $id ) );
		$this->assertSame( SegmentRepository::STATUS_ARCHIVED, $this->segments->find( $id )['status'] );
		$this->assertWPError( $this->resolver->count( $id ) );
		$this->assertSame( 'nowcrm_segment_inactive', $this->resolver->count( $id )->get_error_code() );
		$this->assertSame( 1, $this->resolver->count( $id, true ), 'Admin preview may read an archived definition.' );

		$this->assertTrue( $this->segments->restore( $id ) );
		$this->assertSame( array( $matching ), $this->resolver->ids( $id ) );

		$campaign = $this->make_campaign(
			array(
				'audience' => array(
					'scope'   => 'rules',
					'include' => array(
						array(
							'list'    => 0,
							'tag'     => 0,
							'segment' => $id,
						),
					),
					'exclude' => array(),
				),
			)
		);

		$usage = $this->segments->usage( $id );

		$this->assertCount( 1, $usage );
		$this->assertStringContainsString( (string) $campaign, $usage[0] );

		$blocked = $this->segments->delete( $id );

		$this->assertWPError( $blocked );
		$this->assertSame( 'nowcrm_segment_in_use', $blocked->get_error_code() );

		wp_delete_post( $campaign, true );

		$this->assertSame( array(), $this->segments->usage( $id ) );
		$this->assertTrue( $this->segments->delete( $id ) );
		$this->assertNull( $this->segments->find( $id ) );
	}

	public function test_system_segments_use_native_predicates(): void {
		$subscribed = $this->make_contact();
		$this->make_contact( array( 'status' => Status::UNSUBSCRIBED ) );
		$customer = $this->make_contact();

		$link = $this->links->upsert(
			array(
				'order_id'     => 910001,
				'contact_id'   => $customer,
				'billing_email' => 'order@example.test',
				'status'       => 'completed',
				'total'        => 500,
				'net_total'    => 500,
				'currency'     => 'TWD',
				'is_counted'   => 1,
			)
		);

		$this->assertTrue( $link );

		$this->assertSame( 3, $this->resolver->count( 'system:all-contacts' ) );
		$this->assertSame( 2, $this->resolver->count( 'system:subscribed' ) );
		$this->assertSame( 1, $this->resolver->count( 'system:customers' ) );
		$this->assertSame( array( $customer ), $this->resolver->ids( 'system:customers' ) );
		$this->assertTrue( $this->resolver->contains( 'system:customers', $customer ) );
		$this->assertFalse( $this->resolver->contains( 'system:customers', $subscribed ) );
		$this->assertSame( 'system', substr( $this->segments->find( 'subscribed' )['ref'], 0, 6 ) );

		$this->assertWPError( $this->segments->update( (int) $customer, array( 'name' => 'Nope' ) ) );
		$this->assertWPError( $this->segments->delete( 0 ) );
	}

	public function test_segment_campaign_recipients_and_export_seam(): void {
		$eligible = $this->make_contact( array( 'city' => 'Taipei' ) );
		$optout   = $this->make_contact( array( 'city' => 'Taipei', 'status' => Status::UNSUBSCRIBED ) );
		$blocked  = $this->make_contact( array( 'city' => 'Taipei', 'email' => 'blocked@example.test' ) );

		( new SuppressionRepository() )->add( 'blocked@example.test', 'bounced', 'test' );

		$id = $this->make_segment(
			array(
				'name'    => 'Taipei marketing',
				'filters' => array( array( $this->condition( 'contact', 'city', '=', 'Taipei' ) ) ),
			)
		);

		$this->assertSame( array( $eligible, $optout, $blocked ), $this->resolver->ids( $id ), 'Export membership is raw.' );

		$recipients = $this->resolver->recipients( $id );

		$this->assertNotWPError( $recipients );
		$this->assertSame( array( $eligible ), array_map( 'intval', array_column( $recipients, 'contact_id' ) ) );
		$this->assertSame( 1, $this->resolver->recipient_count( $id ) );

		$where = $this->resolver->where( $id );

		$this->assertNotWPError( $where );
		$this->assertSame( AdvancedFilters::compile( AdvancedFilters::normalize( $this->segments->find( $id )['filters'] ) )['sql'], $where['sql'] );
	}

	public function test_audience_list_and_segment_rows_intersect_and_invalid_refs_fail_closed(): void {
		$tag = $this->terms->create( 'tag', 'VIP' );

		$both = $this->make_contact( array( 'city' => 'Taipei' ) );
		$this->terms->attach( $both, $tag );
		$tag_only = $this->make_contact( array( 'city' => 'Tainan' ) );
		$this->terms->attach( $tag_only, $tag );
		$segment_only = $this->make_contact( array( 'city' => 'Taipei' ) );

		$segment = $this->make_segment(
			array(
				'name'    => 'Taipei',
				'filters' => array( array( $this->condition( 'contact', 'city', '=', 'Taipei' ) ) ),
			)
		);

		$audience = array(
			'scope'   => 'rules',
			'include' => array(
				array(
					'list'    => 0,
					'tag'     => $tag,
					'segment' => $segment,
				),
			),
			'exclude' => array(),
		);

		$validated = Audience::validate( $audience );

		$this->assertNotWPError( $validated );
		$this->assertSame( $tag, $validated['include'][0]['tag'], 'The tag key must survive.' );
		$this->assertSame( $segment, $validated['include'][0]['segment'], 'The segment key must survive.' );

		$recipients = array_map( 'intval', array_column( Audience::recipients( $validated ), 'contact_id' ) );

		$this->assertSame( array( $both ), $recipients, 'List/tag and segment conditions must be AND-ed inside one rule.' );

		$stale = $audience;
		$stale['include'][0]['segment'] = 999999;

		$this->assertWPError( Audience::validate( $stale ) );
		$this->assertSame( array(), Audience::recipients( Audience::stored( $stale ) ), 'A stale reference must fail closed.' );

		$this->assertTrue( $this->segments->archive( $segment ) );
		$this->assertWPError( Audience::validate( $audience ) );
		$this->assertSame( array(), Audience::recipients( Audience::stored( $audience ) ) );
		$this->assertTrue( $this->segments->restore( $segment ) );
		$this->assertSame( array( $both ), array_map( 'intval', array_column( Audience::recipients( Audience::validate( $audience ) ), 'contact_id' ) ) );

		$system = Audience::validate(
			array(
				'scope'   => 'rules',
				'include' => array(
					array(
						'list'    => 0,
						'tag'     => 0,
						'segment' => 'system:all-contacts',
					),
				),
				'exclude' => array(),
			)
		);

		$this->assertNotWPError( $system );
		$this->assertCount( 3, Audience::recipients( $system ), 'A system segment reference must work in a campaign rule.' );

		$this->assertNotEmpty( $segment_only );
	}

	public function test_export_segment_scope_pages_through_all_members_and_rejects_invalid_refs(): void {
		$expected = array();

		for ( $index = 0; $index < CsvExport::PER_PAGE + 1; $index++ ) {
			$expected[] = $this->make_contact( array( 'city' => 'Export bulk' ) );
		}

		$segment = $this->make_segment(
			array(
				'name'    => 'Export bulk',
				'filters' => array( array( $this->condition( 'contact', 'city', '=', 'Export bulk' ) ) ),
			)
		);

		$export = new CsvExport();
		$plan   = $export->plan(
			array(
				'segment' => (string) $segment,
				'columns' => array( 'id' ),
			)
		);

		$this->assertNotWPError( $plan );
		$this->assertSame( count( $expected ), $plan['total'] );

		$ids = array_map(
			static fn( array $row ): int => (int) $row[0],
			iterator_to_array( $export->rows( $plan ) )
		);

		sort( $expected );
		sort( $ids );

		$this->assertSame( $expected, $ids, 'Every member must appear once across pages.' );

		$customer = $this->make_contact();
		$this->links->upsert(
			array(
				'order_id'     => 910002,
				'contact_id'   => $customer,
				'billing_email' => 'customer@example.test',
				'status'       => 'completed',
				'total'        => 100,
				'net_total'    => 100,
				'currency'     => 'TWD',
				'is_counted'   => 1,
			)
		);

		$system = $export->plan(
			array(
				'segment' => 'system:customers',
				'columns' => array( 'id' ),
			)
		);

		$this->assertNotWPError( $system );
		$this->assertSame(
			array( (string) $customer ),
			array_map( static fn( array $row ): string => (string) $row[0], iterator_to_array( $export->rows( $system ) ) )
		);

		$this->assertWPError(
			$export->plan(
				array(
					'segment' => 'custom:999999',
					'columns' => array( 'id' ),
				)
			)
		);
	}

	public function test_automation_segment_predicate_fails_closed(): void {
		$inside  = $this->make_contact( array( 'city' => 'Taipei' ) );
		$outside = $this->make_contact( array( 'city' => 'Tainan' ) );

		$segment = $this->make_segment(
			array(
				'name'    => 'Taipei automation',
				'filters' => array( array( $this->condition( 'contact', 'city', '=', 'Taipei' ) ) ),
			)
		);

		$this->assertTrue( ConditionEvaluator::matches_segment( $segment, $inside ) );
		$this->assertFalse( ConditionEvaluator::matches_segment( $segment, $outside ) );
		$this->assertFalse( ConditionEvaluator::matches_segment( $segment, 0 ) );
		$this->assertWPError( ConditionEvaluator::matches_segment( 'custom:999999', $inside ) );

		$this->segments->archive( $segment );

		$this->assertWPError( ConditionEvaluator::matches_segment( $segment, $inside ) );
	}

	public function test_landing_separates_system_and_saved_rows_and_filters_archived(): void {
		$this->as_admin();
		$this->make_segment(
			array(
				'name'    => 'Alpha audience',
				'filters' => array( array( $this->condition( 'contact', 'city', '=', 'Taipei' ) ) ),
			)
		);
		$archived = $this->make_segment(
			array(
				'name'    => 'Beta archived audience',
				'filters' => array( array( $this->condition( 'contact', 'city', '=', 'Tainan' ) ) ),
			)
		);
		$this->segments->archive( $archived );

		$_GET = array( 'page' => SegmentsPage::SLUG );
		$html = $this->render_segments();

		$this->assertStringContainsString( 'Alpha audience', $html );
		$this->assertStringContainsString( 'Beta archived audience', $html );
		$this->assertStringContainsString( '已訂閱聯絡人', $html );
		$this->assertStringContainsString( '系統', $html );
		$this->assertStringContainsString( '自訂', $html );
		$this->assertStringContainsString( '共 5 筆', $html );

		$_GET = array(
			'page'   => SegmentsPage::SLUG,
			'status' => 'archived',
		);
		$html = $this->render_segments();

		$this->assertStringContainsString( 'Beta archived audience', $html );
		$this->assertStringNotContainsString( 'Alpha audience', $html );
		$this->assertStringNotContainsString( '已訂閱聯絡人', $html );
	}

	public function test_landing_paginates_ten_rows_and_clamps_the_page(): void {
		$this->as_admin();

		for ( $index = 1; $index <= 12; $index++ ) {
			$this->make_segment(
				array(
					'name'    => sprintf( 'Paged audience %02d', $index ),
					'filters' => array( array( $this->condition( 'contact', 'city', '=', 'Taipei' ) ) ),
				)
			);
		}

		$_GET = array(
			'page' => SegmentsPage::SLUG,
			'type' => 'custom',
		);
		$html = $this->render_segments();

		$this->assertSame( 10, substr_count( $html, 'data-segment-ref="custom:' ) );
		$this->assertStringContainsString( '共 12 筆', $html );

		$_GET['paged'] = '2';
		$html          = $this->render_segments();

		$this->assertSame( 2, substr_count( $html, 'data-segment-ref="custom:' ) );

		$_GET['paged'] = '99';
		$html          = $this->render_segments();

		$this->assertSame( 2, substr_count( $html, 'data-segment-ref="custom:' ) );
	}

	public function test_create_and_preview_are_discrete_routes(): void {
		$this->as_admin();

		$_GET = array(
			'page' => SegmentsPage::SLUG,
			'new'  => '1',
		);
		$html = $this->render_segments();

		$this->assertStringContainsString( '新增分眾', $html );
		$this->assertStringContainsString( 'id="nowcrm-segment-form"', $html );
		$this->assertStringNotContainsString( 'id="nowcrm-segments"', $html );

		$this->make_contact( array( 'first_name' => 'Preview', 'email' => 'preview@example.test', 'city' => 'Taipei' ) );
		$id      = $this->make_segment(
			array(
				'name'    => 'Preview audience',
				'filters' => array( array( $this->condition( 'contact', 'city', '=', 'Taipei' ) ) ),
			)
		);

		$_GET = array(
			'page'    => SegmentsPage::SLUG,
			'segment' => 'custom:' . $id,
		);
		$html = $this->render_segments();

		$this->assertStringContainsString( 'id="nowcrm-segment-preview"', $html );
		$this->assertStringContainsString( 'preview@example.test', $html );
		$this->assertStringContainsString( 'id="nowcrm-segment-audience"', $html );
		$this->assertStringContainsString( 'nowcrm_adv', $html );
		$this->assertStringNotContainsString( 'id="nowcrm-segments"', $html );
	}

	public function test_preview_offers_contacts_only_for_equivalent_audiences(): void {
		$this->as_admin();

		$_GET = array(
			'page'    => SegmentsPage::SLUG,
			'segment' => 'system:subscribed',
		);
		$html = $this->render_segments();

		$this->assertStringContainsString( 'id="nowcrm-segment-audience"', $html );
		$this->assertStringContainsString( 'status=subscribed', $html );

		$_GET['segment'] = 'system:customers';
		$html            = $this->render_segments();

		$this->assertStringNotContainsString( 'id="nowcrm-segment-audience"', $html );
		$this->assertStringContainsString( '系統分眾由 NOW CRM', $html );
	}

	public function test_archived_preview_stays_read_only_and_keeps_members_visible(): void {
		$this->as_admin();
		$this->make_contact( array( 'email' => 'archived@example.test', 'city' => 'Taipei' ) );
		$id = $this->make_segment(
			array(
				'name'    => 'Archived audience',
				'filters' => array( array( $this->condition( 'contact', 'city', '=', 'Taipei' ) ) ),
			)
		);

		$this->segments->archive( $id );

		$_GET = array(
			'page'    => SegmentsPage::SLUG,
			'segment' => 'custom:' . $id,
		);
		$html = $this->render_segments();

		$this->assertStringContainsString( '已封存', $html );
		$this->assertStringContainsString( 'archived@example.test', $html );
		$this->assertStringNotContainsString( 'id="nowcrm-segments"', $html );
	}

	public function test_filtered_list_context_survives_segment_preview_and_edit(): void {
		$this->as_admin();

		$id = $this->make_segment(
			array(
				'name'    => 'Audience context',
				'filters' => array( array( $this->condition( 'contact', 'city', '=', 'Taipei' ) ) ),
			)
		);

		$filters = array(
			's'      => 'Audience',
			'type'   => 'custom',
			'status' => 'active',
			'paged'  => 2,
		);

		$_GET = array_merge(
			array(
				'page'    => SegmentsPage::SLUG,
				'segment' => 'custom:' . $id,
			),
			$filters,
			array( 'paged' => '2' )
		);
		$html = $this->render_segments();

		$this->assertStringContainsString( esc_url( Menu::page_url( SegmentsPage::SLUG, $filters ) ), $html );
		$this->assertStringContainsString(
			esc_url( Menu::page_url( SegmentsPage::SLUG, array_merge( array( 'edit' => $id ), $filters ) ) ),
			$html
		);

		$_GET = array_merge(
			array(
				'page' => SegmentsPage::SLUG,
				'edit' => (string) $id,
			),
			$filters,
			array( 'paged' => '2' )
		);
		$html = $this->render_segments();

		$this->assertStringContainsString( esc_url( Menu::page_url( SegmentsPage::SLUG, $filters ) ), $html );
	}

	public function test_segment_archive_action_returns_to_the_filtered_list(): void {
		$this->as_admin();

		$id = $this->make_segment(
			array(
				'name'    => 'Archive context',
				'filters' => array( array( $this->condition( 'contact', 'city', '=', 'Taipei' ) ) ),
			)
		);

		$filters = array(
			's'      => 'Archive',
			'type'   => 'custom',
			'status' => 'active',
			'paged'  => 2,
		);

		$_GET     = array_merge(
			array( 'page' => SegmentsPage::SLUG ),
			$filters,
			array( 'paged' => '2' )
		);
		$_POST    = array(
			'action'   => 'nowcrm_segment_archive',
			'id'       => (string) $id,
			'_wpnonce' => wp_create_nonce( 'nowcrm_segment_archive' ),
		);
		$_REQUEST = $_POST;

		$location = $this->capture_redirect( static fn() => SegmentsPage::archive() );
		$args     = array();

		parse_str( (string) wp_parse_url( $location, PHP_URL_QUERY ), $args );

		$this->assertSame( SegmentsPage::SLUG, $args['page'] ?? '' );
		$this->assertSame( 'archived', $args['nowcrm_segment_notice'] ?? '' );
		$this->assertSame( 'Archive', $args['s'] ?? '' );
		$this->assertSame( 'custom', $args['type'] ?? '' );
		$this->assertSame( 'active', $args['status'] ?? '' );
		$this->assertSame( '2', $args['paged'] ?? '' );
	}

	/**
	 * Render the segment screen as an administrator.
	 */
	private function render_segments(): string {
		ob_start();
		SegmentsPage::render();

		return (string) ob_get_clean();
	}

	/**
	 * Capture the wp_safe_redirect() target of a handler.
	 */
	private function capture_redirect( callable $handler ): string {
		$captured = '';
		$filter   = static function ( $location ) use ( &$captured ): void {
			$captured = (string) $location;
			throw new NowCrm_Segments_Redirect_Captured( $captured );
		};

		add_filter( 'wp_redirect', $filter, 1 );

		try {
			$handler();
		} catch ( NowCrm_Segments_Redirect_Captured $exception ) {
			$captured = $exception->getMessage();
		} finally {
			remove_filter( 'wp_redirect', $filter, 1 );
		}

		$this->assertNotSame( '', $captured, 'The handler did not redirect.' );

		return $captured;
	}

	/**
	 * Use an administrator for capability checks.
	 */
	private function as_admin(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$user    = get_user_by( 'id', $user_id );

		if ( $user ) {
			$user->add_cap( Capabilities::MANAGE_CONTACTS );
		}

		wp_set_current_user( $user_id );
	}
}
