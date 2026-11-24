<?php
/**
 * Contact CSV import: upload validation, header mapping, and bounded batches.
 *
 * The flow mirrors the observed FluentCRM operations without its missing
 * modules:
 *
 * 1. Upload a CSV with an explicit comma or semicolon delimiter. The file is
 *    normalized to UTF-8, a UTF-8 BOM is removed, and headers must be unique.
 * 2. Map each header to one native field or custom field. Email is required.
 * 3. Import in batches of BATCH rows. Each HTTP request advances a byte
 *    offset, so a 100k-row file never loads into memory at once.
 * 4. The last batch deletes the upload and stores a PII-free summary.
 *
 * Status rules follow the reference, with suppression kept safe:
 * - New contacts get the chosen status.
 * - Existing contacts keep their status unless "force update status" is on.
 * - The one exception is a suppressed target (unsubscribed, bounced,
 *   complained). A non-forced import can move a contact into suppression but
 *   can never lift suppression.
 * - A status change also requires "update existing contacts".
 *
 * Double opt-in mail is not sent here. It is separately owned; the seam is the
 * `nowcrm_csv_contact_imported` action fired after a new contact is written.
 *
 * @package NowCRM
 */

namespace NowCRM\Contacts;

use NowCRM\Companies\CompanyRepository;
use NowCRM\Terms\TermRepository;
use WP_Error;

defined( 'ABSPATH' ) || exit;

final class CsvImport {

	/**
	 * Data rows processed per HTTP request.
	 */
	public const BATCH = 100;

	/**
	 * Maximum upload size in bytes.
	 */
	public const MAX_BYTES = 10485760;

	/**
	 * Maximum data rows in one file.
	 */
	public const MAX_ROWS = 100000;

	/**
	 * Maximum columns in one file.
	 */
	public const MAX_COLUMNS = 200;

	/**
	 * Maximum selected lists or tags.
	 */
	public const MAX_TERMS = 100;

	/**
	 * Maximum term names in one row cell.
	 */
	public const MAX_ROW_TERMS = 100;

	/**
	 * Stored sample row numbers per skip reason.
	 */
	public const MAX_SAMPLES = 20;

	/**
	 * Mapping draft lifetime.
	 */
	private const DRAFT_TTL = 15 * MINUTE_IN_SECONDS;

	/**
	 * Prefix for custom-field mapping targets.
	 */
	public const CUSTOM_PREFIX = 'cf:';

	private ContactRepository $contacts;
	private TermRepository $terms;
	private CustomFieldValues $values;
	private CompanyRepository $companies;

	public function __construct(
		?ContactRepository $contacts = null,
		?TermRepository $terms = null,
		?CustomFieldValues $values = null,
		?CompanyRepository $companies = null
	) {
		$this->contacts  = $contacts ?? new ContactRepository();
		$this->terms     = $terms ?? new TermRepository();
		$this->values    = $values ?? new CustomFieldValues();
		$this->companies = $companies ?? new CompanyRepository();
	}

	/**
	 * Standard import targets. Read models, status, wp_user_id, id, and
	 * created/updated timestamps are deliberately absent: an import never
	 * links a WordPress user, never rewrites suppression history directly, and
	 * never writes audit columns.
	 *
	 * @return array<string, string>
	 */
	public static function fields(): array {
		return array(
			'email'          => __( 'Email（必填）', 'now-crm' ),
			'full_name'      => __( '全名', 'now-crm' ),
			'first_name'     => __( '名字', 'now-crm' ),
			'last_name'      => __( '姓氏', 'now-crm' ),
			'phone'          => __( '電話', 'now-crm' ),
			'source'         => __( '來源', 'now-crm' ),
			'prefix'         => __( '稱謂', 'now-crm' ),
			'contact_type'   => __( '聯絡人類型', 'now-crm' ),
			'date_of_birth'  => __( '出生日期', 'now-crm' ),
			'timezone'       => __( '時區', 'now-crm' ),
			'address_line_1' => __( '地址第 1 行', 'now-crm' ),
			'address_line_2' => __( '地址第 2 行', 'now-crm' ),
			'city'           => __( '城市', 'now-crm' ),
			'state'          => __( '州／省／地區', 'now-crm' ),
			'postal_code'    => __( '郵遞區號', 'now-crm' ),
			'country'        => __( '國家代碼', 'now-crm' ),
			'lists'          => __( '名單（逗號分隔）', 'now-crm' ),
			'tags'           => __( '標籤（逗號分隔）', 'now-crm' ),
		);
	}

