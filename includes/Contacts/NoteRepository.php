<?php
/**
 * Contact note persistence and activity type vocabulary.
 *
 * A note is a human authored timeline entry. Creating a note never sends email
 * and never creates a task.
 *
 * @package NowCRM
 */

namespace NowCRM\Contacts;

use NowCRM\Support\Schema;
use WP_Error;

defined( 'ABSPATH' ) || exit;

final class NoteRepository {

	/**
	 * Logical table name, resolved through Schema::table().
	 */
	public const TABLE = 'contact_notes';

	/**
	 * Maximum number of notes one bulk request may delete.
	 */
	public const BULK_LIMIT = 200;

	/**
	 * Default note type.
	 */
	public const KIND_NOTE = 'note';

	/**
	 * Stored title column width.
	 */
	private const TITLE_LENGTH = 255;

	/**
	 * Note and activity types the reference CRM exposes.
	 *
	 * These are labels only. A note of type email or meeting records that an
	 * interaction happened; it does not send anything or create a task.
	 *
	 * @return array<string, string>
	 */
	public static function kinds(): array {
		return array(
			'note'              => __( '筆記', 'now-crm' ),
			'call'              => __( '通話', 'now-crm' ),
			'email'             => __( '電子郵件', 'now-crm' ),
			'meeting'           => __( '會議', 'now-crm' ),
			'quote_sent'        => __( '報價：已寄出', 'now-crm' ),
			'quote_accepted'    => __( '報價：已接受', 'now-crm' ),
			'quote_refused'     => __( '報價：已拒絕', 'now-crm' ),
			'invoice_sent'      => __( '發票：已寄出', 'now-crm' ),
			'invoice_part_paid' => __( '發票：部分付款', 'now-crm' ),
			'invoice_paid'      => __( '發票：已付款', 'now-crm' ),
			'invoice_refunded'  => __( '發票：已退款', 'now-crm' ),
			'transaction'       => __( '交易', 'now-crm' ),
			'feedback'          => __( '回饋', 'now-crm' ),
			'tweet'             => __( '推文', 'now-crm' ),
			'facebook_post'     => __( 'Facebook 貼文', 'now-crm' ),
		);
	}

	/**
	 * Whether a type key is one of the registered activity types.
	 */
	public static function is_valid_kind( string $kind ): bool {
		return array_key_exists( $kind, self::kinds() );
	}

	/**
	 * Human readable type label.
	 */
	public static function label( string $kind ): string {
		$kinds = self::kinds();

		return $kinds[ $kind ] ?? $kind;
	}

