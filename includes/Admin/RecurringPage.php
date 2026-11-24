<?php
/**
 * Recurring campaign schedule detail, content hand-off, and actions.
 *
 * The parent campaign keeps the content. Each period produces one child
 * campaign through Recurring::generate_now(). This screen only changes the
 * schedule and the parent row; it never rewrites the child generation rules.
 *
 * @package NowCRM
 */

namespace NowCRM\Admin;

use NowCRM\Campaign\Campaigns;
use NowCRM\Campaign\Recurring;
use NowCRM\Support\Capabilities;

defined( 'ABSPATH' ) || exit;

final class RecurringPage {

	public const SAVE_ACTION      = 'nowcrm_save_recurring';
	public const STATUS_ACTION    = 'nowcrm_recurring_status';
	public const GENERATE_ACTION  = 'nowcrm_generate_recurring';
	public const DUPLICATE_ACTION = 'nowcrm_duplicate_recurring';
	public const DELETE_ACTION    = 'nowcrm_delete_recurring';

	/** Print the frequency toggle script once per request. */
	private static bool $script_done = false;

	/**
	 * Register the admin-post handlers owned by this screen.
	 */
	public static function register(): void {
		add_action( 'admin_post_' . self::SAVE_ACTION, array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_' . self::STATUS_ACTION, array( __CLASS__, 'handle_status' ) );
		add_action( 'admin_post_' . self::GENERATE_ACTION, array( __CLASS__, 'handle_generate' ) );
		add_action( 'admin_post_' . self::DUPLICATE_ACTION, array( __CLASS__, 'handle_duplicate' ) );
		add_action( 'admin_post_' . self::DELETE_ACTION, array( __CLASS__, 'handle_delete' ) );
	}

	public static function detail_url( int $id ): string {
		return Menu::page_url( Menu::RECURRING_SLUG, array( 'campaign' => $id ) );
	}

	/**
	 * Workspace URL opened on the 設定 tab.
	 */
	public static function settings_url( int $id ): string {
		return Menu::page_url( Menu::RECURRING_SLUG, array( 'campaign' => $id, 'tab' => 'settings' ) );
	}

	public static function content_url( int $id ): string {
		return Menu::page_url( Menu::RECURRING_SLUG, array( 'campaign' => $id, 'edit' => 'content' ) );
	}

	public static function list_url(): string {
		return Menu::page_url( Menu::RECURRING_SLUG );
	}

	/**
	 * Parent campaign, or null for a normal campaign or a generated child.
	 */
	public static function parent( int $id ): ?\WP_Post {
		$post = Campaigns::get( $id );

		if ( ! $post ) {
			return null;
		}

		if ( '' !== (string) get_post_meta( $id, Recurring::META_PARENT, true ) ) {
			return null;
		}

		$status = (string) get_post_meta( $id, Recurring::META_STATUS, true );

		return in_array( $status, array_keys( Recurring::status_labels() ), true ) ? $post : null;
	}

	/**
	 * Short frequency label for a table cell.
	 *
	 * @param array<string, mixed> $settings Schedule settings.
	 */
	public static function frequency_text( array $settings ): string {
		return match ( (string) ( $settings['frequency'] ?? 'daily' ) ) {
			'weekly'  => __( '每週', 'now-crm' ),
			'monthly' => __( '每月', 'now-crm' ),
			default   => __( '每日', 'now-crm' ),
		};
	}

	/**
	 * Frequency plus time of day for a heading or meta line.
	 *
	 * @param array<string, mixed> $settings Schedule settings.
	 */
	public static function frequency_label( array $settings ): string {
		$time = (string) ( $settings['time'] ?? '' );

		if ( 'weekly' === ( $settings['frequency'] ?? '' ) ) {
			/* translators: 1: weekday label, 2: send time */
			return sprintf( __( '每週%1$s %2$s', 'now-crm' ), self::day_label( (string) ( $settings['day'] ?? '' ) ), $time );
		}

		if ( 'monthly' === ( $settings['frequency'] ?? '' ) ) {
			/* translators: 1: day of month, 2: send time */
			return sprintf( __( '每月 %1$d 日 %2$s', 'now-crm' ), max( 1, (int) ( $settings['day'] ?? 1 ) ), $time );
		}

		/* translators: %s: send time */
		return sprintf( __( '每日 %s', 'now-crm' ), $time );
	}

	/**
	 * Weekday label for a stored day key.
	 */
	public static function day_label( string $day ): string {
		return array(
			'mon' => __( '週一', 'now-crm' ),
			'tue' => __( '週二', 'now-crm' ),
			'wed' => __( '週三', 'now-crm' ),
			'thu' => __( '週四', 'now-crm' ),
			'fri' => __( '週五', 'now-crm' ),
			'sat' => __( '週六', 'now-crm' ),
			'sun' => __( '週日', 'now-crm' ),
		)[ $day ] ?? '';
	}

	/**
	 * Recurring status pill. Cells are echoed raw by WorkflowCatalog.
	 */
	public static function status_pill( string $status ): string {
		$labels  = Recurring::status_labels();
		$active  = Recurring::STATUS_ACTIVE === $status;
		$tone    = match ( $status ) {
			Recurring::STATUS_ACTIVE   => 'nowcrm-status-pill--active',
			Recurring::STATUS_PAUSED   => 'nowcrm-status-pill--paused',
			Recurring::STATUS_INACTIVE => 'nowcrm-status-pill--inactive',
			default                    => 'nowcrm-status-pill--draft',
		};

		return '<span class="nowcrm-status-pill ' . $tone . '">'
			. ( $active ? '<span class="nowcrm-status-pill__dot" aria-hidden="true"></span>' : '' )
			. esc_html( $labels[ $status ] ?? $status )
			. '</span>';
	}

	/**
	 * Print an allow-listed notice for recurring screens.
	 */
	public static function render_notice(): void {
		$code = sanitize_key( Menu::query( 'nowcrm_notice' ) );
		$messages = array(
			'recurring_created'         => __( '已建立定期電子報草稿。請先編輯內容，再啟用定期寄送。', 'now-crm' ),
			'recurring_saved'           => __( '已儲存定期寄送設定。', 'now-crm' ),
			'recurring_paused'          => __( '已暫停定期寄送。', 'now-crm' ),
			'recurring_resumed'         => __( '已啟用定期寄送。', 'now-crm' ),
			'recurring_disabled'        => __( '已停用定期寄送。', 'now-crm' ),
			'recurring_generated'       => __( '已建立這一期的子電子報。', 'now-crm' ),
			'recurring_duplicate_period' => __( '這個期間的子電子報已存在。', 'now-crm' ),
			'recurring_generate_failed' => __( '無法建立子電子報。請確認主旨、正文與收件人。', 'now-crm' ),
			'recurring_inactive'        => __( '請先啟用定期寄送。', 'now-crm' ),
			'recurring_duplicated'      => __( '已複製定期電子報，副本為未啟用狀態。', 'now-crm' ),
			'recurring_deleted'         => __( '已刪除定期電子報與其子電子報。', 'now-crm' ),
			'recurring_delete_blocked'  => __( '有子電子報正在排程、寄送或暫停中，未刪除任何資料。', 'now-crm' ),
			'recurring_content_required' => __( '請先填寫主旨和正文，再啟用定期寄送。', 'now-crm' ),
			'recurring_invalid'         => __( '設定不正確，未儲存任何變更。', 'now-crm' ),
			'recurring_not_found'       => __( '找不到定期電子報。', 'now-crm' ),
			'recurring_forbidden'       => __( '您沒有執行此操作的權限。', 'now-crm' ),
			'recurring_failed'          => __( '操作失敗，請再試一次。', 'now-crm' ),
		);

		if ( ! isset( $messages[ $code ] ) ) {
			return;
		}

		$errors = array(
			'recurring_delete_blocked',
			'recurring_content_required',
			'recurring_invalid',
			'recurring_not_found',
			'recurring_forbidden',
			'recurring_failed',
			'recurring_generate_failed',
			'recurring_inactive',
			'recurring_duplicate_period',
		);

		printf(
			'<div class="notice %1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( in_array( $code, $errors, true ) ? 'notice-error' : 'notice-success' ),
			esc_html( $messages[ $code ] )
		);
	}

	/**
	 * Frequency, time, conditional day, and auto-send fields.
	 *
	 * @param array<string, mixed> $settings Schedule settings.
	 */
	public static function render_schedule_inputs( array $settings ): void {
		$frequency = (string) ( $settings['frequency'] ?? 'daily' );
		$time      = (string) ( $settings['time'] ?? '09:00' );
		$day       = (string) ( $settings['day'] ?? '' );
		$weekly    = 'weekly' === $frequency;
		$monthly   = 'monthly' === $frequency;
		?>
		<label>
			<span><?php esc_html_e( '頻率', 'now-crm' ); ?></span>
			<select name="frequency" data-nowcrm-recurring-frequency>
				<option value="daily" <?php selected( $frequency, 'daily' ); ?>><?php esc_html_e( '每日', 'now-crm' ); ?></option>
				<option value="weekly" <?php selected( $frequency, 'weekly' ); ?>><?php esc_html_e( '每週', 'now-crm' ); ?></option>
				<option value="monthly" <?php selected( $frequency, 'monthly' ); ?>><?php esc_html_e( '每月', 'now-crm' ); ?></option>
			</select>
		</label>

		<label>
			<span><?php esc_html_e( '時間', 'now-crm' ); ?></span>
			<input type="time" name="time" value="<?php echo esc_attr( $time ); ?>" required="required">
		</label>

		<div data-nowcrm-day-group="weekly"<?php echo $weekly ? '' : ' hidden'; ?>>
			<label>
				<span><?php esc_html_e( '每週星期', 'now-crm' ); ?></span>
				<select name="day" <?php disabled( ! $weekly ); ?>>
					<?php foreach ( array( 'mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun' ) as $key ) : ?>
						<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $weekly ? $day : 'mon', $key ); ?>>
							<?php echo esc_html( self::day_label( $key ) ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</label>
		</div>

		<div data-nowcrm-day-group="monthly"<?php echo $monthly ? '' : ' hidden'; ?>>
			<label>
				<span><?php esc_html_e( '每月日期', 'now-crm' ); ?></span>
				<input type="number" name="day" min="1" max="31" step="1"
					value="<?php echo esc_attr( $monthly ? (string) max( 1, min( 31, (int) $day ) ) : '1' ); ?>"
					<?php disabled( ! $monthly ); ?>>
			</label>
		</div>

		<label class="nowcrm-recurring__check">
			<span><?php esc_html_e( '自動寄送', 'now-crm' ); ?></span>
			<input type="checkbox" name="auto_send" value="1" <?php checked( ! empty( $settings['auto_send'] ) ); ?>>
		</label>

		<?php if ( ! self::$script_done ) : ?>
			<?php
			self::$script_done = true;
			wp_print_inline_script_tag(
				<<<'JS'
( function () {
	document.querySelectorAll( '[data-nowcrm-recurring-frequency]' ).forEach( function ( select ) {
		var form = select.closest( 'form' );
		if ( ! form ) {
			return;
		}
		var sync = function () {
			var value = select.value;
			form.querySelectorAll( '[data-nowcrm-day-group]' ).forEach( function ( group ) {
				var match = group.getAttribute( 'data-nowcrm-day-group' ) === value;
				group.hidden = ! match;
				group.querySelectorAll( 'input, select' ).forEach( function ( field ) {
					field.disabled = ! match;
				} );
			} );
		};
		select.addEventListener( 'change', sync );
		sync();
	} );
}() );
JS
			);
			?>
		<?php endif; ?>
		<?php
	}

	/**
	 * Trash one parent and its children, or refuse when a child is live.
	 *
	 * @return true|\WP_Error
	 */
	public static function trash_parent( int $id ) {
		$post_type = get_post_type_object( Campaigns::POST_TYPE );

		if ( $id < 1 || ! Capabilities::can_manage_contacts() || ! $post_type || ! self::parent( $id ) ) {
			return Campaigns::error( 'recurring_not_found', __( '找不到定期電子報。', 'now-crm' ), 404 );
		}

		if ( ! current_user_can( $post_type->cap->delete_post, $id ) ) {
			return Campaigns::error( 'recurring_forbidden', __( '您沒有刪除這封定期電子報的權限。', 'now-crm' ), 403 );
		}

		return Campaigns::transaction(
			$id,
			static function ( \WP_Post $post ) use ( $id, $post_type ) {
				unset( $post );

				if ( ! current_user_can( $post_type->cap->delete_post, $id ) ) {
					return Campaigns::error( 'recurring_forbidden', __( '您沒有刪除這封定期電子報的權限。', 'now-crm' ), 403 );
				}

				if ( null === self::parent( $id ) ) {
					return Campaigns::error( 'recurring_not_found', __( '找不到定期電子報。', 'now-crm' ), 404 );
				}

				$children = Recurring::children( $id, PHP_INT_MAX );

				foreach ( $children as $child ) {
					if ( in_array( Campaigns::state( $child ), array( Campaigns::STATUS_SCHEDULED, Campaigns::STATUS_SENDING, Campaigns::STATUS_PAUSED ), true ) ) {
						return Campaigns::error( 'recurring_active_child', __( '有子電子報正在排程、寄送或暫停中。', 'now-crm' ), 409 );
					}
				}

				foreach ( $children as $child ) {
					if ( ! wp_trash_post( $child ) ) {
						return Campaigns::error( 'recurring_delete', __( '無法刪除子電子報。', 'now-crm' ) );
					}
				}

				return wp_trash_post( $id )
					? true
					: Campaigns::error( 'recurring_delete', __( '無法刪除定期電子報。', 'now-crm' ) );
			}
		);
	}

	/**
	 * Save the schedule form. Activation keeps its content check.
	 */
	public static function handle_save(): void {
		self::guard( self::SAVE_ACTION );

		$id   = self::posted_id();
		$post = self::parent( $id );

		if ( ! $post ) {
			self::redirect( self::list_url(), array( 'nowcrm_notice' => 'recurring_not_found' ) );
		}

		if ( ! current_user_can( 'edit_post', $id ) ) {
			self::redirect( self::detail_url( $id ), array( 'nowcrm_notice' => 'recurring_forbidden' ) );
		}

		$title = trim( sanitize_text_field( self::post_string( 'title' ) ) );

		if ( '' === $title ) {
			self::redirect( self::detail_url( $id ), array( 'nowcrm_notice' => 'recurring_invalid' ) );
		}

		$settings = Recurring::settings( $id );
		$result   = Recurring::save(
			$id,
			array(
				'status'    => $settings['status'],
				'frequency' => sanitize_key( self::post_string( 'frequency' ) ),
				'time'      => sanitize_text_field( self::post_string( 'time' ) ),
				'day'       => sanitize_text_field( self::post_string( 'day' ) ),
				'auto_send' => isset( $_POST['auto_send'] ),
			)
		);

		if ( is_wp_error( $result ) ) {
			self::redirect( self::detail_url( $id ), array( 'nowcrm_notice' => self::notice_for_error( $result ) ) );
		}

		if ( $title !== get_the_title( $post ) ) {
			wp_update_post(
				array(
					'ID'         => $id,
					'post_title' => wp_slash( $title ),
				)
			);
		}

		self::redirect( self::detail_url( $id ), array( 'nowcrm_notice' => 'recurring_saved' ) );
	}

	/**
	 * Pause, resume, or disable one parent schedule.
	 */
	public static function handle_status(): void {
		self::guard( self::STATUS_ACTION );

		$id   = self::posted_id();
		$post = self::parent( $id );
		$to_list = 'list' === sanitize_key( self::post_string( 'redirect' ) );
		$target  = $to_list ? self::list_url() : self::detail_url( $id );

		if ( ! $post ) {
			self::redirect( self::list_url(), array( 'nowcrm_notice' => 'recurring_not_found' ) );
		}

		if ( ! current_user_can( 'edit_post', $id ) ) {
			self::redirect( $target, array( 'nowcrm_notice' => 'recurring_forbidden' ) );
		}

		$op = sanitize_key( self::post_string( 'op' ) );
		$result = match ( $op ) {
			'pause'   => Recurring::pause( $id ),
			'resume'  => Recurring::resume( $id ),
			'disable' => Recurring::disable( $id ),
			default   => null,
		};

		if ( null === $result ) {
			self::redirect( $target, array( 'nowcrm_notice' => 'recurring_invalid' ) );
		}

		if ( is_wp_error( $result ) ) {
			self::redirect( $target, array( 'nowcrm_notice' => self::notice_for_error( $result ) ) );
		}

		$notice = match ( $op ) {
			'pause'   => 'recurring_paused',
			'resume'  => 'recurring_resumed',
			default   => 'recurring_disabled',
		};

		self::redirect( $target, array( 'nowcrm_notice' => $notice ) );
	}

	/**
	 * Create one child for the next period now.
	 */
	public static function handle_generate(): void {
		self::guard( self::GENERATE_ACTION );

		$id   = self::posted_id();
		$post = self::parent( $id );

		if ( ! $post ) {
			self::redirect( self::list_url(), array( 'nowcrm_notice' => 'recurring_not_found' ) );
		}

		if ( ! current_user_can( 'edit_post', $id ) ) {
			self::redirect( self::detail_url( $id ), array( 'nowcrm_notice' => 'recurring_forbidden' ) );
		}

		$child = Recurring::generate_now( $id );

		if ( is_wp_error( $child ) ) {
			self::redirect( self::detail_url( $id ), array( 'nowcrm_notice' => self::notice_for_error( $child, 'recurring_generate_failed' ) ) );
		}

		self::redirect( self::detail_url( $id ), array( 'nowcrm_notice' => 'recurring_generated' ) );
	}

	/**
	 * Copy the parent content and schedule, and leave the copy inactive.
	 */
	public static function handle_duplicate(): void {
		self::guard( self::DUPLICATE_ACTION );

		$post_type = get_post_type_object( Campaigns::POST_TYPE );

		if ( ! $post_type || ! current_user_can( $post_type->cap->create_posts ) ) {
			wp_die( esc_html__( '您沒有權限執行此操作。', 'now-crm' ), '', array( 'response' => 403 ) );
		}

		$id   = self::posted_id();
		$post = self::parent( $id );

		if ( ! $post ) {
			self::redirect( self::list_url(), array( 'nowcrm_notice' => 'recurring_not_found' ) );
		}

		if ( ! current_user_can( 'edit_post', $id ) ) {
			self::redirect( self::detail_url( $id ), array( 'nowcrm_notice' => 'recurring_forbidden' ) );
		}

		$settings = Recurring::settings( $id );
		$copy     = Campaigns::duplicate( $post );

		if ( is_wp_error( $copy ) || ! $copy ) {
			self::redirect( self::detail_url( $id ), array( 'nowcrm_notice' => 'recurring_failed' ) );
		}

		$copy = (int) $copy;
		wp_update_post(
			array(
				'ID'         => $copy,
				'post_title' => wp_slash( '複製 ' . get_the_title( $post ) ),
			)
		);

		$result = Recurring::save(
			$copy,
			array(
				'status'    => Recurring::STATUS_INACTIVE,
				'frequency' => $settings['frequency'],
				'time'      => $settings['time'],
				'day'       => $settings['day'],
				'auto_send' => $settings['auto_send'],
			)
		);

		if ( is_wp_error( $result ) ) {
			wp_trash_post( $copy );
			self::redirect( self::detail_url( $id ), array( 'nowcrm_notice' => 'recurring_failed' ) );
		}

		self::redirect( self::detail_url( $copy ), array( 'nowcrm_notice' => 'recurring_duplicated' ) );
	}

	/**
	 * Trash one parent and its children under the active-child guard.
	 */
	public static function handle_delete(): void {
		self::guard( self::DELETE_ACTION );

		$id     = self::posted_id();
		$result = self::trash_parent( $id );

		if ( is_wp_error( $result ) ) {
			$notice = self::notice_for_error( $result );

			if ( in_array( $notice, array( 'recurring_not_found', 'recurring_forbidden' ), true ) ) {
				self::redirect( self::list_url(), array( 'nowcrm_notice' => $notice ) );
			}

			self::redirect( self::detail_url( $id ), array( 'nowcrm_notice' => $notice ) );
		}

		Recurring::sync();

		self::redirect( self::list_url(), array( 'nowcrm_notice' => 'recurring_deleted' ) );
	}

	/**
	 * Capability and nonce gate shared by the recurring handlers.
	 */
	private static function guard( string $action ): void {
		if ( ! Capabilities::can_manage_contacts() ) {
			wp_die( esc_html__( '您沒有執行此操作的權限。', 'now-crm' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( $action );
	}

	/**
	 * Map a repository error to an allow-listed notice code.
	 */
	private static function notice_for_error( \WP_Error $error, string $default = 'recurring_failed' ): string {
		return match ( $error->get_error_code() ) {
			'nowcrm_recurring_content' => 'recurring_content_required',
			'nowcrm_recurring_frequency',
			'nowcrm_recurring_time',
			'nowcrm_recurring_day',
			'nowcrm_recurring_status' => 'recurring_invalid',
			'nowcrm_recurring_inactive' => 'recurring_inactive',
			'nowcrm_recurring_duplicate' => 'recurring_duplicate_period',
			'nowcrm_recurring_source_invalid',
			'nowcrm_recurring_schedule' => 'recurring_generate_failed',
			'nowcrm_recurring_active_child' => 'recurring_delete_blocked',
			'nowcrm_recurring_forbidden' => 'recurring_forbidden',
			'nowcrm_recurring_not_found',
			'nowcrm_not_found' => 'recurring_not_found',
			default => $default,
		};
	}

	private static function posted_id(): int {
		return isset( $_POST['campaign_id'] ) ? absint( wp_unslash( (string) $_POST['campaign_id'] ) ) : 0;
	}

	private static function post_string( string $key ): string {
		return isset( $_POST[ $key ] ) && is_string( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : '';
	}

	private static function redirect( string $url, array $args = array() ): void {
		if ( $args ) {
			$url = add_query_arg( $args, $url );
		}

		wp_safe_redirect( $url );
		exit;
	}
}
