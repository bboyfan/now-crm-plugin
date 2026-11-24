<?php
/**
 * Webhook management screen.
 *
 * Owns outbound CRUD, delivery history and the inbound contact/generic routes.
 * The screen registers its own admin-post handlers. Add the class to
 * Plugin::modules() and its submenu entry to Menu::add_pages() to expose it.
 *
 * @package NowCRM
 */

namespace NowCRM\Admin;

use NowCRM\Support\Capabilities;
use NowCRM\Webhooks\DeliveryLog;
use NowCRM\Webhooks\Dispatcher;
use NowCRM\Webhooks\Events;
use NowCRM\Webhooks\Inbound;
use NowCRM\Webhooks\WebhookRepository;
use WP_Error;

defined( 'ABSPATH' ) || exit;

final class WebhooksPage {

	public const SLUG = 'nowcrm-webhooks';

	private const NOTICE = 'nowcrm_webhook_notice';

	private const DRAFT_STORE = 'nowcrm_webhook_draft_';

	/**
	 * Error codes that return the operator to the submitted form.
	 */
	private const FORM_ERRORS = array( 'invalid_name', 'invalid_url', 'https_required', 'invalid_events', 'too_many', 'error' );

	/**
	 * Register admin-post handlers.
	 */
	public function register(): void {
		$handlers = array(
			'nowcrm_webhook_save'           => 'save',
			'nowcrm_webhook_delete'         => 'delete',
			'nowcrm_webhook_rotate_secret'  => 'rotate_secret',
			'nowcrm_webhook_rotate_inbound' => 'rotate_inbound',
			'nowcrm_webhook_rotate_handler' => 'rotate_handler',
		);

		foreach ( $handlers as $action => $method ) {
			add_action( 'admin_post_' . $action, array( __CLASS__, $method ) );
		}
	}

	/**
	 * Render the screen.
	 */
	public static function render(): void {
		if ( ! Capabilities::can_manage_settings() ) {
			wp_die(
				esc_html__( '您沒有權限檢視 Webhook 設定。', 'now-crm' ),
				'',
				array( 'response' => 403 )
			);
		}

		$repository = new WebhookRepository();
		$webhooks   = $repository->all();
		$request    = self::request( $repository );
		$draft      = self::take_draft( $request );
		$form_url   = admin_url( 'admin-post.php' );

		?>
		<div class="wrap nowcrm-wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Webhooks', 'now-crm' ); ?></h1>
			<?php if ( 'list' === $request['view'] ) : ?>
				<a class="page-title-action" href="<?php echo esc_url( self::new_url() ); ?>"><?php esc_html_e( '新增 Webhook', 'now-crm' ); ?></a>
			<?php endif; ?>
			<hr class="wp-header-end" />

			<?php self::render_settings_crumb( __( 'Webhooks', 'now-crm' ) ); ?>
			<?php self::render_notice(); ?>

			<?php if ( $request['missing'] ) : ?>
				<div class="notice notice-error"><p><?php esc_html_e( '找不到這個 Webhook，可能已被刪除。', 'now-crm' ); ?></p></div>
			<?php endif; ?>

			<?php self::render_tabs( self::tab_for( $request['view'] ) ); ?>

			<?php
			if ( 'new' === $request['view'] ) {
				self::render_form( null, $form_url, $draft );
			} elseif ( 'edit' === $request['view'] && is_array( $request['webhook'] ) ) {
				self::render_form( $request['webhook'], $form_url, $draft );
			} elseif ( 'detail' === $request['view'] && is_array( $request['webhook'] ) ) {
				self::render_detail( $request['webhook'], $form_url );
			} elseif ( 'deliveries' === $request['view'] ) {
				self::render_deliveries( $webhooks );
			} elseif ( 'inbound' === $request['view'] ) {
				self::render_inbound();
			} else {
				self::render_list( $webhooks, $form_url );
			}
			?>
		</div>
		<?php
	}

	/**
	 * Resolve the requested section from the query string.
	 *
	 * A bare `webhook` link keeps opening the edit form, so old bookmarks still
	 * work. The read-only detail view lives at `webhook=<id>&view=detail`.
	 *
	 * @return array{view: string, webhook: array<string, mixed>|null, missing: bool}
	 */
	private static function request( WebhookRepository $repository ): array {
		$webhook_id = sanitize_text_field( Menu::query( 'webhook' ) );
		$view       = sanitize_key( Menu::query( 'view' ) );

		if ( '' !== $webhook_id ) {
			$webhook = $repository->find( $webhook_id );

			if ( null === $webhook ) {
				return array(
					'view'    => 'list',
					'webhook' => null,
					'missing' => true,
				);
			}

			return array(
				'view'    => 'detail' === $view ? 'detail' : 'edit',
				'webhook' => $webhook,
				'missing' => false,
			);
		}

		if ( ! in_array( $view, array( 'new', 'deliveries', 'inbound' ), true ) ) {
			$view = 'list';
		}

		return array(
			'view'    => $view,
			'webhook' => null,
			'missing' => false,
		);
	}

