<?php
/**
 * Contact repository tests.
 *
 * @package NowCRM
 */

use NowCRM\Contacts\Status;
use NowCRM\Support\Schema;

/**
 * @covers \NowCRM\Contacts\ContactRepository
 */
final class ContactsTest extends NowCrmTestCase {

	public function test_create_normalizes_email(): void {
		$id = $this->make_contact( array( 'email' => '  Mixed.Case@Example.TEST  ' ) );

		$contact = $this->contacts->get( $id );

		$this->assertSame( 'mixed.case@example.test', $contact['email_normalized'] );
		$this->assertSame( 'mixed.case@example.test', $contact['email'] );
		$this->assertSame( Status::SUBSCRIBED, $contact['status'] );
		$this->assertNotNull( $contact['created_at'] );
	}

	public function test_duplicate_email_is_rejected_case_insensitively(): void {
		$this->make_contact( array( 'email' => 'dupe@example.test' ) );

		$result = $this->contacts->create( array( 'email' => 'DUPE@example.test' ) );

		$this->assertWPError( $result );
		$this->assertSame( 'nowcrm_duplicate_email', $result->get_error_code() );
		$this->assertSame( 1, $this->table_count( 'contacts' ) );
	}

	public function test_invalid_email_is_rejected(): void {
		$result = $this->contacts->create( array( 'email' => 'not-an-email' ) );

		$this->assertWPError( $result );
		$this->assertSame( 'nowcrm_invalid_email', $result->get_error_code() );
	}

