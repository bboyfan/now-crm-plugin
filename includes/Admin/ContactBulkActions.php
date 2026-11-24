<?php
/**
 * admin-post handler and result notice for contact bulk actions.
 *
 * @package NowCRM
 */

namespace NowCRM\Admin;

use NowCRM\Contacts\AdvancedFilters;
use NowCRM\Contacts\BulkContactAction;
use NowCRM\Contacts\Status;
use NowCRM\Support\Capabilities;
use NowCRM\Terms\TermRepository;

defined( 'ABSPATH' ) || exit;

final class ContactBulkActions {

	/**
	 * admin-post action and nonce action.
	 */
	public const ACTION = 'nowcrm_bulk_contacts';
	public const NONCE  = 'nowcrm_bulk_contacts';

	/**
	 * Register the handler. Menu::register() owns the admin-post registration.
	 */
	public static function register(): void {
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle' ) );
	}

	/**
	 * Validate and run one bulk action, then redirect back to the list.
	 */
	public static function handle(): void {
		if ( ! Capabilities::can_manage_contacts() ) {
			wp_die(
				esc_html__( '您沒有權限執行此操作。', 'now-crm' ),
				'',
				array( 'response' => 403 )
			);
		}

		check_admin_referer( self::NONCE );

		$operation = sanitize_key( self::post_raw( 'bulk_operation' ) );
		$page_args = self::page_args();

		if ( ! in_array( $operation, BulkContactAction::operations(), true ) ) {
			self::redirect(
				$page_args,
				array(
					'nowcrm_bulk'          => 'invalid',
					'nowcrm_bulk_reason'   => 'operation',
					'nowcrm_bulk_operation' => $operation,
				)
			);
		}

		$term_type = BulkContactAction::term_type( $operation );
		$term_ids  = array();

		if ( TermRepository::TYPE_LIST === $term_type ) {
			$term_ids = self::post_ids( 'bulk_list_ids' );
		} elseif ( TermRepository::TYPE_TAG === $term_type ) {
			$term_ids = self::post_ids( 'bulk_tag_ids' );
		}

		$scope = 'all' === self::post_raw( 'bulk_scope' ) ? 'all' : 'selected';

		$filters = array(
			'search'  => sanitize_text_field( self::post_raw( 'filter_search' ) ),
			'status'  => sanitize_key( self::post_raw( 'filter_status' ) ),
			'term_id' => absint( self::post_raw( 'filter_term' ) ),
		);

		// A rejected plan stops the whole run. Dropping it would widen a
		// filter-wide delete to every contact.
		$advanced = AdvancedFilters::from_json( self::post_raw( ContactAdvancedFilters::TRANSPORT ) );

		if ( is_wp_error( $advanced ) ) {
			self::redirect(
				$page_args,
				array(
					'nowcrm_bulk'          => 'invalid',
					'nowcrm_bulk_reason'   => 'filter',
					'nowcrm_bulk_operation' => $operation,
				)
			);
		}

		if ( null !== $advanced ) {
			$filters['filters'] = $advanced;
		}

		$target_status = sanitize_key( self::post_raw( 'bulk_status' ) );
		$expected      = absint( self::post_raw( 'expected_total' ) );

		if ( 'all' === $scope ) {
			self::handle_all( $operation, $page_args, $filters, $term_ids, $target_status, $expected );
		}

		$ids = self::post_ids( 'contact_ids' );

		if ( array() === $ids ) {
			self::redirect(
				$page_args,
				array(
					'nowcrm_bulk'          => 'invalid',
					'nowcrm_bulk_reason'   => 'selection',
					'nowcrm_bulk_operation' => $operation,
				)
			);
		}

		$result = ( new BulkContactAction() )->run(
			array(
				'operation'      => $operation,
				'scope'          => 'selected',
				'ids'            => $ids,
				'filters'        => $filters,
				'expected_total' => $expected,
				'term_ids'       => $term_ids,
				'status'         => $target_status,
				'actor_id'       => get_current_user_id(),
			)
		);

		if ( 'invalid' === $result['status'] ) {
			self::redirect( $page_args, array( 'nowcrm_notice' => self::notice_for_reason( $result['reason'] ) ) );
		}

		self::redirect( $page_args, self::result_args( $result, $operation ) );
	}

