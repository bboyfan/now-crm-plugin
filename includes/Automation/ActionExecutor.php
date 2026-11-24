<?php
/**
 * Executes one automation step.
 *
 * Every action goes through the existing repositories. Email steps only insert
 * a durable queue row; the mailer sends later. Nothing calls wp_mail here.
 *
 * @package NowCRM
 */

namespace NowCRM\Automation;

use NowCRM\Companies\CompanyRepository;
use NowCRM\Cart\Automation as CartAutomation;
use NowCRM\Cart\CartRepository;
use NowCRM\Cart\Reminder;
use NowCRM\Contacts\ContactRepository;
use NowCRM\Contacts\Status;
use NowCRM\Email\EmailJobRepository;
use NowCRM\Email\Queue;
use NowCRM\Email\SuppressionRepository;
use NowCRM\Sequences\Engine as SequenceEngine;
use NowCRM\Terms\TermRepository;
use WP_Error;

defined( 'ABSPATH' ) || exit;

final class ActionExecutor {

	private ContactRepository $contacts;
	private TermRepository $terms;
	private CompanyRepository $companies;
	private EmailJobRepository $jobs;

	public function __construct() {
		$this->contacts  = new ContactRepository();
		$this->terms     = new TermRepository();
		$this->companies = new CompanyRepository();
		$this->jobs      = new EmailJobRepository();
	}

	/**
	 * Apply one action or email step.
	 *
	 * @param array<string, mixed> $automation Automation row.
	 * @param array<string, mixed> $step       Step row.
	 * @param array<string, mixed> $contact    Contact row.
	 * @param array<string, mixed> $run        Run row.
	 * @return array{status: string, payload: array<string, mixed>}|WP_Error
	 */
	public function execute( array $automation, array $step, array $contact, array $run ) {
		if ( AutomationRepository::KIND_EMAIL === $step['kind'] ) {
			return $this->send_email( $automation, $step, $contact, $run );
		}

		return $this->action( $step, $contact );
	}

	/**
	 * Run one non-email action.
	 *
	 * @param array<string, mixed> $step    Step row.
	 * @param array<string, mixed> $contact Contact row.
	 * @return array{status: string, payload: array<string, mixed>}|WP_Error
	 */
	private function action( array $step, array $contact ) {
		$config = (array) $step['config'];
		$key    = (string) $step['action_key'];
		$id     = (int) $contact['id'];

		if ( in_array( $key, array( ActionRegistry::APPLY_LIST, ActionRegistry::REMOVE_LIST, ActionRegistry::APPLY_TAG, ActionRegistry::REMOVE_TAG ), true ) ) {
			$ids       = array_map( 'intval', (array) ( $config['term_ids'] ?? array() ) );
			$attach    = in_array( $key, array( ActionRegistry::APPLY_LIST, ActionRegistry::APPLY_TAG ), true );
			$term_type = ActionRegistry::term_type( $key );

			foreach ( $ids as $term_id ) {
				$term = $this->terms->get( $term_id );

				if ( ! $term || $term_type !== $term['type'] ) {
					return new WP_Error( 'nowcrm_automation_term_missing', __( '名單或標籤已不存在。', 'now-crm' ) );
				}

				$attach ? $this->terms->attach( $id, $term_id ) : $this->terms->detach( $id, $term_id );
			}

			return array(
				'status'  => 'completed',
				'payload' => array(
					'operation' => $key,
					'term_ids'  => $ids,
				),
			);
		}

		if ( ActionRegistry::SET_STATUS === $key ) {
			$status = (string) ( $config['status'] ?? '' );
			$result = $this->contacts->set_status(
				$id,
				$status,
				'automation:' . (int) $step['automation_id'],
				/* translators: %d: automation step ID. */
				sprintf( __( '自動化步驟 #%d', 'now-crm' ), (int) $step['id'] )
			);

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			return array(
				'status'  => 'completed',
				'payload' => array( 'status' => $status ),
			);
		}

		if ( ActionRegistry::ADD_TO_SEQUENCE === $key ) {
			$sequence_id = (int) ( $config['sequence_id'] ?? 0 );
			$result      = ( new SequenceEngine() )->enroll( $sequence_id, array( $id ) );

			if ( is_wp_error( $result ) ) {
				if ( 'nowcrm_sequence_inactive' === $result->get_error_code() ) {
					return array(
						'status'  => 'skipped',
						'payload' => array(
							'reason'      => 'sequence_inactive',
							'sequence_id' => $sequence_id,
						),
					);
				}

				return $result;
			}

			if ( (int) $result['enrolled'] > 0 ) {
				return array(
					'status'  => 'completed',
					'payload' => array(
						'sequence_id' => $sequence_id,
						'queued'      => (int) $result['queued'],
					),
				);
			}

			if ( (int) $result['failed'] > 0 ) {
				return new WP_Error( 'nowcrm_automation_sequence_failed', __( '加入序列失敗。', 'now-crm' ) );
			}

			$reason = 'enrollment_failed';

			foreach ( array(
				'duplicates'             => 'duplicate_enrollment',
				'skipped_suppressed'     => 'suppressed_address',
				'skipped_not_subscribed' => 'not_subscribed',
				'skipped_missing'        => 'contact_missing',
			) as $counter => $code ) {
				if ( (int) ( $result[ $counter ] ?? 0 ) > 0 ) {
					$reason = $code;
					break;
				}
			}

			return array(
				'status'  => 'skipped',
				'payload' => array(
					'reason'      => $reason,
					'sequence_id' => $sequence_id,
				),
			);
		}

		$company_id = (int) ( $config['company_id'] ?? 0 );
		if ( ActionRegistry::APPLY_COMPANY === $key ) {
			$result = $this->companies->attach_contacts( $company_id, array( $id ) );
		} else {
			$result = $this->companies->detach_contacts( $company_id, array( $id ) );
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'status'  => 'completed',
			'payload' => array(
				'operation'  => $key,
				'company_id' => $company_id,
			),
		);
	}

