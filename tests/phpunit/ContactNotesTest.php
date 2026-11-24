<?php
/**
 * Contact note repository, panel, and handler tests.
 *
 * @package NowCRM
 */

use NowCRM\Admin\ContactNotes;
use NowCRM\Admin\Menu;
use NowCRM\Contacts\NoteRepository;

/**
 * Sentinel thrown by the redirect filter so a handler never reaches exit().
 */
final class NowCrm_Note_Redirect_Captured extends \RuntimeException {}

/**
 * @covers \NowCRM\Contacts\NoteRepository
 * @covers \NowCRM\Admin\ContactNotes
 */
final class ContactNotesTest extends NowCrmTestCase {

	private NoteRepository $notes;

	private int $actor;

	public function set_up(): void {
		parent::set_up();

		$this->notes = new NoteRepository();
		$this->actor = (int) self::factory()->user->create( array( 'role' => 'administrator' ) );

		wp_set_current_user( $this->actor );
	}

	public function tear_down(): void {
		$_GET     = array();
		$_POST    = array();
		$_REQUEST = array();
		wp_set_current_user( 0 );

		parent::tear_down();
	}

	public function test_create_persists_author_kind_and_dates(): void {
		$contact_id = $this->make_contact();
		$note_id    = $this->make_note(
			$contact_id,
			array(
				'kind'  => 'call',
				'title' => '電話追蹤',
				'body'  => '<p>客戶要求下週再聯絡。</p>',
			)
		);

		$note = $this->notes->get( $contact_id, $note_id );

		$this->assertNotNull( $note );
		$this->assertSame( $contact_id, $note['contact_id'] );
		$this->assertSame( $this->actor, $note['author_id'] );
		$this->assertSame( 'call', $note['kind'] );
		$this->assertSame( '電話追蹤', $note['title'] );
		$this->assertSame( '<p>客戶要求下週再聯絡。</p>', $note['body'] );
		$this->assertNotSame( '', $note['created_at'] );
		$this->assertNotSame( '', $note['updated_at'] );

		$result = $this->notes->search( $contact_id );

		$this->assertSame( 1, $result['total'] );
		$this->assertSame( $note_id, $result['items'][0]['id'] );
	}

	public function test_created_at_accepts_an_explicit_utc_value(): void {
		$contact_id = $this->make_contact();
		$note_id    = $this->make_note( $contact_id, array( 'created_at' => '2024-01-02 03:04:05' ) );

		$this->assertSame( '2024-01-02 03:04:05', $this->notes->get( $contact_id, $note_id )['created_at'] );

		$invalid = $this->notes->create(
			$contact_id,
			array(
				'title'      => '壞日期',
				'body'       => '內容',
				'created_at' => 'not-a-date',
			)
		);

		$this->assertWPError( $invalid );
		$this->assertSame( 'nowcrm_invalid_created_at', $invalid->get_error_code() );
	}

	public function test_invalid_input_is_rejected(): void {
		$contact_id = $this->make_contact();

		$missing = $this->notes->create( 999999, array( 'title' => '孤兒', 'body' => '內容' ) );
		$this->assertWPError( $missing );
		$this->assertSame( 'nowcrm_not_found', $missing->get_error_code() );

		$kind = $this->notes->create( $contact_id, array( 'kind' => 'task', 'title' => '標題', 'body' => '內容' ) );
		$this->assertWPError( $kind );
		$this->assertSame( 'nowcrm_invalid_kind', $kind->get_error_code() );

		$title = $this->notes->create( $contact_id, array( 'title' => '   ', 'body' => '內容' ) );
		$this->assertWPError( $title );
		$this->assertSame( 'nowcrm_invalid_title', $title->get_error_code() );

		$body = $this->notes->create( $contact_id, array( 'title' => '標題', 'body' => '   ' ) );
		$this->assertWPError( $body );
		$this->assertSame( 'nowcrm_invalid_body', $body->get_error_code() );

		$this->assertSame( 0, $this->table_count( NoteRepository::TABLE ) );
	}

