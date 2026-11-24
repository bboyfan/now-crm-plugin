<?php
/**
 * CSV admin module: menu, nonce-gated phase handlers, and streaming export.
 *
 * This class owns the whole CSV surface, so the shared admin files only need
 * one module registration line:
 *
 *     Admin\ContactCsvAdmin::class,
 *
 * in `Plugin::modules()`. Nothing is written to settings, and no shared screen
 * needs a new control.
 *
 * @package NowCRM
 */

namespace NowCRM\Admin;

use NowCRM\Contacts\CsvExport;
use NowCRM\Contacts\CsvImport;
use NowCRM\Contacts\CsvStorage;
use NowCRM\Support\Capabilities;
use WP_Error;

defined( 'ABSPATH' ) || exit;

final class ContactCsvAdmin {

	public const SLUG = 'nowcrm-contact-csv';

	public const ACTION_UPLOAD = 'nowcrm_contact_csv_upload';
	public const ACTION_START  = 'nowcrm_contact_csv_start';
	public const ACTION_BATCH  = 'nowcrm_contact_csv_batch';
	public const ACTION_EXPORT = 'nowcrm_contact_csv_export';

	public const NONCE_UPLOAD = 'nowcrm_contact_csv_upload';
	public const NONCE_START  = 'nowcrm_contact_csv_start';
	public const NONCE_BATCH  = 'nowcrm_contact_csv_batch';
	public const NONCE_EXPORT = 'nowcrm_contact_csv_export';

	/**
	 * Screen hook suffix from add_submenu_page().
	 */
	private string $hook = '';

	/**
	 * Register hooks. Called from Plugin::boot().
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_page' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		add_action( 'admin_post_' . self::ACTION_UPLOAD, array( $this, 'upload' ) );
		add_action( 'admin_post_' . self::ACTION_START, array( $this, 'start' ) );
		add_action( 'admin_post_' . self::ACTION_BATCH, array( $this, 'batch' ) );
		add_action( 'admin_post_' . self::ACTION_EXPORT, array( $this, 'export' ) );
	}

	/**
	 * Add the CSV screen under NOW CRM.
	 */
	public function add_page(): void {
		$this->hook = (string) add_submenu_page(
			Menu::DASHBOARD_SLUG,
			__( '聯絡人匯入／匯出', 'now-crm' ),
			__( '匯入／匯出', 'now-crm' ),
			Capabilities::MANAGE_CONTACTS,
			self::SLUG,
			array( ContactCsvPage::class, 'render' )
		);
	}

	/**
	 * Load the shared admin stylesheet on this screen only.
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		if ( '' === $this->hook || $hook_suffix !== $this->hook ) {
			return;
		}

		wp_enqueue_style(
			'nowcrm-admin',
			plugins_url( 'assets/css/admin.css', NOWCRM_FILE ),
			array(),
			NOWCRM_VERSION
		);
	}

	/**
	 * Upload a CSV and stage it for mapping.
	 */
	public function upload(): void {
		$this->guard( self::NONCE_UPLOAD );

		$file = isset( $_FILES['nowcrm_csv_file'] ) && is_array( $_FILES['nowcrm_csv_file'] )
			? $_FILES['nowcrm_csv_file']
			: array();

		$valid = CsvImport::validate_upload( $file );

		if ( is_wp_error( $valid ) ) {
			$this->redirect( array( 'tab' => 'import', 'nowcrm_csv_notice' => self::notice( $valid ) ) );
		}

		$delimiter = sanitize_key( $this->post_raw( 'delimiter', 'comma' ) );
		$state     = ( new CsvImport() )->prepare(
			(string) $file['tmp_name'],
			$delimiter,
			(string) $file['name'],
			get_current_user_id()
		);

		if ( is_wp_error( $state ) ) {
			$this->redirect( array( 'tab' => 'import', 'nowcrm_csv_notice' => self::notice( $state ) ) );
		}

		$this->redirect(
			array(
				'tab'   => 'import',
				'token' => (string) $state['token'],
			)
		);
	}

	/**
	 * Validate the mapping and options, then start the run.
	 */
	public function start(): void {
		$this->guard( self::NONCE_START );

		$token   = CsvStorage::clean_token( $this->post_raw( 'token' ) );
		$mapping = isset( $_POST['mapping'] ) && is_array( $_POST['mapping'] ) ? (array) wp_unslash( $_POST['mapping'] ) : array();
		$options = isset( $_POST['options'] ) && is_array( $_POST['options'] ) ? (array) wp_unslash( $_POST['options'] ) : array();

		if ( '' === $token ) {
			$this->redirect( array( 'tab' => 'import', 'nowcrm_csv_notice' => 'expired' ) );
		}

		$state = ( new CsvImport() )->start( $token, $mapping, $options, get_current_user_id() );

		if ( is_wp_error( $state ) ) {
			// Keep the user's edits so a rejected mapping does not lose work.
			CsvImport::save_draft( $token, $mapping, $options, get_current_user_id() );

			$this->redirect(
				array(
					'tab'               => 'import',
					'token'             => $token,
					'nowcrm_csv_notice' => self::notice( $state ),
				)
			);
		}

		CsvImport::clear_draft( $token );

		$this->redirect(
			array(
				'tab'   => 'import',
				'token' => $token,
			)
		);
	}

