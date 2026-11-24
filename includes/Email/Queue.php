<?php
/**
 * Queue worker. Action Scheduler (or WP-Cron as fallback) only wakes this
 * worker; the DB row status, unique key and lease are the real guarantees.
 *
 * @package NowCRM
 */

namespace NowCRM\Email;

use NowCRM\Automation\TriggerRegistry;
use NowCRM\Campaign\Campaigns;
use NowCRM\Campaign\Delivery;
use NowCRM\Cart\CartRepository;
use NowCRM\Cart\Reminder;
use NowCRM\Contacts\ContactRepository;
use NowCRM\Contacts\Status;
use NowCRM\Consent\Config as ConsentConfig;
use NowCRM\Consent\Tokens as ConsentTokens;
use NowCRM\Support\Settings;
use NowCRM\Unsubscribe\Tokens;

defined( 'ABSPATH' ) || exit;

final class Queue {

	public const HOOK    = 'nowcrm_send_batch';
	public const GROUP   = 'nowcrm';
	public const CRON    = 'nowcrm_email_worker';

	private EmailJobRepository $jobs;
	private EmailRenderer $renderer;
	private EmailDispatcher $dispatcher;
	private SuppressionRepository $suppressions;
	private ContactRepository $contacts;

	public function __construct() {
		$this->jobs         = new EmailJobRepository();
		$this->renderer     = new EmailRenderer();
		$this->dispatcher   = new EmailDispatcher();
		$this->suppressions = new SuppressionRepository();
		$this->contacts     = new ContactRepository();
	}

	/**
	 * Hooks.
	 */
	public function register(): void {
		add_action( self::HOOK, array( $this, 'run' ) );
		add_action( self::CRON, array( $this, 'run' ) );
		add_action( 'init', array( $this, 'ensure_scheduled' ), 20 );
	}

