<?php
/**
 * Native automation trigger registry.
 *
 * Business hooks fire only after a successful mutation. This class turns those
 * hooks into durable runs. Actions never execute inline here; the scheduler
 * performs them later, so a slow automation cannot break a checkout or import.
 *
 * @package NowCRM
 */

namespace NowCRM\Automation;

use NowCRM\Contacts\ContactRepository;
use NowCRM\Support\Schema;

defined( 'ABSPATH' ) || exit;

final class TriggerRegistry {

	public const CONTACT_CREATED       = 'contact_created';
	public const CONTACT_UPDATED       = 'contact_updated';
	public const CONTACT_STATUS        = 'contact_status_changed';
	public const TERM_ATTACHED         = 'term_attached';
	public const TERM_DETACHED         = 'term_detached';
	public const CSV_IMPORTED          = 'csv_imported';
	public const ORDER_COMPLETED       = 'order_completed';
	public const ORDER_REFUNDED        = 'order_refunded';
	public const ORDER_CANCELLED       = 'order_cancelled';
	public const CART_ABANDONED        = 'cart_abandoned';
	public const CONTACT_BIRTHDAY      = 'contact_birthday';
	public const WP_LOGIN              = 'wp_login';

	/**
	 * Contacts scanned per birthday query batch.
	 */
	public const BIRTHDAY_BATCH = 200;

	/**
	 * Wall-clock seconds one birthday scan may spend before yielding.
	 */
	public const BIRTHDAY_SECONDS = 20;

	/**
	 * Stable trigger keys and labels. These keys are the persistence contract.
	 *
	 * @return array<string, string>
	 */
	public static function triggers(): array {
		return array(
			self::CONTACT_CREATED   => __( '聯絡人建立', 'now-crm' ),
			self::CONTACT_UPDATED   => __( '聯絡人更新', 'now-crm' ),
			self::CONTACT_STATUS    => __( '聯絡人狀態變更', 'now-crm' ),
			self::TERM_ATTACHED     => __( '加入名單或標籤', 'now-crm' ),
			self::TERM_DETACHED     => __( '移出名單或標籤', 'now-crm' ),
			self::CSV_IMPORTED      => __( 'CSV 匯入聯絡人', 'now-crm' ),
			self::ORDER_COMPLETED   => __( '訂單完成', 'now-crm' ),
			self::ORDER_REFUNDED    => __( '訂單退款', 'now-crm' ),
			self::ORDER_CANCELLED   => __( '訂單取消', 'now-crm' ),
			self::CART_ABANDONED    => __( '購物車放棄', 'now-crm' ),
			self::CONTACT_BIRTHDAY  => __( '聯絡人生日', 'now-crm' ),
			self::WP_LOGIN          => __( '使用者登入', 'now-crm' ),
		);
	}

	/**
	 * One-line description used by the automation editor trigger card.
	 *
	 * These strings are presentation only; the persistence contract stays the
	 * trigger keys in `triggers()`.
	 */
	public static function description( string $key ): string {
		$descriptions = array(
			self::CONTACT_CREATED   => __( '聯絡人建立後立即執行。', 'now-crm' ),
			self::CONTACT_UPDATED   => __( '聯絡人資料更新後執行。', 'now-crm' ),
			self::CONTACT_STATUS    => __( '聯絡人狀態變更後執行。', 'now-crm' ),
			self::TERM_ATTACHED     => __( '聯絡人加入名單或標籤後執行。', 'now-crm' ),
			self::TERM_DETACHED     => __( '聯絡人移出名單或標籤後執行。', 'now-crm' ),
			self::CSV_IMPORTED      => __( 'CSV 匯入建立聯絡人後執行。', 'now-crm' ),
			self::ORDER_COMPLETED   => __( '訂單完成後執行。', 'now-crm' ),
			self::ORDER_REFUNDED    => __( '訂單退款後執行。', 'now-crm' ),
			self::ORDER_CANCELLED   => __( '訂單取消後執行。', 'now-crm' ),
			self::CART_ABANDONED    => __( '購物車放棄後執行。', 'now-crm' ),
			self::CONTACT_BIRTHDAY  => __( '聯絡人生日當天執行。', 'now-crm' ),
			self::WP_LOGIN          => __( '使用者登入後執行。', 'now-crm' ),
		);

		return $descriptions[ $key ] ?? '';
	}