	/**
	 * Validate the transport part of an upload. Content checks live in prepare().
	 *
	 * @param array<string, mixed> $file            $_FILES entry.
	 * @param bool                 $require_uploaded Require a real HTTP upload.
	 * @return true|WP_Error
	 */
	public static function validate_upload( array $file, bool $require_uploaded = true ) {
		$error = (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE );
		$name  = sanitize_file_name( (string) ( $file['name'] ?? '' ) );
		$tmp   = (string) ( $file['tmp_name'] ?? '' );

		if ( UPLOAD_ERR_NO_FILE === $error || '' === $tmp ) {
			return new WP_Error( 'nowcrm_csv_no_file', __( '請選擇 CSV 檔案。', 'now-crm' ) );
		}

		if ( UPLOAD_ERR_OK !== $error ) {
			return new WP_Error( 'nowcrm_csv_upload', __( '檔案上傳失敗，請再試一次。', 'now-crm' ) );
		}

		if ( $require_uploaded && ! is_uploaded_file( $tmp ) ) {
			return new WP_Error( 'nowcrm_csv_upload', __( '檔案不是有效的上傳檔案。', 'now-crm' ) );
		}

		if ( ! is_readable( $tmp ) ) {
			return new WP_Error( 'nowcrm_csv_upload', __( '無法讀取上傳檔案。', 'now-crm' ) );
		}

		if ( 'csv' !== strtolower( (string) pathinfo( $name, PATHINFO_EXTENSION ) ) ) {
			return new WP_Error( 'nowcrm_csv_type', __( '檔案必須是 .csv。', 'now-crm' ) );
		}

		$size = (int) ( $file['size'] ?? 0 );

		if ( $size <= 0 ) {
			$size = (int) filesize( $tmp );
		}

		if ( $size <= 0 ) {
			return new WP_Error( 'nowcrm_csv_empty', __( 'CSV 檔案是空的。', 'now-crm' ) );
		}

		if ( $size > self::MAX_BYTES ) {
			return new WP_Error( 'nowcrm_csv_too_large', __( 'CSV 檔案超過大小上限（10 MB）。', 'now-crm' ) );
		}

		$type = strtolower( trim( (string) ( $file['type'] ?? '' ) ) );

		if ( '' !== $type && ! in_array(
			$type,
			array(
				'text/csv',
				'text/plain',
				'application/csv',
				'application/vnd.ms-excel',
				'application/octet-stream',
			),
			true
		) ) {
			return new WP_Error( 'nowcrm_csv_type', __( '檔案類型不是 CSV。', 'now-crm' ) );
		}

		return true;
	}

	/**
	 * Normalize a delimiter choice.
	 */
	public static function delimiter( string $choice ): string {
		return 'semicolon' === $choice || ';' === $choice ? ';' : ',';
	}

	/**
	 * Read, normalize, and stage an upload. Returns the initial run state.
	 *
	 * @param string $source_path   Readable source file.
	 * @param string $delimiter     comma or semicolon.
	 * @param string $original_name Client file name.
	 * @param int    $user_id       Owner.
	 * @return array<string, mixed>|WP_Error
	 */
	public function prepare( string $source_path, string $delimiter, string $original_name, int $user_id ) {
		$delimiter = self::delimiter( $delimiter );

		if ( ! is_readable( $source_path ) ) {
			return new WP_Error( 'nowcrm_csv_no_file', __( '無法讀取 CSV 檔案。', 'now-crm' ) );
		}

		$size = (int) filesize( $source_path );

		if ( $size <= 0 ) {
			return new WP_Error( 'nowcrm_csv_empty', __( 'CSV 檔案是空的。', 'now-crm' ) );
		}

		if ( $size > self::MAX_BYTES ) {
			return new WP_Error( 'nowcrm_csv_too_large', __( 'CSV 檔案超過大小上限（10 MB）。', 'now-crm' ) );
		}

		$contents = file_get_contents( $source_path );

		if ( ! is_string( $contents ) || '' === $contents ) {
			return new WP_Error( 'nowcrm_csv_empty', __( 'CSV 檔案是空的。', 'now-crm' ) );
		}

		$contents = self::normalize_encoding( $contents );
		$created  = CsvStorage::create(
			$contents,
			array(
				'original_name' => sanitize_file_name( $original_name ),
				'delimiter'     => $delimiter,
				'bytes'         => strlen( $contents ),
				'headers'       => array(),
				'data_offset'   => 0,
				'total_rows'    => 0,
				'mapping'       => null,
				'options'       => null,
				'progress'      => $this->empty_progress(),
			),
			$user_id
		);

		if ( is_wp_error( $created ) ) {
			return $created;
		}

		$token = (string) $created['token'];
		$state = CsvStorage::read( $token, $user_id );

		if ( ! is_array( $state ) ) {
			CsvStorage::delete( $token );

			return new WP_Error( 'nowcrm_csv_storage', __( '無法讀取暫存狀態。', 'now-crm' ) );
		}

		// phpcs:disable WordPress.WP.AlternativeFunctions -- the uploaded CSV is read row by row from a stream; WP_Filesystem cannot stream a handle.
		$handle = fopen( $created['path'], 'rb' );

		if ( false === $handle ) {
			CsvStorage::delete( $token );

			return new WP_Error( 'nowcrm_csv_storage', __( '無法開啟暫存檔案。', 'now-crm' ) );
		}

		$raw_headers = fgetcsv( $handle, 0, $delimiter, '"', '\\' );

		if ( false === $raw_headers || array( null ) === $raw_headers || array() === $raw_headers ) {
			fclose( $handle );
			CsvStorage::delete( $token );

			return new WP_Error( 'nowcrm_csv_empty', __( 'CSV 檔案缺少標題列。', 'now-crm' ) );
		}

		$headers = array();

		foreach ( $raw_headers as $header ) {
			$headers[] = is_scalar( $header ) ? trim( sanitize_text_field( (string) $header ) ) : '';
		}

		if ( count( $headers ) > self::MAX_COLUMNS ) {
			fclose( $handle );
			CsvStorage::delete( $token );

			return new WP_Error( 'nowcrm_csv_too_many_columns', __( 'CSV 欄位數量過多。', 'now-crm' ) );
		}

		foreach ( $headers as $header ) {
			if ( '' === $header ) {
				fclose( $handle );
				CsvStorage::delete( $token );

				return new WP_Error( 'nowcrm_csv_empty_header', __( 'CSV 有空白標題，請補上欄位名稱。', 'now-crm' ) );
			}
		}

		if ( count( $headers ) !== count( array_unique( $headers ) ) ) {
			fclose( $handle );
			CsvStorage::delete( $token );

			return new WP_Error( 'nowcrm_csv_duplicate_headers', __( 'CSV 標題重複，請刪除重複欄位後再試。', 'now-crm' ) );
		}

		$data_offset = (int) ftell( $handle );
		$total_rows  = 0;
		$too_many    = false;

		while ( false !== fgetcsv( $handle, 0, $delimiter, '"', '\\' ) ) {
			++$total_rows;

			if ( $total_rows > self::MAX_ROWS ) {
				$too_many = true;
				break;
			}
		}

		fclose( $handle );
		// phpcs:enable

		if ( $too_many ) {
			CsvStorage::delete( $token );

			return new WP_Error( 'nowcrm_csv_too_many_rows', __( 'CSV 資料列超過上限（100,000 列）。', 'now-crm' ) );
		}

		if ( $total_rows < 1 ) {
			CsvStorage::delete( $token );

			return new WP_Error( 'nowcrm_csv_no_rows', __( 'CSV 只有標題列，沒有資料。', 'now-crm' ) );
		}

		$state['headers']                    = $headers;
		$state['data_offset']                = $data_offset;
		$state['total_rows']                 = $total_rows;
		$state['progress']['byte_offset']    = $data_offset;

		if ( ! CsvStorage::write( $token, $state ) ) {
			CsvStorage::delete( $token );

			return new WP_Error( 'nowcrm_csv_storage', __( '無法寫入暫存狀態。', 'now-crm' ) );
		}

		$state['token'] = $token;

		return $state;
	}