	/**
	 * Create a note for one contact.
	 *
	 * @param int                  $contact_id Contact the note belongs to.
	 * @param array<string, mixed> $data       kind, title, body, author_id.
	 * @return int|WP_Error Note ID.
	 */
	public function create( int $contact_id, array $data ) {
		global $wpdb;

		$contact = ( new ContactRepository() )->get( $contact_id );

		if ( ! $contact ) {
			return new WP_Error( 'nowcrm_not_found', __( '找不到聯絡人。', 'now-crm' ) );
		}

		$kind = $this->validated_kind( $data['kind'] ?? self::KIND_NOTE );

		if ( is_wp_error( $kind ) ) {
			return $kind;
		}

		$title = $this->validated_title( $data['title'] ?? '' );

		if ( is_wp_error( $title ) ) {
			return $title;
		}

		$body = $this->validated_body( $data['body'] ?? '' );

		if ( is_wp_error( $body ) ) {
			return $body;
		}

		$created_at = $this->validated_created_at( $data['created_at'] ?? '' );

		if ( is_wp_error( $created_at ) ) {
			return $created_at;
		}

		$now       = gmdate( 'Y-m-d H:i:s' );
		$author_id = isset( $data['author_id'] ) && is_scalar( $data['author_id'] )
			? absint( $data['author_id'] )
			: 0;

		$inserted = $wpdb->insert(
			Schema::table( self::TABLE ),
			array(
				'contact_id' => $contact_id,
				'author_id'  => $author_id > 0 ? $author_id : null,
				'kind'       => $kind,
				'title'      => $title,
				'body'       => $body,
				'created_at' => $created_at ?? $now,
				'updated_at' => $now,
			),
			array( '%d', '%d', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( ! $inserted ) {
			return new WP_Error( 'nowcrm_db_error', $wpdb->last_error ?: __( '無法寫入筆記。', 'now-crm' ) );
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Fetch one note that belongs to the given contact.
	 *
	 * @return array<string, mixed>|null
	 */
	public function get( int $contact_id, int $note_id ): ?array {
		global $wpdb;

		$table = Schema::table( self::TABLE );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is trusted.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE id = %d AND contact_id = %d",
				$note_id,
				$contact_id
			),
			ARRAY_A
		);

		return $row ? $this->cast_row( $row ) : null;
	}

	/**
	 * Update one note. Author and contact never change.
	 *
	 * @param int                  $contact_id Contact that owns the note.
	 * @param int                  $note_id    Note to update.
	 * @param array<string, mixed> $data       kind, title, body.
	 * @return bool|WP_Error
	 */
	public function update( int $contact_id, int $note_id, array $data ) {
		global $wpdb;

		$note = $this->get( $contact_id, $note_id );

		if ( ! $note ) {
			return new WP_Error( 'nowcrm_not_found', __( '找不到筆記。', 'now-crm' ) );
		}

		$fields  = array();
		$formats = array();

		if ( array_key_exists( 'kind', $data ) ) {
			$kind = $this->validated_kind( $data['kind'] );

			if ( is_wp_error( $kind ) ) {
				return $kind;
			}

			$fields['kind'] = $kind;
			$formats[]      = '%s';
		}

		if ( array_key_exists( 'title', $data ) ) {
			$title = $this->validated_title( $data['title'] );

			if ( is_wp_error( $title ) ) {
				return $title;
			}

			$fields['title'] = $title;
			$formats[]       = '%s';
		}

		if ( array_key_exists( 'body', $data ) ) {
			$body = $this->validated_body( $data['body'] );

			if ( is_wp_error( $body ) ) {
				return $body;
			}

			$fields['body'] = $body;
			$formats[]      = '%s';
		}

		if ( array_key_exists( 'created_at', $data ) ) {
			$created_at = $this->validated_created_at( $data['created_at'] );

			if ( is_wp_error( $created_at ) ) {
				return $created_at;
			}

			if ( null !== $created_at ) {
				$fields['created_at'] = $created_at;
				$formats[]            = '%s';
			}
		}

		if ( array() === $fields ) {
			return true;
		}

		$fields['updated_at'] = gmdate( 'Y-m-d H:i:s' );
		$formats[]            = '%s';

		$updated = $wpdb->update(
			Schema::table( self::TABLE ),
			$fields,
			array(
				'id'         => $note_id,
				'contact_id' => $contact_id,
			),
			$formats,
			array( '%d', '%d' )
		);

		if ( false === $updated ) {
			return new WP_Error( 'nowcrm_db_error', $wpdb->last_error ?: __( '無法更新筆記。', 'now-crm' ) );
		}

		return true;
	}

	/**
	 * Delete one note that belongs to the given contact.
	 *
	 * @return bool|WP_Error
	 */
	public function delete( int $contact_id, int $note_id ) {
		global $wpdb;

		if ( $note_id <= 0 ) {
			return new WP_Error( 'nowcrm_not_found', __( '找不到筆記。', 'now-crm' ) );
		}

		$deleted = $wpdb->delete(
			Schema::table( self::TABLE ),
			array(
				'id'         => $note_id,
				'contact_id' => $contact_id,
			),
			array( '%d', '%d' )
		);

		if ( false === $deleted ) {
			return new WP_Error( 'nowcrm_db_error', $wpdb->last_error ?: __( '無法刪除筆記。', 'now-crm' ) );
		}

		if ( 0 === $deleted ) {
			return new WP_Error( 'nowcrm_not_found', __( '找不到筆記。', 'now-crm' ) );
		}

		return true;
	}

	/**
	 * Delete up to BULK_LIMIT notes of one contact.
	 *
	 * IDs of other contacts are ignored by the SQL constraint.
	 *
	 * @param int[] $note_ids Submitted note IDs, unsanitized.
	 * @return int|WP_Error Number of deleted rows.
	 */
	public function bulk_delete( int $contact_id, array $note_ids ) {
		global $wpdb;

		$ids = array();

		foreach ( $note_ids as $note_id ) {
			if ( ! is_scalar( $note_id ) ) {
				continue;
			}

			$note_id = absint( $note_id );

			if ( $note_id > 0 ) {
				$ids[ $note_id ] = $note_id;
			}
		}

		$ids = array_values( $ids );

		if ( array() === $ids ) {
			return new WP_Error( 'nowcrm_no_selection', __( '請先選取要刪除的筆記。', 'now-crm' ) );
		}

		if ( count( $ids ) > self::BULK_LIMIT ) {
			return new WP_Error( 'nowcrm_too_many', __( '一次最多刪除 200 筆筆記。', 'now-crm' ) );
		}

		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$table        = Schema::table( self::TABLE );
		$params       = array_merge( array( $contact_id ), $ids );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders -- placeholders generated above.
		$deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE contact_id = %d AND id IN ({$placeholders})",
				$params
			)
		);
		// phpcs:enable

		if ( false === $deleted ) {
			return new WP_Error( 'nowcrm_db_error', $wpdb->last_error ?: __( '無法刪除筆記。', 'now-crm' ) );
		}

		return (int) $deleted;
	}

