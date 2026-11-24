<?php
/**
 * HMAC-SHA256 request signing for outbound webhook deliveries.
 *
 * The signed message is `{timestamp}.{raw body}`. Receivers must reject a
 * timestamp outside the replay window and compare the signature in constant
 * time. Header names are NOW CRM specific so they never collide with another
 * webhook producer.
 *
 * @package NowCRM
 */

namespace NowCRM\Webhooks;

defined( 'ABSPATH' ) || exit;

final class Signer {

	public const HEADER_SIGNATURE = 'X-NowCRM-Signature';
	public const HEADER_TIMESTAMP = 'X-NowCRM-Timestamp';
	public const HEADER_EVENT     = 'X-NowCRM-Event';
	public const HEADER_DELIVERY  = 'X-NowCRM-Delivery';

	/**
	 * Default replay window in seconds.
	 */
	public const WINDOW = 300;

	/**
	 * Signature headers for one body.
	 *
	 * @param string   $secret    Webhook signing secret.
	 * @param string   $body      Raw JSON body.
	 * @param int|null $timestamp Unix timestamp. Defaults to now.
	 * @return array<string, string>
	 */
	public static function sign( string $secret, string $body, ?int $timestamp = null ): array {
		$timestamp = (string) ( $timestamp ?? time() );

		return array(
			self::HEADER_TIMESTAMP => $timestamp,
			self::HEADER_SIGNATURE => 'sha256=' . hash_hmac( 'sha256', $timestamp . '.' . $body, $secret ),
		);
	}

	/**
	 * Verify a signature and its timestamp.
	 *
	 * @param string   $secret    Shared secret.
	 * @param string   $body      Raw request body.
	 * @param string   $signature Header value, `sha256=<hex>`.
	 * @param string   $timestamp Header timestamp.
	 * @param int|null $now       Current time, injectable for tests.
	 */
	public static function verify( string $secret, string $body, string $signature, string $timestamp, ?int $now = null ): bool {
		if ( '' === $secret || ! ctype_digit( $timestamp ) ) {
			return false;
		}

		$timestamp = (int) $timestamp;
		$now       = $now ?? time();

		if ( abs( $now - $timestamp ) > self::window() ) {
			return false;
		}

		if ( ! str_starts_with( $signature, 'sha256=' ) ) {
			return false;
		}

		$provided = substr( $signature, 7 );

		if ( 64 !== strlen( $provided ) || ! ctype_xdigit( $provided ) ) {
			return false;
		}

		$expected = hash_hmac( 'sha256', $timestamp . '.' . $body, $secret );

		return hash_equals( $expected, strtolower( $provided ) );
	}

	/**
	 * Replay window in seconds. Filters are clamped to a usable range.
	 */
	public static function window(): int {
		$window = (int) apply_filters( 'nowcrm_webhook_replay_window', self::WINDOW );

		return max( 30, min( 3600, $window ) );
	}
}
