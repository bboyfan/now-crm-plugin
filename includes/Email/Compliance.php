<?php
/**
 * Compliance footer policy for campaign mail.
 *
 * Ordered resolver for the footer source: campaign template override, then the
 * global consent footer, then the existing default footer text. Marketing mail
 * must carry an unsubscribe/manage link unless it is transactional; smartcodes
 * in the footer are validated against a small allow-list.
 *
 * This class never touches sending. Enforcement points stay in the read-only
 * owners; the seams are:
 * - `Campaigns::confirm()` / `Routes::delivery()` should call `validate()` and
 *   refuse marketing sends without a link.
 * - `EmailRenderer::wrap()` should render `render_html()` output for the
 *   resolved footer instead of plain text, so smartcodes become real links.
 * - `Queue::process_one()` should pass a `manage_url` alongside the existing
 *   `unsubscribe_url` when building renderer context.
 *
 * @package NowCRM
 */

namespace NowCRM\Email;

use NowCRM\Consent\Config;
use NowCRM\Public\PreferencePage;
use WP_Error;

defined( 'ABSPATH' ) || exit;

final class Compliance {

	public const UNSUBSCRIBE_TOKEN = '{{unsubscribe_url}}';
	public const MANAGE_TOKEN      = '{{manage_subscription_url}}';
	public const MANAGE_TOKEN_SHORT = '{{manage_url}}';
	public const CRM_UNSUBSCRIBE_TOKEN = '##crm.unsubscribe_url##';
	public const CRM_MANAGE_TOKEN      = '##crm.manage_subscription_url##';

	/**
	 * Smartcodes the footer parser understands.
	 *
	 * @return string[]
	 */
	public static function allowed_tokens(): array {
		return array(
			self::UNSUBSCRIBE_TOKEN,
			self::MANAGE_TOKEN,
			self::MANAGE_TOKEN_SHORT,
			self::CRM_UNSUBSCRIBE_TOKEN,
			self::CRM_MANAGE_TOKEN,
			'{{crm.unsubscribe_url}}',
			'{{crm.manage_subscription_url}}',
		);
	}

	/**
	 * Inner names of the allowed smartcodes.
	 *
	 * @return string[]
	 */
	private static function allowed_names(): array {
		return array(
			'unsubscribe_url',
			'manage_subscription_url',
			'manage_url',
			'crm.unsubscribe_url',
			'crm.manage_subscription_url',
		);
	}

