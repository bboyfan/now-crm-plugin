<?php
/**
 * Reusable template storage, isolation and campaign seeding.
 *
 * @package NowCRM
 */

use NowCRM\Admin\CampaignCreatePage;
use NowCRM\Admin\Menu;
use NowCRM\Admin\TemplatePage;
use NowCRM\Campaign\Campaigns;
use NowCRM\Templates\TemplateRepository;

/**
 * Sentinel thrown by the redirect filter so a handler never reaches exit().
 */
final class NowCrm_Template_Redirect_Captured extends \RuntimeException {}

/**
 * @covers \NowCRM\Templates\TemplateRepository
 * @covers \NowCRM\Admin\TemplateListPage
 * @covers \NowCRM\Admin\TemplatePage
 * @covers \NowCRM\Campaign\Campaigns
 */
final class TemplatesTest extends NowCrmTestCase {

	private TemplateRepository $templates;

	public function set_up(): void {
		parent::set_up();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->templates = new TemplateRepository();
	}

	public function tear_down(): void {
		$_POST    = array();
		$_REQUEST = array();
		wp_set_current_user( 0 );

		parent::tear_down();
	}

	public function test_template_content_modes_duplicate_independence_and_permanent_delete(): void {
		$blocks = '<!-- wp:paragraph --><p>Hello {{first_name}}</p><!-- /wp:paragraph -->';
		$id     = $this->make_template(
			array(
				'name'      => 'Welcome',
				'subject'   => 'Welcome subject',
				'preheader' => 'Preview text',
				'mode'      => Campaigns::MODE_BLOCKS,
				'blocks'    => $blocks,
				'html'      => '<p>Stored HTML</p>',
				'status'    => TemplateRepository::STATUS_PUBLISH,
			)
		);

		$template = $this->templates->get( $id );
		$this->assertSame( $blocks, $template['blocks'] );
		$this->assertSame( 'Welcome subject', $template['subject'] );
		$this->assertSame( TemplateRepository::STATUS_PUBLISH, $template['status'] );

		$updated = $this->templates->update(
			$id,
			array(
				'name'    => 'Welcome',
				'subject' => 'Updated subject',
				'mode'    => Campaigns::MODE_HTML,
				'html'    => '<p>Raw</p><script>alert(1)</script><a href="javascript:alert(1)" onclick="bad()">x</a>',
				'status'  => TemplateRepository::STATUS_PUBLISH,
			)
		);
		$this->assertTrue( $updated );

		$template = $this->templates->get( $id );
		$this->assertSame( Campaigns::MODE_HTML, $template['mode'] );
		$this->assertSame( 'Updated subject', $template['subject'] );
		$this->assertSame( $blocks, $template['blocks'], 'Switching mode must preserve the block source.' );
		$this->assertStringNotContainsString( '<script', $template['html'] );
		$this->assertStringNotContainsString( 'javascript:', $template['html'] );
		$this->assertStringNotContainsString( 'onclick', $template['html'] );

		$copy = $this->templates->duplicate( $id );
		$this->assertIsInt( $copy );
		$this->assertNotSame( $id, $copy );

		$copied = $this->templates->get( $copy );
		$this->assertSame( TemplateRepository::STATUS_DRAFT, $copied['status'] );
		$this->assertSame( $template['subject'], $copied['subject'] );
		$this->assertSame( $template['html'], $copied['html'] );

		$this->templates->update(
			$id,
			array(
				'name'    => 'Changed original',
				'subject' => 'Changed original subject',
				'mode'    => Campaigns::MODE_HTML,
				'html'    => '<p>Changed original HTML</p>',
				'status'  => TemplateRepository::STATUS_PUBLISH,
			)
		);

		$copied = $this->templates->get( $copy );
		$this->assertSame( 'Updated subject', $copied['subject'], 'A copy must not follow later edits of the original.' );
		$this->assertSame( $template['html'], $copied['html'] );

		$this->assertTrue( $this->templates->delete( $copy ) );
		$this->assertNull( get_post( $copy ), 'Delete must remove the template row permanently, not move it to trash.' );
		$this->assertNotNull( get_post( $id ) );
	}

