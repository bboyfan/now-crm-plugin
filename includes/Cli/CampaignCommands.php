<?php
/**
 * Campaign operations.
 *
 * @package NowCRM
 */

namespace NowCRM\Cli;

use NowCRM\Campaign\Campaigns;
use NowCRM\Email\EmailJobRepository;

defined( 'ABSPATH' ) || exit;

final class CampaignCommands {

	/**
	 * List campaigns.
	 *
	 * ## EXAMPLES
	 *
	 *     wp nowcrm campaign list
	 */
	public function list_( array $args = array(), array $assoc_args = array() ): void {
		$posts = get_posts(
			array(
				'post_type'      => Campaigns::POST_TYPE,
				'post_status'    => 'any',
				'posts_per_page' => 50,
				'orderby'        => 'ID',
				'order'          => 'DESC',
			)
		);

		if ( ! $posts ) {
			\WP_CLI::line( 'No campaigns.' );

			return;
		}

		$rows = array();

		foreach ( $posts as $post ) {
			$stats  = ( new EmailJobRepository() )->campaign_stats( (int) $post->ID );
			$rows[] = array(
				'id'       => $post->ID,
				'title'    => mb_substr( $post->post_title, 0, 40 ),
				'status'   => Campaigns::state( (int) $post->ID ),
				'queued'   => $stats['queued'],
				'accepted' => $stats['accepted'],
				'failed'   => $stats['failed'],
			);
		}

		\WP_CLI\Utils\format_items( 'table', $rows, array_keys( $rows[0] ) );
	}

	/**
	 * Start sending a campaign now.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Campaign post ID.
	 *
	 * ## EXAMPLES
	 *
	 *     wp nowcrm campaign send 12
	 */
	public function send( array $args = array(), array $assoc_args = array() ): void {
		$id = absint( $args[0] ?? 0 );

		if ( ! ( new Campaigns() )->start( $id ) ) {
			\WP_CLI::error( 'Campaign 不存在、狀態不允許或內容為空。' );
		}

		\WP_CLI::success( 'Campaign 已開始排入佇列。' );
	}

	/**
	 * Queue a test send.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Campaign post ID.
	 *
	 * --to=<email>
	 * : Recipient.
	 *
	 * ## EXAMPLES
	 *
	 *     wp nowcrm campaign test 12 --to=admin@example.test
	 */
	public function test( array $args = array(), array $assoc_args = array() ): void {
		$id    = absint( $args[0] ?? 0 );
		$email = sanitize_email( (string) ( $assoc_args['to'] ?? '' ) );

		if ( ! ( new Campaigns() )->test_send( $id, $email ) ) {
			\WP_CLI::error( '無法排入測試信。' );
		}

		\WP_CLI::success( '測試信已排入佇列。' );
	}

	/**
	 * Pause a campaign.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Campaign post ID.
	 *
	 * @subcommand pause
	 */
	public function pause( array $args = array(), array $assoc_args = array() ): void {
		$id = absint( $args[0] ?? 0 );

		if ( ! $id || ! ( new Campaigns() )->pause( $id ) ) {
			\WP_CLI::error( '無法暫停：找不到 Campaign 或狀態不允許。' );
		}

		\WP_CLI::success( 'Campaign 已暫停。' );
	}

	/**
	 * Resume a paused campaign.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Campaign post ID.
	 *
	 * @subcommand resume
	 */
	public function resume( array $args = array(), array $assoc_args = array() ): void {
		$id = absint( $args[0] ?? 0 );

		if ( ! $id || ! ( new Campaigns() )->resume( $id ) ) {
			\WP_CLI::error( '無法恢復：找不到 Campaign 或狀態不允許。' );
		}

		\WP_CLI::success( 'Campaign 已恢復。' );
	}

	/**
	 * Cancel a campaign and its queued jobs.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Campaign post ID.
	 *
	 * @subcommand cancel
	 */
	public function cancel( array $args = array(), array $assoc_args = array() ): void {
		$id = absint( $args[0] ?? 0 );

		if ( ! $id || ! ( new Campaigns() )->cancel( $id ) ) {
			\WP_CLI::error( '無法取消：找不到 Campaign 或狀態不允許。' );
		}

		\WP_CLI::success( 'Campaign 已取消。' );
	}
}
