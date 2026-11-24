<?php
/**
 * Smart link tests: target validation, unique slugs, redirects, contact
 * actions, click counters, paused links, and open redirect defence.
 *
 * @package NowCRM
 */

use NowCRM\Admin\SmartLinksPage;
use NowCRM\Public\SmartLinkPage;
use NowCRM\SmartLinks\ContactToken;
use NowCRM\SmartLinks\SmartLinkRepository;
use NowCRM\Terms\TermRepository;

/**
 * Sentinel thrown by the redirect filter so a handler never reaches exit().
 */
final class NowCrm_SmartLinks_Redirect_Captured extends \RuntimeException {}

/**
 * @covers \NowCRM\SmartLinks\SmartLinkRepository
 * @covers \NowCRM\Public\SmartLinkPage
 * @covers \NowCRM\SmartLinks\ContactToken
 * @covers \NowCRM\Admin\SmartLinksPage
 */
final class SmartLinksTest extends NowCrmTestCase {

	public function set_up(): void {
		parent::set_up();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$_GET = array();
	}

	public function tear_down(): void {
		$_GET     = array();
		$_POST    = array();
		$_REQUEST = array();

		// Clear any draft left by a rejected handler.
		delete_transient( 'nowcrm_smart_link_draft_' . get_current_user_id() );
		wp_set_current_user( 0 );

		parent::tear_down();
	}

	/**
	 * Parse the query of a captured redirect target.
	 *
	 * @return array<string, string>
	 */
	private function redirect_query( string $location ): array {
		$query = array();

		parse_str( (string) wp_parse_url( $location, PHP_URL_QUERY ), $query );

		return array_map( 'strval', $query );
	}

	/**
	 * Render the current smart link screen.
	 */
	private function render_page(): string {
		ob_start();

		try {
			SmartLinksPage::render();
		} finally {
			$html = (string) ob_get_clean();
		}

		return $html;
	}

	/**
	 * Capture a handler redirect instead of reaching exit().
	 */
	private function capture_redirect( callable $handler ): string {
		$captured = '';
		$filter   = static function ( $location ) use ( &$captured ): void {
			$captured = (string) $location;
			throw new NowCrm_SmartLinks_Redirect_Captured( $captured );
		};

		add_filter( 'wp_redirect', $filter, 1 );

		try {
			$handler();
		} catch ( NowCrm_SmartLinks_Redirect_Captured $exception ) {
			$captured = $exception->getMessage();
		} finally {
			remove_filter( 'wp_redirect', $filter, 1 );
		}

		$this->assertNotSame( '', $captured, 'The handler did not redirect.' );

		return $captured;
	}

	/**
	 * Create one link and return its stored row.
	 *
	 * @param array<string, mixed> $overrides Payload overrides.
	 * @return array<string, mixed>
	 */
	private function create_link( array $overrides = array() ): array {
		$repo = new SmartLinkRepository();
		$id   = $repo->create(
			array_merge(
				array(
					'title'      => 'Fixture smart link',
					'target_url' => 'https://example.test/offer',
					'slug'       => 'link-' . strtolower( bin2hex( random_bytes( 4 ) ) ),
				),
				$overrides
			)
		);

		$this->assertIsInt( $id, 'Fixture smart link creation failed.' );

		$link = $repo->get( (int) $id );

		$this->assertIsArray( $link );

		return $link;
	}

	public function test_target_must_be_an_absolute_http_url(): void {
		$repo = new SmartLinkRepository();
		$bad  = array(
			'',
			'/relative-path',
			'//evil.example/x',
			'javascript:alert(1)',
			'data:text/html,x',
			'ftp://example.test/file',
			'https://',
			'https://exa mple.test/x',
			'https://user:pass@example.test/x',
		);

		foreach ( $bad as $index => $target ) {
			$result = $repo->create(
				array(
					'title'      => 'Bad target ' . $index,
					'target_url' => $target,
					'slug'       => 'bad-target-' . $index,
				)
			);

			$this->assertWPError( $result, 'Target should be rejected: ' . $target );
			$this->assertSame( 'nowcrm_smart_invalid_target', $result->get_error_code() );
		}

		foreach ( array( 'https://example.test/offer', 'http://example.test:8080/path' ) as $index => $target ) {
			$result = $repo->create(
				array(
					'title'      => 'Good target ' . $index,
					'target_url' => $target,
					'slug'       => 'good-target-' . $index,
				)
			);

			$this->assertIsInt( $result, 'Target should be accepted: ' . $target );
		}
	}

