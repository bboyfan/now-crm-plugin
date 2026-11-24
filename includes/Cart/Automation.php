<?php
/**
 * Single abandoned-cart automation owner.
 *
 * Cart abandonment has exactly one producer: this coordinator. It selects the
 * configured cart-recovery automation (managed by default), creates one run per
 * immutable cart source (cart ID plus cart version), and fences legacy
 * `kind=cart` jobs so the same cart is never mailed twice.
 *
 * Public admin/read API:
 * - eligible_options(): selectable active cart automations.
 * - resolve_owner(): the effective owner plus a machine reason when unusable.
 * - validate_selection()/save_selection(): validated `cart_automation_id`.
 * - status(): readiness for the settings screen.
 * - run_for_cart()/jobs_for_cart(): auditable run and job links.
 *
 * @package NowCRM
 */

namespace NowCRM\Cart;

use NowCRM\Automation\AutomationRepository;
use NowCRM\Automation\Engine;
use NowCRM\Automation\RunRepository;
use NowCRM\Automation\TriggerRegistry;
use NowCRM\Contacts\ContactRepository;
use NowCRM\Contacts\Status;
use NowCRM\Email\EmailJobRepository;
use NowCRM\Email\EmailTemplateRepository;
use NowCRM\Support\Schema;
use NowCRM\Support\Settings;
use WP_Error;

defined( 'ABSPATH' ) || exit;

final class Automation {

	/**
	 * Setting key: 0 selects the managed workflow, a nonzero value selects one
	 * exact active cart automation.
	 */
	public const SETTING = 'cart_automation_id';

	/**
	 * Internal pointer to the managed workflow. Never edited by hand.
	 */
	public const MANAGED_OPTION = 'nowcrm_cart_managed_automation_id';

	/**
	 * One-time marker: the legacy cart template was folded into the managed
	 * automation. Keeping the flag prevents later template edits from
	 * overwriting the automation operator edits.
	 */
	public const MIGRATED_OPTION = 'nowcrm_cart_template_migrated';

	public const MODE_MANAGED = 'managed';
	public const MODE_CUSTOM  = 'custom';

	/**
	 * Ineligible reasons. These are persisted in cart events and job errors.
	 */
	public const REASON_OWNER_UNAVAILABLE = 'owner_unavailable';
	public const REASON_LEGACY_OWNER      = 'legacy_owner';
	public const REASON_ONE_OUTSTANDING   = 'one_outstanding';
	public const REASON_NOT_SUBSCRIBED    = 'not_subscribed';
	public const REASON_NO_EMAIL          = 'no_email';
	public const REASON_CART_DISABLED     = 'cart_disabled';
	public const REASON_CART_MISSING      = 'cart_missing';
	public const REASON_CART_NOT_ABANDONED = 'cart_not_abandoned';
	public const REASON_CART_VERSION_CHANGED = 'cart_version_changed';
	public const REASON_RUN_MISSING       = 'run_missing';
	public const REASON_RUN_NOT_ACTIVE    = 'run_not_active';
	public const REASON_AUTOMATION_INACTIVE = 'automation_inactive';
	public const REASON_RECIPIENT_MISMATCH = 'recipient_mismatch';
	public const REASON_MAX_REMINDERS     = 'max_reminders';
	public const REASON_COOLDOWN          = 'cooldown';
	public const REASON_SUPPRESSED        = 'suppressed_address';
	public const REASON_ORDER_EXISTS      = 'order_exists';
	public const REASON_TEMPLATE_MISSING  = 'template_missing';
	public const REASON_RECOVERY_MISSING  = 'recovery_token_missing';
	public const REASON_RECOVERY_STALE    = 'recovery_token_stale';
	public const REASON_RECOVERY_EXPIRED  = 'recovery_token_expired';

	/**
	 * Register the one-time legacy-template migration.
	 */
	public function register(): void {
		add_action( 'init', array( __CLASS__, 'migrate_legacy_template' ), 30 );
	}

