<?php
/**
 * SES event application: dedupe, provider delivery results, hard bounce and
 * complaint suppression, transient bounces without permanent blocking.
 *
 * Only a matched NOW job allows a bounce or complaint to change contact or
 * queue state. Matching uses the signed SNS mail tag, then the identical
 * original header, a per-attempt correlation token and the exact recipient.
 *
 * @package NowCRM
 */

namespace NowCRM\Ses;

use NowCRM\Contacts\ContactRepository;
use NowCRM\Contacts\Status;
use NowCRM\Email\EmailJobRepository;
use NowCRM\Email\SuppressionRepository;
use NowCRM\Email\Tracking;
use NowCRM\Support\Schema;

defined( 'ABSPATH' ) || exit;

final class Processor {

	/**
	 * Canonical correlation shape: `<job id>-<opaque token>`.
	 *
	 * SES tag values allow only letters, digits, hyphens and underscores, so
	 * the separator is a hyphen and the token never contains a period.
	 */
	private const CORRELATION_PATTERN = '/^([1-9][0-9]*)-[A-Za-z0-9_-]{16,64}$/D';

	private const HANDOFF_STATUSES = array(
		EmailJobRepository::STATUS_ACCEPTED,
		EmailJobRepository::STATUS_SENDING,
		EmailJobRepository::STATUS_UNKNOWN,
	);

	/**
	 * Process a verified SNS notification.
	 *
	 * @param array<string, mixed> $sns Decoded SNS envelope.
	 * @return array{ok: bool, reason: string, event_id: int}
	 */
	public function process( array $sns ): array {
		$message = json_decode( (string) ( $sns['Message'] ?? '' ), true );

		if ( ! is_array( $message ) ) {
			return array( 'ok' => false, 'reason' => 'invalid_message', 'event_id' => 0 );
		}

		$type           = (string) ( $message['notificationType'] ?? $message['eventType'] ?? '' );
		$ses_message_id = (string) ( $message['mail']['messageId'] ?? '' );
		$recipients     = $this->recipients( $message, $type );
		$event_key      = hash( 'sha256', $type . '|' . $ses_message_id . '|' . implode( ',', $recipients ) . '|' . (string) ( $sns['Timestamp'] ?? '' ) );

		$event_id = $this->store( $sns, $type, $ses_message_id, $recipients, $event_key );

		if ( $event_id < 0 ) {
			return array( 'ok' => false, 'reason' => 'store_failed', 'event_id' => 0 );
		}

		if ( 0 === $event_id ) {
			$existing = $this->find_event( $event_key, (string) ( $sns['MessageId'] ?? '' ) );

			if ( ! $existing ) {
				// INSERT IGNORE returned no row and no duplicate exists: the
				// write failed. Never acknowledge it as a processed event.
				return array( 'ok' => false, 'reason' => 'store_failed', 'event_id' => 0 );
			}

			if ( (int) $existing['processed'] === 1 ) {
				return array( 'ok' => true, 'reason' => 'duplicate', 'event_id' => 0 );
			}

			// A previously failed event is safe to rerun. Every side effect
			// below is idempotent and guarded by the current job attempt.
			$event_id = (int) $existing['id'];
		}

		$lock = $this->lock_event( $event_id );

		if ( null === $lock ) {
			return array( 'ok' => false, 'reason' => 'event_locked', 'event_id' => $event_id );
		}

		try {
			// Another worker may have completed this event between the insert
			// check and the lock acquisition.
			$current = $this->find_event_by_id( $event_id );

			if ( ! $current ) {
				return array( 'ok' => false, 'reason' => 'store_failed', 'event_id' => $event_id );
			}

			if ( (int) $current['processed'] === 1 ) {
				return array( 'ok' => true, 'reason' => 'duplicate', 'event_id' => 0 );
			}

			$result = $this->apply( $message, $type, $ses_message_id, $recipients );

			if ( ! $this->mark_processed( $event_id, $result['classification'], $result['matched'] ) ) {
				return array( 'ok' => false, 'reason' => 'persist_failed', 'event_id' => $event_id );
			}

			return array( 'ok' => true, 'reason' => $result['reason'], 'event_id' => $event_id );
		} catch ( \Throwable $error ) {
			// The stored reason stays generic: it feeds health metrics and must
			// never echo a payload or provider error text.
			$this->mark_error( $event_id, 'processing_failed' );

			return array( 'ok' => false, 'reason' => 'processing_failed', 'event_id' => $event_id );
		} finally {
			$this->unlock_event( $lock );
		}
	}

