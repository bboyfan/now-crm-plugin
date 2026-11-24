<?php
/**
 * Contact CSV import and export screen.
 *
 * The screen is settings-free: the upload, the mapping, and the progress all
 * live in the owner-scoped temporary run, not in an option. Each stage is a
 * normal form POST, so the flow works without JavaScript; the progress stage
 * adds an optional auto-submit for long files.
 *
 * @package NowCRM
 */

namespace NowCRM\Admin;

use NowCRM\Contacts\AdvancedFilters;
use NowCRM\Contacts\CsvExport;
use NowCRM\Contacts\CustomFieldDefinitions;
use NowCRM\Contacts\CsvImport;
use NowCRM\Contacts\CsvStorage;
use NowCRM\Contacts\Status;
use NowCRM\Support\Capabilities;
use NowCRM\Terms\TermRepository;

defined( 'ABSPATH' ) || exit;

final class ContactCsvPage {

	/**
	 * Notice code => message. Unknown codes are ignored.
	 *
	 * @return array<string, string>
	 */
	private static function notices(): array {
		return array(
			'invalid_upload'         => __( 'CSV 上傳不正確，請確認檔案是 .csv 且小於 10 MB。', 'now-crm' ),
			'upload_failed'          => __( '檔案上傳失敗，請再試一次。', 'now-crm' ),
			'duplicate_headers'      => __( 'CSV 標題重複，請刪除重複欄位。', 'now-crm' ),
			'empty_header'           => __( 'CSV 有空白標題，請補上欄位名稱。', 'now-crm' ),
			'empty_csv'              => __( 'CSV 沒有資料列。', 'now-crm' ),
			'too_many_rows'          => __( 'CSV 超過 100,000 列上限。', 'now-crm' ),
			'too_many_columns'       => __( 'CSV 欄位超過 200 欄上限。', 'now-crm' ),
			'invalid_mapping'        => __( '欄位對應不正確，請重新確認。', 'now-crm' ),
			'missing_email'          => __( '必須將一個欄位對應到 Email。', 'now-crm' ),
			'unknown_field'          => __( '對應到不存在或不可匯入的欄位。', 'now-crm' ),
			'duplicate_mapping'      => __( '同一個欄位不能被對應兩次。', 'now-crm' ),
			'invalid_options'        => __( '匯入選項不正確。', 'now-crm' ),
			'invalid_status'         => __( '新聯絡人的狀態不正確。', 'now-crm' ),
			'invalid_terms'          => __( '名單或標籤資料不正確。', 'now-crm' ),
			'too_many_terms'         => __( '一次最多選擇 100 個名單或標籤。', 'now-crm' ),
			'expired'                => __( '匯入工作階段已失效，請重新上傳。', 'now-crm' ),
			'started'                => __( '匯入已經開始，不能重設對應。', 'now-crm' ),
			'invalid_columns'        => __( '請至少選擇一個匯出欄位。', 'now-crm' ),
			'no_contacts'            => __( '目前範圍沒有可匯出的聯絡人。', 'now-crm' ),
			'no_selection'           => __( '請先選擇要匯出的聯絡人。', 'now-crm' ),
			'too_many_selected'      => __( '一次最多匯出 500 位選取的聯絡人；大量匯出請用篩選範圍。', 'now-crm' ),
			'export_too_many'        => __( '匯出超過 100,000 筆，請縮小篩選範圍。', 'now-crm' ),
			'invalid_filter'         => __( '進階篩選條件不正確，未匯出任何資料。請回到聯絡人清單修正條件。', 'now-crm' ),
		);
	}

	/**
	 * Render the requested stage.
	 */
	public static function render(): void {
		if ( ! Capabilities::can_manage_contacts() ) {
			wp_die(
				esc_html__( '您沒有權限使用聯絡人匯入／匯出。', 'now-crm' ),
				'',
				array( 'response' => 403 )
			);
		}

		CsvStorage::purge_expired();

		$tab = 'export' === Menu::query( 'tab' ) ? 'export' : 'import';
		?>
		<div class="wrap nowcrm-wrap">
			<?php ContactsPage::render_nav( 'all' ); ?>
			<h1><?php esc_html_e( '聯絡人匯入／匯出', 'now-crm' ); ?></h1>
			<?php self::render_notice(); ?>

			<nav class="nav-tab-wrapper">
				<a class="nav-tab <?php echo 'import' === $tab ? 'nav-tab-active' : ''; ?>"
					href="<?php echo esc_url( Menu::page_url( ContactCsvAdmin::SLUG, array( 'tab' => 'import' ) ) ); ?>">
					<?php esc_html_e( '匯入 CSV', 'now-crm' ); ?>
				</a>
				<a class="nav-tab <?php echo 'export' === $tab ? 'nav-tab-active' : ''; ?>"
					href="<?php echo esc_url( Menu::page_url( ContactCsvAdmin::SLUG, array( 'tab' => 'export' ) ) ); ?>">
					<?php esc_html_e( '匯出 CSV', 'now-crm' ); ?>
				</a>
			</nav>

			<?php
			if ( 'export' === $tab ) {
				self::render_export();
			} else {
				self::render_import();
			}
			?>
		</div>
		<?php
	}

