<?php
/**
 * Advanced contact filter parser, validator, and SQL compiler.
 *
 * @package NowCRM
 */

use NowCRM\Admin\ContactAdvancedFilters;
use NowCRM\Contacts\AdvancedFilters;
use NowCRM\Contacts\BulkContactAction;
use NowCRM\Contacts\CsvExport;
use NowCRM\Contacts\CustomFieldDefinitions;
use NowCRM\Contacts\CustomFieldValues;
use NowCRM\Contacts\Status;
use NowCRM\Support\Schema;
use NowCRM\Terms\TermRepository;

/**
 * @covers \NowCRM\Contacts\AdvancedFilters
 */
final class ContactAdvancedFiltersTest extends NowCrmTestCase {

	public function set_up(): void {
		parent::set_up();

		delete_option( CustomFieldDefinitions::OPTION );
		delete_option( CustomFieldDefinitions::GROUPS_OPTION );
	}

	/**
	 * Contact IDs matching a filter, in ascending order.
	 *
	 * The compiler output is the same WHERE fragment the repository can use:
	 * alias `c`, placeholders for `$wpdb->prepare()`.
	 *
	 * @param array<int, array<int, array<string, mixed>>> $groups Raw groups.
	 * @return int[]
	 */
	private function filter_ids( array $groups ): array {
		$plan = AdvancedFilters::normalize( $groups );

		$this->assertNotWPError( $plan, 'The filter should validate.' );

		$compiled = AdvancedFilters::compile( $plan );
		$table    = Schema::table( 'contacts' );
		$sql      = "SELECT c.id FROM {$table} c WHERE {$compiled['sql']} ORDER BY c.id ASC";

		global $wpdb;

		$ids = $compiled['params']
			? $wpdb->get_col( $wpdb->prepare( $sql, $compiled['params'] ) )
			: $wpdb->get_col( $sql );

		$ids = array_map( 'intval', (array) $ids );

		sort( $ids );

		return $ids;
	}

	/**
	 * Assert the exact matching set.
	 *
	 * @param int[]                                            $expected Expected contact IDs.
	 * @param array<int, array<int, array<string, mixed>>> $groups   Raw groups.
	 */
	private function assert_filter_ids( array $expected, array $groups ): void {
		sort( $expected );

		$this->assertSame( $expected, $this->filter_ids( $groups ) );
	}

	/**
	 * Overwrite the stored creation time.
	 */
	private function set_created_at( int $contact_id, string $datetime ): void {
		global $wpdb;

		$wpdb->update(
			Schema::table( 'contacts' ),
			array( 'created_at' => $datetime ),
			array( 'id' => $contact_id ),
			array( '%s' ),
			array( '%d' )
		);
	}

	public function test_and_within_a_group_and_or_between_groups(): void {
		$taipei_alice = $this->make_contact(
			array(
				'first_name' => 'Alice',
				'city'       => 'Taipei',
			)
		);
		$taipei_bob   = $this->make_contact(
			array(
				'first_name' => 'Bob',
				'city'       => 'Taipei',
			)
		);
		$tainan_alice = $this->make_contact(
			array(
				'first_name' => 'Alice',
				'city'       => 'Tainan',
			)
		);
		$tainan_bob   = $this->make_contact(
			array(
				'first_name' => 'Bob',
				'city'       => 'Tainan',
			)
		);

		$groups = array(
			array(
				array(
					'provider' => 'contact',
					'field'    => 'city',
					'operator' => '=',
					'value'    => 'Taipei',
				),
				array(
					'provider' => 'contact',
					'field'    => 'first_name',
					'operator' => '=',
					'value'    => 'Alice',
				),
			),
			array(
				array(
					'provider' => 'contact',
					'field'    => 'first_name',
					'operator' => '=',
					'value'    => 'Bob',
				),
			),
		);

		// (Taipei AND Alice) OR Bob. The AND group never returns the Tainan Alice row.
		$this->assert_filter_ids(
			array( $taipei_alice, $taipei_bob, $tainan_bob ),
			$groups
		);
	}

