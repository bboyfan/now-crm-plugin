<?php
/**
 * Tiny compare-and-set rate limiter for public consent requests.
 *
 * One non-autoloaded option per bucket holds the Unix time when the bucket
 * opens again. `add_option()` is the atomic claim: when two requests race,
 * only one creates the row.
 *
 * @package NowCRM
 */

namespace NowCRM\Consent;

defined( 'ABSPATH' ) || exit;

final class Throttle {

	public const PREFIX = 'nowcrm_consent_throttle_';

	/**
	 * Claim a bucket. Returns false while the bucket is cooling down.
	 */
	public static function allow( string $bucket, int $window_seconds ): bool {
		$key = self::key( $bucket );
		$now = time();
		$window = max( 1, $window_seconds );

		$opens_at = (int) get_option( $key, 0 );

		if ( $opens_at > $now ) {
			return false;
		}

		$next = $now + $window;

		if ( add_option( $key, $next, '', false ) ) {
			return true;
		}

		// The row exists: re-read and claim only a stale bucket.
		$opens_at = (int) get_option( $key, 0 );

		if ( $opens_at > $now ) {
			return false;
		}

		return (bool) update_option( $key, $next, false );
	}

	/**
	 * Give a bucket back after the guarded send failed, so the contact can
	 * retry immediately.
	 */
	public static function release( string $bucket ): void {
		delete_option( self::key( $bucket ) );
	}

	/**
	 * Option name for a bucket. Exposed so tests can time-travel.
	 */
	public static function key( string $bucket ): string {
		return self::PREFIX . md5( $bucket );
	}
}