	/**
	 * Fold the old cart-template editor into the managed automation once.
	 *
	 * The old template was the only content source for the managed flow. Copy
	 * its exact markup into the managed Email step before the send path starts
	 * reading the step, then leave the automation as the only editable source.
	 */
	public static function migrate_legacy_template(): void {
		if ( get_option( self::MIGRATED_OPTION ) ) {
			return;
		}

		$managed = (int) get_option( self::MANAGED_OPTION, 0 );

		if ( $managed <= 0 ) {
			update_option( self::MIGRATED_OPTION, 1, false );
			return;
		}

		$repo       = new AutomationRepository();
		$automation = $repo->get( $managed );

		if ( ! $automation || TriggerRegistry::CART_ABANDONED !== (string) $automation['trigger_key'] ) {
			update_option( self::MIGRATED_OPTION, 1, false );
			return;
		}

		$steps = $repo->nested_steps( $managed );
		$index = null;

		foreach ( $steps as $step_index => $step ) {
			if ( 'email' === (string) ( $step['kind'] ?? '' ) ) {
				$index = $step_index;
				break;
			}
		}

		$legacy = self::legacy_template_config();

		if ( null === $index || null === $legacy ) {
			update_option( self::MIGRATED_OPTION, 1, false );
			return;
		}

		$config          = (array) ( $steps[ $index ]['config'] ?? array() );
		$default_subject = __( '{{first_name}}，您的購物車還留著', 'now-crm' );
		$content         = trim( (string) ( $config['content'] ?? '' ) );
		$subject         = trim( (string) ( $config['subject'] ?? '' ) );

		// Never overwrite an operator-edited body. A default or empty body is
		// the managed scaffold that the old template used to replace.
		if ( '' !== $content && trim( Reminder::default_content() ) !== $content ) {
			update_option( self::MIGRATED_OPTION, 1, false );
			return;
		}

		$config['mode']    = $legacy['mode'];
		$config['content'] = $legacy['content'];
		$config['preheader'] = $legacy['preheader'];

		// Respect an intentional custom subject; only replace the scaffold.
		if ( '' === $subject || $default_subject === $subject ) {
			$config['subject'] = $legacy['subject'];
		}

		$steps[ $index ]['config'] = $config;

		$saved = $repo->save(
			array(
				'name'           => (string) $automation['name'],
				'status'         => (string) $automation['status'],
				'trigger_key'    => (string) $automation['trigger_key'],
				'trigger_config' => (array) ( $automation['trigger_config'] ?? array() ),
				'entry_config'   => (array) ( $automation['entry_config'] ?? array() ),
				'steps'          => $steps,
			),
			$managed
		);

		if ( ! is_wp_error( $saved ) ) {
			update_option( self::MIGRATED_OPTION, 1, false );
		}
	}

	/**
	 * Exact old cart template, if one is configured and still a cart template.
	 *
	 * @return array{subject: string, preheader: string, mode: string, content: string}|null
	 */
	private static function legacy_template_config(): ?array {
		$id = Settings::int( 'cart_reminder_template_id' );

		if ( $id <= 0 ) {
			return null;
		}

		$template = ( new EmailTemplateRepository() )->get( $id );

		if ( ! $template || 'cart' !== (string) ( $template['kind'] ?? '' ) ) {
			return null;
		}

		$content = (string) ( $template['content'] ?? '' );

		if ( '' === trim( $content ) ) {
			return null;
		}

		$mode = (string) ( $template['mode'] ?? 'html' );

		return array(
			'subject'   => (string) ( $template['subject'] ?? '' ),
			'preheader' => (string) ( $template['preheader'] ?? '' ),
			'mode'      => in_array( $mode, array( 'html', 'blocks' ), true ) ? $mode : 'html',
			'content'   => $content,
		);
	}

	/**
	 * Active cart automations an administrator may select.
	 *
	 * @return array<int, array{id: int, name: string, managed: bool}>
	 */
	public static function eligible_options(): array {
		$options = array();

		foreach ( ( new AutomationRepository() )->active_by_trigger( TriggerRegistry::CART_ABANDONED ) as $automation ) {
			$id = (int) $automation['id'];

			if ( ! self::has_email_step( $id ) ) {
				continue;
			}

			$options[] = array(
				'id'      => $id,
				'name'    => (string) $automation['name'],
				'managed' => self::is_managed( $id ),
			);
		}

		return $options;
	}

