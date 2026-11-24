<?php
/**
 * Bulk catalog actions: selection, authorization, state guards, and counts.
 *
 * @package NowCRM
 */

use NowCRM\Admin\CampaignListPage;
use NowCRM\Admin\WorkflowCatalogActions;
use NowCRM\Automation\AutomationRepository;
use NowCRM\Automation\TriggerRegistry;
use NowCRM\Campaign\Campaigns;
use NowCRM\Sequences\SequenceRepository;
use NowCRM\Templates\TemplateRepository;

/**
 * Sentinel thrown by the redirect filter so a handler never reaches exit().
 */
final class NowCrm_Catalog_Redirect_Captured extends \RuntimeException {}

/**
 * @covers \NowCRM\Admin\WorkflowCatalogActions
 */
final class WorkflowCatalogActionsTest extends NowCrmTestCase {

	private AutomationRepository $automations;
	private SequenceRepository $sequences;
	private TemplateRepository $templates;

	public function set_up(): void {
		parent::set_up();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->automations = new AutomationRepository();
		$this->sequences   = new SequenceRepository();
		$this->templates   = new TemplateRepository();
	}

	public function tear_down(): void {
		$_POST    = array();
		$_REQUEST = array();
		$_GET     = array();
		wp_set_current_user( 0 );

		parent::tear_down();
	}

	public function test_single_campaign_delete_uses_the_shared_state_guard(): void {
		$sending = $this->make_campaign( array( 'status' => Campaigns::STATUS_SENDING ) );
		$draft   = $this->make_campaign();

		$_POST = array(
			'_wpnonce'    => wp_create_nonce( 'nowcrm_delete_campaign' ),
			'action'      => 'nowcrm_delete_campaign',
			'campaign_id' => (string) $sending,
		);
		$_REQUEST = $_POST;

		$args = $this->query_args(
			$this->capture_redirect( static fn() => CampaignListPage::handle_delete() )
		);

		$this->assertSame( 'state_blocked', $args['nowcrm_notice'] ?? '' );
		$this->assertSame( 'draft', get_post_status( $sending ) );
		$this->assertSame( Campaigns::STATUS_SENDING, Campaigns::state( $sending ) );

		$_POST = array(
			'_wpnonce'    => wp_create_nonce( 'nowcrm_delete_campaign' ),
			'action'      => 'nowcrm_delete_campaign',
			'campaign_id' => (string) $draft,
		);
		$_REQUEST = $_POST;

		$args = $this->query_args(
			$this->capture_redirect( static fn() => CampaignListPage::handle_delete() )
		);

		$this->assertSame( 'deleted', $args['nowcrm_notice'] ?? '' );
		$this->assertSame( 'trash', get_post_status( $draft ) );
	}

	public function test_campaign_bulk_trashes_only_eligible_selected_rows(): void {
		$draft  = $this->make_campaign();
		$paused = $this->make_campaign( array( 'status' => Campaigns::STATUS_PAUSED ) );
		$template = $this->make_template();

		$this->post(
			WorkflowCatalogActions::CAMPAIGN_ACTION,
			array(
				'bulk_operation' => 'trash',
				'campaign_ids'   => array( (string) $draft, (string) $paused, (string) $template, 'not-a-number', '0' ),
			)
		);

		$args = $this->query_args(
			$this->capture_redirect( static fn() => WorkflowCatalogActions::campaigns() )
		);

		$this->assertSame( 'bulk_deleted', $args['nowcrm_notice'] ?? '' );
		$this->assertSame( '1', $args['nowcrm_deleted'] ?? '' );
		$this->assertSame( '2', $args['nowcrm_skipped'] ?? '' );
		$this->assertSame( 'trash', get_post_status( $draft ) );
		$this->assertSame( 'draft', get_post_status( $paused ) );
		$this->assertSame( Campaigns::STATUS_PAUSED, Campaigns::state( $paused ) );
		$this->assertNotNull( get_post( $template ) );
	}

