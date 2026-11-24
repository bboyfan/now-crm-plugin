<?php
/**
 * Server-rendered form editor: settings, ordered fields, lists, and tags.
 *
 * @package NowCRM
 */

namespace NowCRM\Admin;

use NowCRM\Forms\EntryRepository;
use NowCRM\Forms\Fields;
use NowCRM\Forms\FormRepository;
use NowCRM\Support\Capabilities;
use NowCRM\Terms\TermRepository;

defined( 'ABSPATH' ) || exit;

final class FormEditorPage {

	/**
	 * Register the save handler.
	 */
	public static function register(): void {
		add_action( 'admin_post_nowcrm_save_form', array( __CLASS__, 'handle_save' ) );
	}

	/**
	 * Editor URL for one form.
	 */
	public static function url( int $form_id ): string {
		return Menu::page_url( FormsPage::SLUG, array( 'form' => $form_id ) );
	}

	/**
	 * Render the editor.
	 */
	public static function render( int $form_id ): void {
		if ( ! Capabilities::can_manage_contacts() ) {
			wp_die( esc_html__( '您沒有權限編輯表單。', 'now-crm' ), '', array( 'response' => 403 ) );
		}

		$form = FormRepository::get( $form_id );

		if ( ! $form ) {
			wp_die( esc_html__( '找不到表單。', 'now-crm' ), '', array( 'response' => 404 ) );
		}

		$draft   = get_transient( self::draft_key( $form_id ) );
		$message = '';

		if ( is_array( $draft ) && is_array( $draft['data'] ?? null ) ) {
			delete_transient( self::draft_key( $form_id ) );

			$data     = (array) $draft['data'];
			$message  = (string) ( $draft['message'] ?? '' );
			$title    = (string) ( $data['title'] ?? $form['title'] );
			$submit   = (string) ( $data['submit_label'] ?? $form['submit_label'] );
			$success  = (string) ( $data['success_message'] ?? $form['success_message'] );
			$doi      = ! empty( $data['double_opt_in'] );
			$lists    = array_map( 'absint', (array) ( $data['lists'] ?? array() ) );
			$tags     = array_map( 'absint', (array) ( $data['tags'] ?? array() ) );
			$fields   = is_array( $data['fields'] ?? null ) ? $data['fields'] : $form['fields'];
		} else {
			$title   = (string) $form['title'];
			$submit  = (string) $form['submit_label'];
			$success = (string) $form['success_message'];
			$doi     = ! empty( $form['double_opt_in'] );
			$lists   = (array) $form['lists'];
			$tags    = (array) $form['tags'];
			$fields  = (array) $form['fields'];
		}

		$term_repo = new TermRepository();
		$all_lists = $term_repo->all( TermRepository::TYPE_LIST );
		$all_tags  = $term_repo->all( TermRepository::TYPE_TAG );
		$entries   = ( new EntryRepository() )->count( $form_id );
		$list_args = FormsPage::list_args();
		$form_url  = add_query_arg( $list_args, admin_url( 'admin-post.php' ) );
		?>
		<div class="wrap nowcrm-wrap">
			<h1 class="wp-heading-inline">
				<?php
				echo esc_html(
					sprintf(
						/* translators: %s: form title. */
						__( '編輯表單：%s', 'now-crm' ),
						$title
					)
				);
				?>
			</h1>
			<hr class="wp-header-end" />

			<?php FormsPage::render_notice(); ?>

			<?php if ( '' !== $message ) : ?>
				<div class="notice notice-error"><p><?php echo esc_html( $message ); ?></p></div>
			<?php endif; ?>

			<p>
				<a class="button" href="<?php echo esc_url( FormsPage::entries_url( $form_id, $list_args ) ); ?>">
					<?php
					echo esc_html(
						sprintf(
							/* translators: %d: entry count. */
							__( '送出紀錄（%d）', 'now-crm' ),
							$entries
						)
					);
					?>
				</a>
				<a class="button button-link" href="<?php echo esc_url( Menu::page_url( FormsPage::SLUG, $list_args ) ); ?>">
					<?php esc_html_e( '表單列表', 'now-crm' ); ?>
				</a>
				<span class="description">
					<?php
					echo esc_html(
						sprintf(
							/* translators: %s: shortcode. */
							__( '短代碼：%s', 'now-crm' ),
							'[nowcrm_form id="' . $form_id . '"]'
						)
					);
					?>
				</span>
			</p>

			<form method="post" action="<?php echo esc_url( $form_url ); ?>" id="nowcrm-form-editor">
				<input type="hidden" name="action" value="nowcrm_save_form" />
				<input type="hidden" name="form_id" value="<?php echo esc_attr( (string) $form_id ); ?>" />
				<?php wp_nonce_field( 'nowcrm_save_form' ); ?>

				<div class="nowcrm-panel">
					<h2><?php esc_html_e( '基本設定', 'now-crm' ); ?></h2>
					<table class="form-table" role="presentation">
						<tbody>
							<tr>
								<th scope="row">
									<label for="nowcrm-form-title"><?php esc_html_e( '表單名稱', 'now-crm' ); ?></label>
								</th>
								<td>
									<input type="text" id="nowcrm-form-title" name="title" class="regular-text"
										value="<?php echo esc_attr( $title ); ?>" required="required" />
								</td>
							</tr>
							<tr>
								<th scope="row">
									<label for="nowcrm-form-submit"><?php esc_html_e( '送出按鈕文字', 'now-crm' ); ?></label>
								</th>
								<td>
									<input type="text" id="nowcrm-form-submit" name="submit_label" class="regular-text"
										value="<?php echo esc_attr( $submit ); ?>" />
								</td>
							</tr>
							<tr>
								<th scope="row">
									<label for="nowcrm-form-success"><?php esc_html_e( '送出成功訊息', 'now-crm' ); ?></label>
								</th>
								<td>
									<textarea id="nowcrm-form-success" name="success_message" rows="4" class="large-text"><?php echo esc_textarea( $success ); ?></textarea>
									<p class="description">
										<?php esc_html_e( '允許基本 HTML。送出成功後取代表單顯示。', 'now-crm' ); ?>
									</p>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( '雙重確認', 'now-crm' ); ?></th>
								<td>
									<label>
										<input type="checkbox" name="double_opt_in" value="1" <?php checked( $doi ); ?> />
										<?php esc_html_e( '標記為需要雙重確認', 'now-crm' ); ?>
									</label>
									<p class="description">
										<?php esc_html_e( '勾選後，只有當全域或送出後加入的名單要求雙重確認時，NOW CRM 才會把新聯絡人設為 transactional 並使用既有的雙重確認流程寄出確認信；未設定要求時直接依表單設定處理。既有聯絡人的狀態不會因此降級或重寄邀請。', 'now-crm' ); ?>
									</p>
								</td>
							</tr>
						</tbody>
					</table>
				</div>

				<div class="nowcrm-panel">
					<h2><?php esc_html_e( '送出後加入', 'now-crm' ); ?></h2>
					<table class="form-table" role="presentation">
						<tbody>
							<tr>
								<th scope="row">
									<label for="nowcrm-form-lists"><?php esc_html_e( '名單', 'now-crm' ); ?></label>
								</th>
								<td>
									<select id="nowcrm-form-lists" name="lists[]" multiple="multiple" size="8">
										<?php foreach ( $all_lists as $term ) : ?>
											<option value="<?php echo esc_attr( (string) $term['id'] ); ?>"
												<?php selected( in_array( (int) $term['id'], $lists, true ) ); ?>>
												<?php echo esc_html( (string) $term['name'] ); ?>
											</option>
										<?php endforeach; ?>
									</select>
									<p class="description">
										<?php esc_html_e( '可多選。送出後把聯絡人加入這些名單；不會移除既有名單。', 'now-crm' ); ?>
									</p>
								</td>
							</tr>
							<tr>
								<th scope="row">
									<label for="nowcrm-form-tags"><?php esc_html_e( '標籤', 'now-crm' ); ?></label>
								</th>
								<td>
									<select id="nowcrm-form-tags" name="tags[]" multiple="multiple" size="8">
										<?php foreach ( $all_tags as $term ) : ?>
											<option value="<?php echo esc_attr( (string) $term['id'] ); ?>"
												<?php selected( in_array( (int) $term['id'], $tags, true ) ); ?>>
												<?php echo esc_html( (string) $term['name'] ); ?>
											</option>
										<?php endforeach; ?>
									</select>
									<p class="description">
										<?php esc_html_e( '可多選。送出後把聯絡人加上這些標籤；不會移除既有標籤。', 'now-crm' ); ?>
									</p>
								</td>
							</tr>
						</tbody>
					</table>
				</div>

				<div class="nowcrm-panel">
					<h2><?php esc_html_e( '欄位', 'now-crm' ); ?></h2>
					<p class="description">
						<?php esc_html_e( '列由上到下就是前台順序。Email 欄位固定為必填，用來建立或更新聯絡人。', 'now-crm' ); ?>
					</p>

					<table class="wp-list-table widefat fixed nowcrm-table">
						<thead>
							<tr>
								<th scope="col" class="nowcrm-col-number"><?php esc_html_e( '順序', 'now-crm' ); ?></th>
								<th scope="col"><?php esc_html_e( '標籤', 'now-crm' ); ?></th>
								<th scope="col"><?php esc_html_e( '代稱', 'now-crm' ); ?></th>
								<th scope="col"><?php esc_html_e( '類型', 'now-crm' ); ?></th>
								<th scope="col"><?php esc_html_e( '必填', 'now-crm' ); ?></th>
								<th scope="col"><?php esc_html_e( '選項（每行一個）', 'now-crm' ); ?></th>
								<th scope="col"><?php esc_html_e( '刪除', 'now-crm' ); ?></th>
							</tr>
						</thead>
						<tbody id="nowcrm-form-fields" data-next="<?php echo esc_attr( (string) count( $fields ) ); ?>">
							<?php foreach ( $fields as $index => $field ) : ?>
								<?php self::field_row( is_int( $index ) ? $index : (string) $index, (array) $field ); ?>
							<?php endforeach; ?>
						</tbody>
					</table>

					<p>
						<button type="button" class="button" id="nowcrm-add-field">
							<?php esc_html_e( '新增欄位', 'now-crm' ); ?>
						</button>
					</p>

					<template id="nowcrm-form-field-template">
						<?php
						self::field_row(
							'__index__',
							array(
								'key'      => '',
								'label'    => '',
								'type'     => Fields::TEXT,
								'required' => false,
								'options'  => array(),
							)
						);
						?>
					</template>
				</div>

				<p class="submit">
					<button type="submit" class="button button-primary"><?php esc_html_e( '儲存表單', 'now-crm' ); ?></button>
				</p>
			</form>

			<div class="nowcrm-panel nowcrm-panel--danger">
				<h2><?php esc_html_e( '刪除表單', 'now-crm' ); ?></h2>
				<p class="description">
					<?php esc_html_e( '刪除後會同時移除這個表單的所有送出紀錄，無法復原。', 'now-crm' ); ?>
				</p>
				<form method="post" action="<?php echo esc_url( $form_url ); ?>"
					onsubmit="return confirm('<?php echo esc_js( __( '確定要刪除此表單與所有送出紀錄嗎？', 'now-crm' ) ); ?>');">
					<input type="hidden" name="action" value="nowcrm_delete_form" />
					<input type="hidden" name="form_id" value="<?php echo esc_attr( (string) $form_id ); ?>" />
					<?php wp_nonce_field( 'nowcrm_delete_form' ); ?>
					<button type="submit" class="button button-link-delete"><?php esc_html_e( '刪除表單', 'now-crm' ); ?></button>
				</form>
			</div>

			<script>
			(function () {
				var template = document.getElementById('nowcrm-form-field-template');
				var body = document.getElementById('nowcrm-form-fields');
				var button = document.getElementById('nowcrm-add-field');

				if (!template || !body || !button || !template.content) {
					return;
				}

				var next = parseInt(body.getAttribute('data-next') || '0', 10) || 0;

				button.addEventListener('click', function () {
					var row = template.content.firstElementChild.cloneNode(true);

					row.querySelectorAll('[name]').forEach(function (node) {
						node.name = node.name.replace('__index__', String(next));
					});
					row.querySelectorAll('[id]').forEach(function (node) {
						node.id = node.id.replace('__index__', String(next));
					});
					row.querySelectorAll('label[for]').forEach(function (node) {
						node.htmlFor = node.htmlFor.replace('__index__', String(next));
					});

					body.appendChild(row);

					var order = row.querySelector('.nowcrm-form-order');

					if (order) {
						order.textContent = String(body.children.length);
					}

					next += 1;
					body.setAttribute('data-next', String(next));
				});
			})();
			</script>
		</div>
		<?php
	}