	public function test_template_directory_search_paging_and_type_isolation(): void {
		for ( $i = 1; $i <= 5; ++$i ) {
			$this->make_template(
				array(
					'name'   => 'Alpha ' . $i,
					'blocks' => '<!-- wp:paragraph --><p>Alpha ' . $i . '</p><!-- /wp:paragraph -->',
				)
			);
		}

		$result = $this->templates->paginate( array( 'page' => 3, 'per_page' => 2, 'search' => 'Alpha' ) );
		$this->assertSame( 5, $result['total'] );
		$this->assertSame( 3, $result['pages'] );
		$this->assertCount( 1, $result['items'] );

		$campaign = $this->make_campaign();
		$before   = $this->campaign_ids();

		$this->assertNull( $this->templates->get( $campaign ), 'A campaign ID must never resolve as a template.' );
		$this->assertNull( $this->templates->for_campaign( $campaign ) );
		$this->assertWPError( $this->templates->update( $campaign, array( 'name' => 'Cross type' ) ) );
		$this->assertWPError( $this->templates->delete( $campaign ) );

		$this->assertNull( $this->templates->get( 0 ) );
		$this->assertNull( $this->templates->get( -3 ) );
		$this->assertNull( $this->templates->get( 999999 ) );
		$this->assertWPError( $this->templates->update( 0, array( 'name' => 'Missing' ) ) );

		$seeded = Campaigns::create_draft( 'Cross type campaign', Campaigns::MODE_BLOCKS, $campaign );
		$this->assertWPError( $seeded );
		$this->assertSame( 'nowcrm_template_not_found', $seeded->get_error_code() );
		$this->assertSame( $before, $this->campaign_ids(), 'A failed template import must not create a campaign.' );

		$draft = $this->make_template(
			array(
				'name'   => 'Draft only',
				'blocks' => '<!-- wp:paragraph --><p>Draft</p><!-- /wp:paragraph -->',
				'status' => TemplateRepository::STATUS_DRAFT,
			)
		);
		$this->assertNull( $this->templates->for_campaign( $draft ), 'Unpublished templates must not seed campaigns.' );
		$this->assertWPError( Campaigns::create_draft( 'Draft template campaign', Campaigns::MODE_BLOCKS, $draft ) );
		$this->assertSame( $before, $this->campaign_ids() );
	}

	public function test_campaign_from_template_copies_content_and_keeps_snapshot_isolated(): void {
		$blocks = '<!-- wp:paragraph --><p>Template body {{first_name}}</p><!-- /wp:paragraph -->';
		$id     = $this->make_template(
			array(
				'name'      => 'Campaign seed',
				'subject'   => 'Template subject',
				'preheader' => 'Template preview',
				'mode'      => Campaigns::MODE_BLOCKS,
				'blocks'    => $blocks,
				'html'      => '<p>Template HTML</p>',
				'status'    => TemplateRepository::STATUS_PUBLISH,
			)
		);

		$existing = $this->make_campaign();
		$before   = Campaigns::draft( Campaigns::get( $existing ) );

		$campaign_id = Campaigns::create_draft( 'From template', Campaigns::MODE_HTML, $id );
		$this->assertIsInt( $campaign_id );

		$post  = Campaigns::get( $campaign_id );
		$draft = Campaigns::draft( $post );
		$this->assertSame( Campaigns::MODE_BLOCKS, $draft['mode'], 'The template mode owns the imported content mode.' );
		$this->assertSame( $blocks, $draft['blocks'] );
		$this->assertSame( 'Template subject', $draft['subject'] );
		$this->assertSame( 'Template preview', $draft['preheader'] );
		$this->assertSame( '<p>Template HTML</p>', $draft['html'] );
		$this->assertSame( Campaigns::STATUS_DRAFT, Campaigns::state( $campaign_id ) );
		$this->assertFalse( metadata_exists( 'post', $campaign_id, '_nowcrm_template_id' ), 'Campaigns must not store a mutable template reference.' );

		$revision = Campaigns::revision( $draft );
		$snapshot = Campaigns::snapshot( $campaign_id, $draft );
		$this->assertStringContainsString( 'Template body', $snapshot['content'] );
		$this->assertSame( 'Template subject', $snapshot['subject'] );
		$this->assertSame( 'Template preview', $snapshot['preheader'] );

		$this->templates->update(
			$id,
			array(
				'name'      => 'Changed template',
				'subject'   => 'Changed subject',
				'preheader' => 'Changed preview',
				'mode'      => Campaigns::MODE_HTML,
				'blocks'    => '<!-- wp:paragraph --><p>Changed blocks</p><!-- /wp:paragraph -->',
				'html'      => '<p>Changed HTML</p>',
				'status'    => TemplateRepository::STATUS_PUBLISH,
			)
		);

		$after = Campaigns::draft( Campaigns::get( $campaign_id ) );
		$this->assertSame( $blocks, $after['blocks'] );
		$this->assertSame( 'Template subject', $after['subject'] );
		$this->assertSame( $revision, Campaigns::revision( $after ), 'Template edits must not change the campaign draft revision.' );
		$this->assertSame( $snapshot['content'], Campaigns::snapshot( $campaign_id, $after )['content'] );
		$this->assertSame( $before, Campaigns::draft( Campaigns::get( $existing ) ), 'Existing campaigns must not change.' );

		$this->assertTrue( $this->templates->delete( $id ) );
		$this->assertSame( $blocks, Campaigns::draft( Campaigns::get( $campaign_id ) )['blocks'] );
		$this->assertSame( $snapshot['content'], Campaigns::snapshot( $campaign_id, Campaigns::draft( Campaigns::get( $campaign_id ) ) )['content'] );

		$blank = Campaigns::create_draft( 'Blank campaign', Campaigns::MODE_HTML );
		$this->assertIsInt( $blank );
		$blank_draft = Campaigns::draft( Campaigns::get( $blank ) );
		$this->assertSame( Campaigns::MODE_HTML, $blank_draft['mode'] );
		$this->assertSame( '', $blank_draft['subject'] );
		$this->assertSame( '', $blank_draft['blocks'] );
		$this->assertFalse( metadata_exists( 'post', $blank, '_nowcrm_template_id' ) );
	}

