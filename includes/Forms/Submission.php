<?php
/**
 * Public submission processing: nonce, honeypot, rate bound, validation,
 * contact upsert, entry storage, term assignment, and the DOI handoff action.
 *
 * @package NowCRM
 */

namespace NowCRM\Forms;

use NowCRM\Contacts\ContactRepository;
use NowCRM\Terms\TermRepository;
use WP_Error;

defined( 'ABSPATH' ) || exit;

final class Submission {

	public const ACTION = 'nowcrm_form_submit';

	private const RATE_LIMIT  = 5;
	private const RATE_WINDOW = 600;

	private FormRepository $forms;
	private EntryRepository $entries;
	private TermRepository $terms;
	private ContactRepository $contacts;

	public function __construct() {
		$this->forms    = new FormRepository();
		$this->entries  = new EntryRepository();
		$this->terms    = new TermRepository();
		$this->contacts = new ContactRepository();
	}

	/**
	 * Register both admin-post variants: logged-in and logged-out.
	 */
	public function register(): void {
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle' ) );
		add_action( 'admin_post_nopriv_' . self::ACTION, array( $this, 'handle' ) );
	}

	/**
	 * Handle a posted public form.
	 */
	public function handle(): void {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( (string) $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';

		if ( 'POST' !== $method ) {
			wp_die( esc_html__( '請使用表單送出。', 'now-crm' ), '', array( 'response' => 405 ) );
		}

		$form_id = isset( $_POST['nowcrm_form_id'] ) && is_scalar( $_POST['nowcrm_form_id'] )
			? absint( wp_unslash( (string) $_POST['nowcrm_form_id'] ) )
			: 0;

		$return = isset( $_POST['nowcrm_form_return'] ) && is_string( $_POST['nowcrm_form_return'] )
			? esc_url_raw( wp_unslash( $_POST['nowcrm_form_return'] ) )
			: '';
		$return = wp_validate_redirect( $return, home_url( '/' ) );

		$form = $form_id > 0 ? $this->forms->get( $form_id ) : null;

		if ( ! $form ) {
			wp_die( esc_html__( '找不到表單。', 'now-crm' ), '', array( 'response' => 404 ) );
		}

		$nonce = isset( $_POST['_nowcrm_form_nonce'] ) && is_string( $_POST['_nowcrm_form_nonce'] )
			? sanitize_text_field( wp_unslash( $_POST['_nowcrm_form_nonce'] ) )
			: '';

		if ( '' === $nonce || ! wp_verify_nonce( $nonce, self::ACTION . '_' . $form_id ) ) {
			wp_die( esc_html__( '表單驗證失敗，請重新整理頁面再試。', 'now-crm' ), '', array( 'response' => 403 ) );
		}

		$honeypot = isset( $_POST['nowcrm_form_hp'] )
			? ( is_string( $_POST['nowcrm_form_hp'] ) ? trim( wp_unslash( $_POST['nowcrm_form_hp'] ) ) : 'bot' )
			: '';

		if ( '' !== $honeypot ) {
			// A filled trap is a bot. Report success without storing anything.
			$this->redirect( $return, array( 'nowcrm_form_success' => $form_id ) );
		}

		if ( $this->is_rate_limited( $form_id ) ) {
			$this->redirect( $return, array( 'nowcrm_form_error' => 'rate_limited' ) );
		}

		$values = isset( $_POST['nowcrm_fields'] ) && is_array( $_POST['nowcrm_fields'] )
			? wp_unslash( $_POST['nowcrm_fields'] )
			: array();

		$result = $this->process( $form_id, $values );

		if ( is_wp_error( $result ) ) {
			if ( 'nowcrm_form_invalid' === $result->get_error_code() ) {
				$data  = (array) $result->get_error_data();
				$token = isset( $_POST['nowcrm_form_token'] ) && is_string( $_POST['nowcrm_form_token'] )
					? sanitize_key( wp_unslash( $_POST['nowcrm_form_token'] ) )
					: '';

				if ( '' === $token ) {
					$token = sanitize_key( wp_generate_password( 20, false, false ) );
				}

				set_transient(
					self::error_transient_key( $token ),
					array(
						'form_id' => $form_id,
						'errors'  => (array) ( $data['field_errors'] ?? array() ),
						'values'  => (array) ( $data['values'] ?? array() ),
						'message' => $result->get_error_message(),
					),
					10 * MINUTE_IN_SECONDS
				);

				$this->redirect(
					$return,
					array(
						'nowcrm_form_error' => 'validation',
						'nowcrm_form_token' => $token,
					)
				);
			}

			$this->redirect( $return, array( 'nowcrm_form_error' => 'error' ) );
		}

		$this->redirect( $return, array( 'nowcrm_form_success' => $form_id ) );
	}

	/**
	 * Validate, upsert the contact, store the entry, assign terms, and emit the
	 * handoff action. Called by the request handler; also safe to call directly.
	 *
	 * @param int                  $form_id Form ID.
	 * @param array<string, mixed> $values  Raw submitted values keyed by field key.
	 * @return array{entry_id:int, contact_id:int, contact_created:bool, data:array<string,mixed>}|WP_Error
	 */
	public function process( int $form_id, array $values ) {
		$form = $this->forms->get( $form_id );

		if ( ! $form ) {
			return new WP_Error( 'nowcrm_form_not_found', __( '找不到表單。', 'now-crm' ) );
		}

		$clean = Fields::validate( $form['fields'], $values );

		if ( is_wp_error( $clean ) ) {
			return $clean;
		}

		$email_key = (string) $form['email_key'];
		$email     = $email_key !== '' ? sanitize_email( (string) ( $clean[ $email_key ] ?? '' ) ) : '';

		if ( '' === $email || ! is_email( $email ) ) {
			return new WP_Error(
				'nowcrm_form_invalid',
				__( '表單缺少有效的 Email。', 'now-crm' ),
				array(
					'field_errors' => array(
						$email_key => __( '請填寫有效的 Email。', 'now-crm' ),
					),
					'values'       => $clean,
				)
			);
		}

		// One upsert per submission. Existing contacts keep their status; new
		// contacts start subscribed with a form source.
		$contact_result = $this->contacts->get_or_create(
			$email,
			array(
				'source' => 'form:' . $form_id,
			)
		);

		if ( is_wp_error( $contact_result ) ) {
			return $contact_result;
		}

		$contact_id = (int) ( $contact_result['contact']['id'] ?? 0 );

		if ( $contact_id <= 0 ) {
			return new WP_Error( 'nowcrm_form_contact', __( '無法建立聯絡人。', 'now-crm' ) );
		}

		$snapshot = array();

		foreach ( $form['fields'] as $field ) {
			$key = (string) ( $field['key'] ?? '' );

			$snapshot[] = array(
				'key'   => $key,
				'label' => (string) ( $field['label'] ?? $key ),
				'type'  => (string) ( $field['type'] ?? Fields::TEXT ),
				'value' => $clean[ $key ] ?? '',
			);
		}

		$payload = array(
			'email'           => $email,
			'contact_id'      => $contact_id,
			'contact_created' => (bool) $contact_result['created'],
			'submitted_at'    => gmdate( 'Y-m-d H:i:s' ),
			'lists'           => $form['lists'],
			'tags'            => $form['tags'],
			'double_opt_in'   => (bool) $form['double_opt_in'],
			'fields'          => $snapshot,
		);

		$entry_id = $this->entries->create( $form_id, $payload );

		if ( is_wp_error( $entry_id ) ) {
			return $entry_id;
		}

		$payload['entry_id'] = $entry_id;

		foreach ( $form['lists'] as $term_id ) {
			$this->attach_term( $contact_id, (int) $term_id, TermRepository::TYPE_LIST );
		}

		foreach ( $form['tags'] as $term_id ) {
			$this->attach_term( $contact_id, (int) $term_id, TermRepository::TYPE_TAG );
		}

		/**
		 * Double-opt-in and automation handoff. NOW does not send mail here.
		 *
		 * @param int                  $entry_id   Entry post ID.
		 * @param int                  $form_id    Form post ID.
		 * @param int                  $contact_id Contact row ID.
		 * @param array<string, mixed> $payload    Email, fields snapshot, lists, tags, DOI flag.
		 */
		do_action( 'nowcrm_form_submitted', $entry_id, $form_id, $contact_id, $payload );

		return array(
			'entry_id'        => (int) $entry_id,
			'contact_id'      => $contact_id,
			'contact_created' => (bool) $contact_result['created'],
			'data'            => $payload,
		);
	}

	/**
	 * Transient key for one validation token.
	 */
	public static function error_transient_key( string $token ): string {
		return 'nowcrm_form_errors_' . substr( sanitize_key( $token ), 0, 40 );
	}

	/**
	 * Attach a term only when its stored type still matches the form setting.
	 */
	private function attach_term( int $contact_id, int $term_id, string $type ): void {
		if ( $contact_id <= 0 || $term_id <= 0 ) {
			return;
		}

		$term = $this->terms->get( $term_id );

		if ( $term && $type === ( $term['type'] ?? '' ) ) {
			$this->terms->attach( $contact_id, $term_id );
		}
	}

	/**
	 * Count and enforce the per-IP, per-form window.
	 */
	private function is_rate_limited( int $form_id ): bool {
		$ip    = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '';
		$key   = 'nowcrm_form_rate_' . md5( $ip ) . '_' . $form_id;
		$count = (int) get_transient( $key );
		$limit = (int) apply_filters( 'nowcrm_form_rate_limit', self::RATE_LIMIT, $form_id );

		if ( $limit > 0 && $count >= $limit ) {
			return true;
		}

		set_transient( $key, $count + 1, self::RATE_WINDOW );

		return false;
	}

	/**
	 * Redirect back to the embedding page with only form notice arguments.
	 *
	 * @param array<string, mixed> $args Arguments to add.
	 */
	private function redirect( string $url, array $args ): void {
		$url = remove_query_arg(
			array( 'nowcrm_form_success', 'nowcrm_form_error', 'nowcrm_form_token' ),
			$url
		);

		wp_safe_redirect( add_query_arg( $args, $url ) );
		exit;
	}
}
