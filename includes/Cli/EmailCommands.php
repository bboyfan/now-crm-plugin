<?php
/**
 * Email queue operations.
 *
 * @package NowCRM
 */

namespace NowCRM\Cli;

use NowCRM\Email\EmailJobRepository;
use NowCRM\Email\Queue;
use NowCRM\Email\SuppressionRepository;
use NowCRM\Privacy\Retention;
use NowCRM\Support\Settings;

defined( 'ABSPATH' ) || exit;

final class EmailCommands {

	/**
	 * Queue counters.
	 *
	 * ## EXAMPLES
	 *
	 *     wp nowcrm email status
	 */
	public function status( array $args = array(), array $assoc_args = array() ): void {
		$stats = ( new Queue() )->status();

		foreach ( $stats as $key => $value ) {
			\WP_CLI::line( $key . ': ' . $value );
		}

		\WP_CLI::line( 'sending_enabled: ' . ( Settings::bool( 'email_sending_enabled' ) ? 'yes' : 'no' ) );
		\WP_CLI::line( 'transport: ' . Settings::string( 'email_transport' ) );
		\WP_CLI::line( 'throttle_per_second: ' . Settings::int( 'email_throttle_per_second' ) );
	}

	/**
	 * Run one worker tick.
	 *
	 * ## OPTIONS
	 *
	 * [--force]
	 * : Run even when email sending is disabled (still uses the configured transport).
	 *
	 * ## EXAMPLES
	 *
	 *     wp nowcrm email worker --force
	 */
	public function worker( array $args = array(), array $assoc_args = array() ): void {
		if ( ! Settings::bool( 'email_sending_enabled' ) && ! isset( $assoc_args['force'] ) ) {
			\WP_CLI::warning( 'Email sending is disabled. Enable it in NOW CRM → 設定, or use --force for a local test.' );

			return;
		}

		$queue = new Queue();

		if ( ! Settings::bool( 'email_sending_enabled' ) && isset( $assoc_args['force'] ) ) {
			Settings::update( array( 'email_sending_enabled' => true ) );
			$queue->run();
			Settings::update( array( 'email_sending_enabled' => false ) );
		} else {
			$queue->run();
		}

		$this->status();
	}

	/**
	 * Retry a failed, unknown or suppressed job explicitly.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Job ID.
	 *
	 * ## EXAMPLES
	 *
	 *     wp nowcrm email retry 123
	 */
	public function retry( array $args = array(), array $assoc_args = array() ): void {
		$id = absint( $args[0] ?? 0 );

		if ( ! $id ) {
			\WP_CLI::error( '請提供 Job ID。' );
		}

		$repo = new EmailJobRepository();

		if ( ! $repo->retry( $id ) ) {
			\WP_CLI::error( '此 Job 不存在或狀態不可重試。' );
		}

		Queue::schedule( 1 );
		\WP_CLI::success( 'Job 已重新排入佇列。' );
	}

	/**
	 * Add an address to the suppression list.
	 *
	 * ## OPTIONS
	 *
	 * <email>
	 * : Address.
	 *
	 * [--reason=<reason>]
	 * : unsubscribed|bounced|complained|manual. Default manual.
	 *
	 * ## EXAMPLES
	 *
	 *     wp nowcrm email suppress someone@example.test --reason=complained
	 */
	public function suppress( array $args = array(), array $assoc_args = array() ): void {
		$email = sanitize_email( (string) ( $args[0] ?? '' ) );

		if ( ! is_email( $email ) ) {
			\WP_CLI::error( 'Email 格式不正確。' );
		}

		$reason = sanitize_key( (string) ( $assoc_args['reason'] ?? SuppressionRepository::REASON_MANUAL ) );

		( new SuppressionRepository() )->add( $email, $reason, 'cli' );
		( new EmailJobRepository() )->suppress_email( $email, $reason );

		\WP_CLI::success( '已加入抑制名單，並略過佇列中的郵件。' );
	}