	public function test_campaign_template_apply_replaces_active_body_and_rejects_stale_or_non_draft_writes(): void {
		$template_blocks = '<!-- wp:paragraph --><p>Editor template {{first_name}}</p><!-- /wp:paragraph -->';
		$id              = $this->make_template(
			array(
				'name'      => 'Editor template',
				'subject'   => 'Editor subject',
				'preheader' => 'Editor preview',
				'mode'      => Campaigns::MODE_BLOCKS,
				'blocks'    => $template_blocks,
				'html'      => '<p>Editor HTML</p>',
				'status'    => TemplateRepository::STATUS_PUBLISH,
			)
		);
		$unpublished = $this->make_template(
			array(
				'name'   => 'Hidden template',
				'blocks' => '<!-- wp:paragraph --><p>Hidden</p><!-- /wp:paragraph -->',
				'status' => TemplateRepository::STATUS_DRAFT,
			)
		);

		$campaign = $this->make_campaign();
		update_post_meta( $campaign, Campaigns::META_FROM_NAME, 'Sender' );
		update_post_meta( $campaign, Campaigns::META_FROM_ADDR, 'sender@example.test' );
		update_post_meta( $campaign, Campaigns::META_REPLY_TO, 'reply@example.test' );
		update_post_meta( $campaign, Campaigns::META_FOOTER, 'Footer' );
		$before = Campaigns::draft( Campaigns::get( $campaign ) );

		$read = $this->campaign_api( 'GET', $campaign )->get_data();
		$this->assertContains( $id, array_column( $read['templates'], 'id' ) );
		$this->assertNotContains( $unpublished, array_column( $read['templates'], 'id' ), 'Only published templates are selectable.' );

		$revision = $read['revision'];
		$applied  = $this->campaign_api( 'POST', $campaign, array( 'template_id' => $id, 'revision' => $revision ) );
		$this->assertSame( 200, $applied->get_status() );

		$draft = $applied->get_data()['draft'];
		$this->assertSame( Campaigns::MODE_BLOCKS, $draft['mode'] );
		$this->assertSame( $template_blocks, $draft['blocks'] );
		$this->assertSame( $before['html'], $draft['html'], 'Applying a block template must keep the draft HTML body.' );
		$this->assertSame( 'Editor subject', $draft['subject'] );
		$this->assertSame( 'Editor preview', $draft['preheader'] );

		foreach ( array( 'title', 'audience', 'ab', 'tracking', 'utm', 'from_name', 'from_address', 'reply_to', 'footer_text' ) as $field ) {
			$this->assertSame( $before[ $field ], $draft[ $field ], 'Applying a template must preserve campaign field: ' . $field );
		}

		$snapshot         = Campaigns::snapshot( $campaign, $draft );
		$applied_revision = Campaigns::revision( $draft );

		$stale = $this->campaign_api( 'POST', $campaign, array( 'template_id' => $id, 'revision' => $revision ) );
		$this->assertSame( 409, $stale->get_status(), 'A stale revision must not overwrite the applied draft.' );

		$this->templates->update(
			$id,
			array(
				'name'      => 'Editor template changed',
				'subject'   => 'Changed subject',
				'preheader' => 'Changed preview',
				'mode'      => Campaigns::MODE_HTML,
				'blocks'    => '<!-- wp:paragraph --><p>Changed blocks</p><!-- /wp:paragraph -->',
				'html'      => '<p>Changed HTML</p>',
				'status'    => TemplateRepository::STATUS_PUBLISH,
			)
		);

		$after = Campaigns::draft( Campaigns::get( $campaign ) );
		$this->assertSame( $template_blocks, $after['blocks'], 'Later template edits must not change the campaign draft.' );
		$this->assertSame( 'Editor subject', $after['subject'] );
		$this->assertSame( $applied_revision, Campaigns::revision( $after ) );
		$this->assertSame( $snapshot['content'], Campaigns::snapshot( $campaign, $after )['content'] );
		$this->assertSame( $snapshot['subject'], Campaigns::snapshot( $campaign, $after )['subject'] );

		$hidden = $this->campaign_api( 'POST', $campaign, array( 'template_id' => $unpublished, 'revision' => $applied_revision ) );
		$this->assertSame( 404, $hidden->get_status() );
		$this->assertSame( 'nowcrm_template_not_found', $hidden->get_data()['code'] );

		$scheduled = $this->make_campaign( array( 'status' => Campaigns::STATUS_SCHEDULED ) );
		update_post_meta(
			$scheduled,
			Campaigns::META_SNAPSHOT,
			wp_slash( (string) wp_json_encode( array( 'content' => '<p>Queued</p>', 'subject' => 'Queued subject' ) ) )
		);
		$scheduled_draft = Campaigns::draft( Campaigns::get( $scheduled ) );
		$rejected        = $this->campaign_api( 'POST', $scheduled, array( 'template_id' => $id, 'revision' => Campaigns::revision( $scheduled_draft ) ) );

		$this->assertSame( 409, $rejected->get_status() );
		$this->assertSame( 'nowcrm_campaign_state', $rejected->get_data()['code'] );
		$this->assertSame( $scheduled_draft, Campaigns::draft( Campaigns::get( $scheduled ) ) );
		$this->assertSame( '<p>Queued</p>', Campaigns::template( $scheduled )['content'], 'A non-draft snapshot must not change.' );
	}