	public function test_campaign_bulk_keeps_scheduled_sending_and_paused_states_untouched(): void {
		$scheduled = $this->make_campaign( array( 'status' => Campaigns::STATUS_SCHEDULED ) );
		$sending   = $this->make_campaign( array( 'status' => Campaigns::STATUS_SENDING ) );

		$this->post(
			WorkflowCatalogActions::CAMPAIGN_ACTION,
			array(
				'bulk_operation' => 'trash',
				'campaign_ids'   => array( (string) $scheduled, (string) $sending ),
			)
		);

		$args = $this->query_args(
			$this->capture_redirect( static fn() => WorkflowCatalogActions::campaigns() )
		);

		$this->assertSame( '0', $args['nowcrm_deleted'] ?? '' );
		$this->assertSame( '2', $args['nowcrm_skipped'] ?? '' );
		$this->assertSame( 'draft', get_post_status( $scheduled ) );
		$this->assertSame( 'draft', get_post_status( $sending ) );
		$this->assertSame( Campaigns::STATUS_SCHEDULED, Campaigns::state( $scheduled ) );
		$this->assertSame( Campaigns::STATUS_SENDING, Campaigns::state( $sending ) );
	}

	public function test_campaign_bulk_without_selection_does_not_mutate(): void {
		$campaign = $this->make_campaign();

		$this->post(
			WorkflowCatalogActions::CAMPAIGN_ACTION,
			array(
				'bulk_operation' => 'trash',
				'campaign_ids'   => array(),
			)
		);

		$args = $this->query_args(
			$this->capture_redirect( static fn() => WorkflowCatalogActions::campaigns() )
		);

		$this->assertSame( 'no_selection', $args['nowcrm_notice'] ?? '' );
		$this->assertSame( 'draft', get_post_status( $campaign ) );
	}

	public function test_bulk_handler_requires_a_valid_nonce(): void {
		$campaign = $this->make_campaign();

		$_POST = array(
			'_wpnonce'       => 'invalid',
			'bulk_operation' => 'trash',
			'campaign_ids'   => array( (string) $campaign ),
		);
		$_REQUEST = $_POST;

		try {
			WorkflowCatalogActions::campaigns();
			$this->fail( 'The handler must stop on an invalid nonce.' );
		} catch ( WPDieException $exception ) {
			$this->assertSame( 'draft', get_post_status( $campaign ) );
		}
	}

	public function test_campaign_bulk_redirect_keeps_zero_search_status_and_page(): void {
		$campaign = $this->make_campaign();

		$this->post(
			WorkflowCatalogActions::CAMPAIGN_ACTION,
			array(
				'bulk_operation' => 'trash',
				'campaign_ids'   => array( (string) $campaign ),
				'filter_search'  => '0',
				'filter_status'  => Campaigns::STATUS_DRAFT,
				'filter_paged'   => '2',
			)
		);

		$args = $this->query_args(
			$this->capture_redirect( static fn() => WorkflowCatalogActions::campaigns() )
		);

		$this->assertSame( '0', $args['s'] ?? '' );
		$this->assertSame( Campaigns::STATUS_DRAFT, $args['status'] ?? '' );
		$this->assertSame( '2', $args['paged'] ?? '' );
	}

	public function test_automation_bulk_status_and_delete_use_repository_validation(): void {
		$first  = $this->make_automation();
		$second = $this->make_automation();
		$template = $this->make_template();

		$this->post(
			WorkflowCatalogActions::AUTOMATION_ACTION,
			array(
				'bulk_operation' => 'status',
				'bulk_status'    => AutomationRepository::STATUS_ACTIVE,
				'automation_ids' => array( (string) $first, (string) $second ),
			)
		);

		$args = $this->query_args(
			$this->capture_redirect( static fn() => WorkflowCatalogActions::automations() )
		);

		$this->assertSame( 'bulk_status', $args['nowcrm_notice'] ?? '' );
		$this->assertSame( '2', $args['nowcrm_deleted'] ?? '' );
		$this->assertSame( AutomationRepository::STATUS_ACTIVE, $this->automations->get( $first )['status'] );
		$this->assertSame( AutomationRepository::STATUS_ACTIVE, $this->automations->get( $second )['status'] );

		$this->post(
			WorkflowCatalogActions::AUTOMATION_ACTION,
			array(
				'bulk_operation' => 'delete',
				'automation_ids' => array( (string) $first, (string) $template ),
			)
		);

		$args = $this->query_args(
			$this->capture_redirect( static fn() => WorkflowCatalogActions::automations() )
		);

		$this->assertSame( 'bulk_deleted', $args['nowcrm_notice'] ?? '' );
		$this->assertSame( '1', $args['nowcrm_deleted'] ?? '' );
		$this->assertSame( '1', $args['nowcrm_skipped'] ?? '' );
		$this->assertNull( $this->automations->get( $first ) );
		$this->assertSame( AutomationRepository::STATUS_ACTIVE, $this->automations->get( $second )['status'] );
		$this->assertNotNull( get_post( $template ) );
	}

