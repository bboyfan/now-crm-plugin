<?php
/**
 * FluentCRM migration: batch, cursor, resume, dry-run, idempotent re-run.
 *
 * Rules that are deliberately conservative:
 *  - Suppressed statuses are never loosened by a later import.
 *  - An existing transactional contact is not turned into subscribed.
 *  - Historical WooCommerce gaps are handled by WooReconciler as transactional.
 *
 * @package NowCRM
 */

namespace NowCRM\FluentCRM;

use NowCRM\Contacts\ContactRepository;
use NowCRM\Contacts\Status;
use NowCRM\Support\Schema;
use NowCRM\Terms\TermRepository;

defined( 'ABSPATH' ) || exit;

final class Importer {

	private Adapter $adapter;
	private ContactRepository $contacts;
	private TermRepository $terms;

	/** @var array<string, int> */
	private array $term_cache = array();

	/** @var array<string, array<int, array<string, mixed>>>|null */
	private ?array $remote_terms = null;

	public function __construct() {
		$this->adapter  = new Adapter();
		$this->contacts = new ContactRepository();
		$this->terms    = new TermRepository();
	}

	/**
	 * Import contacts (and optionally campaign history).
	 *
	 * @param array<string, mixed> $options mode, batch, resume, campaigns, limit.
	 * @return array<string, mixed>
	 */
	public function run( array $options = array() ): array {
		$mode      = 'apply' === ( $options['mode'] ?? '' ) ? 'apply' : 'dry_run';
		$batch     = max( 1, min( 1000, (int) ( $options['batch'] ?? 200 ) ) );
		$resume    = ! empty( $options['resume'] );
		$campaigns = ! empty( $options['campaigns'] );
		$limit     = max( 0, (int) ( $options['limit'] ?? 0 ) );

		if ( ! $this->adapter->available() ) {
			return array(
				'ok'     => false,
				'reason' => 'fluentcrm_tables_missing',
			);
		}

		$run = $resume ? $this->resume_run( $mode ) : $this->start_run( $mode );

		$cursor     = (int) $run['cursor_value'];
		$created    = (int) $run['imported'];
		$updated    = (int) $run['updated'];
		$skipped    = (int) $run['skipped'];
		$failed     = (int) $run['failed'];
		$processed  = 0;
		$errors     = array();
		$complete   = false;
		$status_map = $this->adapter->status_counts();
		$before     = $this->contacts->count_by_status();

		while ( true ) {
			$rows = $this->adapter->contacts_batch( $cursor, $batch );

			if ( ! $rows ) {
				$complete = true;
				break;
			}

			$remote_ids = array_column( $rows, 'remote_id' );
			$relations  = $this->adapter->contact_terms( $remote_ids );

			foreach ( $rows as $row ) {
				$cursor = (int) $row['remote_id'];
				++$processed;

				$email = $this->contacts->normalize_email( (string) $row['email'] );

				if ( '' === $email || ! is_email( $email ) ) {
					++$skipped;
					$errors['invalid_email'] = ( $errors['invalid_email'] ?? 0 ) + 1;
					continue;
				}

				$incoming = $this->map_status( (string) $row['status'] );
				$existing = $this->contacts->find_by_email( $email );

				if ( 'dry_run' === $mode ) {
					if ( $existing ) {
						++$updated;
					} else {
						++$created;
					}

					continue;
				}

				if ( ! $existing ) {
					$id = $this->contacts->create(
						array(
							'email'      => $email,
							'first_name' => (string) $row['first_name'],
							'last_name'  => (string) $row['last_name'],
							'phone'      => (string) $row['phone'],
							'wp_user_id' => $row['user_id'] > 0 ? (int) $row['user_id'] : null,
							'status'     => $incoming,
							'source'     => 'fluentcrm',
						)
					);

					if ( is_wp_error( $id ) ) {
						$existing = $this->contacts->find_by_email( $email );

						if ( ! $existing ) {
							++$failed;
							$errors[ $id->get_error_code() ] = ( $errors[ $id->get_error_code() ] ?? 0 ) + 1;
							continue;
						}

						$existing_id = (int) $existing['id'];
					} else {
						$existing_id = (int) $id;
						++$created;
					}
				} else {
					$existing_id = (int) $existing['id'];
					$this->reconcile_existing( $existing, $row, $incoming );
					++$updated;
				}

				if ( ! empty( $relations[ $row['remote_id'] ] ) ) {
					$this->attach_terms( $existing_id, $relations[ $row['remote_id'] ] );
				}

				if ( $limit > 0 && $processed >= $limit ) {
					break 2;
				}
			}

			$this->save_progress( (int) $run['id'], $cursor, $created, $updated, $skipped, $failed );

			if ( $limit > 0 && $processed >= $limit ) {
				break;
			}
		}

		$campaign_count = $campaigns ? $this->import_campaigns( 'dry_run' !== $mode ) : 0;

		$after = $this->contacts->count_by_status();

		$report = array(
			'ok'            => true,
			'mode'          => $mode,
			'run_id'        => (int) $run['id'],
			'processed'     => $processed,
			'created'       => $created,
			'updated'       => $updated,
			'skipped'       => $skipped,
			'failed'        => $failed,
			'campaigns'     => $campaign_count,
			'last_cursor'   => $cursor,
			'remote_status' => $status_map,
			'local_before'  => $before,
			'local_after'   => $after,
			'terms'         => $this->adapter->terms(),
			'errors'        => $errors,
			'complete'      => $complete,
			'duration'      => 0,
		);

		$status = 'dry_run' === $mode ? 'dry_run_completed' : 'completed';

		if ( ! $complete ) {
			$status = 'running';
		}

		$this->finish_run( (int) $run['id'], $status, $report );

		return $report;
	}

