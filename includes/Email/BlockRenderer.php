<?php
/**
 * Render Gutenberg blocks as email-safe table HTML.
 *
 * Core block output is designed for the web: `core/columns` uses flexbox,
 * buttons use flex layout and block spacing often lives in theme classes. Mail
 * clients do not load those styles. This renderer keeps the editor on native
 * blocks while producing the table structure email clients actually support.
 *
 * @package NowCRM
 */

namespace NowCRM\Email;

defined( 'ABSPATH' ) || exit;

final class BlockRenderer {

	/**
	 * Render one serialized block document.
	 */
	public function render( string $content ): string {
		return $this->strip_unresolved_css( $this->render_blocks( parse_blocks( $content ) ) );
	}

	/**
	 * Gutenberg saves preset values as CSS variables. Core renders them for the
	 * web; mail clients do not resolve them. Resolved declarations are added by
	 * the block renderer, so remove any remaining variable declarations.
	 */
	private function strip_unresolved_css( string $html ): string {
		return (string) preg_replace_callback(
			'#\sstyle=(["\'])(.*?)\1#is',
			static function ( array $match ): string {
				$keep = array();

				foreach ( explode( ';', $match[2] ) as $declaration ) {
					$declaration = trim( $declaration );

					if ( '' === $declaration || preg_match( '#var\s*[:(]#i', $declaration ) ) {
						continue;
					}

					$keep[] = $declaration;
				}

				return array() === $keep ? '' : ' style=' . $match[1] . implode( ';', $keep ) . $match[1];
			},
			$html
		);
	}

	/**
	 * @param array<int, array<string, mixed>> $blocks Parsed blocks.
	 */
	private function render_blocks( array $blocks ): string {
		$html = '';

		foreach ( $blocks as $block ) {
			if ( empty( $block['blockName'] ) ) {
				$html .= (string) ( $block['innerHTML'] ?? '' );
				continue;
			}

			$html .= $this->render_block( $block );
		}

		return $html;
	}

	/**
	 * @param array<string, mixed> $block Parsed block.
	 */
	private function render_block( array $block ): string {
		$name = (string) $block['blockName'];

		return match ( $name ) {
			'core/columns'   => $this->columns( $block ),
			'core/column'    => $this->render_blocks( (array) ( $block['innerBlocks'] ?? array() ) ),
			'core/group'     => $this->group( $block ),
			'core/buttons'   => $this->buttons( $block ),
			'core/button'    => $this->button( $block ),
			'core/spacer'    => $this->spacer( $block ),
			'core/separator' => $this->separator( $block ),
			'core/paragraph',
			'core/heading',
			'core/image',
			'core/list',
			'core/quote',
			'core/table'     => $this->leaf( $block ),
			default          => $this->fallback( $block ),
		};
	}

	/**
	 * Layout blocks that need a table wrapper.
	 *
	 * @param array<string, mixed> $block Parsed block.
	 */
	private function columns( array $block ): string {
		$columns = array_values(
			array_filter(
				(array) ( $block['innerBlocks'] ?? array() ),
				static fn( array $inner ): bool => 'core/column' === ( $inner['blockName'] ?? '' )
			)
		);

		if ( array() === $columns ) {
			return $this->render_blocks( (array) ( $block['innerBlocks'] ?? array() ) );
		}

		$stack = (bool) ( $block['attrs']['isStackedOnMobile'] ?? true );
		$class = 'nowcrm-columns' . ( $stack ? ' nowcrm-columns--stack' : '' );
		$count = count( $columns );
		$html  = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" class="' . esc_attr( $class ) . '" style="width:100%;border-collapse:collapse;"><tr>';

		foreach ( $columns as $column ) {
			$attrs  = (array) ( $column['attrs'] ?? array() );
			$width  = $this->column_width( (string) ( $attrs['width'] ?? '' ), $count );
			$align  = $this->vertical_align( (string) ( $attrs['verticalAlignment'] ?? 'top' ) );
			$style  = 'width:' . $width . ';vertical-align:' . $align . ';' . $this->box_style( $attrs, 'padding' ) . $this->color_style( $attrs, 'background' );

			$html .= '<td class="nowcrm-column" width="' . esc_attr( $width ) . '" valign="' . esc_attr( $align ) . '" style="' . esc_attr( $style ) . '">';
			$html .= $this->render_blocks( (array) ( $column['innerBlocks'] ?? array() ) );
			$html .= '</td>';
		}

		return $html . '</tr></table>';
	}

