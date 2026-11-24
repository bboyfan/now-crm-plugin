<?php
/**
 * Outbound signing, retry bounds, inbound endpoint and REST key rules.
 *
 * @package NowCRM
 */

use NowCRM\Contacts\Status;
use NowCRM\Support\Capabilities;
use NowCRM\Support\RestKeys;
use NowCRM\Terms\TermRepository;
use NowCRM\Webhooks\DeliveryLog;
use NowCRM\Webhooks\Dispatcher;
use NowCRM\Webhooks\Events;
use NowCRM\Webhooks\Inbound;
use NowCRM\Webhooks\Signer;
use NowCRM\Webhooks\WebhookRepository;

/**
 * @covers \NowCRM\Webhooks\Signer
 * @covers \NowCRM\Webhooks\WebhookRepository
 * @covers \NowCRM\Webhooks\DeliveryLog
 * @covers \NowCRM\Webhooks\Dispatcher
 * @covers \NowCRM\Webhooks\Inbound
 * @covers \NowCRM\Support\RestKeys
 */
final class WebhooksTest extends NowCrmTestCase {

	private WebhookRepository $webhooks;
	private DeliveryLog $log;
	private Dispatcher $dispatcher;

	private int $http_status = 200;

	private ?Closure $handlers_filter = null;

	public function set_up(): void {
		parent::set_up();

		delete_option( WebhookRepository::OPTION );
		delete_option( DeliveryLog::OPTION );
		delete_option( Inbound::OPTION );
		wp_clear_scheduled_hook( Dispatcher::CRON );

		$this->webhooks   = new WebhookRepository();
		$this->log        = new DeliveryLog();
		$this->dispatcher = new Dispatcher();

		$this->dispatcher->register();

		add_filter( 'pre_http_request', array( $this, 'stub_http' ), 10, 3 );
		add_filter( 'wp_is_application_passwords_available', '__return_true' );
	}

	public function tear_down(): void {
		remove_filter( 'pre_http_request', array( $this, 'stub_http' ), 10 );
		remove_filter( 'wp_is_application_passwords_available', '__return_true' );

		if ( null !== $this->handlers_filter ) {
			remove_filter( 'nowcrm_webhook_handlers', $this->handlers_filter );
			$this->handlers_filter = null;
		}

		wp_clear_scheduled_hook( Dispatcher::CRON );

		parent::tear_down();
	}