	/**
	 * Render an allow-listed notice.
	 */
	private static function render_notice(): void {
		$code = sanitize_key( Menu::query( 'nowcrm_csv_notice' ) );

		if ( '' === $code ) {
			return;
		}

		$messages = self::notices();
		$message  = $messages[ $code ] ?? '';

		if ( '' === $message ) {
			return;
		}

		echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
	}

	/**
	 * Import stage: upload, mapping, progress, or result.
	 */
	private static function render_import(): void {
		$result_token = sanitize_key( Menu::query( 'result' ) );

		if ( '' !== $result_token ) {
			$summary = CsvImport::result( $result_token, get_current_user_id() );

			if ( is_array( $summary ) ) {
				self::render_result( $summary );
				return;
			}
		}

		$token = CsvStorage::clean_token( Menu::query( 'token' ) );
		$state = '' === $token ? null : CsvStorage::read( $token, get_current_user_id() );

		if ( is_array( $state ) && is_array( $state['mapping'] ) ) {
			self::render_progress( $state );
			return;
		}

		if ( is_array( $state ) ) {
			self::render_mapping( $state );
			return;
		}

		self::render_upload();
	}

	/**
	 * Upload form.
	 */
	private static function render_upload(): void {
		?>
		<div class="nowcrm-panel">
			<h2><?php esc_html_e( '上傳聯絡人 CSV', 'now-crm' ); ?></h2>
			<p class="description">
				<?php esc_html_e( '第一列必須是欄位名稱，標題不能重複。支援 UTF-8（含 BOM）與 Windows-1252；檔案上限 10 MB、100,000 列。', 'now-crm' ); ?>
			</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data" class="nowcrm-inline-form">
				<input type="hidden" name="action" value="<?php echo esc_attr( ContactCsvAdmin::ACTION_UPLOAD ); ?>" />
				<?php wp_nonce_field( ContactCsvAdmin::NONCE_UPLOAD ); ?>

				<label>
					<span><?php esc_html_e( 'CSV 檔案', 'now-crm' ); ?></span>
					<input type="file" name="nowcrm_csv_file" accept=".csv,text/csv,text/plain" required="required" />
				</label>

				<fieldset>
					<legend><?php esc_html_e( '分隔符號', 'now-crm' ); ?></legend>
					<label><input type="radio" name="delimiter" value="comma" checked="checked" /> <?php esc_html_e( '逗號（,）', 'now-crm' ); ?></label>
					<label><input type="radio" name="delimiter" value="semicolon" /> <?php esc_html_e( '分號（;）', 'now-crm' ); ?></label>
				</fieldset>

				<button type="submit" class="button button-primary"><?php esc_html_e( '上傳並選擇欄位', 'now-crm' ); ?></button>
			</form>

			<p class="description">
				<?php esc_html_e( '匯入不會寄送任何郵件。雙重訂閱確認信由另一個流程負責；匯入只會在新聯絡人建立後發出 nowcrm_csv_contact_imported 動作。', 'now-crm' ); ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Mapping and option form.
	 *
	 * @param array<string, mixed> $state Run state.
	 */
	private static function render_mapping( array $state ): void {
		$token   = (string) $state['token'];
		$headers = (array) $state['headers'];
		$rows    = ( new CsvImport() )->preview( $token, get_current_user_id() );
		$draft   = CsvImport::draft( $token, get_current_user_id() );

		$mapping = array() !== $draft['mapping']
			? $draft['mapping']
			: CsvImport::suggest_mapping( $headers );
		$options = $draft['options'];

		$field_options = array( '' => __( '（不匯入）', 'now-crm' ) ) + CsvImport::fields();
		$custom        = array();

		foreach ( CustomFieldDefinitions::all() as $field ) {
			$custom[ CsvImport::CUSTOM_PREFIX . $field['slug'] ] = $field['label'] . ' (' . $field['slug'] . ')';
		}

		$terms   = new TermRepository();
		$lists   = $terms->all( TermRepository::TYPE_LIST );
		$tags    = $terms->all( TermRepository::TYPE_TAG );
		?>
		<div class="nowcrm-panel">
			<h2><?php esc_html_e( '欄位對應', 'now-crm' ); ?></h2>
			<p class="description">
				<?php
				printf(
					/* translators: 1: data rows, 2: file name. */
					esc_html__( '檔案「%2$s」共 %1$d 列。Email 為必填；未對應的欄位會忽略。', 'now-crm' ),
					(int) $state['total_rows'],
					esc_html( (string) $state['original_name'] )
				);
				?>
			</p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( ContactCsvAdmin::ACTION_START ); ?>" />
				<input type="hidden" name="token" value="<?php echo esc_attr( $token ); ?>" />
				<?php wp_nonce_field( ContactCsvAdmin::NONCE_START ); ?>

				<table class="widefat striped">
					<thead><tr>
						<th><?php esc_html_e( 'CSV 欄位', 'now-crm' ); ?></th>
						<th><?php esc_html_e( '範例', 'now-crm' ); ?></th>
						<th><?php esc_html_e( '對應到', 'now-crm' ); ?></th>
					</tr></thead>
					<tbody>
					<?php foreach ( $headers as $index => $header ) : ?>
						<?php
						$sample = '';

						foreach ( $rows as $row ) {
							if ( '' !== (string) ( $row[ $index ] ?? '' ) ) {
								$sample = (string) $row[ $index ];
								break;
							}
						}

						$selected = (string) ( $mapping[ $index ] ?? '' );
						?>
						<tr>
							<td><strong><?php echo esc_html( (string) $header ); ?></strong></td>
							<td><?php echo esc_html( $sample ); ?></td>
							<td>
								<select name="mapping[<?php echo esc_attr( (string) $index ); ?>]">
									<?php foreach ( $field_options as $value => $label ) : ?>
										<option value="<?php echo esc_attr( (string) $value ); ?>" <?php selected( $selected, (string) $value ); ?>>
											<?php echo esc_html( $label ); ?>
										</option>
									<?php endforeach; ?>
									<?php if ( array() !== $custom ) : ?>
										<optgroup label="<?php esc_attr_e( '自訂欄位', 'now-crm' ); ?>">
											<?php foreach ( $custom as $value => $label ) : ?>
												<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $selected, $value ); ?>>
													<?php echo esc_html( $label ); ?>
												</option>
											<?php endforeach; ?>
										</optgroup>
									<?php endif; ?>
								</select>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>

				<h3><?php esc_html_e( '匯入選項', 'now-crm' ); ?></h3>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="nowcrm-csv-status"><?php esc_html_e( '新聯絡人狀態', 'now-crm' ); ?></label></th>
						<td>
							<select id="nowcrm-csv-status" name="options[status]">
								<?php foreach ( Status::all() as $status ) : ?>
									<option value="<?php echo esc_attr( $status ); ?>" <?php selected( (string) ( $options['status'] ?? Status::SUBSCRIBED ), $status ); ?>>
										<?php echo esc_html( Status::label( $status ) ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( '只套用到新聯絡人。既有聯絡人的狀態需勾選「強制更新狀態」，但非強制匯入永遠可以將聯絡人移入抑制狀態。', 'now-crm' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( '既有聯絡人', 'now-crm' ); ?></th>
						<td>
							<label><input type="checkbox" name="options[update]" value="1" <?php checked( ! empty( $options['update'] ) ); ?> /> <?php esc_html_e( '更新既有聯絡人資料', 'now-crm' ); ?></label><br />
							<label><input type="checkbox" name="options[existing_only]" value="1" <?php checked( ! empty( $options['existing_only'] ) ); ?> /> <?php esc_html_e( '只匯入既有聯絡人（自動開啟更新）', 'now-crm' ); ?></label><br />
							<label><input type="checkbox" name="options[keep_existing_data]" value="1" <?php checked( ! empty( $options['keep_existing_data'] ) ); ?> /> <?php esc_html_e( '保留既有資料：只填入目前是空的欄位', 'now-crm' ); ?></label><br />
							<label><input type="checkbox" name="options[force_update_status]" value="1" <?php checked( ! empty( $options['force_update_status'] ) ); ?> /> <?php esc_html_e( '強制更新狀態（可將抑制狀態改回可寄送）', 'now-crm' ); ?></label>
							<p class="description"><?php esc_html_e( '空白儲存格不會清除既有資料。名單與標籤只會加入，不會移除。', 'now-crm' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="nowcrm-csv-lists"><?php esc_html_e( '加入名單', 'now-crm' ); ?></label></th>
						<td>
							<select id="nowcrm-csv-lists" name="options[list_ids][]" multiple="multiple" size="6">
								<?php foreach ( $lists as $term ) : ?>
									<option value="<?php echo esc_attr( (string) $term['id'] ); ?>" <?php selected( in_array( (int) $term['id'], array_map( 'intval', (array) ( $options['list_ids'] ?? array() ) ), true ) ); ?>>
										<?php echo esc_html( (string) $term['name'] ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="nowcrm-csv-tags"><?php esc_html_e( '加入標籤', 'now-crm' ); ?></label></th>
						<td>
							<select id="nowcrm-csv-tags" name="options[tag_ids][]" multiple="multiple" size="6">
								<?php foreach ( $tags as $term ) : ?>
									<option value="<?php echo esc_attr( (string) $term['id'] ); ?>" <?php selected( in_array( (int) $term['id'], array_map( 'intval', (array) ( $options['tag_ids'] ?? array() ) ), true ) ); ?>>
										<?php echo esc_html( (string) $term['name'] ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'CSV 的「名單」「標籤」欄位也會加入；不存在的名稱會自動建立。', 'now-crm' ); ?></p>
						</td>
					</tr>
				</table>

				<button type="submit" class="button button-primary"><?php esc_html_e( '開始匯入', 'now-crm' ); ?></button>
				<a class="button" href="<?php echo esc_url( Menu::page_url( ContactCsvAdmin::SLUG, array( 'tab' => 'import' ) ) ); ?>">
					<?php esc_html_e( '取消', 'now-crm' ); ?>
				</a>
			</form>
		</div>
		<?php
	}

	/**
	 * Progress stage with the next bounded batch.
	 *
	 * @param array<string, mixed> $state Run state.
	 */
	private static function render_progress( array $state ): void {
		$progress = (array) $state['progress'];
		$total    = (int) $state['total_rows'];
		$rows     = (int) $progress['rows'];
		?>
		<div class="nowcrm-panel">
			<h2><?php esc_html_e( '匯入進行中', 'now-crm' ); ?></h2>
			<p>
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: consumed rows, 2: total rows. */
						__( '已處理 %1$d / %2$d 列。每個請求最多處理 100 列。', 'now-crm' ),
						$rows,
						$total
					)
				);
				?>
			</p>
			<ul class="nowcrm-meta">
				<li><span><?php esc_html_e( '新增', 'now-crm' ); ?></span><strong><?php echo esc_html( (string) $progress['inserted'] ); ?></strong></li>
				<li><span><?php esc_html_e( '更新', 'now-crm' ); ?></span><strong><?php echo esc_html( (string) $progress['updated'] ); ?></strong></li>
				<li><span><?php esc_html_e( '未變更', 'now-crm' ); ?></span><strong><?php echo esc_html( (string) $progress['unchanged'] ); ?></strong></li>
				<li><span><?php esc_html_e( '略過', 'now-crm' ); ?></span><strong><?php echo esc_html( (string) $progress['skipped'] ); ?></strong></li>
				<li><span><?php esc_html_e( '失敗', 'now-crm' ); ?></span><strong><?php echo esc_html( (string) $progress['failed'] ); ?></strong></li>
			</ul>

			<form id="nowcrm-csv-batch" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( ContactCsvAdmin::ACTION_BATCH ); ?>" />
				<input type="hidden" name="token" value="<?php echo esc_attr( (string) $state['token'] ); ?>" />
				<?php wp_nonce_field( ContactCsvAdmin::NONCE_BATCH ); ?>
				<button type="submit" class="button button-primary"><?php esc_html_e( '繼續處理下一批', 'now-crm' ); ?></button>
			</form>

			<p class="description"><?php esc_html_e( '暫存檔會在匯入完成或 2 小時後刪除。離開此頁不會取消匯入，但工作階段過期後必須重新上傳。', 'now-crm' ); ?></p>
			<script>
			document.addEventListener('DOMContentLoaded', function () {
				var form = document.getElementById('nowcrm-csv-batch');
				if (form) { form.submit(); }
			});
			</script>
		</div>
		<?php
	}

	/**
	 * Completion summary.
	 *
	 * @param array<string, mixed> $summary Stored summary.
	 */
	private static function render_result( array $summary ): void {
		$labels = array(
			'empty'              => __( '空白列', 'now-crm' ),
			'invalid_email'      => __( 'Email 格式不正確', 'now-crm' ),
			'duplicate_in_file'  => __( '檔案內重複的 Email', 'now-crm' ),
			'not_in_crm'         => __( '不存在於 CRM（只匯入既有聯絡人）', 'now-crm' ),
			'invalid_date'       => __( '出生日期格式不正確', 'now-crm' ),
			'invalid_field'      => __( '標準欄位內容不正確', 'now-crm' ),
			'invalid_custom'     => __( '自訂欄位內容不正確', 'now-crm' ),
			'write_failed'       => __( '資料庫寫入失敗', 'now-crm' ),
			'unknown'            => __( '其他原因', 'now-crm' ),
		);
		?>
		<div class="nowcrm-panel">
			<h2><?php esc_html_e( '匯入完成', 'now-crm' ); ?></h2>
			<ul class="nowcrm-meta">
				<li><span><?php esc_html_e( '資料列', 'now-crm' ); ?></span><strong><?php echo esc_html( (string) $summary['total'] ); ?></strong></li>
				<li><span><?php esc_html_e( '新增', 'now-crm' ); ?></span><strong><?php echo esc_html( (string) $summary['inserted'] ); ?></strong></li>
				<li><span><?php esc_html_e( '更新', 'now-crm' ); ?></span><strong><?php echo esc_html( (string) $summary['updated'] ); ?></strong></li>
				<li><span><?php esc_html_e( '未變更', 'now-crm' ); ?></span><strong><?php echo esc_html( (string) $summary['unchanged'] ); ?></strong></li>
				<li><span><?php esc_html_e( '略過', 'now-crm' ); ?></span><strong><?php echo esc_html( (string) $summary['skipped'] ); ?></strong></li>
				<li><span><?php esc_html_e( '失敗', 'now-crm' ); ?></span><strong><?php echo esc_html( (string) $summary['failed'] ); ?></strong></li>
			</ul>

			<?php if ( array() !== (array) $summary['reasons'] ) : ?>
				<table class="widefat striped">
					<thead><tr>
						<th><?php esc_html_e( '原因', 'now-crm' ); ?></th>
						<th><?php esc_html_e( '列數', 'now-crm' ); ?></th>
						<th><?php esc_html_e( '範例列號', 'now-crm' ); ?></th>
					</tr></thead>
					<tbody>
					<?php foreach ( (array) $summary['reasons'] as $reason => $count ) : ?>
						<tr>
							<td><?php echo esc_html( $labels[ $reason ] ?? $reason ); ?></td>
							<td><?php echo esc_html( (string) $count ); ?></td>
							<td>
								<?php
								$samples = array_map( 'absint', (array) ( $summary['samples'][ $reason ] ?? array() ) );
								echo esc_html( implode( ', ', $samples ) );
								?>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<p class="description">
				<?php esc_html_e( '暫存檔已刪除。匯入不會寄送郵件；需要雙重訂閱確認信時，請由雙重訂閱流程接上 nowcrm_csv_contact_imported 動作。', 'now-crm' ); ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Export form.
	 */
	private static function render_export(): void {
		$columns = CsvExport::columns();
		$custom  = CsvExport::custom_columns();
		$terms   = new TermRepository();
		$lists   = $terms->all( TermRepository::TYPE_LIST );
		$tags    = $terms->all( TermRepository::TYPE_TAG );

		$defaults = array( 'email', 'first_name', 'last_name', 'status' );
		$search   = sanitize_text_field( Menu::query( 's' ) );
		$status   = sanitize_key( Menu::query( 'status' ) );
		$term_id  = absint( Menu::query( 'term_id' ) );
		$segment  = sanitize_text_field( Menu::query( 'segment' ) );
		$scope    = 'selected' === sanitize_key( Menu::query( 'scope' ) ) ? 'selected' : 'filtered';
		$ids_raw  = sanitize_text_field( Menu::query( 'contact_ids' ) );
		$ids      = array_values( array_filter( array_map( 'absint', preg_split( '/[\s,]+/', $ids_raw, -1, PREG_SPLIT_NO_EMPTY ) ) ) );

		// The contact list hands its normalized plan to this screen in the URL.
		// An invalid plan blocks the whole export instead of widening it.
		$advanced       = ContactAdvancedFilters::parse_request( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter.
		$advanced_error = is_wp_error( $advanced ) ? $advanced : null;

		if ( null !== $advanced_error ) {
			$advanced = null;
		}

		// Compact list filters arrive as grouped query fields. Compile them
		// with the same validator the list uses, so export and list match.
		$raw_lists    = isset( $_GET['lists'] ) && is_array( $_GET['lists'] ) ? (array) wp_unslash( $_GET['lists'] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter.
		$raw_tags     = isset( $_GET['tags'] ) && is_array( $_GET['tags'] ) ? (array) wp_unslash( $_GET['tags'] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter.
		$raw_statuses = isset( $_GET['statuses'] ) && is_array( $_GET['statuses'] ) ? (array) wp_unslash( $_GET['statuses'] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter.

		if ( null === $advanced && ( array() !== $raw_lists || array() !== $raw_tags || array() !== $raw_statuses ) ) {
			$simple_statuses = array();

			foreach ( $raw_statuses as $raw_status ) {
				$raw_status = is_scalar( $raw_status ) ? sanitize_key( (string) $raw_status ) : '';

				if ( Status::is_valid( $raw_status ) ) {
					$simple_statuses[] = $raw_status;
				}
			}

			if ( array() === $simple_statuses && Status::is_valid( $status ) ) {
				$simple_statuses[] = $status;
			}

			$simple = ContactAdvancedFilters::plan_from_simple(
				$simple_statuses,
				array_map( 'absint', $raw_lists ),
				array_map( 'absint', $raw_tags )
			);

			if ( is_wp_error( $simple ) ) {
				$advanced_error = $simple;
			} elseif ( null !== $simple ) {
				$advanced = $simple;
			}
		}
		?>
		<?php if ( null !== $advanced_error ) : ?>
			<div class="nowcrm-panel">
				<h2><?php esc_html_e( '匯出聯絡人 CSV', 'now-crm' ); ?></h2>
				<div class="notice notice-error inline">
					<p><?php echo esc_html( $advanced_error->get_error_message() ); ?></p>
				</div>
				<p>
					<a class="button" href="<?php echo esc_url( Menu::page_url( Menu::SLUG ) ); ?>">
						<?php esc_html_e( '回到聯絡人清單', 'now-crm' ); ?>
					</a>
				</p>
			</div>
			<?php
			return;
		endif;
		?>
		<div class="nowcrm-panel">
			<h2><?php esc_html_e( '匯出聯絡人 CSV', 'now-crm' ); ?></h2>
			<form id="nowcrm-csv-export-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( ContactCsvAdmin::ACTION_EXPORT ); ?>" />
				<?php wp_nonce_field( ContactCsvAdmin::NONCE_EXPORT ); ?>
				<?php if ( null !== $advanced ) : ?>
					<input type="hidden" name="<?php echo esc_attr( ContactAdvancedFilters::TRANSPORT ); ?>"
						value="<?php echo esc_attr( AdvancedFilters::to_json( $advanced ) ); ?>" />
				<?php endif; ?>
				<?php if ( '' !== $segment ) : ?>
					<input type="hidden" id="nowcrm-csv-segment" name="segment" value="<?php echo esc_attr( $segment ); ?>" />
					<p class="description">
						<?php
						printf(
							/* translators: %s: segment reference. */
							esc_html__( '這次匯出使用分眾 %s 的即時成員。', 'now-crm' ),
							esc_html( $segment )
						);
						?>
					</p>
				<?php endif; ?>

				<fieldset>
					<legend><?php esc_html_e( '範圍', 'now-crm' ); ?></legend>
					<label><input type="radio" name="scope" value="filtered" <?php checked( 'filtered', $scope ); ?> /> <?php esc_html_e( '符合篩選的聯絡人', 'now-crm' ); ?></label>
					<label><input type="radio" name="scope" value="selected" <?php checked( 'selected', $scope ); ?> /> <?php esc_html_e( '指定的聯絡人 ID', 'now-crm' ); ?></label>
				</fieldset>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( '篩選', 'now-crm' ); ?></th>
						<td>
							<label><?php esc_html_e( '搜尋', 'now-crm' ); ?>
								<input type="search" name="search" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Email 或姓名', 'now-crm' ); ?>" />
							</label>
							<label><?php esc_html_e( '狀態', 'now-crm' ); ?>
								<select name="status">
									<option value=""><?php esc_html_e( '全部狀態', 'now-crm' ); ?></option>
									<?php foreach ( Status::all() as $option ) : ?>
										<option value="<?php echo esc_attr( $option ); ?>" <?php selected( $status, $option ); ?>>
											<?php echo esc_html( Status::label( $option ) ); ?>
										</option>
									<?php endforeach; ?>
								</select>
							</label>
							<label><?php esc_html_e( '名單／標籤', 'now-crm' ); ?>
								<select name="term_id">
									<option value="0"><?php esc_html_e( '全部', 'now-crm' ); ?></option>
									<?php if ( array() !== $lists ) : ?>
										<optgroup label="<?php esc_attr_e( '名單', 'now-crm' ); ?>">
											<?php foreach ( $lists as $term ) : ?>
												<option value="<?php echo esc_attr( (string) $term['id'] ); ?>" <?php selected( $term_id, (int) $term['id'] ); ?>>
													<?php echo esc_html( (string) $term['name'] ); ?>
												</option>
											<?php endforeach; ?>
										</optgroup>
									<?php endif; ?>
									<?php if ( array() !== $tags ) : ?>
										<optgroup label="<?php esc_attr_e( '標籤', 'now-crm' ); ?>">
											<?php foreach ( $tags as $term ) : ?>
												<option value="<?php echo esc_attr( (string) $term['id'] ); ?>" <?php selected( $term_id, (int) $term['id'] ); ?>>
													<?php echo esc_html( (string) $term['name'] ); ?>
												</option>
											<?php endforeach; ?>
										</optgroup>
									<?php endif; ?>
								</select>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="nowcrm-csv-ids"><?php esc_html_e( '指定聯絡人 ID', 'now-crm' ); ?></label></th>
						<td>
							<input id="nowcrm-csv-ids" type="text" name="contact_ids" class="regular-text" placeholder="12, 34, 56"
								value="<?php echo esc_attr( implode( ', ', $ids ) ); ?>" />
							<p class="description"><?php esc_html_e( '只在「指定的聯絡人 ID」範圍使用，最多 500 位。', 'now-crm' ); ?></p>
							<?php if ( count( $ids ) > CsvExport::MAX_SELECTED ) : ?>
								<div class="notice notice-warning inline">
									<p>
										<?php
										printf(
											/* translators: 1: selected contacts, 2: maximum selected contacts. */
											esc_html__( '目前選取 %1$d 位，超過一次匯出的上限 %2$d 位。請改用「符合篩選的聯絡人」範圍，或縮小選取。', 'now-crm' ),
											count( $ids ),
											(int) CsvExport::MAX_SELECTED
										);
										?>
									</p>
								</div>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( '欄位', 'now-crm' ); ?></th>
						<td>
							<?php foreach ( $columns as $key => $label ) : ?>
								<label style="display:inline-block;min-width:12em;">
									<input type="checkbox" name="columns[]" value="<?php echo esc_attr( $key ); ?>" <?php checked( in_array( $key, $defaults, true ) ); ?> />
									<?php echo esc_html( $label ); ?>
								</label>
							<?php endforeach; ?>

							<?php if ( array() !== $custom ) : ?>
								<h4><?php esc_html_e( '自訂欄位', 'now-crm' ); ?></h4>
								<?php foreach ( $custom as $slug => $label ) : ?>
									<label style="display:inline-block;min-width:12em;">
										<input type="checkbox" name="custom_fields[]" value="<?php echo esc_attr( $slug ); ?>" />
										<?php echo esc_html( $label ); ?>
									</label>
								<?php endforeach; ?>
							<?php endif; ?>
						</td>
					</tr>
				</table>

				<button type="submit" class="button button-primary"><?php esc_html_e( '匯出 CSV', 'now-crm' ); ?></button>
				<p class="description">
					<?php esc_html_e( '匯出會以串流方式產生，不會一次載入所有聯絡人。儲存格若以 = + - @ 開頭會加上單引號，避免試算表執行公式。', 'now-crm' ); ?>
				</p>
			</form>
		</div>
		<?php
	}
}
