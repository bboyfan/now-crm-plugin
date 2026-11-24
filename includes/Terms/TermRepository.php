<?php
/**
 * Lists and tags (a single taxonomy table with a type discriminator).
 *
 * @package NowCRM
 */

namespace NowCRM\Terms;

use NowCRM\Contacts\Status;
use NowCRM\Support\Schema;
use WP_Error;

defined( 'ABSPATH' ) || exit;

final class TermRepository {

	public const TYPE_LIST = 'list';
	public const TYPE_TAG  = 'tag';

	public const CUSTOMER_LIST = 'Customers';

	/**
	 * Customer list name used by earlier releases. New installs never create
	 * it, but an upgrade must keep attaching contacts to the existing list.
	 */
	private const LEGACY_CUSTOMER_LIST = '溫釲顧客';

	/**
	 * Name of the list that every WooCommerce customer is attached to.
	 *
	 * An explicit filter always wins. Without one, an existing legacy list is
	 * reused so an upgrade does not rename, duplicate, or split the customer
	 * list. A fresh install gets the neutral default.
	 */
	public static function customer_list_name(): string {
		if ( has_filter( 'nowcrm_customer_list_name' ) ) {
			return (string) apply_filters( 'nowcrm_customer_list_name', self::CUSTOMER_LIST );
		}

		$legacy = ( new self() )->find_by_slug(
			self::TYPE_LIST,
			self::slug_for( self::LEGACY_CUSTOMER_LIST )
		);

		return $legacy ? self::LEGACY_CUSTOMER_LIST : self::CUSTOMER_LIST;
	}

	/**
	 * Allowed types.
	 *
	 * @return string[]
	 */
	public static function types(): array {
		return array( self::TYPE_LIST, self::TYPE_TAG );
	}

	/**
	 * Build a stable slug.
	 *
	 * `sanitize_title()` strips percent-encoded octets, so a pure CJK name
	 * collapses to an empty string. Fall back to a deterministic hash so list
	 * names stay unique without depending on transliteration.
	 */
	public static function slug_for( string $name ): string {
		$slug = sanitize_title( $name );

		if ( '' === $slug ) {
			$slug = 'term-' . substr( md5( trim( $name ) ), 0, 12 );
		}

		return $slug;
	}

	/**
	 * Create a term.
	 *
	 * @param array<string, mixed> $args slug, description.
	 * @return int|WP_Error
	 */
	public function create( string $type, string $name, array $args = array() ) {
		if ( ! in_array( $type, self::types(), true ) ) {
			return new WP_Error( 'nowcrm_invalid_term_type', __( '名單或標籤類型不正確。', 'now-crm' ) );
		}

		$name = sanitize_text_field( $name );

		if ( '' === $name ) {
			return new WP_Error( 'nowcrm_invalid_term_name', __( '名稱不可空白。', 'now-crm' ) );
		}

		$slug = isset( $args['slug'] ) && '' !== (string) $args['slug']
			? self::slug_for( (string) $args['slug'] )
			: self::slug_for( $name );

		if ( '' === $slug ) {
			return new WP_Error( 'nowcrm_invalid_term_slug', __( '無法產生有效的 slug。', 'now-crm' ) );
		}

		if ( $this->find_by_slug( $type, $slug ) ) {
			return new WP_Error( 'nowcrm_duplicate_term', __( '同名或同 slug 的名單／標籤已存在。', 'now-crm' ) );
		}

		return $this->insert_row(
			$type,
			$name,
			$slug,
			isset( $args['description'] ) ? sanitize_textarea_field( (string) $args['description'] ) : null
		);
	}

