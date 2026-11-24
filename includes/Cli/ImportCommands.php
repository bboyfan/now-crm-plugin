<?php
/**
 * Import / reconciliation commands.
 *
 * @package NowCRM
 */

namespace NowCRM\Cli;

use NowCRM\FluentCRM\Adapter;
use NowCRM\FluentCRM\Importer;
use NowCRM\Woo\WooAudit;
use NowCRM\Woo\WooReconciler;

defined( 'ABSPATH' ) || exit;

final class ImportCommands {

	/**
	 * Migrate FluentCRM contacts, lists, tags and optionally campaign history.
	 *
	 * Defaults to a dry run. Re-running is idempotent; --resume continues an
	 * interrupted apply run from its last cursor.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Count what would change without writing contacts or terms. Default.
	 *
	 * [--apply]
	 * : Write contacts, statuses and list/tag relations.
	 *
	 * [--batch=<n>]
	 * : Contacts per batch. Default 200.
	 *
	 * [--resume]
	 * : Continue the newest unfinished apply run.
	 *
	 * [--campaigns]
	 * : Also copy historical campaign rows into the legacy archive.
	 *
	 * [--limit=<n>]
	 * : Stop after N contacts (testing).
	 *
	 * ## EXAMPLES
	 *
	 *     wp nowcrm import fluentcrm --dry-run
	 *     wp nowcrm import fluentcrm --apply --batch=500 --campaigns
	 *
	 * @subcommand fluentcrm
	 */
	public function fluentcrm( array $args = array(), array $assoc_args = array() ): void {
		$adapter = new Adapter();

		if ( ! $adapter->available() ) {
			\WP_CLI::error( '找不到可讀取的 FluentCRM 聯絡人資料表（fc_subscribers 或 fc_contacts）。' );
		}

		$report = ( new Importer() )->run(
			array(
				'mode'      => isset( $assoc_args['apply'] ) ? 'apply' : 'dry_run',
				'batch'     => (int) ( $assoc_args['batch'] ?? 200 ),
				'resume'    => isset( $assoc_args['resume'] ),
				'campaigns' => isset( $assoc_args['campaigns'] ),
				'limit'     => (int) ( $assoc_args['limit'] ?? 0 ),
			)
		);

		if ( empty( $report['ok'] ) ) {
			\WP_CLI::error( (string) ( $report['reason'] ?? 'import_failed' ) );
		}

		\WP_CLI::line( 'Mode: ' . $report['mode'] );
		\WP_CLI::line( 'Remote contacts by status: ' . wp_json_encode( $report['remote_status'] ) );
		\WP_CLI::line( 'Processed: ' . $report['processed'] );
		\WP_CLI::line( 'Created: ' . $report['created'] );
		\WP_CLI::line( 'Updated: ' . $report['updated'] );
		\WP_CLI::line( 'Skipped: ' . $report['skipped'] );
		\WP_CLI::line( 'Failed: ' . $report['failed'] );
		\WP_CLI::line( 'Campaigns archived: ' . $report['campaigns'] );
		\WP_CLI::line( 'Local status after: ' . wp_json_encode( $report['local_after'] ) );

		if ( ! empty( $report['errors'] ) ) {
			\WP_CLI::warning( 'Errors: ' . wp_json_encode( $report['errors'] ) );
		}

		\WP_CLI::success( 'dry_run' === $report['mode'] ? 'Dry run 完成，未寫入聯絡人。' : 'FluentCRM 匯入完成。' );
	}

	/**
	 * Create transactional contacts for WooCommerce billing emails that have no
	 * CRM contact. Never marks them subscribed.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Count gaps only. Default.
	 *
	 * [--apply]
	 * : Create contacts and link historical orders.
	 *
	 * [--batch=<n>]
	 * : Orders per page. Default 100.
	 *
	 * [--limit=<n>]
	 * : Stop after N orders.
	 *
	 * [--audit]
	 * : Read-only reconciliation of order/contact/link gaps. Writes nothing.
	 *
	 * ## EXAMPLES
	 *
	 *     wp nowcrm import woo --dry-run
	 *     wp nowcrm import woo --audit
	 *     wp nowcrm import woo --apply
	 *
	 * @subcommand woo
	 */
	public function woo( array $args = array(), array $assoc_args = array() ): void {
		if ( isset( $assoc_args['audit'] ) ) {
			$report = ( new WooAudit() )->order_linkage(
				(int) ( $assoc_args['batch'] ?? 100 ),
				(int) ( $assoc_args['limit'] ?? 0 )
			);

			foreach ( $report as $key => $value ) {
				\WP_CLI::line( $key . ': ' . $value );
			}

			\WP_CLI::success( 'Woo 關聯稽核完成（唯讀，未寫入）。' );

			return;
		}

		$report = ( new WooReconciler() )->run(
			array(
				'mode'  => isset( $assoc_args['apply'] ) ? 'apply' : 'dry_run',
				'batch' => (int) ( $assoc_args['batch'] ?? 100 ),
				'limit' => (int) ( $assoc_args['limit'] ?? 0 ),
			)
		);

		foreach ( $report as $key => $value ) {
			if ( 'ok' === $key || is_array( $value ) ) {
				continue;
			}

			\WP_CLI::line( $key . ': ' . $value );
		}

		\WP_CLI::success( 'apply' === $report['mode'] ? 'Woo 缺口補檔完成（transactional）。' : 'Woo 缺口 dry run 完成。' );
	}

	/**
	 * Show the latest import runs.
	 *
	 * ## OPTIONS
	 *
	 * [--per-page=<n>]
	 * : Default 10.
	 *
	 * ## EXAMPLES
	 *
	 *     wp nowcrm import status
	 */
	public function status( array $args = array(), array $assoc_args = array() ): void {
		$runs = ( new Importer() )->runs( (int) ( $assoc_args['per-page'] ?? 10 ) );

		if ( ! $runs ) {
			\WP_CLI::line( 'No import runs yet.' );

			return;
		}

		$rows = array();

		foreach ( $runs as $run ) {
			$rows[] = array(
				'id'       => $run['id'],
				'source'   => $run['source'],
				'mode'     => $run['mode'],
				'status'   => $run['status'],
				'cursor'   => $run['cursor_value'],
				'imported' => $run['imported'],
				'updated'  => $run['updated'],
				'skipped'  => $run['skipped'],
				'failed'   => $run['failed'],
				'started'  => $run['started_at'],
			);
		}

		\WP_CLI\Utils\format_items( 'table', $rows, array_keys( $rows[0] ) );
	}
}
