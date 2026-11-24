<?php
/**
 * WooCommerce order → contact synchronisation.
 *
 * Every handler resolves the order through wc_get_order() and writes through the
 * repository layer, which keeps the code compatible with HPOS and legacy storage.
 *
 * @package NowCRM
 */

namespace NowCRM\Woo;

use NowCRM\Consent\Config;
use NowCRM\Contacts\ContactRepository;
use NowCRM\Contacts\Status;
use NowCRM\Orders\OrderAccounting;
use NowCRM\Orders\OrderLinkRepository;
use NowCRM\Support\Settings;
use NowCRM\Terms\TermRepository;
use WC_Order;
use WP_Error;

defined( 'ABSPATH' ) || exit;

final class OrderSync {

	/**
	 * Reentrancy guard: repository writes never touch WooCommerce, but plugins
	 * hooked to our own actions could otherwise cause an infinite loop.
	 */
	private static bool $running = false;

	/**
	 * Register WooCommerce hooks.
	 *
	 * Hooks are always registered; the `woo_sync_enabled` setting is evaluated at
	 * call time so toggling it in wp-admin takes effect on the next request.
	 */
	public function register(): void {
		add_action( 'woocommerce_new_order', array( $this, 'on_new_order' ), 20, 2 );
		add_action( 'woocommerce_update_order', array( $this, 'on_update_order' ), 20, 2 );
		add_action( 'woocommerce_order_status_changed', array( $this, 'on_status_changed' ), 20, 4 );
		add_action( 'woocommerce_order_refunded', array( $this, 'on_refunded' ), 20, 2 );
		add_action( 'woocommerce_trash_order', array( $this, 'on_order_deleted' ), 20, 1 );
		add_action( 'woocommerce_delete_order', array( $this, 'on_order_deleted' ), 20, 1 );
	}

	/**
	 * Hook: new order.
	 *
	 * @param int           $order_id Order ID.
	 * @param WC_Order|null $order    Order object when available.
	 */
	public function on_new_order( $order_id, $order = null ): void {
		$this->handle_event( $order_id );
	}

	/**
	 * Hook: order updated (including billing email changes).
	 *
	 * @param int           $order_id Order ID.
	 * @param WC_Order|null $order    Order object when available.
	 */
	public function on_update_order( $order_id, $order = null ): void {
		$this->handle_event( $order_id );
	}

	/**
	 * Hook: status transition.
	 *
	 * @param int           $order_id   Order ID.
	 * @param string        $old_status Previous status.
	 * @param string        $new_status New status.
	 * @param WC_Order|null $order      Order object when available.
	 */
	public function on_status_changed( $order_id, $old_status = '', $new_status = '', $order = null ): void {
		$result = $this->handle_event( $order_id );

		if ( is_wp_error( $result ) || ! is_array( $result ) ) {
			return;
		}

		$status = str_replace( 'wc-', '', (string) $new_status );

		if ( 'completed' === $status ) {
			do_action( 'nowcrm_order_completed', (int) $order_id, (int) $result['contact_id'] );
		} elseif ( 'cancelled' === $status ) {
			do_action( 'nowcrm_order_cancelled', (int) $order_id, (int) $result['contact_id'] );
		}
	}

	/**
	 * Hook: refund created.
	 *
	 * @param int $order_id  Order ID.
	 * @param int $refund_id Refund ID.
	 */
	public function on_refunded( $order_id, $refund_id = 0 ): void {
		$result = $this->handle_event( $order_id );

		if ( is_wp_error( $result ) || ! is_array( $result ) ) {
			return;
		}

		do_action( 'nowcrm_order_refunded', (int) $order_id, (int) $result['contact_id'], (int) $refund_id );
	}

	/**
	 * Hook: order trashed or permanently deleted. The customer record stays.
	 *
	 * @param int $order_id Order ID.
	 */
	public function on_order_deleted( $order_id ): void {
		if ( ! Settings::bool( 'woo_sync_enabled' ) || self::$running ) {
			return;
		}

		( new OrderLinkRepository() )->delete_by_order( (int) $order_id );
	}

