<?php
/**
 * Contact WP-CLI commands: `wp nowcrm contact <subcommand>`.
 *
 * @package NowCRM
 */

namespace NowCRM\Cli;

use NowCRM\Contacts\ContactRepository;
use NowCRM\Contacts\Status;
use NowCRM\Terms\TermRepository;

defined( 'ABSPATH' ) || exit;

final class ContactCommands {

	/**
	 * List contacts.
	 *
	 * ## OPTIONS
	 *
	 * [--status=<status>]
	 * : Filter by contact status.
	 *
	 * [--search=<text>]
	 * : Search email or name.
	 *
	 * [--per-page=<n>]
	 * : Default 20.
	 *
	 * [--page=<n>]
	 * : Default 1.
	 *
	 * ## EXAMPLES
	 *
	 *     wp nowcrm contact list --status=unsubscribed
	 *
	 * @subcommand list
	 *
	 * @param array<int, string>    $args       Positional arguments.
	 * @param array<string, string> $assoc_args Associative arguments.
	 */
	public function list_( array $args = array(), array $assoc_args = array() ): void {
		$contacts = new ContactRepository();
		$result   = $contacts->search(
			array(
				'status'   => $assoc_args['status'] ?? '',
				'search'   => $assoc_args['search'] ?? '',
				'per_page' => $assoc_args['per-page'] ?? 20,
				'page'     => $assoc_args['page'] ?? 1,
			)
		);

		$rows = array();

		foreach ( $result['items'] as $contact ) {
			$contact_id = (int) $contact['id'];
			$aggregate  = is_array( $contact['aggregate'] ?? null ) ? $contact['aggregate'] : $contacts->aggregate( $contact_id );

			$rows[] = array(
				'id'         => $contact['id'],
				'email'      => $contact['email'],
				'name'       => trim( $contact['first_name'] . ' ' . $contact['last_name'] ),
				'status'     => $contact['status'],
				'orders'     => $aggregate['order_count'],
				'spent'      => $aggregate['total_spent'],
				'wp_user_id' => (string) ( $contact['wp_user_id'] ?? '' ),
			);
		}

		if ( array() === $rows ) {
			\WP_CLI::warning( '沒有符合條件的聯絡人。' );

			return;
		}

		\WP_CLI\Utils\format_items( 'table', $rows, array( 'id', 'email', 'name', 'status', 'orders', 'spent', 'wp_user_id' ) );
		\WP_CLI::log( sprintf( '顯示 %d / 共 %d 筆', count( $rows ), $result['total'] ) );
	}

	/**
	 * Show one contact with aggregates and status history.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Contact ID.
	 *
	 * @subcommand get
	 *
	 * @param array<int, string>    $args       Positional arguments.
	 * @param array<string, string> $assoc_args Associative arguments.
	 */
	public function get_( array $args = array(), array $assoc_args = array() ): void {
		$contacts = new ContactRepository();
		$id       = isset( $args[0] ) ? absint( $args[0] ) : 0;
		$contact  = $id > 0 ? $contacts->get( $id ) : null;

		if ( ! $contact ) {
			\WP_CLI::error( '找不到聯絡人。' );
		}

		$aggregate = $contacts->aggregate( $id );
		$terms     = ( new TermRepository() )->for_contact( $id );

		$rows = array(
			array( 'field' => 'id', 'value' => (string) $contact['id'] ),
			array( 'field' => 'email', 'value' => $contact['email'] ),
			array( 'field' => 'name', 'value' => trim( $contact['first_name'] . ' ' . $contact['last_name'] ) ),
			array( 'field' => 'status', 'value' => $contact['status'] . ' (' . Status::label( $contact['status'] ) . ')' ),
			array( 'field' => 'wp_user_id', 'value' => (string) ( $contact['wp_user_id'] ?? '' ) ),
			array( 'field' => 'order_count', 'value' => (string) $aggregate['order_count'] ),
			array( 'field' => 'total_spent', 'value' => (string) $aggregate['total_spent'] ),
			array( 'field' => 'first_order_at', 'value' => (string) ( $aggregate['first_order_at'] ?? '' ) ),
			array( 'field' => 'last_order_at', 'value' => (string) ( $aggregate['last_order_at'] ?? '' ) ),
			array( 'field' => 'lists_tags', 'value' => implode( ', ', array_column( $terms, 'name' ) ) ),
		);

		\WP_CLI\Utils\format_items( 'table', $rows, array( 'field', 'value' ) );

		$events = $contacts->history()->for_contact( $id, 10 );

		if ( array() === $events ) {
			return;
		}

		\WP_CLI::log( '最近狀態異動：' );

		$history_rows = array_map(
			static fn( array $event ): array => array(
				'occurred_at' => $event['occurred_at'],
				'old'         => (string) $event['old_status'],
				'new'         => $event['new_status'],
				'source'      => $event['source'],
			),
			$events
		);

		\WP_CLI\Utils\format_items( 'table', $history_rows, array( 'occurred_at', 'old', 'new', 'source' ) );
	}

