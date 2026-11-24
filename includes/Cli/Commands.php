<?php
/**
 * WP-CLI commands: `wp nowcrm status` and `wp nowcrm sync-orders`.
 *
 * @package NowCRM
 */

namespace NowCRM\Cli;

use NowCRM\Contacts\ContactRepository;
use NowCRM\Orders\OrderLinkRepository;
use NowCRM\Support\Schema;
use NowCRM\Support\Settings;
use NowCRM\Woo\OrderSync;

defined( 'ABSPATH' ) || exit;

final class Commands {

	/**
	 * Register the `wp nowcrm` namespace and its contact subcommands.
	 */
	public function register(): void {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
			return;
		}

		\WP_CLI::add_command( 'nowcrm', $this );
		\WP_CLI::add_command( 'nowcrm contact', new ContactCommands() );
		\WP_CLI::add_command( 'nowcrm import', new ImportCommands() );
		\WP_CLI::add_command( 'nowcrm email', new EmailCommands() );
		\WP_CLI::add_command( 'nowcrm campaign', new CampaignCommands() );
		\WP_CLI::add_command( 'nowcrm cart', new CartCommands() );
		\WP_CLI::add_command( 'nowcrm ses', new SesCommands() );
	}

	/**
	 * Show contact, order and setting counts.
	 *
	 * ## EXAMPLES
	 *
	 *     wp nowcrm status
	 *
	 * @param array<int, string>    $args       Positional arguments.
	 * @param array<string, string> $assoc_args Associative arguments.
	 */
	public function status( array $args = array(), array $assoc_args = array() ): void {
		$contacts = new ContactRepository();
		$links    = new OrderLinkRepository();
		$counts   = $contacts->count_by_status();

		$rows = array();

		foreach ( $counts as $status => $count ) {
			$rows[] = array(
				'metric' => 'contact:' . $status,
				'value'  => (string) $count,
			);
		}

		$rows[] = array( 'metric' => 'contacts:total', 'value' => (string) array_sum( $counts ) );
		$rows[] = array( 'metric' => 'orders:linked', 'value' => (string) $links->count() );
		$rows[] = array( 'metric' => 'orders:counted', 'value' => (string) $links->count( true ) );
		$rows[] = array( 'metric' => 'customers', 'value' => (string) $links->count_customers() );
		$rows[] = array( 'metric' => 'spend:counted_total', 'value' => (string) $links->total_spent() );
		$rows[] = array( 'metric' => 'schema_version', 'value' => (string) get_option( Schema::VERSION_OPTION, '' ) );

		$queue = ( new \NowCRM\Email\Queue() )->status();

		foreach ( $queue as $key => $value ) {
			$rows[] = array( 'metric' => 'email:' . $key, 'value' => (string) $value );
		}

		$cart_stats = ( new \NowCRM\Cart\CartRepository() )->stats();

		foreach ( $cart_stats as $key => $value ) {
			$rows[] = array( 'metric' => 'cart:' . $key, 'value' => (string) $value );
		}

		$rows[] = array( 'metric' => 'suppressions', 'value' => (string) count( ( new \NowCRM\Email\SuppressionRepository() )->counts() ) );

		foreach ( Settings::all() as $key => $value ) {
			$rows[] = array(
				'metric' => 'setting:' . $key,
				'value'  => is_bool( $value ) ? ( $value ? 'true' : 'false' ) : (string) $value,
			);
		}

		\WP_CLI\Utils\format_items( 'table', $rows, array( 'metric', 'value' ) );
	}

	/**
	 * Synchronise WooCommerce orders into the CRM.
	 *
	 * Historical backfill never runs implicitly: this command defaults to a
	 * dry-run and only writes with --apply. It ignores the `woo_sync_enabled`
	 * toggle because running it is an explicit operator action.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Preview only (default).
	 *
	 * [--apply]
	 * : Write changes.
	 *
	 * [--batch-size=<n>]
	 * : Orders per batch. Default 100.
	 *
	 * [--max=<n>]
	 * : Stop after n orders. Default 0 (no limit).
	 *
	 * [--status=<list>]
	 * : Comma separated WooCommerce statuses.
	 *
	 * [--after=<date>]
	 * : Only orders created on or after Y-m-d.
	 *
	 * [--before=<date>]
	 * : Only orders created on or before Y-m-d.
	 *
	 * ## EXAMPLES
	 *
	 *     wp nowcrm sync-orders --dry-run
	 *     wp nowcrm sync-orders --apply --batch-size=200
	 *
	 * @subcommand sync-orders
	 *
	 * @param array<int, string>    $args       Positional arguments.
	 * @param array<string, string> $assoc_args Associative arguments.
	 */
	public function sync_orders( array $args = array(), array $assoc_args = array() ): void {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			\WP_CLI::error( 'WooCommerce 未載入，無法同步訂單。' );
		}

		$apply      = isset( $assoc_args['apply'] );
		$dry_run    = isset( $assoc_args['dry-run'] );
		$batch_size = isset( $assoc_args['batch-size'] ) ? max( 1, absint( $assoc_args['batch-size'] ) ) : 100;
		$max        = isset( $assoc_args['max'] ) ? absint( $assoc_args['max'] ) : 0;

		if ( $apply && $dry_run ) {
			\WP_CLI::error( '--dry-run 與 --apply 不能同時使用。' );
		}

		if ( ! $apply ) {
			\WP_CLI::log( '模式：dry-run（不會寫入；加上 --apply 才會同步）。' );
		}

		\WP_CLI::log(
			Settings::bool( 'woo_sync_enabled' )
				? '注意：Woo 同步開關為開啟；此指令不受開關影響，仍會依參數執行。'
				: '注意：Woo 同步開關為關閉；此指令是顯式操作，仍會依參數執行。'
		);

		$counters = array(
			'scanned'          => 0,
			'contacts_created' => 0,
			'contacts_updated' => 0,
			'links_created'    => 0,
			'links_updated'    => 0,
			'skipped_no_email' => 0,
			'skipped_orders'   => 0,
			'errors'           => 0,
		);

		$error_messages = array();
		$diff_lines     = array();
		$page           = 1;
		$processed      = 0;

		$sync     = new OrderSync();
		$contacts = new ContactRepository();
		$links    = new OrderLinkRepository();

		do {
			$result      = wc_get_orders( $this->order_query_args( $assoc_args, $batch_size, $page ) );
			$orders      = is_object( $result ) && isset( $result->orders ) ? $result->orders : (array) $result;
			$max_pages   = is_object( $result ) && isset( $result->max_num_pages ) ? (int) $result->max_num_pages : 1;
			$batch_count = 0;

			foreach ( $orders as $order ) {
				if ( $max > 0 && $processed >= $max ) {
					break 2;
				}

				++$processed;
				++$counters['scanned'];
				++$batch_count;

				if ( $apply ) {
					$this->apply_order( $sync, $order, $counters, $error_messages );
				} else {
					$this->preview_order( $contacts, $links, $order, $counters, $diff_lines );
				}
			}

			\WP_CLI::log(
				sprintf(
					'[batch %d] 本批 %d 筆，累計掃描 %d 筆',
					$page,
					$batch_count,
					$counters['scanned']
				)
			);

			++$page;
		} while ( $page <= $max_pages && ( 0 === $max || $processed < $max ) );

		if ( 0 === $counters['scanned'] ) {
			\WP_CLI::log( '沒有符合條件的訂單。' );
		}

		if ( ! $apply && array() !== $diff_lines ) {
			\WP_CLI::log( '--- 前 20 筆差異 ---' );

			foreach ( $diff_lines as $line ) {
				\WP_CLI::log( $line );
			}
		}

		$this->print_summary( $counters );

		if ( $counters['errors'] > 0 ) {
			foreach ( array_slice( $error_messages, 0, 20 ) as $message ) {
				\WP_CLI::warning( $message );
			}

			\WP_CLI::halt( 1 );
		}
	}

	/**
	 * Query arguments for wc_get_orders().
	 *
	 * @param array<string, string> $assoc_args CLI arguments.
	 * @param int                   $batch_size Batch size.
	 * @param int                   $page       Page number.
	 * @return array<string, mixed>
	 */
	private function order_query_args( array $assoc_args, int $batch_size, int $page ): array {
		$query = array(
			'limit'    => $batch_size,
			'page'     => $page,
			'paginate' => true,
			'orderby'  => 'date',
			'order'    => 'ASC',
			'type'     => 'shop_order',
			'return'   => 'objects',
		);

		if ( ! empty( $assoc_args['status'] ) ) {
			$query['status'] = array_map( 'trim', explode( ',', (string) $assoc_args['status'] ) );
		}

		$after  = isset( $assoc_args['after'] ) ? (string) $assoc_args['after'] : '';
		$before = isset( $assoc_args['before'] ) ? (string) $assoc_args['before'] : '';

		if ( '' !== $after && '' !== $before ) {
			$query['date_created'] = $after . '...' . $before;
		} elseif ( '' !== $after ) {
			$query['date_created'] = '>=' . $after;
		} elseif ( '' !== $before ) {
			$query['date_created'] = '<=' . $before;
		}

		return $query;
	}

	/**
	 * Preview one order without writing.
	 *
	 * @param ContactRepository  $contacts   Contact repository.
	 * @param OrderLinkRepository $links      Link repository.
	 * @param \WC_Order          $order      Order.
	 * @param array<string, int> $counters   Counters, by reference.
	 * @param array<int, string> $diff_lines Preview lines, by reference.
	 */
	private function preview_order( ContactRepository $contacts, OrderLinkRepository $links, $order, array &$counters, array &$diff_lines ): void {
		$email = $contacts->normalize_email( (string) $order->get_billing_email() );

		if ( '' === $email || ! is_email( $email ) ) {
			++$counters['skipped_no_email'];

			return;
		}

		$contact = $contacts->find_by_email( $email );
		$link    = $links->find_by_order( (int) $order->get_id() );

		if ( $contact ) {
			++$counters['contacts_updated'];
		} else {
			++$counters['contacts_created'];
		}

		if ( $link ) {
			++$counters['links_updated'];
		} else {
			++$counters['links_created'];
		}

		if ( count( $diff_lines ) < 20 ) {
			$diff_lines[] = sprintf(
				'#%d %s %s %s → %s%s',
				$order->get_id(),
				$order->get_status(),
				$order->get_total(),
				(string) $order->get_currency(),
				$contact ? 'contact #' . $contact['id'] : 'new contact (' . $email . ')',
				$link ? ' [link exists]' : ''
			);
		}
	}

	/**
	 * Apply one order and update counters.
	 *
	 * @param OrderSync          $sync           Sync service.
	 * @param \WC_Order          $order          Order.
	 * @param array<string, int> $counters       Counters, by reference.
	 * @param array<int, string> $error_messages Errors, by reference.
	 */
	private function apply_order( OrderSync $sync, $order, array &$counters, array &$error_messages ): void {
		$result = $sync->sync_order( $order );

		if ( is_wp_error( $result ) ) {
			if ( 'nowcrm_no_email' === $result->get_error_code() ) {
				++$counters['skipped_no_email'];

				return;
			}

			if ( 'nowcrm_draft_order' === $result->get_error_code() ) {
				++$counters['skipped_orders'];

				return;
			}

			++$counters['errors'];
			$error_messages[] = sprintf( '#%d: %s', $order->get_id(), $result->get_error_message() );

			return;
		}

		if ( $result['created_contact'] ) {
			++$counters['contacts_created'];
		}

		if ( 'created_link' === $result['action'] ) {
			++$counters['links_created'];
		} else {
			++$counters['links_updated'];
		}
	}

	/**
	 * Print the final summary table.
	 *
	 * @param array<string, int> $counters Counters.
	 */
	private function print_summary( array $counters ): void {
		$rows = array();

		foreach ( $counters as $key => $value ) {
			$rows[] = array(
				'metric' => $key,
				'value'  => (string) $value,
			);
		}

		\WP_CLI\Utils\format_items( 'table', $rows, array( 'metric', 'value' ) );
	}
}