	/**
	 * Suggested mapping: header index => target. A target is suggested once.
	 *
	 * @param string[] $headers Header row.
	 * @return array<int, string>
	 */
	public static function suggest_mapping( array $headers ): array {
		$standard = self::fields();
		$custom   = array();

		foreach ( CustomFieldDefinitions::all() as $field ) {
			$custom[ strtolower( $field['label'] ) ] = self::CUSTOM_PREFIX . $field['slug'];
			$custom[ strtolower( $field['slug'] ) ]  = self::CUSTOM_PREFIX . $field['slug'];
		}

		$suggested = array();
		$used      = array();

		foreach ( $headers as $index => $header ) {
			$lookup  = strtolower( trim( (string) $header ) );
			$slugish = (string) preg_replace( '/[^a-z0-9]+/', '_', $lookup );
			$target  = '';

			foreach ( $standard as $key => $label ) {
				if ( $lookup === $key || $slugish === $key || $lookup === strtolower( $label ) ) {
					$target = $key;
					break;
				}
			}

			if ( '' === $target && isset( $custom[ $lookup ] ) ) {
				$target = $custom[ $lookup ];
			}

			if ( '' === $target && isset( $custom[ $slugish ] ) ) {
				$target = $custom[ $slugish ];
			}

			if ( '' === $target || isset( $used[ $target ] ) ) {
				$suggested[ (int) $index ] = '';
				continue;
			}

			$used[ $target ]           = true;
			$suggested[ (int) $index ] = $target;
		}

		return $suggested;
	}

	/**
	 * First rows of the staged file for the mapping screen. Never stored.
	 *
	 * @return array<int, array<int, string>>
	 */
	public function preview( string $token, int $user_id, int $limit = 3 ): array {
		$state = CsvStorage::read( $token, $user_id );

		if ( ! is_array( $state ) ) {
			return array();
		}

		// phpcs:disable WordPress.WP.AlternativeFunctions -- the preview reads rows from the CSV stream; WP_Filesystem cannot stream a handle.
		$handle = fopen( CsvStorage::path( $token ), 'rb' );

		if ( false === $handle ) {
			return array();
		}

		fseek( $handle, (int) $state['data_offset'] );

		$rows  = array();
		$limit = max( 1, min( 10, $limit ) );

		while ( count( $rows ) < $limit ) {
			$record = fgetcsv( $handle, 0, (string) $state['delimiter'], '"', '\\' );

			if ( false === $record ) {
				break;
			}

			$row = array();

			foreach ( (array) $state['headers'] as $index => $unused ) {
				$row[ $index ] = isset( $record[ $index ] ) && is_scalar( $record[ $index ] )
					? (string) $record[ $index ]
					: '';
			}

			$rows[] = $row;
		}

		fclose( $handle );
		// phpcs:enable

		return $rows;
	}

