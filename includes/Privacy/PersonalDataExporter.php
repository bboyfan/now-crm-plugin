<?php
/**
 * WordPress personal data exporter for NOW CRM stores.
 *
 * Scope contract:
 * - Only the exact normalized request address is matched. A contact is found
 *   by its unique normalized email; no guest or display-name fallback exists.
 * - Exported: contact profile, custom field values, list/tag memberships,
 *   status history, order links, email job metadata, email engagement events,
 *   carts, form entries, SES event receipts and suppression entries.
 * - Never exported: tokens and hashes, signed recovery URLs, job payload
 *   bodies, raw SNS payloads, secrets, staff notes and actor IDs.
 * - Every page is bounded by self::PER_PAGE and read-only.
 *
 * @package NowCRM
 */

namespace NowCRM\Privacy;

use NowCRM\Contacts\ContactRepository;
use NowCRM\Contacts\CustomFieldDefinitions;
use NowCRM\Contacts\Status;
use NowCRM\Forms\EntryRepository;
use NowCRM\Forms\FormRepository;
use NowCRM\Support\Schema;
use WP_Query;

defined( 'ABSPATH' ) || exit;

final class PersonalDataExporter {

	public const EXPORTER_KEY = 'now-crm';

	/**
	 * Bounded rows per store per page. Each page issues fixed-size queries.
	 */
	public const PER_PAGE = 100;