	/**
	 * The effective owner of cart recovery.
	 *
	 * An explicit broken selection never falls back to the managed workflow:
	 * the reason is surfaced so the administrator can fix the configuration.
	 *
	 * @return array{automation_id: int, mode: string, managed: bool, valid: bool, reason: string}
	 */
	public static function resolve_owner(): array {
		$selected = max( 0, Settings::int( self::SETTING ) );

		if ( $selected > 0 ) {
			$valid = self::validate_selection( $selected );

			return array(
				'automation_id' => $selected,
				'mode'          => self::MODE_CUSTOM,
				'managed'       => false,
				'valid'         => true === $valid,
				'reason'        => true === $valid ? '' : self::REASON_OWNER_UNAVAILABLE,
			);
		}

		$managed = self::ensure_managed();

		if ( is_wp_error( $managed ) ) {
			return array(
				'automation_id' => 0,
				'mode'          => self::MODE_MANAGED,
				'managed'       => true,
				'valid'         => false,
				'reason'        => (string) $managed->get_error_code(),
			);
		}

		$valid = self::validate_selection( (int) $managed );

		return array(
			'automation_id' => (int) $managed,
			'mode'          => self::MODE_MANAGED,
			'managed'       => true,
			'valid'         => true === $valid,
			'reason'        => true === $valid ? '' : self::REASON_OWNER_UNAVAILABLE,
		);
	}

	/**
	 * Validate one explicit selection.
	 *
	 * @return true|WP_Error
	 */
	public static function validate_selection( int $automation_id ) {
		if ( $automation_id <= 0 ) {
			return true;
		}

		$automation = ( new AutomationRepository() )->get( $automation_id );

		if ( ! $automation || TriggerRegistry::CART_ABANDONED !== (string) $automation['trigger_key'] ) {
			return new WP_Error( self::REASON_OWNER_UNAVAILABLE, __( '所選流程不是購物車放棄流程。', 'now-crm' ) );
		}

		if ( AutomationRepository::STATUS_ACTIVE !== (string) $automation['status'] ) {
			return new WP_Error( self::REASON_OWNER_UNAVAILABLE, __( '所選流程必須是啟用狀態。', 'now-crm' ) );
		}

		if ( ! self::has_email_step( $automation_id ) ) {
			return new WP_Error( self::REASON_OWNER_UNAVAILABLE, __( '所選流程需要至少一個 Email 步驟。', 'now-crm' ) );
		}

		return true;
	}

	/**
	 * Validate and store a selection. Zero restores the managed default.
	 *
	 * @return true|WP_Error
	 */
	public static function save_selection( int $automation_id ) {
		$valid = self::validate_selection( $automation_id );

		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		Settings::update( array( self::SETTING => max( 0, $automation_id ) ) );

		return true;
	}

	/**
	 * Readiness for the settings screen.
	 *
	 * @return array<string, mixed>
	 */
	public static function status(): array {
		$owner   = self::resolve_owner();
		$managed = (int) get_option( self::MANAGED_OPTION, 0 );

		return array(
			'setting'        => self::SETTING,
			'managed_option' => self::MANAGED_OPTION,
			'managed_id'     => $managed,
			'managed'        => $managed > 0 ? ( new AutomationRepository() )->get( $managed ) : null,
			'owner'          => $owner,
			'options'        => self::eligible_options(),
			'ready'          => ! empty( $owner['valid'] ) && Settings::bool( 'abandoned_cart_enabled' ),
			'reason'         => Settings::bool( 'abandoned_cart_enabled' ) ? $owner['reason'] : self::REASON_CART_DISABLED,
		);
	}

	/**
	 * Whether an automation is the managed cart workflow.
	 */
	public static function is_managed( int $automation_id ): bool {
		return $automation_id > 0 && $automation_id === (int) get_option( self::MANAGED_OPTION, 0 );
	}

