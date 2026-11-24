<?php
/**
 * Email-safe design tokens shared by the block editor and the renderer.
 *
 * The web editor can use rem, clamp() and CSS variables. Mail clients cannot.
 * These presets keep the same slugs but resolve to fixed pixel values and
 * system font stacks, so a block inserted from a pattern renders predictably
 * in Gmail, Apple Mail and Outlook.
 *
 * @package NowCRM
 */

namespace NowCRM\Email;

defined( 'ABSPATH' ) || exit;

final class DesignTokens {

	public const FONT_SANS = "'PingFang TC','Microsoft JhengHei','Noto Sans TC',Arial,sans-serif";

	public const FONT_SERIF = "Georgia,'Noto Serif TC','Songti TC',serif";

	/**
	 * @return array<int, array{name: string, slug: string, fontFamily: string}>
	 */
	public static function font_families(): array {
		return array(
			array(
				'name'       => __( '黑體（系統）', 'now-crm' ),
				'slug'       => 'sans',
				'fontFamily' => self::FONT_SANS,
			),
			array(
				'name'       => __( '襯線', 'now-crm' ),
				'slug'       => 'serif',
				'fontFamily' => self::FONT_SERIF,
			),
		);
	}

	/**
	 * Keep the core slugs so existing block markup keeps resolving. Sizes are
	 * fixed pixels instead of the theme's fluid rem/clamp values.
	 *
	 * @return array<int, array{name: string, slug: string, size: string}>
	 */
	public static function font_sizes(): array {
		return array(
			array(
				'name' => __( '小字', 'now-crm' ),
				'slug' => 'small',
				'size' => '14px',
			),
			array(
				'name' => __( '正文', 'now-crm' ),
				'slug' => 'medium',
				'size' => '16px',
			),
			array(
				'name' => __( '大正文', 'now-crm' ),
				'slug' => 'large',
				'size' => '20px',
			),
			array(
				'name' => __( '標題', 'now-crm' ),
				'slug' => 'x-large',
				'size' => '26px',
			),
			array(
				'name' => __( '大標題', 'now-crm' ),
				'slug' => 'xx-large',
				'size' => '34px',
			),
			array(
				'name' => __( '主視覺', 'now-crm' ),
				'slug' => 'display',
				'size' => '42px',
			),
		);
	}

	/**
	 * Numeric slugs stay aligned with their pixel values. This preserves the
	 * renderer's historical `var:preset|spacing|40` => 40px behaviour while
	 * replacing the theme's clamp() scale in the editor palette.
	 *
	 * @return array<int, array{name: string, slug: string, size: string}>
	 */
	public static function spacing_sizes(): array {
		$sizes = array();

		foreach ( array( 10, 20, 30, 40, 50, 60, 70, 80 ) as $pixels ) {
			$sizes[] = array(
				'name' => (string) $pixels,
				'slug' => (string) $pixels,
				'size' => $pixels . 'px',
			);
		}

		return $sizes;
	}

	public static function font_size( string $slug ): string {
		foreach ( self::font_sizes() as $preset ) {
			if ( $slug === $preset['slug'] ) {
				return $preset['size'];
			}
		}

		return is_numeric( $slug ) ? $slug . 'px' : '';
	}

	public static function spacing( string $slug ): string {
		return is_numeric( $slug ) ? $slug . 'px' : '';
	}

	/**
	 * Override the theme's clamp/rem preset variables inside the editor
	 * canvas, so the spacing picker and the rendered email agree.
	 */
	public static function editor_css(): string {
		$css = ':root{';

		foreach ( self::font_sizes() as $preset ) {
			$css .= '--wp--preset--font-size--' . $preset['slug'] . ':' . $preset['size'] . '!important;';
		}

		foreach ( self::font_families() as $preset ) {
			$css .= '--wp--preset--font-family--' . $preset['slug'] . ':' . $preset['fontFamily'] . '!important;';
		}

		foreach ( self::spacing_sizes() as $preset ) {
			$css .= '--wp--preset--spacing--' . $preset['slug'] . ':' . $preset['size'] . '!important;';
		}

		return $css . '}';
	}

	public static function font_family( string $slug ): string {
		foreach ( self::font_families() as $preset ) {
			if ( $slug === $preset['slug'] ) {
				return $preset['fontFamily'];
			}
		}

		return '';
	}
}
