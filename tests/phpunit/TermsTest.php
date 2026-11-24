<?php
/**
 * Lists and tags tests.
 *
 * @package NowCRM
 */

/**
 * @covers \NowCRM\Terms\TermRepository
 */
final class TermsTest extends NowCrmTestCase {

	public function test_create_list_and_tag(): void {
		$list = $this->terms->create( 'list', '溫釲顧客' );
		$tag  = $this->terms->create( 'tag', '預購包包' );

		$this->assertIsInt( $list );
		$this->assertIsInt( $tag );
		$this->assertSame( 'list', $this->terms->get( $list )['type'] );
		$this->assertSame( '預購包包', $this->terms->get( $tag )['name'] );
	}

	public function test_slug_is_unique_per_type_only(): void {
		$this->assertIsInt( $this->terms->create( 'list', 'VIP' ) );

		$duplicate = $this->terms->create( 'list', 'vip' );

		$this->assertWPError( $duplicate );
		$this->assertSame( 'nowcrm_duplicate_term', $duplicate->get_error_code() );

		$other_type = $this->terms->create( 'tag', 'VIP' );

		$this->assertIsInt( $other_type );
	}

	public function test_invalid_type_is_rejected(): void {
		$result = $this->terms->create( 'segment', 'Nope' );

		$this->assertWPError( $result );
		$this->assertSame( 'nowcrm_invalid_term_type', $result->get_error_code() );
	}

	public function test_ensure_is_idempotent_and_uses_the_filtered_name(): void {
		add_filter( 'nowcrm_customer_list_name', static fn(): string => '自訂顧客名單' );

		$this->assertSame( '自訂顧客名單', \NowCRM\Terms\TermRepository::customer_list_name() );

		$first  = $this->terms->ensure( 'list', \NowCRM\Terms\TermRepository::customer_list_name() );
		$second = $this->terms->ensure( 'list', \NowCRM\Terms\TermRepository::customer_list_name() );

		$this->assertSame( $first, $second );
		$this->assertSame( 1, $this->table_count( 'terms' ) );
	}

	public function test_attach_and_detach_are_idempotent(): void {
		$list = $this->terms->create( 'list', 'VIP' );
		$id   = $this->make_contact();

		$this->assertTrue( $this->terms->attach( $id, $list ) );
		$this->assertTrue( $this->terms->attach( $id, $list ) );
		$this->assertSame( 1, $this->table_count( 'contact_terms' ) );

		$this->assertTrue( $this->terms->detach( $id, $list ) );
		$this->assertTrue( $this->terms->detach( $id, $list ) );
		$this->assertSame( 0, $this->table_count( 'contact_terms' ) );
	}

	public function test_sync_for_contact_replaces_the_set(): void {
		$keep   = $this->terms->create( 'list', 'Keep' );
		$drop   = $this->terms->create( 'tag', 'Drop' );
		$add    = $this->terms->create( 'tag', 'Add' );
		$id     = $this->make_contact();

		$this->terms->attach( $id, $keep );
		$this->terms->attach( $id, $drop );

		$this->terms->sync_for_contact( $id, array( $keep, $add ) );

		$slugs = array_column( $this->terms->for_contact( $id ), 'slug' );

		$this->assertContains( 'keep', $slugs );
		$this->assertContains( 'add', $slugs );
		$this->assertNotContains( 'drop', $slugs );
	}

	public function test_all_returns_contact_counts(): void {
		$list = $this->terms->create( 'list', 'Counted' );
		$id   = $this->make_contact();

		$this->terms->attach( $id, $list );

		$terms = $this->terms->all( 'list' );

		$this->assertCount( 1, $terms );
		$this->assertSame( 1, $terms[0]['contact_count'] );
	}

	public function test_paginate_is_typed_and_reports_total_membership(): void {
		$list  = $this->terms->create( 'list', 'Alpha' );
		$tag   = $this->terms->create( 'tag', 'Tagged' );
		$first = $this->make_contact();
		$next  = $this->make_contact( array( 'status' => \NowCRM\Contacts\Status::UNSUBSCRIBED ) );

		$this->terms->attach( $first, $list );
		$this->terms->attach( $next, $list );
		$this->terms->attach( $first, $tag );

		$page = $this->terms->paginate(
			array(
				'type'     => 'list',
				'per_page' => 10,
			)
		);

		$this->assertSame( 1, $page['total'] );
		$this->assertCount( 1, $page['items'] );
		$this->assertSame( $list, $page['items'][0]['id'] );
		$this->assertSame( 2, $page['items'][0]['contact_count'] );
		$this->assertSame( 1, $page['items'][0]['subscribed_count'] );
	}