	/**
	 * Inspect recent jobs.
	 *
	 * ## OPTIONS
	 *
	 * [--status=<status>]
	 * : queued|sending|accepted|failed|suppressed|unknown|cancelled.
	 *
	 * [--campaign=<id>]
	 * : Campaign post ID.
	 *
	 * [--email=<email>]
	 * : Recipient.
	 *
	 * [--per-page=<n>]
	 * : Default 20.
	 *
	 * ## EXAMPLES
	 *
	 *     wp nowcrm email jobs --status=failed
	 */
	public function jobs( array $args = array(), array $assoc_args = array() ): void {
		$result = ( new EmailJobRepository() )->search(
			array(
				'status'      => $assoc_args['status'] ?? '',
				'campaign_id' => (int) ( $assoc_args['campaign'] ?? 0 ),
				'email'       => $assoc_args['email'] ?? '',
				'limit'       => (int) ( $assoc_args['per-page'] ?? 20 ),
			)
		);

		if ( ! $result['items'] ) {
			\WP_CLI::line( 'No jobs.' );

			return;
		}

		$rows = array();

		foreach ( $result['items'] as $job ) {
			$rows[] = array(
				'id'       => $job['id'],
				'kind'     => $job['kind'],
				'status'   => $job['status'],
				'recipient' => $job['recipient'],
				'attempts' => $job['attempts'] . '/' . $job['max_attempts'],
				'subject'  => mb_substr( (string) $job['subject'], 0, 40 ),
				'error'    => mb_substr( (string) $job['last_error'], 0, 60 ),
			);
		}

		\WP_CLI\Utils\format_items( 'table', $rows, array_keys( $rows[0] ) );
	}

	/**
	 * Preview or apply retention of historical email payloads.
	 *
	 * The command never deletes rows. It clears frozen job bodies and processed
	 * SNS payloads older than the cutoff, in one bounded batch. Rows are left
	 * alone when they can still be retried or when the payload is needed for
	 * diagnosis. Preview is the default; nothing changes without --apply.
	 *
	 * ## OPTIONS
	 *
	 * [--days=<days>]
	 * : Retention window in days. Default 90, minimum 30.
	 *
	 * [--apply]
	 * : Apply one batch. Omit it for preview. Accepts a bare flag or
	 * `--apply=true`; `--apply=false` stays a preview; other values fail closed.
	 *
	 * [--batch=<n>]
	 * : Maximum rows per store in one apply run. Default 200, maximum 500.
	 *
	 * ## EXAMPLES
	 *
	 *     wp nowcrm email retention
	 *     wp nowcrm email retention --days=180 --apply --batch=100
	 */
	public function retention( array $args = array(), array $assoc_args = array() ): void {
		$days = (int) ( $assoc_args['days'] ?? Retention::DEFAULT_DAYS );

		if ( $days < Retention::MIN_DAYS ) {
			\WP_CLI::error( sprintf( 'Retention window must be at least %d days.', Retention::MIN_DAYS ) );
		}

		$apply = Retention::parse_apply_flag( $assoc_args['apply'] ?? false );

		if ( null === $apply ) {
			\WP_CLI::error( '--apply accepts only true or false. Omit it for a preview.' );
		}

		$service = new Retention();
		$preview = $service->preview( $days );

		\WP_CLI::line( sprintf( 'Cutoff: %s UTC (older than %d days)', $preview['cutoff'], $days ) );
		\WP_CLI::line( sprintf( 'email_jobs.payload: %d eligible', $preview['jobs'] ) );
		\WP_CLI::line( sprintf( 'ses_events.payload: %d eligible', $preview['ses'] ) );

		if ( ! $apply ) {
			\WP_CLI::line( 'Preview only. No data changed. Use --apply to process one batch.' );

			return;
		}

		$batch  = (int) ( $assoc_args['batch'] ?? Retention::DEFAULT_BATCH );
		$result = $service->apply( $days, $batch );

		\WP_CLI::success(
			sprintf(
				'Cleared payloads: email_jobs %d, ses_events %d.',
				$result['jobs']['cleared'],
				$result['ses']['cleared']
			)
		);
	}
}
