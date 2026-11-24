<?php
/**
 * Per-recipient conditional content for campaign emails.
 *
 * Conditions never run in the browser and never write recipient data into a
 * campaign. A send snapshot freezes the condition markers. The queue renders
 * the snapshot for each contact with that contact in the request scope.
 *
 * @package NowCRM
 */

namespace NowCRM\Email;

use NowCRM\Contacts\ContactRepository;
use NowCRM\Contacts\ProfileFields;
use NowCRM\Contacts\Status;
use NowCRM\Terms\TermRepository;

defined( 'ABSPATH' ) || exit;

final class ConditionalContent {

	public const BLOCK     = 'nowcrm/conditional';
	public const SHORTCODE = 'nowcrm_if';

	private const MARKER_OPEN  = '<!--nowcrm:if:';
	private const MARKER_CLOSE = '<!--/nowcrm:if-->';

	private const FIELDS = array( 'contact_type', 'status', 'country', 'list', 'tag' );

	private const OPERATORS = array( 'is', 'is_not' );

	/**
	 * Contact for the render in progress. Null means no request scope is set.
	 *
	 * @var array<string, mixed>|null
	 */
	private static ?array $contact = null;

	/**
	 * Whether a render scope is active. A scope with no contact fails closed.
	 */
	private static bool $scoped = false;

	/**
	 * Terms of the scoped contact, loaded once per render.
	 *
	 * @var array<int, array<string, mixed>>|null
	 */
	private static ?array $terms = null;

	/**
	 * Script handles that already received the condition values.
	 *
	 * @var array<string, bool>
	 */
	private static array $localized = array();