	/**
	 * Whether a trigger key is registered.
	 */
	public static function is_registered( string $key ): bool {
		return isset( self::triggers()[ $key ] );
	}

	/**
	 * Register native hooks.
	 */
	public function register(): void {
		add_action( 'nowcrm_contact_created', array( $this, 'on_contact_created' ), 10, 2 );
		add_action( 'nowcrm_contact_updated', array( $this, 'on_contact_updated' ), 10, 2 );
		add_action( 'nowcrm_contact_status_changed', array( $this, 'on_contact_status_changed' ), 10, 5 );
		add_action( 'nowcrm_term_attached', array( $this, 'on_term_attached' ), 10, 3 );
		add_action( 'nowcrm_term_detached', array( $this, 'on_term_detached' ), 10, 3 );
		add_action( 'nowcrm_csv_contact_imported', array( $this, 'on_csv_imported' ), 10, 3 );
		add_action( 'nowcrm_order_completed', array( $this, 'on_order_completed' ), 10, 2 );
		add_action( 'nowcrm_order_refunded', array( $this, 'on_order_refunded' ), 10, 3 );
		add_action( 'nowcrm_order_cancelled', array( $this, 'on_order_cancelled' ), 10, 2 );
		add_action( 'nowcrm_cart_abandoned', array( $this, 'on_cart_abandoned' ), 10, 2 );
		add_action( 'nowcrm_cart_source_invalidated', array( 'NowCRM\\Cart\\Automation', 'on_source_invalidated' ), 10, 3 );
		add_action( 'wp_login', array( $this, 'on_wp_login' ), 10, 2 );
	}

	/**
	 * Hook: a contact row was created.
	 *
	 * @param int                  $contact_id Contact ID.
	 * @param array<string, mixed> $context    Source and status.
	 */
	public function on_contact_created( $contact_id, $context = array() ): void {
		$context = is_array( $context ) ? $context : array();

		$this->fire(
			self::CONTACT_CREATED,
			array_merge(
				$context,
				array(
					'contact_id' => (int) $contact_id,
					'source_ref' => '',
				)
			)
		);
	}

	/**
	 * Hook: a contact row changed.
	 *
	 * @param int                  $contact_id Contact ID.
	 * @param array<string, mixed> $context    Changed field keys.
	 */
	public function on_contact_updated( $contact_id, $context = array() ): void {
		$context = is_array( $context ) ? $context : array();

		if ( array() === ( $context['fields'] ?? array() ) ) {
			return;
		}

		$this->fire(
			self::CONTACT_UPDATED,
			array_merge(
				$context,
				array(
					'contact_id' => (int) $contact_id,
					'source_ref' => '',
				)
			)
		);
	}

	/**
	 * Hook: contact status transition.
	 *
	 * @param int         $contact_id Contact ID.
	 * @param string      $old_status Previous status.
	 * @param string      $new_status New status.
	 * @param string      $source     Mutation source.
	 * @param int|null    $actor_id   Acting user.
	 */
	public function on_contact_status_changed( $contact_id, $old_status = '', $new_status = '', $source = '', $actor_id = null ): void {
		$this->fire(
			self::CONTACT_STATUS,
			array(
				'contact_id' => (int) $contact_id,
				'old_status' => (string) $old_status,
				'new_status' => (string) $new_status,
				'source'     => (string) $source,
				'actor_id'   => $actor_id,
				'source_ref' => '',
			)
		);
	}

