<?php
/**
 * Native editor bridge: working posts, content saves and conflict handling.
 *
 * @package NowCRM
 */

use NowCRM\Admin\ContentEditorPage;
use NowCRM\Campaign\Campaigns;
use NowCRM\Editor\DummyPost;
use NowCRM\Sequences\SequenceRepository;
use NowCRM\Support\Capabilities;
use NowCRM\Templates\TemplateRepository;

final class EditorTest extends NowCrmTestCase {

	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	public function test_working_post_is_draft_only_and_not_public(): void {
		$campaign = $this->make_campaign( array( 'content' => '<!-- wp:paragraph --><p>Secret</p><!-- /wp:paragraph -->' ) );
		$dummy    = DummyPost::ensure( 'campaign', $campaign );
		$this->assertIsInt( $dummy );
		$this->assertSame( 'draft', get_post_status( $dummy ) );

		wp_update_post( array( 'ID' => $dummy, 'post_status' => 'publish' ) );
		$this->assertSame( 'draft', get_post_status( $dummy ) );

		wp_set_current_user( 0 );
		$request = new WP_REST_Request( 'GET', '/wp/v2/nowcrm-editor/' . $dummy );
		$response = rest_do_request( $request );
		$this->assertContains( $response->get_status(), array( 401, 403 ) );
	}

	public function test_working_post_access_requires_authority_over_its_target(): void {
		$campaign = $this->make_campaign();
		$dummy    = DummyPost::ensure( 'campaign', $campaign );
		$this->assertIsInt( $dummy );

		$admin = wp_get_current_user();
		$this->assertTrue( user_can( $admin, 'read_post', $dummy ) );
		$this->assertTrue( user_can( $admin, 'edit_post', $dummy ) );
		$this->assertTrue( user_can( $admin, 'delete_post', $dummy ) );

		$editor = $this->crm_editor();
		$this->assertFalse( user_can( $editor, 'read_post', $dummy ), 'A manager who cannot edit the campaign must not read its working post.' );
		$this->assertFalse( user_can( $editor, 'edit_post', $dummy ) );
		$this->assertFalse( user_can( $editor, 'delete_post', $dummy ) );

		// The working post belongs to the admin. The campaign author decides.
		$this->assertSame( (int) $admin->ID, (int) get_post( $dummy )->post_author );
		wp_update_post(
			array(
				'ID'          => $campaign,
				'post_author' => $editor->ID,
			)
		);

		$this->assertTrue( user_can( $editor, 'read_post', $dummy ) );
		$this->assertTrue( user_can( $editor, 'edit_post', $dummy ) );
		$this->assertFalse( user_can( $editor, 'delete_post', $dummy ), 'Delete requires delete authority on the campaign itself.' );
	}

	public function test_working_post_denied_when_contacts_capability_is_revoked(): void {
		$editor   = $this->crm_editor();
		$campaign = $this->make_campaign();
		wp_update_post(
			array(
				'ID'          => $campaign,
				'post_author' => $editor->ID,
			)
		);

		$dummy = DummyPost::ensure( 'campaign', $campaign );
		$this->assertIsInt( $dummy );
		$this->assertTrue( user_can( $editor, 'edit_post', $dummy ) );

		$editor->remove_cap( Capabilities::MANAGE_CONTACTS );
		$this->assertFalse( user_can( $editor, 'read_post', $dummy ) );
		$this->assertFalse( user_can( $editor, 'edit_post', $dummy ) );

		$editor->add_cap( 'read_nowcrm_editor' );
		$editor->add_cap( 'edit_nowcrm_editor' );
		$editor->add_cap( 'edit_nowcrm_editors' );
		$editor->add_cap( 'edit_others_nowcrm_editors' );
		$this->assertFalse( user_can( $editor, 'edit_post', $dummy ), 'Stored working-post cap names must not bypass the CRM authority.' );
		$this->assertFalse( user_can( $editor, 'edit_nowcrm_editor', $dummy ), 'The working-post meta cap must resolve through the target authority.' );
	}