	/**
	 * Group blocks become one table cell so backgrounds and padding survive.
	 *
	 * @param array<string, mixed> $block Parsed block.
	 */
	private function group( array $block ): string {
		$attrs  = (array) ( $block['attrs'] ?? array() );
		$layout = strtolower( (string) ( $attrs['layout']['type'] ?? '' ) );

		if ( 'flex' === $layout ) {
			return $this->flex_group( $block );
		}

		$content = $this->render_blocks( (array) ( $block['innerBlocks'] ?? array() ) );
		if ( '' === trim( $content ) ) {
			$content = (string) ( $block['innerHTML'] ?? '' );
		}

		$style = $this->color_style( $attrs, 'background' )
			. $this->box_style( $attrs, 'padding' )
			. $this->border_style( $attrs );

		if ( '' === $style ) {
			return $content;
		}

		return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;border-collapse:collapse;"><tr><td style="' . esc_attr( $style ) . '">' . $content . '</td></tr></table>';
	}

	/**
	 * Flex groups (core/group with row layout) need explicit table cells.
	 *
	 * @param array<string, mixed> $block Parsed block.
	 */
	private function flex_group( array $block ): string {
		$inner = (array) ( $block['innerBlocks'] ?? array() );

		if ( array() === $inner ) {
			return '';
		}

		$attrs = (array) ( $block['attrs'] ?? array() );
		$style = $this->color_style( $attrs, 'background' ) . $this->box_style( $attrs, 'padding' );
		$html  = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;border-collapse:collapse;"><tr>';

		foreach ( $inner as $child ) {
			$html .= '<td style="vertical-align:top;' . esc_attr( $style ) . '">' . $this->render_block( $child ) . '</td>';
		}

		return $html . '</tr></table>';
	}

	/**
	 * Button rows use a table plus one anchor per button.
	 *
	 * @param array<string, mixed> $block Parsed block.
	 */
	private function buttons( array $block ): string {
		$buttons = array();

		foreach ( (array) ( $block['innerBlocks'] ?? array() ) as $inner ) {
			if ( 'core/button' === ( $inner['blockName'] ?? '' ) ) {
				$buttons[] = $this->button( $inner );
			}
		}

		if ( array() === $buttons ) {
			return $this->render_blocks( (array) ( $block['innerBlocks'] ?? array() ) );
		}

		$justify = (string) ( $block['attrs']['layout']['justifyContent'] ?? 'left' );
		$align   = in_array( $justify, array( 'center', 'right' ), true ) ? $justify : 'left';

		return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;border-collapse:collapse;"><tr><td align="' . esc_attr( $align ) . '" style="padding:8px 0;">' . implode( ' ', $buttons ) . '</td></tr></table>';
	}

	/**
	 * @param array<string, mixed> $block Parsed block.
	 */
	private function button( array $block ): string {
		$attrs = (array) ( $block['attrs'] ?? array() );
		$url   = (string) ( $attrs['url'] ?? '' );
		$text  = (string) ( $attrs['text'] ?? '' );

		if ( '' === $text && preg_match( '#<a\b[^>]*>(.*?)</a>#is', (string) ( $block['innerHTML'] ?? '' ), $match ) ) {
			$text = trim( wp_strip_all_tags( $match[1] ) );
		}

		if ( '' === $url && preg_match( '#\shref="([^"]*)"#i', (string) ( $block['innerHTML'] ?? '' ), $match ) ) {
			$url = $match[1];
		}

		$background = $this->color_value( (string) ( $attrs['style']['color']['background'] ?? '' ) ) ?: '#111827';
		$color      = $this->color_value( (string) ( $attrs['style']['color']['text'] ?? '' ) ) ?: '#ffffff';
		$padding    = $this->box_style( $attrs, 'padding' );
		$padding    = '' !== $padding ? $padding : 'padding:12px 22px;';
		$radius     = $this->css_size( (string) ( $attrs['style']['border']['radius'] ?? '' ) );

		$cell_style = 'background-color:' . $background . ';' . $padding;
		if ( '' !== $radius ) {
			$cell_style .= 'border-radius:' . $radius . ';';
		}

		// Cell padding is reliable in Outlook; anchor padding is not.
		return '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="display:inline-block;margin:0 4px 8px;"><tr><td bgcolor="' . esc_attr( $background ) . '" style="' . esc_attr( $cell_style ) . '"><a href="' . esc_url( $url ) . '" style="display:inline-block;color:' . esc_attr( $color ) . ';text-decoration:none;font-weight:600;white-space:nowrap;">' . esc_html( $text ) . '</a></td></tr></table>';
	}

	/**
	 * Spacer blocks are a fixed-height table cell, not a flex div.
	 *
	 * @param array<string, mixed> $block Parsed block.
	 */
	private function spacer( array $block ): string {
		$height = $this->css_size( (string) ( $block['attrs']['height'] ?? '24px' ) ) ?: '24px';

		return '<div style="height:' . esc_attr( $height ) . ';line-height:' . esc_attr( $height ) . ';font-size:0;">&nbsp;</div>';
	}

