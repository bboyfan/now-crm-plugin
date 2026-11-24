<?php
/**
 * Owner-scoped temporary storage for CSV import files.
 *
 * An upload is written outside the site document root under a random token.
 * The token, never a path, is the only handle the admin screens pass around.
 * Every read checks the owner and the expiry, so one user can never continue
 * another user's import, even with a leaked token.
 *
 * The stored JSON also holds the mapping and the progress, so an import run
 * survives across admin requests without a settings write.
 *
 * @package NowCRM
 */

namespace NowCRM\Contacts;

use WP_Error;

defined( 'ABSPATH' ) || exit;

final class CsvStorage {

	/**
	 * Seconds an unused upload stays on disk.
	 */
	public const TTL = 2 * HOUR_IN_SECONDS;

	/**
	 * Token shape: 48 lowercase hex characters from random_bytes(24).
	 */
	public const TOKEN_PATTERN = '/^[a-f0-9]{48}$/';

	/**
	 * File names created below the storage directory.
	 */
	private const PROTECT_FILES = array(
		'.htaccess' => "Require all denied\nDeny from all\n",
		'index.php' => "<?php\n// Silence is golden.\n",
	);

	/**
	 * Storage directory, filterable for hosts with a private path.
	 */
	public static function dir(): string {
		$default = rtrim( sys_get_temp_dir(), '/\\' ) . '/nowcrm-csv';

		return untrailingslashit( (string) apply_filters( 'nowcrm_csv_storage_dir', $default ) );
	}

	/**
	 * Normalize a submitted token, or return an empty string.
	 */
	public static function clean_token( string $token ): string {
		$token = strtolower( trim( $token ) );

		return preg_match( self::TOKEN_PATTERN, $token ) ? $token : '';
	}