	/**
	 * Run one filter-wide batch and return JSON for the client loop.
	 *
	 * The first request fixes the ID ceiling and the destructive count check.
	 * Later requests carry a signed run token and are pinned to the stored
	 * operation, filters, ceiling, and cursor.
	 *
	 * @param array<string, mixed> $page_args     Current list filters.
	 * @param array<string, mixed> $filters       search, status, term_id.
	 * @param int[]                $term_ids      Validated term IDs.
	 */
	private static function handle_all(
		string $operation,
		array $page_args,
		array $filters,
		array $term_ids,
		string $target_status,
		int $expected
	): void {
		if ( '1' !== self::post_raw( 'nowcrm_bulk_batch' ) ) {
			self::redirect(
				$page_args,
				array(
					'nowcrm_bulk'          => 'invalid',
					'nowcrm_bulk_reason'   => 'batch',
					'nowcrm_bulk_operation' => $operation,
				)
			);
		}

		$token = self::post_raw( 'nowcrm_bulk_token' );
		$state = '' === $token ? null : self::load_run( $token );

		if ( '' !== $token && null === $state ) {
			self::json_failure(
				__( '批次工作階段已失效，請重新整理後再試。', 'now-crm' ),
				409,
				Menu::page_url(
					Menu::SLUG,
					array(
						'nowcrm_bulk'        => 'invalid',
						'nowcrm_bulk_reason' => 'expired',
					)
				)
			);
		}

		if ( null !== $state ) {
			$operation     = (string) $state['operation'];
			$filters       = (array) $state['filters'];
			$term_ids      = (array) $state['term_ids'];
			$target_status = (string) $state['status'];
			$expected      = (int) $state['expected_total'];
			$page_args     = (array) $state['page_args'];
			$cursor        = (int) $state['cursor'];
			$max_id        = (int) $state['max_id'];
			$totals        = (array) $state['totals'];
		} else {
			$cursor = 0;
			$max_id = null;
			$totals = self::empty_totals( $expected );
		}

		$request = array(
			'operation'      => $operation,
			'scope'          => 'all',
			'filters'        => $filters,
			'expected_total' => $expected,
			'term_ids'       => $term_ids,
			'status'         => $target_status,
			'actor_id'       => get_current_user_id(),
			'cursor'         => $cursor,
		);

		if ( null !== $max_id ) {
			$request['max_id'] = $max_id;
		}

		$result = ( new BulkContactAction() )->run( $request );

		if ( 'invalid' === $result['status'] ) {
			if ( null !== $state ) {
				self::clear_run( (string) $state['run_id'] );
			}

			self::json_success(
				array(
					'completed' => true,
					'redirect'  => Menu::page_url(
						Menu::SLUG,
						array_merge( $page_args, array( 'nowcrm_notice' => self::notice_for_reason( $result['reason'] ) ) )
					),
				)
			);
		}

		if ( 'changed' === $result['status'] || 'empty' === $result['status'] ) {
			if ( null !== $state ) {
				self::clear_run( (string) $state['run_id'] );
			}

			self::json_success(
				array(
					'completed' => true,
					'redirect'  => Menu::page_url( Menu::SLUG, array_merge( $page_args, self::result_args( $result, $operation ) ) ),
				)
			);
		}

		if ( null === $state ) {
			$totals['matching']       = (int) $result['matching'];
			$totals['expected_total'] = $expected;
		}

		$totals['processed'] += (int) $result['processed'];
		$totals['skipped']   += (int) $result['skipped'];
		$totals['blocked']   += (int) $result['blocked'];
		$totals['errors']    += (int) $result['errors'];

		foreach ( (array) $result['blocked_ids'] as $blocked_id ) {
			$blocked_id = absint( $blocked_id );

			if ( $blocked_id > 0 && count( $totals['blocked_ids'] ) < 20 && ! in_array( $blocked_id, $totals['blocked_ids'], true ) ) {
				$totals['blocked_ids'][] = $blocked_id;
			}
		}

		if ( ! empty( $result['completed'] ) ) {
			if ( null !== $state ) {
				self::clear_run( (string) $state['run_id'] );
			}

			$result['processed']      = $totals['processed'];
			$result['skipped']        = $totals['skipped'];
			$result['blocked']        = $totals['blocked'];
			$result['blocked_ids']    = $totals['blocked_ids'];
			$result['errors']         = $totals['errors'];
			$result['matching']       = $totals['matching'];
			$result['expected_total'] = $totals['expected_total'];

			self::json_success(
				array(
					'completed' => true,
					'processed' => $totals['processed'],
					'matching'  => $totals['matching'],
					'redirect'  => Menu::page_url( Menu::SLUG, array_merge( $page_args, self::result_args( $result, $operation ) ) ),
				)
			);
		}

		$run_id = null === $state ? bin2hex( random_bytes( 16 ) ) : (string) $state['run_id'];
		$cursor = (int) $result['cursor'];

		$state = array(
			'run_id'         => $run_id,
			'user_id'        => get_current_user_id(),
			'operation'      => $operation,
			'filters'        => $filters,
			'term_ids'       => $term_ids,
			'status'         => $target_status,
			'expected_total' => $expected,
			'page_args'      => $page_args,
			'cursor'         => $cursor,
			'max_id'         => (int) $result['max_id'],
			'totals'         => $totals,
			'updated_at'     => time(),
		);

		set_transient( self::run_key( $run_id ), $state, 15 * MINUTE_IN_SECONDS );

		self::json_success(
			array(
				'completed' => false,
				'token'     => self::sign_run( $run_id, (int) $state['user_id'], $cursor ),
				'processed' => $totals['processed'],
				'matching'  => $totals['matching'],
				'progress'  => sprintf(
					/* translators: 1: processed contacts, 2: matching contacts. */
					__( '已處理 %1$d / %2$d 位聯絡人…', 'now-crm' ),
					$totals['processed'] + $totals['skipped'] + $totals['blocked'] + $totals['errors'],
					$totals['matching']
				),
			)
		);
	}