	public function test_slug_is_unique_and_generated_when_blank(): void {
		$repo = new SmartLinkRepository();

		$this->assertIsInt(
			$repo->create(
				array(
					'title'      => 'Promo',
					'target_url' => 'https://example.test/promo',
					'slug'       => 'promo',
				)
			)
		);

		$duplicate = $repo->create(
			array(
				'title'      => 'Promo again',
				'target_url' => 'https://example.test/promo',
				'slug'       => 'Promo',
			)
		);

		$this->assertWPError( $duplicate );
		$this->assertSame( 'nowcrm_smart_duplicate_slug', $duplicate->get_error_code() );

		$invalid = $repo->create(
			array(
				'title'      => 'Short',
				'target_url' => 'https://example.test/x',
				'slug'       => 'ab',
			)
		);

		$this->assertWPError( $invalid );
		$this->assertSame( 'nowcrm_smart_invalid_slug', $invalid->get_error_code() );

		$first  = $this->create_link( array( 'slug' => '' ) );
		$second = $this->create_link( array( 'slug' => '' ) );

		$this->assertNotSame( $first['slug'], $second['slug'] );
		$this->assertGreaterThanOrEqual( SmartLinkRepository::SLUG_MIN, strlen( $first['slug'] ) );
	}

	public function test_unknown_or_wrong_type_term_is_rejected_at_save(): void {
		$repo = new SmartLinkRepository();
		$list = $this->terms->create( TermRepository::TYPE_LIST, 'Only a list' );

		$missing = $repo->create(
			array(
				'title'      => 'Missing term',
				'target_url' => 'https://example.test/x',
				'slug'       => 'missing-term',
				'apply_tags' => array( 999999 ),
			)
		);

		$this->assertWPError( $missing );
		$this->assertSame( 'nowcrm_smart_invalid_term', $missing->get_error_code() );

		$wrong_type = $repo->create(
			array(
				'title'      => 'Wrong type',
				'target_url' => 'https://example.test/x',
				'slug'       => 'wrong-type',
				'apply_tags' => array( $list ),
			)
		);

		$this->assertWPError( $wrong_type );
		$this->assertSame( 'nowcrm_smart_invalid_term', $wrong_type->get_error_code() );
	}

	public function test_anonymous_click_redirects_and_counts(): void {
		$link = $this->create_link( array( 'target_url' => 'https://example.test/offer' ) );

		$this->assertStringContainsString( SmartLinkRepository::QUERY_VAR . '=' . $link['slug'], $link['short_url'] );

		$result = ( new SmartLinkPage() )->handle( $link['slug'] );

		$this->assertSame( 307, $result['status'] );
		$this->assertSame( 'https://example.test/offer', $result['location'] );
		$this->assertSame( 0, $result['contact_id'] );

		$fresh = ( new SmartLinkRepository() )->get( $link['id'] );

		$this->assertSame( 1, $fresh['all_clicks'] );
		$this->assertSame( 0, $fresh['contact_clicks'] );

		$log = ( new SmartLinkRepository() )->click_log();

		$this->assertCount( 1, $log );
		$this->assertSame( array( 'link', 'contact', 'at' ), array_keys( $log[0] ) );
		$this->assertFalse( $log[0]['contact'] );
	}

	public function test_valid_contact_applies_actions_once_and_tracks_contact_clicks(): void {
		$apply_list  = $this->terms->create( TermRepository::TYPE_LIST, 'Apply list' );
		$apply_tag   = $this->terms->create( TermRepository::TYPE_TAG, 'Apply tag' );
		$remove_list = $this->terms->create( TermRepository::TYPE_LIST, 'Remove list' );
		$remove_tag  = $this->terms->create( TermRepository::TYPE_TAG, 'Remove tag' );

		$contact_id = $this->make_contact();

		$this->terms->attach( $contact_id, $remove_list );
		$this->terms->attach( $contact_id, $remove_tag );

		$link = $this->create_link(
			array(
				'apply_lists'  => array( $apply_list ),
				'apply_tags'   => array( $apply_tag ),
				'remove_lists' => array( $remove_list ),
				'remove_tags'  => array( $remove_tag ),
			)
		);

		$page  = new SmartLinkPage();
		$token = ContactToken::issue( $contact_id );

		$this->assertNotSame( '', $token );

		$result = $page->handle( $link['slug'], $token );

		$this->assertSame( 307, $result['status'] );
		$this->assertSame( $contact_id, $result['contact_id'] );

		$term_ids = array_map( 'intval', array_column( $this->terms->for_contact( $contact_id ), 'id' ) );

		$this->assertContains( $apply_list, $term_ids );
		$this->assertContains( $apply_tag, $term_ids );
		$this->assertNotContains( $remove_list, $term_ids );
		$this->assertNotContains( $remove_tag, $term_ids );

		$fresh = ( new SmartLinkRepository() )->get( $link['id'] );

		$this->assertSame( 1, $fresh['all_clicks'] );
		$this->assertSame( 1, $fresh['contact_clicks'] );

		// A second click repeats the redirect and the counts, but never
		// duplicates a membership.
		$this->assertSame( 307, $page->handle( $link['slug'], $token )['status'] );

		$fresh = ( new SmartLinkRepository() )->get( $link['id'] );

		$this->assertSame( 2, $fresh['all_clicks'] );
		$this->assertSame( 2, $fresh['contact_clicks'] );

		$counts = array_count_values( array_map( 'intval', array_column( $this->terms->for_contact( $contact_id ), 'id' ) ) );

		$this->assertSame( 1, $counts[ $apply_tag ] ?? 0 );
		$this->assertSame( 1, $counts[ $apply_list ] ?? 0 );
	}

