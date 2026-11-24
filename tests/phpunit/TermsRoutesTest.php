<?php
/**
 * Lists and tags REST contract tests.
 *
 * @package NowCRM
 */

use NowCRM\Contacts\Status;

/**
 * @covers \NowCRM\Terms\Routes
 */
final class TermsRoutesTest extends NowCrmTestCase {

	private int $admin = 0;

	public function set_up(): void {
		parent::set_up();

		// Build the real REST server so plugin modules register their routes.
		rest_get_server();

		$this->admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin );
	}

	private function api( string $method, string $path, array $data = array() ): WP_REST_Response {
		$request = new WP_REST_Request( $method, '/nowcrm/v1' . $path );

		if ( 'GET' === $method ) {
			$request->set_query_params( $data );
		} else {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( wp_json_encode( $data ) );
		}

		return rest_do_request( $request );
	}

	public function test_requires_manage_contacts_capability(): void {
		wp_set_current_user( 0 );

		$this->assertSame( 401, $this->api( 'GET', '/lists' )->get_status() );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$this->assertSame( 403, $this->api( 'GET', '/lists' )->get_status() );
		$this->assertSame( 403, $this->api( 'POST', '/tags/bulk', array( 'items' => array( array( 'name' => 'x' ) ) ) )->get_status() );

		wp_set_current_user( $this->admin );

		$this->assertSame( 200, $this->api( 'GET', '/lists' )->get_status() );
		$this->assertSame( 200, $this->api( 'GET', '/tags' )->get_status() );
	}

	public function test_index_is_typed_searches_and_reports_counts(): void {
		$list   = $this->terms->create( 'list', 'VIP Buyers', array( 'slug' => 'vip-buyers', 'description' => '高價值顧客' ) );
		$tag    = $this->terms->create( 'tag', 'VIP Tag' );
		$member = $this->make_contact();
		$gone   = $this->make_contact( array( 'status' => Status::UNSUBSCRIBED ) );

		$this->terms->attach( $member, $list );
		$this->terms->attach( $gone, $list );
		$this->terms->attach( $member, $tag );

		$response = $this->api(
			'GET',
			'/lists',
			array(
				'search'   => 'vip',
				'orderby'  => 'contact_count',
				'order'    => 'desc',
				'per_page' => 10,
			)
		);

		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();

		$this->assertSame( 1, $data['total'] );
		$this->assertSame( 1, $data['page'] );
		$this->assertSame( 10, $data['per_page'] );
		$this->assertSame( 1, $data['pages'] );
		$this->assertCount( 1, $data['items'] );

		$item = $data['items'][0];

		$this->assertSame( $list, $item['id'] );
		$this->assertSame( 'list', $item['type'] );
		$this->assertSame( 'VIP Buyers', $item['name'] );
		$this->assertSame( 'vip-buyers', $item['slug'] );
		$this->assertSame( '高價值顧客', $item['description'] );
		$this->assertSame( 2, $item['contact_count'] );
		$this->assertSame( 1, $item['subscribed_count'] );

		$tags = $this->api( 'GET', '/tags', array( 'search' => 'vip' ) )->get_data();

		$this->assertSame( 1, $tags['total'] );
		$this->assertSame( $tag, $tags['items'][0]['id'] );
		$this->assertSame( 'tag', $tags['items'][0]['type'] );
		$this->assertSame( 1, $tags['items'][0]['subscribed_count'] );
	}

	public function test_find_by_id_and_slug_is_type_scoped(): void {
		$list = $this->terms->create( 'list', 'Alpha', array( 'slug' => 'alpha' ) );
		$tag  = $this->terms->create( 'tag', 'Beta', array( 'slug' => 'beta' ) );

		$this->assertSame( 200, $this->api( 'GET', '/lists/' . $list )->get_status() );
		$this->assertSame( 'alpha', $this->api( 'GET', '/lists/' . $list )->get_data()['slug'] );

		$tagged = $this->api( 'GET', '/tags/' . $tag );

		$this->assertSame( 200, $tagged->get_status() );
		$this->assertSame( 'tag', $tagged->get_data()['type'] );

		$crossed = $this->api( 'GET', '/lists/' . $tag );

		$this->assertSame( 404, $crossed->get_status() );
		$this->assertSame( 'nowcrm_not_found', $crossed->get_data()['code'] );

		$this->assertSame( 200, $this->api( 'GET', '/lists/by-slug/alpha' )->get_status() );
		$this->assertSame( 404, $this->api( 'GET', '/tags/by-slug/alpha' )->get_status() );
		$this->assertSame( 200, $this->api( 'GET', '/tags/by-slug/beta' )->get_status() );
	}

	public function test_create_and_update_validate_and_keep_type_isolation(): void {
		$created = $this->api(
			'POST',
			'/lists',
			array(
				'name'        => 'New List',
				'slug'        => 'new-list',
				'description' => 'First',
			)
		);

		$this->assertSame( 201, $created->get_status() );

		$row = $created->get_data();

		$this->assertSame( 'list', $row['type'] );
		$this->assertSame( 'new-list', $row['slug'] );
		$this->assertSame( 'First', $row['description'] );
		$this->assertIsInt( $row['id'] );

		$id = $row['id'];

		$updated = $this->api( 'PUT', '/lists/' . $id, array( 'description' => 'Second' ) );

		$this->assertSame( 200, $updated->get_status() );
		$this->assertSame( 'New List', $updated->get_data()['name'] );
		$this->assertSame( 'new-list', $updated->get_data()['slug'] );
		$this->assertSame( 'Second', $updated->get_data()['description'] );

		$duplicate = $this->api( 'POST', '/lists', array( 'name' => 'New List' ) );

		$this->assertSame( 409, $duplicate->get_status() );
		$this->assertSame( 'nowcrm_duplicate_term', $duplicate->get_data()['code'] );

		$invalid = $this->api( 'POST', '/lists', array( 'name' => '   ' ) );

		$this->assertSame( 400, $invalid->get_status() );
		$this->assertSame( 'nowcrm_invalid_term_name', $invalid->get_data()['code'] );

		$this->assertSame( 404, $this->api( 'PUT', '/tags/' . $id, array( 'name' => 'Nope' ) )->get_status() );
		$this->assertSame( 400, $this->api( 'PUT', '/lists/' . $id, array() )->get_status() );

		$bad_slug = $this->api( 'PUT', '/lists/' . $id, array( 'slug' => '###' ) );

		$this->assertSame( 400, $bad_slug->get_status() );
		$this->assertSame( 'nowcrm_invalid_term_slug', $bad_slug->get_data()['code'] );
	}

	public function test_delete_is_type_scoped_and_removes_relations(): void {
		$list    = $this->terms->create( 'list', 'Temp List' );
		$tag     = $this->terms->create( 'tag', 'Temp Tag' );
		$contact = $this->make_contact();

		$this->terms->attach( $contact, $list );
		$this->terms->attach( $contact, $tag );

		$this->assertSame( 404, $this->api( 'DELETE', '/tags/' . $list )->get_status() );
		$this->assertNotNull( $this->terms->get( $list ) );
		$this->assertSame( array( $contact ), $this->terms->contact_ids( $list ) );

		$deleted = $this->api( 'DELETE', '/lists/' . $list );

		$this->assertSame( 200, $deleted->get_status() );
		$this->assertTrue( $deleted->get_data()['deleted'] );
		$this->assertNull( $this->terms->get( $list ) );
		$this->assertSame( array(), $this->terms->contact_ids( $list ) );

		$this->assertSame( 404, $this->api( 'DELETE', '/lists/' . $list )->get_status() );
		$this->assertSame( 'tag', $this->terms->get( $tag )['type'] );
		$this->assertSame( array( $contact ), $this->terms->contact_ids( $tag ) );
	}

	public function test_bulk_upsert_updates_name_and_preserves_description_and_relations(): void {
		$existing = $this->terms->create( 'list', 'VIP', array( 'description' => 'Original' ) );
		$contact  = $this->make_contact();

		$this->terms->attach( $contact, $existing );

		$response = $this->api(
			'POST',
			'/lists/bulk',
			array(
				'items' => array(
					array(
						'name'        => 'VIP Renamed',
						'slug'        => 'vip',
						'description' => 'Changed',
					),
					array( 'name' => 'Second List' ),
					array(
						'name' => 'Second List Updated',
						'slug' => 'second-list',
					),
					array( 'name' => '   ' ),
				),
			)
		);

		$this->assertSame( 200, $response->get_status() );

		$result = $response->get_data();

		$this->assertSame( 1, $result['created'] );
		$this->assertSame( 2, $result['updated'] );
		$this->assertSame( 1, $result['skipped'] );

		$stored = $this->terms->get( $existing );

		$this->assertSame( 'VIP Renamed', $stored['name'] );
		$this->assertSame( 'vip', $stored['slug'] );
		$this->assertSame( 'Original', $stored['description'] );
		$this->assertSame( array( $contact ), $this->terms->contact_ids( $existing ) );

		$second = $this->terms->find_by_slug( 'list', 'second-list' );

		$this->assertNotNull( $second );
		$this->assertSame( 'Second List Updated', $second['name'] );

		$tags = $this->api(
			'POST',
			'/tags/bulk',
			array( 'items' => array( array( 'name' => 'Tag VIP', 'slug' => 'vip' ) ) )
		);

		$this->assertSame( 200, $tags->get_status() );
		$this->assertSame( 1, $tags->get_data()['created'] );
		$this->assertNotNull( $this->terms->find_by_slug( 'tag', 'vip' ) );
	}

	public function test_bulk_delete_is_bounded_and_type_scoped(): void {
		$list    = $this->terms->create( 'list', 'Drop List' );
		$tag     = $this->terms->create( 'tag', 'Keep Tag' );
		$contact = $this->make_contact();

		$this->terms->attach( $contact, $list );
		$this->terms->attach( $contact, $tag );

		$response = $this->api( 'POST', '/lists/bulk-delete', array( 'ids' => array( $list, $tag ) ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 1, $response->get_data()['deleted'] );
		$this->assertSame( 2, $response->get_data()['requested'] );
		$this->assertNull( $this->terms->get( $list ) );
		$this->assertSame( 'tag', $this->terms->get( $tag )['type'] );
		$this->assertSame( array( $contact ), $this->terms->contact_ids( $tag ) );

		$this->assertSame( 400, $this->api( 'POST', '/lists/bulk-delete', array( 'ids' => array() ) )->get_status() );
		$this->assertSame( 400, $this->api( 'POST', '/lists/bulk-delete', array( 'ids' => 'nope' ) )->get_status() );
		$this->assertSame( 400, $this->api( 'POST', '/lists/bulk-delete', array( 'ids' => array( -1 ) ) )->get_status() );
		$this->assertSame(
			400,
			$this->api( 'POST', '/lists/bulk-delete', array( 'ids' => array_fill( 0, 201, 1 ) ) )->get_status()
		);
	}

	public function test_malformed_or_oversized_payloads_fail_safely(): void {
		$this->assertSame(
			400,
			$this->api(
				'POST',
				'/lists/bulk',
				array( 'items' => array_fill( 0, 501, array( 'name' => 'x' ) ) )
			)->get_status()
		);

		$this->assertSame(
			400,
			$this->api( 'POST', '/lists/bulk', array( 'items' => array( array( 'name' => array( 'nested' ) ) ) ) )->get_status()
		);

		$this->assertSame(
			400,
			$this->api( 'POST', '/lists/bulk', array( 'items' => 'nope' ) )->get_status()
		);

		$this->assertSame( 400, $this->api( 'GET', '/lists', array( 'per_page' => 500 ) )->get_status() );
		$this->assertSame( 400, $this->api( 'GET', '/lists', array( 'orderby' => 'unknown' ) )->get_status() );
	}
}
