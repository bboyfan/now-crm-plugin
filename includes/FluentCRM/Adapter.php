<?php
/**
 * Read-only FluentCRM adapter. It never loads FluentCRM code and never writes
 * to fc_* tables; unknown schema variations are detected from the live columns.
 *
 * @package NowCRM
 */

namespace NowCRM\FluentCRM;

defined( 'ABSPATH' ) || exit;

final class Adapter {

	/** @var array<string, string[]> */
	private array $columns = array();

	/** @var array<string, string> */
	private array $tables = array();

	/**
	 * FluentCRM's subscriber table (fc_subscribers). Older documentation calls
	 * the same rows "contacts"; fc_contacts is accepted as a legacy fallback.
	 */
	public function contacts_table_name(): string {
		foreach ( array( 'subscribers', 'contacts' ) as $name ) {
			if ( '' !== $this->table( $name ) && $this->has_column( $name, 'email' ) ) {
				return $name;
			}
		}

		return '';
	}

	/**
	 * Whether the core FluentCRM subscriber table is readable.
	 */
	public function available(): bool {
		return '' !== $this->contacts_table_name();
	}

	/**
	 * Resolve a fc_* table that actually exists.
	 */
	public function table( string $name ): string {
		if ( array_key_exists( $name, $this->tables ) ) {
			return $this->tables[ $name ];
		}

		global $wpdb;

		$candidates = array(
			$wpdb->prefix . 'fc_' . $name,
			$wpdb->base_prefix . 'fc_' . $name,
		);

		$found = '';

		foreach ( array_unique( $candidates ) as $candidate ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- schema probe.
			$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $candidate ) ) );

			if ( $exists === $candidate || $this->table_is_readable( $candidate ) ) {
				$found = $candidate;
				break;
			}
		}

		$this->tables[ $name ] = $found;

		return $found;
	}

	/**
	 * Probe a table that SHOW TABLES may not list (temporary tables in tests).
	 * Production installations always resolve through SHOW TABLES first.
	 */
	private function table_is_readable( string $table ): bool {
		global $wpdb;

		$suppressed      = $wpdb->suppress_errors( true );
		$wpdb->last_error = '';
		$wpdb->get_var( "SELECT 1 FROM {$table} LIMIT 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- schema probe.
		$error = $wpdb->last_error;
		$wpdb->suppress_errors( $suppressed );

		return '' === $error;
	}

	/**
	 * Column names of a fc_* table.
	 *
	 * @return string[]
	 */
	public function column_names( string $name ): array {
		if ( isset( $this->columns[ $name ] ) ) {
			return $this->columns[ $name ];
		}

		global $wpdb;

		$table = $this->table( $name );

		if ( '' === $table ) {
			$this->columns[ $name ] = array();

			return array();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- trusted internal table.
		$rows = $wpdb->get_col( "DESCRIBE {$table}", 0 );

		$this->columns[ $name ] = array_map( 'strval', (array) $rows );

		return $this->columns[ $name ];
	}

	/**
	 * Whether a table has a column.
	 */
	public function has_column( string $name, string $column ): bool {
		return in_array( $column, $this->column_names( $name ), true );
	}

	/**
	 * Total contacts.
	 */
	public function contact_count(): int {
		global $wpdb;

		$name = $this->contacts_table_name();

		if ( '' === $name ) {
			return 0;
		}

		$table = $this->table( $name );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- trusted internal table.
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	}

	/**
	 * Status distribution.
	 *
	 * @return array<string, int>
	 */
	public function status_counts(): array {
		global $wpdb;

		$name = $this->contacts_table_name();

		if ( '' === $name || ! $this->has_column( $name, 'status' ) ) {
			return array();
		}

		$table = $this->table( $name );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- trusted internal table.
		$rows = (array) $wpdb->get_results( "SELECT status, COUNT(*) AS total FROM {$table} GROUP BY status", ARRAY_A );
		$out  = array();

		foreach ( $rows as $row ) {
			$out[ (string) $row['status'] ] = (int) $row['total'];
		}

		return $out;
	}

	/**
	 * One batch of normalized contacts after a cursor.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function contacts_batch( int $cursor, int $limit = 200 ): array {
		global $wpdb;

		$name = $this->contacts_table_name();

		if ( '' === $name ) {
			return array();
		}

		$table = $this->table( $name );
		$limit = max( 1, min( 1000, $limit ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- trusted internal table and integer limit.
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id > %d ORDER BY id ASC LIMIT {$limit}", $cursor ),
			ARRAY_A
		);

		$out = array();

		foreach ( (array) $rows as $row ) {
			$out[] = array(
				'remote_id'  => (int) $row['id'],
				'email'      => (string) ( $row['email'] ?? '' ),
				'first_name' => (string) ( $row['first_name'] ?? '' ),
				'last_name'  => (string) ( $row['last_name'] ?? '' ),
				'phone'      => (string) ( $row['phone'] ?? '' ),
				'status'     => (string) ( $row['status'] ?? '' ),
				'user_id'    => isset( $row['user_id'] ) ? (int) $row['user_id'] : 0,
				'source'     => (string) ( $row['source'] ?? '' ),
				'created_at' => (string) ( $row['created_at'] ?? '' ),
				'updated_at' => (string) ( $row['updated_at'] ?? '' ),
			);
		}

		return $out;
	}

	/**
	 * Lists and tags with IDs and titles.
	 *
	 * @return array{lists: array<int, array<string, mixed>>, tags: array<int, array<string, mixed>>}
	 */
	public function terms(): array {
		return array(
			'lists' => $this->term_rows( 'lists' ),
			'tags'  => $this->term_rows( 'tags' ),
		);
	}

	/**
	 * One term family.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function term_rows( string $name ): array {
		global $wpdb;

		$table = $this->table( $name );

		if ( '' === $table ) {
			return array();
		}

		$title = $this->has_column( $name, 'title' ) ? 'title' : 'name';
		$slug  = $this->has_column( $name, 'slug' ) ? 'slug' : '';

		$select = 'id, ' . $title . ( $slug ? ', ' . $slug : '' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- internal columns.
		$rows = (array) $wpdb->get_results( "SELECT {$select} FROM {$table} ORDER BY id ASC", ARRAY_A );
		$out  = array();

		foreach ( $rows as $row ) {
			$out[] = array(
				'remote_id' => (int) $row['id'],
				'name'      => (string) $row[ $title ],
				'slug'      => (string) ( $slug ? ( $row[ $slug ] ?? '' ) : '' ),
			);
		}

		return $out;
	}

	/**
	 * Contact → list/tag relations for a batch of remote contact IDs.
	 *
	 * Supports FluentCRM's relation table, dedicated pivot tables, and the
	 * legacy serialized meta format.
	 *
	 * @param int[] $contact_ids Remote IDs.
	 * @return array<int, array{lists: int[], tags: int[]}>
	 */
	public function contact_terms( array $contact_ids ): array {
		global $wpdb;

		$contact_ids = array_values( array_filter( array_map( 'absint', $contact_ids ) ) );
		$out         = array();

		foreach ( $contact_ids as $id ) {
			$out[ $id ] = array(
				'lists' => array(),
				'tags'  => array(),
			);
		}

		if ( ! $contact_ids ) {
			return $out;
		}

		$placeholders = implode( ',', array_fill( 0, count( $contact_ids ), '%d' ) );

		$pivot = $this->table( 'subscriber_pivot' );

		if ( '' !== $pivot && $this->has_column( 'subscriber_pivot', 'subscriber_id' ) ) {
			// FluentCRM 3.x: object_type is the model FQCN
			// (FluentCrm\App\Models\Lists / Tags), object_id is the term ID.
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders built.
			$rows = (array) $wpdb->get_results(
				$wpdb->prepare(
					"SELECT subscriber_id, object_id, object_type FROM {$pivot} WHERE subscriber_id IN ({$placeholders})",
					$contact_ids
				),
				ARRAY_A
			);

			foreach ( $rows as $row ) {
				$type = strtolower( (string) $row['object_type'] );
				$id   = (int) $row['subscriber_id'];
				$rel  = absint( $row['object_id'] );

				if ( str_contains( $type, 'list' ) ) {
					$out[ $id ]['lists'][] = $rel;
				} elseif ( str_contains( $type, 'tag' ) ) {
					$out[ $id ]['tags'][] = $rel;
				}
			}

			return $out;
		}

		$relations = $this->table( 'contact_relations' );

		if ( '' !== $relations && $this->has_column( 'contact_relations', 'relation_type' ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders built.
			$rows = (array) $wpdb->get_results(
				$wpdb->prepare(
					"SELECT contact_id, relation_type, relation_id FROM {$relations} WHERE contact_id IN ({$placeholders})",
					$contact_ids
				),
				ARRAY_A
			);

			foreach ( $rows as $row ) {
				$type = strtolower( (string) $row['relation_type'] );
				$id   = (int) $row['contact_id'];
				$rel  = absint( $row['relation_id'] );

				if ( 'list' === $type || 'lists' === $type || '1' === $type ) {
					$out[ $id ]['lists'][] = $rel;
				}

				if ( 'tag' === $type || 'tags' === $type || '2' === $type ) {
					$out[ $id ]['tags'][] = $rel;
				}
			}

			return $out;
		}

		foreach ( array( 'lists' => 'list', 'tags' => 'tag' ) as $pivot => $bucket ) {
			$table = $this->table( 'contact_' . $pivot );

			if ( '' === $table ) {
				continue;
			}

			$id_col = $pivot . '_id';
			$id_col = $this->has_column( 'contact_' . $pivot, $id_col ) ? $id_col : 'term_id';

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders built.
			$rows = (array) $wpdb->get_results(
				$wpdb->prepare(
					"SELECT contact_id, {$id_col} AS term_id FROM {$table} WHERE contact_id IN ({$placeholders})",
					$contact_ids
				),
				ARRAY_A
			);

			foreach ( $rows as $row ) {
				$out[ (int) $row['contact_id'] ][ $pivot ][] = absint( $row['term_id'] );
			}
		}

		$meta = $this->table( 'contact_meta' );

		if ( '' !== $meta && $this->has_column( 'contact_meta', 'key' ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders built.
			$rows = (array) $wpdb->get_results(
				$wpdb->prepare(
					"SELECT contact_id, `key`, `value` FROM {$meta} WHERE contact_id IN ({$placeholders}) AND `key` IN ('list_ids','tag_ids')",
					$contact_ids
				),
				ARRAY_A
			);

			foreach ( $rows as $row ) {
				$bucket = 'list_ids' === $row['key'] ? 'lists' : 'tags';
				$value  = maybe_unserialize( (string) $row['value'] );

				if ( is_string( $value ) ) {
					$value = preg_split( '/[,\s]+/', trim( $value, '[]' ) );
				}

				foreach ( (array) $value as $term_id ) {
					$term_id = absint( $term_id );

					if ( $term_id ) {
						$out[ (int) $row['contact_id'] ][ $bucket ][] = $term_id;
					}
				}
			}
		}

		return $out;
	}

	/**
	 * Aggregate per-campaign sent/open/click counters from fc_campaign_emails.
	 *
	 * @param int[] $campaign_ids Campaign IDs.
	 * @return array<int, array{sent: int, opens: int, clicks: int}>
	 */
	private function campaign_engagement( array $campaign_ids ): array {
		global $wpdb;

		$table = $this->table( 'campaign_emails' );

		if ( '' === $table || ! $campaign_ids ) {
			return array();
		}

		$campaign_ids = array_values( array_filter( array_map( 'absint', $campaign_ids ) ) );

		if ( ! $campaign_ids ) {
			return array();
		}

		$placeholders = implode( ',', array_fill( 0, count( $campaign_ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders built.
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT campaign_id,
					SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) AS sent,
					SUM(CASE WHEN is_open = 1 THEN 1 ELSE 0 END) AS opens,
					SUM(CASE WHEN click_counter > 0 THEN 1 ELSE 0 END) AS clicks
				FROM {$table}
				WHERE campaign_id IN ({$placeholders})
				GROUP BY campaign_id",
				$campaign_ids
			),
			ARRAY_A
		);

		$out = array();

		foreach ( $rows as $row ) {
			$out[ (int) $row['campaign_id'] ] = array(
				'sent'   => (int) $row['sent'],
				'opens'  => (int) $row['opens'],
				'clicks' => (int) $row['clicks'],
			);
		}

		return $out;
	}

	/**
	 * Historical campaigns after a cursor.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function campaigns_batch( int $cursor, int $limit = 100 ): array {
		global $wpdb;

		$table = $this->table( 'campaigns' );

		if ( '' === $table ) {
			return array();
		}

		$limit = max( 1, min( 500, $limit ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- trusted internal table and integer limit.
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id > %d ORDER BY id ASC LIMIT {$limit}", $cursor ),
			ARRAY_A
		);

		$ids = array_map(
			static function ( array $row ): int {
				return (int) $row['id'];
			},
			(array) $rows
		);

		$engagement = $this->campaign_engagement( $ids );
		$out        = array();

		foreach ( (array) $rows as $row ) {
			$content = '';

			foreach ( array( 'email_body', 'content', 'email_content', 'body' ) as $column ) {
				if ( isset( $row[ $column ] ) && is_string( $row[ $column ] ) && '' !== $row[ $column ] ) {
					$content = $row[ $column ];
					break;
				}
			}

			$id = (int) $row['id'];

			$out[] = array(
				'remote_id'        => $id,
				'title'            => (string) ( $row['title'] ?? $row['name'] ?? '' ),
				'subject'          => (string) ( $row['email_subject'] ?? $row['subject'] ?? '' ),
				'type'             => (string) ( $row['type'] ?? '' ),
				'status'           => (string) ( $row['status'] ?? '' ),
				'recipients_count' => (int) ( $row['recipients_count'] ?? $engagement[ $id ]['sent'] ?? 0 ),
				'open_count'       => (int) ( $engagement[ $id ]['opens'] ?? ( $row['open_count'] ?? 0 ) ),
				'click_count'      => (int) ( $engagement[ $id ]['clicks'] ?? ( $row['click_count'] ?? 0 ) ),
				'content'          => $content,
				'meta'             => wp_json_encode( $row ),
				'created_at'       => (string) ( $row['created_at'] ?? '' ),
			);
		}

		return $out;
	}
}
