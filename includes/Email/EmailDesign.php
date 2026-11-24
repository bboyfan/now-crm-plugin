<?php
/**
 * Campaign-only colour design for block emails.
 *
 * The design is a small, normalized set of hexadecimal colours. Empty values
 * mean "keep the renderer default". Only block campaigns use it; Raw HTML
 * keeps its own inline styles.
 *
 * @package NowCRM
 */

namespace NowCRM\Email;

defined( 'ABSPATH' ) || exit;

final class EmailDesign {

	public const KEYS = array( 'body', 'content', 'heading', 'text', 'link' );

	public const DEFAULTS = array(
		'body'    => '#f4f5f7',
		'content' => '#ffffff',
		'heading' => '#111827',
		'text'    => '#1f2937',
		'link'    => '#1f2937',
	);

	/**
	 * Normalize one stored or submitted design.
	 *
	 * Unknown keys and non-hex colours are dropped to the default (empty).
	 *
	 * @param mixed $value Stored meta array or request value.
	 * @return array{body: string, content: string, heading: string, text: string, link: string}
	 */
	public static function normalize( $value ): array {
		$input  = is_object( $value ) ? (array) $value : ( is_array( $value ) ? $value : array() );
		$design = array();

		foreach ( self::KEYS as $key ) {
			$color = isset( $input[ $key ] ) && is_string( $input[ $key ] ) ? strtolower( trim( $input[ $key ] ) ) : '';

			if ( '' !== $color && ! preg_match( '/^#(?:[0-9a-f]{3}|[0-9a-f]{6})$/', $color ) ) {
				$color = '';
			}

			$design[ $key ] = $color;
		}

		return $design;
	}

	/**
	 * One effective colour: the design value or the renderer default.
	 */
	public static function color( array $design, string $key ): string {
		$design = self::normalize( $design );

		return '' !== $design[ $key ] ? $design[ $key ] : self::DEFAULTS[ $key ];
	}

	/**
	 * Inline CSS for the block editor canvas.
	 *
	 * The canvas keeps the native editor layout; only colours change.
	 */
	public static function canvas_css( array $design ): string {
		$design = self::normalize( $design );
		$css    = '';

		if ( '' !== $design['body'] ) {
			$css .= '.editor-styles-wrapper{background-color:' . $design['body'] . ';}';
		}
		if ( '' !== $design['content'] ) {
			$css .= '.editor-styles-wrapper .is-root-container{background-color:' . $design['content'] . ';}';
		}
		if ( '' !== $design['text'] ) {
			$css .= '.editor-styles-wrapper{color:' . $design['text'] . ';}';
		}
		if ( '' !== $design['heading'] ) {
			$css .= '.editor-styles-wrapper :where(h1,h2,h3,h4,h5,h6){color:' . $design['heading'] . ';}';
		}
		if ( '' !== $design['link'] ) {
			$css .= '.editor-styles-wrapper a{color:' . $design['link'] . ';}';
		}

		return $css;
	}
}
