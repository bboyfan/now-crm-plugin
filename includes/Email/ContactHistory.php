<?php
/**
 * Read model for one contact's email history, plus the one-off send path.
 *
 * Scope rule: a contact owns a job when the job points at the contact row, or
 * when the job has no contact row and the recipient is exactly this contact's
 * current address. A job assigned to another contact never leaks here, even
 * when the stored recipient happens to be the same address.
 *
 * The model never deletes jobs. Accepted rows are audit records: email_events,
 * unsubscribe_tokens and SES correlation all point at a job id. Deleting one
 * would orphan those rows and erase the record of what the operator queued, so
 * a delete action is deliberately absent.
 *
 * One-off mail is queued as kind "custom" with the fully rendered template
 * frozen into the payload, the same pattern as campaign test sends. The queue
 * still applies its own gates at send time: the non-test marketing gate needs
 * Status::SUBSCRIBED, and the global suppression list blocks hard bounces,
 * complaints and unsubscribes. This class never changes a contact status and
 * never removes a suppression entry.
 *
 * @package NowCRM
 */

namespace NowCRM\Email;

use NowCRM\Campaign\Campaigns;
use NowCRM\Contacts\ContactRepository;
use NowCRM\Contacts\Status;
use NowCRM\Support\Schema;
use WP_Error;

defined( 'ABSPATH' ) || exit;

final class ContactHistory {

	public const KIND_CUSTOM = 'custom';

	public const PER_PAGE = 20;

	/**
	 * One page of rows; keeps the IN () event lookup bounded.
	 */
	public const MAX_PER_PAGE = 50;

	/**
	 * Largest body accepted by the compose form, in bytes.
	 */
	public const MAX_BODY_BYTES = 102400;

	/**
	 * Subject limits. The jobs column is varchar(255); staying below it in
	 * bytes avoids a mid-character truncation of multi-byte subjects.
	 */
	public const MAX_SUBJECT_CHARS = 78;
	public const MAX_SUBJECT_BYTES = 240;

	private EmailJobRepository $jobs;
	private SuppressionRepository $suppressions;
	private EmailRenderer $renderer;

	public function __construct() {
		$this->jobs         = new EmailJobRepository();
		$this->suppressions = new SuppressionRepository();
		$this->renderer     = new EmailRenderer();
	}

	/**
	 * Statuses that can appear in this history, in display order.
	 *
	 * @return string[]
	 */
	public static function statuses(): array {
		return array(
			EmailJobRepository::STATUS_QUEUED,
			EmailJobRepository::STATUS_SENDING,
			EmailJobRepository::STATUS_ACCEPTED,
			EmailJobRepository::STATUS_FAILED,
			EmailJobRepository::STATUS_SUPPRESSED,
			EmailJobRepository::STATUS_UNKNOWN,
			EmailJobRepository::STATUS_CANCELLED,
		);
	}

	/**
	 * Kinds this history can filter on. Unknown kinds still render in the list.
	 *
	 * @return string[]
	 */
	public static function kinds(): array {
		return array( 'campaign', 'cart', 'test', self::KIND_CUSTOM );
	}

	public static function is_status( string $status ): bool {
		return in_array( $status, self::statuses(), true );
	}

	public static function is_kind( string $kind ): bool {
		return in_array( $kind, self::kinds(), true );
	}

	/**
	 * Human label. Accepted is transport handoff, not inbox delivery.
	 */
	public static function status_label( string $status ): string {
		$labels = array(
			EmailJobRepository::STATUS_QUEUED     => __( '已排入佇列', 'now-crm' ),
			EmailJobRepository::STATUS_SENDING    => __( '寄送中', 'now-crm' ),
			EmailJobRepository::STATUS_ACCEPTED   => __( '已送出', 'now-crm' ),
			EmailJobRepository::STATUS_FAILED     => __( '失敗', 'now-crm' ),
			EmailJobRepository::STATUS_SUPPRESSED => __( '已阻擋', 'now-crm' ),
			EmailJobRepository::STATUS_UNKNOWN    => __( '結果不明', 'now-crm' ),
			EmailJobRepository::STATUS_CANCELLED  => __( '已取消', 'now-crm' ),
		);

		return $labels[ $status ] ?? $status;
	}

	/**
	 * Delivery results a job may carry, in escalation order.
	 *
	 * @return string[]
	 */
	public static function delivery_statuses(): array {
		return array(
			EmailJobRepository::DELIVERY_DELIVERED,
			EmailJobRepository::DELIVERY_BOUNCED,
			EmailJobRepository::DELIVERY_COMPLAINED,
			EmailJobRepository::DELIVERY_DEFERRED,
		);
	}

