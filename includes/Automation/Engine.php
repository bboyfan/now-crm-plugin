<?php
/**
 * Automation runtime.
 *
 * A trigger creates the run; this engine executes due runs. A run walks its
 * step chain until it waits, ends, fails, or the contact is no longer eligible.
 * Step events make retries idempotent.
 *
 * @package NowCRM
 */

namespace NowCRM\Automation;

use NowCRM\Cart\Automation as CartAutomation;
use NowCRM\Cart\CartRepository;
use NowCRM\Contacts\ContactRepository;
use NowCRM\Contacts\Status;
use NowCRM\Email\EmailJobRepository;
use NowCRM\Support\Settings;
use NowCRM\Terms\TermRepository;
use WP_Error;

defined( 'ABSPATH' ) || exit;

final class Engine {

	public const BATCH_LIMIT      = 50;
	public const MAX_STEPS        = 50;
	public const MAX_SECONDS      = 50;
	public const RUN_MAX_SECONDS  = 15;

	private AutomationRepository $automations;
	private RunRepository $runs;
	private StepResolver $resolver;
	private ActionExecutor $executor;
	private ContactRepository $contacts;

	public function __construct() {
		$this->automations = new AutomationRepository();
		$this->runs        = new RunRepository();
		$this->resolver    = new StepResolver( $this->automations );
		$this->executor    = new ActionExecutor();
		$this->contacts    = new ContactRepository();
	}

	/**
	 * Start a run for one automation and contact.
	 *
	 * @param array<string, mixed> $automation Automation row.
	 * @param array<string, mixed> $contact    Contact row.
	 * @param string               $trigger    Trigger key.
	 * @param string               $source_ref Event reference.
	 * @return array{started: bool, reason: string, run: array<string, mixed>}|WP_Error
	 */
	public function start( array $automation, array $contact, string $trigger, string $source_ref = '' ) {
		$first = $this->resolver->first( (int) $automation['id'] );

		if ( ! $first ) {
			return new WP_Error( 'nowcrm_automation_empty', __( '自動化沒有可執行的步驟。', 'now-crm' ) );
		}

		return $this->runs->start( $automation, (int) $contact['id'], (int) $first['id'], $trigger, $source_ref );
	}

	/**
	 * Start the single cart-scoped run for one immutable cart source.
	 *
	 * Cart runs are created through the source identity API so a replayed
	 * event, a settings change, or a concurrent sweep cannot create a second
	 * flow for the same cart version.
	 *
	 * @param array<string, mixed> $automation Automation row.
	 * @param array<string, mixed> $contact    Contact row.
	 * @param int                  $cart_id    Cart ID.
	 * @param int                  $version    Immutable cart version.
	 * @param string               $source_ref Event reference.
	 * @return array{started: bool, reason: string, run: array<string, mixed>}|WP_Error
	 */
	public function start_cart( array $automation, array $contact, int $cart_id, int $version, string $source_ref = '' ) {
		$first = $this->resolver->first( (int) $automation['id'] );

		if ( ! $first ) {
			return new WP_Error( 'nowcrm_automation_empty', __( '自動化沒有可執行的步驟。', 'now-crm' ) );
		}

		unset( $source_ref );

		return $this->runs->start_source( $automation, (int) $contact['id'], (int) $first['id'], $cart_id, $version );
	}

	/**
	 * Process every due run in this request.
	 *
	 * `locked` is true only when another worker holds the lock and this call
	 * did no work.
	 *
	 * @return array{locked: bool, processed: int, due: int}
	 */
	public function run_due(): array {
		$result = array(
			'locked'    => false,
			'processed' => 0,
			'due'       => 0,
		);

		$lock = $this->acquire_lock();

		if ( ! $lock ) {
			$result['locked'] = true;

			return $result;
		}

		$started = microtime( true );

		try {
			foreach ( $this->runs->due( self::BATCH_LIMIT ) as $run ) {
				if ( microtime( true ) - $started >= self::MAX_SECONDS ) {
					break;
				}

				try {
					$this->process_run( $run );
				} catch ( \Throwable $error ) {
					$this->runs->fail( (int) $run['id'], $error->getMessage() );
				}

				++$result['processed'];
			}
		} finally {
			$this->release_lock( $lock );
		}

		$result['due'] = $this->runs->due_count();

		return $result;
	}

