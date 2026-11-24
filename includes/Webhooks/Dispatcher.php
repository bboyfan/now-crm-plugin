<?php
/**
 * Outbound event dispatch.
 *
 * Native business hooks enqueue one delivery per matching webhook and return.
 * No request performs an HTTP call inline: WP-Cron owns the actual send, which
 * keeps checkout, imports and automation runs fast. Retries use exponential
 * backoff and stop at a fixed attempt ceiling.
 *
 * @package NowCRM
 */

namespace NowCRM\Webhooks;

use NowCRM\Cart\CartRepository;
use NowCRM\Contacts\ContactRepository;
use NowCRM\Terms\TermRepository;

defined( 'ABSPATH' ) || exit;

final class Dispatcher {

	public const CRON = 'nowcrm_webhook_deliver';

	public const MAX_ATTEMPTS = 5;

	public const BASE_DELAY = 30;

	public const MAX_DELAY = 3600;

	public const TIMEOUT = 5;

	public const MAX_PAYLOAD = 65536;

	private WebhookRepository $webhooks;

	private DeliveryLog $log;

	private ContactRepository $contacts;

	private TermRepository $terms;

	/**
	 * Register once, even when several code paths instantiate the dispatcher.
	 */
	private static bool $registered = false;

	public function __construct() {
		$this->webhooks = new WebhookRepository();
		$this->log      = new DeliveryLog();
		$this->contacts = new ContactRepository();
		$this->terms    = new TermRepository();
	}

	/**
	 * Register native event listeners and the cron handler.
	 */
	public function register(): void {
		if ( self::$registered ) {
			return;
		}

		self::$registered = true;

		add_action( 'nowcrm_contact_created', array( $this, 'on_contact_created' ), 20, 2 );
		add_action( 'nowcrm_contact_updated', array( $this, 'on_contact_updated' ), 20, 2 );
		add_action( 'nowcrm_contact_status_changed', array( $this, 'on_contact_status_changed' ), 20, 5 );
		add_action( 'nowcrm_term_attached', array( $this, 'on_term_attached' ), 20, 3 );
		add_action( 'nowcrm_term_detached', array( $this, 'on_term_detached' ), 20, 3 );
		add_action( 'nowcrm_csv_contact_imported', array( $this, 'on_csv_imported' ), 20, 3 );
		add_action( 'nowcrm_order_completed', array( $this, 'on_order_completed' ), 20, 2 );
		add_action( 'nowcrm_order_refunded', array( $this, 'on_order_refunded' ), 20, 3 );
		add_action( 'nowcrm_order_cancelled', array( $this, 'on_order_cancelled' ), 20, 2 );
		add_action( 'nowcrm_cart_abandoned', array( $this, 'on_cart_abandoned' ), 20, 2 );
		add_action( 'nowcrm_cart_completed', array( $this, 'on_cart_completed' ), 20, 3 );
		add_action( 'nowcrm_email_accepted', array( $this, 'on_email_accepted' ), 20, 2 );
		add_action( self::CRON, array( $this, 'deliver' ), 10, 1 );
	}

	/**
	 * Enqueue one delivery per active webhook subscribed to an event.
	 *
	 * @param string               $event Allow-listed event name.
	 * @param array<string, mixed> $data  Event payload.
	 * @return int Number of deliveries queued.
	 */
	public function dispatch_event( string $event, array $data ): int {
		if ( ! Events::is_valid( $event ) ) {
			return 0;
		}

		$webhooks = $this->webhooks->active_for_event( $event );

		if ( array() === $webhooks ) {
			return 0;
		}

		$queued = 0;

		foreach ( $webhooks as $webhook ) {
			$id   = wp_generate_uuid4();
			$body = $this->encode( $id, $event, $data );

			$this->log->add(
				array(
					'id'         => $id,
					'webhook_id' => (string) $webhook['id'],
					'event'      => $event,
					'url'        => (string) $webhook['url'],
					'status'     => 'pending',
				)
			);

			if ( ! $this->log->save_payload( $id, $body ) ) {
				$this->log->update(
					$id,
					array(
						'status' => 'failed',
						'error'  => 'payload_store_failed',
					)
				);
				continue;
			}

			wp_schedule_single_event( time(), self::CRON, array( $id ) );
			++$queued;
		}

		return $queued;
	}

