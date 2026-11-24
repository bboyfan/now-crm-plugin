<?php
/**
 * admin-post handlers and admin notices.
 *
 * @package NowCRM
 */

namespace NowCRM\Admin;

use NowCRM\Automation\AutomationRepository;
use NowCRM\Automation\TriggerRegistry;
use NowCRM\Contacts\ContactRepository;
use NowCRM\Contacts\CustomFieldValues;
use NowCRM\Contacts\ProfileFields;
use NowCRM\Contacts\Status;
use NowCRM\Email\EmailJobRepository;
use NowCRM\Email\Queue;
use NowCRM\Sequences\Engine as SequenceEngine;
use NowCRM\Sequences\SequenceRepository;
use NowCRM\Support\Capabilities;
use NowCRM\Support\Schema;
use NowCRM\Support\Settings;
use NowCRM\Terms\TermRepository;
use WP_Error;

defined( 'ABSPATH' ) || exit;

final class Actions {

	public const ACTION_STATUS = 'nowcrm_change_contact_status';

	public const ACTION_TERM = 'nowcrm_change_contact_term';

	/**
	 * Notice codes that represent failures.
	 *
	 * @var string[]
	 */
	private const ERROR_CODES = array(
		'invalid_email',
		'duplicate_email',
		'invalid_status',
		'invalid_prefix',
		'invalid_contact_type',
		'invalid_country',
		'invalid_timezone',
		'invalid_date_of_birth',
		'invalid_custom_values',
		'invalid_term',
		'invalid_operation',
		'duplicate_term',
		'import_skipped',
		'has_orders',
		'not_found',
		'db_error',
		'error',
		'custom_failed',
		'optin_failed',
		'campaign_title_required',
		'campaign_create_failed',
		'automation_invalid_steps',
		'automation_invalid_trigger',
		'automation_save_failed',
		'sequence_invalid_steps',
		'sequence_audience',
		'sequence_save_failed',
		'sequence_enroll_failed',
		'cart_automation_invalid',
		'job_retry_failed',
		'queue_disabled',
		'settings_invalid_ses_configuration_set',
	);

	/**
	 * Maximum IDs accepted by one batch delete request.
	 */
	private const BULK_LIMIT = 200;

	/**
	 * Maximum CSV rows accepted by one import request.
	 */
	private const IMPORT_LIMIT = 500;

	/**
	 * Maximum uploaded CSV size in bytes.
	 */
	private const IMPORT_MAX_BYTES = 1048576;

	/**
	 * Register every admin-post handler.
	 */
	public static function register(): void {
		$handlers = array(
			'nowcrm_save_settings'  => 'save_settings',
			'nowcrm_create_contact' => 'create_contact',
			'nowcrm_save_contact'   => 'save_contact',
			self::ACTION_STATUS     => 'change_contact_status',
			self::ACTION_TERM       => 'change_contact_term',
			'nowcrm_delete_contact' => 'delete_contact',
			'nowcrm_create_term'    => 'create_term',
			'nowcrm_update_term'    => 'update_term',
			'nowcrm_delete_term'    => 'delete_term',
			'nowcrm_delete_terms'   => 'delete_terms',
			'nowcrm_import_terms'   => 'import_terms',
			'nowcrm_retry_job'      => 'retry_job',
			'nowcrm_run_queue'      => 'run_queue',
			'nowcrm_repair_schema'  => 'repair_schema',
			'nowcrm_create_automation' => 'create_automation',
			'nowcrm_save_automation' => 'save_automation',
			'nowcrm_set_automation_status' => 'set_automation_status',
			'nowcrm_duplicate_automation' => 'duplicate_automation',
			'nowcrm_delete_automation' => 'delete_automation',
			'nowcrm_create_sequence'   => 'create_sequence',
			'nowcrm_save_sequence'     => 'save_sequence',
			'nowcrm_set_sequence_status' => 'set_sequence_status',
			'nowcrm_duplicate_sequence' => 'duplicate_sequence',
			'nowcrm_delete_sequence'   => 'delete_sequence',
			'nowcrm_enroll_sequence'   => 'enroll_sequence',
			'nowcrm_cancel_sequence_enrollment' => 'cancel_sequence_enrollment',
		);

		foreach ( $handlers as $action => $method ) {
			add_action( 'admin_post_' . $action, array( __CLASS__, $method ) );
		}

		ContactNotes::register();
		ContactBulkActions::register();
		ContactEmailPanel::register();

		// Settings form for custom fields owns its own admin-post action.
		( new CustomFields() )->register();
	}

	/**
	 * Allow-listed notice messages.
	 *
	 * @return array<string, string>
	 */
	private static function messages(): array {
		return array(
			'saved'          => __( '已儲存。', 'now-crm' ),
			'created'        => __( '已建立。', 'now-crm' ),
			'campaign_title_required' => __( '請輸入電子報的內部標題。', 'now-crm' ),
			'campaign_create_failed'  => __( '電子報建立失敗，請再試一次。', 'now-crm' ),
			'automation_created' => __( '自動化已建立，請設定步驟後啟用。', 'now-crm' ),
			'automation_saved'   => __( '自動化已儲存。', 'now-crm' ),
			'automation_status_saved' => __( '自動化狀態已更新。', 'now-crm' ),
			'automation_duplicated' => __( '已建立自動化副本。', 'now-crm' ),
			'automation_invalid_steps' => __( '步驟格式不正確，未儲存。請檢查步驟與條件。', 'now-crm' ),
			'automation_invalid_trigger' => __( '觸發事件不正確。', 'now-crm' ),
			'automation_save_failed' => __( '自動化儲存失敗，請檢查欄位後再試。', 'now-crm' ),
			'sequence_created'   => __( '序列已建立。請設定步驟與受眾後再啟用。', 'now-crm' ),
			'sequence_saved'     => __( '序列已儲存。', 'now-crm' ),
			'sequence_status_saved' => __( '序列狀態已更新。', 'now-crm' ),
			'sequence_duplicated' => __( '已建立序列副本。', 'now-crm' ),
			'sequence_deleted'   => __( '序列與追蹤紀錄已刪除。', 'now-crm' ),
			'sequence_enrolled'  => __( '已處理一批受眾。', 'now-crm' ),
			'sequence_enrollment_cancelled' => __( '已取消這位聯絡人的序列追蹤。', 'now-crm' ),
			'sequence_invalid_steps' => __( '序列步驟不正確，未儲存。請檢查主旨、內容、格式與延遲秒數。', 'now-crm' ),
			'sequence_audience'  => __( '序列受眾不正確，請重新選擇名單、標籤、分眾或篩選條件。', 'now-crm' ),
			'sequence_save_failed' => __( '序列儲存失敗，請檢查欄位後再試。', 'now-crm' ),
			'sequence_enroll_failed' => __( '加入受眾失敗，請確認序列已啟用且有有效受眾。', 'now-crm' ),
			'contact_saved'  => __( '聯絡人資料已儲存。', 'now-crm' ),
			'status_saved'   => __( '狀態已更新。', 'now-crm' ),
			'membership_added'   => __( '已加入名單或標籤。', 'now-crm' ),
			'membership_removed' => __( '已移除名單或標籤。', 'now-crm' ),
			'deleted'        => __( '已刪除。', 'now-crm' ),
			'settings_saved' => __( '設定已儲存。', 'now-crm' ),
			'cart_automation_invalid' => __( '購物車自動化未儲存。請選擇啟用中、觸發事件為「購物車放棄」且有 Email 步驟的流程，或改用受管理預設流程。', 'now-crm' ),
			'job_retried'    => __( '郵件已重新排入佇列。', 'now-crm' ),
			'job_retry_failed' => __( '找不到這筆郵件，或目前狀態不可重試。', 'now-crm' ),
			'queue_processed' => __( '已處理一批佇列。', 'now-crm' ),
			'queue_disabled'  => __( '實際寄送未開啟，佇列不會執行。', 'now-crm' ),
			'settings_invalid_ses_configuration_set' => __( 'SES Configuration Set 名稱不正確，未儲存。請使用 1–64 個英文字母、數字、連字號或底線；空白表示使用身分預設。原有設定未變更。', 'now-crm' ),
			'invalid_email'  => __( 'Email 格式不正確，請重新輸入。', 'now-crm' ),
			'duplicate_email' => __( '此 Email 已存在於其他聯絡人。', 'now-crm' ),
			'invalid_status' => __( '聯絡人狀態不正確。', 'now-crm' ),
			'invalid_prefix' => __( '稱謂不正確。', 'now-crm' ),
			'invalid_contact_type' => __( '聯絡人類型不正確。', 'now-crm' ),
			'invalid_country' => __( '國家代碼不正確，請使用兩個字母的 ISO 代碼。', 'now-crm' ),
			'invalid_timezone' => __( '時區不正確。', 'now-crm' ),
			'invalid_date_of_birth' => __( '出生日期格式不正確，請使用 YYYY-MM-DD。', 'now-crm' ),
			'invalid_custom_values' => __( '自訂欄位值不正確，尚未儲存。', 'now-crm' ),
			'invalid_term'   => __( '名單／標籤資料不正確。', 'now-crm' ),
			'invalid_operation' => __( '操作不正確，未變更任何名單或標籤。', 'now-crm' ),
			'duplicate_term' => __( '同名或同 slug 的名單／標籤已存在。', 'now-crm' ),
			'imported'       => __( '已匯入或更新。相同 slug 只更新名稱，原有說明與聯絡人關聯會保留。', 'now-crm' ),
			'import_skipped' => __( '沒有可匯入的資料。請確認 CSV 第一列包含 name（或 title）欄位。', 'now-crm' ),
			'has_orders'     => __( '此聯絡人仍連結 WooCommerce 訂單，請先確認訂單歸屬後再刪除。', 'now-crm' ),
			'not_found'      => __( '找不到資料，可能已被刪除。', 'now-crm' ),
			'db_error'       => __( '資料庫寫入失敗，請再試一次。', 'now-crm' ),
			'custom_failed'  => __( '聯絡人已建立，但自訂欄位未能儲存。請到聯絡人詳細資料重新輸入。', 'now-crm' ),
			'optin_failed'   => __( '聯絡人已建立，但訂閱確認信未能排入佇列。請到聯絡人詳細資料重新寄送。', 'now-crm' ),
			'error'          => __( '操作失敗，請再試一次。', 'now-crm' ),
		);
	}

