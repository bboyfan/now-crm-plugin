<?php
/**
 * Recurring campaign admin: list, create, schedule detail, and delete guards.
 *
 * @package NowCRM
 */

use NowCRM\Admin\CampaignListPage;
use NowCRM\Admin\DashboardPage;
use NowCRM\Admin\Menu;
use NowCRM\Admin\RecurringListPage;
use NowCRM\Admin\RecurringPage;
use NowCRM\Campaign\Campaigns;
use NowCRM\Campaign\Recurring;

/**
 * Sentinel thrown by the redirect filter so a handler never reaches exit().
 */
final class NowCrm_Recurring_Redirect_Captured extends \RuntimeException {}

/**
 * @covers \NowCRM\Campaign\Recurring
 * @covers \NowCRM\Admin\RecurringListPage
 * @covers \NowCRM\Admin\RecurringPage
 */
final class RecurringAdminTest extends NowCrmTestCase {

	public function set_up(): void {
		parent::set_up();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->set_settings(
			array(
				'email_sending_enabled'     => true,
				'email_transport'           => 'capture',
				'email_throttle_per_second' => 50,
			)
		);
	}

	public function tear_down(): void {
		$_POST    = array();
		$_REQUEST = array();
		$_GET     = array();
		wp_set_current_user( 0 );

		parent::tear_down();
	}

	public function test_parent_paginate_excludes_normal_campaigns_and_children(): void {
		$normal = $this->make_campaign( array( 'title' => 'Normal fixture campaign' ) );
		$parent = $this->make_parent( array( 'title' => 'Recurring fixture parent' ) );
		$child  = $this->make_child( $parent, Campaigns::STATUS_DRAFT, 'Recurring fixture child' );

		$result = Recurring::paginate( '', 'all', 1, 10 );
		$ids    = array_map( static fn( $post ): int => (int) $post->ID, $result['items'] );

		$this->assertContains( $parent, $ids );
		$this->assertNotContains( $normal, $ids );
		$this->assertNotContains( $child, $ids );
		$this->assertSame( 1, $result['total'] );
		$this->assertSame( 1, $result['counts']['all'] );
		$this->assertSame( 1, $result['counts'][ Recurring::STATUS_INACTIVE ] );
	}

	public function test_paginate_search_status_and_page_clamp(): void {
		$alpha = $this->make_parent(
			array(
				'title'    => 'Needle alpha schedule',
				'content'  => true,
				'settings' => array( 'status' => Recurring::STATUS_ACTIVE, 'frequency' => 'weekly', 'day' => 'wed' ),
			)
		);
		$beta = $this->make_parent(
			array(
				'title'    => 'Other beta schedule',
				'settings' => array( 'status' => Recurring::STATUS_PAUSED ),
			)
		);

		for ( $index = 1; $index <= 10; $index++ ) {
			$this->make_parent( array( 'title' => 'Bulk schedule ' . $index ) );
		}

		$search = Recurring::paginate( 'Needle', 'all', 1, 10 );
		$this->assertSame( array( $alpha ), array_map( static fn( $post ): int => (int) $post->ID, $search['items'] ) );

		$paused = Recurring::paginate( '', Recurring::STATUS_PAUSED, 1, 10 );
		$this->assertSame( array( $beta ), array_map( static fn( $post ): int => (int) $post->ID, $paused['items'] ) );

		$clamped = Recurring::paginate( '', 'all', 99, 10 );
		$this->assertSame( 12, $clamped['total'] );
		$this->assertSame( 2, $clamped['pages'] );
		$this->assertSame( 2, $clamped['page'] );
		$this->assertCount( 2, $clamped['items'] );

		$first = Recurring::paginate( '', 'all', 0, 10 );
		$this->assertSame( 1, $first['page'] );
		$this->assertCount( 10, $first['items'] );
		$this->assertSame( 1, $clamped['counts'][ Recurring::STATUS_ACTIVE ] );
		$this->assertSame( 1, $clamped['counts'][ Recurring::STATUS_PAUSED ] );
		$this->assertSame( 10, $clamped['counts'][ Recurring::STATUS_INACTIVE ] );
	}