	public function test_invalid_or_expired_contact_token_still_redirects_without_actions(): void {
		$tag        = $this->terms->create( TermRepository::TYPE_TAG, 'Token tag' );
		$contact_id = $this->make_contact();
		$link       = $this->create_link( array( 'apply_tags' => array( $tag ) ) );
		$page       = new SmartLinkPage();

		$garbage = $page->handle( $link['slug'], 'not-a-token' );

		$this->assertSame( 307, $garbage['status'] );
		$this->assertSame( 0, $garbage['contact_id'] );

		$expired = ContactToken::issue( $contact_id, 0 );

		$this->assertNull( ContactToken::validate( $expired ) );
		$this->assertSame( 307, $page->handle( $link['slug'], $expired )['status'] );

		$valid  = ContactToken::issue( $contact_id );
		$forged = substr( $valid, 0, -1 ) . ( str_ends_with( $valid, 'a' ) ? 'b' : 'a' );

		$this->assertNull( ContactToken::validate( $forged ) );
		$this->assertSame( 307, $page->handle( $link['slug'], $forged )['status'] );

		$this->assertNotContains( $tag, array_map( 'intval', array_column( $this->terms->for_contact( $contact_id ), 'id' ) ) );

		$fresh = ( new SmartLinkRepository() )->get( $link['id'] );

		$this->assertSame( 3, $fresh['all_clicks'] );
		$this->assertSame( 0, $fresh['contact_clicks'] );
	}

	public function test_paused_link_returns_404_and_does_not_count(): void {
		$link = $this->create_link();
		$repo = new SmartLinkRepository();

		$this->assertTrue( $repo->set_status( $link['id'], SmartLinkRepository::STATUS_PAUSED ) );

		$result = ( new SmartLinkPage() )->handle( $link['slug'] );

		$this->assertSame( 404, $result['status'] );
		$this->assertArrayNotHasKey( 'location', $result );

		$fresh = $repo->get( $link['id'] );

		$this->assertSame( 0, $fresh['all_clicks'] );
		$this->assertSame( 0, $fresh['contact_clicks'] );

		$this->assertTrue( $repo->set_status( $link['id'], SmartLinkRepository::STATUS_ACTIVE ) );
		$this->assertSame( 307, ( new SmartLinkPage() )->handle( $link['slug'] )['status'] );
	}

	public function test_tampered_target_never_becomes_an_open_redirect(): void {
		$link = $this->create_link();
		$page = new SmartLinkPage();

		foreach ( array( 'javascript:alert(1)', '//evil.example/x', 'ftp://evil.example/x', 'data:text/html,x' ) as $target ) {
			update_post_meta( $link['id'], SmartLinkRepository::META_TARGET, $target );

			$result = $page->handle( $link['slug'] );

			$this->assertSame( 404, $result['status'], 'Tampered target must not redirect: ' . $target );
		}

		$fresh = ( new SmartLinkRepository() )->get( $link['id'] );

		$this->assertSame( 0, $fresh['all_clicks'] );
	}