	/**
	 * Tab that owns one view.
	 */
	private static function tab_for( string $view ): string {
		if ( 'deliveries' === $view ) {
			return 'deliveries';
		}

		if ( 'inbound' === $view ) {
			return 'inbound';
		}

		return 'endpoints';
	}

	/**
	 * Breadcrumb back to the shared settings screen.
	 */
	private static function render_settings_crumb( string $label ): void {
		?>
		<nav class="nowcrm-settings-crumbs" aria-label="<?php esc_attr_e( '設定位置', 'now-crm' ); ?>">
			<p class="description">
				<a href="<?php echo esc_url( Menu::page_url( Menu::SETTINGS_SLUG ) ); ?>"><?php esc_html_e( '設定', 'now-crm' ); ?></a>
				<span aria-hidden="true">/</span>
				<span aria-current="page"><?php echo esc_html( $label ); ?></span>
			</p>
		</nav>
		<?php
	}

	/**
	 * Section tabs: endpoints, delivery log, inbound.
	 */
	private static function render_tabs( string $current ): void {
		$tabs = array(
			'endpoints'  => array( __( '端點', 'now-crm' ), self::page_url() ),
			'deliveries' => array( __( '傳送紀錄', 'now-crm' ), (string) add_query_arg( 'view', 'deliveries', self::page_url() ) ),
			'inbound'    => array( __( '入站 Webhook', 'now-crm' ), (string) add_query_arg( 'view', 'inbound', self::page_url() ) ),
		);
		?>
		<nav class="nav-tab-wrapper" aria-label="<?php esc_attr_e( 'Webhook 分區', 'now-crm' ); ?>">
			<?php foreach ( $tabs as $key => $tab ) : ?>
				<a class="nav-tab<?php echo $key === $current ? ' nav-tab-active' : ''; ?>"
					href="<?php echo esc_url( $tab[1] ); ?>"
					<?php echo $key === $current ? 'aria-current="page"' : ''; ?>>
					<?php echo esc_html( $tab[0] ); ?>
				</a>
			<?php endforeach; ?>
		</nav>
		<?php
	}

