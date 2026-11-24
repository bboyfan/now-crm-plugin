<?php
/**
 * The campaign workspace REST contract.
 *
 * @package NowCRM
 */

namespace NowCRM\Campaign;

use NowCRM\Admin\CampaignPage;
use NowCRM\Admin\Menu;
use NowCRM\Email\EmailJobRepository;
use NowCRM\Email\EmailDesign;
use NowCRM\Email\EmailRenderer;
use NowCRM\Email\Tracking;
use NowCRM\Segments\SegmentRepository;
use NowCRM\Support\Settings;
use NowCRM\Templates\TemplateRepository;
use NowCRM\Terms\TermRepository;

defined( 'ABSPATH' ) || exit;

final class Routes {

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	public function routes(): void {
		$base = '/campaigns/(?P<id>[1-9]\d*)';
		register_rest_route(
			'nowcrm/v1',
			$base,
			array(
				$this->route( 'GET', 'read' ),
				$this->route( 'PUT', 'save', array(
					'revision' => array( 'type' => 'string', 'required' => true ),
					'draft'    => array( 'type' => 'object', 'required' => true ),
				) ),
				$this->route( 'POST', 'template', array(
					'template_id' => array( 'type' => 'integer', 'required' => true, 'minimum' => 1 ),
					'revision'    => array( 'type' => 'string', 'required' => true ),
				) ),
			)
		);
		register_rest_route(
			'nowcrm/v1',
			$base . '/preview',
			array(
				$this->route( 'GET', 'preview' ),
				// Unsaved preview. Draft-only and read-only: it never writes the
				// campaign. Send exactly one of html or blocks.
				$this->route( 'POST', 'preview', array(
					'html'   => array( 'type' => 'string' ),
					'blocks' => array( 'type' => 'string' ),
					'design' => array( 'type' => 'object' ),
				) ),
			)
		);
		register_rest_route( 'nowcrm/v1', $base . '/review', $this->route( 'GET', 'review', array(
			'revision' => array( 'type' => 'string', 'required' => true ),
		) ) );
		register_rest_route( 'nowcrm/v1', $base . '/test', $this->route( 'POST', 'test', array(
			'email' => array( 'type' => 'string', 'format' => 'email', 'required' => true ),
		) ) );
		register_rest_route( 'nowcrm/v1', $base . '/delivery', $this->route( 'POST', 'delivery', array(
			'op'       => array( 'type' => 'string', 'enum' => array( 'confirm', 'unschedule', 'pause', 'resume', 'cancel' ), 'required' => true ),
			'token'    => array( 'type' => 'string', 'default' => '' ),
			'schedule' => array( 'type' => 'string', 'default' => '' ),
			'schedule_end' => array( 'type' => 'string', 'default' => '' ),
		) ) );
		register_rest_route( 'nowcrm/v1', $base . '/duplicate', $this->route( 'POST', 'duplicate' ) );
		register_rest_route( 'nowcrm/v1', $base . '/recipient-options', $this->route( 'GET', 'recipient_options', array(
			'field'    => array( 'type' => 'string', 'required' => true ),
			'search'   => array( 'type' => 'string', 'default' => '' ),
			'selected' => array( 'type' => 'string', 'default' => '' ),
		) ) );
		register_rest_route( 'nowcrm/v1', $base . '/recipients', $this->route( 'POST', 'recipients', array(
			'audience'   => array( 'type' => 'object', 'required' => true ),
			'page'       => array( 'type' => 'integer', 'default' => 1 ),
			'count_only' => array( 'type' => 'boolean', 'default' => false ),
		) ) );
		register_rest_route(
			'nowcrm/v1',
			$base . '/recurring',
			array(
				$this->route( 'GET', 'recurring' ),
				$this->route(
					'PUT',
					'save_recurring',
					array(
						'status'    => array(
							'type' => 'string',
							'enum' => array( 'active', 'paused', 'inactive' ),
						),
						'frequency' => array(
							'type'     => 'string',
							'enum'     => array( 'daily', 'weekly', 'monthly' ),
							'required' => true,
						),
						'time'      => array(
							'type'     => 'string',
							'required' => true,
						),
						'day'       => array(
							'type'    => array( 'string', 'integer' ),
							'default' => '',
						),
						'auto_send' => array(
							'type'    => 'boolean',
							'default' => false,
						),
					)
				),
				$this->route(
					'POST',
					'recurring_action',
					array(
						'op' => array(
							'type'     => 'string',
							'enum'     => array( 'pause', 'resume', 'disable', 'generate' ),
							'required' => true,
						),
					)
				),
			)
		);
	}