	/**
	 * Recent import runs.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function runs( int $limit = 10 ): array {
		global $wpdb;

		$table = Schema::table( 'import_runs' );
		$limit = max( 1, min( 100, $limit ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- trusted table and integer.
		return (array) $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id DESC LIMIT {$limit}", ARRAY_A );
	}

	/**
	 * Reconcile an existing contact without loosening suppression.
	 *
	 * @param array<string, mixed> $existing Current row.
	 * @param array<string, mixed> $row      Remote row.
	 */
	private function reconcile_existing( array $existing, array $row, string $incoming ): void {
		$profile = array();

		foreach ( array( 'first_name', 'last_name', 'phone' ) as $field ) {
			if ( '' === (string) $existing[ $field ] && '' !== (string) $row[ $field ] ) {
				$profile[ $field ] = (string) $row[ $field ];
			}
		}

		if ( empty( $existing['wp_user_id'] ) && ! empty( $row['user_id'] ) ) {
			$profile['wp_user_id'] = (int) $row['user_id'];
		}

		if ( $profile ) {
			$this->contacts->update( (int) $existing['id'], $profile );
		}

		$current_suppressed = Status::is_suppressed( (string) $existing['status'] );
		$incoming_suppressed = Status::is_suppressed( $incoming );

		if ( $current_suppressed ) {
			return;
		}

		if ( $incoming_suppressed ) {
			$this->contacts->set_status( (int) $existing['id'], $incoming, 'fluentcrm-import', 'Imported status from FluentCRM' );
		}
	}

	/**
	 * Map FluentCRM's statuses onto the five CRM statuses. pending and archived
	 * become transactional (no marketing consent); spammed is treated as a
	 * complaint so it is suppressed.
	 */
	private function map_status( string $status ): string {
		$status = strtolower( trim( $status ) );

		$map = array(
			'subscribed'    => Status::SUBSCRIBED,
			'unsubscribed'  => Status::UNSUBSCRIBED,
			'transactional' => Status::TRANSACTIONAL,
			'bounced'       => Status::BOUNCED,
			'complained'    => Status::COMPLAINED,
			'spammed'       => Status::COMPLAINED,
			'pending'       => Status::TRANSACTIONAL,
			'archived'      => Status::TRANSACTIONAL,
		);

		return $map[ $status ] ?? Status::TRANSACTIONAL;
	}

	/**
	 * Attach list/tag relations for one contact.
	 *
	 * @param array{lists: int[], tags: int[]} $relations Remote term IDs.
	 */
	private function attach_terms( int $contact_id, array $relations ): void {
		if ( null === $this->remote_terms ) {
			$this->remote_terms = $this->adapter->terms();
		}

		$remote_terms = $this->remote_terms;

		foreach ( array( 'lists' => TermRepository::TYPE_LIST, 'tags' => TermRepository::TYPE_TAG ) as $bucket => $type ) {
			$by_remote = array();

			foreach ( $remote_terms[ $bucket ] as $term ) {
				$by_remote[ $term['remote_id'] ] = $term['name'];
			}

			foreach ( (array) ( $relations[ $bucket ] ?? array() ) as $remote_id ) {
				if ( empty( $by_remote[ $remote_id ] ) ) {
					continue;
				}

				$name = $by_remote[ $remote_id ];
				$key  = $type . ':' . $name;

				if ( ! isset( $this->term_cache[ $key ] ) ) {
					$this->term_cache[ $key ] = (int) $this->terms->ensure( $type, $name );
				}

				if ( $this->term_cache[ $key ] > 0 ) {
					$this->terms->attach( $contact_id, $this->term_cache[ $key ] );
				}
			}
		}
	}