	/**
	 * Print the notice requested by the current screen, if it is allow-listed.
	 */
	public static function render_notice(): void {
		if ( ! isset( $_GET['nowcrm_notice'] ) || ! is_string( $_GET['nowcrm_notice'] ) ) {
			return;
		}

		$code     = sanitize_key( wp_unslash( $_GET['nowcrm_notice'] ) );
		$messages = self::messages();

		if ( ! array_key_exists( $code, $messages ) ) {
			return;
		}

		$class = in_array( $code, self::ERROR_CODES, true ) ? 'notice-error' : 'notice-success';

		printf(
			'<div class="notice %1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $class ),
			esc_html( $messages[ $code ] )
		);
	}

	/**
	 * Translates a repository error into an allow-listed notice code.
	 */
	public static function notice_from_error( WP_Error $error ): string {
		$code = preg_replace( '/^nowcrm_/', '', (string) $error->get_error_code() );
		$code = is_string( $code ) ? $code : '';

		$aliases = array(
			'invalid_term_type' => 'invalid_term',
			'invalid_term_name' => 'invalid_term',
			'invalid_term_slug' => 'invalid_term',
		);

		if ( isset( $aliases[ $code ] ) ) {
			$code = $aliases[ $code ];
		}

		if ( str_starts_with( $code, 'automation_' ) && 'automation_invalid_steps' !== $code ) {
			$code = 'automation_save_failed';
		}

		if ( str_starts_with( $code, 'sequence_' ) ) {
			$code = match ( $code ) {
				'sequence_steps', 'sequence_subject', 'sequence_content', 'sequence_mode', 'sequence_delay', 'sequence_preheader' => 'sequence_invalid_steps',
				'sequence_audience' => 'sequence_audience',
				'sequence_inactive' => 'sequence_enroll_failed',
				'sequence_not_found' => 'not_found',
				default => 'sequence_save_failed',
			};
		}

		return array_key_exists( $code, self::messages() ) ? $code : 'error';
	}

	/**
	 * Save every typed setting. Checkboxes that are absent become false; text
	 * and number fields absent from a partial form keep their current value.
	 */
	public static function save_settings(): void {
		self::guard( 'nowcrm_save_settings', Capabilities::MANAGE_SETTINGS );

		$patch = array();
		$scope = self::post_raw( 'settings_scope', '' );
		$scopes = array(
			'general' => array(
				'woo_sync_enabled',
				'auto_subscribe_new_woo_contacts',
				'show_checkout_subscription_checkbox',
			),
			'email'   => array(
				'email_sending_enabled',
				'email_transport',
				'email_from_name',
				'email_from_address',
				'email_reply_to',
				'email_batch_size',
				'email_throttle_per_second',
				'email_max_attempts',
				'email_lease_minutes',
				'campaign_tracking_enabled',
				'sequence_tracking_enabled',
				'ses_configuration_set',
				'ses_topic_arn',
			),
			'cart'    => array(
				'abandoned_cart_enabled',
				'cart_idle_minutes',
				'cart_reminder_delay_minutes',
				'cart_lost_days',
				'cart_cooldown_days',
				'cart_max_reminders',
				'cart_recovery_days',
			),
		);

		if ( ! isset( $scopes[ $scope ] ) ) {
			self::redirect( Menu::SETTINGS_SLUG, 'error' );
		}

		// Reject unreadable input for the SES configuration set before any save.
		// Settings::cast strips unsupported characters, which would silently
		// change the name; the operator must see the rejected value instead.
		if ( 'email' === $scope && array_key_exists( 'ses_configuration_set', $_POST ) ) {
			$raw_configuration_set = $_POST['ses_configuration_set']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- presence and type only; the patch value is validated below.

			if ( ! is_string( $raw_configuration_set ) ) {
				self::redirect( Menu::SETTINGS_SLUG, 'settings_invalid_ses_configuration_set', array( 'tab' => 'email' ) );
			}
		}

		foreach ( $scopes[ $scope ] as $key ) {
			$default = Settings::DEFAULTS[ $key ];

			if ( is_bool( $default ) ) {
				$patch[ $key ] = '1' === self::post_raw( $key );
				continue;
			}

			if ( ! isset( $_POST[ $key ] ) || ! is_string( $_POST[ $key ] ) ) {
				continue;
			}

			$raw = wp_unslash( $_POST[ $key ] );

			$patch[ $key ] = is_int( $default ) ? (int) $raw : $raw;
		}

		if ( 'email' === $scope && array_key_exists( 'ses_configuration_set', $patch ) ) {
			$configuration_set = trim( (string) $patch['ses_configuration_set'] );

			// AWS accepts 1–64 ASCII letters, digits, hyphens, or underscores.
			if ( '' !== $configuration_set && ( strlen( $configuration_set ) > 64 || ! preg_match( '/^[A-Za-z0-9_-]+$/', $configuration_set ) ) ) {
				self::redirect( Menu::SETTINGS_SLUG, 'settings_invalid_ses_configuration_set', array( 'tab' => 'email' ) );
			}

			$patch['ses_configuration_set'] = $configuration_set;
		}

		if ( 'cart' === $scope && array_key_exists( 'cart_automation_id', $_POST ) ) {
			$raw_selection = $_POST['cart_automation_id']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated below.

			if ( ! is_string( $raw_selection ) ) {
				self::redirect( Menu::CART_SLUG, 'cart_automation_invalid', array( 'tab' => 'settings' ) );
			}

			$selection = absint( wp_unslash( $raw_selection ) );
			$valid     = \NowCRM\Cart\Automation::validate_selection( $selection );

			if ( is_wp_error( $valid ) ) {
				self::redirect( Menu::CART_SLUG, 'cart_automation_invalid', array( 'tab' => 'settings' ) );
			}

			$patch['cart_automation_id'] = $selection;
		}

		Settings::update( $patch );

		$page = Menu::SETTINGS_SLUG;
		$args = array();

		if ( 'cart' === $scope ) {
			$page = Menu::CART_SLUG;
			$args = array( 'tab' => 'settings' );
		} elseif ( 'email' === $scope ) {
			$args = array( 'tab' => 'email' );
		}

		self::redirect( $page, 'settings_saved', $args );
	}

