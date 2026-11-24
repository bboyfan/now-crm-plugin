<?php
/**
 * Campaign recipient selection.
 *
 * @package NowCRM
 */

use NowCRM\Campaign\Audience;
use NowCRM\Campaign\Campaigns;
use NowCRM\Contacts\CustomFieldDefinitions;
use NowCRM\Contacts\CustomFieldValues;
use NowCRM\Contacts\Status;
use NowCRM\Email\SuppressionRepository;
use NowCRM\Segments\SegmentRepository;

final class AudienceTest extends NowCrmTestCase {

	public function test_empty_rules_require_explicit_selection_and_all_excludes_ineligible_contacts(): void {
		$included = $this->make_contact();
		$this->make_contact( array( 'status' => Status::UNSUBSCRIBED ) );
		$this->make_contact( array( 'email' => 'suppressed@example.test' ) );
		( new SuppressionRepository() )->add( 'suppressed@example.test', 'bounced', 'test' );

		$audience = Audience::stored( null );
		$this->assertSame( array(), Audience::recipients( $audience ) );
		$audience['scope'] = 'all';
		$this->assertSame( array( $included ), array_map( 'intval', array_column( Audience::recipients( $audience ), 'contact_id' ) ) );
	}

	public function test_lists_and_tags_intersect_within_a_rule_and_union_between_rules(): void {
		$list = $this->terms->create( 'list', 'List' );
		$include = $this->terms->create( 'tag', 'Include' );
		$extra = $this->terms->create( 'tag', 'Other group' );
		$exclude = $this->terms->create( 'tag', 'Exclude' );
		$contacts = array();
		foreach ( array( array( $list, $include ), array( $extra ), array( $list ), array( $extra, $exclude ) ) as $terms ) {
			$id = $this->make_contact();
			foreach ( $terms as $term ) {
				$this->terms->attach( $id, $term );
			}
			$contacts[] = $id;
		}
		$rules = array(
			'scope' => 'rules',
			'include' => array( array( 'list' => $list, 'tag' => $include ), array( 'list' => 0, 'tag' => $extra ) ),
			'exclude' => array( array( 'list' => 0, 'tag' => $exclude ) ),
		);
		$this->assertSame( array_slice( $contacts, 0, 2 ), array_map( 'intval', array_column( Audience::recipients( Audience::validate( $rules ) ), 'contact_id' ) ) );
		$rules['include'][0]['list'] = $include;
		$this->assertWPError( Audience::validate( $rules ) );
	}

	public function test_segments_scope_selects_one_saved_segment_and_exclusions_apply(): void {
		SegmentRepository::register_post_type();
		$segments = new SegmentRepository();
		$segment = $segments->create(
			array(
				'name'    => 'Alpha',
				'filters' => array(
					array(
						array( 'provider' => 'contact', 'field' => 'first_name', 'operator' => '=', 'value' => 'Alpha' ),
					),
				),
			)
		);
		$this->assertIsInt( $segment );

		$alpha = $this->make_contact( array( 'first_name' => 'Alpha' ) );
		$this->make_contact( array( 'first_name' => 'Beta' ) );
		$this->make_contact( array( 'first_name' => 'Alpha', 'status' => Status::UNSUBSCRIBED ) );
		$this->make_contact( array( 'first_name' => 'Alpha', 'email' => 'suppressed@example.test' ) );
		( new SuppressionRepository() )->add( 'suppressed@example.test', 'bounced', 'test' );

		$audience = array(
			'scope'   => 'segments',
			'include' => array( array( 'list' => 0, 'tag' => 0, 'segment' => 'stale:kept-for-mode-switch' ) ),
			'exclude' => array(),
			'segment' => 'custom:' . $segment,
		);

		$validated = Audience::validate( $audience );
		$this->assertNotWPError( $validated, 'Inactive include rows must not block a segments save.' );
		$this->assertSame( 'custom:' . $segment, $validated['segment'] );
		$this->assertSame( 'segments', $validated['scope'] );
		$this->assertArrayNotHasKey( 'filters', $validated );
		$this->assertSame( $audience['include'], $validated['include'], 'Inactive include rows persist for a later scope switch.' );

		$ids = array_map( 'intval', array_column( Audience::recipients( $validated ), 'contact_id' ) );
		$this->assertSame( array( $alpha ), $ids, 'Only the subscribed, unsuppressed segment member remains.' );

		$exclude = $this->terms->create( 'tag', 'Excluded' );
		$this->terms->attach( $alpha, $exclude );
		$validated['exclude'] = array( array( 'list' => 0, 'tag' => $exclude, 'segment' => 0 ) );
		$this->assertSame( array(), Audience::recipients( $validated ), 'Exclusions intersect the segment population.' );

		$empty = array(
			'scope'   => 'segments',
			'include' => array(),
			'exclude' => array(),
			'segment' => 0,
		);
		$empty_validated = Audience::validate( $empty );
		$this->assertNotWPError( $empty_validated, 'An empty segment selection may be saved.' );
		$this->assertSame( array(), Audience::recipients( $empty_validated ), 'An empty qualifier matches nobody, not everybody.' );

		$stale = $empty;
		$stale['segment'] = 999999;
		$this->assertWPError( Audience::validate( $stale ) );
		$this->assertSame( array(), Audience::recipients( Audience::stored( $stale ) ), 'A stale segment selection fails closed.' );

		// The persisted shape survives a JSON roundtrip and keeps the revision stable.
		$json = (string) wp_json_encode( $validated );
		$this->assertSame( $validated, Audience::stored( $json ) );
		$this->assertSame(
			Campaigns::revision( array( 'audience' => $validated ) ),
			Campaigns::revision( array( 'audience' => Audience::stored( $json ) ) )
		);

		$this->assertTrue( $segments->archive( $segment ) );
		$this->assertWPError( Audience::validate( $audience ) );
		$this->assertSame( array(), Audience::recipients( Audience::stored( $audience ) ) );
	}