	/**
	 * Endpoint list. Data first: the form only opens through its own link.
	 *
	 * @param array<string, array<string, mixed>> $webhooks Stored endpoints.
	 */
	private static function render_list( array $webhooks, string $form_url ): void {
		?>
		<div class="nowcrm-panel">
			<h2><?php esc_html_e( '已建立的 Webhook', 'now-crm' ); ?></h2>

			<?php if ( array() === $webhooks ) : ?>
				<p class="description"><?php esc_html_e( '尚未建立任何 Webhook。', 'now-crm' ); ?></p>
				<p><a class="button button-primary" href="<?php echo esc_url( self::new_url() ); ?>"><?php esc_html_e( '新增第一個 Webhook', 'now-crm' ); ?></a></p>
			<?php else : ?>
				<p class="description"><?php esc_html_e( '開啟端點可查看簽章密鑰、輪換密鑰與該端點的傳送紀錄。', 'now-crm' ); ?></p>
				<table class="wp-list-table widefat fixed striped nowcrm-table">
					<thead>
						<tr>
							<th><?php esc_html_e( '名稱', 'now-crm' ); ?></th>
							<th><?php esc_html_e( '端點', 'now-crm' ); ?></th>
							<th><?php esc_html_e( '事件', 'now-crm' ); ?></th>
							<th><?php esc_html_e( '狀態', 'now-crm' ); ?></th>
							<th><?php esc_html_e( '更新', 'now-crm' ); ?></th>
							<th><?php esc_html_e( '操作', 'now-crm' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $webhooks as $webhook ) : ?>
							<?php $labels = array_map( array( Events::class, 'label' ), (array) $webhook['events'] ); ?>
							<tr id="nowcrm-webhook-row-<?php echo esc_attr( (string) $webhook['id'] ); ?>">
								<td>
									<strong>
										<a href="<?php echo esc_url( self::detail_url( (string) $webhook['id'] ) ); ?>"><?php echo esc_html( (string) $webhook['name'] ); ?></a>
									</strong><br />
									<code><?php echo esc_html( (string) $webhook['id'] ); ?></code>
								</td>
								<td><code style="word-break:break-all;"><?php echo esc_html( (string) $webhook['url'] ); ?></code></td>
								<td><?php echo esc_html( implode( '、', $labels ) ); ?></td>
								<td><?php echo ! empty( $webhook['active'] ) ? esc_html__( '啟用', 'now-crm' ) : esc_html__( '停用', 'now-crm' ); ?></td>
								<td><?php echo esc_html( Menu::format_datetime( (string) $webhook['updated_at'] ) ); ?></td>
								<td>
									<a class="button button-small" href="<?php echo esc_url( self::detail_url( (string) $webhook['id'] ) ); ?>"><?php esc_html_e( '詳細資料', 'now-crm' ); ?></a>

									<form method="post" action="<?php echo esc_url( $form_url ); ?>" style="display:inline-block;margin:0 0 0 4px;">
										<input type="hidden" name="action" value="nowcrm_webhook_rotate_secret" />
										<input type="hidden" name="id" value="<?php echo esc_attr( (string) $webhook['id'] ); ?>" />
										<?php wp_nonce_field( 'nowcrm_webhook_rotate_secret' ); ?>
										<button type="submit" class="button button-small"><?php esc_html_e( '輪換密鑰', 'now-crm' ); ?></button>
									</form>

									<form method="post" action="<?php echo esc_url( $form_url ); ?>" style="display:inline-block;margin:0 0 0 4px;">
										<input type="hidden" name="action" value="nowcrm_webhook_delete" />
										<input type="hidden" name="id" value="<?php echo esc_attr( (string) $webhook['id'] ); ?>" />
										<?php wp_nonce_field( 'nowcrm_webhook_delete' ); ?>
										<button type="submit" class="button button-small button-link-delete" onclick="return confirm('<?php echo esc_js( __( '確定刪除這個 Webhook？', 'now-crm' ) ); ?>');"><?php esc_html_e( '刪除', 'now-crm' ); ?></button>
									</form>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Add or edit form.
	 *
	 * @param array<string, mixed>|null $editing Endpoint being edited.
	 * @param array<string, mixed>|null $draft   Fields from a failed save.
	 */
	private static function render_form( ?array $editing, string $form_url, ?array $draft = null ): void {
		$webhook = is_array( $editing ) ? $editing : array();

		if ( is_array( $draft ) ) {
			$webhook = array_merge( $webhook, $draft );
		}

		$events = Events::all();
		$cancel = null !== $editing ? self::detail_url( (string) $webhook['id'] ) : self::page_url();

		?>
		<div class="nowcrm-panel">
			<h2><?php echo null !== $editing ? esc_html__( '編輯 Webhook', 'now-crm' ) : esc_html__( '新增 Webhook', 'now-crm' ); ?></h2>
			<form method="post" action="<?php echo esc_url( $form_url ); ?>" id="nowcrm-webhook-form">
				<input type="hidden" name="action" value="nowcrm_webhook_save" />
				<input type="hidden" name="id" value="<?php echo esc_attr( (string) ( $webhook['id'] ?? '' ) ); ?>" />
				<?php wp_nonce_field( 'nowcrm_webhook_save' ); ?>

				<table class="form-table" role="presentation"><tbody>
					<tr>
						<th><label for="nowcrm-webhook-name"><?php esc_html_e( '名稱', 'now-crm' ); ?></label></th>
						<td><input type="text" id="nowcrm-webhook-name" name="name" class="regular-text" maxlength="<?php echo esc_attr( (string) WebhookRepository::MAX_NAME ); ?>" required value="<?php echo esc_attr( (string) ( $webhook['name'] ?? '' ) ); ?>" /></td>
					</tr>
					<tr>
						<th><label for="nowcrm-webhook-url"><?php esc_html_e( '端點 URL', 'now-crm' ); ?></label></th>
						<td>
							<input type="url" id="nowcrm-webhook-url" name="url" class="large-text" maxlength="<?php echo esc_attr( (string) WebhookRepository::MAX_URL ); ?>" required value="<?php echo esc_attr( (string) ( $webhook['url'] ?? '' ) ); ?>" placeholder="https://example.com/webhooks/now" />
							<p class="description"><?php esc_html_e( '外部端點必須是 HTTPS；HTTP 只允許 localhost 或私有網路。', 'now-crm' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( '事件', 'now-crm' ); ?></th>
						<td>
							<fieldset>
								<?php foreach ( $events as $event => $label ) : ?>
									<?php $id = 'nowcrm-webhook-event-' . sanitize_html_class( str_replace( '.', '-', $event ) ); ?>
									<label for="<?php echo esc_attr( $id ); ?>" style="display:inline-block;margin-right:16px;">
										<input type="checkbox" id="<?php echo esc_attr( $id ); ?>" name="events[]" value="<?php echo esc_attr( $event ); ?>" <?php checked( in_array( $event, (array) ( $webhook['events'] ?? array() ), true ) ); ?> />
										<?php echo esc_html( $label ); ?>
									</label>
								<?php endforeach; ?>
							</fieldset>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( '狀態', 'now-crm' ); ?></th>
						<td>
							<label for="nowcrm-webhook-active">
								<input type="checkbox" id="nowcrm-webhook-active" name="active" value="1" <?php checked( ! empty( $webhook['active'] ) ); ?> />
								<?php esc_html_e( '啟用這個 Webhook', 'now-crm' ); ?>
							</label>
						</td>
					</tr>
				</tbody></table>

				<p class="submit">
					<button type="submit" class="button button-primary" id="nowcrm-webhook-save">
						<?php echo null !== $editing ? esc_html__( '儲存', 'now-crm' ) : esc_html__( '建立', 'now-crm' ); ?>
					</button>
					<a class="button" href="<?php echo esc_url( $cancel ); ?>"><?php esc_html_e( '取消', 'now-crm' ); ?></a>
				</p>
			</form>
		</div>
		<?php
	}

	/**
	 * One endpoint: configuration, signing secret and its own delivery log.
	 *
	 * @param array<string, mixed> $webhook Stored endpoint.
	 */
	private static function render_detail( array $webhook, string $form_url ): void {
		$deliveries = array_slice(
			array_values(
				array_filter(
					( new DeliveryLog() )->all( DeliveryLog::MAX_ENTRIES ),
					static fn( array $delivery ): bool => (string) $delivery['webhook_id'] === (string) $webhook['id']
				)
			),
			0,
			50
		);
		$labels     = array_map( array( Events::class, 'label' ), (array) $webhook['events'] );
		?>
		<div class="nowcrm-panel">
			<h2>
				<?php echo esc_html( (string) $webhook['name'] ); ?>
				<span class="nowcrm-badge"><?php echo ! empty( $webhook['active'] ) ? esc_html__( '啟用', 'now-crm' ) : esc_html__( '停用', 'now-crm' ); ?></span>
			</h2>

			<ul class="nowcrm-meta">
				<li><span><?php esc_html_e( 'Webhook ID', 'now-crm' ); ?></span><code><?php echo esc_html( (string) $webhook['id'] ); ?></code></li>
				<li><span><?php esc_html_e( '端點 URL', 'now-crm' ); ?></span><code style="word-break:break-all;"><?php echo esc_html( (string) $webhook['url'] ); ?></code></li>
				<li><span><?php esc_html_e( '事件', 'now-crm' ); ?></span><strong><?php echo esc_html( implode( '、', $labels ) ); ?></strong></li>
				<li><span><?php esc_html_e( '更新', 'now-crm' ); ?></span><strong><?php echo esc_html( Menu::format_datetime( (string) $webhook['updated_at'] ) ); ?></strong></li>
			</ul>

			<details style="margin-top:12px;">
				<summary><?php esc_html_e( '簽章密鑰', 'now-crm' ); ?></summary>
				<code style="word-break:break-all;"><?php echo esc_html( (string) $webhook['secret'] ); ?></code>
			</details>

			<p class="submit">
				<a class="button button-primary" href="<?php echo esc_url( self::edit_url( (string) $webhook['id'] ) ); ?>"><?php esc_html_e( '編輯', 'now-crm' ); ?></a>

				<form method="post" action="<?php echo esc_url( $form_url ); ?>" style="display:inline-block;margin:0 0 0 4px;">
					<input type="hidden" name="action" value="nowcrm_webhook_rotate_secret" />
					<input type="hidden" name="id" value="<?php echo esc_attr( (string) $webhook['id'] ); ?>" />
					<?php wp_nonce_field( 'nowcrm_webhook_rotate_secret' ); ?>
					<button type="submit" class="button"><?php esc_html_e( '輪換密鑰', 'now-crm' ); ?></button>
				</form>

				<form method="post" action="<?php echo esc_url( $form_url ); ?>" style="display:inline-block;margin:0 0 0 4px;">
					<input type="hidden" name="action" value="nowcrm_webhook_delete" />
					<input type="hidden" name="id" value="<?php echo esc_attr( (string) $webhook['id'] ); ?>" />
					<?php wp_nonce_field( 'nowcrm_webhook_delete' ); ?>
					<button type="submit" class="button button-link-delete" onclick="return confirm('<?php echo esc_js( __( '確定刪除這個 Webhook？', 'now-crm' ) ); ?>');"><?php esc_html_e( '刪除', 'now-crm' ); ?></button>
				</form>

				<a class="button" href="<?php echo esc_url( self::page_url() ); ?>"><?php esc_html_e( '返回列表', 'now-crm' ); ?></a>
			</p>
		</div>

		<div class="nowcrm-panel">
			<h2><?php esc_html_e( '此端點的傳送紀錄', 'now-crm' ); ?></h2>

			<?php if ( array() === $deliveries ) : ?>
				<p class="description"><?php esc_html_e( '此端點尚無傳送紀錄。', 'now-crm' ); ?></p>
			<?php else : ?>
				<table class="wp-list-table widefat fixed striped nowcrm-table">
					<thead>
						<tr>
							<th><?php esc_html_e( '時間', 'now-crm' ); ?></th>
							<th><?php esc_html_e( '事件', 'now-crm' ); ?></th>
							<th><?php esc_html_e( '狀態', 'now-crm' ); ?></th>
							<th><?php esc_html_e( '嘗試', 'now-crm' ); ?></th>
							<th><?php esc_html_e( '回應', 'now-crm' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $deliveries as $delivery ) : ?>
							<?php
							$next = null !== $delivery['next_attempt_at']
								? sprintf( /* translators: %s: next attempt time. */ __( '，下次 %s', 'now-crm' ), Menu::format_datetime( (string) $delivery['next_attempt_at'] ) )
								: '';
							?>
							<tr>
								<td><?php echo esc_html( Menu::format_datetime( (string) $delivery['created_at'] ) ); ?></td>
								<td><code><?php echo esc_html( (string) $delivery['event'] ); ?></code></td>
								<td><?php echo esc_html( self::status_label( (string) $delivery['status'] ) . $next ); ?></td>
								<td><?php echo esc_html( (string) $delivery['attempts'] . ' / ' . Dispatcher::MAX_ATTEMPTS ); ?></td>
								<td>
									<?php echo esc_html( null === $delivery['response_code'] ? '—' : (string) $delivery['response_code'] ); ?>
									<?php if ( '' !== $delivery['error'] ) : ?>
										<br /><span class="description"><?php echo esc_html( (string) $delivery['error'] ); ?></span>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<p><a href="<?php echo esc_url( (string) add_query_arg( 'view', 'deliveries', self::page_url() ) ); ?>"><?php esc_html_e( '查看所有傳送紀錄', 'now-crm' ); ?></a></p>
		</div>
		<?php
	}

	/**
	 * All recent deliveries across endpoints.
	 *
	 * @param array<string, array<string, mixed>> $webhooks Stored endpoints.
	 */
	private static function render_deliveries( array $webhooks ): void {
		$deliveries = ( new DeliveryLog() )->all( 50 );

		?>
		<div class="nowcrm-panel">
			<h2><?php esc_html_e( '傳送紀錄', 'now-crm' ); ?></h2>

			<?php if ( array() === $deliveries ) : ?>
				<p class="description"><?php esc_html_e( '尚無傳送紀錄。', 'now-crm' ); ?></p>
			<?php else : ?>
				<table class="wp-list-table widefat fixed striped nowcrm-table">
					<thead>
						<tr>
							<th><?php esc_html_e( '時間', 'now-crm' ); ?></th>
							<th><?php esc_html_e( '事件', 'now-crm' ); ?></th>
							<th><?php esc_html_e( 'Webhook', 'now-crm' ); ?></th>
							<th><?php esc_html_e( '狀態', 'now-crm' ); ?></th>
							<th><?php esc_html_e( '嘗試', 'now-crm' ); ?></th>
							<th><?php esc_html_e( '回應', 'now-crm' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $deliveries as $delivery ) : ?>
							<?php
							$name = (string) ( $webhooks[ (string) $delivery['webhook_id'] ]['name'] ?? $delivery['webhook_id'] );
							$next = null !== $delivery['next_attempt_at']
								? sprintf( /* translators: %s: next attempt time. */ __( '，下次 %s', 'now-crm' ), Menu::format_datetime( (string) $delivery['next_attempt_at'] ) )
								: '';
							?>
							<tr>
								<td><?php echo esc_html( Menu::format_datetime( (string) $delivery['created_at'] ) ); ?></td>
								<td><code><?php echo esc_html( (string) $delivery['event'] ); ?></code></td>
								<td><?php echo esc_html( $name ); ?></td>
								<td><?php echo esc_html( self::status_label( (string) $delivery['status'] ) . $next ); ?></td>
								<td><?php echo esc_html( (string) $delivery['attempts'] . ' / ' . Dispatcher::MAX_ATTEMPTS ); ?></td>
								<td>
									<?php echo esc_html( null === $delivery['response_code'] ? '—' : (string) $delivery['response_code'] ); ?>
									<?php if ( '' !== $delivery['error'] ) : ?>
										<br /><span class="description"><?php echo esc_html( (string) $delivery['error'] ); ?></span>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * inbound panel.
	 */
	private static function render_inbound(): void {
		$form_url    = admin_url( 'admin-post.php' );
		$contact_key = Inbound::contact_key();
		$contact_url = Inbound::contact_url();
		$handlers    = Inbound::handlers();

		?>
		<div class="nowcrm-panel">
			<h2><?php esc_html_e( '入站 Webhook', 'now-crm' ); ?></h2>

			<h3><?php esc_html_e( '聯絡人', 'now-crm' ); ?></h3>
			<?php if ( '' === $contact_key ) : ?>
				<p class="description"><?php esc_html_e( '尚未產生聯絡人金鑰。', 'now-crm' ); ?></p>
			<?php else : ?>
				<input type="text" class="large-text" readonly value="<?php echo esc_attr( $contact_url ); ?>" onclick="this.select();" />
				<p class="description"><?php esc_html_e( '這個 URL 就是憑證，請當作密碼保管。輪換後舊 URL 會立即失效。', 'now-crm' ); ?></p>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( $form_url ); ?>">
				<input type="hidden" name="action" value="nowcrm_webhook_rotate_inbound" />
				<?php wp_nonce_field( 'nowcrm_webhook_rotate_inbound' ); ?>
				<button type="submit" class="button" id="nowcrm-webhook-rotate-inbound">
					<?php echo '' === $contact_key ? esc_html__( '產生聯絡人金鑰', 'now-crm' ) : esc_html__( '輪換聯絡人金鑰', 'now-crm' ); ?>
				</button>
			</form>

			<p class="description">
				<?php esc_html_e( 'POST JSON：email 必填；可選 first_name、last_name、phone、lists[]、tags[]。lists 與 tags 可用名稱、slug 或 ID，且必須已存在。', 'now-crm' ); ?>
			</p>

			<h3><?php esc_html_e( '通用處理器', 'now-crm' ); ?></h3>
			<?php if ( array() === $handlers ) : ?>
				<p class="description">
					<?php esc_html_e( '目前沒有註冊處理器。其他程式可用 nowcrm_webhook_handlers 篩選器註冊 handler slug 與名稱。', 'now-crm' ); ?>
				</p>
			<?php else : ?>
				<table class="wp-list-table widefat fixed striped nowcrm-table">
					<thead>
						<tr>
							<th><?php esc_html_e( '處理器', 'now-crm' ); ?></th>
							<th><?php esc_html_e( '入站 URL', 'now-crm' ); ?></th>
							<th><?php esc_html_e( '操作', 'now-crm' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $handlers as $handler => $label ) : ?>
							<?php
							$key = Inbound::handler_key( $handler );
							$url = Inbound::handler_url( $handler );
							?>
							<tr>
								<td><strong><?php echo esc_html( $label ); ?></strong><br /><code><?php echo esc_html( $handler ); ?></code></td>
								<td>
									<?php if ( '' === $url ) : ?>
										<span class="description"><?php esc_html_e( '尚未產生金鑰。', 'now-crm' ); ?></span>
									<?php else : ?>
										<input type="text" class="large-text" readonly value="<?php echo esc_attr( $url ); ?>" onclick="this.select();" />
									<?php endif; ?>
								</td>
								<td>
									<form method="post" action="<?php echo esc_url( $form_url ); ?>">
										<input type="hidden" name="action" value="nowcrm_webhook_rotate_handler" />
										<input type="hidden" name="handler" value="<?php echo esc_attr( $handler ); ?>" />
										<?php wp_nonce_field( 'nowcrm_webhook_rotate_handler' ); ?>
										<button type="submit" class="button button-small" id="nowcrm-webhook-rotate-<?php echo esc_attr( $handler ); ?>">
											<?php echo '' === $key ? esc_html__( '產生金鑰', 'now-crm' ) : esc_html__( '輪換金鑰', 'now-crm' ); ?>
										</button>
									</form>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Save a new or existing webhook.
	 */
	public static function save(): void {
		self::guard( 'nowcrm_webhook_save', Capabilities::MANAGE_SETTINGS );

		$id   = self::post_text( 'id' );
		$data = array(
			'name'   => self::post_text( 'name' ),
			'url'    => self::post_raw( 'url' ),
			'events' => self::post_array( 'events' ),
			'active' => ! empty( $_POST['active'] ),
		);

		$repository = new WebhookRepository();
		$result     = '' === $id ? $repository->create( $data ) : $repository->update( $id, $data );

		if ( is_wp_error( $result ) ) {
			$notice = self::notice_from_error( $result );

			if ( '' === $id ) {
				self::remember_draft( 'new', $data );

				self::redirect( $notice, array( 'view' => 'new' ) );
			}

			if ( null === $repository->find( $id ) ) {
				self::redirect( $notice );
			}

			self::remember_draft( $id, $data );

			self::redirect( $notice, array( 'webhook' => $id ) );
		}

		self::redirect( 'saved' );
	}

	/**
	 * Delete a webhook.
	 */
	public static function delete(): void {
		self::guard( 'nowcrm_webhook_delete', Capabilities::MANAGE_SETTINGS );

		$deleted = ( new WebhookRepository() )->delete( self::post_text( 'id' ) );

		self::redirect( $deleted ? 'deleted' : 'not_found' );
	}

	/**
	 * Rotate an outbound signing secret.
	 */
	public static function rotate_secret(): void {
		self::guard( 'nowcrm_webhook_rotate_secret', Capabilities::MANAGE_SETTINGS );

		$result = ( new WebhookRepository() )->rotate_secret( self::post_text( 'id' ) );

		self::redirect( is_wp_error( $result ) ? self::notice_from_error( $result ) : 'rotated' );
	}

	/**
	 * Create or rotate the contact inbound key.
	 */
	public static function rotate_inbound(): void {
		self::guard( 'nowcrm_webhook_rotate_inbound', Capabilities::MANAGE_SETTINGS );

		Inbound::rotate_contact_key();

		self::redirect( 'rotated' );
	}

	/**
	 * Create or rotate one generic handler key.
	 */
	public static function rotate_handler(): void {
		self::guard( 'nowcrm_webhook_rotate_handler', Capabilities::MANAGE_SETTINGS );

		$result = Inbound::rotate_handler_key( self::post_text( 'handler' ) );

		self::redirect( is_wp_error( $result ) ? 'invalid_handler' : 'rotated' );
	}

	/**
	 * Read and clear the submitted form after a validation failure.
	 *
	 * @param array{view: string, webhook: array<string, mixed>|null, missing: bool} $request Current view.
	 * @return array<string, mixed>|null
	 */
	private static function take_draft( array $request ): ?array {
		$notice = self::requested_notice();

		if ( ! in_array( $notice, self::FORM_ERRORS, true ) || ! in_array( $request['view'], array( 'new', 'edit' ), true ) ) {
			return null;
		}

		$object = 'edit' === $request['view'] && is_array( $request['webhook'] )
			? (string) $request['webhook']['id']
			: 'new';
		$draft  = get_transient( self::draft_key( $object ) );

		if ( ! is_array( $draft ) ) {
			return null;
		}

		delete_transient( self::draft_key( $object ) );

		if ( ( $draft['object'] ?? '' ) !== $object || ! is_array( $draft['fields'] ?? null ) ) {
			return null;
		}

		return $draft['fields'];
	}

	/**
	 * Keep the submitted form fields for one user and object.
	 *
	 * The signing secret is never part of the draft.
	 *
	 * @param string               $object Webhook ID or `new`.
	 * @param array<string, mixed> $data   Submitted name, url, events, active.
	 */
	private static function remember_draft( string $object, array $data ): void {
		set_transient(
			self::draft_key( $object ),
			array(
				'object' => $object,
				'fields' => array(
					'name'   => (string) ( $data['name'] ?? '' ),
					'url'    => (string) ( $data['url'] ?? '' ),
					'events' => array_values( (array) ( $data['events'] ?? array() ) ),
					'active' => ! empty( $data['active'] ),
				),
			),
			5 * MINUTE_IN_SECONDS
		);
	}

	/**
	 * Per-user, per-object draft key.
	 */
	private static function draft_key( string $object ): string {
		return self::DRAFT_STORE . get_current_user_id() . '_' . sanitize_key( $object );
	}

	/**
	 * Notice code from the query string.
	 */
	private static function requested_notice(): string {
		if ( ! isset( $_GET[ self::NOTICE ] ) || ! is_string( $_GET[ self::NOTICE ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return '';
		}

		return sanitize_key( wp_unslash( $_GET[ self::NOTICE ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Render the allow-listed notice.
	 */
	private static function render_notice(): void {
		$code = self::requested_notice();

		if ( '' === $code ) {
			return;
		}

		$messages = self::messages();

		if ( ! isset( $messages[ $code ] ) ) {
			return;
		}

		$errors = array( 'invalid_name', 'invalid_url', 'https_required', 'invalid_events', 'too_many', 'not_found', 'invalid_handler', 'error' );

		printf(
			'<div class="notice %s is-dismissible"><p>%s</p></div>',
			in_array( $code, $errors, true ) ? 'notice-error' : 'notice-success',
			esc_html( $messages[ $code ] )
		);
	}

	/**
	 * Human label for a delivery status.
	 */
	private static function status_label( string $status ): string {
		$labels = array(
			'pending'   => __( '等待中', 'now-crm' ),
			'retrying'  => __( '等待重試', 'now-crm' ),
			'delivered' => __( '已送達', 'now-crm' ),
			'failed'    => __( '失敗', 'now-crm' ),
		);

		return $labels[ $status ] ?? $status;
	}

	/**
	 * Stable page URL.
	 */
	private static function page_url(): string {
		return (string) add_query_arg( 'page', self::SLUG, admin_url( 'admin.php' ) );
	}

	/**
	 * Read-only detail URL for one endpoint.
	 */
	private static function detail_url( string $id ): string {
		return (string) add_query_arg(
			array(
				'page'    => self::SLUG,
				'webhook' => $id,
				'view'    => 'detail',
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Edit URL for one endpoint. This is the historical `webhook` link.
	 */
	private static function edit_url( string $id ): string {
		return (string) add_query_arg(
			array(
				'page'    => self::SLUG,
				'webhook' => $id,
			),
			admin_url( 'admin.php' )
		);
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
	 * Redirect back to the screen with a notice code and optional view.
	 *
	 * @param array<string, mixed> $args Extra query arguments.
	 */
	private static function redirect( string $notice, array $args = array() ): void {
		wp_safe_redirect(
			add_query_arg(
				array_merge(
					array(
						'page'       => self::SLUG,
						self::NOTICE => $notice,
					),
					$args
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Capability and nonce guard.
	 */
	private static function guard( string $action, string $capability ): void {
		if ( ! current_user_can( $capability ) ) {
			wp_die(
				esc_html__( '您沒有權限執行此操作。', 'now-crm' ),
				'',
				array( 'response' => 403 )
			);
		}

		check_admin_referer( $action );
	}

	/**
	 * Allow-listed notice messages.
	 *
	 * @return array<string, string>
	 */
	private static function messages(): array {
		return array(
			'saved'           => __( 'Webhook 已儲存。', 'now-crm' ),
			'deleted'         => __( 'Webhook 已刪除。', 'now-crm' ),
			'rotated'         => __( '金鑰已更新，舊金鑰立即失效。', 'now-crm' ),
			'invalid_name'    => __( '請輸入 100 字以內的 Webhook 名稱。', 'now-crm' ),
			'invalid_url'     => __( 'Webhook 網址不正確。', 'now-crm' ),
			'https_required'  => __( '外部 Webhook 必須使用 HTTPS。', 'now-crm' ),
			'invalid_events'  => __( '請至少選擇一個有效事件。', 'now-crm' ),
			'too_many'        => __( '已達 Webhook 數量上限。', 'now-crm' ),
			'not_found'       => __( '找不到這個 Webhook。', 'now-crm' ),
			'invalid_handler' => __( '未註冊的 Webhook 處理器。', 'now-crm' ),
			'error'           => __( '操作失敗，請再試一次。', 'now-crm' ),
		);
	}

	/**
	 * Map a WP_Error to an allowed notice code.
	 */
	private static function notice_from_error( WP_Error $error ): string {
		$code = preg_replace( '/^nowcrm_webhook_/', '', (string) $error->get_error_code() );
		$code = is_string( $code ) ? $code : '';

		return isset( self::messages()[ $code ] ) ? $code : 'error';
	}

	/**
	 * Unslashed sanitized POST string.
	 */
	private static function post_text( string $key ): string {
		return isset( $_POST[ $key ] ) && is_string( $_POST[ $key ] )
			? sanitize_text_field( wp_unslash( $_POST[ $key ] ) )
			: '';
	}

	/**
	 * Unslashed raw POST string.
	 */
	private static function post_raw( string $key ): string {
		return isset( $_POST[ $key ] ) && is_string( $_POST[ $key ] )
			? trim( (string) wp_unslash( $_POST[ $key ] ) )
			: '';
	}

	/**
	 * Unslashed sanitized POST array.
	 *
	 * @return string[]
	 */
	private static function post_array( string $key ): array {
		if ( ! isset( $_POST[ $key ] ) || ! is_array( $_POST[ $key ] ) ) {
			return array();
		}

		return array_values(
			array_filter(
				array_map(
					static fn( $value ): string => is_string( $value ) ? sanitize_text_field( wp_unslash( $value ) ) : '',
					(array) $_POST[ $key ]
				)
			)
		);
	}
}
