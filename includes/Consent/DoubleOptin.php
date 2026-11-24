<?php
/**
 * Double opt-in: explicit consent by signed confirmation only.
 *
 * A contact waiting for confirmation may rest at `pending` (or at
 * `transactional` for legacy rows): marketing gates already exclude both, and
 * only `confirm()` may promote it to `subscribed`. Attaching lists never
 * sends mail; callers use `enroll()` or `send()` explicitly. Suppressed
 * contacts (unsubscribed, bounced, complained, spammed, or suppression-listed)
 * can never be promoted — confirmation reports an error instead.
 *
 * Delivery reuses the existing queue and renderer. Confirmation mail is a
 * transactional single send, queued with the `consent` kind: the queue skips
 * only the subscribed-status gate for it, while the global suppression gate
 * still applies.
 *
 * @package NowCRM
 */

namespace NowCRM\Consent;

use NowCRM\Contacts\ContactRepository;
use NowCRM\Contacts\Status;
use NowCRM\Email\EmailJobRepository;
use NowCRM\Email\EmailRenderer;
use NowCRM\Email\SuppressionRepository;
use NowCRM\Terms\TermRepository;
use WP_Error;

defined( 'ABSPATH' ) || exit;

final class DoubleOptin {

	public const SOURCE = 'double-optin';

	/** Queue kind for confirmation and link-delivery mail. */
	public const JOB_KIND = 'consent';

	public const JOB_PREFIX = 'nowcrm-doi:';

	private ContactRepository $contacts;
	private TermRepository $terms;
	private SuppressionRepository $suppressions;
	private EmailJobRepository $jobs;
	private EmailRenderer $renderer;

	public function __construct(
		?ContactRepository $contacts = null,
		?TermRepository $terms = null,
		?SuppressionRepository $suppressions = null,
		?EmailJobRepository $jobs = null,
		?EmailRenderer $renderer = null
	) {
		$this->contacts     = $contacts ?: new ContactRepository();
		$this->terms        = $terms ?: new TermRepository();
		$this->suppressions = $suppressions ?: new SuppressionRepository();
		$this->jobs         = $jobs ?: new EmailJobRepository();
		$this->renderer     = $renderer ?: new EmailRenderer();
	}

	/**
	 * Explicit subscription entry point. Never runs on mere list attachment.
	 *
	 * @param string               $email    Address to enroll.
	 * @param array<string, mixed> $data     Contact fields (names, phone, profile keys, source).
	 * @param int[]                $list_ids Lists to attach.
	 * @return array{ok: bool, contact_id: int, status: string, sent: bool, job_id?: int}|WP_Error
	 */
	public function enroll( string $email, array $data = array(), array $list_ids = array() ) {
		$email = Tokens::normalize_email( $email );

		if ( ! is_email( $email ) ) {
			return new WP_Error( 'nowcrm_doi_invalid_email', __( 'Email 格式不正確。', 'now-crm' ) );
		}

		if ( $this->suppressions->is_suppressed( $email ) ) {
			return new WP_Error( 'nowcrm_doi_suppressed', __( '此地址無法訂閱。', 'now-crm' ) );
		}

		$list_ids = $this->clean_lists( $list_ids );
		$existing = $this->contacts->find_by_email( $email );

		if ( $existing ) {
			$contact_id = (int) $existing['id'];

			if ( Status::is_suppressed( (string) $existing['status'] ) ) {
				return new WP_Error( 'nowcrm_doi_suppressed', __( '此地址無法訂閱。', 'now-crm' ) );
			}

			$this->attach_lists( $contact_id, $list_ids );

			if ( Status::SUBSCRIBED === (string) $existing['status'] ) {
				Config::pending_clear( $contact_id );

				return array(
					'ok'         => true,
					'contact_id' => $contact_id,
					'status'     => Status::SUBSCRIBED,
					'sent'       => false,
				);
			}

			$sent = $this->send( $contact_id, $list_ids );

			if ( is_wp_error( $sent ) ) {
				return $sent;
			}

			return array(
				'ok'         => true,
				'contact_id' => $contact_id,
				'status'     => (string) $existing['status'],
				'sent'       => (bool) $sent['sent'],
				'job_id'     => $sent['job_id'] ?? 0,
			);
		}

		$created = $this->contacts->create(
			array_merge(
				$data,
				array(
					'email'  => $email,
					'status' => Status::TRANSACTIONAL,
					'source' => isset( $data['source'] ) ? substr( sanitize_text_field( (string) $data['source'] ), 0, 50 ) : self::SOURCE,
				)
			)
		);

		if ( is_wp_error( $created ) ) {
			return $created;
		}

		$contact_id = (int) $created;
		$this->attach_lists( $contact_id, $list_ids );

		$sent = $this->send( $contact_id, $list_ids );

		if ( is_wp_error( $sent ) ) {
			return $sent;
		}

		return array(
			'ok'         => true,
			'contact_id' => $contact_id,
			'status'     => Status::TRANSACTIONAL,
			'sent'       => (bool) $sent['sent'],
			'job_id'     => $sent['job_id'] ?? 0,
		);
	}