	/**
	 * Execute one claimed run.
	 *
	 * @param array<string, mixed> $run Run row.
	 */
	private function process_run( array $run ): void {
		$fresh = $this->runs->get( (int) $run['id'] );

		if ( ! $fresh || ! in_array( (string) $fresh['status'], array( RunRepository::STATUS_ACTIVE, RunRepository::STATUS_WAITING ), true ) ) {
			// Cancelled, failed, or completed after the due query.
			return;
		}

		$run = $fresh;

		// A pre-2.6 cart run has no source link. It must never create a new
		// generic automation Job; finish it visibly and keep its events.
		if ( empty( $run['cart_id'] ) && empty( $run['source_key'] ) && TriggerRegistry::CART_ABANDONED === (string) ( $run['source_trigger'] ?? '' ) ) {
			$this->runs->cancel( (int) $run['id'], 'legacy_cart_automation' );

			return;
		}

		$cart_id = (int) ( $run['cart_id'] ?? 0 );

		$automation = $this->automations->get( (int) $run['automation_id'] );

		if ( ! $automation || AutomationRepository::STATUS_ACTIVE !== $automation['status'] ) {
			return;
		}

		$contact = $this->contacts->get( (int) $run['contact_id'] );

		if ( ! $contact ) {
			$this->runs->cancel( (int) $run['id'], 'contact_missing' );

			return;
		}

		$force = ! empty( $automation['entry_config']['force_run'] );

		if ( ! $this->eligible( $contact, $force ) ) {
			$this->runs->cancel( (int) $run['id'], 'not_subscribed' );

			return;
		}

		$step = null !== $run['next_step_id'] ? $this->automations->step( (int) $run['next_step_id'] ) : null;

		if ( ! $step ) {
			// A parked terminal wait owns no next step; keep the wait step as
			// the last completed one when the due time finishes the run.
			$this->runs->complete( (int) $run['id'], $run['last_completed_step_id'] ?? null );

			return;
		}

		$deadline = microtime( true ) + self::RUN_MAX_SECONDS;
		$last_id  = null;

		for ( $index = 0; $index < self::MAX_STEPS; $index++ ) {
			if ( microtime( true ) >= $deadline ) {
				// Leave the run due for the next scheduled tick.
				$this->runs->advance( (int) $run['id'], $last_id, $step, RunRepository::STATUS_ACTIVE, gmdate( 'Y-m-d H:i:s' ) );

				return;
			}

			if ( $cart_id > 0 ) {
				$outcome = $this->cart_step( $run, $automation, $step, $force );

				if ( 'busy' === $outcome ) {
					// Short lock contention: keep the run due for a later tick.
					return;
				}

				if ( 'stop' === $outcome ) {
					return;
				}
			} else {
				$contact = $this->contacts->get( (int) $run['contact_id'] );

				if ( ! $contact ) {
					$this->runs->cancel( (int) $run['id'], 'contact_missing' );

					return;
				}

				if ( ! $this->eligible( $contact, $force ) ) {
					$this->runs->cancel( (int) $run['id'], 'not_subscribed' );

					return;
				}

				$outcome = $this->process_step( $automation, $run, $step, $contact );
			}

			if ( $outcome['stop'] ) {
				return;
			}

			$last_id = (int) $step['id'];

			if ( ! $outcome['next'] ) {
				$this->runs->complete( (int) $run['id'], $last_id );

				return;
			}

			$step = $outcome['next'];
		}
	}

	/**
	 * Validate and execute one cart step under the shared cart lock.
	 *
	 * The cart status, exact source version, automation state, and run state
	 * are re-read inside the same lock that guards the action. A missed
	 * invalidation or an order that landed earlier therefore cannot execute a
	 * stale step. The lock is short: one check and one step, then release.
	 *
	 * @param array<string, mixed> $run        Run row.
	 * @param array<string, mixed> $automation Automation row.
	 * @param array<string, mixed> $step       Step row.
	 * @param bool                 $force      Automation force-run setting.
	 * @return array{stop: bool, next: array<string, mixed>|null}|string 'busy' or 'stop'
	 */
	private function cart_step( array $run, array $automation, array $step, bool $force ) {
		$outcome = CartRepository::with_lock(
			(int) $run['cart_id'],
			function () use ( $run, $automation, $step, $force ) {
				$fresh = $this->runs->get( (int) $run['id'] );

				if ( ! $fresh || ! in_array( (string) $fresh['status'], array( RunRepository::STATUS_ACTIVE, RunRepository::STATUS_WAITING ), true ) ) {
					return 'stop';
				}

				$reason = $this->cart_block_reason( $fresh );

				if ( '' !== $reason ) {
					$this->runs->cancel( (int) $fresh['id'], $reason );

					return 'stop';
				}

				$contact = $this->contacts->get( (int) $fresh['contact_id'] );

				if ( ! $contact ) {
					$this->runs->cancel( (int) $fresh['id'], 'contact_missing' );

					return 'stop';
				}

				if ( ! $this->eligible( $contact, $force ) ) {
					$this->runs->cancel( (int) $fresh['id'], 'not_subscribed' );

					return 'stop';
				}

				return $this->process_step( $automation, $fresh, $step, $contact );
			}
		);

		if ( is_wp_error( $outcome ) ) {
			// Busy lock: never fail or skip the run; the next tick retries.
			return 'busy';
		}

		return $outcome;
	}

