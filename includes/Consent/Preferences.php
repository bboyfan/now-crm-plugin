<?php
/**
 * Public preference-center operations behind signed tokens.
 *
 * Every mutating call takes a contact ID that the caller resolved from a
 * purpose-scoped token; this class never parses tokens itself. Reads and
 * writes reuse `ContactRepository` (profile fields, status) and
 * `TermRepository` (list attach/detach restricted to configured public lists).
 * Suppression and status rules are never bypassed: suppressed contacts keep
 * their status, and suppression entries are never lifted here.
 *
 * @package NowCRM
 */

namespace NowCRM\Consent;

use NowCRM\Contacts\ContactRepository;
use NowCRM\Contacts\ProfileFields;
use NowCRM\Contacts\Status;
use NowCRM\Email\EmailJobRepository;
use NowCRM\Email\SuppressionRepository;
use NowCRM\Email\Tracking;
use NowCRM\Terms\TermRepository;
use WP_Error;

defined( 'ABSPATH' ) || exit;

final class Preferences {

	public const SOURCE = 'preference-center';

	/**
	 * The reply sent for every public link request, whatever the outcome, so
	 * the form is never an email oracle.
	 */
	public const REQUEST_MESSAGE = '';

	private ContactRepository $contacts;
	private TermRepository $terms;
	private SuppressionRepository $suppressions;
	private EmailJobRepository $jobs;
	private Tracking $tracking;
	private DoubleOptin $doi;

	public function __construct(
		?ContactRepository $contacts = null,
		?TermRepository $terms = null,
		?SuppressionRepository $suppressions = null,
		?EmailJobRepository $jobs = null,
		?DoubleOptin $doi = null
	) {
		$this->contacts     = $contacts ?: new ContactRepository();
		$this->terms        = $terms ?: new TermRepository();
		$this->suppressions = $suppressions ?: new SuppressionRepository();
		$this->jobs         = $jobs ?: new EmailJobRepository();
		$this->tracking     = new Tracking();
		$this->doi          = $doi ?: new DoubleOptin( $this->contacts, $this->terms, $this->suppressions, $this->jobs );
	}

	/**
	 * Generic reply for the public manage-link request form.
	 */
	public static function request_message(): string {
		return __( '如果此 Email 已存在於系統中，我們已寄出偏好設定連結。', 'now-crm' );
	}

	/**
	 * Send a manage link when possible. Always the same reply: unknown,
	 * suppressed-by-bounce/complaint, throttled and sent states are identical
	 * to the requester.
	 *
	 * @return array{ok: bool, message: string, sent: bool}
	 */
	public function request_link( string $email, string $ip = '' ): array {
		$sendable = $this->sendable_link_target( $email );

		$window = max( 1, (int) Config::get( 'public_request_throttle_minutes', 5 ) ) * MINUTE_IN_SECONDS;
		$bucket = 'pref-request:' . Tokens::normalize_email( $email );

		if ( '' !== $ip ) {
			$bucket .= ':' . preg_replace( '/[^0-9a-fA-F:.]/', '', $ip );
		}

		if ( ! Throttle::allow( $bucket, $window ) ) {
			return $this->request_reply( false );
		}

		if ( null === $sendable ) {
			Throttle::release( $bucket );

			return $this->request_reply( false );
		}

		[ $contact_id, $address ] = $sendable;

		$issued = Tokens::issue(
			Tokens::PURPOSE_MANAGE,
			$contact_id,
			$address,
			max( 1, (int) Config::get( 'manage_token_days', 90 ) ) * DAY_IN_SECONDS
		);

		if ( is_wp_error( $issued ) ) {
			Throttle::release( $bucket );

			return $this->request_reply( false );
		}

		$enqueued = $this->enqueue_single(
			'nowcrm-pref-link:' . $contact_id . ':' . bin2hex( random_bytes( 8 ) ),
			$contact_id,
			$address,
			(string) $this->contacts->get( $contact_id )['first_name'],
			(string) $this->contacts->get( $contact_id )['last_name'],
			__( '您的偏好設定連結', 'now-crm' ),
			$this->link_email_body( Tokens::url( $issued['token'] ) )
		);

		if ( is_wp_error( $enqueued ) ) {
			Throttle::release( $bucket );

			return $this->request_reply( false );
		}

		return $this->request_reply( true );
	}