	/**
	 * Register the exporter with WordPress core.
	 */
	public static function register(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'exporters' ) );
	}

	/**
	 * Add this exporter to the core exporter list.
	 *
	 * @param array<string, array<string, mixed>> $exporters Registered exporters.
	 * @return array<string, array<string, mixed>>
	 */
	public static function exporters( array $exporters ): array {
		$exporters[ self::EXPORTER_KEY ] = array(
			'exporter_friendly_name' => __( 'NOW CRM', 'now-crm' ),
			'callback'               => array( __CLASS__, 'export' ),
		);

		return $exporters;
	}

	/**
	 * Export one page of data for the request address.
	 *
	 * @param string $email_address Request address.
	 * @param int    $page          Page number, starting at 1.
	 * @return array{data: array<int, array<string, mixed>>, done: bool}
	 */
	public static function export( $email_address, $page = 1 ): array {
		$email = mb_strtolower( sanitize_email( (string) $email_address ), 'UTF-8' );
		$page  = max( 1, absint( $page ) );

		if ( ! is_email( $email ) ) {
			return array(
				'data' => array(),
				'done' => true,
			);
		}

		$contact    = ( new ContactRepository() )->find_by_email( $email );
		$contact_id = $contact ? (int) $contact['id'] : 0;

		$items = array();
		$more  = false;

		$profile = self::profile( $contact, $page );
		$items   = array_merge( $items, $profile['items'] );
		$more    = $more || $profile['more'];

		if ( $contact_id > 0 ) {
			foreach ( array(
				self::custom_fields( $contact_id, $page ),
				self::memberships( $contact_id, $page ),
				self::status_history( $contact_id, $page ),
			) as $group ) {
				$items = array_merge( $items, $group['items'] );
				$more  = $more || $group['more'];
			}
		}

		foreach ( array(
			self::orders( $contact_id, $page ),
			self::email_jobs( $contact_id, $email, $page ),
			self::email_events( $contact_id, $email, $page ),
			self::carts( $email, $page ),
			self::form_entries( $contact_id, $email, $page ),
			self::ses_events( $email, $page ),
			self::suppression( $email, $page ),
		) as $group ) {
			$items = array_merge( $items, $group['items'] );
			$more  = $more || $group['more'];
		}

		return array(
			'data' => $items,
			'done' => ! $more,
		);
	}

	/**
	 * Contact profile. Only the first page carries the single profile item.
	 *
	 * @param array<string, mixed>|null $contact Contact row.
	 * @return array{items: array<int, array<string, mixed>>, more: bool}
	 */
	private static function profile( ?array $contact, int $page ): array {
		if ( ! $contact || 1 !== $page ) {
			return array(
				'items' => array(),
				'more'  => false,
			);
		}

		$data = array(
			__( '電子郵件', 'now-crm' )          => (string) $contact['email'],
			__( '名字', 'now-crm' )              => (string) $contact['first_name'],
			__( '姓氏', 'now-crm' )              => (string) $contact['last_name'],
			__( '稱謂', 'now-crm' )              => (string) $contact['prefix'],
			__( '電話', 'now-crm' )              => (string) $contact['phone'],
			__( '聯絡人類型', 'now-crm' )        => (string) $contact['contact_type'],
			__( '生日', 'now-crm' )              => (string) ( $contact['date_of_birth'] ?? '' ),
			__( '時區', 'now-crm' )              => (string) $contact['timezone'],
			__( '地址第一行', 'now-crm' )        => (string) $contact['address_line_1'],
			__( '地址第二行', 'now-crm' )        => (string) $contact['address_line_2'],
			__( '城市', 'now-crm' )              => (string) $contact['city'],
			__( '州／省', 'now-crm' )            => (string) $contact['state'],
			__( '郵遞區號', 'now-crm' )          => (string) $contact['postal_code'],
			__( '國家', 'now-crm' )              => (string) $contact['country'],
			__( '訂閱狀態', 'now-crm' )          => Status::label( (string) $contact['status'] ),
			__( '狀態變更時間（UTC）', 'now-crm' ) => (string) ( $contact['status_changed_at'] ?? '' ),
			__( '建立時間（UTC）', 'now-crm' )   => (string) $contact['created_at'],
			__( '更新時間（UTC）', 'now-crm' )   => (string) $contact['updated_at'],
		);

		return array(
			'items' => array(
				self::item( 'nowcrm-profile', __( 'NOW CRM 聯絡人資料', 'now-crm' ), 'profile:' . (int) $contact['id'], $data ),
			),
			'more'  => false,
		);
	}

	/**
	 * Custom field values for one contact.
	 *
	 * @return array{items: array<int, array<string, mixed>>, more: bool}
	 */
	private static function custom_fields( int $contact_id, int $page ): array {
		global $wpdb;

		$table = Schema::table( 'contact_field_values' );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name is trusted; the clause is fixed and values use placeholders.
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, field_key, value FROM {$table} WHERE contact_id = %d ORDER BY field_key ASC, id ASC LIMIT %d OFFSET %d",
				$contact_id,
				self::PER_PAGE + 1,
				self::offset( $page )
			),
			ARRAY_A
		);
		// phpcs:enable

		$page_rows = self::bounded_rows( $rows );
		$items     = array();

		$labels = array();

		foreach ( CustomFieldDefinitions::all() as $definition ) {
			$labels[ strtolower( (string) $definition['slug'] ) ] = (string) $definition['label'];
		}

		foreach ( $page_rows['rows'] as $row ) {
			$key   = strtolower( (string) $row['field_key'] );
			$label = $labels[ $key ] ?? (string) $row['field_key'];

			$items[] = self::item(
				'nowcrm-custom-fields',
				__( 'NOW CRM 自訂欄位', 'now-crm' ),
				'field:' . (int) $row['id'],
				array( $label => self::decode_value( (string) $row['value'] ) )
			);
		}

		return array(
			'items' => $items,
			'more'  => $page_rows['more'],
		);
	}

	/**
	 * List and tag memberships.
	 *
	 * @return array{items: array<int, array<string, mixed>>, more: bool}
	 */
	private static function memberships( int $contact_id, int $page ): array {
		global $wpdb;

		$links = Schema::table( 'contact_terms' );
		$terms = Schema::table( 'terms' );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table names are trusted; values use placeholders.
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ct.id, ct.created_at, t.type, t.name FROM {$links} ct INNER JOIN {$terms} t ON t.id = ct.term_id
				 WHERE ct.contact_id = %d ORDER BY ct.id ASC LIMIT %d OFFSET %d",
				$contact_id,
				self::PER_PAGE + 1,
				self::offset( $page )
			),
			ARRAY_A
		);
		// phpcs:enable

		$page_rows = self::bounded_rows( $rows );
		$items     = array();

		foreach ( $page_rows['rows'] as $row ) {
			$items[] = self::item(
				'nowcrm-memberships',
				__( 'NOW CRM 名單與標籤', 'now-crm' ),
				'membership:' . (int) $row['id'],
				array(
					__( '類型', 'now-crm' )           => (string) $row['type'],
					__( '名稱', 'now-crm' )           => (string) $row['name'],
					__( '加入時間（UTC）', 'now-crm' ) => (string) $row['created_at'],
				)
			);
		}

		return array(
			'items' => $items,
			'more'  => $page_rows['more'],
		);
	}

	/**
	 * Status history. The human note and actor ID stay private.
	 *
	 * @return array{items: array<int, array<string, mixed>>, more: bool}
	 */
	private static function status_history( int $contact_id, int $page ): array {
		global $wpdb;

		$table = Schema::table( 'contact_status_events' );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name is trusted; values use placeholders.
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, old_status, new_status, source, occurred_at FROM {$table}
				 WHERE contact_id = %d ORDER BY occurred_at ASC, id ASC LIMIT %d OFFSET %d",
				$contact_id,
				self::PER_PAGE + 1,
				self::offset( $page )
			),
			ARRAY_A
		);
		// phpcs:enable

		$page_rows = self::bounded_rows( $rows );
		$items     = array();

		foreach ( $page_rows['rows'] as $row ) {
			$old = null === $row['old_status'] ? '' : Status::label( (string) $row['old_status'] );

			$items[] = self::item(
				'nowcrm-status-history',
				__( 'NOW CRM 訂閱狀態紀錄', 'now-crm' ),
				'status:' . (int) $row['id'],
				array(
					__( '原狀態', 'now-crm' )           => $old,
					__( '新狀態', 'now-crm' )           => Status::label( (string) $row['new_status'] ),
					__( '來源', 'now-crm' )             => (string) $row['source'],
					__( '發生時間（UTC）', 'now-crm' )   => (string) $row['occurred_at'],
				)
			);
		}

		return array(
			'items' => $items,
			'more'  => $page_rows['more'],
		);
	}

	/**
	 * Order links owned by the exact current contact.
	 *
	 * A request address never selects another contact's order row. When no
	 * contact exists, no order link is exported: privacy wins over an address
	 * match because order ownership is a CRM ID, not an email.
	 *
	 * @return array{items: array<int, array<string, mixed>>, more: bool}
	 */
	private static function orders( int $contact_id, int $page ): array {
		global $wpdb;

		if ( $contact_id <= 0 ) {
			return array(
				'items' => array(),
				'more'  => false,
			);
		}

		$table = Schema::table( 'order_links' );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name is trusted; values use placeholders.
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT order_id, status, total, net_total, currency, marketing_optin, date_created, date_paid, date_completed
				 FROM {$table} WHERE contact_id = %d ORDER BY order_id ASC LIMIT %d OFFSET %d",
				$contact_id,
				self::PER_PAGE + 1,
				self::offset( $page )
			),
			ARRAY_A
		);
		// phpcs:enable

		$page_rows = self::bounded_rows( $rows );
		$items     = array();

		foreach ( $page_rows['rows'] as $row ) {
			$items[] = self::item(
				'nowcrm-orders',
				__( 'NOW CRM 訂單紀錄', 'now-crm' ),
				'order:' . (int) $row['order_id'],
				array(
					__( '訂單編號', 'now-crm' )            => (string) $row['order_id'],
					__( '狀態', 'now-crm' )                => (string) $row['status'],
					__( '總額', 'now-crm' )                => (string) $row['total'],
					__( '淨額', 'now-crm' )                => (string) $row['net_total'],
					__( '幣別', 'now-crm' )                => (string) $row['currency'],
					__( '行銷同意', 'now-crm' )            => self::yes_no( $row['marketing_optin'] ),
					__( '建立時間（UTC）', 'now-crm' )      => (string) ( $row['date_created'] ?? '' ),
					__( '付款時間（UTC）', 'now-crm' )      => (string) ( $row['date_paid'] ?? '' ),
					__( '完成時間（UTC）', 'now-crm' )      => (string) ( $row['date_completed'] ?? '' ),
				)
			);
		}

		return array(
			'items' => $items,
			'more'  => $page_rows['more'],
		);
	}

	/**
	 * Email job metadata. The frozen body payload and claim token never leave.
	 *
	 * Scope mirrors ContactHistory: a job belongs to the contact row, or has no
	 * contact row and carries exactly the current address.
	 *
	 * @return array{items: array<int, array<string, mixed>>, more: bool}
	 */
	private static function email_jobs( int $contact_id, string $email, int $page ): array {
		global $wpdb;

		$table = Schema::table( 'email_jobs' );

		if ( $contact_id > 0 ) {
			$where = '(contact_id = %d OR (contact_id IS NULL AND recipient = %s))';
			$args  = array( $contact_id, $email );
		} else {
			$contacts = Schema::table( 'contacts' );
			$where    = '((contact_id IS NULL OR contact_id NOT IN (SELECT id FROM ' . $contacts . ')) AND recipient = %s)';
			$args     = array( $email );
		}

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name is trusted; the clause is fixed and values use placeholders.
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, kind, campaign_id, subject, status, attempts, max_attempts,
					created_at, accepted_at, delivery_status, delivery_at
				 FROM {$table} WHERE {$where} ORDER BY id ASC LIMIT %d OFFSET %d",
				array_merge( $args, array( self::PER_PAGE + 1, self::offset( $page ) ) )
			),
			ARRAY_A
		);
		// phpcs:enable

		$page_rows = self::bounded_rows( $rows );
		$items     = array();

		foreach ( $page_rows['rows'] as $row ) {
			$items[] = self::item(
				'nowcrm-email',
				__( 'NOW CRM 電子郵件紀錄', 'now-crm' ),
				'job:' . (int) $row['id'],
				array(
					__( '主旨', 'now-crm' )                    => (string) $row['subject'],
					__( '類型', 'now-crm' )                    => (string) $row['kind'],
					__( '佇列狀態', 'now-crm' )                => (string) $row['status'],
					__( '嘗試次數', 'now-crm' )                => (string) $row['attempts'] . ' / ' . (string) $row['max_attempts'],
					__( '建立時間（UTC）', 'now-crm' )          => (string) $row['created_at'],
					__( '送出時間（UTC）', 'now-crm' )          => (string) ( $row['accepted_at'] ?? '' ),
					__( '送達狀態', 'now-crm' )                => (string) $row['delivery_status'],
					__( '送達時間（UTC）', 'now-crm' )          => (string) ( $row['delivery_at'] ?? '' ),
				)
			);
		}

		return array(
			'items' => $items,
			'more'  => $page_rows['more'],
		);
	}

	/**
	 * Email engagement events. Link URLs, user agents and IP hashes stay out.
	 *
	 * With a current contact, only events owned by that contact are exported.
	 * Without one, events are reached only through dangling or ownerless jobs
	 * that carry the exact request address, so a replacement contact never
	 * inherits another deleted contact's history by email.
	 *
	 * @return array{items: array<int, array<string, mixed>>, more: bool}
	 */
	private static function email_events( int $contact_id, string $email, int $page ): array {
		global $wpdb;

		$table = Schema::table( 'email_events' );

		if ( $contact_id > 0 ) {
			$from  = $table . ' e';
			$where = 'e.contact_id = %d';
			$args  = array( $contact_id );
		} else {
			$jobs     = Schema::table( 'email_jobs' );
			$contacts = Schema::table( 'contacts' );
			$from     = $table . ' e INNER JOIN ' . $jobs . ' j ON j.id = e.job_id';
			$where    = '(j.contact_id IS NULL OR j.contact_id NOT IN (SELECT id FROM ' . $contacts . ')) AND j.recipient = %s';
			$args     = array( $email );
		}

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table names are trusted; the clause is fixed and values use placeholders.
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT e.id, e.type, e.campaign_id, e.job_id, e.occurred_at FROM {$from}
				 WHERE {$where} ORDER BY e.occurred_at ASC, e.id ASC LIMIT %d OFFSET %d",
				array_merge( $args, array( self::PER_PAGE + 1, self::offset( $page ) ) )
			),
			ARRAY_A
		);
		// phpcs:enable

		$page_rows = self::bounded_rows( $rows );
		$items     = array();

		foreach ( $page_rows['rows'] as $row ) {
			$items[] = self::item(
				'nowcrm-email',
				__( 'NOW CRM 電子郵件紀錄', 'now-crm' ),
				'event:' . (int) $row['id'],
				array(
					__( '事件類型', 'now-crm' )          => (string) $row['type'],
					__( '電子報 ID', 'now-crm' )         => (string) ( $row['campaign_id'] ?? '' ),
					__( '郵件 Job ID', 'now-crm' )       => (string) ( $row['job_id'] ?? '' ),
					__( '發生時間（UTC）', 'now-crm' )    => (string) $row['occurred_at'],
				)
			);
		}

		return array(
			'items' => $items,
			'more'  => $page_rows['more'],
		);
	}

	/**
	 * Carts matched by the normalized address. Snapshot and session data stay out.
	 *
	 * @return array{items: array<int, array<string, mixed>>, more: bool}
	 */
	private static function carts( string $email, int $page ): array {
		global $wpdb;

		$table = Schema::table( 'carts' );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name is trusted; values use placeholders.
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, status, item_count, total, currency, created_at, last_activity_at, abandoned_at,
					recovered_order_id
				 FROM {$table} WHERE email_normalized = %s ORDER BY id ASC LIMIT %d OFFSET %d",
				$email,
				self::PER_PAGE + 1,
				self::offset( $page )
			),
			ARRAY_A
		);
		// phpcs:enable

		$page_rows = self::bounded_rows( $rows );
		$items     = array();

		foreach ( $page_rows['rows'] as $row ) {
			$items[] = self::item(
				'nowcrm-carts',
				__( 'NOW CRM 購物車紀錄', 'now-crm' ),
				'cart:' . (int) $row['id'],
				array(
					__( '狀態', 'now-crm' )              => (string) $row['status'],
					__( '商品數量', 'now-crm' )          => (string) $row['item_count'],
					__( '總額', 'now-crm' )              => (string) $row['total'],
					__( '幣別', 'now-crm' )              => (string) $row['currency'],
					__( '建立時間（UTC）', 'now-crm' )    => (string) $row['created_at'],
					__( '最後活動（UTC）', 'now-crm' )    => (string) $row['last_activity_at'],
					__( '放棄時間（UTC）', 'now-crm' )    => (string) ( $row['abandoned_at'] ?? '' ),
					__( '恢復訂單編號', 'now-crm' )       => (string) ( $row['recovered_order_id'] ?? '' ),
				)
			);
		}

		return array(
			'items' => $items,
			'more'  => $page_rows['more'],
		);
	}

	/**
	 * Form entries owned by the contact, or unowned entries for the exact email.
	 *
	 * An entry assigned to another contact is never disclosed, even when the
	 * requested address matches its stored email: addresses are mutable and can
	 * move between contacts.
	 *
	 * @return array{items: array<int, array<string, mixed>>, more: bool}
	 */
	private static function form_entries( int $contact_id, string $email, int $page ): array {
		$unowned = array(
			'relation' => 'AND',
			array(
				'key'     => EntryRepository::META_EMAIL,
				'value'   => $email,
				'compare' => '=',
			),
			array(
				'relation' => 'OR',
				array(
					'key'     => EntryRepository::META_CONTACT,
					'compare' => 'NOT EXISTS',
				),
				array(
					'key'     => EntryRepository::META_CONTACT,
					'value'   => '0',
					'compare' => '=',
				),
			),
		);

		$meta_query = array( 'relation' => 'OR' );

		if ( $contact_id > 0 ) {
			$meta_query[] = array(
				'key'     => EntryRepository::META_CONTACT,
				'value'   => (string) $contact_id,
				'compare' => '=',
			);
		}

		$meta_query[] = $unowned;

		$query = new WP_Query(
			array(
				'post_type'              => FormRepository::ENTRY_TYPE,
				'post_status'            => array( 'publish', 'private' ),
				'posts_per_page'         => self::PER_PAGE + 1,
				'offset'                 => self::offset( $page ),
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'meta_query'             => $meta_query,
				'ignore_sticky_posts'    => true,
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
			)
		);

		$page_rows = self::bounded_rows( $query->posts );
		$items     = array();

		foreach ( $page_rows['rows'] as $post ) {
			$payload = json_decode( (string) get_post_meta( (int) $post->ID, EntryRepository::META_DATA, true ), true );
			$payload = is_array( $payload ) ? $payload : array();
			$data    = array(
				__( '表單', 'now-crm' )              => (string) get_the_title( (int) $post->post_parent ),
				__( '送出時間（UTC）', 'now-crm' )    => (string) $post->post_date_gmt,
			);

			foreach ( (array) ( $payload['fields'] ?? array() ) as $field ) {
				if ( ! is_array( $field ) ) {
					continue;
				}

				$label = (string) ( $field['label'] ?? $field['key'] ?? '' );

				if ( '' !== $label ) {
					$data[ $label ] = self::decode_value( $field['value'] ?? '' );
				}
			}

			$items[] = self::item(
				'nowcrm-forms',
				__( 'NOW CRM 表單送出紀錄', 'now-crm' ),
				'form-entry:' . (int) $post->ID,
				$data
			);
		}

		return array(
			'items' => $items,
			'more'  => $page_rows['more'],
		);
	}

	/**
	 * SES event receipts for the address. The raw SNS payload stays out.
	 *
	 * @return array{items: array<int, array<string, mixed>>, more: bool}
	 */
	private static function ses_events( string $email, int $page ): array {
		global $wpdb;

		$table = Schema::table( 'ses_events' );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name is trusted; values use placeholders.
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, event_type, processed, received_at FROM {$table}
				 WHERE email_normalized = %s ORDER BY id ASC LIMIT %d OFFSET %d",
				$email,
				self::PER_PAGE + 1,
				self::offset( $page )
			),
			ARRAY_A
		);
		// phpcs:enable

		$page_rows = self::bounded_rows( $rows );
		$items     = array();

		foreach ( $page_rows['rows'] as $row ) {
			$items[] = self::item(
				'nowcrm-ses',
				__( 'NOW CRM 寄送事件', 'now-crm' ),
				'ses:' . (int) $row['id'],
				array(
					__( '事件類型', 'now-crm' )          => (string) $row['event_type'],
					__( '已處理', 'now-crm' )            => self::yes_no( $row['processed'] ),
					__( '收到時間（UTC）', 'now-crm' )    => (string) $row['received_at'],
				)
			);
		}

		return array(
			'items' => $items,
			'more'  => $page_rows['more'],
		);
	}

	/**
	 * Suppression entry for the address.
	 *
	 * @return array{items: array<int, array<string, mixed>>, more: bool}
	 */
	private static function suppression( string $email, int $page ): array {
		global $wpdb;

		$table = Schema::table( 'suppressions' );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name is trusted; values use placeholders.
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, reason, created_at, updated_at FROM {$table}
				 WHERE email_normalized = %s ORDER BY id ASC LIMIT %d OFFSET %d",
				$email,
				self::PER_PAGE + 1,
				self::offset( $page )
			),
			ARRAY_A
		);
		// phpcs:enable

		$page_rows = self::bounded_rows( $rows );
		$items     = array();

		foreach ( $page_rows['rows'] as $row ) {
			$items[] = self::item(
				'nowcrm-suppression',
				__( 'NOW CRM 抑制名單', 'now-crm' ),
				'suppression:' . (int) $row['id'],
				array(
					__( '原因', 'now-crm' )              => (string) $row['reason'],
					__( '建立時間（UTC）', 'now-crm' )    => (string) $row['created_at'],
					__( '更新時間（UTC）', 'now-crm' )    => (string) $row['updated_at'],
				)
			);
		}

		return array(
			'items' => $items,
			'more'  => $page_rows['more'],
		);
	}

	/**
	 * Trim one page fetched with PER_PAGE + 1 rows and report more data.
	 *
	 * @param array<int, mixed> $rows Rows or posts, in query order.
	 * @return array{rows: array<int, mixed>, more: bool}
	 */
	private static function bounded_rows( array $rows ): array {
		$more = count( $rows ) > self::PER_PAGE;

		if ( $more ) {
			array_pop( $rows );
		}

		return array(
			'rows' => $rows,
			'more' => $more,
		);
	}

	/**
	 * Build one export item from ordered name/value pairs.
	 *
	 * @param string                $group_id    Stable group ID.
	 * @param string                $group_label Localized group label.
	 * @param string                $item_id     Stable item ID.
	 * @param array<string, mixed>  $fields      Name/value pairs.
	 * @return array<string, mixed>
	 */
	private static function item( string $group_id, string $group_label, string $item_id, array $fields ): array {
		$data = array();

		foreach ( $fields as $name => $value ) {
			$data[] = array(
				'name'  => (string) $name,
				'value' => self::value_text( $value ),
			);
		}

		return array(
			'group_id'    => $group_id,
			'group_label' => $group_label,
			'item_id'     => $item_id,
			'data'        => $data,
		);
	}

	/**
	 * Serialize one stored value as export text.
	 *
	 * @param mixed $value Stored value.
	 */
	private static function value_text( $value ): string {
		if ( is_bool( $value ) ) {
			return $value ? '1' : '0';
		}

		if ( is_scalar( $value ) || null === $value ) {
			return (string) $value;
		}

		$json = wp_json_encode( $value );

		return is_string( $json ) ? $json : '';
	}

	/**
	 * Decode a JSON custom field or form value, falling back to raw text.
	 *
	 * @param mixed $value Stored value.
	 */
	private static function decode_value( $value ): string {
		if ( is_array( $value ) ) {
			return self::value_text( $value );
		}

		$raw     = (string) $value;
		$decoded = json_decode( $raw, true );

		if ( JSON_ERROR_NONE === json_last_error() && null !== $decoded ) {
			return self::value_text( $decoded );
		}

		return $raw;
	}

	/**
	 * Format a nullable boolean flag.
	 *
	 * @param mixed $value Stored flag.
	 */
	private static function yes_no( $value ): string {
		if ( null === $value || '' === $value ) {
			return '';
		}

		return (int) $value > 0 ? '1' : '0';
	}

	/**
	 * Row offset for a 1-based page.
	 */
	private static function offset( int $page ): int {
		return max( 0, $page - 1 ) * self::PER_PAGE;
	}
}
