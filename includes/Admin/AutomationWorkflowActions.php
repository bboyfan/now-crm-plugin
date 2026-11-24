<?php
/**
 * Run controls for automations: cancel one open run or retry one failed step.
 *
 * Every handler checks the manage capability, the nonce, and that the run
 * belongs to the automation in the request. The repository methods compare and
 * set the run status, so two requests cannot both change one run.
 *
 * @package NowCRM
 */

namespace NowCRM\Admin;

use NowCRM\Automation\RunRepository;
use NowCRM\Support\Capabilities;

defined( 'ABSPATH' ) || exit;

final class AutomationWorkflowActions {

	public const CANCEL = 'nowcrm_cancel_automation_run';
	public const RETRY  = 'nowcrm_retry_automation_run';

	/**
	 * Nonce action shared by both run controls.
	 */
	public const NONCE = 'nowcrm_automation_run';

	/**
	 * Query argument that carries a run notice code.
	 */
	public const NOTICE = 'nowcrm_run_notice';

	/**
	 * Register the admin-post handlers.
	 */
	public static function register(): void {
		add_action( 'admin_post_' . self::CANCEL, array( __CLASS__, 'cancel' ) );
		add_action( 'admin_post_' . self::RETRY, array( __CLASS__, 'retry' ) );
	}

	/**
	 * Nonce field for one run control.
	 */
	public static function nonce_field(): void {
		wp_nonce_field( self::NONCE );
	}

	/**
	 * Print a notice for the current run screen.
	 */
	public static function render_notice(): void {
		if ( ! isset( $_GET[ self::NOTICE ] ) || ! is_string( $_GET[ self::NOTICE ] ) ) {
			return;
		}

		$code = sanitize_key( wp_unslash( $_GET[ self::NOTICE ] ) );

		$messages = array(
			'run_cancelled'     => array( 'notice-success', __( '已取消這次執行。已排入佇列的郵件不會撤回。', 'now-crm' ) ),
			'run_not_open'      => array( 'notice-warning', __( '這次執行已結束，狀態沒有變更。', 'now-crm' ) ),
			'run_retried'       => array( 'notice-success', __( '已重新排入失敗的步驟。已完成的步驟不會重跑。', 'now-crm' ) ),
			'run_not_retryable' => array( 'notice-error', __( '這次執行不是失敗狀態，或狀態已改變。請重新整理。', 'now-crm' ) ),
			'run_outcome_unknown' => array( 'notice-error', __( '步驟結果不明，無法安全重試。請先確認動作是否已完成。', 'now-crm' ) ),
			'not_found'         => array( 'notice-error', __( '找不到執行紀錄或自動化。', 'now-crm' ) ),
		);

		if ( ! isset( $messages[ $code ] ) ) {
			return;
		}

		printf(
			'<div class="notice %1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $messages[ $code ][0] ),
			esc_html( $messages[ $code ][1] )
		);
	}

	/**
	 * Cancel one open run.
	 */
	public static function cancel(): void {
		self::handle( 'cancel' );
	}

	/**
	 * Retry the failed next step of one run.
	 */
	public static function retry(): void {
		self::handle( 'retry' );
	}

	/**
	 * Shared guard and dispatch.
	 *
	 * @param string $operation cancel or retry.
	 */
	private static function handle( string $operation ): void {
		if ( ! current_user_can( Capabilities::MANAGE_CONTACTS ) ) {
			wp_die( esc_html__( '您沒有權限執行此操作。', 'now-crm' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( self::NONCE );

		$run_id        = absint( self::post_raw( 'run_id' ) );
		$automation_id = absint( self::post_raw( 'automation_id' ) );
		$paged         = max( 1, absint( self::post_raw( 'paged' ) ) );
		$status        = sanitize_key( self::post_raw( 'run_status' ) );
		$search        = sanitize_text_field( self::post_raw( 'run_search' ) );

		$runs = new RunRepository();
		$run  = $run_id > 0 ? $runs->get( $run_id ) : null;

		if ( ! $run || (int) $run['automation_id'] !== $automation_id ) {
			self::redirect( $automation_id, $paged, $status, $search, 'not_found' );
		}

		if ( 'cancel' === $operation ) {
			self::redirect( $automation_id, $paged, $status, $search, $runs->cancel_if_open( $run_id ) ? 'run_cancelled' : 'run_not_open' );
		}

		$result = $runs->retry_failed( $run_id );

		$notice = 'run_retried';

		if ( is_wp_error( $result ) ) {
			$notice = 'nowcrm_run_outcome_unknown' === $result->get_error_code() ? 'run_outcome_unknown' : 'run_not_retryable';
		}

		self::redirect( $automation_id, $paged, $status, $search, $notice );
	}

	/**
	 * Redirect back to the run list with the same filters.
	 *
	 * @param int    $automation_id Automation ID.
	 * @param int    $paged         Page number.
	 * @param string $status        Run status filter.
	 * @param string $search        Contact search text.
	 * @param string $notice        Notice code.
	 */
	private static function redirect( int $automation_id, int $paged, string $status, string $search, string $notice ): void {
		$args = array(
			'id'         => $automation_id,
			'paged'      => max( 1, $paged ),
			self::NOTICE => $notice,
		);

		if ( '' !== $status ) {
			$args['run_status'] = $status;
		}

		if ( '' !== $search ) {
			$args['run_search'] = $search;
		}

		wp_safe_redirect( Menu::page_url( Menu::AUTOMATION_RUNS_SLUG, $args ) );

		exit;
	}

	/**
	 * Raw unslashed string from the current POST request (arrays ignored).
	 */
	private static function post_raw( string $key ): string {
		if ( ! isset( $_POST[ $key ] ) || ! is_string( $_POST[ $key ] ) ) {
			return '';
		}

		return wp_unslash( $_POST[ $key ] );
	}
}
