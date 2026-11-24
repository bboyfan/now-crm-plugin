<?php
/**
 * Bulk actions for the workflow catalogs.
 *
 * Each handler validates the capability, the nonce, the operation, and the
 * exact selection. Row state and object capability are checked again before
 * a write. No selection never mutates data.
 *
 * @package NowCRM
 */

namespace NowCRM\Admin;

use NowCRM\Automation\AutomationRepository;
use NowCRM\Campaign\Campaigns;
use NowCRM\Sequences\SequenceRepository;
use NowCRM\Support\Capabilities;
use NowCRM\Templates\TemplateRepository;
use WP_Error;

defined( 'ABSPATH' ) || exit;

final class WorkflowCatalogActions {

	public const CAMPAIGN_ACTION   = 'nowcrm_bulk_campaigns';
	public const AUTOMATION_ACTION = 'nowcrm_bulk_automations';
	public const SEQUENCE_ACTION   = 'nowcrm_bulk_sequences';
	public const TEMPLATE_ACTION   = 'nowcrm_bulk_delete_templates';

	/**
	 * Campaign states that a bulk trash may touch.
	 *
	 * Scheduled, sending, and paused campaigns keep queue semantics, so the
	 * list withholds them and the handler rejects them.
	 */
	public const CAMPAIGN_BULK_STATES = array(
		Campaigns::STATUS_DRAFT,
		Campaigns::STATUS_SENT,
		Campaigns::STATUS_CANCELLED,
	);

	/**
	 * Automation statuses that a bulk status change may set.
	 */
	public const AUTOMATION_BULK_STATUSES = array(
		AutomationRepository::STATUS_DRAFT,
		AutomationRepository::STATUS_ACTIVE,
		AutomationRepository::STATUS_PAUSED,
	);

	/**
	 * Register the campaign, automation, and sequence routes.
	 *
	 * The template bulk route stays registered by TemplateListPage so that its
	 * public method keeps working; that method delegates here.
	 */
	public static function register(): void {
		add_action( 'admin_post_' . self::CAMPAIGN_ACTION, array( __CLASS__, 'campaigns' ) );
		add_action( 'admin_post_' . self::AUTOMATION_ACTION, array( __CLASS__, 'automations' ) );
		add_action( 'admin_post_' . self::SEQUENCE_ACTION, array( __CLASS__, 'sequences' ) );
	}

	/**
	 * Move selected campaigns to the trash after a locked state check.
	 */
	public static function campaigns(): void {
		self::guard( self::CAMPAIGN_ACTION );

		$filters = self::filters( 'campaign' );
		$operation = sanitize_key( self::post_string( 'bulk_operation' ) );

		if ( 'trash' !== $operation ) {
			self::redirect( Menu::CAMPAIGNS_SLUG, array( 'nowcrm_notice' => 'invalid_operation' ), $filters );
		}

		$ids = self::posted_ids( 'campaign_ids' );

		if ( ! $ids ) {
			self::redirect( Menu::CAMPAIGNS_SLUG, array( 'nowcrm_notice' => 'no_selection' ), $filters );
		}

		$deleted = 0;
		$skipped = 0;

		foreach ( $ids as $id ) {
			if ( is_wp_error( self::trash_campaign( $id ) ) ) {
				++$skipped;
			} else {
				++$deleted;
			}
		}

		self::redirect(
			Menu::CAMPAIGNS_SLUG,
			array(
				'nowcrm_notice'  => 'bulk_deleted',
				'nowcrm_deleted' => $deleted,
				'nowcrm_skipped' => $skipped,
			),
			$filters
		);
	}