	public function test_working_post_revision_and_autosave_follow_parent_authority(): void {
		$campaign = $this->make_campaign();
		$dummy    = DummyPost::ensure( 'campaign', $campaign );
		$this->assertIsInt( $dummy );

		$revision = _wp_put_post_revision( $dummy );
		$autosave = _wp_put_post_revision( $dummy, true );
		$this->assertIsInt( $revision );
		$this->assertIsInt( $autosave );

		$admin = wp_get_current_user();
		foreach ( array( $revision, $autosave ) as $child ) {
			$this->assertTrue( user_can( $admin, 'read_post', $child ) );
			$this->assertTrue( user_can( $admin, 'edit_post', $child ) );
			$this->assertTrue( user_can( $admin, 'delete_post', $child ) );
		}

		$editor = $this->crm_editor();
		$this->assertFalse( user_can( $editor, 'edit_post', $revision ) );
		$this->assertFalse( user_can( $editor, 'edit_post', $autosave ) );

		// Revisions belong to the working post, not to their own author.
		wp_update_post(
			array(
				'ID'          => $campaign,
				'post_author' => $editor->ID,
			)
		);
		$this->assertTrue( user_can( $editor, 'edit_post', $revision ) );
		$this->assertTrue( user_can( $editor, 'edit_post', $autosave ) );
	}

	public function test_working_post_with_invalid_target_fails_closed(): void {
		$admin = wp_get_current_user();

		$orphan = wp_insert_post(
			array(
				'post_type'   => DummyPost::POST_TYPE,
				'post_status' => 'draft',
				'post_title'  => 'Orphan working post',
			),
			true
		);
		$this->assertIsInt( $orphan );
		update_post_meta( $orphan, DummyPost::META_TARGET_TYPE, 'campaign' );
		update_post_meta( $orphan, DummyPost::META_TARGET_ID, 999999 );
		$this->assertFalse( user_can( $admin, 'edit_post', $orphan ), 'A working post without a live target must fail closed.' );

		$template = ( new TemplateRepository() )->create(
			array(
				'name'   => 'Mismatch template',
				'mode'   => Campaigns::MODE_BLOCKS,
				'blocks' => '',
			)
		);
		$this->assertIsInt( $template );
		update_post_meta( $orphan, DummyPost::META_TARGET_ID, $template );
		$this->assertTrue( user_can( $admin, 'edit_post', $template ) );
		$this->assertFalse( user_can( $admin, 'edit_post', $orphan ), 'A campaign working post must not borrow template authority.' );

		update_post_meta( $orphan, DummyPost::META_TARGET_TYPE, 'unknown' );
		$this->assertFalse( user_can( $admin, 'edit_post', $orphan ) );
	}

