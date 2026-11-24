<?php
/**
 * Read-only reconciliation audit for Woo orders and abandoned carts.
 *
 * The audit never writes, never outputs an address, and never guesses a paid
 * state from a status alias. Cart payment evidence uses the same facts as the
 * cart tracker: a paid date plus the confirmation marker or a transaction ID.
 * Attribution is read from the exact linked cart row only.
 *
 * @package NowCRM
 */

namespace NowCRM\Woo;

use NowCRM\Cart\Automation;
use NowCRM\Cart\CartRepository;
use NowCRM\Contacts\ContactRepository;
use NowCRM\Contacts\Status;
use NowCRM\Email\EmailJobRepository;
use NowCRM\Email\SuppressionRepository;
use NowCRM\Orders\OrderLinkRepository;
use NowCRM\Support\Settings;

defined( 'ABSPATH' ) || exit;

final class WooAudit {

	/**
	 * Run both read-only checks.
	 *
	 * @param array<string, mixed> $options batch, limit.
	 * @return array<string, mixed>
	 */
	public function run( array $options = array() ): array {
		$batch = max( 1, min( 500, (int) ( $options['batch'] ?? 100 ) ) );
		$limit = max( 0, (int) ( $options['limit'] ?? 0 ) );

		return array(
			'ok'     => true,
			'mode'   => 'dry_run',
			'orders' => $this->order_linkage( $batch, $limit ),
			'carts'  => $this->cart_reminders( $batch, $limit ),
		);
	}

	/**
	 * Aggregate order/contact/link gaps.
	 *
	 * @return array<string, int>
	 */
	public function order_linkage( int $batch = 100, int $limit = 0 ): array {
		$contacts = new ContactRepository();
		$links    = new OrderLinkRepository();

		$result = array(
			'orders_scanned'    => 0,
			'no_email'          => 0,
			'contact_missing'   => 0,
			'link_missing'      => 0,
			'linked'            => 0,
			'draft_skipped'     => 0,
			'order_link_without_contact' => 0,
		);

		$page  = 1;
		$seen  = 0;
		$guard = 0;

		do {
			$query = wc_get_orders(
				array(
					'limit'    => $batch,
					'page'     => $page,
					'orderby'  => 'ID',
					'order'    => 'ASC',
					'paginate' => true,
					'type'     => 'shop_order',
				)
			);

			$orders = is_object( $query ) ? $query->orders : array();
			$pages  = is_object( $query ) ? (int) $query->max_num_pages : 0;

			foreach ( $orders as $order ) {
				if ( $limit > 0 && $seen >= $limit ) {
					break 2;
				}

				if ( ! $order instanceof \WC_Order ) {
					continue;
				}

				++$result['orders_scanned'];
				++$seen;

				if ( 'checkout-draft' === (string) $order->get_status() ) {
					++$result['draft_skipped'];
					continue;
				}

				$email = $contacts->normalize_email( (string) $order->get_billing_email() );
				$link  = $links->find_by_order( (int) $order->get_id() );

				if ( '' === $email || ! is_email( $email ) ) {
					++$result['no_email'];
					continue;
				}

				$contact = $contacts->find_by_email( $email );

				if ( ! $contact ) {
					++$result['contact_missing'];

					if ( $link ) {
						++$result['order_link_without_contact'];
					}

					continue;
				}

				if ( ! $link ) {
					++$result['link_missing'];
					continue;
				}

				++$result['linked'];
			}

			++$page;
			++$guard;
		} while ( $page <= $pages && $guard < 1000 && ( 0 === $limit || $seen < $limit ) );

		return $result;
	}