	public function test_automation_bulk_rejects_an_unknown_status_without_writing(): void {
		$automation = $this->make_automation();

		$this->post(
			WorkflowCatalogActions::AUTOMATION_ACTION,
			array(
				'bulk_operation' => 'status',
				'bulk_status'    => 'deleted',
				'automation_ids' => array( (string) $automation ),
			)
		);

		$args = $this->query_args(
			$this->capture_redirect( static fn() => WorkflowCatalogActions::automations() )
		);

		$this->assertSame( 'invalid_operation', $args['nowcrm_notice'] ?? '' );
		$this->assertSame( AutomationRepository::STATUS_ACTIVE, $this->automations->get( $automation )['status'] );
	}

	public function test_sequence_bulk_deletes_only_its_own_selected_rows(): void {
		$sequence = $this->make_sequence();
		$campaign = $this->make_campaign();

		$this->post(
			WorkflowCatalogActions::SEQUENCE_ACTION,
			array(
				'bulk_operation' => 'delete',
				'sequence_ids'   => array( (string) $sequence, (string) $campaign ),
			)
		);

		$args = $this->query_args(
			$this->capture_redirect( static fn() => WorkflowCatalogActions::sequences() )
		);

		$this->assertSame( 'bulk_deleted', $args['nowcrm_notice'] ?? '' );
		$this->assertSame( '1', $args['nowcrm_deleted'] ?? '' );
		$this->assertSame( '1', $args['nowcrm_skipped'] ?? '' );
		$this->assertNull( $this->sequences->get( $sequence ) );
		$this->assertNotNull( get_post( $campaign ) );
	}

	public function test_template_bulk_deletes_only_selected_templates(): void {
		$first  = $this->make_template( array( 'name' => 'Selected one' ) );
		$second = $this->make_template( array( 'name' => 'Selected two' ) );
		$keep   = $this->make_template( array( 'name' => 'Keep me' ) );
		$campaign = $this->make_campaign();

		$this->post(
			WorkflowCatalogActions::TEMPLATE_ACTION,
			array(
				'template_ids' => array( (string) $first, (string) $second, (string) $campaign ),
				'filter_status' => TemplateRepository::STATUS_PUBLISH,
			),
			'page'
		);

		$args = $this->query_args(
			$this->capture_redirect( static fn() => WorkflowCatalogActions::templates() )
		);

		$this->assertSame( 'bulk_deleted', $args['nowcrm_notice'] ?? '' );
		$this->assertSame( '2', $args['nowcrm_deleted'] ?? '' );
		$this->assertSame( '1', $args['nowcrm_skipped'] ?? '' );
		$this->assertNull( get_post( $first ) );
		$this->assertNull( get_post( $second ) );
		$this->assertNotNull( get_post( $keep ) );
		$this->assertNotNull( get_post( $campaign ) );
	}

	public function test_template_all_scope_requeries_the_server_filter(): void {
		$alpha_one = $this->make_template( array( 'name' => 'Alpha one' ) );
		$alpha_two = $this->make_template( array( 'name' => 'Alpha two' ) );
		$beta      = $this->make_template( array( 'name' => 'Beta one' ) );

		$this->post(
			WorkflowCatalogActions::TEMPLATE_ACTION,
			array(
				'filter_search' => 'Alpha',
				'filter_status' => TemplateRepository::STATUS_PUBLISH,
			),
			'all'
		);

		$args = $this->query_args(
			$this->capture_redirect( static fn() => WorkflowCatalogActions::templates() )
		);

		$this->assertSame( '2', $args['nowcrm_deleted'] ?? '' );
		$this->assertNull( get_post( $alpha_one ) );
		$this->assertNull( get_post( $alpha_two ) );
		$this->assertNotNull( get_post( $beta ) );
	}

	public function test_template_all_scope_fails_closed_on_an_unknown_filter(): void {
		$template = $this->make_template( array( 'name' => 'Alpha one' ) );

		$this->post(
			WorkflowCatalogActions::TEMPLATE_ACTION,
			array( 'filter_status' => 'archived' ),
			'all'
		);

		$args = $this->query_args(
			$this->capture_redirect( static fn() => WorkflowCatalogActions::templates() )
		);

		$this->assertSame( 'invalid_filter', $args['nowcrm_notice'] ?? '' );
		$this->assertNotNull( get_post( $template ) );
	}