	/**
	 * @param array<string, mixed> $block Parsed block.
	 */
	private function separator( array $block ): string {
		$attrs = (array) ( $block['attrs'] ?? array() );
		$color = $this->color_value( (string) ( $attrs['style']['color']['background'] ?? '' ) ) ?: '#e5e7eb';

		return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;border-collapse:collapse;"><tr><td style="border-top:1px solid ' . esc_attr( $color ) . ';font-size:0;line-height:0;">&nbsp;</td></tr></table>';
	}

	/**
	 * Text, media and table leaves keep their inline content but receive the
	 * block's own typography/colour/spacing as inline CSS.
	 *
	 * @param array<string, mixed> $block Parsed block.
	 */
	private function leaf( array $block ): string {
		$name = (string) $block['blockName'];
		$html = render_block( $block );
		$css  = $this->text_style( (array) ( $block['attrs'] ?? array() ) );

		if ( 'core/table' === $name ) {
			$html = $this->add_style( $html, 'margin:0;', 'figure' );
			$html = $this->add_style( $html, 'width:100%;border-collapse:collapse;', 'table' );
			$html = (string) preg_replace_callback(
				'#<(td|th)(\s[^>]*)?>#i',
				static function ( array $match ): string {
					$attrs = (string) ( $match[2] ?? '' );
					$style = 'border:1px solid #e5e7eb;padding:8px 10px;text-align:left;';

					if ( preg_match( '#\sstyle\s*=\s*(["\'])(.*?)\1#is', $attrs, $existing ) ) {
						$value = rtrim( $existing[2], ';' ) . ';' . $style;
						$attrs = str_replace( $existing[0], ' style=' . $existing[1] . $value . $existing[1], $attrs );
					} else {
						$attrs .= ' style="' . esc_attr( $style ) . '"';
					}

					return '<' . $match[1] . $attrs . '>';
				},
				$html
			);
			return $html;
		}

		if ( 'core/image' === $name ) {
			$width = $this->css_size( (string) ( $block['attrs']['width'] ?? '' ) );
			$html  = $this->add_style( $html, 'margin:0 0 16px;', 'figure' );
			$html  = $this->add_style( $html, 'max-width:100%;height:auto;display:block;' . ( '' !== $width ? 'width:' . $width . ';' : '' ), 'img' );
			return $html;
		}

		if ( '' !== $css ) {
			$html = $this->add_style( $html, $css );
		}

		return $html;
	}

	/**
	 * Unknown blocks keep their normal rendering; the outer sanitizer still
	 * removes active content.
	 *
	 * @param array<string, mixed> $block Parsed block.
	 */
	private function fallback( array $block ): string {
		return render_block( $block );
	}

	/**
	 * @param array<string, mixed> $attrs Block attributes.
	 */
	private function text_style( array $attrs ): string {
		$style = array();
		$text  = $this->color_value( (string) ( $attrs['style']['color']['text'] ?? '' ) );

		if ( '' !== $text ) {
			$style[] = 'color:' . $text;
		}

		$typography = (array) ( $attrs['style']['typography'] ?? array() );
		$map        = array(
			'fontSize'      => 'font-size',
			'lineHeight'    => 'line-height',
			'letterSpacing' => 'letter-spacing',
			'fontWeight'    => 'font-weight',
			'textTransform' => 'text-transform',
			'fontStyle'     => 'font-style',
		);

		foreach ( $map as $key => $property ) {
			if ( empty( $typography[ $key ] ) ) {
				continue;
			}

			$value = $this->css_size( (string) $typography[ $key ], in_array( $key, array( 'lineHeight', 'fontWeight' ), true ) );

			if ( '' !== $value ) {
				$style[] = $property . ':' . $value;
			}
		}

		$family = $this->font_family( (string) ( $typography['fontFamily'] ?? '' ) );
		if ( '' !== $family ) {
			$style[] = 'font-family:' . $family;
		}

		$style[] = $this->box_style( $attrs, 'padding' );
		$style[] = $this->box_style( $attrs, 'margin' );

		$align = (string) ( $attrs['align'] ?? '' );
		if ( in_array( $align, array( 'left', 'center', 'right' ), true ) ) {
			$style[] = 'text-align:' . $align;
		}

		return trim( implode( ';', array_filter( $style ) ), ';' );
	}