	/**
	 * Move one campaign to the trash after a locked capability and state check.
	 *
	 * Both the list row action and the bulk handler use this path, so a live
	 * campaign cannot be trashed through the single action either.
	 *
	 * @param int $id Campaign ID.
	 * @return true|WP_Error
	 */
	public static function trash_campaign( int $id ) {
		$post_type = get_post_type_object( Campaigns::POST_TYPE );

		if (
			$id < 1
			|| ! Capabilities::can_manage_contacts()
			|| ! $post_type
			|| ! Campaigns::get( $id )
			|| ! current_user_can( $post_type->cap->delete_post, $id )
		) {
			return new WP_Error( 'nowcrm_campaign_not_found', __( '找不到電子報，或您沒有刪除權限。', 'now-crm' ) );
		}

		return Campaigns::transaction(
			$id,
			static function ( \WP_Post $post ) use ( $id, $post_type ) {
				unset( $post );

				if ( ! current_user_can( $post_type->cap->delete_post, $id ) ) {
					return new WP_Error( 'nowcrm_campaign_forbidden', __( '您沒有刪除這封電子報的權限。', 'now-crm' ) );
				}

				if ( ! in_array( Campaigns::state( $id ), WorkflowCatalogActions::CAMPAIGN_BULK_STATES, true ) ) {
					return new WP_Error( 'nowcrm_campaign_state', __( '寄送中、已排程或已暫停的電子報不能移至回收桶。', 'now-crm' ) );
				}

				return wp_trash_post( $id )
					? true
					: new WP_Error( 'nowcrm_campaign_delete', __( '無法將電子報移至回收桶。', 'now-crm' ) );
			}
		);
	}

	/**
	 * Change the status of, or delete, selected automations.
	 */
	public static function automations(): void {
		self::guard( self::AUTOMATION_ACTION );

		$filters   = self::filters( 'automation' );
		$operation = sanitize_key( self::post_string( 'bulk_operation' ) );

		if ( ! in_array( $operation, array( 'status', 'delete' ), true ) ) {
			self::redirect( Menu::AUTOMATIONS_SLUG, array( 'nowcrm_notice' => 'invalid_operation' ), $filters );
		}

		$ids = self::posted_ids( 'automation_ids' );

		if ( ! $ids ) {
			self::redirect( Menu::AUTOMATIONS_SLUG, array( 'nowcrm_notice' => 'no_selection' ), $filters );
		}

		$repository = new AutomationRepository();
		$done       = 0;
		$skipped    = 0;

		if ( 'delete' === $operation ) {
			foreach ( $ids as $id ) {
				if ( $repository->delete( $id ) ) {
					++$done;
				} else {
					++$skipped;
				}
			}

			self::redirect(
				Menu::AUTOMATIONS_SLUG,
				array(
					'nowcrm_notice'  => 'bulk_deleted',
					'nowcrm_deleted' => $done,
					'nowcrm_skipped' => $skipped,
				),
				$filters
			);
		}

		$status = sanitize_key( self::post_string( 'bulk_status' ) );

		if ( ! in_array( $status, self::AUTOMATION_BULK_STATUSES, true ) ) {
			self::redirect( Menu::AUTOMATIONS_SLUG, array( 'nowcrm_notice' => 'invalid_operation' ), $filters );
		}

		foreach ( $ids as $id ) {
			$result = $repository->set_status( $id, $status );

			if ( is_wp_error( $result ) ) {
				++$skipped;
			} else {
				++$done;
			}
		}

		self::redirect(
			Menu::AUTOMATIONS_SLUG,
			array(
				'nowcrm_notice'  => 'bulk_status',
				'nowcrm_deleted' => $done,
				'nowcrm_skipped' => $skipped,
			),
			$filters
		);
	}