	public function test_deleted_term_is_skipped_and_click_still_redirects(): void {
		$tag        = $this->terms->create( TermRepository::TYPE_TAG, 'Drifting tag' );
		$contact_id = $this->make_contact();
		$link       = $this->create_link( array( 'apply_tags' => array( $tag ) ) );

		$this->assertTrue( $this->terms->delete( $tag ) );

		$result = ( new SmartLinkPage() )->handle( $link['slug'], ContactToken::issue( $contact_id ) );

		$this->assertSame( 307, $result['status'] );

		$fresh = ( new SmartLinkRepository() )->get( $link['id'] );

		$this->assertSame( 1, $fresh['all_clicks'] );
		$this->assertSame( 1, $fresh['contact_clicks'] );
	}

	public function test_short_url_token_carries_no_email(): void {
		$contact_id = $this->make_contact( array( 'email' => 'private.person@example.test' ) );
		$link       = $this->create_link();
		$token      = ContactToken::issue( $contact_id );
		$url        = SmartLinkPage::url( $link['slug'], $token );

		$this->assertStringContainsString( SmartLinkRepository::TOKEN_QUERY_VAR . '=', $url );
		$this->assertStringNotContainsString( 'private.person', $url );
		$this->assertStringNotContainsString( '@', (string) wp_parse_url( $url, PHP_URL_QUERY ) );
	}

	public function test_update_and_delete(): void {
		$link = $this->create_link();
		$repo = new SmartLinkRepository();

		$updated = $repo->update(
			$link['id'],
			array(
				'title'      => 'Renamed link',
				'target_url' => 'https://example.test/new-target',
				'slug'       => 'renamed-link',
			)
		);

		$this->assertIsArray( $updated );
		$this->assertSame( 'Renamed link', $updated['title'] );
		$this->assertSame( 'https://example.test/new-target', $updated['target_url'] );
		$this->assertSame( 'renamed-link', $updated['slug'] );
		$this->assertSame( $link['id'], $repo->find_by_slug( 'renamed-link' )['id'] );

		$other = $this->create_link( array( 'slug' => 'taken-slug' ) );
		$clash = $repo->update( $link['id'], array( 'slug' => 'taken-slug' ) );

		$this->assertWPError( $clash );
		$this->assertSame( 'nowcrm_smart_duplicate_slug', $clash->get_error_code() );

		$this->assertTrue( $repo->delete( $link['id'] ) );
		$this->assertNull( $repo->get( $link['id'] ) );
		$this->assertNull( $repo->find_by_slug( 'renamed-link' ) );
		$this->assertIsArray( $repo->get( $other['id'] ) );
	}

	public function test_click_log_is_bounded(): void {
		$link = $this->create_link();
		$repo = new SmartLinkRepository();

		for ( $i = 0; $i < SmartLinkRepository::CLICK_LOG_LIMIT + 10; $i++ ) {
			$repo->record_click( $link['id'] );
		}

		$log = $repo->click_log();

		$this->assertCount( SmartLinkRepository::CLICK_LOG_LIMIT, $log );
		$this->assertSame( $link['id'], (int) $log[0]['link'] );
	}

	public function test_default_screen_lists_data_without_opening_the_create_form(): void {
		$link = $this->create_link(
			array(
				'title'      => 'Autumn offer',
				'slug'       => 'autumn-offer',
				'target_url' => 'https://example.test/autumn',
			)
		);

		( new SmartLinkRepository() )->record_click( $link['id'] );

		$_GET = array( 'page' => SmartLinksPage::SLUG );

		$html = $this->render_page();

		$this->assertStringContainsString( 'Autumn offer', $html );
		$this->assertStringContainsString( 'autumn-offer', $html );
		$this->assertStringContainsString( 'https://example.test/autumn', $html );
		$this->assertStringContainsString( '共 1 筆智慧連結', $html );
		$this->assertStringContainsString( 'new=1', $html, 'The list must offer an explicit create entry point.' );
		$this->assertStringNotContainsString( 'value="nowcrm_create_smart_link"', $html, 'The list must not embed the create form.' );
		$this->assertStringNotContainsString( 'href="https://example.test/autumn"', $html, 'The admin must not fire the target link.' );
		$this->assertStringNotContainsString( 'nowcrm_contact=', $html, 'The admin must not reveal signed contact links.' );
	}

	public function test_create_screen_is_opened_on_demand_and_cancels_back_to_the_list(): void {
		$this->create_link( array( 'title' => 'Existing link' ) );

		$_GET = array(
			'page' => SmartLinksPage::SLUG,
			'new'  => '1',
		);

		$html = $this->render_page();

		$this->assertStringContainsString( 'value="nowcrm_create_smart_link"', $html );
		$this->assertStringContainsString( 'name="target_url"', $html );
		$this->assertStringContainsString( '對象動作', $html );
		$this->assertStringContainsString( 'page=nowcrm-smart-links', $html );
		$this->assertStringNotContainsString( 'Existing link', $html, 'The create screen must not render the list.' );
		$this->assertStringNotContainsString( 'nowcrm_update_smart_link', $html );
		$this->assertStringNotContainsString( 'new=1', $html, 'Back and cancel must return to the plain list.' );
	}