	/**
	 * Aggregate reminder eligibility and exact linked-order evidence.
	 *
	 * @return array<string, mixed>
	 */
	public function cart_reminders( int $batch = 100, int $limit = 0 ): array {
		$carts       = new CartRepository();
		$contacts    = new ContactRepository();
		$suppressions = new SuppressionRepository();
		$jobs        = new EmailJobRepository();

		$result = array(
			'status_counts'        => $carts->stats(),
			'scan_scope'           => 'all carts: abandoned eligibility and exact linked-order evidence',
			'carts_scanned'        => 0,
			'abandoned_scanned'    => 0,
			'eligible'             => 0,
			'not_eligible'         => 0,
			'reasons'              => array(),
			'linked_carts'         => 0,
			'linked_order_missing' => 0,
			'paid_evidence'        => 0,
			'paid_not_finalized'   => 0,
			'finalized_without_evidence' => 0,
			'attribution'          => array(),
		);

		$owner = $this->owner_state();

		$batch  = max( 1, min( 200, $batch ) );
		$offset = 0;
		$seen   = 0;

		do {
			$page = $carts->search(
				array(
					'limit'  => $batch,
					'offset' => $offset,
				)
			);

			foreach ( $page['items'] as $cart ) {
				if ( $limit > 0 && $seen >= $limit ) {
					break 2;
				}

				++$seen;
				++$result['carts_scanned'];

				if ( CartRepository::STATUS_ABANDONED === (string) $cart['status'] ) {
					++$result['abandoned_scanned'];

					$reason = $this->reminder_reason( $cart, $contacts, $suppressions, $jobs, $owner );

					if ( '' === $reason ) {
						++$result['eligible'];
					} else {
						++$result['not_eligible'];
						$result['reasons'][ $reason ] = (int) ( $result['reasons'][ $reason ] ?? 0 ) + 1;
					}
				}

				$order_id = (int) ( $cart['recovered_order_id'] ?? 0 );

				if ( $order_id <= 0 ) {
					continue;
				}

				++$result['linked_carts'];

				$source = (string) ( $cart['recovery_source'] ?? '' );
				$result['attribution'][ $source ] = (int) ( $result['attribution'][ $source ] ?? 0 ) + 1;

				$order = wc_get_order( $order_id );

				if ( ! $order instanceof \WC_Order ) {
					++$result['linked_order_missing'];
					continue;
				}

				$paid = $this->has_paid_evidence( $order );
				$finalized = in_array( (string) $cart['status'], array( CartRepository::STATUS_COMPLETED, CartRepository::STATUS_RECOVERED ), true );

				if ( $paid ) {
					++$result['paid_evidence'];
				}

				if ( $paid && ! $finalized ) {
					++$result['paid_not_finalized'];
				}

				if ( ! $paid && $finalized ) {
					++$result['finalized_without_evidence'];
				}
			}

			$offset += $batch;
		} while ( count( $page['items'] ) === $batch && ( 0 === $limit || $seen < $limit ) );

		return $result;
	}

	/**
	 * Owner readiness without creating or saving the managed automation.
	 *
	 * @return array{valid: bool}
	 */
	private function owner_state(): array {
		$selected = max( 0, Settings::int( Automation::SETTING ) );

		if ( $selected > 0 ) {
			return array( 'valid' => true === Automation::validate_selection( $selected ) );
		}

		$managed = (int) get_option( Automation::MANAGED_OPTION, 0 );

		return array( 'valid' => $managed > 0 && true === Automation::validate_selection( $managed ) );
	}

	/**
	 * Machine reason a currently abandoned cart cannot start a reminder now.
	 * An empty string means eligible.
	 *
	 * @param array<string, mixed> $cart Cart row.
	 * @param array<string, mixed> $owner Cart owner state.
	 */
	private function reminder_reason(
		array $cart,
		ContactRepository $contacts,
		SuppressionRepository $suppressions,
		EmailJobRepository $jobs,
		array $owner
	): string {
		if ( ! Settings::bool( 'abandoned_cart_enabled' ) ) {
			return 'cart_disabled';
		}

		if ( empty( $owner['valid'] ) ) {
			return 'owner_unavailable';
		}

		if ( (int) ( $cart['recovered_order_id'] ?? 0 ) > 0 ) {
			return 'order_exists';
		}

		$email = (string) ( $cart['email_normalized'] ?? $cart['email'] ?? '' );

		if ( '' === $email ) {
			return 'no_email';
		}

		$contact = $contacts->find_by_email( $email );

		if ( ! $contact ) {
			return 'no_contact';
		}

		if ( Status::SUBSCRIBED !== (string) $contact['status'] ) {
			return 'not_subscribed';
		}

		if ( $suppressions->is_suppressed( $email ) ) {
			return 'suppressed';
		}

		$accepted = max(
			(int) ( $cart['reminders_sent'] ?? 0 ),
			$jobs->accepted_count_for_cart( (int) $cart['id'] )
		);

		if ( $accepted >= max( 0, Settings::int( 'cart_max_reminders' ) ) ) {
			return 'max_reminders';
		}

		$cooldown_days = Settings::int( 'cart_cooldown_days' );

		if ( $cooldown_days > 0 ) {
			$last = ( new CartRepository() )->last_reminder_at( $email );

			if ( $last && strtotime( $last . ' UTC' ) > time() - $cooldown_days * DAY_IN_SECONDS ) {
				return 'cooldown';
			}
		}

		return '';
	}

	/**
	 * Same payment facts as the cart tracker. A status alias never decides.
	 */
	private function has_paid_evidence( \WC_Order $order ): bool {
		if ( ! $order->get_date_paid() ) {
			return false;
		}

		if ( '' !== (string) $order->get_meta( '_nowcrm_payment_confirmed' ) ) {
			return true;
		}

		return '' !== trim( (string) $order->get_transaction_id() );
	}
}