	/**
	 * Why a cart run may not execute now. Empty means it may.
	 *
	 * @param array<string, mixed> $run Run row.
	 */
	private function cart_block_reason( array $run ): string {
		$cart_id = (int) $run['cart_id'];
		$version = (int) ( $run['cart_version'] ?? 0 );
		$source  = (string) ( $run['source_key'] ?? '' );

		if ( ! Settings::bool( 'abandoned_cart_enabled' ) ) {
			return CartAutomation::REASON_CART_DISABLED;
		}

		$cart = ( new CartRepository() )->get( $cart_id );

		if ( ! $cart ) {
			return CartAutomation::REASON_CART_MISSING;
		}

		if ( CartRepository::STATUS_ABANDONED !== (string) $cart['status'] ) {
			return CartAutomation::REASON_CART_NOT_ABANDONED;
		}

		if ( (int) $cart['version'] !== $version || 'cart:' . $cart_id . ':' . $version !== $source ) {
			return CartAutomation::REASON_CART_VERSION_CHANGED;
		}

		return '';
	}

	/**
	 * Execute one step and write its event.
	 *
	 * @param array<string, mixed> $automation Automation row.
	 * @param array<string, mixed> $run        Run row.
	 * @param array<string, mixed> $step       Step row.
	 * @param array<string, mixed> $contact    Contact row.
	 * @return array{stop: bool, next: array<string, mixed>|null}
	 */
	private function process_step( array $automation, array $run, array $step, array $contact ): array {
		$kind = (string) $step['kind'];

		if ( AutomationRepository::KIND_END === $kind ) {
			$this->runs->record_event( (int) $run['id'], (int) $step['id'], (int) $contact['id'], array( 'kind' => $kind ) );
			$this->runs->complete( (int) $run['id'], (int) $step['id'] );

			return array(
				'stop' => true,
				'next' => null,
			);
		}

		if ( AutomationRepository::KIND_GOAL === $kind ) {
			$event = $this->runs->record_event( (int) $run['id'], (int) $step['id'], (int) $contact['id'], array( 'kind' => $kind ) );

			$config    = (array) $step['config'];
			$mode      = AutomationRepository::GOAL_MODE_OPTIONAL === ( $config['mode'] ?? '' )
				? AutomationRepository::GOAL_MODE_OPTIONAL
				: AutomationRepository::GOAL_MODE_REQUIRED;
			$timeout   = AutomationRepository::GOAL_MODE_OPTIONAL === $mode ? 0 : max( 0, (int) ( $config['timeout_seconds'] ?? 0 ) );
			$event_id  = (int) $event['event']['id'];
			$payload   = (array) ( $event['event']['payload'] ?? array() );
			$satisfied = ! empty( $payload['satisfied'] );
			$deadline  = (string) ( $payload['deadline'] ?? '' );
			$next      = $this->resolver->next_after( $step );
			$on_miss   = $this->resolver->first_child( (int) $step['id'], AutomationRepository::BRANCH_TIMEOUT ) ?: $next;

			if ( $event['created'] ) {
				$satisfied = $this->goal_evidence( $config, $contact );

				$this->runs->update_event(
					$event_id,
					array(
						'kind'            => $kind,
						'event'           => (string) ( $config['event'] ?? '' ),
						'satisfied'       => $satisfied,
						'mode'            => $mode,
						'timeout_seconds' => $timeout,
					),
					$satisfied ? RunRepository::EVENT_COMPLETED : RunRepository::EVENT_WAITING
				);
			}

			if ( ! $satisfied ) {
				if ( $event['created'] && AutomationRepository::GOAL_MODE_OPTIONAL === $mode ) {
					// A non-blocking goal continues now. It never jumps a
					// contact to an earlier step; the run keeps its position.
					$this->runs->update_event(
						$event_id,
						array(
							'kind'            => $kind,
							'event'           => (string) ( $config['event'] ?? '' ),
							'satisfied'       => false,
							'mode'            => $mode,
							'timeout_seconds' => 0,
							'skipped'         => 'optional',
						),
						RunRepository::EVENT_SKIPPED
					);

					if ( ! $on_miss ) {
						$this->runs->complete( (int) $run['id'], (int) $step['id'] );

						return array(
							'stop' => true,
							'next' => null,
						);
					}

					if ( ! $this->runs->advance( (int) $run['id'], (int) $step['id'], $on_miss, RunRepository::STATUS_ACTIVE, gmdate( 'Y-m-d H:i:s' ) ) ) {
						return array(
							'stop' => true,
							'next' => null,
						);
					}

					return array(
						'stop' => false,
						'next' => $on_miss,
					);
				}

				if ( $event['created'] && $timeout > 0 ) {
					$deadline = gmdate( 'Y-m-d H:i:s', time() + $timeout );

					$this->runs->update_event(
						$event_id,
						array(
							'kind'            => $kind,
							'event'           => (string) ( $config['event'] ?? '' ),
							'satisfied'       => false,
							'mode'            => $mode,
							'timeout_seconds' => $timeout,
							'deadline'        => $deadline,
						),
						RunRepository::EVENT_WAITING
					);

					// The goal step stays next so the deadline records one
					// timeout event before the run takes the timeout branch.
					$this->runs->advance( (int) $run['id'], (int) $step['id'], $step, RunRepository::STATUS_WAITING, $deadline );

					return array(
						'stop' => true,
						'next' => null,
					);
				}

				if ( ! $event['created'] && '' !== $deadline && $deadline <= gmdate( 'Y-m-d H:i:s' ) ) {
					// Expire only while the event is still waiting: a native
					// event may have resumed this goal in the same instant, and
					// a satisfied goal must never be rewritten as a timeout.
					$expired = $this->runs->transition_event(
						$event_id,
						RunRepository::EVENT_WAITING,
						RunRepository::EVENT_TIMEOUT,
						array(
							'kind'            => $kind,
							'event'           => (string) ( $config['event'] ?? '' ),
							'satisfied'       => false,
							'mode'            => $mode,
							'timeout_seconds' => $timeout,
							'deadline'        => $deadline,
							'timed_out'       => true,
						)
					);

					if ( ! $expired ) {
						return array(
							'stop' => true,
							'next' => null,
						);
					}

					if ( ! $on_miss ) {
						$this->runs->complete( (int) $run['id'], (int) $step['id'] );

						return array(
							'stop' => true,
							'next' => null,
						);
					}

					if ( ! $this->runs->advance( (int) $run['id'], (int) $step['id'], $on_miss, RunRepository::STATUS_ACTIVE, gmdate( 'Y-m-d H:i:s' ) ) ) {
						return array(
							'stop' => true,
							'next' => null,
						);
					}

					return array(
						'stop' => false,
						'next' => $on_miss,
					);
				}

				// A deadline keeps the goal step as the next pointer. A goal
				// saved before deadlines parks with no due time and waits for a
				// native event, unchanged.
				$this->runs->advance(
					(int) $run['id'],
					(int) $step['id'],
					'' !== $deadline ? $step : $next,
					RunRepository::STATUS_WAITING,
					'' !== $deadline ? $deadline : null
				);

				return array(
					'stop' => true,
					'next' => null,
				);
			}

			if ( ! $next ) {
				$this->runs->complete( (int) $run['id'], (int) $step['id'] );

				return array(
					'stop' => true,
					'next' => null,
				);
			}

			if ( ! $this->runs->advance( (int) $run['id'], (int) $step['id'], $next, RunRepository::STATUS_ACTIVE, gmdate( 'Y-m-d H:i:s' ) ) ) {
				return array(
					'stop' => true,
					'next' => null,
				);
			}

			return array(
				'stop' => false,
				'next' => $next,
			);
		}

		if ( AutomationRepository::KIND_WAIT === $kind ) {
			$event = $this->runs->record_event( (int) $run['id'], (int) $step['id'], (int) $contact['id'], array( 'kind' => $kind ) );

			if ( $event['created'] ) {
				$this->runs->update_event(
					(int) $event['event']['id'],
					array(
						'kind'          => $kind,
						'delay_seconds' => (int) $step['delay_seconds'],
					)
				);
			}

			$next  = $this->resolver->next_after( $step );
			$delay = max( 0, (int) $step['delay_seconds'] );

			// A terminal wait (last step of the chain or of its branch) still
			// honors its delay. A zero delay or an already elapsed wait (the
			// event exists) finishes now, as before.
			if ( ! $next && ( ! $event['created'] || $delay <= 0 ) ) {
				$this->runs->complete( (int) $run['id'], (int) $step['id'] );

				return array(
					'stop' => true,
					'next' => null,
				);
			}

			// A duplicate event means the wait already elapsed once. Do not wait again.
			$due = $event['created']
				? gmdate( 'Y-m-d H:i:s', time() + $delay )
				: gmdate( 'Y-m-d H:i:s' );

			if ( ! $next ) {
				// Park without a next step: the due run completes on its own
				// and never re-executes this wait.
				$this->runs->park_until( (int) $run['id'], (int) $step['id'], $due );

				return array(
					'stop' => true,
					'next' => null,
				);
			}

			$this->runs->advance( (int) $run['id'], (int) $step['id'], $next, RunRepository::STATUS_WAITING, $due );

			return array(
				'stop' => true,
				'next' => null,
			);
		}

		if ( AutomationRepository::KIND_SPLIT === $kind ) {
			$event = $this->runs->record_event( (int) $run['id'], (int) $step['id'], (int) $contact['id'], array( 'kind' => $kind ) );

			$payload = (array) ( $event['event']['payload'] ?? array() );
			$branch  = (string) ( $payload['branch'] ?? '' );

			// The first attempt decides; the stored choice wins on every retry.
			if ( $event['created'] || ! in_array( $branch, array( AutomationRepository::BRANCH_A, AutomationRepository::BRANCH_B ), true ) ) {
				$choice = $this->split_choice( $step, (int) $run['automation_id'], (int) $contact['id'] );
				$branch = $choice['branch'];

				$this->runs->update_event(
					(int) $event['event']['id'],
					array(
						'kind'     => $kind,
						'branch'   => $branch,
						'weight_a' => $choice['weight_a'],
						'weight_b' => $choice['weight_b'],
						'point'    => $choice['point'],
					)
				);
			}

			$next = $this->resolver->branch_next( $step, $branch );

			if ( ! $next ) {
				$this->runs->complete( (int) $run['id'], (int) $step['id'] );

				return array(
					'stop' => true,
					'next' => null,
				);
			}

			if ( ! $this->runs->advance( (int) $run['id'], (int) $step['id'], $next, RunRepository::STATUS_ACTIVE, gmdate( 'Y-m-d H:i:s' ) ) ) {
				return array(
					'stop' => true,
					'next' => null,
				);
			}

			return array(
				'stop' => false,
				'next' => $next,
			);
		}

		if ( AutomationRepository::KIND_CONDITION === $kind ) {
			$event = $this->runs->record_event( (int) $run['id'], (int) $step['id'], (int) $contact['id'], array( 'kind' => $kind ) );

			$payload = $event['event']['payload'] ?? array();
			$branch  = (string) ( $payload['branch'] ?? '' );

			if ( $event['created'] ) {
				$matched = ConditionEvaluator::matches( (array) ( $step['config']['conditions'] ?? array() ), (int) $contact['id'] );

				if ( is_wp_error( $matched ) ) {
					$this->runs->update_event( (int) $event['event']['id'], array( 'error' => $matched->get_error_message() ), RunRepository::EVENT_FAILED );
					$this->runs->fail( (int) $run['id'], $matched->get_error_message() );

					return array(
						'stop' => true,
						'next' => null,
					);
				}

				$branch = $matched ? AutomationRepository::BRANCH_YES : AutomationRepository::BRANCH_NO;

				$this->runs->update_event(
					(int) $event['event']['id'],
					array(
						'kind'    => $kind,
						'branch'  => $branch,
						'matched' => (bool) $matched,
					)
				);
			}

			if ( ! in_array( $branch, array( AutomationRepository::BRANCH_YES, AutomationRepository::BRANCH_NO ), true ) ) {
				$this->runs->fail( (int) $run['id'], 'conditional_branch_missing' );

				return array(
					'stop' => true,
					'next' => null,
				);
			}

			$next = $this->resolver->branch_next( $step, $branch );

			if ( ! $next ) {
				$this->runs->complete( (int) $run['id'], (int) $step['id'] );

				return array(
					'stop' => true,
					'next' => null,
				);
			}

			if ( ! $this->runs->advance( (int) $run['id'], (int) $step['id'], $next, RunRepository::STATUS_ACTIVE, gmdate( 'Y-m-d H:i:s' ) ) ) {
				return array(
					'stop' => true,
					'next' => null,
				);
			}

			return array(
				'stop' => false,
				'next' => $next,
			);
		}

		// The marker is written before the effect as in_progress, then settled
		// to completed, skipped, or failed once the outcome is known.
		$event = $this->runs->record_event( (int) $run['id'], (int) $step['id'], (int) $contact['id'], array( 'kind' => $kind ), RunRepository::EVENT_IN_PROGRESS );

		if ( ! $event['created'] ) {
			$existing = $event['event'];
			$status   = (string) ( $existing['status'] ?? '' );

			if ( AutomationRepository::KIND_EMAIL === $step['kind'] && (int) ( $run['cart_id'] ?? 0 ) > 0 ) {
				return $this->settle_cart_email( $automation, $run, $step, $contact, $existing );
			}

			if ( RunRepository::EVENT_FAILED === $status ) {
				$this->runs->fail( (int) $run['id'], 'step_previously_failed' );

				return array(
					'stop' => true,
					'next' => null,
				);
			}

			if ( ! in_array( $status, array( RunRepository::EVENT_COMPLETED, RunRepository::EVENT_SKIPPED ), true ) ) {
				// The action started but never reported an outcome, so its
				// effects are unknown. Replaying it could repeat them and
				// skipping it would hide the failure: stop visibly failed.
				$this->runs->fail( (int) $run['id'], __( '上一個步驟的結果未知，已停止以避免重複執行。', 'now-crm' ) );

				return array(
					'stop' => true,
					'next' => null,
				);
			}

			// The action already ran. Advance without repeating it.
			$next = $this->resolver->next_after( $step );

			if ( ! $next ) {
				$this->runs->complete( (int) $run['id'], (int) $step['id'] );

				return array(
					'stop' => true,
					'next' => null,
				);
			}

			if ( ! $this->runs->advance( (int) $run['id'], (int) $step['id'], $next, RunRepository::STATUS_ACTIVE, gmdate( 'Y-m-d H:i:s' ) ) ) {
				return array(
					'stop' => true,
					'next' => null,
				);
			}

			return array(
				'stop' => false,
				'next' => $next,
			);
		}

		try {
			$result = $this->executor->execute( $automation, $step, $contact, $run );
		} catch ( \Throwable $error ) {
			// The action may have already applied part of its effect. Keep the
			// event as an interrupted marker so the run is visibly failed and
			// cannot be retried into a duplicate side effect.
			$this->runs->update_event(
				(int) $event['event']['id'],
				array( 'error' => $error->getMessage() ),
				RunRepository::EVENT_INTERRUPTED
			);

			$this->runs->fail(
				(int) $run['id'],
				'' !== trim( $error->getMessage() )
					? $error->getMessage()
					: __( '步驟執行中斷，結果未知。', 'now-crm' )
			);

			return array(
				'stop' => true,
				'next' => null,
			);
		}

		if ( is_wp_error( $result ) ) {
			$this->runs->update_event(
				(int) $event['event']['id'],
				array( 'error' => $result->get_error_message() ),
				RunRepository::EVENT_FAILED
			);
			$this->runs->fail( (int) $run['id'], $result->get_error_message() );

			return array(
				'stop' => true,
				'next' => null,
			);
		}

		$this->runs->update_event( (int) $event['event']['id'], (array) $result['payload'], (string) $result['status'] );

		if ( AutomationRepository::KIND_EMAIL === $step['kind'] && (int) ( $run['cart_id'] ?? 0 ) > 0 && 'waiting' === (string) $result['status'] ) {
			// The job is durable but its transport outcome is not known. Keep
			// the run parked at this same step and re-read the job on a bounded
			// tick instead of claiming the Email was sent.
			$last_id = isset( $run['last_completed_step_id'] ) && (int) $run['last_completed_step_id'] > 0
				? (int) $run['last_completed_step_id']
				: null;

			$this->park_cart_email( (int) $run['id'], (int) $step['id'], $last_id );

			return array(
				'stop' => true,
				'next' => null,
			);
		}

		$next = $this->resolver->next_after( $step );

		if ( ! $next ) {
			$this->runs->complete( (int) $run['id'], (int) $step['id'] );

			return array(
				'stop' => true,
				'next' => null,
			);
		}

		if ( ! $this->runs->advance( (int) $run['id'], (int) $step['id'], $next, RunRepository::STATUS_ACTIVE, gmdate( 'Y-m-d H:i:s' ) ) ) {
			return array(
				'stop' => true,
				'next' => null,
			);
		}

		return array(
			'stop' => false,
			'next' => $next,
		);
	}

