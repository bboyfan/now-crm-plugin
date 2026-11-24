<?php
/**
 * API key screen backed by WordPress Application Passwords.
 *
 * Only `manage_options` can mint or delete a key. Keys are offered only for
 * users who hold a NOW CRM capability. A new password is shown once through a
 * short-lived transient; it is never appended to a URL or written to an option.
 *
 * @package NowCRM
 */

namespace NowCRM\Admin;

use NowCRM\Support\Capabilities;
use NowCRM\Support\RestKeys;
use WP_Error;
use WP_User;

defined( 'ABSPATH' ) || exit;

final class ApiKeysPage {

	public const SLUG = 'nowcrm-api-keys';

	private const NOTICE = 'nowcrm_api_notice';

	private const PASSWORD_TRANSIENT = 'nowcrm_ap_password_';

	/**
	 * Register admin-post handlers.
	 */
	public function register(): void {
		add_action( 'admin_post_nowcrm_ap_create', array( __CLASS__, 'create' ) );
		add_action( 'admin_post_nowcrm_ap_delete', array( __CLASS__, 'delete' ) );
	}

	/**
	 * Render the screen.
	 */
	public static function render(): void {
		if ( ! Capabilities::can_manage_settings() ) {
			wp_die(
				esc_html__( '您沒有權限檢視 API 金鑰。', 'now-crm' ),
				'',
				array( 'response' => 403 )
			);
		}

		$available  = RestKeys::available();
		$can_manage = RestKeys::can_manage();
		$summary    = RestKeys::summary();
		$password   = $can_manage ? self::take_password() : null;
		$form_url   = admin_url( 'admin-post.php' );
		$creating   = 'new' === sanitize_key( Menu::query( 'view' ) );
		$can_create = $available && $can_manage && array() !== $summary;

		?>
		<div class="wrap nowcrm-wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'API 金鑰', 'now-crm' ); ?></h1>
			<?php if ( $can_create && ! $creating ) : ?>
				<a class="page-title-action" href="<?php echo esc_url( self::new_url() ); ?>"><?php esc_html_e( '建立金鑰', 'now-crm' ); ?></a>
			<?php endif; ?>
			<hr class="wp-header-end" />

			<?php self::render_settings_crumb(); ?>
			<?php self::render_notice(); ?>

			<?php if ( null !== $password ) : ?>
				<div class="nowcrm-panel">
					<h2><?php esc_html_e( '新金鑰只會顯示一次', 'now-crm' ); ?></h2>
					<p>
						<?php
						printf(
							/* translators: 1: key label, 2: user login. */
							esc_html__( '「%1$s」已為 %2$s 建立。請立即複製，離開頁面後無法再次取得。', 'now-crm' ),
							esc_html( (string) $password['name'] ),
							esc_html( (string) $password['user_login'] )
						);
						?>
					</p>
					<input type="text" class="large-text" readonly value="<?php echo esc_attr( (string) $password['password'] ); ?>" onclick="this.select();" />
				</div>
			<?php endif; ?>

			<?php if ( ! $available ) : ?>
				<div class="nowcrm-panel nowcrm-panel--danger">
					<h2><?php esc_html_e( 'Application Passwords 無法使用', 'now-crm' ); ?></h2>
					<p class="description">
						<?php esc_html_e( 'WordPress 只在 HTTPS 或本機環境提供 Application Passwords。修正環境後再回到此頁。', 'now-crm' ); ?>
					</p>
				</div>
			<?php elseif ( ! $can_manage ) : ?>
				<div class="nowcrm-panel">
					<p class="description"><?php esc_html_e( '只有具備 manage_options 的使用者可以建立或刪除 API 金鑰。', 'now-crm' ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( $creating ) : ?>
				<div class="nowcrm-panel">
					<h2><?php esc_html_e( '建立金鑰', 'now-crm' ); ?></h2>

					<?php if ( ! $can_create ) : ?>
						<p class="description"><?php esc_html_e( '目前沒有具備 NOW CRM 權限的使用者，或這個站台不支援 Application Passwords。', 'now-crm' ); ?></p>
						<p><a class="button" href="<?php echo esc_url( self::page_url() ); ?>"><?php esc_html_e( '返回金鑰列表', 'now-crm' ); ?></a></p>
					<?php else : ?>
						<form method="post" action="<?php echo esc_url( $form_url ); ?>">
							<input type="hidden" name="action" value="nowcrm_ap_create" />
							<?php wp_nonce_field( 'nowcrm_ap_create' ); ?>

							<table class="form-table" role="presentation"><tbody>
								<tr>
									<th><label for="nowcrm-api-user"><?php esc_html_e( '使用者', 'now-crm' ); ?></label></th>
									<td>
										<select id="nowcrm-api-user" name="user_id" required>
											<?php foreach ( $summary as $row ) : ?>
												<option value="<?php echo esc_attr( (string) $row['id'] ); ?>">
													<?php echo esc_html( $row['display_name'] . ' (' . $row['user_login'] . ')' ); ?>
												</option>
											<?php endforeach; ?>
										</select>
									</td>
								</tr>
								<tr>
									<th><label for="nowcrm-api-name"><?php esc_html_e( '金鑰名稱', 'now-crm' ); ?></label></th>
									<td><input type="text" id="nowcrm-api-name" name="name" class="regular-text" maxlength="<?php echo esc_attr( (string) RestKeys::MAX_NAME ); ?>" required /></td>
								</tr>
							</tbody></table>

							<p class="submit">
								<button type="submit" class="button button-primary" id="nowcrm-api-create"><?php esc_html_e( '建立金鑰', 'now-crm' ); ?></button>
								<a class="button" href="<?php echo esc_url( self::page_url() ); ?>"><?php esc_html_e( '取消', 'now-crm' ); ?></a>
							</p>
						</form>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<div class="nowcrm-panel">
				<h2><?php esc_html_e( '現有金鑰', 'now-crm' ); ?></h2>

				<?php if ( array() === $summary ) : ?>
					<p class="description"><?php esc_html_e( '沒有可顯示的使用者。', 'now-crm' ); ?></p>
				<?php endif; ?>

				<?php foreach ( $summary as $row ) : ?>
					<h3>
						<?php echo esc_html( (string) $row['display_name'] ); ?>
						<code><?php echo esc_html( (string) $row['user_login'] ); ?></code>
					</h3>

					<?php if ( array() === $row['keys'] ) : ?>
						<p class="description"><?php esc_html_e( '尚未建立金鑰。', 'now-crm' ); ?></p>
						<?php continue; ?>
					<?php endif; ?>

					<table class="wp-list-table widefat fixed striped nowcrm-table">
						<thead>
							<tr>
								<th><?php esc_html_e( '名稱', 'now-crm' ); ?></th>
								<th><?php esc_html_e( 'ID', 'now-crm' ); ?></th>
								<th><?php esc_html_e( '建立時間', 'now-crm' ); ?></th>
								<th><?php esc_html_e( '最後使用', 'now-crm' ); ?></th>
								<th><?php esc_html_e( '最後 IP', 'now-crm' ); ?></th>
								<?php if ( $can_manage ) : ?>
									<th><?php esc_html_e( '操作', 'now-crm' ); ?></th>
								<?php endif; ?>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $row['keys'] as $key ) : ?>
								<tr>
									<td><?php echo esc_html( (string) $key['name'] ); ?></td>
									<td><code><?php echo esc_html( (string) $key['uuid'] ); ?></code></td>
									<td><?php echo esc_html( self::format_time( (int) $key['created'] ) ); ?></td>
									<td><?php echo esc_html( null === $key['last_used'] ? '—' : self::format_time( (int) $key['last_used'] ) ); ?></td>
									<td><?php echo esc_html( '' === $key['last_ip'] ? '—' : (string) $key['last_ip'] ); ?></td>
									<?php if ( $can_manage ) : ?>
										<td>
											<form method="post" action="<?php echo esc_url( $form_url ); ?>">
												<input type="hidden" name="action" value="nowcrm_ap_delete" />
												<input type="hidden" name="user_id" value="<?php echo esc_attr( (string) $row['id'] ); ?>" />
												<input type="hidden" name="uuid" value="<?php echo esc_attr( (string) $key['uuid'] ); ?>" />
												<?php wp_nonce_field( 'nowcrm_ap_delete' ); ?>
												<button type="submit" class="button button-small button-link-delete" id="nowcrm-api-delete-<?php echo esc_attr( (string) $key['uuid'] ); ?>" onclick="return confirm('<?php echo esc_js( __( '確定刪除這個 API 金鑰？', 'now-crm' ) ); ?>');">
													<?php esc_html_e( '刪除', 'now-crm' ); ?>
												</button>
											</form>
										</td>
									<?php endif; ?>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endforeach; ?>
			</div>

			<div class="nowcrm-panel">
				<h2><?php esc_html_e( '認證方式', 'now-crm' ); ?></h2>
				<p class="description">
					<?php esc_html_e( '金鑰是 WordPress Application Password。使用 HTTP Basic 認證呼叫 nowcrm/v1 端點，權限仍由使用者的 NOW CRM 能力決定。', 'now-crm' ); ?>
				</p>
				<p><code>curl -u <?php echo esc_html( 'user:application-password' ); ?> <?php echo esc_html( rest_url( 'nowcrm/v1/lists' ) ); ?></code></p>
			</div>
		</div>
		<?php
	}

