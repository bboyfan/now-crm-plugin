<?php
/**
 * Transport boundary. Everything above this class hands off a rendered message;
 * wp_mail() returning true means "accepted by the mail stack", not inbox
 * delivery. The capture transport exists for local verification only.
 *
 * @package NowCRM
 */

namespace NowCRM\Email;

use NowCRM\Support\Settings;

defined( 'ABSPATH' ) || exit;

final class EmailDispatcher {

	public const CAPTURE_OPTION = 'nowcrm_captured_mail';

	/**
	 * Send one rendered message.
	 *
	 * @param array<string, mixed> $mail to, to_name, subject, html, text, job_id, kind, unsubscribe_url, headers.
	 * @return array{ok: bool, message_id: string, transport: string, error: string}
	 */
	public function send( array $mail ): array {
		$to      = (string) ( $mail['to'] ?? '' );
		$subject = (string) ( $mail['subject'] ?? '' );

		if ( ! is_email( $to ) ) {
			return array(
				'ok'         => false,
				'message_id' => '',
				'transport'  => '',
				'error'      => 'invalid_recipient',
			);
		}

		$transport = Settings::string( 'email_transport' );
		$transport = in_array( $transport, array( 'wp_mail', 'capture' ), true ) ? $transport : 'wp_mail';
		$message_id = $this->message_id( $to, (int) ( $mail['job_id'] ?? 0 ) );

		$headers = array( 'Content-Type: text/html; charset=UTF-8' );

		if ( ! empty( $mail['unsubscribe_url'] ) ) {
			$headers[] = 'List-Unsubscribe: <' . esc_url_raw( (string) $mail['unsubscribe_url'] ) . '>';
			$headers[] = 'List-Unsubscribe-Post: List-Unsubscribe=One-Click';
		}

		$correlation = (string) ( $mail['ses_correlation'] ?? '' );
		$correlation = preg_match( '/^[1-9][0-9]*-[A-Za-z0-9_-]{16,64}$/', $correlation ) ? $correlation : '';

		foreach ( (array) ( $mail['headers'] ?? array() ) as $header ) {
			$header = trim( (string) $header );

			if ( '' !== $header ) {
				$headers[] = $header;
			}
		}

		$sender = ! empty( $mail['frozen_sender'] ) ? $mail : self::sender( $mail );
		$from_name = (string) $sender['from_name'];
		$from_addr = (string) $sender['from_address'];
		$reply_to = (string) $sender['reply_to'];
		if ( is_email( $reply_to ) ) {
			$headers[] = 'Reply-To: ' . $reply_to;
		}

		if ( 'capture' === $transport ) {
			$headers[] = 'Message-ID: <' . $message_id . '>';
			$this->capture( $mail, $headers, $message_id );
			return array( 'ok' => true, 'message_id' => $message_id, 'transport' => 'capture', 'error' => '' );
		}

		// Correlation headers go out only on the real handoff. The tag value and
		// the header value stay identical, and AWS tag syntax allows only
		// letters, digits, hyphens and underscores.
		if ( '' !== $correlation ) {
			$headers[] = 'X-NowCRM-Job: ' . $correlation;
			$headers[] = 'X-SES-MESSAGE-TAGS: nowcrm_job=' . $correlation;
		}

		$configuration_set = substr( (string) preg_replace( '/[^A-Za-z0-9_-]/', '', Settings::string( 'ses_configuration_set' ) ), 0, 64 );

		if ( '' !== $configuration_set ) {
			$headers[] = 'X-SES-CONFIGURATION-SET: ' . $configuration_set;
		}

		$from_filter = static function () use ( $from_addr ): string {
			return $from_addr;
		};

		$name_filter = static function () use ( $from_name ): string {
			return $from_name;
		};

		add_filter( 'wp_mail_from', $from_filter, 99 );
		add_filter( 'wp_mail_from_name', $name_filter, 99 );

		// PHPMailer writes its own Message-ID header. Supplying one as a wp_mail()
		// custom header emitted two, which RFC 5322 forbids and providers may
		// reject or rewrite. Give the same value to PHPMailer as its canonical id.
		$message_id_filter = static function ( $phpmailer ) use ( $message_id ): void {
			if ( is_object( $phpmailer ) && property_exists( $phpmailer, 'MessageID' ) ) {
				$phpmailer->MessageID = '<' . $message_id . '>';
			}
		};

		add_action( 'phpmailer_init', $message_id_filter, 99 );

		$failure = '';

		$on_failure = static function ( $error ) use ( &$failure ): void {
			if ( is_wp_error( $error ) ) {
				$failure = $error->get_error_message();
			}
		};

		add_action( 'wp_mail_failed', $on_failure );

		try {
			$sent = wp_mail( $to, $subject, (string) ( $mail['html'] ?? '' ), $headers, array() );
		} catch ( \Throwable $error ) {
			$sent = false;
			$failure = $error->getMessage();
		} finally {
			remove_action( 'wp_mail_failed', $on_failure );
			remove_action( 'phpmailer_init', $message_id_filter, 99 );
			remove_filter( 'wp_mail_from', $from_filter, 99 );
			remove_filter( 'wp_mail_from_name', $name_filter, 99 );
		}

		if ( ! $sent ) {
			return array(
				'ok'         => false,
				'message_id' => $message_id,
				'transport'  => 'wp_mail',
				'error'      => $failure ?: 'wp_mail_returned_false',
			);
		}

		return array(
			'ok'         => true,
			'message_id' => $message_id,
			'transport'  => 'wp_mail',
			'error'      => '',
		);
	}

