<?php
/**
 * Review, confirmation and delivery transitions for campaign broadcasts.
 *
 * @package NowCRM
 */

namespace NowCRM\Campaign;

use NowCRM\Email\Compliance;
use NowCRM\Email\EmailJobRepository;
use NowCRM\Email\Queue;
use NowCRM\Support\Schema;
use NowCRM\Support\Settings;

defined( 'ABSPATH' ) || exit;

final class Delivery {

	private EmailJobRepository $jobs;

	public function __construct() {
		$this->jobs = new EmailJobRepository();
	}

	public function review( \WP_Post $post, string $revision ) {
		$draft = Campaigns::draft( $post );
		if ( $revision !== Campaigns::revision( $draft ) ) {
			return Campaigns::error( 'draft_changed', __( '草稿已變更。請重新整理後再編輯。', 'now-crm' ), 409 );
		}
		$snapshot = Campaigns::snapshot( $post->ID, $draft );
		$ab = Campaigns::ab_state( $draft );
		if ( $ab['enabled'] && '' === trim( $ab['subject_b'] ) ) {
			return Campaigns::ab_error();
		}
		$audience = Audience::validate( $draft['audience'] );
		if ( is_wp_error( $audience ) ) {
			return $audience;
		}
		$rows = Audience::recipients( $audience );
		if ( null === $rows ) {
			return Campaigns::storage_error();
		}

		return array(
			'count'    => count( $rows ),
			'token'    => $this->token( $post->ID, $draft, $snapshot, $rows ),
			'sender'   => $snapshot['from_name'] . ( $snapshot['from_address'] ? ' <' . $snapshot['from_address'] . '>' : '' ),
			'audience' => Audience::describe( $audience ),
			'ready'    => self::has_content( $snapshot ) && ! empty( $rows ),
			'sample'   => array_column( array_slice( $rows, 0, 10 ), 'recipient' ),
			'snapshot' => $snapshot,
		);
	}

	/**
	 * Per-variant broadcast engagement from the frozen snapshot and its jobs.
	 *
	 * Empty when the campaign has no A/B subjects. Variant tags are written to
	 * the job payload at enqueue time, so the split never needs recomputing.
	 *
	 * @return array<string, array{subject: string, accepted: int, opened: int, clicked: int, unsubscribed: int}>
	 */
	public function variant_stats( int $id ): array {
		$snapshot = Campaigns::template( $id );
		$ab = is_array( $snapshot['ab'] ?? null ) ? $snapshot['ab'] : array();

		if ( empty( $ab['enabled'] ) ) {
			return array();
		}

		global $wpdb;

		$jobs = Schema::table( 'email_jobs' );
		$events = Schema::table( 'email_events' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders -- trusted table names, values use placeholders.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT variant,
					COUNT(DISTINCT jobs.id) AS accepted,
					COUNT(DISTINCT CASE WHEN events.type = %s THEN events.job_id END) AS opened,
					COUNT(DISTINCT CASE WHEN events.type = %s THEN events.job_id END) AS clicked,
					COUNT(DISTINCT CASE WHEN events.type = %s THEN events.job_id END) AS unsubscribed
				FROM (
					SELECT id, CASE WHEN JSON_UNQUOTE(JSON_EXTRACT(payload, '$.ab')) = 'b' THEN 'b' ELSE 'a' END AS variant
					FROM {$jobs} WHERE campaign_id = %d AND kind = 'campaign' AND status = %s
				) jobs
				LEFT JOIN {$events} events ON events.job_id = jobs.id AND events.type IN (%s, %s, %s)
				GROUP BY variant",
				array( 'open', 'click', 'unsubscribe', $id, EmailJobRepository::STATUS_ACCEPTED, 'open', 'click', 'unsubscribe' )
			),
			ARRAY_A
		);

		$stats = array(
			'a' => array(
				'subject'      => (string) ( $snapshot['subject'] ?? '' ),
				'accepted'     => 0,
				'opened'       => 0,
				'clicked'      => 0,
				'unsubscribed' => 0,
			),
			'b' => array(
				'subject'      => (string) ( $ab['subject_b'] ?? '' ),
				'accepted'     => 0,
				'opened'       => 0,
				'clicked'      => 0,
				'unsubscribed' => 0,
			),
		);

		foreach ( (array) $rows as $row ) {
			$variant = 'b' === ( $row['variant'] ?? '' ) ? 'b' : 'a';
			$stats[ $variant ]['accepted']     = (int) $row['accepted'];
			$stats[ $variant ]['opened']       = (int) $row['opened'];
			$stats[ $variant ]['clicked']      = (int) $row['clicked'];
			$stats[ $variant ]['unsubscribed'] = (int) $row['unsubscribed'];
		}

