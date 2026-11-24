<?php
/**
 * Contact notes panel and admin-post handlers.
 *
 * Nothing here registers a hook until register() is called. The contact detail
 * screen owns rendering; this class owns the note list, the editor, and the
 * mutations scoped to one contact.
 *
 * @package NowCRM
 */

namespace NowCRM\Admin;

use NowCRM\Contacts\ContactRepository;
use NowCRM\Contacts\NoteRepository;
use NowCRM\Support\Capabilities;
use WP_Error;

defined( 'ABSPATH' ) || exit;

final class ContactNotes {

	private const PER_PAGE = 20;

	private const NOTICE_KEY = 'nowcrm_note_notice';

	private const DELETED_KEY = 'nowcrm_note_deleted';

	private const ACTION_SAVE = 'nowcrm_save_contact_note';

	private const ACTION_DELETE = 'nowcrm_delete_contact_note';

	private const ACTION_BULK_DELETE = 'nowcrm_bulk_delete_contact_notes';

	/**
	 * Notice codes that represent failures.
	 *
	 * @var string[]
	 */
	private const ERROR_CODES = array(
		'invalid_kind',
		'invalid_title',
		'invalid_body',
		'invalid_created_at',
		'not_found',
		'no_selection',
		'too_many',
		'db_error',
		'error',
	);

	/**
	 * Register the admin-post handlers. Call once from the admin boot path.
	 */
	public static function register(): void {
		add_action( 'admin_post_' . self::ACTION_SAVE, array( __CLASS__, 'save_note' ) );
		add_action( 'admin_post_' . self::ACTION_DELETE, array( __CLASS__, 'delete_note' ) );
		add_action( 'admin_post_' . self::ACTION_BULK_DELETE, array( __CLASS__, 'bulk_delete_notes' ) );
	}

	/**
	 * Create or update a note from the contact detail screen.
	 */
	public static function save_note(): void {
		self::guard( self::ACTION_SAVE );

		$contact_id = absint( self::post_raw( 'contact_id' ) );

		if ( $contact_id <= 0 || ! ( new ContactRepository() )->get( $contact_id ) ) {
			self::redirect_contacts( 'not_found' );
		}

		$note_id = absint( self::post_raw( 'note_id' ) );
		$created = self::created_at_from_request();

		if ( false === $created ) {
			self::redirect_contact( $contact_id, 'invalid_created_at' );
		}

		$data = array(
			'kind'      => sanitize_key( self::post_raw( 'kind' ) ),
			'title'     => self::post_raw( 'title' ),
			'body'      => self::post_raw( 'body' ),
			'author_id' => get_current_user_id(),
		);

		if ( null !== $created ) {
			$data['created_at'] = $created;
		}

		$repository = new NoteRepository();
		$result     = $note_id > 0
			? $repository->update( $contact_id, $note_id, $data )
			: $repository->create( $contact_id, $data );

		if ( is_wp_error( $result ) ) {
			self::redirect_contact( $contact_id, self::notice_from_error( $result ) );
		}

		self::redirect_contact( $contact_id, 'saved' );
	}

	/**
	 * Delete one note from the contact detail screen.
	 */
	public static function delete_note(): void {
		self::guard( self::ACTION_DELETE );

		$contact_id = absint( self::post_raw( 'contact_id' ) );

		if ( $contact_id <= 0 || ! ( new ContactRepository() )->get( $contact_id ) ) {
			self::redirect_contacts( 'not_found' );
		}

		$deleted = ( new NoteRepository() )->delete( $contact_id, absint( self::post_raw( 'note_id' ) ) );

		if ( is_wp_error( $deleted ) ) {
			self::redirect_contact( $contact_id, self::notice_from_error( $deleted ) );
		}

		self::redirect_contact( $contact_id, 'deleted' );
	}