	private function route( string $method, string $handler, array $args = array() ): array {
		return array(
			'methods'             => $method,
			'callback'            => array( $this, $handler ),
			'permission_callback' => static function ( \WP_REST_Request $request ): bool {
				return Campaigns::can_edit( self::id( $request ) );
			},
			'args'                => $args,
		);
	}

	private static function id( \WP_REST_Request $request ): int {
		return (int) $request->get_url_params()['id'];
	}

	public function read( \WP_REST_Request $request ) {
		$id = self::id( $request );
		$post = Campaigns::get( $id );
		if ( ! $post ) {
			return Campaigns::error( 'not_found', __( '找不到電子報。', 'now-crm' ), 404 );
		}

		$state = Campaigns::state( $id );
		$data = array(
			'id'              => $id,
			'title'           => $post->post_title,
			'state'           => $state,
			'status_label'    => Campaigns::status_label( $state ),
			'sending_enabled' => Settings::bool( 'email_sending_enabled' ),
			'tracking_enabled'=> Settings::bool( 'campaign_tracking_enabled' ),
		);

		if ( Campaigns::STATUS_DRAFT === $state ) {
			$draft = Campaigns::draft( $post );
			$data += array(
				'draft'    => $draft,
				'revision' => Campaigns::revision( $draft ),
				'lists'    => $this->terms( 'list' ),
				'tags'     => $this->terms( 'tag' ),
				'segments' => $this->segment_options(),
			);

			if ( 'GET' === $request->get_method() ) {
				$data['templates'] = $this->template_options();
				$data['advanced']  = RecipientFilters::catalog();
			}

			return $data;
		}

		$tracking = new Tracking();
		$stats = $tracking->campaign_stats( $id );
		$engagement = $tracking->campaign_engagement_stats( array( $id ) )[ $id ] ?? array();
		$jobs = ( new EmailJobRepository() )->campaign_stats( $id );
		$schedule = (string) get_post_meta( $id, Campaigns::META_SCHEDULE, true );
		$end = (string) get_post_meta( $id, Campaigns::META_SCHEDULE_END, true );
		$finished = (string) get_post_meta( $id, Campaigns::META_SENT_AT, true );
		$report = $data + array(
			'subject'  => Campaigns::template( $id )['subject'] ?? Campaigns::draft( $post )['subject'],
			'schedule' => $schedule ? get_date_from_gmt( $schedule, 'Y-m-d H:i' ) : '',
			'schedule_end' => $end ? get_date_from_gmt( $end, 'Y-m-d H:i' ) : '',
			'finished' => $finished ? get_date_from_gmt( $finished, 'Y-m-d H:i' ) : '',
			'jobs'     => $jobs,
			'links'    => $tracking->campaign_links( $id ),
			'metrics'  => array(
				'opened'      => $engagement['opened'] ?? 0,
				'clicked'     => $engagement['clicked'] ?? 0,
				'bounce'      => $stats['bounce'],
				'complaint'   => $stats['complaint'],
				'unsubscribe' => $stats['unsubscribe'],
			),
		);

		$variants = ( new Delivery() )->variant_stats( $id );
		if ( $variants ) {
			$report['variants'] = $variants;
		}

		return $report;
	}

	private function terms( string $type ): array {
		return array_map(
			static fn( array $term ): array => array( 'id' => (int) $term['id'], 'name' => (string) $term['name'] ),
			( new TermRepository() )->all( $type )
		);
	}

	/**
	 * Active system and custom segments selectable in the audience rules.
	 */
	private function segment_options(): array {
		return array_map(
			static fn( array $segment ): array => array(
				'ref'       => (string) $segment['ref'],
				'name'      => (string) $segment['name'],
				'is_system' => (bool) $segment['is_system'],
			),
			( new SegmentRepository() )->all( array( 'status' => 'active' ) )
		);
	}

	/**
	 * Published templates shown in the campaign editor.
	 */
	private function template_options(): array {
		return array_map(
			static fn( array $template ): array => array(
				'id'        => (int) $template['id'],
				'name'      => (string) $template['name'],
				'mode'      => (string) $template['mode'],
				'subject'   => (string) $template['subject'],
				'preheader' => (string) $template['preheader'],
			),
			( new TemplateRepository() )->published()
		);
	}