	/**
	 * Validate and normalize a submitted mapping.
	 *
	 * @param array<int|string, mixed> $mapping Header index => target key.
	 * @param string[]                 $headers Header row.
	 * @return array<int, string>|WP_Error
	 */
	public function validate_mapping( array $mapping, array $headers ) {
		$standard = self::fields();
		$clean    = array();
		$used     = array();
		$has_mail = false;

		foreach ( $mapping as $index => $target ) {
			if ( ! is_scalar( $index ) || ! is_scalar( $target ) ) {
				continue;
			}

			$index  = (int) $index;
			$target = trim( (string) $target );

			if ( '' === $target ) {
				continue;
			}

			if ( $index < 0 || $index >= count( $headers ) ) {
				return new WP_Error( 'nowcrm_csv_invalid_mapping', __( '欄位對應不正確。', 'now-crm' ) );
			}

			if ( str_starts_with( $target, self::CUSTOM_PREFIX ) ) {
				$field = CustomFieldDefinitions::get( substr( $target, strlen( self::CUSTOM_PREFIX ) ) );

				if ( null === $field ) {
					return new WP_Error( 'nowcrm_csv_unknown_field', __( '對應到不存在的自訂欄位。', 'now-crm' ) );
				}

				$target = self::CUSTOM_PREFIX . $field['slug'];
			} elseif ( ! isset( $standard[ $target ] ) ) {
				return new WP_Error( 'nowcrm_csv_unknown_field', __( '對應到不支援的欄位。', 'now-crm' ) );
			}

			if ( isset( $used[ $target ] ) ) {
				return new WP_Error( 'nowcrm_csv_duplicate_mapping', __( '同一個欄位不能被對應兩次。', 'now-crm' ) );
			}

			$used[ $target ]    = true;
			$clean[ $index ]    = $target;
			$has_mail           = $has_mail || 'email' === $target;
		}

		ksort( $clean );

		if ( ! $has_mail ) {
			return new WP_Error( 'nowcrm_csv_missing_email', __( '必須將一個欄位對應到 Email。', 'now-crm' ) );
		}

		return $clean;
	}

	/**
	 * Validate import options.
	 *
	 * @param array<string, mixed> $input Raw options.
	 * @return array<string, mixed>|WP_Error
	 */
	public function validate_options( array $input ) {
		$status = sanitize_key( (string) ( $input['status'] ?? Status::SUBSCRIBED ) );

		if ( ! Status::is_valid( $status ) ) {
			return new WP_Error( 'nowcrm_csv_invalid_status', __( '新聯絡人的狀態不正確。', 'now-crm' ) );
		}

		$existing_only = '1' === (string) ( $input['existing_only'] ?? '0' );
		$update        = $existing_only || '1' === (string) ( $input['update'] ?? '0' );

		$list_ids = $this->clean_term_ids( (array) ( $input['list_ids'] ?? array() ), TermRepository::TYPE_LIST );
		$tag_ids  = $this->clean_term_ids( (array) ( $input['tag_ids'] ?? array() ), TermRepository::TYPE_TAG );

		if ( is_wp_error( $list_ids ) ) {
			return $list_ids;
		}

		if ( is_wp_error( $tag_ids ) ) {
			return $tag_ids;
		}

		return array(
			'status'              => $status,
			'update'              => $update,
			'existing_only'       => $existing_only,
			'keep_existing_data'  => '1' === (string) ( $input['keep_existing_data'] ?? '0' ),
			'force_update_status' => '1' === (string) ( $input['force_update_status'] ?? '0' ),
			'list_ids'            => $list_ids,
			'tag_ids'             => $tag_ids,
		);
	}

	/**
	 * Save the mapping and options for a staged file.
	 *
	 * @param array<int|string, mixed> $mapping Submitted mapping.
	 * @param array<string, mixed>     $options Submitted options.
	 * @return array<string, mixed>|WP_Error
	 */
	public function start( string $token, array $mapping, array $options, int $user_id ) {
		$state = CsvStorage::read( $token, $user_id );

		if ( ! is_array( $state ) ) {
			return new WP_Error( 'nowcrm_csv_expired', __( '匯入工作階段已失效，請重新上傳。', 'now-crm' ) );
		}

		if ( (int) ( $state['progress']['rows'] ?? 0 ) > 0 ) {
			return new WP_Error( 'nowcrm_csv_started', __( '匯入已經開始，不能重設對應。', 'now-crm' ) );
		}

		$clean = $this->validate_mapping( $mapping, (array) $state['headers'] );

		if ( is_wp_error( $clean ) ) {
			return $clean;
		}

		$clean_options = $this->validate_options( $options );

		if ( is_wp_error( $clean_options ) ) {
			return $clean_options;
		}

		$state['mapping'] = $clean;
		$state['options'] = $clean_options;

		if ( ! CsvStorage::write( $token, $state ) ) {
			return new WP_Error( 'nowcrm_csv_storage', __( '無法儲存匯入設定。', 'now-crm' ) );
		}

		$state['token'] = $token;

		return $state;
	}