	public function test_template_bulk_fails_closed_above_the_bulk_limit(): void {
		$template = $this->make_template( array( 'name' => 'Should survive' ) );
		$ids      = array_merge( array( (string) $template ), array_map( 'strval', range( 900000, 900099 ) ) );

		$this->post(
			WorkflowCatalogActions::TEMPLATE_ACTION,
			array( 'template_ids' => $ids ),
			'page'
		);

		$args = $this->query_args(
			$this->capture_redirect( static fn() => WorkflowCatalogActions::templates() )
		);

		$this->assertSame( 'too_many', $args['nowcrm_notice'] ?? '' );
		$this->assertSame( (string) TemplateRepository::BULK_LIMIT, $args['nowcrm_limit'] ?? '' );
		$this->assertNotNull( get_post( $template ) );
	}

	public function test_template_bulk_skips_ids_the_user_cannot_delete(): void {
		$template = $this->make_template( array( 'name' => 'Protected template' ) );

		$user = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$user = get_user_by( 'id', $user );
		$this->assertNotFalse( $user );
		$user->add_cap( 'nowcrm_manage_contacts' );
		wp_set_current_user( $user->ID );

		$this->post(
			WorkflowCatalogActions::TEMPLATE_ACTION,
			array( 'template_ids' => array( (string) $template ) ),
			'page'
		);

		$args = $this->query_args(
			$this->capture_redirect( static fn() => WorkflowCatalogActions::templates() )
		);

		$this->assertSame( 'bulk_deleted', $args['nowcrm_notice'] ?? '' );
		$this->assertSame( '0', $args['nowcrm_deleted'] ?? '' );
		$this->assertSame( '1', $args['nowcrm_skipped'] ?? '' );
		$this->assertNotNull( get_post( $template ) );
	}

	/**
	 * Fill POST and REQUEST with one valid nonce.
	 *
	 * @param string               $action Nonce action.
	 * @param array<string, mixed> $data   Extra fields.
	 * @param string               $scope  Scope value; an empty string omits it.
	 */
	private function post( string $action, array $data, string $scope = '' ): void {
		$_POST = array_merge(
			array(
				'_wpnonce' => wp_create_nonce( $action ),
				'action'   => $action,
			),
			$data
		);

		if ( '' !== $scope ) {
			$_POST['scope'] = $scope;
		}

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
			throw new NowCrm_Catalog_Redirect_Captured( $captured );
		};

		add_filter( 'wp_redirect', $filter, 1 );

		try {
			$handler();
		} catch ( NowCrm_Catalog_Redirect_Captured $exception ) {
			$captured = $exception->getMessage();
		} finally {
			remove_filter( 'wp_redirect', $filter, 1 );
		}

		$this->assertNotSame( '', $captured, 'The handler did not redirect.' );

		return $captured;
	}

	private function make_template( array $overrides = array() ): int {
		$id = $this->templates->create(
			array_merge(
				array(
					'name'   => 'Template ' . wp_generate_password( 6, false ),
					'mode'   => Campaigns::MODE_HTML,
					'status' => TemplateRepository::STATUS_PUBLISH,
					'html'   => '<p>Fixture</p>',
				),
				$overrides
			)
		);

		$this->assertIsInt( $id );

		return (int) $id;
	}

	private function make_automation(): int {
		$id = $this->automations->save(
			array(
				'name'        => 'Fixture automation ' . wp_generate_password( 6, false ),
				'status'      => AutomationRepository::STATUS_ACTIVE,
				'trigger_key' => TriggerRegistry::ORDER_COMPLETED,
				'entry_config' => array( 'run_once' => false ),
				'steps'       => array(
					array( 'kind' => AutomationRepository::KIND_WAIT, 'delay_seconds' => 60 ),
				),
			)
		);

		$this->assertIsInt( $id );

		return (int) $id;
	}

	private function make_sequence(): int {
		$id = $this->sequences->create(
			array(
				'name'          => 'Fixture sequence ' . wp_generate_password( 6, false ),
				'status'        => SequenceRepository::STATUS_ACTIVE,
				'allow_reentry' => false,
				'audience'      => array( 'type' => 'all' ),
				'steps'         => array(
					array(
						'name'          => 'Welcome',
						'subject'       => 'Step one',
						'preheader'     => '',
						'mode'          => 'html',
						'content'       => '<p>One</p>',
						'delay_seconds' => 0,
					),
				),
			)
		);

		$this->assertIsInt( $id );

		return (int) $id;
	}
}