	/**
	 * Delete selected sequences through the existing cancel-and-delete path.
	 */
	public static function sequences(): void {
		self::guard( self::SEQUENCE_ACTION );

		$filters   = self::filters( 'sequence' );
		$operation = sanitize_key( self::post_string( 'bulk_operation' ) );

		if ( 'delete' !== $operation ) {
			self::redirect( Menu::SEQUENCES_SLUG, array( 'nowcrm_notice' => 'invalid_operation' ), $filters );
		}

		$ids = self::posted_ids( 'sequence_ids' );

		if ( ! $ids ) {
			self::redirect( Menu::SEQUENCES_SLUG, array( 'nowcrm_notice' => 'no_selection' ), $filters );
		}

		$repository = new SequenceRepository();
		$post_type  = get_post_type_object( SequenceRepository::POST_TYPE );
		$deleted    = 0;
		$skipped    = 0;

		foreach ( $ids as $id ) {
			if ( ! $repository->get( $id ) ) {
				++$skipped;
				continue;
			}

			if ( $post_type && ! current_user_can( $post_type->cap->delete_post, $id ) ) {
				++$skipped;
				continue;
			}

			$result = $repository->delete( $id, true );

			if ( is_wp_error( $result ) ) {
				++$skipped;
			} else {
				++$deleted;
			}
		}

		self::redirect(
			Menu::SEQUENCES_SLUG,
			array(
				'nowcrm_notice'  => 'bulk_deleted',
				'nowcrm_deleted' => $deleted,
				'nowcrm_skipped' => $skipped,
			),
			$filters
		);
	}

	/**
	 * Delete selected templates, or all templates that match the filters.
	 *
	 * The filtered scope re-runs the server query with the same search and
	 * status. Unknown filters and selections above BULK_LIMIT fail closed.
	 */
	public static function templates(): void {
		self::guard( self::TEMPLATE_ACTION );

		$filters   = self::filters( 'template' );
		$operation = sanitize_key( self::post_string( 'bulk_operation' ) );

		if ( ! in_array( $operation, array( '', 'delete' ), true ) ) {
			self::redirect( Menu::TEMPLATES_SLUG, array( 'nowcrm_notice' => 'invalid_operation' ), $filters );
		}

		$scope = sanitize_key( self::post_string( 'scope' ) );
		$scope = '' === $scope ? 'page' : $scope;

		if ( ! in_array( $scope, array( 'page', 'all' ), true ) ) {
			self::redirect( Menu::TEMPLATES_SLUG, array( 'nowcrm_notice' => 'invalid_filter' ), $filters );
		}

		$status = sanitize_key( self::post_string( 'filter_status' ) );
		$status = '' === $status ? 'all' : $status;

		if ( ! in_array( $status, array( 'all', TemplateRepository::STATUS_DRAFT, TemplateRepository::STATUS_PUBLISH ), true ) ) {
			self::redirect( Menu::TEMPLATES_SLUG, array( 'nowcrm_notice' => 'invalid_filter' ), $filters );
		}

		if ( 'all' === $scope ) {
			$result = ( new TemplateRepository() )->paginate(
				array(
					'page'     => 1,
					'per_page' => TemplateRepository::BULK_LIMIT,
					'search'   => $filters['search'],
					'status'   => 'all' === $status ? '' : $status,
				)
			);

			if ( (int) $result['total'] > TemplateRepository::BULK_LIMIT ) {
				self::redirect(
					Menu::TEMPLATES_SLUG,
					array(
						'nowcrm_notice' => 'too_many',
						'nowcrm_limit'  => TemplateRepository::BULK_LIMIT,
					),
					$filters
				);
			}

			$ids = array_map(
				static function ( array $template ): int {
					return (int) $template['id'];
				},
				(array) $result['items']
			);
		} else {
			$ids = self::posted_ids( 'template_ids' );
		}

		if ( ! $ids ) {
			self::redirect( Menu::TEMPLATES_SLUG, array( 'nowcrm_notice' => 'no_selection' ), $filters );
		}

		if ( count( $ids ) > TemplateRepository::BULK_LIMIT ) {
			self::redirect(
				Menu::TEMPLATES_SLUG,
				array(
					'nowcrm_notice' => 'too_many',
					'nowcrm_limit'  => TemplateRepository::BULK_LIMIT,
				),
				$filters
			);
		}

		$deleted = self::delete_templates( $ids );

		self::redirect(
			Menu::TEMPLATES_SLUG,
			array(
				'nowcrm_notice'  => 'bulk_deleted',
				'nowcrm_deleted' => $deleted,
				'nowcrm_skipped' => count( $ids ) - $deleted,
			),
			$filters
		);
	}