	/**
	 * Apply one event and classify it for diagnostics.
	 *
	 * @param array<string, mixed> $message       SES message.
	 * @param string[]             $recipients    Addresses.
	 * @return array{reason: string, classification: string, matched: int}
	 */
	private function apply( array $message, string $type, string $ses_message_id, array $recipients ): array {
		if ( ! in_array( $type, array( 'Delivery', 'Bounce', 'Complaint' ), true ) ) {
			return array(
				'reason'         => strtolower( $type ) ?: 'ignored',
				'classification' => 'ignored',
				'matched'        => 0,
			);
		}

		$contacts     = new ContactRepository();
		$suppressions = new SuppressionRepository();
		$jobs         = new EmailJobRepository();
		$tracking     = new Tracking();
		$correlation  = $this->correlation_value( $message );
		$matched      = 0;

		if ( 'Delivery' === $type ) {
			foreach ( $recipients as $email ) {
				$job = $this->resolve_job( $jobs, $email, $correlation );

				if ( ! $job ) {
					continue;
				}

				++$matched;
				$this->record_outcome( $jobs, $job, EmailJobRepository::DELIVERY_DELIVERED );
			}

			return $this->classification( 'delivery', $matched );
		}

		if ( 'Complaint' === $type ) {
			$detail = $this->complaint_detail( $message );

			foreach ( $recipients as $email ) {
				$job = $this->resolve_job( $jobs, $email, $correlation );

				if ( ! $job ) {
					continue;
				}

				++$matched;
				$contact = $contacts->find_by_email( $email );

				if ( ! $this->apply_outcome( $jobs, $job, EmailJobRepository::DELIVERY_COMPLAINED, $detail ) ) {
					continue;
				}

				$this->record_tracking( $tracking, $email, 'complaint', $ses_message_id, $contact, $job );

				if ( $contact && Status::COMPLAINED !== (string) $contact['status'] ) {
					if ( is_wp_error( $contacts->set_status( (int) $contact['id'], Status::COMPLAINED, 'ses-complaint', 'SES complaint notification' ) ) ) {
						throw new \RuntimeException( 'contact_status_write_failed' );
					}
				}

				if ( ! $suppressions->add( $email, SuppressionRepository::REASON_COMPLAINED, 'ses', $contact ? (int) $contact['id'] : null ) ) {
					throw new \RuntimeException( 'suppression_write_failed' );
				}

				$jobs->suppress_email( $email, 'complained' );
			}

			return $this->classification( 'complaint', $matched );
		}

		$bounce_type = (string) ( $message['bounce']['bounceType'] ?? '' );
		$permanent   = 'Permanent' === $bounce_type;

		if ( ! $permanent ) {
			// Transient and Undetermined bounces are recorded but must not
			// permanently block an address.
			foreach ( $recipients as $email ) {
				$job = $this->resolve_job( $jobs, $email, $correlation );

				if ( ! $job ) {
					continue;
				}

				++$matched;
				$contact = $contacts->find_by_email( $email );

				if ( ! $this->apply_outcome( $jobs, $job, EmailJobRepository::DELIVERY_DEFERRED, $this->bounce_detail( $message, $email ) ) ) {
					continue;
				}

				$this->record_tracking( $tracking, $email, 'bounce', $ses_message_id, $contact, $job );
			}

			return $this->classification( 'bounce_transient', $matched );
		}

		foreach ( $recipients as $email ) {
			$job = $this->resolve_job( $jobs, $email, $correlation );

			if ( ! $job ) {
				continue;
			}

			++$matched;
			$contact = $contacts->find_by_email( $email );

			if ( ! $this->apply_outcome( $jobs, $job, EmailJobRepository::DELIVERY_BOUNCED, $this->bounce_detail( $message, $email ) ) ) {
				continue;
			}

			$this->record_tracking( $tracking, $email, 'bounce', $ses_message_id, $contact, $job );

			if ( $contact && ! in_array( (string) $contact['status'], array( Status::BOUNCED, Status::COMPLAINED ), true ) ) {
				$note = 'SES permanent bounce';

				if ( ! empty( $message['bounce']['bouncedRecipients'][0]['diagnosticCode'] ) ) {
					$note .= ': ' . (string) $message['bounce']['bouncedRecipients'][0]['diagnosticCode'];
				}

				if ( is_wp_error( $contacts->set_status( (int) $contact['id'], Status::BOUNCED, 'ses-bounce', $note ) ) ) {
					throw new \RuntimeException( 'contact_status_write_failed' );
				}
			}

			// A complaint outranks a bounce; never rewrite that reason.
			$existing = $suppressions->find( $email );

			if ( ! $existing || SuppressionRepository::REASON_COMPLAINED !== (string) $existing['reason'] ) {
				if ( ! $suppressions->add( $email, SuppressionRepository::REASON_BOUNCED, 'ses', $contact ? (int) $contact['id'] : null ) ) {
					throw new \RuntimeException( 'suppression_write_failed' );
				}
			}

			$jobs->suppress_email( $email, 'bounced' );
		}

		return $this->classification( 'bounce_permanent', $matched );
	}