	public function test_title_is_plain_text_and_body_keeps_safe_html(): void {
		$contact_id = $this->make_contact();
		$note_id    = $this->make_note(
			$contact_id,
			array(
				'title' => '<b>粗體標題</b>',
				'body'  => '<script>alert(1)</script><strong>保留粗體</strong><p>段落</p>',
			)
		);

		$note = $this->notes->get( $contact_id, $note_id );

		$this->assertSame( '粗體標題', $note['title'] );
		$this->assertStringNotContainsString( '<script', $note['body'] );
		$this->assertStringContainsString( '<strong>保留粗體</strong>', $note['body'] );
		$this->assertStringContainsString( '<p>段落</p>', $note['body'] );
	}

	public function test_update_changes_fields_but_keeps_author_and_scope(): void {
		$contact_id = $this->make_contact();
		$note_id    = $this->make_note( $contact_id );
		$before     = $this->notes->get( $contact_id, $note_id );

		$this->assertTrue(
			$this->notes->update(
				$contact_id,
				$note_id,
				array(
					'kind'  => 'meeting',
					'title' => '會議紀錄',
					'body'  => '<p>更新後內容</p>',
				)
			)
		);

		$after = $this->notes->get( $contact_id, $note_id );

		$this->assertSame( 'meeting', $after['kind'] );
		$this->assertSame( '會議紀錄', $after['title'] );
		$this->assertSame( '<p>更新後內容</p>', $after['body'] );
		$this->assertSame( $before['author_id'], $after['author_id'] );
		$this->assertSame( $before['created_at'], $after['created_at'] );
		$this->assertGreaterThanOrEqual( $before['updated_at'], $after['updated_at'] );

		$other = $this->make_contact();

		$scoped = $this->notes->update( $other, $note_id, array( 'title' => '不應寫入' ) );

		$this->assertWPError( $scoped );
		$this->assertSame( 'nowcrm_not_found', $scoped->get_error_code() );
		$this->assertSame( '會議紀錄', $this->notes->get( $contact_id, $note_id )['title'] );
	}

	public function test_delete_is_scoped_to_the_owning_contact(): void {
		$owner    = $this->make_contact();
		$intruder = $this->make_contact();
		$note_id  = $this->make_note( $owner );

		$blocked = $this->notes->delete( $intruder, $note_id );

		$this->assertWPError( $blocked );
		$this->assertSame( 'nowcrm_not_found', $blocked->get_error_code() );
		$this->assertNotNull( $this->notes->get( $owner, $note_id ) );

		$this->assertTrue( $this->notes->delete( $owner, $note_id ) );
		$this->assertNull( $this->notes->get( $owner, $note_id ) );
	}

	public function test_bulk_delete_is_scoped_bounded_and_deduplicated(): void {
		$owner    = $this->make_contact();
		$other    = $this->make_contact();
		$first    = $this->make_note( $owner );
		$second   = $this->make_note( $owner );
		$foreign  = $this->make_note( $other );

		$deleted = $this->notes->bulk_delete( $owner, array( $first, $second, $foreign, $first ) );

		$this->assertSame( 2, $deleted );
		$this->assertNull( $this->notes->get( $owner, $first ) );
		$this->assertNull( $this->notes->get( $owner, $second ) );
		$this->assertNotNull( $this->notes->get( $other, $foreign ) );

		$empty = $this->notes->bulk_delete( $owner, array( 0, 'abc', array( 1 ) ) );
		$this->assertWPError( $empty );
		$this->assertSame( 'nowcrm_no_selection', $empty->get_error_code() );

		$too_many = $this->notes->bulk_delete( $owner, range( 1, NoteRepository::BULK_LIMIT + 1 ) );
		$this->assertWPError( $too_many );
		$this->assertSame( 'nowcrm_too_many', $too_many->get_error_code() );
	}

