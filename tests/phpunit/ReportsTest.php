<?php
/**
 * Report ranges, deduplicated aggregates, revenue scoping and CSV output.
 *
 * @package NowCRM
 */

use NowCRM\Campaign\Campaigns;
use NowCRM\Orders\OrderAccounting;
use NowCRM\Reports\DateRange;
use NowCRM\Reports\ReportCsv;
use NowCRM\Reports\ReportService;
use NowCRM\Support\Schema;
use NowCRM\Terms\TermRepository;

/**
 * @covers \NowCRM\Reports\ReportService
 * @covers \NowCRM\Reports\DateRange
 * @covers \NowCRM\Reports\ReportCsv
 */
final class ReportsTest extends NowCrmTestCase {

	/**
	 * Range pinned to UTC so fixed fixtures stay stable.
	 */
	private function range( string $from, string $to ): DateRange {
		return DateRange::parse( $from, $to, new DateTimeZone( 'UTC' ) );
	}

	/**
	 * Insert an accepted or queued job at a fixed time.
	 */
	private function job( int $campaign_id, string $email, string $at, string $kind = 'campaign', string $status = 'accepted' ): int {
		global $wpdb;

		$table        = Schema::table( 'email_jobs' );
		$accepted_sql = 'accepted' === $status ? '%s' : 'NULL';

		$sql = "INSERT INTO {$table}
			(job_key, kind, campaign_id, recipient, subject, status, available_at, accepted_at, created_at, updated_at)
			VALUES (%s, %s, %d, %s, %s, %s, %s, {$accepted_sql}, %s, %s)";

		$params = array(
			'report-' . $kind . '-' . $campaign_id . '-' . md5( $email . $at . wp_generate_password( 6, false ) ),
			$kind,
			$campaign_id,
			$email,
			'Report fixture',
			$status,
			$at,
		);

		if ( 'accepted' === $status ) {
			$params[] = $at;
		}

		$params[] = $at;
		$params[] = $at;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- NULL token is generated above.
		$wpdb->query( $wpdb->prepare( $sql, $params ) );

		return (int) $wpdb->insert_id;
	}

	/**
	 * Insert an engagement event at a fixed time.
	 */
	private function event( int $campaign_id, string $type, string $at, int $contact_id = 0, int $job_id = 0, string $url = '' ): void {
		global $wpdb;

		$wpdb->insert(
			Schema::table( 'email_events' ),
			array(
				'event_key'   => substr( 'report:' . $type . ':' . md5( wp_generate_password( 16, false ) ), 0, 191 ),
				'type'        => $type,
				'campaign_id' => $campaign_id,
				'job_id'      => $job_id,
				'contact_id'  => $contact_id,
				'url'         => $url,
				'user_agent'  => 'fixture',
				'ip_hash'     => '',
				'meta'        => '{}',
				'occurred_at' => $at,
			)
		);
	}