	/**
	 * Copy historical campaign rows into the local legacy archive.
	 */
	private function import_campaigns( bool $apply ): int {
		$cursor = 0;
		$count  = 0;

		while ( true ) {
			$rows = $this->adapter->campaigns_batch( $cursor, 100 );

			if ( ! $rows ) {
				break;
			}

			foreach ( $rows as $row ) {
				$cursor = (int) $row['remote_id'];

				if ( $apply ) {
					$this->upsert_campaign( $row );
				}

				++$count;
			}
		}

		return $count;
	}

	/**
	 * Idempotent legacy campaign upsert.
	 */
	private function upsert_campaign( array $row ): void {
		global $wpdb;

		$table   = Schema::table( 'legacy_campaigns' );
		$created = $row['created_at'] ? gmdate( 'Y-m-d H:i:s', (int) strtotime( (string) $row['created_at'] ) ) : '';

		$sql = "INSERT INTO {$table}
			(remote_id, title, subject, type, status, recipients_count, open_count, click_count, content, meta, remote_created_at, imported_at)
			VALUES (%d, %s, %s, %s, %s, %d, %d, %d, %s, %s, " . ( '' !== $created ? '%s' : 'NULL' ) . ", %s)
			ON DUPLICATE KEY UPDATE
				title = VALUES(title), subject = VALUES(subject), type = VALUES(type), status = VALUES(status),
				recipients_count = VALUES(recipients_count), open_count = VALUES(open_count), click_count = VALUES(click_count),
				content = VALUES(content), meta = VALUES(meta), remote_created_at = VALUES(remote_created_at), imported_at = VALUES(imported_at)";

		$params = array(
			(int) $row['remote_id'],
			substr( (string) $row['title'], 0, 255 ),
			substr( (string) $row['subject'], 0, 255 ),
			substr( (string) $row['type'], 0, 30 ),
			substr( (string) $row['status'], 0, 30 ),
			(int) $row['recipients_count'],
			(int) $row['open_count'],
			(int) $row['click_count'],
			(string) $row['content'],
			(string) $row['meta'],
		);

		if ( '' !== $created ) {
			$params[] = $created;
		}

		$params[] = gmdate( 'Y-m-d H:i:s' );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is trusted.
		$wpdb->query( $wpdb->prepare( $sql, $params ) );
	}

	/**
	 * Start a new run row.
	 *
	 * @return array<string, mixed>
	 */
	private function start_run( string $mode ): array {
		global $wpdb;

		$table = Schema::table( 'import_runs' );
		$now   = gmdate( 'Y-m-d H:i:s' );

		$wpdb->insert(
			$table,
			array(
				'source'      => 'fluentcrm',
				'mode'        => $mode,
				'status'      => 'running',
				'cursor_value' => '0',
				'started_at'  => $now,
			),
			array( '%s', '%s', '%s', '%s', '%s' )
		);

		return array(
			'id'           => (int) $wpdb->insert_id,
			'cursor_value' => '0',
			'imported'     => 0,
			'updated'      => 0,
			'skipped'      => 0,
			'failed'       => 0,
		);
	}

	/**
	 * Resume the newest unfinished run of the same mode, or start fresh.
	 *
	 * @return array<string, mixed>
	 */
	private function resume_run( string $mode ): array {
		global $wpdb;

		$table = Schema::table( 'import_runs' );

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE source = %s AND mode = %s AND status = %s ORDER BY id DESC LIMIT 1",
				'fluentcrm',
				$mode,
				'running'
			),
			ARRAY_A
		);

		return $row ? $row : $this->start_run( $mode );
	}

	/**
	 * Persist progress after each batch so an interrupted run can resume.
	 */
	private function save_progress( int $run_id, int $cursor, int $created, int $updated, int $skipped, int $failed ): void {
		global $wpdb;

		$wpdb->update(
			Schema::table( 'import_runs' ),
			array(
				'cursor_value' => (string) $cursor,
				'imported'     => $created,
				'updated'      => $updated,
				'skipped'      => $skipped,
				'failed'       => $failed,
			),
			array( 'id' => $run_id ),
			array( '%s', '%d', '%d', '%d', '%d' ),
			array( '%d' )
		);
	}

	/**
	 * Close a run.
	 */
	private function finish_run( int $run_id, string $status, array $report ): void {
		global $wpdb;

		$data = array(
			'status'       => $status,
			'cursor_value' => (string) $report['last_cursor'],
			'imported'     => (int) $report['created'],
			'updated'      => (int) $report['updated'],
			'skipped'      => (int) $report['skipped'],
			'failed'       => (int) $report['failed'],
			'report'       => wp_json_encode( $report ),
		);
		$formats = array( '%s', '%s', '%d', '%d', '%d', '%d', '%s' );

		if ( 'running' !== $status ) {
			$data['finished_at'] = gmdate( 'Y-m-d H:i:s' );
			$formats[]           = '%s';
		}

		$wpdb->update(
			Schema::table( 'import_runs' ),
			$data,
			array( 'id' => $run_id ),
			$formats,
			array( '%d' )
		);
	}
}
