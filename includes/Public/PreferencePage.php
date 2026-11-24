<?php
/**
 * Public preference center: token-capped page plus `[nowcrm_preferences]`.
 *
 * GET renders; POST mutates. The token in the URL is the capability — no nonce
 * is required, and CSRF is contained because the token is unguessable,
 * purpose-scoped and every action posts back with it. GET never mutates,
 * except the double opt-in confirmation click, which mirrors the reference
 * single-click confirm. The address-change confirmation renders first and
 * applies on POST so scanners cannot rewrite contact data by prefetching.
 *
 * SEAM: register in `Plugin::modules()` as `Public\PreferencePage::class`.
 *
 * @package NowCRM
 */

namespace NowCRM\Public;

use NowCRM\Consent\Config;
use NowCRM\Consent\DoubleOptin;
use NowCRM\Consent\Preferences;
use NowCRM\Consent\Tokens;
use NowCRM\Contacts\ProfileFields;

defined( 'ABSPATH' ) || exit;

final class PreferencePage {

	public const QUERY_VAR = 'nowcrm_preferences';
	public const SHORTCODE  = 'nowcrm_preferences';

	private Preferences $prefs;
	private DoubleOptin $doi;

	/** Result of a token-less request POST, shown by the shortcode. */
	private ?array $request_result = null;

	public function __construct() {
		$this->prefs = new Preferences();
		$this->doi   = new DoubleOptin();
	}

	/**
	 * Hooks.
	 */
	public function register(): void {
		add_action( 'template_redirect', array( $this, 'maybe_handle' ), 1 );
		add_shortcode( self::SHORTCODE, array( $this, 'shortcode' ) );
	}

	/**
	 * Endpoint URL carrying a token.
	 */
	public static function url( string $raw_token ): string {
		return add_query_arg(
			array(
				self::QUERY_VAR => '1',
				'token'         => $raw_token,
			),
			home_url( '/' )
		);
	}

	/**
	 * Intercept the endpoint and token-less request posts.
	 */
	public function maybe_handle(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- token is the capability; public request form is rate-limited.
		if ( isset( $_POST['nowcrm_pref_request'] ) ) {
			$email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( (string) $_POST['email'] ) ) : '';
			$ip    = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '';

			$this->request_result = $this->prefs->request_link( $email, $ip );

			if ( isset( $_GET[ self::QUERY_VAR ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- token is the capability.
				$this->render_document( __( '偏好設定', 'now-crm' ), $this->request_fragment( $this->request_result['message'] ) );
				exit;
			}

			return;
		}

		if ( ! isset( $_GET[ self::QUERY_VAR ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- token is the capability.
			return;
		}

		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( (string) $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
		$token  = '';

		if ( 'POST' === $method ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- token is the capability.
			$token = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['token'] ) ) : '';
		} else {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- token is the capability.
			$token = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['token'] ) ) : '';
		}

		if ( 'POST' === $method ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- token is the capability.
			$action = isset( $_POST['nowcrm_consent_action'] ) ? sanitize_key( wp_unslash( (string) $_POST['nowcrm_consent_action'] ) ) : '';
			$this->handle_post( $token, $action );
			exit;
		}

		$this->handle_get( $token );
		exit;
	}