	public function test_search_filters_by_title_and_kind_and_pages(): void {
		$contact_id = $this->make_contact();
		$first      = $this->make_note( $contact_id, array( 'kind' => 'note', 'title' => '第一則筆記' ) );
		$second     = $this->make_note( $contact_id, array( 'kind' => 'call', 'title' => '第二則通話' ) );
		$third      = $this->make_note( $contact_id, array( 'kind' => 'meeting', 'title' => '第三則會議' ) );

		$by_title = $this->notes->search( $contact_id, array( 'search' => '第二' ) );

		$this->assertSame( 1, $by_title['total'] );
		$this->assertSame( $second, $by_title['items'][0]['id'] );

		$by_kind = $this->notes->search( $contact_id, array( 'kind' => 'call' ) );

		$this->assertSame( 1, $by_kind['total'] );
		$this->assertSame( $second, $by_kind['items'][0]['id'] );

		$page_one = $this->notes->search( $contact_id, array( 'page' => 1, 'per_page' => 2 ) );
		$page_two = $this->notes->search( $contact_id, array( 'page' => 2, 'per_page' => 2 ) );

		$this->assertSame( 3, $page_one['total'] );
		$this->assertSame( array( $third, $second ), array_column( $page_one['items'], 'id' ) );
		$this->assertSame( array( $first ), array_column( $page_two['items'], 'id' ) );
	}

	public function test_purge_removes_only_the_deleted_contacts_notes(): void {
		$owner = $this->make_contact();
		$other = $this->make_contact();

		$this->make_note( $owner );
		$this->make_note( $owner );
		$this->make_note( $other );

		$this->notes->delete_for_contact( $owner );

		$this->assertSame( 0, $this->notes->search( $owner )['total'] );
		$this->assertSame( 1, $this->notes->search( $other )['total'] );
	}

	public function test_contact_deletion_purges_its_notes(): void {
		$owner = $this->make_contact();
		$other = $this->make_contact();

		$this->make_note( $owner );
		$other_note = $this->make_note( $other );

		$this->assertTrue( $this->contacts->delete( $owner ) );
		$this->assertSame( 1, $this->table_count( NoteRepository::TABLE ) );
		$this->assertSame( 1, $this->notes->search( $other )['total'] );
		$this->assertNotNull( $this->notes->get( $other, $other_note ) );
	}

	public function test_note_lifecycle_keeps_status_history_and_sends_nothing(): void {
		$contact_id = $this->make_contact();
		$before     = $this->history->for_contact( $contact_id );

		$note_id = $this->make_note( $contact_id );
		$this->notes->update( $contact_id, $note_id, array( 'title' => '更新標題' ) );
		$this->notes->delete( $contact_id, $note_id );

		$this->assertCount( count( $before ), $this->history->for_contact( $contact_id ) );
		$this->assertSame( 0, $this->table_count( 'email_jobs' ) );
		$this->assertFalse( wp_next_scheduled( 'nowcrm_send_batch' ) );
	}

	public function test_register_adds_the_admin_post_handlers(): void {
		ContactNotes::register();

		$this->assertSame(
			10,
			has_action( 'admin_post_nowcrm_save_contact_note', array( ContactNotes::class, 'save_note' ) )
		);
		$this->assertSame(
			10,
			has_action( 'admin_post_nowcrm_delete_contact_note', array( ContactNotes::class, 'delete_note' ) )
		);
		$this->assertSame(
			10,
			has_action( 'admin_post_nowcrm_bulk_delete_contact_notes', array( ContactNotes::class, 'bulk_delete_notes' ) )
		);
	}