	/**
	 * Create the managed workflow when it is missing.
	 *
	 * The managed flow is one frozen Email step and no Wait: the only delay is
	 * the cart's own reminder due time, applied once by the queue. Editing it
	 * in the automation editor keeps the pointer, so a paused or edited flow is
	 * never silently replaced.
	 *
	 * @return int|WP_Error
	 */
	public static function ensure_managed() {
		$existing = (int) get_option( self::MANAGED_OPTION, 0 );

		if ( $existing > 0 ) {
			$row = ( new AutomationRepository() )->get( $existing );

			if ( $row && TriggerRegistry::CART_ABANDONED === (string) $row['trigger_key'] ) {
				return $existing;
			}
		}

		$legacy = self::legacy_template_config();
		$config = $legacy ?? array(
			'subject'   => __( '{{first_name}}，您的購物車還留著', 'now-crm' ),
			'preheader' => '',
			'mode'      => 'html',
			'content'   => Reminder::default_content(),
		);

		$id = ( new AutomationRepository() )->save(
			array(
				'name'         => __( '購物車挽回（系統託管）', 'now-crm' ),
				'status'       => AutomationRepository::STATUS_ACTIVE,
				'trigger_key'  => TriggerRegistry::CART_ABANDONED,
				'entry_config' => array( 'run_once' => false, 'force_run' => false ),
				'steps'        => array(
					array(
						'kind'   => 'email',
						'config' => $config,
					),
					array( 'kind' => 'end' ),
				),
			)
		);

		if ( is_wp_error( $id ) ) {
			return $id;
		}

		update_option( self::MANAGED_OPTION, (int) $id, false );

		return (int) $id;
	}

	/**
	 * Start the one cart-recovery run for this cart source.
	 *
	 * The callback passed to the cart lock returns this same result, so a
	 * concurrent sweep or a replayed hook can only observe the existing run.
	 *
	 * @param int    $cart_id    Cart ID.
	 * @param string $source_ref Event reference.
	 * @return array{started: bool, reason: string, run: array<string, mixed>}|WP_Error
	 */
	public static function start_for_cart( int $cart_id, string $source_ref = '' ) {
		if ( $cart_id <= 0 ) {
			return new WP_Error( self::REASON_CART_MISSING, __( '找不到購物車。', 'now-crm' ) );
		}

		// Cheap preflight so the managed owner is created only for a cart that
		// could actually start. Every check repeats under the cart lock.
		$cart = ( new CartRepository() )->get( $cart_id );

		if ( ! $cart ) {
			return new WP_Error( self::REASON_CART_MISSING, __( '找不到購物車。', 'now-crm' ) );
		}

		if ( ! Settings::bool( 'abandoned_cart_enabled' ) ) {
			return new WP_Error( self::REASON_CART_DISABLED, __( '購物車挽回已停用。', 'now-crm' ) );
		}

		if ( CartRepository::STATUS_ABANDONED !== (string) $cart['status'] ) {
			return new WP_Error( self::REASON_CART_NOT_ABANDONED, __( '購物車不是放棄狀態。', 'now-crm' ) );
		}

		$owner = self::resolve_owner();

		if ( empty( $owner['valid'] ) ) {
			self::note_once( $cart_id, 'not_eligible', array( 'reason' => $owner['reason'] ) );

			return new WP_Error( self::REASON_OWNER_UNAVAILABLE, __( '購物車挽回流程目前不可用。', 'now-crm' ) );
		}

		// Lifecycle first, then cart. No path holds the cart lock and then
		// takes the lifecycle lock: a structural save takes only lifecycle,
		// and Engine step execution takes only the cart lock.
		return AutomationRepository::with_lifecycle_lock(
			(int) $owner['automation_id'],
			static function () use ( $cart_id, $source_ref, $owner ) {
				return CartRepository::with_lock(
					$cart_id,
					static function () use ( $cart_id, $source_ref, $owner ) {
						return self::start_locked( $cart_id, $source_ref, $owner );
					}
				);
			}
		);
	}

	/**
	 * Durable due reconciliation for an abandoned cart.
	 *
	 * Called by the sweeper for due carts that were captured before an owner
	 * existed or whose earlier dispatch never created a run. Idempotent through
	 * the immutable source identity.
	 *
	 * @param array<string, mixed> $cart Cart row.
	 */
	public static function dispatch_due( array $cart ): bool {
		$result = self::start_for_cart( (int) $cart['id'], 'cart:' . (int) $cart['id'] );

		return is_array( $result ) && ! empty( $result['started'] );
	}