	public function test_advanced_scope_ors_groups_ands_conditions_and_keeps_eligibility(): void {
		$definitions = CustomFieldDefinitions::save(
			array(
				array( 'label' => 'Tier', 'slug' => 'tier', 'type' => 'select-one', 'options' => array( 'Bronze', 'Silver' ) ),
			)
		);
		$this->assertNotWPError( $definitions );

		$alpha_taipei = $this->make_contact( array( 'first_name' => 'Alice', 'city' => 'Taipei' ) );
		$tag          = $this->terms->create( 'tag', 'VIP' );
		$bob          = $this->make_contact( array( 'first_name' => 'Bob', 'city' => 'Tainan' ) );
		$this->terms->attach( $bob, $tag );
		$this->make_contact( array( 'first_name' => 'Alice', 'city' => 'Tainan' ) );
		$this->make_contact( array( 'first_name' => 'Alice', 'city' => 'Taipei', 'status' => Status::UNSUBSCRIBED ) );
		$this->make_contact( array( 'first_name' => 'Alice', 'city' => 'Taipei', 'email' => 'advanced-suppressed@example.test' ) );
		( new SuppressionRepository() )->add( 'advanced-suppressed@example.test', 'bounced', 'test' );

		$silver = $this->make_contact( array( 'first_name' => 'Dana' ) );
		( new CustomFieldValues() )->save( $silver, array( 'tier' => 'Silver' ) );

		$audience = array(
			'scope'   => 'advanced',
			'include' => array( array( 'list' => 0, 'tag' => 0, 'segment' => 'custom:9' ) ),
			'exclude' => array(),
			'filters' => array(
				'version'  => 1,
				'groups'   => array(
					array(
						array( 'provider' => 'contact', 'field' => 'first_name', 'operator' => '=', 'value' => 'Alice' ),
						array( 'provider' => 'contact', 'field' => 'city', 'operator' => '=', 'value' => 'Taipei' ),
					),
					array(
						array( 'provider' => 'segment', 'field' => 'tags', 'operator' => 'in', 'value' => array( $tag ) ),
					),
					array(
						array( 'provider' => 'custom_fields', 'field' => 'tier', 'operator' => 'in', 'value' => array( 'Silver' ) ),
					),
				),
			),
		);

		$validated = Audience::validate( $audience );
		$this->assertNotWPError( $validated );
		$this->assertSame( 3, count( $validated['filters']['groups'] ), 'The normalized plan persists in the draft.' );
		$this->assertSame( 'contact', $validated['filters']['groups'][0][0]['provider'] );
		$this->assertSame( 'custom_fields', $validated['filters']['groups'][2][0]['provider'] );
		$this->assertArrayNotHasKey( 'segment', $validated, 'Inactive qualifiers are not persisted.' );

		$ids = array_map( 'intval', array_column( Audience::recipients( $validated ), 'contact_id' ) );
		sort( $ids );
		$expected = array( $alpha_taipei, $bob, $silver );
		sort( $expected );
		$this->assertSame( $expected, $ids, 'Groups are OR-ed, conditions inside a group are AND-ed, and eligibility still applies.' );

		$exclude = $this->terms->create( 'tag', 'Do not mail' );
		$this->terms->attach( $bob, $exclude );
		$validated['exclude'] = array( array( 'list' => 0, 'tag' => $exclude, 'segment' => 0 ) );
		$this->assertSame( array( $alpha_taipei, $silver ), array_map( 'intval', array_column( Audience::recipients( $validated ), 'contact_id' ) ) );

		foreach ( array( null, array(), array( 'version' => 1, 'groups' => array() ) ) as $empty ) {
			$none = array(
				'scope'   => 'advanced',
				'include' => array(),
				'exclude' => array(),
				'filters' => $empty,
			);
			$none_validated = Audience::validate( $none );
			$this->assertNotWPError( $none_validated, 'An empty advanced plan may be saved.' );
			$this->assertNull( $none_validated['filters'] );
			$this->assertSame( array(), Audience::recipients( $none_validated ), 'An empty plan matches nobody, not everybody.' );
		}

		$malformed = $audience;
		$malformed['filters']['groups'][0][0]['operator'] = 'in_all';
		$this->assertWPError( Audience::validate( $malformed ), 'A populated malformed plan is rejected.' );

		$unknown = $audience;
		$unknown['filters']['groups'][0][0] = array( 'provider' => 'contact', 'field' => 'shoe_size', 'operator' => '=', 'value' => '42' );
		$this->assertWPError( Audience::validate( $unknown ) );

		// A saved plan whose field disappeared stays readable and fails closed.
		$stale = Audience::stored( $unknown );
		$this->assertSame( $unknown['filters'], $stale['filters'], 'Invalid saved conditions are preserved for repair, not dropped.' );
		$this->assertSame( array(), Audience::recipients( $stale ) );

		$json = (string) wp_json_encode( $validated );
		$this->assertSame( $validated, Audience::stored( $json ) );
		$this->assertSame(
			Campaigns::revision( array( 'audience' => $validated ) ),
			Campaigns::revision( array( 'audience' => Audience::stored( $json ) ) )
		);
	}