	public function test_create_handler_stores_inactive_schedule_without_children(): void {
		$this->post(
			RecurringListPage::CREATE_ACTION,
			array(
				'title'     => 'Weekly fixture parent',
				'mode'      => Campaigns::MODE_HTML,
				'frequency' => 'weekly',
				'time'      => '08:30',
				'day'       => 'wed',
				'auto_send' => '1',
			)
		);

		$args = $this->query_args( $this->capture_redirect( static fn() => RecurringListPage::handle_create() ) );

		$this->assertSame( Menu::RECURRING_SLUG, $args['page'] ?? '' );
		$this->assertSame( 'recurring_created', $args['nowcrm_notice'] ?? '' );

		$id = absint( $args['campaign'] ?? 0 );
		$this->assertGreaterThan( 0, $id );

		$settings = Recurring::settings( $id );
		$this->assertSame( Recurring::STATUS_INACTIVE, $settings['status'] );
		$this->assertSame( 'weekly', $settings['frequency'] );
		$this->assertSame( '08:30', $settings['time'] );
		$this->assertSame( 'wed', $settings['day'] );
		$this->assertTrue( $settings['auto_send'] );
		$this->assertSame( '', $settings['next_run_at'] );
		$this->assertSame( 0, Recurring::child_count( $id ) );
		$this->assertSame( 'draft', get_post_status( $id ) );
		$this->assertSame( Campaigns::STATUS_DRAFT, Campaigns::state( $id ) );
	}

	public function test_create_validation_failure_reopens_panel_and_preserves_values(): void {
		$before = (int) wp_count_posts( Campaigns::POST_TYPE )->draft;

		$this->post(
			RecurringListPage::CREATE_ACTION,
			array(
				'title'     => 'Broken weekly fixture',
				'mode'      => Campaigns::MODE_BLOCKS,
				'frequency' => 'weekly',
				'time'      => '07:15',
				'day'       => '',
			)
		);

		$args = $this->query_args( $this->capture_redirect( static fn() => RecurringListPage::handle_create() ) );

		$this->assertSame( Menu::RECURRING_SLUG, $args['page'] ?? '' );
		$this->assertSame( '1', $args['new'] ?? '' );

		$state = get_transient( 'nowcrm_recurring_create_' . get_current_user_id() );
		$this->assertIsArray( $state );
		$this->assertSame( 'Broken weekly fixture', $state['values']['title'] ?? '' );
		$this->assertSame( 'weekly', $state['values']['frequency'] ?? '' );
		$this->assertNotSame( '', (string) ( $state['error'] ?? '' ) );
		$this->assertSame( $before, (int) wp_count_posts( Campaigns::POST_TYPE )->draft );
	}

	public function test_activation_without_content_is_rejected_and_stays_inactive(): void {
		$parent = $this->make_parent( array( 'title' => 'Empty recurring fixture' ) );

		$this->post(
			RecurringPage::STATUS_ACTION,
			array(
				'campaign_id' => (string) $parent,
				'op'          => 'resume',
			)
		);

		$args = $this->query_args( $this->capture_redirect( static fn() => RecurringPage::handle_status() ) );

		$this->assertSame( 'recurring_content_required', $args['nowcrm_notice'] ?? '' );
		$this->assertSame( Recurring::STATUS_INACTIVE, Recurring::settings( $parent )['status'] );
		$this->assertSame( '', Recurring::settings( $parent )['next_run_at'] );
	}

	public function test_activation_pause_resume_and_disable_transitions(): void {
		$parent = $this->make_parent(
			array(
				'title'    => 'Transition fixture',
				'content'  => true,
				'settings' => array( 'frequency' => 'daily', 'time' => '10:00' ),
			)
		);

		$this->post( RecurringPage::STATUS_ACTION, array( 'campaign_id' => (string) $parent, 'op' => 'resume' ) );
		$args = $this->query_args( $this->capture_redirect( static fn() => RecurringPage::handle_status() ) );
		$this->assertSame( 'recurring_resumed', $args['nowcrm_notice'] ?? '' );
		$this->assertSame( Recurring::STATUS_ACTIVE, Recurring::settings( $parent )['status'] );
		$this->assertNotSame( '', Recurring::settings( $parent )['next_run_at'] );

		$this->post( RecurringPage::STATUS_ACTION, array( 'campaign_id' => (string) $parent, 'op' => 'pause' ) );
		$this->capture_redirect( static fn() => RecurringPage::handle_status() );
		$this->assertSame( Recurring::STATUS_PAUSED, Recurring::settings( $parent )['status'] );

		$this->post( RecurringPage::STATUS_ACTION, array( 'campaign_id' => (string) $parent, 'op' => 'resume' ) );
		$this->capture_redirect( static fn() => RecurringPage::handle_status() );
		$this->assertSame( Recurring::STATUS_ACTIVE, Recurring::settings( $parent )['status'] );

		$this->post( RecurringPage::STATUS_ACTION, array( 'campaign_id' => (string) $parent, 'op' => 'disable' ) );
		$this->capture_redirect( static fn() => RecurringPage::handle_status() );
		$this->assertSame( Recurring::STATUS_INACTIVE, Recurring::settings( $parent )['status'] );
		$this->assertSame( '', Recurring::settings( $parent )['next_run_at'] );
	}

