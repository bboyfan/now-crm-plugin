<?php
/**
 * Authenticated lists and tags REST contract.
 *
 * @package NowCRM
 */

namespace NowCRM\Terms;

use NowCRM\Support\Capabilities;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * Term endpoints live under `nowcrm/v1`. The resource segment owns the term
 * type; callers can never switch type through request data.
 */
final class Routes {

	private const REST_NAMESPACE = 'nowcrm/v1';

	private const MAX_ITEMS = 500;

	private const MAX_IDS = 200;

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	public function routes(): void {
		$resources = array(
			'lists' => TermRepository::TYPE_LIST,
			'tags'  => TermRepository::TYPE_TAG,
		);

		foreach ( $resources as $resource => $type ) {
			$base = '/' . $resource;

			register_rest_route(
				self::REST_NAMESPACE,
				$base,
				array(
					$this->route( 'GET', 'index', $type, $this->index_args() ),
					$this->route(
						'POST',
						'create',
						$type,
						array(
							'name'        => array(
								'type'     => 'string',
								'required' => true,
								'minLength' => 1,
							),
							'slug'        => array(
								'type'    => 'string',
								'default' => '',
							),
							'description' => array(
								'type'    => 'string',
								'default' => '',
							),
						)
					),
				)
			);

			register_rest_route(
				self::REST_NAMESPACE,
				$base . '/bulk',
				$this->route(
					'POST',
					'bulk_save',
					$type,
					array(
						'items' => array(
							'type'     => 'array',
							'required' => true,
							'minItems' => 1,
							'maxItems' => self::MAX_ITEMS,
						),
					)
				)
			);

			register_rest_route(
				self::REST_NAMESPACE,
				$base . '/bulk-delete',
				$this->route(
					'POST',
					'bulk_delete',
					$type,
					array(
						'ids' => array(
							'type'     => 'array',
							'required' => true,
							'minItems' => 1,
							'maxItems' => self::MAX_IDS,
						),
					)
				)
			);

			register_rest_route(
				self::REST_NAMESPACE,
				$base . '/by-slug/(?P<slug>[^/]+)',
				$this->route( 'GET', 'find_by_slug', $type )
			);

			register_rest_route(
				self::REST_NAMESPACE,
				$base . '/(?P<id>[1-9]\d*)',
				array(
					$this->route( 'GET', 'find', $type ),
					$this->route(
						'PUT',
						'update',
						$type,
						array(
							'name'        => array( 'type' => 'string' ),
							'slug'        => array( 'type' => 'string' ),
							'description' => array( 'type' => 'string' ),
						)
					),
					$this->route( 'DELETE', 'delete', $type ),
				)
			);
		}
	}

