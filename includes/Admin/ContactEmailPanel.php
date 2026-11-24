<?php
/**
 * Contact detail panel: email history, preview, and one-off compose.
 *
 * Nothing here registers a hook until register() is called. The contact detail
 * screen owns rendering; this class owns the read-only history list, the frozen
 * preview, and the compose form that queues a real "custom" job.
 *
 * Permission model: the panel only renders for manage_contacts, the same
 * capability ContactDetailPage requires. NOW has no read-only lower role, so
 * every viewer who can open the contact screen can read full previews. Adding
 * a lower role requires a metadata-only mode before this panel is exposed to
 * it; nothing here grants that access.
 *
 * @package NowCRM
 */

namespace NowCRM\Admin;

use NowCRM\Contacts\ContactRepository;
use NowCRM\Contacts\Status;
use NowCRM\Email\ContactHistory;
use NowCRM\Support\Capabilities;
use NowCRM\Support\Settings;
use WP_Error;

defined( 'ABSPATH' ) || exit;

final class ContactEmailPanel {

	public const ACTION_SEND = 'nowcrm_send_contact_email';

	public const NOTICE_KEY = 'nowcrm_email_notice';

	/**
	 * Notice codes that represent failures.
	 *
	 * @var string[]
	 */
	private const ERROR_CODES = array(
		'not_found',
		'not_subscribed',
		'suppressed',
		'invalid_request',
		'invalid_subject',
		'invalid_body',
		'db_error',
		'error',
	);

	/**
	 * Register the admin-post handler. Call once from the admin boot path.
	 */
	public static function register(): void {
		add_action( 'admin_post_' . self::ACTION_SEND, array( __CLASS__, 'send_one_off' ) );
	}

	/**
	 * Queue one composed email for the contact.
	 *
	 * A successful redirect means a queue row exists. It never means the mail
	 * was sent: the worker still applies the sending switch, the subscribed
	 * gate and the suppression list.
	 */
	public static function send_one_off(): void {
		self::guard( self::ACTION_SEND );

		$contact_id = absint( self::post_raw( 'contact_id' ) );

		if ( $contact_id <= 0 || ! ( new ContactRepository() )->get( $contact_id ) ) {
			self::redirect_contacts( 'not_found' );
		}

		$result = ( new ContactHistory() )->queue_one_off(
			$contact_id,
			array(
				'subject'    => self::post_raw( 'subject' ),
				'body'       => self::post_raw( 'body' ),
				'request_id' => sanitize_text_field( self::post_raw( 'request_id' ) ),
			),
			get_current_user_id()
		);

		if ( is_wp_error( $result ) ) {
			self::redirect_contact( $contact_id, self::notice_from_error( $result ) );
		}

		self::redirect_contact( $contact_id, 'queued', array( 'email_job' => (int) $result ) );
	}