	public function test_invalid_status_is_rejected(): void {
		$result = $this->contacts->create(
			array(
				'email'  => 'status@example.test',
				'status' => 'vip',
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'nowcrm_invalid_status', $result->get_error_code() );
	}

	public function test_get_or_create_reports_creation_once(): void {
		$first  = $this->contacts->get_or_create( 'New@Example.test' );
		$second = $this->contacts->get_or_create( 'new@example.test' );

		$this->assertTrue( $first['created'] );
		$this->assertFalse( $second['created'] );
		$this->assertSame( (int) $first['contact']['id'], (int) $second['contact']['id'] );
	}

	public function test_update_email_conflict_is_rejected(): void {
		$first  = $this->make_contact( array( 'email' => 'first@example.test' ) );
		$second = $this->make_contact( array( 'email' => 'second@example.test' ) );

		$result = $this->contacts->update( $second, array( 'email' => 'FIRST@example.test' ) );

		$this->assertWPError( $result );
		$this->assertSame( 'nowcrm_duplicate_email', $result->get_error_code() );
		$this->assertSame( 'second@example.test', $this->contacts->get( $second )['email'] );
	}

	public function test_update_changes_profile_fields_only(): void {
		$id = $this->make_contact();

		$this->assertTrue(
			$this->contacts->update(
				$id,
				array(
					'first_name' => '  Mei ',
					'phone'      => '0912345678',
				)
			)
		);

		$contact = $this->contacts->get( $id );

		$this->assertSame( 'Mei', $contact['first_name'] );
		$this->assertSame( '0912345678', $contact['phone'] );
		$this->assertSame( Status::SUBSCRIBED, $contact['status'], 'update() must not silently change status.' );
	}

	public function test_search_filters_and_paginates(): void {
		$alpha = $this->make_contact(
			array(
				'email'      => 'alpha@example.test',
				'first_name' => 'Alpha',
			)
		);

		$this->make_contact( array( 'email' => 'beta@example.test', 'first_name' => 'Beta' ) );

		$by_name = $this->contacts->search( array( 'search' => 'Alph' ) );

		$this->assertSame( 1, $by_name['total'] );
		$this->assertSame( $alpha, (int) $by_name['items'][0]['id'] );

		$paged = $this->contacts->search( array( 'per_page' => 1, 'page' => 2 ) );

		$this->assertSame( 2, $paged['total'] );
		$this->assertCount( 1, $paged['items'] );

		$escaped = $this->contacts->search( array( 'search' => '%' ) );

		$this->assertSame( 0, $escaped['total'], 'LIKE wildcards from the search box must be escaped.' );
	}

	public function test_search_supports_phone_contact_id_and_multi_word_names(): void {
		$id = $this->make_contact(
			array(
				'email'      => 'mei.chen@example.test',
				'first_name' => 'Mei',
				'last_name'  => 'Chen',
				'phone'      => '0912-345-678',
			)
		);

		$other = $this->make_contact(
			array(
				'email'      => 'other@example.test',
				'first_name' => 'Other',
				'last_name'  => 'Person',
				'phone'      => '0987-654-321',
			)
		);

		$by_phone = $this->contacts->search( array( 'search' => '0912-345' ) );
		$this->assertSame( 1, $by_phone['total'] );
		$this->assertSame( $id, (int) $by_phone['items'][0]['id'] );

		$by_id = $this->contacts->search( array( 'search' => '#' . $id ) );
		$this->assertSame( 1, $by_id['total'] );
		$this->assertSame( $id, (int) $by_id['items'][0]['id'] );

		$this->assertSame( 1, $this->contacts->search( array( 'search' => 'Mei Chen' ) )['total'] );
		$this->assertSame( 1, $this->contacts->search( array( 'search' => 'Chen Mei' ) )['total'] );
		$this->assertSame( 0, $this->contacts->search( array( 'search' => '#999999' ) )['total'] );

		$this->assertNotSame( $id, $other );
	}

	public function test_search_sort_is_stable_with_id_tiebreak(): void {
		$first  = $this->make_contact(
			array(
				'email'      => 'same-a@example.test',
				'first_name' => 'Same',
				'last_name'  => 'Name',
				'status'     => Status::SUBSCRIBED,
			)
		);
		$second = $this->make_contact(
			array(
				'email'      => 'same-b@example.test',
				'first_name' => 'Same',
				'last_name'  => 'Name',
				'status'     => Status::SUBSCRIBED,
			)
		);
		$third  = $this->make_contact(
			array(
				'email'      => 'same-c@example.test',
				'first_name' => 'Same',
				'last_name'  => 'Name',
				'status'     => Status::UNSUBSCRIBED,
			)
		);

		$asc = $this->contacts->search(
			array(
				'orderby'  => 'first_name',
				'order'    => 'ASC',
				'per_page' => 10,
			)
		);

		$this->assertSame( array( $first, $second, $third ), array_map( 'intval', array_column( $asc['items'], 'id' ) ) );

		$desc = $this->contacts->search(
			array(
				'orderby'  => 'first_name',
				'order'    => 'DESC',
				'per_page' => 10,
			)
		);

		$this->assertSame( array( $third, $second, $first ), array_map( 'intval', array_column( $desc['items'], 'id' ) ) );

		// The same ID tiebreak must hold across page boundaries.
		$page_two = $this->contacts->search(
			array(
				'orderby'  => 'first_name',
				'order'    => 'ASC',
				'per_page' => 1,
				'page'     => 2,
			)
		);

		$this->assertSame( array( $second ), array_map( 'intval', array_column( $page_two['items'], 'id' ) ) );
	}

	public function test_search_by_status_and_term(): void {
		$list = $this->terms->create( 'list', 'VIP' );
		$id   = $this->make_contact( array( 'status' => Status::TRANSACTIONAL ) );
		$other = $this->make_contact();

		$this->terms->attach( $id, $list );

		$this->assertSame( 1, $this->contacts->search( array( 'status' => Status::TRANSACTIONAL ) )['total'] );
		$this->assertSame( 1, $this->contacts->search( array( 'term_id' => $list ) )['total'] );
		$this->assertSame( 2, $this->contacts->search()['total'] );
		$this->assertNotSame( $id, $other );
	}

	public function test_search_attaches_terms_without_n_plus_one(): void {
		$list = $this->terms->create( 'list', '溫釲顧客' );
		$tag  = $this->terms->create( 'tag', '預購' );
		$id   = $this->make_contact();

		$this->terms->attach( $id, $list );
		$this->terms->attach( $id, $tag );

		$result = $this->contacts->search();

		$this->assertCount( 2, $result['items'][0]['terms'] );
	}

	public function test_delete_removes_relations_and_history(): void {
		$id = $this->make_contact();
		$list = $this->terms->create( 'list', 'Temp' );

		$this->terms->attach( $id, $list );
		$this->contacts->set_status( $id, Status::UNSUBSCRIBED, 'test' );

		$this->assertTrue( $this->contacts->delete( $id ) );
		$this->assertNull( $this->contacts->get( $id ) );
		$this->assertSame( 0, $this->table_count( 'contact_terms' ) );
		$this->assertSame( 0, $this->table_count( 'contact_status_events' ) );
	}

	public function test_delete_is_refused_while_orders_are_linked(): void {
		$id    = $this->make_contact( array( 'email' => 'linked@example.test' ) );
		$order = $this->make_order( array( 'billing_email' => 'linked@example.test' ) );

		$this->assertTrue(
			$this->links->upsert(
				array(
					'order_id'   => $order->get_id(),
					'contact_id' => $id,
					'status'     => 'pending',
				)
			)
		);

		$result = $this->contacts->delete( $id );

		$this->assertWPError( $result );
		$this->assertSame( 'nowcrm_has_orders', $result->get_error_code() );
		$this->assertNotNull( $this->contacts->get( $id ) );
	}

	public function test_count_by_status_covers_all_statuses(): void {
		$this->make_contact( array( 'status' => Status::SUBSCRIBED ) );
		$this->make_contact( array( 'status' => Status::UNSUBSCRIBED ) );

		$counts = $this->contacts->count_by_status();

		$this->assertSame(
			array( 'subscribed', 'pending', 'unsubscribed', 'transactional', 'bounced', 'complained', 'spammed' ),
			array_keys( $counts )
		);
		$this->assertSame( 1, $counts[ Status::SUBSCRIBED ] );
		$this->assertSame( 1, $counts[ Status::UNSUBSCRIBED ] );
		$this->assertSame( 0, $counts[ Status::BOUNCED ] );
	}

	public function test_aggregates_derive_from_counted_links(): void {
		$id = $this->make_contact();

		foreach ( array( array( 101, 500, 1 ), array( 102, 250, 0 ) ) as $row ) {
			$this->links->upsert(
				array(
					'order_id'      => $row[0],
					'contact_id'    => $id,
					'status'        => $row[2] ? 'completed' : 'pending',
					'total'         => $row[1],
					'net_total'     => $row[1],
					'is_counted'    => $row[2],
					'currency'      => 'TWD',
					'date_created'  => '2026-01-0' . ( $row[0] - 100 ) . ' 00:00:00',
				)
			);
		}

		$aggregate = $this->contacts->aggregate( $id );

		$this->assertSame( 1, $aggregate['order_count'] );
		$this->assertSame( 500.0, $aggregate['total_spent'] );
		$this->assertSame( '2026-01-01 00:00:00', $aggregate['first_order_at'] );
	}

	public function test_aggregate_many_batches_counted_orders(): void {
		$first  = $this->make_contact( array( 'email' => 'batch-first@example.test' ) );
		$second = $this->make_contact( array( 'email' => 'batch-second@example.test' ) );
		$empty  = $this->make_contact( array( 'email' => 'batch-empty@example.test' ) );

		$links = array(
			array( 201, $first, 500, 1, '2026-02-01 00:00:00' ),
			array( 202, $first, 250, 0, '2026-02-02 00:00:00' ),
			array( 203, $second, 1200, 1, '2026-03-05 00:00:00' ),
			array( 204, $second, 900, 0, '2026-03-06 00:00:00' ),
		);

		foreach ( $links as $link ) {
			$this->links->upsert(
				array(
					'order_id'     => $link[0],
					'contact_id'   => $link[1],
					'status'       => $link[3] ? 'completed' : 'pending',
					'total'        => $link[2],
					'net_total'    => $link[2],
					'is_counted'   => $link[3],
					'currency'     => 'TWD',
					'date_created' => $link[4],
				)
			);
		}

		$aggregates = $this->contacts->aggregate_many( array( $first, $second, $empty ) );

		$this->assertSame( array( $first, $second, $empty ), array_keys( $aggregates ) );
		$this->assertSame( 1, $aggregates[ $first ]['order_count'], 'Uncounted orders must not reach the batch.' );
		$this->assertSame( 500.0, $aggregates[ $first ]['total_spent'] );
		$this->assertSame( '2026-02-01 00:00:00', $aggregates[ $first ]['first_order_at'] );
		$this->assertSame( '2026-02-01 00:00:00', $aggregates[ $first ]['last_order_at'] );
		$this->assertSame( 'TWD', $aggregates[ $first ]['currency'] );
		$this->assertSame( 1, $aggregates[ $second ]['order_count'] );
		$this->assertSame( 1200.0, $aggregates[ $second ]['total_spent'] );
		$this->assertSame( '2026-03-05 00:00:00', $aggregates[ $second ]['last_order_at'] );

		$this->assertSame( 0, $aggregates[ $empty ]['order_count'] );
		$this->assertSame( 0.0, $aggregates[ $empty ]['total_spent'] );
		$this->assertNull( $aggregates[ $empty ]['first_order_at'] );
		$this->assertNull( $aggregates[ $empty ]['last_order_at'] );
		$this->assertSame( '', $aggregates[ $empty ]['currency'] );

		$this->assertSame( array(), $this->contacts->aggregate_many( array() ) );

		$by_id = array_column( $this->contacts->search( array( 'per_page' => 10 ) )['items'], null, 'id' );

		$this->assertSame( $aggregates[ $first ], $by_id[ $first ]['aggregate'] );
		$this->assertSame( $aggregates[ $second ], $by_id[ $second ]['aggregate'] );
		$this->assertSame( $aggregates[ $empty ], $by_id[ $empty ]['aggregate'] );
	}

	public function test_unique_email_index_blocks_direct_duplicates(): void {
		global $wpdb;

		$id = $this->make_contact( array( 'email' => 'unique@example.test' ) );
		$row = $this->contacts->get( $id );

		$wpdb->suppress_errors( true );

		$inserted = $wpdb->insert(
			Schema::table( 'contacts' ),
			array(
				'email'            => 'conflict@example.test',
				'email_normalized' => 'unique@example.test',
				'status'           => 'subscribed',
				'created_at'       => gmdate( 'Y-m-d H:i:s' ),
				'updated_at'       => gmdate( 'Y-m-d H:i:s' ),
			),
			array( '%s', '%s', '%s', '%s', '%s' )
		);

		$wpdb->suppress_errors( false );

		$this->assertFalse( $inserted, 'The unique key must hold even outside the repository.' );
		$this->assertSame( $row['email'], $this->contacts->find_by_email( 'UNIQUE@example.test' )['email'] );
	}
}