	/**
	 * Cron handler: send one delivery or schedule a bounded retry.
	 */
	public function deliver( $delivery_id ): void {
		$id    = is_string( $delivery_id ) ? $delivery_id : '';
		$entry = '' === $id ? null : $this->log->find( $id );

		if ( ! $entry || in_array( $entry['status'], array( 'delivered', 'failed' ), true ) ) {
			return;
		}

		if ( ! $this->log->claim( $id ) ) {
			return;
		}

		try {
			$webhook = $this->webhooks->find( (string) $entry['webhook_id'] );

			if ( ! $webhook || empty( $webhook['active'] ) ) {
				$this->fail( $id, 'webhook_inactive' );
				return;
			}

			$body = $this->log->payload( $id );

			if ( null === $body ) {
				$this->fail( $id, 'payload_expired' );
				return;
			}

			$signature = Signer::sign( (string) $webhook['secret'], $body );

			$response = wp_remote_post(
				(string) $webhook['url'],
				array(
					'timeout'     => self::TIMEOUT,
					'redirection' => 0,
					'blocking'    => true,
					'sslverify'   => true,
					'headers'     => array_merge(
						$signature,
						array(
							'Content-Type'        => 'application/json; charset=utf-8',
							'Accept'              => 'application/json',
							'User-Agent'          => 'NOW-CRM/' . ( defined( 'NOWCRM_VERSION' ) ? NOWCRM_VERSION : 'dev' ),
							Signer::HEADER_EVENT  => (string) $entry['event'],
							Signer::HEADER_DELIVERY => $id,
						)
					),
					'body'        => $body,
				)
			);

			$attempts = (int) $entry['attempts'] + 1;

			if ( is_wp_error( $response ) ) {
				$this->retry_or_fail( $id, $attempts, null, $response->get_error_message() );
				return;
			}

			$code = (int) wp_remote_retrieve_response_code( $response );

			if ( $code >= 200 && $code < 300 ) {
				$this->log->update(
					$id,
					array(
						'status'          => 'delivered',
						'attempts'        => $attempts,
						'response_code'   => $code,
						'error'           => '',
						'next_attempt_at' => null,
					)
				);
				$this->log->delete_payload( $id );
				wp_clear_scheduled_hook( self::CRON, array( $id ) );

				return;
			}

			$this->retry_or_fail( $id, $attempts, $code, sprintf( 'HTTP %d', $code ) );
		} finally {
			$this->log->release( $id );
		}
	}

	/**
	 * Backoff for an attempt number, capped at MAX_DELAY.
	 *
	 * Attempt 1 waits BASE_DELAY, attempt 2 waits twice that, and so on.
	 */
	public static function backoff( int $attempt ): int {
		$attempt = max( 1, $attempt );
		$delay   = self::BASE_DELAY * ( 2 ** min( 20, $attempt - 1 ) );

		return (int) min( self::MAX_DELAY, $delay );
	}

	/**
	 * Hook: contact created.
	 */
	public function on_contact_created( $contact_id, $context = array() ): void {
		$contact_id = absint( $contact_id );
		$context    = is_array( $context ) ? $context : array();

		$this->dispatch_event(
			Events::CONTACT_CREATED,
			$this->contact_data( $contact_id )
		);

		$this->automation_triggered(
			'contact_created',
			$contact_id,
			array(
				'source' => (string) ( $context['source'] ?? '' ),
				'status' => (string) ( $context['status'] ?? '' ),
			)
		);
	}

	/**
	 * Hook: contact updated.
	 */
	public function on_contact_updated( $contact_id, $context = array() ): void {
		$contact_id = absint( $contact_id );
		$context    = is_array( $context ) ? $context : array();
		$fields     = array_values( array_filter( array_map( 'sanitize_key', (array) ( $context['fields'] ?? array() ) ) ) );

		$this->dispatch_event(
			Events::CONTACT_UPDATED,
			array_merge(
				$this->contact_data( $contact_id ),
				array(
					'fields' => $fields,
					'source' => (string) ( $context['source'] ?? '' ),
				)
			)
		);

		$this->automation_triggered(
			'contact_updated',
			$contact_id,
			array(
				'fields' => $fields,
				'source' => (string) ( $context['source'] ?? '' ),
			)
		);
	}