	public function test_invalid_filter_is_rejected_before_search(): void {
		$unknown_field = AdvancedFilters::normalize(
			array(
				array(
					array(
						'provider' => 'contact',
						'field'    => 'shoe_size',
						'operator' => '=',
						'value'    => '42',
					),
				),
			)
		);

		$this->assertWPError( $unknown_field );
		$this->assertSame( 'nowcrm_advanced_filter_invalid', $unknown_field->get_error_code() );

		$wrong_operator = AdvancedFilters::normalize(
			array(
				array(
					array(
						'provider' => 'contact',
						'field'    => 'city',
						'operator' => 'in_all',
						'value'    => array( 'Taipei' ),
					),
				),
			)
		);

		$this->assertWPError( $wrong_operator );
		$this->assertSame( 'unknown_operator', $wrong_operator->get_error_data()['reason'] );

		$empty_group = AdvancedFilters::normalize( array( array() ) );
		$this->assertWPError( $empty_group );

		$empty_value = AdvancedFilters::normalize(
			array(
				array(
					array(
						'provider' => 'contact',
						'field'    => 'city',
						'operator' => 'contains',
						'value'    => '   ',
					),
				),
			)
		);

		$this->assertWPError( $empty_value );
	}

	public function test_unknown_compiled_condition_fails_closed(): void {
		$this->make_contact();

		$compiled = AdvancedFilters::compile(
			array(
				'groups' => array(
					array(
						array(
							'provider' => 'contact',
							'field'    => 'does_not_exist',
							'operator' => '=',
							'value'    => 'x',
						),
					),
				),
			)
		);

		$this->assertStringContainsString( '1=0', $compiled['sql'] );
		$this->assertSame( array(), $compiled['params'] );

		$table = Schema::table( 'contacts' );

		global $wpdb;

		$rows = $wpdb->get_col( "SELECT c.id FROM {$table} c WHERE {$compiled['sql']}" );

		$this->assertSame( array(), $rows );
	}

	public function test_unknown_group_does_not_widen_a_valid_or_group(): void {
		$match = $this->make_contact( array( 'city' => 'Taipei' ) );
		$this->make_contact( array( 'city' => 'Tainan' ) );

		$plan = array(
			'groups' => array(
				array(
					array(
						'provider' => 'contact',
						'field'    => 'city',
						'operator' => '=',
						'value'    => 'Taipei',
					),
				),
				array(
					array(
						'provider' => 'contact',
						'field'    => 'nope',
						'operator' => '=',
						'value'    => 'x',
					),
				),
			),
		);

		// The parser rejects an unknown field before any query runs.
		$normalized = AdvancedFilters::normalize( $plan );

		$this->assertWPError( $normalized );
		$this->assertSame( 'unknown_field', $normalized->get_error_data()['reason'] );

		// A caller that bypasses the parser still cannot widen the result: the
		// unknown group compiles to 1=0 and only the valid group matches.
		$compiled = AdvancedFilters::compile( $plan );
		$table    = Schema::table( 'contacts' );
		$sql      = "SELECT c.id FROM {$table} c WHERE {$compiled['sql']} ORDER BY c.id ASC";

		global $wpdb;

		$ids = $compiled['params']
			? $wpdb->get_col( $wpdb->prepare( $sql, $compiled['params'] ) )
			: $wpdb->get_col( $sql );

		$this->assertSame( array( $match ), array_map( 'intval', (array) $ids ) );
		$this->assertStringContainsString( '1=0', $compiled['sql'] );
	}

	public function test_status_and_profile_columns(): void {
		$mr = $this->make_contact(
			array(
				'first_name'   => 'Mister',
				'email'        => 'mister@example.test',
				'phone'        => '+886 912 345 678',
				'status'       => Status::SUBSCRIBED,
				'prefix'       => 'Mr',
				'contact_type' => 'customer',
				'country'      => 'TW',
			)
		);
		$ms = $this->make_contact(
			array(
				'first_name'   => 'Miss',
				'email'        => 'miss@example.test',
				'status'       => Status::UNSUBSCRIBED,
				'prefix'       => 'Ms',
				'contact_type' => 'lead',
				'country'      => 'US',
			)
		);

		$this->assert_filter_ids(
			array( $mr ),
			array(
				array(
					array( 'provider' => 'segment', 'field' => 'status', 'operator' => 'in', 'value' => array( Status::SUBSCRIBED ) ),
					array( 'provider' => 'segment', 'field' => 'contact_type', 'operator' => 'in', 'value' => array( 'customer' ) ),
					array( 'provider' => 'contact', 'field' => 'country', 'operator' => 'in', 'value' => array( 'TW' ) ),
					array( 'provider' => 'contact', 'field' => 'prefix', 'operator' => 'in', 'value' => array( 'Mr' ) ),
				),
			)
		);

		$this->assert_filter_ids(
			array( $ms ),
			array(
				array(
					array( 'provider' => 'segment', 'field' => 'status', 'operator' => 'not_in', 'value' => array( Status::SUBSCRIBED ) ),
					array( 'provider' => 'contact', 'field' => 'prefix', 'operator' => 'in', 'value' => array( 'Ms' ) ),
				),
			)
		);

		$this->assert_filter_ids(
			array( $mr ),
			array(
				array(
					array( 'provider' => 'contact', 'field' => 'phone', 'operator' => 'contains', 'value' => '912' ),
				),
			)
		);

		$this->assert_filter_ids(
			array( $ms ),
			array(
				array(
					array( 'provider' => 'contact', 'field' => 'email', 'operator' => '=', 'value' => 'miss@example.test' ),
				),
			)
		);

		// A status outside the vocabulary is a hard error, not an empty filter.
		$invalid = AdvancedFilters::normalize(
			array(
				array(
					array( 'provider' => 'segment', 'field' => 'status', 'operator' => 'in', 'value' => array( 'vip' ) ),
				),
			)
		);

		$this->assertWPError( $invalid );
	}