	/**
	 * Latest run for a cart, newest first.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function run_for_cart( int $cart_id ): ?array {
		if ( $cart_id <= 0 ) {
			return null;
		}

		$rows = ( new RunRepository() )->for_cart( $cart_id, 1 );

		return $rows[0] ?? null;
	}

	/**
	 * Jobs linked to a cart through the cart column.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function jobs_for_cart( int $cart_id, int $limit = 20 ): array {
		if ( $cart_id <= 0 ) {
			return array();
		}

		return ( new EmailJobRepository() )->for_cart( $cart_id, $limit );
	}

	/**
	 * Cancel every open run and queued job of one cart.
	 *
	 * Used by the lost transition, where no later source remains to protect.
	 */
	public static function cancel_open( int $cart_id, string $reason ): int {
		if ( $cart_id <= 0 ) {
			return 0;
		}

		$cancelled = ( new RunRepository() )->cancel_open_for_cart( $cart_id, $reason );

		$cancelled += (int) ( new EmailJobRepository() )->cancel_cart( $cart_id, $reason );

		return $cancelled;
	}

	/**
	 * Consume `nowcrm_cart_source_invalidated`.
	 *
	 * Terminal carts (order_pending, completed, recovered, lost) cancel every
	 * open run and queued job of that exact cart, whatever its version. A
	 * reactivation cancels only prior sources, so a stale asynchronous event
	 * can never cancel the current version's run. Another cart with the same
	 * email is never touched.
	 */
	public static function on_source_invalidated( $cart_id, $version = 0, $reason = '' ): void {
		$cart_id = absint( $cart_id );

		if ( $cart_id <= 0 ) {
			return;
		}

		$cart = ( new CartRepository() )->get( $cart_id );

		if ( ! $cart ) {
			return;
		}

		$current = (int) $cart['version'];
		$source  = 'cart:' . $cart_id . ':' . $current;
		$status  = (string) $cart['status'];
		$reasons = sanitize_key( (string) $reason ) ?: self::REASON_CART_VERSION_CHANGED;
		$runs    = new RunRepository();
		$jobs    = new EmailJobRepository();

		if ( ! in_array( $status, array( CartRepository::STATUS_ACTIVE, CartRepository::STATUS_ABANDONED ), true ) ) {
			$cancelled = $jobs->cancel_cart( $cart_id, $reasons );
			$count     = $runs->cancel_open_for_cart( $cart_id, $reasons );
		} else {
			$cancelled = $jobs->cancel_source( $cart_id, $source, $reasons );
			$count     = 0;

			foreach ( $runs->open_for_cart( $cart_id ) as $run ) {
				if ( (string) ( $run['source_key'] ?? '' ) === $source ) {
					continue;
				}

				// A column row can only be current when it carries the exact
				// current cart and version; legacy rows have no cart column.
				if ( (int) ( $run['cart_id'] ?? 0 ) === $cart_id && (int) ( $run['cart_version'] ?? 0 ) === $current ) {
					continue;
				}

				if ( $runs->cancel( (int) $run['id'], $reasons ) ) {
					++$count;
				}
			}
		}

		if ( $cancelled + $count > 0 ) {
			self::note_once(
				$cart_id,
				'automation_invalidated',
				array(
					'reason'  => $reasons,
					'version' => $current,
					'jobs'    => $cancelled,
					'runs'    => $count,
				)
			);
		}
	}