	/**
	 * Delete a bounded selection of notes from one contact.
	 */
	public static function bulk_delete_notes(): void {
		self::guard( self::ACTION_BULK_DELETE );

		$contact_id = absint( self::post_raw( 'contact_id' ) );

		if ( $contact_id <= 0 || ! ( new ContactRepository() )->get( $contact_id ) ) {
			self::redirect_contacts( 'not_found' );
		}

		$raw_ids = isset( $_POST['note_ids'] ) && is_array( $_POST['note_ids'] )
			? wp_unslash( $_POST['note_ids'] )
			: array();

		if ( count( $raw_ids ) > NoteRepository::BULK_LIMIT ) {
			self::redirect_contact( $contact_id, 'too_many' );
		}

		$note_ids = array();

		foreach ( $raw_ids as $note_id ) {
			if ( ! is_scalar( $note_id ) ) {
				continue;
			}

			$note_id = absint( $note_id );

			if ( $note_id > 0 ) {
				$note_ids[] = $note_id;
			}
		}

		$deleted = ( new NoteRepository() )->bulk_delete( $contact_id, $note_ids );

		if ( is_wp_error( $deleted ) ) {
			self::redirect_contact( $contact_id, self::notice_from_error( $deleted ) );
		}

		if ( $deleted <= 0 ) {
			self::redirect_contact( $contact_id, 'not_found' );
		}

		self::redirect_contact(
			$contact_id,
			'bulk_deleted',
			array( self::DELETED_KEY => (int) $deleted )
		);
	}