	public function test_term_inclusion_exclusion_and_empty_membership(): void {
		$vip  = $this->terms->create( TermRepository::TYPE_LIST, 'VIP' );
		$hot  = $this->terms->create( TermRepository::TYPE_LIST, 'Hot' );
		$tag  = $this->terms->create( TermRepository::TYPE_TAG, 'Buyer' );

		$this->assertIsInt( $vip );
		$this->assertIsInt( $hot );
		$this->assertIsInt( $tag );

		$both_lists = $this->make_contact();
		$vip_only   = $this->make_contact();
		$tag_only   = $this->make_contact();
		$none       = $this->make_contact();

		$this->terms->attach( $both_lists, $vip );
		$this->terms->attach( $both_lists, $hot );
		$this->terms->attach( $both_lists, $tag );

		$this->terms->attach( $vip_only, $vip );

		$this->terms->attach( $tag_only, $tag );

		$this->assert_filter_ids(
			array( $both_lists, $vip_only ),
			array(
				array( array( 'provider' => 'segment', 'field' => 'lists', 'operator' => 'in', 'value' => array( $vip ) ) ),
			)
		);

		$this->assert_filter_ids(
			array( $tag_only, $none ),
			array(
				array( array( 'provider' => 'segment', 'field' => 'lists', 'operator' => 'not_in', 'value' => array( $vip ) ) ),
			)
		);

		// includes all of: both lists are required.
		$this->assert_filter_ids(
			array( $both_lists ),
			array(
				array( array( 'provider' => 'segment', 'field' => 'lists', 'operator' => 'in_all', 'value' => array( $vip, $hot ) ) ),
			)
		);

		// does not include all: everyone that has fewer than both.
		$this->assert_filter_ids(
			array( $vip_only, $tag_only, $none ),
			array(
				array( array( 'provider' => 'segment', 'field' => 'lists', 'operator' => 'not_in_all', 'value' => array( $vip, $hot ) ) ),
			)
		);

		// No list membership: a tag-only contact also has no list.
		$this->assert_filter_ids(
			array( $tag_only, $none ),
			array(
				array( array( 'provider' => 'segment', 'field' => 'lists', 'operator' => 'is_null' ) ),
			)
		);

		$this->assert_filter_ids(
			array( $both_lists, $vip_only ),
			array(
				array( array( 'provider' => 'segment', 'field' => 'lists', 'operator' => 'not_null' ) ),
			)
		);

		// A tag ID is not a list ID, even though both live in one table.
		$wrong_type = AdvancedFilters::normalize(
			array(
				array( array( 'provider' => 'segment', 'field' => 'lists', 'operator' => 'in', 'value' => array( $tag ) ) ),
			)
		);

		$this->assertWPError( $wrong_type );

		$missing = AdvancedFilters::normalize(
			array(
				array( array( 'provider' => 'segment', 'field' => 'tags', 'operator' => 'in', 'value' => array( 999999 ) ) ),
			)
		);

		$this->assertWPError( $missing );
	}