	/**
	 * Shortcode: center when a manage token is present, else the request form.
	 *
	 * @param array<string, mixed>|string $atts
	 */
	public function shortcode( $atts ): string {
		$atts = is_array( $atts ) ? $atts : array();

		$token = isset( $atts['token'] ) ? sanitize_text_field( (string) $atts['token'] ) : '';

		if ( '' === $token && isset( $_GET['token'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- token is the capability.
			$token = sanitize_text_field( wp_unslash( (string) $_GET['token'] ) );
		}

		if ( null !== $this->request_result ) {
			return $this->request_fragment( $this->request_result['message'] );
		}

		$decoded = Tokens::validate( $token, Tokens::PURPOSE_MANAGE );

		if ( null === $decoded ) {
			return $this->request_fragment( '' );
		}

		$context = $this->prefs->context_for( $decoded['contact_id'] );

		if ( null === $context ) {
			return $this->request_fragment( '' );
		}

		return $this->center_fragment( $context, $token, '' );
	}

	/**
	 * POST dispatcher. Every branch re-validates purpose before acting.
	 */
	private function handle_post( string $token, string $action ): void {
		if ( 'request' === $action ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- rate-limited public form.
			$email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( (string) $_POST['email'] ) ) : '';
			$ip    = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '';

			$result = $this->prefs->request_link( $email, $ip );

			$this->render_document( __( '偏好設定', 'now-crm' ), $this->request_fragment( $result['message'] ) );
			return;
		}

		$decoded = Tokens::decode( $token );

		if ( null === $decoded ) {
			$this->render_invalid();
			return;
		}

		if ( Tokens::PURPOSE_MANAGE === $decoded['purpose'] ) {
			$this->handle_manage_post( $decoded['contact_id'], $token, $action );
			return;
		}

		if ( Tokens::PURPOSE_EMAIL_CHANGE === $decoded['purpose'] && 'confirm_change' === $action ) {
			$result = $this->prefs->confirm_email_change( $token );

			if ( is_wp_error( $result ) ) {
				$this->render_document( __( '確認 Email', 'now-crm' ), $this->notice( $result->get_error_message(), false ) );
				return;
			}

			$context = $this->prefs->context_for( (int) $result['contact']['id'] );
			$inner   = $this->notice( $result['message'], true );

			if ( null !== $context ) {
				$issued = Tokens::issue(
					Tokens::PURPOSE_MANAGE,
					(int) $result['contact']['id'],
					(string) $result['contact']['email'],
					max( 1, (int) Config::get( 'manage_token_days', 90 ) ) * DAY_IN_SECONDS
				);

				if ( ! is_wp_error( $issued ) ) {
					$inner .= $this->center_fragment( $context, $issued['token'], '' );
				}
			}

			$this->render_document( __( '確認 Email', 'now-crm' ), $inner );
			return;
		}

		$this->render_invalid();
	}

	/**
	 * POST actions inside the manage center.
	 */
	private function handle_manage_post( int $contact_id, string $token, string $action ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- token is the capability.
		$input = $_POST;

		if ( 'unsubscribe' === $action ) {
			$reason = isset( $input['reason'] ) ? sanitize_key( wp_unslash( (string) $input['reason'] ) ) : '';
			$result = $this->prefs->unsubscribe( $contact_id, $reason );

			$this->render_document( __( '取消訂閱', 'now-crm' ), $this->notice( $result['message'], (bool) $result['ok'] ) );
			return;
		}

		if ( 'change_email' === $action ) {
			$new    = isset( $input['new_email'] ) ? sanitize_email( wp_unslash( (string) $input['new_email'] ) ) : '';
			$result = $this->prefs->request_email_change( $contact_id, $new );

			$message = is_wp_error( $result ) ? $result->get_error_message() : $result['message'];
			$context = $this->prefs->context_for( $contact_id );
			$inner   = $this->notice( $message, ! is_wp_error( $result ) );

			if ( null !== $context ) {
				$inner .= $this->center_fragment( $context, $token, '' );
			}

			$this->render_document( __( '偏好設定', 'now-crm' ), $inner );
			return;
		}

		$result  = $this->prefs->save_profile( $contact_id, is_array( $input ) ? $input : array() );
		$message = is_wp_error( $result ) ? $result->get_error_message() : $result['message'];
		$context = $this->prefs->context_for( $contact_id );
		$inner   = $this->notice( $message, ! is_wp_error( $result ) );

		if ( null !== $context ) {
			$inner .= $this->center_fragment( $context, $token, '' );
		}

		$this->render_document( __( '偏好設定', 'now-crm' ), $inner );
	}

	/**
	 * GET dispatcher.
	 */
	private function handle_get( string $token ): void {
		if ( '' === $token ) {
			$this->render_document( __( '偏好設定', 'now-crm' ), $this->request_fragment( '' ) );
			return;
		}

		$decoded = Tokens::decode( $token );

		if ( null === $decoded ) {
			$this->render_invalid();
			return;
		}

		if ( Tokens::PURPOSE_MANAGE === $decoded['purpose'] ) {
			$context = $this->prefs->context_for( $decoded['contact_id'] );

			if ( null === $context ) {
				$this->render_invalid();
				return;
			}

			$this->render_document( __( '偏好設定', 'now-crm' ), $this->center_fragment( $context, $token, '' ) );
			return;
		}

		if ( Tokens::PURPOSE_CONFIRM === $decoded['purpose'] ) {
			$result = $this->doi->confirm( $token );

			if ( is_wp_error( $result ) ) {
				$this->render_document( __( '確認訂閱', 'now-crm' ), $this->notice( $result->get_error_message(), false ) );
				return;
			}

			$config = $this->doi->config_for_lists( array() );

			$this->render_document( __( '確認訂閱', 'now-crm' ), $result['message'] . $config['after_confirm_message'] );
			return;
		}

		if ( Tokens::PURPOSE_EMAIL_CHANGE === $decoded['purpose'] ) {
			$contact = $this->prefs->context_for( $decoded['contact_id'] );

			if ( null === $contact ) {
				$this->render_invalid();
				return;
			}

			$new   = sanitize_email( (string) ( $decoded['extra']['new'] ?? '' ) );
			$inner = '<p>' . sprintf(
				/* translators: %s: new email address. */
				esc_html__( '確認要把 Email 改為 %s 嗎？', 'now-crm' ),
				'<strong>' . esc_html( $new ) . '</strong>'
			) . '</p>'
				. '<form method="post" action="' . esc_url( self::url( $token ) ) . '">'
				. '<input type="hidden" name="token" value="' . esc_attr( $token ) . '" />'
				. '<input type="hidden" name="nowcrm_consent_action" value="confirm_change" />'
				. '<button type="submit">' . esc_html__( '確認變更', 'now-crm' ) . '</button></form>';

			$this->render_document( __( '確認 Email', 'now-crm' ), $inner );
			return;
		}

		$this->render_invalid();
	}

	private function render_invalid(): void {
		$this->render_document(
			__( '偏好設定', 'now-crm' ),
			$this->notice( __( '這個連結無效或已過期。', 'now-crm' ), false )
		);
	}

	/**
	 * Request-link form fragment for the shortcode and the bare endpoint.
	 */
	private function request_fragment( string $notice ): string {
		$inner = '' !== $notice ? $this->notice( $notice, true ) : '';

		$inner .= '<form method="post" action="' . esc_url( self::url( '' ) ) . '">'
			. '<p><label>' . esc_html__( 'Email', 'now-crm' ) . '<br />'
			. '<input type="email" name="email" required="required" autocomplete="email" /></label></p>'
			. '<input type="hidden" name="nowcrm_pref_request" value="1" />'
			. '<input type="hidden" name="nowcrm_consent_action" value="request" />'
			. '<button type="submit">' . esc_html__( '寄送偏好設定連結', 'now-crm' ) . '</button></form>';

		return $inner;
	}

	/**
	 * The center fragment: profile, public lists, email change, unsubscribe.
	 *
	 * @param array{contact: array<string, mixed>, lists: array<int, array<string, mixed>>} $context
	 */
	private function center_fragment( array $context, string $token, string $notice ): string {
		$contact = $context['contact'];
		$action  = esc_url( self::url( $token ) );
		$inner   = '' !== $notice ? $this->notice( $notice, true ) : '';

		$inner .= '<form method="post" action="' . $action . '">'
			. '<h2>' . esc_html__( '基本資料', 'now-crm' ) . '</h2>'
			. '<input type="hidden" name="token" value="' . esc_attr( $token ) . '" />'
			. '<input type="hidden" name="nowcrm_consent_action" value="save" />'
			. $this->text_row( 'first_name', __( '名字', 'now-crm' ), (string) ( $contact['first_name'] ?? '' ) )
			. $this->text_row( 'last_name', __( '姓氏', 'now-crm' ), (string) ( $contact['last_name'] ?? '' ) )
			. $this->text_row( 'phone', __( '電話', 'now-crm' ), (string) ( $contact['phone'] ?? '' ) )
			. $this->profile_rows( $contact )
			. '<h2>' . esc_html__( '訂閱名單', 'now-crm' ) . '</h2>';

		if ( ! $context['lists'] ) {
			$inner .= '<p>' . esc_html__( '目前沒有可自行管理的公開名單。', 'now-crm' ) . '</p>';
		} else {
			foreach ( $context['lists'] as $list ) {
				$inner .= '<p><label><input type="checkbox" name="lists[]" value="' . (int) $list['id'] . '"'
					. ( $list['selected'] ? ' checked="checked"' : '' ) . ' /> '
					. esc_html( (string) $list['name'] ) . '</label></p>';
			}
		}

		$inner .= '<button type="submit">' . esc_html__( '儲存', 'now-crm' ) . '</button></form>';

		$inner .= '<form method="post" action="' . $action . '">'
			. '<h2>' . esc_html__( 'Email', 'now-crm' ) . '</h2>'
			. '<p>' . esc_html( (string) $contact['email'] ) . '</p>'
			. '<input type="hidden" name="token" value="' . esc_attr( $token ) . '" />'
			. '<input type="hidden" name="nowcrm_consent_action" value="change_email" />'
			. '<p><label>' . esc_html__( '新 Email', 'now-crm' ) . '<br />'
			. '<input type="email" name="new_email" required="required" autocomplete="email" /></label></p>'
			. '<button type="submit">' . esc_html__( '更換 Email（需重新確認）', 'now-crm' ) . '</button></form>';

		$inner .= '<form method="post" action="' . $action . '">'
			. '<h2>' . esc_html__( '取消訂閱', 'now-crm' ) . '</h2>'
			. '<input type="hidden" name="token" value="' . esc_attr( $token ) . '" />'
			. '<input type="hidden" name="nowcrm_consent_action" value="unsubscribe" />'
			. '<p><label>' . esc_html__( '原因（選填）', 'now-crm' ) . '<br />'
			. '<select name="reason">'
			. '<option value="">' . esc_html__( '不想回答', 'now-crm' ) . '</option>'
			. '<option value="too_many">' . esc_html__( '信件太多', 'now-crm' ) . '</option>'
			. '<option value="irrelevant">' . esc_html__( '內容不相關', 'now-crm' ) . '</option>'
			. '<option value="other">' . esc_html__( '其他', 'now-crm' ) . '</option>'
			. '</select></label></p>'
			. '<button type="submit">' . esc_html__( '確認取消訂閱', 'now-crm' ) . '</button></form>';

		return $inner;
	}

	/**
	 * One profile field row, reusing the ProfileFields vocabulary.
	 *
	 * @param array<string, mixed> $contact
	 */
	private function profile_rows( array $contact ): string {
		$html = '';

		foreach ( ProfileFields::fields() as $key => $definition ) {
			$value = ProfileFields::form_value( $contact, $key );
			$label = esc_html( (string) ( $definition['label'] ?? $key ) );
			$name  = esc_attr( $key );
			$type  = (string) ( $definition['type'] ?? ProfileFields::TYPE_TEXT );

			if ( ProfileFields::TYPE_SELECT === $type ) {
				$html .= '<p><label>' . $label . '<br /><select name="' . $name . '">'
					. '<option value="">—</option>'
					. $this->select_options( $key, $value )
					. '</select></label></p>';
				continue;
			}

			if ( ProfileFields::TYPE_DATE === $type ) {
				$html .= '<p><label>' . $label . '<br /><input type="date" name="' . $name . '" value="' . esc_attr( $value ) . '" /></label></p>';
				continue;
			}

			$html .= '<p><label>' . $label . '<br /><input type="text" name="' . $name . '" value="' . esc_attr( $value ) . '" /></label></p>';
		}

		return $html;
	}

	private function select_options( string $key, string $current ): string {
		$options = array();

		if ( 'prefix' === $key ) {
			foreach ( ProfileFields::prefixes() as $prefix ) {
				$options[ $prefix ] = $prefix;
			}
		} elseif ( 'contact_type' === $key ) {
			$options = ProfileFields::contact_types();
		} elseif ( 'country' === $key ) {
			$options = ProfileFields::countries();
		}

		$html = '';

		foreach ( $options as $value => $label ) {
			$html .= '<option value="' . esc_attr( (string) $value ) . '"'
				. ( (string) $value === $current ? ' selected="selected"' : '' ) . '>'
				. esc_html( (string) $label ) . '</option>';
		}

		return $html;
	}

	private function text_row( string $name, string $label, string $value ): string {
		return '<p><label>' . esc_html( $label ) . '<br />'
			. '<input type="text" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" /></label></p>';
	}

	private function notice( string $message, bool $ok ): string {
		return '<p class="nowcrm-consent-notice nowcrm-consent-notice--' . ( $ok ? 'ok' : 'error' ) . '">'
			. esc_html( $message ) . '</p>';
	}

	/**
	 * Standalone page in the same native style as the unsubscribe endpoint.
	 */
	private function render_document( string $title, string $inner ): void {
		status_header( 200 );

		if ( ! headers_sent() ) {
			header( 'Content-Type: text/html; charset=UTF-8' );
			header( 'X-Robots-Tag: noindex, nofollow' );
			header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
		}

		echo '<!DOCTYPE html><html><head><meta charset="utf-8">'
			. '<meta name="viewport" content="width=device-width,initial-scale=1">'
			. '<meta name="robots" content="noindex,nofollow">'
			. '<title>' . esc_html( $title ) . '</title></head>'
			. '<body style="margin:0;background:#f4f5f7;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Helvetica,Arial,sans-serif;">'
			. '<div style="max-width:560px;margin:60px auto;background:#fff;border-radius:8px;padding:40px;">'
			. '<h1 style="margin:0 0 12px;font-size:22px;color:#111827;">' . esc_html( $title ) . '</h1>'
			. $inner // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built with escaped fragments above.
			. '</div></body></html>';
	}
}