	/**
	 * Render the note list, filters, and editor for one contact.
	 */
	public static function render( int $contact_id ): void {
		if ( ! Capabilities::can_manage_contacts() ) {
			wp_die(
				esc_html__( '您沒有權限管理聯絡人筆記。', 'now-crm' ),
				'',
				array( 'response' => 403 )
			);
		}

		$repository = new NoteRepository();
		$search     = trim( sanitize_text_field( Menu::query( 'note_search' ) ) );
		$kind       = sanitize_key( Menu::query( 'note_kind' ) );
		$kind       = NoteRepository::is_valid_kind( $kind ) ? $kind : '';
		$paged      = max( 1, absint( Menu::query( 'note_paged', '1' ) ) );

		$result = $repository->search(
			$contact_id,
			array(
				'search'   => $search,
				'kind'     => $kind,
				'page'     => $paged,
				'per_page' => self::PER_PAGE,
			)
		);

		$items       = $result['items'];
		$total       = $result['total'];
		$total_pages = (int) ceil( $total / self::PER_PAGE );

		$note_param    = sanitize_key( Menu::query( 'note' ) );
		$note_id       = absint( $note_param );
		$editing       = $note_id > 0 ? $repository->get( $contact_id, $note_id ) : null;
		$composer_open = 'new' === $note_param || null !== $editing;

		$created_local = $editing
			? (string) get_date_from_gmt( (string) $editing['created_at'], 'Y-m-d\TH:i' )
			: '';
		$editing_kind  = $editing ? (string) $editing['kind'] : NoteRepository::KIND_NOTE;

		$form_url    = admin_url( 'admin-post.php' );
		$view_url    = self::page_url( $contact_id, $search, $kind, $paged );
		$create_url  = add_query_arg( 'note', 'new', $view_url );
		$confirm_del = esc_js( __( '確定要刪除選取的筆記嗎？此操作無法復原。', 'now-crm' ) );
		?>
		<div class="nowcrm-panel" id="nowcrm-contact-notes">
			<div class="nowcrm-notes__header">
				<div class="nowcrm-notes__heading">
					<h2><?php esc_html_e( '聯絡人筆記', 'now-crm' ); ?></h2>
					<p class="description">
						<?php esc_html_e( '筆記只記錄聯絡人互動，不會寄送 Email，也不會建立任務。', 'now-crm' ); ?>
					</p>
				</div>

				<a class="button button-primary nowcrm-notes__add" href="<?php echo esc_url( $create_url ); ?>"
					data-nowcrm-note-create aria-haspopup="dialog">
					<?php esc_html_e( '新增筆記', 'now-crm' ); ?>
				</a>
			</div>

			<?php self::render_notice(); ?>

			<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="nowcrm-filters">
				<input type="hidden" name="page" value="<?php echo esc_attr( Menu::CONTACT_SLUG ); ?>" />
				<input type="hidden" name="id" value="<?php echo esc_attr( (string) $contact_id ); ?>" />
				<input type="hidden" name="tab" value="notes" />

				<label>
					<span class="screen-reader-text"><?php esc_html_e( '搜尋筆記標題', 'now-crm' ); ?></span>
					<input type="search" name="note_search" value="<?php echo esc_attr( $search ); ?>"
						placeholder="<?php esc_attr_e( '搜尋筆記標題', 'now-crm' ); ?>" />
				</label>

				<label>
					<span class="screen-reader-text"><?php esc_html_e( '筆記類型篩選', 'now-crm' ); ?></span>
					<select name="note_kind">
						<option value=""><?php esc_html_e( '全部類型', 'now-crm' ); ?></option>
						<?php foreach ( NoteRepository::kinds() as $key => $label ) : ?>
							<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $kind, $key ); ?>>
								<?php echo esc_html( $label ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</label>

				<button type="submit" class="button"><?php esc_html_e( '篩選', 'now-crm' ); ?></button>
			</form>

			<?php if ( array() === $items ) : ?>
				<p class="nowcrm-notes__empty">
					<?php esc_html_e( '尚無筆記。使用上方「新增筆記」建立第一則。', 'now-crm' ); ?>
				</p>
			<?php else : ?>
				<table class="wp-list-table widefat striped nowcrm-table">
					<thead>
						<tr>
							<td class="check-column">
								<span class="screen-reader-text"><?php esc_html_e( '選取', 'now-crm' ); ?></span>
							</td>
							<th><?php esc_html_e( '類型', 'now-crm' ); ?></th>
							<th><?php esc_html_e( '標題', 'now-crm' ); ?></th>
							<th><?php esc_html_e( '內容', 'now-crm' ); ?></th>
							<th><?php esc_html_e( '作者', 'now-crm' ); ?></th>
							<th><?php esc_html_e( '建立時間', 'now-crm' ); ?></th>
							<th><?php esc_html_e( '更新時間', 'now-crm' ); ?></th>
							<th><?php esc_html_e( '操作', 'now-crm' ); ?></th>
						</tr>
					</thead>
					<tbody>
							<?php foreach ( $items as $note ) : ?>
								<tr>
									<th scope="row" class="check-column">
										<input type="checkbox" name="note_ids[]"
											form="nowcrm-notes-bulk"
											value="<?php echo esc_attr( (string) (int) $note['id'] ); ?>"
											aria-label="<?php echo esc_attr( sprintf( /* translators: %s: note title. */ __( '選取筆記：%s', 'now-crm' ), (string) $note['title'] ) ); ?>" />
									</th>
									<td><?php echo esc_html( NoteRepository::label( (string) $note['kind'] ) ); ?></td>
									<td><?php echo esc_html( (string) $note['title'] ); ?></td>
									<td><?php echo wp_kses_post( (string) $note['body'] ); ?></td>
									<td><?php echo esc_html( self::author_name( $note['author_id'] ) ); ?></td>
									<td><?php echo esc_html( Menu::format_datetime( (string) $note['created_at'] ) ); ?></td>
									<td><?php echo esc_html( Menu::format_datetime( (string) $note['updated_at'] ) ); ?></td>
									<td>
										<a class="nowcrm-notes__edit" href="<?php echo esc_url( add_query_arg( 'note', (int) $note['id'], $view_url ) ); ?>"
											data-nowcrm-note-edit
											data-note-id="<?php echo esc_attr( (string) (int) $note['id'] ); ?>"
											data-note-kind="<?php echo esc_attr( (string) $note['kind'] ); ?>"
											data-note-title="<?php echo esc_attr( (string) $note['title'] ); ?>"
											data-note-created="<?php echo esc_attr( (string) get_date_from_gmt( (string) $note['created_at'], 'Y-m-d\TH:i' ) ); ?>"
											data-note-body="<?php echo esc_attr( (string) $note['body'] ); ?>">
											<?php esc_html_e( '編輯', 'now-crm' ); ?>
										</a>
										<form method="post" action="<?php echo esc_url( $form_url ); ?>" class="nowcrm-note-delete">
											<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_DELETE ); ?>" />
											<input type="hidden" name="contact_id" value="<?php echo esc_attr( (string) $contact_id ); ?>" />
											<input type="hidden" name="note_id" value="<?php echo esc_attr( (string) (int) $note['id'] ); ?>" />
											<?php wp_nonce_field( self::ACTION_DELETE ); ?>
											<button type="submit" class="button-link-delete" onclick="return confirm('<?php echo esc_js( __( '確定要刪除此筆記嗎？此操作無法復原。', 'now-crm' ) ); ?>');">
												<?php esc_html_e( '刪除', 'now-crm' ); ?>
											</button>
										</form>
									</td>
								</tr>
							<?php endforeach; ?>
					</tbody>
				</table>

