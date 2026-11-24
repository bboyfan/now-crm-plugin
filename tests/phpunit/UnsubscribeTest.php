<?php
/**
 * Unsubscribe token lifecycle and immediate suppression.
 *
 * @package NowCRM
 */

use NowCRM\Contacts\Status;
use NowCRM\Email\EmailJobRepository;
use NowCRM\Email\SuppressionRepository;
use NowCRM\Support\Schema;
use NowCRM\Unsubscribe\Endpoint;
use NowCRM\Unsubscribe\Tokens;

/**
 * @covers \NowCRM\Unsubscribe\Tokens
 * @covers \NowCRM\Unsubscribe\Endpoint
 */
final class UnsubscribeTest extends NowCrmTestCase {

	private Tokens $tokens;
	private Endpoint $endpoint;

	public function set_up(): void {
		parent::set_up();

		$this->tokens   = new Tokens();
		$this->endpoint = new Endpoint();
	}

	public function test_token_is_stored_hashed_and_process_unsubscribes(): void {
		global $wpdb;

		$contact = $this->make_contact( array( 'email' => 'optout@example.test' ) );
		$issued  = $this->tokens->issue( $contact );

		$this->assertSame( 64, strlen( $issued['token'] ) );

		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . Schema::table( 'unsubscribe_tokens' ) . ' WHERE contact_id = %d', $contact ),
			ARRAY_A
		);

		$this->assertSame( hash( 'sha256', $issued['token'] ), $row['token_hash'] );
		$this->assertNotSame( $issued['token'], $row['token_hash'] );

		$result = $this->endpoint->process( $issued['token'], 'too_many', 'unsubscribe-page' );

		$this->assertTrue( $result['ok'] );
		$this->assertFalse( $result['already'] );
		$this->assertSame( Status::UNSUBSCRIBED, $this->contacts->get( $contact )['status'] );
		$this->assertTrue( ( new SuppressionRepository() )->is_suppressed( 'optout@example.test' ) );

		$history = $this->history->for_contact( $contact, 5 );
		$this->assertSame( '信件太多', $history[0]['note'] );
		$this->assertSame( 'unsubscribe-page', $history[0]['source'] );
	}

	public function test_process_immediately_suppresses_queued_marketing_jobs(): void {
		$contact = $this->make_contact( array( 'email' => 'queued@example.test' ) );
		$issued  = $this->tokens->issue( $contact );

		$job_id = ( new EmailJobRepository() )->enqueue(
			array(
				'job_key'   => 'campaign:1:contact:' . $contact,
				'kind'      => 'campaign',
				'campaign_id' => 1,
				'contact_id' => $contact,
				'recipient' => 'queued@example.test',
				'subject'   => 'Queued',
			)
		);

		$this->endpoint->process( $issued['token'], '', 'unsubscribe-one-click' );

		$this->assertSame(
			EmailJobRepository::STATUS_SUPPRESSED,
			( new EmailJobRepository() )->get( (int) $job_id )['status']
		);
	}

	public function test_replay_is_idempotent(): void {
		$contact = $this->make_contact();
		$issued  = $this->tokens->issue( $contact );

		$this->endpoint->process( $issued['token'], '', 'unsubscribe-page' );
		$history = $this->history->for_contact( $contact, 10 );

		$again = $this->endpoint->process( $issued['token'], '', 'unsubscribe-page' );

		$this->assertTrue( $again['ok'] );
		$this->assertTrue( $again['already'] );
		$this->assertCount( count( $history ), $this->history->for_contact( $contact, 10 ) );
	}

	public function test_expired_and_forged_tokens_are_rejected(): void {
		global $wpdb;

		$contact = $this->make_contact();
		$issued  = $this->tokens->issue( $contact );

		$wpdb->update(
			Schema::table( 'unsubscribe_tokens' ),
			array( 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ),
			array( 'contact_id' => $contact )
		);

		$this->assertSame( 410, $this->endpoint->process( $issued['token'] )['code'] );
		$this->assertSame( 410, $this->endpoint->process( str_repeat( 'a', 64 ) )['code'] );
		$this->assertSame( Status::SUBSCRIBED, $this->contacts->get( $contact )['status'] );
	}

	public function test_one_click_source_is_recorded(): void {
		$contact = $this->make_contact();
		$issued  = $this->tokens->issue( $contact );

		$this->endpoint->process( $issued['token'], '', 'unsubscribe-one-click' );

		$history = $this->history->for_contact( $contact, 5 );

		$this->assertSame( 'unsubscribe-one-click', $history[0]['source'] );
	}
}
