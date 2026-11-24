<?php
/**
 * Shared email rendering for Campaigns and cart reminders. One path for the
 * preview, the test send and the real send.
 *
 * @package NowCRM
 */

namespace NowCRM\Email;

use NowCRM\Support\Settings;

defined( 'ABSPATH' ) || exit;

final class EmailRenderer {

	/**
	 * Render a template.
	 *
	 * @param array<string, mixed> $template mode (blocks|html), content, subject, preheader, footer_text.
	 * @param array<string, mixed> $context  Contact and link context.
	 * @return array{subject: string, html: string, text: string}
	 */
	public function render( array $template, array $context ): array {
		$mode    = 'blocks' === ( $template['mode'] ?? 'blocks' ) ? 'blocks' : 'html';
		$content = (string) ( $template['content'] ?? '' );
		$scope   = ConditionalContent::contains_condition( $content );

		if ( $scope ) {
			ConditionalContent::begin( ConditionalContent::contact_from_context( $context ) );
		}

		try {
			if ( 'blocks' === $mode ) {
				$inner = $this->render_blocks( $content );
			} else {
				$inner = $this->sanitize_raw_html( $content );
			}

			if ( $scope ) {
				$inner = ConditionalContent::resolve( $inner );
			}

			$inner = $this->personalize( $inner, $context, true );
			// Only block snapshots carry design; Raw HTML has no design key.
			$design = EmailDesign::normalize( $template['design'] ?? array() );

			// A pasted, complete HTML email already owns its document, container
			// and footer (FluentCRM imports are exactly this shape). Wrapping it
			// again creates a nested <html> and a second card/footer, so send the
			// document as-is and only add a compliance footer when it has none.
			$html = 'html' === $mode && $this->is_full_document( $inner )
				? $this->finalize_document( $inner, $context )
				: $this->wrap( $inner, $template, $context, $design );

			return array(
				'subject' => $this->personalize( (string) ( $template['subject'] ?? '' ), $context, false ),
				'html'    => $html,
				'text'    => $this->html_to_text( $html ),
			);
		} finally {
			if ( $scope ) {
				ConditionalContent::end();
			}
		}
	}

	/**
	 * Whether the raw body is a complete email document rather than a fragment.
	 */
	private function is_full_document( string $html ): bool {
		return false !== stripos( $html, '<!doctype' ) || (bool) preg_match( '/<html[\s>]/i', $html );
	}

	/**
	 * Keep a complete raw document byte-for-byte, adding only the compliance
	 * link it is missing. No layout wrapper, no CSS rewriting.
	 *
	 * @param array<string, mixed> $context Recipient context.
	 */
	private function finalize_document( string $html, array $context ): string {
		// Documents saved before the sanitizer preserved <meta> lost their
		// charset declaration. Re-add one only when the document declares no
		// encoding at all; never override an explicit charset.
		if ( ! preg_match( '/<meta\b[^>]*charset\s*=/i', $html ) && ! preg_match( '/<meta\b[^>]*http-equiv\s*=\s*(["\']?)content-type\1/i', $html ) ) {
			$html = (string) preg_replace( '/<head\b[^>]*>/i', '$0<meta charset="utf-8">', $html, 1 );
		}

		$unsubscribe = (string) ( $context['unsubscribe_url'] ?? '' );

		if ( '' === $unsubscribe || Compliance::has_required_link( $html ) ) {
			return $html;
		}

		$manage = (string) ( $context['manage_url'] ?? '' );
		$links  = '<a href="' . esc_url( $unsubscribe ) . '" style="color:#6b7280;text-decoration:underline;">' . esc_html__( '取消訂閱', 'now-crm' ) . '</a>';

		if ( '' !== $manage ) {
			$links .= ' · <a href="' . esc_url( $manage ) . '" style="color:#6b7280;text-decoration:underline;">' . esc_html__( '管理訂閱偏好', 'now-crm' ) . '</a>';
		}

		$footer = '<div style="padding:24px;text-align:center;font-size:12px;line-height:1.6;color:#6b7280;">' . $links . '</div>';

		if ( preg_match( '#</body>#i', $html ) ) {
			return (string) preg_replace( '#</body>#i', $footer . '</body>', $html, 1 );
		}

		return $html . $footer;
	}