	/**
	 * Re-read the persisted cart job outcome and settle the parked step.
	 *
	 * Only an accepted handoff advances the run. Suppressed/cancelled outcomes
	 * are recorded as skipped and cancel the run; failed/unknown outcomes fail
	 * it visibly. None of them replay the Email or re-arm an unknown job.
	 *
	 * @param array<string, mixed> $automation Automation row.
	 * @param array<string, mixed> $run        Run row.
	 * @param array<string, mixed> $step       Email step.
	 * @param array<string, mixed> $contact    Contact row.
	 * @param array<string, mixed> $event      Existing step event.
	 * @return array{stop: bool, next: array<string, mixed>|null}
	 */
	private function settle_cart_email( array $automation, array $run, array $step, array $contact, array $event ): array {
		$payload = (array) ( $event['payload'] ?? array() );
		$job_id  = (int) ( $payload['job_id'] ?? 0 );
		$jobs    = new EmailJobRepository();
		$job     = $job_id > 0 ? $jobs->get( $job_id ) : null;
		$last_id = isset( $run['last_completed_step_id'] ) && (int) $run['last_completed_step_id'] > 0
			? (int) $run['last_completed_step_id']
			: null;

		if ( ! $job ) {
			// The queue row can be missing when a worker crashed before insert.
			// The step re-runs through the same frozen job key, so it cannot
			// create a second job or rotate a live recovery token.
			$result = $this->executor->execute( $automation, $step, $contact, $run );

			if ( is_wp_error( $result ) ) {
				$this->runs->update_event( (int) $event['id'], array( 'error' => $result->get_error_message() ), RunRepository::EVENT_FAILED );
				$this->runs->fail( (int) $run['id'], $result->get_error_message() );

				return array(
					'stop' => true,
					'next' => null,
				);
			}

			$this->runs->update_event( (int) $event['id'], (array) $result['payload'], (string) $result['status'] );
			$this->park_cart_email( (int) $run['id'], (int) $step['id'], $last_id );

			return array(
				'stop' => true,
				'next' => null,
			);
		}

		$status  = (string) $job['status'];
		$settled = array_merge(
			$payload,
			array(
				'job_id'     => (int) $job['id'],
				'job_key'    => (string) $job['job_key'],
				'outcome'    => $status,
				'handoff'    => $status,
				'reason'     => (string) ( $job['last_error'] ?? '' ),
				'settled_at' => gmdate( 'Y-m-d H:i:s' ),
			)
		);

		if ( EmailJobRepository::STATUS_ACCEPTED === $status ) {
			$this->runs->update_event( (int) $event['id'], $settled, RunRepository::EVENT_COMPLETED );

			$next = $this->resolver->next_after( $step );

			if ( ! $next ) {
				$this->runs->complete( (int) $run['id'], (int) $step['id'] );

				return array(
					'stop' => true,
					'next' => null,
				);
			}

			$this->runs->advance( (int) $run['id'], (int) $step['id'], $next, RunRepository::STATUS_ACTIVE, gmdate( 'Y-m-d H:i:s' ) );

			return array(
				'stop' => false,
				'next' => $next,
			);
		}

		if ( in_array( $status, array( EmailJobRepository::STATUS_QUEUED, EmailJobRepository::STATUS_SENDING ), true ) ) {
			$waits = (int) ( $payload['waits'] ?? 0 ) + 1;

			if ( $waits > 30 ) {
				$this->runs->update_event(
					(int) $event['id'],
					array_merge( $settled, array( 'outcome' => 'unknown', 'reason' => 'job_outcome_timeout' ) ),
					RunRepository::EVENT_FAILED
				);
				$this->runs->fail( (int) $run['id'], 'job_outcome_timeout' );

				return array(
					'stop' => true,
					'next' => null,
				);
			}

			$settled['waits'] = $waits;

			$this->runs->update_event( (int) $event['id'], $settled, RunRepository::EVENT_WAITING );
			$this->park_cart_email( (int) $run['id'], (int) $step['id'], $last_id );

			return array(
				'stop' => true,
				'next' => null,
			);
		}

		if ( in_array( $status, array( EmailJobRepository::STATUS_FAILED, EmailJobRepository::STATUS_UNKNOWN ), true ) ) {
			$this->runs->update_event( (int) $event['id'], $settled, RunRepository::EVENT_FAILED );
			$this->runs->fail( (int) $run['id'], (string) ( $job['last_error'] ?: 'job_' . $status ) );

			return array(
				'stop' => true,
				'next' => null,
			);
		}

		// Suppressed or cancelled: an honest terminal result, never a resend.
		$this->runs->update_event( (int) $event['id'], $settled, RunRepository::EVENT_SKIPPED );
		$this->runs->cancel( (int) $run['id'], (string) ( $job['last_error'] ?: 'job_' . $status ) );

		return array(
			'stop' => true,
			'next' => null,
		);
	}

