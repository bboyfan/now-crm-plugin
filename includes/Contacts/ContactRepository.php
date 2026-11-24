<?php
/**
 * Contact persistence. All reads and writes go through this class.
 *
 * @package NowCRM
 */

namespace NowCRM\Contacts;

use NowCRM\Support\Schema;
use WP_Error;

defined( 'ABSPATH' ) || exit;

final class ContactRepository {

	private ?StatusHistoryRepository $history = null;

	/**
	 * Status history repository.
	 */
	public function history(): StatusHistoryRepository {
		if ( null === $this->history ) {
			$this->history = new StatusHistoryRepository();
		}

		return $this->history;
	}

	/**
	 * Normalize an email address for storage and lookups.
	 */
	public function normalize_email( string $email ): string {
		return mb_strtolower( trim( $email ), 'UTF-8' );
	}

	/**
	 * Create a contact.
	 *
	 * @param array<string, mixed> $data email (required), first_name, last_name, phone, status, wp_user_id, source, actor_id, plus any ProfileFields::keys(). Set `defer_event` to skip the created hook so a caller can attach lists and custom values first.
	 * @return int|WP_Error Contact ID.
	 */
	public function create( array $data ) {
		global $wpdb;

		$email = $this->normalize_email( (string) ( $data['email'] ?? '' ) );

		if ( '' === $email || ! is_email( $email ) ) {
			return new WP_Error( 'nowcrm_invalid_email', __( 'Email 格式不正確。', 'now-crm' ) );
		}

		$status = isset( $data['status'] ) ? sanitize_key( (string) $data['status'] ) : Status::SUBSCRIBED;

		if ( ! Status::is_valid( $status ) ) {
			return new WP_Error( 'nowcrm_invalid_status', __( '聯絡人狀態不正確。', 'now-crm' ) );
		}

		if ( $this->find_by_email( $email ) ) {
			return new WP_Error( 'nowcrm_duplicate_email', __( '此 Email 已存在。', 'now-crm' ) );
		}

		$now    = gmdate( 'Y-m-d H:i:s' );
		$source = substr( sanitize_text_field( (string) ( $data['source'] ?? 'manual' ) ), 0, 191 );

		$row = array(
			'email'             => $email,
			'email_normalized'  => $email,
			'first_name'        => sanitize_text_field( (string) ( $data['first_name'] ?? '' ) ),
			'last_name'         => sanitize_text_field( (string) ( $data['last_name'] ?? '' ) ),
			'phone'             => sanitize_text_field( (string) ( $data['phone'] ?? '' ) ),
			'wp_user_id'        => empty( $data['wp_user_id'] ) ? null : absint( $data['wp_user_id'] ),
			'status'            => $status,
			'status_changed_at' => $now,
			'source'            => $source,
			'created_at'        => $now,
			'updated_at'        => $now,
		);

		$profile = ProfileFields::normalize( $data );

		if ( is_wp_error( $profile ) ) {
			return $profile;
		}

		// New rows start from the canonical profile defaults.
		$profile = array_merge( ProfileFields::defaults(), $profile );
		$row     = array_merge( $row, $profile );

		$formats = array( '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s' );
		$formats = array_merge( $formats, array_fill( 0, count( $profile ), '%s' ) );

		$inserted = $wpdb->insert(
			Schema::table( 'contacts' ),
			$row,
			$formats
		);

		if ( ! $inserted ) {
			// A concurrent request may have won the unique key race.
			if ( $this->find_by_email( $email ) ) {
				return new WP_Error( 'nowcrm_duplicate_email', __( '此 Email 已存在。', 'now-crm' ) );
			}

			return new WP_Error( 'nowcrm_db_error', $wpdb->last_error ?: __( '無法寫入聯絡人。', 'now-crm' ) );
		}

		$id = (int) $wpdb->insert_id;

		$this->history()->record(
			$id,
			null,
			$status,
			$source,
			'',
			isset( $data['actor_id'] ) ? absint( $data['actor_id'] ) : null
		);

		if ( empty( $data['defer_event'] ) ) {
			do_action(
				'nowcrm_contact_created',
				$id,
				array(
					'source' => $source,
					'status' => $status,
				)
			);
		}

		return $id;
	}

	/**
	 * Fetch a contact by ID.
	 *
	 * @return array<string, mixed>|null
	 */
	public function get( int $id ): ?array {
		global $wpdb;

		$table = Schema::table( 'contacts' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is trusted.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );

		return $row ? $this->cast_row( $row ) : null;
	}