	/**
	 * Hook: a term link was inserted for the first time.
	 *
	 * @param int    $contact_id Contact ID.
	 * @param int    $term_id    Term ID.
	 * @param string $term_type  List or tag.
	 */
	public function on_term_attached( $contact_id, $term_id, $term_type = '' ): void {
		$this->fire(
			self::TERM_ATTACHED,
			array(
				'contact_id' => (int) $contact_id,
				'term_id'    => (int) $term_id,
				'term_type'  => (string) $term_type,
				'source_ref' => '',
			)
		);
	}

	/**
	 * Hook: a term link was removed.
	 *
	 * @param int    $contact_id Contact ID.
	 * @param int    $term_id    Term ID.
	 * @param string $term_type  List or tag.
	 */
	public function on_term_detached( $contact_id, $term_id, $term_type = '' ): void {
		$this->fire(
			self::TERM_DETACHED,
			array(
				'contact_id' => (int) $contact_id,
				'term_id'    => (int) $term_id,
				'term_type'  => (string) $term_type,
				'source_ref' => '',
			)
		);
	}

	/**
	 * Hook: CSV import finished writing one contact.
	 *
	 * @param int                  $contact_id Contact ID.
	 * @param string               $email      Address.
	 * @param array<string, mixed> $values     Imported values.
	 */
	public function on_csv_imported( $contact_id, $email = '', $values = array() ): void {
		$this->fire(
			self::CSV_IMPORTED,
			array(
				'contact_id' => (int) $contact_id,
				'email'      => (string) $email,
				'source'     => 'csv',
				'source_ref' => '',
			)
		);
	}

	/**
	 * Hook: order reached completed.
	 *
	 * @param int $order_id   Order ID.
	 * @param int $contact_id Contact ID.
	 */
	public function on_order_completed( $order_id, $contact_id = 0 ): void {
		$this->fire(
			self::ORDER_COMPLETED,
			array(
				'contact_id' => (int) $contact_id,
				'order_id'   => (int) $order_id,
				'source_ref' => 'order:' . (int) $order_id,
			)
		);
	}

	/**
	 * Hook: a refund was recorded.
	 *
	 * @param int $order_id   Order ID.
	 * @param int $contact_id Contact ID.
	 * @param int $refund_id  Refund ID.
	 */
	public function on_order_refunded( $order_id, $contact_id = 0, $refund_id = 0 ): void {
		$this->fire(
			self::ORDER_REFUNDED,
			array(
				'contact_id' => (int) $contact_id,
				'order_id'   => (int) $order_id,
				'refund_id'  => (int) $refund_id,
				'source_ref' => 'refund:' . (int) $order_id . ':' . (int) $refund_id,
			)
		);
	}

	/**
	 * Hook: order reached cancelled.
	 *
	 * @param int $order_id   Order ID.
	 * @param int $contact_id Contact ID.
	 */
	public function on_order_cancelled( $order_id, $contact_id = 0 ): void {
		$this->fire(
			self::ORDER_CANCELLED,
			array(
				'contact_id' => (int) $contact_id,
				'order_id'   => (int) $order_id,
				'source_ref' => 'order:' . (int) $order_id,
			)
		);
	}

	/**
	 * Hook: a cart became abandoned.
	 *
	 * @param int    $cart_id Cart ID.
	 * @param string $email   Captured email.
	 */
	public function on_cart_abandoned( $cart_id, $email = '' ): void {
		unset( $email );

		// The cart coordinator is the only producer: it selects the configured
		// owner, dedupes by immutable cart source, and fences legacy jobs.
		\NowCRM\Cart\Automation::start_for_cart( (int) $cart_id, 'cart:' . (int) $cart_id );
	}