	public function test_custom_field_typed_comparisons_and_empty_relationship(): void {
		$this->assertNotWPError(
			CustomFieldDefinitions::save(
				array(
					array( 'label' => 'Score', 'slug' => 'score', 'type' => 'number' ),
					array( 'label' => 'Tier', 'slug' => 'tier', 'type' => 'select-one', 'options' => array( 'Bronze', 'Silver', 'Gold' ) ),
					array( 'label' => 'Interests', 'slug' => 'interests', 'type' => 'select-multi', 'options' => array( 'News', 'Events', 'Offers' ) ),
					array( 'label' => 'Birthday', 'slug' => 'birthday', 'type' => 'date' ),
				)
			)
		);

		$values = new CustomFieldValues();

		$head = $this->make_contact( array( 'first_name' => 'Head' ) );
		$mid  = $this->make_contact( array( 'first_name' => 'Mid' ) );
		$bare = $this->make_contact( array( 'first_name' => 'Bare' ) );

		$this->assertNotWPError(
			$values->save(
				$head,
				array(
					'score'     => '42',
					'tier'      => 'Gold',
					'interests' => array( 'News', 'Events' ),
					'birthday'  => '1990-05-04',
				)
			)
		);

		$this->assertNotWPError(
			$values->save(
				$mid,
				array(
					'score'     => '7',
					'tier'      => 'Silver',
					'interests' => array( 'Offers' ),
					'birthday'  => '2001-12-31',
				)
			)
		);

		$condition = static function ( string $field, string $operator, $value = null ): array {
			$condition = array(
				'provider' => 'custom_fields',
				'field'    => $field,
				'operator' => $operator,
			);

			if ( null !== $value ) {
				$condition['value'] = $value;
			}

			return array( array( $condition ) );
		};

		$this->assert_filter_ids( array( $head ), $condition( 'score', '>', '10' ) );
		$this->assert_filter_ids( array( $mid ), $condition( 'score', '<', '10' ) );
		$this->assert_filter_ids( array( $mid ), $condition( 'score', '=', '7.0' ) );
		$this->assert_filter_ids( array( $mid ), $condition( 'tier', 'in', array( 'Silver' ) ) );
		$this->assert_filter_ids( array( $head, $bare ), $condition( 'tier', 'not_in', array( 'Silver' ) ) );
		$this->assert_filter_ids( array( $head ), $condition( 'interests', 'in', array( 'Events' ) ) );
		$this->assert_filter_ids( array( $mid, $bare ), $condition( 'interests', 'not_in', array( 'Events' ) ) );
		$this->assert_filter_ids( array( $head ), $condition( 'birthday', 'before', '2000-01-01' ) );
		$this->assert_filter_ids( array( $mid ), $condition( 'birthday', 'date_equal', '2001-12-31' ) );

		// An unset custom field is a missing row: is_null matches it.
		$this->assert_filter_ids( array( $bare ), $condition( 'score', 'is_null' ) );
		$this->assert_filter_ids( array( $head, $mid ), $condition( 'score', 'not_null' ) );

		// A value that the definition does not offer is rejected.
		$invalid_option = AdvancedFilters::normalize( $condition( 'tier', 'in', array( 'Platinum' ) ) );
		$this->assertWPError( $invalid_option );

		// A field key that no definition declares is rejected.
		$unknown = AdvancedFilters::normalize( $condition( 'no_such_field', '=', 'x' ) );
		$this->assertWPError( $unknown );
	}

	public function test_date_operators_on_contact_columns(): void {
		$recent = $this->make_contact();
		$old    = $this->make_contact();

		$recent_at = gmdate( 'Y-m-d H:i:s', time() - ( 5 * DAY_IN_SECONDS ) );
		$old_at    = gmdate( 'Y-m-d H:i:s', time() - ( 40 * DAY_IN_SECONDS ) );

		$this->set_created_at( $recent, $recent_at );
		$this->set_created_at( $old, $old_at );

		$this->assert_filter_ids(
			array( $recent ),
			array( array( array( 'provider' => 'contact', 'field' => 'created_at', 'operator' => 'days_within', 'value' => 10 ) ) )
		);

		$this->assert_filter_ids(
			array( $old ),
			array( array( array( 'provider' => 'contact', 'field' => 'created_at', 'operator' => 'days_before', 'value' => 30 ) ) )
		);

		$cutoff = gmdate( 'Y-m-d', time() - ( 20 * DAY_IN_SECONDS ) );

		$this->assert_filter_ids(
			array( $recent ),
			array( array( array( 'provider' => 'contact', 'field' => 'created_at', 'operator' => 'after', 'value' => $cutoff ) ) )
		);

		$this->assert_filter_ids(
			array( $old ),
			array( array( array( 'provider' => 'contact', 'field' => 'created_at', 'operator' => 'before', 'value' => $cutoff ) ) )
		);

		$this->assert_filter_ids(
			array( $recent ),
			array( array( array( 'provider' => 'contact', 'field' => 'created_at', 'operator' => 'date_equal', 'value' => gmdate( 'Y-m-d', strtotime( $recent_at . ' UTC' ) ) ) ) )
		);

		// A day count is a value, not a date.
		$bad_days = AdvancedFilters::normalize(
			array( array( array( 'provider' => 'contact', 'field' => 'created_at', 'operator' => 'days_within', 'value' => '2026-01-01' ) ) )
		);

		$this->assertWPError( $bad_days );
	}