	/**
	 * Replace one draft campaign's content with a published template.
	 *
	 * The campaign keeps its own copy of the template fields, so later template
	 * edits never change this draft or the send snapshot.
	 */
	public function template( \WP_REST_Request $request ) {
		$id   = self::id( $request );
		$post = Campaigns::get( $id );

		if ( ! $post ) {
			return Campaigns::error( 'not_found', __( '找不到電子報。', 'now-crm' ), 404 );
		}

		if ( Campaigns::STATUS_DRAFT !== Campaigns::state( $id ) ) {
			return Campaigns::error( 'campaign_state', __( '只有草稿可以套用電子報範本。', 'now-crm' ), 409 );
		}

		$template = ( new TemplateRepository() )->for_campaign( (int) $request['template_id'] );

		if ( ! $template ) {
			return Campaigns::error( 'template_not_found', __( '找不到可用的電子報範本。', 'now-crm' ), 404 );
		}

		$draft              = Campaigns::draft( $post );
		$draft['mode']      = $template['mode'];
		$draft['subject']   = $template['subject'];
		$draft['preheader'] = $template['preheader'];

		// Replace only the body of the mode the template uses. The other saved
		// representation stays intact when the editor switches mode later.
		if ( Campaigns::MODE_HTML === $template['mode'] ) {
			$draft['html'] = $template['html'];
		} else {
			$draft['blocks'] = $template['blocks'];
		}

		$result = Campaigns::save( $id, $draft, (string) $request['revision'] );

		return is_wp_error( $result ) ? $result : $this->read( $request );
	}

	public function save( \WP_REST_Request $request ) {
		$result = Campaigns::save( self::id( $request ), $request['draft'], $request['revision'] );
		return is_wp_error( $result ) ? $result : $this->read( $request );
	}

	public function review( \WP_REST_Request $request ) {
		$id = self::id( $request );
		if ( Campaigns::STATUS_DRAFT !== Campaigns::state( $id ) ) {
			return Campaigns::error( 'campaign_state', __( '此電子報已確認寄送。', 'now-crm' ), 409 );
		}
		$review = ( new Delivery() )->review( Campaigns::get( $id ), $request['revision'] );
		if ( is_wp_error( $review ) ) {
			return $review;
		}
		$snapshot = $review['snapshot'];
		unset( $review['snapshot'] );
		return $review + array(
			'reply_to' => $snapshot['reply_to'],
			'preview'  => $this->render_preview( $snapshot ),
		);
	}

	public function preview( \WP_REST_Request $request ): array|\WP_Error {
		$id = self::id( $request );

		if ( 'POST' === $request->get_method() ) {
			$post = Campaigns::get( $id );
			if ( ! $post ) {
				return Campaigns::error( 'not_found', __( '找不到電子報。', 'now-crm' ), 404 );
			}
			if ( Campaigns::STATUS_DRAFT !== Campaigns::state( $id ) ) {
				return Campaigns::error( 'campaign_state', __( '只有草稿可以預覽未儲存的內容。', 'now-crm' ), 409 );
			}

			$draft         = Campaigns::draft( $post );
			$has_html      = $request->has_param( 'html' );
			$has_blocks    = $request->has_param( 'blocks' );

			if ( $has_html === $has_blocks ) {
				return Campaigns::error( 'invalid_preview', __( '請提供 HTML 或區塊正文其中一種預覽內容。', 'now-crm' ), 400 );
			}

			if ( $has_blocks ) {
				$blocks = Campaigns::sanitize_blocks( (string) $request['blocks'] );
				if ( is_wp_error( $blocks ) ) {
					return $blocks;
				}

				$draft['mode']   = Campaigns::MODE_BLOCKS;
				$draft['blocks'] = $blocks;
				if ( $request->has_param( 'design' ) ) {
					$draft['design'] = EmailDesign::normalize( $request['design'] );
				}

				return $this->render_preview( Campaigns::snapshot( $id, $draft ) );
			}

			$draft['mode'] = Campaigns::MODE_HTML;
			$draft['html'] = ( new EmailRenderer() )->sanitize_raw_html( (string) $request['html'] );

			return $this->render_preview( Campaigns::snapshot( $id, $draft ) );
		}

		$template = Campaigns::template( $id ) ?? Campaigns::snapshot( $id, Campaigns::draft( Campaigns::get( $id ) ) );
		return $this->render_preview( $template );
	}