	/**
	 * Human label for a provider delivery result. Empty means no SNS receipt.
	 */
	public static function delivery_label( string $status ): string {
		$labels = array(
			EmailJobRepository::DELIVERY_DELIVERED  => __( '已送達', 'now-crm' ),
			EmailJobRepository::DELIVERY_BOUNCED    => __( '已退信', 'now-crm' ),
			EmailJobRepository::DELIVERY_COMPLAINED => __( '客訴', 'now-crm' ),
			EmailJobRepository::DELIVERY_DEFERRED   => __( '暫時退信', 'now-crm' ),
			''                                      => __( '尚未回條', 'now-crm' ),
		);

		return $labels[ $status ] ?? $status;
	}

	/**
	 * Outcome shown to operators: the provider result when one exists,
	 * otherwise the queue status.
	 *
	 * @param array<string, mixed> $job Job row.
	 */
	public static function display_status_label( array $job ): string {
		$delivery = (string) ( $job['delivery_status'] ?? '' );

		return '' !== $delivery
			? self::delivery_label( $delivery )
			: self::status_label( (string) ( $job['status'] ?? '' ) );
	}

	public static function kind_label( string $kind ): string {
		$labels = array(
			'campaign'         => __( '電子報', 'now-crm' ),
			'cart'             => __( '購物車提醒', 'now-crm' ),
			'test'             => __( '測試寄送', 'now-crm' ),
			self::KIND_CUSTOM  => __( '一次性 Email', 'now-crm' ),
		);

		return $labels[ $kind ] ?? $kind;
	}

	/**
	 * One page of jobs owned by a contact.
	 *
	 * @param array<string, mixed> $args status, kind, page, per_page.
	 * @return array{items: array<int, array<string, mixed>>, total: int, page: int, per_page: int, pages: int}
	 */
	public function search( int $contact_id, string $email, array $args = array() ): array {
		global $wpdb;

		$table = Schema::table( 'email_jobs' );

		[ $clause, $params ] = $this->scope( $contact_id, $email, $args );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- clause is built from fixed fragments.
		$total_sql = $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$clause}", $params );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- clause is built from fixed fragments; table name is trusted and values use placeholders.
		$total     = (int) $wpdb->get_var( $total_sql );

