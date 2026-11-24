<?php
/**
 * One-time upgrade of campaign records from the previous workspace.
 *
 * @package NowCRM
 */

namespace NowCRM\Campaign;

use NowCRM\Email\EmailJobRepository;

defined( 'ABSPATH' ) || exit;

final class Migration {

	public static function run(): bool {
		global $wpdb;

		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status NOT IN ('trash', 'auto-draft')", Campaigns::POST_TYPE ) );
		if ( $wpdb->last_error ) {
			return false;
		}

		foreach ( $ids as $id ) {
			$id = (int) $id;
			$result = Campaigns::transaction(
				$id,
				static function () use ( $id ) {
					$raw = json_decode( (string) get_post_meta( $id, Campaigns::META_AUDIENCE, true ), true );
					if ( is_array( $raw ) && ! isset( $raw['scope'] ) ) {
						$audience = Audience::stored( $raw );
						$type = $raw['type'] ?? null;
						if ( in_array( $type, array( 'lists', 'tags' ), true ) ) {
							foreach ( (array) ( $raw['term_ids'] ?? array() ) as $term ) {
								$audience['include'][] = array(
									'list' => 'lists' === $type ? absint( $term ) : 0,
									'tag'  => 'tags' === $type ? absint( $term ) : 0,
								);
							}
						} elseif ( 'all' === $type || ! $audience['include'] ) {
							$audience['scope'] = 'all';
						}
						Campaigns::write_meta( $id, Campaigns::META_AUDIENCE, (string) wp_json_encode( Audience::stored( $audience ) ) );
					}

					$state = Campaigns::state( $id );
					$snapshot = Campaigns::template( $id );
					if ( ! isset( $snapshot['audience'] ) && in_array( $state, array( Campaigns::STATUS_SCHEDULED, Campaigns::STATUS_SENDING, Campaigns::STATUS_PAUSED ), true ) ) {
						$jobs = new EmailJobRepository();
						if ( 0 === $jobs->campaign_stats( $id )['total'] ) {
							Campaigns::write_meta( $id, Campaigns::META_SNAPSHOT, null );
							Campaigns::write_meta( $id, Campaigns::META_SCHEDULE, null );
							Campaigns::write_meta( $id, Campaigns::META_STATUS, Campaigns::STATUS_DRAFT );
						} else {
							if ( false === $jobs->cancel_campaign( $id, 'campaign_requires_review' ) ) {
								return Campaigns::storage_error();
							}
							Campaigns::write_meta( $id, Campaigns::META_STATUS, Campaigns::STATUS_CANCELLED );
						}
					}

					foreach ( array( '_nowcrm_audience_max_id', '_nowcrm_recipient_total', '_nowcrm_campaign_cursor', '_nowcrm_enqueue_done', '_nowcrm_sent_count' ) as $key ) {
						Campaigns::write_meta( $id, $key, null );
					}
					return true;
				}
			);
			if ( is_wp_error( $result ) ) {
				return false;
			}
		}

		foreach ( self::hooks() as $hook ) {
			wp_unschedule_hook( $hook );
		}
		if ( function_exists( 'as_unschedule_all_actions' ) && did_action( 'action_scheduler_init' ) ) {
			self::clear_actions();
		} else {
			add_action( 'action_scheduler_init', array( self::class, 'clear_actions' ) );
		}
		return true;
	}

	public static function clear_actions(): void {
		foreach ( self::hooks() as $hook ) {
			as_unschedule_all_actions( $hook, array(), 'nowcrm' );
		}
	}

	private static function hooks(): array {
		return array( 'nowcrm_campaign_enqueue', 'nowcrm_campaign_start', 'nowcrm_campaign_sweep' );
	}
}