	/**
	 * Whether text carries an unsubscribe/manage link: a known smartcode or an
	 * anchor pointing at a preference handler.
	 */
	public static function has_required_link( string $text ): bool {
		foreach ( self::allowed_tokens() as $token ) {
			if ( false !== strpos( $text, $token ) ) {
				return true;
			}
		}

		if ( preg_match_all( '/<a\b[^>]*\bhref=(["\'])(.*?)\1/i', $text, $matches ) ) {
			foreach ( $matches[2] as $raw ) {
				$url = html_entity_decode( (string) $raw, ENT_QUOTES );

				if (
					false !== stripos( $url, 'nowcrm_unsubscribe' )
					|| false !== stripos( $url, 'nowcrm_preferences' )
					|| false !== stripos( $url, 'unsubscribe' )
					|| false !== stripos( $url, 'preference' )
				) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Smartcode identifiers in the text that are not on the allow-list.
	 *
	 * @return string[]
	 */
	public static function unknown_tokens( string $text ): array {
		if ( ! preg_match_all( '/\{\{\s*([a-zA-Z0-9_.\-]+)\s*\}\}/', $text, $matches ) ) {
			return array();
		}

		$unknown = array();

		foreach ( $matches[1] as $name ) {
			if ( ! in_array( $name, self::allowed_names(), true ) && ! in_array( $name, $unknown, true ) ) {
				$unknown[] = $name;
			}
		}

		return $unknown;
	}

	/**
	 * Ordered footer resolution: template override, global footer, default.
	 */
	public static function resolve( ?string $template_footer ): string {
		$template_footer = trim( (string) $template_footer );

		if ( '' !== $template_footer ) {
			return $template_footer;
		}

		$global = trim( (string) Config::get( 'footer_text', '' ) );

		if ( '' !== $global ) {
			return $global;
		}

		return EmailRenderer::default_footer();
	}

	/**
	 * Substitute link smartcodes with plain URLs.
	 *
	 * @param array{unsubscribe_url?: string, manage_url?: string} $urls
	 */
	public static function substitute( string $raw, array $urls ): string {
		$unsubscribe = (string) ( $urls['unsubscribe_url'] ?? '' );
		$manage      = self::manage_url( $urls, $unsubscribe );

		$text = str_replace( array( self::UNSUBSCRIBE_TOKEN, self::CRM_UNSUBSCRIBE_TOKEN, '{{crm.unsubscribe_url}}' ), $unsubscribe, $raw );
		$text = str_replace( array( self::MANAGE_TOKEN, self::MANAGE_TOKEN_SHORT, self::CRM_MANAGE_TOKEN, '{{crm.manage_subscription_url}}' ), $manage, $text );

		return $text;
	}

	/**
	 * Render footer HTML for the mail wrapper: paragraphs stay escaped, known
	 * smartcodes become real links.
	 *
	 * @param array{unsubscribe_url?: string, manage_url?: string} $urls
	 */
	public static function render_html( string $raw, array $urls ): string {
		$escaped = nl2br( esc_html( $raw ) );
		$unsub   = (string) ( $urls['unsubscribe_url'] ?? '' );
		$manage  = self::manage_url( $urls, $unsub );

		$links = array(
			self::UNSUBSCRIBE_TOKEN => array(
				'url'   => $unsub,
				'label' => __( '取消訂閱', 'now-crm' ),
			),
			self::MANAGE_TOKEN       => array(
				'url'   => $manage,
				'label' => __( '管理訂閱偏好', 'now-crm' ),
			),
			self::MANAGE_TOKEN_SHORT => array(
				'url'   => $manage,
				'label' => __( '管理訂閱偏好', 'now-crm' ),
			),
			self::CRM_UNSUBSCRIBE_TOKEN => array(
				'url'   => $unsub,
				'label' => __( '取消訂閱', 'now-crm' ),
			),
			self::CRM_MANAGE_TOKEN      => array(
				'url'   => $manage,
				'label' => __( '管理訂閱偏好', 'now-crm' ),
			),
			'{{crm.unsubscribe_url}}'         => array(
				'url'   => $unsub,
				'label' => __( '取消訂閱', 'now-crm' ),
			),
			'{{crm.manage_subscription_url}}' => array(
				'url'   => $manage,
				'label' => __( '管理訂閱偏好', 'now-crm' ),
			),
		);

		foreach ( $links as $token => $link ) {
			if ( '' === $link['url'] ) {
				continue;
			}

			$escaped = str_replace(
				esc_html( $token ),
				'<a href="' . esc_url( $link['url'] ) . '" style="color:#6b7280;text-decoration:underline;">' . esc_html( $link['label'] ) . '</a>',
				$escaped
			);
		}

		return $escaped;
	}

	/**
	 * If the email has an unsubscribe link but no signed management link,
	 * send the reader to the public request form, never the unsubscribe action.
	 *
	 * @param array{unsubscribe_url?: string, manage_url?: string} $urls Link targets.
	 */
	private static function manage_url( array $urls, string $unsubscribe ): string {
		$manage = (string) ( $urls['manage_url'] ?? '' );

		return '' !== $manage || '' === $unsubscribe ? $manage : PreferencePage::url( '' );
	}

	/**
	 * Enforce the footer policy. Transactional mail always passes.
	 *
	 * @return true|WP_Error
	 */
	public static function validate( ?string $template_footer, string $body, bool $transactional ) {
		if ( $transactional ) {
			return true;
		}

		$footer  = (string) $template_footer;
		$unknown = self::unknown_tokens( $footer );

		if ( $unknown ) {
			return new WP_Error(
				'nowcrm_compliance_unknown_smartcode',
				sprintf(
					/* translators: %s: comma-separated smartcode names. */
					__( '頁尾含有不支援的 smartcode：%s。', 'now-crm' ),
					implode( ', ', $unknown )
				)
			);
		}

		if ( self::has_required_link( $footer ) || self::has_required_link( $body ) ) {
			return true;
		}

		return new WP_Error(
			'nowcrm_compliance_missing_link',
			__( '行銷郵件必須包含取消訂閱或管理偏好的連結（{{unsubscribe_url}} / {{manage_url}}），交易信除外。', 'now-crm' )
		);
	}

	/**
	 * Compliance checkpoint for a campaign template. Never rewrites footer
	 * text: an empty footer resolves to the global/default footer at render
	 * time, while an explicit footer without a link is rejected at confirm
	 * instead of being silently amended here.
	 *
	 * @param array<string, mixed>                           $template Campaign template snapshot.
	 * @param array{unsubscribe_url?: string, manage_url?: string} $urls
	 * @return array<string, mixed>
	 */
	public static function for_campaign( array $template, array $urls ): array {
		unset( $urls );

		return $template;
	}

	/**
	 * Queue/kind mapping for callers: only campaigns are marketing mail.
	 */
	public static function is_marketing_kind( string $kind ): bool {
		return 'campaign' === $kind;
	}
}
