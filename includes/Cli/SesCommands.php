<?php
/**
 * SES/SNS fixtures for local verification without AWS credentials.
 *
 * @package NowCRM
 */

namespace NowCRM\Cli;

use NowCRM\Ses\Processor;

defined( 'ABSPATH' ) || exit;

final class SesCommands {

	/**
	 * Apply a stored SNS envelope (JSON) through the normal processor.
	 *
	 * ## OPTIONS
	 *
	 * --file=<path>
	 * : Path to a JSON SNS message on the server.
	 *
	 * ## EXAMPLES
	 *
	 *     wp nowcrm ses simulate --file=/tmp/bounce.json
	 *
	 * @subcommand simulate
	 */
	public function simulate( array $args = array(), array $assoc_args = array() ): void {
		$file = (string) ( $assoc_args['file'] ?? '' );

		if ( '' === $file || ! is_readable( $file ) ) {
			\WP_CLI::error( '請提供可讀取的 --file=SNS JSON。' );
		}

		$payload = json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- CLI fixture.

		if ( ! is_array( $payload ) ) {
			\WP_CLI::error( 'JSON 格式不正確。' );
		}

		$result = ( new Processor() )->process( $payload );

		\WP_CLI::line( 'ok: ' . ( $result['ok'] ? 'yes' : 'no' ) );
		\WP_CLI::line( 'reason: ' . $result['reason'] );
		\WP_CLI::line( 'event_id: ' . $result['event_id'] );

		if ( ! $result['ok'] ) {
			\WP_CLI::error( '事件處理失敗。' );
		}

		\WP_CLI::success( '事件已套用（本機 Fixture，未經驗證簽章）。' );
	}
}
