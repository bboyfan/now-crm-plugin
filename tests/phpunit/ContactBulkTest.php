<?php
/**
 * Contact bulk action tests.
 *
 * @package NowCRM
 */

use NowCRM\Contacts\BulkContactAction;
use NowCRM\Contacts\Status;

/**
 * @covers \NowCRM\Contacts\BulkContactAction
 * @covers \NowCRM\Contacts\ContactRepository::search_ids
 * @covers \NowCRM\Contacts\ContactRepository::search_bounds
 */
final class ContactBulkTest extends NowCrmTestCase {

	public function test_selected_rows_leave_unselected_contacts_untouched(): void {
		$list     = $this->terms->create( 'list', 'VIP' );
		$selected = $this->make_contact();
		$other    = $this->make_contact();

		$result = $this->run_bulk(
			array(
				'operation' => BulkContactAction::ATTACH_LIST,
				'ids'       => array( $selected ),
				'term_ids'  => array( $list ),
			)
		);

		$this->assertSame( 'done', $result['status'] );
		$this->assertSame( 1, $result['processed'] );
		$this->assertContains( $list, $this->term_ids_for( $selected ) );
		$this->assertNotContains( $list, $this->term_ids_for( $other ) );
	}

	public function test_filtered_scope_uses_the_list_search_status_and_term_filters(): void {
		$list = $this->terms->create( 'list', 'VIP' );
		$tag  = $this->terms->create( 'tag', 'Buyer' );
		$hit  = $this->make_contact(
			array(
				'first_name' => 'Alpha',
				'status'     => Status::SUBSCRIBED,
			)
		);
		$miss = $this->make_contact(
			array(
				'first_name' => 'Beta',
				'status'     => Status::SUBSCRIBED,
			)
		);
		$unsubscribed = $this->make_contact(
			array(
				'first_name' => 'Alpha',
				'status'     => Status::UNSUBSCRIBED,
			)
		);

		$this->terms->attach( $hit, $tag );
		$this->terms->attach( $miss, $tag );
		$this->terms->attach( $unsubscribed, $tag );

		$result = $this->run_bulk(
			array(
				'operation'      => BulkContactAction::ATTACH_LIST,
				'scope'          => 'all',
				'filters'        => array(
					'search'  => 'Alpha',
					'status'  => Status::SUBSCRIBED,
					'term_id' => $tag,
				),
				'expected_total' => 1,
				'term_ids'       => array( $list ),
			)
		);

		$this->assertSame( 'done', $result['status'] );
		$this->assertSame( 1, $result['matching'] );
		$this->assertSame( 1, $result['processed'] );
		$this->assertContains( $list, $this->term_ids_for( $hit ) );
		$this->assertNotContains( $list, $this->term_ids_for( $miss ) );
		$this->assertNotContains( $list, $this->term_ids_for( $unsubscribed ) );
	}

	public function test_membership_does_not_drop_other_lists_or_tags(): void {
		$keep = $this->terms->create( 'list', 'Keep' );
		$drop = $this->terms->create( 'tag', 'Drop' );
		$add  = $this->terms->create( 'list', 'Add' );
		$id   = $this->make_contact();

		$this->terms->attach( $id, $keep );
		$this->terms->attach( $id, $drop );

		$detach = $this->run_bulk(
			array(
				'operation' => BulkContactAction::DETACH_TAG,
				'ids'       => array( $id ),
				'term_ids'  => array( $drop ),
			)
		);

		$attach = $this->run_bulk(
			array(
				'operation' => BulkContactAction::ATTACH_LIST,
				'ids'       => array( $id ),
				'term_ids'  => array( $add ),
			)
		);

		$term_ids = $this->term_ids_for( $id );

		$this->assertSame( 'done', $detach['status'] );
		$this->assertSame( 'done', $attach['status'] );
		$this->assertContains( $keep, $term_ids );
		$this->assertContains( $add, $term_ids );
		$this->assertNotContains( $drop, $term_ids );
	}