	public function test_exclusions_fail_closed_in_every_scope(): void {
		$kept = $this->make_contact();
		$excluded = $this->make_contact();
		$tag = $this->terms->create( 'tag', 'Excluded' );
		$this->terms->attach( $excluded, $tag );

		$all = array(
			'scope'   => 'all',
			'include' => array(),
			'exclude' => array( array( 'list' => 0, 'tag' => $tag, 'segment' => 0 ) ),
		);
		$this->assertSame( array( $kept ), array_map( 'intval', array_column( Audience::recipients( Audience::validate( $all ) ), 'contact_id' ) ) );

		$stale = $all;
		$stale['exclude'] = array( array( 'list' => 0, 'tag' => 0, 'segment' => 999999 ) );
		$this->assertWPError( Audience::validate( $stale ) );
		$this->assertSame( array(), Audience::recipients( Audience::stored( $stale ) ), 'A stale exclusion never widens the population.' );
	}

	public function test_preview_page_counts_and_clamps_without_writing(): void {
		for ( $i = 0; $i < 25; ++$i ) {
			$this->make_contact();
		}

		$audience = array(
			'scope'   => 'all',
			'include' => array(),
			'exclude' => array(),
		);

		$preview = Audience::preview_page( $audience, 9 );
		$this->assertNotWPError( $preview );
		$this->assertSame( 25, $preview['count'] );
		$this->assertSame( 2, $preview['page'], 'A page past the end clamps to the last page.' );
		$this->assertSame( 20, $preview['per_page'] );
		$this->assertCount( 5, $preview['recipients'] );

		$ids = array_map( 'intval', array_column( $preview['recipients'], 'id' ) );
		$sorted = $ids;
		sort( $sorted );
		$this->assertSame( $sorted, $ids, 'Recipients stay in ascending contact ID order.' );

		$first = $preview['recipients'][0];
		$this->assertSame( array( 'id', 'email', 'name' ), array_keys( $first ) );
		$this->assertSame( 'Test Buyer', $first['name'] );
		$this->assertNotSame( '', $first['email'] );

		$this->assertCount( 20, Audience::preview_page( $audience, 1 )['recipients'] );
		$this->assertSame( 1, Audience::preview_page( $audience, 0 )['page'], 'Page zero clamps to the first page.' );
		$this->assertSame( 25, Audience::count_recipients( $audience ) );

		$none = Audience::stored( null );
		$empty = Audience::preview_page( $none, 4 );
		$this->assertSame( 0, $empty['count'] );
		$this->assertSame( 1, $empty['page'] );
		$this->assertSame( array(), $empty['recipients'] );
	}
}