	/**
	 * Render a Gutenberg block document to email-safe HTML.
	 */
	public function render_blocks( string $content ): string {
		// Core block HTML is web HTML: columns/buttons use flexbox and block
		// spacing lives in theme classes. Email clients need tables and inline
		// CSS, so render the same blocks through the email-safe renderer.
		$html = ( new BlockRenderer() )->render( $content );

		/** This filter is documented in the plugin architecture notes. */
		$html = (string) apply_filters( 'nowcrm_email_blocks_html', $html, $content );

		return $this->sanitize_raw_html( $html );
	}

	/**
	 * Strip active content from administrator-supplied HTML while keeping email
	 * layout (tables, styles, images, inline attributes).
	 */
	public function sanitize_raw_html( string $html ): string {
		// <meta charset>/<viewport> and <link> are part of a complete email
		// document; only navigation-capable tags are removed.
		$html = (string) preg_replace( '#<base\b[^>]*>#is', '', $html );
		$html = (string) preg_replace( '#<meta\b[^>]*http-equiv\s*=\s*(["\']?)refresh\1[^>]*>#is', '', $html );
		$html = (string) preg_replace( '#<script\b[^>]*>.*?</script>#is', '', $html );
		$html = (string) preg_replace( '#<iframe\b[^>]*>.*?</iframe>#is', '', $html );
		$html = (string) preg_replace( '#<(object|embed|form|input|button|textarea|select)\b[^>]*>.*?</\1>#is', '', $html );
		$html = (string) preg_replace( '#<(object|embed|form|input|button|textarea|select)\b[^>]*/?>#is', '', $html );
		$html = (string) preg_replace( '#\son[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#i', '', $html );
		$html = (string) preg_replace( '#javascript\s*:#i', '', $html );
		$html = (string) preg_replace( '#expression\s*\(#i', '', $html );

		return $html;
	}

	/**
	 * Replace {{placeholders}} with escaped values.
	 *
	 * @param array<string, mixed> $context Values.
	 */
	public function personalize( string $text, array $context, bool $escape ): string {
		$first = (string) ( $context['first_name'] ?? '' );
		$last  = (string) ( $context['last_name'] ?? '' );
		$name  = trim( $first . ' ' . $last );

		$site_url = (string) ( $context['site_url'] ?? home_url( '/' ) );
		$manage   = (string) ( $context['manage_url'] ?? '' );
		$values   = array(
			'first_name'             => $first,
			'last_name'              => $last,
			'full_name'              => $name ?: __( '您好', 'now-crm' ),
			'email'                  => (string) ( $context['email'] ?? '' ),
			'site_name'              => (string) ( $context['site_name'] ?? get_bloginfo( 'name' ) ),
			'site_url'               => $site_url,
			'unsubscribe_url'        => (string) ( $context['unsubscribe_url'] ?? '' ),
			'manage_url'             => $manage,
			'manage_subscription_url' => $manage,
			'web_preview_url'        => (string) ( $context['web_preview_url'] ?? $site_url ),
			'cart_url'               => (string) ( $context['cart_url'] ?? wc_get_cart_url() ),
			'recovery_url'           => (string) ( $context['recovery_url'] ?? '' ),
			'order_id'               => (string) ( $context['order_id'] ?? '' ),
			'order_total'            => (string) ( $context['order_total'] ?? '' ),
		);

		$replacements = array();

		foreach ( $values as $key => $value ) {
			$replacements[ '{{' . $key . '}}' ] = $escape ? esc_html( $value ) : $value;
		}

		// FluentCRM imports use ##crm.*## and {{crm.*}} smartcodes. Accept the
		// recipient/link subset NOW can resolve so a pasted campaign keeps its
		// real unsubscribe and personalization links.
		$crm = array(
			'crm.first_name'              => $values['first_name'],
			'crm.last_name'               => $values['last_name'],
			'crm.full_name'               => $values['full_name'],
			'crm.email'                   => $values['email'],
			'crm.unsubscribe_url'         => $values['unsubscribe_url'],
			'crm.manage_subscription_url' => $values['manage_subscription_url'],
			'web_preview_url'             => $values['web_preview_url'],
		);

		foreach ( $crm as $key => $value ) {
			$escaped = $escape ? esc_html( (string) $value ) : (string) $value;

			$replacements[ '{{' . $key . '}}' ] = $escaped;
			$replacements[ '##' . $key . '##' ] = $escaped;
		}

		return strtr( $text, $replacements );
	}