	public function test_campaign_create_form_creates_requested_mode_and_ignores_legacy_template_fields(): void {
		// A legacy form post may still send template and settings fields. The
		// agreed create form is title+mode only, so none of them may seed the
		// draft and both modes must land on the campaign workspace.
		$template = $this->make_template(
			array(
				'name'      => 'Not imported by create',
				'subject'   => 'Template subject',
				'preheader' => 'Template preview',
				'mode'      => Campaigns::MODE_HTML,
				'blocks'    => '<!-- wp:paragraph --><p>Template blocks</p><!-- /wp:paragraph -->',
				'html'      => '<p>Template HTML</p>',
				'status'    => TemplateRepository::STATUS_PUBLISH,
			)
		);

		$_POST    = array(
			'_wpnonce'    => wp_create_nonce( 'nowcrm_create_campaign' ),
			'title'       => 'Title and mode only',
			'mode'        => Campaigns::MODE_BLOCKS,
			'template_id' => (string) $template,
			'subject'     => 'Legacy subject',
			'preheader'   => 'Legacy preview',
		);
		$_REQUEST = $_POST;

		$location = $this->capture_redirect( static fn() => CampaignCreatePage::handle_create() );
		$args     = $this->query_args( $location );

		$this->assertSame( Menu::CAMPAIGNS_SLUG, $args['page'] ?? '' );
		$this->assertArrayHasKey( 'campaign', $args );
		$campaign = Campaigns::get( (int) $args['campaign'] );
		$this->assertNotNull( $campaign );

		$draft = Campaigns::draft( $campaign );
		$this->assertSame( Campaigns::MODE_BLOCKS, $draft['mode'] );
		$this->assertSame( 'Title and mode only', $draft['title'] );
		$this->assertSame( '', $draft['subject'], 'Create must not read legacy subject fields.' );
		$this->assertSame( '', $draft['preheader'] );
		$this->assertSame( '', $draft['blocks'] );
		$this->assertSame( '', $draft['html'], 'A block campaign must not import the template HTML body.' );

		$_POST    = array(
			'_wpnonce' => wp_create_nonce( 'nowcrm_create_campaign' ),
			'title'    => 'Raw HTML campaign',
			'mode'     => Campaigns::MODE_HTML,
		);
		$_REQUEST = $_POST;

		$location = $this->capture_redirect( static fn() => CampaignCreatePage::handle_create() );
		$args     = $this->query_args( $location );

		$this->assertSame( Menu::CAMPAIGNS_SLUG, $args['page'] ?? '' );
		$this->assertArrayHasKey( 'campaign', $args );
		$raw = Campaigns::draft( Campaigns::get( (int) $args['campaign'] ) );
		$this->assertSame( Campaigns::MODE_HTML, $raw['mode'] );
		$this->assertSame( '', $raw['subject'] );
		$this->assertSame( '', $raw['preheader'] );
		$this->assertSame( '', $raw['html'] );
	}