		$per_page = max( 1, min( self::MAX_PER_PAGE, (int) ( $args['per_page'] ?? self::PER_PAGE ) ) );
		$pages    = (int) ceil( $total / $per_page );
		$page     = max( 1, min( max( 1, $pages ), (int) ( $args['page'] ?? 1 ) ) );
		$offset   = ( $page - 1 ) * $per_page;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- clause is built from fixed fragments.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE {$clause} ORDER BY id DESC LIMIT %d OFFSET %d",
				array_merge( $params, array( $per_page, $offset ) )
			),
			ARRAY_A
		);

		return array(
			'items'    => array_map( array( $this->jobs, 'cast' ), (array) $rows ),
			'total'    => $total,
			'page'     => $page,
			'per_page' => $per_page,
			'pages'    => $pages,
		);
	}

	/**
	 * Per-status counters for the whole contact history, ignoring list filters.
	 *
	 * @return array<string, int>
	 */
	public function counts( int $contact_id, string $email ): array {
		global $wpdb;

		$table = Schema::table( 'email_jobs' );

		[ $clause, $params ] = $this->scope( $contact_id, $email, array() );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- clause is built from fixed fragments.
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT status, COUNT(*) AS total FROM {$table} WHERE {$clause} GROUP BY status", $params ),
			ARRAY_A
		);

		$counts = array_fill_keys( self::statuses(), 0 );
		$counts['total'] = 0;

		foreach ( (array) $rows as $row ) {
			$status = (string) $row['status'];
			$total  = (int) $row['total'];

			$counts[ $status ] = isset( $counts[ $status ] ) ? $counts[ $status ] + $total : $total;
			$counts['total']  += $total;
		}

		return $counts;
	}

	/**
	 * Open and click counters for a bounded set of jobs.
	 *
	 * @param int[] $job_ids Job IDs on the current page.
	 * @return array<int, array{open: int, click: int, last_at: string}>
	 */
	public function events( array $job_ids ): array {
		global $wpdb;

		$ids = array_values( array_unique( array_filter( array_map( 'absint', $job_ids ) ) ) );
		$map = array();

		foreach ( $ids as $id ) {
			$map[ $id ] = array( 'open' => 0, 'click' => 0, 'last_at' => '' );
		}

		if ( ! $ids ) {
			return $map;
		}

		$table        = Schema::table( 'email_events' );
		$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders and trusted table only.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT job_id, type, COUNT(*) AS total, MAX(occurred_at) AS last_at
				 FROM {$table} WHERE job_id IN ({$placeholders}) AND type IN ('open', 'click')
				 GROUP BY job_id, type",
				$ids
			),
			ARRAY_A
		);

		foreach ( (array) $rows as $row ) {
			$job = (int) $row['job_id'];
			$type = (string) $row['type'];

			if ( ! isset( $map[ $job ] ) || ! in_array( $type, array( 'open', 'click' ), true ) ) {
				continue;
			}

			$map[ $job ][ $type ] = (int) $row['total'];
			$map[ $job ]['last_at'] = (string) $row['last_at'];
		}

		return $map;
	}

	/**
	 * One job owned by the contact, or null.
	 *
	 * @return array<string, mixed>|null
	 */
	public function find( int $contact_id, string $email, int $job_id ): ?array {
		global $wpdb;

		if ( $job_id <= 0 ) {
			return null;
		}

		$table = Schema::table( 'email_jobs' );
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table}
				 WHERE id = %d AND (contact_id = %d OR (contact_id IS NULL AND recipient = %s))",
				$job_id,
				$contact_id,
				$this->normalize( $email )
			),
			ARRAY_A
		);

		return $row ? $this->jobs->cast( $row ) : null;
	}

	/**
	 * Render the frozen snapshot of one job for a read-only preview.
	 *
	 * No unsubscribe token is issued and no tracking URL is injected, so this
	 * method writes nothing. Missing snapshots return null instead of an empty
	 * body so the screen can say the body is unavailable.
	 *
	 * @return array{job: array<string, mixed>, subject: string, html: string}|null
	 */
	public function preview( int $contact_id, string $email, int $job_id ): ?array {
		$job = $this->find( $contact_id, $email, $job_id );

		if ( ! $job ) {
			return null;
		}

		$template = self::template_for_job( $job );

		if ( ! $template ) {
			return null;
		}

		$contact = ( new ContactRepository() )->get( $contact_id );

		if ( ! $contact ) {
			return null;
		}

		$payload = is_array( $job['payload'] ?? null ) ? $job['payload'] : array();

		$context = array(
			'first_name'      => (string) ( $contact['first_name'] ?? '' ),
			'last_name'       => (string) ( $contact['last_name'] ?? '' ),
			'email'           => (string) ( $contact['email'] ?? '' ),
			'site_name'       => wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ),
			'site_url'        => home_url( '/' ),
			'order_id'        => (string) ( $payload['order_id'] ?? '' ),
			'order_total'     => (string) ( $payload['order_total'] ?? '' ),
			'cart_url'        => function_exists( 'wc_get_cart_url' ) ? wc_get_cart_url() : home_url( '/' ),
			'recovery_url'    => (string) ( $payload['recovery_url'] ?? '' ),
			'unsubscribe_url' => '',
		);

		$rendered = $this->renderer->render( $template, $context );

		return array(
			'job'     => $job,
			'subject' => $rendered['subject'] ?: (string) $job['subject'],
			'html'    => $rendered['html'],
		);
	}

	/**
	 * Frozen template of a job. Campaign jobs read the campaign snapshot;
	 * cart, test and custom jobs carry their snapshot in the payload.
	 *
	 * @param array<string, mixed> $job Job row.
	 * @return array<string, mixed>|null
	 */
	public static function template_for_job( array $job ): ?array {
		if ( 'campaign' === ( $job['kind'] ?? '' ) ) {
			$campaign_id = (int) ( $job['campaign_id'] ?? 0 );

			return $campaign_id > 0 ? Campaigns::template( $campaign_id ) : null;
		}

		$payload  = is_array( $job['payload'] ?? null ) ? $job['payload'] : array();
		$template = $payload['template'] ?? null;

		return is_array( $template ) && isset( $template['content'] ) ? $template : null;
	}

	/**
	 * Why this contact cannot receive a queued one-off email.
	 *
	 * @param array<string, mixed> $contact Contact row.
	 * @return string '' when allowed, otherwise not_subscribed|suppressed.
	 */
	public function send_block_reason( array $contact ): string {
		if ( Status::SUBSCRIBED !== (string) ( $contact['status'] ?? '' ) ) {
			return 'not_subscribed';
		}

		$email = (string) ( $contact['email'] ?? '' );

		if ( ! is_email( $email ) || $this->suppressions->is_suppressed( $email ) ) {
			return 'suppressed';
		}

		return '';
	}

	/**
	 * Queue one one-off email for a contact.
	 *
	 * The job is a real queue row with a frozen template. It is never sent from
	 * this method; the worker decides at send time, and only when the sending
	 * switch is on. Reusing request_id returns the existing job, so a double
	 * form submit cannot create a second email.
	 *
	 * @param array<string, mixed> $input subject, body, request_id.
	 * @return int|WP_Error
	 */
	public function queue_one_off( int $contact_id, array $input, int $actor_id = 0 ) {
		$contact = ( new ContactRepository() )->get( $contact_id );

		if ( ! $contact ) {
			return new WP_Error( 'nowcrm_contact_not_found', __( '找不到此聯絡人。', 'now-crm' ) );
		}

		$email = (string) ( $contact['email'] ?? '' );

		if ( ! is_email( $email ) ) {
			return new WP_Error( 'nowcrm_invalid_email', __( '聯絡人 Email 格式不正確。', 'now-crm' ) );
		}

		$reason = $this->send_block_reason( $contact );

		if ( 'not_subscribed' === $reason ) {
			return new WP_Error(
				'nowcrm_not_subscribed',
				__( '只有「已訂閱」聯絡人可排入行銷 Email；本次未建立 Job，聯絡人狀態未變更。', 'now-crm' )
			);
		}

		if ( 'suppressed' === $reason ) {
			return new WP_Error(
				'nowcrm_suppressed',
				__( '此地址在抑制名單中，不能排入 Email；本次未建立 Job。', 'now-crm' )
			);
		}

		$request_id = strtolower( trim( (string) ( $input['request_id'] ?? '' ) ) );

		if ( ! preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $request_id ) ) {
			return new WP_Error( 'nowcrm_invalid_request', __( '表單已失效，請重新整理後再試。', 'now-crm' ) );
		}

		$subject = trim( sanitize_text_field( (string) ( $input['subject'] ?? '' ) ) );

		if ( '' === $subject || mb_strlen( $subject ) > self::MAX_SUBJECT_CHARS || strlen( $subject ) > self::MAX_SUBJECT_BYTES ) {
			return new WP_Error(
				'nowcrm_invalid_subject',
				/* translators: %d: maximum subject length. */
				sprintf( __( '主旨不可空白，且最多 %d 個字元。', 'now-crm' ), self::MAX_SUBJECT_CHARS )
			);
		}

		$body = str_replace( "\0", '', (string) ( $input['body'] ?? '' ) );
		$body = trim( wp_check_invalid_utf8( $body, true ) );

		if ( strlen( $body ) > self::MAX_BODY_BYTES || ! self::has_content( $body ) ) {
			return new WP_Error( 'nowcrm_invalid_body', __( '請填寫 Email 內容（可含 HTML）。', 'now-crm' ) );
		}

		$template = array(
			'mode'         => 'html',
			'content'      => $body,
			'subject'      => $subject,
			'preheader'    => '',
			'footer_text'  => '',
			'from_name'    => '',
			'from_address' => '',
			'reply_to'     => '',
		);

		$job_key = 'custom:' . $contact_id . ':' . hash( 'sha256', $request_id );

		$result = $this->jobs->enqueue(
			array(
				'job_key'        => $job_key,
				'kind'           => self::KIND_CUSTOM,
				'contact_id'     => $contact_id,
				'recipient'      => $email,
				'recipient_name' => trim( (string) ( $contact['first_name'] ?? '' ) . ' ' . (string) ( $contact['last_name'] ?? '' ) ),
				'subject'        => $subject,
				'payload'        => array(
					'template'   => $template,
					'channel'    => 'contact_one_off',
					'request_id' => $request_id,
					'created_by' => absint( $actor_id ),
				),
				'priority'       => 5,
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		Queue::schedule( 1 );

		return (int) $result;
	}

	/**
	 * Shared WHERE scope plus validated status/kind filters.
	 *
	 * @param array<string, mixed> $args status, kind.
	 * @return array{0: string, 1: array<int, mixed>}
	 */
	private function scope( int $contact_id, string $email, array $args ): array {
		$where  = array( '(contact_id = %d OR (contact_id IS NULL AND recipient = %s))' );
		$params = array( absint( $contact_id ), $this->normalize( $email ) );

		$status = sanitize_key( (string) ( $args['status'] ?? '' ) );
		if ( '' !== $status && self::is_status( $status ) ) {
			$where[]  = 'status = %s';
			$params[] = $status;
		}

		$kind = sanitize_key( (string) ( $args['kind'] ?? '' ) );
		if ( '' !== $kind && self::is_kind( $kind ) ) {
			$where[]  = 'kind = %s';
			$params[] = $kind;
		}

		return array( implode( ' AND ', $where ), $params );
	}

	/**
	 * Normalize an address the same way the queue and suppression list do.
	 */
	private function normalize( string $email ): string {
		return mb_strtolower( trim( $email ), 'UTF-8' );
	}

	/**
	 * Whether a body has visible text or an image.
	 */
	private static function has_content( string $body ): bool {
		if ( '' === $body ) {
			return false;
		}

		$text = html_entity_decode( wp_strip_all_tags( $body ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		return 1 === preg_match( '/[^\s\p{Z}]/u', $text ) || 1 === preg_match( '/<img\b[^>]+\bsrc\s*=/i', $body );
	}
}