	/**
	 * Freeze one cart Email into the existing queue.
	 *
	 * The job carries the immutable cart source, run and step links. The
	 * recovery URL and template are frozen in the payload, so a replayed step
	 * or a crash before handoff never rotates a live token. The run stays
	 * parked until the queue reports the actual transport outcome.
	 *
	 * @param array<string, mixed> $automation Automation row.
	 * @param array<string, mixed> $step       Email step.
	 * @param array<string, mixed> $contact    Contact row.
	 * @param array<string, mixed> $run        Run row.
	 * @return array{status: string, payload: array<string, mixed>}|WP_Error
	 */
	private function send_cart_email( array $automation, array $step, array $contact, array $run ) {
		if ( ! Status::allows_cart_email( (string) $contact['status'] ) ) {
			return array( 'status' => 'skipped', 'payload' => array( 'reason' => 'not_subscribed', 'outcome' => 'suppressed' ) );
		}

		$email = (string) $contact['email'];

		if ( ( new SuppressionRepository() )->is_suppressed( $email ) ) {
			return array( 'status' => 'skipped', 'payload' => array( 'reason' => 'suppressed_address', 'outcome' => 'suppressed' ) );
		}

		$cart_id = (int) $run['cart_id'];
		$version = (int) ( $run['cart_version'] ?? 0 );
		$cart    = ( new CartRepository() )->get( $cart_id );

		if ( ! $cart || CartRepository::STATUS_ABANDONED !== (string) $cart['status'] || (int) $cart['version'] !== $version ) {
			return array( 'status' => 'skipped', 'payload' => array( 'reason' => CartAutomation::REASON_CART_VERSION_CHANGED, 'outcome' => 'suppressed' ) );
		}

		if ( mb_strtolower( trim( $email ) ) !== (string) $cart['email_normalized'] ) {
			return array( 'status' => 'skipped', 'payload' => array( 'reason' => CartAutomation::REASON_RECIPIENT_MISMATCH, 'outcome' => 'suppressed' ) );
		}

		$source  = 'cart:' . $cart_id . ':' . $version;
		$job_key = sprintf( 'cart:%d:v%d:run:%d:step:%d', $cart_id, $version, (int) $run['id'], (int) $step['id'] );
		$existing = $this->jobs->get_by_key( $job_key );

		if ( $existing ) {
			return array(
				'status'  => 'waiting',
				'payload' => array(
					'job_id'      => (int) $existing['id'],
					'job_key'     => $job_key,
					'outcome'     => 'queued',
					'handoff'     => 'pending',
					'source_key'  => $source,
					'cart_version' => $version,
				),
			);
		}

		$recovery_url = (string) $this->jobs->recovery_url_for_source( $source );

		if ( '' === $recovery_url ) {
			$raw     = bin2hex( random_bytes( 32 ) );
			$expires = gmdate( 'Y-m-d H:i:s', time() + max( 1, \NowCRM\Support\Settings::int( 'cart_recovery_days' ) ) * DAY_IN_SECONDS );

			( new CartRepository() )->set_recovery_token( $cart_id, hash( 'sha256', $raw ), $expires );

			$recovery_url = add_query_arg(
				array(
					'nowcrm_recover' => '1',
					'token'          => rawurlencode( $raw ),
				),
				home_url( '/' )
			);
		}

		$config = (array) $step['config'];

		$template = Reminder::render_cart_template( $config, $cart, $recovery_url );

		$available = (string) ( $cart['reminder_due_at'] ?? '' );
		$available = '' !== $available && strtotime( $available . ' UTC' ) > time()
			? $available
			: gmdate( 'Y-m-d H:i:s' );

		$job_id = $this->jobs->enqueue(
			array(
				'job_key'        => $job_key,
				'kind'           => 'automation',
				'cart_id'        => $cart_id,
				'run_id'         => (int) $run['id'],
				'source_key'     => $source,
				'contact_id'     => (int) $contact['id'],
				'recipient'      => $email,
				'recipient_name' => trim( (string) $contact['first_name'] . ' ' . (string) $contact['last_name'] ),
				'subject'        => (string) $template['subject'],
				'payload'        => array(
					'template'      => $template,
					'automation_id' => (int) $automation['id'],
					'run_id'        => (int) $run['id'],
					'step_id'       => (int) $step['id'],
					'cart_id'       => $cart_id,
					'cart_version'  => $version,
					'entry_key'     => $source,
					'source_key'    => $source,
					'recovery_url'  => $recovery_url,
					'order_total'   => (string) $cart['total'],
				),
				'available_at'   => $available,
				'priority'       => 5,
			)
		);

		if ( is_wp_error( $job_id ) ) {
			return $job_id;
		}

		Queue::schedule( 0 );

		return array(
			'status'  => 'waiting',
			'payload' => array(
				'job_id'        => (int) $job_id,
				'job_key'       => $job_key,
				'outcome'       => 'queued',
				'handoff'       => 'pending',
				'source_key'    => $source,
				'cart_version'  => $version,
			),
		);
	}