	/**
	 * Park the run at the same cart Email step for a bounded outcome check.
	 */
	private function park_cart_email( int $run_id, int $step_id, ?int $last_id ): void {
		$step = $this->automations->step( $step_id );

		if ( ! $step ) {
			return;
		}

		$this->runs->advance(
			$run_id,
			$last_id,
			$step,
			RunRepository::STATUS_WAITING,
			gmdate( 'Y-m-d H:i:s', time() + 60 )
		);
	}

	/**
	 * Stable weighted branch for one automation and contact.
	 *
	 * The hash is a fixed integer point from 1 to 100; branch A owns every
	 * point up to and including its weight. The same automation and contact
	 * always produce the same point, so re-runs never flip sides.
	 *
	 * @param array<string, mixed> $step          Split step row.
	 * @param int                  $automation_id Automation ID.
	 * @param int                  $contact_id    Contact ID.
	 * @return array{branch: string, weight_a: int, weight_b: int, point: int}
	 */
	private function split_choice( array $step, int $automation_id, int $contact_id ): array {
		$config   = (array) ( $step['config'] ?? array() );
		$weight_a = max( 0, min( 100, (int) ( $config['weight_a'] ?? AutomationRepository::SPLIT_DEFAULT_WEIGHT ) ) );
		$hash     = (int) hexdec( substr( hash( 'sha256', 'nowcrm-split:' . $automation_id . ':' . $contact_id ), 0, 7 ) );
		$point    = ( $hash % 100 ) + 1;

		return array(
			'branch'   => $point <= $weight_a ? AutomationRepository::BRANCH_A : AutomationRepository::BRANCH_B,
			'weight_a' => $weight_a,
			'weight_b' => 100 - $weight_a,
			'point'    => $point,
		);
	}