	public static function sender( array $mail ): array {
		$name = (string) ( $mail['from_name'] ?? '' ) ?: Settings::string( 'email_from_name' );
		$from = (string) ( $mail['from_address'] ?? '' ) ?: Settings::string( 'email_from_address' );
		$reply = (string) ( $mail['reply_to'] ?? '' ) ?: Settings::string( 'email_reply_to' );
		$host = (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST );

		return array(
			'from_name'    => $name ?: wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			'from_address' => is_email( $from ) ? $from : 'wordpress@' . ( $host ?: 'localhost' ),
			'reply_to'     => is_email( $reply ) ? sanitize_email( $reply ) : '',
		);
	}

	/**
	 * Store a bounded ring buffer of locally captured messages.
	 *
	 * @param array<string, mixed> $mail     Mail data.
	 * @param string[]             $headers  Headers.
	 * @param string               $message_id Generated id.
	 */
	private function capture( array $mail, array $headers, string $message_id ): void {
		$entry = array(
			'to'         => (string) ( $mail['to'] ?? '' ),
			'subject'    => (string) ( $mail['subject'] ?? '' ),
			'html'       => (string) ( $mail['html'] ?? '' ),
			'text'       => (string) ( $mail['text'] ?? '' ),
			'headers'    => $headers,
			'message_id' => $message_id,
			'job_id'     => (int) ( $mail['job_id'] ?? 0 ),
			'kind'       => (string) ( $mail['kind'] ?? '' ),
			'captured_at' => gmdate( 'Y-m-d H:i:s' ),
		);

		$log = (array) get_option( self::CAPTURE_OPTION, array() );
		array_unshift( $log, $entry );
		$log = array_slice( $log, 0, 50 );

		update_option( self::CAPTURE_OPTION, $log, false );

		/** Fires after a message is captured instead of being sent. */
		do_action( 'nowcrm_email_captured', $entry );
	}

	/**
	 * Deterministic-ish Message-ID for correlation in logs.
	 */
	private function message_id( string $to, int $job_id ): string {
		$host = (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST );

		return sprintf(
			'nowcrm-%d-%s@%s',
			$job_id,
			substr( hash( 'sha256', $to . '|' . microtime( true ) . '|' . wp_generate_password( 16, false ) ), 0, 24 ),
			$host ?: 'localhost'
		);
	}
}