	/**
	 * Build one authenticated endpoint definition.
	 *
	 * The matched resource fixes the term type for the callback, so request
	 * data can never select a different type.
	 *
	 * @param array<string, mixed> $args Request arguments.
	 * @return array<string, mixed>
	 */
	private function route( string $method, string $handler, string $type, array $args = array() ): array {
		return array(
			'methods'             => $method,
			'callback'            => function ( WP_REST_Request $request ) use ( $handler, $type ) {
				return $this->{$handler}( $request, $type );
			},
			'permission_callback' => static function ( WP_REST_Request $request ): bool {
				return Capabilities::can_manage_contacts();
			},
			'args'                => $args,
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function index_args(): array {
		return array(
			'page'     => array(
				'type'    => 'integer',
				'default' => 1,
				'minimum' => 1,
			),
			'per_page' => array(
				'type'    => 'integer',
				'default' => 20,
				'minimum' => 1,
				'maximum' => 100,
			),
			'search'   => array(
				'type'    => 'string',
				'default' => '',
			),
			'orderby'  => array(
				'type'    => 'string',
				'default' => 'name',
				'enum'    => array( 'name', 'slug', 'contact_count', 'created_at' ),
			),
			'order'    => array(
				'type'    => 'string',
				'default' => 'asc',
				'enum'    => array( 'asc', 'desc' ),
			),
		);
	}

	public function index( WP_REST_Request $request, string $type ): WP_REST_Response {
		$page     = max( 1, absint( $request->get_param( 'page' ) ) );
		$per_page = max( 1, min( 100, absint( $request->get_param( 'per_page' ) ) ) );

		$result = ( new TermRepository() )->paginate(
			array(
				'type'     => $type,
				'search'   => (string) $request->get_param( 'search' ),
				'orderby'  => (string) $request->get_param( 'orderby' ),
				'order'    => strtoupper( (string) $request->get_param( 'order' ) ),
				'page'     => $page,
				'per_page' => $per_page,
			)
		);

		$total = (int) $result['total'];

		return new WP_REST_Response(
			array(
				'items'    => array_map( array( $this, 'shape' ), $result['items'] ),
				'total'    => $total,
				'page'     => $page,
				'per_page' => $per_page,
				'pages'    => (int) ceil( $total / $per_page ),
			),
			200
		);
	}

	public function find( WP_REST_Request $request, string $type ) {
		$id  = (int) ( $request->get_url_params()['id'] ?? 0 );
		$row = $id > 0 ? ( new TermRepository() )->get( $id ) : null;

		if ( ! $row || (string) $row['type'] !== $type ) {
			return $this->not_found();
		}

		return new WP_REST_Response( $this->shape( $row ), 200 );
	}

	public function find_by_slug( WP_REST_Request $request, string $type ) {
		$slug = (string) ( $request->get_url_params()['slug'] ?? '' );
		$row  = '' !== $slug ? ( new TermRepository() )->find_by_slug( $type, $slug ) : null;

		if ( ! $row ) {
			return $this->not_found();
		}

		return new WP_REST_Response( $this->shape( $row ), 200 );
	}

	public function create( WP_REST_Request $request, string $type ) {
		$created = ( new TermRepository() )->create(
			$type,
			(string) $request->get_param( 'name' ),
			array(
				'slug'        => (string) $request->get_param( 'slug' ),
				'description' => (string) $request->get_param( 'description' ),
			)
		);

		if ( is_wp_error( $created ) ) {
			return $this->failure( $created );
		}

		$row = ( new TermRepository() )->get( (int) $created );

		return new WP_REST_Response( $row ? $this->shape( $row ) : array( 'id' => (int) $created ), 201 );
	}

	public function update( WP_REST_Request $request, string $type ) {
		$id   = (int) ( $request->get_url_params()['id'] ?? 0 );
		$repo = new TermRepository();
		$row  = $id > 0 ? $repo->get( $id ) : null;

		if ( ! $row || (string) $row['type'] !== $type ) {
			return $this->not_found();
		}

		$data = array();

		foreach ( array( 'name', 'slug', 'description' ) as $field ) {
			if ( $request->has_param( $field ) ) {
				$data[ $field ] = (string) $request->get_param( $field );
			}
		}

		if ( array() === $data ) {
			return new WP_Error(
				'nowcrm_invalid_request',
				__( '沒有可更新的欄位。', 'now-crm' ),
				array( 'status' => 400 )
			);
		}

		$updated = $repo->update( $id, $data );

		if ( is_wp_error( $updated ) ) {
			return $this->failure( $updated );
		}

		$fresh = $repo->get( $id );

		return new WP_REST_Response( $fresh ? $this->shape( $fresh ) : array( 'id' => $id ), 200 );
	}

	public function delete( WP_REST_Request $request, string $type ) {
		$id   = (int) ( $request->get_url_params()['id'] ?? 0 );
		$repo = new TermRepository();
		$row  = $id > 0 ? $repo->get( $id ) : null;

		if ( ! $row || (string) $row['type'] !== $type ) {
			return $this->not_found();
		}

		if ( ! $repo->delete( $id, $type ) ) {
			return new WP_Error(
				'nowcrm_db_error',
				__( '無法刪除名單／標籤。', 'now-crm' ),
				array( 'status' => 500 )
			);
		}

		return new WP_REST_Response(
			array(
				'deleted' => true,
				'id'      => $id,
				'type'    => $type,
			),
			200
		);
	}

	public function bulk_save( WP_REST_Request $request, string $type ) {
		$items = $request->get_param( 'items' );

		if ( ! is_array( $items ) || array() === $items || count( $items ) > self::MAX_ITEMS ) {
			return $this->invalid_payload( __( '批次項目格式不正確。', 'now-crm' ) );
		}

		$normalized = array();

		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				return $this->invalid_payload( __( '批次項目格式不正確。', 'now-crm' ) );
			}

			foreach ( array( 'name', 'title', 'slug', 'description' ) as $field ) {
				if ( isset( $item[ $field ] ) && ! is_scalar( $item[ $field ] ) ) {
					return $this->invalid_payload( __( '批次項目格式不正確。', 'now-crm' ) );
				}
			}

			$normalized[] = array(
				'name'        => (string) ( $item['name'] ?? $item['title'] ?? '' ),
				'slug'        => (string) ( $item['slug'] ?? '' ),
				'description' => (string) ( $item['description'] ?? '' ),
			);
		}

		$result = ( new TermRepository() )->create_many( $type, $normalized );

		return new WP_REST_Response(
			array(
				'created' => (int) $result['created'],
				'updated' => (int) $result['updated'],
				'skipped' => (int) $result['skipped'],
			),
			200
		);
	}