	/**
	 * Resume parked goal runs that match one native event.
	 *
	 * The run becomes active and due immediately; the scheduler executes its
	 * next step later, preserving the hook-never-runs-actions contract.
	 *
	 * @param string               $trigger_key Native trigger key.
	 * @param array<string, mixed> $context     Event context.
	 * @return int Number of runs resumed.
	 */
	public function resume_goals( string $trigger_key, array $context ): int {
		if ( ! in_array( $trigger_key, array( TriggerRegistry::TERM_ATTACHED, TriggerRegistry::TERM_DETACHED, TriggerRegistry::CONTACT_STATUS ), true ) ) {
			return 0;
		}

		$contact_id = absint( $context['contact_id'] ?? 0 );

		if ( $contact_id <= 0 ) {
			return 0;
		}

		$resumed = 0;

		foreach ( $this->runs->parked_goals( $contact_id ) as $parked ) {
			if ( ! $this->goal_matches( (array) $parked['goal_config'], $trigger_key, $context ) ) {
				continue;
			}

			$event = $this->runs->event_for_step( (int) $parked['id'], (int) $parked['goal_step_id'] );

			// Only a waiting goal event can be satisfied; a completed or
			// timed-out one already has its outcome.
			if ( ! $event || RunRepository::EVENT_WAITING !== (string) ( $event['status'] ?? '' ) ) {
				continue;
			}

			$payload = array_merge(
				(array) ( $event['payload'] ?? array() ),
				array(
					'kind'       => AutomationRepository::KIND_GOAL,
					'event'      => (string) ( $parked['goal_config']['event'] ?? '' ),
					'satisfied'  => true,
					'resumed_by' => $trigger_key,
					'source_ref' => (string) ( $context['source_ref'] ?? '' ),
				)
			);

			// The guarded multi-table write publishes the event and wakes the
			// run together, so a concurrent deadline can only lose.
			if ( ! $this->runs->resume_goal( (int) $parked['id'], (int) $parked['goal_step_id'], $payload ) ) {
				continue;
			}

			++$resumed;
		}

		return $resumed;
	}