			<form id="nowcrm-notes-bulk" method="post" action="<?php echo esc_url( $form_url ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_BULK_DELETE ); ?>" />
				<input type="hidden" name="contact_id" value="<?php echo esc_attr( (string) $contact_id ); ?>" />
				<?php wp_nonce_field( self::ACTION_BULK_DELETE ); ?>

				<p class="submit">
					<button type="submit" class="button button-link-delete" onclick="return confirm('<?php echo $confirm_del; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above. ?>');">
						<?php esc_html_e( '刪除選取的筆記', 'now-crm' ); ?>
					</button>
					<span class="description">
						<?php
						printf(
							/* translators: %d: maximum number of notes per bulk delete. */
							esc_html__( '一次最多刪除 %d 筆。', 'now-crm' ),
							(int) NoteRepository::BULK_LIMIT
						);
						?>
					</span>
				</p>
			</form>

			<?php if ( $total_pages > 1 ) : ?>
				<div class="tablenav">
					<div class="tablenav-pages">
						<span class="displaying-num">
							<?php
							printf(
								/* translators: %s: number of notes. */
								esc_html__( '共 %s 筆', 'now-crm' ),
								esc_html( number_format_i18n( $total ) )
							);
							?>
						</span>

						<?php if ( $paged > 1 ) : ?>
							<a class="prev-page button" href="<?php echo esc_url( self::page_url( $contact_id, $search, $kind, $paged - 1 ) ); ?>">
								<?php esc_html_e( '上一頁', 'now-crm' ); ?>
							</a>
						<?php endif; ?>

						<span class="paging-input">
							<?php
							printf(
								/* translators: 1: current page, 2: total pages. */
								esc_html__( '第 %1$s / %2$s 頁', 'now-crm' ),
								esc_html( number_format_i18n( $paged ) ),
								esc_html( number_format_i18n( $total_pages ) )
							);
							?>
						</span>

						<?php if ( $paged < $total_pages ) : ?>
							<a class="next-page button" href="<?php echo esc_url( self::page_url( $contact_id, $search, $kind, $paged + 1 ) ); ?>">
								<?php esc_html_e( '下一頁', 'now-crm' ); ?>
							</a>
						<?php endif; ?>
					</div>
				</div>
			<?php endif; ?>
			<?php endif; // Notes exist. ?>

