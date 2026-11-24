<?php
/**
 * Unsubscribe token lifecycle: CSPRNG token, SHA-256 at rest, expiry, single use.
 *
 * @package NowCRM
 */

namespace NowCRM\Unsubscribe;

use NowCRM\Support\Schema;

defined( 'ABSPATH' ) || exit;

final class Tokens {

	public const PURPOSE = 'unsubscribe';

	/**
	 * Issue a token.
	 *
	 * @param int      $contact_id   Contact.
	 * @param int|null $campaign_id  Campaign that triggered the mail.
	 * @param int|null $job_id       Email job.
	 * @param int      $days         Lifetime in days.
	 * @return array{token: string, url: string, expires_at: string, id: int}
	 */
	public function issue( int $contact_id, ?int $campaign_id = null, ?int $job_id = null, int $days = 90 ): array {
		global $wpdb;

		$raw    = bin2hex( random_bytes( 32 ) );
		$hash   = hash( 'sha256', $raw );
		$now    = gmdate( 'Y-m-d H:i:s' );
		$expiry = gmdate( 'Y-m-d H:i:s', time() + max( 1, $days ) * DAY_IN_SECONDS );
		$table  = Schema::table( 'unsubscribe_tokens' );

		$wpdb->insert(
			$table,
			array(
				'token_hash'  => $hash,
				'contact_id'  => $contact_id,
				'campaign_id' => $campaign_id ?: null,
				'job_id'      => $job_id ?: null,
				'purpose'     => self::PURPOSE,
				'expires_at'  => $expiry,
				'created_at'  => $now,
			),
			array( '%s', '%d', '%d', '%d', '%s', '%s', '%s' )
		);

		return array(
			'id'         => (int) $wpdb->insert_id,
			'token'      => $raw,
			'url'        => $this->url( $raw ),
			'expires_at' => $expiry,
		);
	}

	/**
	 * Find an unexpired token by its raw value.
	 *
	 * @return array<string, mixed>|null
	 */
	public function find( string $raw_token ): ?array {
		global $wpdb;

		if ( '' === $raw_token ) {
			return null;
		}

		$table = Schema::table( 'unsubscribe_tokens' );

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE token_hash = %s AND expires_at >= %s",
				hash( 'sha256', $raw_token ),
				gmdate( 'Y-m-d H:i:s' )
			),
			ARRAY_A
		);

		if ( ! $row ) {
			return null;
		}

		$row['id']          = (int) $row['id'];
		$row['contact_id']  = (int) $row['contact_id'];
		$row['campaign_id'] = (int) $row['campaign_id'];
		$row['job_id']      = (int) $row['job_id'];

		return $row;
	}

	/**
	 * Mark a token used. Only the first caller wins.
	 */
	public function consume( int $id ): bool {
		global $wpdb;

		$table = Schema::table( 'unsubscribe_tokens' );

		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET used_at = %s WHERE id = %d AND used_at IS NULL",
				gmdate( 'Y-m-d H:i:s' ),
				$id
			)
		);

		return 1 === (int) $updated;
	}

	/**
	 * Public unsubscribe URL (query-string form works without rewrite rules).
	 */
	public function url( string $raw_token ): string {
		return add_query_arg(
			array(
				'nowcrm_unsubscribe' => '1',
				'token'              => rawurlencode( $raw_token ),
			),
			home_url( '/' )
		);
	}

	/**
	 * Invalidate outstanding tokens of a contact (used after complaint).
	 */
	public function revoke_for_contact( int $contact_id ): void {
		global $wpdb;

		$table = Schema::table( 'unsubscribe_tokens' );

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET used_at = %s WHERE contact_id = %d AND used_at IS NULL",
				gmdate( 'Y-m-d H:i:s' ),
				$contact_id
			)
		);
	}
}