	public function test_edit_screen_shows_target_actions_and_click_split(): void {
		$list  = $this->terms->create( TermRepository::TYPE_LIST, 'VIP list' );
		$tag   = $this->terms->create( TermRepository::TYPE_TAG, 'Hot lead' );
		$old   = $this->terms->create( TermRepository::TYPE_LIST, 'Old list' );
		$link  = $this->create_link(
			array(
				'title'        => 'Partner redirect',
				'slug'         => 'partner-redirect',
				'target_url'   => 'https://example.test/partner',
				'apply_lists'  => array( $list ),
				'apply_tags'   => array( $tag ),
				'remove_lists' => array( $old ),
			)
		);
		$repo = new SmartLinkRepository();

		$repo->record_click( $link['id'], $this->make_contact() );
		$repo->record_click( $link['id'] );

		$_GET = array(
			'page' => SmartLinksPage::SLUG,
			'edit' => (string) $link['id'],
		);

		$html = $this->render_page();

		$this->assertStringContainsString( 'value="nowcrm_update_smart_link"', $html );
		$this->assertStringContainsString( 'https://example.test/partner', $html );
		$this->assertStringContainsString( 'VIP list', $html );
		$this->assertStringContainsString( 'Hot lead', $html );
		$this->assertStringContainsString( 'Old list', $html );
		$this->assertStringContainsString( '全部點擊', $html );
		$this->assertStringContainsString( '聯絡人點擊', $html );
		$this->assertStringContainsString( '匿名點擊', $html );
		$this->assertStringContainsString( '<dd>2</dd>', $html );
		$this->assertStringContainsString( '<dd>1</dd>', $html );
		$this->assertStringContainsString( 'nowcrm_smart=partner-redirect', $html );
		$this->assertStringContainsString( 'page=nowcrm-smart-links', $html );
		$this->assertStringNotContainsString( 'nowcrm_contact=', $html );
		$this->assertStringNotContainsString( 'href="https://example.test/partner"', $html, 'The admin must not fire the target link.' );
	}

	public function test_unknown_edit_id_returns_the_validated_list_with_an_error(): void {
		$this->create_link( array( 'title' => 'Still listed' ) );

		$_GET = array(
			'page' => SmartLinksPage::SLUG,
			'edit' => '999999',
		);

		$html = $this->render_page();

		$this->assertStringContainsString( '找不到這個智慧連結', $html );
		$this->assertStringContainsString( 'Still listed', $html );
		$this->assertStringNotContainsString( 'value="nowcrm_update_smart_link"', $html );
	}

	public function test_search_scopes_the_list_and_its_empty_state(): void {
		$this->create_link( array( 'title' => 'Needle campaign', 'slug' => 'needle-campaign' ) );
		$this->create_link( array( 'title' => 'Other campaign', 'slug' => 'other-campaign' ) );

		$_GET = array(
			'page' => SmartLinksPage::SLUG,
			's'    => 'needle',
		);

		$html = $this->render_page();

		$this->assertStringContainsString( 'Needle campaign', $html );
		$this->assertStringNotContainsString( 'Other campaign', $html );
		$this->assertStringContainsString( '共 1 筆智慧連結', $html );

		$_GET['s'] = 'no-such-link';

		$html = $this->render_page();

		$this->assertStringContainsString( '沒有符合目前搜尋的智慧連結', $html );
		$this->assertStringNotContainsString( 'value="nowcrm_create_smart_link"', $html );
		$this->assertStringNotContainsString( 'value="nowcrm_update_smart_link"', $html );
	}

	public function test_failed_create_returns_to_the_create_screen_not_the_list(): void {
		$_POST = array(
			'_wpnonce'   => wp_create_nonce( 'nowcrm_create_smart_link' ),
			'action'     => 'nowcrm_create_smart_link',
			'title'      => '',
			'target_url' => 'https://example.test/offer',
			'slug'       => '',
		);
		$_REQUEST = $_POST;

		$location = $this->capture_redirect( array( SmartLinksPage::class, 'handle_create' ) );
		$query    = array();

		parse_str( (string) wp_parse_url( $location, PHP_URL_QUERY ), $query );

		$this->assertSame( '1', $query['new'] ?? '' );
		$this->assertSame( 'title_required', $query[ 'nowcrm_smart_notice' ] ?? '' );
		$this->assertSame( SmartLinksPage::SLUG, $query['page'] ?? '' );
	}

