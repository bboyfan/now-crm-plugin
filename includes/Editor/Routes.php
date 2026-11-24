<?php
/**
 * Content save endpoints used by the native editor screen.
 *
 * The block editor saves into a private working post. These endpoints are the
 * only bridge that writes the edited title/content back into a campaign or a
 * template, so Campaigns::save() revision checks stay authoritative.
 *
 * @package NowCRM
 */

namespace NowCRM\Editor;

use NowCRM\Campaign\Campaigns;
use NowCRM\Templates\TemplateRepository;

defined( 'ABSPATH' ) || exit;

final class Routes {

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	public function routes(): void {
		register_rest_route(
			'nowcrm/v1',
			'/campaigns/(?P<id>[1-9]\d*)/content',
			array(
				'methods'             => 'PUT',
				'callback'            => array( $this, 'campaign' ),
				'permission_callback' => static fn( \WP_REST_Request $request ): bool => Campaigns::can_edit( (int) $request['id'] ),
				'args'                => $this->args(),
			)
		);
		register_rest_route(
			'nowcrm/v1',
			'/templates/(?P<id>[1-9]\d*)/content',
			array(
				'methods'             => 'PUT',
				'callback'            => array( $this, 'template' ),
				'permission_callback' => static function ( \WP_REST_Request $request ): bool {
					return ( new TemplateRepository() )->can_edit( (int) $request['id'] );
				},
				'args'                => $this->args(),
			)
		);
	}

	private function args(): array {
		return array(
			'title'    => array( 'type' => 'string', 'default' => '' ),
			'blocks'   => array( 'type' => 'string', 'required' => true ),
			'revision' => array( 'type' => 'string', 'required' => true ),
		);
	}

	public function campaign( \WP_REST_Request $request ) {
		$id   = (int) $request['id'];
		$post = Campaigns::get( $id );

		if ( ! $post ) {
			return Campaigns::error( 'not_found', __( '找不到電子報。', 'now-crm' ), 404 );
		}
		if ( Campaigns::STATUS_DRAFT !== Campaigns::state( $id ) ) {
			return Campaigns::error( 'campaign_state', __( '只有草稿可以編輯內容。', 'now-crm' ), 409 );
		}

		$draft = Campaigns::draft( $post );
		$title = sanitize_text_field( (string) $request['title'] );
		$draft['title'] = '' !== $title ? $title : $draft['title'];
		$draft['blocks'] = (string) $request['blocks'];

		$result = Campaigns::save( $id, $draft, (string) $request['revision'] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$updated = Campaigns::draft( Campaigns::get( $id ) );

		return array(
			'title'    => $updated['title'],
			'blocks'   => $updated['blocks'],
			'revision' => Campaigns::revision( $updated ),
		);
	}

	public function template( \WP_REST_Request $request ) {
		$id   = (int) $request['id'];
		$repo = new TemplateRepository();
		$template = $repo->get( $id );

		if ( ! $template ) {
			return $repo->error( 'not_found', __( '找不到電子報範本。', 'now-crm' ), 404 );
		}

		$current = $repo->revision( $template );
		if ( ! hash_equals( $current, (string) $request['revision'] ) ) {
			return $repo->error( 'changed', __( '範本已變更。請重新整理後再編輯。', 'now-crm' ), 409 );
		}

		$title = sanitize_text_field( (string) $request['title'] );
		$result = $repo->update(
			$id,
			array(
				'name'   => '' !== $title ? $title : $template['name'],
				'blocks' => (string) $request['blocks'],
			)
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$updated = $repo->get( $id );

		return array(
			'title'    => $updated['name'],
			'blocks'   => $updated['blocks'],
			'revision' => $repo->revision( $updated ),
		);
	}
}