	/**
	 * Queue a confirmation email for a contact. Explicit callers only.
	 *
	 * @param int[] $list_ids Lists recorded in the token and pending state.
	 * @return array{ok: bool, sent: bool, already?: bool, job_id?: int, retry_after?: int}|WP_Error
	 */
	public function send( int $contact_id, array $list_ids = array() ) {
		$contact = $this->contacts->get( $contact_id );

		if ( ! $contact ) {
			return new WP_Error( 'nowcrm_doi_not_found', __( '找不到聯絡人。', 'now-crm' ) );
		}

		$email = (string) $contact['email'];

		if ( Status::is_suppressed( (string) $contact['status'] ) || $this->suppressions->is_suppressed( $email ) ) {
			Config::pending_clear( $contact_id );

			return new WP_Error( 'nowcrm_doi_suppressed', __( '此地址無法訂閱。', 'now-crm' ) );
		}

		if ( Status::SUBSCRIBED === (string) $contact['status'] ) {
			Config::pending_clear( $contact_id );

			return array(
				'ok'      => true,
				'sent'    => false,
				'already' => true,
			);
		}

		$config = $this->config_for_lists( $list_ids );
		$window = max( 1, (int) $config['resend_throttle_minutes'] ) * MINUTE_IN_SECONDS;
		$bucket = 'doi:' . $contact_id;

		if ( ! Throttle::allow( $bucket, $window ) ) {
			return new WP_Error(
				'nowcrm_doi_throttled',
				__( '確認信剛剛才寄出，請稍後再試。', 'now-crm' ),
				array( 'retry_after' => $window )
			);
		}

		$issued = Tokens::issue(
			Tokens::PURPOSE_CONFIRM,
			$contact_id,
			$email,
			max( 1, (int) Config::get( 'confirm_token_days', 7 ) ) * DAY_IN_SECONDS,
			array( 'lists' => $this->clean_lists( $list_ids ) )
		);

		if ( is_wp_error( $issued ) ) {
			Throttle::release( $bucket );

			return $issued;
		}

		$template = $this->template_for( $config, $contact, Tokens::url( $issued['token'] ) );
		$job_id   = $this->jobs->enqueue(
			array(
				'job_key'        => self::JOB_PREFIX . $contact_id . ':' . bin2hex( random_bytes( 8 ) ),
				'kind'           => self::JOB_KIND,
				'contact_id'     => $contact_id,
				'recipient'      => $email,
				'recipient_name' => trim( (string) $contact['first_name'] . ' ' . (string) $contact['last_name'] ),
				'subject'        => $template['subject'],
				'payload'        => array( 'template' => $template ),
			)
		);

		if ( is_wp_error( $job_id ) ) {
			Throttle::release( $bucket );

			return new WP_Error( 'nowcrm_doi_send_failed', __( '確認信目前無法寄出，請稍後再試。', 'now-crm' ) );
		}

		Config::pending_set( $contact_id, $email, $this->clean_lists( $list_ids ) );

		return array(
			'ok'     => true,
			'sent'   => true,
			'job_id' => (int) $job_id,
		);
	}