	/**
	 * Classification plus matched recipient count for one envelope.
	 *
	 * @return array{reason: string, classification: string, matched: int}
	 */
	private function classification( string $reason, int $matched ): array {
		return array(
			'reason'         => $reason,
			'classification' => $matched > 0 ? 'matched' : 'unmatched',
			'matched'        => $matched,
		);
	}

	/**
	 * Resolve the exact queue job behind a provider event.
	 *
	 * The event must carry the per-attempt correlation token: a signed tag, or
	 * the identical original header. A numeric legacy job ID is not proof.
	 *
	 * @param string[] $recipients Recipient addresses.
	 */
	private function resolve_job( EmailJobRepository $jobs, string $email, ?string $correlation ): ?array {
		if ( null === $correlation || ! preg_match( self::CORRELATION_PATTERN, $correlation, $matches ) ) {
			return null;
		}

		$job = $jobs->get( (int) $matches[1] );

		if ( ! $job ) {
			return null;
		}

		if (
			'' === (string) $job['ses_correlation']
			|| ! hash_equals( (string) $job['ses_correlation'], $correlation )
			|| 0 !== strcasecmp( (string) $job['recipient'], $email )
			|| ! in_array( (string) $job['status'], self::HANDOFF_STATUSES, true )
		) {
			return null;
		}

		return $job;
	}

	/**
	 * Whether a resolved job still owns the same attempt after a race.
	 */
	private function still_current( EmailJobRepository $jobs, array $job ): bool {
		$fresh = $jobs->get( (int) $job['id'] );

		return $fresh
			&& in_array( (string) $fresh['status'], self::HANDOFF_STATUSES, true )
			&& (int) $fresh['attempts'] === (int) $job['attempts']
			&& '' !== (string) $fresh['ses_correlation']
			&& hash_equals( (string) $fresh['ses_correlation'], (string) $job['ses_correlation'] );
	}

	/**
	 * Record a delivery result under the resolved attempt token.
	 */
	private function record_outcome( EmailJobRepository $jobs, array $job, string $status, string $error = '' ): bool {
		return $jobs->mark_delivery_event(
			(int) $job['id'],
			$status,
			$error,
			(int) $job['attempts'],
			(string) $job['ses_correlation']
		);
	}

