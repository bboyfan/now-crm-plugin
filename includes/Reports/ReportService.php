<?php
/**
 * Server-derived reporting over native tables.
 *
 * Every number comes from the plugin's own tables. Engagement counts are
 * deduplicated at query time: opens once per recipient, clicks once per
 * recipient job, feedback and unsubscribes once per recipient. Rates return
 * null when the denominator is zero, so the page can show "—" instead of a
 * fake 0%.
 *
 * Attribution limits are deliberate. order_links has no campaign column, so
 * per-campaign revenue is not reported. Order revenue is grouped by currency
 * and is never summed across currencies.
 *
 * @package NowCRM
 */

namespace NowCRM\Reports;

use NowCRM\Campaign\Campaigns;
use NowCRM\Contacts\Status;
use NowCRM\Support\Schema;
use NowCRM\Terms\TermRepository;

defined( 'ABSPATH' ) || exit;

final class ReportService {

	public const PER_PAGE     = 20;
	public const MAX_PER_PAGE = 100;

	/**
	 * Coarse campaign counters for one bounded range.
	 *
	 * @param array<string, mixed> $filters campaign_id, status.
	 * @return array<int, array<string, mixed>> Rows sorted by accepted count.
	 */
	public function campaign_rows( DateRange $range, array $filters = array() ): array {
		$campaign_id = absint( $filters['campaign_id'] ?? 0 );
		$state       = sanitize_key( (string) ( $filters['status'] ?? '' ) );

		$accepted = $this->accepted_counts( $range, $campaign_id );
		$events   = $this->event_counts( $range, $campaign_id );
		$ids      = array_values( array_unique( array_merge( array_keys( $accepted ), array_keys( $events ) ) ) );

		if ( ! $ids ) {
			return array();
		}

		$posts = $this->campaign_posts( $ids );
		$rows  = array();

		foreach ( $ids as $id ) {
			$id   = (int) $id;
			$post = $posts[ $id ] ?? null;
			// A deleted, trashed, auto-draft or wrong-type post keeps its
			// historical counters but has no current state or detail page.
			$live = null !== $post && $this->campaign_post_live( $post );
			$key  = $live ? $this->campaign_post_state( $post ) : '';

			if ( '' !== $state && $key !== $state ) {
				continue;
			}

			$row = array(
				'campaign_id'      => $id,
				'name'             => $live ? (string) $post['name'] : ( '#' . $id ),
				'status'           => $key,
				'accepted'         => (int) ( $accepted[ $id ] ?? 0 ),
				'opens'            => (int) ( $events[ $id ]['opens'] ?? 0 ),
				'clicks'           => (int) ( $events[ $id ]['clicks'] ?? 0 ),
				'bounces'          => (int) ( $events[ $id ]['bounces'] ?? 0 ),
				'complaints'       => (int) ( $events[ $id ]['complaints'] ?? 0 ),
				'unsubscribes'     => (int) ( $events[ $id ]['unsubscribes'] ?? 0 ),
				'detail_available' => $live,
			);

			$rows[] = $this->with_rates( $row );
		}

		usort(
			$rows,
			static function ( array $left, array $right ): int {
				return ( $right['accepted'] <=> $left['accepted'] )
					?: ( $right['opens'] <=> $left['opens'] )
					?: strcmp( (string) $left['name'], (string) $right['name'] );
			}
		);

		return $rows;
	}

	/**
	 * One page of the campaign table plus totals for the whole filtered set.
	 *
	 * @param array<string, mixed> $filters campaign_id, status, page, per_page.
	 * @return array{rows: array<int, array<string, mixed>>, total: int, page: int, per_page: int, pages: int, totals: array<string, mixed>}
	 */
	public function campaign_table( DateRange $range, array $filters = array() ): array {
		$rows     = $this->campaign_rows( $range, $filters );
		$per_page = max( 1, min( self::MAX_PER_PAGE, absint( $filters['per_page'] ?? self::PER_PAGE ) ) );
		$total    = count( $rows );
		$pages    = max( 1, (int) ceil( $total / $per_page ) );
		$page     = min( $pages, max( 1, absint( $filters['page'] ?? 1 ) ) );

		return array(
			'rows'     => array_slice( $rows, ( $page - 1 ) * $per_page, $per_page ),
			'total'    => $total,
			'page'     => $page,
			'per_page' => $per_page,
			'pages'    => $pages,
			'totals'   => $this->totals( $rows ),
		);
	}

	/**
	 * Sum counters and derive rates from the sums.
	 *
	 * @param array<int, array<string, mixed>> $rows Campaign rows.
	 * @return array<string, mixed>
	 */
	public function totals( array $rows ): array {
		$totals = array(
			'accepted'     => 0,
			'opens'        => 0,
			'clicks'       => 0,
			'bounces'      => 0,
			'complaints'   => 0,
			'unsubscribes' => 0,
		);

		foreach ( $rows as $row ) {
			foreach ( array_keys( $totals ) as $key ) {
				$totals[ $key ] += (int) ( $row[ $key ] ?? 0 );
			}
		}

		return $this->with_rates( $totals );
	}

	/**
	 * Contact growth and the current status mix.
	 *
	 * New contacts are counted by created_at inside the range, using the
	 * optional current list/tag membership as an AND filter. The status mix is
	 * a current snapshot over every contact, which is the only honest way to
	 * describe a status that can change after creation. An invalid term does
	 * not broaden the query: it returns no new contacts.
	 *
	 * @return array{new: int, status_mix: array<string, int>, total: int}
	 */
	public function contact_summary( DateRange $range, int $list_id = 0, int $tag_id = 0 ): array {
		global $wpdb;

		$table = Schema::table( 'contacts' );

		$new = $this->contact_new_count( $range, $list_id, $tag_id );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- trusted table name.
		$rows = (array) $wpdb->get_results( "SELECT status, COUNT(*) AS total FROM {$table} GROUP BY status", ARRAY_A );

		$mix = array_fill_keys( Status::all(), 0 );

		foreach ( $rows as $row ) {
			$mix[ (string) $row['status'] ] = (int) $row['total'];
		}

		return array(
			'new'        => $new,
			'status_mix' => $mix,
			'total'      => array_sum( $mix ),
		);
	}