	/**
	 * Process one batch.
	 */
	public function batch(): void {
		$this->guard( self::NONCE_BATCH );

		$token = CsvStorage::clean_token( $this->post_raw( 'token' ) );

		if ( '' === $token ) {
			$this->redirect( array( 'tab' => 'import', 'nowcrm_csv_notice' => 'expired' ) );
		}

		$result = ( new CsvImport() )->run_batch( $token, get_current_user_id() );

		if ( is_wp_error( $result ) ) {
			$this->redirect( array( 'tab' => 'import', 'nowcrm_csv_notice' => self::notice( $result ) ) );
		}

		if ( ! empty( $result['completed'] ) ) {
			$this->redirect(
				array(
					'tab'    => 'import',
					'result' => (string) $result['result_token'],
				)
			);
		}

		$this->redirect(
			array(
				'tab'   => 'import',
				'token' => $token,
			)
		);
	}

	/**
	 * Stream an export and exit. No row set is loaded whole.
	 */
	public function export(): void {
		$this->guard( self::NONCE_EXPORT );

		$ids = preg_split( '/[\s,]+/', $this->post_raw( 'contact_ids' ), -1, PREG_SPLIT_NO_EMPTY );

		$plan = ( new CsvExport() )->plan(
			array(
				'scope'         => sanitize_key( $this->post_raw( 'scope', 'filtered' ) ),
				'search'        => sanitize_text_field( $this->post_raw( 'search' ) ),
				'status'        => sanitize_key( $this->post_raw( 'status' ) ),
				'term_id'       => absint( $this->post_raw( 'term_id' ) ),
				'segment'       => sanitize_text_field( $this->post_raw( 'segment' ) ),
				'filters'       => $this->post_raw( ContactAdvancedFilters::TRANSPORT ),
				'ids'           => is_array( $ids ) ? $ids : array(),
				'columns'       => isset( $_POST['columns'] ) && is_array( $_POST['columns'] ) ? (array) wp_unslash( $_POST['columns'] ) : array(),
				'custom_fields' => isset( $_POST['custom_fields'] ) && is_array( $_POST['custom_fields'] ) ? (array) wp_unslash( $_POST['custom_fields'] ) : array(),
			)
		);

		if ( is_wp_error( $plan ) ) {
			$this->redirect( array( 'tab' => 'export', 'nowcrm_csv_notice' => self::notice( $plan ) ) );
		}

		$export = new CsvExport();

		while ( ob_get_level() > 0 ) {
			@ob_end_clean();
		}

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . CsvExport::filename() . '"' );

		$output = fopen( 'php://output', 'wb' );

		if ( false === $output ) {
			exit;
		}

		fputcsv(
			$output,
			array_map( array( CsvExport::class, 'safe_cell' ), $export->header( $plan ) ),
			',',
			'"',
			'\\'
		);

		foreach ( $export->rows( $plan ) as $row ) {
			fputcsv(
				$output,
				array_map( array( CsvExport::class, 'safe_cell' ), $row ),
				',',
				'"',
				'\\'
			);
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions -- the CSV export writes to php://output; WP_Filesystem cannot stream the response handle.
		fclose( $output );
		exit;
	}

	/**
	 * Capability + nonce gate.
	 */
	private function guard( string $action ): void {
		if ( ! Capabilities::can_manage_contacts() ) {
			wp_die(
				esc_html__( '您沒有權限執行此操作。', 'now-crm' ),
				'',
				array( 'response' => 403 )
			);
		}

		check_admin_referer( $action );
	}

	/**
	 * Map an error to an allow-listed notice code.
	 */
	private static function notice( WP_Error $error ): string {
		$map = array(
			'nowcrm_csv_no_file'         => 'invalid_upload',
			'nowcrm_csv_upload'          => 'upload_failed',
			'nowcrm_csv_type'            => 'invalid_upload',
			'nowcrm_csv_empty'           => 'empty_csv',
			'nowcrm_csv_no_rows'         => 'empty_csv',
			'nowcrm_csv_too_large'       => 'invalid_upload',
			'nowcrm_csv_too_many_rows'   => 'too_many_rows',
			'nowcrm_csv_too_many_columns' => 'too_many_columns',
			'nowcrm_csv_duplicate_headers' => 'duplicate_headers',
			'nowcrm_csv_empty_header'    => 'empty_header',
			'nowcrm_csv_invalid_mapping' => 'invalid_mapping',
			'nowcrm_csv_missing_email'   => 'missing_email',
			'nowcrm_csv_unknown_field'   => 'unknown_field',
			'nowcrm_csv_duplicate_mapping' => 'duplicate_mapping',
			'nowcrm_csv_invalid_status'  => 'invalid_status',
			'nowcrm_csv_invalid_terms'   => 'invalid_terms',
			'nowcrm_csv_too_many_terms'  => 'too_many_terms',
			'nowcrm_csv_expired'         => 'expired',
			'nowcrm_csv_started'         => 'started',
			'nowcrm_csv_not_started'     => 'invalid_mapping',
			'nowcrm_csv_invalid_columns' => 'invalid_columns',
			'nowcrm_csv_no_selection'    => 'no_selection',
			'nowcrm_csv_no_contacts'     => 'no_contacts',
			'nowcrm_advanced_filter_invalid' => 'invalid_filter',
			'nowcrm_csv_too_many_selected' => 'too_many_selected',
			'nowcrm_csv_no_owner'        => 'upload_failed',
			'nowcrm_csv_storage'         => 'upload_failed',
		);

		return $map[ $error->get_error_code() ] ?? 'invalid_upload';
	}

	/**
	 * Redirect back to the CSV screen.
	 *
	 * @param array<string, mixed> $args Query arguments.
	 */
	private function redirect( array $args ): void {
		wp_safe_redirect( Menu::page_url( self::SLUG, $args ) );
		exit;
	}

	/**
	 * Raw unslashed string from POST (arrays ignored).
	 */
	private function post_raw( string $key, string $default = '' ): string {
		if ( ! isset( $_POST[ $key ] ) || ! is_string( $_POST[ $key ] ) ) {
			return $default;
		}

		return wp_unslash( $_POST[ $key ] );
	}
}