	/**
	 * CAS the outcome, then prove the same attempt still owns the row.
	 *
	 * A false return skips every contact and queue side effect: either the CAS
	 * lost to a retry, a higher-ranked result already exists, or the write
	 * failed. A replay whose exact result is already stored still proceeds so
	 * idempotent side effects can recover from an earlier partial failure.
	 */
	private function apply_outcome( EmailJobRepository $jobs, array $job, string $status, string $error = '' ): bool {
		$recorded = $this->record_outcome( $jobs, $job, $status, $error );

		if ( ! $recorded && ! $this->outcome_persisted( $jobs, $job, $status ) ) {
			return false;
		}

		return $this->still_current( $jobs, $job );
	}

	/**
	 * Whether the exact result is already stored for this attempt.
	 */
	private function outcome_persisted( EmailJobRepository $jobs, array $job, string $status ): bool {
		$fresh = $jobs->get( (int) $job['id'] );

		return $fresh
			&& (string) $fresh['delivery_status'] === $status
			&& (int) $fresh['attempts'] === (int) $job['attempts']
			&& '' !== (string) $fresh['ses_correlation']
			&& hash_equals( (string) $fresh['ses_correlation'], (string) $job['ses_correlation'] );
	}

	/**
	 * Read the canonical correlation value from the signed event.
	 *
	 * The tag wins. A header that disagrees with the tag invalidates both, so
	 * a conflicting valid marker can never override the declared tag.
	 *
	 * @param array<string, mixed> $message SES message.
	 */
	private function correlation_value( array $message ): ?string {
		$tag_values = $this->header_values( (array) ( $message['mail']['tags']['nowcrm_job'] ?? array() ) );
		$tag        = 1 === count( $tag_values ) ? $tag_values[0] : '';
		$header     = '';

		foreach ( (array) ( $message['mail']['headers'] ?? array() ) as $entry ) {
			if ( ! is_array( $entry ) || 'x-nowcrm-job' !== strtolower( trim( (string) ( $entry['name'] ?? '' ) ) ) ) {
				continue;
			}

			$value = trim( (string) ( $entry['value'] ?? '' ) );

			if ( '' === $value ) {
				continue;
			}

			if ( '' !== $header && $header !== $value ) {
				return null;
			}

			$header = $value;
		}

		if ( '' !== $tag ) {
			if ( '' !== $header && $header !== $tag ) {
				return null;
			}

			$canonical = $tag;
		} else {
			$canonical = $header;
		}

		if ( '' === $canonical || ! preg_match( self::CORRELATION_PATTERN, $canonical ) ) {
			return null;
		}

		return $canonical;
	}

	/**
	 * Normalize a tag value that may be a string or a list of strings.
	 *
	 * @param mixed $raw Raw tag value.
	 * @return string[]
	 */
	private function header_values( $raw ): array {
		$values = array();

		foreach ( (array) $raw as $value ) {
			if ( is_array( $value ) ) {
				continue;
			}

			$value = trim( (string) $value );

			if ( '' !== $value ) {
				$values[] = $value;
			}
		}

		return array_values( array_unique( $values ) );
	}

	/**
	 * Associate feedback only for a resolved job.
	 *
	 * @param array<string, mixed>|null $contact Contact row or null.
	 */
	private function record_tracking( Tracking $tracking, string $email, string $type, string $ses_message_id, ?array $contact, array $job ): void {
		global $wpdb;

		$recorded = $tracking->record(
			$type . ':' . $ses_message_id . ':' . md5( $email ), $type,
			'campaign' === ( $job['kind'] ?? '' ) ? (int) $job['campaign_id'] : 0,
			(int) ( $job['id'] ?? 0 ), (int) ( $contact['id'] ?? 0 ), '', '',
			array( 'ses_message_id' => $ses_message_id )
		);

		// INSERT IGNORE returns 0 for a duplicate, which is not a failure. Only
		// a confirmed database error must leave the event unprocessed.
		if ( ! $recorded && '' !== (string) $wpdb->last_error ) {
			throw new \RuntimeException( 'email_event_write_failed' );
		}
	}