	/**
	 * Render the history, filter bar, preview, and compose form.
	 */
	public static function render( int $contact_id ): void {
		if ( ! Capabilities::can_manage_contacts() ) {
			wp_die(
				esc_html__( '您沒有權限檢視聯絡人 Email 歷程。', 'now-crm' ),
				'',
				array( 'response' => 403 )
			);
		}

		$contact = ( new ContactRepository() )->get( $contact_id );

		if ( ! $contact ) {
			?>
			<div class="nowcrm-panel">
				<h2><?php esc_html_e( 'Email 歷程', 'now-crm' ); ?></h2>
				<p><?php esc_html_e( '找不到此聯絡人。', 'now-crm' ); ?></p>
			</div>
			<?php
			return;
		}

		$history = new ContactHistory();
		$email   = (string) $contact['email'];

		$status = sanitize_key( Menu::query( 'email_status' ) );
		$status = ContactHistory::is_status( $status ) ? $status : '';

		$kind = sanitize_key( Menu::query( 'email_kind' ) );
		$kind = ContactHistory::is_kind( $kind ) ? $kind : '';

		$paged = max( 1, absint( Menu::query( 'email_paged', '1' ) ) );

		$result = $history->search(
			$contact_id,
			$email,
			array(
				'status' => $status,
				'kind'   => $kind,
				'page'   => $paged,
			)
		);

		$counts  = $history->counts( $contact_id, $email );
		$events  = $history->events( wp_list_pluck( $result['items'], 'id' ) );
		$list_url = Menu::page_url( Menu::CONTACT_SLUG, array( 'id' => $contact_id, 'tab' => 'emails' ) );

		$preview_id = absint( Menu::query( 'email_preview' ) );
		$preview    = $preview_id > 0 ? $history->preview( $contact_id, $email, $preview_id ) : null;

		$blocked = $history->send_block_reason( $contact );
		$sending = Settings::bool( 'email_sending_enabled' );

		$form_url = admin_url( 'admin-post.php' );
		?>
		<div class="nowcrm-panel" id="nowcrm-contact-email">
			<h2><?php esc_html_e( 'Email 歷程', 'now-crm' ); ?></h2>
			<p class="description">
				<?php esc_html_e( '依實際佇列 Job 列出電子報、購物車、測試與一次性 Email。狀態優先顯示 SES SNS 回報的送達結果；尚未收到通知時才顯示佇列狀態，「已送出」只表示 transport 已接受，不代表已送達信箱。開信可能來自郵件用戶端預取，屬於弱訊號。', 'now-crm' ); ?>
			</p>
			<p class="description">
				<?php esc_html_e( '此面板不提供刪除：Job 是寄送稽核紀錄，開信／點擊事件與退訂權杖都指向 Job，刪除會留下孤兒資料。', 'now-crm' ); ?>
			</p>

			<?php self::render_notice(); ?>

			<ul class="nowcrm-meta">
				<li>
					<span><?php esc_html_e( '全部', 'now-crm' ); ?></span>
					<strong><?php echo esc_html( number_format_i18n( (int) $counts['total'] ) ); ?></strong>
				</li>
				<?php foreach ( ContactHistory::statuses() as $key ) : ?>
					<li>
						<span>
							<?php if ( '' !== $status && $status === $key ) : ?>
								<?php echo esc_html( ContactHistory::status_label( $key ) ); ?>
							<?php else : ?>
								<a href="<?php echo esc_url( self::filter_url( $list_url, $kind, $key ) ); ?>">
									<?php echo esc_html( ContactHistory::status_label( $key ) ); ?>
								</a>
							<?php endif; ?>
						</span>
						<strong><?php echo esc_html( number_format_i18n( (int) ( $counts[ $key ] ?? 0 ) ) ); ?></strong>
					</li>
				<?php endforeach; ?>
			</ul>

			<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="nowcrm-filters">
				<input type="hidden" name="page" value="<?php echo esc_attr( Menu::CONTACT_SLUG ); ?>" />
				<input type="hidden" name="id" value="<?php echo esc_attr( (string) $contact_id ); ?>" />
				<input type="hidden" name="tab" value="emails" />

				<label>
					<span class="screen-reader-text"><?php esc_html_e( '狀態篩選', 'now-crm' ); ?></span>
					<select name="email_status">
						<option value=""><?php esc_html_e( '全部狀態', 'now-crm' ); ?></option>
						<?php foreach ( ContactHistory::statuses() as $key ) : ?>
							<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $status, $key ); ?>>
								<?php echo esc_html( ContactHistory::status_label( $key ) ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</label>

				<label>
					<span class="screen-reader-text"><?php esc_html_e( '類型篩選', 'now-crm' ); ?></span>
					<select name="email_kind">
						<option value=""><?php esc_html_e( '全部類型', 'now-crm' ); ?></option>
						<?php foreach ( ContactHistory::kinds() as $key ) : ?>
							<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $kind, $key ); ?>>
								<?php echo esc_html( ContactHistory::kind_label( $key ) ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</label>

				<button type="submit" class="button"><?php esc_html_e( '篩選', 'now-crm' ); ?></button>
				<a class="button" href="<?php echo esc_url( $list_url ); ?>"><?php esc_html_e( '清除篩選', 'now-crm' ); ?></a>
			</form>

			<?php if ( $preview_id > 0 ) : ?>
				<?php if ( $preview ) : ?>
					<details class="nowcrm-panel" open="open">
						<summary>
							<?php
							printf(
								/* translators: 1: job ID, 2: subject. */
								esc_html__( '內容預覽 #%1$s：%2$s', 'now-crm' ),
								esc_html( (string) (int) $preview['job']['id'] ),
								esc_html( $preview['subject'] )
							);
							?>
						</summary>
						<p class="description">
							<?php esc_html_e( '唯讀預覽。此處不注入開信像素或點擊改寫，也不建立退訂權杖。', 'now-crm' ); ?>
						</p>
						<iframe sandbox srcdoc="<?php echo esc_attr( $preview['html'] ); ?>"
							width="100%" height="480" title="<?php esc_attr_e( 'Email 內容預覽', 'now-crm' ); ?>"></iframe>
					</details>
				<?php else : ?>
					<div class="notice notice-warning inline">
						<p><?php esc_html_e( '找不到這封 Email 的內容快照；不會顯示替代內容。', 'now-crm' ); ?></p>
					</div>
				<?php endif; ?>
			<?php endif; ?>

			<table class="wp-list-table widefat striped nowcrm-table">
				<thead>
					<tr>
						<th><?php esc_html_e( '建立時間', 'now-crm' ); ?></th>
						<th><?php esc_html_e( '類型', 'now-crm' ); ?></th>
						<th><?php esc_html_e( '主旨', 'now-crm' ); ?></th>
						<th><?php esc_html_e( '狀態', 'now-crm' ); ?></th>
						<th><?php esc_html_e( '開信', 'now-crm' ); ?></th>
						<th><?php esc_html_e( '點擊', 'now-crm' ); ?></th>
						<th><?php esc_html_e( '操作', 'now-crm' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( array() === $result['items'] ) : ?>
						<tr>
							<td colspan="7"><?php esc_html_e( '沒有符合條件的 Email。', 'now-crm' ); ?></td>
						</tr>
					<?php else : ?>
						<?php foreach ( $result['items'] as $job ) : ?>
							<?php
							$job_id  = (int) $job['id'];
							$engaged = $events[ $job_id ] ?? array( 'open' => 0, 'click' => 0 );
							$error   = (string) ( $job['last_error'] ?? '' );
							?>
							<tr>
								<td><?php echo esc_html( Menu::format_datetime( (string) $job['created_at'] ) ); ?></td>
								<td><?php echo esc_html( ContactHistory::kind_label( (string) $job['kind'] ) ); ?></td>
								<td>
									<?php echo esc_html( mb_substr( (string) $job['subject'], 0, 80 ) ); ?>
									<span class="description">
										#<?php echo esc_html( (string) $job_id ); ?>
										· <?php echo esc_html( (string) $job['recipient'] ); ?>
									</span>
								</td>
								<td>
									<?php echo esc_html( ContactHistory::display_status_label( $job ) ); ?>
									<?php if ( '' !== $error ) : ?>
										<span class="description"><?php echo esc_html( mb_substr( $error, 0, 80 ) ); ?></span>
									<?php endif; ?>
								</td>
								<td>
									<?php
									echo (int) $engaged['open'] > 0
										? esc_html__( '是（弱訊號）', 'now-crm' )
										: '—';
									?>
								</td>
								<td>
									<?php
									echo (int) $engaged['click'] > 0
										? esc_html( number_format_i18n( (int) $engaged['click'] ) )
										: '—';
									?>
								</td>
								<td>
									<a class="button button-small"
										href="<?php echo esc_url( add_query_arg( 'email_preview', $job_id, $list_url ) ); ?>">
										<?php esc_html_e( '預覽', 'now-crm' ); ?>
									</a>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>

			<?php if ( $result['pages'] > 1 ) : ?>
				<div class="tablenav">
					<div class="tablenav-pages">
						<span class="displaying-num">
							<?php
							printf(
								/* translators: %s: number of emails. */
								esc_html__( '共 %s 筆', 'now-crm' ),
								esc_html( number_format_i18n( (int) $result['total'] ) )
							);
							?>
						</span>

						<?php if ( $result['page'] > 1 ) : ?>
							<a class="prev-page button" href="<?php echo esc_url( self::page_url( $contact_id, $status, $kind, $result['page'] - 1 ) ); ?>">
								<?php esc_html_e( '上一頁', 'now-crm' ); ?>
							</a>
						<?php endif; ?>

						<span class="paging-input">
							<?php
							printf(
								/* translators: 1: current page, 2: total pages. */
								esc_html__( '第 %1$s / %2$s 頁', 'now-crm' ),
								esc_html( number_format_i18n( (int) $result['page'] ) ),
								esc_html( number_format_i18n( (int) $result['pages'] ) )
							);
							?>
						</span>

						<?php if ( $result['page'] < $result['pages'] ) : ?>
							<a class="next-page button" href="<?php echo esc_url( self::page_url( $contact_id, $status, $kind, $result['page'] + 1 ) ); ?>">
								<?php esc_html_e( '下一頁', 'now-crm' ); ?>
							</a>
						<?php endif; ?>
					</div>
				</div>
			<?php endif; ?>

			<h3><?php esc_html_e( '一次性 Email', 'now-crm' ); ?></h3>
			<p class="description">
				<?php esc_html_e( '表單只會建立佇列 Job，不會立即寄出，也不代表已送達。寄送時佇列會再次檢查訂閱狀態與抑制名單。', 'now-crm' ); ?>
			</p>

			<?php if ( ! $sending ) : ?>
				<div class="notice notice-warning inline">
					<p><?php esc_html_e( '實際寄送開關目前關閉：可以排入佇列，但不會寄出任何郵件。', 'now-crm' ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( '' !== $blocked ) : ?>
				<div class="notice notice-warning inline">
					<p>
						<?php
						if ( 'suppressed' === $blocked ) {
							esc_html_e( '此地址在抑制名單中，或 Email 格式不正確，因此不提供一次性寄送。抑制名單不會被移除。', 'now-crm' );
						} else {
							printf(
								/* translators: %s: contact status label. */
								esc_html__( '目前聯絡人狀態為「%s」。NOW 對非測試的行銷 Email 要求「已訂閱」，因此不提供一次性寄送；狀態不會被自動變更。', 'now-crm' ),
								esc_html( Status::label( (string) $contact['status'] ) )
							);
						}
						?>
					</p>
				</div>
			<?php else : ?>
				<form method="post" action="<?php echo esc_url( $form_url ); ?>">
					<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_SEND ); ?>" />
					<input type="hidden" name="contact_id" value="<?php echo esc_attr( (string) $contact_id ); ?>" />
					<input type="hidden" name="request_id" value="<?php echo esc_attr( wp_generate_uuid4() ); ?>" />
					<?php wp_nonce_field( self::ACTION_SEND ); ?>

					<p>
						<label for="nowcrm-email-subject"><?php esc_html_e( '主旨', 'now-crm' ); ?></label><br />
						<input type="text" id="nowcrm-email-subject" name="subject" class="regular-text"
							maxlength="<?php echo esc_attr( (string) ContactHistory::MAX_SUBJECT_CHARS ); ?>" required="required" />
					</p>

					<p>
						<label for="nowcrm-email-body"><?php esc_html_e( '內容', 'now-crm' ); ?></label><br />
						<textarea id="nowcrm-email-body" name="body" rows="10" class="large-text" required="required"></textarea>
					</p>

					<p class="description">
						<?php esc_html_e( '支援 HTML 與 {{first_name}}、{{last_name}}、{{email}}、{{site_name}} 等代稱。HTML 寄送前由 EmailRenderer 清理；主旨與內容會凍結在 Job 快照。', 'now-crm' ); ?>
					</p>

					<p class="submit">
						<button type="submit" class="button button-primary"><?php esc_html_e( '排入寄送佇列', 'now-crm' ); ?></button>
					</p>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Allow-listed notice messages.
	 *
	 * @return array<string, string>
	 */
	private static function messages(): array {
		return array(
			'queued'           => __( '已建立寄送 Job。實際寄送結果以佇列狀態為準，不代表已送達。', 'now-crm' ),
			'not_found'        => __( '找不到資料，可能已被刪除。', 'now-crm' ),
			'not_subscribed'   => __( '只有「已訂閱」聯絡人可排入行銷 Email；本次未建立 Job，聯絡人狀態未變更。', 'now-crm' ),
			'suppressed'       => __( '此地址在抑制名單中，不能排入 Email；本次未建立 Job。', 'now-crm' ),
			'invalid_request'  => __( '表單已失效，請重新整理後再試。', 'now-crm' ),
			'invalid_subject'  => __( '主旨不可空白，且最多 78 個字元。', 'now-crm' ),
			'invalid_body'     => __( '請填寫 Email 內容（可含 HTML）。', 'now-crm' ),
			'db_error'         => __( '資料庫寫入失敗，請再試一次。', 'now-crm' ),
			'error'            => __( '操作失敗，請再試一次。', 'now-crm' ),
		);
	}

	/**
	 * Print the allow-listed notice for this panel.
	 */
	private static function render_notice(): void {
		if ( ! isset( $_GET[ self::NOTICE_KEY ] ) || ! is_string( $_GET[ self::NOTICE_KEY ] ) ) {
			return;
		}

		$code     = sanitize_key( wp_unslash( $_GET[ self::NOTICE_KEY ] ) );
		$messages = self::messages();

		if ( ! array_key_exists( $code, $messages ) ) {
			return;
		}

		$class = in_array( $code, self::ERROR_CODES, true ) ? 'notice-error' : 'notice-success';
		$job   = absint( Menu::query( 'email_job' ) );

		printf(
			'<div class="notice %1$s is-dismissible"><p>%2$s</p>',
			esc_attr( $class ),
			esc_html( $messages[ $code ] )
		);

		if ( 'queued' === $code ) {
			if ( $job > 0 ) {
				printf(
					'<p class="description">%s</p>',
					esc_html(
						sprintf(
							/* translators: %d: job ID. */
							__( 'Job #%d 的目前狀態請見下方列表。', 'now-crm' ),
							$job
						)
					)
				);
			}

			if ( ! Settings::bool( 'email_sending_enabled' ) ) {
				printf(
					'<p class="description">%s</p>',
					esc_html__( '寄送開關目前關閉，此 Job 會留在佇列，直到開關開啟。', 'now-crm' )
				);
			}
		}

		echo '</div>';
	}

	/**
	 * Translate a repository error into an allow-listed notice code.
	 */
	private static function notice_from_error( WP_Error $error ): string {
		$code = preg_replace( '/^nowcrm_/', '', (string) $error->get_error_code() );
		$code = is_string( $code ) ? $code : '';

		$aliases = array(
			'contact_not_found' => 'not_found',
			'invalid_email'     => 'error',
			'invalid_job'       => 'invalid_body',
			'invalid_request'   => 'invalid_request',
			'job_failed'        => 'error',
		);

		if ( isset( $aliases[ $code ] ) ) {
			$code = $aliases[ $code ];
		}

		return array_key_exists( $code, self::messages() ) ? $code : 'error';
	}

	/**
	 * Capability + nonce gate.
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
	 * Redirect back to one contact with a panel notice.
	 *
	 * @param array<string, mixed> $args Extra query arguments.
	 */
	private static function redirect_contact( int $contact_id, string $notice, array $args = array() ): void {
		wp_safe_redirect(
			Menu::page_url(
				Menu::CONTACT_SLUG,
				array_merge(
					array(
						'id'              => $contact_id,
						'tab'             => 'emails',
						self::NOTICE_KEY  => $notice,
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
	 * URL for one page of the history with the active filters.
	 */
	private static function page_url( int $contact_id, string $status, string $kind, int $paged ): string {
		$args = array(
			'id'          => $contact_id,
			'tab'         => 'emails',
			'email_paged' => max( 1, $paged ),
		);

		if ( '' !== $status ) {
			$args['email_status'] = $status;
		}

		if ( '' !== $kind ) {
			$args['email_kind'] = $kind;
		}

		return Menu::page_url( Menu::CONTACT_SLUG, $args );
	}

	/**
	 * URL for the list filtered by one status, keeping the kind filter.
	 */
	private static function filter_url( string $list_url, string $kind, string $status ): string {
		$args = array( 'email_status' => $status );

		if ( '' !== $kind ) {
			$args['email_kind'] = $kind;
		}

		return add_query_arg( $args, $list_url );
	}
}