	public function test_delete_keeps_order_linked_contacts_and_reports_them(): void {
		$deletable = $this->make_contact();
		$protected = $this->make_contact();

		$this->assertNotWPError(
			$this->links->upsert(
				array(
					'order_id'      => 918273,
					'contact_id'    => $protected,
					'billing_email' => 'protected@example.test',
					'status'        => 'wc-completed',
					'total'         => 100,
					'net_total'     => 100,
					'currency'      => 'TWD',
					'is_counted'    => 1,
				)
			)
		);

		$result = $this->run_bulk(
			array(
				'operation' => BulkContactAction::DELETE,
				'ids'       => array( $deletable, $protected ),
			)
		);

		$this->assertSame( 'done', $result['status'] );
		$this->assertSame( 1, $result['processed'] );
		$this->assertSame( 1, $result['blocked'] );
		$this->assertSame( array( $protected ), $result['blocked_ids'] );
		$this->assertNull( $this->contacts->get( $deletable ) );
		$this->assertNotNull( $this->contacts->get( $protected ) );
		$this->assertSame( 1, $this->links->count() );
	}

	public function test_filtered_delete_rechecks_the_confirmed_total(): void {
		$this->make_contact();
		$this->make_contact();

		$result = $this->run_bulk(
			array(
				'operation'      => BulkContactAction::DELETE,
				'scope'          => 'all',
				'filters'        => array(),
				'expected_total' => 1,
			)
		);

		$this->assertSame( 'changed', $result['status'] );
		$this->assertSame( 2, $result['matching'] );
		$this->assertSame( 1, $result['expected_total'] );
		$this->assertSame( 0, $result['processed'] );
		$this->assertSame( 2, $this->table_count( 'contacts' ) );
	}

	public function test_filtered_status_change_processes_every_match(): void {
		$ids = array(
			$this->make_contact( array( 'status' => Status::SUBSCRIBED ) ),
			$this->make_contact( array( 'status' => Status::SUBSCRIBED ) ),
			$this->make_contact( array( 'status' => Status::SUBSCRIBED ) ),
		);

		$result = $this->run_bulk(
			array(
				'operation'      => BulkContactAction::SET_STATUS,
				'scope'          => 'all',
				'filters'        => array( 'status' => Status::SUBSCRIBED ),
				'expected_total' => 3,
				'status'         => Status::UNSUBSCRIBED,
			)
		);

		$this->assertSame( 'done', $result['status'] );
		$this->assertSame( 3, $result['processed'] );

		foreach ( $ids as $id ) {
			$this->assertSame( Status::UNSUBSCRIBED, $this->contacts->get( $id )['status'] );
		}

		$events = $this->history->for_contact( $ids[0] );

		$this->assertSame( 'admin:bulk', $events[0]['source'] );
		$this->assertSame( Status::UNSUBSCRIBED, $events[0]['new_status'] );
	}

	public function test_filtered_term_detach_clears_every_match(): void {
		$list = $this->terms->create( 'list', 'Seasonal' );
		$ids  = array(
			$this->make_contact(),
			$this->make_contact(),
			$this->make_contact(),
		);

		foreach ( $ids as $id ) {
			$this->terms->attach( $id, $list );
		}

		$result = $this->run_bulk(
			array(
				'operation'      => BulkContactAction::DETACH_LIST,
				'scope'          => 'all',
				'filters'        => array( 'term_id' => $list ),
				'expected_total' => 3,
				'term_ids'       => array( $list ),
			)
		);

		$this->assertSame( 'done', $result['status'] );
		$this->assertSame( 3, $result['processed'] );

		foreach ( $ids as $id ) {
			$this->assertNotContains( $list, $this->term_ids_for( $id ) );
		}
	}