	/**
	 * Diagnostic text for one bounced recipient.
	 *
	 * @param array<string, mixed> $message Bounce message.
	 */
	private function bounce_detail( array $message, string $email ): string {
		$bounce = (array) ( $message['bounce'] ?? array() );
		$match  = null;

		foreach ( (array) ( $bounce['bouncedRecipients'] ?? array() ) as $recipient ) {
			if ( is_array( $recipient ) && 0 === strcasecmp( (string) ( $recipient['emailAddress'] ?? '' ), $email ) ) {
				$match = $recipient;
				break;
			}
		}

		if ( null === $match && isset( $bounce['bouncedRecipients'][0] ) && is_array( $bounce['bouncedRecipients'][0] ) ) {
			$match = $bounce['bouncedRecipients'][0];
		}

		$diagnostic = is_array( $match ) ? trim( (string) ( $match['diagnosticCode'] ?? '' ) ) : '';
		$subtype    = is_array( $match ) ? trim( (string) ( $match['bounceSubType'] ?? '' ) ) : '';

		if ( '' === $subtype ) {
			$subtype = trim( (string) ( $bounce['bounceSubType'] ?? '' ) );
		}

		$parts = array_filter(
			array( $diagnostic, $subtype ),
			static fn( string $part ): bool => '' !== $part
		);

		return implode( ' / ', $parts );
	}

	/**
	 * Complaint feedback type for the delivery layer.
	 *
	 * @param array<string, mixed> $message Complaint message.
	 */
	private function complaint_detail( array $message ): string {
		$complaint = (array) ( $message['complaint'] ?? array() );

		return trim( (string) ( $complaint['complaintFeedbackType'] ?? $complaint['complaintSubType'] ?? '' ) );
	}

	/**
	 * Extract addresses per notification type.
	 *
	 * @return string[]
	 */
	private function recipients( array $message, string $type ): array {
		$emails = array();

		if ( 'Delivery' === $type ) {
			foreach ( (array) ( $message['delivery']['recipients'] ?? array() ) as $recipient ) {
				$emails[] = is_array( $recipient ) ? (string) ( $recipient['emailAddress'] ?? '' ) : (string) $recipient;
			}
		}

		if ( 'Bounce' === $type ) {
			foreach ( (array) ( $message['bounce']['bouncedRecipients'] ?? array() ) as $recipient ) {
				$emails[] = (string) ( $recipient['emailAddress'] ?? '' );
			}
		}

		if ( 'Complaint' === $type ) {
			foreach ( (array) ( $message['complaint']['complainedRecipients'] ?? array() ) as $recipient ) {
				$emails[] = (string) ( $recipient['emailAddress'] ?? '' );
			}
		}

		$emails = array_values( array_unique( array_filter( array_map( 'sanitize_email', $emails ) ) ) );

		return array_values( array_filter( $emails, 'is_email' ) );
	}

