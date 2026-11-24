<?php
/**
 * Public unsubscribe endpoint. GET only renders a confirmation page; POST
 * performs the change so mail scanners cannot unsubscribe a customer by
 * prefetching links. POST is also the RFC 8058 one-click path.
 *
 * @package NowCRM
 */

namespace NowCRM\Unsubscribe;

use NowCRM\Contacts\ContactRepository;
use NowCRM\Contacts\Status;
use NowCRM\Email\EmailJobRepository;
use NowCRM\Email\SuppressionRepository;
use NowCRM\Email\Tracking;

defined( 'ABSPATH' ) || exit;

final class Endpoint {

	private Tokens $tokens;
	private ContactRepository $contacts;
	private SuppressionRepository $suppressions;
	private EmailJobRepository $jobs;
	private Tracking $tracking;

	public function __construct() {
		$this->tokens       = new Tokens();
		$this->contacts     = new ContactRepository();
		$this->suppressions = new SuppressionRepository();
		$this->jobs         = new EmailJobRepository();
		$this->tracking     = new Tracking();
	}

	/**
	 * Handle the public request before the theme renders.
	 */
	public function register(): void {
		add_action( 'template_redirect', array( $this, 'maybe_handle' ), 1 );
	}

	/**
	 * Intercept ?nowcrm_unsubscribe=1.
	 */
	public function maybe_handle(): void {
		if ( ! isset( $_GET['nowcrm_unsubscribe'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- token is the capability.
			return;
		}

		$token  = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['token'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( (string) $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';

		if ( 'POST' === $method ) {
			$reason = isset( $_POST['reason'] ) ? sanitize_key( wp_unslash( (string) $_POST['reason'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- token is the capability.
			$result = $this->process( $token, $reason, 'unsubscribe-one-click' );

			$this->render( $result, $token, false );
			exit;
		}

		$found = $this->tokens->find( $token );

		if ( ! $found ) {
			$this->render(
				array(
					'ok'      => false,
					'code'    => 410,
					'message' => __( '這個取消訂閱連結無效或已過期。', 'now-crm' ),
				),
				'',
				false
			);
			exit;
		}

		$contact = $this->contacts->get( $found['contact_id'] );
		$already = ! $contact || Status::is_suppressed( (string) $contact['status'] );

		$this->render(
			array(
				'ok'       => true,
				'code'     => 200,
				'already'  => $already,
				'message'  => $already
					? __( '這個 Email 已經不會再收到行銷郵件。', 'now-crm' )
					: __( '確認要取消訂閱嗎？', 'now-crm' ),
				'contact'  => $contact,
			),
			$token,
			true
		);
		exit;
	}

	/**
	 * Apply an unsubscribe. Idempotent; a used token still reports success.
	 *
	 * @return array{ok: bool, code: int, message: string, already?: bool, contact?: array<string, mixed>|null}
	 */
	public function process( string $raw_token, string $reason = '', string $source = 'unsubscribe-one-click' ): array {
		$token = $this->tokens->find( $raw_token );

		if ( ! $token ) {
			return array(
				'ok'      => false,
				'code'    => 410,
				'message' => __( '這個取消訂閱連結無效或已過期。', 'now-crm' ),
			);
		}

		$contact = $this->contacts->get( (int) $token['contact_id'] );

		if ( ! $contact ) {
			return array(
				'ok'      => false,
				'code'    => 404,
				'message' => __( '找不到對應的聯絡人。', 'now-crm' ),
			);
		}

		$already = ! empty( $token['used_at'] ) || Status::is_suppressed( (string) $contact['status'] );
		$note    = $already ? '' : $this->reason_note( $reason );

		if ( ! $already ) {

			$this->contacts->set_status(
				(int) $contact['id'],
				Status::UNSUBSCRIBED,
				substr( $source, 0, 191 ),
				$note
			);

			$this->suppressions->add( (string) $contact['email'], SuppressionRepository::REASON_UNSUBSCRIBED, $source, (int) $contact['id'] );
		}

		// Even for an already-suppressed contact: clear anything still queued.
		$this->jobs->suppress_email( (string) $contact['email'], 'unsubscribed' );

		$this->tokens->consume( (int) $token['id'] );

		$campaign_id = (int) $token['campaign_id'];

		$this->tracking->record(
			$campaign_id > 0 ? 'unsubscribe:' . $campaign_id . ':' . (int) $contact['id'] : 'unsubscribe:' . (int) $contact['id'],
			'unsubscribe',
			$campaign_id,
			(int) $token['job_id'],
			(int) $contact['id'],
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
			'contact' => $this->contacts->get( (int) $contact['id'] ),
		);
	}

	/**
	 * Map a reason code to a human note stored in status history.
	 */
	private function reason_note( string $reason ): string {
		$labels = array(
			'not_signed_up' => '從未訂閱',
			'too_many'      => '信件太多',
			'irrelevant'    => '內容不相關',
			'other'         => '其他原因',
		);

		return $labels[ $reason ] ?? '';
	}

	/**
	 * Render a standalone page and set response headers.
	 *
	 * @param array<string, mixed> $result Process result.
	 */
	private function render( array $result, string $token, bool $show_form ): void {
		$code = (int) ( $result['code'] ?? 200 );

		status_header( $code );
		if ( ! headers_sent() ) {
			header( 'Content-Type: text/html; charset=UTF-8' );
			header( 'X-Robots-Tag: noindex, nofollow' );
			header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
		}

		$site_name = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$message   = (string) ( $result['message'] ?? '' );
		$form      = '';

		if ( $show_form && ! empty( $result['ok'] ) && empty( $result['already'] ) ) {
			$action = esc_url( $this->tokens->url( $token ) );
			$form   = '<form method="post" action="' . $action . '" style="margin:24px 0 0;">'
				. '<p style="margin:0 0 8px;font-size:14px;color:#374151;">' . esc_html__( '為什麼要取消？（選填）', 'now-crm' ) . '</p>'
				. '<select name="reason" style="width:100%;padding:10px;border:1px solid #d1d5db;border-radius:4px;font-size:15px;margin-bottom:16px;">'
				. '<option value="">' . esc_html__( '不想回答', 'now-crm' ) . '</option>'
				. '<option value="too_many">' . esc_html__( '信件太多', 'now-crm' ) . '</option>'
				. '<option value="not_signed_up">' . esc_html__( '我沒訂閱過', 'now-crm' ) . '</option>'
				. '<option value="irrelevant">' . esc_html__( '內容不相關', 'now-crm' ) . '</option>'
				. '<option value="other">' . esc_html__( '其他', 'now-crm' ) . '</option>'
				. '</select>'
				. '<button type="submit" style="display:block;width:100%;padding:12px 16px;background:#111827;color:#fff;border:0;border-radius:4px;font-size:15px;cursor:pointer;">'
				. esc_html__( '確認取消訂閱', 'now-crm' ) . '</button></form>';
		}

		$email = is_array( $result['contact'] ?? null ) ? (string) ( $result['contact']['email'] ?? '' ) : '';

		echo '<!DOCTYPE html><html lang="zh-Hant"><head><meta charset="utf-8">'
			. '<meta name="viewport" content="width=device-width,initial-scale=1">'
			. '<meta name="robots" content="noindex,nofollow">'
			. '<title>' . esc_html( $message ?: $site_name ) . '</title></head>'
			. '<body style="margin:0;background:#f4f5f7;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Helvetica,Arial,sans-serif;">'
			. '<div style="max-width:520px;margin:60px auto;background:#fff;border-radius:8px;padding:40px;">'
			. '<h1 style="margin:0 0 12px;font-size:22px;color:#111827;">' . esc_html( $site_name ) . '</h1>'
			. '<p style="margin:0 0 8px;font-size:16px;line-height:1.6;color:#374151;">' . esc_html( $message ) . '</p>';

		if ( '' !== $email ) {
			echo '<p style="margin:0;font-size:13px;color:#6b7280;">' . esc_html( $email ) . '</p>';
		}

		echo $form; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built with escaped fragments above.

		echo '<p style="margin:28px 0 0;font-size:12px;color:#9ca3af;">'
			. esc_html__( 'NOW CRM · 這個頁面不會要求您登入。', 'now-crm' )
			. '</p></div></body></html>';
	}
}
