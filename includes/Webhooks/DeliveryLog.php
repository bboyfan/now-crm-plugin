<?php
/**
 * Bounded delivery history for outbound webhooks.
 *
 * The log is one non-autoloaded option capped at 200 entries. It stores only
 * metadata, never request bodies, so contact data cannot accumulate in the
 * options table. The body needed for a retry lives in a short-lived transient
 * and is removed after the last attempt.
 *
 * A per-delivery claim option stops two cron requests from sending the same
 * delivery at the same time.
 *
 * @package NowCRM
 */

namespace NowCRM\Webhooks;

defined( 'ABSPATH' ) || exit;

final class DeliveryLog {

	public const OPTION = 'nowcrm_webhook_deliveries';

	public const PAYLOAD_PREFIX = 'nowcrm_webhook_payload_';

	public const CLAIM_PREFIX = 'nowcrm_webhook_claim_';

	public const MAX_ENTRIES = 200;

	public const PAYLOAD_TTL = DAY_IN_SECONDS;

	public const CLAIM_TTL = 300;

	/**
	 * Newest delivery entries first.
	 *
	 * @param int $limit Maximum rows, 0 for every stored row.
	 * @return array<int, array<string, mixed>>
	 */
	public function all( int $limit = 0 ): array {
		$rows = $this->raw();

		if ( $limit > 0 ) {
			$rows = array_slice( $rows, 0, $limit );
		}

		return array_values( array_map( array( $this, 'normalize' ), $rows ) );
	}

	/**
	 * One delivery by ID.
	 *
	 * @return array<string, mixed>|null
	 */
	public function find( string $id ): ?array {
		foreach ( $this->raw() as $row ) {
			if ( is_array( $row ) && (string) ( $row['id'] ?? '' ) === $id ) {
				return $this->normalize( $row );
			}
		}

		return null;
	}

	/**
	 * Append one delivery and trim the log.
	 *
	 * @param array<string, mixed> $entry Metadata.
	 * @return array<string, mixed> Stored entry.
	 */
	public function add( array $entry ): array {
		$now = gmdate( 'Y-m-d H:i:s' );

		$entry = array_merge(
			array(
				'webhook_id'      => '',
				'event'           => '',
				'url'             => '',
				'status'          => 'pending',
				'attempts'        => 0,
				'response_code'   => null,
				'error'           => '',
				'created_at'      => $now,
				'updated_at'      => $now,
				'next_attempt_at' => null,
			),
			$entry
		);

		$entry['id'] = isset( $entry['id'] ) && is_string( $entry['id'] ) && '' !== $entry['id']
			? $entry['id']
			: wp_generate_uuid4();

		$entry['attempts']      = max( 0, (int) $entry['attempts'] );
		$entry['response_code'] = null === $entry['response_code'] ? null : (int) $entry['response_code'];
		$entry['error']         = (string) $entry['error'];
		$entry['next_attempt_at'] = is_string( $entry['next_attempt_at'] ) ? $entry['next_attempt_at'] : null;

		$rows = $this->raw();
		array_unshift( $rows, $entry );

		if ( count( $rows ) > self::MAX_ENTRIES ) {
			foreach ( array_slice( $rows, self::MAX_ENTRIES ) as $dropped ) {
				$this->forget( (string) ( $dropped['id'] ?? '' ) );
			}

			$rows = array_slice( $rows, 0, self::MAX_ENTRIES );
		}

		$this->store( $rows );

		return $this->normalize( $entry );
	}

	/**
	 * Patch one delivery.
	 *
	 * @param array<string, mixed> $patch Fields to replace.
	 * @return array<string, mixed>|null
	 */
	public function update( string $id, array $patch ): ?array {
		$rows = $this->raw();

		foreach ( $rows as $index => $row ) {
			if ( ! is_array( $row ) || (string) ( $row['id'] ?? '' ) !== $id ) {
				continue;
			}

			$merged = array_merge( $row, $patch, array( 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ) );

			if ( array_key_exists( 'next_attempt_at', $patch ) && null === $patch['next_attempt_at'] ) {
				$merged['next_attempt_at'] = null;
			}

			$rows[ $index ] = $merged;
			$this->store( $rows );

			return $this->normalize( $merged );
		}

		return null;
	}

	/**
	 * Store the signed request body until the last attempt.
	 */
	public function save_payload( string $id, string $body ): bool {
		return set_transient( self::PAYLOAD_PREFIX . $id, $body, self::PAYLOAD_TTL );
	}

	/**
	 * Body for a retry, or null when it expired.
	 */
	public function payload( string $id ): ?string {
		$body = get_transient( self::PAYLOAD_PREFIX . $id );

		return is_string( $body ) ? $body : null;
	}

	/**
	 * Remove a stored request body.
	 */
	public function delete_payload( string $id ): void {
		delete_transient( self::PAYLOAD_PREFIX . $id );
	}

	/**
	 * Atomically claim a delivery for one worker.
	 */
	public function claim( string $id ): bool {
		$key      = self::CLAIM_PREFIX . $id;
		$existing = get_option( $key, null );

		if ( null !== $existing ) {
			if ( is_numeric( $existing ) && ( time() - (int) $existing ) < self::CLAIM_TTL ) {
				return false;
			}

			delete_option( $key );
		}

		return (bool) add_option( $key, time(), '', false );
	}

	/**
	 * Release a claim after an attempt.
	 */
	public function release( string $id ): void {
		delete_option( self::CLAIM_PREFIX . $id );
	}

	/**
	 * Stored rows as a plain list.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function raw(): array {
		$rows = get_option( self::OPTION, array() );

		if ( ! is_array( $rows ) ) {
			return array();
		}

		return array_values( array_filter( $rows, 'is_array' ) );
	}

	/**
	 * Persist rows without autoloading the option.
	 *
	 * @param array<int, array<string, mixed>> $rows Rows.
	 */
	private function store( array $rows ): void {
		update_option( self::OPTION, array_values( $rows ), false );
	}

	/**
	 * Drop transient and claim state for an entry leaving the log.
	 */
	private function forget( string $id ): void {
		if ( '' === $id ) {
			return;
		}

		$this->delete_payload( $id );
		delete_option( self::CLAIM_PREFIX . $id );
	}

	/**
	 * Normalize one stored row.
	 *
	 * @param array<string, mixed> $row Row.
	 * @return array<string, mixed>
	 */
	private function normalize( array $row ): array {
		return array(
			'id'              => (string) ( $row['id'] ?? '' ),
			'webhook_id'      => (string) ( $row['webhook_id'] ?? '' ),
			'event'           => (string) ( $row['event'] ?? '' ),
			'url'             => (string) ( $row['url'] ?? '' ),
			'status'          => (string) ( $row['status'] ?? 'pending' ),
			'attempts'        => max( 0, (int) ( $row['attempts'] ?? 0 ) ),
			'response_code'   => isset( $row['response_code'] ) && null !== $row['response_code'] ? (int) $row['response_code'] : null,
			'error'           => (string) ( $row['error'] ?? '' ),
			'created_at'      => (string) ( $row['created_at'] ?? '' ),
			'updated_at'      => (string) ( $row['updated_at'] ?? '' ),
			'next_attempt_at' => isset( $row['next_attempt_at'] ) && is_string( $row['next_attempt_at'] ) ? $row['next_attempt_at'] : null,
		);
	}
}