	/**
	 * Delete template IDs through the repository's bounded, ACL-checked path.
	 *
	 * @param int[] $ids Template IDs.
	 */
	public static function delete_templates( array $ids ): int {
		$ids = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );

		if ( ! $ids ) {
			return 0;
		}

		return ( new TemplateRepository() )->delete_many( $ids, TemplateRepository::BULK_LIMIT );
	}

	/**
	 * Capability and nonce gate shared by every bulk handler.
	 *
	 * @param string $action Nonce action.
	 */
	private static function guard( string $action ): void {
		if ( ! Capabilities::can_manage_contacts() ) {
			wp_die( esc_html__( '您沒有執行此操作的權限。', 'now-crm' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( $action );
	}

	/**
	 * Read one scalar POST value.
	 *
	 * @param string $key Field name.
	 */
	private static function post_string( string $key ): string {
		return isset( $_POST[ $key ] ) && is_string( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : '';
	}

	/**
	 * Read, validate, and de-duplicate strict positive integer IDs.
	 *
	 * @param string $field Field name.
	 * @return int[]
	 */
	private static function posted_ids( string $field ): array {
		if ( ! isset( $_POST[ $field ] ) || ! is_array( $_POST[ $field ] ) ) {
			return array();
		}

		$ids = array();

		foreach ( wp_unslash( $_POST[ $field ] ) as $value ) {
			if ( ! is_scalar( $value ) ) {
				continue;
			}

			$id = filter_var(
				(string) $value,
				FILTER_VALIDATE_INT,
				array( 'options' => array( 'min_range' => 1 ) )
			);

			if ( false !== $id ) {
				$ids[ (int) $id ] = (int) $id;
			}
		}

		return array_values( $ids );
	}

	/**
	 * Safe list filters used by redirects.
	 *
	 * @param string $type campaign, automation, sequence, or template.
	 * @return array{search: string, status: string, page: int}
	 */
	private static function filters( string $type ): array {
		$search = sanitize_text_field( self::post_string( 'filter_search' ) );
		$status = sanitize_key( self::post_string( 'filter_status' ) );
		$page   = max( 1, absint( self::post_string( 'filter_paged' ) ) );
		$page   = $page > 1 ? $page : 1;

		$allowed = match ( $type ) {
			'campaign' => array(
				'all',
				Campaigns::STATUS_DRAFT,
				Campaigns::STATUS_SCHEDULED,
				Campaigns::STATUS_SENDING,
				Campaigns::STATUS_PAUSED,
				Campaigns::STATUS_SENT,
				Campaigns::STATUS_CANCELLED,
			),
			'automation', 'sequence' => array( 'all', 'draft', 'active', 'paused' ),
			'template' => array( 'all', TemplateRepository::STATUS_DRAFT, TemplateRepository::STATUS_PUBLISH ),
			default => array( 'all' ),
		};

		if ( '' === $status || ! in_array( $status, $allowed, true ) ) {
			$status = 'all';
		}

		return array(
			'search' => $search,
			'status' => $status,
			'page'   => $page,
		);
	}

	/**
	 * Redirect to the list with the notice and the safe filters.
	 *
	 * @param string               $slug    Menu slug.
	 * @param array<string, mixed> $notice  Notice arguments.
	 * @param array<string, mixed> $filters Safe filters.
	 */
	private static function redirect( string $slug, array $notice, array $filters ): void {
		$args = array();

		if ( '' !== (string) $filters['search'] ) {
			$args['s'] = (string) $filters['search'];
		}

		if ( 'all' !== (string) $filters['status'] ) {
			$args['status'] = (string) $filters['status'];
		}

		if ( (int) $filters['page'] > 1 ) {
			$args['paged'] = (int) $filters['page'];
		}

		wp_safe_redirect( Menu::page_url( $slug, array_merge( $args, $notice ) ) );
		exit;
	}
}
