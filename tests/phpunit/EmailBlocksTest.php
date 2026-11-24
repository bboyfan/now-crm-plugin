<?php
/**
 * Email-safe rendering of Gutenberg blocks.
 *
 * @package NowCRM
 */

use NowCRM\Editor\Patterns;
use NowCRM\Email\EmailRenderer;

final class EmailBlocksTest extends NowCrmTestCase {

	private function render_blocks( string $blocks ): string {
		return ( new EmailRenderer() )->render_blocks( $blocks );
	}

	public function test_columns_are_rendered_as_a_table_not_flexbox(): void {
		$blocks = '<!-- wp:columns --><div class="wp-block-columns">'
			. '<!-- wp:column {"width":"33.33%"} --><div class="wp-block-column"><!-- wp:paragraph --><p>One</p><!-- /wp:paragraph --></div><!-- /wp:column -->'
			. '<!-- wp:column {"width":"33.33%"} --><div class="wp-block-column"><!-- wp:paragraph --><p>Two</p><!-- /wp:paragraph --></div><!-- /wp:column -->'
			. '<!-- wp:column {"width":"33.34%"} --><div class="wp-block-column"><!-- wp:paragraph --><p>Three</p><!-- /wp:paragraph --></div><!-- /wp:column -->'
			. '</div><!-- /wp:columns -->';

		$html = $this->render_blocks( $blocks );

		$this->assertStringNotContainsString( 'wp-block-columns', $html );
		$this->assertStringNotContainsString( 'display:flex', $html );
		$this->assertStringContainsString( 'nowcrm-columns--stack', $html, 'Mobile stacking class is present.' );
		$this->assertSame( 3, substr_count( $html, 'class="nowcrm-column"' ) );
		$this->assertSame( 3, substr_count( $html, '<td ' ) );
		$this->assertStringContainsString( 'width="33.33%"', $html );
	}

	public function test_group_background_and_padding_are_inline(): void {
		$blocks = '<!-- wp:group {"style":{"color":{"background":"#edf5f0"},"spacing":{"padding":{"top":"24px","right":"24px","bottom":"22px","left":"24px"}}}} -->'
			. '<div class="wp-block-group"><!-- wp:paragraph --><p>Notes</p><!-- /wp:paragraph --></div><!-- /wp:group -->';

		$html = $this->render_blocks( $blocks );

		$this->assertStringContainsString( 'background-color:#edf5f0', $html );
		$this->assertStringContainsString( 'padding:24px 24px 22px 24px', $html );
		$this->assertStringContainsString( 'Notes', $html );
	}

	public function test_buttons_are_rendered_as_anchors_with_inline_colours(): void {
		$blocks = '<!-- wp:buttons {"layout":{"justifyContent":"center"}} --><div class="wp-block-buttons">'
			. '<!-- wp:button {"text":"Buy","url":"https://example.test/shop","style":{"color":{"background":"#243e31","text":"#ffffff"},"border":{"radius":"4px"}}} -->'
			. '<div class="wp-block-button"><a class="wp-block-button__link" href="https://example.test/shop">Buy</a></div>'
			. '<!-- /wp:button --></div><!-- /wp:buttons -->';

		$html = $this->render_blocks( $blocks );

		$this->assertStringContainsString( 'href="https://example.test/shop"', $html );
		$this->assertStringContainsString( 'background-color:#243e31', $html );
		$this->assertStringContainsString( 'color:#ffffff', $html );
		$this->assertStringContainsString( 'border-radius:4px', $html );
		$this->assertStringNotContainsString( 'wp-block-button__link', $html );
	}

	public function test_wrapper_has_mobile_stacking_but_no_generic_cell_borders(): void {
		$blocks = '<!-- wp:columns --><div class="wp-block-columns"><!-- wp:column --><div class="wp-block-column"><!-- wp:paragraph --><p>A</p><!-- /wp:paragraph --></div><!-- /wp:column --></div><!-- /wp:columns -->';

		$html = ( new EmailRenderer() )->render(
			array(
				'mode'     => 'blocks',
				'content'  => $blocks,
				'subject'  => 'Test',
				'footer_text' => 'Footer',
				'design'   => array( 'body' => '', 'content' => '', 'heading' => '', 'text' => '', 'link' => '' ),
			),
			array( 'email' => 'test@example.test', 'unsubscribe_url' => 'https://example.test/u' )
		)['html'];

		$this->assertStringContainsString( '@media only screen and (max-width:600px)', $html );
		$this->assertStringContainsString( '.nowcrm-columns--stack', $html );
		$this->assertStringNotContainsString( '.nowcrm-body td{border', $html );
	}

