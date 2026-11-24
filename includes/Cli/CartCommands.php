<?php
/**
 * Abandoned-cart operations.
 *
 * @package NowCRM
 */

namespace NowCRM\Cli;

use NowCRM\Cart\Automation;
use NowCRM\Cart\CartRepository;
use NowCRM\Cart\Sweeper;
use NowCRM\Woo\WooAudit;

defined( 'ABSPATH' ) || exit;

final class CartCommands {

	/**
	 * Run one sweep.
	 *
	 * ## EXAMPLES
	 *
	 *     wp nowcrm cart sweep
	 */
	public function sweep( array $args = array(), array $assoc_args = array() ): void {
		$result = ( new Sweeper() )->run();

		foreach ( $result as $key => $value ) {
			\WP_CLI::line( $key . ': ' . $value );
		}
	}

	/**
	 * List captured carts.
	 *
	 * ## OPTIONS
	 *
	 * [--status=<status>]
	 * : active|abandoned|lost|completed|recovered.
	 *
	 * [--email=<email>]
	 * : Filter by address.
	 *
	 * [--per-page=<n>]
	 * : Default 20.
	 *
	 * ## EXAMPLES
	 *
	 *     wp nowcrm cart list --status=abandoned
	 */
	public function list_( array $args = array(), array $assoc_args = array() ): void {
		$result = ( new CartRepository() )->search(
			array(
				'status' => $assoc_args['status'] ?? '',
				'email'  => $assoc_args['email'] ?? '',
				'limit'  => (int) ( $assoc_args['per-page'] ?? 20 ),
			)
		);

		if ( ! $result['items'] ) {
			\WP_CLI::line( 'No carts.' );

			return;
		}

		$rows = array();

		foreach ( $result['items'] as $cart ) {
			$rows[] = array(
				'id'        => $cart['id'],
				'status'    => $cart['status'],
				'email'     => $cart['email'],
				'items'     => $cart['item_count'],
				'total'     => $cart['total'],
				'reminders' => $cart['reminders_sent'],
				'activity'  => $cart['last_activity_at'],
				'order'     => $cart['recovered_order_id'] ?: '—',
				'source'    => $cart['recovery_source'] ?: '—',
			);
		}

		\WP_CLI\Utils\format_items( 'table', $rows, array_keys( $rows[0] ) );
	}

	/**
	 * Read-only reconciliation: reminder eligibility and exact linked-order
	 * payment/attribution evidence. Writes nothing and prints no addresses.
	 *
	 * ## OPTIONS
	 *
	 * [--batch=<n>]
	 * : Rows per page. Default 100, max 200.
	 *
	 * [--limit=<n>]
	 * : Stop after N rows. Default all.
	 *
	 * ## EXAMPLES
	 *
	 *     wp nowcrm cart audit
	 *     wp nowcrm cart audit --batch=200 --limit=2000
	 *
	 * @subcommand audit
	 */
	public function audit( array $args = array(), array $assoc_args = array() ): void {
		$report = ( new WooAudit() )->cart_reminders(
			(int) ( $assoc_args['batch'] ?? 100 ),
			(int) ( $assoc_args['limit'] ?? 0 )
		);

		foreach ( $report as $key => $value ) {
			\WP_CLI::line( $key . ': ' . wp_json_encode( $value ) );
		}

		\WP_CLI::success( '購物車稽核完成（唯讀，未寫入）。' );
	}

	/**
	 * Queue a reminder immediately (respects policy checks at send time).
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Cart ID.
	 *
	 * ## EXAMPLES
	 *
	 *     wp nowcrm cart remind 12
	 *
	 * @subcommand remind
	 */
	public function remind( array $args = array(), array $assoc_args = array() ): void {
		$id   = absint( $args[0] ?? 0 );
		$cart = ( new CartRepository() )->get( $id );

		if ( ! $cart ) {
			\WP_CLI::error( '找不到購物車。' );
		}

		$result = Automation::start_for_cart( (int) $cart['id'], 'cart:' . (int) $cart['id'] );

		if ( is_wp_error( $result ) ) {
			\WP_CLI::error( $result->get_error_message() );
		}

		\WP_CLI::success( '提醒已交由購物車自動化流程處理。' );
	}
}