	/**
	 * Hook: contact status changed.
	 */
	public function on_contact_status_changed( $contact_id, $old_status = '', $new_status = '', $source = '', $actor_id = null ): void {
		$contact_id = absint( $contact_id );
		$data       = array_merge(
			$this->contact_data( $contact_id ),
			array(
				'old_status' => sanitize_key( (string) $old_status ),
				'new_status' => sanitize_key( (string) $new_status ),
				'source'     => sanitize_text_field( (string) $source ),
				'actor_id'   => null === $actor_id ? null : absint( $actor_id ),
			)
		);

		$this->dispatch_event( Events::CONTACT_STATUS, $data );

		$this->automation_triggered(
			'contact_status_changed',
			$contact_id,
			array(
				'old_status' => sanitize_key( (string) $old_status ),
				'new_status' => sanitize_key( (string) $new_status ),
				'source'     => sanitize_text_field( (string) $source ),
			)
		);
	}

	/**
	 * Hook: contact attached to a list or tag.
	 */
	public function on_term_attached( $contact_id, $term_id, $term_type = '' ): void {
		$this->on_term( Events::CONTACT_LIST_ADDED, Events::CONTACT_TAG_ADDED, 'term_attached', $contact_id, $term_id, $term_type );
	}

	/**
	 * Hook: contact detached from a list or tag.
	 */
	public function on_term_detached( $contact_id, $term_id, $term_type = '' ): void {
		$this->on_term( Events::CONTACT_LIST_REMOVED, Events::CONTACT_TAG_REMOVED, 'term_detached', $contact_id, $term_id, $term_type );
	}

	/**
	 * Hook: CSV import created a contact.
	 */
	public function on_csv_imported( $contact_id, $email = '', $values = array() ): void {
		$contact_id = absint( $contact_id );

		$this->dispatch_event(
			Events::CONTACT_CSV_IMPORTED,
			array_merge(
				$this->contact_data( $contact_id ),
				array( 'source' => 'csv_import' )
			)
		);

		$this->automation_triggered(
			'csv_imported',
			$contact_id,
			array( 'source' => 'csv_import' )
		);
	}

	/**
	 * Hook: order completed.
	 */
	public function on_order_completed( $order_id, $contact_id = 0 ): void {
		$order_id   = absint( $order_id );
		$contact_id = absint( $contact_id );

		$this->dispatch_event( Events::ORDER_COMPLETED, $this->order_data( $order_id, $contact_id ) );

		$this->automation_triggered( 'order_completed', $contact_id, array( 'order_id' => $order_id ) );
	}

	/**
	 * Hook: order refunded.
	 */
	public function on_order_refunded( $order_id, $contact_id = 0, $refund_id = 0 ): void {
		$order_id   = absint( $order_id );
		$contact_id = absint( $contact_id );

		$this->dispatch_event(
			Events::ORDER_REFUNDED,
			$this->order_data( $order_id, $contact_id, array( 'refund_id' => absint( $refund_id ) ) )
		);

		$this->automation_triggered(
			'order_refunded',
			$contact_id,
			array(
				'order_id'  => $order_id,
				'refund_id' => absint( $refund_id ),
			)
		);
	}

	/**
	 * Hook: order cancelled.
	 */
	public function on_order_cancelled( $order_id, $contact_id = 0 ): void {
		$order_id   = absint( $order_id );
		$contact_id = absint( $contact_id );

		$this->dispatch_event( Events::ORDER_CANCELLED, $this->order_data( $order_id, $contact_id ) );

		$this->automation_triggered( 'order_cancelled', $contact_id, array( 'order_id' => $order_id ) );
	}

	/**
	 * Hook: cart abandoned.
	 */
	public function on_cart_abandoned( $cart_id, $email = '' ): void {
		$cart_id = absint( $cart_id );
		$contact = is_email( (string) $email ) ? $this->contacts->find_by_email( (string) $email ) : null;
		$data    = $this->cart_data( $cart_id, $contact ? (int) $contact['id'] : 0 );

		$this->dispatch_event( Events::CART_ABANDONED, $data );

		$this->automation_triggered( 'cart_abandoned', (int) ( $data['contact_id'] ?? 0 ), array( 'cart_id' => $cart_id ) );
	}