	public function bulk_delete( WP_REST_Request $request, string $type ) {
		$raw = $request->get_param( 'ids' );

		if ( ! is_array( $raw ) || array() === $raw || count( $raw ) > self::MAX_IDS ) {
			return $this->invalid_payload( __( '批次刪除格式不正確。', 'now-crm' ) );
		}

		$ids = array();

		foreach ( $raw as $id ) {
			$value = is_scalar( $id )
				? filter_var( $id, FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1 ) ) )
				: false;

			if ( false === $value ) {
				return $this->invalid_payload( __( '批次刪除格式不正確。', 'now-crm' ) );
			}

			$ids[] = (int) $value;
		}

		$deleted = ( new TermRepository() )->delete_many( $type, $ids );

		return new WP_REST_Response(
			array(
				'deleted'   => $deleted,
				'requested' => count( $ids ),
			),
			200
		);
	}

	/**
	 * Stable response shape shared by every endpoint.
	 *
	 * Counts are present when the listing query supplies them; single-term
	 * reads return null because the repository has no single-term count query.
	 *
	 * @param array<string, mixed> $row Term row.
	 * @return array<string, mixed>
	 */
	public function shape( array $row ): array {
		return array(
			'id'               => (int) $row['id'],
			'type'             => (string) $row['type'],
			'name'             => (string) $row['name'],
			'slug'             => (string) $row['slug'],
			'description'      => (string) ( $row['description'] ?? '' ),
			'contact_count'    => isset( $row['contact_count'] ) ? (int) $row['contact_count'] : null,
			'subscribed_count' => isset( $row['subscribed_count'] ) ? (int) $row['subscribed_count'] : null,
			'created_at'       => (string) $row['created_at'],
			'updated_at'       => (string) $row['updated_at'],
		);
	}

	private function not_found(): WP_Error {
		return new WP_Error(
			'nowcrm_not_found',
			__( '找不到名單／標籤。', 'now-crm' ),
			array( 'status' => 404 )
		);
	}

	private function invalid_payload( string $message ): WP_Error {
		return new WP_Error( 'nowcrm_invalid_request', $message, array( 'status' => 400 ) );
	}

	/**
	 * Map repository errors to HTTP statuses without losing the error code.
	 */
	private function failure( WP_Error $error ): WP_Error {
		$statuses = array(
			'nowcrm_duplicate_term'    => 409,
			'nowcrm_not_found'         => 404,
			'nowcrm_invalid_term_type' => 400,
			'nowcrm_invalid_term_name' => 400,
			'nowcrm_invalid_term_slug' => 400,
			'nowcrm_db_error'          => 500,
		);

		$code = (string) $error->get_error_code();

		return new WP_Error(
			$code,
			$error->get_error_message(),
			array( 'status' => $statuses[ $code ] ?? 400 )
		);
	}
}