	public function test_save_handler_creates_a_note_with_the_current_author(): void {
		$contact_id = $this->make_contact();

		$this->post(
			array(
				'_wpnonce'   => wp_create_nonce( 'nowcrm_save_contact_note' ),
				'contact_id' => $contact_id,
				'note_id'    => 0,
				'kind'       => 'meeting',
				'title'      => '現場拜訪',
				'body'       => '<p>討論下一季方案。</p>',
				'created_at' => '2024-05-06T07:08',
			)
		);

		$location = $this->capture_redirect( static fn() => ContactNotes::save_note() );

		$this->assertStringContainsString( 'page=' . Menu::CONTACT_SLUG, $location );
		$this->assertStringContainsString( 'nowcrm_note_notice=saved', $location );

		$result = $this->notes->search( $contact_id );

		$this->assertSame( 1, $result['total'] );
		$this->assertSame( $this->actor, $result['items'][0]['author_id'] );
		$this->assertSame( 'meeting', $result['items'][0]['kind'] );
		$this->assertSame(
			get_gmt_from_date( '2024-05-06 07:08:00', 'Y-m-d H:i:s' ),
			$result['items'][0]['created_at']
		);
	}

	public function test_save_handler_defaults_to_now_and_returns_to_notes(): void {
		$contact_id = $this->make_contact();
		$before     = time();

		$this->post(
			array(
				'_wpnonce'   => wp_create_nonce( 'nowcrm_save_contact_note' ),
				'contact_id' => $contact_id,
				'note_id'    => 0,
				'kind'       => 'note',
				'title'      => '預設日期',
				'body'       => '內容',
			)
		);

		$location = $this->capture_redirect( static fn() => ContactNotes::save_note() );

		$this->assertStringContainsString( 'tab=notes', $location );
		$this->assertStringContainsString( 'nowcrm_note_notice=saved', $location );

		$items = $this->notes->search( $contact_id )['items'];

		$this->assertCount( 1, $items );
		$this->assertGreaterThanOrEqual( gmdate( 'Y-m-d H:i:s', $before - 5 ), $items[0]['created_at'] );
	}

	public function test_render_opens_the_composer_only_for_this_contacts_note(): void {
		$owner   = $this->make_contact();
		$other   = $this->make_contact();
		$note_id = $this->make_note( $owner, array( 'title' => '本人筆記' ) );

		$_GET     = array( 'note' => 'new' );
		$_REQUEST = $_GET;

		ob_start();
		ContactNotes::render( $owner );
		$create_html = (string) ob_get_clean();

		$this->assertStringContainsString( 'data-mode="create"', $create_html );
		$this->assertMatchesRegularExpression( '/<dialog[^>]*id="nowcrm-note-drawer"[^>]*\sopen/', $create_html );

		$_GET     = array( 'note' => (string) $note_id );
		$_REQUEST = $_GET;

		ob_start();
		ContactNotes::render( $owner );
		$edit_html = (string) ob_get_clean();

		$this->assertStringContainsString( 'data-mode="edit"', $edit_html );
		$this->assertStringContainsString( 'name="note_id" value="' . $note_id . '"', $edit_html );
		$this->assertMatchesRegularExpression( '/<dialog[^>]*id="nowcrm-note-drawer"[^>]*\sopen/', $edit_html );

		$_GET     = array( 'note' => (string) $this->make_note( $other, array( 'title' => '他人筆記' ) ) );
		$_REQUEST = $_GET;

		ob_start();
		ContactNotes::render( $owner );
		$foreign_html = (string) ob_get_clean();

		$this->assertStringNotContainsString( '他人筆記', $foreign_html );
		$this->assertStringNotContainsString( 'data-mode="edit"', $foreign_html );
		$this->assertDoesNotMatchRegularExpression( '/<dialog[^>]*id="nowcrm-note-drawer"[^>]*\sopen/', $foreign_html );
	}

	public function test_save_handler_rejects_an_invalid_date(): void {
		$contact_id = $this->make_contact();

		$this->post(
			array(
				'_wpnonce'   => wp_create_nonce( 'nowcrm_save_contact_note' ),
				'contact_id' => $contact_id,
				'note_id'    => 0,
				'kind'       => 'note',
				'title'      => '日期錯誤',
				'body'       => '內容',
				'created_at' => 'not-a-date',
			)
		);

		$location = $this->capture_redirect( static fn() => ContactNotes::save_note() );

		$this->assertStringContainsString( 'nowcrm_note_notice=invalid_created_at', $location );
		$this->assertSame( 0, $this->notes->search( $contact_id )['total'] );
	}