	public function test_template_handlers_enforce_capability_nonce_and_permanent_delete(): void {
		$id = $this->make_template(
			array(
				'name'   => 'Guarded',
				'blocks' => '<!-- wp:paragraph --><p>Guarded</p><!-- /wp:paragraph -->',
			)
		);

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$_POST    = array(
			'_wpnonce'    => wp_create_nonce( 'nowcrm_save_template' ),
			'template_id' => (string) $id,
			'name'        => 'Subscriber change',
		);
		$_REQUEST = $_POST;

		try {
			TemplatePage::save_template();
			$this->fail( 'A subscriber must not save a template.' );
		} catch ( WPDieException $exception ) {
			$this->addToAssertionCount( 1 );
		}

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$_POST    = array( 'template_id' => (string) $id, 'name' => 'No nonce' );
		$_REQUEST = $_POST;

		try {
			TemplatePage::save_template();
			$this->fail( 'A missing nonce must not save a template.' );
		} catch ( WPDieException $exception ) {
			$this->addToAssertionCount( 1 );
		}

		$this->assertSame( 'Guarded', $this->templates->get( $id )['name'] );

		$_POST    = array(
			'_wpnonce'    => wp_create_nonce( 'nowcrm_save_template' ),
			'template_id' => (string) $id,
			'name'        => 'Allowed change',
			'subject'     => 'Allowed subject',
			'preheader'   => 'Allowed preview',
			'mode'        => Campaigns::MODE_BLOCKS,
			'blocks'      => '<!-- wp:paragraph --><p>Allowed body</p><!-- /wp:paragraph -->',
			'html'        => '',
			'status'      => TemplateRepository::STATUS_PUBLISH,
		);
		$_REQUEST = $_POST;

		$location = $this->capture_redirect( static fn() => TemplatePage::save_template() );
		$args     = $this->query_args( $location );
		$this->assertSame( 'saved', $args['nowcrm_notice'] ?? '' );

		$saved = $this->templates->get( $id );
		$this->assertSame( 'Allowed change', $saved['name'] );
		$this->assertSame( TemplateRepository::STATUS_PUBLISH, $saved['status'] );

		$_POST    = array(
			'_wpnonce'    => wp_create_nonce( 'nowcrm_delete_template' ),
			'template_id' => (string) $id,
		);
		$_REQUEST = $_POST;

		$location = $this->capture_redirect( static fn() => \NowCRM\Admin\TemplateListPage::handle_delete() );
		$args     = $this->query_args( $location );
		$this->assertSame( 'deleted', $args['nowcrm_notice'] ?? '' );
		$this->assertNull( get_post( $id ) );

		$campaign = $this->make_campaign();
		$_POST    = array(
			'_wpnonce'    => wp_create_nonce( 'nowcrm_delete_template' ),
			'template_id' => (string) $campaign,
		);
		$_REQUEST = $_POST;

		$location = $this->capture_redirect( static fn() => \NowCRM\Admin\TemplateListPage::handle_delete() );
		$args     = $this->query_args( $location );
		$this->assertSame( 'not_found', $args['nowcrm_notice'] ?? '' );
		$this->assertNotNull( Campaigns::get( $campaign ) );
	}