	/**
	 * Process one bounded batch.
	 *
	 * @return array<string, mixed>|WP_Error Progress, or a completed summary.
	 */
	public function run_batch( string $token, int $user_id ) {
		$state = CsvStorage::read( $token, $user_id );

		if ( ! is_array( $state ) ) {
			return new WP_Error( 'nowcrm_csv_expired', __( '匯入工作階段已失效，請重新上傳。', 'now-crm' ) );
		}

		if ( ! is_array( $state['mapping'] ) || ! is_array( $state['options'] ) ) {
			return new WP_Error( 'nowcrm_csv_not_started', __( '尚未完成欄位對應。', 'now-crm' ) );
		}

		$path = CsvStorage::path( $token );
		// phpcs:disable WordPress.WP.AlternativeFunctions -- each batch resumes from a byte offset in the CSV stream; WP_Filesystem cannot stream a handle.
		$handle = fopen( $path, 'rb' );

		if ( false === $handle ) {
			return new WP_Error( 'nowcrm_csv_expired', __( '找不到暫存檔案，請重新上傳。', 'now-crm' ) );
		}

		$progress = array_merge( $this->empty_progress(), (array) $state['progress'] );
		$offset   = max( (int) $state['data_offset'], (int) $progress['byte_offset'] );

		fseek( $handle, $offset );

		$delimiter = (string) $state['delimiter'];
		$options   = (array) $state['options'];
		$row       = (int) $progress['rows'];
		$seen      = array();
		$eof       = false;

		for ( $index = 0; $index < self::BATCH; $index++ ) {
			$record = fgetcsv( $handle, 0, $delimiter, '"', '\\' );

			if ( false === $record ) {
				$eof = true;
				break;
			}

			++$row;
			$outcome = $this->import_row( $record, $state, $options, $progress, $row, $seen, $user_id );
			$this->apply_outcome( $progress, $outcome, $row );
		}

		$new_offset = (int) ftell( $handle );
		fclose( $handle );
		// phpcs:enable

		$progress['rows']        = $row;
		$progress['byte_offset'] = $new_offset;

		$complete = $eof || $new_offset >= (int) $state['bytes'];

		if ( ! $complete ) {
			$state['progress'] = $progress;

			if ( ! CsvStorage::write( $token, $state ) ) {
				return new WP_Error( 'nowcrm_csv_storage', __( '無法儲存匯入進度。', 'now-crm' ) );
			}

			return array(
				'completed'  => false,
				'token'      => $token,
				'rows'       => $progress['rows'],
				'total_rows' => (int) $state['total_rows'],
				'inserted'   => $progress['inserted'],
				'updated'    => $progress['updated'],
				'unchanged'  => $progress['unchanged'],
				'skipped'    => $progress['skipped'],
				'failed'     => $progress['failed'],
				'not_in_crm' => $progress['not_in_crm'],
				'reasons'    => $progress['reasons'],
				'samples'    => $progress['samples'],
			);
		}

		$summary = $this->summary( $state, $progress );
		$result  = bin2hex( random_bytes( 16 ) );

		set_transient(
			'nowcrm_csv_result_' . $result,
			array_merge( array( 'user_id' => $user_id ), $summary ),
			self::DRAFT_TTL
		);

		CsvStorage::delete( $token );

		return array_merge(
			array(
				'completed'    => true,
				'result_token' => $result,
			),
			$summary
		);
	}

	/**
	 * Read a finished summary for its owner.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function result( string $token, int $user_id ): ?array {
		$token = preg_match( '/^[a-f0-9]{32}$/', $token ) ? $token : '';

		if ( '' === $token || $user_id <= 0 ) {
			return null;
		}

		$summary = get_transient( 'nowcrm_csv_result_' . $token );

		if ( ! is_array( $summary ) || (int) ( $summary['user_id'] ?? 0 ) !== $user_id ) {
			return null;
		}

		return $summary;
	}

	/**
	 * Keep a rejected mapping so the screen can show the user's edits again.
	 *
	 * @param array<string, mixed> $mapping Submitted mapping.
	 * @param array<string, mixed> $options Submitted options.
	 */
	public static function save_draft( string $token, array $mapping, array $options, int $user_id ): void {
		$token = CsvStorage::clean_token( $token );

		if ( '' === $token ) {
			return;
		}

		set_transient(
			'nowcrm_csv_draft_' . $token,
			array(
				'user_id' => $user_id,
				'mapping' => $mapping,
				'options' => $options,
			),
			self::DRAFT_TTL
		);
	}

	/**
	 * Read a rejected mapping draft for its owner.
	 *
	 * @return array{mapping: array<string, mixed>, options: array<string, mixed>}
	 */
	public static function draft( string $token, int $user_id ): array {
		$token = CsvStorage::clean_token( $token );

		if ( '' !== $token ) {
			$draft = get_transient( 'nowcrm_csv_draft_' . $token );

			if ( is_array( $draft ) && (int) ( $draft['user_id'] ?? 0 ) === $user_id ) {
				return array(
					'mapping' => (array) ( $draft['mapping'] ?? array() ),
					'options' => (array) ( $draft['options'] ?? array() ),
				);
			}
		}

		return array(
			'mapping' => array(),
			'options' => array(),
		);
	}

	/**
	 * Delete a mapping draft.
	 */
	public static function clear_draft( string $token ): void {
		$token = CsvStorage::clean_token( $token );

		if ( '' !== $token ) {
			delete_transient( 'nowcrm_csv_draft_' . $token );
		}
	}