	/**
	 * Whether a goal config accepts one native event.
	 *
	 * @param array<string, mixed> $config      Goal config.
	 * @param string               $trigger_key Native trigger key.
	 * @param array<string, mixed> $context     Event context.
	 */
	private function goal_matches( array $config, string $trigger_key, array $context ): bool {
		$event = TriggerRegistry::CONTACT_STATUS === $trigger_key
			? AutomationRepository::GOAL_STATUS_CHANGED
			: $trigger_key;

		if ( (string) ( $config['event'] ?? '' ) !== $event ) {
			return false;
		}

		if ( AutomationRepository::GOAL_STATUS_CHANGED === $event ) {
			return in_array( (string) ( $context['new_status'] ?? '' ), (array) ( $config['statuses'] ?? array() ), true );
		}

		return in_array( (int) ( $context['term_id'] ?? 0 ), array_map( 'intval', (array) ( $config['term_ids'] ?? array() ) ), true );
	}

	/**
	 * Whether a goal is already true when the run reaches it.
	 *
	 * This is the cheap evidence query: current term links and current contact
	 * status. It uses the same "any selected" semantics as live event matching.
	 *
	 * @param array<string, mixed> $config  Goal config.
	 * @param array<string, mixed> $contact Contact row.
	 */
	private function goal_evidence( array $config, array $contact ): bool {
		$event = (string) ( $config['event'] ?? '' );

		if ( AutomationRepository::GOAL_STATUS_CHANGED === $event ) {
			return in_array( (string) $contact['status'], (array) ( $config['statuses'] ?? array() ), true );
		}

		$wanted   = array_map( 'intval', (array) ( $config['term_ids'] ?? array() ) );
		$attached = array_map(
			static fn( array $term ): int => (int) $term['id'],
			( new TermRepository() )->for_contact( (int) $contact['id'] )
		);

		if ( AutomationRepository::GOAL_TERM_ATTACHED === $event ) {
			return array() !== array_intersect( $wanted, $attached );
		}

		return array() !== array_diff( $wanted, $attached );
	}

	/**
	 * Whether the contact may receive automation actions.
	 *
	 * @param array<string, mixed> $contact Contact row.
	 * @param bool                 $force   Automation force-run setting.
	 */
	private function eligible( array $contact, bool $force ): bool {
		if ( $force ) {
			return true;
		}

		return in_array( (string) $contact['status'], array( Status::SUBSCRIBED, Status::TRANSACTIONAL ), true );
	}

	/**
	 * Serialize workers with a connection-scoped lock.
	 */
	private function acquire_lock(): ?string {
		global $wpdb;

		$key = 'nowcrm-automation-' . md5( DB_NAME . ':' . $wpdb->prefix );

		return '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $key ) )
			? $key
			: null;
	}

	/**
	 * Release the worker lock.
	 */
	private function release_lock( string $key ): void {
		global $wpdb;

		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $key ) );
	}
}