	/**
	 * Insert a contact at a fixed creation time.
	 */
	private function contact( string $email, string $created_at, string $status = 'subscribed' ): int {
		global $wpdb;

		$wpdb->insert(
			Schema::table( 'contacts' ),
			array(
				'email'            => $email,
				'email_normalized' => mb_strtolower( $email, 'UTF-8' ),
				'status'           => $status,
				'source'           => 'test',
				'created_at'       => $created_at,
				'updated_at'       => $created_at,
			)
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Insert a cart event at a fixed time.
	 */
	private function cart_event( int $cart_id, string $type, string $at ): void {
		global $wpdb;

		$wpdb->insert(
			Schema::table( 'cart_events' ),
			array(
				'cart_id'     => $cart_id,
				'type'        => $type,
				'meta'        => '{}',
				'occurred_at' => $at,
			)
		);
	}

	/**
	 * Create a WooCommerce order link with controlled payment state.
	 *
	 * The link payload comes from the real WooCommerce order through
	 * OrderAccounting, so status, net total and currency follow production
	 * rules. A false $paid clears the stored payment date to model a counted
	 * order that is still unpaid, such as a COD order.
	 */
	private function order_link( int $contact_id, float $total, string $currency, string $status, bool $paid ): int {
		$order = $this->make_order(
			array(
				'billing_email' => 'cart-buyer@example.test',
				'total'         => $total,
				'status'        => $status,
			)
		);

		$order->set_currency( $currency );

		if ( $paid && ! $order->get_date_paid() ) {
			$order->set_date_paid( '2026-01-20 10:00:00' );
		}

		$order->save();

		$payload = OrderAccounting::map_order( $order );

		if ( ! $paid ) {
			$payload['date_paid'] = null;
		}

		$this->links->upsert( array_merge( $payload, array( 'contact_id' => $contact_id ) ) );

		return (int) $order->get_id();
	}

	/**
	 * Set the current state, attribution source and attributed order of a cart.
	 */
	private function settle_cart_source( array $cart, string $status, int $order_id, string $source ): void {
		global $wpdb;

		$wpdb->update(
			Schema::table( 'carts' ),
			array(
				'status'             => $status,
				'recovered_order_id' => $order_id,
				'recovery_source'    => $source,
			),
			array( 'id' => (int) $cart['id'] ),
			array( '%s', '%d', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Set the current state and attributed order of a captured cart.
	 */
	private function settle_cart( array $cart, string $status, int $order_id ): void {
		global $wpdb;

		$wpdb->update(
			Schema::table( 'carts' ),
			array(
				'status'             => $status,
				'recovered_order_id' => $order_id,
			),
			array( 'id' => (int) $cart['id'] ),
			array( '%s', '%d' ),
			array( '%d' )
		);
	}

	public function test_date_range_uses_local_day_boundaries(): void {
		$range = DateRange::parse( '2026-11-01', '2026-11-30', new DateTimeZone( 'Asia/Taipei' ) );

		$this->assertSame( '2026-11-01', $range->from );
		$this->assertSame( '2026-11-30', $range->to );
		$this->assertSame( '2026-10-31 16:00:00', $range->from_utc );
		$this->assertSame( '2026-11-30 16:00:00', $range->to_utc );
		$this->assertSame( 30, $range->days() );
	}

	public function test_date_range_defaults_reverses_and_caps_span(): void {
		$tz  = new DateTimeZone( 'UTC' );
		$now = new DateTimeImmutable( '2026-11-18 10:00:00', $tz );

		$default = DateRange::parse( null, null, $tz, $now );
		$this->assertSame( '2026-10-20', $default->from );
		$this->assertSame( '2026-11-18', $default->to );
		$this->assertSame( 30, $default->days() );

		$reversed = DateRange::parse( '2026-11-30', '2026-11-01', $tz );
		$this->assertSame( '2026-11-01', $reversed->from );

		$invalid = DateRange::parse( '2026-02-30', '2026-11-18', $tz );
		$this->assertSame( '2026-10-20', $invalid->from );

		$capped = DateRange::parse( '2024-01-01', '2026-11-18', $tz );
		$this->assertSame( '2025-11-18', $capped->from );
		$this->assertSame( 366, $capped->days() );
	}

	public function test_contact_growth_uses_created_at_and_mix_uses_every_contact(): void {
		$range = $this->range( '2026-01-01', '2026-01-31' );

		$this->contact( 'start@example.test', '2026-01-01 00:00:00', 'subscribed' );
		$this->contact( 'middle@example.test', '2026-01-15 12:00:00', 'unsubscribed' );
		$this->contact( 'outside@example.test', '2026-02-01 00:00:00', 'complained' );

		$summary = ( new ReportService() )->contact_summary( $range );

		$this->assertSame( 2, $summary['new'] );
		$this->assertSame( 3, $summary['total'] );
		$this->assertSame( 1, $summary['status_mix']['subscribed'] );
		$this->assertSame( 1, $summary['status_mix']['unsubscribed'] );
		$this->assertSame( 1, $summary['status_mix']['complained'] );
		$this->assertSame( 0, $summary['status_mix']['bounced'] );
	}

	public function test_order_summary_counts_only_counted_orders_inside_range_and_groups_currency(): void {
		$range   = $this->range( '2026-01-01', '2026-01-31' );
		$contact = $this->contact( 'buyer@example.test', '2025-12-01 00:00:00' );

		$orders = array(
			array( 91001, 'TWD', 100.0, true, '2026-01-01 00:00:00' ),
			array( 91002, 'TWD', 90.0, true, '2026-01-15 10:00:00' ),
			array( 91003, 'TWD', 999.0, false, '2026-01-15 10:00:00' ),
			array( 91004, 'TWD', 500.0, true, '2026-02-01 00:00:00' ),
			array( 91005, 'EUR', 50.0, true, '2026-01-20 10:00:00' ),
		);

		foreach ( $orders as [ $order_id, $currency, $net, $counted, $date ] ) {
			$result = $this->links->upsert(
				array(
					'order_id'     => $order_id,
					'contact_id'   => $contact,
					'billing_email' => 'buyer@example.test',
					'status'       => 'completed',
					'total'        => $net,
					'net_total'    => $net,
					'currency'     => $currency,
					'is_counted'   => $counted,
					'date_created' => $date,
				)
			);
			$this->assertNotWPError( $result );
		}

		$summary = ( new ReportService() )->order_summary( $range );

		$this->assertSame( 3, $summary['orders'] );
		$this->assertSame( 1, $summary['customers'] );
		$this->assertSame( 2, $summary['by_currency']['TWD']['orders'] );
		$this->assertSame( 190.0, $summary['by_currency']['TWD']['revenue'] );
		$this->assertSame( 95.0, $summary['by_currency']['TWD']['average'] );
		$this->assertSame( 1, $summary['by_currency']['EUR']['orders'] );
		$this->assertSame( 50.0, $summary['by_currency']['EUR']['revenue'] );
	}

	public function test_cart_recovery_uses_abandoned_events_and_reports_zero_safe_rate(): void {
		$range = $this->range( '2026-01-01', '2026-01-31' );

		global $wpdb;
		$carts = Schema::table( 'carts' );

		$recovered = $this->make_cart( array( 'total' => 250, 'currency' => 'TWD' ) );
		$lost      = $this->make_cart();
		$completed = $this->make_cart();
		$outside   = $this->make_cart( array( 'total' => 500 ) );

		$wpdb->update( $carts, array( 'status' => 'recovered' ), array( 'id' => (int) $recovered['id'] ), array( '%s' ), array( '%d' ) );
		$wpdb->update( $carts, array( 'status' => 'lost' ), array( 'id' => (int) $lost['id'] ), array( '%s' ), array( '%d' ) );
		$wpdb->update( $carts, array( 'status' => 'completed' ), array( 'id' => (int) $completed['id'] ), array( '%s' ), array( '%d' ) );
		$wpdb->update( $carts, array( 'status' => 'recovered' ), array( 'id' => (int) $outside['id'] ), array( '%s' ), array( '%d' ) );

		$this->cart_event( (int) $recovered['id'], 'abandoned', '2026-01-10 10:00:00' );
		$this->cart_event( (int) $recovered['id'], 'reminder_queued', '2026-01-11 10:00:00' );
		$this->cart_event( (int) $lost['id'], 'abandoned', '2026-01-12 10:00:00' );
		$this->cart_event( (int) $completed['id'], 'abandoned', '2026-01-13 10:00:00' );
		$this->cart_event( (int) $outside['id'], 'abandoned', '2026-02-02 10:00:00' );

		$summary = ( new ReportService() )->cart_summary( $range );

		$this->assertSame( 3, $summary['abandoned'] );
		$this->assertSame( 1, $summary['recovered'] );
		$this->assertSame( 0, $summary['recovered_paid'] );
		$this->assertSame( 1, $summary['lost'] );
		$this->assertSame( 1, $summary['completed'] );
		$this->assertSame( 1, $summary['reminders'] );
		// The cart is recovered but has no attributed order, so the cart total
		// is not reported as revenue and no value is inferred.
		$this->assertSame( array(), $summary['recovered_value'] );
		$this->assertSame( 0.0, (float) $summary['recovery_rate'] );

		$empty = ( new ReportService() )->cart_summary( $this->range( '2020-01-01', '2020-01-31' ) );
		$this->assertSame( 0, $empty['recovered_paid'] );
		$this->assertSame( array(), $empty['recovered_value'] );
		$this->assertNull( $empty['recovery_rate'] );
	}

	public function test_campaign_table_deduplicates_events_and_handles_zero_denominators(): void {
		$range    = $this->range( '2026-01-01', '2026-01-31' );
		$campaign = $this->make_campaign( array( 'title' => 'Alpha', 'status' => Campaigns::STATUS_SENT ) );
		$other    = $this->make_campaign( array( 'title' => 'Beta', 'status' => Campaigns::STATUS_SENT ) );

		$first  = $this->job( $campaign, 'one@example.test', '2026-01-05 10:00:00' );
		$second = $this->job( $campaign, 'two@example.test', '2026-01-06 10:00:00' );

		// Excluded: test send, queued job, accepted at the exclusive end.
		$this->job( $campaign, 'test@example.test', '2026-01-07 10:00:00', 'test' );
		$this->job( $campaign, 'queued@example.test', '2026-01-07 10:00:00', 'campaign', 'queued' );
		$this->job( $campaign, 'late@example.test', '2026-02-01 00:00:00' );

		// Duplicate rows with different event keys must not inflate counters.
		$this->event( $campaign, 'open', '2026-01-08 10:00:00', 51 );
		$this->event( $campaign, 'open', '2026-01-08 11:00:00', 51 );
		$this->event( $campaign, 'click', '2026-01-09 10:00:00', 51, $first, 'https://example.test/a' );
		$this->event( $campaign, 'click', '2026-01-09 11:00:00', 51, $first, 'https://example.test/b' );
		$this->event( $campaign, 'click', '2026-01-09 12:00:00', 52, $second, 'https://example.test/a' );
		$this->event( $campaign, 'bounce', '2026-01-10 10:00:00', 53 );
		$this->event( $campaign, 'bounce', '2026-01-10 11:00:00', 53 );
		$this->event( $campaign, 'complaint', '2026-01-11 10:00:00', 54 );
		$this->event( $campaign, 'unsubscribe', '2026-01-12 10:00:00', 55 );
		$this->event( $campaign, 'unsubscribe', '2026-01-12 11:00:00', 55 );

		// Events without an accepted job still list the campaign, with null rates.
		$this->event( $other, 'open', '2026-01-13 10:00:00', 61 );

		$rows = ( new ReportService() )->campaign_rows( $range );

		$this->assertCount( 2, $rows );

		$alpha = $rows[0];
		$this->assertSame( $campaign, $alpha['campaign_id'] );
		$this->assertSame( 2, $alpha['accepted'] );
		$this->assertSame( 1, $alpha['opens'] );
		$this->assertSame( 2, $alpha['clicks'] );
		$this->assertSame( 1, $alpha['bounces'] );
		$this->assertSame( 1, $alpha['complaints'] );
		$this->assertSame( 1, $alpha['unsubscribes'] );
		$this->assertSame( 0.5, $alpha['open_rate'] );
		$this->assertSame( 1.0, $alpha['click_rate'] );

		$beta = $rows[1];
		$this->assertSame( $other, $beta['campaign_id'] );
		$this->assertSame( 0, $beta['accepted'] );
		$this->assertNull( $beta['open_rate'] );
		$this->assertNull( $beta['bounce_rate'] );
		$this->assertNull( ReportService::rate( 0, 0 ) );
		$this->assertSame( 0.0, ReportService::rate( 0, 5 ) );
	}

	public function test_campaign_rows_honor_date_campaign_status_and_pagination(): void {
		$campaign = $this->make_campaign( array( 'title' => 'Sent in January', 'status' => Campaigns::STATUS_SENT ) );
		$draft    = $this->make_campaign( array( 'title' => 'Draft in January', 'status' => Campaigns::STATUS_DRAFT ) );
		$later    = $this->make_campaign( array( 'title' => 'Sent in February', 'status' => Campaigns::STATUS_SENT ) );

		$this->job( $campaign, 'jan@example.test', '2026-01-05 10:00:00' );
		$this->job( $draft, 'draft@example.test', '2026-01-06 10:00:00' );
		$this->job( $later, 'feb@example.test', '2026-02-05 10:00:00' );

		$service = new ReportService();
		$range   = $this->range( '2026-01-01', '2026-01-31' );

		$this->assertCount( 2, $service->campaign_rows( $range ) );
		$this->assertCount( 1, $service->campaign_rows( $range, array( 'status' => Campaigns::STATUS_SENT ) ) );
		$this->assertSame( $campaign, $service->campaign_rows( $range, array( 'campaign_id' => $campaign ) )[0]['campaign_id'] );

		$table = $service->campaign_table( $range, array( 'per_page' => 1, 'page' => 2 ) );

		$this->assertSame( 2, $table['total'] );
		$this->assertSame( 2, $table['pages'] );
		$this->assertSame( 2, $table['page'] );
		$this->assertCount( 1, $table['rows'] );
		$this->assertSame( 2, $table['totals']['accepted'] );

		$february = $service->campaign_rows( $this->range( '2026-02-01', '2026-02-28' ) );
		$this->assertCount( 1, $february );
		$this->assertSame( $later, $february[0]['campaign_id'] );
	}

	public function test_campaign_status_scope_excludes_trashed_deleted_and_wrong_type_posts(): void {
		$range = $this->range( '2026-01-01', '2026-01-31' );

		$live    = $this->make_campaign( array( 'title' => 'Live sent', 'status' => Campaigns::STATUS_SENT ) );
		$trashed = $this->make_campaign( array( 'title' => 'Trashed sent', 'status' => Campaigns::STATUS_SENT ) );
		$deleted = $this->make_campaign( array( 'title' => 'Deleted sent', 'status' => Campaigns::STATUS_SENT ) );
		$auto    = $this->make_campaign( array( 'title' => 'Auto sent', 'status' => Campaigns::STATUS_SENT ) );

		// A normal post keeps campaign stats but is not a campaign post.
		$foreign = (int) wp_insert_post(
			array(
				'post_type'   => 'post',
				'post_status' => 'publish',
				'post_title'  => 'Not a campaign',
			)
		);

		$ids = array( $live, $trashed, $deleted, $auto, $foreign );

		foreach ( $ids as $index => $id ) {
			$job = $this->job( $id, 'scope-' . $index . '@example.test', '2026-01-05 10:00:00' );
			$this->event( $id, 'open', '2026-01-06 10:00:00', 100 + $index, $job );
			$this->event( $id, 'click', '2026-01-06 11:00:00', 100 + $index, $job, 'https://example.test/scope-' . $index );
		}

		wp_trash_post( $trashed );
		wp_delete_post( $deleted, true );

		global $wpdb;
		$wpdb->update( $wpdb->posts, array( 'post_status' => 'auto-draft' ), array( 'ID' => $auto ) );
		clean_post_cache( $auto );

		$service = new ReportService();

		// The unfiltered table keeps every historical counter, but only the
		// live post can open a detail page.
		$rows = $service->campaign_rows( $range );
		$this->assertCount( 5, $rows );

		$by_id = array_column( $rows, null, 'campaign_id' );

		$this->assertTrue( $by_id[ $live ]['detail_available'] );
		$this->assertSame( Campaigns::STATUS_SENT, $by_id[ $live ]['status'] );

		foreach ( array( $trashed, $deleted, $auto, $foreign ) as $id ) {
			$this->assertFalse( $by_id[ $id ]['detail_available'] );
			$this->assertSame( '', $by_id[ $id ]['status'] );
		}

		$this->assertSame( '#' . $deleted, $by_id[ $deleted ]['name'] );

		$table = $service->campaign_table( $range );
		$this->assertCount( 5, $table['rows'] );
		$this->assertSame( 5, $table['totals']['accepted'] );

		// The status filter matches only the valid live campaign in rows,
		// daily trends and the CSV source.
		$filtered = $service->campaign_rows( $range, array( 'status' => Campaigns::STATUS_SENT ) );
		$this->assertCount( 1, $filtered );
		$this->assertSame( $live, $filtered[0]['campaign_id'] );

		$trends = $service->email_trends( $range, array( 'status' => Campaigns::STATUS_SENT ) );
		$this->assertSame( 1, $trends['accepted']['2026-01-05'] );
		$this->assertSame( 1, $trends['opens']['2026-01-06'] );
		$this->assertSame( 1, $trends['clicks']['2026-01-06'] );

		$sent_table = $service->campaign_table( $range, array( 'status' => Campaigns::STATUS_SENT ) );
		$this->assertSame( 1, $sent_table['total'] );
		$this->assertSame( 1, $sent_table['totals']['accepted'] );

		$csv = ReportCsv::row( $sent_table['rows'][0] );
		$this->assertSame( $live, (int) $csv[0] );
		$this->assertSame( 'Live sent', $csv[1] );

		// Top links keep historical titles and fallbacks, and say when the
		// detail page is not reachable.
		$links = array_column( $service->campaign_top_links( $range ), null, 'campaign_id' );

		$this->assertTrue( $links[ $live ]['detail_available'] );
		$this->assertFalse( $links[ $trashed ]['detail_available'] );
		$this->assertSame( 'Trashed sent', $links[ $trashed ]['name'] );
		$this->assertFalse( $links[ $deleted ]['detail_available'] );
		$this->assertSame( '#' . $deleted, $links[ $deleted ]['name'] );
		$this->assertFalse( $links[ $auto ]['detail_available'] );

		$sent_links = $service->campaign_top_links( $range, array( 'status' => Campaigns::STATUS_SENT ) );
		$this->assertCount( 1, $sent_links );
		$this->assertSame( $live, $sent_links[0]['campaign_id'] );
		$this->assertTrue( $sent_links[0]['detail_available'] );
	}

	public function test_campaign_detail_lists_links_and_events_and_rejects_unknown_ids(): void {
		$range    = $this->range( '2026-01-01', '2026-01-31' );
		$campaign = $this->make_campaign( array( 'title' => 'Detail', 'status' => Campaigns::STATUS_SENT ) );
		$job      = $this->job( $campaign, 'detail@example.test', '2026-01-05 10:00:00' );

		$this->event( $campaign, 'click', '2026-01-06 10:00:00', 71, $job, 'https://example.test/offer' );
		$this->event( $campaign, 'open', '2026-01-06 11:00:00', 71 );

		$detail = ( new ReportService() )->campaign_detail( $campaign, $range );

		$this->assertNotNull( $detail );
		$this->assertSame( 1, $detail['campaign']['accepted'] );
		$this->assertSame( 1, $detail['campaign']['clicks'] );
		$this->assertSame( 'https://example.test/offer', $detail['links'][0]['url'] );
		$this->assertSame( 1, $detail['links'][0]['recipients'] );
		$this->assertNotEmpty( $detail['events'] );

		$this->assertNull( ( new ReportService() )->campaign_detail( 999999, $range ) );
	}

	public function test_csv_output_is_formula_safe_and_uses_the_same_campaign_scope(): void {
		$range    = $this->range( '2026-01-01', '2026-01-31' );
		$campaign = $this->make_campaign( array( 'title' => '=HYPERLINK("http://example.test")', 'status' => Campaigns::STATUS_SENT ) );
		$other    = $this->make_campaign( array( 'title' => 'Other', 'status' => Campaigns::STATUS_SENT ) );

		$this->job( $campaign, 'safe@example.test', '2026-01-05 10:00:00' );
		$this->event( $other, 'open', '2026-01-06 10:00:00', 81 );

		$service = new ReportService();

		$scoped = $service->campaign_rows( $range, array( 'campaign_id' => $campaign ) );
		$this->assertCount( 1, $scoped );
		$this->assertSame( $campaign, $scoped[0]['campaign_id'] );

		$csv = ReportCsv::row( $scoped[0] );
		$this->assertCount( count( ReportCsv::header() ), $csv );
		$this->assertSame( '\'=HYPERLINK("http://example.test")', $csv[1] );

		$rows = $service->campaign_rows( $range );
		$this->assertCount( 2, $rows );

		$other_row = $rows[ array_search( $other, array_column( $rows, 'campaign_id' ), true ) ];
		$other_csv = ReportCsv::row( $other_row );

		$this->assertSame( '', $other_csv[9] );
		$this->assertSame( '', $other_csv[10] );
		$this->assertSame( 'nowcrm-report-2026-01-01-2026-01-31.csv', ReportCsv::filename( $range ) );
	}

	public function test_month_shift_clamps_at_month_end_without_overflow(): void {
		$this->assertSame( '2026-02-28', DateRange::shift_months( '2026-03-31', -1 ) );
		$this->assertSame( '2025-12-31', DateRange::shift_months( '2026-01-31', -1 ) );
		$this->assertSame( '2023-02-28', DateRange::shift_months( '2024-02-29', -12 ) );
		$this->assertSame( '2026-04-30', DateRange::shift_months( '2026-01-31', 3 ) );
	}

	public function test_contact_growth_zero_fills_local_days_and_and_filters_membership(): void {
		$list = $this->terms->create( TermRepository::TYPE_LIST, 'Report list' );
		$tag  = $this->terms->create( TermRepository::TYPE_TAG, 'Report tag' );
		$this->assertIsInt( $list );
		$this->assertIsInt( $tag );

		$both = $this->contact( 'both@example.test', '2026-03-08 05:00:00' );
		$only = $this->contact( 'list-only@example.test', '2026-03-08 06:00:00' );

		$this->terms->attach( $both, (int) $list );
		$this->terms->attach( $both, (int) $tag );
		$this->terms->attach( $only, (int) $list );

		$zone  = new DateTimeZone( 'America/New_York' );
		$range = DateRange::parse( '2026-03-07', '2026-03-09', $zone );

		$all = ( new ReportService() )->contact_growth( $range );

		$this->assertSame( array( '2026-03-07', '2026-03-08', '2026-03-09' ), array_keys( $all ) );
		$this->assertSame( 0, $all['2026-03-07'] );
		$this->assertSame( 2, $all['2026-03-08'] );
		$this->assertSame( 0, $all['2026-03-09'] );

		$filtered = ( new ReportService() )->contact_growth( $range, (int) $list, (int) $tag );

		$this->assertSame( 1, $filtered['2026-03-08'] );
		$this->assertSame( 0, ( new ReportService() )->contact_new_count( $range, 999999, 0 ) );
	}

	public function test_contact_growth_keeps_dst_local_day_boundaries(): void {
		$this->contact( 'before-midnight@example.test', '2026-03-08 04:59:59' );
		$this->contact( 'after-midnight@example.test', '2026-03-08 05:00:00' );
		$this->contact( 'late-est@example.test', '2026-03-09 03:59:59' );
		$this->contact( 'edt-midnight@example.test', '2026-03-09 04:00:00' );

		$range = DateRange::parse( '2026-03-07', '2026-03-09', new DateTimeZone( 'America/New_York' ) );
		$days  = ( new ReportService() )->contact_growth( $range );

		$this->assertSame( 1, $days['2026-03-07'] );
		$this->assertSame( 2, $days['2026-03-08'] );
		$this->assertSame( 1, $days['2026-03-09'] );
	}

	public function test_contact_growth_handles_fixed_offset_timezone(): void {
		$this->contact( 'before-range@example.test', '2025-12-31 18:14:59' );
		$this->contact( 'midnight-start@example.test', '2025-12-31 18:15:00' );
		$this->contact( 'last-second@example.test', '2026-01-01 18:14:59' );
		$this->contact( 'next-midnight@example.test', '2026-01-01 18:15:00' );

		$range = DateRange::parse( '2026-01-01', '2026-01-02', new DateTimeZone( '+05:45' ) );
		$days  = ( new ReportService() )->contact_growth( $range );

		$this->assertSame( array( '2026-01-01', '2026-01-02' ), array_keys( $days ) );
		$this->assertSame( 2, $days['2026-01-01'] );
		$this->assertSame( 1, $days['2026-01-02'] );
	}

	public function test_cart_recovered_value_counts_paid_attributed_orders_once(): void {
		$range   = $this->range( '2026-01-01', '2026-01-31' );
		$contact = $this->contact( 'cart-buyer@example.test', '2025-12-01 00:00:00' );

		// Captured cart totals differ from the Woo order net totals on purpose.
		// The report must use the paid orders, never carts.total.
		$paid_twd  = $this->make_cart( array( 'total' => 900, 'currency' => 'TWD' ) );
		$paid_eur  = $this->make_cart( array( 'total' => 990, 'currency' => 'EUR' ) );
		$unpaid    = $this->make_cart( array( 'total' => 700, 'currency' => 'TWD' ) );
		$pending   = $this->make_cart( array( 'total' => 650, 'currency' => 'TWD' ) );
		$failed    = $this->make_cart( array( 'total' => 600, 'currency' => 'TWD' ) );
		$natural   = $this->make_cart( array( 'total' => 500, 'currency' => 'TWD' ) );
		$duplicate = $this->make_cart( array( 'total' => 900, 'currency' => 'TWD' ) );

		$order_twd     = $this->order_link( $contact, 250.55, 'TWD', 'processing', true );
		$order_eur     = $this->order_link( $contact, 50.0, 'EUR', 'completed', true );
		$order_unpaid  = $this->order_link( $contact, 700.0, 'TWD', 'processing', false );
		$order_pending = $this->order_link( $contact, 650.0, 'TWD', 'pending', true );
		$order_failed  = $this->order_link( $contact, 600.0, 'TWD', 'failed', true );
		$order_natural = $this->order_link( $contact, 500.0, 'TWD', 'completed', true );

		$this->settle_cart( $paid_twd, 'recovered', $order_twd );
		$this->settle_cart( $paid_eur, 'recovered', $order_eur );
		$this->settle_cart( $unpaid, 'recovered', $order_unpaid );
		$this->settle_cart( $pending, 'recovered', $order_pending );
		$this->settle_cart( $failed, 'recovered', $order_failed );
		// A second recovered cart points at the same order id. The order must
		// count once even when attribution is duplicated across carts.
		$this->settle_cart( $duplicate, 'recovered', $order_twd );
		$this->settle_cart( $natural, 'completed', $order_natural );

		$this->cart_event( (int) $paid_twd['id'], 'abandoned', '2026-01-10 10:00:00' );
		// The same cart is abandoned twice. It stays one cart and one order.
		$this->cart_event( (int) $paid_twd['id'], 'abandoned', '2026-01-12 10:00:00' );
		$this->cart_event( (int) $paid_eur['id'], 'abandoned', '2026-01-11 10:00:00' );
		$this->cart_event( (int) $unpaid['id'], 'abandoned', '2026-01-13 10:00:00' );
		$this->cart_event( (int) $pending['id'], 'abandoned', '2026-01-14 10:00:00' );
		$this->cart_event( (int) $failed['id'], 'abandoned', '2026-01-15 10:00:00' );
		$this->cart_event( (int) $duplicate['id'], 'abandoned', '2026-01-16 10:00:00' );
		$this->cart_event( (int) $natural['id'], 'abandoned', '2026-01-17 10:00:00' );

		$summary = ( new ReportService() )->cart_summary( $range );

		$this->assertSame( 7, $summary['abandoned'] );
		$this->assertSame( 6, $summary['recovered'] );
		$this->assertSame( 1, $summary['completed'] );
		$this->assertSame( 2, $summary['recovered_paid'] );

		$this->assertCount( 2, $summary['recovered_value'] );
		$this->assertEqualsWithDelta( 250.55, $summary['recovered_value']['TWD'], 0.0001 );
		$this->assertEqualsWithDelta( 50.0, $summary['recovered_value']['EUR'], 0.0001 );
		$this->assertEqualsWithDelta( 2 / 7, (float) $summary['recovery_rate'], 0.0001 );
	}

	public function test_cart_summary_separates_period_events_from_current_state(): void {
		$range   = $this->range( '2026-01-01', '2026-01-31' );
		$contact = $this->contact( 'cart-events@example.test', '2025-12-01 00:00:00' );

		// A fresh email-attributed order exists but is still pending. It has no
		// payment date, so it must not turn into paid revenue.
		$pending       = $this->make_cart( array( 'total' => 800, 'currency' => 'TWD' ) );
		$pending_order = $this->order_link( $contact, 800.0, 'TWD', 'pending', false );

		$this->settle_cart_source( $pending, 'order_pending', $pending_order, 'email_click' );

		foreach ( array( 'abandoned', 'reminder_queued', 'reminder_sent', 'recovery_click', 'cart_restored', 'order_placed' ) as $index => $type ) {
			$this->cart_event( (int) $pending['id'], $type, '2026-01-0' . ( 5 + $index ) . ' 10:00:00' );
		}

		// The same cart also has events outside the range. Period counters
		// follow occurred_at, so these rows must not bleed into January.
		$this->cart_event( (int) $pending['id'], 'reminder_queued', '2025-12-31 23:59:59' );
		$this->cart_event( (int) $pending['id'], 'reminder_queued', '2026-02-01 00:00:00' );
		$this->cart_event( (int) $pending['id'], 'cart_reactivated', '2026-01-20 10:00:00' );

		// Email-attributed cart that reached a real paid order.
		$paid       = $this->make_cart( array( 'total' => 500, 'currency' => 'EUR' ) );
		$paid_order = $this->order_link( $contact, 120.25, 'EUR', 'processing', true );

		$this->settle_cart_source( $paid, 'recovered', $paid_order, 'email_click' );

		foreach ( array( 'abandoned', 'reminder_queued', 'reminder_sent', 'recovery_click', 'cart_restored', 'order_placed', 'order_paid' ) as $index => $type ) {
			$this->cart_event( (int) $paid['id'], $type, '2026-01-1' . $index . ' 10:00:00' );
		}

		// Natural order: it is completed and paid, but it is not email
		// attributed, so its amount must stay out of the recovered value.
		$natural       = $this->make_cart( array( 'total' => 300, 'currency' => 'TWD' ) );
		$natural_order = $this->order_link( $contact, 300.0, 'TWD', 'completed', true );

		$this->settle_cart( $natural, 'completed', $natural_order );
		$this->cart_event( (int) $natural['id'], 'abandoned', '2026-01-21 10:00:00' );
		$this->cart_event( (int) $natural['id'], 'order_placed', '2026-01-22 10:00:00' );
		$this->cart_event( (int) $natural['id'], 'order_paid', '2026-01-22 10:05:00' );

		$lost = $this->make_cart();
		$this->settle_cart( $lost, 'lost', 0 );
		$this->cart_event( (int) $lost['id'], 'abandoned', '2026-01-25 10:00:00' );

		// Woo can store a counted status and a paid date on an offline or COD
		// transition while payment policy keeps the cart order_pending. The
		// report must not promote that order into paid revenue.
		$offline       = $this->make_cart( array( 'total' => 640, 'currency' => 'TWD' ) );
		$offline_order = $this->order_link( $contact, 640.0, 'TWD', 'processing', true );

		$this->settle_cart_source( $offline, 'order_pending', $offline_order, 'email_click' );
		$this->cart_event( (int) $offline['id'], 'abandoned', '2026-01-27 10:00:00' );
		$this->cart_event( (int) $offline['id'], 'order_placed', '2026-01-27 10:05:00' );

		$summary = ( new ReportService() )->cart_summary( $range );

		// Current state of the abandoned cohort, one row per cart.
		$this->assertSame( 5, $summary['abandoned'] );
		$this->assertSame( 0, $summary['abandoned_open'] );
		$this->assertSame( 2, $summary['order_pending'] );
		$this->assertSame( 1, $summary['recovered'] );
		$this->assertSame( 1, $summary['completed'] );
		$this->assertSame( 1, $summary['lost'] );

		// Only the paid email-attributed order whose cart is recovered is
		// revenue. The fresh pending order, the order_pending cart with a Woo
		// paid date, and the natural order are all excluded.
		$this->assertSame( 1, $summary['recovered_paid'] );
		$this->assertSame( array( 'EUR' => 120.25 ), $summary['recovered_value'] );
		$this->assertEqualsWithDelta( 1 / 5, (float) $summary['recovery_rate'], 0.0001 );

		// Period event counts come from occurred_at, not from current state.
		$this->assertSame(
			array(
				'reminder_queued' => 2,
				'reminder_sent'   => 2,
				'recovery_click'  => 2,
				'cart_restored'   => 2,
				'order_placed'    => 4,
				'order_paid'      => 2,
			),
			$summary['period_events']
		);
		$this->assertSame( 2, $summary['reminders'] );
	}

	public function test_email_trends_dedup_daily_and_follow_current_status_scope(): void {
		$range    = $this->range( '2026-01-01', '2026-01-31' );
		$campaign = $this->make_campaign( array( 'title' => 'Trend sent', 'status' => Campaigns::STATUS_SENT ) );
		$draft    = $this->make_campaign( array( 'title' => 'Trend draft', 'status' => Campaigns::STATUS_DRAFT ) );

		$job = $this->job( $campaign, 'one@example.test', '2026-01-05 10:00:00' );

		$this->event( $campaign, 'open', '2026-01-05 11:00:00', 51, $job );
		$this->event( $campaign, 'open', '2026-01-05 12:00:00', 51, $job );
		$this->event( $campaign, 'open', '2026-01-06 11:00:00', 51, $job );
		$this->event( $campaign, 'click', '2026-01-05 13:00:00', 51, $job, 'https://example.test/trend' );
		$this->event( $draft, 'open', '2026-01-05 11:00:00', 61 );
		$this->event( $draft, 'click', '2026-01-05 14:00:00', 61, 0, 'https://example.test/draft' );

		$service = new ReportService();
		$trends  = $service->email_trends( $range, array( 'status' => Campaigns::STATUS_SENT ) );

		$this->assertSame( 1, $trends['accepted']['2026-01-05'] );
		$this->assertSame( 1, $trends['opens']['2026-01-05'] );
		$this->assertSame( 1, $trends['opens']['2026-01-06'] );
		$this->assertSame( 0, $trends['opens']['2026-01-07'] );

		$rows = $service->campaign_rows( $range, array( 'status' => Campaigns::STATUS_SENT ) );
		$this->assertSame( 1, $rows[0]['opens'] );

		$top = $service->campaign_top_links( $range, array( 'status' => Campaigns::STATUS_SENT ) );
		$this->assertSame( 'https://example.test/trend', $top[0]['url'] );

		$draft_top = $service->campaign_top_links( $range, array( 'status' => Campaigns::STATUS_DRAFT ) );
		$this->assertSame( 'https://example.test/draft', $draft_top[0]['url'] );
	}

	public function test_automation_summary_and_trend_use_retained_run_rows(): void {
		$range = $this->range( '2026-01-01', '2026-01-31' );
		$first = $this->contact( 'run-one@example.test', '2026-01-01 00:00:00' );
		$two   = $this->contact( 'run-two@example.test', '2026-01-01 00:00:00' );

		global $wpdb;

		$automations = Schema::table( 'automations' );
		$runs        = Schema::table( 'automation_runs' );

		$wpdb->insert(
			$automations,
			array(
				'name'        => 'Report automation',
				'status'      => 'active',
				'trigger_key' => 'contact_created',
				'created_at'  => '2026-01-01 00:00:00',
				'updated_at'  => '2026-01-01 00:00:00',
			)
		);
		$automation = (int) $wpdb->insert_id;

		foreach ( array( array( $first, '2026-01-10 10:00:00', 'active' ), array( $two, '2026-01-11 10:00:00', 'completed' ) ) as [ $contact_id, $started, $status ] ) {
			$wpdb->insert(
				$runs,
				array(
					'automation_id'  => $automation,
					'contact_id'     => (int) $contact_id,
					'status'         => $status,
					'source_trigger' => 'contact_created',
					'started_at'     => $started,
					'updated_at'     => $started,
				)
			);
		}

		$service = new ReportService();
		$summary = $service->automation_summary( $range );
		$trend   = $service->automation_trend( $range );

		$this->assertSame( 2, $summary['total'] );
		$this->assertSame( 1, $summary['automations'] );
		$this->assertCount( 1, $summary['rows'] );
		$this->assertSame( 'Report automation', $summary['rows'][0]['name'] );
		$this->assertSame( 1, $summary['rows'][0]['active'] );
		$this->assertSame( 1, $summary['rows'][0]['completed'] );
		$this->assertSame( 1, $trend['2026-01-10'] );
		$this->assertSame( 1, $trend['2026-01-11'] );
		$this->assertSame( 0, $trend['2026-01-12'] );
	}
}