	/**
	 * Process one row.
	 *
	 * @param array<int, string|null> $record   Parsed record.
	 * @param array<string, mixed>    $state    Run state.
	 * @param array<string, mixed>    $options  Validated options.
	 * @param array<string, mixed>    $progress Progress, modified for not_in_crm.
	 * @param int                     $row      1-based data row number.
	 * @param array<string, bool>     $seen     Emails seen in this batch.
	 * @return array{outcome: string, reason: string}
	 */
	private function import_row(
		array $record,
		array $state,
		array $options,
		array &$progress,
		int $row,
		array &$seen,
		int $user_id
	): array {
		if ( array() === array_filter( $record, static fn( $value ) => null !== $value && '' !== trim( (string) $value ) ) ) {
			return array( 'outcome' => 'skipped', 'reason' => 'empty' );
		}

		$values = array();

		foreach ( (array) $state['mapping'] as $index => $target ) {
			$index          = (int) $index;
			$values[ $target ] = isset( $record[ $index ] ) && is_scalar( $record[ $index ] )
				? trim( (string) $record[ $index ] )
				: '';
		}

		$email = (string) ( $values['email'] ?? '' );

		if ( '' === $email || ! is_email( $email ) ) {
			return array( 'outcome' => 'skipped', 'reason' => 'invalid_email' );
		}

		$email = $this->contacts->normalize_email( $email );

		if ( isset( $seen[ $email ] ) ) {
			return array( 'outcome' => 'skipped', 'reason' => 'duplicate_in_file' );
		}

		$seen[ $email ] = true;

		if ( '' === ( $values['first_name'] ?? '' ) && '' === ( $values['last_name'] ?? '' ) && '' !== ( $values['full_name'] ?? '' ) ) {
			$parts            = explode( ' ', $values['full_name'] );
			$values['first_name'] = (string) array_shift( $parts );

			if ( array() !== $parts ) {
				$values['last_name'] = implode( ' ', $parts );
			}
		}

		$profile = array();

		foreach ( ProfileFields::keys() as $key ) {
			if ( isset( $values[ $key ] ) && '' !== $values[ $key ] ) {
				$profile[ $key ] = $values[ $key ];
			}
		}

		if ( isset( $profile['date_of_birth'] ) ) {
			$date = self::parse_date( $profile['date_of_birth'] );

			if ( false === $date ) {
				return array( 'outcome' => 'skipped', 'reason' => 'invalid_date' );
			}

			$profile['date_of_birth'] = $date;
		}

		$profile = ProfileFields::normalize( $profile );

		if ( is_wp_error( $profile ) ) {
			return array( 'outcome' => 'skipped', 'reason' => 'invalid_field' );
		}

		$core = array();

		foreach ( array( 'first_name', 'last_name', 'phone' ) as $key ) {
			if ( isset( $values[ $key ] ) && '' !== $values[ $key ] ) {
				$core[ $key ] = $values[ $key ];
			}
		}

		$custom = array();

		foreach ( $values as $target => $value ) {
			if ( ! str_starts_with( (string) $target, self::CUSTOM_PREFIX ) || '' === $value ) {
				continue;
			}

			$field = CustomFieldDefinitions::get( substr( (string) $target, strlen( self::CUSTOM_PREFIX ) ) );

			if ( null === $field ) {
				continue;
			}

			$custom[ $field['slug'] ] = CustomFieldDefinitions::is_multi( $field['type'] )
				? self::split_names( $value )
				: $value;
		}

		$existing = $this->contacts->find_by_email( $email );

		if ( ! $existing && $options['existing_only'] ) {
			++$progress['not_in_crm'];

			return array( 'outcome' => 'skipped', 'reason' => 'not_in_crm' );
		}

		if ( $existing ) {
			return $this->update_existing( $existing, $values, $core, $profile, $custom, $options, $user_id );
		}

		return $this->create_new( $email, $values, $core, $profile, $custom, $options, $user_id );
	}

	/**
	 * Apply one row to an existing contact.
	 *
	 * @param array<string, mixed> $existing Existing row.
	 * @param array<string, mixed> $values   Mapped raw values.
	 * @param array<string, mixed> $core     first_name, last_name, phone.
	 * @param array<string, mixed> $profile  Normalized profile fields.
	 * @param array<string, mixed> $custom   Custom values.
	 * @param array<string, mixed> $options  Options.
	 * @return array{outcome: string, reason: string}
	 */
	private function update_existing(
		array $existing,
		array $values,
		array $core,
		array $profile,
		array $custom,
		array $options,
		int $user_id
	): array {
		$id = (int) $existing['id'];

		if ( ! $options['update'] ) {
			$this->attach_terms( $id, $values, $options );
			$this->attach_company( $id, $values );

			return array( 'outcome' => 'unchanged', 'reason' => '' );
		}

		$prepared = null;

		if ( array() !== $custom ) {
			$prepared = $this->values->prepare( $id, $custom );

			if ( is_wp_error( $prepared ) ) {
				return array( 'outcome' => 'skipped', 'reason' => 'invalid_custom' );
			}

			if ( $options['keep_existing_data'] ) {
				$stored = $this->values->for_contact( $id );

				foreach ( array_keys( (array) $prepared['values'] ) as $key ) {
					if ( self::filled( $stored[ $key ] ?? null ) ) {
						unset( $prepared['values'][ $key ] );
					}
				}
			}
		}

		$patch = array_merge( $core, $profile );

		if ( $options['keep_existing_data'] ) {
			foreach ( array_keys( $patch ) as $key ) {
				if ( self::filled( $existing[ $key ] ?? null ) ) {
					unset( $patch[ $key ] );
				}
			}
		}

		$changed = false;

		if ( array() !== $patch ) {
			$updated = $this->contacts->update( $id, $patch );

			if ( is_wp_error( $updated ) ) {
				return array( 'outcome' => 'failed', 'reason' => 'write_failed' );
			}

			$changed = true;
		}

		if ( is_array( $prepared ) && array() !== (array) $prepared['values'] ) {
			$saved = $this->values->commit( $id, $prepared, false );

			if ( is_wp_error( $saved ) ) {
				return array( 'outcome' => 'failed', 'reason' => 'write_failed' );
			}

			$changed = true;
		}

		$desired = (string) $options['status'];
		$current = (string) $existing['status'];

		if ( $desired !== $current && ( $options['force_update_status'] || Status::is_suppressed( $desired ) ) ) {
			$status = $this->contacts->set_status( $id, $desired, 'csv', '', $user_id );

			if ( is_wp_error( $status ) ) {
				return array( 'outcome' => 'failed', 'reason' => 'write_failed' );
			}

			$changed = true;
		}

		$this->attach_terms( $id, $values, $options );
		$this->attach_company( $id, $values );

		return array(
			'outcome' => $changed ? 'updated' : 'unchanged',
			'reason'  => '',
		);
	}