	/**
	 * Schedule the next worker tick.
	 */
	public static function schedule( int $delay = 0 ): void {
		$when = time() + max( 0, $delay );

		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( $when, self::HOOK, array(), self::GROUP );
			return;
		}

		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_single_event( $when, self::HOOK );
		}
	}

	/**
	 * Keep a heartbeat even when nothing is enqueued, so scheduled campaigns
	 * and cart sweeps wake up without a page view.
	 */
	public function ensure_scheduled(): void {
		if ( function_exists( 'as_schedule_recurring_action' ) && did_action( 'action_scheduler_init' ) ) {
			if ( ! as_next_scheduled_action( self::CRON, array(), self::GROUP ) ) {
				as_schedule_recurring_action( time() + 60, MINUTE_IN_SECONDS, self::CRON, array(), self::GROUP, true );
			}
		} elseif ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + 60, 'nowcrm_minute', self::CRON );
		}
	}

	/**
	 * Worker tick.
	 */
	public function run(): void {
		if ( ! Settings::bool( 'email_sending_enabled' ) ) {
			return;
		}

		$lock = $this->acquire_lock();

		if ( ! $lock ) {
			// Another worker is active; check again soon.
			self::schedule( 20 );
			return;
		}

		$token = bin2hex( random_bytes( 16 ) );
		try {
			$this->jobs->release_stale();

			$delivery = new Delivery();
			$delivery->advance();
			$rows = $this->jobs->claim( Settings::int( 'email_batch_size' ), $token );

			if ( $rows ) {
				$this->process_rows( $rows, $token );
				$delivery->advance();
			}
			if ( $this->jobs->due_count() > 0 ) {
				self::schedule( 1 );
			}

			// A failed tick never reaches this point: the exception skips the
			// record and the lock still releases in finally.
			WorkerHealth::record();
		} finally {
			$this->release_lock( $lock );
		}
	}

	/**
	 * Process a set of claimed rows.
	 *
	 * @param array<int, array<string, mixed>> $rows  Claimed jobs.
	 * @param string                           $token Claim token.
	 * @return array{accepted: int, failed: int, suppressed: int, deferred: int, unknown: int, skipped: int}
	 */
	public function process_rows( array $rows, string $token ): array {
		$result = array(
			'accepted'   => 0,
			'failed'     => 0,
			'suppressed' => 0,
			'deferred'   => 0,
			'unknown'    => 0,
			'skipped'    => 0,
		);

		$rate     = max( 1, Settings::int( 'email_throttle_per_second' ) );
		$interval = (int) floor( 1000000 / $rate );
		$deadline = microtime( true ) + 20;

		$next_send = microtime( true );
		foreach ( $rows as $row ) {
			$id = (int) $row['id'];
			if ( microtime( true ) >= $deadline ) {
				if ( 'campaign' !== $row['kind'] || ! $this->campaign_gate( $row, $token ) ) {
					$this->jobs->defer( $id, $token, 0 );
					if ( 'campaign' === $row['kind'] ) {
						$this->recheck_cancelled( (int) $row['campaign_id'] );
					}
				}
				continue;
			}

			$sleep = (int) ( ( $next_send - microtime( true ) ) * 1000000 );
			if ( $sleep > 0 ) {
				usleep( $sleep );
			}
			$job = $this->jobs->get( $id );
			if ( ! $job || EmailJobRepository::STATUS_SENDING !== $job['status'] || $token !== $job['claim_token'] ) {
				continue;
			}

			$next_send = microtime( true ) + $interval / 1000000;
			$outcome = $this->process_one( $job, $token );
			++$result[ $outcome ];
		}

		return $result;
	}

	/**
	 * Send one job after all gates.
	 *
	 * Cart jobs authorize the exact cart source under the short cart lock,
	 * then release it before the transport call. The claim stays `sending`
	 * while the message is handed off, so a cart transition during transport
	 * cannot rewrite the worker's outcome.
	 *
	 * @param array<string, mixed> $job Claimed job.
	 * @return string accepted|failed|suppressed|unknown|deferred|skipped
	 */
	private function process_one( array $job, string $token ): string {
		if ( $this->is_cart_job( $job ) ) {
			$authorized = CartRepository::with_lock(
				(int) $job['cart_id'],
				function () use ( $job, $token ) {
					$fresh = $this->jobs->get( (int) $job['id'] );

					if ( ! $fresh || EmailJobRepository::STATUS_SENDING !== $fresh['status'] || $token !== (string) $fresh['claim_token'] ) {
						return false;
					}

					$contact  = (int) $fresh['contact_id'] > 0 ? $this->contacts->get( (int) $fresh['contact_id'] ) : null;
					$eligible = Reminder::can_send( $fresh, $contact );

					if ( true !== $eligible ) {
						return (string) $eligible;
					}

					if ( 'automation' === $fresh['kind'] ) {
						$recipient_reason = $this->recipient_reason( (int) $fresh['contact_id'], (string) $fresh['recipient'], true );

						if ( null !== $recipient_reason ) {
							return $recipient_reason;
						}
					}

					return true;
				}
			);

			if ( is_wp_error( $authorized ) ) {
				$this->jobs->defer( (int) $job['id'], $token, 5, 'cart_locked' );

				return 'deferred';
			}

			if ( false === $authorized ) {
				// The row changed while waiting for the cart lock: never let a
				// stale worker store an outcome for another claim or terminal row.
				return 'skipped';
			}

			if ( true !== $authorized ) {
				$marked = $this->jobs->mark_suppressed( (int) $job['id'], $token, (string) $authorized );

				if ( $marked ) {
					$this->record_cart_outcome( $job, 'suppressed', (string) $authorized );
				}

				return 'suppressed';
			}
		}

		return $this->process_one_inner( $job, $token );
	}

	/**
	 * Whether a claimed job needs the abandoned-cart source fence.
	 *
	 * @param array<string, mixed> $job Job row.
	 */
	private function is_cart_job( array $job ): bool {
		$cart_id = (int) $job['cart_id'];

		if ( $cart_id <= 0 ) {
			return false;
		}

		if ( 'cart' === $job['kind'] ) {
			return true;
		}

		return 'automation' === $job['kind'] && 0 === strpos( (string) ( $job['source_key'] ?? '' ), 'cart:' );
	}

	/**
	 * Send one job after all gates, inside the cart lock when applicable.
	 *
	 * @param array<string, mixed> $job Claimed job.
	 * @return string accepted|failed|suppressed
	 */
	private function process_one_inner( array $job, string $token ): string {
		$kind       = (string) $job['kind'];
		$contact_id = (int) $job['contact_id'];
		$contact    = $contact_id > 0 ? $this->contacts->get( $contact_id ) : null;
		$recipient  = (string) $job['recipient'];
		$is_cart_job = $this->is_cart_job( $job );
		$is_cart_automation = 'automation' === $kind && $is_cart_job;

		if ( 'campaign' === $kind ) {
			$blocked = $this->campaign_gate( $job, $token );
			if ( $blocked ) {
				return $blocked;
			}
		}

		// Cart jobs were fully revalidated under the cart lock; after that
		// authorization the worker owns the handoff and must not re-check.
		if ( ! $is_cart_job ) {
			// Cutover fence: older cart-trigger automations queued generic jobs
			// without a cart link. Resolve the exact run once and suppress only
			// when it proves the cart source; verified non-cart jobs are untouched.
			if ( 'automation' === $kind && (int) $job['cart_id'] <= 0 && '' === (string) ( $job['source_key'] ?? '' ) ) {
				$legacy = $this->legacy_cart_automation( $job );

				if ( $legacy ) {
					$this->jobs->mark_suppressed( (int) $job['id'], $token, 'legacy_cart_automation' );
					$this->record_cart_outcome( $job, 'suppressed', 'legacy_cart_automation' );

					return 'suppressed';
				}
			}

			// 2. Marketing gate: status must still be subscribed. Consent mail is
			// transactional (double opt-in, link delivery): it skips only this
			// gate, never the global suppression gate below.
			if ( ! in_array( $kind, array( 'test', 'consent' ), true ) ) {
				if ( ! $contact || Status::SUBSCRIBED !== $contact['status'] ) {
					$this->jobs->mark_suppressed( (int) $job['id'], $token, 'not_subscribed' );

					return 'suppressed';
				}
			}

			// 3. Global suppression list (hard bounce / complaint / unsubscribe).
			if ( $this->suppressions->is_suppressed( $recipient ) ) {
				$this->jobs->mark_suppressed( (int) $job['id'], $token, 'suppressed_address' );

				return 'suppressed';
			}
		}

		// 4. Build the message from the frozen template snapshot.
		$template = $this->template_for( $job );

		if ( ! $template ) {
			$this->jobs->mark_failed( (int) $job['id'], $token, 'template_missing', false );

			return 'failed';
		}

		$template = $this->variant_template( $template, $job );

		$context = array(
			'first_name' => (string) ( $contact['first_name'] ?? '' ),
			'last_name'  => (string) ( $contact['last_name'] ?? '' ),
			'email'      => $recipient,
			'site_name'  => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			'site_url'   => home_url( '/' ),
			'order_id'   => (string) ( $job['payload']['order_id'] ?? '' ),
			'order_total' => (string) ( $job['payload']['order_total'] ?? '' ),
			'cart_url'   => function_exists( 'wc_get_cart_url' ) ? wc_get_cart_url() : home_url( '/' ),
		);

		if ( 'cart' === $kind && ! empty( $job['payload']['recovery_url'] ) ) {
			$context['recovery_url'] = (string) $job['payload']['recovery_url'];
		}

		$unsubscribe_url = '';
		$manage_url      = '';

		if ( 'test' !== $kind && 'consent' !== $kind && $contact ) {
			$token_row       = ( new Tokens() )->issue( (int) $contact['id'], (int) $job['campaign_id'] ?: null, (int) $job['id'] );
			if ( ! $token_row['id'] ) {
				$this->jobs->mark_failed( (int) $job['id'], $token, 'unsubscribe_token_failed', true );
				return 'failed';
			}
			$unsubscribe_url = $token_row['url'];
		}

		if ( $contact ) {
			$manage_issued = ConsentTokens::issue(
				ConsentTokens::PURPOSE_MANAGE,
				(int) $contact['id'],
				(string) $contact['email'],
				max( 1, (int) ConsentConfig::get( 'manage_token_days', 90 ) ) * DAY_IN_SECONDS
			);

			if ( ! is_wp_error( $manage_issued ) ) {
				$manage_url = ConsentTokens::url( $manage_issued['token'] );
			}
		}

		$context['unsubscribe_url'] = $unsubscribe_url;
		$context['manage_url']      = $manage_url;

		$rendered = $this->renderer->render( $template, $context );
		$html     = $rendered['html'];

		if ( 'campaign' === $kind ) {
			$job['payload'] = $template;
			$html = ( new Tracking() )->inject( $html, $job, (array) $contact );

			$blocked = $this->campaign_gate( $job, $token );
			if ( $blocked ) {
				return $blocked;
			}
			$blocked = $this->current_recipient_gate( $job, $token, $contact_id, $recipient );
			if ( $blocked ) {
				return $blocked;
			}
		} elseif ( 'cart' === $kind || 'custom' === $kind || $is_cart_automation ) {
			$html = ( new Tracking() )->inject( $html, $job, (array) $contact );
		} elseif ( 'sequence' === $kind && $contact ) {
			$payload  = isset( $job['payload'] ) && is_array( $job['payload'] ) ? $job['payload'] : array();
			$tracking = isset( $payload['tracking'] ) && is_array( $payload['tracking'] ) ? $payload['tracking'] : array();

			// The enqueue marker prevents old jobs from gaining tracking when the
			// setting is turned on later; the current setting prevents tracking
			// after it is turned off.
			if ( ! empty( $tracking['sequence'] ) && Settings::bool( 'sequence_tracking_enabled' ) ) {
				$html = ( new Tracking() )->inject_sequence( $html, $job, (array) $contact );
			}
		}

		// 6. Automation jobs revalidate their frozen recipient against the
		// current contact immediately before dispatch. A changed address
		// suppresses the job instead of rerouting mail to an address the
		// contact no longer uses. Cart automation already did this under the
		// pre-handoff cart lock.
		if ( 'automation' === $kind && ! $is_cart_job ) {
			$blocked = $this->current_recipient_gate( $job, $token, $contact_id, $recipient );

			if ( $blocked ) {
				return $blocked;
			}
		}

		$dispatch = $this->dispatcher->send(
			array(
				'to'              => $recipient,
				'to_name'         => (string) $job['recipient_name'],
				'subject'         => $rendered['subject'] ?: (string) $job['subject'],
				'html'            => $html,
				'text'            => $rendered['text'],
				'job_id'          => (int) $job['id'],
				'ses_correlation' => (string) ( $job['ses_correlation'] ?? '' ),
				'kind'            => $kind,
				'unsubscribe_url' => $unsubscribe_url,
				'frozen_sender'   => 'cart' !== $kind && ! empty( $template['from_address'] ),
				'reply_to'        => (string) ( $job['payload']['reply_to'] ?? $template['reply_to'] ?? '' ),
				'from_name'       => (string) ( $job['payload']['from_name'] ?? $template['from_name'] ?? '' ),
				'from_address'    => (string) ( $job['payload']['from_address'] ?? $template['from_address'] ?? '' ),
			)
		);

		if ( $dispatch['ok'] ) {
			$stored = $this->jobs->mark_accepted( (int) $job['id'], $token, $dispatch['message_id'], $dispatch['transport'] );

			if ( ! $stored ) {
				// Another claim or terminal transition won the row; never book
				// acceptance that was not persisted.
				return 'skipped';
			}

			if ( $is_cart_automation ) {
				$cart_repo = new CartRepository();
				$cart_repo->mark_reminder_sent( (int) $job['cart_id'] );
				$cart_repo->record_event(
					(int) $job['cart_id'],
					'reminder_sent',
					array(
						'job_id'       => (int) $job['id'],
						'automation_id' => (int) ( $job['payload']['automation_id'] ?? 0 ),
						'run_id'       => (int) ( $job['payload']['run_id'] ?? 0 ),
						'source_key'   => (string) ( $job['source_key'] ?? '' ),
					)
				);
				$this->record_cart_outcome( $job, 'accepted', '' );
			}

			if ( 'cart' === $kind && $job['cart_id'] > 0 ) {
				$cart_repo = new \NowCRM\Cart\CartRepository();
				$cart_repo->mark_reminder_sent( (int) $job['cart_id'] );
				$cart_repo->record_event( (int) $job['cart_id'], 'reminder_sent', array( 'job_id' => (int) $job['id'] ) );
			}

			do_action( 'nowcrm_email_accepted', $job, $dispatch );

			return 'accepted';
		}

		// A wp_mail false is not proof that nothing left the site. Cart and
		// campaign handoffs stay unknown instead of auto-retrying.
		if ( 'wp_mail' === $dispatch['transport'] && ( 'campaign' === $kind || $is_cart_job ) ) {
			$stored = $this->jobs->mark_unknown( (int) $job['id'], $token, $dispatch['error'] ?: 'send_outcome_unknown' );

			if ( ! $stored ) {
				return 'skipped';
			}

			$this->record_cart_outcome( $job, 'unknown', $dispatch['error'] ?: 'send_outcome_unknown' );

			return 'unknown';
		}

		$stored = $this->jobs->mark_failed( (int) $job['id'], $token, $dispatch['error'] ?: 'send_failed', true );

		if ( ! $stored ) {
			return 'skipped';
		}

		$this->record_cart_outcome( $job, 'failed', $dispatch['error'] ?: 'send_failed' );

		return 'failed';
	}

	/**
	 * Whether an old generic automation job belongs to the cart trigger.
	 *
	 * Only the exact persisted run is read. A run without cart source evidence
	 * keeps its ordinary automation behavior.
	 */
	private function legacy_cart_automation( array $job ): bool {
		$payload = is_array( $job['payload'] ?? null ) ? $job['payload'] : array();
		$run_id  = (int) ( $payload['run_id'] ?? 0 );

		if ( $run_id <= 0 ) {
			return false;
		}

		$run = ( new \NowCRM\Automation\RunRepository() )->get( $run_id );

		if ( ! $run ) {
			return false;
		}

		if ( (int) ( $payload['automation_id'] ?? 0 ) > 0 && (int) $payload['automation_id'] !== (int) $run['automation_id'] ) {
			return false;
		}

		return TriggerRegistry::CART_ABANDONED === (string) $run['source_trigger']
			|| 0 === strpos( (string) $run['source_ref'], 'cart:' );
	}

	/**
	 * Write the queue outcome onto the cart run step for audit.
	 *
	 * The event payload keeps the job ID, so a late or duplicate queue result
	 * cannot overwrite another job's outcome.
	 */
	private function record_cart_outcome( array $job, string $outcome, string $reason ): void {
		$payload = is_array( $job['payload'] ?? null ) ? $job['payload'] : array();
		$run_id  = (int) ( $payload['run_id'] ?? 0 );
		$step_id = (int) ( $payload['step_id'] ?? 0 );

		if ( $run_id <= 0 || $step_id <= 0 ) {
			return;
		}

		( new \NowCRM\Automation\RunRepository() )->record_job_outcome(
			$run_id,
			$step_id,
			(int) $job['id'],
			$outcome,
			array( 'reason' => $reason )
		);
	}

	/**
	 * Whether a frozen recipient may still receive the job.
	 *
	 * @param int    $contact_id Contact ID.
	 * @param string $recipient  Frozen queued recipient.
	 * @param bool   $cart_job   Whether the caller already proved a cart job.
	 * @return string|null Stable reason when blocked, null when eligible.
	 */
	private function recipient_reason( int $contact_id, string $recipient, bool $cart_job = false ): ?string {
		$current = $contact_id > 0 ? $this->contacts->get( $contact_id ) : null;

		if ( ! $current || 0 !== strcasecmp( $recipient, (string) $current['email'] ) ||
			$this->suppressions->is_suppressed( $recipient ) ) {
			return 'recipient_no_longer_eligible';
		}

		$eligible = $cart_job
			? Status::allows_cart_email( (string) $current['status'] )
			: Status::SUBSCRIBED === $current['status'];

		return $eligible ? null : 'recipient_no_longer_eligible';
	}

	/**
	 * Verify a frozen recipient still matches the current contact before send.
	 *
	 * The queued recipient and content stay immutable: when the contact's
	 * address, subscription state or suppression state changed after enqueue,
	 * the job is suppressed with a stable reason instead of being rerouted.
	 *
	 * @param array<string, mixed> $job        Job row.
	 * @param string               $token      Claim token.
	 * @param int                  $contact_id Contact ID.
	 * @param string               $recipient  Frozen queued recipient.
	 * @return string|null 'suppressed' when blocked, null when still eligible.
	 */
	private function current_recipient_gate( array $job, string $token, int $contact_id, string $recipient ): ?string {
		if ( null === $this->recipient_reason( $contact_id, $recipient ) ) {
			return null;
		}

		$this->jobs->mark_suppressed( (int) $job['id'], $token, 'recipient_no_longer_eligible' );

		return 'suppressed';
	}

	/**
	 * Apply the job's frozen A/B variant to the campaign template.
	 *
	 * The variant is assigned at enqueue time. Campaigns without A/B keep the
	 * single frozen subject.
	 *
	 * @param array<string, mixed> $template Frozen template.
	 * @param array<string, mixed> $job      Job row.
	 * @return array<string, mixed>
	 */
	private function variant_template( array $template, array $job ): array {
		if ( 'campaign' !== $job['kind'] || 'b' !== ( $job['payload']['ab'] ?? '' ) ) {
			return $template;
		}

		$ab      = isset( $template['ab'] ) && is_array( $template['ab'] ) ? $template['ab'] : array();
		$subject = (string) ( $ab['subject_b'] ?? '' );

		if ( ! empty( $ab['enabled'] ) && '' !== trim( $subject ) ) {
			$template['subject'] = $subject;
		}

		return $template;
	}

	/**
	 * Resolve the frozen template of a job.
	 *
	 * @param array<string, mixed> $job Job row.
	 * @return array<string, mixed>|null
	 */
	private function template_for( array $job ): ?array {
		if ( 'cart' === $job['kind'] ) {
			return Reminder::template_for_job( $job );
		}

		if ( 'test' === $job['kind'] ) {
			return $job['payload']['template'] ?? null;
		}

		if ( 'custom' === $job['kind'] ) {
			$template = $job['payload']['template'] ?? null;

			return is_array( $template ) && isset( $template['content'] ) ? $template : null;
		}

		if ( 'automation' === $job['kind'] ) {
			$template = $job['payload']['template'] ?? null;

			return is_array( $template ) && isset( $template['content'] ) ? $template : null;
		}

		if ( 'sequence' === $job['kind'] ) {
			$template = $job['payload']['template'] ?? null;

			return is_array( $template ) && isset( $template['content'] ) ? $template : null;
		}

		if ( 'consent' === $job['kind'] ) {
			$template = $job['payload']['template'] ?? null;

			return is_array( $template ) && isset( $template['content'] ) ? $template : null;
		}

		return Campaigns::template( (int) $job['campaign_id'] );
	}

	private function campaign_gate( array $job, string $token ): ?string {
		$id = (int) $job['campaign_id'];
		wp_cache_delete( $id, 'post_meta' );
		$state = Campaigns::state( $id );
		if ( Campaigns::STATUS_SENDING === $state ) {
			return null;
		}
		if ( Campaigns::STATUS_PAUSED === $state ) {
			$this->jobs->defer( (int) $job['id'], $token, 0, 'campaign_paused' );
			$this->recheck_cancelled( $id );
			return 'deferred';
		}
		$this->jobs->mark_suppressed( (int) $job['id'], $token, 'campaign_not_sending' );
		return 'suppressed';
	}

	private function recheck_cancelled( int $id ): void {
		if ( $id ) {
			wp_cache_delete( $id, 'post_meta' );
			if ( Campaigns::STATUS_CANCELLED === Campaigns::state( $id ) ) {
				$this->jobs->cancel_campaign( $id, 'campaign_cancelled' );
			}
		}
	}

	/** Use a connection-scoped lock to serialize the site's transport rate. */
	private function acquire_lock(): ?string {
		global $wpdb;
		$key = 'nowcrm-queue-' . md5( DB_NAME . ':' . $wpdb->prefix );
		return '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $key ) )
			? $key
			: null;
	}

	private function release_lock( string $key ): void {
		global $wpdb;
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $key ) );
	}

	/**
	 * Queue status for CLI/admin.
	 *
	 * @return array<string, int>
	 */
	public function status(): array {
		$stats = $this->jobs->global_stats();

		return array(
			'queued'     => $stats[ EmailJobRepository::STATUS_QUEUED ] ?? 0,
			'sending'    => $stats[ EmailJobRepository::STATUS_SENDING ] ?? 0,
			'accepted'   => $stats[ EmailJobRepository::STATUS_ACCEPTED ] ?? 0,
			'failed'     => $stats[ EmailJobRepository::STATUS_FAILED ] ?? 0,
			'suppressed' => $stats[ EmailJobRepository::STATUS_SUPPRESSED ] ?? 0,
			'unknown'    => $stats[ EmailJobRepository::STATUS_UNKNOWN ] ?? 0,
			'cancelled'  => $stats[ EmailJobRepository::STATUS_CANCELLED ] ?? 0,
		);
	}
}