	/**
	 * Hook: a WordPress user logged in successfully.
	 *
	 * `wp_login` only runs once authentication succeeds, so a failed attempt
	 * never reaches this handler. Users without a native contact are ignored.
	 *
	 * @param string        $user_login Login name.
	 * @param \WP_User|null $user       Authenticated user when passed.
	 */
	public function on_wp_login( $user_login = '', $user = null ): void {
		$wp_user = $user instanceof \WP_User ? $user : get_user_by( 'login', (string) $user_login );
		$user_id = $wp_user instanceof \WP_User ? (int) $wp_user->ID : 0;
		$email   = $wp_user instanceof \WP_User ? (string) $wp_user->user_email : '';

		$contact_id = $this->contact_id_for_login( $user_id, $email );

		if ( $contact_id <= 0 && is_email( (string) $user_login ) ) {
			$contact_id = $this->contact_id_for_login( 0, (string) $user_login );
		}

		if ( $contact_id <= 0 ) {
			return;
		}

		$this->fire(
			self::WP_LOGIN,
			array(
				'contact_id' => $contact_id,
				'wp_user_id' => $user_id,
				'email'      => $email,
				'source_ref' => '',
			)
		);
	}

	/**
	 * Native contact for a WordPress user, matched by user ID then email.
	 *
	 * @param int    $user_id WordPress user ID.
	 * @param string $email   Account email.
	 */
	private function contact_id_for_login( int $user_id, string $email ): int {
		global $wpdb;

		if ( $user_id > 0 ) {
			$table = Schema::table( 'contacts' );

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is trusted.
			$id = $wpdb->get_var(
				$wpdb->prepare( "SELECT id FROM {$table} WHERE wp_user_id = %d ORDER BY id ASC LIMIT 1", $user_id )
			);

			if ( $id ) {
				return (int) $id;
			}
		}

		if ( '' === $email ) {
			return 0;
		}

		$contact = ( new ContactRepository() )->find_by_email( $email );

		return $contact ? (int) $contact['id'] : 0;
	}

	/**
	 * Fire birthday automations for every contact whose month/day is today.
	 *
	 * The scan is keyset-batched and time-bounded, so a large list cannot run
	 * past a request. Runs are keyed by the site-local date, so one contact
	 * fires at most once per day even without run_once.
	 *
	 * @param string $local_date Site-local Y-m-d; defaults to today.
	 * @return int Number of runs started or restarted.
	 */
	public function fire_birthdays( string $local_date = '' ): int {
		if ( array() === ( new AutomationRepository() )->active_by_trigger( self::CONTACT_BIRTHDAY ) ) {
			return 0;
		}

		$date = self::site_date( $local_date );
		$bits = array_map( 'intval', explode( '-', $date ) );
		$ref  = 'birthday:' . $date;
		$last = 0;
		$fired = 0;
		$start = microtime( true );

		do {
			$ids = $this->birthday_contact_ids( $bits[1] ?? 0, $bits[2] ?? 0, $last, self::BIRTHDAY_BATCH );

			foreach ( $ids as $contact_id ) {
				$last = $contact_id;

				$fired += $this->fire(
					self::CONTACT_BIRTHDAY,
					array(
						'contact_id' => $contact_id,
						'local_date' => $date,
						'source_ref' => $ref,
					)
				);
			}

			if ( count( $ids ) < self::BIRTHDAY_BATCH ) {
				break;
			}
		} while ( microtime( true ) - $start < self::BIRTHDAY_SECONDS );

		return $fired;
	}