		return $stats;
	}

	public function confirm( int $id, string $token, string $local_date, string $local_end = '' ) {
		if ( ! Settings::bool( 'email_sending_enabled' ) ) {
			return self::sending_disabled();
		}
		$when = '' === $local_date ? null : self::schedule_time( $local_date );
		$end = '' === $local_end ? null : self::schedule_time( $local_end );
		if ( '' !== $local_end && ( ! $when || ! $end || $end <= $when ) ) {
			return Campaigns::error( 'invalid_schedule_range', __( '排程結束時間須晚於開始時間。', 'now-crm' ) );
		}
		if ( '' !== $local_date && ! $when ) {
			return Campaigns::error( 'invalid_schedule', __( '請選擇未來的寄送時間。', 'now-crm' ) );
		}

		$result = Campaigns::transaction(
			$id,
			function ( \WP_Post $post ) use ( $id, $token, $when, $end ) {
				if ( Campaigns::STATUS_DRAFT !== Campaigns::state( $id ) ) {
					return self::invalid_transition();
				}
				if ( $when && $when <= time() ) {
					return Campaigns::error( 'invalid_schedule', __( '請選擇未來的寄送時間。', 'now-crm' ) );
				}

				$draft = Campaigns::draft( $post );
				$snapshot = Campaigns::snapshot( $id, $draft );
				$ab = Campaigns::ab_state( $draft );
				if ( $ab['enabled'] && '' === trim( $ab['subject_b'] ) ) {
					return Campaigns::ab_error();
				}
				if ( ! self::has_content( $snapshot ) ) {
					return Campaigns::error( 'missing_content', __( '請填寫郵件主旨和正文。', 'now-crm' ) );
				}
				$compliance = Compliance::validate( Compliance::resolve( $snapshot['footer_text'] ?? '' ), $snapshot['content'] ?? '', false );
				if ( is_wp_error( $compliance ) ) {
					return Campaigns::error( 'compliance_missing_link', $compliance->get_error_message(), 422 );
				}
				$audience = Audience::validate( $draft['audience'] );
				if ( is_wp_error( $audience ) ) {
					return $audience;
				}

				$due = gmdate( 'Y-m-d H:i:s', $when ?? time() );
				$count = $this->jobs->enqueue_campaign( $id, $audience, $snapshot, $due, $end ? $end - $when + 1 : 1 );
				if ( false === $count ) {
					return Campaigns::storage_error();
				}
				$rows = $this->jobs->campaign_recipients( $id );
				if ( ! $count || null === $rows || ! hash_equals( $this->token( $id, $draft, $snapshot, $rows ), $token ) ) {
					return Campaigns::error( 'review_changed', __( '郵件或收件人已變更。請重新檢查後再確認。', 'now-crm' ), 409 );
				}

				$snapshot['audience'] = $audience;
				Campaigns::write_meta( $id, Campaigns::META_SNAPSHOT, (string) wp_json_encode( $snapshot ) );
				Campaigns::write_meta( $id, Campaigns::META_SCHEDULE, $when ? $due : null );
				Campaigns::write_meta( $id, Campaigns::META_SCHEDULE_END, $end ? gmdate( 'Y-m-d H:i:s', $end ) : null );
				Campaigns::write_meta( $id, Campaigns::META_STATUS, $when ? Campaigns::STATUS_SCHEDULED : Campaigns::STATUS_SENDING );
				return true;
			}
		);

		if ( ! is_wp_error( $result ) ) {
			Queue::schedule( $when ? max( 0, $when - time() ) : 0 );
		}
		return $result;
	}

	public function transition( int $id, string $operation ) {
		$result = Campaigns::transaction(
			$id,
			function () use ( $id, $operation ) {
				$state = Campaigns::state( $id );
				$targets = array(
					'unschedule' => array( Campaigns::STATUS_SCHEDULED => Campaigns::STATUS_DRAFT ),
					'pause'      => array( Campaigns::STATUS_SENDING => Campaigns::STATUS_PAUSED ),
					'resume'     => array( Campaigns::STATUS_PAUSED => Campaigns::STATUS_SENDING ),
					'cancel'     => array(
						Campaigns::STATUS_SCHEDULED => Campaigns::STATUS_CANCELLED,
						Campaigns::STATUS_PAUSED => Campaigns::STATUS_CANCELLED,
					),
				);
				$target = $targets[ $operation ][ $state ] ?? null;
				if ( ! $target ) {
					return self::invalid_transition();
				}

				if ( 'unschedule' === $operation ) {
					if ( ! $this->jobs->unschedule_campaign( $id ) ) {
						return Campaigns::storage_error();
					}
					Campaigns::write_meta( $id, Campaigns::META_SCHEDULE, null );
					Campaigns::write_meta( $id, Campaigns::META_SCHEDULE_END, null );
					Campaigns::write_meta( $id, Campaigns::META_SNAPSHOT, null );
				} elseif ( 'cancel' === $operation && false === $this->jobs->cancel_campaign( $id, 'campaign_cancelled' ) ) {
					return Campaigns::storage_error();
				}

				Campaigns::write_meta( $id, Campaigns::META_STATUS, $target );
				return true;
			}
		);

		if ( ! is_wp_error( $result ) && 'resume' === $operation ) {
			Queue::schedule();
		}
		return $result;
	}

	/** Promote due campaigns and close runs whose jobs all reached a terminal state. */
	public function advance(): void {
		global $wpdb;

		$jobs = Schema::table( 'email_jobs' );
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT state.post_id
				FROM {$wpdb->postmeta} state
				INNER JOIN {$wpdb->posts} post ON post.ID = state.post_id
				WHERE post.post_type = %s AND state.meta_key = %s AND (
					(state.meta_value = %s AND EXISTS (
						SELECT 1 FROM {$wpdb->postmeta} due
						WHERE due.post_id = state.post_id AND due.meta_key = %s AND due.meta_value <= %s
					)) OR (state.meta_value IN (%s, %s) AND NOT EXISTS (
						SELECT 1 FROM {$jobs} job
						WHERE job.campaign_id = state.post_id AND job.kind = 'campaign' AND job.status IN ('queued', 'sending')
					))
				)
				ORDER BY state.post_id LIMIT 50",
				Campaigns::POST_TYPE, Campaigns::META_STATUS,
				Campaigns::STATUS_SCHEDULED, Campaigns::META_SCHEDULE, gmdate( 'Y-m-d H:i:s' ), Campaigns::STATUS_SENDING, Campaigns::STATUS_PAUSED
			)
		);

		foreach ( $ids as $id ) {
			$id = (int) $id;
			Campaigns::transaction(
				$id,
				function () use ( $id ) {
					$state = Campaigns::state( $id );
					if ( Campaigns::STATUS_SCHEDULED === $state ) {
						$due = (string) get_post_meta( $id, Campaigns::META_SCHEDULE, true );
						if ( '' !== $due && $due <= gmdate( 'Y-m-d H:i:s' ) ) {
							Campaigns::write_meta( $id, Campaigns::META_STATUS, Campaigns::STATUS_SENDING );
						}
					} elseif ( in_array( $state, array( Campaigns::STATUS_SENDING, Campaigns::STATUS_PAUSED ), true ) ) {
						$stats = $this->jobs->campaign_stats( $id );
						if ( $stats['total'] > 0 && 0 === $stats['queued'] && 0 === $stats['sending'] ) {
							Campaigns::write_meta( $id, Campaigns::META_SENT_AT, gmdate( 'Y-m-d H:i:s' ) );
							Campaigns::write_meta( $id, Campaigns::META_STATUS, Campaigns::STATUS_SENT );
						}
					}
					return true;
				}
			);
		}
	}

	public function test( \WP_Post $post, string $email ) {
		if ( ! Settings::bool( 'email_sending_enabled' ) ) {
			return self::sending_disabled();
		}
		$snapshot = Campaigns::STATUS_DRAFT === Campaigns::state( $post->ID )
			? Campaigns::snapshot( $post->ID, Campaigns::draft( $post ) )
			: Campaigns::template( $post->ID );
		if ( ! is_email( $email ) || ! $snapshot || ! self::has_content( $snapshot ) ) {
			return Campaigns::error( 'invalid_test', __( '請填寫主旨、正文和有效的測試收件地址。', 'now-crm' ) );
		}

		$snapshot['subject'] = '[測試] ' . $snapshot['subject'];
		$result = $this->jobs->enqueue(
			array(
				'job_key'     => 'test:' . wp_generate_uuid4(),
				'kind'        => 'test',
				'campaign_id' => $post->ID,
				'recipient'   => $email,
				'subject'     => $snapshot['subject'],
				'payload'     => array( 'template' => $snapshot ),
				'priority'    => 1,
			)
		);
		if ( ! is_wp_error( $result ) ) {
			Queue::schedule();
		}
		return $result;
	}

	private function token( int $id, array $draft, array $snapshot, array $rows ): string {
		$hash = hash_init( 'sha256' );
		hash_update( $hash, $id . ':' . Campaigns::revision( $draft ) . ':' . Campaigns::revision( $snapshot ) );
		foreach ( $rows as $row ) {
			hash_update( $hash, "\n" . $row['contact_id'] . ':' . $row['recipient'] );
		}
		return wp_hash( hash_final( $hash ) );
	}

	private static function has_content( array $snapshot ): bool {
		$html = $snapshot['content'];
		return '' !== trim( $snapshot['subject'] ) && (
			1 === preg_match( '/[^\s\p{Z}]/u', html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) ||
			1 === preg_match( '/<img\b[^>]+\bsrc\s*=/i', $html )
		);
	}

	private static function schedule_time( string $local ): ?int {
		$date = \DateTimeImmutable::createFromFormat( '!Y-m-d\TH:i', $local, wp_timezone() );
		if ( ! $date || $local !== $date->format( 'Y-m-d\TH:i' ) || $date->getTimestamp() <= time() ) {
			return null;
		}
		return $date->getTimestamp();
	}

	private static function invalid_transition(): \WP_Error {
		return Campaigns::error( 'campaign_state', __( '電子報狀態已變更，請重新整理。', 'now-crm' ), 409 );
	}

	private static function sending_disabled(): \WP_Error {
		return Campaigns::error( 'sending_disabled', __( '郵件寄送已關閉。請先到 NOW CRM 設定開啟寄送。', 'now-crm' ) );
	}
}