	private function render_preview( array $template ): array {
		$user = wp_get_current_user();
		$rendered = ( new EmailRenderer() )->render(
			$template,
			array(
				'first_name'      => $user->first_name ?: __( '示範', 'now-crm' ),
				'last_name'       => $user->last_name,
				'email'           => $user->user_email,
				'unsubscribe_url' => '#unsubscribe',
				'manage_url'      => '#manage',
			)
		);
		$csp = '<meta http-equiv="Content-Security-Policy" content="default-src \'none\'; img-src https: http: data:; style-src \'unsafe-inline\'; base-uri \'none\'; form-action \'none\'">';
		return array(
			'subject' => $rendered['subject'],
			'html'    => preg_replace( '/<head>/i', '<head>' . $csp, $rendered['html'], 1 ),
		);
	}

	public function test( \WP_REST_Request $request ) {
		$result = ( new Delivery() )->test( Campaigns::get( self::id( $request ) ), $request['email'] );
		return is_wp_error( $result ) ? $result : new \WP_REST_Response( array( 'job_id' => $result ), 202 );
	}

	public function delivery( \WP_REST_Request $request ) {
		$id = self::id( $request );

		// A recurring campaign is a template. Its children carry the sends.
		if ( 'confirm' === $request['op'] && Recurring::settings( $id )['enabled'] ) {
			return Campaigns::error(
				'recurring_parent',
				__( '這封電子報是定期寄送範本，不會直接寄送。請在定期寄送面板產生或調整寄送。', 'now-crm' ),
				409
			);
		}

		$delivery = new Delivery();
		$result = 'confirm' === $request['op']
			? $delivery->confirm( $id, $request['token'], $request['schedule'], $request['schedule_end'] )
			: $delivery->transition( $id, $request['op'] );

		return is_wp_error( $result ) ? $result : $this->read( $request );
	}

	public function duplicate( \WP_REST_Request $request ) {
		$type = get_post_type_object( Campaigns::POST_TYPE );
		if ( ! $type || ! current_user_can( $type->cap->create_posts ) ) {
			return Campaigns::error( 'campaign_forbidden', __( '您沒有建立電子報的權限。', 'now-crm' ), 403 );
		}
		$id = Campaigns::duplicate( Campaigns::get( self::id( $request ) ) );
		return is_wp_error( $id ) ? $id : new \WP_REST_Response( array( 'url' => CampaignPage::url( $id ) ), 201 );
	}

	/**
	 * Bounded option rows for one lookup field in the advanced rule picker.
	 *
	 * Draft-only and read-only: unknown fields return 422, and saved IDs ride
	 * along with the search hits so a stored value stays visible.
	 */
	public function recipient_options( \WP_REST_Request $request ) {
		$post = $this->draft_post( self::id( $request ) );
		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$field = mb_substr( sanitize_text_field( (string) $request->get_param( 'field' ) ), 0, 120 );
		$catalog = RecipientFilters::catalog();
		$fields = isset( $catalog['fields'] ) && is_array( $catalog['fields'] ) ? $catalog['fields'] : array();
		if ( '' === $field || ! isset( $fields[ $field ] ) ) {
			return Campaigns::error( 'invalid_field', __( '不支援的篩選欄位。', 'now-crm' ) );
		}

		$selected = array();
		foreach ( explode( ',', (string) $request->get_param( 'selected' ) ) as $piece ) {
			$piece = trim( (string) $piece );
			if ( '' !== $piece ) {
				$selected[] = mb_substr( $piece, 0, 120 );
			}
		}
		$selected = array_slice( array_values( array_unique( $selected ) ), 0, 100 );
		$search = mb_substr( sanitize_text_field( (string) $request->get_param( 'search' ) ), 0, 80 );

		$options = RecipientFilters::options( $field, $search, $selected );
		if ( is_wp_error( $options ) ) {
			$message = $options->get_error_message();
			return Campaigns::error( 'invalid_field', '' !== $message ? $message : __( '不支援的篩選欄位。', 'now-crm' ) );
		}

		$list = isset( $options['options'] ) && is_array( $options['options'] ) ? $options['options'] : (array) $options;
		$rows = array();
		foreach ( array_slice( $list, 0, 100 ) as $option ) {
			if ( ! is_array( $option ) ) {
				continue;
			}
			$rows[] = array(
				'value' => (string) ( $option['value'] ?? '' ),
				'label' => (string) ( $option['label'] ?? '' ),
			);
		}

		return array( 'options' => $rows );
	}

	/**
	 * Paged recipient estimate for unsaved audience rules.
	 *
	 * Draft-only and read-only: the audience validates but never saves, and
	 * the frozen snapshot and revision never move. `count_only` skips the page
	 * query and answers with just the total.
	 */
	public function recipients( \WP_REST_Request $request ) {
		$post = $this->draft_post( self::id( $request ) );
		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$audience = $request->get_param( 'audience' );
		if ( ! is_array( $audience ) ) {
			return Campaigns::error( 'invalid_audience', __( '請檢查收件規則。', 'now-crm' ) );
		}
		$validated = Audience::validate( $audience );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}