	/**
	 * Register the block and the editor configuration.
	 */
	public static function register(): void {
		add_action( 'init', array( self::class, 'register_block' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue_editor_config' ), 20 );
	}

	/**
	 * Register the server render callback. The editor registers the same name
	 * in the campaign bundle; this registration supplies the PHP renderer.
	 */
	public static function register_block(): void {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}

		register_block_type(
			self::BLOCK,
			array(
				'api_version'     => 3,
				'attributes'      => array(
					'field'    => array(
						'type'    => 'string',
						'default' => 'contact_type',
					),
					'operator' => array(
						'type'    => 'string',
						'default' => 'is',
					),
					'value'    => array(
						'type'    => 'string',
						'default' => '',
					),
				),
				'supports'        => array( 'html' => false ),
				'render_callback' => array( self::class, 'render_block' ),
			)
		);
	}

	/**
	 * Add condition values to the campaign editor script.
	 *
	 * Runs after the campaign page enqueues the workspace bundle. The values
	 * come from PHP so filtered contact types and countries stay in sync.
	 */
	public static function enqueue_editor_config(): void {
		if ( ! wp_script_is( 'nowcrm-campaign-workspace', 'enqueued' ) ) {
			return;
		}

		self::localize_editor_config( 'nowcrm-campaign-workspace' );
	}

	/**
	 * Attach the condition values to one editor script.
	 *
	 * The campaign workspace and the reusable template editor mount the same
	 * shared block, so both need the same option data. Handles are remembered
	 * so repeated calls never print the payload twice.
	 *
	 * @param string $handle Enqueued script handle to localize the data for.
	 */
	public static function localize_editor_config( string $handle ): void {
		if ( '' === $handle || isset( self::$localized[ $handle ] ) || ! wp_script_is( $handle, 'enqueued' ) ) {
			return;
		}

		$lists = array();
		$tags  = array();

		foreach ( ( new TermRepository() )->all() as $term ) {
			$item = array(
				'id'   => (int) $term['id'],
				'name' => (string) $term['name'],
			);

			if ( TermRepository::TYPE_LIST === $term['type'] ) {
				$lists[] = $item;
			} elseif ( TermRepository::TYPE_TAG === $term['type'] ) {
				$tags[] = $item;
			}
		}

		$statuses = array();

		foreach ( Status::all() as $status ) {
			$statuses[ $status ] = Status::label( $status );
		}

		wp_localize_script(
			$handle,
			'nowcrmConditionalConfig',
			array(
				'contactTypes' => ProfileFields::contact_types(),
				'statuses'     => $statuses,
				'countries'    => ProfileFields::countries(),
				'lists'        => $lists,
				'tags'         => $tags,
			)
		);

		self::$localized[ $handle ] = true;
	}

	/**
	 * Server render callback for the block.
	 *
	 * Without a render scope the block leaves a frozen marker. The marker keeps
	 * the condition and the rendered inner blocks so a snapshot can resolve it
	 * later for each recipient.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @param string               $content    Rendered inner blocks.
	 * @return string
	 */
	public static function render_block( array $attributes, string $content ): string {
		$condition = self::condition( $attributes );

		if ( null === $condition || '' === trim( $content ) ) {
			return '';
		}

		if ( ! self::$scoped ) {
			return self::MARKER_OPEN . base64_encode( (string) wp_json_encode( $condition ) ) . '-->' . $content . self::MARKER_CLOSE;
		}

		return self::matches( $condition ) ? $content : '';
	}

	/**
	 * Resolve frozen markers and the shortcode fallback.
	 *
	 * Nested conditions resolve from the inside out. A malformed or truncated
	 * condition drops its content, so unknown input never leaks to a recipient.
	 *
	 * @param string $html Rendered HTML.
	 */
	public static function resolve( string $html ): string {
		if ( ! self::contains_condition( $html ) ) {
			return $html;
		}

		$pattern = '/<!--nowcrm:if:([A-Za-z0-9+\/=]+)-->|<!--\/nowcrm:if-->|\[nowcrm_if\b(?:\s[^\]]*)?\]|\[\/nowcrm_if\]/i';

		if ( ! preg_match_all( $pattern, $html, $matches, PREG_OFFSET_CAPTURE ) ) {
			return $html;
		}

		$output = '';
		$stack  = array();
		$offset = 0;

		foreach ( $matches[0] as $match ) {
			$token = $match[0];
			$start = $match[1];
			$text  = substr( $html, $offset, $start - $offset );
			$offset = $start + strlen( $token );

			if ( $stack ) {
				$stack[ count( $stack ) - 1 ]['buffer'] .= $text;
			} else {
				$output .= $text;
			}

			$open = self::open_condition( $token );

			if ( null !== $open ) {
				$stack[] = array(
					'kind'      => $open['kind'],
					'condition' => $open['condition'],
					'buffer'    => '',
				);
				continue;
			}

			$close = self::close_kind( $token );

			if ( null === $close || ! $stack || end( $stack )['kind'] !== $close ) {
				// A stray or mismatched close is dropped.
				continue;
			}

			$frame  = array_pop( $stack );
			$render = null !== $frame['condition'] && self::matches( $frame['condition'] ) ? $frame['buffer'] : '';

			if ( $stack ) {
				$stack[ count( $stack ) - 1 ]['buffer'] .= $render;
			} else {
				$output .= $render;
			}
		}

		if ( $stack ) {
			// An unclosed condition fails closed; its buffer is discarded.
			return $output;
		}

		return $output . substr( $html, $offset );
	}

	/**
	 * Whether content contains a conditional block, marker or shortcode.
	 */
	public static function contains_condition( string $content ): bool {
		return str_contains( $content, 'wp:' . self::BLOCK )
			|| str_contains( $content, self::MARKER_OPEN )
			|| false !== stripos( $content, '[' . self::SHORTCODE );
	}

	/**
	 * Resolve the native contact for this render.
	 *
	 * A caller can pass the contact row directly under `contact`; otherwise the
	 * campaign queue's email address is the stable lookup key.
	 *
	 * @param array<string, mixed> $context Render context.
	 * @return array<string, mixed>|null
	 */
	public static function contact_from_context( array $context ): ?array {
		if ( isset( $context['contact'] ) && is_array( $context['contact'] ) && $context['contact'] ) {
			return $context['contact'];
		}

		$repository = new ContactRepository();

		if ( ! empty( $context['contact_id'] ) ) {
			return $repository->get( (int) $context['contact_id'] );
		}

		if ( ! empty( $context['email'] ) && is_string( $context['email'] ) ) {
			return $repository->find_by_email( $context['email'] );
		}

		return null;
	}

	/**
	 * Start a request scope.
	 *
	 * @param array<string, mixed>|null $contact Native contact row, or null.
	 */
	public static function begin( ?array $contact ): void {
		self::$contact = $contact;
		self::$scoped  = true;
		self::$terms   = null;
	}

	/**
	 * End the request scope.
	 */
	public static function end(): void {
		self::$contact = null;
		self::$scoped  = false;
		self::$terms   = null;
	}

	/**
	 * Whether a render scope is active.
	 */
	public static function active(): bool {
		return self::$scoped;
	}

	/**
	 * Normalize and allowlist block attributes. Unknown input returns null and
	 * fails closed.
	 *
	 * @param array<string, mixed> $attributes Raw attributes.
	 * @return array{field: string, operator: string, value: string}|null
	 */
	private static function condition( array $attributes ): ?array {
		$field    = sanitize_key( (string) ( $attributes['field'] ?? '' ) );
		$operator = sanitize_key( (string) ( $attributes['operator'] ?? '' ) );
		$value    = trim( sanitize_text_field( (string) ( $attributes['value'] ?? '' ) ) );

		if ( ! in_array( $field, self::FIELDS, true ) || ! in_array( $operator, self::OPERATORS, true ) || '' === $value ) {
			return null;
		}

		if ( 'list' === $field || 'tag' === $field ) {
			$value = (string) absint( $value );

			if ( '0' === $value ) {
				return null;
			}
		} elseif ( 'contact_type' === $field ) {
			if ( ! ProfileFields::is_valid_contact_type( $value ) ) {
				return null;
			}
		} elseif ( 'status' === $field ) {
			if ( ! Status::is_valid( $value ) ) {
				return null;
			}
		} else {
			$value = strtoupper( $value );

			if ( ! ProfileFields::is_valid_country( $value ) ) {
				return null;
			}
		}

		return array(
			'field'    => $field,
			'operator' => $operator,
			'value'    => $value,
		);
	}

	/**
	 * Evaluate one condition against the scoped contact.
	 *
	 * @param array{field: string, operator: string, value: string} $condition Condition.
	 */
	private static function matches( array $condition ): bool {
		if ( null === self::$contact ) {
			return false;
		}

		$field = $condition['field'];

		if ( 'list' === $field || 'tag' === $field ) {
			$match = self::has_term( $field, (int) $condition['value'] );
		} else {
			$actual = (string) ( self::$contact[ $field ] ?? '' );

			if ( 'country' === $field ) {
				$actual = strtoupper( $actual );
			}

			$match = $actual === $condition['value'];
		}

		return 'is_not' === $condition['operator'] ? ! $match : $match;
	}

	/**
	 * Whether the scoped contact belongs to a list or tag.
	 */
	private static function has_term( string $type, int $term_id ): bool {
		if ( $term_id <= 0 ) {
			return false;
		}

		if ( null === self::$terms ) {
			$id          = (int) ( self::$contact['id'] ?? 0 );
			self::$terms = $id > 0 ? ( new TermRepository() )->for_contact( $id ) : array();
		}

		foreach ( self::$terms as $term ) {
			if ( $type === ( $term['type'] ?? '' ) && $term_id === (int) ( $term['id'] ?? 0 ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Parse an opening token.
	 *
	 * @return array{kind: string, condition: array<string, string>|null}|null
	 */
	private static function open_condition( string $token ): ?array {
		if ( str_starts_with( $token, self::MARKER_OPEN ) ) {
			$encoded = substr( $token, strlen( self::MARKER_OPEN ), -3 );
			$decoded = base64_decode( $encoded, true );
			$attrs   = false !== $decoded ? json_decode( $decoded, true ) : null;

			return array(
				'kind'      => 'marker',
				'condition' => is_array( $attrs ) ? self::condition( $attrs ) : null,
			);
		}

		if ( preg_match( '/^\[nowcrm_if\b(.*)\]$/is', $token, $matches ) ) {
			$atts = shortcode_parse_atts( trim( (string) $matches[1] ) );

			if ( ! is_array( $atts ) ) {
				$atts = array();
			}

			return array(
				'kind'      => 'shortcode',
				'condition' => self::condition(
					array(
						'field'    => $atts['field'] ?? '',
						'operator' => $atts['operator'] ?? ( $atts['op'] ?? '' ),
						'value'    => $atts['value'] ?? '',
					)
				),
			);
		}

		return null;
	}

	/**
	 * Kind of a closing token.
	 */
	private static function close_kind( string $token ): ?string {
		if ( self::MARKER_CLOSE === $token ) {
			return 'marker';
		}

		if ( preg_match( '/^\[\/nowcrm_if\]$/i', $token ) ) {
			return 'shortcode';
		}

		return null;
	}
}
