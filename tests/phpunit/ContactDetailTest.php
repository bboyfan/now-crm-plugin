<?php
/**
 * Contact detail split actions and tab routing.
 *
 * @package NowCRM
 */

use NowCRM\Admin\Actions;
use NowCRM\Admin\ContactDetailPage;
use NowCRM\Admin\Menu;
use NowCRM\Contacts\Status;
use NowCRM\Terms\TermRepository;

/**
 * @covers \NowCRM\Admin\Actions
 * @covers \NowCRM\Admin\ContactDetailPage
 */
final class ContactDetailTest extends NowCrmTestCase {

	private int $actor;

	public function set_up(): void {
		parent::set_up();

		$this->actor = (int) self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->actor );
	}

	public function test_profile_save_does_not_change_status_or_membership(): void {
		$contact = $this->make_contact(
			array(
				'status'     => Status::SUBSCRIBED,
				'first_name' => 'Before',
			)
		);

		$list = $this->terms->create( TermRepository::TYPE_LIST, 'Retained list' );

		$this->assertIsInt( $list );
		$this->terms->attach( $contact, (int) $list );

		$history_before = count( $this->history->for_contact( $contact, 50 ) );

		$result = Actions::persist_contact(
			$contact,
			array(
				'email'      => (string) $this->contacts->get( $contact )['email'],
				'first_name' => 'After',
				'last_name'  => 'Buyer',
				'status'     => Status::UNSUBSCRIBED,
				'note'       => 'stale status note',
				'term_ids'   => array(),
			)
		);

		$this->assertTrue( $result );

		$saved = $this->contacts->get( $contact );

		$this->assertSame( 'After', $saved['first_name'] );
		$this->assertSame( Status::SUBSCRIBED, $saved['status'] );
		$this->assertSame(
			array( (int) $list ),
			array_map( 'intval', wp_list_pluck( $this->terms->for_contact( $contact ), 'id' ) )
		);
		$this->assertCount( $history_before, $this->history->for_contact( $contact, 50 ) );
	}

	public function test_status_action_records_audit_without_touching_profile_or_membership(): void {
		$contact = $this->make_contact(
			array(
				'status'     => Status::SUBSCRIBED,
				'first_name' => 'Mei',
				'last_name'  => 'Chen',
				'phone'      => '0912345678',
			)
		);

		$tag = $this->terms->create( TermRepository::TYPE_TAG, 'Retained tag' );

		$this->assertIsInt( $tag );
		$this->terms->attach( $contact, (int) $tag );

		$history_before = count( $this->history->for_contact( $contact, 50 ) );
		$profile_before = $this->contacts->get( $contact );

		$this->assertTrue(
			Actions::update_contact_status( $contact, Status::PENDING, 'operator note' )
		);

		$saved = $this->contacts->get( $contact );

		$this->assertSame( Status::PENDING, $saved['status'] );
		$this->assertSame( $profile_before['first_name'], $saved['first_name'] );
		$this->assertSame( $profile_before['last_name'], $saved['last_name'] );
		$this->assertSame( $profile_before['phone'], $saved['phone'] );
		$this->assertSame( $profile_before['email'], $saved['email'] );
		$this->assertSame(
			array( (int) $tag ),
			array_map( 'intval', wp_list_pluck( $this->terms->for_contact( $contact ), 'id' ) )
		);

		$events = $this->history->for_contact( $contact, 50 );

		$this->assertCount( $history_before + 1, $events );
		$this->assertSame( 'admin', $events[0]['source'] );
		$this->assertSame( 'operator note', $events[0]['note'] );
		$this->assertSame( (string) $this->actor, (string) $events[0]['actor_id'] );
		$this->assertSame( Status::PENDING, $events[0]['new_status'] );
	}

	public function test_membership_delta_rejects_cross_type_and_keeps_unrelated_terms(): void {
		$first  = $this->make_contact();
		$second = $this->make_contact();

		$list = $this->terms->create( TermRepository::TYPE_LIST, 'Delta list' );
		$tag  = $this->terms->create( TermRepository::TYPE_TAG, 'Delta tag' );

		$this->assertIsInt( $list );
		$this->assertIsInt( $tag );

		$this->terms->attach( $first, (int) $tag );
		$this->terms->attach( $second, (int) $list );

		$cross = Actions::update_contact_term( $second, TermRepository::TYPE_TAG, (int) $list, 'attach' );

		$this->assertWPError( $cross );
		$this->assertSame( 'nowcrm_invalid_term', $cross->get_error_code() );
		$this->assertSame(
			array( (int) $list ),
			array_map( 'intval', wp_list_pluck( $this->terms->for_contact( $second ), 'id' ) )
		);
		$this->assertSame(
			array( (int) $tag ),
			array_map( 'intval', wp_list_pluck( $this->terms->for_contact( $first ), 'id' ) )
		);

		$this->assertTrue( Actions::update_contact_term( $second, TermRepository::TYPE_TAG, (int) $tag, 'attach' ) );

		$second_ids = array_map( 'intval', wp_list_pluck( $this->terms->for_contact( $second ), 'id' ) );
		sort( $second_ids );

		$this->assertSame( array( (int) $list, (int) $tag ), $second_ids );

		$this->assertTrue( Actions::update_contact_term( $second, TermRepository::TYPE_LIST, (int) $list, 'remove' ) );

		$this->assertSame(
			array( (int) $tag ),
			array_map( 'intval', wp_list_pluck( $this->terms->for_contact( $second ), 'id' ) )
		);
		$this->assertSame(
			array( (int) $tag ),
			array_map( 'intval', wp_list_pluck( $this->terms->for_contact( $first ), 'id' ) )
		);

		$invalid = Actions::update_contact_term( $second, TermRepository::TYPE_TAG, (int) $tag, 'destroy' );

		$this->assertWPError( $invalid );
		$this->assertSame( 'nowcrm_invalid_operation', $invalid->get_error_code() );
	}

	public function test_term_hooks_fire_once_for_idempotent_attach(): void {
		$contact = $this->make_contact();
		$list    = $this->terms->create( TermRepository::TYPE_LIST, 'Hook list' );

		$this->assertIsInt( $list );

		$attached = 0;
		$detached = 0;

		$on_attach = static function ( $contact_id, $term_id, $type ) use ( &$attached, $contact, $list ): void {
			if ( (int) $contact_id === $contact && (int) $term_id === (int) $list && TermRepository::TYPE_LIST === $type ) {
				$attached++;
			}
		};

		$on_detach = static function ( $contact_id, $term_id, $type ) use ( &$detached, $contact, $list ): void {
			if ( (int) $contact_id === $contact && (int) $term_id === (int) $list && TermRepository::TYPE_LIST === $type ) {
				$detached++;
			}
		};

		add_action( 'nowcrm_term_attached', $on_attach, 10, 3 );
		add_action( 'nowcrm_term_detached', $on_detach, 10, 3 );

		$this->assertTrue( Actions::update_contact_term( $contact, TermRepository::TYPE_LIST, (int) $list, 'attach' ) );
		$this->assertTrue( Actions::update_contact_term( $contact, TermRepository::TYPE_LIST, (int) $list, 'attach' ) );
		$this->assertTrue( Actions::update_contact_term( $contact, TermRepository::TYPE_LIST, (int) $list, 'remove' ) );
		$this->assertTrue( Actions::update_contact_term( $contact, TermRepository::TYPE_LIST, (int) $list, 'remove' ) );

		remove_action( 'nowcrm_term_attached', $on_attach, 10 );
		remove_action( 'nowcrm_term_detached', $on_detach, 10 );

		$this->assertSame( 1, $attached );
		$this->assertSame( 1, $detached );
	}

	public function test_tab_route_renders_only_the_requested_tab(): void {
		$contact = $this->make_contact();
		$base    = array(
			'page' => Menu::CONTACT_SLUG,
			'id'   => (string) $contact,
		);

		$overview = $this->render_page( $base );

		$this->assertStringContainsString( 'data-nowcrm-profile-form', $overview );
		$this->assertStringContainsString( 'name="contact_type"', $overview );
		$this->assertStringContainsString( 'name="timezone"', $overview );
		$this->assertStringNotContainsString( 'id="nowcrm-contact-email"', $overview );
		$this->assertStringNotContainsString( 'id="nowcrm-contact-notes"', $overview );
		$this->assertStringNotContainsString( '購買統計', $overview );

		$emails = $this->render_page( $base + array( 'tab' => 'emails' ) );

		$this->assertStringContainsString( 'id="nowcrm-contact-email"', $emails );
		$this->assertStringNotContainsString( 'data-nowcrm-profile-form', $emails );
		$this->assertStringNotContainsString( 'id="nowcrm-contact-notes"', $emails );

		$purchases = $this->render_page( $base + array( 'tab' => 'purchases' ) );

		$this->assertStringContainsString( '購買統計', $purchases );
		$this->assertStringNotContainsString( 'data-nowcrm-profile-form', $purchases );
		$this->assertStringNotContainsString( 'id="nowcrm-contact-email"', $purchases );

		$notes = $this->render_page( $base + array( 'tab' => 'notes' ) );

		$this->assertStringContainsString( '狀態動態', $notes );
		$this->assertStringContainsString( 'id="nowcrm-contact-notes"', $notes );
		$this->assertStringNotContainsString( 'data-nowcrm-profile-form', $notes );

		$fallback = $this->render_page( $base + array( 'tab' => 'not-a-tab' ) );

		$this->assertStringContainsString( 'data-nowcrm-profile-form', $fallback );
	}

	public function test_missing_contact_page_stays_useful(): void {
		$html = $this->render_page(
			array(
				'page' => Menu::CONTACT_SLUG,
				'id'   => 999999,
			)
		);

		$this->assertStringContainsString( '找不到聯絡人', $html );
		$this->assertStringContainsString( '返回聯絡人列表', $html );
		$this->assertStringNotContainsString( 'data-nowcrm-profile-form', $html );
	}

	/**
	 * Render the detail screen with one query state.
	 *
	 * @param array<string, mixed> $query Query arguments.
	 */
	private function render_page( array $query ): string {
		$_GET = $query;

		ob_start();

		try {
			ContactDetailPage::render();
		} finally {
			$html = (string) ob_get_clean();
			$_GET = array();
		}

		return $html;
	}
}