	/**
	 * Contact plus public-list memberships for the center page.
	 *
	 * @return array{contact: array<string, mixed>, lists: array<int, array<string, mixed>>}|null
	 */
	public function context_for( int $contact_id ): ?array {
		$contact = $this->contacts->get( $contact_id );

		if ( ! $contact ) {
			return null;
		}

		$member = array();

		foreach ( $this->terms->for_contact( $contact_id ) as $term ) {
			if ( TermRepository::TYPE_LIST === (string) $term['type'] ) {
				$member[ (int) $term['id'] ] = true;
			}
		}

		$lists = array();

		foreach ( Config::public_list_ids() as $id ) {
			$term = $this->terms->get( $id );

			if ( ! $term || TermRepository::TYPE_LIST !== (string) $term['type'] ) {
				continue;
			}

			$lists[] = array(
				'id'       => (int) $term['id'],
				'name'     => (string) $term['name'],
				'selected' => isset( $member[ (int) $term['id'] ] ),
			);
		}

		return array(
			'contact' => $contact,
			'lists'   => $lists,
		);
	}

	/**
	 * Save profile fields and public list memberships. The `email` key is
	 * display-only and always ignored; address changes go through
	 * `request_email_change()`. Suppressed contacts may update info but their
	 * status is never touched here.
	 *
	 * @param array<string, mixed> $input Raw request data.
	 * @return array{ok: bool, message: string, contact: array<string, mixed>}|WP_Error
	 */
	public function save_profile( int $contact_id, array $input ) {
		$contact = $this->contacts->get( $contact_id );

		if ( ! $contact ) {
			return new WP_Error( 'nowcrm_pref_not_found', __( '找不到聯絡人。', 'now-crm' ) );
		}

		$patch = array();

		foreach ( array( 'first_name', 'last_name', 'phone' ) as $key ) {
			if ( array_key_exists( $key, $input ) ) {
				$patch[ $key ] = sanitize_text_field( (string) $input[ $key ] );
			}
		}

		$profile = ProfileFields::normalize( $input );

		if ( is_wp_error( $profile ) ) {
			return $profile;
		}

		foreach ( $profile as $key => $value ) {
			$patch[ $key ] = $value;
		}

		$updated = $this->contacts->update( $contact_id, $patch );

		if ( is_wp_error( $updated ) ) {
			return $updated;
		}

		if ( array_key_exists( 'lists', $input ) ) {
			$this->sync_public_lists( $contact_id, $input['lists'] );
		}

		return array(
			'ok'      => true,
			'message' => __( '已更新您的資料。', 'now-crm' ),
			'contact' => $this->contacts->get( $contact_id ),
		);
	}

	/**
	 * Unsubscribe from the preference center. Idempotent, mirroring the native
	 * unsubscribe endpoint minus its legacy token lookup: status, suppression,
	 * queued-job sweep and tracking behave the same.
	 *
	 * @return array{ok: bool, code: int, already: bool, message: string, contact?: array<string, mixed>}
	 */
	public function unsubscribe( int $contact_id, string $reason = '', string $source = self::SOURCE ): array {
		$contact = $this->contacts->get( $contact_id );

		if ( ! $contact ) {
			return array(
				'ok'      => false,
				'code'    => 404,
				'already' => false,
				'message' => __( '找不到對應的聯絡人。', 'now-crm' ),
			);
		}

		$already = Status::is_suppressed( (string) $contact['status'] );
		$note    = $already ? '' : $this->reason_note( $reason );

		if ( ! $already ) {
			$this->contacts->set_status( $contact_id, Status::UNSUBSCRIBED, substr( $source, 0, 191 ), $note );
			$this->suppressions->add( (string) $contact['email'], SuppressionRepository::REASON_UNSUBSCRIBED, $source, $contact_id );
			Config::pending_clear( $contact_id );
		}

		$this->jobs->suppress_email( (string) $contact['email'], 'unsubscribed' );

		$this->tracking->record(
			'unsub-pref:' . $contact_id,
			'unsubscribe',
			0,
			0,
			$contact_id,
			'',
			'',
			array( 'reason' => $note, 'source' => $source )
		);

		return array(
			'ok'      => true,
			'code'    => 200,
			'already' => $already,
			'message' => $already
				? __( '這個 Email 已經取消訂閱。', 'now-crm' )
				: __( '已取消訂閱，之後不會再收到行銷郵件。', 'now-crm' ),
			'contact' => $this->contacts->get( $contact_id ),
		);
	}