	/**
	 * Locked portion of start_for_cart.
	 *
	 * @return array{started: bool, reason: string, run: array<string, mixed>}|WP_Error
	 */
	private static function start_locked( int $cart_id, string $source_ref, array $owner ) {
		$repo = new CartRepository();
		$cart = $repo->get( $cart_id );

		if ( ! $cart ) {
			return new WP_Error( self::REASON_CART_MISSING, __( '找不到購物車。', 'now-crm' ) );
		}

		if ( ! Settings::bool( 'abandoned_cart_enabled' ) ) {
			return new WP_Error( self::REASON_CART_DISABLED, __( '購物車挽回已停用。', 'now-crm' ) );
		}

		if ( CartRepository::STATUS_ABANDONED !== (string) $cart['status'] ) {
			return new WP_Error( self::REASON_CART_NOT_ABANDONED, __( '購物車不是放棄狀態。', 'now-crm' ) );
		}

		$version = (int) $cart['version'];
		$source  = 'cart:' . $cart_id . ':' . $version;
		$runs    = new RunRepository();
		$existing = $runs->find_by_source_key( $source );

		if ( $existing ) {
			return array(
				'started' => false,
				'reason'  => 'duplicate_source',
				'run'     => $existing,
				'run_id'  => (int) $existing['id'],
			);
		}

		$legacy = self::legacy_fence( $cart );

		if ( is_wp_error( $legacy ) ) {
			self::note_once( $cart_id, 'not_eligible', array( 'reason' => (string) $legacy->get_error_code() ) );

			return $legacy;
		}
		$automation = ( new AutomationRepository() )->get( (int) $owner['automation_id'] );

		if ( ! $automation ) {
			self::note_once( $cart_id, 'not_eligible', array( 'reason' => self::REASON_OWNER_UNAVAILABLE ) );

			return new WP_Error( self::REASON_OWNER_UNAVAILABLE, __( '購物車挽回流程目前不可用。', 'now-crm' ) );
		}

		$contact = self::contact_for_cart( $cart );

		if ( is_wp_error( $contact ) ) {
			self::note_once( $cart_id, 'not_eligible', array( 'reason' => (string) $contact->get_error_code() ) );

			return $contact;
		}

		// The caller holds the lifecycle lock (outside) and the cart lock
		// (inside). This method must not acquire either one again.
		$result = ( new Engine() )->start_cart(
			$automation,
			$contact,
			$cart_id,
			$version,
			$source_ref
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( ! empty( $result['started'] ) ) {
			$repo->record_event(
				$cart_id,
				'automation_started',
				array(
					'automation_id' => (int) $owner['automation_id'],
					'run_id'        => (int) $result['run']['id'],
					'source_key'    => $source,
					'mode'          => $owner['mode'],
				)
			);
		}

		if ( empty( $result['run']['contact_id'] ) ) {
			$result['run']['contact_id'] = (int) $contact['id'];
		}

		if ( ! Status::allows_cart_email( (string) $contact['status'] ) ) {
			self::note_once( $cart_id, 'not_eligible', array( 'reason' => self::REASON_NOT_SUBSCRIBED ) );
		}

		return $result;
	}

	/**
	 * Resolve or safely create the contact that owns the cart run.
	 *
	 * A guest without a native contact becomes transactional: it can own the
	 * run for audit, and only the cart reminder policy may mail it. It is never
	 * subscribed by inference and no marketing mail is sent.
	 *
	 * @param array<string, mixed> $cart Cart row.
	 * @return array<string, mixed>|WP_Error
	 */
	private static function contact_for_cart( array $cart ) {
		$email = trim( (string) $cart['email'] );

		if ( ! is_email( $email ) ) {
			return new WP_Error( self::REASON_NO_EMAIL, __( '購物車沒有有效 Email。', 'now-crm' ) );
		}

		$repo    = new ContactRepository();
		$contact = $repo->find_by_email( $email );

		if ( $contact ) {
			return $contact;
		}

		$created = $repo->get_or_create(
			$email,
			array(
				'status' => Status::TRANSACTIONAL,
				'source' => 'abandoned-cart',
			)
		);

		if ( is_wp_error( $created ) ) {
			return $created;
		}

		$contact = $created['contact'] ?? null;

		return is_array( $contact ) ? $contact : new WP_Error( self::REASON_NO_EMAIL, __( '無法建立聯絡人。', 'now-crm' ) );
	}

	/**
	 * Legacy `kind=cart` fence.
	 *
	 * Every claimed (`sending`) row is an exclusive owner regardless of the
	 * payload version and is never cancelled. A queued row of the current
	 * version also blocks. Only an older, provably undispatched queued row is
	 * cancelled. Accepted and unknown outcomes are conservative blocks. The
	 * query is not page-limited, so a claimed handoff can never hide behind
	 * newer history.
	 *
	 * @param array<string, mixed> $cart Cart row.
	 * @return true|WP_Error
	 */
	private static function legacy_fence( array $cart ) {
		global $wpdb;

		$version = (int) $cart['version'];
		$table   = Schema::table( 'email_jobs' );
		$jobs    = new EmailJobRepository();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- trusted table.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, status, payload FROM {$table}
				WHERE cart_id = %d AND kind = %s AND status IN (%s, %s, %s, %s)
				ORDER BY id ASC",
				(int) $cart['id'],
				'cart',
				EmailJobRepository::STATUS_QUEUED,
				EmailJobRepository::STATUS_SENDING,
				EmailJobRepository::STATUS_ACCEPTED,
				EmailJobRepository::STATUS_UNKNOWN
			),
			ARRAY_A
		);