	public function test_generate_creates_exactly_one_child_per_period(): void {
		$this->make_contact( array( 'email' => 'recurring-generate@example.test' ) );
		$parent = $this->make_parent(
			array(
				'title'    => 'Generate fixture',
				'content'  => true,
				'settings' => array( 'status' => Recurring::STATUS_ACTIVE, 'frequency' => 'daily', 'time' => '09:00' ),
			)
		);

		$this->post( RecurringPage::GENERATE_ACTION, array( 'campaign_id' => (string) $parent ) );
		$args = $this->query_args( $this->capture_redirect( static fn() => RecurringPage::handle_generate() ) );

		$this->assertSame( 'recurring_generated', $args['nowcrm_notice'] ?? '' );
		$children = Recurring::children( $parent, 20 );
		$this->assertCount( 1, $children );
		$this->assertSame( (string) $parent, (string) get_post_meta( $children[0], Recurring::META_PARENT, true ) );
		$this->assertSame( 1, Recurring::child_count( $parent ) );
	}

	public function test_duplicate_creates_inactive_copy_without_children(): void {
		$parent = $this->make_parent(
			array(
				'title'    => 'Duplicate fixture',
				'content'  => true,
				'settings' => array( 'status' => Recurring::STATUS_ACTIVE, 'frequency' => 'monthly', 'time' => '11:00', 'day' => '15' ),
			)
		);
		$this->make_child( $parent, Campaigns::STATUS_DRAFT );

		$this->post( RecurringPage::DUPLICATE_ACTION, array( 'campaign_id' => (string) $parent ) );
		$args = $this->query_args( $this->capture_redirect( static fn() => RecurringPage::handle_duplicate() ) );

		$this->assertSame( 'recurring_duplicated', $args['nowcrm_notice'] ?? '' );

		$copy = absint( $args['campaign'] ?? 0 );
		$this->assertGreaterThan( 0, $copy );
		$this->assertNotSame( $parent, $copy );
		$this->assertStringStartsWith( '複製 ', (string) get_the_title( $copy ) );

		$settings = Recurring::settings( $copy );
		$this->assertSame( Recurring::STATUS_INACTIVE, $settings['status'] );
		$this->assertSame( 'monthly', $settings['frequency'] );
		$this->assertSame( '15', $settings['day'] );
		$this->assertSame( 0, Recurring::child_count( $copy ) );
		$this->assertSame( '', (string) get_post_meta( $copy, Recurring::META_PARENT, true ) );
		$this->assertCount( 1, Recurring::children( $parent, 20 ) );
	}

	public function test_delete_trashes_parent_and_children_but_leaves_normal_campaign(): void {
		$normal = $this->make_campaign( array( 'title' => 'Untouched normal campaign' ) );
		$parent = $this->make_parent( array( 'title' => 'Delete fixture' ) );
		$child  = $this->make_child( $parent, Campaigns::STATUS_DRAFT );

		$this->post( RecurringPage::DELETE_ACTION, array( 'campaign_id' => (string) $parent ) );
		$args = $this->query_args( $this->capture_redirect( static fn() => RecurringPage::handle_delete() ) );

		$this->assertSame( 'recurring_deleted', $args['nowcrm_notice'] ?? '' );
		$this->assertSame( 'trash', get_post_status( $parent ) );
		$this->assertSame( 'trash', get_post_status( $child ) );
		$this->assertSame( 'draft', get_post_status( $normal ) );
	}