	/**
	 * Insert the raw envelope once.
	 *
	 * @param string[] $recipients Recipient addresses.
	 * @return int Negative on write failure, 0 for an existing row, else the ID.
	 */
	private function store( array $sns, string $type, string $ses_message_id, array $recipients, string $event_key ): int {
		global $wpdb;

		$table = Schema::table( 'ses_events' );
		$now   = gmdate( 'Y-m-d H:i:s' );

		$inserted = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$table}
				(sns_message_id, event_key, event_type, ses_message_id, email_normalized, payload, received_at)
				VALUES (%s, %s, %s, %s, %s, %s, %s)",
				substr( (string) ( $sns['MessageId'] ?? '' ), 0, 100 ),
				$event_key,
				substr( $type, 0, 30 ),
				substr( $ses_message_id, 0, 191 ),
				substr( (string) ( $recipients[0] ?? '' ), 0, 191 ),
				wp_json_encode( $sns ),
				$now
			)
		);

		if ( false === $inserted ) {
			return -1;
		}

		return $inserted ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Read the stored envelope for a duplicate insert.
	 *
	 * @return array<string, mixed>|null
	 */
	private function find_event( string $event_key, string $sns_message_id ): ?array {
		global $wpdb;

		$table = Schema::table( 'ses_events' );
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, processed, status FROM {$table} WHERE event_key = %s OR sns_message_id = %s ORDER BY id ASC LIMIT 1",
				$event_key,
				substr( $sns_message_id, 0, 100 )
			),
			ARRAY_A
		);

		return $row ?: null;
	}

	/**
	 * Mark a stored event processed with its classification.
	 */
	private function mark_processed( int $event_id, string $classification, int $matched ): bool {
		global $wpdb;

		$updated = $wpdb->update(
			Schema::table( 'ses_events' ),
			array(
				'processed'          => 1,
				'status'             => substr( $classification, 0, 20 ),
				'matched_recipients' => max( 0, $matched ),
				'error'              => null,
			),
			array( 'id' => $event_id ),
			array( '%d', '%s', '%d', '%s' ),
			array( '%d' )
		);

		return false !== $updated && $updated > 0;
	}

	/**
	 * Store a safe processing failure marker.
	 */
	private function mark_error( int $event_id, string $reason ): void {
		global $wpdb;

		$wpdb->update(
			Schema::table( 'ses_events' ),
			array(
				'status' => 'failed',
				'error'  => substr( $reason, 0, 100 ),
			),
			array( 'id' => $event_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Re-read one stored event under the event lock.
	 *
	 * @return array<string, mixed>|null
	 */
	private function find_event_by_id( int $event_id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT id, processed, status FROM %i WHERE id = %d',
				Schema::table( 'ses_events' ),
				$event_id
			),
			ARRAY_A
		);

		return $row ?: null;
	}

	/**
	 * Per-event advisory lock so two deliveries of one event cannot race.
	 */
	private function lock_event( int $event_id ): ?string {
		global $wpdb;

		$name = 'nowcrm-ses-event-' . $event_id;
		$got  = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $name, 5 ) );

		return '1' === (string) $got ? $name : null;
	}

	private function unlock_event( string $name ): void {
		global $wpdb;

		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
	}

	/**
	 * Event counters for health and the admin surface.
	 *
	 * @return array{received: int, matched: int, unmatched: int, failed: int, ignored: int, last_received_at: string}
	 */
	public static function diagnostics(): array {
		global $wpdb;

		$table = Schema::table( 'ses_events' );
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COUNT(*) AS received,
					COALESCE(SUM(CASE WHEN status = %s THEN 1 ELSE 0 END), 0) AS matched,
					COALESCE(SUM(CASE WHEN status = %s THEN 1 ELSE 0 END), 0) AS unmatched,
					COALESCE(SUM(CASE WHEN status = %s THEN 1 ELSE 0 END), 0) AS failed,
					COALESCE(SUM(CASE WHEN status IN ('', 'ignored', 'legacy') THEN 1 ELSE 0 END), 0) AS ignored,
					MAX(received_at) AS last_received_at
				FROM {$table}",
				'matched',
				'unmatched',
				'failed'
			),
			ARRAY_A
		);

		$last = is_array( $row ) ? (string) ( $row['last_received_at'] ?? '' ) : '';

		return array(
			'received'         => is_array( $row ) ? (int) $row['received'] : 0,
			'matched'          => is_array( $row ) ? (int) $row['matched'] : 0,
			'unmatched'        => is_array( $row ) ? (int) $row['unmatched'] : 0,
			'failed'           => is_array( $row ) ? (int) $row['failed'] : 0,
			'ignored'          => is_array( $row ) ? (int) $row['ignored'] : 0,
			'last_received_at' => '' !== $last ? $last : '',
		);
	}
}