	public function test_plan_is_typed_and_stable(): void {
		$list = $this->terms->create( TermRepository::TYPE_LIST, 'Plan list' );

		$plan = AdvancedFilters::normalize(
			array(
				'groups' => array(
					array(
						array( 'provider' => 'segment', 'field' => 'lists', 'operator' => 'in', 'value' => array( (string) $list ) ),
						array( 'provider' => 'contact', 'field' => 'wp_user_id', 'operator' => '>', 'value' => '3' ),
					),
				),
			)
		);

		$this->assertNotWPError( $plan );
		$this->assertSame( 1, $plan['version'] );
		$this->assertSame( 'segment', $plan['groups'][0][0]['provider'] );
		$this->assertSame( 'lists', $plan['groups'][0][0]['field'] );
		$this->assertSame( array( $list ), $plan['groups'][0][0]['value'] );
		$this->assertSame( 3, $plan['groups'][0][1]['value'] );

		// A plan is accepted as input and comes back unchanged.
		$this->assertSame( $plan, AdvancedFilters::normalize( $plan ) );

		$compiled = AdvancedFilters::compile( $plan );

		$this->assertArrayHasKey( 'sql', $compiled );
		$this->assertArrayHasKey( 'params', $compiled );
		$this->assertStringContainsString( 'c.wp_user_id', $compiled['sql'] );
		$this->assertStringContainsString( 'c.status', AdvancedFilters::compile( AdvancedFilters::normalize( array( array( array( 'provider' => 'segment', 'field' => 'status', 'operator' => 'in', 'value' => array( 'subscribed' ) ) ) ) ) )['sql'] );
	}

	public function test_limits_are_enforced(): void {
		$condition = array( 'provider' => 'contact', 'field' => 'city', 'operator' => '=', 'value' => 'Taipei' );

		$six_groups = AdvancedFilters::normalize( array_fill( 0, AdvancedFilters::MAX_GROUPS + 1, array( $condition ) ) );
		$this->assertWPError( $six_groups );

		$eleven = AdvancedFilters::normalize( array( array_fill( 0, AdvancedFilters::MAX_CONDITIONS + 1, $condition ) ) );
		$this->assertWPError( $eleven );

		$too_many_values = AdvancedFilters::normalize(
			array(
				array(
					array(
						'provider' => 'segment',
						'field'    => 'status',
						'operator' => 'in',
						'value'    => array_fill( 0, AdvancedFilters::MAX_VALUES + 1, Status::SUBSCRIBED ),
					),
				),
			)
		);

		// Duplicate values collapse before the limit, so fill with distinct ones.
		$this->assertNotWPError( $too_many_values );

		$distinct = array_slice( array_keys( \NowCRM\Contacts\ProfileFields::countries() ), 0, AdvancedFilters::MAX_VALUES + 1 );

		$this->assertCount( AdvancedFilters::MAX_VALUES + 1, $distinct );

		$this->assertWPError(
			AdvancedFilters::normalize(
				array(
					array(
						array( 'provider' => 'contact', 'field' => 'country', 'operator' => 'in', 'value' => $distinct ),
					),
				)
			)
		);
	}