	/**
	 * Create a contact from one row.
	 *
	 * @param array<string, mixed> $values  Mapped raw values.
	 * @param array<string, mixed> $core    first_name, last_name, phone.
	 * @param array<string, mixed> $profile Normalized profile fields.
	 * @param array<string, mixed> $custom  Custom values.
	 * @param array<string, mixed> $options Options.
	 * @return array{outcome: string, reason: string}
	 */
	private function create_new(
		string $email,
		array $values,
		array $core,
		array $profile,
		array $custom,
		array $options,
		int $user_id
	): array {
		$data = array_merge(
			$core,
			$profile,
			array(
				'email'    => $email,
				'status'   => (string) $options['status'],
				'source'   => '' !== ( $values['source'] ?? '' ) ? $values['source'] : 'csv',
				'actor_id' => $user_id,
			)
		);

		$id = $this->contacts->create( $data );

		if ( is_wp_error( $id ) ) {
			if ( 'nowcrm_duplicate_email' === $id->get_error_code() ) {
				return array( 'outcome' => 'skipped', 'reason' => 'duplicate_in_file' );
			}

			return array( 'outcome' => 'failed', 'reason' => 'write_failed' );
		}

		$contact_id = (int) $id;

		if ( array() !== $custom ) {
			$prepared = $this->values->prepare( $contact_id, $custom );

			if ( is_wp_error( $prepared ) ) {
				$this->contacts->delete( $contact_id );

				return array( 'outcome' => 'skipped', 'reason' => 'invalid_custom' );
			}

			$saved = $this->values->commit( $contact_id, $prepared, false );

			if ( is_wp_error( $saved ) ) {
				$this->contacts->delete( $contact_id );

				return array( 'outcome' => 'failed', 'reason' => 'write_failed' );
			}
		}

		$this->attach_terms( $contact_id, $values, $options );
		$this->attach_company( $contact_id, $values );

		/**
		 * Double opt-in seam. The separately owned workflow can subscribe here
		 * and decide whether to send confirmation mail. This importer never
		 * sends mail itself.
		 */
		do_action( 'nowcrm_csv_contact_imported', $contact_id, $email, $values );

		return array( 'outcome' => 'inserted', 'reason' => '' );
	}

	/**
	 * Attach selected and row-level lists and tags. Never detaches.
	 *
	 * @param array<string, mixed> $values  Mapped raw values.
	 * @param array<string, mixed> $options Options.
	 */
	private function attach_terms( int $contact_id, array $values, array $options ): void {
		$ids = array_merge( (array) $options['list_ids'], (array) $options['tag_ids'] );

		foreach ( $this->row_terms( (string) ( $values['lists'] ?? '' ) ) as $name ) {
			$id = $this->terms->ensure( TermRepository::TYPE_LIST, $name );

			if ( $id > 0 ) {
				$ids[] = $id;
			}
		}

		foreach ( $this->row_terms( (string) ( $values['tags'] ?? '' ) ) as $name ) {
			$id = $this->terms->ensure( TermRepository::TYPE_TAG, $name );

			if ( $id > 0 ) {
				$ids[] = $id;
			}
		}

		foreach ( array_unique( array_filter( array_map( 'absint', $ids ) ) ) as $term_id ) {
			$this->terms->attach( $contact_id, (int) $term_id );
		}
	}

	/**
	 * Attach a mapped company name, creating the company when it is missing.
	 *
	 * @param array<string, mixed> $values Mapped raw values.
	 */
	private function attach_company( int $contact_id, array $values ): void {
		$name = sanitize_text_field( (string) ( $values['company'] ?? '' ) );

		if ( '' === $name ) {
			return;
		}

		if ( mb_strlen( $name, 'UTF-8' ) > 191 ) {
			$name = mb_substr( $name, 0, 191, 'UTF-8' );
		}

		$company = $this->companies->find_by_name( $name );

		if ( ! $company ) {
			$created = $this->companies->create( array( 'name' => $name ) );
			$company = is_int( $created ) ? $this->companies->get( $created ) : $this->companies->find_by_name( $name );
		}

		if ( $company ) {
			$this->companies->attach_contacts( (int) $company['id'], array( $contact_id ) );
		}
	}

	/**
	 * Split a row cell of list or tag names. Inner CSV keeps quoted commas.
	 *
	 * @return string[]
	 */
	private function row_terms( string $value ): array {
		if ( '' === trim( $value ) ) {
			return array();
		}

		$names = array();

		foreach ( self::split_names( $value ) as $name ) {
			$name = sanitize_text_field( $name );

			if ( '' === $name ) {
				continue;
			}

			if ( mb_strlen( $name, 'UTF-8' ) > 191 ) {
				$name = mb_substr( $name, 0, 191, 'UTF-8' );
			}

			$names[ $name ] = $name;

			if ( count( $names ) >= self::MAX_ROW_TERMS ) {
				break;
			}
		}

		return array_values( $names );
	}

	/**
	 * Split a comma cell, keeping quoted values intact.
	 *
	 * @return string[]
	 */
	private static function split_names( string $value ): array {
		if ( '' === trim( $value ) ) {
			return array();
		}

		$parts = str_getcsv( $value, ',', '"', '\\' );
		$names = array();

		foreach ( (array) $parts as $part ) {
			$part = trim( (string) $part );

			if ( '' !== $part ) {
				$names[] = $part;
			}
		}

		return array_values( array_unique( $names ) );
	}

