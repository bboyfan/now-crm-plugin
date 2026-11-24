<?php
/**
 * Checkout capture. The browser sends the latest checkout email and the time
 * it observed the checkout state. The server binds the Woo session, reads the
 * real cart, and never adopts a client cycle as identity.
 *
 * @package NowCRM
 */

namespace NowCRM\Cart;

use NowCRM\Support\Settings;

defined( 'ABSPATH' ) || exit;

final class Tracker {

	/**
	 * Client observation time bounds for activity_order (epoch milliseconds).
	 */
	private const ACTIVITY_FUTURE_MS = 300000;
	private const ACTIVITY_STALE_MS  = 604800000;
	private const ROTATION_GUARD_MS  = 300000;

	/**
	 * Cycle captured by the first genuine Woo cart mutation in this request.
	 * Null means no mutation waits for a flush.
	 */
	private ?string $pending_cycle = null;

	/**
	 * Woo cart item keys that carry no replayable third-party data.
	 */
	private const CORE_ITEM_KEYS = array(
		'key',
		'product_id',
		'variation_id',
		'variation',
		'quantity',
		'data',
		'data_hash',
		'line_subtotal',
		'line_subtotal_tax',
		'line_total',
		'line_tax',
		'line_tax_data',
	);

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_script' ) );
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'on_order_processed' ), 20, 3 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'on_store_api_order' ), 20, 1 );
		add_action( 'woocommerce_payment_complete', array( $this, 'on_payment_complete' ), 20, 1 );
		add_action( 'woocommerce_payment_complete_order_status_processing', array( $this, 'on_payment_complete' ), 20, 1 );
		add_action( 'woocommerce_payment_complete_order_status_completed', array( $this, 'on_payment_complete' ), 20, 1 );
		add_action( 'woocommerce_order_status_changed', array( $this, 'on_status_changed' ), 20, 4 );
		add_action( 'woocommerce_order_refunded', array( $this, 'on_refunded' ), 20, 2 );

		// Native cart mutation signals. Classic UI, cart AJAX and the Store API
		// all pass through these Woo methods, so one set of hooks covers both.
		foreach (
			array(
				'woocommerce_add_to_cart',
				'woocommerce_cart_item_removed',
				'woocommerce_cart_item_restored',
				'woocommerce_after_cart_item_quantity_update',
				'woocommerce_applied_coupon',
				'woocommerce_removed_coupon',
				'woocommerce_cart_emptied',
			) as $hook
		) {
			add_action( $hook, array( $this, 'on_cart_mutation' ), 20 );
		}

		add_action( 'nowcrm_cart_flush_mutation', array( $this, 'flush_cart_mutation' ), 10 );
		add_action( 'shutdown', array( $this, 'flush_cart_mutation' ), 10 );
	}

	/**
	 * Public capture route.
	 */
	public function routes(): void {
		register_rest_route(
			'nowcrm/v1',
			'/cart/track',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'track' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Frontend script.
	 */
	public function enqueue_script(): void {
		if ( ! Settings::bool( 'abandoned_cart_enabled' ) ) {
			return;
		}

		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
			return;
		}

		// Retry payment and thank-you pages must never start a new cart cycle
		// from leftover Woo contents. The normal checkout page stays covered.
		if ( ( function_exists( 'is_checkout_pay_page' ) && is_checkout_pay_page() )
			|| ( function_exists( 'is_order_received_page' ) && is_order_received_page() )
			|| ( function_exists( 'is_wc_endpoint_url' ) && ( is_wc_endpoint_url( 'order-pay' ) || is_wc_endpoint_url( 'order-received' ) ) ) ) {
			return;
		}

		$asset_file = NOWCRM_DIR . '/assets/build/cart-track.asset.php';

		if ( ! is_readable( $asset_file ) ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log(
					'NOW CRM: assets/build/cart-track.asset.php is missing. Run `npm ci && npm run build`.'
				);
			}

			return;
		}

		$asset = include $asset_file;

		if ( ! is_array( $asset ) ) {
			return;
		}

		$dependencies = isset( $asset['dependencies'] ) && is_array( $asset['dependencies'] )
			? $asset['dependencies']
			: array();

		$version = isset( $asset['version'] ) && is_string( $asset['version'] ) && '' !== $asset['version']
			? $asset['version']
			: NOWCRM_VERSION;

		wp_enqueue_script(
			'nowcrm-cart-track',
			plugins_url( 'assets/build/cart-track.js', NOWCRM_FILE ),
			$dependencies,
			$version,
			true
		);

		wp_localize_script(
			'nowcrm-cart-track',
			'nowcrmCart',
			array(
				'endpoint' => esc_url_raw( rest_url( 'nowcrm/v1/cart/track' ) ),
				'nonce'    => wp_create_nonce( 'wp_rest' ),
			)
		);
	}

	/**
	 * Capture checkout activity.
	 *
	 * @return \WP_REST_Response
	 */
	public function track( \WP_REST_Request $request ) {
		if ( ! Settings::bool( 'abandoned_cart_enabled' ) ) {
			return new \WP_REST_Response( array( 'ok' => false, 'reason' => 'disabled' ), 200 );
		}

		if ( ! $this->is_same_origin( $request ) ) {
			return new \WP_REST_Response( array( 'ok' => false, 'reason' => 'forbidden_origin' ), 403 );
		}

		if ( ! $this->has_valid_nonce( $request ) ) {
			return new \WP_REST_Response( array( 'ok' => false, 'reason' => 'invalid_nonce' ), 403 );
		}

		$kind_raw  = $request->get_param( 'kind' );
		$state_raw = $request->get_param( 'email_state' );
		$kind      = is_string( $kind_raw ) && 'activity' === $kind_raw ? 'activity' : 'capture';
		$state     = is_string( $state_raw ) && 'none' === $state_raw ? 'none' : 'valid';

		if ( 'activity' === $kind && 'valid' !== $state ) {
			return new \WP_REST_Response( array( 'ok' => false, 'reason' => 'invalid_request' ), 200 );
		}

		$email = sanitize_email( (string) $request->get_param( 'email' ) );

		if ( 'valid' === $state && ! is_email( $email ) ) {
			return new \WP_REST_Response( array( 'ok' => false, 'reason' => 'invalid_email' ), 200 );
		}

		$order_ms = $this->activity_order( $request );

		if ( is_wp_error( $order_ms ) ) {
			return new \WP_REST_Response( array( 'ok' => false, 'reason' => $order_ms->get_error_code() ), 200 );
		}

		if ( $this->is_rate_limited() ) {
			return new \WP_REST_Response( array( 'ok' => false, 'reason' => 'rate_limited' ), 429 );
		}

		$this->maybe_load_cart();

		if ( ! function_exists( 'WC' ) || ! WC()->cart || WC()->cart->is_empty() ) {
			return new \WP_REST_Response( array( 'ok' => false, 'reason' => 'empty_cart' ), 200 );
		}

		if ( ! WC()->session ) {
			return new \WP_REST_Response( array( 'ok' => false, 'reason' => 'no_session' ), 200 );
		}

		$customer = (string) WC()->session->get_customer_id();

		if ( '' === $customer ) {
			return new \WP_REST_Response( array( 'ok' => false, 'reason' => 'no_session' ), 200 );
		}

		$server = $this->normalize_cycle( (string) WC()->session->get( 'nowcrm_cart_cycle' ) );
		$legacy = $this->legacy_hint( $request );
		$explicit_raw = (string) $request->get_param( 'previous_cycle' );
		$explicit     = $this->normalize_cycle( $explicit_raw );

		if ( '' !== $explicit_raw && '' === $explicit ) {
			return new \WP_REST_Response( array( 'ok' => false, 'reason' => 'stale_cycle' ), 200 );
		}

		if ( '' !== $explicit && $explicit !== $server ) {
			return new \WP_REST_Response( array( 'ok' => false, 'reason' => 'stale_cycle' ), 200 );
		}

		if ( '' !== $server && '' !== $legacy && $legacy !== $server ) {
			return new \WP_REST_Response( array( 'ok' => false, 'reason' => 'stale_cycle' ), 200 );
		}

		$established = false;

		if ( '' === $server ) {
			// A heartbeat or a cleared field never starts a cart cycle.
			if ( 'capture' !== $kind || 'valid' !== $state ) {
				return new \WP_REST_Response( array( 'ok' => false, 'reason' => 'no_capture' ), 200 );
			}

			$rotated = (int) WC()->session->get( 'nowcrm_cycle_rotated_at' );
			$now_ms  = $this->now_ms();

			if ( 0 === $order_ms ) {
				if ( $rotated > 0 && ( $now_ms - $rotated ) <= self::ROTATION_GUARD_MS ) {
					return new \WP_REST_Response( array( 'ok' => false, 'reason' => 'cycle_rotated' ), 200 );
				}
			} elseif ( $rotated > 0 && $order_ms < $rotated ) {
				return new \WP_REST_Response( array( 'ok' => false, 'reason' => 'stale_cycle' ), 200 );
			}

			$generation = (int) WC()->session->get( 'nowcrm_cycle_generation' );
			$server     = substr( hash( 'sha256', 'guest|' . $customer . '|' . $generation ), 0, 32 );

			WC()->session->set( 'nowcrm_cart_cycle', $server );
			$established = true;
		}

		if ( 'capture' === $kind && 'none' === $state ) {
			$result = ( new CartRepository() )->clear_email( $server, $customer, $order_ms );

			return $this->capture_response( $result, $established, $request );
		}

		if ( 'activity' === $kind ) {
			$result = ( new CartRepository() )->touch_activity( $server, $customer, $order_ms );

			if ( ! is_wp_error( $result ) || 'nowcrm_cart_not_active' !== $result->get_error_code() ) {
				return $this->capture_response( $result, $established, $request );
			}

			// An abandoned cart reactivates through the normal capture path,
			// which keeps the existing version and job rules.
		}

		$cart = $this->cart_snapshot( WC()->cart );

		$result = ( new CartRepository() )->record_activity(
			array(
				'cycle_key'         => $server,
				'session_key'       => $customer,
				'user_id'           => get_current_user_id(),
				'email'             => $email,
				'snapshot'          => $cart['snapshot'],
				'cart_hash'         => $cart['cart_hash'],
				'item_count'        => $cart['item_count'],
				'total'             => $cart['total'],
				'currency'          => $cart['currency'],
				'activity_order_ms' => 0 === $order_ms ? $this->now_ms() : $order_ms,
			)
		);

		return $this->capture_response( $result, $established, $request );
	}

	/**
	 * Build the shared capture response for a repository result.
	 *
	 * @param array<string, mixed>|WP_Error $result      Repository result.
	 * @param bool                          $established Whether this request bound the cycle.
	 * @param \WP_REST_Request              $request     Request.
	 * @return \WP_REST_Response
	 */
	private function capture_response( $result, bool $established, \WP_REST_Request $request ) {
		if ( is_wp_error( $result ) ) {
			return new \WP_REST_Response(
				array(
					'ok'     => false,
					'reason' => str_replace( 'nowcrm_cart_', '', $result->get_error_code() ),
				),
				200
			);
		}

		$data = array(
			'ok'      => true,
			'cart_id' => (int) $result['id'],
			'version' => (int) $result['version'],
		);

		if ( $established || '' === $this->legacy_hint( $request ) ) {
			$data['cycle'] = (string) $result['cart_cycle_key'];
		}

		if ( ! empty( $result['activity_stale'] ) ) {
			$data['stale'] = true;
		}

		return new \WP_REST_Response( $data, 200 );
	}

	/**
	 * Authoritative server snapshot for one live cart.
	 *
	 * Items keep the existing replayable shape and mark third-party line data.
	 * Coupons are stored as codes and included in the hash, because Woo's own
	 * hash ignores them and a discount can leave the total unchanged.
	 *
	 * @return array{snapshot: array{items: array<int, array<string, mixed>>, coupons: string[]}, cart_hash: string, item_count: int, total: float, currency: string}
	 */
	private function cart_snapshot( \WC_Cart $cart ): array {
		$items = array();

		foreach ( $cart->get_cart() as $item ) {
			$entry = array(
				'product_id'   => (int) ( $item['product_id'] ?? 0 ),
				'variation_id' => (int) ( $item['variation_id'] ?? 0 ),
				'quantity'     => (float) ( $item['quantity'] ?? 0 ),
				'variation'    => is_array( $item['variation'] ?? null ) ? $item['variation'] : array(),
			);

			if ( array_diff( array_keys( $item ), self::CORE_ITEM_KEYS ) ) {
				$entry['requires_item_data'] = true;
			}

			$items[] = $entry;
		}

		$coupons = array();

		foreach ( $cart->get_applied_coupons() as $code ) {
			$code = (string) $code;

			if ( '' !== $code ) {
				$coupons[] = $code;
			}
		}

		sort( $coupons );

		$hash = (string) $cart->get_cart_hash();

		if ( '' === $hash ) {
			// Woo returns '' for an empty cart, but the repository reserves ''
			// for "no hash supplied". A stable value records the clear once and
			// then keeps later identical clears a no-op.
			$hash = md5( 'nowcrm|empty-cart' );
		}

		if ( $coupons ) {
			$hash = md5( $hash . '|' . implode( '|', $coupons ) );
		}

		return array(
			'snapshot'   => array(
				'items'   => $items,
				'coupons' => $coupons,
			),
			'cart_hash'  => $hash,
			'item_count' => (int) $cart->get_cart_contents_count(),
			'total'      => (float) $cart->get_total( 'edit' ),
			'currency'   => (string) get_woocommerce_currency(),
		);
	}

	/**
	 * Queue one server snapshot for a native Woo cart mutation.
	 *
	 * Every add, quantity, variation, coupon, remove, restore and clear in the
	 * request collapses into one write after totals settle. Recovery rebuilds
	 * and rollbacks are not customer mutations and are ignored.
	 *
	 * @param mixed $context Hook argument. Not used.
	 */
	public function on_cart_mutation( $context = null ): void {
		if ( Recovery::is_restoring() || ! Settings::bool( 'abandoned_cart_enabled' ) ) {
			return;
		}

		if ( null !== $this->pending_cycle ) {
			return;
		}

		// No established cycle means no captured contact: a mutation must not
		// start a cycle on its own.
		$cycle = $this->current_cycle();

		if ( '' !== $cycle ) {
			$this->pending_cycle = $cycle;
		}
	}

	/**
	 * Write the coalesced mutation snapshot.
	 *
	 * Runs at shutdown or through nowcrm_cart_flush_mutation. The cycle is
	 * checked again because a checkout in the same request can rotate it.
	 */
	public function flush_cart_mutation(): void {
		$cycle = $this->pending_cycle;

		$this->pending_cycle = null;

		if ( '' === $cycle || ! Settings::bool( 'abandoned_cart_enabled' ) ) {
			return;
		}

		if ( ! function_exists( 'WC' ) || ! WC()->cart || ! WC()->session ) {
			return;
		}

		if ( $cycle !== $this->current_cycle() ) {
			return;
		}

		$customer = (string) WC()->session->get_customer_id();

		if ( '' === $customer ) {
			return;
		}

		$cart = $this->cart_snapshot( WC()->cart );

		// No email and no client activity time: the repository keeps the
		// existing row's email and client watermark for a server mutation.
		( new CartRepository() )->record_activity(
			array(
				'cycle_key'    => $cycle,
				'session_key'  => $customer,
				'user_id'      => get_current_user_id(),
				'snapshot'     => $cart['snapshot'],
				'cart_hash'    => $cart['cart_hash'],
				'item_count'   => $cart['item_count'],
				'total'        => $cart['total'],
				'currency'     => $cart['currency'],
				'woo_mutation' => true,
			)
		);
	}

	/**
	 * Classic checkout: create the pending link, not a paid state.
	 *
	 * @param int        $order_id Order ID.
	 * @param array      $posted   Posted checkout data.
	 * @param \WC_Order  $order    Order.
	 */
	public function on_order_processed( $order_id, $posted = array(), $order = null ): void {
		$order = $order instanceof \WC_Order ? $order : wc_get_order( (int) $order_id );

		if ( $order ) {
			$this->link_pending_order( $order );
		}
	}

	/**
	 * Blocks checkout: same behavior via the Store API hook.
	 *
	 * @param \WC_Order $order Order.
	 */
	public function on_store_api_order( $order ): void {
		if ( $order instanceof \WC_Order ) {
			$this->link_pending_order( $order );
		}
	}

	/**
	 * Explicit payment confirmation from Woo or a gateway.
	 *
	 * The marker records that payment_complete ran. The date_paid check still
	 * decides paid state; a status change alone never sets the marker.
	 *
	 * @param int $order_id Order ID.
	 */
	public function on_payment_complete( $order_id ): void {
		$order = wc_get_order( (int) $order_id );

		if ( ! $order ) {
			return;
		}

		$changed = false;

		// Woo only sets date_paid when payment_complete() sees a valid status.
		// An explicit gateway or COD confirmation on processing is still
		// authoritative, so persist the paid time here as well.
		if ( ! $order->get_date_paid() ) {
			$order->set_date_paid( time() );
			$changed = true;
		}

		if ( '' === (string) $order->get_meta( '_nowcrm_payment_confirmed' ) ) {
			$order->update_meta_data( '_nowcrm_payment_confirmed', (string) time() );
			$changed = true;
		}

		if ( $changed ) {
			$order->save();
		}

		$this->finalize_paid_order( $order );
	}

	/**
	 * Status transitions. Payment needs explicit evidence; failed and
	 * cancelled payments keep the cart pending without reviving old work.
	 *
	 * @param int           $order_id Order ID.
	 * @param string        $old      Old status.
	 * @param string        $new      New status.
	 * @param \WC_Order|null $order   Order object.
	 */
	public function on_status_changed( $order_id, $old = '', $new = '', $order = null ): void {
		$order = $order instanceof \WC_Order ? $order : wc_get_order( (int) $order_id );

		if ( ! $order ) {
			return;
		}

		if ( in_array( (string) $new, array( 'failed', 'cancelled' ), true ) ) {
			$this->fail_linked_order( $order, (string) $new );

			return;
		}

		if ( $this->has_payment_evidence( $order ) ) {
			$this->finalize_paid_order( $order );
		}
	}

	/**
	 * Refunds keep the paid evidence. Reporting reads the real Woo net total.
	 *
	 * @param int $order_id  Order ID.
	 * @param int $refund_id Refund ID.
	 */
	public function on_refunded( $order_id, $refund_id = 0 ): void {
		$order = wc_get_order( (int) $order_id );

		if ( ! $order ) {
			return;
		}

		$cart_id = (int) $order->get_meta( '_nowcrm_cart_linked' );

		if ( $cart_id <= 0 ) {
			return;
		}

		( new CartRepository() )->record_event(
			$cart_id,
			'order_refunded',
			array(
				'order_id'  => (int) $order_id,
				'refund_id' => (int) $refund_id,
			)
		);
	}

	/**
	 * Explicit paid transition for callers that already verified Woo payment.
	 *
	 * @param \WC_Order $order Order.
	 */
	public function complete_for_order( \WC_Order $order ): void {
		$this->finalize_paid_order( $order );
	}

	/**
	 * Link the exact cart to a newly created order and move it to pending.
	 */
	private function link_pending_order( \WC_Order $order ): void {
		$order_id = (int) $order->get_id();

		if ( ! $order_id || '' !== (string) $order->get_meta( '_nowcrm_cart_linked' ) ) {
			return;
		}

		// Store API draft orders are not checkout orders yet.
		if ( 'checkout-draft' === (string) $order->get_status() ) {
			return;
		}

		$cycle = $this->current_cycle();

		if ( '' === $cycle ) {
			return;
		}

		$repo = new CartRepository();
		$cart = $repo->find_by_cycle( $cycle );

		if ( ! $cart ) {
			return;
		}

		$recovered = function_exists( 'WC' ) && WC()->session ? (int) WC()->session->get( 'nowcrm_recovered_cart_id' ) : 0;
		$source    = $recovered === (int) $cart['id'] ? 'email_click' : 'natural';

		if ( ! $repo->mark_order_pending( (int) $cart['id'], $order_id, $source ) ) {
			return;
		}

		$row = $repo->get( (int) $cart['id'] );

		$order->update_meta_data( '_nowcrm_cart_linked', (string) (int) $cart['id'] );
		$order->update_meta_data( '_nowcrm_cart_cycle', (string) $cart['cart_cycle_key'] );
		$order->update_meta_data( '_nowcrm_cart_version', (string) (int) ( $row['version'] ?? $cart['version'] ) );
		$order->update_meta_data( '_nowcrm_cart_attribution', $source );
		$order->save();

		$this->rotate_cycle();
	}

	/**
	 * Complete the exact linked cart when payment evidence exists.
	 */
	private function finalize_paid_order( \WC_Order $order ): void {
		$order_id = (int) $order->get_id();
		$cart_id  = (int) $order->get_meta( '_nowcrm_cart_linked' );

		if ( ! $order_id || $cart_id <= 0 ) {
			return;
		}

		$repo = new CartRepository();
		$cart = $repo->get( $cart_id );

		if ( ! $cart ) {
			return;
		}

		$cycle = (string) $order->get_meta( '_nowcrm_cart_cycle' );

		if ( '' !== $cycle && $cycle !== (string) $cart['cart_cycle_key'] ) {
			return;
		}

		$source = (string) $order->get_meta( '_nowcrm_cart_attribution' );

		if ( 'email_click' !== $source && 'natural' !== $source ) {
			$source = (int) $cart['recovered_order_id'] === $order_id ? 'email_click' : 'natural';
		}

		$version = (int) $order->get_meta( '_nowcrm_cart_version' );
		$done    = $repo->complete_with_order( $cart_id, $order_id, $source, $version );

		if ( ! $done && ! in_array( (string) $cart['status'], array( CartRepository::STATUS_COMPLETED, CartRepository::STATUS_RECOVERED ), true ) ) {
			$repo->record_event(
				$cart_id,
				'order_paid_blocked',
				array(
					'order_id' => $order_id,
					'reason'   => 'cart_state_or_version',
				)
			);
		}
	}

	/**
	 * Record a failed or cancelled payment for the exact linked cart.
	 */
	private function fail_linked_order( \WC_Order $order, string $reason ): void {
		$cart_id = (int) $order->get_meta( '_nowcrm_cart_linked' );

		if ( $cart_id <= 0 ) {
			return;
		}

		( new CartRepository() )->mark_order_failed( $cart_id, (int) $order->get_id(), $reason );
	}

	/**
	 * Paid evidence: an actual paid date plus an explicit confirmation marker
	 * or a persisted Woo transaction id. Status and a synthesized date alone
	 * are never proof, so COD/BACS/cheque stay pending until confirmation.
	 */
	private function has_payment_evidence( \WC_Order $order ): bool {
		if ( ! $order->get_date_paid() ) {
			return false;
		}

		if ( '' !== (string) $order->get_meta( '_nowcrm_payment_confirmed' ) ) {
			return true;
		}

		return '' !== trim( (string) $order->get_transaction_id() );
	}

	/**
	 * Activity observation time in epoch milliseconds, or an error reason.
	 *
	 * @return int|\WP_Error 0 when the client omitted it.
	 */
	private function activity_order( \WP_REST_Request $request ) {
		$raw = $request->get_param( 'activity_order' );

		if ( null === $raw || '' === $raw ) {
			return 0;
		}

		if ( ! is_string( $raw ) && ! is_int( $raw ) ) {
			return new \WP_Error( 'activity_order_invalid' );
		}

		$raw = (string) $raw;

		if ( ! preg_match( '/^\d{10,16}$/', $raw ) ) {
			return new \WP_Error( 'activity_order_invalid' );
		}

		$value = (int) $raw;
		$now   = $this->now_ms();

		if ( $value > $now + self::ACTIVITY_FUTURE_MS ) {
			return new \WP_Error( 'activity_order_future' );
		}

		if ( $value < $now - self::ACTIVITY_STALE_MS ) {
			return new \WP_Error( 'activity_order_stale' );
		}

		return $value;
	}

	/**
	 * Epoch milliseconds.
	 */
	private function now_ms(): int {
		return (int) floor( microtime( true ) * 1000 );
	}

	/**
	 * Legacy client body cycle. Only old requests (no activity_order and no
	 * previous_cycle) can carry it, and it is never identity. The persisted
	 * cookie is never read, so an upgraded client cannot be rejected by a
	 * stale legacy cookie before it binds a fresh server cycle.
	 */
	private function legacy_hint( \WP_REST_Request $request ): string {
		if ( null !== $request->get_param( 'previous_cycle' ) || null !== $request->get_param( 'activity_order' ) ) {
			return '';
		}

		return $this->normalize_cycle( (string) $request->get_param( 'cycle' ) );
	}

	/**
	 * Normalize a cycle candidate. Empty means unusable.
	 */
	private function normalize_cycle( string $candidate ): string {
		$candidate = strtolower( preg_replace( '/[^A-Za-z0-9]/', '', $candidate ) );

		return strlen( $candidate ) >= 16 && strlen( $candidate ) <= 64 ? $candidate : '';
	}

	/**
	 * Current cycle bound to the Woo session.
	 */
	private function current_cycle(): string {
		return function_exists( 'WC' ) && WC()->session
			? $this->normalize_cycle( (string) WC()->session->get( 'nowcrm_cart_cycle' ) )
			: '';
	}

	/**
	 * Forget the cycle so the next purchase starts a new one.
	 */
	private function rotate_cycle(): void {
		if ( function_exists( 'WC' ) && WC()->session ) {
			WC()->session->set( 'nowcrm_cart_cycle', '' );
			WC()->session->set( 'nowcrm_recovered_cart_id', 0 );
			WC()->session->set( 'nowcrm_cycle_generation', (int) WC()->session->get( 'nowcrm_cycle_generation' ) + 1 );
			WC()->session->set( 'nowcrm_cycle_rotated_at', $this->now_ms() );
		}

		if ( ! headers_sent() ) {
			setcookie( 'nowcrm_cycle', '', time() - 3600, COOKIEPATH ?: '/', COOKIE_DOMAIN ?: '', is_ssl(), true );
		}
	}

	/**
	 * Same-origin fetch required. Origin is preferred; Referer is a fallback.
	 */
	private function is_same_origin( \WP_REST_Request $request ): bool {
		$origin = (string) $request->get_header( 'origin' );

		if ( '' === $origin ) {
			$origin = (string) $request->get_header( 'referer' );
		}

		if ( '' === $origin ) {
			return false;
		}

		return $this->canonical_origin( $origin ) !== ''
			&& $this->canonical_origin( $origin ) === $this->canonical_origin( home_url( '/' ) );
	}

	/**
	 * Scheme, lower-case host, and effective port.
	 */
	private function canonical_origin( string $url ): string {
		$parts = wp_parse_url( $url );

		if ( ! is_array( $parts ) || empty( $parts['host'] ) || empty( $parts['scheme'] ) ) {
			return '';
		}

		$scheme = strtolower( (string) $parts['scheme'] );

		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return '';
		}

		$port = isset( $parts['port'] ) ? (int) $parts['port'] : ( 'https' === $scheme ? 443 : 80 );

		return $scheme . '://' . strtolower( (string) $parts['host'] ) . ':' . $port;
	}

	/**
	 * REST nonce from the WordPress header. Guests use the same endpoint
	 * nonce; WordPress cookie auth uses it for authenticated requests.
	 */
	private function has_valid_nonce( \WP_REST_Request $request ): bool {
		$nonce = (string) $request->get_header( 'x-wp-nonce' );

		if ( '' === $nonce ) {
			$nonce = (string) $request->get_param( '_wpnonce' );
		}

		return '' !== $nonce && (bool) wp_verify_nonce( $nonce, 'wp_rest' );
	}

	/**
	 * Load Woo's cart in a REST request.
	 */
	private function maybe_load_cart(): void {
		if ( ! function_exists( 'WC' ) ) {
			return;
		}

		if ( WC()->cart && WC()->session ) {
			return;
		}

		if ( function_exists( 'wc_load_cart' ) ) {
			wc_load_cart();
		}

		if ( ! WC()->session && method_exists( WC(), 'initialize_session' ) ) {
			WC()->initialize_session();
		}
	}

	/**
	 * Soft per-IP rate limit for the public capture endpoint.
	 */
	private function is_rate_limited(): bool {
		$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) ) : '';
		$key = 'nowcrm_cart_rl_' . md5( $ip . wp_salt( 'nowcrm_ip' ) );
		$hit = (int) get_transient( $key );

		if ( $hit >= 60 ) {
			return true;
		}

		set_transient( $key, $hit + 1, MINUTE_IN_SECONDS );

		return false;
	}
}