	/**
	 * Create a contact.
	 *
	 * ## OPTIONS
	 *
	 * --email=<email>
	 * : Contact email.
	 *
	 * [--first-name=<name>]
	 * : First name.
	 *
	 * [--last-name=<name>]
	 * : Last name.
	 *
	 * [--status=<status>]
	 * : Contact status, defaults to subscribed.
	 *
	 * [--lists=<csv>]
	 * : List names or slugs, comma separated.
	 *
	 * @subcommand create
	 *
	 * @param array<int, string>    $args       Positional arguments.
	 * @param array<string, string> $assoc_args Associative arguments.
	 */
	public function create( array $args = array(), array $assoc_args = array() ): void {
		$contacts = new ContactRepository();
		$email    = isset( $assoc_args['email'] ) ? sanitize_email( $assoc_args['email'] ) : '';

		$created = $contacts->create(
			array(
				'email'      => $email,
				'first_name' => $assoc_args['first-name'] ?? '',
				'last_name'  => $assoc_args['last-name'] ?? '',
				'status'     => $assoc_args['status'] ?? Status::SUBSCRIBED,
				'source'     => 'cli',
			)
		);

		if ( is_wp_error( $created ) ) {
			\WP_CLI::error( $created->get_error_message() );
		}

		if ( ! empty( $assoc_args['lists'] ) ) {
			$terms = new TermRepository();

			foreach ( explode( ',', (string) $assoc_args['lists'] ) as $name ) {
				$name = trim( $name );

				if ( '' === $name ) {
					continue;
				}

				$term_id = $terms->ensure( TermRepository::TYPE_LIST, $name );

				if ( $term_id > 0 ) {
					$terms->attach( (int) $created, $term_id );
				}
			}
		}

		\WP_CLI::success( sprintf( '已建立聯絡人 #%d', $created ) );
	}

	/**
	 * Change a contact status.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Contact ID.
	 *
	 * <status>
	 * : subscribed|unsubscribed|transactional|bounced|complained
	 *
	 * [--note=<text>]
	 * : Optional note stored with the status event.
	 *
	 * @subcommand status
	 *
	 * @param array<int, string>    $args       Positional arguments.
	 * @param array<string, string> $assoc_args Associative arguments.
	 */
	public function status_( array $args = array(), array $assoc_args = array() ): void {
		$id     = isset( $args[0] ) ? absint( $args[0] ) : 0;
		$status = isset( $args[1] ) ? sanitize_key( $args[1] ) : '';
		$note   = isset( $assoc_args['note'] ) ? (string) $assoc_args['note'] : '';

		$updated = ( new ContactRepository() )->set_status( $id, $status, 'cli', $note, get_current_user_id() );

		if ( is_wp_error( $updated ) ) {
			\WP_CLI::error( $updated->get_error_message() );
		}

		\WP_CLI::success( sprintf( '聯絡人 #%d 狀態已設為 %s', $id, $status ) );
	}
}