	/**
	 * Load a run from a signed token, scoped to the current user and cursor.
	 *
	 * @return array<string, mixed>|null
	 */
	private static function load_run( string $token ): ?array {
		$payload = self::verify_token( $token );

		if ( null === $payload ) {
			return null;
		}

		list( $run_id, $user_id, $cursor ) = $payload;

		if ( get_current_user_id() !== $user_id ) {
			return null;
		}

		$state = get_transient( self::run_key( $run_id ) );

		if ( ! is_array( $state ) ) {
			return null;
		}

		if ( (int) ( $state['user_id'] ?? 0 ) !== $user_id ) {
			return null;
		}

		// A token only matches the stored cursor, so an earlier token cannot
		// replay a batch that the server already advanced past.
		if ( (int) ( $state['cursor'] ?? -1 ) !== $cursor ) {
			return null;
		}

		if ( ! in_array( (string) ( $state['operation'] ?? '' ), BulkContactAction::operations(), true ) ) {
			return null;
		}

		return $state;
	}

	/**
	 * Sign the run ID, user, and cursor.
	 */
	private static function sign_run( string $run_id, int $user_id, int $cursor ): string {
		$payload = $run_id . ':' . $user_id . ':' . $cursor;
		$encoded = rtrim( strtr( base64_encode( $payload ), '+/', '-_' ), '=' );

		return $encoded . '.' . wp_hash( $payload, 'nowcrm_bulk' );
	}

	/**
	 * Verify a run token.
	 *
	 * @return array{0: string, 1: int, 2: int}|null
	 */
	private static function verify_token( string $token ): ?array {
		$parts = explode( '.', $token );

		if ( 2 !== count( $parts ) ) {
			return null;
		}

		$encoded = strtr( $parts[0], '-_', '+/' );
		$encoded = str_pad( $encoded, ( 4 - strlen( $encoded ) % 4 ) % 4, '=' );
		$payload = base64_decode( $encoded, true );

		if ( ! is_string( $payload ) || '' === $payload ) {
			return null;
		}

		if ( ! hash_equals( wp_hash( $payload, 'nowcrm_bulk' ), $parts[1] ) ) {
			return null;
		}

		$bits = explode( ':', $payload );

		if ( 3 !== count( $bits ) ) {
			return null;
		}

		return array( $bits[0], absint( $bits[1] ), absint( $bits[2] ) );
	}

	/**
	 * Delete stored run state.
	 */
	private static function clear_run( string $run_id ): void {
		delete_transient( self::run_key( $run_id ) );
	}

	private static function run_key( string $run_id ): string {
		return 'nowcrm_bulk_' . $run_id;
	}

	/**
	 * Outcome totals before the first batch.
	 *
	 * @return array<string, mixed>
	 */
	private static function empty_totals( int $expected ): array {
		return array(
			'processed'      => 0,
			'skipped'        => 0,
			'blocked'        => 0,
			'blocked_ids'    => array(),
			'errors'         => 0,
			'matching'       => 0,
			'expected_total' => $expected,
		);
	}