	/**
	 * Gate hook handling on the setting and the reentrancy guard.
	 *
	 * @param int|WC_Order $order Order reference.
	 */
	private function handle_event( $order ) {
		if ( ! Settings::bool( 'woo_sync_enabled' ) || self::$running ) {
			return null;
		}

		return $this->sync_order( $order );
	}

	/**
	 * Synchronise one order: resolve the contact, attach the customer list and
	 * upsert the order link.
	 *
	 * A contact's status is never changed here. Only brand new contacts receive a
	 * status, derived from `auto_subscribe_new_woo_contacts` and the saved
	 * checkout consent.
	 *
	 * @param int|WC_Order $order Order ID or object.
	 * @return array{order_id: int, contact_id: int, action: string, created_contact: bool}|WP_Error
	 */
	public function sync_order( $order ) {
		if ( ! is_object( $order ) ) {
			$order = wc_get_order( (int) $order );
		}

		if ( ! $order instanceof WC_Order ) {
			return new WP_Error( 'nowcrm_invalid_order', __( '找不到 WooCommerce 訂單。', 'now-crm' ) );
		}

		$order_id = (int) $order->get_id();
		$contacts = new ContactRepository();
		$email    = $contacts->normalize_email( (string) $order->get_billing_email() );

		if ( '' === $email || ! is_email( $email ) ) {
			return new WP_Error( 'nowcrm_no_email', __( '訂單沒有有效的帳單 Email。', 'now-crm' ) );
		}

		if ( 'checkout-draft' === (string) $order->get_status() ) {
			return new WP_Error( 'nowcrm_draft_order', __( '草稿訂單不列入同步。', 'now-crm' ) );
		}

		self::$running = true;

		try {
			$auto_subscribe  = Settings::bool( 'auto_subscribe_new_woo_contacts' );
			$optin           = CheckoutOptin::normalize_order_optin( $order );
			$created_contact = false;
			$contact         = $contacts->find_by_email( $email );
			$terms           = new TermRepository();
			$list_id         = $terms->ensure( TermRepository::TYPE_LIST, TermRepository::customer_list_name() );
			$list_ids        = $list_id > 0 ? array( (int) $list_id ) : array();
			$doi_required    = Config::is_double_optin_required( $list_ids );

			if ( ! $contact ) {
				$created = $contacts->get_or_create(
					$email,
					array(
						'first_name' => (string) $order->get_billing_first_name(),
						'last_name'  => (string) $order->get_billing_last_name(),
						'phone'      => (string) $order->get_billing_phone(),
						'status'     => Status::for_new_woo_contact(
							CheckoutOptin::should_subscribe_new_contact( $order, $auto_subscribe, $optin, $doi_required )
						),
						'wp_user_id' => $order->get_customer_id() ? (int) $order->get_customer_id() : null,
						'source'     => 'woocommerce',
					)
				);

				if ( is_wp_error( $created ) ) {
					return $created;
				}

				$contact         = $created['contact'];
				$created_contact = (bool) $created['created'];
			}

			if ( empty( $contact['wp_user_id'] ) && $order->get_customer_id() ) {
				$contacts->update( (int) $contact['id'], array( 'wp_user_id' => (int) $order->get_customer_id() ) );
				$contact['wp_user_id'] = (int) $order->get_customer_id();
			}

			if ( $list_id > 0 ) {
				$terms->attach( (int) $contact['id'], $list_id );
			}

			$links   = new OrderLinkRepository();
			$before  = $links->find_by_order( $order_id );
			$payload = OrderAccounting::map_order( $order );

			$payload['contact_id']      = (int) $contact['id'];
			$payload['marketing_optin'] = $optin;

			$upserted = $links->upsert( $payload );

			if ( is_wp_error( $upserted ) ) {
				return $upserted;
			}

			/**
			 * A successfully linked Woo order. Consent entry points may start a
			 * double opt-in for a newly created contact with explicit consent.
			 */
			do_action( 'nowcrm_woo_order_synced', (int) $contact['id'], $created_contact, $list_ids, $optin );

			return array(
				'order_id'        => $order_id,
				'contact_id'      => (int) $contact['id'],
				'action'          => $before ? 'updated_link' : 'created_link',
				'created_contact' => $created_contact,
			);
		} finally {
			self::$running = false;
		}
	}
}