	/**
	 * Zero-filled daily contact growth for the range.
	 *
	 * Each key is an exact site-local date from the range, including days with
	 * no contacts. The list and tag filters use current membership and are
	 * AND-ed, matching the Contacts simple filter semantics.
	 *
	 * @return array<string, int> date => new contacts.
	 */
	public function contact_growth( DateRange $range, int $list_id = 0, int $tag_id = 0 ): array {
		$days = $this->zero_days( $range );

		if ( ! $this->term_matches( $list_id, TermRepository::TYPE_LIST ) || ! $this->term_matches( $tag_id, TermRepository::TYPE_TAG ) ) {
			return $days;
		}

		global $wpdb;

		$contacts = Schema::table( 'contacts' );
		$links    = Schema::table( 'contact_terms' );
		$day      = $range->local_day_expression( 'c.created_at' );

		$sql    = "SELECT {$day['sql']} AS local_day, COUNT(*) AS total
			FROM {$contacts} AS c
			WHERE c.created_at >= %s AND c.created_at < %s";
		$params = array_merge( $day['params'], array( $range->from_utc, $range->to_utc ) );

		if ( $list_id > 0 ) {
			$sql     .= " AND EXISTS (SELECT 1 FROM {$links} AS lt WHERE lt.contact_id = c.id AND lt.term_id = %d)";
			$params[] = $list_id;
		}

		if ( $tag_id > 0 ) {
			$sql     .= " AND EXISTS (SELECT 1 FROM {$links} AS tt WHERE tt.contact_id = c.id AND tt.term_id = %d)";
			$params[] = $tag_id;
		}

		$sql .= ' GROUP BY local_day ORDER BY local_day ASC';

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- only placeholders were appended.
		$rows = (array) $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );

		foreach ( $rows as $row ) {
			$key = (string) $row['local_day'];

			if ( array_key_exists( $key, $days ) ) {
				$days[ $key ] = (int) $row['total'];
			}
		}