	public function test_paginate_searches_name_slug_and_description(): void {
		$match = $this->terms->create(
			'list',
			'VIP Buyers',
			array(
				'slug'        => 'vip-buyers',
				'description' => '高價值顧客',
			)
		);
		$this->terms->create( 'list', 'Newsletter' );

		foreach ( array( 'VIP', 'vip-buyers', '高價值' ) as $search ) {
			$page = $this->terms->paginate(
				array(
					'type'   => 'list',
					'search' => $search,
				)
			);

			$this->assertSame( 1, $page['total'], 'Search failed for: ' . $search );
			$this->assertSame( $match, $page['items'][0]['id'] );
		}
	}

	public function test_paginate_sorts_and_pages_by_membership_count(): void {
		$alpha = $this->terms->create( 'tag', 'Alpha' );
		$beta  = $this->terms->create( 'tag', 'Beta' );
		$gamma = $this->terms->create( 'tag', 'Gamma' );

		$first = $this->make_contact();
		$next  = $this->make_contact();

		$this->terms->attach( $first, $alpha );
		$this->terms->attach( $next, $alpha );
		$this->terms->attach( $first, $gamma );

		$ordered = $this->terms->paginate(
			array(
				'type'     => 'tag',
				'orderby'  => 'contact_count',
				'order'    => 'DESC',
				'per_page' => 2,
				'page'     => 1,
			)
		);

		$this->assertSame( 3, $ordered['total'] );
		$this->assertSame(
			array( $alpha, $gamma ),
			array_column( $ordered['items'], 'id' )
		);

		$rest = $this->terms->paginate(
			array(
				'type'     => 'tag',
				'orderby'  => 'contact_count',
				'order'    => 'DESC',
				'per_page' => 2,
				'page'     => 2,
			)
		);

		$this->assertSame( array( $beta ), array_column( $rest['items'], 'id' ) );

		$by_name = $this->terms->paginate(
			array(
				'type'    => 'tag',
				'orderby' => 'not-a-column',
			)
		);

		$this->assertSame(
			array( $alpha, $beta, $gamma ),
			array_column( $by_name['items'], 'id' )
		);
	}

	public function test_delete_many_removes_one_type_and_keeps_the_other(): void {
		$list = $this->terms->create( 'list', 'Shared' );
		$tag  = $this->terms->create( 'tag', 'Shared' );
		$id   = $this->make_contact();

		$this->terms->attach( $id, $list );
		$this->terms->attach( $id, $tag );

		$this->assertSame( 1, $this->terms->delete_many( 'list', array( $list, $tag ) ) );

		$this->assertNull( $this->terms->get( $list ) );
		$this->assertSame( array(), $this->terms->contact_ids( $list ) );

		$this->assertSame( 'tag', $this->terms->get( $tag )['type'] );
		$this->assertSame( array( $id ), $this->terms->contact_ids( $tag ) );
	}

	public function test_create_many_upserts_by_slug_and_keeps_description_and_relations(): void {
		$existing = $this->terms->create( 'list', 'VIP', array( 'description' => 'Original' ) );
		$contact  = $this->make_contact();

		$this->terms->attach( $contact, $existing );

		$result = $this->terms->create_many(
			'list',
			array(
				array(
					'name'        => 'VIP renamed',
					'slug'        => 'vip',
					'description' => 'Changed',
				),
				array( 'title' => 'From Title' ),
				array( 'name' => 'New List' ),
				array(
					'name' => 'New List updated',
					'slug' => 'new-list',
				),
				array(
					'name' => ' ',
					'slug' => 'blank',
				),
			)
		);

		$this->assertSame( 2, $result['created'] );
		$this->assertSame( 2, $result['updated'] );
		$this->assertSame( 1, $result['skipped'] );

		$stored = $this->terms->get( $existing );

		$this->assertSame( 'VIP renamed', $stored['name'] );
		$this->assertSame( 'vip', $stored['slug'] );
		$this->assertSame( 'Original', $stored['description'] );
		$this->assertSame( array( $contact ), $this->terms->contact_ids( $existing ) );

		$created = $this->terms->find_by_slug( 'list', 'new-list' );

		$this->assertNotNull( $created );
		$this->assertSame( 'New List updated', $created['name'] );
		$this->assertSame( 3, $this->table_count( 'terms' ) );
	}