	/**
	 * Alias that reads as a resend. Same throttle applies.
	 *
	 * @param int[] $list_ids
	 * @return array{ok: bool, sent: bool, already?: bool, job_id?: int, retry_after?: int}|WP_Error
	 */
	public function resend( int $contact_id, array $list_ids = array() ) {
		return $this->send( $contact_id, $list_ids );
	}

	/**
	 * Apply a signed confirmation. Pending becomes subscribed here and nowhere
	 * else. Idempotent: a repeated click reports success without new writes.
	 *
	 * @return array{ok: bool, code: int, already: bool, message: string, contact?: array<string, mixed>}|WP_Error
	 */
	public function confirm( string $raw_token ) {
		$decoded = Tokens::validate( $raw_token, Tokens::PURPOSE_CONFIRM );

		if ( null === $decoded ) {
			return new WP_Error( 'nowcrm_doi_invalid', __( '這個確認連結無效或已過期。', 'now-crm' ), array( 'status' => 410 ) );
		}

		$contact = $this->contacts->get( $decoded['contact_id'] );

		if ( ! $contact ) {
			return new WP_Error( 'nowcrm_doi_invalid', __( '這個確認連結無效或已過期。', 'now-crm' ), array( 'status' => 410 ) );
		}

		if ( Tokens::normalize_email( (string) $contact['email'] ) !== $decoded['email'] ) {
			return new WP_Error( 'nowcrm_doi_invalid', __( '這個確認連結無效或已過期。', 'now-crm' ), array( 'status' => 410 ) );
		}

		$suppressed = Status::is_suppressed( (string) $contact['status'] )
			|| $this->suppressions->is_suppressed( (string) $contact['email'] );

		if ( $suppressed ) {
			Config::pending_clear( (int) $contact['id'] );

			return new WP_Error( 'nowcrm_doi_suppressed', __( '此地址無法訂閱。', 'now-crm' ) );
		}

		if ( Status::SUBSCRIBED === (string) $contact['status'] ) {
			Config::pending_clear( (int) $contact['id'] );

			return array(
				'ok'      => true,
				'code'    => 200,
				'already' => true,
				'message' => __( '訂閱已經確認過了。', 'now-crm' ),
				'contact' => $this->contacts->get( (int) $contact['id'] ),
			);
		}

		$pending = Config::pending_get( (int) $contact['id'] );
		$lists   = $pending ? $pending['lists'] : array();

		if ( ! $lists && isset( $decoded['extra']['lists'] ) && is_array( $decoded['extra']['lists'] ) ) {
			$lists = $this->clean_lists( $decoded['extra']['lists'] );
		}

		$updated = $this->contacts->set_status( (int) $contact['id'], Status::SUBSCRIBED, self::SOURCE );

		if ( is_wp_error( $updated ) ) {
			return $updated;
		}

		$this->attach_lists( (int) $contact['id'], $lists );
		Config::pending_clear( (int) $contact['id'] );

		do_action( 'nowcrm_contact_confirmed', (int) $contact['id'], array( 'source' => self::SOURCE ) );

		return array(
			'ok'      => true,
			'code'    => 200,
			'already' => false,
			'message' => __( '訂閱已確認，感謝您！', 'now-crm' ),
			'contact' => $this->contacts->get( (int) $contact['id'] ),
		);
	}