			<dialog class="nowcrm-drawer nowcrm-notes__drawer" id="nowcrm-note-drawer"
				aria-labelledby="nowcrm-note-drawer-title"
				data-error-text="<?php esc_attr_e( '筆記儲存失敗，請再試一次。', 'now-crm' ); ?>"
				data-confirm-discard="<?php esc_attr_e( '尚未儲存的內容會遺失，確定要關閉嗎？', 'now-crm' ); ?>"
				<?php echo $composer_open ? 'open' : ''; ?>>
				<form method="post" action="<?php echo esc_url( $form_url ); ?>" class="nowcrm-drawer__form"
					id="nowcrm-note-form"
					data-mode="<?php echo $editing ? 'edit' : 'create'; ?>"
					data-default-kind="<?php echo esc_attr( NoteRepository::KIND_NOTE ); ?>"
					data-label-create="<?php esc_attr_e( '新增筆記', 'now-crm' ); ?>"
					data-label-edit="<?php esc_attr_e( '編輯筆記', 'now-crm' ); ?>"
					data-label-save="<?php esc_attr_e( '儲存筆記', 'now-crm' ); ?>">
					<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_SAVE ); ?>" />
					<input type="hidden" name="contact_id" value="<?php echo esc_attr( (string) $contact_id ); ?>" />
					<input type="hidden" name="note_id" value="<?php echo esc_attr( (string) ( $editing ? (int) $editing['id'] : 0 ) ); ?>" />
					<?php wp_nonce_field( self::ACTION_SAVE ); ?>

					<div class="nowcrm-drawer__header">
						<h2 id="nowcrm-note-drawer-title" data-nowcrm-note-heading>
							<?php echo $editing ? esc_html__( '編輯筆記', 'now-crm' ) : esc_html__( '新增筆記', 'now-crm' ); ?>
						</h2>
						<button type="button" class="nowcrm-drawer__close" data-nowcrm-close-note
							aria-label="<?php esc_attr_e( '關閉', 'now-crm' ); ?>">
							<span class="dashicons dashicons-no-alt" aria-hidden="true"></span>
						</button>
					</div>

					<div class="nowcrm-drawer__body">
						<p class="nowcrm-contact-detail__inline-notice" data-nowcrm-note-error
							role="alert" tabindex="-1" hidden></p>

						<div class="nowcrm-notes__field">
							<label for="nowcrm-note-title"><?php esc_html_e( '標題', 'now-crm' ); ?></label>
							<input type="text" id="nowcrm-note-title" name="title" maxlength="255" required="required"
								value="<?php echo esc_attr( $editing ? (string) $editing['title'] : '' ); ?>"
								data-nowcrm-note-title />
						</div>

						<div class="nowcrm-notes__row">
							<div class="nowcrm-notes__field">
								<label for="nowcrm-note-kind"><?php esc_html_e( '類型', 'now-crm' ); ?></label>
								<select id="nowcrm-note-kind" name="kind">
									<?php foreach ( NoteRepository::kinds() as $key => $label ) : ?>
										<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $editing_kind, $key ); ?>>
											<?php echo esc_html( $label ); ?>
										</option>
									<?php endforeach; ?>
								</select>
							</div>

							<div class="nowcrm-notes__field">
								<label for="nowcrm-note-created"><?php esc_html_e( '日期與時間（選填）', 'now-crm' ); ?></label>
								<input type="datetime-local" id="nowcrm-note-created" name="created_at"
									value="<?php echo esc_attr( $created_local ); ?>" />
								<p class="description">
									<?php esc_html_e( '留空時，新增使用目前時間，編輯保留原日期。', 'now-crm' ); ?>
								</p>
							</div>
						</div>

						<div class="nowcrm-notes__field nowcrm-notes__field--body">
							<label for="nowcrm-note-body"><?php esc_html_e( '內容', 'now-crm' ); ?></label>
							<textarea id="nowcrm-note-body" name="body" rows="8" required="required"><?php echo esc_textarea( $editing ? (string) $editing['body'] : '' ); ?></textarea>
							<p class="description">
								<?php esc_html_e( '內容可使用基本 HTML。送出後會依 WordPress 允許的張貼標籤過濾。', 'now-crm' ); ?>
							</p>
						</div>
					</div>

					<div class="nowcrm-drawer__footer">
						<a class="button" href="<?php echo esc_url( $view_url ); ?>" data-nowcrm-close-note>
							<?php esc_html_e( '取消', 'now-crm' ); ?>
						</a>
						<button type="submit" class="button button-primary" data-nowcrm-note-submit>
							<?php echo $editing ? esc_html__( '儲存筆記', 'now-crm' ) : esc_html__( '新增筆記', 'now-crm' ); ?>
						</button>
					</div>
				</form>
			</dialog>
		</div>
		<?php
	}

	/**
	 * Print the note notice requested by the current URL, if allow-listed.
	 */
	private static function render_notice(): void {
		$code = sanitize_key( Menu::query( self::NOTICE_KEY ) );

		if ( '' === $code ) {
			return;
		}

		$messages = self::messages();

		if ( ! array_key_exists( $code, $messages ) ) {
			return;
		}

		$message = $messages[ $code ];

		if ( 'bulk_deleted' === $code ) {
			$message = sprintf(
				/* translators: %d: number of deleted notes. */
				__( '已刪除 %d 筆筆記。', 'now-crm' ),
				absint( Menu::query( self::DELETED_KEY, '0' ) )
			);
		}

		printf(
			'<div class="notice %1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( in_array( $code, self::ERROR_CODES, true ) ? 'notice-error' : 'notice-success' ),
			esc_html( $message )
		);
	}

	/**
	 * Allow-listed notice messages.
	 *
	 * @return array<string, string>
	 */
	private static function messages(): array {
		return array(
			'saved'         => __( '筆記已儲存。', 'now-crm' ),
			'deleted'       => __( '筆記已刪除。', 'now-crm' ),
			'bulk_deleted'  => __( '已刪除筆記。', 'now-crm' ),
			'invalid_kind'  => __( '筆記類型不正確。', 'now-crm' ),
			'invalid_title' => __( '請輸入筆記標題。', 'now-crm' ),
			'invalid_body'  => __( '請輸入筆記內容。', 'now-crm' ),
			'invalid_created_at' => __( '筆記日期不正確。', 'now-crm' ),
			'not_found'     => __( '找不到筆記，可能已刪除或屬於其他聯絡人。', 'now-crm' ),
			'no_selection'  => __( '請先選取要刪除的筆記。', 'now-crm' ),
			'too_many'      => __( '一次最多刪除 200 筆筆記。', 'now-crm' ),
			'db_error'      => __( '資料庫寫入失敗，請再試一次。', 'now-crm' ),
			'error'         => __( '操作失敗，請再試一次。', 'now-crm' ),
		);
	}

	/**
	 * Translate a repository error into an allow-listed notice code.
	 */
	private static function notice_from_error( WP_Error $error ): string {
		$code = preg_replace( '/^nowcrm_/', '', (string) $error->get_error_code() );
		$code = is_string( $code ) ? $code : '';

		return array_key_exists( $code, self::messages() ) ? $code : 'error';
	}

	/**
	 * Capability + nonce gate shared by every handler.
	 */
	private static function guard( string $action ): void {
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
	 * Raw unslashed string from the current POST request (arrays ignored).
	 */
	private static function post_raw( string $key ): string {
		if ( ! isset( $_POST[ $key ] ) || ! is_string( $_POST[ $key ] ) ) {
			return '';
		}

		return wp_unslash( $_POST[ $key ] );
	}

	/**
	 * Convert the optional created_at form field from site time to UTC.
	 *
	 * @return string|null|false UTC datetime, null when the field is empty,
	 *                            false when the submitted value is invalid.
	 */
	private static function created_at_from_request() {
		$raw = trim( self::post_raw( 'created_at' ) );

		if ( '' === $raw ) {
			return null;
		}

		$raw = str_replace( 'T', ' ', $raw );

		if ( preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $raw ) ) {
			$raw .= ':00';
		}

		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $raw ) ) {
			return false;
		}

		$utc = get_gmt_from_date( $raw, 'Y-m-d H:i:s' );

		return preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string) $utc ) ? $utc : false;
	}

	/**
	 * Redirect back to one contact with a note notice.
	 *
	 * @param array<string, mixed> $args Extra query arguments.
	 */
	private static function redirect_contact( int $contact_id, string $notice, array $args = array() ): void {
		wp_safe_redirect(
			Menu::page_url(
				Menu::CONTACT_SLUG,
				array_merge(
					array(
						'id'            => $contact_id,
						'tab'           => 'notes',
						self::NOTICE_KEY => $notice,
					),
					$args
				)
			)
		);

		exit;
	}

	/**
	 * Redirect to the contact list with a shared notice code.
	 */
	private static function redirect_contacts( string $notice ): void {
		wp_safe_redirect(
			Menu::page_url( Menu::SLUG, array( 'nowcrm_notice' => $notice ) )
		);

		exit;
	}

	/**
	 * URL for one page of the note list with the active filters.
	 */
	private static function page_url( int $contact_id, string $search, string $kind, int $paged ): string {
		$args = array(
			'id'         => $contact_id,
			'tab'        => 'notes',
			'note_paged' => max( 1, $paged ),
		);

		if ( '' !== $search ) {
			$args['note_search'] = $search;
		}

		if ( '' !== $kind ) {
			$args['note_kind'] = $kind;
		}

		return Menu::page_url( Menu::CONTACT_SLUG, $args );
	}

	/**
	 * Display name of a note author.
	 *
	 * @param mixed $author_id Raw author ID.
	 */
	private static function author_name( $author_id ): string {
		$author_id = absint( $author_id );

		if ( $author_id <= 0 ) {
			return '—';
		}

		$user = get_userdata( $author_id );

		return $user ? (string) $user->display_name : '#' . $author_id;
	}
}