	public function test_update_keeps_slug_when_omitted_and_rejects_an_empty_one(): void {
		$list    = $this->terms->create( 'list', 'VIP' );
		$contact = $this->make_contact();

		$this->terms->attach( $contact, $list );

		$this->assertTrue( $this->terms->update( $list, array( 'description' => 'Updated' ) ) );

		$stored = $this->terms->get( $list );

		$this->assertSame( 'vip', $stored['slug'] );
		$this->assertSame( 'Updated', $stored['description'] );
		$this->assertSame( array( $contact ), $this->terms->contact_ids( $list ) );

		$rejected = $this->terms->update( $list, array( 'slug' => '###' ) );

		$this->assertWPError( $rejected );
		$this->assertSame( 'nowcrm_invalid_term_slug', $rejected->get_error_code() );
		$this->assertSame( 'vip', $this->terms->get( $list )['slug'] );
		$this->assertSame( array( $contact ), $this->terms->contact_ids( $list ) );
	}

	/**
	 * Record `nowcrm_term_*` events as [event, id, type] tuples.
	 *
	 * @param array<int, array{0: string, 1: int, 2: string}> $events Event sink.
	 */
	private function record_term_events( array &$events ): void {
		$record = static function ( string $event ) use ( &$events ): callable {
			return static function ( $term_id, $term_type ) use ( &$events, $event ): void {
				$events[] = array( $event, (int) $term_id, (string) $term_type );
			};
		};

		add_action( 'nowcrm_term_created', $record( 'created' ), 10, 2 );
		add_action( 'nowcrm_term_updated', $record( 'updated' ), 10, 2 );
		add_action( 'nowcrm_term_deleted', $record( 'deleted' ), 10, 2 );
	}

	public function test_term_lifecycle_hooks_fire_once_per_successful_mutation(): void {
		$events = array();
		$this->record_term_events( $events );

		$list = $this->terms->create( 'list', 'Hook List' );

		$this->assertWPError( $this->terms->create( 'list', 'Hook List' ) );
		$this->assertTrue( $this->terms->update( $list, array() ) );
		$this->assertTrue( $this->terms->update( $list, array( 'description' => 'Changed' ) ) );
		$this->assertTrue( $this->terms->delete( $list, 'list' ) );

		$tag = $this->terms->create( 'tag', 'Hook Tag' );

		$this->assertTrue( $this->terms->delete( $tag ) );

		$this->assertSame(
			array(
				array( 'created', $list, 'list' ),
				array( 'updated', $list, 'list' ),
				array( 'deleted', $list, 'list' ),
				array( 'created', $tag, 'tag' ),
				array( 'deleted', $tag, 'tag' ),
			),
			$events
		);
	}

	public function test_bulk_term_hooks_report_exactly_the_mutated_rows(): void {
		$existing = $this->terms->create( 'list', 'Bulk Existing', array( 'description' => 'Keep' ) );
		$tag      = $this->terms->create( 'tag', 'Bulk Tag' );

		$events = array();
		$this->record_term_events( $events );

		$result = $this->terms->create_many(
			'list',
			array(
				array(
					'name' => 'Bulk Renamed',
					'slug' => 'bulk-existing',
				),
				array( 'name' => 'Bulk New' ),
				array( 'name' => '  ' ),
			)
		);

		$new = $this->terms->find_by_slug( 'list', 'bulk-new' );

		$this->assertSame( 1, $result['created'] );
		$this->assertSame( 1, $result['updated'] );
		$this->assertNotNull( $new );
		$this->assertSame(
			array(
				array( 'updated', $existing, 'list' ),
				array( 'created', (int) $new['id'], 'list' ),
			),
			$events
		);

		$events = array();

		$this->assertSame( 1, $this->terms->delete_many( 'list', array( $existing, $tag, 999999 ) ) );
		$this->assertSame( array( array( 'deleted', $existing, 'list' ) ), $events );
		$this->assertSame( 'tag', $this->terms->get( $tag )['type'] );
	}

	public function test_delete_removes_relations(): void {
		$list = $this->terms->create( 'list', 'Temp' );
		$id   = $this->make_contact();

		$this->terms->attach( $id, $list );
		$this->assertTrue( $this->terms->delete( $list ) );

		$this->assertNull( $this->terms->get( $list ) );
		$this->assertSame( 0, $this->table_count( 'contact_terms' ) );
		$this->assertSame( array(), $this->terms->contact_ids( $list ) );
	}
}
