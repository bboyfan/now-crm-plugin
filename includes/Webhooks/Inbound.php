<?php
/**
 * Inbound webhook endpoint.
 *
 * Public route: `?nowcrm_webhook=contact&key=<uuid>` creates or updates one
 * contact and attaches validated lists and tags. `?nowcrm_webhook=<handler>
 * &key=<uuid>` dispatches a generic handler action. Every key is scoped: a key
 * for one handler cannot drive another route. Invalid keys, unknown handlers
 * and missing keys all return the same 401 body so the endpoint is not an
 * existence oracle.
 *
 * @package NowCRM
 */

namespace NowCRM\Webhooks;

use NowCRM\Contacts\ContactRepository;
use NowCRM\Terms\TermRepository;
use WP_Error;

defined( 'ABSPATH' ) || exit;

final class Inbound {

	public const QUERY_VAR = 'nowcrm_webhook';

	public const OPTION = 'nowcrm_webhook_secret';

	public const MAX_BODY = 65536;

	public const MAX_TERMS = 100;

	/**
	 * Handle the public request on init.
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'maybe_handle' ), 1 );
	}

	/**
	 * Intercept `?nowcrm_webhook=...` before the theme renders.
	 */
	public function maybe_handle(): void {
		if ( ! isset( $_GET[ self::QUERY_VAR ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the key is the capability.
			return;
		}

		$route  = isset( $_GET[ self::QUERY_VAR ] ) && is_string( $_GET[ self::QUERY_VAR ] ) ? sanitize_key( wp_unslash( $_GET[ self::QUERY_VAR ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$key    = isset( $_GET['key'] ) && is_string( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REQUEST_METHOD'] ) ) : 'GET';
		$body   = $this->read_payload();

		if ( is_wp_error( $body ) ) {
			$error  = $body->get_error_data();
			$status = is_array( $error ) && isset( $error['status'] ) ? (int) $error['status'] : 400;

			nocache_headers();
			wp_send_json(
				array(
					'ok'    => false,
					'error' => (string) $body->get_error_code(),
				),
				$status >= 400 && $status <= 499 ? $status : 400
			);
		}

		$result = $this->handle( $route, $key, $body, $method );

		nocache_headers();
		wp_send_json( $result['body'], $result['code'] );
	}

	/**
	 * Process a decoded request. Tests call this directly.
	 *
	 * @param string               $route  `contact` or a registered handler slug.
	 * @param string               $key    Scoped UUID.
	 * @param array<string, mixed> $payload Decoded body.
	 * @param string               $method HTTP method.
	 * @return array{code: int, body: array<string, mixed>}
	 */
	public function handle( string $route, string $key, array $payload, string $method = 'POST' ): array {
		if ( 'POST' !== strtoupper( $method ) ) {
			return array(
				'code' => 405,
				'body' => array(
					'ok'    => false,
					'error' => 'method_not_allowed',
				),
			);
		}

		$route = sanitize_key( $route );

		if ( 'contact' === $route ) {
			if ( ! $this->authorize_contact( $key ) ) {
				return $this->unauthorized();
			}

			return $this->handle_contact( $payload );
		}

		if ( ! $this->authorize_handler( $route, $key ) ) {
			return $this->unauthorized();
		}

		/**
		 * Generic inbound webhook.
		 *
		 * @param array<string, mixed> $payload Decoded request body.
		 * @param string               $handler Handler slug.
		 */
		do_action( 'nowcrm_webhook_handler_' . $route, $payload, $route );
		do_action( 'nowcrm_webhook_handler', $route, $payload );

		return array(
			'code' => 202,
			'body' => array(
				'ok'       => true,
				'accepted' => true,
			),
		);
	}

	/**
	 * Contact key, optionally generated.
	 */
	public static function contact_key( bool $create = false ): string {
		$state = self::state();

		if ( '' === $state['contact'] && $create ) {
			return self::rotate_contact_key();
		}

		return $state['contact'];
	}

	/**
	 * Generate a new contact key.
	 */
	public static function rotate_contact_key(): string {
		$state            = self::state();
		$state['contact'] = wp_generate_uuid4();

		self::store( $state );

		return $state['contact'];
	}

	/**
	 * Registered generic handlers, from the filter.
	 *
	 * @return array<string, string> Slug => label.
	 */
	public static function handlers(): array {
		$handlers = apply_filters( 'nowcrm_webhook_handlers', array() );
		$out      = array();

		foreach ( (array) $handlers as $slug => $label ) {
			$slug = sanitize_key( (string) $slug );

			if ( '' === $slug || 'contact' === $slug || isset( $out[ $slug ] ) ) {
				continue;
			}

			$out[ $slug ] = is_string( $label ) && '' !== $label ? $label : $slug;
		}

		return $out;
	}

	/**
	 * Key for one registered handler, optionally generated.
	 */
	public static function handler_key( string $handler, bool $create = false ): string {
		$handler = sanitize_key( $handler );

		if ( '' === $handler || ! isset( self::handlers()[ $handler ] ) ) {
			return '';
		}

		$state = self::state();
		$key   = isset( $state['handlers'][ $handler ] ) && is_string( $state['handlers'][ $handler ] )
			? $state['handlers'][ $handler ]
			: '';

		if ( '' === $key && $create ) {
			$rotated = self::rotate_handler_key( $handler );

			return is_wp_error( $rotated ) ? '' : $rotated;
		}

		return $key;
	}

	/**
	 * Generate a new key for a registered handler.
	 *
	 * @return string|WP_Error
	 */
	public static function rotate_handler_key( string $handler ) {
		$handler = sanitize_key( $handler );

		if ( '' === $handler || ! isset( self::handlers()[ $handler ] ) ) {
			return new WP_Error( 'nowcrm_webhook_invalid_handler', __( '未註冊的 Webhook 處理器。', 'now-crm' ) );
		}

		$state    = self::state();
		$key      = wp_generate_uuid4();

		$state['handlers'][ $handler ] = $key;

		self::store( $state );

		return $key;
	}

	/**
	 * Public contact URL, or an empty string before a key exists.
	 */
	public static function contact_url(): string {
		$key = self::contact_key();

		return '' === $key ? '' : self::url( 'contact', $key );
	}

	/**
	 * Public handler URL, or an empty string before a key exists.
	 */
	public static function handler_url( string $handler ): string {
		$key = self::handler_key( $handler );

		return '' === $key ? '' : self::url( sanitize_key( $handler ), $key );
	}

	/**
	 * Current inbound state.
	 *
	 * @return array{contact: string, handlers: array<string, string>}
	 */
	public static function state(): array {
		$stored   = get_option( self::OPTION, array() );
		$stored   = is_array( $stored ) ? $stored : array();
		$contact  = isset( $stored['contact'] ) && is_string( $stored['contact'] ) ? trim( $stored['contact'] ) : '';
		$handlers = array();

		foreach ( (array) ( $stored['handlers'] ?? array() ) as $slug => $key ) {
			$slug = sanitize_key( (string) $slug );

			if ( '' !== $slug && is_string( $key ) && '' !== $key ) {
				$handlers[ $slug ] = $key;
			}
		}

		return array(
			'contact'  => $contact,
			'handlers' => $handlers,
		);
	}

	/**
	 * Create or update a contact and attach lists/tags.
	 *
	 * @param array<string, mixed> $payload Request body.
	 * @return array{code: int, body: array<string, mixed>}
	 */
	private function handle_contact( array $payload ): array {
		$email = isset( $payload['email'] ) && is_string( $payload['email'] ) ? sanitize_email( $payload['email'] ) : '';

		if ( '' === $email || ! is_email( $email ) ) {
			return $this->invalid( array( 'email' => __( '需要有效的 Email。', 'now-crm' ) ) );
		}

		$profile = $this->profile( $payload );

		if ( is_wp_error( $profile ) ) {
			return $this->invalid( array( 'profile' => $profile->get_error_message() ) );
		}

		$terms = $this->resolve_terms( $payload );

		if ( is_wp_error( $terms ) ) {
			$data = $terms->get_error_data();
			$field = is_array( $data ) && isset( $data['field'] ) ? (string) $data['field'] : 'terms';

			return $this->invalid( array( $field => $terms->get_error_message() ) );
		}

		$contacts = new ContactRepository();
		$existing = $contacts->find_by_email( $email );
		$created  = false;

		if ( $existing ) {
			$id = (int) $existing['id'];

			if ( array() !== $profile ) {
				$updated = $contacts->update( $id, $profile );

				if ( is_wp_error( $updated ) ) {
					$code = str_starts_with( (string) $updated->get_error_code(), 'nowcrm_invalid' ) ? 422 : 500;

					return array(
						'code' => $code,
						'body' => array(
							'ok'    => false,
							'error' => 'save_failed',
						),
					);
				}
			}
		} else {
			$result = $contacts->get_or_create( $email, array_merge( $profile, array( 'source' => 'webhook' ) ) );

			if ( is_wp_error( $result ) ) {
				return array(
					'code' => 500,
					'body' => array(
						'ok'    => false,
						'error' => 'save_failed',
					),
				);
			}

			$id      = (int) $result['contact']['id'];
			$created = (bool) $result['created'];
		}

		$repo = new TermRepository();

		foreach ( $terms['lists'] as $term_id ) {
			$repo->attach( $id, $term_id );
		}

		foreach ( $terms['tags'] as $term_id ) {
			$repo->attach( $id, $term_id );
		}

		return array(
			'code' => 200,
			'body' => array(
				'ok'      => true,
				'created' => $created,
				'id'      => $id,
				'lists'   => $terms['lists'],
				'tags'    => $terms['tags'],
			),
		);
	}

	/**
	 * Validated profile patch.
	 *
	 * @param array<string, mixed> $payload Request body.
	 * @return array<string, string>|WP_Error
	 */
	private function profile( array $payload ) {
		$limits = array(
			'first_name' => 100,
			'last_name'  => 100,
			'phone'      => 40,
		);

		$profile = array();

		foreach ( $limits as $field => $limit ) {
			if ( ! array_key_exists( $field, $payload ) ) {
				continue;
			}

			if ( ! is_scalar( $payload[ $field ] ) ) {
				return new WP_Error( 'nowcrm_webhook_invalid_profile', __( '聯絡人欄位格式不正確。', 'now-crm' ) );
			}

			$profile[ $field ] = mb_substr( sanitize_text_field( (string) $payload[ $field ] ), 0, $limit );
		}

		return $profile;
	}

	/**
	 * Resolve list and tag references to existing term IDs.
	 *
	 * @param array<string, mixed> $payload Request body.
	 * @return array{lists: int[], tags: int[]}|WP_Error
	 */
	private function resolve_terms( array $payload ) {
		$repo   = new TermRepository();
		$fields = array(
			'lists' => TermRepository::TYPE_LIST,
			'tags'  => TermRepository::TYPE_TAG,
		);

		$resolved = array(
			'lists' => array(),
			'tags'  => array(),
		);

		foreach ( $fields as $field => $type ) {
			if ( ! array_key_exists( $field, $payload ) ) {
				continue;
			}

			$raw = $payload[ $field ];

			if ( ! is_array( $raw ) ) {
				$raw = array( $raw );
			}

			if ( count( $raw ) > self::MAX_TERMS ) {
				return new WP_Error(
					'nowcrm_webhook_invalid_terms',
					__( '名單或標籤數量過多。', 'now-crm' ),
					array( 'field' => $field )
				);
			}

			$by_id   = array();
			$by_name = array();
			$by_slug = array();

			foreach ( $repo->all( $type ) as $term ) {
				$id           = (int) $term['id'];
				$by_id[ $id ] = true;
				$by_name[ mb_strtolower( (string) $term['name'], 'UTF-8' ) ] = $id;
				$by_slug[ mb_strtolower( (string) $term['slug'], 'UTF-8' ) ] = $id;
			}

			foreach ( $raw as $item ) {
				$term_id = 0;

				if ( is_int( $item ) || ( is_string( $item ) && ctype_digit( $item ) ) ) {
					$term_id = (int) $item;

					if ( ! isset( $by_id[ $term_id ] ) ) {
						return new WP_Error(
							'nowcrm_webhook_invalid_terms',
							__( '名單或標籤不存在。', 'now-crm' ),
							array( 'field' => $field )
						);
					}
				} elseif ( is_string( $item ) && '' !== trim( $item ) ) {
					$value = trim( $item );
					$name  = mb_strtolower( $value, 'UTF-8' );
					$slug  = mb_strtolower( sanitize_title( $value ), 'UTF-8' );

					if ( isset( $by_name[ $name ] ) ) {
						$term_id = $by_name[ $name ];
					} elseif ( '' !== $slug && isset( $by_slug[ $slug ] ) ) {
						$term_id = $by_slug[ $slug ];
					}

					if ( 0 === $term_id ) {
						return new WP_Error(
							'nowcrm_webhook_invalid_terms',
							__( '名單或標籤不存在。', 'now-crm' ),
							array( 'field' => $field )
						);
					}
				} else {
					return new WP_Error(
						'nowcrm_webhook_invalid_terms',
						__( '名單或標籤格式不正確。', 'now-crm' ),
						array( 'field' => $field )
					);
				}

				$resolved[ $field ][] = $term_id;
			}

			$resolved[ $field ] = array_values( array_unique( $resolved[ $field ] ) );
		}

		return $resolved;
	}

	/**
	 * Constant-time contact key check.
	 */
	private function authorize_contact( string $key ): bool {
		$expected = self::state()['contact'];

		if ( '' === $expected || '' === $key ) {
			hash_equals( str_repeat( '0', 36 ), $key );

			return false;
		}

		return hash_equals( $expected, $key );
	}

	/**
	 * Constant-time handler key check. Every stored handler key is compared so
	 * an unknown route does not return faster than a known one.
	 */
	private function authorize_handler( string $route, string $key ): bool {
		$handlers = self::state()['handlers'];
		$match    = false;

		foreach ( $handlers as $slug => $secret ) {
			if ( hash_equals( (string) $secret, $key ) ) {
				$match = $slug === $route;
			}
		}

		if ( array() === $handlers || '' === $key ) {
			hash_equals( str_repeat( '0', 36 ), $key );
		}

		return $match;
	}

	/**
	 * Read and decode the request body.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	private function read_payload() {
		$length = isset( $_SERVER['CONTENT_LENGTH'] ) ? (int) $_SERVER['CONTENT_LENGTH'] : 0;

		if ( $length > self::MAX_BODY ) {
			return new WP_Error( 'payload_too_large', '', array( 'status' => 413 ) );
		}

		$raw = file_get_contents( 'php://input' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- raw JSON body.
		$raw = is_string( $raw ) ? $raw : '';

		if ( strlen( $raw ) > self::MAX_BODY ) {
			return new WP_Error( 'payload_too_large', '', array( 'status' => 413 ) );
		}

		if ( '' === $raw ) {
			$data = isset( $_POST ) && is_array( $_POST ) ? wp_unslash( $_POST ) : array();

			return is_array( $data ) ? $data : array();
		}

		$content_type = isset( $_SERVER['CONTENT_TYPE'] ) ? strtolower( (string) $_SERVER['CONTENT_TYPE'] ) : '';
		$data         = json_decode( $raw, true );

		if ( JSON_ERROR_NONE === json_last_error() && is_array( $data ) ) {
			return $data;
		}

		if ( str_contains( $content_type, 'application/x-www-form-urlencoded' ) ) {
			parse_str( $raw, $parsed );

			return is_array( $parsed ) ? wp_unslash( $parsed ) : array();
		}

		return new WP_Error( 'invalid_json', '', array( 'status' => 400 ) );
	}

	/**
	 * Generic unauthorized response. Never names the route or key.
	 *
	 * @return array{code: int, body: array<string, string>}
	 */
	private function unauthorized(): array {
		return array(
			'code' => 401,
			'body' => array(
				'ok'    => false,
				'error' => 'unauthorized',
			),
		);
	}

	/**
	 * Field validation response.
	 *
	 * @param array<string, string> $fields Field messages.
	 * @return array{code: int, body: array<string, mixed>}
	 */
	private function invalid( array $fields ): array {
		return array(
			'code' => 422,
			'body' => array(
				'ok'     => false,
				'error'  => 'validation_failed',
				'fields' => $fields,
			),
		);
	}

	/**
	 * Public URL for a route and key.
	 */
	private static function url( string $route, string $key ): string {
		return (string) add_query_arg(
			array(
				self::QUERY_VAR => $route,
				'key'           => $key,
			),
			home_url( '/' )
		);
	}

	/**
	 * Persist inbound keys without autoloading them.
	 *
	 * @param array{contact: string, handlers: array<string, string>} $state State.
	 */
	private static function store( array $state ): void {
		update_option( self::OPTION, $state, false );
	}
}