	public function test_working_posts_cannot_be_created_or_published_through_rest(): void {
		$request = new WP_REST_Request( 'POST', '/wp/v2/nowcrm-editor' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( array( 'title' => 'Forged working post', 'status' => 'draft' ) ) );
		$this->assertSame( 403, rest_do_request( $request )->get_status(), 'REST must not create working posts.' );

		$campaign = $this->make_campaign();
		$dummy    = DummyPost::ensure( 'campaign', $campaign );
		$this->assertIsInt( $dummy );

		$request = new WP_REST_Request( 'POST', '/wp/v2/nowcrm-editor/' . $dummy );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( array( 'status' => 'publish' ) ) );
		$this->assertSame( 403, rest_do_request( $request )->get_status(), 'REST must not publish working posts.' );
		$this->assertSame( 'draft', get_post_status( $dummy ) );
	}

	public function test_working_post_follows_source_changes_in_the_same_second(): void {
		$campaign = $this->make_campaign( array( 'content' => '<!-- wp:paragraph --><p>First</p><!-- /wp:paragraph -->' ) );
		update_post_meta( $campaign, Campaigns::META_MODE, Campaigns::MODE_BLOCKS );
		update_post_meta( $campaign, Campaigns::META_STATUS, Campaigns::STATUS_DRAFT );

		$dummy = DummyPost::ensure( 'campaign', $campaign );
		$this->assertIsInt( $dummy );

		wp_update_post(
			array(
				'ID'           => $campaign,
				'post_content' => '<!-- wp:paragraph --><p>Second</p><!-- /wp:paragraph -->',
			)
		);
		$this->assertSame( $dummy, DummyPost::ensure( 'campaign', $campaign ) );
		$this->assertStringContainsString( 'Second', (string) get_post( $dummy )->post_content, 'Same-second source saves must still refresh the working post.' );

		wp_update_post(
			array(
				'ID'           => $dummy,
				'post_content' => '<!-- wp:paragraph --><p>Local only</p><!-- /wp:paragraph -->',
			)
		);
		DummyPost::ensure( 'campaign', $campaign );
		$this->assertStringContainsString( 'Second', (string) get_post( $dummy )->post_content, 'Direct working-post writes must not outlive the campaign source.' );
	}

	public function test_campaign_content_endpoint_uses_revision_and_draft_state(): void {
		$campaign = $this->make_campaign(
			array(
				'content' => '<!-- wp:paragraph --><p>Old</p><!-- /wp:paragraph -->',
				'subject' => 'Keep subject',
			)
		);
		update_post_meta( $campaign, Campaigns::META_MODE, Campaigns::MODE_BLOCKS );
		update_post_meta( $campaign, Campaigns::META_STATUS, Campaigns::STATUS_DRAFT );

		$draft    = Campaigns::draft( Campaigns::get( $campaign ) );
		$revision = Campaigns::revision( $draft );

		$response = $this->content_request( $campaign, '<!-- wp:heading --><h2>New</h2><!-- /wp:heading -->', $revision );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( '<!-- wp:heading --><h2>New</h2><!-- /wp:heading -->', Campaigns::get( $campaign )->post_content );
		$this->assertSame( 'Keep subject', Campaigns::draft( Campaigns::get( $campaign ) )['subject'] );

		$this->assertSame( 409, $this->content_request( $campaign, '<!-- wp:paragraph --><p>Stale</p><!-- /wp:paragraph -->', $revision )->get_status() );

		update_post_meta( $campaign, Campaigns::META_STATUS, Campaigns::STATUS_SCHEDULED );
		$current = Campaigns::revision( Campaigns::draft( Campaigns::get( $campaign ) ) );
		$this->assertSame( 409, $this->content_request( $campaign, '<!-- wp:paragraph --><p>Blocked</p><!-- /wp:paragraph -->', $current )->get_status() );
	}

	public function test_template_content_endpoint_uses_revision(): void {
		$repo = new TemplateRepository();
		$id   = $repo->create(
			array(
				'name'   => 'Editor template',
				'mode'   => Campaigns::MODE_BLOCKS,
				'blocks' => '<!-- wp:paragraph --><p>Old template</p><!-- /wp:paragraph -->',
			)
		);
		$this->assertIsInt( $id );

		$template = $repo->get( $id );
		$revision = $repo->revision( $template );

		$request = new WP_REST_Request( 'PUT', '/nowcrm/v1/templates/' . $id . '/content' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( array( 'title' => 'Editor template', 'blocks' => '<!-- wp:paragraph --><p>New template</p><!-- /wp:paragraph -->', 'revision' => $revision ) ) );
		$response = rest_do_request( $request );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( '<!-- wp:paragraph --><p>New template</p><!-- /wp:paragraph -->', $repo->get( $id )['blocks'] );

		$request->set_body( wp_json_encode( array( 'title' => 'Editor template', 'blocks' => '<p>Stale</p>', 'revision' => $revision ) ) );
		$this->assertSame( 409, rest_do_request( $request )->get_status() );
	}

	public function test_content_editor_url_points_at_hidden_screen(): void {
		$url = ContentEditorPage::url( 'campaign', 12 );
		$this->assertStringContainsString( 'page=' . ContentEditorPage::SLUG, $url );
		$this->assertStringContainsString( 'target=campaign', $url );
		$this->assertStringContainsString( 'id=12', $url );
	}

	public function test_sequence_working_post_is_a_reusable_blank_canvas(): void {
		$repo = new SequenceRepository();
		$id   = $repo->create(
			array(
				'name'  => 'Sequence canvas',
				'steps' => array(
					array(
						'name'          => 'First mail',
						'subject'       => 'Persisted subject',
						'preheader'     => '',
						'mode'          => Campaigns::MODE_BLOCKS,
						'content'       => '<!-- wp:paragraph --><p>Persisted step content</p><!-- /wp:paragraph -->',
						'delay_seconds' => 0,
					),
				),
			)
		);
		$this->assertIsInt( $id );

		$dummy = DummyPost::ensure( 'sequence', $id );
		$this->assertIsInt( $dummy );
		$this->assertStringNotContainsString(
			'Persisted step content',
			(string) get_post( $dummy )->post_content,
			'Persisted sequence step content must never leak into the blank canvas.'
		);

		wp_update_post(
			array(
				'ID'           => $dummy,
				'post_content' => '<!-- wp:paragraph --><p>Unsaved canvas</p><!-- /wp:paragraph -->',
			)
		);

		$steps = $repo->get( $id )['steps'];
		$this->assertSame(
			'<!-- wp:paragraph --><p>Persisted step content</p><!-- /wp:paragraph -->',
			(string) $steps[0]['content'],
			'Writing the working post must never write sequence step content.'
		);

		$this->assertSame( $dummy, DummyPost::ensure( 'sequence', $id ), 'The working post is reused for the same sequence.' );
		$this->assertStringContainsString(
			'Unsaved canvas',
			(string) get_post( $dummy )->post_content,
			'Reusing the canvas must not reset unsaved editor content.'
		);

		$this->assertTrue( $repo->delete( $id, true ) );
		$this->assertNull( get_post( $dummy ), 'Permanently deleting a sequence removes its working post.' );

		$campaign = $this->make_campaign();
		$this->assertWPError( DummyPost::ensure( 'sequence', $campaign ), 'A non-sequence ID must be refused.' );

		$trashed = $repo->create(
			array(
				'name'  => 'Trashed canvas',
				'steps' => array(
					array(
						'name'          => 'Mail',
						'subject'       => 'Subject',
						'preheader'     => '',
						'mode'          => Campaigns::MODE_BLOCKS,
						'content'       => '<!-- wp:paragraph --><p>Trash</p><!-- /wp:paragraph -->',
						'delay_seconds' => 0,
					),
				),
			)
		);
		$this->assertIsInt( $trashed );
		wp_trash_post( $trashed );
		$this->assertWPError( DummyPost::ensure( 'sequence', (int) $trashed ), 'Trashed sequences must be refused.' );
	}

	/**
	 * CRM manager with edit_posts, without delete or others authority.
	 */
	private function crm_editor(): WP_User {
		$user = new WP_User( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$user->add_cap( Capabilities::MANAGE_CONTACTS );
		$user->add_cap( 'edit_posts' );

		return $user;
	}

	private function content_request( int $campaign, string $blocks, string $revision ): WP_REST_Response {
		$request = new WP_REST_Request( 'PUT', '/nowcrm/v1/campaigns/' . $campaign . '/content' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			wp_json_encode(
				array(
					'title'    => 'Updated title',
					'blocks'   => $blocks,
					'revision' => $revision,
				)
			)
		);

		return rest_do_request( $request );
	}
}