	/**
	 * Find a contact by email (case-insensitive).
	 *
	 * @return array<string, mixed>|null
	 */
	public function find_by_email( string $email ): ?array {
		global $wpdb;

		$normalized = $this->normalize_email( $email );

		if ( '' === $normalized ) {
			return null;
		}

		$table = Schema::table( 'contacts' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is trusted.
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE email_normalized = %s", $normalized ),
			ARRAY_A
		);

		return $row ? $this->cast_row( $row ) : null;
	}

	/**
	 * Find or create by email.
	 *
	 * @param array<string, mixed> $data Extra fields used when creating.
	 * @return array{contact: array<string, mixed>, created: bool}|WP_Error
	 */
	public function get_or_create( string $email, array $data = array() ) {
		$existing = $this->find_by_email( $email );

		if ( $existing ) {
			return array(
				'contact' => $existing,
				'created' => false,
			);
		}

		$data['email'] = $email;
		$id            = $this->create( $data );

		if ( is_wp_error( $id ) ) {
			$existing = $this->find_by_email( $email );

			if ( $existing ) {
				return array(
					'contact' => $existing,
					'created' => false,
				);
			}

			return $id;
		}

		return array(
			'contact' => $this->get( $id ),
			'created' => true,
		);
	}

	/**
	 * Update profile fields. Status changes must go through set_status().
	 *
	 * @param array<string, mixed> $data email, first_name, last_name, phone, wp_user_id, plus any ProfileFields::keys().
	 * @return bool|WP_Error
	 */
	public function update( int $id, array $data ) {
		global $wpdb;

		$contact = $this->get( $id );

		if ( ! $contact ) {
			return new WP_Error( 'nowcrm_not_found', __( '找不到聯絡人。', 'now-crm' ) );
		}

		$fields  = array();
		$formats = array();

		if ( array_key_exists( 'email', $data ) ) {
			$email = $this->normalize_email( (string) $data['email'] );

			if ( '' === $email || ! is_email( $email ) ) {
				return new WP_Error( 'nowcrm_invalid_email', __( 'Email 格式不正確。', 'now-crm' ) );
			}

			$other = $this->find_by_email( $email );

			if ( $other && (int) $other['id'] !== $id ) {
				return new WP_Error( 'nowcrm_duplicate_email', __( '此 Email 已存在於其他聯絡人。', 'now-crm' ) );
			}

			$fields['email']            = $email;
			$fields['email_normalized'] = $email;
			$formats[]                  = '%s';
			$formats[]                  = '%s';
		}

		foreach ( array( 'first_name', 'last_name', 'phone' ) as $key ) {
			if ( array_key_exists( $key, $data ) ) {
				$fields[ $key ] = sanitize_text_field( (string) $data[ $key ] );
				$formats[]      = '%s';
			}
		}

		$profile = ProfileFields::normalize( $data );

		if ( is_wp_error( $profile ) ) {
			return $profile;
		}

		// Omitted profile keys stay untouched, so partial forms keep data.
		foreach ( $profile as $key => $value ) {
			$fields[ $key ] = $value;
			$formats[]      = '%s';
		}

		if ( array_key_exists( 'wp_user_id', $data ) ) {
			$fields['wp_user_id'] = empty( $data['wp_user_id'] ) ? null : absint( $data['wp_user_id'] );
			$formats[]            = '%d';
		}

		if ( array() === $fields ) {
			return true;
		}

		$fields['updated_at'] = gmdate( 'Y-m-d H:i:s' );
		$formats[]            = '%s';

		$updated = $wpdb->update(
			Schema::table( 'contacts' ),
			$fields,
			array( 'id' => $id ),
			$formats,
			array( '%d' )
		);

		if ( false === $updated ) {
			return new WP_Error( 'nowcrm_db_error', $wpdb->last_error ?: __( '無法更新聯絡人。', 'now-crm' ) );
		}

		if ( $updated > 0 ) {
			do_action(
				'nowcrm_contact_updated',
				$id,
				array(
					'fields' => array_values( array_diff( array_keys( $fields ), array( 'updated_at' ) ) ),
					'source' => 'repository',
				)
			);
		}

		return true;
	}