	public function test_delete_refuses_parent_with_live_child(): void {
		$parent = $this->make_parent( array( 'title' => 'Protected fixture' ) );
		$child  = $this->make_child( $parent, Campaigns::STATUS_PAUSED );

		$this->post( RecurringPage::DELETE_ACTION, array( 'campaign_id' => (string) $parent ) );
		$args = $this->query_args( $this->capture_redirect( static fn() => RecurringPage::handle_delete() ) );

		$this->assertSame( 'recurring_delete_blocked', $args['nowcrm_notice'] ?? '' );
		$this->assertSame( 'draft', get_post_status( $parent ) );
		$this->assertSame( 'draft', get_post_status( $child ) );
		$this->assertSame( Campaigns::STATUS_PAUSED, Campaigns::state( $child ) );
	}

	public function test_bulk_delete_reports_processed_and_skipped(): void {
		$normal = $this->make_campaign( array( 'title' => 'Bulk untouched normal' ) );
		$first  = $this->make_parent( array( 'title' => 'Bulk deletable parent' ) );
		$first_child = $this->make_child( $first, Campaigns::STATUS_DRAFT );
		$second = $this->make_parent( array( 'title' => 'Bulk protected parent' ) );
		$second_child = $this->make_child( $second, Campaigns::STATUS_SCHEDULED );

		$this->post(
			RecurringListPage::BULK_ACTION,
			array(
				'bulk_operation' => 'trash',
				'recurring_ids'  => array( (string) $first, (string) $second, (string) $normal, '0', 'invalid' ),
				'filter_search'  => '0',
				'filter_status'  => Recurring::STATUS_INACTIVE,
				'filter_paged'   => '2',
			)
		);

		$args = $this->query_args( $this->capture_redirect( static fn() => RecurringListPage::handle_bulk() ) );

		$this->assertSame( 'bulk_deleted', $args['nowcrm_notice'] ?? '' );
		$this->assertSame( '1', $args['nowcrm_deleted'] ?? '' );
		$this->assertSame( '2', $args['nowcrm_skipped'] ?? '' );
		$this->assertSame( '0', $args['s'] ?? '' );
		$this->assertSame( Recurring::STATUS_INACTIVE, $args['status'] ?? '' );
		$this->assertSame( '2', $args['paged'] ?? '' );

		$this->assertSame( 'trash', get_post_status( $first ) );
		$this->assertSame( 'trash', get_post_status( $first_child ) );
		$this->assertSame( 'draft', get_post_status( $second ) );
		$this->assertSame( 'draft', get_post_status( $second_child ) );
		$this->assertSame( 'draft', get_post_status( $normal ) );
	}

	public function test_handlers_require_capability_and_nonce(): void {
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber );

		$this->post( RecurringListPage::CREATE_ACTION, array( 'title' => 'Capability fixture', 'frequency' => 'daily', 'time' => '09:00' ) );
		$this->assert_handler_dies( static fn() => RecurringListPage::handle_create() );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$_POST = array(
			'_wpnonce' => 'invalid',
			'action'   => RecurringListPage::CREATE_ACTION,
			'title'    => 'Nonce fixture',
		);
		$_REQUEST = $_POST;