	/**
	 * Save handler.
	 */
	public static function handle_save(): void {
		if ( ! Capabilities::can_manage_contacts() ) {
			wp_die( esc_html__( '您沒有權限儲存表單。', 'now-crm' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( 'nowcrm_save_form' );

		$form_id = isset( $_POST['form_id'] ) && is_scalar( $_POST['form_id'] )
			? absint( wp_unslash( (string) $_POST['form_id'] ) )
			: 0;

		$form = FormRepository::get( $form_id );

		if ( ! $form ) {
			self::redirect(
				array_merge(
					FormsPage::list_args(),
					array( 'nowcrm_forms_notice' => 'not_found' )
				)
			);
		}

		$title   = isset( $_POST['title'] ) && is_string( $_POST['title'] )
			? sanitize_text_field( wp_unslash( $_POST['title'] ) )
			: '';
		$submit  = isset( $_POST['submit_label'] ) && is_string( $_POST['submit_label'] )
			? sanitize_text_field( wp_unslash( $_POST['submit_label'] ) )
			: '';
		$success = isset( $_POST['success_message'] ) && is_string( $_POST['success_message'] )
			? wp_kses_post( wp_unslash( $_POST['success_message'] ) )
			: '';
		$doi     = ! empty( $_POST['double_opt_in'] );

		$fields = isset( $_POST['fields'] ) && is_array( $_POST['fields'] )
			? wp_unslash( $_POST['fields'] )
			: array();
		$lists  = isset( $_POST['lists'] ) && is_array( $_POST['lists'] )
			? array_map( 'absint', wp_unslash( $_POST['lists'] ) )
			: array();
		$tags   = isset( $_POST['tags'] ) && is_array( $_POST['tags'] )
			? array_map( 'absint', wp_unslash( $_POST['tags'] ) )
			: array();

		$result = FormRepository::update(
			$form_id,
			array(
				'title'           => $title,
				'submit_label'    => $submit,
				'success_message' => $success,
				'double_opt_in'   => $doi,
				'fields'          => $fields,
				'lists'           => $lists,
				'tags'            => $tags,
			)
		);

		if ( is_wp_error( $result ) ) {
			set_transient(
				self::draft_key( $form_id ),
				array(
					'data'    => array(
						'title'           => $title,
						'submit_label'    => $submit,
						'success_message' => $success,
						'double_opt_in'   => $doi,
						'lists'           => $lists,
						'tags'            => $tags,
						'fields'          => Fields::normalize_definitions( $fields ),
					),
					'message' => $result->get_error_message(),
				),
				10 * MINUTE_IN_SECONDS
			);

			self::redirect(
				array_merge(
					FormsPage::list_args(),
					array(
						'form'                => $form_id,
						'nowcrm_forms_notice' => FormsPage::notice_from_error( $result ),
					)
				)
			);
		}

		delete_transient( self::draft_key( $form_id ) );

		self::redirect(
			array_merge(
				FormsPage::list_args(),
				array(
					'form'                => $form_id,
					'nowcrm_forms_notice' => 'saved',
				)
			)
		);
	}

	/**
	 * One editable field row. Also used inside the inert add-row template.
	 *
	 * @param int|string           $index Row index or the `__index__` placeholder.
	 * @param array<string, mixed> $field Field definition.
	 */
	private static function field_row( $index, array $field ): void {
		$index    = (string) $index;
		$key      = (string) ( $field['key'] ?? '' );
		$label    = (string) ( $field['label'] ?? '' );
		$type     = (string) ( $field['type'] ?? Fields::TEXT );
		$required = ! empty( $field['required'] );
		$options  = is_array( $field['options'] ?? null ) ? implode( "\n", array_map( 'strval', $field['options'] ) ) : (string) ( $field['options'] ?? '' );
		$prefix   = 'nowcrm-form-field-' . $index;
		$order    = is_numeric( $index ) ? (string) ( (int) $index + 1 ) : '';
		?>
		<tr>
			<td class="nowcrm-col-number nowcrm-form-order"><?php echo esc_html( $order ); ?></td>
			<td>
				<label class="screen-reader-text" for="<?php echo esc_attr( $prefix . '-label' ); ?>">
					<?php esc_html_e( '欄位標籤', 'now-crm' ); ?>
				</label>
				<input type="text" id="<?php echo esc_attr( $prefix . '-label' ); ?>"
					name="fields[<?php echo esc_attr( $index ); ?>][label]"
					value="<?php echo esc_attr( $label ); ?>" class="regular-text" />
			</td>
			<td>
				<label class="screen-reader-text" for="<?php echo esc_attr( $prefix . '-key' ); ?>">
					<?php esc_html_e( '欄位代稱', 'now-crm' ); ?>
				</label>
				<input type="text" id="<?php echo esc_attr( $prefix . '-key' ); ?>"
					name="fields[<?php echo esc_attr( $index ); ?>][key]"
					value="<?php echo esc_attr( $key ); ?>" class="regular-text" size="12" />
			</td>
			<td>
				<label class="screen-reader-text" for="<?php echo esc_attr( $prefix . '-type' ); ?>">
					<?php esc_html_e( '欄位類型', 'now-crm' ); ?>
				</label>
				<select id="<?php echo esc_attr( $prefix . '-type' ); ?>"
					name="fields[<?php echo esc_attr( $index ); ?>][type]">
					<?php foreach ( Fields::labels() as $value => $type_label ) : ?>
						<option value="<?php echo esc_attr( (string) $value ); ?>" <?php selected( $type, $value ); ?>>
							<?php echo esc_html( (string) $type_label ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</td>
			<td>
				<label>
					<input type="checkbox" name="fields[<?php echo esc_attr( $index ); ?>][required]" value="1" <?php checked( $required ); ?> />
					<?php esc_html_e( '必填', 'now-crm' ); ?>
				</label>
			</td>
			<td>
				<label class="screen-reader-text" for="<?php echo esc_attr( $prefix . '-options' ); ?>">
					<?php esc_html_e( '欄位選項', 'now-crm' ); ?>
				</label>
				<textarea id="<?php echo esc_attr( $prefix . '-options' ); ?>"
					name="fields[<?php echo esc_attr( $index ); ?>][options]"
					rows="3" class="large-text"><?php echo esc_textarea( $options ); ?></textarea>
			</td>
			<td>
				<label>
					<input type="checkbox" name="fields[<?php echo esc_attr( $index ); ?>][delete]" value="1" />
					<?php esc_html_e( '刪除', 'now-crm' ); ?>
				</label>
			</td>
		</tr>
		<?php
	}

	/**
	 * Per-user draft transient key for validation failures.
	 */
	private static function draft_key( int $form_id ): string {
		return 'nowcrm_form_draft_' . get_current_user_id() . '_' . $form_id;
	}

	/**
	 * Redirect back to the editor or form list.
	 *
	 * @param array<string, mixed> $args Query arguments.
	 */
	private static function redirect( array $args ): void {
		wp_safe_redirect( Menu::page_url( FormsPage::SLUG, $args ) );
		exit;
	}
}