	public function test_save_handler_cannot_update_another_contacts_note(): void {
		$owner    = $this->make_contact();
		$intruder = $this->make_contact();
		$note_id  = $this->make_note( $owner, array( 'title' => '原標題' ) );

		$this->post(
			array(
				'_wpnonce'   => wp_create_nonce( 'nowcrm_save_contact_note' ),
				'contact_id' => $intruder,
				'note_id'    => $note_id,
				'kind'       => 'note',
				'title'      => '被竄改',
				'body'       => '不應寫入',
			)
		);

		$location = $this->capture_redirect( static fn() => ContactNotes::save_note() );

		$this->assertStringContainsString( 'nowcrm_note_notice=not_found', $location );
		$this->assertSame( '原標題', $this->notes->get( $owner, $note_id )['title'] );
		$this->assertSame( 0, $this->notes->search( $intruder )['total'] );
	}

	public function test_save_handler_requires_capability_and_nonce(): void {
		$contact_id = $this->make_contact();

		$this->post(
			array(
				'contact_id' => $contact_id,
				'note_id'    => 0,
				'kind'       => 'note',
				'title'      => '沒有 nonce',
				'body'       => '內容',
			)
		);

		try {
			ContactNotes::save_note();
			$this->fail( 'A missing nonce must be rejected.' );
		} catch ( WPDieException $e ) {
			$this->addToAssertionCount( 1 );
		}

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$this->post(
			array(
				'_wpnonce'   => wp_create_nonce( 'nowcrm_save_contact_note' ),
				'contact_id' => $contact_id,
				'note_id'    => 0,
				'kind'       => 'note',
				'title'      => '沒有權限',
				'body'       => '內容',
			)
		);

		$this->expectException( 'WPDieException' );

		ContactNotes::save_note();
	}

	public function test_delete_handler_deletes_one_scoped_note(): void {
		$owner   = $this->make_contact();
		$note_id = $this->make_note( $owner );

		$this->post(
			array(
				'_wpnonce'   => wp_create_nonce( 'nowcrm_delete_contact_note' ),
				'contact_id' => $owner,
				'note_id'    => $note_id,
			)
		);

		$location = $this->capture_redirect( static fn() => ContactNotes::delete_note() );

		$this->assertStringContainsString( 'nowcrm_note_notice=deleted', $location );
		$this->assertNull( $this->notes->get( $owner, $note_id ) );
	}

	public function test_bulk_handler_deletes_only_the_current_contacts_notes(): void {
		$owner   = $this->make_contact();
		$other   = $this->make_contact();
		$first   = $this->make_note( $owner );
		$second  = $this->make_note( $owner );
		$foreign = $this->make_note( $other );

		$this->post(
			array(
				'_wpnonce'   => wp_create_nonce( 'nowcrm_bulk_delete_contact_notes' ),
				'contact_id' => $owner,
				'note_ids'   => array( $first, $second, $foreign ),
			)
		);

		$location = $this->capture_redirect( static fn() => ContactNotes::bulk_delete_notes() );

		$this->assertStringContainsString( 'nowcrm_note_notice=bulk_deleted', $location );
		$this->assertStringContainsString( 'nowcrm_note_deleted=2', $location );
		$this->assertSame( 0, $this->notes->search( $owner )['total'] );
		$this->assertNotNull( $this->notes->get( $other, $foreign ) );
	}

	public function test_bulk_handler_rejects_more_than_the_limit(): void {
		$owner   = $this->make_contact();
		$note_id = $this->make_note( $owner );

		$this->post(
			array(
				'_wpnonce'   => wp_create_nonce( 'nowcrm_bulk_delete_contact_notes' ),
				'contact_id' => $owner,
				'note_ids'   => array_merge( array( $note_id ), range( 1, NoteRepository::BULK_LIMIT ) ),
			)
		);

		$location = $this->capture_redirect( static fn() => ContactNotes::bulk_delete_notes() );

		$this->assertStringContainsString( 'nowcrm_note_notice=too_many', $location );
		$this->assertNotNull( $this->notes->get( $owner, $note_id ) );
	}