	/**
	 * Notice code for an invalid payload reason.
	 */
	private static function notice_for_reason( string $reason ): string {
		return match ( $reason ) {
			'term'   => 'invalid_term',
			'status' => 'invalid_status',
			'filter' => 'invalid_filter',
			default  => 'error',
		};
	}

	/**
	 * Query arguments that render a result notice.
	 *
	 * @param array<string, mixed> $result Bulk action result.
	 * @return array<string, mixed>
	 */
	private static function result_args( array $result, string $operation ): array {
		$args = array(
			'nowcrm_bulk'           => sanitize_key( (string) $result['status'] ),
			'nowcrm_bulk_operation' => $operation,
			'nowcrm_bulk_done'      => absint( $result['processed'] ),
			'nowcrm_bulk_skipped'   => absint( $result['skipped'] ),
			'nowcrm_bulk_blocked'   => absint( $result['blocked'] ),
			'nowcrm_bulk_errors'    => absint( $result['errors'] ),
			'nowcrm_bulk_matching'  => absint( $result['matching'] ),
			'nowcrm_bulk_expected'  => absint( $result['expected_total'] ),
			'nowcrm_bulk_target'    => sanitize_key( (string) $result['target_status'] ),
		);

		if ( array() !== $result['blocked_ids'] ) {
			$args['nowcrm_bulk_ids'] = implode( ',', array_map( 'absint', $result['blocked_ids'] ) );
		}

		return $args;
	}

	/**
	 * JSON success and exit.
	 *
	 * @param array<string, mixed> $data Payload.
	 */
	private static function json_success( array $data ): void {
		wp_send_json_success( $data );
	}

	/**
	 * JSON failure and exit.
	 *
	 * @param string               $message  Human message.
	 * @param int                  $status   HTTP status.
	 * @param string               $redirect Optional recovery URL.
	 */
	private static function json_failure( string $message, int $status = 400, string $redirect = '' ): void {
		$data = array( 'message' => $message );

		if ( '' !== $redirect ) {
			$data['redirect'] = $redirect;
		}

		wp_send_json_error( $data, $status );
	}

	/**
	 * Render the detailed result of the last bulk action.
	 */
	public static function render_result_notice(): void {
		$state = sanitize_key( Menu::query( 'nowcrm_bulk' ) );

		if ( ! in_array( $state, array( 'done', 'empty', 'changed', 'invalid' ), true ) ) {
			return;
		}

		$operation = sanitize_key( Menu::query( 'nowcrm_bulk_operation' ) );
		$done      = absint( Menu::query( 'nowcrm_bulk_done', '0' ) );
		$skipped   = absint( Menu::query( 'nowcrm_bulk_skipped', '0' ) );
		$blocked   = absint( Menu::query( 'nowcrm_bulk_blocked', '0' ) );
		$errors    = absint( Menu::query( 'nowcrm_bulk_errors', '0' ) );
		$matching  = absint( Menu::query( 'nowcrm_bulk_matching', '0' ) );
		$expected  = absint( Menu::query( 'nowcrm_bulk_expected', '0' ) );
		$target    = sanitize_key( Menu::query( 'nowcrm_bulk_target' ) );
		$reason    = sanitize_key( Menu::query( 'nowcrm_bulk_reason' ) );

		$class = 'notice-success';

		if ( 'changed' === $state || 'invalid' === $state ) {
			$class = 'notice-error';
		} elseif ( 'empty' === $state || ( 'done' === $state && $blocked > 0 ) ) {
			$class = 'notice-warning';
		}

		$message = self::result_message( $state, $operation, $done, $target, $reason, $matching, $expected );

		echo '<div class="notice ' . esc_attr( $class ) . ' is-dismissible">';
		echo '<p>' . esc_html( $message ) . '</p>';

		if ( 'done' === $state && $skipped > 0 ) {
			printf(
				'<p>%s</p>',
				esc_html(
					sprintf(
						/* translators: %d: number of contacts that no longer exist. */
						__( '有 %d 位聯絡人已不存在，已略過。', 'now-crm' ),
						$skipped
					)
				)
			);
		}

		if ( 'done' === $state && $errors > 0 ) {
			printf(
				'<p>%s</p>',
				esc_html(
					sprintf(
						/* translators: %d: number of contacts that failed to update. */
						__( '有 %d 位處理失敗，請再試一次。', 'now-crm' ),
						$errors
					)
				)
			);
		}

		if ( 'done' === $state && $blocked > 0 ) {
			$blocked_ids = array_filter( array_map( 'absint', explode( ',', Menu::query( 'nowcrm_bulk_ids' ) ) ) );
			$blocked_ids = array_slice( $blocked_ids, 0, 10 );

			printf(
				'<p>%s</p>',
				esc_html(
					sprintf(
						/* translators: %d: number of contacts still linked to WooCommerce orders. */
						__( '另有 %d 位聯絡人仍連結 WooCommerce 訂單，未刪除；訂單與會計資料未變更。', 'now-crm' ),
						$blocked
					)
				)
			);

			if ( array() !== $blocked_ids ) {
				printf(
					'<p>%s</p>',
					esc_html(
						sprintf(
							/* translators: %s: comma separated contact IDs. */
							__( '未刪除的聯絡人 ID：%s。', 'now-crm' ),
							implode( ', ', $blocked_ids )
						)
					)
				);
			}
		}

		echo '</div>';
	}

