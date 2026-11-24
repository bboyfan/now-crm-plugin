<?php
/**
 * Advanced recipient filters: accepted-send negatives, live references,
 * Woo metrics and items, and participation predicates.
 *
 * No email is sent. Jobs are queued and marked accepted in the database, and
 * open, click and bounce events are recorded directly.
 *
 * @package NowCRM
 */

use NowCRM\Automation\AutomationRepository;
use NowCRM\Campaign\Campaigns;
use NowCRM\Campaign\RecipientFilters;
use NowCRM\Contacts\Status;
use NowCRM\Email\EmailJobRepository;
use NowCRM\Email\Tracking;
use NowCRM\Support\Schema;

/**
 * @covers \NowCRM\Campaign\RecipientFilters
 */
final class RecipientFiltersTest extends NowCrmTestCase {

	private EmailJobRepository $jobs;

	public function set_up(): void {
		parent::set_up();
		$this->set_settings( array( 'woo_sync_enabled' => true ) );
		$this->jobs = new EmailJobRepository();
	}

	/**
	 * Run a groups list through normalize and compile, then return matches.
	 *
	 * @param array<int, array<int, array<string, mixed>>> $groups Groups.
	 * @return int[]
	 */
	private function run_plan( array $groups ): array {
		$plan = RecipientFilters::normalize( $groups );

		$this->assertNotWPError( $plan );

		$compiled = RecipientFilters::compile( $plan );

		global $wpdb;

		$table = Schema::table( 'contacts' );
		$sql   = "SELECT c.id FROM {$table} c WHERE {$compiled['sql']} ORDER BY c.id";

		$ids = array() === $compiled['params']
			? $wpdb->get_col( $sql )
			: $wpdb->get_col( $wpdb->prepare( $sql, $compiled['params'] ) );

		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Campaign with accepted broadcast history.
	 */
	private function campaign_with_history( string $title ): int {
		$id = $this->make_campaign( array( 'title' => $title ) );

		update_post_meta( $id, Campaigns::META_STATUS, Campaigns::STATUS_SENT );

		return $id;
	}

	/**
	 * Queue one job. The caller marks it accepted when the fixture needs it.
	 */
	private function queue_job( int $contact_id, string $email, int $campaign_id, string $kind = 'campaign' ): int {
		static $n = 0;

		++$n;

		$id = $this->jobs->enqueue(
			array(
				'job_key'     => 'fixture-' . $campaign_id . '-' . $contact_id . '-' . $n . '-' . wp_generate_password( 6, false ),
				'kind'        => $kind,
				'campaign_id' => $campaign_id,
				'contact_id'  => $contact_id,
				'recipient'   => $email,
				'subject'     => 'Fixture',
			)
		);

		$this->assertIsInt( $id );

		return (int) $id;
	}

	/**
	 * Mark a queued job accepted by the transport.
	 */
	private function accept_job( int $job_id ): void {
		global $wpdb;

		$updated = $wpdb->update(
			Schema::table( 'email_jobs' ),
			array(
				'status'      => EmailJobRepository::STATUS_ACCEPTED,
				'accepted_at' => gmdate( 'Y-m-d H:i:s' ),
			),
			array( 'id' => $job_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		$this->assertSame( 1, $updated );
	}

	/**
	 * Record one local engagement event.
	 */
	private function record_event( string $type, int $campaign_id, int $job_id, int $contact_id ): void {
		$tracking = new Tracking();

		$this->assertTrue(
			$tracking->record(
				'fixture-' . $type . '-' . $campaign_id . '-' . $contact_id . '-' . $job_id,
				$type,
				$campaign_id,
				$job_id,
				$contact_id
			)
		);
	}

	/**
	 * Contacts across every send state plus a campaign with history.
	 *
	 * @return array<string, int>
	 */
	private function email_world(): array {
		$campaign = $this->campaign_with_history( 'Fixture broadcast alpha' );

		$accepted    = $this->make_contact( array( 'email' => 'accepted-a@example.test' ) );
		$queued      = $this->make_contact( array( 'email' => 'queued-b@example.test' ) );
		$none        = $this->make_contact( array( 'email' => 'none-c@example.test' ) );
		$tested      = $this->make_contact( array( 'email' => 'test-d@example.test' ) );
		$unsubscribed = $this->make_contact(
			array(
				'email'  => 'unsub-e@example.test',
				'status' => Status::UNSUBSCRIBED,
			)
		);
		$bounced     = $this->make_contact( array( 'email' => 'bounced-f@example.test' ) );
		$unsub_none  = $this->make_contact(
			array(
				'email'  => 'unsub-g@example.test',
				'status' => Status::UNSUBSCRIBED,
			)
		);
		$sent_only   = $this->make_contact( array( 'email' => 'sent-h@example.test' ) );

		$job_accepted = $this->queue_job( $accepted, 'accepted-a@example.test', $campaign );
		$this->accept_job( $job_accepted );

		$this->queue_job( $queued, 'queued-b@example.test', $campaign );

		$job_test = $this->queue_job( $tested, 'test-d@example.test', $campaign, 'test' );
		$this->accept_job( $job_test );

		$job_unsub = $this->queue_job( $unsubscribed, 'unsub-e@example.test', $campaign );
		$this->accept_job( $job_unsub );

		$job_bounced = $this->queue_job( $bounced, 'bounced-f@example.test', $campaign );
		$this->accept_job( $job_bounced );

		$job_sent = $this->queue_job( $sent_only, 'sent-h@example.test', $campaign );
		$this->accept_job( $job_sent );

		$this->record_event( 'open', $campaign, $job_accepted, $accepted );
		$this->record_event( 'click', $campaign, $job_accepted, $accepted );
		$this->record_event( 'bounce', $campaign, $job_bounced, $bounced );

		return array(
			'campaign'     => $campaign,
			'accepted'     => $accepted,
			'queued'       => $queued,
			'none'         => $none,
			'tested'       => $tested,
			'unsubscribed' => $unsubscribed,
			'bounced'      => $bounced,
			'unsub_none'   => $unsub_none,
			'sent_only'    => $sent_only,
		);
	}

	public function test_sent_matches_only_accepted_broadcast_sends(): void {
		$world = $this->email_world();

		$found = $this->run_plan(
			array(
				array(
					array(
						'provider' => 'email',
						'field'    => 'sent',
						'operator' => 'in',
						'value'    => array( $world['campaign'] ),
					),
				),
			)
		);

		$this->assertSame(
			array( $world['accepted'], $world['unsubscribed'], $world['bounced'], $world['sent_only'] ),
			$this->sorted( $found ),
			'Pending, unsent and test-only sends must not match. An unsubscribed contact with an accepted send still matches: eligibility stays in the audience layer.'
		);
	}

	public function test_sent_negative_includes_pending_unsent_and_test(): void {
		$world = $this->email_world();

		$found = $this->run_plan(
			array(
				array(
					array(
						'provider' => 'email',
						'field'    => 'sent',
						'operator' => 'not_in',
						'value'    => array( $world['campaign'] ),
					),
				),
			)
		);

		$this->assertContains( $world['queued'], $found );
		$this->assertContains( $world['none'], $found );
		$this->assertContains( $world['tested'], $found );
		$this->assertContains( $world['unsub_none'], $found );
		$this->assertNotContains( $world['accepted'], $found );
		$this->assertNotContains( $world['sent_only'], $found );
	}

	public function test_current_eligibility_stays_outside_the_fragment(): void {
		$world    = $this->email_world();
		$plan     = RecipientFilters::normalize(
			array(
				array(
					array(
						'provider' => 'email',
						'field'    => 'sent',
						'operator' => 'in',
						'value'    => array( $world['campaign'] ),
					),
				),
			)
		);
		$compiled = RecipientFilters::compile( $plan );

		$this->assertStringNotContainsString( 'suppressions', $compiled['sql'] );
		$this->assertStringNotContainsString( 'c.status', $compiled['sql'] );
	}

	public function test_opened_and_clicked_require_an_accepted_send(): void {
		$world = $this->email_world();

		$opened = $this->run_plan(
			array(
				array(
					array(
						'provider' => 'email',
						'field'    => 'opened',
						'operator' => 'in',
						'value'    => array( $world['campaign'] ),
					),
				),
			)
		);

		$this->assertSame( array( $world['accepted'] ), $opened );

		$clicked = $this->run_plan(
			array(
				array(
					array(
						'provider' => 'email',
						'field'    => 'clicked',
						'operator' => 'in',
						'value'    => array( $world['campaign'] ),
					),
				),
			)
		);

		$this->assertSame( array( $world['accepted'] ), $clicked );

		$not_opened = $this->run_plan(
			array(
				array(
					array(
						'provider' => 'email',
						'field'    => 'opened',
						'operator' => 'not_in',
						'value'    => array( $world['campaign'] ),
					),
				),
			)
		);

		$this->assertSame(
			$this->sorted( array( $world['bounced'], $world['unsubscribed'], $world['sent_only'] ) ),
			$this->sorted( $not_opened ),
			'Only sent contacts without an open match. Pending, unsent and test contacts are outside the historical send.'
		);
	}

	public function test_received_excludes_bounced_sends(): void {
		$world = $this->email_world();

		$received = $this->run_plan(
			array(
				array(
					array(
						'provider' => 'email',
						'field'    => 'received',
						'operator' => 'in',
						'value'    => array( $world['campaign'] ),
					),
				),
			)
		);

		$this->assertContains( $world['accepted'], $received );
		$this->assertContains( $world['sent_only'], $received );
		$this->assertNotContains( $world['bounced'], $received );
	}

	public function test_stale_campaign_reference_matches_nothing(): void {
		$this->email_world();

		foreach ( array( 'sent', 'received', 'opened', 'clicked' ) as $field ) {
			foreach ( array( 'in', 'not_in' ) as $operator ) {
				$found = $this->run_plan(
					array(
						array(
							array(
								'provider' => 'email',
								'field'    => $field,
								'operator' => $operator,
								'value'    => array( 999999999 ),
							),
						),
					)
				);

				$this->assertSame( array(), $found, "Stale {$field} {$operator} must fail closed." );
			}
		}
	}

	public function test_last_activity_dates(): void {
		$world = $this->email_world();
		$today = (string) current_time( 'Y-m-d' );

		$sent = $this->run_plan(
			array(
				array(
					array(
						'provider' => 'email',
						'field'    => 'last_sent',
						'operator' => 'date_equal',
						'value'    => $today,
					),
				),
			)
		);

		$this->assertContains( $world['accepted'], $sent );
		$this->assertNotContains( $world['none'], $sent );
		$this->assertNotContains( $world['queued'], $sent );

		$opened = $this->run_plan(
			array(
				array(
					array(
						'provider' => 'email',
						'field'    => 'last_opened',
						'operator' => 'date_equal',
						'value'    => $today,
					),
				),
			)
		);

		$this->assertSame( array( $world['accepted'] ), $opened );

		$never_opened = $this->run_plan(
			array(
				array(
					array(
						'provider' => 'email',
						'field'    => 'last_opened',
						'operator' => 'is_null',
						'value'    => null,
					),
				),
			)
		);

		$this->assertContains( $world['none'], $never_opened );
		$this->assertNotContains( $world['accepted'], $never_opened );
	}

	public function test_normalize_returns_typed_values(): void {
		$plan = RecipientFilters::normalize(
			array(
				array(
					array(
						'provider' => 'email',
						'field'    => 'sent',
						'operator' => 'in',
						'value'    => array( '7', 7, '9' ),
					),
					array(
						'provider' => 'email',
						'field'    => 'last_sent',
						'operator' => 'days_within',
						'value'    => '30',
					),
					array(
						'provider' => 'woo',
						'field'    => 'coupons',
						'operator' => 'in',
						'value'    => array( 'SAVE10' ),
					),
				),
			)
		);

		$this->assertNotWPError( $plan );
		$this->assertSame( 1, $plan['version'] );

		$rows = $plan['groups'][0];

		$this->assertSame( array( 7, 9 ), $rows[0]['value'] );
		$this->assertSame( 30, $rows[1]['value'] );
		$this->assertSame( array( 'save10' ), $rows[2]['value'] );
	}

	public function test_normalize_rejects_bad_rows(): void {
		$bad = array(
			array(
				array(
					array(
						'provider' => 'email',
						'field'    => 'nope',
						'operator' => 'in',
						'value'    => array( 1 ),
					),
				),
			),
			array(
				array(
					array(
						'provider' => 'email',
						'field'    => 'sent',
						'operator' => '=',
						'value'    => array( 1, 2 ),
					),
				),
			),
			array(
				array(
					array(
						'provider' => 'email',
						'field'    => 'last_sent',
						'operator' => 'before',
						'value'    => 'not-a-date',
					),
				),
			),
			array(
				array(
					array(
						'provider' => 'email',
						'field'    => 'sent',
						'operator' => 'in',
						'value'    => array(),
					),
				),
			),
		);

		foreach ( $bad as $groups ) {
			$result = RecipientFilters::normalize( $groups );

			$this->assertWPError( $result );
			$this->assertSame( 'nowcrm_advanced_filter_invalid', $result->get_error_code() );
			$this->assertNotEmpty( $result->get_error_data() );
		}
	}

	public function test_catalog_shares_ui_metadata_only(): void {
		$world   = $this->email_world();
		$catalog = RecipientFilters::catalog();

		$this->assertSame( 5, $catalog['limits']['groups'] );
		$this->assertSame( 100, $catalog['limits']['values'] );
		$this->assertArrayHasKey( 'email', $catalog['providers'] );
		$this->assertArrayHasKey( 'woo', $catalog['providers'] );
		$this->assertArrayHasKey( 'automation', $catalog['providers'] );
		$this->assertArrayHasKey( 'sequence', $catalog['providers'] );
		$this->assertArrayHasKey( 'completed', $catalog['operators'] );

		$sent = $catalog['fields']['email.sent'];

		$this->assertSame( array( 'in', 'not_in', '=', '!=' ), $sent['ops'] );
		$this->assertTrue( $sent['lookup'] );
		$this->assertArrayHasKey( (string) $world['campaign'], $sent['options'] );

		$this->assertArrayHasKey( 'contact.email', $catalog['fields'] );

		$json = wp_json_encode( $catalog );

		$this->assertStringNotContainsString( 'EXISTS', (string) $json );
		$this->assertStringNotContainsString( 'SELECT', (string) $json );
		$this->assertStringNotContainsString( 'validate', (string) $json );
		$this->assertStringNotContainsString( 'compile', (string) $json );
	}

	public function test_campaign_options_list_history_and_mark_tests(): void {
		$world = $this->email_world();

		$only_test = $this->make_campaign( array( 'title' => 'Fixture test only' ) );
		update_post_meta( $only_test, Campaigns::META_STATUS, Campaigns::STATUS_SENT );

		$contact = $this->make_contact( array( 'email' => 'option-test@example.test' ) );
		$job     = $this->queue_job( $contact, 'option-test@example.test', $only_test, 'test' );
		$this->accept_job( $job );

		$result = RecipientFilters::options( 'email.sent', 'broadcast alpha', array() );

		$this->assertNotWPError( $result );

		$values = wp_list_pluck( $result['options'], 'value' );

		$this->assertContains( (string) $world['campaign'], $values );
		$this->assertNotContains( (string) $only_test, $values );

		$marked = RecipientFilters::options( 'email.sent', '', array( $world['campaign'] ) );

		$this->assertNotWPError( $marked );
		$this->assertContains( (string) $world['campaign'], wp_list_pluck( $marked['options'], 'value' ) );

		$this->assertWPError( RecipientFilters::options( 'email.nope', '', array() ) );
	}

	/**
	 * One rich order: simple product plus variation, coupon, category and tag.
	 *
	 * @return array<string, mixed>
	 */
	private function woo_world(): array {
		$email = 'woo-buyer@example.test';
		$buyer = $this->make_contact( array( 'email' => $email ) );
		$other = $this->make_contact( array( 'email' => 'woo-other@example.test' ) );

		$cat = wp_insert_term( 'Fixture Buyer Cat', 'product_cat' );
		$this->assertNotWPError( $cat );
		$tag = wp_insert_term( 'Fixture Buyer Tag', 'product_tag' );
		$this->assertNotWPError( $tag );

		$simple = new \WC_Product_Simple();
		$simple->set_name( 'Fixture simple product' );
		$simple->set_regular_price( '100' );
		$simple->set_status( 'publish' );
		$simple->set_category_ids( array( (int) $cat['term_id'] ) );
		$simple->set_tag_ids( array( (int) $tag['term_id'] ) );
		$simple->save();

		$parent = new \WC_Product_Variable();
		$parent->set_name( 'Fixture variable product' );
		$parent->set_status( 'publish' );
		$parent->save();

		$variation = new \WC_Product_Variation();
		$variation->set_parent_id( $parent->get_id() );
		$variation->set_regular_price( '50' );
		$variation->set_status( 'publish' );
		$variation->save();

		$coupon = new \WC_Coupon();
		$coupon->set_code( 'FIXTURE10' );
		$coupon->set_discount_type( 'fixed_cart' );
		$coupon->set_amount( '10' );
		$coupon->save();

		$order = wc_create_order();
		$order->set_billing_email( $email );
		$order->add_product( wc_get_product( $simple->get_id() ), 1 );
		$order->add_product( wc_get_product( $variation->get_id() ), 2 );
		$order->apply_coupon( 'FIXTURE10' );
		$order->calculate_totals();
		$order->save();
		$order->update_status( 'processing' );

		$order = wc_get_order( $order->get_id() );
		$link  = $this->links->find_by_order( $order->get_id() );

		$this->assertNotNull( $link, 'The order sync must link the fixture order.' );
		$this->assertSame( $buyer, (int) $link['contact_id'] );
		$this->assertSame( 1, (int) $link['is_counted'] );

		return array(
			'buyer'     => $buyer,
			'other'     => $other,
			'order'     => $order,
			'link'      => $link,
			'simple'    => $simple->get_id(),
			'parent'    => $parent->get_id(),
			'variation' => $variation->get_id(),
			'category'  => (int) $cat['term_id'],
			'tag'       => (int) $tag['term_id'],
		);
	}

	public function test_woo_count_date_and_currency_metrics(): void {
		$world     = $this->woo_world();
		$aggregate = $this->contacts->aggregate( $world['buyer'] );

		$this->assertSame( 1, (int) $aggregate['order_count'] );

		$count = $this->run_plan(
			array(
				array(
					array(
						'provider' => 'woo',
						'field'    => 'order_count',
						'operator' => '=',
						'value'    => '1',
					),
				),
			)
		);

		$this->assertContains( $world['buyer'], $count );
		$this->assertNotContains( $world['other'], $count );

		$first = $this->run_plan(
			array(
				array(
					array(
						'provider' => 'woo',
						'field'    => 'first_order_date',
						'operator' => 'days_within',
						'value'    => 1,
					),
				),
			)
		);

		$this->assertContains( $world['buyer'], $first );
		$this->assertNotContains( $world['other'], $first );

		$never = $this->run_plan(
			array(
				array(
					array(
						'provider' => 'woo',
						'field'    => 'last_order_date',
						'operator' => 'is_null',
						'value'    => null,
					),
				),
			)
		);

		$this->assertContains( $world['other'], $never );
		$this->assertNotContains( $world['buyer'], $never );

		$currency = strtolower( (string) $aggregate['currency'] );
		$field    = 'woo.total_spent_' . $currency;

		$this->assertArrayHasKey( $field, RecipientFilters::catalog()['fields'] );

		$spent = $this->run_plan(
			array(
				array(
					array(
						'provider' => 'woo',
						'field'    => 'total_spent_' . $currency,
						'operator' => '=',
						'value'    => (string) $aggregate['total_spent'],
					),
				),
			)
		);

		$this->assertContains( $world['buyer'], $spent );
		$this->assertNotContains( $world['other'], $spent );
	}

	public function test_woo_products_variations_taxonomies_and_coupons(): void {
		$world = $this->woo_world();

		foreach ( array( 'simple', 'parent', 'variation' ) as $key ) {
			$found = $this->run_plan(
				array(
					array(
						array(
							'provider' => 'woo',
							'field'    => 'products',
							'operator' => 'in',
							'value'    => array( $world[ $key ] ),
						),
					),
				)
			);

			$this->assertSame( array( $world['buyer'] ), $found, "Product {$key} must match the buyer." );
		}

		$bogus = $this->run_plan(
			array(
				array(
					array(
						'provider' => 'woo',
						'field'    => 'products',
						'operator' => 'in',
						'value'    => array( 999999999 ),
					),
				),
			)
		);

		$this->assertSame( array(), $bogus );

		$not_bought = $this->run_plan(
			array(
				array(
					array(
						'provider' => 'woo',
						'field'    => 'products',
						'operator' => 'not_in',
						'value'    => array( $world['simple'] ),
					),
				),
			)
		);

		$this->assertContains( $world['other'], $not_bought );
		$this->assertNotContains( $world['buyer'], $not_bought );

		foreach ( array( 'categories' => 'category', 'tags' => 'tag' ) as $field => $key ) {
			$found = $this->run_plan(
				array(
					array(
						array(
							'provider' => 'woo',
							'field'    => $field,
							'operator' => 'in',
							'value'    => array( $world[ $key ] ),
						),
					),
				)
			);

			$this->assertSame( array( $world['buyer'] ), $found, "Term {$field} must match the buyer." );

			$stale = $this->run_plan(
				array(
					array(
						array(
							'provider' => 'woo',
							'field'    => $field,
							'operator' => 'in',
							'value'    => array( 999999999 ),
						),
					),
				)
			);

			$this->assertSame( array(), $stale, "Deleted {$field} terms must fail closed." );
		}

		$coupon = $this->run_plan(
			array(
				array(
					array(
						'provider' => 'woo',
						'field'    => 'coupons',
						'operator' => 'in',
						'value'    => array( 'fixture10' ),
					),
				),
			)
		);

		$this->assertSame( array( $world['buyer'] ), $coupon );

		$no_coupon = $this->run_plan(
			array(
				array(
					array(
						'provider' => 'woo',
						'field'    => 'coupons',
						'operator' => 'is_null',
						'value'    => null,
					),
				),
			)
		);

		$this->assertContains( $world['other'], $no_coupon );
		$this->assertNotContains( $world['buyer'], $no_coupon );
	}

	public function test_woo_option_lookups(): void {
		$world = $this->woo_world();

		$products = RecipientFilters::options( 'woo.products', 'Fixture simple', array() );

		$this->assertNotWPError( $products );
		$this->assertContains( (string) $world['simple'], wp_list_pluck( $products['options'], 'value' ) );

		$selected = RecipientFilters::options( 'woo.products', '', array( $world['variation'] ) );

		$this->assertNotWPError( $selected );
		$this->assertContains( (string) $world['variation'], wp_list_pluck( $selected['options'], 'value' ) );

		$categories = RecipientFilters::options( 'woo.categories', 'Buyer Cat', array() );

		$this->assertNotWPError( $categories );
		$this->assertContains( (string) $world['category'], wp_list_pluck( $categories['options'], 'value' ) );

		$coupons = RecipientFilters::options( 'woo.coupons', 'FIXTURE', array( 'fixture10' ) );

		$this->assertNotWPError( $coupons );

		$values = array_map( 'strtolower', wp_list_pluck( $coupons['options'], 'value' ) );

		$this->assertContains( 'fixture10', $values );
	}

	public function test_automation_and_sequence_participation(): void {
		global $wpdb;

		$buyer = $this->make_contact( array( 'email' => 'auto-buyer@example.test' ) );
		$other = $this->make_contact( array( 'email' => 'auto-other@example.test' ) );

		$wpdb->insert(
			Schema::table( 'automations' ),
			array(
				'name'           => 'Fixture flow',
				'status'         => AutomationRepository::STATUS_ACTIVE,
				'trigger_key'    => 'manual',
				'trigger_config' => '{}',
				'entry_config'   => '{}',
				'created_at'     => gmdate( 'Y-m-d H:i:s' ),
				'updated_at'     => gmdate( 'Y-m-d H:i:s' ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		$automation = (int) $wpdb->insert_id;
		$this->assertGreaterThan( 0, $automation );

		$wpdb->insert(
			Schema::table( 'automation_runs' ),
			array(
				'automation_id'  => $automation,
				'contact_id'     => $buyer,
				'status'         => 'active',
				'source_trigger' => 'manual',
				'source_ref'     => 'test',
				'started_at'     => gmdate( 'Y-m-d H:i:s' ),
				'updated_at'     => gmdate( 'Y-m-d H:i:s' ),
			),
			array( '%d', '%d', '%s', '%s', '%s', '%s', '%s' )
		);

		$sequence = wp_insert_post(
			array(
				'post_type'   => \NowCRM\Sequences\SequenceRepository::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'Fixture sequence',
			),
			true
		);

		$this->assertIsInt( $sequence );

		$wpdb->insert(
			Schema::table( 'sequence_trackers' ),
			array(
				'sequence_id' => $sequence,
				'contact_id'  => $buyer,
				'status'      => 'completed',
				'cycle'       => 1,
				'next_index'  => 0,
				'started_at'  => gmdate( 'Y-m-d H:i:s' ),
				'created_at'  => gmdate( 'Y-m-d H:i:s' ),
				'updated_at'  => gmdate( 'Y-m-d H:i:s' ),
			),
			array( '%d', '%d', '%s', '%d', '%d', '%s', '%s', '%s' )
		);

		$in_automation = $this->run_plan(
			array(
				array(
					array(
						'provider' => 'automation',
						'field'    => 'activity',
						'operator' => 'in',
						'value'    => array( $automation ),
					),
				),
			)
		);

		$this->assertSame( array( $buyer ), $in_automation );

		$active = $this->run_plan(
			array(
				array(
					array(
						'provider' => 'automation',
						'field'    => 'activity',
						'operator' => 'active',
						'value'    => $automation,
					),
				),
			)
		);

		$this->assertSame( array( $buyer ), $active );

		$completed = $this->run_plan(
			array(
				array(
					array(
						'provider' => 'automation',
						'field'    => 'activity',
						'operator' => 'completed',
						'value'    => $automation,
					),
				),
			)
		);

		$this->assertSame( array(), $completed );

		$in_sequence = $this->run_plan(
			array(
				array(
					array(
						'provider' => 'sequence',
						'field'    => 'activity',
						'operator' => 'completed',
						'value'    => $sequence,
					),
				),
			)
		);

		$this->assertSame( array( $buyer ), $in_sequence );

		$not_in_sequence = $this->run_plan(
			array(
				array(
					array(
						'provider' => 'sequence',
						'field'    => 'activity',
						'operator' => 'not_in',
						'value'    => array( $sequence ),
					),
				),
			)
		);

		$this->assertContains( $other, $not_in_sequence );
		$this->assertNotContains( $buyer, $not_in_sequence );

		$stale = $this->run_plan(
			array(
				array(
					array(
						'provider' => 'automation',
						'field'    => 'activity',
						'operator' => 'in',
						'value'    => array( 999999999 ),
					),
				),
			)
		);

		$this->assertSame( array(), $stale );

		$auto_options = RecipientFilters::options( 'automation.activity', 'Fixture flow', array( $automation ) );

		$this->assertNotWPError( $auto_options );
		$this->assertContains( (string) $automation, wp_list_pluck( $auto_options['options'], 'value' ) );

		$seq_options = RecipientFilters::options( 'sequence.activity', '', array( $sequence ) );

		$this->assertNotWPError( $seq_options );
		$this->assertContains( (string) $sequence, wp_list_pluck( $seq_options['options'], 'value' ) );
	}

	public function test_mixed_contact_and_campaign_plan(): void {
		$world = $this->email_world();

		$found = $this->run_plan(
			array(
				array(
					array(
						'provider' => 'contact',
						'field'    => 'email',
						'operator' => 'contains',
						'value'    => 'accepted-a@',
					),
					array(
						'provider' => 'email',
						'field'    => 'sent',
						'operator' => 'in',
						'value'    => array( $world['campaign'] ),
					),
				),
			)
		);

		$this->assertSame( array( $world['accepted'] ), $found );
	}

	/**
	 * Sorted copy for order-independent assertions.
	 *
	 * @param int[] $ids IDs.
	 * @return int[]
	 */
	private function sorted( array $ids ): array {
		sort( $ids, SORT_NUMERIC );

		return $ids;
	}
}
