<?php
/**
 * Signed, purpose-scoped, expiring tokens for public consent flows.
 *
 * Stateless HMAC design: the payload carries purpose, contact, email and
 * expiry, and the signature binds all of it to `wp_salt('auth')`. No database
 * row is created, so no schema change is needed. Validation is constant-time
 * via `hash_equals()`.
 *
 * @package NowCRM
 */

namespace NowCRM\Consent;

use WP_Error;

defined( 'ABSPATH' ) || exit;

final class Tokens {

	public const PURPOSE_MANAGE      = 'manage';
	public const PURPOSE_UNSUBSCRIBE = 'unsubscribe';
	public const PURPOSE_CONFIRM     = 'confirm';
	public const PURPOSE_EMAIL_CHANGE = 'email_change';

	public const VERSION = 'n1';

	/**
	 * Every purpose this service may mint.
	 *
	 * @return string[]
	 */
	public static function purposes(): array {
		return array(
			self::PURPOSE_MANAGE,
			self::PURPOSE_UNSUBSCRIBE,
			self::PURPOSE_CONFIRM,
			self::PURPOSE_EMAIL_CHANGE,
		);
	}

	/**
	 * Mint a token.
	 *
	 * @param string               $purpose      One of self::purposes().
	 * @param int                  $contact_id   Contact the token is bound to.
	 * @param string               $email        Address the token is bound to.
	 * @param int                  $ttl_seconds  Lifetime in seconds; 0 expires immediately.
	 * @param array<string, mixed> $extra        Small purpose payload (list IDs, new email).
	 * @return array{token: string, expires_at: int}|WP_Error
	 */
	public static function issue( string $purpose, int $contact_id, string $email, int $ttl_seconds, array $extra = array() ) {
		if ( ! in_array( $purpose, self::purposes(), true ) ) {
			return new WP_Error( 'nowcrm_invalid_token_purpose', __( 'Token 用途不正確。', 'now-crm' ) );
		}

		$email = self::normalize_email( $email );

		if ( $contact_id <= 0 || ! is_email( $email ) ) {
			return new WP_Error( 'nowcrm_invalid_token_subject', __( 'Token 對象不正確。', 'now-crm' ) );
		}

		$now     = time();
		$payload = array(
			'v'   => self::VERSION,
			'p'   => $purpose,
			'cid' => $contact_id,
			'e'   => $email,
			'iat' => $now,
			'exp' => $now + max( 0, $ttl_seconds ),
			'n'   => bin2hex( random_bytes( 16 ) ),
			'x'   => $extra,
		);

		$encoded = self::base64url_encode( (string) wp_json_encode( $payload ) );

		return array(
			'token'      => $encoded . '.' . hash_hmac( 'sha256', $encoded, self::secret() ),
			'expires_at' => $payload['exp'],
		);
	}

	/**
	 * Verify structure, signature and expiry. The caller must still check that
	 * the returned purpose matches the flow it is serving.
	 *
	 * @return array{purpose: string, contact_id: int, email: string, expires_at: int, extra: array<string, mixed>}|null
	 */
	public static function decode( string $raw ): ?array {
		if ( '' === $raw || strlen( $raw ) > 2048 ) {
			return null;
		}

		$parts = explode( '.', $raw, 2 );

		if ( 2 !== count( $parts ) || '' === $parts[0] || '' === $parts[1] ) {
			return null;
		}

		[ $encoded, $signature ] = $parts;

		if ( ! preg_match( '/^[A-Za-z0-9\-_=]+$/', $encoded ) || ! preg_match( '/^[0-9a-f]{64}$/', $signature ) ) {
			return null;
		}

		$expected = hash_hmac( 'sha256', $encoded, self::secret() );

		if ( ! hash_equals( $expected, strtolower( $signature ) ) ) {
			return null;
		}

		$payload = json_decode( self::base64url_decode( $encoded ), true );

		if ( ! is_array( $payload ) || self::VERSION !== ( $payload['v'] ?? '' ) ) {
			return null;
		}

		$purpose    = (string) ( $payload['p'] ?? '' );
		$contact_id = (int) ( $payload['cid'] ?? 0 );
		$email      = self::normalize_email( (string) ( $payload['e'] ?? '' ) );
		$expiry     = (int) ( $payload['exp'] ?? 0 );
		$extra      = isset( $payload['x'] ) && is_array( $payload['x'] ) ? $payload['x'] : array();

		if ( ! in_array( $purpose, self::purposes(), true ) || $contact_id <= 0 || ! is_email( $email ) || $expiry <= 0 ) {
			return null;
		}

		if ( $expiry <= time() ) {
			return null;
		}

		return array(
			'purpose'    => $purpose,
			'contact_id' => $contact_id,
			'email'      => $email,
			'expires_at' => $expiry,
			'extra'      => $extra,
		);
	}

	/**
	 * Verify a token for one exact purpose.
	 *
	 * @return array{purpose: string, contact_id: int, email: string, expires_at: int, extra: array<string, mixed>}|null
	 */
	public static function validate( string $raw, string $purpose ): ?array {
		$decoded = self::decode( $raw );

		if ( null === $decoded || $decoded['purpose'] !== $purpose ) {
			return null;
		}

		return $decoded;
	}

	/**
	 * Public preference-center URL carrying a token.
	 */
	public static function url( string $raw ): string {
		return \NowCRM\Public\PreferencePage::url( $raw );
	}

	/**
	 * Normalize an address the same way contacts do.
	 */
	public static function normalize_email( string $email ): string {
		return mb_strtolower( trim( $email ), 'UTF-8' );
	}

	/**
	 * Signing secret. `wp_salt('auth')` rotates only when the site salts do,
	 * which invalidates outstanding tokens — the safe direction.
	 */
	private static function secret(): string {
		return (string) wp_salt( 'auth' );
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