		foreach ( (array) $rows as $row ) {
			$payload     = json_decode( (string) ( $row['payload'] ?? '' ), true );
			$payload     = is_array( $payload ) ? $payload : array();
			$job_version = (int) ( $payload['cart_version'] ?? 0 );
			$status      = (string) $row['status'];

			if ( EmailJobRepository::STATUS_SENDING === $status ) {
				// A claimed worker handoff owns the cart regardless of its
				// payload version. It is never cancelled; the accepted or
				// unknown outcome decides the result.
				return new WP_Error( self::REASON_LEGACY_OWNER, __( '既有購物車提醒仍是唯一寄送者。', 'now-crm' ) );
			}

			if ( EmailJobRepository::STATUS_QUEUED === $status ) {
				if ( $job_version === $version ) {
					return new WP_Error( self::REASON_LEGACY_OWNER, __( '既有購物車提醒仍是唯一寄送者。', 'now-crm' ) );
				}

				// Only an older, provably undispatched row may be cancelled.
				$jobs->cancel_job( (int) $row['id'], self::REASON_CART_VERSION_CHANGED );
				continue;
			}

			if ( $job_version <= 0 || $job_version === $version ) {
				return new WP_Error( self::REASON_LEGACY_OWNER, __( '既有購物車提醒結果不可重送。', 'now-crm' ) );
			}
		}

		return self::legacy_generic_fence( (int) $cart['id'] );
	}

	/**
	 * Fence older generic cart-trigger jobs with an exact source and job key.
	 *
	 * Only the immutable old `cart:<id>` source and the job key's run/start
	 * hash are trusted. Queued generic jobs are ignored because Queue
	 * suppresses them; sending, accepted and unknown outcomes block a
	 * replacement message for the same cart. Unrelated contact mail is never
	 * consulted.
	 *
	 * @return true|WP_Error
	 */
	private static function legacy_generic_fence( int $cart_id ) {
		global $wpdb;

		$table = Schema::table( 'automation_runs' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- trusted table.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, automation_id, started_at FROM {$table} WHERE source_trigger = %s AND source_ref = %s AND cart_id IS NULL ORDER BY id DESC LIMIT 20",
				TriggerRegistry::CART_ABANDONED,
				'cart:' . $cart_id
			),
			ARRAY_A
		);

		$jobs = new EmailJobRepository();

		foreach ( (array) $rows as $row ) {
			$prefix = sprintf(
				'automation:%d:run:%d:start:%s:',
				(int) $row['automation_id'],
				(int) $row['id'],
				substr( md5( (string) $row['started_at'] ), 0, 12 )
			);

			foreach ( $jobs->by_key_prefix( $prefix, 20 ) as $job ) {
				if ( in_array(
					(string) $job['status'],
					array( EmailJobRepository::STATUS_SENDING, EmailJobRepository::STATUS_ACCEPTED, EmailJobRepository::STATUS_UNKNOWN ),
					true
				) ) {
					return new WP_Error( self::REASON_LEGACY_OWNER, __( '既有購物車流程的寄送結果不可重送。', 'now-crm' ) );
				}
			}
		}

		return true;
	}
	private static function has_email_step( int $automation_id ): bool {
		foreach ( ( new AutomationRepository() )->steps( $automation_id ) as $step ) {
			if ( AutomationRepository::KIND_EMAIL === $step['kind'] ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Append one cart event unless the same type is already present.
	 */
	private static function note_once( int $cart_id, string $type, array $meta ): void {
		foreach ( ( new CartRepository() )->events( $cart_id, 50 ) as $event ) {
			if ( $type === (string) $event['type'] ) {
				return;
			}
		}

		( new CartRepository() )->record_event( $cart_id, $type, $meta );
	}
}