	/**
	 * Request an address change. The contact row is untouched until the new
	 * address owner clicks the confirmation link. Replies are identical whether
	 * the address is usable or not.
	 *
	 * @return array{ok: bool, sent: bool, unchanged: bool, message: string}|WP_Error
	 */
	public function request_email_change( int $contact_id, string $new_email ) {
		$contact = $this->contacts->get( $contact_id );

		if ( ! $contact ) {
			return new WP_Error( 'nowcrm_pref_not_found', __( '找不到聯絡人。', 'now-crm' ) );
		}

		$new = Tokens::normalize_email( $new_email );

		if ( ! is_email( $new ) ) {
			return new WP_Error( 'nowcrm_pref_invalid_email', __( 'Email 格式不正確。', 'now-crm' ) );
		}

		if ( $new === Tokens::normalize_email( (string) $contact['email'] ) ) {
			return array(
				'ok'        => true,
				'sent'      => false,
				'unchanged' => true,
				'message'   => __( 'Email 沒有變更。', 'now-crm' ),
			);
		}

		$usable = ! $this->suppressions->is_suppressed( $new );

		if ( $usable ) {
			$other = $this->contacts->find_by_email( $new );
			$usable = ! $other || (int) $other['id'] === $contact_id;
		}

		if ( $usable ) {
			$issued = Tokens::issue(
				Tokens::PURPOSE_EMAIL_CHANGE,
				$contact_id,
				(string) $contact['email'],
				max( 1, (int) Config::get( 'email_change_token_days', 2 ) ) * DAY_IN_SECONDS,
				array( 'new' => $new )
			);

			$usable = ! is_wp_error( $issued );

			if ( $usable ) {
				$enqueued = $this->enqueue_single(
					'nowcrm-email-change:' . $contact_id . ':' . bin2hex( random_bytes( 8 ) ),
					$contact_id,
					$new,
					(string) $contact['first_name'],
					(string) $contact['last_name'],
					__( '請確認您的新 Email', 'now-crm' ),
					$this->email_change_body( Tokens::url( $issued['token'] ) )
				);

				$usable = ! is_wp_error( $enqueued );
			}
		}

		return array(
			'ok'        => true,
			'sent'      => $usable,
			'unchanged' => false,
			'message'   => __( '如果新地址可以使用，確認信已經寄出，請點擊信中的連結完成變更。', 'now-crm' ),
		);
	}

	/**
	 * Apply a signed address change. A subscribed contact drops to
	 * `transactional` and gets a fresh double opt-in for the new address;
	 * suppressed statuses are kept and never lifted.
	 *
	 * @return array{ok: bool, already: bool, message: string, contact?: array<string, mixed>}|WP_Error
	 */
	public function confirm_email_change( string $raw_token ) {
		$decoded = Tokens::validate( $raw_token, Tokens::PURPOSE_EMAIL_CHANGE );

		if ( null === $decoded ) {
			return new WP_Error( 'nowcrm_email_change_invalid', __( '這個確認連結無效或已過期。', 'now-crm' ), array( 'status' => 410 ) );
		}

		$contact = $this->contacts->get( $decoded['contact_id'] );

		if ( ! $contact ) {
			return new WP_Error( 'nowcrm_email_change_invalid', __( '這個確認連結無效或已過期。', 'now-crm' ), array( 'status' => 410 ) );
		}

		$new = Tokens::normalize_email( (string) ( $decoded['extra']['new'] ?? '' ) );

		if ( ! is_email( $new ) ) {
			return new WP_Error( 'nowcrm_email_change_invalid', __( '這個確認連結無效或已過期。', 'now-crm' ), array( 'status' => 410 ) );
		}

		$current = Tokens::normalize_email( (string) $contact['email'] );

		if ( $new === $current ) {
			return array(
				'ok'      => true,
				'already' => true,
				'message' => __( 'Email 已經更新過了。', 'now-crm' ),
				'contact' => $contact,
			);
		}

		if ( $current !== $decoded['email'] ) {
			return new WP_Error( 'nowcrm_email_change_invalid', __( '這個確認連結無效或已過期。', 'now-crm' ), array( 'status' => 410 ) );
		}

		if ( $this->suppressions->is_suppressed( $new ) ) {
			return new WP_Error( 'nowcrm_email_change_unusable', __( '此地址無法使用。', 'now-crm' ) );
		}

		$other = $this->contacts->find_by_email( $new );

		if ( $other && (int) $other['id'] !== (int) $contact['id'] ) {
			return new WP_Error( 'nowcrm_email_change_unusable', __( '此地址無法使用。', 'now-crm' ) );
		}

		$was_subscribed = Status::SUBSCRIBED === (string) $contact['status'];

		$updated = $this->contacts->update( (int) $contact['id'], array( 'email' => $new ) );

		if ( is_wp_error( $updated ) ) {
			return $updated;
		}

		if ( $was_subscribed ) {
			$this->contacts->set_status( (int) $contact['id'], Status::TRANSACTIONAL, 'email-change' );
			$sent = $this->doi->send( (int) $contact['id'], array() );

			if ( is_wp_error( $sent ) && 'nowcrm_doi_throttled' !== $sent->get_error_code() ) {
				return $sent;
			}

			return array(
				'ok'      => true,
				'already' => false,
				'message' => __( 'Email 已更新，確認信已寄到新地址，請再點擊一次完成訂閱確認。', 'now-crm' ),
				'contact' => $this->contacts->get( (int) $contact['id'] ),
			);
		}

		return array(
			'ok'      => true,
			'already' => false,
			'message' => __( 'Email 已更新。', 'now-crm' ),
			'contact' => $this->contacts->get( (int) $contact['id'] ),
		);
	}