	/**
	 * Create one key.
	 */
	public static function create(): void {
		self::guard( 'nowcrm_ap_create' );

		$user_id = isset( $_POST['user_id'] ) ? absint( wp_unslash( $_POST['user_id'] ) ) : 0;
		$name    = isset( $_POST['name'] ) && is_string( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		$result  = RestKeys::create( $user_id, $name );

		if ( is_wp_error( $result ) ) {
			self::redirect( self::notice_from_error( $result ) );
		}

		$user = get_user_by( 'ID', $user_id );

		if ( $user instanceof WP_User ) {
			set_transient(
				self::PASSWORD_TRANSIENT . get_current_user_id(),
				array(
					'user_login' => (string) $user->user_login,
					'name'       => (string) $result['name'],
					'password'   => (string) $result['password'],
				),
				5 * MINUTE_IN_SECONDS
			);
		}

		self::redirect( 'created' );
	}

	/**
	 * Delete one key.
	 */
	public static function delete(): void {
		self::guard( 'nowcrm_ap_delete' );

		$user_id = isset( $_POST['user_id'] ) ? absint( wp_unslash( $_POST['user_id'] ) ) : 0;
		$uuid    = isset( $_POST['uuid'] ) && is_string( $_POST['uuid'] ) ? sanitize_text_field( wp_unslash( $_POST['uuid'] ) ) : '';
		$result  = RestKeys::delete( $user_id, $uuid );

		self::redirect( is_wp_error( $result ) ? self::notice_from_error( $result ) : 'deleted' );
	}

	/**
	 * Breadcrumb back to the shared settings screen.
	 */
	private static function render_settings_crumb(): void {
		?>
		<nav class="nowcrm-settings-crumbs" aria-label="<?php esc_attr_e( '設定位置', 'now-crm' ); ?>">
			<p class="description">
				<a href="<?php echo esc_url( Menu::page_url( Menu::SETTINGS_SLUG ) ); ?>"><?php esc_html_e( '設定', 'now-crm' ); ?></a>
				<span aria-hidden="true">/</span>
				<span aria-current="page"><?php esc_html_e( 'API 金鑰', 'now-crm' ); ?></span>
			</p>
		</nav>
		<?php
	}

	/**
	 * Key list URL.
	 */
	private static function page_url(): string {
		return (string) add_query_arg( 'page', self::SLUG, admin_url( 'admin.php' ) );
	}

	/**
	 * Create-form URL.
	 */
	private static function new_url(): string {
		return (string) add_query_arg(
			array(
				'page' => self::SLUG,
				'view' => 'new',
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Consume the one-time password transient.
	 *
	 * @return array<string, string>|null
	 */
	private static function take_password(): ?array {
		$key      = self::PASSWORD_TRANSIENT . get_current_user_id();
		$password = get_transient( $key );

		if ( ! is_array( $password ) || empty( $password['password'] ) ) {
			return null;
		}

		delete_transient( $key );

		$raw = (string) $password['password'];

		if ( class_exists( 'WP_Application_Passwords' ) ) {
			$raw = (string) \WP_Application_Passwords::chunk_password( $raw );
		}

		return array(
			'name'       => (string) ( $password['name'] ?? '' ),
			'user_login' => (string) ( $password['user_login'] ?? '' ),
			'password'   => $raw,
		);
	}

	/**
	 * Render the allow-listed notice.
	 */
	private static function render_notice(): void {
		if ( ! isset( $_GET[ self::NOTICE ] ) || ! is_string( $_GET[ self::NOTICE ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$code     = sanitize_key( wp_unslash( $_GET[ self::NOTICE ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$messages = self::messages();

		if ( ! isset( $messages[ $code ] ) ) {
			return;
		}

		$errors = array( 'forbidden', 'unavailable', 'user', 'name', 'uuid', 'not_found', 'error' );

		printf(
			'<div class="notice %s is-dismissible"><p>%s</p></div>',
			in_array( $code, $errors, true ) ? 'notice-error' : 'notice-success',
			esc_html( $messages[ $code ] )
		);
	}

	/**
	 * Redirect back with a notice code.
	 */
	private static function redirect( string $notice ): void {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'   => self::SLUG,
					self::NOTICE => $notice,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Capability and nonce guard. Minting and deleting require manage_options.
	 */
	private static function guard( string $action ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die(
				esc_html__( '只有 manage_options 可以管理 API 金鑰。', 'now-crm' ),
				'',
				array( 'response' => 403 )
			);
		}

		check_admin_referer( $action );
	}

	/**
	 * Allow-listed notices.
	 *
	 * @return array<string, string>
	 */
	private static function messages(): array {
		return array(
			'created'     => __( 'API 金鑰已建立。', 'now-crm' ),
			'deleted'     => __( 'API 金鑰已刪除。', 'now-crm' ),
			'forbidden'   => __( '只有 manage_options 可以建立或刪除 API 金鑰。', 'now-crm' ),
			'unavailable' => __( '這個站台目前無法使用 Application Passwords。', 'now-crm' ),
			'user'        => __( '此使用者沒有 NOW CRM 權限。', 'now-crm' ),
			'name'        => __( '請輸入 100 字以內的金鑰名稱。', 'now-crm' ),
			'uuid'        => __( '金鑰 ID 不正確。', 'now-crm' ),
			'not_found'   => __( '找不到這個 API 金鑰。', 'now-crm' ),
			'error'       => __( '操作失敗，請再試一次。', 'now-crm' ),
		);
	}

	/**
	 * Map a WP_Error to an allowed notice code.
	 */
	private static function notice_from_error( WP_Error $error ): string {
		$code = preg_replace( '/^nowcrm_rest_keys_/', '', (string) $error->get_error_code() );
		$code = is_string( $code ) ? $code : '';

		if ( 'application_password_not_found' === $code ) {
			$code = 'not_found';
		}

		return isset( self::messages()[ $code ] ) ? $code : 'error';
	}

	/**
	 * Format a Unix timestamp in site time.
	 */
	private static function format_time( int $timestamp ): string {
		return (string) wp_date( 'Y-m-d H:i', $timestamp );
	}
}