	public function test_paragraph_typography_and_colour_are_inlined(): void {
		$blocks = '<!-- wp:paragraph {"style":{"typography":{"fontSize":"18px","lineHeight":"1.7"},"color":{"text":"#123456"}}} -->'
			. '<p>Styled</p><!-- /wp:paragraph -->';

		$html = $this->render_blocks( $blocks );

		$this->assertStringContainsString( 'font-size:18px', $html );
		$this->assertStringContainsString( 'line-height:1.7', $html );
		$this->assertStringContainsString( 'color:#123456', $html );
	}

	public function test_font_family_and_preset_sizes_resolve_to_email_tokens(): void {
		$blocks = '<!-- wp:paragraph {"style":{"typography":{"fontSize":"var:preset|font-size|x-large","fontFamily":"var:preset|font-family|sans"},"spacing":{"margin":{"top":"var:preset|spacing|40"}}}} -->'
			. '<p style="font-size:var:preset|font-size|x-large">Token</p><!-- /wp:paragraph -->';

		$html = $this->render_blocks( $blocks );

		$this->assertStringContainsString( 'font-size:26px', $html );
		$this->assertStringContainsString( 'font-family:', $html );
		$this->assertStringContainsString( 'PingFang TC', $html );
		$this->assertStringContainsString( 'margin:40px 0 0 0', $html );
		$this->assertStringNotContainsString( 'var:', $html );
	}

	public function test_section_patterns_are_registered_for_the_editor_only(): void {
		$registry = WP_Block_Patterns_Registry::get_instance();

		foreach ( array( 'nowcrm/hero', 'nowcrm/product-grid', 'nowcrm/cta' ) as $name ) {
			$pattern = $registry->get_registered( $name );
			$this->assertIsArray( $pattern, $name . ' is registered.' );
			$this->assertSame( array( 'nowcrm_editor' ), $pattern['postTypes'] );
			$this->assertSame( array( 'nowcrm-email' ), $pattern['categories'] );
		}
	}

	public function test_section_patterns_render_as_email_tables(): void {
		$pattern = WP_Block_Patterns_Registry::get_instance()->get_registered( 'nowcrm/product-grid' );
		$this->assertIsArray( $pattern );

		$html = $this->render_blocks( (string) $pattern['content'] );

		$this->assertStringContainsString( '<table', $html );
		$this->assertStringContainsString( 'nowcrm-columns--stack', $html );
		$this->assertStringNotContainsString( 'wp-block-columns', $html );
		$this->assertStringNotContainsString( 'display:flex', $html );
		$this->assertStringNotContainsString( 'var:', $html );
		$this->assertStringNotContainsString( 'clamp(', $html );
		$this->assertStringNotContainsString( 'rem;', $html );
	}

	public function test_editor_settings_use_pixel_tokens_only_for_editor_posts(): void {
		$patterns = new Patterns();
		$settings = $patterns->editor_settings(
			array( '__experimentalFeatures' => array() ),
			(object) array( 'post' => (object) array( 'post_type' => 'nowcrm_editor' ) )
		);

		$this->assertSame( '14px', $settings['fontSizes'][0]['size'] );
		$this->assertSame( '40px', $settings['spacingSizes'][3]['size'] );
		$this->assertSame( 'sans', $settings['fontFamilies'][0]['slug'] );

		$unchanged = $patterns->editor_settings(
			array( 'fontSizes' => array( 'untouched' ) ),
			(object) array( 'post' => (object) array( 'post_type' => 'post' ) )
		);
		$this->assertSame( array( 'untouched' ), $unchanged['fontSizes'] );
	}
}