	/**
	 * Resolve a link-request target. Null means: reply generic, send nothing.
	 *
	 * @return array{0: int, 1: string}|null contact ID and address.
	 */
	private function sendable_link_target( string $email ): ?array {
		$normalized = Tokens::normalize_email( $email );

		if ( ! is_email( $normalized ) ) {
			return null;
		}

		$contact = $this->contacts->find_by_email( $normalized );

		if ( ! $contact ) {
			return null;
		}

		// Never mail bounced, complained, or spammed addresses from a public
		// form: it burns sending reputation. Unsubscribed contacts still get a
		// link so they can restore their own preferences.
		if ( in_array( (string) $contact['status'], array( Status::BOUNCED, Status::COMPLAINED, Status::SPAMMED ), true ) ) {
			return null;
		}

		return array( (int) $contact['id'], (string) $contact['email'] );
	}

	/**
	 * @return array{ok: bool, message: string, sent: bool}
	 */
	private function request_reply( bool $sent ): array {
		return array(
			'ok'      => true,
			'message' => self::request_message(),
			'sent'    => $sent,
		);
	}

	/**
	 * Attach/detach only public lists; every other membership is untouched.
	 *
	 * @param string|string[]|mixed $selected
	 */
	private function sync_public_lists( int $contact_id, $selected ): void {
		if ( ! is_array( $selected ) ) {
			$selected = array( $selected );
		}

		$want = array();

		foreach ( $selected as $id ) {
			$id = (int) $id;

			if ( $id > 0 && Config::is_list_public( $id ) ) {
				$want[] = $id;
			}
		}

		$want   = array_values( array_unique( $want ) );
		$public = Config::public_list_ids();

		$member = array();

		foreach ( $this->terms->for_contact( $contact_id ) as $term ) {
			if ( TermRepository::TYPE_LIST === (string) $term['type'] ) {
				$member[] = (int) $term['id'];
			}
		}

		foreach ( $want as $id ) {
			if ( ! in_array( $id, $member, true ) && $this->terms->get( $id ) ) {
				$this->terms->attach( $contact_id, $id );
			}
		}

		foreach ( array_intersect( $member, $public ) as $id ) {
			if ( ! in_array( $id, $want, true ) ) {
				$this->terms->detach( $contact_id, $id );
			}
		}
	}

	/**
	 * Enqueue one transactional consent email through the shared queue.
	 *
	 * @return int|WP_Error job ID.
	 */
	private function enqueue_single( string $job_key, int $contact_id, string $recipient, string $first_name, string $last_name, string $subject, string $html ) {
		return $this->jobs->enqueue(
			array(
				'job_key'        => $job_key,
				'kind'           => DoubleOptin::JOB_KIND,
				'contact_id'     => $contact_id,
				'recipient'      => $recipient,
				'recipient_name' => trim( $first_name . ' ' . $last_name ),
				'subject'        => $subject,
				'payload'        => array(
					'template' => array(
						'mode'         => 'html',
						'content'      => $html,
						'subject'      => $subject,
						'preheader'    => '',
						'footer_text'  => '{{manage_url}}',
						'from_name'    => '',
						'from_address' => '',
						'reply_to'     => '',
					),
				),
			)
		);
	}

	private function link_email_body( string $url ): string {
		return '<h2>' . esc_html__( '您的偏好設定連結', 'now-crm' ) . '</h2>'
			. '<p>' . esc_html__( '點擊下方連結即可管理您的訂閱資料，不需要登入：', 'now-crm' ) . '</p>'
			. '<p><a href="' . esc_url( $url ) . '">' . esc_html__( '管理我的訂閱偏好', 'now-crm' ) . '</a></p>'
			. '<p>' . esc_html__( '如果這不是您本人操作，請忽略此信。', 'now-crm' ) . '</p>';
	}

	private function email_change_body( string $url ): string {
		return '<h2>' . esc_html__( '請確認您的新 Email', 'now-crm' ) . '</h2>'
			. '<p>' . esc_html__( '點擊下方連結即可完成地址變更：', 'now-crm' ) . '</p>'
			. '<p><a href="' . esc_url( $url ) . '">' . esc_html__( '確認新 Email', 'now-crm' ) . '</a></p>'
			. '<p>' . esc_html__( '如果這不是您本人操作，請忽略此信。', 'now-crm' ) . '</p>';
	}

	private function reason_note( string $reason ): string {
		$labels = array(
			'not_signed_up' => '從未訂閱',
			'too_many'      => '信件太多',
			'irrelevant'    => '內容不相關',
			'other'         => '其他原因',
		);

		return $labels[ $reason ] ?? '';
	}
}