	/**
	 * Hook: cart completed into an order.
	 */
	public function on_cart_completed( $cart_id, $order_id = 0, $source = '' ): void {
		$cart_id = absint( $cart_id );

		$this->dispatch_event(
			Events::CART_COMPLETED,
			array_merge(
				$this->cart_data( $cart_id, 0 ),
				array(
					'order_id' => absint( $order_id ),
					'source'   => sanitize_text_field( (string) $source ),
				)
			)
		);
	}

	/**
	 * Hook: a mail job was accepted by the transport.
	 */
	public function on_email_accepted( $job, $dispatch = array() ): void {
		if ( ! is_array( $job ) ) {
			return;
		}

		$contact_id = absint( $job['contact_id'] ?? 0 );
		$kind       = sanitize_key( (string) ( $job['kind'] ?? '' ) );
		$campaign   = absint( $job['campaign_id'] ?? 0 );
		$dispatch   = is_array( $dispatch ) ? $dispatch : array();

		$data = array(
			'job_id'      => absint( $job['id'] ?? 0 ),
			'kind'        => $kind,
			'campaign_id' => $campaign,
			'cart_id'     => absint( $job['cart_id'] ?? 0 ),
			'contact_id'  => $contact_id,
			'recipient'   => sanitize_email( (string) ( $job['recipient'] ?? '' ) ),
			'transport'   => sanitize_key( (string) ( $dispatch['transport'] ?? '' ) ),
			'message_id'  => sanitize_text_field( (string) ( $dispatch['message_id'] ?? '' ) ),
		);

		$this->dispatch_event( Events::EMAIL_ACCEPTED, $data );

		if ( 'campaign' === $kind && $campaign > 0 ) {
			$this->dispatch_event(
				Events::CAMPAIGN_EMAIL_ACCEPTED,
				array(
					'campaign_id' => $campaign,
					'job_id'      => $data['job_id'],
					'contact_id'  => $contact_id,
					'recipient'   => $data['recipient'],
				)
			);
		}
	}

	/**
	 * List/tag attach or detach.
	 */
	private function on_term( string $list_event, string $tag_event, string $trigger, $contact_id, $term_id, $term_type ): void {
		$contact_id = absint( $contact_id );
		$term_id    = absint( $term_id );
		$term       = $this->terms->get( $term_id );
		$type       = $term ? (string) $term['type'] : sanitize_key( (string) $term_type );

		$data = array(
			'contact_id' => $contact_id,
			'term_id'    => $term_id,
			'term_type'  => $type,
			'term_name'  => (string) ( $term['name'] ?? '' ),
			'term_slug'  => (string) ( $term['slug'] ?? '' ),
		);

		$this->dispatch_event( TermRepository::TYPE_LIST === $type ? $list_event : $tag_event, $data );

		$this->automation_triggered(
			$trigger,
			$contact_id,
			array(
				'term_id'   => $term_id,
				'term_type' => $type,
			)
		);
	}

	/**
	 * Emit the automation trigger view of a native event.
	 *
	 * @param array<string, mixed> $context Curated context.
	 */
	private function automation_triggered( string $trigger, int $contact_id, array $context = array() ): void {
		$this->dispatch_event(
			Events::AUTOMATION_TRIGGERED,
			array(
				'trigger'    => $trigger,
				'contact_id' => $contact_id,
				'context'    => $context,
			)
		);
	}

	/**
	 * Curated contact snapshot.
	 *
	 * @return array<string, mixed>
	 */
	private function contact_data( int $contact_id ): array {
		$contact = $contact_id > 0 ? $this->contacts->get( $contact_id ) : null;

		if ( ! $contact ) {
			return array( 'contact_id' => $contact_id );
		}

		return array(
			'contact_id' => (int) $contact['id'],
			'email'      => (string) $contact['email'],
			'first_name' => (string) $contact['first_name'],
			'last_name'  => (string) $contact['last_name'],
			'phone'      => (string) $contact['phone'],
			'status'     => (string) $contact['status'],
			'source'     => (string) $contact['source'],
			'created_at' => (string) $contact['created_at'],
			'updated_at' => (string) $contact['updated_at'],
		);
	}