	/**
	 * Insert one validated term row.
	 *
	 * @param string      $type        Term type.
	 * @param string      $name        Sanitized name.
	 * @param string      $slug        Sanitized slug.
	 * @param string|null $description Sanitized description.
	 * @return int|WP_Error
	 */
	private function insert_row( string $type, string $name, string $slug, ?string $description ) {
		global $wpdb;

		$now = gmdate( 'Y-m-d H:i:s' );

		$inserted = $wpdb->insert(
			Schema::table( 'terms' ),
			array(
				'type'        => $type,
				'name'        => $name,
				'slug'        => $slug,
				'description' => $description,
				'created_at'  => $now,
				'updated_at'  => $now,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( ! $inserted ) {
			return new WP_Error( 'nowcrm_db_error', $wpdb->last_error ?: __( '無法建立名單／標籤。', 'now-crm' ) );
		}

		$id = (int) $wpdb->insert_id;

		// Fire after the row exists; bulk inserts reach this point once per row.
		do_action( 'nowcrm_term_created', $id, $type );

		return $id;
	}

	/**
	 * Save several terms of one type by slug.
	 *
	 * Matches the bulk contract of the reference controllers: a known slug
	 * updates the name only, so the stored description and every contact
	 * relation stay intact. Unknown slugs insert. Repeated slugs inside the
	 * batch therefore update the row inserted earlier in the same call.
	 *
	 * @param array<int, array<string, mixed>> $items Each: name (or title), slug, description.
	 * @return array{created: int, updated: int, skipped: int}
	 */
	public function create_many( string $type, array $items ): array {
		if ( ! in_array( $type, self::types(), true ) ) {
			return array(
				'created' => 0,
				'updated' => 0,
				'skipped' => count( $items ),
			);
		}

		$rows    = array();
		$skipped = 0;

		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				++$skipped;

				continue;
			}

			$name = sanitize_text_field( (string) ( $item['name'] ?? $item['title'] ?? '' ) );

			if ( '' === $name ) {
				++$skipped;

				continue;
			}

			$raw_slug = trim( (string) ( $item['slug'] ?? '' ) );
			$slug     = self::slug_for( '' !== $raw_slug ? $raw_slug : $name );

			if ( '' === $slug ) {
				++$skipped;

				continue;
			}

			$rows[] = array(
				'name'        => $name,
				'slug'        => $slug,
				'description' => sanitize_textarea_field( (string) ( $item['description'] ?? '' ) ),
			);
		}

		$known   = $this->existing_terms( $type, array_column( $rows, 'slug' ) );
		$created = 0;
		$updated = 0;

		foreach ( $rows as $row ) {
			if ( isset( $known[ $row['slug'] ] ) ) {
				$changed = $this->update( (int) $known[ $row['slug'] ], array( 'name' => $row['name'] ) );

				if ( is_wp_error( $changed ) ) {
					++$skipped;

					continue;
				}

				++$updated;

				continue;
			}

			$inserted = $this->insert_row( $type, $row['name'], $row['slug'], $row['description'] );

			if ( is_wp_error( $inserted ) ) {
				++$skipped;

				continue;
			}

			$known[ $row['slug'] ] = (int) $inserted;
			++$created;
		}

		return array(
			'created' => $created,
			'updated' => $updated,
			'skipped' => $skipped,
		);
	}