	/**
	 * Increment the outcome counters.
	 *
	 * @param array<string, mixed> $progress Progress.
	 * @param array<string, mixed> $outcome  Outcome and reason.
	 */
	private function apply_outcome( array &$progress, array $outcome, int $row ): void {
		$outcome_key = (string) ( $outcome['outcome'] ?? 'skipped' );
		$reason      = (string) ( $outcome['reason'] ?? '' );

		if ( in_array( $outcome_key, array( 'inserted', 'updated', 'unchanged' ), true ) ) {
			++$progress[ $outcome_key ];

			return;
		}

		if ( 'failed' === $outcome_key ) {
			++$progress['failed'];
		} else {
			++$progress['skipped'];
		}

		$key = '' !== $reason ? $reason : 'unknown';
		$progress['reasons'][ $key ] = (int) ( $progress['reasons'][ $key ] ?? 0 ) + 1;

		if ( count( (array) ( $progress['samples'][ $key ] ?? array() ) ) < self::MAX_SAMPLES ) {
			$progress['samples'][ $key ][] = $row;
		}
	}

	/**
	 * Default progress shape. Byte offset starts after the header.
	 *
	 * @return array<string, mixed>
	 */
	private function empty_progress(): array {
		return array(
			'rows'        => 0,
			'byte_offset' => 0,
			'inserted'    => 0,
			'updated'     => 0,
			'unchanged'   => 0,
			'skipped'     => 0,
			'failed'      => 0,
			'not_in_crm'  => 0,
			'reasons'     => array(),
			'samples'     => array(),
		);
	}

	/**
	 * PII-free completion summary.
	 *
	 * @param array<string, mixed> $state    State.
	 * @param array<string, mixed> $progress Progress.
	 * @return array<string, mixed>
	 */
	private function summary( array $state, array $progress ): array {
		return array(
			'total'      => (int) $state['total_rows'],
			'rows'       => (int) $progress['rows'],
			'inserted'   => (int) $progress['inserted'],
			'updated'    => (int) $progress['updated'],
			'unchanged'  => (int) $progress['unchanged'],
			'skipped'    => (int) $progress['skipped'],
			'failed'     => (int) $progress['failed'],
			'not_in_crm' => (int) $progress['not_in_crm'],
			'reasons'    => (array) $progress['reasons'],
			'samples'    => (array) $progress['samples'],
		);
	}

	/**
	 * Validate and deduplicate selected term IDs.
	 *
	 * @param array<int|string, mixed> $ids  Raw IDs.
	 * @param string                   $type Required term type.
	 * @return int[]|WP_Error
	 */
	private function clean_term_ids( array $ids, string $type ) {
		$clean = array();

		foreach ( $ids as $id ) {
			if ( ! is_scalar( $id ) ) {
				continue;
			}

			$id = absint( $id );

			if ( $id > 0 ) {
				$clean[ $id ] = $id;
			}
		}

		if ( count( $clean ) > self::MAX_TERMS ) {
			return new WP_Error( 'nowcrm_csv_too_many_terms', __( '一次最多選擇 100 個名單或標籤。', 'now-crm' ) );
		}

		foreach ( $clean as $id ) {
			$term = $this->terms->get( $id );

			if ( ! $term || (string) $term['type'] !== $type ) {
				return new WP_Error( 'nowcrm_csv_invalid_terms', __( '名單或標籤資料不正確。', 'now-crm' ) );
			}
		}

		return array_values( $clean );
	}

	/**
	 * Parse a CSV date. Same accepted shapes as the reference importer.
	 *
	 * @return string|false
	 */
	public static function parse_date( string $value ) {
		$value = trim( $value );

		if ( preg_match( '/^(\d{4})[-\/.](\d{1,2})[-\/.](\d{1,2})(?:[ T]\d{1,2}:\d{2}(?::\d{2})?)?$/', $value, $match ) ) {
			list( , $year, $month, $day ) = $match;
		} elseif ( preg_match( '/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $value, $match ) ) {
			list( , $month, $day, $year ) = $match;
		} elseif ( preg_match( '/^(\d{1,2})[-.](\d{1,2})[-.](\d{4})$/', $value, $match ) ) {
			list( , $day, $month, $year ) = $match;
		} else {
			return false;
		}

		if ( ! checkdate( (int) $month, (int) $day, (int) $year ) ) {
			return false;
		}

		return sprintf( '%04d-%02d-%02d', (int) $year, (int) $month, (int) $day );
	}

	/**
	 * A stored value counts as filled unless it is null or an empty string.
	 *
	 * @param mixed $value Stored value.
	 */
	private static function filled( $value ): bool {
		return null !== $value && '' !== $value;
	}

	/**
	 * Strip a UTF-8 BOM and convert Windows-1252 content to UTF-8.
	 */
	private static function normalize_encoding( string $contents ): string {
		if ( str_starts_with( $contents, "\xEF\xBB\xBF" ) ) {
			$contents = substr( $contents, 3 );
		}

		$is_utf8 = function_exists( 'mb_check_encoding' )
			? mb_check_encoding( $contents, 'UTF-8' )
			: (bool) preg_match( '//u', $contents );

		if ( $is_utf8 ) {
			return $contents;
		}

		if ( function_exists( 'mb_convert_encoding' ) ) {
			$converted = mb_convert_encoding( $contents, 'UTF-8', 'Windows-1252' );
		} elseif ( function_exists( 'iconv' ) ) {
			$converted = @iconv( 'Windows-1252', 'UTF-8//IGNORE', $contents );
		} else {
			return $contents;
		}

		return is_string( $converted ) && '' !== $converted ? $converted : $contents;
	}
}
