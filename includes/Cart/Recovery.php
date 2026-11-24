<?php
/**
 * Signed cart recovery. Every snapshot line is validated before the live cart
 * is detached; the exact previous contents, coupons and removed items are held
 * in memory and written back untouched when any step fails.
 *
 * @package NowCRM
 */

namespace NowCRM\Cart;

use NowCRM\Support\Settings;

defined( 'ABSPATH' ) || exit;

final class Recovery {

	/**
	 * Snapshot keys this class understands. Anything else may be customer or
	 * plugin data that cannot be replayed, so the line is refused instead of
	 * being copied into the live cart.
	 */
	private const ITEM_KEYS = array( 'product_id', 'variation_id', 'quantity', 'variation', 'requires_item_data' );

	/**
	 * Number of live restore writes in progress.
	 */
	private static int $restoring = 0;

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_action( 'template_redirect', array( $this, 'maybe_handle' ), 1 );
	}

	/**
	 * Intercept ?nowcrm_recover=1&token=…
	 */
	public function maybe_handle(): void {
		if ( ! isset( $_GET['nowcrm_recover'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- token is the capability.
			return;
		}

		if ( ! Settings::bool( 'abandoned_cart_enabled' ) ) {
			$this->render_page( 200, __( '購物車提醒目前未啟用。', 'now-crm' ) );
			exit;
		}

		$token = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['token'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$carts = new CartRepository();
		$cart  = $carts->find_by_recovery_token( $token );

		if ( ! $cart ) {
			$this->render_page( 410, __( '這個購物車連結無效或已過期。', 'now-crm' ) );
			exit;
		}

		$this->maybe_load_cart();

		// A token may only revive an abandoned cart. It never logs a customer in
		// and never reactivates another cycle, pending order or completed cart.
		if ( ! self::supports_status( (string) $cart['status'] ) ) {
			if ( in_array( (string) $cart['status'], array( CartRepository::STATUS_COMPLETED, CartRepository::STATUS_RECOVERED ), true ) ) {
				$this->render_page( 200, __( '這個購物車已經完成訂單，不需要再還原。', 'now-crm' ) );
			} else {
				$this->render_page( 410, __( '這個購物車連結已失效，或購物車已有較新的活動。', 'now-crm' ) );
			}

			exit;
		}

		$cart_id = (int) $cart['id'];

		// A click is part of the funnel even if the cart itself cannot be
		// restored, so it is recorded separately from a successful restore.
		$carts->record_event( $cart_id, 'recovery_click' );

		$validation = $this->validate_items( $cart );

		if ( ! $validation['ok'] ) {
			$this->render_page(
				409,
				__( '購物車內有商品已無法購買，為避免覆蓋您目前的購物車，這次沒有進行還原。', 'now-crm' ),
				$validation['errors']
			);
			exit;
		}

		if ( ! $this->session_cycle_matches( $cart ) ) {
			$this->render_page( 409, __( '您目前的購物車已有其他商品，為避免覆蓋，這次沒有進行還原。', 'now-crm' ) );
			exit;
		}

		$restored = $this->restore(
			$cart,
			function () use ( $carts, $cart ): bool {
				return $this->adopt( $carts, $cart );
			}
		);

		if ( ! $restored['ok'] ) {
			$this->render_page( 409, $restored['error'] );
			exit;
		}

		// adopt_recovery() already recorded the cart_restored event for this
		// exact version after it transferred ownership.
		$this->set_session( $cart );
		wp_safe_redirect( add_query_arg( 'nowcrm_recovered', '1', wc_get_checkout_url() ) );
		exit;
	}

	/**
	 * A recovery token may only act on an abandoned cart.
	 */
	public static function supports_status( string $status ): bool {
		return CartRepository::STATUS_ABANDONED === $status;
	}

	/**
	 * Whether restore() is rebuilding, reverting, or clearing the live cart.
	 *
	 * Cart mutation listeners must ignore these writes: they rebuild the
	 * customer's own abandoned cart, not a new customer action.
	 */
	public static function is_restoring(): bool {
		return self::$restoring > 0;
	}

	/**
	 * Validate every snapshot line before touching the live cart.
	 *
	 * @param array<string, mixed> $cart Cart row or snapshot.
	 * @return array{ok: bool, errors: string[]}
	 */
	public function validate_items( array $cart ): array {
		$errors   = array();
		$items    = isset( $cart['items'] ) && is_array( $cart['items'] ) ? array_values( $cart['items'] ) : array();
		$stock    = array(); // Stock owner id => requested quantity.
		$owners   = array(); // Stock owner id => product object.
		$sold_qty = array(); // Merge identity => quantity for sold-individually lines.
		$sold     = array(); // Merge identity => product name.

		if ( ! $items ) {
			return array(
				'ok'     => false,
				'errors' => array( __( '沒有可還原的商品。', 'now-crm' ) ),
			);
		}

		$errors = array_merge( $errors, $this->validate_coupons( $cart ) );

		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				$errors[] = __( '商品資料格式不正確。', 'now-crm' );
				continue;
			}

			$product_id      = isset( $item['product_id'] ) ? absint( $item['product_id'] ) : 0;
			$variation_id    = isset( $item['variation_id'] ) ? absint( $item['variation_id'] ) : 0;
			$quantity        = isset( $item['quantity'] ) && is_numeric( $item['quantity'] ) ? (float) $item['quantity'] : 0.0;
			$variation_input = isset( $item['variation'] ) ? $item['variation'] : array();

			if ( ! empty( $item['requires_item_data'] ) ) {
				$errors[] = sprintf( /* translators: %d: product id. */ __( '商品 #%d 含有外掛自訂的購物車資料，無法安全還原。', 'now-crm' ), $product_id );
				continue;
			}

			if ( array_diff( array_keys( $item ), self::ITEM_KEYS ) ) {
				$errors[] = sprintf( /* translators: %d: product id. */ __( '商品 #%d 含有無法辨識的購物車資料，無法安全還原。', 'now-crm' ), $product_id );
				continue;
			}

			if ( ! is_array( $variation_input ) ) {
				$errors[] = sprintf( /* translators: %d: product id. */ __( '商品 #%d 的規格資料格式不正確。', 'now-crm' ), $product_id );
				continue;
			}

			$line = $this->resolve_line( $product_id, $variation_id, $variation_input, $quantity, $errors );

			if ( null === $line ) {
				continue;
			}

			$product = $line['product'];

			if ( $product->is_sold_individually() ) {
				$sold_qty[ $line['identity'] ] = ( $sold_qty[ $line['identity'] ] ?? 0.0 ) + $quantity;
				$sold[ $line['identity'] ]     = $product->get_name();
			}

			$owner_id = (int) $product->get_stock_managed_by_id();
			$stock[ $owner_id ] = ( $stock[ $owner_id ] ?? 0.0 ) + $quantity;

			if ( ! isset( $owners[ $owner_id ] ) ) {
				$owners[ $owner_id ] = $owner_id === (int) $product->get_id() ? $product : wc_get_product( $owner_id );
			}
		}

		foreach ( $stock as $owner_id => $quantity ) {
			$owner = $owners[ $owner_id ] ?? null;

			if ( ! $owner instanceof \WC_Product ) {
				$errors[] = __( '商品庫存資料不完整，無法安全還原。', 'now-crm' );
				continue;
			}

			if ( $owner->has_enough_stock( $quantity ) ) {
				continue;
			}

			$errors[] = sprintf( /* translators: %s: product name. */ __( '「%s」庫存不足。', 'now-crm' ), $owner->get_name() );
		}

		foreach ( $sold_qty as $identity => $quantity ) {
			if ( $quantity > 1 ) {
				$errors[] = sprintf( /* translators: %s: product name. */ __( '「%s」限量一件，無法還原多件。', 'now-crm' ), $sold[ $identity ] );
			}
		}

		return array(
			'ok'     => ! $errors,
			'errors' => $errors,
		);
	}

	/**
	 * Replace the live cart with the snapshot.
	 *
	 * The live cart is only detached after validation passes. Its exact item
	 * arrays (including third-party line data), applied coupons and removed
	 * items are kept verbatim and put back when a line, its quantity or the
	 * pre-commit adoption callback fails.
	 *
	 * @param array<string, mixed>    $cart       Cart row or snapshot.
	 * @param (callable(): bool)|null $pre_commit Adoption gate that must succeed before the new cart is committed.
	 * @return array{ok: bool, error: string}
	 */
	public function restore( array $cart, ?callable $pre_commit = null ): array {
		$this->maybe_load_cart();

		$validation = $this->validate_items( $cart );

		if ( ! $validation['ok'] ) {
			return array(
				'ok'    => false,
				'error' => __( '購物車內有商品已無法購買，為避免覆蓋您目前的購物車，這次沒有進行還原。', 'now-crm' ),
			);
		}

		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return array(
				'ok'    => false,
				'error' => __( '無法載入購物車。', 'now-crm' ),
			);
		}

		$live     = WC()->cart;
		$original = array(
			'contents' => $live->cart_contents,
			'coupons'  => $live->applied_coupons,
			'removed'  => $live->removed_cart_contents,
		);

		$live->cart_contents = array();

		$added   = array();
		$failed  = false;
		$blocked = false;

		++self::$restoring;

		try {
			foreach ( (array) $cart['items'] as $item ) {
				$key = $live->add_to_cart(
					(int) $item['product_id'],
					(float) $item['quantity'],
					(int) ( $item['variation_id'] ?? 0 ),
					isset( $item['variation'] ) && is_array( $item['variation'] ) ? $item['variation'] : array()
				);

				if ( ! $key ) {
					$failed = true;
					break;
				}

				$added[ $key ] = ( $added[ $key ] ?? 0.0 ) + (float) $item['quantity'];
			}
		} catch ( \Throwable $e ) {
			$failed = true;
		} finally {
			--self::$restoring;
		}

		if ( ! $failed ) {
			foreach ( $added as $key => $quantity ) {
				if ( ! isset( $live->cart_contents[ $key ] ) || (float) $live->cart_contents[ $key ]['quantity'] !== (float) $quantity ) {
					$failed = true;
					break;
				}
			}
		}

		if ( ! $failed && null !== $pre_commit ) {
			$blocked = true !== $pre_commit();
			$failed  = $blocked;
		}

		if ( $failed ) {
			$this->write_original( $live, $original );

			return array(
				'ok'    => false,
				'error' => $blocked
					? __( '這個還原連結已失效，或購物車已有較新的內容；已保留您原本的購物車。', 'now-crm' )
					: __( '還原時發生錯誤，已保留您原本的購物車。', 'now-crm' ),
			);
		}

		$live->removed_cart_contents = array();
		$live->calculate_totals();
		$live->persistent_cart_update();

		return array(
			'ok'    => true,
			'error' => '',
		);
	}

	/**
	 * Resolve and validate one snapshot line.
	 *
	 * @param array<string, mixed> $variation_input Snapshot variation attributes.
	 * @param string[]             $errors          Collected errors, appended to.
	 * @return array{product: \WC_Product, identity: string}|null
	 */
	private function resolve_line( int $product_id, int $variation_id, array $variation_input, float $quantity, array &$errors ): ?array {
		if ( $product_id <= 0 ) {
			$errors[] = __( '商品資料不完整。', 'now-crm' );

			return null;
		}

		if ( ! is_finite( $quantity ) || $quantity <= 0 ) {
			$errors[] = sprintf( /* translators: %d: product id. */ __( '商品 #%d 的數量不正確。', 'now-crm' ), $product_id );

			return null;
		}

		$parent = wc_get_product( $product_id );

		if ( $variation_id > 0 ) {
			$variation = wc_get_product( $variation_id );

			if ( ! $variation instanceof \WC_Product_Variation
				|| ! $parent instanceof \WC_Product
				|| (int) $variation->get_parent_id() !== $product_id ) {
				$errors[] = sprintf( /* translators: %d: product id. */ __( '商品 #%d 的規格已不存在。', 'now-crm' ), $product_id );

				return null;
			}

			if ( ! $this->variation_matches( $parent, $variation, $variation_input, $errors ) ) {
				return null;
			}

			$product = $variation;
		} else {
			if ( ! $parent instanceof \WC_Product ) {
				$errors[] = sprintf( /* translators: %d: product id. */ __( '商品 #%d 已不存在。', 'now-crm' ), $product_id );

				return null;
			}

			if ( $parent->is_type( 'variable' ) ) {
				$errors[] = sprintf( /* translators: %d: product id. */ __( '商品 #%d 需要選擇規格才能還原。', 'now-crm' ), $product_id );

				return null;
			}

			if ( $variation_input ) {
				$errors[] = sprintf( /* translators: %d: product id. */ __( '商品 #%d 含有無法辨識的規格資料。', 'now-crm' ), $product_id );

				return null;
			}

			$product = $parent;
		}

		if ( ! $product->is_purchasable() || ! $product->is_in_stock() ) {
			$errors[] = sprintf( /* translators: %s: product name. */ __( '「%s」目前無法購買。', 'now-crm' ), $product->get_name() );

			return null;
		}

		if ( $product->is_sold_individually() && 1.0 !== $quantity ) {
			$errors[] = sprintf( /* translators: %s: product name. */ __( '「%s」限量一件，無法還原多件。', 'now-crm' ), $product->get_name() );

			return null;
		}

		return array(
			'product'  => $product,
			'identity' => $product_id . ':' . $variation_id . ':' . (string) wp_json_encode( $variation_input ),
		);
	}

	/**
	 * Validate that a snapshot's variation belongs to its parent and that the
	 * recorded attributes are the ones the variation actually offers. This
	 * covers taxonomy and custom ("generic") attributes.
	 *
	 * @param array<string, mixed> $payload Snapshot variation attributes.
	 * @param string[]             $errors  Collected errors, appended to.
	 */
	private function variation_matches( \WC_Product $parent, \WC_Product_Variation $variation, array $payload, array &$errors ): bool {
		$ok       = true;
		$expected = $variation->get_variation_attributes();
		$allowed  = array();

		foreach ( $parent->get_attributes() as $attribute ) {
			if ( ! $attribute instanceof \WC_Product_Attribute || ! $attribute->get_variation() ) {
				continue;
			}

			$key             = 'attribute_' . sanitize_title( $attribute->get_name() );
			$allowed[ $key ] = true;

			if ( ! array_key_exists( $key, $payload ) ) {
				$errors[] = sprintf(
					/* translators: 1: product id, 2: attribute label. */
					__( '商品 #%1$d 缺少規格「%2$s」。', 'now-crm' ),
					(int) $variation->get_parent_id(),
					wc_attribute_label( $attribute->get_name() )
				);
				$ok = false;
				continue;
			}

			$value = is_scalar( $payload[ $key ] ) ? (string) $payload[ $key ] : '';
			$valid = isset( $expected[ $key ] ) ? (string) $expected[ $key ] : '';

			if ( '' === $valid ) {
				if ( '' === $value || ! in_array( $value, $attribute->get_slugs(), true ) ) {
					$errors[] = sprintf(
						/* translators: 1: product id, 2: attribute label. */
						__( '商品 #%1$d 的規格「%2$s」已無法選購。', 'now-crm' ),
						(int) $variation->get_parent_id(),
						wc_attribute_label( $attribute->get_name() )
					);
					$ok = false;
				}
			} elseif ( $value !== $valid ) {
				$errors[] = sprintf(
					/* translators: 1: product id, 2: attribute label. */
					__( '商品 #%1$d 的規格「%2$s」與紀錄不符。', 'now-crm' ),
					(int) $variation->get_parent_id(),
					wc_attribute_label( $attribute->get_name() )
				);
				$ok = false;
			}
		}

		foreach ( array_keys( $payload ) as $key ) {
			if ( ! is_string( $key ) || 0 !== strpos( $key, 'attribute_' ) || ! isset( $allowed[ $key ] ) ) {
				$errors[] = sprintf( /* translators: %d: product id. */ __( '商品 #%d 含有無法辨識的規格資料。', 'now-crm' ), (int) $variation->get_parent_id() );
				$ok = false;
			}
		}

		return $ok;
	}

	/**
	 * Coupons cannot be replayed from the snapshot: the capture only records
	 * line items, and an individual-use or cart-total dependent coupon may no
	 * longer mean the same discount. The conservative policy is to keep the
	 * live cart's coupons and refuse a snapshot that declares coupons which are
	 * not already applied to this cart.
	 *
	 * @param array<string, mixed> $cart Cart row or snapshot.
	 * @return string[]
	 */
	private function validate_coupons( array $cart ): array {
		if ( ! array_key_exists( 'coupons', $cart ) || null === $cart['coupons'] ) {
			return array();
		}

		$unsupported = __( '原購物車的優惠券資料格式不正確，無法安全還原。', 'now-crm' );

		if ( ! is_array( $cart['coupons'] ) ) {
			return array( $unsupported );
		}

		$live = function_exists( 'WC' ) && WC()->cart ? (array) WC()->cart->get_applied_coupons() : array();

		foreach ( $cart['coupons'] as $code ) {
			if ( ! is_string( $code ) || '' === $code ) {
				return array( $unsupported );
			}

			if ( ! in_array( $code, $live, true ) ) {
				return array( __( '原購物車的優惠券無法自動套用，為避免折扣錯誤，這次沒有進行還原。', 'now-crm' ) );
			}
		}

		return array();
	}

	/**
	 * Whether the session's live cart may be replaced without losing a newer
	 * cycle owner. An empty cart is always safe to replace.
	 *
	 * @param array<string, mixed> $cart Cart row.
	 */
	private function session_cycle_matches( array $cart ): bool {
		if ( ! function_exists( 'WC' ) || ! WC()->cart || WC()->cart->is_empty() ) {
			return true;
		}

		$cycle = function_exists( 'WC' ) && WC()->session ? (string) WC()->session->get( 'nowcrm_cart_cycle' ) : '';
		$want  = (string) ( $cart['cart_cycle_key'] ?? '' );

		// A non-empty cart with no bound cycle is newer than the token: refuse
		// rather than silently destroying it.
		return '' !== $cycle && $cycle === $want;
	}

	/**
	 * Adopt the exact cart row through the repository's compare-and-swap.
	 * Without adoption the recovery must not change cycle ownership.
	 *
	 * @param array<string, mixed> $cart Cart row loaded from the verified token.
	 */
	private function adopt( CartRepository $carts, array $cart ): bool {
		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return false;
		}

		$session_key = (string) WC()->session->get_customer_id();

		if ( '' === $session_key ) {
			return false;
		}

		return $carts->adopt_recovery( $cart, $session_key );
	}

	/**
	 * Write the previous cart back verbatim and recalculate its totals.
	 *
	 * @param array{contents: array<string, mixed>, coupons: array<int, string>, removed: array<string, mixed>} $original Captured live cart.
	 */
	private function write_original( \WC_Cart $live, array $original ): void {
		$live->cart_contents         = $original['contents'];
		$live->applied_coupons       = $original['coupons'];
		$live->removed_cart_contents = $original['removed'];
		$live->calculate_totals();
		$live->persistent_cart_update();
	}

	/**
	 * Tie the session to the recovered cycle so an order is attributed to the
	 * email click rather than a natural checkout. Only the server session
	 * carries this provenance; no client cookie or token state is written.
	 */
	private function set_session( array $cart ): void {
		if ( function_exists( 'WC' ) && WC()->session ) {
			WC()->session->set( 'nowcrm_recovered_cart_id', (int) $cart['id'] );
			WC()->session->set( 'nowcrm_cart_cycle', (string) $cart['cart_cycle_key'] );
		}

		// Drop any stale client hint so later capture cannot prefer an older
		// cycle over the adopted server session value.
		if ( ! headers_sent() ) {
			setcookie(
				'nowcrm_cycle',
				'',
				time() - HOUR_IN_SECONDS,
				COOKIEPATH ?: '/',
				COOKIE_DOMAIN ?: '',
				is_ssl(),
				true
			);
		}
	}

	/**
	 * Load Woo's cart outside the normal checkout flow.
	 */
	private function maybe_load_cart(): void {
		if ( function_exists( 'WC' ) && WC()->cart ) {
			return;
		}

		if ( function_exists( 'wc_load_cart' ) ) {
			wc_load_cart();
		}
	}

	/**
	 * Minimal standalone page.
	 *
	 * @param string[] $details Extra lines.
	 */
	private function render_page( int $status, string $message, array $details = array() ): void {
		status_header( $status );
		if ( ! headers_sent() ) {
			header( 'Content-Type: text/html; charset=UTF-8' );
			header( 'X-Robots-Tag: noindex, nofollow' );
			header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
		}

		$site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$list = '';

		foreach ( $details as $detail ) {
			$list .= '<li>' . esc_html( $detail ) . '</li>';
		}

		echo '<!DOCTYPE html><html lang="zh-Hant"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
			. '<meta name="robots" content="noindex,nofollow"><title>' . esc_html( $message ) . '</title></head>'
			. '<body style="margin:0;background:#f4f5f7;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Helvetica,Arial,sans-serif;">'
			. '<div style="max-width:560px;margin:60px auto;background:#fff;border-radius:8px;padding:40px;">'
			. '<h1 style="margin:0 0 12px;font-size:22px;color:#111827;">' . esc_html( $site ) . '</h1>'
			. '<p style="margin:0;font-size:16px;line-height:1.6;color:#374151;">' . esc_html( $message ) . '</p>';

		if ( '' !== $list ) {
			echo '<ul style="margin:16px 0 0;padding-left:20px;color:#6b7280;font-size:14px;line-height:1.7;">' . wp_kses_post( $list ) . '</ul>';
		}

		echo '<p style="margin:24px 0 0;"><a href="' . esc_url( function_exists( 'wc_get_cart_url' ) ? wc_get_cart_url() : home_url( '/' ) ) . '" style="color:#111827;">'
			. esc_html__( '回到購物車', 'now-crm' ) . '</a></p></div></body></html>';
	}
}