	/**
	 * Stored terms of one type keyed by slug, fetched in one query.
	 *
	 * @param string[] $slugs Slugs to look up.
	 * @return array<string, int>
	 */
	private function existing_terms( string $type, array $slugs ): array {
		global $wpdb;

		$slugs = array_values( array_unique( array_filter( array_map( 'strval', $slugs ) ) ) );

		if ( array() === $slugs ) {
			return array();
		}

		$table        = Schema::table( 'terms' );
		$placeholders = implode( ',', array_fill( 0, count( $slugs ), '%s' ) );
		$params       = array_merge( array( $type ), $slugs );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders -- table name is trusted; placeholders are generated above.
		$found = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, slug FROM {$table} WHERE type = %s AND slug IN ({$placeholders})",
				$params
			),
			ARRAY_A
		);
		// phpcs:enable

		$known = array();

		foreach ( (array) $found as $row ) {
			$known[ (string) $row['slug'] ] = (int) $row['id'];
		}

		return $known;
	}

	/**
	 * Return the term ID for (type, name), creating it when missing.
	 *
	 * @return int Term ID, 0 on failure.
	 */
	public function ensure( string $type, string $name ): int {
		$slug   = self::slug_for( $name );
		$exists = '' === $slug ? null : $this->find_by_slug( $type, $slug );

		if ( $exists ) {
			return (int) $exists['id'];
		}

		$created = $this->create( $type, $name );

		return is_wp_error( $created ) ? 0 : (int) $created;
	}

	/**
	 * Fetch one term.
	 *
	 * @return array<string, mixed>|null
	 */
	public function get( int $id ): ?array {
		global $wpdb;

		$table = Schema::table( 'terms' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is trusted.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );

		if ( ! $row ) {
			return null;
		}

		$row['id'] = (int) $row['id'];

		return $row;
	}

	/**
	 * Fetch one term by type and slug.
	 *
	 * @return array<string, mixed>|null
	 */
	public function find_by_slug( string $type, string $slug ): ?array {
		global $wpdb;

		$table = Schema::table( 'terms' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is trusted.
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE type = %s AND slug = %s", $type, $slug ),
			ARRAY_A
		);

		if ( ! $row ) {
			return null;
		}

		$row['id'] = (int) $row['id'];

		return $row;
	}

	/**
	 * All terms, optionally filtered by type, each with a contact count.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function all( ?string $type = null ): array {
		global $wpdb;

		$table = Schema::table( 'terms' );
		$links = Schema::table( 'contact_terms' );

		$where  = '1=1';
		$params = array();

		if ( null !== $type && in_array( $type, self::types(), true ) ) {
			$where    = 't.type = %s';
			$params[] = $type;
		}

		$sql = "SELECT t.*, COUNT(ct.contact_id) AS contact_count
			FROM {$table} t
			LEFT JOIN {$links} ct ON ct.term_id = t.id
			WHERE {$where}
			GROUP BY t.id
			ORDER BY t.type ASC, t.name ASC";

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- table names are trusted.
		$rows = $params
			? $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A )
			: $wpdb->get_results( $sql, ARRAY_A );
		// phpcs:enable

		return array_map(
			static function ( array $row ): array {
				$row['id']            = (int) $row['id'];
				$row['contact_count'] = (int) $row['contact_count'];

				return $row;
			},
			(array) $rows
		);
	}

	/**
	 * One page of terms with membership counts.
	 *
	 * Runs one grouped query for the page plus one count query, so a page never
	 * issues per-row queries. Counts cover every linked contact, independent of
	 * status, and match the Contacts `term_id` filter.
	 *
	 * @param array<string, mixed> $args type, search, orderby, order, page, per_page.
	 * @return array{items: array<int, array<string, mixed>>, total: int}
	 */
	public function paginate( array $args = array() ): array {
		global $wpdb;

		$args = array_merge(
			array(
				'type'     => null,
				'search'   => '',
				'orderby'  => 'name',
				'order'    => 'ASC',
				'page'     => 1,
				'per_page' => 20,
			),
			$args
		);

		$type     = in_array( $args['type'], self::types(), true ) ? (string) $args['type'] : null;
		$search   = trim( (string) $args['search'] );
		$page     = max( 1, absint( $args['page'] ) );
		$per_page = max( 1, min( 100, absint( $args['per_page'] ) ) );
		$order    = 'DESC' === strtoupper( (string) $args['order'] ) ? 'DESC' : 'ASC';

		$columns = array(
			'id'            => 't.id',
			'name'          => 't.name',
			'slug'          => 't.slug',
			'contact_count' => 'contact_count',
			'created_at'    => 't.created_at',
			'updated_at'    => 't.updated_at',
		);

		$orderby = $columns[ (string) $args['orderby'] ] ?? 't.name';

		$table    = Schema::table( 'terms' );
		$links    = Schema::table( 'contact_terms' );
		$contacts = Schema::table( 'contacts' );

		$where  = array( '1=1' );
		$params = array();

		if ( null !== $type ) {
			$where[]  = 't.type = %s';
			$params[] = $type;
		}

		if ( '' !== $search ) {
			$like = '%' . $wpdb->esc_like( $search ) . '%';

			$where[] = '(t.name LIKE %s OR t.slug LIKE %s OR t.description LIKE %s)';
			array_push( $params, $like, $like, $like );
		}

		$where_sql = implode( ' AND ', $where );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders -- table names are trusted, values are placeholders.
		$count_sql = "SELECT COUNT(*) FROM {$table} t WHERE {$where_sql}";
		$total     = (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) : $wpdb->get_var( $count_sql ) );

		$sql = "SELECT t.*,
				COUNT(ct.contact_id) AS contact_count,
				SUM(CASE WHEN c.status = %s THEN 1 ELSE 0 END) AS subscribed_count
			FROM {$table} t
			LEFT JOIN {$links} ct ON ct.term_id = t.id
			LEFT JOIN {$contacts} c ON c.id = ct.contact_id
			WHERE {$where_sql}
			GROUP BY t.id
			ORDER BY {$orderby} {$order}, t.id ASC
			LIMIT %d OFFSET %d";

		$query_params   = array_merge( array( Status::SUBSCRIBED ), $params );
		$query_params[] = $per_page;
		$query_params[] = ( $page - 1 ) * $per_page;

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $query_params ), ARRAY_A );
		// phpcs:enable

		$items = array_map(
			static function ( array $row ): array {
				$row['id']               = (int) $row['id'];
				$row['contact_count']    = (int) $row['contact_count'];
				$row['subscribed_count'] = (int) $row['subscribed_count'];

				return $row;
			},
			(array) $rows
		);

		return array(
			'items' => $items,
			'total' => $total,
		);
	}

	/**
	 * Update a term.
	 *
	 * @param array<string, mixed> $data name, slug, description.
	 * @return bool|WP_Error
	 */
	public function update( int $id, array $data ) {
		global $wpdb;

		$term = $this->get( $id );

		if ( ! $term ) {
			return new WP_Error( 'nowcrm_not_found', __( '找不到名單／標籤。', 'now-crm' ) );
		}

		$fields  = array();
		$formats = array();

		if ( array_key_exists( 'name', $data ) ) {
			$name = sanitize_text_field( (string) $data['name'] );

			if ( '' === $name ) {
				return new WP_Error( 'nowcrm_invalid_term_name', __( '名稱不可空白。', 'now-crm' ) );
			}

			$fields['name'] = $name;
			$formats[]      = '%s';
		}

		if ( array_key_exists( 'slug', $data ) ) {
			$slug = sanitize_title( (string) $data['slug'] );

			if ( '' === $slug ) {
				return new WP_Error( 'nowcrm_invalid_term_slug', __( '無法產生有效的 slug。', 'now-crm' ) );
			}

			$dupe = $this->find_by_slug( (string) $term['type'], $slug );

			if ( $dupe && (int) $dupe['id'] !== $id ) {
				return new WP_Error( 'nowcrm_duplicate_term', __( '此 slug 已存在。', 'now-crm' ) );
			}

			$fields['slug'] = $slug;
			$formats[]      = '%s';
		}

		if ( array_key_exists( 'description', $data ) ) {
			$fields['description'] = sanitize_textarea_field( (string) $data['description'] );
			$formats[]             = '%s';
		}

		if ( array() === $fields ) {
			return true;
		}

		$fields['updated_at'] = gmdate( 'Y-m-d H:i:s' );
		$formats[]            = '%s';

		$updated = $wpdb->update( Schema::table( 'terms' ), $fields, array( 'id' => $id ), $formats, array( '%d' ) );

		if ( false === $updated ) {
			return new WP_Error( 'nowcrm_db_error', $wpdb->last_error ?: __( '無法更新名單／標籤。', 'now-crm' ) );
		}

		do_action( 'nowcrm_term_updated', $id, (string) $term['type'] );

		return true;
	}

	/**
	 * Delete a term and its contact relations.
	 *
	 * @param int         $id   Term ID.
	 * @param string|null $type Term type when the caller already resolved it.
	 */
	public function delete( int $id, ?string $type = null ): bool {
		global $wpdb;

		$term_type = null !== $type && in_array( $type, self::types(), true ) ? $type : '';

		// Only resolve the type when a listener needs it and the caller did not
		// already read the row.
		if ( '' === $term_type && false !== has_action( 'nowcrm_term_deleted' ) ) {
			$term = $this->get( $id );

			if ( $term ) {
				$term_type = (string) $term['type'];
			}
		}

		$wpdb->delete( Schema::table( 'contact_terms' ), array( 'term_id' => $id ), array( '%d' ) );

		$deleted = (bool) $wpdb->delete( Schema::table( 'terms' ), array( 'id' => $id ), array( '%d' ) );

		if ( $deleted && '' !== $term_type ) {
			do_action( 'nowcrm_term_deleted', $id, $term_type );
		}

		return $deleted;
	}

	/**
	 * Delete terms of one type and their contact relations.
	 *
	 * IDs of the other type are ignored. Chunks keep each statement bounded.
	 *
	 * @param int[] $term_ids Term IDs.
	 * @return int Number of deleted terms.
	 */
	public function delete_many( string $type, array $term_ids ): int {
		global $wpdb;

		if ( ! in_array( $type, self::types(), true ) ) {
			return 0;
		}

		$ids = array_values( array_unique( array_filter( array_map( 'absint', $term_ids ) ) ) );

		if ( array() === $ids ) {
			return 0;
		}

		$table   = Schema::table( 'terms' );
		$links   = Schema::table( 'contact_terms' );
		$deleted = 0;

		foreach ( array_chunk( $ids, 200 ) as $chunk ) {
			$placeholders = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );
			$params       = array_merge( array( $type ), $chunk );

			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders -- table names are trusted; placeholders are generated above.
			$matched = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT id FROM {$table} WHERE type = %s AND id IN ({$placeholders})",
					$params
				)
			);
			// phpcs:enable

			$matched = is_array( $matched )
				? array_values( array_filter( array_unique( array_map( 'absint', $matched ) ) ) )
				: array();

			if ( array() === $matched ) {
				continue;
			}

			$id_placeholders = implode( ',', array_fill( 0, count( $matched ), '%d' ) );

			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders -- table names are trusted; placeholders are generated above.
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$links} WHERE term_id IN ({$id_placeholders})",
					$matched
				)
			);

			$removed = $wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$table} WHERE type = %s AND id IN ({$id_placeholders})",
					array_merge( array( $type ), $matched )
				)
			);
			// phpcs:enable

			if ( false === $removed ) {
				continue;
			}

			$removed  = (int) $removed;
			$deleted += $removed;

			// Emit only when every selected row left in this statement, so a
			// concurrent delete can never produce an event for a surviving row.
			if ( $removed === count( $matched ) ) {
				foreach ( $matched as $term_id ) {
					do_action( 'nowcrm_term_deleted', (int) $term_id, $type );
				}
			}
		}

		return $deleted;
	}

	/**
	 * Attach a contact to a term. Idempotent.
	 */
	public function attach( int $contact_id, int $term_id ): bool {
		global $wpdb;

		if ( $contact_id <= 0 || $term_id <= 0 ) {
			return false;
		}

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name comes from Schema::table() and every value uses a prepare() placeholder.
		$wpdb->query(
			$wpdb->prepare(
				'INSERT IGNORE INTO ' . Schema::table( 'contact_terms' ) . ' (contact_id, term_id, created_at) VALUES (%d, %d, %s)',
				$contact_id,
				$term_id,
				gmdate( 'Y-m-d H:i:s' )
			)
		);
		// phpcs:enable

		if ( 1 === (int) $wpdb->rows_affected ) {
			$term = $this->get( $term_id );

			do_action( 'nowcrm_term_attached', $contact_id, $term_id, $term ? (string) $term['type'] : '' );
		}

		return true;
	}

	/**
	 * Detach a contact from a term. Idempotent.
	 */
	public function detach( int $contact_id, int $term_id ): bool {
		global $wpdb;

		$removed = $wpdb->delete(
			Schema::table( 'contact_terms' ),
			array(
				'contact_id' => $contact_id,
				'term_id'    => $term_id,
			),
			array( '%d', '%d' )
		);

		if ( (int) $removed > 0 ) {
			$term = $this->get( $term_id );

			do_action( 'nowcrm_term_detached', $contact_id, $term_id, $term ? (string) $term['type'] : '' );
		}

		return true;
	}

	/**
	 * Replace the full term set of a contact.
	 *
	 * @param int[] $term_ids Term IDs to keep.
	 */
	public function sync_for_contact( int $contact_id, array $term_ids ): void {
		global $wpdb;

		$keep = array_values( array_unique( array_filter( array_map( 'absint', $term_ids ) ) ) );

		$table = Schema::table( 'contact_terms' );

		if ( array() === $keep ) {
			$removed = $wpdb->get_col( $wpdb->prepare( "SELECT term_id FROM {$table} WHERE contact_id = %d", $contact_id ) );
			$wpdb->delete( $table, array( 'contact_id' => $contact_id ), array( '%d' ) );

			foreach ( (array) $removed as $term_id ) {
				$term = $this->get( (int) $term_id );

				do_action( 'nowcrm_term_detached', $contact_id, (int) $term_id, $term ? (string) $term['type'] : '' );
			}

			return;
		}

		$placeholders = implode( ',', array_fill( 0, count( $keep ), '%d' ) );
		$params       = array_merge( array( $contact_id ), $keep );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders -- placeholders generated above.
		$removed = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT term_id FROM {$table} WHERE contact_id = %d AND term_id NOT IN ({$placeholders})",
				$params
			)
		);

		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE contact_id = %d AND term_id NOT IN ({$placeholders})",
				$params
			)
		);
		// phpcs:enable

		foreach ( (array) $removed as $term_id ) {
			$term = $this->get( (int) $term_id );

			do_action( 'nowcrm_term_detached', $contact_id, (int) $term_id, $term ? (string) $term['type'] : '' );
		}

		foreach ( $keep as $term_id ) {
			$this->attach( $contact_id, $term_id );
		}
	}

	/**
	 * Terms of one contact.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function for_contact( int $contact_id ): array {
		global $wpdb;

		$links = Schema::table( 'contact_terms' );
		$terms = Schema::table( 'terms' );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names are trusted.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT t.* FROM {$links} ct INNER JOIN {$terms} t ON t.id = ct.term_id
				WHERE ct.contact_id = %d ORDER BY t.type ASC, t.name ASC",
				$contact_id
			),
			ARRAY_A
		);
		// phpcs:enable

		return array_map(
			static function ( array $row ): array {
				$row['id'] = (int) $row['id'];

				return $row;
			},
			(array) $rows
		);
	}

	/**
	 * Contact IDs inside a term.
	 *
	 * @return int[]
	 */
	public function contact_ids( int $term_id ): array {
		global $wpdb;

		$links = Schema::table( 'contact_terms' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is trusted.
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT contact_id FROM {$links} WHERE term_id = %d", $term_id ) );

		return array_map( 'intval', (array) $ids );
	}
}
