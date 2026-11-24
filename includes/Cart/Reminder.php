<?php
/**
 * Abandoned-cart reminder policy and template snapshot.
 *
 * The reminder follows the cart-specific policy: subscribed and
 * transactional contacts are eligible. A newly captured guest becomes a
 * transactional contact, which authorizes this cart mail and nothing else.
 * Other statuses stay blocked, including through a forced run.
 *
 * @package NowCRM
 */

namespace NowCRM\Cart;

use NowCRM\Contacts\Status;
use NowCRM\Email\EmailJobRepository;
use NowCRM\Email\EmailRenderer;
use NowCRM\Email\SuppressionRepository;
use NowCRM\Support\Settings;

defined( 'ABSPATH' ) || exit;

final class Reminder {

	/**
	 * Revalidate a claimed cart job immediately before sending.
	 *
	 * @param array<string, mixed>      $job     Job row.
	 * @param array<string, mixed>|null $contact Contact row.
	 * @return true|string True when allowed, otherwise a machine reason.
	 */
	public static function can_send( array $job, ?array $contact ) {
		if ( ! Settings::bool( 'abandoned_cart_enabled' ) ) {
			return 'cart_disabled';
		}

		$cart = ( new CartRepository() )->get( (int) $job['cart_id'] );

		if ( ! $cart ) {
			return 'cart_missing';
		}

		if ( CartRepository::STATUS_ABANDONED !== $cart['status'] ) {
			return 'cart_not_abandoned';
		}

		$payload = is_array( $job['payload'] ?? null ) ? $job['payload'] : array();
		$source  = (string) ( $job['source_key'] ?? '' );

		if ( '' === $source ) {
			$source = (string) ( $payload['source_key'] ?? '' );
		}

		$current_source = 'cart:' . (int) $cart['id'] . ':' . (int) $cart['version'];

		// The persisted row source is authoritative for versioned jobs. Older
		// legacy rows may carry only the immutable cart version.
		if ( '' !== $source && $source !== $current_source ) {
			return 'cart_version_changed';
		}

		$version = (int) ( $payload['cart_version'] ?? 0 );

		if ( $version > 0 && $version !== (int) $cart['version'] ) {
			return 'cart_version_changed';
		}

		$payload_source = (string) ( $payload['source_key'] ?? '' );

		if ( '' !== $payload_source && $payload_source !== $current_source ) {
			return 'cart_version_changed';
		}

		$run_id = (int) ( $payload['run_id'] ?? 0 );

		if ( $run_id > 0 ) {
			$run = ( new \NowCRM\Automation\RunRepository() )->get( $run_id );

			if ( ! $run || ! in_array( (string) $run['status'], array( \NowCRM\Automation\RunRepository::STATUS_ACTIVE, \NowCRM\Automation\RunRepository::STATUS_WAITING ), true ) ) {
				return 'run_not_active';
			}

			$automation = ( new \NowCRM\Automation\AutomationRepository() )->get( (int) $run['automation_id'] );

			if ( ! $automation || \NowCRM\Automation\AutomationRepository::STATUS_ACTIVE !== (string) $automation['status'] ) {
				return 'automation_inactive';
			}
		}

		if ( ! $contact || ! Status::allows_cart_email( (string) $contact['status'] ) ) {
			return 'not_subscribed';
		}

		if ( mb_strtolower( trim( (string) $job['recipient'] ) ) !== (string) $cart['email_normalized'] ) {
			return 'recipient_mismatch';
		}

		$link_reason = self::recovery_link_reason( $job, $cart );

		if ( null !== $link_reason ) {
			return $link_reason;
		}

		$accepted = max(
			(int) $cart['reminders_sent'],
			(int) ( new EmailJobRepository() )->accepted_count_for_cart( (int) $cart['id'], (string) ( $payload['source_key'] ?? '' ) )
		);

		if ( $accepted >= max( 0, Settings::int( 'cart_max_reminders' ) ) ) {
			return 'max_reminders';
		}

		if ( ( new SuppressionRepository() )->is_suppressed( (string) $cart['email'] ) ) {
			return 'suppressed';
		}

		$cooldown_days = Settings::int( 'cart_cooldown_days' );

		if ( $cooldown_days > 0 ) {
			$last = ( new CartRepository() )->last_reminder_at( (string) $cart['email'] );

			if ( $last && strtotime( $last . ' UTC' ) > time() - $cooldown_days * DAY_IN_SECONDS ) {
				return 'cooldown';
			}
		}

		// This cart's exact Woo order link is written under the same cart lock.
		if ( (int) ( $cart['recovered_order_id'] ?? 0 ) > 0 ) {
			return 'order_exists';
		}

		return true;
	}