	public function test_rejected_create_keeps_list_scope_and_a_route_bound_draft(): void {
		$taken = $this->create_link( array( 'title' => 'Taken', 'slug' => 'taken-slug' ) );

		$_POST = array(
			'_wpnonce'   => wp_create_nonce( 'nowcrm_create_smart_link' ),
			'action'     => 'nowcrm_create_smart_link',
			'title'      => 'Drafted title',
			'target_url' => 'https://example.test/drafted',
			'slug'       => 'taken-slug',
			's'          => 'needle',
			'paged'      => '3',
		);
		$_REQUEST = $_POST;

		$query = $this->redirect_query( $this->capture_redirect( array( SmartLinksPage::class, 'handle_create' ) ) );

		$this->assertSame( '1', $query['new'] ?? '' );
		$this->assertSame( 'duplicate_slug', $query[ 'nowcrm_smart_notice' ] ?? '' );
		$this->assertSame( 'needle', $query['s'] ?? '' );
		$this->assertSame( '3', $query['paged'] ?? '' );

		// The draft belongs to the create route only.
		$_GET     = array(
			'page'                => SmartLinksPage::SLUG,
			'edit'                => (string) $taken['id'],
			'nowcrm_smart_notice' => 'duplicate_slug',
		);
		$edit_html = $this->render_page();

		$this->assertStringNotContainsString( 'value="Drafted title"', $edit_html );
		$this->assertStringContainsString( 'value="Taken"', $edit_html );

		$_GET = $query + array( 'page' => SmartLinksPage::SLUG );
		$html = $this->render_page();

		$this->assertStringContainsString( 'value="Drafted title"', $html );
		$this->assertStringContainsString( 'value="https://example.test/drafted"', $html );
		$this->assertStringContainsString( 'value="taken-slug"', $html );
		$this->assertStringContainsString( 'name="s" value="needle"', $html );
		$this->assertStringContainsString( 'name="paged" value="3"', $html );

		// One-time: a refresh keeps the form but drops the submitted values.
		$again = $this->render_page();

		$this->assertStringNotContainsString( 'value="Drafted title"', $again );
		$this->assertStringContainsString( 'value="nowcrm_create_smart_link"', $again );
		$this->assertStringContainsString( 'name="paged" value="3"', $again );
	}

	public function test_rejected_update_keeps_submitted_values_for_the_same_link(): void {
		$link = $this->create_link( array( 'title' => 'Original A', 'slug' => 'original-a' ) );

		$this->create_link( array( 'title' => 'Other B', 'slug' => 'other-b' ) );

		$_POST = array(
			'_wpnonce'   => wp_create_nonce( 'nowcrm_update_smart_link' ),
			'action'     => 'nowcrm_update_smart_link',
			'link_id'    => (string) $link['id'],
			'title'      => 'Edited A',
			'target_url' => 'https://example.test/edited',
			'slug'       => 'other-b',
			's'          => 'original',
			'paged'      => '2',
		);
		$_REQUEST = $_POST;

		$query = $this->redirect_query( $this->capture_redirect( array( SmartLinksPage::class, 'handle_update' ) ) );

		$this->assertSame( (string) $link['id'], $query['edit'] ?? '' );
		$this->assertSame( 'duplicate_slug', $query[ 'nowcrm_smart_notice' ] ?? '' );
		$this->assertSame( 'original', $query['s'] ?? '' );
		$this->assertSame( '2', $query['paged'] ?? '' );

		$_GET = $query + array( 'page' => SmartLinksPage::SLUG );
		$html = $this->render_page();

		$this->assertStringContainsString( 'value="Edited A"', $html );
		$this->assertStringContainsString( 'value="https://example.test/edited"', $html );
		$this->assertStringContainsString( 'value="other-b"', $html );
		$this->assertStringContainsString( 'name="s" value="original"', $html );
		$this->assertStringContainsString( 'name="paged" value="2"', $html );

		$again = $this->render_page();

		$this->assertStringContainsString( 'value="Original A"', $again );
		$this->assertStringContainsString( 'value="original-a"', $again );
		$this->assertStringNotContainsString( 'value="Edited A"', $again );
	}
}