	/**
	 * List notes of one contact, newest first.
	 *
	 * @param array<string, mixed> $args search, kind, page, per_page.
	 * @return array{items: array<int, array<string, mixed>>, total: int, page: int, per_page: int}
	 */
	public function search( int $contact_id, array $args = array() ): array {
		global $wpdb;

		$args = array_merge(
			array(
				'search'   => '',
				'kind'     => '',
				'page'     => 1,
				'per_page' => 20,
			),
			$args
		);

		$search   = trim( sanitize_text_field( (string) $args['search'] ) );
		$kind     = sanitize_key( (string) $args['kind'] );
		$page     = max( 1, absint( $args['page'] ) );
		$per_page = max( 1, min( 100, absint( $args['per_page'] ) ) );

		if ( ! self::is_valid_kind( $kind ) ) {
			$kind = '';
		}

		$where  = array( 'contact_id = %d' );
		$params = array( $contact_id );

		if ( '' !== $search ) {
			$where[]  = 'title LIKE %s';
			$params[] = '%' . $wpdb->esc_like( $search ) . '%';
		}

		if ( '' !== $kind ) {
			$where[]  = 'kind = %s';
			$params[] = $kind;
		}

		$where_sql = implode( ' AND ', $where );
		$table     = Schema::table( self::TABLE );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders -- table name is trusted, values are placeholders.
		$total = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}", $params )
		);

		$query_params   = $params;
		$query_params[] = $per_page;
		$query_params[] = ( $page - 1 ) * $per_page;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE {$where_sql} ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d",
				$query_params
			),
			ARRAY_A
		);
		// phpcs:enable

		$items = array();

		foreach ( (array) $rows as $row ) {
			$items[] = $this->cast_row( $row );
		}

		return array(
			'items'    => $items,
			'total'    => $total,
			'page'     => $page,
			'per_page' => $per_page,
		);
	}

	/**
	 * Delete every note of a contact (used when the contact is deleted).
	 */
	public function delete_for_contact( int $contact_id ): void {
		global $wpdb;

		$wpdb->delete(
			Schema::table( self::TABLE ),
			array( 'contact_id' => $contact_id ),
			array( '%d' )
		);
	}

	/**
	 * Validate and normalize a type key.
	 *
	 * @param mixed $kind Raw type.
	 * @return string|WP_Error
	 */
	private function validated_kind( $kind ) {
		if ( ! is_scalar( $kind ) ) {
			return new WP_Error( 'nowcrm_invalid_kind', __( '筆記類型不正確。', 'now-crm' ) );
		}

		$kind = sanitize_key( (string) $kind );

		if ( ! self::is_valid_kind( $kind ) ) {
			return new WP_Error( 'nowcrm_invalid_kind', __( '筆記類型不正確。', 'now-crm' ) );
		}

		return $kind;
	}

	/**
	 * Validate and sanitize a title.
	 *
	 * @param mixed $title Raw title.
	 * @return string|WP_Error
	 */
	private function validated_title( $title ) {
		if ( ! is_scalar( $title ) ) {
			return new WP_Error( 'nowcrm_invalid_title', __( '請輸入筆記標題。', 'now-crm' ) );
		}

		$title = trim( sanitize_text_field( (string) $title ) );

		if ( '' === $title ) {
			return new WP_Error( 'nowcrm_invalid_title', __( '請輸入筆記標題。', 'now-crm' ) );
		}

		return mb_substr( $title, 0, self::TITLE_LENGTH, 'UTF-8' );
	}

	/**
	 * Validate and filter rich note content.
	 *
	 * @param mixed $body Raw HTML body.
	 * @return string|WP_Error
	 */
	private function validated_body( $body ) {
		if ( ! is_scalar( $body ) ) {
			return new WP_Error( 'nowcrm_invalid_body', __( '請輸入筆記內容。', 'now-crm' ) );
		}

		$body = trim( wp_kses_post( (string) $body ) );

		if ( '' === $body ) {
			return new WP_Error( 'nowcrm_invalid_body', __( '請輸入筆記內容。', 'now-crm' ) );
		}

		return $body;
	}

	/**
	 * Validate an explicit creation time.
	 *
	 * The value is a UTC MySQL datetime. An empty value means "use now" on
	 * create and "keep the stored value" on update.
	 *
	 * @param mixed $created_at Raw UTC timestamp.
	 * @return string|null|WP_Error
	 */
	private function validated_created_at( $created_at ) {
		if ( ! is_scalar( $created_at ) ) {
			return new WP_Error( 'nowcrm_invalid_created_at', __( '筆記日期不正確。', 'now-crm' ) );
		}

		$created_at = trim( (string) $created_at );

		if ( '' === $created_at ) {
			return null;
		}

		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $created_at ) ) {
			return new WP_Error( 'nowcrm_invalid_created_at', __( '筆記日期不正確。', 'now-crm' ) );
		}

		$timestamp = strtotime( $created_at . ' UTC' );

		if ( false === $timestamp ) {
			return new WP_Error( 'nowcrm_invalid_created_at', __( '筆記日期不正確。', 'now-crm' ) );
		}

		return gmdate( 'Y-m-d H:i:s', $timestamp );
	}

	/**
	 * Cast numeric columns to native types.
	 *
	 * @param array<string, mixed> $row Raw row.
	 * @return array<string, mixed>
	 */
	private function cast_row( array $row ): array {
		$row['id']         = (int) $row['id'];
		$row['contact_id'] = (int) $row['contact_id'];
		$row['author_id']  = null === $row['author_id'] ? null : (int) $row['author_id'];

		return $row;
	}
}