	/**
	 * Today's date in the site timezone, or a validated caller override.
	 *
	 * Deriving the date here instead of from gmdate() keeps the scan on the
	 * calendar day the site owner sees.
	 *
	 * @param string $local_date Candidate date.
	 */
	private static function site_date( string $local_date ): string {
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $local_date ) ) {
			return $local_date;
		}

		return (string) wp_date( 'Y-m-d' );
	}

	/**
	 * One keyset batch of contacts whose birth month/day matches.
	 *
	 * @param int $month    Month number.
	 * @param int $day      Day of month.
	 * @param int $after_id Only IDs greater than this.
	 * @param int $limit    Batch size.
	 * @return int[]
	 */
	private function birthday_contact_ids( int $month, int $day, int $after_id, int $limit ): array {
		global $wpdb;

		if ( $month < 1 || $day < 1 ) {
			return array();
		}

		$table = Schema::table( 'contacts' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is trusted.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE date_of_birth IS NOT NULL AND MONTH(date_of_birth) = %d AND DAYOFMONTH(date_of_birth) = %d AND id > %d ORDER BY id ASC LIMIT %d",
				$month,
				$day,
				$after_id,
				max( 1, $limit )
			)
		);

		return array_map( 'absint', (array) $ids );
	}

	/**
	 * Fan one event out to matching active automations.
	 *
	 * @param string               $trigger_key Trigger key.
	 * @param array<string, mixed> $context     Event context.
	 * @return int Number of runs started or restarted.
	 */
	public function fire( string $trigger_key, array $context ): int {
		if ( ! self::is_registered( $trigger_key ) ) {
			return 0;
		}

		// Cart abandonment has exactly one producer. A generic fire must never
		// dispatch every matching automation or bypass the source coordinator.
		if ( self::CART_ABANDONED === $trigger_key ) {
			return 0;
		}

		$contacts   = new ContactRepository();
		$contact_id = absint( $context['contact_id'] ?? 0 );

		if ( $contact_id <= 0 && ! empty( $context['email'] ) ) {
			$contact = $contacts->find_by_email( (string) $context['email'] );
			$contact_id = $contact ? (int) $contact['id'] : 0;
		}

		if ( $contact_id <= 0 ) {
			return 0;
		}

		$contact = $contacts->get( $contact_id );

		if ( ! $contact ) {
			return 0;
		}

		$automations = ( new AutomationRepository() )->active_by_trigger( $trigger_key );
		$engine      = new Engine();

		// Goal resumes must run even when no automation uses this trigger key.
		$engine->resume_goals( $trigger_key, array_merge( $context, array( 'contact_id' => $contact_id ) ) );

		if ( array() === $automations ) {
			return 0;
		}

		$count = 0;

		foreach ( $automations as $automation ) {
			if ( ! $this->matches_config( (array) $automation['trigger_config'], $context, $contact_id ) ) {
				continue;
			}

			$result = $engine->start(
				$automation,
				$contact,
				$trigger_key,
				(string) ( $context['source_ref'] ?? '' )
			);

			if ( ! is_wp_error( $result ) && ! empty( $result['started'] ) ) {
				++$count;
			}
		}

		return $count;
	}

	/**
	 * Whether a trigger filter accepts this event.
	 *
	 * @param array<string, mixed> $config     Trigger config.
	 * @param array<string, mixed> $context    Event context.
	 * @param int                  $contact_id Contact ID.
	 */
	private function matches_config( array $config, array $context, int $contact_id ): bool {
		if ( array() === $config ) {
			return true;
		}

		$statuses = (array) ( $config['statuses'] ?? array() );

		if ( array() !== $statuses ) {
			$status = sanitize_key( (string) ( $context['new_status'] ?? $context['status'] ?? '' ) );

			if ( ! in_array( $status, $statuses, true ) ) {
				return false;
			}
		}

		$sources = (array) ( $config['sources'] ?? array() );

		if ( array() !== $sources ) {
			$source = mb_strtolower( (string) ( $context['source'] ?? '' ), 'UTF-8' );

			if ( ! in_array( $source, array_map( static fn( $value ) => mb_strtolower( (string) $value, 'UTF-8' ), $sources ), true ) ) {
				return false;
			}
		}

		$term_ids = array_map( 'intval', (array) ( $config['term_ids'] ?? array() ) );

		if ( array() !== $term_ids && ! in_array( (int) ( $context['term_id'] ?? 0 ), $term_ids, true ) ) {
			return false;
		}

		$term_types = (array) ( $config['term_types'] ?? array() );

		if ( array() !== $term_types && ! in_array( (string) ( $context['term_type'] ?? '' ), $term_types, true ) ) {
			return false;
		}

		if ( ! empty( $config['conditions'] ) ) {
			$matches = ConditionEvaluator::matches( (array) $config['conditions'], $contact_id );

			if ( is_wp_error( $matches ) || ! $matches ) {
				return false;
			}
		}

		return true;
	}
}
