<?php
/**
 * Company persistence and company-contact relationships.
 *
 * Companies are standalone rows. The link table only connects companies to
 * existing contacts; deleting a company removes links and never contacts.
 *
 * @package NowCRM
 */

namespace NowCRM\Companies;

use NowCRM\Support\Schema;
use WP_Error;

defined( 'ABSPATH' ) || exit;

final class CompanyRepository {

	/**
	 * Maximum link rows changed by one attach or detach call.
	 */
	public const LINK_LIMIT = 200;

	/**
	 * Table holding company rows.
	 */
	private function companies_table(): string {
		return Schema::table( 'companies' );
	}

	/**
	 * Table holding company-contact links.
	 */
	private function links_table(): string {
		return Schema::table( 'company_contacts' );
	}

	/**
	 * Create a company.
	 *
	 * @param array<string, mixed> $data Company fields.
	 * @return int|WP_Error Company ID.
	 */
	public function create( array $data ) {
		$row = $this->sanitize_row( $data );

		if ( is_wp_error( $row ) ) {
			return $row;
		}

		if ( '' === $row['name'] ) {
			return new WP_Error( 'nowcrm_invalid_company_name', __( '請輸入公司名稱。', 'now-crm' ) );
		}

		if ( $this->find_by_name( $row['name'] ) ) {
			return new WP_Error( 'nowcrm_duplicate_company', __( '此公司名稱已存在。', 'now-crm' ) );
		}

		global $wpdb;

		$now = gmdate( 'Y-m-d H:i:s' );

		// Fixed column order; a null owner is omitted so the column keeps NULL.
		$order = array(
			'name'             => '%s',
			'owner_id'         => '%d',
			'industry'         => '%s',
			'type'             => '%s',
			'email'            => '%s',
			'phone'            => '%s',
			'address_line_1'   => '%s',
			'address_line_2'   => '%s',
			'postal_code'      => '%s',
			'city'             => '%s',
			'state'            => '%s',
			'country'          => '%s',
			'employees_number' => '%d',
			'description'      => '%s',
			'logo'             => '%s',
			'website'          => '%s',
			'linkedin_url'     => '%s',
			'facebook_url'     => '%s',
			'twitter_url'      => '%s',
		);

		$insert  = array();
		$formats = array();

		foreach ( $order as $key => $format ) {
			if ( null === $row[ $key ] ) {
				continue;
			}

			$insert[ $key ] = $row[ $key ];
			$formats[]      = $format;
		}

		$insert['created_at'] = $now;
		$insert['updated_at'] = $now;
		$formats[]            = '%s';
		$formats[]            = '%s';

		$inserted = $wpdb->insert( $this->companies_table(), $insert, $formats );

		if ( ! $inserted ) {
			if ( $this->find_by_name( $row['name'] ) ) {
				return new WP_Error( 'nowcrm_duplicate_company', __( '此公司名稱已存在。', 'now-crm' ) );
			}

			return new WP_Error( 'nowcrm_db_error', $wpdb->last_error ?: __( '無法寫入公司。', 'now-crm' ) );
		}

		$id = (int) $wpdb->insert_id;

		if ( null !== $row['owner_id'] ) {
			$this->attach_contacts( $id, array( $row['owner_id'] ) );
		}

		return $id;
	}
	public function get( int $id ): ?array {
		global $wpdb;

		if ( $id <= 0 ) {
			return null;
		}

		$table = $this->companies_table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is trusted.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );

		return $row ? $this->cast_row( $row ) : null;
	}

	/**
	 * Find a company by exact name.
	 *
	 * @return array<string, mixed>|null
	 */
	public function find_by_name( string $name ): ?array {
		global $wpdb;

		$name = trim( $name );

		if ( '' === $name ) {
			return null;
		}

		$table = $this->companies_table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is trusted.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE name = %s", $name ), ARRAY_A );

		return $row ? $this->cast_row( $row ) : null;
	}

	/**
	 * Update company fields. Only keys present in $data change.
	 *
	 * @param array<string, mixed> $data Company fields.
	 * @return bool|WP_Error
	 */
	public function update( int $id, array $data ) {
		$company = $this->get( $id );

		if ( ! $company ) {
			return new WP_Error( 'nowcrm_not_found', __( '找不到此公司。', 'now-crm' ) );
		}

		$name_changed  = array_key_exists( 'name', $data );
		$owner_changed = array_key_exists( 'owner_id', $data );

		$row = $this->sanitize_row( $data, true );

		if ( is_wp_error( $row ) ) {
			return $row;
		}

		if ( $name_changed && '' === ( $row['name'] ?? '' ) ) {
			return new WP_Error( 'nowcrm_invalid_company_name', __( '請輸入公司名稱。', 'now-crm' ) );
		}

		if ( $name_changed && '' !== $row['name'] && $row['name'] !== $company['name'] ) {
			$other = $this->find_by_name( $row['name'] );

			if ( $other && (int) $other['id'] !== $id ) {
				return new WP_Error( 'nowcrm_duplicate_company', __( '此公司名稱已存在。', 'now-crm' ) );
			}
		}

		global $wpdb;

		$now = gmdate( 'Y-m-d H:i:s' );

		// $wpdb cannot store NULL through a format, so a cleared owner gets its own statement.
		$clear_owner = array_key_exists( 'owner_id', $row ) && null === $row['owner_id'];

		if ( $clear_owner ) {
			unset( $row['owner_id'] );
		}

		if ( array() !== $row ) {
			$fields  = $row;
			$formats = array();

			foreach ( $row as $value ) {
				$formats[] = is_int( $value ) ? '%d' : '%s';
			}

			$fields['updated_at'] = $now;
			$formats[]            = '%s';

			$updated = $wpdb->update(
				$this->companies_table(),
				$fields,
				array( 'id' => $id ),
				$formats,
				array( '%d' )
			);

			if ( false === $updated ) {
				return new WP_Error( 'nowcrm_db_error', $wpdb->last_error ?: __( '無法更新公司。', 'now-crm' ) );
			}
		}

		if ( $clear_owner ) {
			$table = $this->companies_table();

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is trusted.
			$cleared = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET owner_id = NULL, updated_at = %s WHERE id = %d", $now, $id ) );

			if ( false === $cleared ) {
				return new WP_Error( 'nowcrm_db_error', $wpdb->last_error ?: __( '無法更新公司。', 'now-crm' ) );
			}
		}

		if ( $owner_changed && null !== ( $row['owner_id'] ?? null ) ) {
			$this->attach_contacts( $id, array( (int) $row['owner_id'] ) );
		}

		return true;
	}

	/**
	 * Delete a company and its contact links. Linked contacts stay intact.
	 *
	 * @return bool|WP_Error
	 */
	public function delete( int $id ) {
		if ( ! $this->get( $id ) ) {
			return new WP_Error( 'nowcrm_not_found', __( '找不到此公司。', 'now-crm' ) );
		}

		global $wpdb;

		$wpdb->delete( $this->links_table(), array( 'company_id' => $id ), array( '%d' ) );

		return (bool) $wpdb->delete( $this->companies_table(), array( 'id' => $id ), array( '%d' ) );
	}

	/**
	 * Search companies with filters, sorting, and pagination.
	 *
	 * @param array<string, mixed> $args search, industry, type, owner_id, page, per_page, orderby, order.
	 * @return array{items: array<int, array<string, mixed>>, total: int}
	 */
	public function search( array $args = array() ): array {
		global $wpdb;

		$args = array_merge(
			array(
				'search'   => '',
				'industry' => '',
				'type'     => '',
				'owner_id' => 0,
				'page'     => 1,
				'per_page' => 20,
				'orderby'  => 'id',
				'order'    => 'DESC',
			),
			$args
		);

		$search   = trim( (string) $args['search'] );
		$industry = sanitize_text_field( (string) $args['industry'] );
		$type     = sanitize_text_field( (string) $args['type'] );
		$owner_id = absint( $args['owner_id'] );
		$page     = max( 1, absint( $args['page'] ) );
		$per_page = max( 1, min( 100, absint( $args['per_page'] ) ) );

		$orderby = CompanyFields::is_valid_orderby( (string) $args['orderby'] )
			? (string) $args['orderby']
			: 'id';
		$order   = 'ASC' === strtoupper( (string) $args['order'] ) ? 'ASC' : 'DESC';

		$companies = $this->companies_table();
		$links     = $this->links_table();

		$where  = array( '1=1' );
		$params = array();

		if ( '' !== $search ) {
			$like    = '%' . $wpdb->esc_like( $search ) . '%';
			$where[] = '(c.name LIKE %s OR c.email LIKE %s OR c.phone LIKE %s OR c.description LIKE %s)';

			array_push( $params, $like, $like, $like, $like );
		}

		if ( '' !== $industry ) {
			$where[]  = 'c.industry = %s';
			$params[] = $industry;
		}

		if ( '' !== $type ) {
			$where[]  = 'c.type = %s';
			$params[] = $type;
		}

		if ( $owner_id > 0 ) {
			$where[]  = 'c.owner_id = %d';
			$params[] = $owner_id;
		}

		$where_sql = implode( ' AND ', $where );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders -- table names are trusted, values are placeholders.
		$total = (int) $wpdb->get_var(
			$params
				? $wpdb->prepare( "SELECT COUNT(*) FROM {$companies} c WHERE {$where_sql}", $params )
				: "SELECT COUNT(*) FROM {$companies} c WHERE {$where_sql}"
		);

		$order_sql = CompanyFields::order_column( $orderby ) . ' ' . $order . ', c.id ' . $order;

		$sql = "SELECT c.*, COALESCE(cc.contact_count, 0) AS contact_count
			FROM {$companies} c
			LEFT JOIN (
				SELECT company_id, COUNT(*) AS contact_count FROM {$links} GROUP BY company_id
			) cc ON cc.company_id = c.id
			WHERE {$where_sql} ORDER BY {$order_sql} LIMIT %d OFFSET %d";

		$query_params   = $params;
		$query_params[] = $per_page;
		$query_params[] = ( $page - 1 ) * $per_page;

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $query_params ), ARRAY_A );
		// phpcs:enable

		$items = array();

		foreach ( (array) $rows as $row ) {
			$item                  = $this->cast_row( $row );
			$item['contact_count'] = (int) ( $row['contact_count'] ?? 0 );
			$items[]               = $item;
		}

		$this->attach_owners( $items );

		return array(
			'items' => $items,
			'total' => $total,
		);
	}

	/**
	 * Distinct non-empty industries in use, for filter dropdowns.
	 *
	 * @return string[]
	 */
	public function distinct_industries( int $limit = 100 ): array {
		global $wpdb;

		$limit = max( 1, min( 200, $limit ) );
		$table = $this->companies_table();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders -- table name is trusted, limit is clamped.
		$rows = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT industry FROM {$table} WHERE industry <> '' ORDER BY industry ASC LIMIT %d", $limit ) );
		// phpcs:enable

		return array_values( array_map( 'strval', (array) $rows ) );
	}

	/**
	 * List contacts attached to one company.
	 *
	 * @param array<string, mixed> $args search, page, per_page, orderby, order.
	 * @return array{items: array<int, array<string, mixed>>, total: int}|WP_Error
	 */
	public function contacts( int $company_id, array $args = array() ) {
		global $wpdb;

		if ( ! $this->get( $company_id ) ) {
			return new WP_Error( 'nowcrm_not_found', __( '找不到此公司。', 'now-crm' ) );
		}

		$args = array_merge(
			array(
				'search'   => '',
				'page'     => 1,
				'per_page' => 20,
				'orderby'  => 'id',
				'order'    => 'DESC',
			),
			$args
		);

		$search   = trim( (string) $args['search'] );
		$page     = max( 1, absint( $args['page'] ) );
		$per_page = max( 1, min( 100, absint( $args['per_page'] ) ) );

		$columns = array(
			'id'         => 'c.id',
			'email'      => 'c.email',
			'created_at' => 'c.created_at',
		);

		$orderby = $columns[ (string) $args['orderby'] ] ?? 'c.id';
		$order   = 'ASC' === strtoupper( (string) $args['order'] ) ? 'ASC' : 'DESC';

		$links    = $this->links_table();
		$contacts = Schema::table( 'contacts' );

		$where  = array( 'cc.company_id = %d' );
		$params = array( $company_id );

		if ( '' !== $search ) {
			$like    = '%' . $wpdb->esc_like( $search ) . '%';
			$where[] = '(c.email LIKE %s OR c.first_name LIKE %s OR c.last_name LIKE %s)';

			array_push( $params, $like, $like, $like );
		}

		$where_sql = implode( ' AND ', $where );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders -- table names are trusted, values are placeholders.
		$total = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$links} cc INNER JOIN {$contacts} c ON c.id = cc.contact_id WHERE {$where_sql}",
				$params
			)
		);

		$sql = "SELECT c.*, cc.created_at AS attached_at
			FROM {$links} cc
			INNER JOIN {$contacts} c ON c.id = cc.contact_id
			WHERE {$where_sql} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";

		$query_params   = $params;
		$query_params[] = $per_page;
		$query_params[] = ( $page - 1 ) * $per_page;

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $query_params ), ARRAY_A );
		// phpcs:enable

		$items = array();

		foreach ( (array) $rows as $row ) {
			$item                 = $row;
			$item['id']           = (int) $row['id'];
			$item['wp_user_id']   = null === $row['wp_user_id'] ? null : (int) $row['wp_user_id'];
			$item['attached_at']  = $row['attached_at'] ?? null;
			$items[]              = $item;
		}

		return array(
			'items' => $items,
			'total' => $total,
		);
	}

	/**
	 * Contacts not yet attached to a company, for the attach picker.
	 *
	 * @return array<int, array<string, mixed>>|WP_Error
	 */
	public function available_contacts( int $company_id, string $search = '', int $limit = 20 ) {
		global $wpdb;

		if ( ! $this->get( $company_id ) ) {
			return new WP_Error( 'nowcrm_not_found', __( '找不到此公司。', 'now-crm' ) );
		}

		$limit  = max( 1, min( 100, $limit ) );
		$search = trim( $search );

		$links    = $this->links_table();
		$contacts = Schema::table( 'contacts' );

		$where  = array( 'NOT EXISTS (SELECT 1 FROM ' . $links . ' cc WHERE cc.company_id = %d AND cc.contact_id = c.id)' );
		$params = array( $company_id );

		if ( '' !== $search ) {
			$like    = '%' . $wpdb->esc_like( $search ) . '%';
			$where[] = '(c.email LIKE %s OR c.first_name LIKE %s OR c.last_name LIKE %s)';

			array_push( $params, $like, $like, $like );
		}

		$where_sql = implode( ' AND ', $where );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders -- table names are trusted, values are placeholders.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT c.id, c.email, c.first_name, c.last_name
				FROM {$contacts} c
				WHERE {$where_sql}
				ORDER BY c.id DESC
				LIMIT %d",
				array_merge( $params, array( $limit ) )
			),
			ARRAY_A
		);
		// phpcs:enable

		$items = array();

		foreach ( (array) $rows as $row ) {
			$items[] = array(
				'id'         => (int) $row['id'],
				'email'      => (string) $row['email'],
				'first_name' => (string) ( $row['first_name'] ?? '' ),
				'last_name'  => (string) ( $row['last_name'] ?? '' ),
			);
		}

		return $items;
	}

	/**
	 * List companies linked to one contact, for the contact detail panel.
	 *
	 * @param array<string, mixed> $args search, page, per_page.
	 * @return array{items: array<int, array<string, mixed>>, total: int}|WP_Error
	 */
	public function companies_for_contact( int $contact_id, array $args = array() ) {
		global $wpdb;

		if ( ! $this->contact_exists( $contact_id ) ) {
			return new WP_Error( 'nowcrm_not_found', __( '找不到此聯絡人。', 'now-crm' ) );
		}

		$args = array_merge(
			array(
				'search'   => '',
				'page'     => 1,
				'per_page' => 20,
			),
			$args
		);

		$search   = trim( (string) $args['search'] );
		$page     = max( 1, absint( $args['page'] ) );
		$per_page = max( 1, min( 100, absint( $args['per_page'] ) ) );

		$links     = $this->links_table();
		$companies = $this->companies_table();

		$where  = array( 'cc.contact_id = %d' );
		$params = array( $contact_id );

		if ( '' !== $search ) {
			$like    = '%' . $wpdb->esc_like( $search ) . '%';
			$where[] = '(c.name LIKE %s OR c.email LIKE %s)';

			array_push( $params, $like, $like );
		}

		$where_sql = implode( ' AND ', $where );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders -- table names are trusted, values are placeholders.
		$total = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$links} cc INNER JOIN {$companies} c ON c.id = cc.company_id WHERE {$where_sql}",
				$params
			)
		);

		$sql = "SELECT c.*, cc.created_at AS attached_at
			FROM {$links} cc
			INNER JOIN {$companies} c ON c.id = cc.company_id
			WHERE {$where_sql} ORDER BY c.name ASC LIMIT %d OFFSET %d";

		$query_params   = $params;
		$query_params[] = $per_page;
		$query_params[] = ( $page - 1 ) * $per_page;

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $query_params ), ARRAY_A );
		// phpcs:enable

		$items = array();

		foreach ( (array) $rows as $row ) {
			$item                = $this->cast_row( $row );
			$item['attached_at'] = $row['attached_at'] ?? null;
			$items[]             = $item;
		}

		$this->attach_owners( $items );

		return array(
			'items' => $items,
			'total' => $total,
		);
	}

	/**
	 * Companies not yet linked to a contact, for the contact-side attach picker.
	 *
	 * @return array<int, array<string, mixed>>|WP_Error
	 */
	public function available_companies( int $contact_id, string $search = '', int $limit = 20 ) {
		global $wpdb;

		if ( ! $this->contact_exists( $contact_id ) ) {
			return new WP_Error( 'nowcrm_not_found', __( '找不到此聯絡人。', 'now-crm' ) );
		}

		$limit  = max( 1, min( 100, $limit ) );
		$search = trim( $search );

		$links     = $this->links_table();
		$companies = $this->companies_table();

		$where  = array( 'NOT EXISTS (SELECT 1 FROM ' . $links . ' cc WHERE cc.contact_id = %d AND cc.company_id = c.id)' );
		$params = array( $contact_id );

		if ( '' !== $search ) {
			$like    = '%' . $wpdb->esc_like( $search ) . '%';
			$where[] = '(c.name LIKE %s OR c.email LIKE %s)';

			array_push( $params, $like, $like );
		}

		$where_sql = implode( ' AND ', $where );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders -- table names are trusted, values are placeholders.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT c.id, c.name, c.industry, c.type
				FROM {$companies} c
				WHERE {$where_sql}
				ORDER BY c.name ASC
				LIMIT %d",
				array_merge( $params, array( $limit ) )
			),
			ARRAY_A
		);
		// phpcs:enable

		$items = array();

		foreach ( (array) $rows as $row ) {
			$items[] = array(
				'id'       => (int) $row['id'],
				'name'     => (string) $row['name'],
				'industry' => (string) ( $row['industry'] ?? '' ),
				'type'     => (string) ( $row['type'] ?? '' ),
			);
		}

		return $items;
	}

	/**
	 * Attach existing contacts to a company. Re-attaching is a no-op.
	 *
	 * @param int[] $contact_ids Contact IDs.
	 * @return int|WP_Error Number of new links.
	 */
	public function attach_contacts( int $company_id, array $contact_ids ) {
		if ( ! $this->get( $company_id ) ) {
			return new WP_Error( 'nowcrm_not_found', __( '找不到此公司。', 'now-crm' ) );
		}

		$ids = $this->normalize_ids( $contact_ids );

		if ( array() === $ids ) {
			return new WP_Error( 'nowcrm_invalid_contact', __( '請選擇要連結的聯絡人。', 'now-crm' ) );
		}

		if ( ! $this->contacts_exist( $ids ) ) {
			return new WP_Error( 'nowcrm_invalid_contact', __( '部分聯絡人不存在，請重新整理後再試。', 'now-crm' ) );
		}

		global $wpdb;

		$now          = gmdate( 'Y-m-d H:i:s' );
		$links        = $this->links_table();
		$placeholders = array();
		$params       = array();

		foreach ( $ids as $contact_id ) {
			$placeholders[] = '(%d, %d, %s)';
			array_push( $params, $company_id, $contact_id, $now );
		}

		$values_sql = implode( ',', $placeholders );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders -- table name and placeholders are generated above.
		$result = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$links} (company_id, contact_id, created_at) VALUES {$values_sql}", $params ) );
		// phpcs:enable

		if ( false === $result ) {
			return new WP_Error( 'nowcrm_db_error', $wpdb->last_error ?: __( '無法連結聯絡人。', 'now-crm' ) );
		}

		return (int) $result;
	}

	/**
	 * Detach contacts from a company. Contacts stay intact. Detaching the
	 * owner also clears the owner field, matching the reference behavior.
	 *
	 * @param int[] $contact_ids Contact IDs.
	 * @return int|WP_Error Number of removed links.
	 */
	public function detach_contacts( int $company_id, array $contact_ids ) {
		$company = $this->get( $company_id );

		if ( ! $company ) {
			return new WP_Error( 'nowcrm_not_found', __( '找不到此公司。', 'now-crm' ) );
		}

		$ids = $this->normalize_ids( $contact_ids );

		if ( array() === $ids ) {
			return new WP_Error( 'nowcrm_invalid_contact', __( '請選擇要移除的聯絡人。', 'now-crm' ) );
		}

		global $wpdb;

		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$links        = $this->links_table();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders -- table name is trusted, placeholders are generated above.
		$removed = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$links} WHERE company_id = %d AND contact_id IN ({$placeholders})",
				array_merge( array( $company_id ), $ids )
			)
		);
		// phpcs:enable

		if ( false === $removed ) {
			return new WP_Error( 'nowcrm_db_error', $wpdb->last_error ?: __( '無法移除聯絡人連結。', 'now-crm' ) );
		}

		if ( null !== $company['owner_id'] && in_array( (int) $company['owner_id'], $ids, true ) ) {
			$table = $this->companies_table();

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is trusted.
			$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET owner_id = NULL, updated_at = %s WHERE id = %d", gmdate( 'Y-m-d H:i:s' ), $company_id ) );
		}

		return (int) $removed;
	}

	/**
	 * Set or clear the company owner. The owner must be an existing contact;
	 * setting an owner also links that contact to the company.
	 *
	 * @return bool|WP_Error
	 */
	public function set_owner( int $company_id, ?int $owner_id ) {
		if ( ! $this->get( $company_id ) ) {
			return new WP_Error( 'nowcrm_not_found', __( '找不到此公司。', 'now-crm' ) );
		}

		if ( null !== $owner_id ) {
			if ( $owner_id <= 0 || ! $this->contact_exists( $owner_id ) ) {
				return new WP_Error( 'nowcrm_invalid_owner', __( '負責人必須是已存在的聯絡人。', 'now-crm' ) );
			}
		}

		global $wpdb;

		$now = gmdate( 'Y-m-d H:i:s' );

		if ( null === $owner_id ) {
			$table = $this->companies_table();

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is trusted.
			$updated = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET owner_id = NULL, updated_at = %s WHERE id = %d", $now, $company_id ) );
		} else {
			$updated = $wpdb->update(
				$this->companies_table(),
				array(
					'owner_id'   => $owner_id,
					'updated_at' => $now,
				),
				array( 'id' => $company_id ),
				array( '%d', '%s' ),
				array( '%d' )
			);
		}

		if ( false === $updated ) {
			return new WP_Error( 'nowcrm_db_error', $wpdb->last_error ?: __( '無法設定負責人。', 'now-crm' ) );
		}

		if ( null !== $owner_id ) {
			$this->attach_contacts( $company_id, array( $owner_id ) );
		}

		return true;
	}

	/**
	 * Sanitize request data down to known columns.
	 *
	 * @param array<string, mixed> $data Raw input.
	 * @return array<string, mixed>|WP_Error
	 */
	private function sanitize_row( array $data, bool $partial = false ) {
		$row = array();

		$take = static function ( string $key ) use ( $data, $partial ): bool {
			return ! $partial || array_key_exists( $key, $data );
		};

		if ( $take( 'name' ) ) {
			$row['name'] = sanitize_text_field( (string) ( $data['name'] ?? '' ) );
		}

		if ( $take( 'owner_id' ) ) {
			$raw = $data['owner_id'] ?? null;

			if ( null === $raw || '' === $raw ) {
				$row['owner_id'] = null;
			} else {
				$owner_id = absint( $raw );

				if ( $owner_id <= 0 || ! $this->contact_exists( $owner_id ) ) {
					return new WP_Error( 'nowcrm_invalid_owner', __( '負責人必須是已存在的聯絡人。', 'now-crm' ) );
				}

				$row['owner_id'] = $owner_id;
			}
		}

		foreach ( array( 'industry', 'type', 'phone' ) as $key ) {
			if ( $take( $key ) ) {
				$row[ $key ] = sanitize_text_field( (string) ( $data[ $key ] ?? '' ) );
			}
		}

		if ( $take( 'type' ) && ! CompanyFields::is_valid_type( (string) $row['type'] ) ) {
			return new WP_Error( 'nowcrm_invalid_company_type', __( '公司類型不正確。', 'now-crm' ) );
		}

		if ( $take( 'email' ) ) {
			$email = sanitize_email( (string) ( $data['email'] ?? '' ) );

			if ( '' !== $email && ! is_email( $email ) ) {
				return new WP_Error( 'nowcrm_invalid_email', __( 'Email 格式不正確。', 'now-crm' ) );
			}

			$row['email'] = $email;
		}

		foreach ( array( 'address_line_1', 'address_line_2', 'postal_code', 'city', 'state', 'country', 'description' ) as $key ) {
			if ( $take( $key ) ) {
				$row[ $key ] = 'description' === $key
					? sanitize_textarea_field( (string) ( $data[ $key ] ?? '' ) )
					: sanitize_text_field( (string) ( $data[ $key ] ?? '' ) );
			}
		}

		if ( $take( 'employees_number' ) ) {
			$row['employees_number'] = absint( $data['employees_number'] ?? 0 );
		}

		foreach ( array( 'logo', 'website', 'linkedin_url', 'facebook_url', 'twitter_url' ) as $key ) {
			if ( ! $take( $key ) ) {
				continue;
			}

			$url = CompanyFields::sanitize_url( (string) ( $data[ $key ] ?? '' ) );

			if ( '' === $url && '' !== trim( (string) ( $data[ $key ] ?? '' ) ) ) {
				return new WP_Error( 'nowcrm_invalid_url', __( '網址格式不正確，請檢查後再試。', 'now-crm' ) );
			}

			$row[ $key ] = $url;
		}

		return $row;
	}

	/**
	 * Normalize ID lists: positive unique integers, bounded per call.
	 *
	 * @param mixed[] $ids Raw IDs.
	 * @return int[]
	 */
	private function normalize_ids( array $ids ): array {
		$clean = array();

		foreach ( $ids as $id ) {
			$id = absint( $id );

			if ( $id > 0 ) {
				$clean[ $id ] = $id;
			}
		}

		return array_slice( array_values( $clean ), 0, self::LINK_LIMIT );
	}

	/**
	 * Whether every given ID exists in the contacts table.
	 *
	 * @param int[] $ids Contact IDs.
	 */
	private function contacts_exist( array $ids ): bool {
		global $wpdb;

		if ( array() === $ids ) {
			return false;
		}

		$contacts     = Schema::table( 'contacts' );
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders -- table name is trusted, placeholders are generated above.
		$found = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$contacts} WHERE id IN ({$placeholders})", $ids ) );
		// phpcs:enable

		return $found === count( $ids );
	}

	/**
	 * Whether one contact exists.
	 */
	private function contact_exists( int $contact_id ): bool {
		return $this->contacts_exist( array( $contact_id ) );
	}

	/**
	 * Attach owner display data to a page of companies with one extra query.
	 *
	 * @param array<int, array<string, mixed>> $items Company rows, modified by reference.
	 */
	private function attach_owners( array &$items ): void {
		global $wpdb;

		$ids = array();

		foreach ( $items as $item ) {
			if ( null !== ( $item['owner_id'] ?? null ) ) {
				$ids[ (int) $item['owner_id'] ] = (int) $item['owner_id'];
			}
		}

		if ( array() === $ids ) {
			foreach ( $items as $index => $item ) {
				$items[ $index ]['owner'] = null;
			}

			return;
		}

		$ids          = array_values( $ids );
		$contacts     = Schema::table( 'contacts' );
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders -- placeholders generated above.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, email, first_name, last_name FROM {$contacts} WHERE id IN ({$placeholders})",
				$ids
			),
			ARRAY_A
		);
		// phpcs:enable

		$map = array();

		foreach ( (array) $rows as $row ) {
			$map[ (int) $row['id'] ] = array(
				'id'         => (int) $row['id'],
				'email'      => (string) $row['email'],
				'first_name' => (string) ( $row['first_name'] ?? '' ),
				'last_name'  => (string) ( $row['last_name'] ?? '' ),
			);
		}

		foreach ( $items as $index => $item ) {
			$owner_id                = null === ( $item['owner_id'] ?? null ) ? null : (int) $item['owner_id'];
			$items[ $index ]['owner'] = null !== $owner_id && isset( $map[ $owner_id ] ) ? $map[ $owner_id ] : null;
		}
		unset( $items );
	}

	/**
	 * Cast numeric columns to native types.
	 *
	 * @param array<string, mixed> $row Raw row.
	 * @return array<string, mixed>
	 */
	private function cast_row( array $row ): array {
		$row['id']               = (int) $row['id'];
		$row['owner_id']         = null === ( $row['owner_id'] ?? null ) ? null : (int) $row['owner_id'];
		$row['employees_number'] = (int) ( $row['employees_number'] ?? 0 );

		return $row;
	}
}