		return $days;
	}

	/**
	 * Count new contacts inside the range, respecting current membership.
	 */
	public function contact_new_count( DateRange $range, int $list_id = 0, int $tag_id = 0 ): int {
		if ( ! $this->term_matches( $list_id, TermRepository::TYPE_LIST ) || ! $this->term_matches( $tag_id, TermRepository::TYPE_TAG ) ) {
			return 0;
		}

		global $wpdb;

		$contacts = Schema::table( 'contacts' );
		$links    = Schema::table( 'contact_terms' );

		$sql    = "SELECT COUNT(*) FROM {$contacts} AS c WHERE c.created_at >= %s AND c.created_at < %s";
		$params = array( $range->from_utc, $range->to_utc );

		if ( $list_id > 0 ) {
			$sql     .= " AND EXISTS (SELECT 1 FROM {$links} AS lt WHERE lt.contact_id = c.id AND lt.term_id = %d)";
			$params[] = $list_id;
		}

		if ( $tag_id > 0 ) {
			$sql     .= " AND EXISTS (SELECT 1 FROM {$links} AS tt WHERE tt.contact_id = c.id AND tt.term_id = %d)";
			$params[] = $tag_id;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- only placeholders were appended.
		return (int) $wpdb->get_var( $wpdb->prepare( $sql, $params ) );
	}

	/**
	 * Bounded current snapshot rankings for lists, tags and countries.
	 *
	 * These are rankings of current membership, not historical membership at
	 * the range end. Country values stay as the native two-letter code.
	 *
	 * @return array{lists: array<int, array<string, mixed>>, tags: array<int, array<string, mixed>>, countries: array<int, array<string, mixed>>}
	 */
	public function contact_breakdowns( int $limit = 5 ): array {
		global $wpdb;

		$limit   = max( 1, min( 20, $limit ) );
		$terms   = Schema::table( 'terms' );
		$links   = Schema::table( 'contact_terms' );
		$contact = Schema::table( 'contacts' );

		$rank_terms = static function ( string $type ) use ( $wpdb, $terms, $links, $limit ): array {
			$sql = "SELECT t.id, t.name, COUNT(ct.contact_id) AS total
				FROM {$terms} AS t
				INNER JOIN {$links} AS ct ON ct.term_id = t.id
				WHERE t.type = %s
				GROUP BY t.id, t.name
				ORDER BY total DESC, t.name ASC
				LIMIT %d";

			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- fixed query with placeholders.
			$rows = (array) $wpdb->get_results( $wpdb->prepare( $sql, $type, $limit ), ARRAY_A );

			return array_map(
				static function ( array $row ): array {
					return array(
						'id'    => (int) $row['id'],
						'name'  => (string) $row['name'],
						'total' => (int) $row['total'],
					);
				},
				$rows
			);
		};

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- trusted table name.
		$countries = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT country, COUNT(*) AS total FROM {$contact}
				WHERE country <> '' GROUP BY country ORDER BY total DESC, country ASC LIMIT %d",
				$limit
			),
			ARRAY_A
		);

		return array(
			'lists'     => $rank_terms( TermRepository::TYPE_LIST ),
			'tags'      => $rank_terms( TermRepository::TYPE_TAG ),
			'countries' => array_map(
				static function ( array $row ): array {
					return array(
						'code'  => strtoupper( (string) $row['country'] ),
						'total' => (int) $row['total'],
					);
				},
				$countries
			),
		);
	}

	/**
	 * Counted orders inside the range, grouped by currency.
	 *
	 * @return array{orders: int, customers: int, by_currency: array<string, array{orders: int, revenue: float, average: float|null}>}
	 */
	public function order_summary( DateRange $range ): array {
		global $wpdb;

		$table = Schema::table( 'order_links' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- trusted table name.
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT currency, COUNT(*) AS orders, COALESCE(SUM(net_total), 0) AS revenue
				FROM {$table}
				WHERE is_counted = 1 AND date_created IS NOT NULL AND date_created >= %s AND date_created < %s
				GROUP BY currency
				ORDER BY revenue DESC, currency ASC",
				$range->from_utc,
				$range->to_utc
			),
			ARRAY_A
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- trusted table name.
		$customers = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT contact_id) FROM {$table}
				WHERE is_counted = 1 AND date_created IS NOT NULL AND date_created >= %s AND date_created < %s",
				$range->from_utc,
				$range->to_utc
			)
		);

		$by_currency = array();
		$orders      = 0;

		foreach ( $rows as $row ) {
			$currency = strtoupper( (string) $row['currency'] );
			$label    = '' === $currency ? '—' : $currency;
			$count    = (int) $row['orders'];
			$revenue  = (float) $row['revenue'];

			$orders += $count;

			$by_currency[ $label ] = array(
				'orders'  => $count,
				'revenue' => $revenue,
				'average' => $count > 0 ? $revenue / $count : null,
			);
		}

		return array(
			'orders'         => $orders,
			'customers'      => $customers,
			'average_orders' => self::rate( $orders, $customers ),
			'by_currency'    => $by_currency,
		);
	}

	/**
	 * Zero-filled daily count of counted orders.
	 *
	 * @return array<string, int> date => counted orders.
	 */
	public function order_trend( DateRange $range ): array {
		$days = $this->zero_days( $range );

		global $wpdb;

		$table = Schema::table( 'order_links' );
		$day   = $range->local_day_expression( 'date_created' );

		$sql = "SELECT {$day['sql']} AS local_day, COUNT(*) AS total
			FROM {$table}
			WHERE is_counted = 1 AND date_created IS NOT NULL
				AND date_created >= %s AND date_created < %s
			GROUP BY local_day ORDER BY local_day ASC";

		$params = array_merge( $day['params'], array( $range->from_utc, $range->to_utc ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- only placeholders were appended.
		$rows = (array) $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );

		foreach ( $rows as $row ) {
			$key = (string) $row['local_day'];

			if ( array_key_exists( $key, $days ) ) {
				$days[ $key ] = (int) $row['total'];
			}
		}

		return $days;
	}

	/**
	 * Abandoned cart funnel for carts abandoned inside the range.
	 *
	 * The denominator comes from the append-only 'abandoned' cart events, so a
	 * cart that returns to active does not erase its history. Each cart counts
	 * once even when it was abandoned more than once in the window.
	 *
	 * Current state counters (abandoned_open, order_pending, lost, completed,
	 * recovered) describe the same cohort as it is now. They are states, not
	 * proof of payment. Pre-upgrade rows can be recovered without a paid
	 * order, so recovered never claims money.
	 *
	 * recovered_paid and recovered_value only use the email-attributed Woo
	 * orders behind carts whose current state is recovered, and only when the
	 * stored link is counted and has an actual payment date. order_pending is
	 * never promoted here, even when Woo stored a paid date on an offline
	 * transition; payment policy owns that state change. Missing links are
	 * never estimated and cart totals are never summed as revenue.
	 *
	 * period_events counts fixed cart event rows by their own occurred_at, so
	 * it follows the range, not the current state of the cohort.
	 *
	 * @return array{abandoned: int, active: int, abandoned_open: int, order_pending: int, lost: int, completed: int, recovered: int, recovered_paid: int, recovery_rate: float|null, reminders: int, period_events: array<string, int>, recovered_value: array<string, float>}
	 */
	public function cart_summary( DateRange $range ): array {
		global $wpdb;

		$events = Schema::table( 'cart_events' );
		$carts  = Schema::table( 'carts' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- trusted table names.
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT carts.status AS status, COUNT(DISTINCT events.cart_id) AS total
				FROM {$events} AS events
				INNER JOIN {$carts} AS carts ON carts.id = events.cart_id
				WHERE events.type = %s AND events.occurred_at >= %s AND events.occurred_at < %s
				GROUP BY carts.status",
				'abandoned',
				$range->from_utc,
				$range->to_utc
			),
			ARRAY_A
		);

		$counts = array(
			'active'        => 0,
			'abandoned'     => 0,
			'order_pending' => 0,
			'lost'          => 0,
			'completed'     => 0,
			'recovered'     => 0,
		);

		foreach ( $rows as $row ) {
			$counts[ (string) $row['status'] ] = (int) $row['total'];
		}

		$abandoned = array_sum( $counts );

		// Revenue comes from the attributed Woo orders, never from carts.total.
		// The DISTINCT cohort keeps one row per order, so repeated abandoned
		// events and duplicate cart attribution cannot inflate the sum. Only
		// counted orders with an actual payment time count as paid, so pending,
		// failed and unpaid COD orders drop out by their stored Woo status.
		// The cart must also be in the source state that payment policy sets
		// (recovered) with email attribution. Someone else may set a paid date
		// on an offline transition, so order_pending is never promoted here.
		// Legacy rows have no recovery_source and stay eligible when recovered.
		$links = Schema::table( 'order_links' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- trusted table names.
		$value_rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT links.currency AS currency, COUNT(*) AS orders, COALESCE(SUM(links.net_total), 0) AS value
				FROM {$links} AS links
				INNER JOIN (
					SELECT DISTINCT carts.recovered_order_id AS order_id
					FROM {$events} AS events
					INNER JOIN {$carts} AS carts ON carts.id = events.cart_id
					WHERE events.type = %s AND events.occurred_at >= %s AND events.occurred_at < %s
						AND carts.status = %s AND carts.recovered_order_id > 0
						AND ( carts.recovery_source = %s OR carts.recovery_source IS NULL OR carts.recovery_source = '' )
				) AS cohort ON cohort.order_id = links.order_id
				WHERE links.is_counted = 1 AND links.date_paid IS NOT NULL
				GROUP BY links.currency",
				'abandoned',
				$range->from_utc,
				$range->to_utc,
				'recovered',
				'email_click'
			),
			ARRAY_A
		);

		$recovered_value = array();
		$recovered_paid  = 0;

		foreach ( $value_rows as $row ) {
			$currency = strtoupper( (string) $row['currency'] );

			$recovered_paid += (int) $row['orders'];

			$recovered_value[ '' === $currency ? '—' : $currency ] = (float) $row['value'];
		}

		// Fixed event set, one grouped query. Event rows can repeat, so these
		// are period event counts, not distinct carts or orders.
		$event_types = array(
			'reminder_queued',
			'reminder_sent',
			'recovery_click',
			'cart_restored',
			'order_placed',
			'order_paid',
		);

		$period_events = array_fill_keys( $event_types, 0 );

		$event_placeholders = implode( ', ', array_fill( 0, count( $event_types ), '%s' ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- trusted table name and generated placeholders.
		$event_rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT type, COUNT(*) AS total FROM {$events}
				WHERE type IN ({$event_placeholders}) AND occurred_at >= %s AND occurred_at < %s
				GROUP BY type",
				array_merge( $event_types, array( $range->from_utc, $range->to_utc ) )
			),
			ARRAY_A
		);

		foreach ( $event_rows as $row ) {
			$type = (string) $row['type'];

			if ( array_key_exists( $type, $period_events ) ) {
				$period_events[ $type ] = (int) $row['total'];
			}
		}

		return array(
			'abandoned'      => $abandoned,
			'active'         => $counts['active'],
			'abandoned_open' => $counts['abandoned'],
			'order_pending'  => $counts['order_pending'],
			'lost'           => $counts['lost'],
			'completed'      => $counts['completed'],
			'recovered'      => $counts['recovered'],
			'recovered_paid' => $recovered_paid,
			'recovery_rate'  => self::rate( $recovered_paid, $abandoned ),
			'reminders'      => $period_events['reminder_queued'],
			'period_events'  => $period_events,
			'recovered_value' => $recovered_value,
		);
	}

	/**
	 * Zero-filled daily count of carts that recorded an abandoned event.
	 *
	 * A cart counts once per local day even if the event repeats. This is the
	 * same true-abandoned cohort used by cart_summary, not the reminder queue.
	 *
	 * @return array<string, int> date => distinct abandoned carts.
	 */
	public function cart_trend( DateRange $range ): array {
		$days = $this->zero_days( $range );

		global $wpdb;

		$table = Schema::table( 'cart_events' );
		$day   = $range->local_day_expression( 'occurred_at' );

		$sql = "SELECT {$day['sql']} AS local_day, COUNT(DISTINCT cart_id) AS total
			FROM {$table}
			WHERE type = %s AND occurred_at >= %s AND occurred_at < %s
			GROUP BY local_day ORDER BY local_day ASC";

		$params = array_merge( $day['params'], array( 'abandoned', $range->from_utc, $range->to_utc ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- only placeholders were appended.
		$rows = (array) $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );

		foreach ( $rows as $row ) {
			$key = (string) $row['local_day'];

			if ( array_key_exists( $key, $days ) ) {
				$days[ $key ] = (int) $row['total'];
			}
		}

		return $days;
	}

	/**
	 * Per-campaign drilldown: counters, top clicked links and recent events.
	 *
	 * @return array{campaign: array<string, mixed>, links: array<int, array{url: string, recipients: int}>, events: array<int, array<string, mixed>>}|null
	 */
	public function campaign_detail( int $campaign_id, DateRange $range ): ?array {
		$post = Campaigns::get( $campaign_id );

		if ( ! $post ) {
			return null;
		}

		$rows = $this->campaign_rows( $range, array( 'campaign_id' => $campaign_id ) );
		$row  = $rows[0] ?? $this->with_rates(
			array(
				'campaign_id'  => $campaign_id,
				'name'         => $this->campaign_name( $campaign_id, $post ),
				'status'       => $this->campaign_state( $campaign_id ),
				'accepted'     => 0,
				'opens'        => 0,
				'clicks'       => 0,
				'bounces'      => 0,
				'complaints'   => 0,
				'unsubscribes' => 0,
			)
		);

		return array(
			'campaign' => $row,
			'links'    => $this->campaign_links( $campaign_id, $range ),
			'events'   => $this->campaign_events( $campaign_id, $range ),
		);
	}

	/**
	 * Campaigns for the filter select, newest first.
	 *
	 * When a selected campaign is outside the first bounded page, its option is
	 * added so the current scope is visible instead of silently disappearing.
	 *
	 * @return array<int, string>
	 */
	public function campaign_options( int $include_id = 0 ): array {
		$posts = get_posts(
			array(
				'post_type'           => Campaigns::POST_TYPE,
				'post_status'         => 'any',
				'posts_per_page'      => 200,
				'orderby'             => 'date',
				'order'               => 'DESC',
				'no_found_rows'       => true,
				'ignore_sticky_posts' => true,
			)
		);

		$options = array();

		foreach ( $posts as $post ) {
			$options[ (int) $post->ID ] = $this->campaign_name( (int) $post->ID, $post );
		}

		if ( $include_id > 0 && ! isset( $options[ $include_id ] ) ) {
			$post = Campaigns::get( $include_id );

			$options[ $include_id ] = $post
				? $this->campaign_name( $include_id, $post )
				: sprintf(
					/* translators: %d: campaign id. */
					__( '已刪除的電子報 #%d', 'now-crm' ),
					$include_id
				);
		}

		return $options;
	}

	/**
	 * Zero-filled daily email counters for the current campaign scope.
	 *
	 * Daily opens/clicks/unsubscribes deduplicate inside each local day. The
	 * period totals in campaign_rows deduplicate across the whole range, so a
	 * recipient active on two days appears in two daily buckets but once in
	 * the period total. Accepted jobs use accepted_at; engagement uses the
	 * event timestamp, matching the existing table and CSV contract.
	 *
	 * @param array<string, mixed> $filters campaign_id, status.
	 * @return array{accepted: array<string, int>, opens: array<string, int>, clicks: array<string, int>, unsubscribes: array<string, int>}
	 */
	public function email_trends( DateRange $range, array $filters = array() ): array {
		$campaign_id = absint( $filters['campaign_id'] ?? 0 );
		$status      = sanitize_key( (string) ( $filters['status'] ?? '' ) );
		$empty       = $this->zero_days( $range );

		$series = array(
			'accepted'     => $empty,
			'opens'        => $empty,
			'clicks'       => $empty,
			'unsubscribes' => $empty,
		);

		global $wpdb;

		$jobs   = Schema::table( 'email_jobs' );
		$events = Schema::table( 'email_events' );
		$dayJob = $range->local_day_expression( 'accepted_at' );
		$dayEv  = $range->local_day_expression( 'occurred_at' );

		$job_sql    = "SELECT campaign_id, {$dayJob['sql']} AS local_day, COUNT(DISTINCT id) AS total
			FROM {$jobs}
			WHERE kind = %s AND status = %s AND campaign_id > 0
				AND accepted_at IS NOT NULL AND accepted_at >= %s AND accepted_at < %s";
		$job_params = array_merge( $dayJob['params'], array( 'campaign', 'accepted', $range->from_utc, $range->to_utc ) );

		if ( $campaign_id > 0 ) {
			$job_sql     .= ' AND campaign_id = %d';
			$job_params[] = $campaign_id;
		}

		$job_sql .= ' GROUP BY campaign_id, local_day';

		$event_sql = "SELECT campaign_id, {$dayEv['sql']} AS local_day,
				COUNT(DISTINCT CASE WHEN type = %s THEN COALESCE(NULLIF(contact_id, 0), NULLIF(job_id, 0), id) END) AS opens,
				COUNT(DISTINCT CASE WHEN type = %s THEN COALESCE(NULLIF(job_id, 0), NULLIF(contact_id, 0), id) END) AS clicks,
				COUNT(DISTINCT CASE WHEN type = %s THEN COALESCE(NULLIF(contact_id, 0), NULLIF(job_id, 0), id) END) AS unsubscribes
			FROM {$events}
			WHERE campaign_id > 0 AND occurred_at >= %s AND occurred_at < %s
				AND type IN (%s, %s, %s)";
		$event_params = array_merge(
			$dayEv['params'],
			array(
				'open',
				'click',
				'unsubscribe',
				$range->from_utc,
				$range->to_utc,
				'open',
				'click',
				'unsubscribe',
			)
		);

		if ( $campaign_id > 0 ) {
			$event_sql     .= ' AND campaign_id = %d';
			$event_params[] = $campaign_id;
		}

		$event_sql .= ' GROUP BY campaign_id, local_day';

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- only placeholders were appended.
		$job_rows = (array) $wpdb->get_results( $wpdb->prepare( $job_sql, $job_params ), ARRAY_A );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- only placeholders were appended.
		$event_rows = (array) $wpdb->get_results( $wpdb->prepare( $event_sql, $event_params ), ARRAY_A );

		$ids = array();

		foreach ( array_merge( $job_rows, $event_rows ) as $row ) {
			$ids[ (int) $row['campaign_id'] ] = true;
		}

		$allowed = null;

		if ( '' !== $status ) {
			$allowed = array();

			foreach ( $this->campaign_states( array_keys( $ids ) ) as $id => $state ) {
				if ( $state === $status ) {
					$allowed[ $id ] = true;
				}
			}
		}

		foreach ( $job_rows as $row ) {
			$id  = (int) $row['campaign_id'];
			$key = (string) $row['local_day'];

			if ( ( null !== $allowed && ! isset( $allowed[ $id ] ) ) || ! array_key_exists( $key, $series['accepted'] ) ) {
				continue;
			}

			$series['accepted'][ $key ] += (int) $row['total'];
		}

		foreach ( $event_rows as $row ) {
			$id  = (int) $row['campaign_id'];
			$key = (string) $row['local_day'];

			if ( ( null !== $allowed && ! isset( $allowed[ $id ] ) ) || ! array_key_exists( $key, $series['opens'] ) ) {
				continue;
			}

			$series['opens'][ $key ]       += (int) $row['opens'];
			$series['clicks'][ $key ]      += (int) $row['clicks'];
			$series['unsubscribes'][ $key ] += (int) $row['unsubscribes'];
		}

		return $series;
	}

	/**
	 * Bounded top clicked links across the filtered campaign scope.
	 *
	 * Returns no rows when a campaign is selected because campaign_detail
	 * already lists that campaign's links. Status filtering uses the same
	 * current post status as the table and CSV. Rows keep the stored title
	 * for a trashed campaign but report when its detail page is unavailable.
	 *
	 * @param array<string, mixed> $filters campaign_id, status.
	 * @return array<int, array{campaign_id: int, name: string, url: string, recipients: int, detail_available: bool}>
	 */
	public function campaign_top_links( DateRange $range, array $filters = array(), int $limit = 10 ): array {
		if ( absint( $filters['campaign_id'] ?? 0 ) > 0 ) {
			return array();
		}

		global $wpdb;

		$table  = Schema::table( 'email_events' );
		$status = sanitize_key( (string) ( $filters['status'] ?? '' ) );
		$limit  = max( 1, min( 50, $limit ) );

		$sql    = "SELECT e.campaign_id, e.url,
				COUNT(DISTINCT CASE WHEN e.job_id > 0 THEN e.job_id ELSE COALESCE(NULLIF(e.contact_id, 0), e.id) END) AS recipients
			FROM {$table} AS e
			WHERE e.type = %s AND e.url <> '' AND e.campaign_id > 0
				AND e.occurred_at >= %s AND e.occurred_at < %s";
		$params = array( 'click', $range->from_utc, $range->to_utc );

		if ( '' !== $status ) {
			// Apply the current campaign state before grouping and LIMIT, so a
			// filtered scope cannot lose its real rows behind 50 excluded groups.
			$sql .= " AND EXISTS (
					SELECT 1 FROM {$wpdb->posts} AS p
					WHERE p.ID = e.campaign_id
						AND p.post_type = %s
						AND p.post_status NOT IN ('trash', 'auto-draft')
						AND COALESCE((SELECT pm.meta_value FROM {$wpdb->postmeta} AS pm WHERE pm.post_id = p.ID AND pm.meta_key = %s LIMIT 1), %s) = %s
				)";
			$params[] = Campaigns::POST_TYPE;
			$params[] = Campaigns::META_STATUS;
			$params[] = Campaigns::STATUS_DRAFT;
			$params[] = $status;
		}

		$sql .= ' GROUP BY e.campaign_id, e.url ORDER BY recipients DESC, e.url ASC LIMIT %d';
		$params[] = $limit;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- only placeholders were appended.
		$rows = (array) $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );

		$links = array();
		$ids   = array();

		foreach ( $rows as $row ) {
			$id = (int) $row['campaign_id'];

			$links[] = array(
				'campaign_id' => $id,
				'url'         => esc_url_raw( (string) $row['url'], array( 'http', 'https' ) ),
				'recipients'  => (int) $row['recipients'],
			);
			$ids[]   = $id;
		}

		$posts = $this->campaign_posts( $ids );

		foreach ( $links as &$link ) {
			$post = $posts[ $link['campaign_id'] ] ?? null;

			// A missing or hidden post keeps its historical title when one is
			// still stored, but it has no detail page to open.
			$link['name']             = null !== $post ? (string) $post['name'] : ( '#' . $link['campaign_id'] );
			$link['detail_available'] = null !== $post && $this->campaign_post_live( $post );
		}

		unset( $link );

		return $links;
	}

	/**
	 * Bounded automation run starts grouped by automation and current state.
	 *
	 * automation_runs keeps one retained row per automation and contact, so
	 * this counts starts, not enrollment history. The headline total is a
	 * separate count so the bounded list cannot understate it.
	 *
	 * @return array{total: int, rows: array<int, array<string, mixed>>}
	 */
	public function automation_summary( DateRange $range ): array {
		global $wpdb;

		$runs = Schema::table( 'automation_runs' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- trusted table name.
		$totals = (array) $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COUNT(*) AS total, COUNT(DISTINCT automation_id) AS automations
				FROM {$runs} WHERE started_at >= %s AND started_at < %s",
				$range->from_utc,
				$range->to_utc
			),
			ARRAY_A
		);

		$total       = (int) ( $totals['total'] ?? 0 );
		$automations = (int) ( $totals['automations'] ?? 0 );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- trusted table name.
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT automation_id, COUNT(*) AS total,
					SUM(status = %s) AS active, SUM(status = %s) AS waiting,
					SUM(status = %s) AS completed, SUM(status = %s) AS cancelled,
					SUM(status = %s) AS failed,
					MIN(started_at) AS first_start, MAX(started_at) AS last_start
				FROM {$runs}
				WHERE started_at >= %s AND started_at < %s
				GROUP BY automation_id
				ORDER BY total DESC, automation_id ASC
				LIMIT 50",
				'active',
				'waiting',
				'completed',
				'cancelled',
				'failed',
				$range->from_utc,
				$range->to_utc
			),
			ARRAY_A
		);

		$ids   = array_map( 'intval', array_column( $rows, 'automation_id' ) );
		$names = array();

		if ( $ids ) {
			$table        = Schema::table( 'automations' );
			$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- trusted table and generated placeholders.
			$names_rows = (array) $wpdb->get_results(
				$wpdb->prepare( "SELECT id, name FROM {$table} WHERE id IN ({$placeholders})", $ids ),
				ARRAY_A
			);

			foreach ( $names_rows as $row ) {
				$names[ (int) $row['id'] ] = (string) $row['name'];
			}
		}

		$out = array();

		foreach ( $rows as $row ) {
			$id = (int) $row['automation_id'];

			$out[] = array(
				'automation_id' => $id,
				'name'          => $names[ $id ] ?? ( '#' . $id ),
				'total'         => (int) $row['total'],
				'active'        => (int) $row['active'],
				'waiting'       => (int) $row['waiting'],
				'completed'     => (int) $row['completed'],
				'cancelled'     => (int) $row['cancelled'],
				'failed'        => (int) $row['failed'],
				'first_start'   => (string) $row['first_start'],
				'last_start'    => (string) $row['last_start'],
			);
		}

		return array(
			'total'       => $total,
			'automations' => $automations,
			'rows'        => $out,
		);
	}

	/**
	 * Zero-filled daily count of retained automation run starts.
	 *
	 * @return array<string, int> date => run starts.
	 */
	public function automation_trend( DateRange $range ): array {
		$days = $this->zero_days( $range );

		global $wpdb;

		$table = Schema::table( 'automation_runs' );
		$day   = $range->local_day_expression( 'started_at' );

		$sql = "SELECT {$day['sql']} AS local_day, COUNT(*) AS total
			FROM {$table}
			WHERE started_at >= %s AND started_at < %s
			GROUP BY local_day ORDER BY local_day ASC";

		$params = array_merge( $day['params'], array( $range->from_utc, $range->to_utc ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- only placeholders were appended.
		$rows = (array) $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );

		foreach ( $rows as $row ) {
			$key = (string) $row['local_day'];

			if ( array_key_exists( $key, $days ) ) {
				$days[ $key ] = (int) $row['total'];
			}
		}

		return $days;
	}

	/**
	 * Safe rate helper. A zero denominator yields null, never infinity.
	 */
	public static function rate( int $numerator, int $denominator ): ?float {
		if ( $denominator <= 0 ) {
			return null;
		}

		return (float) $numerator / (float) $denominator;
	}

	/**
	 * Chronological zero-filled day map for the range.
	 *
	 * @return array<string, int>
	 */
	private function zero_days( DateRange $range ): array {
		$days   = array();
		$cursor = new \DateTimeImmutable( $range->from, new \DateTimeZone( 'UTC' ) );
		$end    = $range->to;

		while ( $cursor->format( 'Y-m-d' ) <= $end ) {
			$days[ $cursor->format( 'Y-m-d' ) ] = 0;
			$cursor = $cursor->modify( '+1 day' );
		}

		return $days;
	}

	/**
	 * Whether a non-zero term id exists with the expected type.
	 */
	private function term_matches( int $id, string $type ): bool {
		if ( $id <= 0 ) {
			return true;
		}

		global $wpdb;

		$table = Schema::table( 'terms' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- trusted table name.
		return (bool) $wpdb->get_var(
			$wpdb->prepare( "SELECT 1 FROM {$table} WHERE id = %d AND type = %s", $id, $type )
		);
	}

	/**
	 * Current campaign states for a bounded id set in one query.
	 *
	 * Trashed, auto-draft, deleted and wrong-type posts have no state, so a
	 * status filter cannot match them.
	 *
	 * @param array<int, mixed> $ids Campaign ids.
	 * @return array<int, string>
	 */
	private function campaign_states( array $ids ): array {
		$states = array();

		foreach ( $this->campaign_posts( $ids ) as $id => $post ) {
			if ( $this->campaign_post_live( $post ) ) {
				$states[ $id ] = $this->campaign_post_state( $post );
			}
		}

		return $states;
	}

	/**
	 * Names, raw post status and stored campaign state for a bounded id set.
	 *
	 * One query loads only the columns the report needs. Missing or empty
	 * status meta normalizes to draft, matching Campaigns::state().
	 *
	 * @param array<int, mixed> $ids Campaign ids.
	 * @return array<int, array{name: string, status: string, state: string}>
	 */
	private function campaign_posts( array $ids ): array {
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );

		if ( array() === $ids ) {
			return array();
		}

		global $wpdb;

		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- trusted tables and generated placeholders.
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID, p.post_title, p.post_status,
					COALESCE((SELECT pm.meta_value FROM {$wpdb->postmeta} AS pm WHERE pm.post_id = p.ID AND pm.meta_key = %s LIMIT 1), '') AS state
				FROM {$wpdb->posts} AS p
				WHERE p.ID IN ({$placeholders}) AND p.post_type = %s",
				array_merge( array( Campaigns::META_STATUS ), $ids, array( Campaigns::POST_TYPE ) )
			),
			ARRAY_A
		);

		$posts = array();

		foreach ( $rows as $row ) {
			$id    = (int) $row['ID'];
			$title = trim( html_entity_decode( wp_strip_all_tags( (string) $row['post_title'] ), ENT_QUOTES, 'UTF-8' ) );

			$posts[ $id ] = array(
				'name'   => '' === $title ? '#' . $id : $title,
				'status' => (string) $row['post_status'],
				'state'  => (string) $row['state'],
			);
		}

		return $posts;
	}

	/**
	 * Whether a campaign post can open its detail page.
	 *
	 * Mirrors Campaigns::get(): the post must keep the campaign type and must
	 * not be trashed or an auto-draft. The query already enforces the type.
	 *
	 * @param array{name: string, status: string, state: string} $post Post row.
	 */
	private function campaign_post_live( array $post ): bool {
		return ! in_array( $post['status'], array( 'trash', 'auto-draft' ), true );
	}

	/**
	 * Stored campaign state of a post row, defaulting to draft.
	 *
	 * @param array{name: string, status: string, state: string} $post Post row.
	 */
	private function campaign_post_state( array $post ): string {
		return '' === $post['state'] ? Campaigns::STATUS_DRAFT : $post['state'];
	}

	/**
	 * Accepted broadcast jobs per campaign.
	 *
	 * @return array<int, int>
	 */
	private function accepted_counts( DateRange $range, int $campaign_id ): array {
		global $wpdb;

		$table  = Schema::table( 'email_jobs' );
		$sql    = "SELECT campaign_id, COUNT(DISTINCT id) AS accepted
			FROM {$table}
			WHERE kind = %s AND status = %s AND campaign_id > 0
				AND accepted_at IS NOT NULL AND accepted_at >= %s AND accepted_at < %s";
		$params = array( 'campaign', 'accepted', $range->from_utc, $range->to_utc );

		if ( $campaign_id > 0 ) {
			$sql     .= ' AND campaign_id = %d';
			$params[] = $campaign_id;
		}

		$sql .= ' GROUP BY campaign_id';

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- only placeholders were appended.
		$rows = (array) $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
		$out  = array();

		foreach ( $rows as $row ) {
			$out[ (int) $row['campaign_id'] ] = (int) $row['accepted'];
		}

		return $out;
	}

	/**
	 * Deduplicated engagement counters per campaign.
	 *
	 * Opens count one recipient per campaign. Clicks count one recipient job,
	 * so several clicked links in one email count once. Feedback and
	 * unsubscribes count one recipient. Duplicate rows with a different
	 * event_key cannot inflate any counter.
	 *
	 * @return array<int, array<string, int>>
	 */
	private function event_counts( DateRange $range, int $campaign_id ): array {
		global $wpdb;

		$table = Schema::table( 'email_events' );

		$sql = "SELECT campaign_id,
				COUNT(DISTINCT CASE WHEN type = %s THEN COALESCE(NULLIF(contact_id, 0), NULLIF(job_id, 0), id) END) AS opens,
				COUNT(DISTINCT CASE WHEN type = %s THEN COALESCE(NULLIF(job_id, 0), NULLIF(contact_id, 0), id) END) AS clicks,
				COUNT(DISTINCT CASE WHEN type = %s THEN COALESCE(NULLIF(contact_id, 0), NULLIF(job_id, 0), id) END) AS bounces,
				COUNT(DISTINCT CASE WHEN type = %s THEN COALESCE(NULLIF(contact_id, 0), NULLIF(job_id, 0), id) END) AS complaints,
				COUNT(DISTINCT CASE WHEN type = %s THEN COALESCE(NULLIF(contact_id, 0), NULLIF(job_id, 0), id) END) AS unsubscribes
			FROM {$table}
			WHERE campaign_id > 0 AND occurred_at >= %s AND occurred_at < %s
				AND type IN (%s, %s, %s, %s, %s)";

		$params = array(
			'open',
			'click',
			'bounce',
			'complaint',
			'unsubscribe',
			$range->from_utc,
			$range->to_utc,
			'open',
			'click',
			'bounce',
			'complaint',
			'unsubscribe',
		);

		if ( $campaign_id > 0 ) {
			$sql     .= ' AND campaign_id = %d';
			$params[] = $campaign_id;
		}

		$sql .= ' GROUP BY campaign_id';

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- only placeholders were appended.
		$rows = (array) $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
		$out  = array();

		foreach ( $rows as $row ) {
			$out[ (int) $row['campaign_id'] ] = array(
				'opens'        => (int) $row['opens'],
				'clicks'       => (int) $row['clicks'],
				'bounces'      => (int) $row['bounces'],
				'complaints'   => (int) $row['complaints'],
				'unsubscribes' => (int) $row['unsubscribes'],
			);
		}

		return $out;
	}

	/**
	 * Top clicked links inside the range.
	 *
	 * @return array<int, array{url: string, recipients: int}>
	 */
	private function campaign_links( int $campaign_id, DateRange $range ): array {
		global $wpdb;

		$table = Schema::table( 'email_events' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- trusted table name.
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT url, COUNT(DISTINCT CASE WHEN job_id > 0 THEN job_id ELSE id END) AS recipients
				FROM {$table}
				WHERE campaign_id = %d AND type = %s AND url <> ''
					AND occurred_at >= %s AND occurred_at < %s
				GROUP BY url
				ORDER BY recipients DESC, url ASC
				LIMIT 20",
				$campaign_id,
				'click',
				$range->from_utc,
				$range->to_utc
			),
			ARRAY_A
		);

		$links = array();

		foreach ( $rows as $row ) {
			$links[] = array(
				'url'        => esc_url_raw( (string) $row['url'], array( 'http', 'https' ) ),
				'recipients' => (int) $row['recipients'],
			);
		}

		return $links;
	}

	/**
	 * Recent events for the drilldown.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function campaign_events( int $campaign_id, DateRange $range ): array {
		global $wpdb;

		$table = Schema::table( 'email_events' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- trusted table name.
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT type, url, contact_id, job_id, occurred_at
				FROM {$table}
				WHERE campaign_id = %d AND occurred_at >= %s AND occurred_at < %s
				ORDER BY occurred_at DESC, id DESC
				LIMIT 50",
				$campaign_id,
				$range->from_utc,
				$range->to_utc
			),
			ARRAY_A
		);

		return array_map(
			static function ( array $row ): array {
				return array(
					'type'        => (string) $row['type'],
					'url'         => esc_url_raw( (string) $row['url'], array( 'http', 'https' ) ),
					'contact_id'  => (int) $row['contact_id'],
					'job_id'      => (int) $row['job_id'],
					'occurred_at' => (string) $row['occurred_at'],
				);
			},
			$rows
		);
	}

	/**
	 * Derive rates for a counter set.
	 *
	 * @param array<string, mixed> $row Counter row.
	 * @return array<string, mixed>
	 */
	private function with_rates( array $row ): array {
		$accepted = (int) ( $row['accepted'] ?? 0 );

		$row['open_rate']        = self::rate( (int) ( $row['opens'] ?? 0 ), $accepted );
		$row['click_rate']       = self::rate( (int) ( $row['clicks'] ?? 0 ), $accepted );
		$row['bounce_rate']      = self::rate( (int) ( $row['bounces'] ?? 0 ), $accepted );
		$row['complaint_rate']   = self::rate( (int) ( $row['complaints'] ?? 0 ), $accepted );
		$row['unsubscribe_rate'] = self::rate( (int) ( $row['unsubscribes'] ?? 0 ), $accepted );

		return $row;
	}

	/**
	 * Stored campaign state, defaulting to draft.
	 */
	private function campaign_state( int $campaign_id ): string {
		$state = (string) get_post_meta( $campaign_id, Campaigns::META_STATUS, true );

		return '' === $state ? Campaigns::STATUS_DRAFT : $state;
	}

	/**
	 * Human campaign name with a stable fallback for deleted posts.
	 *
	 * @param \WP_Post|null $post Post object when it exists.
	 */
	private function campaign_name( int $campaign_id, ?\WP_Post $post ): string {
		$title = $post instanceof \WP_Post ? wp_strip_all_tags( (string) $post->post_title ) : '';
		$name  = trim( html_entity_decode( $title, ENT_QUOTES, 'UTF-8' ) );

		return '' === $name ? '#' . $campaign_id : $name;
	}
}