	/**
	 * Wrap inner HTML in a mail-client-safe document.
	 *
	 * @param array<string, mixed> $template Template data.
	 * @param array<string, mixed> $context  Context data.
	 */
	public function wrap( string $inner, array $template, array $context, array $design = array() ): string {
		$design     = EmailDesign::normalize( $design );
		$body_bg    = EmailDesign::color( $design, 'body' );
		$content_bg = EmailDesign::color( $design, 'content' );
		$heading    = EmailDesign::color( $design, 'heading' );
		$body_text  = EmailDesign::color( $design, 'text' );
		$link       = EmailDesign::color( $design, 'link' );
		$site_name  = (string) ( $context['site_name'] ?? get_bloginfo( 'name' ) );
		$site_url   = (string) ( $context['site_url'] ?? home_url( '/' ) );
		$preheader  = trim( (string) ( $template['preheader'] ?? '' ) );
		$footer     = Compliance::resolve( (string) ( $template['footer_text'] ?? '' ) );
		$unsub      = (string) ( $context['unsubscribe_url'] ?? '' );
		$manage     = (string) ( $context['manage_url'] ?? '' );
		$inner      = $this->inline_css( $inner, $design );

		if ( '' === $preheader ) {
			$preheader = $this->html_to_text( $inner );
			$preheader = mb_substr( $preheader, 0, 140 );
		}

		$footer_html = '<p style="margin:0 0 6px;font-size:12px;line-height:1.5;color:#6b7280;">' . esc_html( $site_name ) . ' · <a href="' . esc_url( $site_url ) . '" style="color:#6b7280;">' . esc_html( wp_parse_url( $site_url, PHP_URL_HOST ) ?: $site_url ) . '</a></p>';

		if ( '' !== $footer ) {
			$footer_html .= '<p style="margin:0 0 6px;font-size:12px;line-height:1.5;color:#6b7280;">' . Compliance::render_html( $footer, array( 'unsubscribe_url' => $unsub, 'manage_url' => $manage ) ) . '</p>';
		}

		if ( '' !== $unsub && ! Compliance::has_required_link( $footer ) ) {
			$footer_html .= '<p style="margin:0;font-size:12px;line-height:1.5;color:#6b7280;"><a href="' . esc_url( $unsub ) . '" style="color:#6b7280;text-decoration:underline;">' . esc_html__( '取消訂閱', 'now-crm' ) . '</a></p>';
		}

		$style = '
			body,table,td,p,a,li{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Helvetica,Arial,sans-serif;}
			img{max-width:100%;height:auto;border:0;display:block;}
			a{color:' . $link . ';}
			.nowcrm-wrap{width:100%;background:' . $body_bg . ';margin:0;padding:0;}
			.nowcrm-card{width:100%;max-width:600px;background:' . $content_bg . ';border-radius:6px;}
			.nowcrm-body{padding:32px 32px 24px;font-size:16px;line-height:1.65;color:' . $body_text . ';}
			.nowcrm-body h1{font-size:26px;line-height:1.3;margin:0 0 16px;color:' . $heading . ';}
			.nowcrm-body h2{font-size:22px;line-height:1.3;margin:24px 0 12px;color:' . $heading . ';}
			.nowcrm-body h3{font-size:18px;line-height:1.4;margin:20px 0 10px;color:' . $heading . ';}
			.nowcrm-body p{margin:0 0 16px;}
			.nowcrm-body ul,.nowcrm-body ol{margin:0 0 16px;padding-left:22px;}
			.nowcrm-body .wp-block-button__link{display:inline-block;background:#111827;color:#ffffff;padding:12px 22px;border-radius:4px;text-decoration:none;font-weight:600;}
			.nowcrm-body .wp-block-image{margin:0 0 16px;}
			.nowcrm-body table{border-collapse:collapse;}
			@media only screen and (max-width:600px){
				.nowcrm-columns--stack,.nowcrm-columns--stack tbody,.nowcrm-columns--stack tr,.nowcrm-columns--stack td{display:block!important;width:100%!important;box-sizing:border-box;}
			}
			.nowcrm-footer{padding:0 32px 28px;font-size:12px;color:#6b7280;}
		';

		$wrap_attr       = '' !== $design['body'] ? ' style="background-color:' . $design['body'] . ';"' : '';
		$wrap_cell_style = 'padding:28px 12px;' . ( '' !== $design['body'] ? 'background-color:' . $design['body'] . ';' : '' );

		return '<!DOCTYPE html><html lang="' . esc_attr( get_bloginfo( 'language' ) ?: 'zh-Hant' ) . '"><head>'
			. '<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
			. '<meta name="x-apple-disable-message-reformatting">'
			. '<title>' . esc_html( (string) ( $template['subject'] ?? $site_name ) ) . '</title>'
			. '<style type="text/css">' . $style . '</style></head>'
			. '<body style="margin:0;padding:0;background:' . $body_bg . ';">'
			. '<div style="display:none;font-size:1px;color:' . $body_bg . ';line-height:1px;max-height:0;max-width:0;opacity:0;overflow:hidden;">' . esc_html( $preheader ) . '</div>'
			. '<table role="presentation" class="nowcrm-wrap" width="100%" cellpadding="0" cellspacing="0" border="0"' . $wrap_attr . '><tr><td align="center" style="' . $wrap_cell_style . '">'
			. '<table role="presentation" class="nowcrm-card" width="600" cellpadding="0" cellspacing="0" border="0" style="max-width:600px;width:100%;background:' . $content_bg . ';border-radius:6px;">'
			. '<tr><td class="nowcrm-body" style="padding:32px 32px 24px;font-size:16px;line-height:1.65;color:' . $body_text . ';">' . $inner . '</td></tr>'
			. '<tr><td class="nowcrm-footer" style="padding:0 32px 28px;font-size:12px;color:#6b7280;">' . $footer_html . '</td></tr>'
			. '</table></td></tr></table></body></html>';
	}

	/**
	 * Apply a small, predictable set of inline styles. Mail clients that ignore
	 * <style> still get readable typography; unknown selectors are untouched.
	 */
	public function inline_css( string $html, array $design = array() ): string {
		$design      = EmailDesign::normalize( $design );
		$rules       = array(
			'p'   => 'margin:0 0 16px;font-size:16px;line-height:1.65;color:#1f2937;',
			'h1'  => 'font-size:26px;line-height:1.3;margin:0 0 16px;color:#111827;',
			'h2'  => 'font-size:22px;line-height:1.3;margin:24px 0 12px;color:#111827;',
			'h3'  => 'font-size:18px;line-height:1.4;margin:20px 0 10px;color:#111827;',
			'ul'  => 'margin:0 0 16px;padding-left:22px;',
			'ol'  => 'margin:0 0 16px;padding-left:22px;',
			'li'  => 'margin:0 0 8px;font-size:16px;line-height:1.6;',
			'a'   => 'color:#1f2937;text-decoration:underline;',
			'img' => 'max-width:100%;height:auto;border:0;',
		);
		$design_tags = array(
			'p'  => 'text',
			'li' => 'text',
			'td' => 'text',
			'th' => 'text',
			'h1' => 'heading',
			'h2' => 'heading',
			'h3' => 'heading',
			'a'  => 'link',
		);

		foreach ( array_unique( array_merge( array_keys( $rules ), array_keys( $design_tags ) ) ) as $tag ) {
			$style = $rules[ $tag ] ?? '';
			$color = isset( $design_tags[ $tag ] ) ? $design[ $design_tags[ $tag ] ] : '';

			if ( '' !== $color ) {
				$style = preg_match( '/color\s*:/i', $style )
					? (string) preg_replace( '/color\s*:[^;]*;?/i', 'color:' . $color . ';', $style )
					: $style . 'color:' . $color . ';';
			}

			if ( '' === $style ) {
				continue;
			}

			$html = (string) preg_replace_callback(
				'#<' . $tag . '(\s[^>]*)?>#i',
				static function ( array $match ) use ( $style, $color, $tag ): string {
					$attrs = $match[1] ?? '';

					if ( preg_match( '#\sstyle\s*=\s*(["\'])(.*?)\1#is', $attrs, $style_match ) ) {
						// An explicit colour from the block wins; a style that only
						// sets size, spacing, or weight still gets the global colour.
						if ( '' === $color || preg_match( '/(?:^|;)\s*color\s*:/i', $style_match[2] ) ) {
							return $match[0];
						}

						$value = rtrim( $style_match[2], ';' ) . ';color:' . $color . ';';
						$attrs = str_replace( $style_match[0], ' style=' . $style_match[1] . $value . $style_match[1], $attrs );

						return '<' . $tag . $attrs . '>';
					}

					return '<' . $tag . $attrs . ' style="' . $style . '">';
				},
				$html
			);
		}

		// Relative links would break in a mail client; make them absolute.
		$html = (string) preg_replace_callback(
			'#(<a\b[^>]*\shref=")([^"]+)(")#i',
			static function ( array $match ): string {
				$url = html_entity_decode( $match[2], ENT_QUOTES );

				if ( preg_match( '~^(https?:|mailto:|tel:|#)~i', $url ) ) {
					return $match[0];
				}

				return $match[1] . esc_url( home_url( '/' . ltrim( $url, '/' ) ) ) . $match[3];
			},
			$html
		);

		return $html;
	}

	/**
	 * Plain-text alternative.
	 */
	public function html_to_text( string $html ): string {
		$text = (string) preg_replace( '#<(script|style)\b[^>]*>.*?</\1>#is', '', $html );
		$text = (string) preg_replace( '#<br\s*/?>#i', "\n", $text );
		$text = (string) preg_replace( '#</(p|div|h[1-6]|li|tr)>#i', "\n", $text );
		$text = (string) wp_strip_all_tags( $text );
		$text = html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );
		$text = (string) preg_replace( "/[ \t]+/", ' ', $text );
		$text = (string) preg_replace( "/\n{3,}/", "\n\n", $text );

		return trim( $text );
	}

	/**
	 * Footer text used by campaigns when meta is empty.
	 */
	public static function default_footer(): string {
		$from = Settings::string( 'email_from_name' );

		if ( '' === $from ) {
			$from = get_bloginfo( 'name' );
		}

		// Both actions use smartcodes, so they become real links for every
		// recipient at render time: 取消訂閱 or 管理訂閱偏好.
		$footer = $from . "\n{{unsubscribe_url}} 或 {{manage_url}}";

		return (string) apply_filters( 'nowcrm_email_footer_text', $footer );
	}
}
