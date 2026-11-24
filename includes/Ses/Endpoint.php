<?php
/**
 * SNS webhook endpoint. No unauthenticated POST is accepted: topic allowlist
 * and SNS signature verification both run before any data is applied.
 *
 * @package NowCRM
 */

namespace NowCRM\Ses;

defined( 'ABSPATH' ) || exit;

final class Endpoint {

	private Verifier $verifier;
	private Processor $processor;

	public function __construct() {
		$this->verifier  = new Verifier();
		$this->processor = new Processor();
	}

	/**
	 * Register the route.
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	/**
	 * REST route.
	 */
	public function routes(): void {
		register_rest_route(
			'nowcrm/v1',
			'/ses/sns',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Handle one SNS delivery.
	 *
	 * @return \WP_REST_Response
	 */
	public function handle( \WP_REST_Request $request ) {
		$payload = json_decode( (string) $request->get_body(), true );

		if ( ! is_array( $payload ) ) {
			return new \WP_REST_Response( array( 'ok' => false, 'reason' => 'invalid_json' ), 400 );
		}

		$topic = (string) ( $payload['TopicArn'] ?? '' );

		if ( ! $this->verifier->topic_allowed( $topic ) ) {
			return new \WP_REST_Response( array( 'ok' => false, 'reason' => 'topic_not_allowed' ), 403 );
		}

		if ( ! $this->verifier->verify( $payload ) ) {
			return new \WP_REST_Response( array( 'ok' => false, 'reason' => 'invalid_signature' ), 403 );
		}

		$type = (string) ( $payload['Type'] ?? '' );

		if ( 'SubscriptionConfirmation' === $type ) {
			$url = (string) ( $payload['SubscribeURL'] ?? '' );

			if ( ! $this->verifier->is_allowed_subscribe_url( $url ) ) {
				return new \WP_REST_Response( array( 'ok' => false, 'reason' => 'invalid_subscribe_url' ), 403 );
			}

			if ( ! apply_filters( 'nowcrm_sns_auto_confirm', true, $payload ) ) {
				return new \WP_REST_Response( array( 'ok' => true, 'reason' => 'confirmation_skipped' ), 200 );
			}

			$response = wp_safe_remote_get( $url, array( 'timeout' => 15, 'redirection' => 0 ) );

			if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
				return new \WP_REST_Response( array( 'ok' => false, 'reason' => 'confirmation_failed' ), 502 );
			}

			return new \WP_REST_Response( array( 'ok' => true, 'reason' => 'confirmed' ), 200 );
		}

		if ( 'UnsubscribeConfirmation' === $type ) {
			return new \WP_REST_Response( array( 'ok' => true, 'reason' => 'unsubscribed_topic' ), 200 );
		}

		if ( 'Notification' !== $type ) {
			return new \WP_REST_Response( array( 'ok' => false, 'reason' => 'unknown_type' ), 400 );
		}

		$result = $this->processor->process( $payload );

		return new \WP_REST_Response(
			array(
				'ok'     => $result['ok'],
				'reason' => $result['reason'],
			),
			$result['ok'] ? 200 : 500
		);
	}
}