	/**
	 * Change the status and append a history entry.
	 *
	 * @return bool|WP_Error
	 */
	public function set_status(
		int $id,
		string $status,
		string $source,
		string $note = '',
		?int $actor_id = null
	) {
		global $wpdb;

		$status = sanitize_key( $status );

		if ( ! Status::is_valid( $status ) ) {
			return new WP_Error( 'nowcrm_invalid_status', __( '聯絡人狀態不正確。', 'now-crm' ) );
		}

		$contact = $this->get( $id );

		if ( ! $contact ) {
			return new WP_Error( 'nowcrm_not_found', __( '找不到聯絡人。', 'now-crm' ) );
		}

		$now = gmdate( 'Y-m-d H:i:s' );

		if ( $contact['status'] === $status ) {
			$wpdb->update(
				Schema::table( 'contacts' ),
				array( 'updated_at' => $now ),
				array( 'id' => $id ),
				array( '%s' ),
				array( '%d' )
			);

			return true;
		}

		$updated = $wpdb->update(
			Schema::table( 'contacts' ),
			array(
				'status'            => $status,
				'status_changed_at' => $now,
				'updated_at'        => $now,
			),
			array( 'id' => $id ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);

		if ( false === $updated ) {
			return new WP_Error( 'nowcrm_db_error', $wpdb->last_error ?: __( '無法更新狀態。', 'now-crm' ) );
		}

		$this->history()->record( $id, (string) $contact['status'], $status, $source, $note, $actor_id );

		do_action( 'nowcrm_contact_status_changed', $id, (string) $contact['status'], $status, $source, $actor_id );

		return true;
	}

	/**
	 * Delete a contact and its relations. Refuses when orders are still linked,
	 * because dropping the link would silently corrupt order history.
	 *
	 * @return bool|WP_Error
	 */
	public function delete( int $id ) {
		global $wpdb;

		$contact = $this->get( $id );

		if ( ! $contact ) {
			return new WP_Error( 'nowcrm_not_found', __( '找不到聯絡人。', 'now-crm' ) );
		}

		$links = Schema::table( 'order_links' );
		$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$links} WHERE contact_id = %d", $id ) );

		if ( $count > 0 ) {
			return new WP_Error(
				'nowcrm_has_orders',
				__( '此聯絡人仍連結 WooCommerce 訂單，請先確認訂單歸屬後再刪除。', 'now-crm' )
			);
		}

		$wpdb->delete( Schema::table( 'contact_terms' ), array( 'contact_id' => $id ), array( '%d' ) );
		// Company links are pivots only: drop them and clear any owner
		// reference, but keep every company row and other contacts intact.
		$wpdb->delete( Schema::table( 'company_contacts' ), array( 'contact_id' => $id ), array( '%d' ) );
		$companies_table = Schema::table( 'companies' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is trusted.
		$wpdb->query( $wpdb->prepare( "UPDATE {$companies_table} SET owner_id = NULL, updated_at = %s WHERE owner_id = %d", gmdate( 'Y-m-d H:i:s' ), $id ) );
		( new CustomFieldValues() )->delete_for_contact( $id );
		( new NoteRepository() )->delete_for_contact( $id );
		$this->history()->delete_for_contact( $id );

		return (bool) $wpdb->delete( Schema::table( 'contacts' ), array( 'id' => $id ), array( '%d' ) );
	}

	/**
	 * Search contacts.
	 *
	 * `search` accepts a name, email, phone fragment, or `#<id>`. Words are
	 * AND-ed across the name, email, and phone columns. Sorting is limited to
	 * an allowlist and always breaks ties with the contact ID in the same
	 * direction, so equal names or statuses keep a stable order.
	 *
	 * @param array<string, mixed> $args search, status, term_id, filters, page, per_page, orderby, order.
	 * @return array{items: array<int, array<string, mixed>>, total: int}
	 */
	public function search( array $args = array() ): array {
		global $wpdb;

		$args = array_merge(
			array(
				'search'   => '',
				'status'   => '',
				'term_id'  => 0,
				'filters'  => null,
				'page'     => 1,
				'per_page' => 20,
				'orderby'  => 'id',
				'order'    => 'DESC',
			),
			$args
		);

		$page     = max( 1, absint( $args['page'] ) );
		$per_page = max( 1, min( 150, absint( $args['per_page'] ) ) );

		$columns = array(
			'id'         => 'c.id',
			'email'      => 'c.email',
			'first_name' => 'c.first_name',
			'last_name'  => 'c.last_name',
			'status'     => 'c.status',
			'created_at' => 'c.created_at',
			'updated_at' => 'c.updated_at',
		);

		$orderby = $columns[ (string) $args['orderby'] ] ?? 'c.id';
		$order   = 'ASC' === strtoupper( (string) $args['order'] ) ? 'ASC' : 'DESC';

		// A second key keeps equal names and statuses in a stable order.
		$order_sql = 'c.id' === $orderby
			? "{$orderby} {$order}"
			: "{$orderby} {$order}, c.id {$order}";

		$table = Schema::table( 'contacts' );

		list( $where_sql, $params ) = $this->search_where( $args );

		$count_sql = "SELECT COUNT(*) FROM {$table} c WHERE {$where_sql}";

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders -- table names are trusted, values are placeholders.
		$total = (int) $wpdb->get_var( $params ? $wpdb->prepare( $count_sql, $params ) : $count_sql );

		$sql = "SELECT c.* FROM {$table} c WHERE {$where_sql} ORDER BY {$order_sql} LIMIT %d OFFSET %d";

		$query_params   = $params;
		$query_params[] = $per_page;
		$query_params[] = ( $page - 1 ) * $per_page;

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $query_params ), ARRAY_A );
		// phpcs:enable

