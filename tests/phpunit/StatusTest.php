<?php
/**
 * Status model and status history tests.
 *
 * @package NowCRM
 */

use NowCRM\Contacts\Status;

/**
 * @covers \NowCRM\Contacts\Status
 * @covers \NowCRM\Contacts\StatusHistoryRepository
 */
final class StatusTest extends NowCrmTestCase {

	public function test_exactly_seven_statuses_exist(): void {
		$this->assertSame(
			array( 'subscribed', 'pending', 'unsubscribed', 'transactional', 'bounced', 'complained', 'spammed' ),
			Status::all()
		);

		foreach ( Status::all() as $status ) {
			$this->assertTrue( Status::is_valid( $status ) );
			$this->assertNotSame( $status, Status::label( $status ) );
		}

		$this->assertFalse( Status::is_valid( 'customer' ) );
	}

	public function test_creation_records_the_first_status_event(): void {
		$id    = $this->make_contact( array( 'source' => 'test:unit' ) );
		$event = $this->history->for_contact( $id )[0];

		$this->assertNull( $event['old_status'] );
		$this->assertSame( Status::SUBSCRIBED, $event['new_status'] );
		$this->assertSame( 'test:unit', $event['source'] );
		$this->assertNotEmpty( $event['occurred_at'] );
	}

	public function test_status_change_records_source_and_time(): void {
		$id = $this->make_contact();

		$this->assertTrue( $this->contacts->set_status( $id, Status::UNSUBSCRIBED, 'admin:manual', '顧客要求', 7 ) );

		$contact = $this->contacts->get( $id );
		$events  = $this->history->for_contact( $id );

		$this->assertSame( Status::UNSUBSCRIBED, $contact['status'] );
		$this->assertNotEmpty( $contact['status_changed_at'] );
		$this->assertSame( 'admin:manual', $events[0]['source'] );
		$this->assertSame( '顧客要求', $events[0]['note'] );
		$this->assertSame( 7, (int) $events[0]['actor_id'] );
		$this->assertSame( Status::SUBSCRIBED, $events[0]['old_status'] );
	}

	public function test_setting_the_same_status_adds_no_event(): void {
		$id = $this->make_contact();

		$before = count( $this->history->for_contact( $id ) );

		$this->assertTrue( $this->contacts->set_status( $id, Status::SUBSCRIBED, 'test' ) );

		$this->assertCount( $before, $this->history->for_contact( $id ) );
	}

	public function test_invalid_status_change_is_rejected(): void {
		$id = $this->make_contact();

		$result = $this->contacts->set_status( $id, 'vip', 'test' );

		$this->assertWPError( $result );
		$this->assertSame( Status::SUBSCRIBED, $this->contacts->get( $id )['status'] );
	}

	public function test_suppression_helper_matches_the_contract(): void {
		$this->assertTrue( Status::is_suppressed( Status::UNSUBSCRIBED ) );
		$this->assertTrue( Status::is_suppressed( Status::BOUNCED ) );
		$this->assertTrue( Status::is_suppressed( Status::COMPLAINED ) );
		$this->assertTrue( Status::is_suppressed( Status::SPAMMED ) );
		$this->assertFalse( Status::is_suppressed( Status::PENDING ) );
		$this->assertFalse( Status::is_suppressed( Status::SUBSCRIBED ) );
		$this->assertFalse( Status::is_suppressed( Status::TRANSACTIONAL ) );
	}

	public function test_cart_email_policy_allows_only_subscribed_and_transactional(): void {
		$this->assertTrue( Status::allows_cart_email( Status::SUBSCRIBED ) );
		$this->assertTrue( Status::allows_cart_email( Status::TRANSACTIONAL ) );
		$this->assertFalse( Status::allows_cart_email( Status::PENDING ) );
		$this->assertFalse( Status::allows_cart_email( Status::UNSUBSCRIBED ) );
		$this->assertFalse( Status::allows_cart_email( Status::BOUNCED ) );
		$this->assertFalse( Status::allows_cart_email( Status::COMPLAINED ) );
		$this->assertFalse( Status::allows_cart_email( Status::SPAMMED ) );
	}

	public function test_new_woo_contact_default_status_follows_the_setting(): void {
		$this->assertSame( Status::SUBSCRIBED, Status::for_new_woo_contact( true ) );
		$this->assertSame( Status::TRANSACTIONAL, Status::for_new_woo_contact( false ) );
	}
}