	/**
	 * Main sentence of a result notice.
	 */
	private static function result_message(
		string $state,
		string $operation,
		int $done,
		string $target,
		string $reason,
		int $matching,
		int $expected
	): string {
		if ( 'changed' === $state ) {
			return sprintf(
				/* translators: 1: contacts matching now, 2: contacts the user confirmed. */
				__( '名單已變動：目前有 %1$d 位聯絡人符合篩選，原本確認 %2$d 位。未刪除任何資料，請重新整理後再執行。', 'now-crm' ),
				$matching,
				$expected
			);
		}

		if ( 'empty' === $state ) {
			return __( '目前沒有符合條件的聯絡人，未執行任何操作。', 'now-crm' );
		}

		if ( 'invalid' === $state ) {
			return match ( $reason ) {
				'selection' => __( '請先勾選要處理的聯絡人。', 'now-crm' ),
				'term'      => __( '名單／標籤資料不正確。', 'now-crm' ),
				'status'    => __( '聯絡人狀態不正確。', 'now-crm' ),
				'filter'    => __( '進階篩選條件不正確，未執行任何操作。請修正條件後再試。', 'now-crm' ),
				'batch'     => __( '篩選批次操作需要瀏覽器 JavaScript；選取的聯絡人仍可直接執行。', 'now-crm' ),
				'expired'   => __( '批次工作階段已失效，請重新整理後再試。', 'now-crm' ),
				default     => __( '批次操作參數不正確，未執行任何操作。', 'now-crm' ),
			};
		}

		if ( $done < 1 ) {
			return BulkContactAction::DELETE === $operation
				? __( '沒有聯絡人被刪除。', 'now-crm' )
				: __( '沒有聯絡人需要更新。', 'now-crm' );
		}

		return match ( $operation ) {
			BulkContactAction::ATTACH_LIST => sprintf(
				/* translators: %d: number of contacts. */
				__( '已將 %d 位聯絡人加入所選名單。', 'now-crm' ),
				$done
			),
			BulkContactAction::DETACH_LIST => sprintf(
				/* translators: %d: number of contacts. */
				__( '已將 %d 位聯絡人移出所選名單。', 'now-crm' ),
				$done
			),
			BulkContactAction::ATTACH_TAG => sprintf(
				/* translators: %d: number of contacts. */
				__( '已將 %d 位聯絡人加入所選標籤。', 'now-crm' ),
				$done
			),
			BulkContactAction::DETACH_TAG => sprintf(
				/* translators: %d: number of contacts. */
				__( '已將 %d 位聯絡人移出所選標籤。', 'now-crm' ),
				$done
			),
			BulkContactAction::SET_STATUS => Status::is_valid( $target )
				? sprintf(
					/* translators: 1: number of contacts, 2: status label. */
					__( '已將 %1$d 位聯絡人的狀態設為「%2$s」。', 'now-crm' ),
					$done,
					Status::label( $target )
				)
				: __( '聯絡人狀態已更新。', 'now-crm' ),
			BulkContactAction::DELETE => sprintf(
				/* translators: %d: number of deleted contacts. */
				__( '已永久刪除 %d 位聯絡人。', 'now-crm' ),
				$done
			),
			default => __( '批次操作已完成。', 'now-crm' ),
		};
	}