		if ( true === $request->get_param( 'count_only' ) ) {
			$total = Audience::count_recipients( $validated );

			return null === $total ? Campaigns::storage_error() : array( 'count' => $total );
		}

		return Audience::preview_page( $validated, (int) $request->get_param( 'page' ) );
	}

	/**
	 * Draft post for the recipient endpoints, with the state guard.
	 *
	 * @return \WP_Post|\WP_Error
	 */
	private function draft_post( int $id ) {
		$post = Campaigns::get( $id );
		if ( ! $post ) {
			return Campaigns::error( 'not_found', __( '找不到電子報。', 'now-crm' ), 404 );
		}
		if ( Campaigns::STATUS_DRAFT !== Campaigns::state( $id ) ) {
			return Campaigns::error( 'campaign_state', __( '只有草稿可以編輯收件人。', 'now-crm' ), 409 );
		}
		return $post;
	}

	/**
	 * Recurring schedule state for one campaign.
	 */

	public function recurring( \WP_REST_Request $request ): array {
		return $this->recurring_payload( self::id( $request ) );
	}

	/**
	 * Validate and store the recurring schedule.
	 *
	 * @return array|\WP_Error
	 */
	public function save_recurring( \WP_REST_Request $request ) {
		$id = self::id( $request );
		// A schedule save is not an activation. Omit status to keep whatever
		// another tab last stored; only explicit status calls change it.
		$status = $request->has_param( 'status' )
			? (string) $request['status']
			: Recurring::settings( $id )['status'];

		if ( ! $request->has_param( 'status' ) && '' === (string) get_post_meta( $id, Recurring::META_STATUS, true ) ) {
			$status = Recurring::STATUS_ACTIVE;
		}

		$result = Recurring::save(
			$id,
			array(
				'status'    => $status,
				'frequency' => (string) $request['frequency'],
				'time'      => (string) $request['time'],
				'day'       => is_scalar( $request['day'] ) ? (string) $request['day'] : '',
				'auto_send' => true === $request['auto_send'],
			)
		);

		return is_wp_error( $result ) ? $result : $this->recurring_payload( $id );
	}

	/**
	 * Pause, resume, disable, or generate one child now.
	 *
	 * @return array|\WP_Error|\WP_REST_Response
	 */
	public function recurring_action( \WP_REST_Request $request ) {
		$id = self::id( $request );
		$op = (string) $request['op'];

		if ( 'generate' === $op ) {
			$child = Recurring::generate_now( $id );

			return is_wp_error( $child )
				? $child
				: new \WP_REST_Response( $this->recurring_payload( $id ) + array( 'child_id' => $child ), 201 );
		}

		$result = match ( $op ) {
			'pause'  => Recurring::pause( $id ),
			'resume' => Recurring::resume( $id ),
			default  => Recurring::disable( $id ),
		};

		return is_wp_error( $result ) ? $result : $this->recurring_payload( $id );
	}

	/**
	 * Response shape shared by the recurring endpoints.
	 *
	 * @return array<string, mixed>
	 */
	private function recurring_payload( int $id ): array {
		$children = array();

		foreach ( Recurring::children( $id, 20 ) as $child_id ) {
			$child = Campaigns::get( $child_id );

			if ( ! $child || ! Campaigns::can_edit( $child_id ) ) {
				continue;
			}

			$state = Campaigns::state( $child_id );
			$children[] = array(
				'id'           => $child_id,
				'title'        => $child->post_title,
				'status'       => $state,
				'status_label' => Campaigns::status_label( $state ),
				'created'      => (string) get_post_time( 'Y-m-d H:i', false, $child ),
				'url'          => Campaigns::STATUS_DRAFT === $state
					? CampaignPage::url( $child_id )
					: Menu::page_url( Menu::EMAIL_SLUG, array( 'campaign_id' => $child_id, 'kind' => 'campaign' ) ),
			);
		}

		return array(
			'campaign_id' => $id,
			'is_draft'    => Campaigns::STATUS_DRAFT === Campaigns::state( $id ),
			'is_child'    => '' !== (string) get_post_meta( $id, Recurring::META_PARENT, true ),
			'recurring'   => Recurring::summary( $id ),
			'children'    => $children,
			'child_count' => Recurring::child_count( $id ),
		);
	}
}