	public function test_bulk_delete_is_bounded_and_ignores_foreign_or_missing_ids(): void {
		$first  = $this->make_template(
			array(
				'name'   => 'Bulk one',
				'blocks' => '<!-- wp:paragraph --><p>One</p><!-- /wp:paragraph -->',
			)
		);
		$second = $this->make_template(
			array(
				'name'   => 'Bulk two',
				'blocks' => '<!-- wp:paragraph --><p>Two</p><!-- /wp:paragraph -->',
			)
		);
		$campaign = $this->make_campaign();

		$ids = array_merge( range( 900000, 900099 ), array( $first, $second, $campaign ) );

		$this->assertSame( 0, $this->templates->delete_many( $ids ) );
		$this->assertNotNull( get_post( $first ), 'IDs beyond the first 100 must not be processed.' );
		$this->assertNotNull( get_post( $second ) );

		$this->assertSame( 2, $this->templates->delete_many( array( $first, $second, $campaign ) ) );
		$this->assertNull( get_post( $first ) );
		$this->assertNull( get_post( $second ) );
		$this->assertNotNull( Campaigns::get( $campaign ), 'A campaign ID must never be deleted by template bulk delete.' );

		$_POST    = array(
			'_wpnonce'     => wp_create_nonce( 'nowcrm_bulk_delete_templates' ),
			'template_ids' => array(),
		);
		$_REQUEST = $_POST;

		$location = $this->capture_redirect( static fn() => \NowCRM\Admin\TemplateListPage::handle_bulk_delete() );
		$args     = $this->query_args( $location );
		$this->assertSame( 'no_selection', $args['nowcrm_notice'] ?? '' );
	}

	/**
	 * Dispatch one campaign workspace REST request.
	 *
	 * @param array<string, mixed> $data Body or query fields.
	 */
	private function campaign_api( string $method, int $id, array $data = array() ): WP_REST_Response {
		$request = new \WP_REST_Request( $method, '/nowcrm/v1/campaigns/' . $id );

		if ( 'GET' === $method ) {
			$request->set_query_params( $data );
		} else {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( (string) wp_json_encode( $data ) );
		}

		return rest_do_request( $request );
	}

	/**
	 * Create a template fixture with valid content.
	 *
	 * @param array<string, mixed> $overrides Field overrides.
	 */
	private function make_template( array $overrides = array() ): int {
		$id = $this->templates->create(
			array_merge(
				array(
					'name'      => 'Template ' . wp_generate_password( 6, false ),
					'subject'   => 'Subject',
					'preheader' => 'Preview',
					'mode'      => Campaigns::MODE_BLOCKS,
					'blocks'    => '<!-- wp:paragraph --><p>Body</p><!-- /wp:paragraph -->',
					'html'      => '',
					'status'    => TemplateRepository::STATUS_PUBLISH,
				),
				$overrides
			)
		);

		$this->assertIsInt( $id, 'Template fixture creation failed.' );

		return (int) $id;
	}

	/**
	 * Count campaign rows, including every non-trash status.
	 */
	private function campaign_ids(): array {
		return get_posts(
			array(
				'post_type'      => Campaigns::POST_TYPE,
				'post_status'    => array( 'draft', 'publish', 'pending', 'future', 'private' ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);
	}

	/**
	 * Parse the query string of a captured redirect.
	 *
	 * @return array<string, string>
	 */
	private function query_args( string $location ): array {
		$query = (string) wp_parse_url( $location, PHP_URL_QUERY );
		parse_str( $query, $args );

		return array_map( 'strval', $args );
	}

	/**
	 * Capture the wp_safe_redirect() target of a handler.
	 *
	 * @param callable $handler Handler under test.
	 */
	private function capture_redirect( callable $handler ): string {
		$captured = '';
		$filter   = static function ( $location ) use ( &$captured ): void {
			$captured = (string) $location;
			throw new NowCrm_Template_Redirect_Captured( $captured );
		};

		add_filter( 'wp_redirect', $filter, 1 );

		try {
			$handler();
		} catch ( NowCrm_Template_Redirect_Captured $exception ) {
			$captured = $exception->getMessage();
		} finally {
			remove_filter( 'wp_redirect', $filter, 1 );
		}

		$this->assertNotSame( '', $captured, 'The handler did not redirect.' );

		return $captured;
	}
}