	/**
	 * @param array<string, mixed> $attrs Block attributes.
	 */
	private function box_style( array $attrs, string $property ): string {
		$box = $attrs['style']['spacing'][ $property ] ?? null;

		if ( ! is_array( $box ) ) {
			return '';
		}

		$values = array();

		foreach ( array( 'top', 'right', 'bottom', 'left' ) as $side ) {
			$value = $this->css_size( (string) ( $box[ $side ] ?? '' ) );
			$values[] = '' !== $value ? $value : '0';
		}

		if ( array( '0', '0', '0', '0' ) === $values ) {
			return '';
		}

		return $property . ':' . implode( ' ', $values ) . ';';
	}

	/**
	 * @param array<string, mixed> $attrs Block attributes.
	 */
	private function color_style( array $attrs, string $key ): string {
		$color = $this->color_value( (string) ( $attrs['style']['color'][ $key ] ?? '' ) );

		return '' !== $color ? ( 'background' === $key ? 'background-color:' : 'color:' ) . $color . ';' : '';
	}

	/**
	 * @param array<string, mixed> $attrs Block attributes.
	 */
	private function border_style( array $attrs ): string {
		$border = (array) ( $attrs['style']['border'] ?? array() );
		$width  = $this->css_size( (string) ( $border['width'] ?? '' ) );
		$color  = $this->color_value( (string) ( $border['color'] ?? '' ) );
		$radius = $this->css_size( (string) ( $border['radius'] ?? '' ) );
		$style  = '';

		if ( '' !== $width && '' !== $color ) {
			$style .= 'border:' . $width . ' solid ' . $color . ';';
		}

		if ( '' !== $radius ) {
			$style .= 'border-radius:' . $radius . ';';
		}

		return $style;
	}

	private function column_width( string $width, int $count ): string {
		$width = trim( $width );

		if ( '' === $width ) {
			return round( 100 / max( 1, $count ), 2 ) . '%';
		}

		if ( preg_match( '/^\d+(?:\.\d+)?$/', $width ) ) {
			return $width . '%';
		}

		return $this->css_size( $width );
	}

	private function vertical_align( string $value ): string {
		return in_array( $value, array( 'top', 'middle', 'bottom' ), true ) ? $value : 'top';
	}

	/**
	 * Keep literal CSS sizes and common numeric presets; skip unresolved theme
	 * vars and tokens that would be meaningless in an inbox.
	 */
	private function css_size( string $value, bool $unitless = false ): string {
		$value = trim( $value );

		if ( '' === $value ) {
			return '';
		}

		if ( str_starts_with( $value, 'var:' ) ) {
			$parts  = explode( '|', $value );
			$preset = (string) ( $parts[1] ?? '' );
			$slug   = (string) end( $parts );

			if ( 'font-size' === $preset ) {
				return DesignTokens::font_size( $slug );
			}

			if ( 'spacing' === $preset ) {
				return DesignTokens::spacing( $slug );
			}

			return is_numeric( $slug ) ? $slug . 'px' : '';
		}

		if ( preg_match( '/^-?\d+(?:\.\d+)?$/', $value ) ) {
			return $unitless ? $value : $value . 'px';
		}

		return $value;
	}

	private function font_family( string $value ): string {
		$value = trim( $value );

		if ( '' === $value ) {
			return '';
		}

		if ( str_starts_with( $value, 'var:' ) ) {
			$parts = explode( '|', $value );

			if ( 'font-family' === ( $parts[1] ?? '' ) ) {
				return DesignTokens::font_family( (string) end( $parts ) );
			}

			return '';
		}

		return $value;
	}

	private function color_value( string $value ): string {
		$value = trim( $value );

		if ( '' === $value || str_starts_with( $value, 'var:' ) ) {
			return '';
		}

		return $value;
	}

	/**
	 * Add inline CSS to the first matching tag, preserving existing styles.
	 */
	private function add_style( string $html, string $style, string $tag = '' ): string {
		if ( '' === $style ) {
			return $html;
		}

		$pattern = '' !== $tag
			? '#<(' . preg_quote( $tag, '#' ) . ')\b(\s[^>]*)?>#i'
			: '#<([a-z][a-z0-9]*)\b(\s[^>]*)?>#i';

		return (string) preg_replace_callback(
			$pattern,
			static function ( array $match ) use ( $style ): string {
				$attrs = (string) ( $match[2] ?? '' );

				if ( preg_match( '#\sstyle\s*=\s*(["\'])(.*?)\1#is', $attrs, $existing ) ) {
					$value = rtrim( $existing[2], ';' ) . ';' . $style;
					$attrs = str_replace( $existing[0], ' style=' . $existing[1] . $value . $existing[1], $attrs );
				} else {
					$attrs .= ' style="' . esc_attr( $style ) . '"';
				}

				return '<' . $match[1] . $attrs . '>';
			},
			$html,
			1
		);
	}
}