		$items = array();

		foreach ( (array) $rows as $row ) {
			$items[] = $this->cast_row( $row );
		}

		$this->attach_terms( $items );
		$this->attach_aggregates( $items );

		return array(
			'items' => $items,
			'total' => $total,
		);
	}

	/**
	 * Matching count and maximum ID for the search filters.
	 *
	 * Count and ceiling come from one query, so a destructive filter-wide run
	 * compares against the same snapshot it bounds.
	 *
	 * @param array<string, mixed> $args search, status, term_id, filters.
	 * @return array{matching: int, max_id: int}
	 */
	public function search_bounds( array $args = array() ): array {
		global $wpdb;

		$table = Schema::table( 'contacts' );

		list( $where_sql, $params ) = $this->search_where( $args );

		$sql = "SELECT COUNT(*) AS matching, COALESCE(MAX(c.id), 0) AS max_id
			FROM {$table} c
			WHERE {$where_sql}";

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders -- table name is trusted, values use placeholders.
		$row = $params
			? $wpdb->get_row( $wpdb->prepare( $sql, $params ), ARRAY_A )
			: $wpdb->get_row( $sql, ARRAY_A );
		// phpcs:enable

		return array(
			'matching' => (int) ( $row['matching'] ?? 0 ),
			'max_id'   => (int) ( $row['max_id'] ?? 0 ),
		);
	}

	/**
	 * IDs matching the search filters after a cursor.
	 *
	 * The keyset cursor keeps a batch from skipping rows when the filter
	 * membership changes between batches.
	 *
	 * @param array<string, mixed> $args     search, status, term_id.
	 * @param int                  $after_id Last processed ID.
	 * @param int                  $max_id   Inclusive ceiling; 0 means no ceiling.
	 * @param int                  $limit    Batch size.
	 * @return int[]
	 */
	public function search_ids( array $args, int $after_id, int $max_id, int $limit ): array {
		global $wpdb;

		$limit = max( 1, min( 500, absint( $limit ) ) );

		$table = Schema::table( 'contacts' );

		list( $where_sql, $params ) = $this->search_where( $args );

		$sql = "SELECT c.id FROM {$table} c WHERE {$where_sql} AND c.id > %d";

		$params[] = max( 0, $after_id );

		if ( $max_id > 0 ) {
			$sql     .= ' AND c.id <= %d';
			$params[] = $max_id;
		}

		$sql     .= ' ORDER BY c.id ASC LIMIT %d';
		$params[] = $limit;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders -- table name is trusted, values use placeholders.
		$ids = $wpdb->get_col( $wpdb->prepare( $sql, $params ) );
		// phpcs:enable

		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Filter the given IDs down to contacts that still exist.
	 *
	 * @param int[] $ids Contact IDs.
	 * @return int[]
	 */
	public function existing_ids( array $ids ): array {
		global $wpdb;

		$ids = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );

		if ( array() === $ids ) {
			return array();
		}

		$table        = Schema::table( 'contacts' );
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders -- table name is trusted, placeholders are generated above.
		$found = $wpdb->get_col(
			$wpdb->prepare( "SELECT id FROM {$table} WHERE id IN ({$placeholders})", $ids )
		);
		// phpcs:enable

		return array_map( 'intval', (array) $found );
	}

	/**
	 * Neighbouring contact IDs in newest-first list order.
	 *
	 * One query returns the closest higher and lower IDs. The detail screen
	 * uses it for previous/next browsing without loading the Contacts list.
	 *
	 * @return array{previous: int, next: int}
	 */
	public function adjacent_ids( int $id ): array {
		global $wpdb;

		if ( $id <= 0 ) {
			return array(
				'previous' => 0,
				'next'     => 0,
			);
		}

		$table = Schema::table( 'contacts' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is trusted.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT MIN(CASE WHEN id > %d THEN id END) AS previous_id, MAX(CASE WHEN id < %d THEN id END) AS next_id FROM {$table}",
				$id,
				$id
			),
			ARRAY_A
		);

		return array(
			'previous' => (int) ( $row['previous_id'] ?? 0 ),
			'next'     => (int) ( $row['next_id'] ?? 0 ),
		);
	}

	/**
	 * WHERE clause for the contact list filters.
	 *
	 * @param array<string, mixed> $args search, status, term_id, filters.
	 * @return array{0: string, 1: array<int, mixed>}
	 */
	private function search_where( array $args ): array {
		global $wpdb;

		$search  = trim( (string) ( $args['search'] ?? '' ) );
		$status  = sanitize_key( (string) ( $args['status'] ?? '' ) );
		$term_id = absint( $args['term_id'] ?? 0 );

		$links = Schema::table( 'contact_terms' );

		$where  = array( '1=1' );
		$params = array();

		if ( '' !== $search ) {
			// `#123` is an exact contact lookup, as in the reference list.
			if ( preg_match( '/^#(\d{1,15})$/', $search, $match ) ) {
				$where[]  = 'c.id = %d';
				$params[] = (int) $match[1];
			} else {
				$words = preg_split( '/\s+/u', $search, -1, PREG_SPLIT_NO_EMPTY );
				$words = array_slice( is_array( $words ) ? $words : array( $search ), 0, 10 );

				foreach ( $words as $word ) {
					$like    = '%' . $wpdb->esc_like( (string) $word ) . '%';
					$where[] = '(c.email LIKE %s OR c.first_name LIKE %s OR c.last_name LIKE %s OR c.phone LIKE %s)';

					array_push( $params, $like, $like, $like, $like );
				}
			}
		}

		if ( '' !== $status && Status::is_valid( $status ) ) {
			$where[]  = 'c.status = %s';
			$params[] = $status;
		}

		if ( $term_id > 0 ) {
			$where[]  = "EXISTS (SELECT 1 FROM {$links} ct WHERE ct.contact_id = c.id AND ct.term_id = %d)";
			$params[] = $term_id;
		}

		$advanced = $args['filters'] ?? null;

		if ( null !== $advanced ) {
			if ( $advanced instanceof WP_Error ) {
				// A rejected plan must never widen a list, bulk run, or export.
				$where[] = '1=0';
			} else {
				$compiled = AdvancedFilters::compile( $advanced );
				$where[]  = $compiled['sql'];

				foreach ( $compiled['params'] as $param ) {
					$params[] = $param;
				}
			}
		}

		return array( implode( ' AND ', $where ), $params );
	}

	/**
	 * Counts per status.
	 *
	 * @return array<string, int>
	 */
	public function count_by_status(): array {
		global $wpdb;

		$table = Schema::table( 'contacts' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is trusted.
		$rows = $wpdb->get_results( "SELECT status, COUNT(*) AS total FROM {$table} GROUP BY status", ARRAY_A );

		$counts = array_fill_keys( Status::all(), 0 );

		foreach ( (array) $rows as $row ) {
			$counts[ (string) $row['status'] ] = (int) $row['total'];
		}

		return $counts;
	}

	/**
	 * Order aggregates derived from the order link table. Never stored, so a
	 * resync can never double count.
	 *
	 * @return array{order_count: int, total_spent: float, first_order_at: ?string, last_order_at: ?string, currency: string}
	 */
	public function aggregate( int $contact_id ): array {
		global $wpdb;

		$table = Schema::table( 'order_links' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is trusted.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COUNT(*) AS order_count,
					COALESCE(SUM(net_total), 0) AS total_spent,
					MIN(date_created) AS first_order_at,
					MAX(date_created) AS last_order_at,
					MAX(currency) AS currency
				FROM {$table}
				WHERE contact_id = %d AND is_counted = 1",
				$contact_id
			),
			ARRAY_A
		);

		return array(
			'order_count'    => (int) ( $row['order_count'] ?? 0 ),
			'total_spent'    => (float) ( $row['total_spent'] ?? 0 ),
			'first_order_at' => $row['first_order_at'] ?: null,
			'last_order_at'  => $row['last_order_at'] ?: null,
			'currency'       => (string) ( $row['currency'] ?? '' ),
		);
	}

	/**
	 * Order aggregates for a batch of contacts with a single query.
	 *
	 * Contacts without counted orders get zeroed defaults, so callers never
	 * have to fall back to one query per row.
	 *
	 * @param int[] $contact_ids Contact IDs.
	 * @return array<int, array{order_count: int, total_spent: float, first_order_at: ?string, last_order_at: ?string, currency: string}>
	 */
	public function aggregate_many( array $contact_ids ): array {
		global $wpdb;

		$ids = array();

		foreach ( $contact_ids as $contact_id ) {
			$contact_id = (int) $contact_id;

			if ( $contact_id > 0 ) {
				$ids[ $contact_id ] = $contact_id;
			}
		}

		if ( array() === $ids ) {
			return array();
		}

		$ids   = array_values( $ids );
		$table = Schema::table( 'order_links' );

		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders -- table name is trusted, placeholders are generated above.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT contact_id,
					COUNT(*) AS order_count,
					COALESCE(SUM(net_total), 0) AS total_spent,
					MIN(date_created) AS first_order_at,
					MAX(date_created) AS last_order_at,
					MAX(currency) AS currency
				FROM {$table}
				WHERE contact_id IN ({$placeholders}) AND is_counted = 1
				GROUP BY contact_id",
				$ids
			),
			ARRAY_A
		);
		// phpcs:enable

		$aggregates = array();

		foreach ( $ids as $contact_id ) {
			$aggregates[ $contact_id ] = array(
				'order_count'    => 0,
				'total_spent'    => 0.0,
				'first_order_at' => null,
				'last_order_at'  => null,
				'currency'       => '',
			);
		}

		foreach ( (array) $rows as $row ) {
			$aggregates[ (int) $row['contact_id'] ] = array(
				'order_count'    => (int) $row['order_count'],
				'total_spent'    => (float) $row['total_spent'],
				'first_order_at' => $row['first_order_at'] ?: null,
				'last_order_at'  => $row['last_order_at'] ?: null,
				'currency'       => (string) ( $row['currency'] ?? '' ),
			);
		}

		return $aggregates;
	}

	/**
	 * Attach order aggregates to a page of contacts with one extra query.
	 *
	 * @param array<int, array<string, mixed>> $items Contact rows, modified by reference.
	 */
	private function attach_aggregates( array &$items ): void {
		if ( array() === $items ) {
			return;
		}

		$ids        = array_map( static fn( $row ) => (int) $row['id'], $items );
		$aggregates = $this->aggregate_many( $ids );

		foreach ( $items as $index => $item ) {
			$items[ $index ]['aggregate'] = $aggregates[ (int) $item['id'] ] ?? $this->aggregate( (int) $item['id'] );
		}
	}

	/**
	 * Attach term summaries to a page of contacts with one extra query.
	 *
	 * @param array<int, array<string, mixed>> $items Contact rows, modified by reference.
	 */
	private function attach_terms( array &$items ): void {
		global $wpdb;

		if ( array() === $items ) {
			return;
		}

		$ids = array_map( static fn( $row ) => (int) $row['id'], $items );

		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$links        = Schema::table( 'contact_terms' );
		$terms        = Schema::table( 'terms' );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders -- placeholders generated above.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ct.contact_id, t.id, t.type, t.name, t.slug
				FROM {$links} ct
				INNER JOIN {$terms} t ON t.id = ct.term_id
				WHERE ct.contact_id IN ({$placeholders})
				ORDER BY t.type ASC, t.name ASC",
				$ids
			),
			ARRAY_A
		);
		// phpcs:enable

		$map = array();

		foreach ( (array) $rows as $row ) {
			$map[ (int) $row['contact_id'] ][] = array(
				'id'   => (int) $row['id'],
				'type' => (string) $row['type'],
				'name' => (string) $row['name'],
				'slug' => (string) $row['slug'],
			);
		}

		foreach ( $items as &$item ) {
			$item['terms'] = $map[ (int) $item['id'] ] ?? array();
		}
		unset( $item );
	}

	/**
	 * Cast numeric columns to native types.
	 *
	 * @param array<string, mixed> $row Raw row.
	 * @return array<string, mixed>
	 */
	private function cast_row( array $row ): array {
		$row['id']         = (int) $row['id'];
		$row['wp_user_id'] = null === $row['wp_user_id'] ? null : (int) $row['wp_user_id'];

		return $row;
	}
}