	public function test_render_shows_author_dates_and_safe_content(): void {
		$contact_id = $this->make_contact();
		$note_id    = $this->make_note(
			$contact_id,
			array(
				'kind'  => 'feedback',
				'title' => '客戶回饋',
				'body'  => '<script>alert(1)</script><strong>很滿意</strong>',
			)
		);

		$note   = $this->notes->get( $contact_id, $note_id );
		$author = get_userdata( $this->actor );

		ob_start();
		ContactNotes::render( $contact_id );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'id="nowcrm-contact-notes"', $html );
		$this->assertStringContainsString( '客戶回饋', $html );
		$this->assertStringContainsString( '<strong>很滿意</strong>', $html );
		$this->assertStringNotContainsString( '<script', $html );
		$this->assertStringContainsString( esc_html( (string) $author->display_name ), $html );
		$this->assertStringContainsString( esc_html( Menu::format_datetime( (string) $note['created_at'] ) ), $html );
		$this->assertStringContainsString( 'nowcrm_save_contact_note', $html );
		$this->assertStringContainsString( 'nowcrm_bulk_delete_contact_notes', $html );
		$this->assertStringContainsString( 'nowcrm_delete_contact_note', $html );
		$this->assertStringContainsString( 'nowcrm-notes-bulk', $html );
		$this->assertStringContainsString( 'name="created_at"', $html );
		$this->assertStringContainsString( 'type="datetime-local"', $html );
	}

	public function test_render_does_not_show_another_contacts_notes(): void {
		$owner = $this->make_contact();
		$other = $this->make_contact();

		$this->make_note( $owner, array( 'title' => '本人筆記' ) );
		$this->make_note( $other, array( 'title' => '他人專屬筆記' ) );

		ob_start();
		ContactNotes::render( $owner );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( '本人筆記', $html );
		$this->assertStringNotContainsString( '他人專屬筆記', $html );
	}

	public function test_render_requires_the_contact_capability(): void {
		$contact_id = $this->make_contact();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$this->expectException( 'WPDieException' );

		ContactNotes::render( $contact_id );
	}

	/**
	 * Create a note with fictitious data.
	 *
	 * @param array<string, mixed> $overrides Field overrides.
	 */
	private function make_note( int $contact_id, array $overrides = array() ): int {
		$note_id = $this->notes->create(
			$contact_id,
			array_merge(
				array(
					'kind'      => 'note',
					'title'     => '測試筆記 ' . wp_generate_password( 8, false ),
					'body'      => '<p>測試內容</p>',
					'author_id' => $this->actor,
				),
				$overrides
			)
		);

		$this->assertIsInt( $note_id, 'Fixture note creation failed.' );

		return (int) $note_id;
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
			throw new NowCrm_Note_Redirect_Captured( $captured );
		};

		add_filter( 'wp_redirect', $filter, 1 );

		try {
			$handler();
		} catch ( NowCrm_Note_Redirect_Captured $e ) {
			$captured = $e->getMessage();
		} finally {
			remove_filter( 'wp_redirect', $filter, 1 );
		}

		$this->assertNotSame( '', $captured, 'The handler did not redirect.' );

		return $captured;
	}

	/**
	 * Simulate a POST request.
	 *
	 * @param array<string, mixed> $fields Request fields.
	 */
	private function post( array $fields ): void {
		// HTTP form values arrive as strings; simulate that so post_raw() sees
		// the same types a real admin-post request provides.
		$normalize = static function ( $value ) use ( &$normalize ) {
			if ( is_array( $value ) ) {
				return array_map( $normalize, $value );
			}

			if ( null === $value ) {
				return '';
			}

			return is_scalar( $value ) ? (string) $value : $value;
		};

		$payload = array();

		foreach ( $fields as $key => $value ) {
			$payload[ $key ] = $normalize( $value );
		}

		$_POST    = $payload;
		$_REQUEST = $payload;
	}
}