	public function test_fragment_composes_with_repository_filters(): void {
		$hit  = $this->make_contact( array( 'city' => 'Taipei', 'status' => Status::SUBSCRIBED ) );
		$miss = $this->make_contact( array( 'city' => 'Taipei', 'status' => Status::UNSUBSCRIBED ) );

		$compiled = AdvancedFilters::compile(
			AdvancedFilters::normalize(
				array( array( array( 'provider' => 'contact', 'field' => 'city', 'operator' => '=', 'value' => 'Taipei' ) ) )
			)
		);

		$table  = Schema::table( 'contacts' );
		$params = array_merge( $compiled['params'], array( Status::SUBSCRIBED ) );
		$sql    = "SELECT c.id FROM {$table} c WHERE {$compiled['sql']} AND c.status = %s";

		global $wpdb;

		$ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( $sql, $params ) ) );

		$this->assertSame( array( $hit ), $ids );
		$this->assertNotContains( $miss, $ids );
	}

	public function test_advanced_filter_reaches_repository_search_paths(): void {
		$hit   = $this->make_contact( array( 'city' => 'Taipei', 'status' => Status::SUBSCRIBED ) );
		$miss  = $this->make_contact( array( 'city' => 'Tainan', 'status' => Status::SUBSCRIBED ) );
		$other = $this->make_contact( array( 'city' => 'Taipei', 'status' => Status::UNSUBSCRIBED ) );

		$plan = AdvancedFilters::normalize(
			array(
				array(
					array( 'provider' => 'contact', 'field' => 'city', 'operator' => '=', 'value' => 'Taipei' ),
					array( 'provider' => 'segment', 'field' => 'status', 'operator' => 'in', 'value' => array( Status::SUBSCRIBED ) ),
				),
			)
		);

		$this->assertNotWPError( $plan );

		$page = $this->contacts->search( array( 'filters' => $plan, 'page' => 1, 'per_page' => 20 ) );

		$this->assertSame( 1, $page['total'] );
		$this->assertSame( $hit, (int) $page['items'][0]['id'] );

		$bounds = $this->contacts->search_bounds( array( 'filters' => $plan ) );

		$this->assertSame( 1, $bounds['matching'] );
		$this->assertSame( $hit, $bounds['max_id'] );

		$this->assertSame( array( $hit ), $this->contacts->search_ids( array( 'filters' => $plan ), 0, 0, 50 ) );

		// A rejected plan is a closed gate, not an unfiltered list.
		$rejected = new \WP_Error( 'nowcrm_advanced_filter_invalid', 'bad' );

		$this->assertSame( 0, $this->contacts->search( array( 'filters' => $rejected, 'page' => 1, 'per_page' => 20 ) )['total'] );
		$this->assertSame( 0, $this->contacts->search_bounds( array( 'filters' => $rejected ) )['matching'] );
		$this->assertSame( array(), $this->contacts->search_ids( array( 'filters' => $rejected ), 0, 0, 50 ) );

		$this->assertNotSame( $hit, $miss );
		$this->assertNotSame( $hit, $other );
	}

	public function test_simple_plan_composes_status_and_taxonomies(): void {
		$list_a = $this->terms->create( 'list', 'List A' );
		$list_b = $this->terms->create( 'list', 'List B' );
		$tag    = $this->terms->create( 'tag', 'Tag A' );

		$hit = $this->make_contact( array( 'status' => Status::SUBSCRIBED ) );
		$this->terms->attach( $hit, $list_a );
		$this->terms->attach( $hit, $tag );

		$second_list = $this->make_contact( array( 'status' => Status::SUBSCRIBED ) );
		$this->terms->attach( $second_list, $list_b );
		$this->terms->attach( $second_list, $tag );

		$wrong_status = $this->make_contact( array( 'status' => Status::TRANSACTIONAL ) );
		$this->terms->attach( $wrong_status, $list_a );
		$this->terms->attach( $wrong_status, $tag );

		$wrong_tag = $this->make_contact( array( 'status' => Status::SUBSCRIBED ) );
		$this->terms->attach( $wrong_tag, $list_a );

		$plan = ContactAdvancedFilters::plan_from_simple( array( Status::SUBSCRIBED ), array( $list_a ), array( $tag ) );

		$this->assertNotWPError( $plan );

		$page = $this->contacts->search( array( 'filters' => $plan, 'per_page' => 20 ) );

		$this->assertSame( 1, $page['total'] );
		$this->assertSame( $hit, (int) $page['items'][0]['id'] );

		// Any selected list matches within the list category; status and tag
		// still constrain across categories.
		$or_plan = ContactAdvancedFilters::plan_from_simple(
			array( Status::SUBSCRIBED ),
			array( $list_a, $list_b ),
			array( $tag )
		);

		$this->assertNotWPError( $or_plan );

		$or_page = $this->contacts->search( array( 'filters' => $or_plan, 'per_page' => 20 ) );
		$ids     = array_map( 'intval', array_column( $or_page['items'], 'id' ) );

		sort( $ids );

		$expected = array( $hit, $second_list );
		sort( $expected );

		$this->assertSame( 2, $or_page['total'] );
		$this->assertSame( $expected, $ids );
		$this->assertNotContains( $wrong_status, $ids );
		$this->assertNotContains( $wrong_tag, $ids );
	}

	public function test_simple_plan_rejects_unknown_values_without_widening(): void {
		$hit = $this->make_contact( array( 'status' => Status::SUBSCRIBED ) );

		$bad_status = ContactAdvancedFilters::plan_from_simple( array( 'not-a-status' ), array(), array() );

		$this->assertWPError( $bad_status );
		$this->assertSame( 0, $this->contacts->search( array( 'filters' => $bad_status, 'per_page' => 20 ) )['total'] );

		$bad_term = ContactAdvancedFilters::plan_from_simple( array(), array( 999999 ), array() );

		$this->assertWPError( $bad_term );
		$this->assertSame( 0, $this->contacts->search( array( 'filters' => $bad_term, 'per_page' => 20 ) )['total'] );

		$this->assertGreaterThan( 0, $hit );
	}

	public function test_invalid_plan_blocks_export_and_does_not_widen_bulk(): void {
		$hit   = $this->make_contact( array( 'city' => 'Taipei' ) );
		$other = $this->make_contact( array( 'city' => 'Tainan' ) );

		$this->assertWPError( AdvancedFilters::from_json( '{not-json' ) );
		$this->assertWPError( AdvancedFilters::from_json( '"text"' ) );
		$this->assertNull( AdvancedFilters::from_json( '' ) );

		$plan = AdvancedFilters::normalize(
			array( array( array( 'provider' => 'contact', 'field' => 'city', 'operator' => '=', 'value' => 'Taipei' ) ) )
		);

		$this->assertNotWPError( $plan );

		$export = ( new CsvExport() )->plan(
			array(
				'columns' => array( 'email' ),
				'scope'   => 'filtered',
				'filters' => AdvancedFilters::to_json( $plan ),
			)
		);

		$this->assertIsArray( $export );
		$this->assertSame( 1, $export['total'] );
		$this->assertSame( $plan, $export['filters']['filters'] );

		$rows = iterator_to_array( ( new CsvExport() )->rows( $export ) );

		$this->assertCount( 1, $rows );
		$this->assertSame( $this->contacts->get( $hit )['email'], $rows[0][0] );

		$bad = ( new CsvExport() )->plan(
			array(
				'columns' => array( 'email' ),
				'scope'   => 'filtered',
				'filters' => '{bad',
			)
		);

		$this->assertWPError( $bad );
		$this->assertSame( 'nowcrm_advanced_filter_invalid', $bad->get_error_code() );

		$tag = $this->terms->create( TermRepository::TYPE_TAG, 'Bulk filter tag' );

		$this->assertIsInt( $tag );

		// A rejected plan keeps a filter-wide run empty.
		$rejected = ( new BulkContactAction() )->run(
			array(
				'operation'      => BulkContactAction::ATTACH_TAG,
				'scope'          => 'all',
				'filters'        => array( 'filters' => new \WP_Error( 'nowcrm_advanced_filter_invalid', 'bad' ) ),
				'expected_total' => 100,
				'term_ids'       => array( $tag ),
				'actor_id'       => 1,
			)
		);

		$this->assertSame( 'empty', $rejected['status'] );
		$this->assertSame( 0, $rejected['processed'] );
		$this->assertSame( array(), $this->terms->for_contact( $hit ) );

		// The same run with a valid plan touches only the matching contact.
		$run = ( new BulkContactAction() )->run(
			array(
				'operation'      => BulkContactAction::ATTACH_TAG,
				'scope'          => 'all',
				'filters'        => array( 'filters' => $plan ),
				'expected_total' => 1,
				'term_ids'       => array( $tag ),
				'actor_id'       => 1,
			)
		);

		$this->assertSame( 'done', $run['status'] );
		$this->assertSame( 1, $run['processed'] );
		$this->assertSame( array( $tag ), array_map( 'intval', array_column( $this->terms->for_contact( $hit ), 'id' ) ) );
		$this->assertSame( array(), $this->terms->for_contact( $other ) );
	}

	public function test_admin_request_parsing_and_transport(): void {
		$list = $this->terms->create( TermRepository::TYPE_LIST, 'Request list' );

		$this->assertIsInt( $list );
		$this->assertNull( ContactAdvancedFilters::parse_request( array() ) );

		$plan = ContactAdvancedFilters::parse_request(
			array(
				'nowcrm_adv' => array(
					array(
						array( 'provider' => 'contact', 'field' => 'city', 'operator' => '=', 'value' => ' Taipei ' ),
						array( 'property' => '', 'operator' => '', 'value' => '' ),
					),
					array(
						array( 'provider' => 'segment', 'field' => 'lists', 'operator' => 'in', 'value' => array( (string) $list ) ),
					),
				),
			)
		);

		$this->assertNotWPError( $plan );
		$this->assertCount( 2, $plan['groups'] );
		$this->assertCount( 1, $plan['groups'][0] );
		$this->assertSame( 'Taipei', $plan['groups'][0][0]['value'] );
		$this->assertSame( array( $list ), $plan['groups'][1][0]['value'] );

		// The remove button drops its row before validation.
		$removed = ContactAdvancedFilters::parse_request(
			array(
				'nowcrm_adv'        => array(
					array(
						array( 'provider' => 'contact', 'field' => 'city', 'operator' => '=', 'value' => 'Taipei' ),
						array( 'property' => '', 'operator' => '', 'value' => '' ),
					),
				),
				'nowcrm_adv_remove' => '0:0',
			)
		);

		$this->assertNull( $removed );
		$this->assertWPError( ContactAdvancedFilters::parse_request( array( 'nowcrm_adv' => 'nope' ) ) );

		// A partially filled row is kept on purpose, so validation reports it
		// instead of silently dropping the condition.
		$partial = ContactAdvancedFilters::parse_request(
			array(
				'nowcrm_adv' => array(
					array(
						array( 'provider' => 'contact', 'field' => '', 'operator' => '', 'value' => '' ),
					),
				),
			)
		);

		$this->assertWPError( $partial );
		$this->assertSame( 'condition_field', $partial->get_error_data()['reason'] );

		$broken = ContactAdvancedFilters::parse_request(
			array(
				'nowcrm_adv' => array(
					array(
						array( 'provider' => 'contact', 'field' => 'nope', 'operator' => '=', 'value' => 'x' ),
					),
				),
			)
		);

		$this->assertWPError( $broken );
		$this->assertArrayHasKey( 'groups', $broken->get_error_data() );

		$this->assertSame( $plan, AdvancedFilters::from_json( AdvancedFilters::to_json( $plan ) ) );
		$this->assertSame( array( $list ), AdvancedFilters::to_query( $plan )[1][0]['value'] );
	}

	public function test_export_screen_carries_and_validates_the_plan(): void {
		$method = new \ReflectionMethod( \NowCRM\Admin\ContactCsvPage::class, 'render_export' );
		$method->setAccessible( true );

		$_GET = array();

		ob_start();
		$method->invoke( null );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'name="action"', $html );
		$this->assertStringNotContainsString( 'name="filter_advanced"', $html );

		$plan = AdvancedFilters::normalize(
			array( array( array( 'provider' => 'contact', 'field' => 'city', 'operator' => '=', 'value' => 'Taipei' ) ) )
		);

		$this->assertNotWPError( $plan );

		$_GET = array(
			'nowcrm_adv' => array(
				array(
					array( 'provider' => 'contact', 'field' => 'city', 'operator' => '=', 'value' => 'Taipei' ),
				),
			),
		);

		ob_start();
		$method->invoke( null );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'name="filter_advanced"', $html );
		$this->assertStringContainsString( esc_attr( AdvancedFilters::to_json( $plan ) ), $html );

		$_GET = array(
			'nowcrm_adv' => array(
				array(
					array( 'provider' => 'contact', 'field' => 'nope', 'operator' => '=', 'value' => 'x' ),
				),
			),
		);

		ob_start();
		$method->invoke( null );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'notice-error', $html );
		$this->assertStringContainsString( '不支援的篩選欄位', $html );
		$this->assertStringNotContainsString( 'name="filter_advanced"', $html );
		$this->assertStringNotContainsString( 'name="action"', $html );

		$_GET = array();
	}

	public function test_renderer_emits_real_controls(): void {
		$tag = $this->terms->create( TermRepository::TYPE_TAG, 'Renderer tag' );

		$this->assertIsInt( $tag );

		$plan = AdvancedFilters::normalize(
			array(
				array(
					array( 'provider' => 'segment', 'field' => 'tags', 'operator' => 'in', 'value' => array( $tag ) ),
					array( 'provider' => 'contact', 'field' => 'city', 'operator' => '=', 'value' => '<script>alert(1)</script>' ),
				),
			)
		);

		$this->assertNotWPError( $plan );

		ob_start();
		ContactAdvancedFilters::render( $plan );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'id="nowcrm-adv-filters"', $html );
		$this->assertStringContainsString( 'name="nowcrm_adv[0][0][property]"', $html );
		$this->assertStringContainsString( 'name="nowcrm_adv[0][0][operator]"', $html );
		$this->assertStringContainsString( 'name="nowcrm_adv[0][0][value][]"', $html );
		$this->assertStringContainsString( 'name="nowcrm_adv[0][1][value]"', $html );
		$this->assertStringContainsString( 'Renderer tag', $html );
		$this->assertStringContainsString( 'selected="selected"', $html );
		$this->assertStringContainsString( 'name="nowcrm_adv_remove"', $html );
		$this->assertStringContainsString( '或 (OR)', $html );

		// A submitted value is escaped, never emitted as markup.
		$this->assertStringNotContainsString( '<script>alert(1)</script>', $html );
		$this->assertStringContainsString( '&lt;script&gt;alert(1)', $html );

		$error = ContactAdvancedFilters::parse_request(
			array(
				'nowcrm_adv' => array(
					array(
						array( 'provider' => 'contact', 'field' => 'nope', 'operator' => '=', 'value' => 'x' ),
					),
				),
			)
		);

		$this->assertWPError( $error );

		ob_start();
		ContactAdvancedFilters::render( $plan, $error );
		$error_html = (string) ob_get_clean();

		$this->assertStringContainsString( 'notice-error', $error_html );
		$this->assertStringContainsString( '不支援的篩選欄位', $error_html );
	}
}
