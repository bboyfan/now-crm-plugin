<?php
/**
 * Optional checkout newsletter consent checkbox (Classic and Blocks).
 *
 * The checkbox records consent on the order. For a new contact the saved
 * consent decides the status: an explicit decline stops auto subscription.
 *
 * @package NowCRM
 */

namespace NowCRM\Woo;

use NowCRM\Support\Settings;
use WC_Order;

defined( 'ABSPATH' ) || exit;

final class CheckoutOptin {

	public const META_KEY        = '_nowcrm_marketing_optin';
	public const BLOCKS_META_KEY = '_wc_other/nowcrm/marketing-optin';
	public const FIELD_ID        = 'nowcrm/marketing-optin';
	public const FIELD_NAME      = 'nowcrm_marketing_optin';

	/**
	 * Register the checkout integrations.
	 *
	 * Callbacks are always registered and check the setting at call time so the
	 * wp-admin toggle takes effect immediately.
	 */
	public function register(): void {
		add_action( 'woocommerce_init', array( $this, 'register_blocks_field' ) );
		add_action( 'woocommerce_checkout_before_order_review', array( $this, 'render_classic_checkbox' ) );
		add_action( 'woocommerce_checkout_create_order', array( $this, 'save_classic_checkout' ), 10, 2 );
	}

	/**
	 * Whether the operator enabled the checkbox.
	 */
	public function enabled(): bool {
		return Settings::bool( 'show_checkout_subscription_checkbox' );
	}

	/**
	 * Register the Blocks checkout field through the public Additional Checkout
	 * Fields API, which stores the value on the order.
	 */
	public function register_blocks_field(): void {
		if ( ! $this->enabled() || ! function_exists( 'woocommerce_register_additional_checkout_field' ) ) {
			return;
		}

		woocommerce_register_additional_checkout_field(
			array(
				'id'       => self::FIELD_ID,
				'label'    => __( '訂閱電子報', 'now-crm' ),
				'location' => 'order',
				'type'     => 'checkbox',
				'required' => false,
			)
		);
	}

	/**
	 * Render the checkbox in the classic (shortcode) checkout.
	 *
	 * The hook sits inside the checkout form but outside the order-review
	 * fragments that WooCommerce refreshes over AJAX. The checkbox starts
	 * unchecked; only an explicit previous POST survives a failed submission.
	 */
	public function render_classic_checkbox(): void {
		if ( ! $this->enabled() ) {
			return;
		}

		$posted = $this->posted_classic_choice();

		printf(
			'<p class="form-row nowcrm-newsletter-optin"><label class="woocommerce-form__label woocommerce-form__label-for-checkbox checkbox"><input type="checkbox" class="woocommerce-form__input woocommerce-form__input-checkbox input-checkbox" name="%1$s" value="1"%3$s /> <span>%2$s</span></label></p>',
			esc_attr( self::FIELD_NAME ),
			esc_html__( '訂閱電子報', 'now-crm' ),
			checked( true === $posted, true, false )
		);
	}

	/**
	 * Persist the classic checkout checkbox on the order.
	 *
	 * Only a WooCommerce checkout submission records a value. A Blocks value is
	 * never overwritten.
	 *
	 * @param WC_Order             $order Order being created.
	 * @param array<string, mixed> $data  Checkout data.
	 */
	public function save_classic_checkout( $order, array $data = array() ): void {
		if ( ! $this->enabled() || ! $order instanceof WC_Order ) {
			return;
		}

		$posted = $this->posted_classic_choice();

		if ( null === $posted || 'checkout' !== (string) $order->get_created_via() ) {
			return;
		}

		if ( $order->meta_exists( self::BLOCKS_META_KEY ) ) {
			return;
		}

		$order->update_meta_data( self::META_KEY, $posted ? '1' : '0' );
	}

	/**
	 * Posted classic checkbox choice.
	 *
	 * Null means this is not a WooCommerce checkout submission. Otherwise the
	 * value is a strict boolean; arrays and other invalid values are declines.
	 */
	private function posted_classic_choice(): ?bool {
		if ( ! isset( $_POST['woocommerce-process-checkout-nonce'] ) ) {
			return null;
		}

		if ( ! isset( $_POST[ self::FIELD_NAME ] ) ) {
			return false;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verifies its own checkout nonce.
		$value = wp_unslash( $_POST[ self::FIELD_NAME ] );

		return is_string( $value ) && '1' === $value;
	}

	/**
	 * Normalized consent value of an order: true, false, or null when unknown.
	 *
	 * A boolean false from the metadata API is an explicit decline. Arrays and
	 * other non-scalar values are unknown, never consent.
	 *
	 * @param WC_Order $order Order.
	 */
	public static function normalize_order_optin( $order ): ?bool {
		if ( ! $order instanceof WC_Order ) {
			return null;
		}

		foreach ( array( self::META_KEY, self::BLOCKS_META_KEY ) as $key ) {
			$value = $order->get_meta( $key, true );

			if ( null === $value || '' === $value ) {
				continue;
			}

			if ( is_bool( $value ) ) {
				return $value;
			}

			if ( ! is_scalar( $value ) ) {
				continue;
			}

			return in_array( (string) $value, array( '1', 'yes', 'true' ), true );
		}

		return null;
	}

	/**
	 * Whether WooCommerce created the order during a native checkout.
	 *
	 * @param WC_Order $order Order.
	 */
	public static function is_native_checkout( $order ): bool {
		if ( ! $order instanceof WC_Order ) {
			return false;
		}

		return in_array( (string) $order->get_created_via(), array( 'checkout', 'store-api' ), true );
	}

	/**
	 * Whether a new contact from this order must start subscribed.
	 *
	 * An explicit decline always wins. When double opt-in is required, a new
	 * contact stays transactional until confirmation. Otherwise, explicit
	 * consent subscribes, and auto-subscribe applies only when no choice was
	 * saved and the order did not show a native checkout checkbox.
	 *
	 * @param WC_Order  $order          Order.
	 * @param bool      $auto_subscribe Auto-subscribe setting.
	 * @param bool|null $optin          Normalized saved consent.
	 * @param bool      $doi_required   Effective double opt-in requirement.
	 */
	public static function should_subscribe_new_contact( $order, bool $auto_subscribe, ?bool $optin, bool $doi_required = false ): bool {
		if ( false === $optin ) {
			return false;
		}

		// A required confirmation always starts at transactional, even after an
		// explicit yes. The consent layer starts one confirmation afterwards.
		if ( $doi_required ) {
			return false;
		}

		if ( true === $optin ) {
			return true;
		}

		$silent_blocked = self::is_native_checkout( $order ) && Settings::bool( 'show_checkout_subscription_checkbox' );

		return $auto_subscribe && ! $silent_blocked;
	}
}
