<?php
/**
 * Email section patterns and editor design tokens.
 *
 * Patterns are only registered for the disposable NOW CRM editor post type,
 * so they do not leak into normal WordPress posts. Their markup stays on core
 * blocks; BlockRenderer turns the saved block document into table HTML.
 *
 * @package NowCRM
 */

namespace NowCRM\Editor;

use NowCRM\Email\DesignTokens;

defined( 'ABSPATH' ) || exit;

final class Patterns {

	private const CATEGORY = 'nowcrm-email';

	public function register(): void {
		add_action( 'init', array( $this, 'register_patterns' ) );
		add_filter( 'block_editor_settings_all', array( $this, 'editor_settings' ), 20, 2 );
	}

	public function register_patterns(): void {
		if ( ! function_exists( 'register_block_pattern' ) ) {
			return;
		}

		if ( class_exists( '\WP_Block_Pattern_Categories_Registry' ) ) {
			$categories = \WP_Block_Pattern_Categories_Registry::get_instance();
			if ( ! $categories->is_registered( self::CATEGORY ) ) {
				register_block_pattern_category(
					self::CATEGORY,
					array( 'label' => __( 'NOW CRM 電子報', 'now-crm' ) )
				);
			}
		}

		foreach ( self::patterns() as $slug => $pattern ) {
			register_block_pattern( 'nowcrm/' . $slug, $pattern );
		}
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	public static function patterns(): array {
		$shared = array(
			'categories' => array( self::CATEGORY ),
			'postTypes'  => array( DummyPost::POST_TYPE ),
		);

		return array(
			'hero' => array_merge(
				$shared,
				array(
					'title'       => __( '主視覺 Hero', 'now-crm' ),
					'description' => __( '置中標題、說明與主要按鈕，適合活動或新品開場。', 'now-crm' ),
					'content'     => self::hero(),
				)
			),
			'product-grid' => array_merge(
				$shared,
				array(
					'title'       => __( '三欄商品網格', 'now-crm' ),
					'description' => __( '三張商品／優惠卡，含圖片預留區、價格與按鈕；手機自動堆疊。', 'now-crm' ),
					'content'     => self::product_grid(),
				)
			),
			'cta' => array_merge(
				$shared,
				array(
					'title'       => __( '行動呼籲 CTA', 'now-crm' ),
					'description' => __( '深色背景的收尾區塊，適合放單一明確動作。', 'now-crm' ),
					'content'     => self::cta(),
				)
			),
		);
	}

	/**
	 * Replace the web-oriented theme presets with fixed-pixel email tokens.
	 *
	 * @param array<string, mixed> $settings Block editor settings.
	 * @param mixed                $context  Block editor context.
	 * @return array<string, mixed>
	 */
	public function editor_settings( array $settings, $context ): array {
		$post = is_object( $context ) && isset( $context->post ) ? $context->post : null;

		if ( ! $post || DummyPost::POST_TYPE !== $post->post_type ) {
			return $settings;
		}

		$settings['fontSizes']    = DesignTokens::font_sizes();
		$settings['fontFamilies'] = DesignTokens::font_families();
		$settings['spacingSizes'] = DesignTokens::spacing_sizes();

		$features = (array) ( $settings['__experimentalFeatures'] ?? array() );

		$typography = (array) ( $features['typography'] ?? array() );
		$font_sizes = (array) ( $typography['fontSizes'] ?? array() );
		$font_sizes['theme'] = DesignTokens::font_sizes();
		$typography['fontSizes'] = $font_sizes;

		$font_families = (array) ( $typography['fontFamilies'] ?? array() );
		$font_families['theme'] = DesignTokens::font_families();
		$typography['fontFamilies'] = $font_families;
		$features['typography'] = $typography;

		$spacing = (array) ( $features['spacing'] ?? array() );
		$spacing_sizes = (array) ( $spacing['spacingSizes'] ?? array() );
		$spacing_sizes['theme'] = DesignTokens::spacing_sizes();
		$spacing['spacingSizes'] = $spacing_sizes;
		$features['spacing'] = $spacing;

		$settings['__experimentalFeatures'] = $features;

		return $settings;
	}

	private static function hero(): string {
		return <<<'HTML'
<!-- wp:group {"style":{"color":{"background":"#eef3ec"},"spacing":{"padding":{"top":"var:preset|spacing|60","right":"var:preset|spacing|50","bottom":"var:preset|spacing|60","left":"var:preset|spacing|50"}}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group has-background" style="background-color:#eef3ec;padding-top:var:preset|spacing|60;padding-right:var:preset|spacing|50;padding-bottom:var:preset|spacing|60;padding-left:var:preset|spacing|50">
<!-- wp:paragraph {"align":"center","style":{"typography":{"fontSize":"var:preset|font-size|small","fontFamily":"var:preset|font-family|sans","letterSpacing":"0.12em","textTransform":"uppercase"},"color":{"text":"#5d6b61"}}} -->
<p class="has-text-align-center has-text-color" style="color:#5d6b61;font-size:var:preset|font-size|small;font-family:var:preset|font-family|sans;letter-spacing:0.12em;text-transform:uppercase">NEW COLLECTION</p>
<!-- /wp:paragraph -->
<!-- wp:heading {"textAlign":"center","level":2,"style":{"typography":{"fontSize":"var:preset|font-size|display","fontFamily":"var:preset|font-family|sans","fontWeight":"700","lineHeight":"1.15"},"color":{"text":"#1f2a24"},"spacing":{"margin":{"top":"var:preset|spacing|20","bottom":"var:preset|spacing|20"}}}} -->
<h2 class="wp-block-heading has-text-align-center has-text-color" style="color:#1f2a24;font-size:var:preset|font-size|display;font-family:var:preset|font-family|sans;font-weight:700;line-height:1.15;margin-top:var:preset|spacing|20;margin-bottom:var:preset|spacing|20">讓好東西，<br>被看見。</h2>
<!-- /wp:heading -->
<!-- wp:paragraph {"align":"center","style":{"typography":{"fontSize":"var:preset|font-size|medium","fontFamily":"var:preset|font-family|sans","lineHeight":"1.6"},"color":{"text":"#4b5a50"},"spacing":{"margin":{"bottom":"var:preset|spacing|40"}}}} -->
<p class="has-text-align-center has-text-color" style="color:#4b5a50;font-size:var:preset|font-size|medium;font-family:var:preset|font-family|sans;line-height:1.6;margin-bottom:var:preset|spacing|40">用一段話說明這次活動或商品的重點，讓收件人一眼知道為什麼值得點開。</p>
<!-- /wp:paragraph -->
<!-- wp:buttons {"layout":{"type":"flex","justifyContent":"center"}} -->
<div class="wp-block-buttons"><!-- wp:button {"style":{"color":{"background":"#243e31","text":"#ffffff"},"border":{"radius":"4px"},"spacing":{"padding":{"top":"var:preset|spacing|20","right":"var:preset|spacing|40","bottom":"var:preset|spacing|20","left":"var:preset|spacing|40"}},"typography":{"fontSize":"var:preset|font-size|medium","fontFamily":"var:preset|font-family|sans"}}} -->
<div class="wp-block-button"><a class="wp-block-button__link has-text-color has-background" href="#" style="background-color:#243e31;color:#ffffff;border-radius:4px;padding-top:var:preset|spacing|20;padding-right:var:preset|spacing|40;padding-bottom:var:preset|spacing|20;padding-left:var:preset|spacing|40;font-size:var:preset|font-size|medium;font-family:var:preset|font-family|sans">立即選購</a></div>
<!-- /wp:button --></div>
<!-- /wp:buttons -->
</div>
<!-- /wp:group -->
HTML;
	}

	private static function product_grid(): string {
		return <<<'HTML'
<!-- wp:group {"style":{"spacing":{"padding":{"top":"var:preset|spacing|60","right":"var:preset|spacing|40","bottom":"var:preset|spacing|60","left":"var:preset|spacing|40"}}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group" style="padding-top:var:preset|spacing|60;padding-right:var:preset|spacing|40;padding-bottom:var:preset|spacing|60;padding-left:var:preset|spacing|40">
<!-- wp:heading {"textAlign":"center","level":2,"style":{"typography":{"fontSize":"var:preset|font-size|x-large","fontFamily":"var:preset|font-family|sans","fontWeight":"700","lineHeight":"1.3"},"color":{"text":"#1f2a24"},"spacing":{"margin":{"bottom":"var:preset|spacing|20"}}}} -->
<h2 class="wp-block-heading has-text-align-center has-text-color" style="color:#1f2a24;font-size:var:preset|font-size|x-large;font-family:var:preset|font-family|sans;font-weight:700;line-height:1.3;margin-bottom:var:preset|spacing|20">本週精選</h2>
<!-- /wp:heading -->
<!-- wp:paragraph {"align":"center","style":{"typography":{"fontSize":"var:preset|font-size|medium","fontFamily":"var:preset|font-family|sans","lineHeight":"1.6"},"color":{"text":"#5d6b61"},"spacing":{"margin":{"bottom":"var:preset|spacing|40"}}}} -->
<p class="has-text-align-center has-text-color" style="color:#5d6b61;font-size:var:preset|font-size|medium;font-family:var:preset|font-family|sans;line-height:1.6;margin-bottom:var:preset|spacing|40">三欄商品卡，手機自動堆疊；把圖片預留區換成商品圖即可。</p>
<!-- /wp:paragraph -->
<!-- wp:columns -->
<div class="wp-block-columns"><!-- wp:column {"width":"33.33%"} -->
<div class="wp-block-column" style="flex-basis:33.33%"><!-- wp:group {"style":{"color":{"background":"#ffffff"},"border":{"width":"1px","color":"#dfe5dd","radius":"6px"},"spacing":{"padding":{"top":"var:preset|spacing|30","right":"var:preset|spacing|30","bottom":"var:preset|spacing|30","left":"var:preset|spacing|30"}}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group has-background has-border-color" style="background-color:#ffffff;border:1px solid #dfe5dd;border-radius:6px;padding-top:var:preset|spacing|30;padding-right:var:preset|spacing|30;padding-bottom:var:preset|spacing|30;padding-left:var:preset|spacing|30">
<!-- wp:group {"style":{"color":{"background":"#f0f2ef"},"spacing":{"padding":{"top":"var:preset|spacing|40","bottom":"var:preset|spacing|40"}}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group has-background" style="background-color:#f0f2ef;padding-top:var:preset|spacing|40;padding-bottom:var:preset|spacing|40"><!-- wp:paragraph {"align":"center","style":{"typography":{"fontSize":"var:preset|font-size|small","fontFamily":"var:preset|font-family|sans"},"color":{"text":"#8a958d"}}} -->
<p class="has-text-align-center has-text-color" style="color:#8a958d;font-size:var:preset|font-size|small;font-family:var:preset|font-family|sans">商品圖片</p>
<!-- /wp:paragraph --></div>
<!-- /wp:group -->
<!-- wp:heading {"textAlign":"center","level":3,"style":{"typography":{"fontSize":"var:preset|font-size|large","fontFamily":"var:preset|font-family|sans","fontWeight":"700","lineHeight":"1.35"},"color":{"text":"#1f2a24"},"spacing":{"margin":{"top":"var:preset|spacing|20","bottom":"var:preset|spacing|10"}}}} -->
<h3 class="wp-block-heading has-text-align-center has-text-color" style="color:#1f2a24;font-size:var:preset|font-size|large;font-family:var:preset|font-family|sans;font-weight:700;line-height:1.35;margin-top:var:preset|spacing|20;margin-bottom:var:preset|spacing|10">商品名稱</h3>
<!-- /wp:heading -->
<!-- wp:paragraph {"align":"center","style":{"typography":{"fontSize":"var:preset|font-size|small","fontFamily":"var:preset|font-family|sans","lineHeight":"1.6"},"color":{"text":"#5d6b61"}}} -->
<p class="has-text-align-center has-text-color" style="color:#5d6b61;font-size:var:preset|font-size|small;font-family:var:preset|font-family|sans;line-height:1.6">一句話說明材質、尺寸或特色。</p>
<!-- /wp:paragraph -->
<!-- wp:paragraph {"align":"center","style":{"typography":{"fontSize":"var:preset|font-size|medium","fontFamily":"var:preset|font-family|sans","fontWeight":"700"},"color":{"text":"#243e31"},"spacing":{"margin":{"top":"var:preset|spacing|20","bottom":"var:preset|spacing|20"}}}} -->
<p class="has-text-align-center has-text-color" style="color:#243e31;font-size:var:preset|font-size|medium;font-family:var:preset|font-family|sans;font-weight:700;margin-top:var:preset|spacing|20;margin-bottom:var:preset|spacing|20">NT$ 1,280</p>
<!-- /wp:paragraph -->
<!-- wp:buttons {"layout":{"type":"flex","justifyContent":"center"}} -->
<div class="wp-block-buttons"><!-- wp:button {"style":{"color":{"background":"#243e31","text":"#ffffff"},"border":{"radius":"4px"},"spacing":{"padding":{"top":"var:preset|spacing|10","right":"var:preset|spacing|20","bottom":"var:preset|spacing|10","left":"var:preset|spacing|20"}},"typography":{"fontSize":"var:preset|font-size|small","fontFamily":"var:preset|font-family|sans"}}} -->
<div class="wp-block-button"><a class="wp-block-button__link has-text-color has-background" href="#" style="background-color:#243e31;color:#ffffff;border-radius:4px;padding-top:var:preset|spacing|10;padding-right:var:preset|spacing|20;padding-bottom:var:preset|spacing|10;padding-left:var:preset|spacing|20;font-size:var:preset|font-size|small;font-family:var:preset|font-family|sans">查看商品</a></div>
<!-- /wp:button --></div>
<!-- /wp:buttons -->
</div>
<!-- /wp:group --></div>
<!-- /wp:column -->
<!-- wp:column {"width":"33.33%"} -->
<div class="wp-block-column" style="flex-basis:33.33%"><!-- wp:group {"style":{"color":{"background":"#ffffff"},"border":{"width":"1px","color":"#dfe5dd","radius":"6px"},"spacing":{"padding":{"top":"var:preset|spacing|30","right":"var:preset|spacing|30","bottom":"var:preset|spacing|30","left":"var:preset|spacing|30"}}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group has-background has-border-color" style="background-color:#ffffff;border:1px solid #dfe5dd;border-radius:6px;padding-top:var:preset|spacing|30;padding-right:var:preset|spacing|30;padding-bottom:var:preset|spacing|30;padding-left:var:preset|spacing|30">
<!-- wp:group {"style":{"color":{"background":"#f0f2ef"},"spacing":{"padding":{"top":"var:preset|spacing|40","bottom":"var:preset|spacing|40"}}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group has-background" style="background-color:#f0f2ef;padding-top:var:preset|spacing|40;padding-bottom:var:preset|spacing|40"><!-- wp:paragraph {"align":"center","style":{"typography":{"fontSize":"var:preset|font-size|small","fontFamily":"var:preset|font-family|sans"},"color":{"text":"#8a958d"}}} -->
<p class="has-text-align-center has-text-color" style="color:#8a958d;font-size:var:preset|font-size|small;font-family:var:preset|font-family|sans">商品圖片</p>
<!-- /wp:paragraph --></div>
<!-- /wp:group -->
<!-- wp:heading {"textAlign":"center","level":3,"style":{"typography":{"fontSize":"var:preset|font-size|large","fontFamily":"var:preset|font-family|sans","fontWeight":"700","lineHeight":"1.35"},"color":{"text":"#1f2a24"},"spacing":{"margin":{"top":"var:preset|spacing|20","bottom":"var:preset|spacing|10"}}}} -->
<h3 class="wp-block-heading has-text-align-center has-text-color" style="color:#1f2a24;font-size:var:preset|font-size|large;font-family:var:preset|font-family|sans;font-weight:700;line-height:1.35;margin-top:var:preset|spacing|20;margin-bottom:var:preset|spacing|10">商品名稱</h3>
<!-- /wp:heading -->
<!-- wp:paragraph {"align":"center","style":{"typography":{"fontSize":"var:preset|font-size|small","fontFamily":"var:preset|font-family|sans","lineHeight":"1.6"},"color":{"text":"#5d6b61"}}} -->
<p class="has-text-align-center has-text-color" style="color:#5d6b61;font-size:var:preset|font-size|small;font-family:var:preset|font-family|sans;line-height:1.6">一句話說明材質、尺寸或特色。</p>
<!-- /wp:paragraph -->
<!-- wp:paragraph {"align":"center","style":{"typography":{"fontSize":"var:preset|font-size|medium","fontFamily":"var:preset|font-family|sans","fontWeight":"700"},"color":{"text":"#243e31"},"spacing":{"margin":{"top":"var:preset|spacing|20","bottom":"var:preset|spacing|20"}}}} -->
<p class="has-text-align-center has-text-color" style="color:#243e31;font-size:var:preset|font-size|medium;font-family:var:preset|font-family|sans;font-weight:700;margin-top:var:preset|spacing|20;margin-bottom:var:preset|spacing|20">NT$ 1,280</p>
<!-- /wp:paragraph -->
<!-- wp:buttons {"layout":{"type":"flex","justifyContent":"center"}} -->
<div class="wp-block-buttons"><!-- wp:button {"style":{"color":{"background":"#243e31","text":"#ffffff"},"border":{"radius":"4px"},"spacing":{"padding":{"top":"var:preset|spacing|10","right":"var:preset|spacing|20","bottom":"var:preset|spacing|10","left":"var:preset|spacing|20"}},"typography":{"fontSize":"var:preset|font-size|small","fontFamily":"var:preset|font-family|sans"}}} -->
<div class="wp-block-button"><a class="wp-block-button__link has-text-color has-background" href="#" style="background-color:#243e31;color:#ffffff;border-radius:4px;padding-top:var:preset|spacing|10;padding-right:var:preset|spacing|20;padding-bottom:var:preset|spacing|10;padding-left:var:preset|spacing|20;font-size:var:preset|font-size|small;font-family:var:preset|font-family|sans">查看商品</a></div>
<!-- /wp:button --></div>
<!-- /wp:buttons -->
</div>
<!-- /wp:group --></div>
<!-- /wp:column -->
<!-- wp:column {"width":"33.34%"} -->
<div class="wp-block-column" style="flex-basis:33.34%"><!-- wp:group {"style":{"color":{"background":"#ffffff"},"border":{"width":"1px","color":"#dfe5dd","radius":"6px"},"spacing":{"padding":{"top":"var:preset|spacing|30","right":"var:preset|spacing|30","bottom":"var:preset|spacing|30","left":"var:preset|spacing|30"}}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group has-background has-border-color" style="background-color:#ffffff;border:1px solid #dfe5dd;border-radius:6px;padding-top:var:preset|spacing|30;padding-right:var:preset|spacing|30;padding-bottom:var:preset|spacing|30;padding-left:var:preset|spacing|30">
<!-- wp:group {"style":{"color":{"background":"#f0f2ef"},"spacing":{"padding":{"top":"var:preset|spacing|40","bottom":"var:preset|spacing|40"}}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group has-background" style="background-color:#f0f2ef;padding-top:var:preset|spacing|40;padding-bottom:var:preset|spacing|40"><!-- wp:paragraph {"align":"center","style":{"typography":{"fontSize":"var:preset|font-size|small","fontFamily":"var:preset|font-family|sans"},"color":{"text":"#8a958d"}}} -->
<p class="has-text-align-center has-text-color" style="color:#8a958d;font-size:var:preset|font-size|small;font-family:var:preset|font-family|sans">商品圖片</p>
<!-- /wp:paragraph --></div>
<!-- /wp:group -->
<!-- wp:heading {"textAlign":"center","level":3,"style":{"typography":{"fontSize":"var:preset|font-size|large","fontFamily":"var:preset|font-family|sans","fontWeight":"700","lineHeight":"1.35"},"color":{"text":"#1f2a24"},"spacing":{"margin":{"top":"var:preset|spacing|20","bottom":"var:preset|spacing|10"}}}} -->
<h3 class="wp-block-heading has-text-align-center has-text-color" style="color:#1f2a24;font-size:var:preset|font-size|large;font-family:var:preset|font-family|sans;font-weight:700;line-height:1.35;margin-top:var:preset|spacing|20;margin-bottom:var:preset|spacing|10">商品名稱</h3>
<!-- /wp:heading -->
<!-- wp:paragraph {"align":"center","style":{"typography":{"fontSize":"var:preset|font-size|small","fontFamily":"var:preset|font-family|sans","lineHeight":"1.6"},"color":{"text":"#5d6b61"}}} -->
<p class="has-text-align-center has-text-color" style="color:#5d6b61;font-size:var:preset|font-size|small;font-family:var:preset|font-family|sans;line-height:1.6">一句話說明材質、尺寸或特色。</p>
<!-- /wp:paragraph -->
<!-- wp:paragraph {"align":"center","style":{"typography":{"fontSize":"var:preset|font-size|medium","fontFamily":"var:preset|font-family|sans","fontWeight":"700"},"color":{"text":"#243e31"},"spacing":{"margin":{"top":"var:preset|spacing|20","bottom":"var:preset|spacing|20"}}}} -->
<p class="has-text-align-center has-text-color" style="color:#243e31;font-size:var:preset|font-size|medium;font-family:var:preset|font-family|sans;font-weight:700;margin-top:var:preset|spacing|20;margin-bottom:var:preset|spacing|20">NT$ 1,280</p>
<!-- /wp:paragraph -->
<!-- wp:buttons {"layout":{"type":"flex","justifyContent":"center"}} -->
<div class="wp-block-buttons"><!-- wp:button {"style":{"color":{"background":"#243e31","text":"#ffffff"},"border":{"radius":"4px"},"spacing":{"padding":{"top":"var:preset|spacing|10","right":"var:preset|spacing|20","bottom":"var:preset|spacing|10","left":"var:preset|spacing|20"}},"typography":{"fontSize":"var:preset|font-size|small","fontFamily":"var:preset|font-family|sans"}}} -->
<div class="wp-block-button"><a class="wp-block-button__link has-text-color has-background" href="#" style="background-color:#243e31;color:#ffffff;border-radius:4px;padding-top:var:preset|spacing|10;padding-right:var:preset|spacing|20;padding-bottom:var:preset|spacing|10;padding-left:var:preset|spacing|20;font-size:var:preset|font-size|small;font-family:var:preset|font-family|sans">查看商品</a></div>
<!-- /wp:button --></div>
<!-- /wp:buttons -->
</div>
<!-- /wp:group --></div>
<!-- /wp:column --></div>
<!-- /wp:columns -->
</div>
<!-- /wp:group -->
HTML;
	}

	private static function cta(): string {
		return <<<'HTML'
<!-- wp:group {"style":{"color":{"background":"#243e31"},"spacing":{"padding":{"top":"var:preset|spacing|60","right":"var:preset|spacing|50","bottom":"var:preset|spacing|60","left":"var:preset|spacing|50"}}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group has-background" style="background-color:#243e31;padding-top:var:preset|spacing|60;padding-right:var:preset|spacing|50;padding-bottom:var:preset|spacing|60;padding-left:var:preset|spacing|50">
<!-- wp:heading {"textAlign":"center","level":2,"style":{"typography":{"fontSize":"var:preset|font-size|x-large","fontFamily":"var:preset|font-family|sans","fontWeight":"700","lineHeight":"1.3"},"color":{"text":"#ffffff"}}} -->
<h2 class="wp-block-heading has-text-align-center has-text-color" style="color:#ffffff;font-size:var:preset|font-size|x-large;font-family:var:preset|font-family|sans;font-weight:700;line-height:1.3">準備好開始了嗎？</h2>
<!-- /wp:heading -->
<!-- wp:paragraph {"align":"center","style":{"typography":{"fontSize":"var:preset|font-size|medium","fontFamily":"var:preset|font-family|sans","lineHeight":"1.6"},"color":{"text":"#dbe5dc"},"spacing":{"margin":{"top":"var:preset|spacing|20","bottom":"var:preset|spacing|30"}}}} -->
<p class="has-text-align-center has-text-color" style="color:#dbe5dc;font-size:var:preset|font-size|medium;font-family:var:preset|font-family|sans;line-height:1.6;margin-top:var:preset|spacing|20;margin-bottom:var:preset|spacing|30">用一句話收尾，並給收件人一個明確的下一步。</p>
<!-- /wp:paragraph -->
<!-- wp:buttons {"layout":{"type":"flex","justifyContent":"center"}} -->
<div class="wp-block-buttons"><!-- wp:button {"style":{"color":{"background":"#ffffff","text":"#243e31"},"border":{"radius":"4px"},"spacing":{"padding":{"top":"var:preset|spacing|20","right":"var:preset|spacing|40","bottom":"var:preset|spacing|20","left":"var:preset|spacing|40"}},"typography":{"fontSize":"var:preset|font-size|medium","fontFamily":"var:preset|font-family|sans"}}} -->
<div class="wp-block-button"><a class="wp-block-button__link has-text-color has-background" href="#" style="background-color:#ffffff;color:#243e31;border-radius:4px;padding-top:var:preset|spacing|20;padding-right:var:preset|spacing|40;padding-bottom:var:preset|spacing|20;padding-left:var:preset|spacing|40;font-size:var:preset|font-size|medium;font-family:var:preset|font-family|sans">查看全部</a></div>
<!-- /wp:button --></div>
<!-- /wp:buttons -->
</div>
<!-- /wp:group -->
HTML;
	}
}