	/**
	 * Freeze one email into the existing queue.
	 *
	 * @param array<string, mixed> $automation Automation row.
	 * @param array<string, mixed> $step       Email step.
	 * @param array<string, mixed> $contact    Contact row.
	 * @param array<string, mixed> $run        Run row.
	 * @return array{status: string, payload: array<string, mixed>}|WP_Error
	 */
	private function send_email( array $automation, array $step, array $contact, array $run ) {
		if ( (int) ( $run['cart_id'] ?? 0 ) > 0 ) {
			return $this->send_cart_email( $automation, $step, $contact, $run );
		}

		$email = (string) $contact['email'];

		// The queue repeats these gates at send time. Skipping here avoids a job
		// that can never be delivered.
		if ( Status::SUBSCRIBED !== $contact['status'] ) {
			return array(
				'status'  => 'skipped',
				'payload' => array( 'reason' => 'not_subscribed' ),
			);
		}

		if ( ( new SuppressionRepository() )->is_suppressed( $email ) ) {
			return array(
				'status'  => 'skipped',
				'payload' => array( 'reason' => 'suppressed_address' ),
			);
		}

		$config = (array) $step['config'];

		$template = array(
			'mode'      => (string) ( $config['mode'] ?? 'html' ),
			'content'   => (string) ( $config['content'] ?? '' ),
			'subject'   => (string) ( $config['subject'] ?? '' ),
			'preheader' => (string) ( $config['preheader'] ?? '' ),
		);

		foreach ( array( 'from_name', 'from_address', 'reply_to', 'footer_text' ) as $key ) {
			if ( ! empty( $config[ $key ] ) ) {
				$template[ $key ] = (string) $config[ $key ];
			}
		}

		$job_key = sprintf(
			'automation:%d:run:%d:start:%s:step:%d',
			(int) $automation['id'],
			(int) $run['id'],
			substr( md5( (string) $run['started_at'] ), 0, 12 ),
			(int) $step['id']
		);

		$job_id = $this->jobs->enqueue(
			array(
				'job_key'      => $job_key,
				'kind'         => 'automation',
				'contact_id'   => (int) $contact['id'],
				'recipient'    => $email,
				'recipient_name' => trim( (string) $contact['first_name'] . ' ' . (string) $contact['last_name'] ),
				'subject'      => (string) ( $config['subject'] ?? '' ),
				'payload'      => array(
					'template'      => $template,
					'automation_id' => (int) $automation['id'],
					'run_id'        => (int) $run['id'],
					'step_id'       => (int) $step['id'],
				),
				'available_at' => gmdate( 'Y-m-d H:i:s' ),
			)
		);

		if ( is_wp_error( $job_id ) ) {
			return $job_id;
		}

		Queue::schedule( 0 );

		return array(
			'status'  => 'completed',
			'payload' => array(
				'job_id'  => (int) $job_id,
				'job_key' => $job_key,
			),
		);
	}
}