	/**
	 * Render the confirmation email (or an admin preview) without sending.
	 * Previews use an inert URL that can never confirm.
	 *
	 * @param array<string, mixed> $override subject, preheader, body, after message.
	 * @return array{subject: string, html: string, text: string}
	 */
	public function preview( array $override = array(), ?int $contact_id = null ): array {
		$config = $this->config_for_lists( array() );

		foreach ( array( 'email_subject', 'email_preheader', 'email_body', 'after_confirm_message' ) as $key ) {
			if ( isset( $override[ $key ] ) && is_string( $override[ $key ] ) && '' !== trim( $override[ $key ] ) ) {
				$config[ $key ] = 'email_body' === $key || 'after_confirm_message' === $key
					? $this->renderer->sanitize_raw_html( $override[ $key ] )
					: sanitize_text_field( $override[ $key ] );
			}
		}

		$contact = $contact_id ? $this->contacts->get( $contact_id ) : null;

		if ( ! $contact ) {
			$contact = array(
				'first_name' => __( '預覽', 'now-crm' ),
				'last_name'  => '',
				'email'      => 'preview@example.test',
			);
		}

		$template = $this->template_for( $config, $contact, '#nowcrm-doi-preview' );

		return $this->renderer->render( $template, $this->render_context( $contact ) );
	}

	/**
	 * Effective content: the single requested list's override wins, otherwise
	 * the global settings.
	 *
	 * @param int[] $list_ids
	 * @return array{mode: string, email_subject: string, email_preheader: string, email_body: string, after_confirm_message: string, resend_throttle_minutes: int}
	 */
	public function config_for_lists( array $list_ids ): array {
		$list_ids = $this->clean_lists( $list_ids );

		if ( 1 === count( $list_ids ) ) {
			$config = Config::list_config( $list_ids[0] );

			if ( Config::LIST_REQUIRE === $config['mode'] || Config::LIST_SKIP === $config['mode'] ) {
				return $config;
			}
		}

		$global = Config::all()['double_optin'];

		return array(
			'mode'                    => Config::LIST_INHERIT,
			'email_subject'           => (string) $global['email_subject'],
			'email_preheader'         => (string) $global['email_preheader'],
			'email_body'              => (string) $global['email_body'],
			'after_confirm_message'   => (string) $global['after_confirm_message'],
			'resend_throttle_minutes' => (int) $global['resend_throttle_minutes'],
		);
	}

	/**
	 * Whether double opt-in is required for a set of lists.
	 *
	 * @param int[] $list_ids
	 */
	public function is_required( array $list_ids ): bool {
		return Config::is_double_optin_required( $list_ids );
	}

	/**
	 * Frozen queue template for one confirmation send.
	 *
	 * @param array<string, mixed> $config  Effective content.
	 * @param array<string, mixed> $contact Contact row.
	 */
	private function template_for( array $config, array $contact, string $confirm_url ): array {
		$body = str_replace( '{{confirm_url}}', esc_url( $confirm_url ), (string) $config['email_body'] );

		return array(
			'mode'         => 'html',
			'content'      => $body,
			'subject'      => (string) $config['email_subject'],
			'preheader'    => (string) $config['email_preheader'],
			'footer_text'  => '{{manage_url}}',
			'from_name'    => '',
			'from_address' => '',
			'reply_to'     => '',
		);
	}

	/**
	 * Renderer context for preview sends.
	 *
	 * @param array<string, mixed> $contact Contact row.
	 */
	private function render_context( array $contact ): array {
		return array(
			'first_name' => (string) ( $contact['first_name'] ?? '' ),
			'last_name'  => (string) ( $contact['last_name'] ?? '' ),
			'email'      => (string) ( $contact['email'] ?? '' ),
			'site_name'  => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			'site_url'   => home_url( '/' ),
		);
	}

	/**
	 * Attach existing lists of the right type, ignoring the rest.
	 *
	 * @param int[] $list_ids
	 */
	private function attach_lists( int $contact_id, array $list_ids ): void {
		foreach ( $this->clean_lists( $list_ids ) as $id ) {
			$term = $this->terms->get( $id );

			if ( $term && TermRepository::TYPE_LIST === (string) $term['type'] ) {
				$this->terms->attach( $contact_id, $id );
			}
		}
	}

	/**
	 * Keep positive integer IDs only.
	 *
	 * @param mixed[] $list_ids
	 * @return int[]
	 */
	private function clean_lists( array $list_ids ): array {
		$clean = array();

		foreach ( $list_ids as $id ) {
			$id = (int) $id;

			if ( $id > 0 ) {
				$clean[] = $id;
			}
		}

		return array_values( array_unique( $clean ) );
	}
}
