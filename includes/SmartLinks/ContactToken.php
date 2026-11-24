<?php
/**
 * Signed contact context for smart links.
 *
 * The token binds a contact ID, an issue time and an expiry to
 * `wp_salt('auth')`. The payload carries no address and no name, so a short
 * URL never exposes PII. It is domain-separated from consent tokens by a
 * distinct HMAC key, so neither token type can stand in for the other.
 *
 * @package NowCRM
 */

namespace NowCRM\SmartLinks;

defined( 'ABSPATH' ) || exit;

final class ContactToken {

	public const VERSION = 'n1';
	public const PURPOSE = 'smart_link';

	public const DEFAULT_TTL = 7 * DAY_IN_SECONDS;
	public const MAX_TTL     = 7 * DAY_IN_SECONDS;

	/**
	 * Mint a token for one contact.
	 */
	public static function issue( int $contact_id, int $ttl_seconds = self::DEFAULT_TTL ): string {
		if ( $contact_id <= 0 ) {
			return '';
		}

		$now = time();
		$ttl = max( 0, min( $ttl_seconds, self::MAX_TTL ) );

		$payload = array(
			'v'   => self::VERSION,
			'p'   => self::PURPOSE,
			'cid' => $contact_id,
			'iat' => $now,
			'exp' => $now + $ttl,
			'n'   => bin2hex( random_bytes( 16 ) ),
		);

		$encoded = self::base64url_encode( (string) wp_json_encode( $payload ) );

		return $encoded . '.' . hash_hmac( 'sha256', $encoded, self::secret() );
	}

	/**
	 * Verify signature and expiry. Returns the contact ID, or null when the
	 * token is missing, malformed, forged or expired.
	 */
	public static function validate( string $raw ): ?int {
		if ( '' === $raw || strlen( $raw ) > 512 ) {
			return null;
		}

		$parts = explode( '.', $raw, 2 );

		if ( 2 !== count( $parts ) || '' === $parts[0] || '' === $parts[1] ) {
			return null;
		}

		[ $encoded, $signature ] = $parts;

		if ( ! preg_match( '/^[A-Za-z0-9\-_]+$/', $encoded ) || ! preg_match( '/^[0-9a-f]{64}$/', $signature ) ) {
			return null;
		}

		$expected = hash_hmac( 'sha256', $encoded, self::secret() );

		if ( ! hash_equals( $expected, strtolower( $signature ) ) ) {
			return null;
		}

		$payload = json_decode( self::base64url_decode( $encoded ), true );

		if ( ! is_array( $payload ) || self::VERSION !== ( $payload['v'] ?? '' ) || self::PURPOSE !== ( $payload['p'] ?? '' ) ) {
			return null;
		}

		$contact_id = (int) ( $payload['cid'] ?? 0 );
		$issued     = (int) ( $payload['iat'] ?? 0 );
		$expiry     = (int) ( $payload['exp'] ?? 0 );

		if ( $contact_id <= 0 || $issued <= 0 || $expiry <= $issued ) {
			return null;
		}

		if ( $expiry - $issued > self::MAX_TTL || $issued > time() + 300 || $expiry <= time() ) {
			return null;
		}

		return $contact_id;
	}

	/**
	 * Signing key. The leading domain tag keeps this key distinct from the
	 * consent token key built from the same salt.
	 */
	private static function secret(): string {
		return 'nowcrm-smart-link|' . (string) wp_salt( 'auth' );
	}

	private static function base64url_encode( string $bytes ): string {
		return rtrim( strtr( base64_encode( $bytes ), '+/', '-_' ), '=' );
	}

	private static function base64url_decode( string $text ): string {
		$padded = strtr( $text, '-_', '+/' );
		$pad    = strlen( $padded ) % 4;

		if ( $pad ) {
			$padded .= str_repeat( '=', 4 - $pad );
		}

		$decoded = base64_decode( $padded, true );

		return false === $decoded ? '' : $decoded;
	}
}