	/**
	 * Preserve the current list filters on the redirect target.
	 *
	 * @return array<string, mixed>
	 */
	private static function page_args(): array {
		$search   = sanitize_text_field( self::post_raw( 'filter_search' ) );
		$status   = sanitize_key( self::post_raw( 'filter_status' ) );
		$term     = absint( self::post_raw( 'filter_term' ) );
		$paged    = max( 1, absint( self::post_raw( 'filter_paged' ) ) );
		$mode     = sanitize_key( self::post_raw( 'filter_mode' ) );
		$per_page = absint( self::post_raw( 'filter_per_page' ) );
		$orderby  = sanitize_key( self::post_raw( 'filter_orderby' ) );
		$order    = 'ASC' === strtoupper( self::post_raw( 'filter_order' ) ) ? 'ASC' : 'DESC';
		$lists    = self::post_ids( 'filter_lists' );
		$tags     = self::post_ids( 'filter_tags' );

		$statuses = array();

		foreach ( (array) ( $_POST['filter_statuses'] ?? array() ) as $slug ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- guarded by the bulk nonce before this runs.
			if ( ! is_string( $slug ) ) {
				continue;
			}

			$slug = sanitize_key( wp_unslash( $slug ) );

			if ( Status::is_valid( $slug ) && ! in_array( $slug, $statuses, true ) ) {
				$statuses[] = $slug;
			}
		}

		$args = array();

		if ( '' !== $search ) {
			$args['s'] = $search;
		}

		if ( 'advanced' !== $mode && Status::is_valid( $status ) ) {
			$args['status'] = $status;
		}

		if ( 'advanced' !== $mode && $term > 0 ) {
			$args['term_id'] = $term;
		}

		if ( 'advanced' !== $mode && array() !== $lists ) {
			$args['lists'] = $lists;
		}

		if ( 'advanced' !== $mode && array() !== $tags ) {
			$args['tags'] = $tags;
		}

		if ( 'advanced' !== $mode && count( $statuses ) > 1 ) {
			$args['statuses'] = $statuses;
		}

		if ( in_array( $per_page, array( 10, 20, 50, 100, 150 ), true ) ) {
			$args['per_page'] = $per_page;
		}

		if ( in_array( $orderby, array( 'id', 'first_name', 'status' ), true ) ) {
			$args['orderby'] = $orderby;
			$args['order']   = $order;
		}

		if ( $paged > 1 ) {
			$args['paged'] = $paged;
		}

		$advanced = AdvancedFilters::from_json( self::post_raw( ContactAdvancedFilters::TRANSPORT ) );

		if ( 'simple' === $mode ) {
			// The compact controls stay compact; the validated plan stays in
			// the request, not in the advanced editor URL.
			return $args;
		}

		if ( 'advanced' === $mode ) {
			$args['mode'] = 'advanced';
		}

		if ( is_array( $advanced ) ) {
			$args[ ContactAdvancedFilters::INPUT ] = AdvancedFilters::to_query( $advanced );
		}

		return $args;
	}

	/**
	 * Raw unslashed string from POST (arrays ignored).
	 */
	private static function post_raw( string $key ): string {
		if ( ! isset( $_POST[ $key ] ) || ! is_string( $_POST[ $key ] ) ) {
			return '';
		}

		return wp_unslash( $_POST[ $key ] );
	}

	/**
	 * Positive unique IDs from an array POST field.
	 *
	 * @return int[]
	 */
	private static function post_ids( string $key ): array {
		if ( ! isset( $_POST[ $key ] ) || ! is_array( $_POST[ $key ] ) ) {
			return array();
		}

		$ids = array();

		foreach ( wp_unslash( $_POST[ $key ] ) as $value ) {
			if ( ! is_scalar( $value ) ) {
				continue;
			}

			$value = absint( $value );

			if ( $value > 0 ) {
				$ids[ $value ] = $value;
			}
		}

		return array_values( $ids );
	}

	/**
	 * Redirect back to the contact list with result arguments.
	 *
	 * @param array<string, mixed> $page_args Current list filters.
	 * @param array<string, mixed> $args      Result arguments.
	 */
	private static function redirect( array $page_args, array $args ): void {
		wp_safe_redirect( Menu::page_url( Menu::SLUG, array_merge( $page_args, $args ) ) );
		exit;
	}
}