	/**
	 * Compose a cart Email step config with the cart items and recovery URL.
	 *
	 * Both the managed and custom flows use this method; the automation Email
	 * step is the only editable content source.
	 *
	 * @param array<string, mixed> $config Step config.
	 * @param array<string, mixed> $cart   Cart row.
	 * @param string               $recovery_url Recovery URL.
	 * @return array<string, mixed>
	 */
	public static function render_cart_template( array $config, array $cart, string $recovery_url ): array {
		$content = str_replace(
			array( '{{cart_items}}', '{{recovery_url}}' ),
			array( self::items_html( $cart ), esc_url( $recovery_url ) ),
			(string) ( $config['content'] ?? '' )
		);

		$template = array(
			'mode'        => (string) ( $config['mode'] ?? 'html' ),
			'content'     => $content,
			'subject'     => (string) ( $config['subject'] ?? '' ),
			'preheader'   => (string) ( $config['preheader'] ?? '' ),
			'footer_text' => (string) ( $config['footer_text'] ?? EmailRenderer::default_footer() ),
		);

		foreach ( array( 'from_name', 'from_address', 'reply_to' ) as $key ) {
			if ( ! empty( $config[ $key ] ) ) {
				$template[ $key ] = (string) $config[ $key ];
			}
		}

		return $template;
	}

	/**
	 * Frozen template stored in the job payload.
	 *
	 * @param array<string, mixed> $job Job row.
	 * @return array<string, mixed>|null
	 */
	public static function template_for_job( array $job ): ?array {
		$payload = (array) ( $job['payload'] ?? array() );
		$template = $payload['template'] ?? null;

		return is_array( $template ) && isset( $template['content'] ) ? $template : null;
	}

	/**
	 * Built-in reminder body.
	 */
	public static function default_content(): string {
		return '<p>{{first_name}} 您好，</p>'
			. '<p>您在結帳頁留下的商品還在購物車裡。如果還在考慮，可以從下面的連結回去完成訂單。</p>'
			. '{{cart_items}}'
			. '<p style="margin-top:24px;"><a href="{{recovery_url}}" style="display:inline-block;background:#111827;color:#ffffff;padding:12px 22px;border-radius:4px;text-decoration:none;font-weight:600;">回到購物車</a></p>'
			. '<p style="font-size:13px;color:#6b7280;">如果已經完成訂單，請忽略這封信。</p>';
	}

	/**
	 * Items table from the captured snapshot.
	 */
	private static function items_html( array $cart ): string {
		$rows = '';

		foreach ( (array) $cart['items'] as $item ) {
			$product_id = (int) ( $item['product_id'] ?? 0 );
			$product    = $product_id ? wc_get_product( $product_id ) : null;

			if ( ! $product ) {
				continue;
			}

			$quantity = (float) ( $item['quantity'] ?? 0 );
			$name     = $product->get_name();
			$rows    .= '<tr>'
				. '<td style="padding:8px 10px;border-bottom:1px solid #e5e7eb;">' . esc_html( $name ) . '</td>'
				. '<td style="padding:8px 10px;border-bottom:1px solid #e5e7eb;text-align:center;">' . esc_html( (string) $quantity ) . '</td>'
				. '</tr>';
		}

		if ( '' === $rows ) {
			return '';
		}

		return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;margin:20px 0;">'
			. '<thead><tr>'
			. '<th style="padding:8px 10px;border-bottom:2px solid #e5e7eb;text-align:left;font-size:13px;color:#6b7280;">' . esc_html__( '商品', 'now-crm' ) . '</th>'
			. '<th style="padding:8px 10px;border-bottom:2px solid #e5e7eb;text-align:center;font-size:13px;color:#6b7280;">' . esc_html__( '數量', 'now-crm' ) . '</th>'
			. '</tr></thead><tbody>' . $rows . '</tbody></table>';
	}

	/**
	 * Check the frozen recovery URL against the cart's current token.
	 *
	 * A repeated sweep must keep the queued URL. A job queued before a token
	 * rotation (older plugin versions) or after the token expired must not
	 * send a dead link. Jobs without a payload URL keep their old contract.
	 *
	 * @param array<string, mixed> $job  Job row.
	 * @param array<string, mixed> $cart Cart row.
	 */
	private static function recovery_link_reason( array $job, array $cart ): ?string {
		$payload = is_array( $job['payload'] ?? null ) ? $job['payload'] : array();
		$url     = (string) ( $payload['recovery_url'] ?? '' );

		if ( '' === $url ) {
			return null;
		}

		$query = (string) wp_parse_url( $url, PHP_URL_QUERY );
		parse_str( $query, $args );
		$raw = isset( $args['token'] ) && is_string( $args['token'] ) ? $args['token'] : '';

		if ( '' === $raw ) {
			return 'recovery_token_missing';
		}

		$hash = (string) ( $cart['recovery_token_hash'] ?? '' );

		if ( '' === $hash || ! hash_equals( $hash, hash( 'sha256', $raw ) ) ) {
			return 'recovery_token_stale';
		}

		$expires = (string) ( $cart['recovery_token_expires'] ?? '' );

		if ( '' === $expires || strtotime( $expires . ' UTC' ) < time() ) {
			return 'recovery_token_expired';
		}

		return null;
	}

}