	/**
	 * Run one worker tick from the admin UI (local/staging convenience).
	 */
	public static function run_queue(): void {
		self::guard( 'nowcrm_run_queue', Capabilities::MANAGE_SETTINGS );

		$return = array( 'tab' => EmailLogPage::TAB_DIAGNOSTICS );
		if ( ! Settings::bool( 'email_sending_enabled' ) ) {
			self::redirect( Menu::EMAIL_SLUG, 'queue_disabled', $return );
		}

		( new Queue() )->run();

		self::redirect( Menu::EMAIL_SLUG, 'queue_processed', $return );
	}

	/**
	 * Explicit operator retry of a failed/unknown/suppressed job.
	 */
	public static function retry_job(): void {
		$job_id = absint( $_REQUEST['job'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce is checked next.
		self::guard( 'nowcrm_retry_job_' . $job_id, Capabilities::MANAGE_CONTACTS );

		$return = array();
		if ( $job_id > 0 && '1' === self::post_raw( 'return_job' ) ) {
			$return['job'] = $job_id;
		}

		foreach ( array( 'status', 'kind', 'email', 'campaign_id', 'cart_id', 'delivery_status', 'paged' ) as $key ) {
			$value = self::post_text( 'return_' . $key );
			if ( '' !== $value && strlen( $value ) <= 254 ) {
				$return[ $key ] = rawurlencode( $value );
			}
		}

		if ( ! $job_id || ! ( new EmailJobRepository() )->retry( $job_id ) ) {
			self::redirect( Menu::EMAIL_SLUG, 'job_retry_failed', $return );
		}

		Queue::schedule( 1 );

		self::redirect( Menu::EMAIL_SLUG, 'job_retried', $return );
	}

	/**
	 * Re-run the idempotent install and report whether every table exists.
	 *
	 * Normal requests never inspect the tables, so this is the only way to
	 * repair a schema that drifted after the version option was written.
	 */
	public static function repair_schema(): void {
		self::guard( 'nowcrm_repair_schema', Capabilities::MANAGE_SETTINGS );

		Schema::install();

		self::redirect( Menu::TOOLS_SLUG, Schema::tables_exist() ? 'schema_ok' : 'schema_missing', array( 'tab' => 'diagnostics' ) );
	}

	/**
	 * Create a contact from the list-screen drawer.
	 *
	 * Every value is validated before the insert, so a rejected submission
	 * leaves no partial row. The created hook fires once, after lists, tags,
	 * and custom values are attached, so consumers see the finished contact.
	 */
	public static function create_contact(): void {
		self::guard( 'nowcrm_create_contact', Capabilities::MANAGE_CONTACTS );

		$repository = new ContactRepository();
		$post       = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce is checked by guard().
		$mode       = self::post_raw( 'create_mode', 'profile' );
		$term_ids   = self::contact_term_ids( $post );
		$custom     = null;

		$draft = array(
			'email'          => self::post_raw( 'email' ),
			'first_name'     => self::post_raw( 'first_name' ),
			'last_name'      => self::post_raw( 'last_name' ),
			'phone'          => self::post_raw( 'phone' ),
			'prefix'         => self::post_raw( 'prefix' ),
			'contact_type'   => self::post_raw( 'contact_type' ),
			'date_of_birth'  => self::post_raw( 'date_of_birth' ),
			'timezone'       => self::post_raw( 'timezone' ),
			'address_line_1' => self::post_raw( 'address_line_1' ),
			'address_line_2' => self::post_raw( 'address_line_2' ),
			'city'           => self::post_raw( 'city' ),
			'state'          => self::post_raw( 'state' ),
			'postal_code'    => self::post_raw( 'postal_code' ),
			'country'        => self::post_raw( 'country' ),
			'status'         => self::post_raw( 'status', Status::SUBSCRIBED ),
			'term_ids'       => $term_ids,
			'consent'        => '1' === self::post_raw( 'nowcrm_consent' ),
			'create_mode'    => $mode,
			'custom_data'    => isset( $post[ CustomFieldValues::FORM_MARKER ] ) && is_array( $post[ CustomFieldValues::FORM_MARKER ] )
				? CustomFieldValues::parse_request( $post )
				: array(),
		);

		$email = sanitize_email( (string) $draft['email'] );

		if ( '' === $email || ! is_email( $email ) ) {
			self::fail_contact_creation( 'invalid_email', $draft, 'email' );
		}

		if ( $repository->find_by_email( $email ) ) {
			self::fail_contact_creation( 'duplicate_email', $draft, 'email' );
		}

		$status = sanitize_key( (string) $draft['status'] );

		if ( array_key_exists( 'status', $_POST ) && '' === $status ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce is checked by guard().
			self::fail_contact_creation( 'invalid_status', $draft, 'status' );
		}

		$status = '' === $status ? Status::SUBSCRIBED : $status;

		if ( ! Status::is_valid( $status ) ) {
			self::fail_contact_creation( 'invalid_status', $draft, 'status' );
		}

		$profile = ProfileFields::normalize( $post );

		if ( is_wp_error( $profile ) ) {
			$code  = str_replace( 'nowcrm_invalid_', '', (string) $profile->get_error_code() );
			$focus = in_array( $code, array( 'prefix', 'contact_type', 'country', 'timezone', 'date_of_birth' ), true )
				? $code
				: 'email';

			self::fail_contact_creation(
				in_array( $code, array( 'prefix', 'contact_type', 'country', 'timezone', 'date_of_birth' ), true )
					? 'invalid_' . $code
					: 'error',
				$draft,
				$focus
			);
		}

		$terms_repository = new TermRepository();

		foreach ( $term_ids as $term_id ) {
			if ( ! $terms_repository->get( $term_id ) ) {
				self::fail_contact_creation( 'invalid_term', $draft, 'term_ids' );
			}
		}

		if ( array() !== $draft['custom_data'] ) {
			$prepared = CustomFieldValues::validate( $draft['custom_data'] );

			if ( is_wp_error( $prepared ) ) {
				$errors = (array) $prepared->get_error_data()['errors'];
				self::fail_contact_creation( 'invalid_custom_values', $draft, 'nowcrm_cf[' . (string) array_key_first( $errors ) . ']' );
			}

			$custom = $prepared;
		}

		$user    = get_user_by( 'email', $email );
		$created = $repository->create(
			array_merge(
				$profile,
				array(
					'email'       => $email,
					'first_name'  => sanitize_text_field( (string) $draft['first_name'] ),
					'last_name'   => sanitize_text_field( (string) $draft['last_name'] ),
					'phone'       => sanitize_text_field( (string) $draft['phone'] ),
					'wp_user_id'  => $user ? (int) $user->ID : null,
					'status'      => $status,
					'source'      => 'admin',
					'actor_id'    => get_current_user_id(),
					'defer_event' => true,
				)
			)
		);

		if ( is_wp_error( $created ) ) {
			$code = preg_replace( '/^nowcrm_/', '', (string) $created->get_error_code() );
			self::fail_contact_creation( is_string( $code ) ? $code : 'error', $draft, 'email' );
		}

		$contact_id = (int) $created;
		$warning    = '';

		$terms_repository->sync_for_contact( $contact_id, $term_ids );

		if ( is_array( $custom ) ) {
			$saved = ( new CustomFieldValues() )->commit( $contact_id, $custom );

			if ( is_wp_error( $saved ) ) {
				$warning = 'custom_failed';
			}
		}

		if ( Status::PENDING === $status && ! empty( $draft['consent'] ) ) {
			// Explicit operator choice only: the consent module queues its own
			// confirmation mail; creation itself never sends.
			$sent = ( new \NowCRM\Consent\DoubleOptin() )->send( $contact_id, self::list_ids( $terms_repository, $contact_id ) );

			if ( is_wp_error( $sent ) && '' === $warning ) {
				$warning = 'optin_failed';
			}
		}

		do_action(
			'nowcrm_contact_created',
			$contact_id,
			array(
				'source' => 'admin',
				'status' => $status,
			)
		);

		if ( '' !== $warning ) {
			self::redirect( Menu::CONTACT_SLUG, $warning, array( 'id' => $contact_id ) );
		}

		if ( 'another' === $mode ) {
			self::redirect( Menu::SLUG, 'created', array( 'nowcrm_drawer' => '1' ) );
		}

		self::redirect( Menu::CONTACT_SLUG, 'created', array( 'id' => $contact_id ) );
	}

	/**
	 * Validate submitted term IDs and return the unique positive set.
	 *
	 * @param array<string, mixed> $post Unslashed POST data.
	 * @return int[]
	 */
	private static function contact_term_ids( array $post ): array {
		$ids = $post['term_ids'] ?? array();

		if ( ! is_array( $ids ) ) {
			return array();
		}

		$clean = array();

		foreach ( $ids as $term_id ) {
			if ( ! is_scalar( $term_id ) ) {
				continue;
			}

			$term_id = absint( $term_id );

			if ( $term_id > 0 ) {
				$clean[ $term_id ] = $term_id;
			}
		}

		return array_values( $clean );
	}

	/**
	 * List IDs currently attached to a contact.
	 *
	 * @return int[]
	 */
	private static function list_ids( TermRepository $terms, int $contact_id ): array {
		$ids = array();

		foreach ( $terms->for_contact( $contact_id ) as $term ) {
			if ( TermRepository::TYPE_LIST === (string) $term['type'] ) {
				$ids[] = (int) $term['id'];
			}
		}

		return $ids;
	}

	/**
	 * Keep the rejected drawer submission for one round trip.
	 *
	 * @param array<string, mixed> $draft Drawer values.
	 */
	private static function fail_contact_creation( string $notice, array $draft, string $focus ): void {
		$draft['focus'] = $focus;

		set_transient(
			'nowcrm_contact_draft_' . get_current_user_id(),
			$draft,
			5 * MINUTE_IN_SECONDS
		);

		self::redirect( Menu::SLUG, $notice, array( 'nowcrm_drawer' => '1' ) );
	}

	/**
	 * Update profile fields and custom values from the detail form.
	 */
	public static function save_contact(): void {
		self::guard( 'nowcrm_save_contact', Capabilities::MANAGE_CONTACTS );

		$contact_id = absint( self::post_raw( 'contact_id' ) );

		if ( $contact_id <= 0 ) {
			self::redirect( Menu::SLUG, 'not_found' );
		}

		$result = self::persist_contact( $contact_id, wp_unslash( $_POST ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce is checked by guard().

		if ( is_wp_error( $result ) ) {
			self::redirect(
				Menu::CONTACT_SLUG,
				self::notice_from_error( $result ),
				array(
					'id'  => $contact_id,
					'tab' => ContactDetailPage::DEFAULT_TAB,
				)
			);
		}

		self::redirect(
			Menu::CONTACT_SLUG,
			'contact_saved',
			array(
				'id'  => $contact_id,
				'tab' => ContactDetailPage::DEFAULT_TAB,
			)
		);
	}

	/**
	 * Validate and persist one profile form submission.
	 *
	 * This path writes profile columns and custom values only. Status and term
	 * membership have their own single-purpose actions, so stale `status`
	 * or `term_ids` fields in an old form cannot change either one.
	 *
	 * Custom values validate before the profile write, so an invalid custom
	 * value cannot leave a half-updated contact. A submission without the
	 * custom-field markers comes from the older form and leaves stored custom
	 * values untouched.
	 *
	 * @param int                  $contact_id Contact ID.
	 * @param array<string, mixed> $post       Unslashed POST data.
	 * @return true|WP_Error
	 */
	public static function persist_contact( int $contact_id, array $post ) {
		$repository = new ContactRepository();
		$contact    = $contact_id > 0 ? $repository->get( $contact_id ) : null;

		if ( ! $contact ) {
			return new WP_Error( 'nowcrm_not_found', __( '找不到聯絡人。', 'now-crm' ) );
		}

		$custom      = null;
		$custom_repo = new CustomFieldValues();

		if ( isset( $post[ CustomFieldValues::FORM_MARKER ] ) ) {
			$custom = $custom_repo->prepare( $contact_id, CustomFieldValues::parse_request( $post ) );

			if ( is_wp_error( $custom ) ) {
				return $custom;
			}
		}

		$profile = array_intersect_key( $post, array_flip( ProfileFields::keys() ) );

		$updated = $repository->update(
			$contact_id,
			array_merge(
				array(
					'email'      => sanitize_email( self::post_value( $post, 'email' ) ),
					'first_name' => sanitize_text_field( self::post_value( $post, 'first_name' ) ),
					'last_name'  => sanitize_text_field( self::post_value( $post, 'last_name' ) ),
					'phone'      => sanitize_text_field( self::post_value( $post, 'phone' ) ),
				),
				$profile
			)
		);

		if ( is_wp_error( $updated ) ) {
			return $updated;
		}

		if ( is_array( $custom ) ) {
			$saved = $custom_repo->commit( $contact_id, $custom );

			if ( is_wp_error( $saved ) ) {
				return $saved;
			}
		}

		return true;
	}

	/**
	 * Unslashed scalar from a submitted array.
	 *
	 * @param array<string, mixed> $post    Unslashed POST data.
	 * @param string               $key     Field name.
	 * @param string               $default Fallback.
	 */
	private static function post_value( array $post, string $key, string $default = '' ): string {
		if ( ! isset( $post[ $key ] ) || ! is_scalar( $post[ $key ] ) ) {
			return $default;
		}

		return (string) $post[ $key ];
	}

	/**
	 * Change the status of one contact and append its audit event.
	 */
	public static function change_contact_status(): void {
		$contact_id = absint( self::post_raw( 'contact_id' ) );

		if ( $contact_id <= 0 ) {
			self::redirect( Menu::SLUG, 'not_found' );
		}

		self::guard( self::ACTION_STATUS . '_' . $contact_id, Capabilities::MANAGE_CONTACTS );

		$tab    = ContactDetailPage::normalize_tab( self::post_raw( 'tab' ) );
		$result = self::update_contact_status(
			$contact_id,
			sanitize_key( self::post_raw( 'status' ) ),
			sanitize_textarea_field( self::post_raw( 'note' ) )
		);

		if ( is_wp_error( $result ) ) {
			self::redirect(
				Menu::CONTACT_SLUG,
				self::notice_from_error( $result ),
				array(
					'id'  => $contact_id,
					'tab' => $tab,
				)
			);
		}

		self::redirect(
			Menu::CONTACT_SLUG,
			'status_saved',
			array(
				'id'  => $contact_id,
				'tab' => $tab,
			)
		);
	}

	/**
	 * Validate and apply one status change. No mail is queued or sent.
	 *
	 * @return true|WP_Error
	 */
	public static function update_contact_status( int $contact_id, string $status, string $note = '' ) {
		$status = sanitize_key( $status );

		if ( ! Status::is_valid( $status ) ) {
			return new WP_Error( 'nowcrm_invalid_status', __( '聯絡人狀態不正確。', 'now-crm' ) );
		}

		return ( new ContactRepository() )->set_status(
			$contact_id,
			$status,
			'admin',
			$note,
			get_current_user_id()
		);
	}

	/**
	 * Attach or detach one term without touching the rest of the membership.
	 */
	public static function change_contact_term(): void {
		$contact_id = absint( self::post_raw( 'contact_id' ) );

		if ( $contact_id <= 0 ) {
			self::redirect( Menu::SLUG, 'not_found' );
		}

		self::guard( self::ACTION_TERM . '_' . $contact_id, Capabilities::MANAGE_CONTACTS );

		$tab       = ContactDetailPage::normalize_tab( self::post_raw( 'tab' ) );
		$operation = sanitize_key( self::post_raw( 'operation' ) );
		$result    = self::update_contact_term(
			$contact_id,
			sanitize_key( self::post_raw( 'type' ) ),
			absint( self::post_raw( 'term_id' ) ),
			$operation
		);

		if ( is_wp_error( $result ) ) {
			self::redirect(
				Menu::CONTACT_SLUG,
				self::notice_from_error( $result ),
				array(
					'id'  => $contact_id,
					'tab' => $tab,
				)
			);
		}

		self::redirect(
			Menu::CONTACT_SLUG,
			'remove' === $operation ? 'membership_removed' : 'membership_added',
			array(
				'id'  => $contact_id,
				'tab' => $tab,
			)
		);
	}

	/**
	 * Apply a membership delta after validating the type, contact, and term.
	 *
	 * @return true|WP_Error
	 */
	public static function update_contact_term( int $contact_id, string $type, int $term_id, string $operation ) {
		if ( ! in_array( $operation, array( 'attach', 'remove' ), true ) ) {
			return new WP_Error( 'nowcrm_invalid_operation', __( '操作不正確。', 'now-crm' ) );
		}

		$type = sanitize_key( $type );

		if ( ! in_array( $type, TermRepository::types(), true ) ) {
			return new WP_Error( 'nowcrm_invalid_term', __( '名單／標籤類型不正確。', 'now-crm' ) );
		}

		if ( $contact_id <= 0 || ! ( new ContactRepository() )->get( $contact_id ) ) {
			return new WP_Error( 'nowcrm_not_found', __( '找不到聯絡人。', 'now-crm' ) );
		}

		$terms = new TermRepository();
		$term  = $terms->get( $term_id );

		if ( ! $term || (string) $term['type'] !== $type ) {
			return new WP_Error( 'nowcrm_invalid_term', __( '名單／標籤不存在或類型不符。', 'now-crm' ) );
		}

		if ( 'attach' === $operation ) {
			$terms->attach( $contact_id, $term_id );
		} else {
			$terms->detach( $contact_id, $term_id );
		}

		return true;
	}

	/**
	 * Delete a contact when it has no linked orders.
	 */
	public static function delete_contact(): void {
		self::guard( 'nowcrm_delete_contact', Capabilities::MANAGE_CONTACTS );

		$contact_id = absint( self::post_raw( 'contact_id' ) );
		$repository = new ContactRepository();

		if ( $contact_id <= 0 ) {
			self::redirect( Menu::SLUG, 'not_found' );
		}

		$deleted = $repository->delete( $contact_id );

		if ( is_wp_error( $deleted ) ) {
			self::redirect(
				Menu::CONTACT_SLUG,
				self::notice_from_error( $deleted ),
				array( 'id' => $contact_id )
			);
		}

		self::redirect( Menu::SLUG, 'deleted' );
	}

	/**
	 * Screen slug that owns a term type.
	 */
	private static function term_page( string $type ): string {
		return TermRepository::TYPE_TAG === $type ? Menu::TAGS_SLUG : Menu::LISTS_SLUG;
	}

	/**
	 * Create a list or tag.
	 */
	public static function create_term(): void {
		self::guard( 'nowcrm_create_term', Capabilities::MANAGE_CONTACTS );

		$type = sanitize_key( self::post_raw( 'type' ) );

		if ( ! in_array( $type, TermRepository::types(), true ) ) {
			self::redirect( Menu::LISTS_SLUG, 'invalid_term' );
		}

		$page    = self::term_page( $type );
		$created = ( new TermRepository() )->create(
			$type,
			self::post_text( 'name' ),
			array(
				'slug'        => self::post_text( 'slug' ),
				'description' => self::post_raw( 'description' ),
			)
		);

		if ( is_wp_error( $created ) ) {
			self::redirect( $page, self::notice_from_error( $created ) );
		}

		self::redirect( $page, 'created' );
	}

	/**
	 * Update the name, slug, or description of one list or tag.
	 */
	public static function update_term(): void {
		self::guard( 'nowcrm_update_term', Capabilities::MANAGE_CONTACTS );

		$type = sanitize_key( self::post_raw( 'type' ) );

		if ( ! in_array( $type, TermRepository::types(), true ) ) {
			self::redirect( Menu::LISTS_SLUG, 'invalid_term' );
		}

		$page    = self::term_page( $type );
		$term_id = absint( self::post_raw( 'term_id' ) );
		$terms   = new TermRepository();
		$term    = $term_id > 0 ? $terms->get( $term_id ) : null;

		if ( ! $term || (string) $term['type'] !== $type ) {
			self::redirect( $page, 'not_found' );
		}

		$data = array(
			'name'        => self::post_text( 'name' ),
			'description' => self::post_raw( 'description' ),
		);

		$slug = trim( self::post_text( 'slug' ) );

		// An empty slug field keeps the stored slug; it never clears it.
		if ( '' !== $slug ) {
			$data['slug'] = $slug;
		}

		$updated = $terms->update( $term_id, $data );

		if ( is_wp_error( $updated ) ) {
			self::redirect( $page, self::notice_from_error( $updated ) );
		}

		self::redirect( $page, 'saved' );
	}

	/**
	 * Delete a list or tag together with its contact relations.
	 */
	public static function delete_term(): void {
		self::guard( 'nowcrm_delete_term', Capabilities::MANAGE_CONTACTS );

		$type = sanitize_key( self::post_raw( 'type' ) );

		if ( ! in_array( $type, TermRepository::types(), true ) ) {
			self::redirect( Menu::LISTS_SLUG, 'invalid_term' );
		}

		$page    = self::term_page( $type );
		$term_id = absint( self::post_raw( 'term_id' ) );
		$terms   = new TermRepository();
		$term    = $term_id > 0 ? $terms->get( $term_id ) : null;

		if ( ! $term || (string) $term['type'] !== $type ) {
			self::redirect( $page, 'not_found' );
		}

		$terms->delete( $term_id );

		self::redirect( $page, 'deleted' );
	}

	/**
	 * Delete the selected lists or tags of one type.
	 */
	public static function delete_terms(): void {
		self::guard( 'nowcrm_delete_terms', Capabilities::MANAGE_CONTACTS );

		$type = sanitize_key( self::post_raw( 'type' ) );

		if ( ! in_array( $type, TermRepository::types(), true ) ) {
			self::redirect( Menu::LISTS_SLUG, 'invalid_term' );
		}

		$page = self::term_page( $type );
		$raw  = isset( $_POST['term_ids'] ) && is_array( $_POST['term_ids'] )
			? wp_unslash( $_POST['term_ids'] )
			: array();

		$ids = array();

		foreach ( $raw as $term_id ) {
			if ( ! is_scalar( $term_id ) ) {
				continue;
			}

			$term_id = absint( $term_id );

			if ( $term_id > 0 ) {
				$ids[] = $term_id;
			}
		}

		$ids = array_slice( array_values( array_unique( $ids ) ), 0, self::BULK_LIMIT );

		if ( array() === $ids ) {
			self::redirect( $page, 'invalid_term' );
		}

		$deleted = ( new TermRepository() )->delete_many( $type, $ids );

		self::redirect( $page, $deleted > 0 ? 'deleted' : 'not_found' );
	}

	/**
	 * Create lists or tags from an uploaded CSV file.
	 */
	public static function import_terms(): void {
		self::guard( 'nowcrm_import_terms', Capabilities::MANAGE_CONTACTS );

		$type = sanitize_key( self::post_raw( 'type' ) );

		if ( ! in_array( $type, TermRepository::types(), true ) ) {
			self::redirect( Menu::LISTS_SLUG, 'invalid_term' );
		}

		$page = self::term_page( $type );
		$file = isset( $_FILES['nowcrm_terms_csv'] ) && is_array( $_FILES['nowcrm_terms_csv'] )
			? $_FILES['nowcrm_terms_csv']
			: array();

		$error = (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE );
		$size  = (int) ( $file['size'] ?? 0 );
		$tmp   = (string) ( $file['tmp_name'] ?? '' );

		if ( UPLOAD_ERR_OK !== $error || $size > self::IMPORT_MAX_BYTES || ! is_uploaded_file( $tmp ) ) {
			self::redirect( $page, 'invalid_term' );
		}

		$rows = self::parse_terms_csv( $tmp );

		if ( array() === $rows ) {
			self::redirect( $page, 'import_skipped' );
		}

		$result  = ( new TermRepository() )->create_many( $type, $rows );
		$changed = $result['created'] + $result['updated'];

		self::redirect( $page, $changed > 0 ? 'imported' : 'import_skipped' );
	}

	/**
	 * Read a CSV upload into rows of name, slug, and description.
	 *
	 * The first row must name the columns; `name` and `title` are both accepted
	 * for the term name. Rows stop at the import limit.
	 *
	 * @return array<int, array{name: string, slug: string, description: string}>
	 */
	private static function parse_terms_csv( string $path ): array {
		if ( ! is_readable( $path ) ) {
			return array();
		}

		// phpcs:disable WordPress.WP.AlternativeFunctions -- the uploaded CSV is parsed row by row from a stream; WP_Filesystem cannot stream a handle.
		$handle = fopen( $path, 'r' );

		if ( false === $handle ) {
			return array();
		}

		$rows = array();
		$map  = null;

		while ( ( $cells = fgetcsv( $handle, null, ',', '"', '\\' ) ) !== false ) {
			if ( ! is_array( $cells ) || array( null ) === $cells ) {
				continue;
			}

			$cells = array_map( static fn( $cell ): string => trim( (string) $cell ), $cells );

			if ( array( '' ) === $cells ) {
				continue;
			}

			if ( null === $map ) {
				$header    = array_map( 'strtolower', $cells );
				$header[0] = (string) preg_replace( '/^\xEF\xBB\xBF/', '', $header[0] );

				$name_at = array_search( 'name', $header, true );

				if ( false === $name_at ) {
					$name_at = array_search( 'title', $header, true );
				}

				if ( false === $name_at ) {
					break;
				}

				$map = array(
					'name'        => $name_at,
					'slug'        => array_search( 'slug', $header, true ),
					'description' => array_search( 'description', $header, true ),
				);

				continue;
			}

			$name = $cells[ $map['name'] ] ?? '';

			if ( '' === $name ) {
				continue;
			}

			$rows[] = array(
				'name'        => $name,
				'slug'        => false !== $map['slug'] ? ( $cells[ $map['slug'] ] ?? '' ) : '',
				'description' => false !== $map['description'] ? ( $cells[ $map['description'] ] ?? '' ) : '',
			);

			if ( count( $rows ) >= self::IMPORT_LIMIT ) {
				break;
			}
		}

		fclose( $handle );
		// phpcs:enable

		return $rows;
	}

	/**
	 * Create a draft automation from the list page.
	 */
	public static function create_automation(): void {
		self::guard( 'nowcrm_create_automation', Capabilities::MANAGE_CONTACTS );

		$trigger = sanitize_key( self::post_raw( 'trigger_key' ) );

		if ( ! TriggerRegistry::is_registered( $trigger ) ) {
			self::redirect( Menu::AUTOMATIONS_SLUG, 'automation_invalid_trigger' );
		}

		$name   = self::post_text( 'name' );
		$labels = TriggerRegistry::triggers();

		if ( '' === $name ) {
			$name = (string) ( $labels[ $trigger ] ?? __( '自動化', 'now-crm' ) );
		}

		$id = ( new AutomationRepository() )->save(
			array(
				'name'         => $name,
				'status'       => AutomationRepository::STATUS_DRAFT,
				'trigger_key'  => $trigger,
				'created_by'   => get_current_user_id(),
				'entry_config' => array(
					'run_once'  => false,
					'force_run' => false,
				),
				'steps'        => array( array( 'kind' => 'end' ) ),
			)
		);

		if ( is_wp_error( $id ) ) {
			self::redirect( Menu::AUTOMATIONS_SLUG, self::notice_from_error( $id ) );
		}

		self::redirect( Menu::AUTOMATION_SLUG, 'automation_created', array( 'id' => (int) $id ) );
	}

	/**
	 * Save the editor form. Malformed step graphs are rejected before any write.
	 */
	public static function save_automation(): void {
		self::guard( 'nowcrm_save_automation', Capabilities::MANAGE_CONTACTS );

		$id = absint( self::post_raw( 'id' ) );
		$repository = new AutomationRepository();
		$existing   = $repository->get( $id );

		if ( ! $existing ) {
			self::fail_automation_save( __( '找不到自動化。', 'now-crm' ), 'not_found', Menu::AUTOMATIONS_SLUG, array() );
		}

		$trigger = sanitize_key( self::post_raw( 'trigger_key' ) );

		if ( ! TriggerRegistry::is_registered( $trigger ) ) {
			self::fail_automation_save( __( '觸發事件不正確。', 'now-crm' ), 'automation_invalid_trigger', Menu::AUTOMATION_SLUG, array( 'id' => $id ) );
		}

		$raw_steps = self::post_raw( 'steps_json' );

		if ( '' === $raw_steps || strlen( $raw_steps ) > 524288 ) {
			self::fail_automation_save( __( '步驟格式不正確，未儲存。請檢查步驟與條件。', 'now-crm' ), 'automation_invalid_steps', Menu::AUTOMATION_SLUG, array( 'id' => $id ) );
		}

		$steps = json_decode( $raw_steps, true );

		if ( ! is_array( $steps ) ) {
			self::fail_automation_save( __( '步驟格式不正確，未儲存。請檢查步驟與條件。', 'now-crm' ), 'automation_invalid_steps', Menu::AUTOMATION_SLUG, array( 'id' => $id ) );
		}

		// An ordinary save must not write the status. Pausing and draft
		// changes use the status action. Only the explicit enable-with-save
		// button activates, so a stale form cannot restart a paused flow.
		$status = 'active' === sanitize_key( self::post_raw( 'save_status' ) )
			? AutomationRepository::STATUS_ACTIVE
			: null;

		$trigger_config = self::automation_trigger_config( $trigger );

		if ( (string) $existing['trigger_key'] === $trigger && ! empty( $existing['trigger_config']['conditions'] ) ) {
			$trigger_config['conditions'] = $existing['trigger_config']['conditions'];
		}

		$payload = array(
			'name'           => self::post_text( 'name' ),
			'trigger_key'    => $trigger,
			'trigger_config' => $trigger_config,
			'entry_config'   => array(
				'run_once'  => ! empty( $_POST['run_once'] ),
				'force_run' => ! empty( $_POST['force_run'] ),
			),
			'steps'          => $steps,
		);

		if ( null !== $status ) {
			$payload['status'] = $status;
		}

		$saved = $repository->save( $payload, $id );

		if ( is_wp_error( $saved ) ) {
			self::fail_automation_save( $saved->get_error_message(), self::notice_from_error( $saved ), Menu::AUTOMATION_SLUG, array( 'id' => $id ) );
		}

		if ( self::wants_json() ) {
			wp_send_json_success(
				array(
					'redirect' => Menu::page_url(
						Menu::AUTOMATION_SLUG,
						array(
							'id'            => $id,
							'nowcrm_notice' => 'automation_saved',
						)
					),
				)
			);
		}

		self::redirect( Menu::AUTOMATION_SLUG, 'automation_saved', array( 'id' => $id ) );
	}

	/**
	 * Whether the current save request expects a JSON response.
	 */
	private static function wants_json(): bool {
		return '1' === self::post_raw( 'nowcrm_json' );
	}

	/**
	 * Reject one automation save.
	 *
	 * A live save (fetch) gets the real error message and the editor keeps its
	 * draft. A regular form post keeps the existing redirect and notice.
	 *
	 * @param string               $message Error text.
	 * @param string               $notice  Allow-listed notice code.
	 * @param string               $page    Menu slug for the redirect.
	 * @param array<string, mixed> $args    Extra query arguments.
	 */
	private static function fail_automation_save( string $message, string $notice, string $page, array $args ): void {
		if ( self::wants_json() ) {
			wp_send_json_error( array( 'message' => $message ), 400 );
		}

		self::redirect( $page, $notice, $args );
	}

	/**
	 * Keep the automation list filters of the current row form.
	 *
	 * The list page uses these names. A search string must not be empty, a
	 * status must be known ("all" is not), and a page must be above one.
	 *
	 * @return array<string, mixed>
	 */
	private static function automation_list_filters(): array {
		$args = array();

		$search = self::post_text( 'filter_search' );

		if ( '' !== $search ) {
			$args['filter_search'] = $search;
		}

		$status = sanitize_key( self::post_raw( 'filter_status' ) );

		if ( in_array( $status, array( AutomationRepository::STATUS_DRAFT, AutomationRepository::STATUS_ACTIVE, AutomationRepository::STATUS_PAUSED ), true ) ) {
			$args['filter_status'] = $status;
		}

		$paged = absint( self::post_raw( 'filter_paged' ) );

		if ( $paged > 1 ) {
			$args['filter_paged'] = $paged;
		}

		return $args;
	}

	/**
	 * Activate, pause, or return one automation to draft.
	 */
	public static function set_automation_status(): void {
		self::guard( 'nowcrm_set_automation_status', Capabilities::MANAGE_CONTACTS );

		$id     = absint( self::post_raw( 'id' ) );
		$status = sanitize_key( self::post_raw( 'status' ) );
		$return = sanitize_key( self::post_raw( 'return' ) );

		$result = ( new AutomationRepository() )->set_status( $id, $status );

		if ( is_wp_error( $result ) ) {
			$notice = self::notice_from_error( $result );
			$args   = 'editor' === $return ? array( 'id' => $id ) : self::automation_list_filters();
			$page   = 'editor' === $return ? Menu::AUTOMATION_SLUG : Menu::AUTOMATIONS_SLUG;

			self::redirect( $page, $notice, $args );
		}

		$args = 'editor' === $return ? array( 'id' => $id ) : self::automation_list_filters();
		$page = 'editor' === $return ? Menu::AUTOMATION_SLUG : Menu::AUTOMATIONS_SLUG;

		self::redirect( $page, 'automation_status_saved', $args );
	}

	/**
	 * Copy one automation and its steps into a new draft.
	 */
	public static function duplicate_automation(): void {
		self::guard( 'nowcrm_duplicate_automation', Capabilities::MANAGE_CONTACTS );

		$id         = absint( self::post_raw( 'id' ) );
		$repository = new AutomationRepository();
		$automation = $repository->get( $id );

		if ( ! $automation ) {
			self::redirect( Menu::AUTOMATIONS_SLUG, 'not_found', self::automation_list_filters() );
		}

		$copy = $repository->save(
			array(
				'name'           => mb_substr( (string) $automation['name'] . ' (copy)', 0, 191 ),
				'status'         => AutomationRepository::STATUS_DRAFT,
				'trigger_key'    => (string) $automation['trigger_key'],
				'trigger_config' => (array) $automation['trigger_config'],
				'entry_config'   => (array) $automation['entry_config'],
				'steps'          => $repository->nested_steps( $id ),
			),
			0,
			$repository->referenced_sequence_ids( $id )
		);

		if ( is_wp_error( $copy ) ) {
			self::redirect(
				Menu::AUTOMATIONS_SLUG,
				self::notice_from_error( $copy ),
				array_merge( array( 'id' => $id ), self::automation_list_filters() )
			);
		}

		self::redirect( Menu::AUTOMATION_SLUG, 'automation_duplicated', array( 'id' => (int) $copy ) );
	}

	/**
	 * Delete one automation and all of its runs.
	 */
	public static function delete_automation(): void {
		self::guard( 'nowcrm_delete_automation', Capabilities::MANAGE_CONTACTS );

		$id = absint( self::post_raw( 'id' ) );

		if ( ! ( new AutomationRepository() )->delete( $id ) ) {
			self::redirect( Menu::AUTOMATIONS_SLUG, 'not_found', self::automation_list_filters() );
		}

		self::redirect( Menu::AUTOMATIONS_SLUG, 'deleted', self::automation_list_filters() );
	}

	/**
	 * Create a draft sequence with one placeholder step.
	 */
	public static function create_sequence(): void {
		self::guard( 'nowcrm_create_sequence', Capabilities::MANAGE_CONTACTS );

		$name = self::post_text( 'name' );

		if ( '' === $name ) {
			$name = __( '未命名序列', 'now-crm' );
		}

		$id = ( new SequenceRepository() )->create(
			array(
				'name'     => $name,
				'status'   => SequenceRepository::STATUS_DRAFT,
				'audience' => array( 'type' => 'all' ),
				'steps'    => array(
					array(
						'name'          => __( '步驟 1', 'now-crm' ),
						'subject'       => __( '新步驟 1', 'now-crm' ),
						'preheader'     => '',
						'mode'          => 'blocks',
						'content'       => '<!-- wp:paragraph --><p>' . esc_html__( '請編輯這一步的內容。', 'now-crm' ) . '</p><!-- /wp:paragraph -->',
						'delay_seconds' => 0,
					),
				),
			)
		);

		if ( is_wp_error( $id ) ) {
			self::redirect( Menu::SEQUENCES_SLUG, self::notice_from_error( $id ) );
		}

		self::redirect( Menu::SEQUENCE_SLUG, 'sequence_created', array( 'id' => (int) $id ) );
	}

	/**
	 * Save the sequence editor. Steps and audience are validated again.
	 */
	public static function save_sequence(): void {
		self::guard( 'nowcrm_save_sequence', Capabilities::MANAGE_CONTACTS );

		$id       = absint( self::post_raw( 'id' ) );
		$existing = ( new SequenceRepository() )->get( $id );

		if ( ! $existing ) {
			self::redirect( Menu::SEQUENCES_SLUG, 'not_found' );
		}

		$status   = sanitize_key( self::post_raw( 'status' ) );
		$audience = self::sequence_audience_from_request( sanitize_key( self::post_raw( 'audience_type' ) ) );

		if ( is_wp_error( $audience ) ) {
			self::redirect( Menu::SEQUENCE_SLUG, 'sequence_audience', array( 'id' => $id ) );
		}

		$steps = isset( $_POST['steps'] ) && is_array( $_POST['steps'] )
			? wp_unslash( $_POST['steps'] )
			: array();

		$saved = ( new SequenceRepository() )->update(
			$id,
			array(
				'name'          => self::post_raw( 'name' ),
				'status'        => $status,
				'audience'      => $audience,
				'allow_reentry' => ! empty( $_POST['allow_reentry'] ),
				'steps'         => $steps,
			)
		);

		if ( is_wp_error( $saved ) ) {
			self::redirect( Menu::SEQUENCE_SLUG, self::notice_from_error( $saved ), array( 'id' => $id ) );
		}

		self::redirect( Menu::SEQUENCE_SLUG, 'sequence_saved', array( 'id' => $id ) );
	}

	/**
	 * Activate, pause, or return one sequence to draft.
	 */
	public static function set_sequence_status(): void {
		self::guard( 'nowcrm_set_sequence_status', Capabilities::MANAGE_CONTACTS );

		$id     = absint( self::post_raw( 'id' ) );
		$status = sanitize_key( self::post_raw( 'status' ) );
		$return = sanitize_key( self::post_raw( 'return' ) );
		$result = ( new SequenceRepository() )->set_status( $id, $status );
		$page   = 'editor' === $return ? Menu::SEQUENCE_SLUG : Menu::SEQUENCES_SLUG;
		$args   = 'editor' === $return ? array( 'id' => $id ) : array();

		if ( is_wp_error( $result ) ) {
			self::redirect( $page, self::notice_from_error( $result ), $args );
		}

		self::redirect( $page, 'sequence_status_saved', $args );
	}

	/**
	 * Copy one sequence definition into a new draft.
	 */
	public static function duplicate_sequence(): void {
		self::guard( 'nowcrm_duplicate_sequence', Capabilities::MANAGE_CONTACTS );

		$id   = absint( self::post_raw( 'id' ) );
		$copy = ( new SequenceRepository() )->duplicate( $id );

		if ( is_wp_error( $copy ) ) {
			self::redirect( Menu::SEQUENCES_SLUG, self::notice_from_error( $copy ), array( 'id' => $id ) );
		}

		self::redirect( Menu::SEQUENCE_SLUG, 'sequence_duplicated', array( 'id' => (int) $copy ) );
	}

	/**
	 * Delete one sequence and cancel its active enrollments first.
	 */
	public static function delete_sequence(): void {
		self::guard( 'nowcrm_delete_sequence', Capabilities::MANAGE_CONTACTS );

		$id     = absint( self::post_raw( 'id' ) );
		$result = ( new SequenceRepository() )->delete( $id, true );

		if ( is_wp_error( $result ) ) {
			self::redirect( Menu::SEQUENCES_SLUG, self::notice_from_error( $result ), array( 'id' => $id ) );
		}

		self::redirect( Menu::SEQUENCES_SLUG, 'sequence_deleted' );
	}

	/**
	 * Enroll one bounded audience batch and return the counts.
	 */
	public static function enroll_sequence(): void {
		self::guard( 'nowcrm_enroll_sequence', Capabilities::MANAGE_CONTACTS );

		$id     = absint( self::post_raw( 'id' ) );
		$after  = absint( self::post_raw( 'after_id' ) );
		$result = ( new SequenceEngine() )->enroll( $id, array(), $after );

		if ( is_wp_error( $result ) ) {
			self::redirect( Menu::SEQUENCES_SLUG, self::notice_from_error( $result ), array( 'id' => $id ) );
		}

		$args = array(
			'id'             => $id,
			'enrolled'       => (int) $result['enrolled'],
			'not_subscribed' => (int) $result['skipped_not_subscribed'],
			'suppressed'     => (int) $result['skipped_suppressed'],
			'missing'        => (int) $result['skipped_missing'],
			'duplicates'     => (int) $result['duplicates'],
			'queued'         => (int) $result['queued'],
			'failed'         => (int) $result['failed'],
		);

		if ( ! empty( $result['next_after_id'] ) ) {
			$args['next_after_id'] = (int) $result['next_after_id'];
		}

		self::redirect( Menu::SEQUENCES_SLUG, 'sequence_enrolled', $args );
	}

	/**
	 * Cancel one contact's active enrollment in a sequence.
	 */
	public static function cancel_sequence_enrollment(): void {
		self::guard( 'nowcrm_cancel_sequence_enrollment', Capabilities::MANAGE_CONTACTS );

		$id         = absint( self::post_raw( 'id' ) );
		$contact_id = absint( self::post_raw( 'contact_id' ) );
		$return     = sanitize_key( self::post_raw( 'return' ) );

		if ( ! ( new SequenceRepository() )->get( $id ) ) {
			self::redirect( Menu::SEQUENCES_SLUG, 'not_found' );
		}

		( new SequenceEngine() )->cancel( $id, $contact_id );

		$page = 'runs' === $return ? Menu::SEQUENCE_RUNS_SLUG : Menu::SEQUENCE_SLUG;

		self::redirect( $page, 'sequence_enrollment_cancelled', array( 'id' => $id ) );
	}

	/**
	 * Audience from editor controls.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	private static function sequence_audience_from_request( string $type ) {
		if ( 'all' === $type ) {
			return array( 'type' => 'all' );
		}

		if ( 'list' === $type || 'tag' === $type ) {
			return array(
				'type'    => $type,
				'term_id' => absint( self::post_raw( 'list' === $type ? 'list_term_id' : 'tag_term_id' ) ),
			);
		}

		if ( 'segment' === $type ) {
			$raw = self::post_raw( 'segment_id' );
			$ref = preg_match( '/^\d+$/', $raw ) ? (int) $raw : sanitize_text_field( $raw );

			return array(
				'type'       => 'segment',
				'segment_id' => $ref,
			);
		}

		if ( 'filters' === $type ) {
			$plan = ContactAdvancedFilters::parse_request( $_POST );

			if ( is_wp_error( $plan ) ) {
				return new WP_Error( 'nowcrm_sequence_audience', $plan->get_error_message() );
			}

			if ( null === $plan ) {
				return new WP_Error( 'nowcrm_sequence_audience', __( '請至少加入一個篩選條件。', 'now-crm' ) );
			}

			return array(
				'type'    => 'filters',
				'filters' => $plan,
			);
		}

		return new WP_Error( 'nowcrm_sequence_audience', __( '序列受眾類型不正確。', 'now-crm' ) );
	}

	/**
	 * Trigger filters from the editor form.
	 *
	 *
	 * @return array<string, mixed>
	 */
	private static function automation_trigger_config( string $trigger ): array {
		$config = array();

		$status_trigger = in_array( $trigger, array( TriggerRegistry::CONTACT_STATUS, TriggerRegistry::CONTACT_CREATED ), true );
		$term_trigger   = in_array( $trigger, array( TriggerRegistry::TERM_ATTACHED, TriggerRegistry::TERM_DETACHED ), true );
		$source_trigger = in_array(
			$trigger,
			array( TriggerRegistry::CONTACT_CREATED, TriggerRegistry::CONTACT_UPDATED, TriggerRegistry::CSV_IMPORTED, TriggerRegistry::CONTACT_STATUS ),
			true
		);

		$statuses = $status_trigger && isset( $_POST['trigger_statuses'] ) && is_array( $_POST['trigger_statuses'] )
			? array_map(
				static fn( $value ) => is_scalar( $value ) ? sanitize_key( (string) $value ) : '',
				(array) wp_unslash( $_POST['trigger_statuses'] )
			)
			: array();

		$statuses = array_values( array_filter( $statuses ) );

		if ( array() !== $statuses ) {
			$config['statuses'] = $statuses;
		}

		$term_ids = $term_trigger && isset( $_POST['trigger_term_ids'] ) && is_array( $_POST['trigger_term_ids'] )
			? array_map(
				static fn( $value ) => is_scalar( $value ) ? absint( $value ) : 0,
				(array) wp_unslash( $_POST['trigger_term_ids'] )
			)
			: array();

		$term_ids = array_values( array_filter( $term_ids ) );

		if ( array() !== $term_ids ) {
			$config['term_ids'] = $term_ids;
		}

		$term_types = $term_trigger && isset( $_POST['trigger_term_types'] ) && is_array( $_POST['trigger_term_types'] )
			? array_map(
				static fn( $value ) => is_scalar( $value ) ? sanitize_key( (string) $value ) : '',
				(array) wp_unslash( $_POST['trigger_term_types'] )
			)
			: array();

		$term_types = array_values( array_filter( $term_types ) );

		if ( array() !== $term_types ) {
			$config['term_types'] = $term_types;
		}

		$sources = $source_trigger ? self::post_raw( 'trigger_sources' ) : '';

		if ( '' !== trim( $sources ) ) {
			$config['sources'] = array_values(
				array_filter(
					array_map( 'sanitize_text_field', array_map( 'trim', explode( ',', $sources ) ) )
				)
			);
		}

		return $config;
	}

	/**
	 * Capability + nonce gate shared by every handler.
	 *
	 * @param string $action     Nonce action.
	 * @param string $capability Capability required for the mutation.
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
	 * Raw unslashed string from the current POST request (arrays ignored).
	 */
	private static function post_raw( string $key, string $default = '' ): string {
		if ( ! isset( $_POST[ $key ] ) || ! is_string( $_POST[ $key ] ) ) {
			return $default;
		}

		return wp_unslash( $_POST[ $key ] );
	}

	/**
	 * Sanitized single-line text field from the current POST request.
	 */
	private static function post_text( string $key ): string {
		return sanitize_text_field( self::post_raw( $key ) );
	}

	/**
	 * Redirect back to one of this plugin's screens with a notice code.
	 *
	 * @param string               $page   Menu slug.
	 * @param string               $notice Allow-listed notice code.
	 * @param array<string, mixed> $args   Extra query arguments.
	 */
	private static function redirect( string $page, string $notice, array $args = array() ): void {
		wp_safe_redirect(
			Menu::page_url(
				$page,
				array_merge( array( 'nowcrm_notice' => $notice ), $args )
			)
		);

		exit;
	}
}