	/**
	 * Short-circuit every outbound request. Tests never touch the network.
	 *
	 * @param mixed                $pre  Short-circuit value.
	 * @param array<string, mixed> $args Request args.
	 * @param string               $url  Request URL.
	 * @return mixed
	 */
	public function stub_http( $pre, $args, $url ) {
		if ( false !== $pre ) {
			return $pre;
		}

		return array(
			'headers'  => array(),
			'body'     => '',
			'response' => array(
				'code'    => $this->http_status,
				'message' => 'stub',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	public function test_signature_verifies_and_rejects_replay_and_forgery(): void {
		$secret = str_repeat( 'a', 64 );
		$body   = '{"event":"contact.created"}';
		$stamp  = 1_700_000_000;
		$signed = Signer::sign( $secret, $body, $stamp );

		$this->assertSame( (string) $stamp, $signed[ Signer::HEADER_TIMESTAMP ] );
		$this->assertStringStartsWith( 'sha256=', $signed[ Signer::HEADER_SIGNATURE ] );
		$this->assertTrue(
			Signer::verify( $secret, $body, $signed[ Signer::HEADER_SIGNATURE ], $signed[ Signer::HEADER_TIMESTAMP ], $stamp )
		);
		$this->assertTrue(
			Signer::verify( $secret, $body, $signed[ Signer::HEADER_SIGNATURE ], $signed[ Signer::HEADER_TIMESTAMP ], $stamp + Signer::window() )
		);

		$this->assertFalse(
			Signer::verify( $secret, $body, $signed[ Signer::HEADER_SIGNATURE ], $signed[ Signer::HEADER_TIMESTAMP ], $stamp + Signer::window() + 1 )
		);
		$this->assertFalse(
			Signer::verify( $secret, $body, $signed[ Signer::HEADER_SIGNATURE ], $signed[ Signer::HEADER_TIMESTAMP ], $stamp - Signer::window() - 1 )
		);
		$this->assertFalse(
			Signer::verify( str_repeat( 'b', 64 ), $body, $signed[ Signer::HEADER_SIGNATURE ], $signed[ Signer::HEADER_TIMESTAMP ], $stamp )
		);
		$this->assertFalse(
			Signer::verify( $secret, $body . ' ', $signed[ Signer::HEADER_SIGNATURE ], $signed[ Signer::HEADER_TIMESTAMP ], $stamp )
		);
		$this->assertFalse( Signer::verify( $secret, $body, 'sha256=' . str_repeat( '0', 64 ), (string) $stamp, $stamp ) );
		$this->assertFalse( Signer::verify( $secret, $body, 'md5=abc', (string) $stamp, $stamp ) );
		$this->assertFalse( Signer::verify( $secret, $body, $signed[ Signer::HEADER_SIGNATURE ], 'now', $stamp ) );
	}

	public function test_repository_enforces_event_allowlist_and_url_scheme(): void {
		$created = $this->make_webhook();

		$this->assertSame( 64, strlen( (string) $created['secret'] ) );
		$this->assertTrue( $created['active'] );

		$local = $this->webhooks->create(
			array(
				'name'   => 'Local hook',
				'url'    => 'http://localhost:8080/hook',
				'events' => array( Events::ORDER_COMPLETED ),
				'active' => true,
			)
		);

		$this->assertIsArray( $local );

		$plain_http = $this->webhooks->create(
			array(
				'name'   => 'Remote plain',
				'url'    => 'http://example.com/hook',
				'events' => array( Events::ORDER_COMPLETED ),
			)
		);

		$this->assertWPError( $plain_http );
		$this->assertSame( 'nowcrm_webhook_https_required', $plain_http->get_error_code() );

		$ftp = $this->webhooks->create(
			array(
				'name'   => 'FTP',
				'url'    => 'ftp://example.com/hook',
				'events' => array( Events::ORDER_COMPLETED ),
			)
		);

		$this->assertWPError( $ftp );
		$this->assertSame( 'nowcrm_webhook_invalid_url', $ftp->get_error_code() );

		$unknown_event = $this->webhooks->create(
			array(
				'name'   => 'Unknown event',
				'url'    => 'https://example.test/hook',
				'events' => array( 'contact.deleted' ),
			)
		);

		$this->assertWPError( $unknown_event );
		$this->assertSame( 'nowcrm_webhook_invalid_events', $unknown_event->get_error_code() );

		$no_name = $this->webhooks->create(
			array(
				'name'   => '',
				'url'    => 'https://example.test/hook',
				'events' => array( Events::ORDER_COMPLETED ),
			)
		);

		$this->assertWPError( $no_name );
		$this->assertSame( 'nowcrm_webhook_invalid_name', $no_name->get_error_code() );

		$updated = $this->webhooks->update(
			(string) $local['id'],
			array( 'url' => 'http://example.com/hook' )
		);

		$this->assertWPError( $updated );
		$this->assertSame( 'http://localhost:8080/hook', $this->webhooks->find( (string) $local['id'] )['url'] );

		$this->assertTrue( $this->webhooks->delete( (string) $created['id'] ) );
		$this->assertNull( $this->webhooks->find( (string) $created['id'] ) );
	}

	public function test_successful_delivery_is_terminal_and_never_resent(): void {
		$webhook = $this->make_webhook( array( 'events' => array( Events::CONTACT_CREATED ) ) );

		$this->assertSame( 1, $this->dispatcher->dispatch_event( Events::CONTACT_CREATED, array( 'contact_id' => 7 ) ) );

		$entry = $this->log->all()[0];

		$this->assertSame( 'pending', $entry['status'] );
		$this->assertSame( (string) $webhook['id'], $entry['webhook_id'] );
		$this->assertNotSame( '', $this->log->payload( $entry['id'] ) );

		$this->dispatcher->deliver( $entry['id'] );

		$delivered = $this->log->find( $entry['id'] );

		$this->assertSame( 'delivered', $delivered['status'] );
		$this->assertSame( 1, $delivered['attempts'] );
		$this->assertSame( 200, $delivered['response_code'] );
		$this->assertNull( $this->log->payload( $entry['id'] ) );

		$this->dispatcher->deliver( $entry['id'] );
		$this->assertSame( 1, $this->log->find( $entry['id'] )['attempts'] );
	}

	public function test_retry_backoff_is_bounded_and_stops_at_ceiling(): void {
		$this->assertSame( 30, Dispatcher::backoff( 1 ) );
		$this->assertSame( 60, Dispatcher::backoff( 2 ) );
		$this->assertSame( 120, Dispatcher::backoff( 3 ) );
		$this->assertSame( 240, Dispatcher::backoff( 4 ) );
		$this->assertSame( 3600, Dispatcher::backoff( 20 ) );
		$this->assertSame( 3600, Dispatcher::backoff( 100 ) );

		$this->http_status = 503;

		$this->make_webhook( array( 'events' => array( Events::CONTACT_CREATED ) ) );
		$this->dispatcher->dispatch_event( Events::CONTACT_CREATED, array( 'contact_id' => 8 ) );

		$id = $this->log->all()[0]['id'];

		$this->dispatcher->deliver( $id );

		$first = $this->log->find( $id );

		$this->assertSame( 'retrying', $first['status'] );
		$this->assertSame( 1, $first['attempts'] );
		$this->assertSame( 503, $first['response_code'] );
		$this->assertNotNull( $first['next_attempt_at'] );
		$this->assertNotFalse( wp_next_scheduled( Dispatcher::CRON, array( $id ) ) );

		for ( $attempt = 2; $attempt <= Dispatcher::MAX_ATTEMPTS; $attempt++ ) {
			$this->dispatcher->deliver( $id );
		}

		$failed = $this->log->find( $id );

		$this->assertSame( 'failed', $failed['status'] );
		$this->assertSame( Dispatcher::MAX_ATTEMPTS, $failed['attempts'] );
		$this->assertNull( $failed['next_attempt_at'] );
		$this->assertNull( $this->log->payload( $id ) );
		$this->assertFalse( wp_next_scheduled( Dispatcher::CRON, array( $id ) ) );

		$this->dispatcher->deliver( $id );
		$this->assertSame( Dispatcher::MAX_ATTEMPTS, $this->log->find( $id )['attempts'] );
	}

	public function test_dispatch_targets_only_active_subscribed_webhooks(): void {
		$match = $this->make_webhook( array( 'events' => array( Events::CONTACT_CREATED ) ) );
		$this->make_webhook( array( 'events' => array( Events::CONTACT_CREATED ), 'active' => false ) );
		$this->make_webhook( array( 'events' => array( Events::ORDER_COMPLETED ) ) );

		$this->assertSame( 1, $this->dispatcher->dispatch_event( Events::CONTACT_CREATED, array( 'contact_id' => 9 ) ) );

		$rows = $this->log->all();

		$this->assertCount( 1, $rows );
		$this->assertSame( (string) $match['id'], $rows[0]['webhook_id'] );
		$this->assertSame( 0, $this->dispatcher->dispatch_event( 'contact.deleted', array() ) );
	}

	public function test_inbound_contact_creates_contact_and_applies_lists_and_tags(): void {
		$key   = Inbound::rotate_contact_key();
		$list  = $this->make_term( TermRepository::TYPE_LIST, 'Newsletter' );
		$tag   = $this->make_term( TermRepository::TYPE_TAG, 'VIP' );
		$email = 'inbound-' . wp_generate_password( 6, false ) . '@example.test';

		$result = ( new Inbound() )->handle(
			'contact',
			$key,
			array(
				'email'      => $email,
				'first_name' => 'Inbound',
				'phone'      => '0912345678',
				'lists'      => array( $list ),
				'tags'       => array( 'VIP' ),
			)
		);

		$this->assertSame( 200, $result['code'] );
		$this->assertTrue( $result['body']['created'] );

		$contact = $this->contacts->find_by_email( $email );

		$this->assertIsArray( $contact );
		$this->assertSame( 'Inbound', $contact['first_name'] );

		$attached = array_map( 'intval', wp_list_pluck( $this->terms->for_contact( (int) $contact['id'] ), 'id' ) );

		$this->assertContains( $list, $attached );
		$this->assertContains( $tag, $attached );

		$wrong = ( new Inbound() )->handle( 'contact', 'not-the-key', array( 'email' => $email ) );

		$this->assertSame( 401, $wrong['code'] );
		$this->assertSame( array( 'ok' => false, 'error' => 'unauthorized' ), $wrong['body'] );
	}

	public function test_inbound_validation_failure_writes_nothing(): void {
		$key   = Inbound::rotate_contact_key();
		$inbound = new Inbound();

		$bad_email = $inbound->handle( 'contact', $key, array( 'email' => 'not-an-email' ) );

		$this->assertSame( 422, $bad_email['code'] );
		$this->assertSame( 'validation_failed', $bad_email['body']['error'] );

		$email = 'unknown-list-' . wp_generate_password( 6, false ) . '@example.test';
		$bad_list = $inbound->handle(
			'contact',
			$key,
			array(
				'email' => $email,
				'lists' => array( 999999 ),
			)
		);

		$this->assertSame( 422, $bad_list['code'] );
		$this->assertArrayHasKey( 'lists', $bad_list['body']['fields'] );
		$this->assertNull( $this->contacts->find_by_email( $email ) );

		$this->assertSame( 405, $inbound->handle( 'contact', $key, array( 'email' => $email ), 'GET' )['code'] );
	}

	public function test_inbound_update_keeps_suppressed_status(): void {
		$key   = Inbound::rotate_contact_key();
		$email = 'suppressed-' . wp_generate_password( 6, false ) . '@example.test';
		$id    = $this->make_contact( array( 'email' => $email, 'first_name' => 'Before' ) );

		$this->contacts->set_status( $id, Status::UNSUBSCRIBED, 'test' );

		$result = ( new Inbound() )->handle( 'contact', $key, array( 'email' => $email, 'first_name' => 'After' ) );

		$this->assertSame( 200, $result['code'] );
		$this->assertFalse( $result['body']['created'] );

		$contact = $this->contacts->get( $id );

		$this->assertSame( 'After', $contact['first_name'] );
		$this->assertSame( Status::UNSUBSCRIBED, $contact['status'] );
	}

	public function test_generic_handler_dispatch_is_scoped_and_has_no_route_oracle(): void {
		$this->handlers_filter = static fn(): array => array( 'erp' => 'ERP' );
		add_filter( 'nowcrm_webhook_handlers', $this->handlers_filter );

		$handler_key = Inbound::rotate_handler_key( 'erp' );
		$contact_key = Inbound::rotate_contact_key();
		$received    = array();

		$this->assertIsString( $handler_key );

		$capture = static function ( $payload, $handler ) use ( &$received ): void {
			$received = array( 'payload' => $payload, 'handler' => $handler );
		};

		add_action( 'nowcrm_webhook_handler_erp', $capture, 10, 2 );

		$accepted = ( new Inbound() )->handle( 'erp', $handler_key, array( 'order_id' => 12 ) );

		$this->assertSame( 202, $accepted['code'] );
		$this->assertSame( 'erp', $received['handler'] );
		$this->assertSame( 12, $received['payload']['order_id'] );

		$wrong_scope = ( new Inbound() )->handle( 'erp', $contact_key, array( 'order_id' => 12 ) );
		$unknown     = ( new Inbound() )->handle( 'missing', $handler_key, array() );

		$this->assertSame( 401, $wrong_scope['code'] );
		$this->assertSame( $wrong_scope['body'], $unknown['body'] );

		remove_action( 'nowcrm_webhook_handler_erp', $capture, 10 );
	}

	public function test_application_password_key_lifecycle_and_redaction(): void {
		wp_set_current_user( 1 );

		$manager = self::factory()->user->create( array( 'role' => 'editor' ) );
		$user    = new WP_User( $manager );
		$user->add_cap( Capabilities::MANAGE_CONTACTS );
		clean_user_cache( $manager );

		$created = RestKeys::create( $manager, 'CI key' );

		$this->assertIsArray( $created );
		$this->assertNotSame( '', $created['password'] );
		$this->assertNotEmpty( $created['uuid'] );

		$keys = RestKeys::keys_for( $manager );

		$this->assertCount( 1, $keys );
		$this->assertSame( 'CI key', $keys[0]['name'] );
		$this->assertArrayNotHasKey( 'password', $keys[0] );
		$this->assertStringNotContainsString( $created['password'], (string) wp_json_encode( $keys ) );

		$this->assertTrue( RestKeys::is_capable( $manager ) );
		$this->assertTrue( RestKeys::delete( $manager, $created['uuid'] ) );
		$this->assertSame( array(), RestKeys::keys_for( $manager ) );
	}

	public function test_key_mutation_requires_manager_and_capable_target(): void {
		wp_set_current_user( 1 );

		$manager = self::factory()->user->create( array( 'role' => 'editor' ) );
		$user    = new WP_User( $manager );
		$user->add_cap( Capabilities::MANAGE_CONTACTS );
		clean_user_cache( $manager );

		$plain = self::factory()->user->create( array( 'role' => 'editor' ) );

		$not_capable = RestKeys::create( $plain, 'Nope' );

		$this->assertWPError( $not_capable );
		$this->assertSame( 'nowcrm_rest_keys_user', $not_capable->get_error_code() );

		wp_set_current_user( $manager );

		$forbidden_create = RestKeys::create( $manager, 'Nope' );
		$forbidden_delete = RestKeys::delete( (int) $manager, '00000000-0000-4000-8000-000000000000' );

		$this->assertWPError( $forbidden_create );
		$this->assertSame( 'nowcrm_rest_keys_forbidden', $forbidden_create->get_error_code() );
		$this->assertWPError( $forbidden_delete );
		$this->assertSame( 'nowcrm_rest_keys_forbidden', $forbidden_delete->get_error_code() );
	}

	public function test_unavailable_application_passwords_block_creation(): void {
		wp_set_current_user( 1 );

		add_filter( 'wp_is_application_passwords_available', '__return_false' );

		$result = RestKeys::create( 1, 'Blocked' );

		remove_filter( 'wp_is_application_passwords_available', '__return_false' );

		$this->assertWPError( $result );
		$this->assertSame( 'nowcrm_rest_keys_unavailable', $result->get_error_code() );
	}

	/**
	 * Create a webhook fixture.
	 *
	 * @param array<string, mixed> $overrides Overrides.
	 * @return array<string, mixed>
	 */
	private function make_webhook( array $overrides = array() ): array {
		$result = $this->webhooks->create(
			array_merge(
				array(
					'name'   => 'Hook ' . wp_generate_password( 5, false ),
					'url'    => 'https://example.test/' . wp_generate_password( 6, false ),
					'events' => array( Events::CONTACT_CREATED ),
					'active' => true,
				),
				$overrides
			)
		);

		$this->assertIsArray( $result, 'Webhook fixture creation failed.' );

		return $result;
	}

	/**
	 * Create a term fixture.
	 */
	private function make_term( string $type, string $name ): int {
		$id = $this->terms->create( $type, $name );

		$this->assertIsInt( $id );

		return (int) $id;
	}
}