	/**
	 * Store file contents and initial metadata.
	 *
	 * @param string               $contents Raw file bytes.
	 * @param array<string, mixed> $meta     State fields below the storage keys.
	 * @return array{token: string, path: string}|WP_Error
	 */
	public static function create( string $contents, array $meta, int $user_id ) {
		if ( $user_id <= 0 ) {
			return new WP_Error( 'nowcrm_csv_no_owner', __( '找不到目前的使用者。', 'now-crm' ) );
		}

		if ( ! self::ensure_dir() ) {
			return new WP_Error( 'nowcrm_csv_storage', __( '無法建立暫存目錄。', 'now-crm' ) );
		}

		$token = bin2hex( random_bytes( 24 ) );
		$path  = self::path( $token );
		$now   = time();

		$state = array_merge(
			array(
				'version'    => 1,
				'token'      => $token,
				'user_id'    => $user_id,
				'created_at' => $now,
				'expires_at' => $now + self::TTL,
			),
			$meta
		);

		if ( false === file_put_contents( $path, $contents, LOCK_EX ) ) {
			return new WP_Error( 'nowcrm_csv_storage', __( '無法寫入暫存檔案。', 'now-crm' ) );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions -- the private store needs owner-only permissions; WP_Filesystem has no chmod equivalent.
		@chmod( $path, 0600 );

		if ( ! self::write( $token, $state ) ) {
			self::delete( $token );

			return new WP_Error( 'nowcrm_csv_storage', __( '無法寫入暫存狀態。', 'now-crm' ) );
		}

		return array(
			'token' => $token,
			'path'  => $path,
		);
	}

	/**
	 * CSV path for a token. The caller must already own the token.
	 */
	public static function path( string $token ): string {
		return self::dir() . '/' . $token . '.csv';
	}

	/**
	 * Read a state for its owner. Expired or unknown runs return null, and an
	 * expired run is removed.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function read( string $token, int $user_id ): ?array {
		$token = self::clean_token( $token );

		if ( '' === $token || $user_id <= 0 ) {
			return null;
		}

		$state_path = self::state_path( $token );

		if ( ! is_readable( $state_path ) ) {
			return null;
		}

		$raw = file_get_contents( $state_path );

		if ( ! is_string( $raw ) || '' === $raw ) {
			self::delete( $token );

			return null;
		}

		$state = json_decode( $raw, true );

		if ( ! is_array( $state ) ) {
			self::delete( $token );

			return null;
		}

		if ( (int) ( $state['user_id'] ?? 0 ) !== $user_id ) {
			return null;
		}

		if ( (int) ( $state['expires_at'] ?? 0 ) <= time() ) {
			self::delete( $token );

			return null;
		}

		if ( ! is_readable( self::path( $token ) ) ) {
			self::delete( $token );

			return null;
		}

		$state['token'] = $token;

		return $state;
	}

	/**
	 * Replace a state. The caller must already own the token.
	 *
	 * @param array<string, mixed> $state Full state.
	 */
	public static function write( string $token, array $state ): bool {
		$token = self::clean_token( $token );

		if ( '' === $token ) {
			return false;
		}

		if ( ! is_dir( self::dir() ) && ! self::ensure_dir() ) {
			return false;
		}

		unset( $state['token'] );

		$json = wp_json_encode( $state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

		if ( ! is_string( $json ) ) {
			return false;
		}

		$target = self::state_path( $token );
		$temp   = $target . '.' . bin2hex( random_bytes( 6 ) ) . '.tmp';

		if ( false === file_put_contents( $temp, $json, LOCK_EX ) ) {
			return false;
		}

		// phpcs:disable WordPress.WP.AlternativeFunctions -- an atomic temp-file rename is required for the private store; WP_Filesystem has no chmod equivalent and cannot guarantee an atomic replace.
		@chmod( $temp, 0600 );

		if ( ! @rename( $temp, $target ) ) {
			wp_delete_file( $temp );

			return false;
		}
		// phpcs:enable

		return true;
	}

	/**
	 * Delete the file and the state.
	 */
	public static function delete( string $token ): void {
		$token = self::clean_token( $token );

		if ( '' === $token ) {
			return;
		}

		wp_delete_file( self::state_path( $token ) );
		wp_delete_file( self::path( $token ) );
	}

	/**
	 * Remove expired runs and stale orphan files. Bounded to a fixed number of
	 * directory entries per call, so a purge never scans an unbounded folder.
	 */
	public static function purge_expired(): void {
		$dir = self::dir();

		if ( ! is_dir( $dir ) ) {
			return;
		}

		$now    = time();
		$states = glob( $dir . '/*.json' );
		$scanned = 0;

		foreach ( (array) $states as $file ) {
			if ( ++$scanned > 100 ) {
				break;
			}

			$state = json_decode( (string) file_get_contents( $file ), true );
			$token = basename( $file, '.json' );

			if ( ! is_array( $state ) || (int) ( $state['expires_at'] ?? 0 ) <= $now ) {
				self::delete( $token );
			}
		}

		$scanned = 0;

		foreach ( (array) glob( $dir . '/*.csv' ) as $file ) {
			if ( ++$scanned > 100 ) {
				break;
			}

			$modified = (int) filemtime( $file );

			if ( $modified > 0 && $modified + ( self::TTL * 2 ) < $now ) {
				wp_delete_file( $file );
			}
		}
	}

	/**
	 * Create the directory with owner-only permissions and deny files.
	 */
	private static function ensure_dir(): bool {
		// phpcs:disable WordPress.WP.AlternativeFunctions -- the private store needs an owner-only directory mode and a writable check; WP_Filesystem has no chmod equivalent.
		$dir = self::dir();

		if ( is_dir( $dir ) ) {
			return is_writable( $dir );
		}

		if ( ! wp_mkdir_p( $dir ) ) {
			return false;
		}

		@chmod( $dir, 0700 );

		foreach ( self::PROTECT_FILES as $name => $contents ) {
			$file = $dir . '/' . $name;

			if ( ! file_exists( $file ) ) {
				@file_put_contents( $file, $contents, LOCK_EX );
			}
		}

		return is_writable( $dir );
		// phpcs:enable
	}

	/**
	 * State JSON path for a token.
	 */
	private static function state_path( string $token ): string {
		return self::dir() . '/' . $token . '.json';
	}
}