	public function test_invalid_status_and_term_payloads_write_nothing(): void {
		$list = $this->terms->create( 'list', 'List' );
		$tag  = $this->terms->create( 'tag', 'Tag' );
		$id   = $this->make_contact();

		$bad_status = $this->run_bulk(
			array(
				'operation' => BulkContactAction::SET_STATUS,
				'ids'       => array( $id ),
				'status'    => 'vip',
			)
		);

		$wrong_type = $this->run_bulk(
			array(
				'operation' => BulkContactAction::ATTACH_TAG,
				'ids'       => array( $id ),
				'term_ids'  => array( $list ),
			)
		);

		$no_terms = $this->run_bulk(
			array(
				'operation' => BulkContactAction::DETACH_LIST,
				'ids'       => array( $id ),
				'term_ids'  => array(),
			)
		);

		$this->assertSame( 'invalid', $bad_status['status'] );
		$this->assertSame( 'status', $bad_status['reason'] );
		$this->assertSame( 'invalid', $wrong_type['status'] );
		$this->assertSame( 'term', $wrong_type['reason'] );
		$this->assertSame( 'invalid', $no_terms['status'] );
		$this->assertSame( 0, $this->table_count( 'contact_terms' ) );

		$contact = $this->contacts->get( $id );

		$this->assertSame( Status::SUBSCRIBED, $contact['status'] );
		$this->assertNotContains( $tag, $this->term_ids_for( $id ) );
	}

	public function test_later_batch_respects_ceiling_and_first_pass_abort(): void {
		$first  = $this->make_contact();
		$second = $this->make_contact();

		$aborted = $this->run_bulk(
			array(
				'operation'      => BulkContactAction::DELETE,
				'scope'          => 'all',
				'filters'        => array(),
				'expected_total' => 1,
			)
		);

		$this->assertSame( 'changed', $aborted['status'] );
		$this->assertSame( 2, $aborted['matching'] );
		$this->assertSame( 2, $this->table_count( 'contacts' ) );

		$bounded = $this->run_bulk(
			array(
				'operation'      => BulkContactAction::DELETE,
				'scope'          => 'all',
				'filters'        => array(),
				'expected_total' => 2,
				'cursor'         => 0,
				'max_id'         => $first,
			)
		);

		$this->assertSame( 'done', $bounded['status'] );
		$this->assertTrue( $bounded['completed'] );
		$this->assertSame( $first, $bounded['cursor'] );
		$this->assertSame( $first, $bounded['max_id'] );
		$this->assertSame( 1, $bounded['processed'] );
		$this->assertNull( $this->contacts->get( $first ) );
		$this->assertNotNull( $this->contacts->get( $second ) );

		$late = $this->make_contact();

		$next = $this->run_bulk(
			array(
				'operation'      => BulkContactAction::DELETE,
				'scope'          => 'all',
				'filters'        => array(),
				'expected_total' => 2,
				'cursor'         => $first,
				'max_id'         => $first,
			)
		);

		$this->assertSame( 'done', $next['status'] );
		$this->assertTrue( $next['completed'] );
		$this->assertSame( 0, $next['processed'] );
		$this->assertNotNull( $this->contacts->get( $late ) );
		$this->assertNotNull( $this->contacts->get( $second ) );
	}

	/**
	 * Run a bulk request with test-safe defaults.
	 *
	 * @param array<string, mixed> $request Request overrides.
	 * @return array<string, mixed>
	 */
	private function run_bulk( array $request ): array {
		return ( new BulkContactAction( $this->contacts, $this->terms ) )->run(
			array_merge(
				array(
					'operation'      => '',
					'scope'          => 'selected',
					'ids'            => array(),
					'filters'        => array(),
					'expected_total' => 0,
					'term_ids'       => array(),
					'status'         => '',
					'actor_id'       => 0,
				),
				$request
			)
		);
	}

	/**
	 * Term IDs of one contact.
	 *
	 * @return int[]
	 */
	private function term_ids_for( int $contact_id ): array {
		return array_map( 'intval', array_column( $this->terms->for_contact( $contact_id ), 'id' ) );
	}
}