		$this->assert_handler_dies( static fn() => RecurringListPage::handle_create() );
	}

	public function test_recurring_list_renders_parents_only(): void {
		$normal = $this->make_campaign( array( 'title' => 'Hidden normal campaign title' ) );
		$parent = $this->make_parent( array( 'title' => 'Visible recurring parent title' ) );
		$this->make_child( $parent, Campaigns::STATUS_DRAFT, 'Hidden recurring child title' );

		$_GET = array( 'page' => Menu::RECURRING_SLUG );

		ob_start();
		RecurringListPage::render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'Visible recurring parent title', $html );
		$this->assertStringNotContainsString( 'Hidden normal campaign title', $html );
		$this->assertStringNotContainsString( 'Hidden recurring child title', $html );
	}

	public function test_newsletter_list_and_dashboard_hide_recurring_parents_and_children(): void {
		$normal = $this->make_campaign( array( 'title' => 'Visible normal newsletter title' ) );
		$parent = $this->make_parent( array( 'title' => 'Excluded recurring parent title' ) );
		$child  = $this->make_child( $parent, Campaigns::STATUS_DRAFT, 'Excluded recurring child title' );

		$_GET = array( 'page' => Menu::CAMPAIGNS_SLUG );

		ob_start();
		CampaignListPage::render();
		$campaigns = (string) ob_get_clean();

		$this->assertStringContainsString( 'Visible normal newsletter title', $campaigns );
		$this->assertStringNotContainsString( 'Excluded recurring parent title', $campaigns );
		$this->assertStringNotContainsString( 'Excluded recurring child title', $campaigns );

		$_GET = array( 'page' => Menu::DASHBOARD_SLUG );

		ob_start();
		DashboardPage::render();
		$dashboard = (string) ob_get_clean();

		$this->assertStringContainsString( 'Visible normal newsletter title', $dashboard );
		$this->assertStringNotContainsString( 'Excluded recurring parent title', $dashboard );
		$this->assertStringNotContainsString( 'Excluded recurring child title', $dashboard );
	}

	/**
	 * Create an inactive parent campaign.
	 *
	 * @param array<string, mixed> $overrides Title, content flag, and settings.
	 */
	private function make_parent( array $overrides = array() ): int {
		$id = Campaigns::create_draft(
			(string) ( $overrides['title'] ?? 'Recurring fixture ' . wp_generate_password( 6, false ) ),
			Campaigns::MODE_HTML
		);

		$this->assertIsInt( $id );
		$id = (int) $id;

		update_post_meta(
			$id,
			Campaigns::META_AUDIENCE,
			wp_slash( (string) wp_json_encode( array( 'scope' => 'all', 'include' => array(), 'exclude' => array() ) ) )
		);

		$settings = array_merge(
			array(
				'status'    => Recurring::STATUS_INACTIVE,
				'frequency' => 'daily',
				'time'      => '09:00',
				'day'       => '',
				'auto_send' => false,
			),
			$overrides['settings'] ?? array()
		);

		if ( ! empty( $overrides['content'] ) || Recurring::STATUS_ACTIVE === $settings['status'] ) {
			update_post_meta( $id, Campaigns::META_SUBJECT, 'Fixture recurring subject' );
			update_post_meta( $id, Campaigns::META_HTML, wp_slash( '<p>Fixture recurring body</p>' ) );
		}

		$result = Recurring::save( $id, $settings );
		$this->assertNotWPError( $result );

		return $id;
	}

	/**
	 * Create a child campaign for a parent.
	 */
	private function make_child( int $parent, string $state = Campaigns::STATUS_DRAFT, string $title = '' ): int {
		$id = $this->make_campaign(
			array(
				'title' => '' !== $title ? $title : 'Recurring child ' . wp_generate_password( 6, false ),
			)
		);

		update_post_meta( $id, Recurring::META_PARENT, (string) $parent );
		update_post_meta( $id, Recurring::META_SLOT, 'fixture:' . wp_generate_password( 8, false ) );
		update_post_meta( $id, Campaigns::META_STATUS, $state );

		return $id;
	}

	private function post( string $action, array $data ): void {
		$_POST = array_merge(
			array(
				'_wpnonce' => wp_create_nonce( $action ),
				'action'   => $action,
			),
			$data
		);
		$_REQUEST = $_POST;
	}

	private function query_args( string $location ): array {
		$query = (string) wp_parse_url( $location, PHP_URL_QUERY );
		parse_str( $query, $args );

		return array_map( 'strval', $args );
	}

	private function capture_redirect( callable $handler ): string {
		$captured = '';
		$filter   = static function ( $location ) use ( &$captured ): void {
			$captured = (string) $location;
			throw new NowCrm_Recurring_Redirect_Captured( $captured );
		};

		add_filter( 'wp_redirect', $filter, 1 );

		try {
			$handler();
		} catch ( NowCrm_Recurring_Redirect_Captured $exception ) {
			$captured = $exception->getMessage();
		} finally {
			remove_filter( 'wp_redirect', $filter, 1 );
		}

		$this->assertNotSame( '', $captured, 'The handler did not redirect.' );

		return $captured;
	}

	private function assert_handler_dies( callable $handler ): void {
		try {
			$handler();
		} catch ( WPDieException $exception ) {
			$this->addToAssertionCount( 1 );
			return;
		}

		$this->fail( 'The handler must stop on capability or nonce failure.' );
	}
}