	/**
	 * Curated order snapshot.
	 *
	 * @param array<string, mixed> $extra Extra fields.
	 * @return array<string, mixed>
	 */
	private function order_data( int $order_id, int $contact_id, array $extra = array() ): array {
		$data = array(
			'order_id'   => $order_id,
			'contact_id' => $contact_id,
		);

		if ( function_exists( 'wc_get_order' ) ) {
			$order = wc_get_order( $order_id );

			if ( $order ) {
				$data += array(
					'number'   => (string) $order->get_order_number(),
					'status'   => (string) $order->get_status(),
					'total'    => (float) $order->get_total(),
					'currency' => (string) $order->get_currency(),
				);
			}
		}

		return array_merge( $data, $extra );
	}

	/**
	 * Curated cart snapshot.
	 *
	 * @return array<string, mixed>
	 */
	private function cart_data( int $cart_id, int $contact_id ): array {
		$data = array(
			'cart_id'    => $cart_id,
			'contact_id' => $contact_id,
		);

		$cart = $cart_id > 0 ? ( new CartRepository() )->get( $cart_id ) : null;

		if ( $cart ) {
			$data += array(
				'email'      => (string) ( $cart['email'] ?? '' ),
				'item_count' => (int) ( $cart['item_count'] ?? 0 ),
				'total'      => (float) ( $cart['total'] ?? 0 ),
			);
		}

		return $data;
	}

	/**
	 * Mark a delivery failed without a further attempt.
	 */
	private function fail( string $id, string $error ): void {
		$this->log->update(
			$id,
			array(
				'status'          => 'failed',
				'error'           => $error,
				'next_attempt_at' => null,
			)
		);

		$this->log->delete_payload( $id );
		wp_clear_scheduled_hook( self::CRON, array( $id ) );
	}

	/**
	 * Retry with backoff, or stop at the attempt ceiling.
	 */
	private function retry_or_fail( string $id, int $attempts, ?int $code, string $error ): void {
		if ( $attempts >= self::MAX_ATTEMPTS ) {
			$this->log->update(
				$id,
				array(
					'status'          => 'failed',
					'attempts'        => $attempts,
					'response_code'   => $code,
					'error'           => substr( $error, 0, 200 ),
					'next_attempt_at' => null,
				)
			);
			$this->log->delete_payload( $id );
			wp_clear_scheduled_hook( self::CRON, array( $id ) );

			return;
		}

		$delay = self::backoff( $attempts );
		$next  = gmdate( 'Y-m-d H:i:s', time() + $delay );

		$this->log->update(
			$id,
			array(
				'status'          => 'retrying',
				'attempts'        => $attempts,
				'response_code'   => $code,
				'error'           => substr( $error, 0, 200 ),
				'next_attempt_at' => $next,
			)
		);

		wp_schedule_single_event( time() + $delay, self::CRON, array( $id ) );
	}

	/**
	 * JSON envelope, reduced when it exceeds the payload ceiling.
	 */
	private function encode( string $id, string $event, array $data ): string {
		$envelope = array(
			'id'         => $id,
			'event'      => $event,
			'site'       => home_url( '/' ),
			'created_at' => gmdate( 'c' ),
			'data'       => $data,
		);

		$json = wp_json_encode( $envelope );

		if ( is_string( $json ) && strlen( $json ) <= self::MAX_PAYLOAD ) {
			return $json;
		}

		$mini = array();

		foreach ( $data as $key => $value ) {
			if ( count( $mini ) >= 20 ) {
				break;
			}

			if ( is_bool( $value ) || is_int( $value ) || is_float( $value ) || is_string( $value ) ) {
				$mini[ $key ] = is_string( $value ) ? mb_substr( $value, 0, 200 ) : $value;
			} elseif ( null === $value ) {
				$mini[ $key ] = null;
			}
		}

		$mini['truncated'] = true;
		$envelope['data']  = $mini;

		$json = wp_json_encode( $envelope );

		return is_string( $json ) ? $json : '{"data":{"truncated":true}}';
	}
}
