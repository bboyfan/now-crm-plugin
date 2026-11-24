<?php
/**
 * AWS SNS message verification for SES bounce/complaint notifications.
 *
 * Only the documented SNS certificate hosts are fetched. Test environments can
 * override the verdict through a filter; production never sets it.
 *
 * @package NowCRM
 */

namespace NowCRM\Ses;

use NowCRM\Support\Settings;

defined( 'ABSPATH' ) || exit;

final class Verifier {

	/**
	 * Verify an SNS notification or subscription message.
	 *
	 * @param array<string, mixed> $payload Decoded SNS JSON.
	 */
	public function verify( array $payload ): bool {
		$pre = apply_filters( 'nowcrm_sns_signature_valid', null, $payload );

		if ( is_bool( $pre ) ) {
			return $pre;
		}

		$type       = (string) ( $payload['Type'] ?? '' );
		$message    = (string) ( $payload['Message'] ?? '' );
		$message_id = (string) ( $payload['MessageId'] ?? '' );
		$topic      = (string) ( $payload['TopicArn'] ?? '' );
		$timestamp  = (string) ( $payload['Timestamp'] ?? '' );
		$signature  = (string) ( $payload['Signature'] ?? '' );
		$cert_url   = (string) ( $payload['SigningCertURL'] ?? $payload['SigningCertUrl'] ?? '' );
		$version    = (string) ( $payload['SignatureVersion'] ?? '1' );
		$subject    = (string) ( $payload['Subject'] ?? '' );

		if ( '' === $type || '' === $message_id || '' === $topic || '' === $signature || '' === $cert_url ) {
			return false;
		}

		if ( ! in_array( $type, array( 'Notification', 'SubscriptionConfirmation', 'UnsubscribeConfirmation' ), true ) ) {
			return false;
		}

		if ( ! $this->is_allowed_cert_url( $cert_url ) ) {
			return false;
		}

		$cert = $this->fetch_cert( $cert_url );

		if ( '' === $cert ) {
			return false;
		}

		$canonical = "Message\n{$message}\nMessageId\n{$message_id}\n";

		if ( '' !== $subject ) {
			$canonical .= "Subject\n{$subject}\n";
		}

		$canonical .= "Timestamp\n{$timestamp}\nTopicArn\n{$topic}\nType\n{$type}\n";

		$algorithm = '2' === $version ? OPENSSL_ALGO_SHA256 : OPENSSL_ALGO_SHA1;
		$decoded   = base64_decode( $signature, true );

		if ( false === $decoded ) {
			return false;
		}

		return 1 === openssl_verify( $canonical, $decoded, $cert, $algorithm );
	}

	/**
	 * Topic allowlist from settings plus a filter.
	 */
	public function topic_allowed( string $topic_arn ): bool {
		$configured = array_filter(
			array_map(
				'trim',
				explode( ',', Settings::string( 'ses_topic_arn' ) )
			)
		);

		$allowed = (array) apply_filters( 'nowcrm_sns_allowed_topics', $configured );

		if ( ! $allowed ) {
			return false;
		}

		return in_array( $topic_arn, $allowed, true );
	}

	/**
	 * Whether a SubscribeURL is an SNS endpoint on an official host.
	 */
	public function is_allowed_subscribe_url( string $url ): bool {
		$parts = wp_parse_url( $url );

		if ( ! is_array( $parts ) || 'https' !== ( $parts['scheme'] ?? '' ) ) {
			return false;
		}

		$host = (string) ( $parts['host'] ?? '' );

		return (bool) preg_match( '/^sns\.[a-z0-9-]+\.amazonaws\.com(\.cn)?$/', $host );
	}

	/**
	 * Only SimpleNotificationService-*.pem from sns.<region>.amazonaws.com.
	 */
	private function is_allowed_cert_url( string $url ): bool {
		if ( ! preg_match( '#^https://sns\.[a-z0-9-]+\.amazonaws\.com(\.cn)?/SimpleNotificationService-[A-Za-z0-9]+\.pem$#', $url ) ) {
			return false;
		}

		$host = (string) wp_parse_url( $url, PHP_URL_HOST );

		// phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- This line only validates the fixed SNS certificate host. It does not load content from a remote server.
		return '' !== $host && str_starts_with( $host, 'sns.' ) && str_ends_with( $host, '.amazonaws.com' );
	}

	/**
	 * Fetch and cache the PEM certificate.
	 */
	private function fetch_cert( string $url ): string {
		$key    = 'nowcrm_sns_cert_' . md5( $url );
		$cached = get_transient( $key );

		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}

		// phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- URL is restricted to the SNS certificate allowlist above.
		$response = wp_safe_remote_get( $url, array( 'timeout' => 10, 'redirection' => 0 ) );

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return '';
		}

		$body = (string) wp_remote_retrieve_body( $response );

		if ( ! str_contains( $body, 'BEGIN CERTIFICATE' ) ) {
			return '';
		}

		set_transient( $key, $body, DAY_IN_SECONDS );

		return $body;
	}
}
