<?php
/**
 * Sequence definitions, audience gates, enrollment, and due processing.
 *
 * @package NowCRM
 */

use NowCRM\Admin\Actions;
use NowCRM\Contacts\Status;
use NowCRM\Email\EmailDispatcher;
use NowCRM\Email\EmailJobRepository;
use NowCRM\Email\Queue;
use NowCRM\Email\SuppressionRepository;
use NowCRM\Segments\SegmentRepository;
use NowCRM\Sequences\Engine;
use NowCRM\Sequences\SequenceRepository;
use NowCRM\Sequences\TrackerRepository;
use NowCRM\Support\Schema;
use NowCRM\Terms\TermRepository;

/**
 * Sentinel thrown by the redirect filter so a handler never reaches exit().
 */
final class NowCrm_Sequence_Redirect_Captured extends \RuntimeException {}

/**
 * @covers \NowCRM\Sequences\Engine
 * @covers \NowCRM\Sequences\SequenceRepository
 * @covers \NowCRM\Sequences\TrackerRepository
 * @covers \NowCRM\Sequences\AudienceResolver
 */
final class SequencesTest extends NowCrmTestCase {

	private SequenceRepository $sequences;
	private TrackerRepository $trackers;
	private Engine $engine;
	private EmailJobRepository $jobs;

	public function set_up(): void {
		parent::set_up();

		$this->sequences = new SequenceRepository();
		$this->trackers  = new TrackerRepository();
		$this->engine    = new Engine();
		$this->jobs      = new EmailJobRepository();

		$this->set_settings(
			array(
				'email_sending_enabled' => true,
				'email_transport'       => 'capture',
			)
		);
	}

	public function tear_down(): void {
		$_POST    = array();
		$_REQUEST = array();
		wp_set_current_user( 0 );

		parent::tear_down();
	}

	/**
	 * Create an active two-step sequence.
	 *
	 * @param array<string, mixed> $overrides Definition overrides.
	 */
	private function make_sequence( array $overrides = array() ): int {
		$id = $this->sequences->create(
			array_merge(
				array(
					'name'          => 'Fixture sequence ' . wp_generate_password( 6, false ),
					'status'        => SequenceRepository::STATUS_ACTIVE,
					'allow_reentry' => false,
					'audience'      => array( 'type' => 'all' ),
					'steps'         => array(
						array(
							'name'          => 'Welcome',
							'subject'       => 'Step one',
							'preheader'     => 'First',
							'mode'          => 'html',
							'content'       => '<p>One {{first_name}}</p>',
							'delay_seconds' => 0,
						),
						array(
							'name'          => 'Follow up',
							'subject'       => 'Step two',
							'preheader'     => 'Second',
							'mode'          => 'html',
							'content'       => '<p>Two</p>',
							'delay_seconds' => 60,
						),
					),
				),
				$overrides
			)
		);

		$this->assertNotWPError( $id, 'Fixture sequence creation failed.' );

		return (int) $id;
	}

	/**
	 * Sequence jobs in ascending ID order.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function sequence_jobs(): array {
		$items = $this->jobs->search( array( 'kind' => 'sequence', 'limit' => 100 ) )['items'];
		usort( $items, static fn( array $left, array $right ): int => $left['id'] <=> $right['id'] );

		return $items;
	}

	/**
	 * Time one second after a UTC SQL time.
	 */
	private function after( ?string $time ): string {
		return gmdate( 'Y-m-d H:i:s', strtotime( (string) $time . ' UTC' ) + 1 );
	}

	/**
	 * Time one second before a UTC SQL time.
	 */
	private function before( ?string $time ): string {
		return gmdate( 'Y-m-d H:i:s', strtotime( (string) $time . ' UTC' ) - 1 );
	}

	public function test_definition_validation_sorts_steps_and_rejects_invalid_input(): void {
		$id = $this->sequences->create(
			array(
				'name'     => 'Sorted sequence',
				'status'   => SequenceRepository::STATUS_DRAFT,
				'audience' => array( 'type' => 'all' ),
				'steps'    => array(
					array( 'subject' => 'Later', 'mode' => 'html', 'content' => '<p>Later</p>', 'delay_seconds' => 3600 ),
					array( 'subject' => 'Now', 'mode' => 'html', 'content' => '<p>Now</p>', 'delay_seconds' => 0 ),
				),
			)
		);

		$this->assertNotWPError( $id );
		$sequence = $this->sequences->get( (int) $id );

		$this->assertSame( array( 0, 3600 ), array_column( $sequence['steps'], 'delay_seconds' ) );
		$this->assertSame( 'Now', $sequence['steps'][0]['subject'] );

		$this->assertWPError(
			$this->sequences->create(
				array(
					'name'   => 'No steps',
					'status' => SequenceRepository::STATUS_ACTIVE,
					'steps'  => array(),
				)
			)
		);

		$this->assertWPError(
			$this->sequences->create(
				array(
					'name'   => 'Bad mode',
					'status' => SequenceRepository::STATUS_DRAFT,
					'steps'  => array(
						array( 'subject' => 'Subject', 'mode' => 'markdown', 'content' => 'Body', 'delay_seconds' => 0 ),
					),
				)
			)
		);

		$this->assertWPError(
			$this->sequences->create(
				array(
					'name'     => 'Bad audience',
					'status'   => SequenceRepository::STATUS_DRAFT,
					'audience' => array( 'type' => 'list', 'term_id' => 999999 ),
					'steps'    => array(
						array( 'subject' => 'Subject', 'mode' => 'html', 'content' => 'Body', 'delay_seconds' => 0 ),
					),
				)
			)
		);
	}

	public function test_delayed_steps_queue_in_order_only_when_due(): void {
		$sequence = $this->make_sequence();
		$contact  = $this->make_contact();

		$result = $this->engine->enroll( $sequence, array( $contact ) );

		$this->assertSame( 1, $result['enrolled'] );
		$this->assertSame( 1, $result['queued'] );
		$this->assertCount( 1, $this->sequence_jobs() );
		$this->assertSame( 'Step one', $this->sequence_jobs()[0]['subject'] );

		$tracker = $this->trackers->find_active( $sequence, $contact );
		$this->assertSame( 1, $tracker['next_index'] );

		$early = $this->engine->process_due( $this->before( $tracker['next_run_at'] ) );

		$this->assertSame( 0, $early['processed'] );
		$this->assertCount( 1, $this->sequence_jobs() );

		$due = $this->engine->process_due( gmdate( 'Y-m-d H:i:s', strtotime( $this->after( $tracker['next_run_at'] ) . ' UTC' ) + 1 ) );

		$this->assertSame( 1, $due['queued'] );
		$this->assertSame( array( 'Step one', 'Step two' ), array_column( $this->sequence_jobs(), 'subject' ) );

		$tracker = $this->trackers->find_active( $sequence, $contact );
		$this->assertSame( 2, $tracker['next_index'] );
	}

	public function test_active_enrollment_is_unique_and_reentry_needs_completion(): void {
		$sequence = $this->make_sequence();
		$contact  = $this->make_contact();

		$this->assertSame( 1, $this->engine->enroll( $sequence, array( $contact ) )['enrolled'] );

		$again = $this->engine->enroll( $sequence, array( $contact ) );

		$this->assertSame( 1, $again['duplicates'] );
		$this->assertSame( 1, $this->table_count( 'sequence_trackers', 'sequence_id = ' . $sequence ) );
		$this->assertCount( 1, $this->sequence_jobs() );

		$tracker = $this->trackers->find_active( $sequence, $contact );
		$this->trackers->complete( (int) $tracker['id'] );

		$this->assertSame( 1, $this->engine->enroll( $sequence, array( $contact ) )['duplicates'] );

		$reentry = $this->make_sequence( array( 'allow_reentry' => true ) );
		$other   = $this->make_contact();

		$this->assertSame( 1, $this->engine->enroll( $reentry, array( $other ) )['enrolled'] );
		$first = $this->trackers->find_active( $reentry, $other );
		$this->trackers->complete( (int) $first['id'] );

		$this->assertSame( 1, $this->engine->enroll( $reentry, array( $other ) )['enrolled'] );
		$this->assertSame( 2, $this->table_count( 'sequence_trackers', 'sequence_id = ' . $reentry ) );
		$this->assertSame( 2, $this->table_count( 'email_jobs', "kind = 'sequence' AND job_key LIKE 'sequence:{$reentry}:%'" ) );
	}

	public function test_unsubscribed_and_suppressed_contacts_are_skipped(): void {
		$sequence    = $this->make_sequence();
		$subscribed  = $this->make_contact();
		$unsubscribed = $this->make_contact();
		$suppressed  = $this->make_contact( array( 'email' => 'suppressed-' . wp_generate_password( 8, false ) . '@example.test' ) );

		$this->contacts->set_status( $unsubscribed, Status::UNSUBSCRIBED, 'test' );
		( new SuppressionRepository() )->add( $this->contacts->get( $suppressed )['email'], 'bounced', 'test' );

		$result = $this->engine->enroll( $sequence, array( $subscribed, $unsubscribed, $suppressed ) );

		$this->assertSame( 1, $result['enrolled'] );
		$this->assertSame( 1, $result['skipped_not_subscribed'] );
		$this->assertSame( 1, $result['skipped_suppressed'] );
		$this->assertSame( 1, $this->table_count( 'sequence_trackers', 'sequence_id = ' . $sequence ) );

		$late = $this->make_contact();
		$this->engine->enroll( $sequence, array( $late ) );
		$this->contacts->set_status( $late, Status::UNSUBSCRIBED, 'test' );

		$tracker = $this->trackers->find_active( $sequence, $late );
		$cancelled = $this->engine->process_due( gmdate( 'Y-m-d H:i:s', strtotime( (string) $tracker['next_run_at'] . ' UTC' ) + 1 ) );

		$this->assertSame( 1, $cancelled['cancelled'] );
		$this->assertSame( TrackerRepository::STATUS_CANCELLED, $this->trackers->get( (int) $tracker['id'] )['status'] );
		$this->assertSame( 1, $this->table_count( 'email_jobs', "kind = 'sequence' AND status = 'cancelled'" ) );
	}

	public function test_transactional_contact_is_not_enrolled_in_a_sequence(): void {
		$sequence = $this->make_sequence();
		$contact  = $this->make_contact(
			array(
				'email'  => 'sequence-transactional-' . wp_generate_password( 8, false ) . '@example.test',
				'status' => Status::TRANSACTIONAL,
			)
		);

		$result = $this->engine->enroll( $sequence, array( $contact ) );

		$this->assertSame( 0, $result['enrolled'] );
		$this->assertSame( 1, $result['skipped_not_subscribed'] );
		$this->assertSame( 0, $this->table_count( 'sequence_trackers' ) );
		$this->assertSame( 0, $this->table_count( 'email_jobs', "kind = 'sequence'" ) );
	}

	public function test_completion_waits_for_queue_drain_and_cancel_stops_future_steps(): void {
		$sequence = $this->make_sequence(
			array(
				'steps' => array(
					array( 'subject' => 'Only', 'mode' => 'html', 'content' => '<p>Only</p>', 'delay_seconds' => 0 ),
				),
			)
		);
		$contact = $this->make_contact();

		$this->engine->enroll( $sequence, array( $contact ) );

		$tracker = $this->trackers->find_active( $sequence, $contact );
		$waiting = $this->engine->process_due( $this->after( $tracker['next_run_at'] ) );

		$this->assertSame( 1, $waiting['waiting'] );

		$job = $this->sequence_jobs()[0];
		$this->set_job_status( (int) $job['id'], EmailJobRepository::STATUS_ACCEPTED );

		$done = $this->engine->process_due( gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ) );

		$this->assertSame( 1, $done['completed'] );
		$this->assertSame( TrackerRepository::STATUS_COMPLETED, $this->trackers->get( (int) $tracker['id'] )['status'] );

		$second = $this->make_sequence( array( 'name' => 'Cancel fixture' ) );
		$other  = $this->make_contact();

		$this->engine->enroll( $second, array( $other ) );
		$this->assertSame( 1, $this->engine->cancel( $second, $other ) );
		$this->assertNull( $this->trackers->find_active( $second, $other ) );
		$this->assertSame( 1, $this->table_count( 'email_jobs', "kind = 'sequence' AND status = 'cancelled' AND job_key LIKE 'sequence:{$second}:%'" ) );

		$after = $this->engine->process_due( gmdate( 'Y-m-d H:i:s', time() + ( 2 * DAY_IN_SECONDS ) ) );
		$this->assertSame( 0, $after['queued'] );
	}

	public function test_paused_sequence_defers_and_resumes(): void {
		$sequence = $this->make_sequence();
		$contact  = $this->make_contact();

		$this->engine->enroll( $sequence, array( $contact ) );
		$tracker = $this->trackers->find_active( $sequence, $contact );

		$this->assertNotWPError( $this->sequences->set_status( $sequence, SequenceRepository::STATUS_PAUSED ) );

		$paused = $this->engine->process_due( $this->after( $tracker['next_run_at'] ) );

		$this->assertSame( 1, $paused['skipped'] );
		$this->assertCount( 1, $this->sequence_jobs() );

		$this->assertNotWPError( $this->sequences->set_status( $sequence, SequenceRepository::STATUS_ACTIVE ) );

		$resumed = $this->engine->process_due( gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS ) );

		$this->assertSame( 1, $resumed['queued'] );
		$this->assertCount( 2, $this->sequence_jobs() );
	}

	public function test_sequence_jobs_queue_while_sending_is_disabled(): void {
		$this->set_settings( array( 'email_sending_enabled' => false ) );

		$sequence = $this->make_sequence();
		$contact  = $this->make_contact();

		$result = $this->engine->enroll( $sequence, array( $contact ) );

		$this->assertSame( 1, $result['queued'] );

		$job = $this->sequence_jobs()[0];
		$this->assertSame( EmailJobRepository::STATUS_QUEUED, $job['status'] );

		( new Queue() )->run();

		$this->assertSame( EmailJobRepository::STATUS_QUEUED, $this->jobs->get( (int) $job['id'] )['status'] );
		$this->assertSame( array(), get_option( EmailDispatcher::CAPTURE_OPTION, array() ) );
	}

	public function test_sequence_job_uses_the_frozen_step_template(): void {
		$sequence = $this->make_sequence();
		$contact  = $this->make_contact();

		$this->engine->enroll( $sequence, array( $contact ) );
		$job = $this->sequence_jobs()[0];

		$this->assertSame( '<p>One {{first_name}}</p>', $job['payload']['template']['content'] );

		$this->sequences->update(
			$sequence,
			array(
				'name'     => 'Fixture sequence edited',
				'status'   => SequenceRepository::STATUS_ACTIVE,
				'audience' => array( 'type' => 'all' ),
				'steps'    => array(
					array( 'subject' => 'New one', 'mode' => 'html', 'content' => '<p>Changed</p>', 'delay_seconds' => 0 ),
					array( 'subject' => 'Step two', 'mode' => 'html', 'content' => '<p>Two</p>', 'delay_seconds' => 60 ),
				),
			)
		);

		( new Queue() )->run();

		$this->assertSame( EmailJobRepository::STATUS_ACCEPTED, $this->jobs->get( (int) $job['id'] )['status'] );

		$log = get_option( EmailDispatcher::CAPTURE_OPTION, array() );
		$this->assertCount( 1, $log );
		$this->assertSame( 'Step one', $log[0]['subject'] );
		$this->assertStringContainsString( '>One Test</p>', $log[0]['html'] );
		$this->assertStringNotContainsString( 'Changed', $log[0]['html'] );
	}

	public function test_list_segment_and_filter_audiences_resolve_members(): void {
		$list = $this->terms->create( TermRepository::TYPE_LIST, 'Sequence list' );
		$this->assertIsInt( $list );

		$first  = $this->make_contact( array( 'email' => 'list-one@example.test' ) );
		$second = $this->make_contact( array( 'email' => 'list-two@example.test' ) );

		$this->terms->attach( $first, (int) $list );
		$this->terms->attach( $second, (int) $list );
		$this->contacts->set_status( $second, Status::UNSUBSCRIBED, 'test' );

		$sequence = $this->make_sequence(
			array(
				'audience' => array( 'type' => 'list', 'term_id' => (int) $list ),
			)
		);

		$this->assertSame( 1, $this->sequences->members( $sequence ) );

		$result = $this->engine->enroll( $sequence );
		$this->assertSame( 1, $result['enrolled'] );
		$this->assertSame( $first, $this->trackers->find_active( $sequence, $first )['contact_id'] );

		$tag = $this->terms->create( TermRepository::TYPE_TAG, 'Sequence tag' );
		$this->assertIsInt( $tag );
		$this->terms->attach( $first, (int) $tag );

		$tagged = $this->make_sequence(
			array(
				'audience' => array( 'type' => 'tag', 'term_id' => (int) $tag ),
			)
		);

		$this->assertSame( 1, $this->sequences->members( $tagged ) );

		$plan = array(
			array(
				array( 'provider' => 'segment', 'field' => 'status', 'operator' => 'in', 'value' => array( Status::SUBSCRIBED ) ),
			),
		);

		$segment_id = ( new SegmentRepository() )->create(
			array(
				'name'    => 'Sequence segment',
				'filters' => $plan,
			)
		);
		$this->assertNotWPError( $segment_id );

		$segmented = $this->make_sequence(
			array(
				'audience' => array( 'type' => 'segment', 'segment_id' => (int) $segment_id ),
			)
		);

		$this->assertSame( 1, $this->sequences->members( $segmented ) );

		$filtered = $this->make_sequence(
			array(
				'audience' => array( 'type' => 'filters', 'filters' => $plan ),
			)
		);

		$stats = $this->engine->stats( $filtered );
		$this->assertSame( 1, $stats['members'] );
		$this->assertSame( 0, $stats['trackers']['total'] );
	}

	public function test_admin_handlers_save_enroll_and_cancel(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$sequence = $this->make_sequence();
		$contact  = $this->make_contact();

		$this->post(
			array(
				'_wpnonce'      => wp_create_nonce( 'nowcrm_save_sequence' ),
				'id'            => $sequence,
				'name'          => 'Handler sequence',
				'status'        => SequenceRepository::STATUS_ACTIVE,
				'audience_type' => 'all',
				'allow_reentry' => '1',
				'steps'         => array(
					array(
						'name'          => 'One',
						'subject'       => 'Handler step one',
						'preheader'     => '',
						'mode'          => 'html',
						'content'       => '<p>One</p>',
						'delay_seconds' => '0',
					),
					array(
						'name'          => 'Two',
						'subject'       => 'Handler step two',
						'preheader'     => '',
						'mode'          => 'html',
						'content'       => '<p>Two</p>',
						'delay_seconds' => '120',
					),
				),
			)
		);

		$redirect = $this->capture_redirect( static fn() => Actions::save_sequence() );

		$this->assertStringContainsString( 'nowcrm_notice=sequence_saved', $redirect );

		$saved = $this->sequences->get( $sequence );
		$this->assertSame( 'Handler sequence', $saved['name'] );
		$this->assertTrue( $saved['allow_reentry'] );
		$this->assertSame( array( 0, 120 ), array_column( $saved['steps'], 'delay_seconds' ) );

		$this->post(
			array(
				'_wpnonce' => wp_create_nonce( 'nowcrm_enroll_sequence' ),
				'id'       => $sequence,
			)
		);

		$redirect = $this->capture_redirect( static fn() => Actions::enroll_sequence() );

		$this->assertStringContainsString( 'nowcrm_notice=sequence_enrolled', $redirect );
		$this->assertStringContainsString( 'enrolled=1', $redirect );
		$this->assertNotNull( $this->trackers->find_active( $sequence, $contact ) );

		$this->post(
			array(
				'_wpnonce'   => wp_create_nonce( 'nowcrm_cancel_sequence_enrollment' ),
				'id'         => $sequence,
				'contact_id' => $contact,
				'return'     => 'runs',
			)
		);

		$redirect = $this->capture_redirect( static fn() => Actions::cancel_sequence_enrollment() );

		$this->assertStringContainsString( 'nowcrm_notice=sequence_enrollment_cancelled', $redirect );
		$this->assertNull( $this->trackers->find_active( $sequence, $contact ) );

		$this->post(
			array(
				'_wpnonce' => wp_create_nonce( 'nowcrm_duplicate_sequence' ),
				'id'       => $sequence,
			)
		);

		$redirect = $this->capture_redirect( static fn() => Actions::duplicate_sequence() );
		parse_str( (string) wp_parse_url( $redirect, PHP_URL_QUERY ), $query );
		$copy = absint( $query['id'] ?? 0 );

		$this->assertStringContainsString( 'nowcrm_notice=sequence_duplicated', $redirect );
		$this->assertGreaterThan( 0, $copy );

		$copied = $this->sequences->get( $copy );

		$this->assertNotNull( $copied );
		$this->assertSame( SequenceRepository::STATUS_DRAFT, $copied['status'] );
		$this->assertCount( 2, $copied['steps'] );
	}

	public function test_admin_post_round_trips_hour_and_day_units_with_content(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$sequence = $this->make_sequence();

		$this->post(
			array(
				'_wpnonce'      => wp_create_nonce( 'nowcrm_save_sequence' ),
				'id'            => $sequence,
				'name'          => 'Unit delay handler',
				'status'        => SequenceRepository::STATUS_ACTIVE,
				'audience_type' => 'all',
				'allow_reentry' => '1',
				'steps'         => array(
					array(
						'name'               => 'Hourly',
						'subject'            => 'Hourly subject',
						'preheader'          => 'Hourly preheader',
						'mode'               => 'blocks',
						'content'            => '<!-- wp:paragraph --><p>Hourly</p><!-- /wp:paragraph -->',
						'delay_value'        => '2',
						'delay_unit'         => 'hours',
						'delay_seconds_part' => '30',
						'delay_seconds'      => '999',
					),
					array(
						'name'               => 'Daily',
						'subject'            => 'Daily subject',
						'preheader'          => 'Daily preheader',
						'mode'               => 'html',
						'content'            => '<p>Daily</p>',
						'delay_value'        => '3',
						'delay_unit'         => 'days',
						'delay_seconds_part' => '5',
						'delay_seconds'      => '0',
					),
				),
			)
		);

		$redirect = $this->capture_redirect( static fn() => Actions::save_sequence() );

		$this->assertStringContainsString( 'nowcrm_notice=sequence_saved', $redirect );

		$saved = $this->sequences->get( $sequence );

		$this->assertNotNull( $saved );
		$this->assertSame( array( 7230, 259205 ), array_column( $saved['steps'], 'delay_seconds' ) );
		$this->assertSame( array( 'Hourly', 'Daily' ), array_column( $saved['steps'], 'name' ) );
		$this->assertSame( array( 'Hourly subject', 'Daily subject' ), array_column( $saved['steps'], 'subject' ) );
		$this->assertSame( array( 'Hourly preheader', 'Daily preheader' ), array_column( $saved['steps'], 'preheader' ) );
		$this->assertSame( array( 'blocks', 'html' ), array_column( $saved['steps'], 'mode' ) );
		$this->assertNotEmpty( $saved['steps'][0]['uuid'] );
		$this->assertNotEmpty( $saved['steps'][1]['uuid'] );
		$this->assertNotSame( $saved['steps'][0]['uuid'], $saved['steps'][1]['uuid'] );
		$this->assertSame( '<!-- wp:paragraph --><p>Hourly</p><!-- /wp:paragraph -->', $saved['steps'][0]['content'] );
		$this->assertSame( '<p>Daily</p>', $saved['steps'][1]['content'] );
	}

	public function test_sequence_tracker_list_is_paginated(): void {
		$sequence = $this->make_sequence();
		$contacts = array();

		for ( $index = 0; $index < 3; $index++ ) {
			$contacts[] = $this->make_contact();
		}

		$this->engine->enroll( $sequence, $contacts );

		$page = $this->trackers->paginate( $sequence, 1, 2 );

		$this->assertSame( 3, $page['total'] );
		$this->assertCount( 2, $page['items'] );
	}

	public function test_catalog_facets_search_legacy_draft_and_page_clamp(): void {
		$draft  = $this->make_sequence(
			array(
				'name'   => 'Catalog draft',
				'status' => SequenceRepository::STATUS_DRAFT,
			)
		);
		$active = $this->make_sequence( array( 'name' => 'Catalog active' ) );
		$paused = $this->make_sequence(
			array(
				'name'   => 'Catalog paused',
				'status' => SequenceRepository::STATUS_PAUSED,
			)
		);
		$legacy = wp_insert_post(
			array(
				'post_type'   => SequenceRepository::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'Legacy missing status',
			),
			true
		);

		$this->assertIsInt( $legacy );

		$all = $this->sequences->catalog( '', 'all', 1, 2 );

		$this->assertSame( 4, $all['total'] );
		$this->assertSame( 2, $all['pages'] );
		$this->assertSame( 1, $all['page'] );
		$this->assertCount( 2, $all['items'] );

		$expected_counts = array(
			'all'    => 4,
			'draft'  => 2,
			'active' => 1,
			'paused' => 1,
		);
		$actual_counts   = $all['counts'];
		ksort( $expected_counts );
		ksort( $actual_counts );

		$this->assertSame( $expected_counts, $actual_counts );
		$this->assertSame( (int) $legacy, (int) $all['items'][0]['id'] );

		$search = $this->sequences->catalog( 'Legacy', 'all', 1, 10 );

		$this->assertSame( 1, $search['total'] );
		$this->assertSame( 1, $search['counts']['draft'] );
		$this->assertSame( (int) $legacy, (int) $search['items'][0]['id'] );

		$clamped = $this->sequences->catalog( '', 'draft', 99, 10 );

		$this->assertSame( 2, $clamped['total'] );
		$this->assertSame( 1, $clamped['pages'] );
		$this->assertSame( 1, $clamped['page'] );
		$this->assertSame( array( $legacy, $draft ), array_column( $clamped['items'], 'id' ) );

		$active_page = $this->sequences->catalog( '', 'active', 1, 5 );

		$this->assertSame( 1, $active_page['total'] );
		$this->assertSame( $active, (int) $active_page['items'][0]['id'] );
		$this->assertSame( 1, $this->sequences->catalog( '', 'paused', 1, 5 )['total'] );
		$this->assertSame( 4, $this->sequences->catalog( '', 'unknown', 1, 5 )['total'] );
	}

	/**
	 * Simulate a POST request with HTTP string values.
	 *
	 * @param array<string, mixed> $fields Request fields.
	 */
	private function post( array $fields ): void {
		$fields   = $this->http_scalars( $fields );
		$_POST    = $fields;
		$_REQUEST = $fields;
	}

	/**
	 * Recursively cast scalars the way PHP receives form data.
	 *
	 * @param mixed $value Request value.
	 * @return mixed
	 */
	private function http_scalars( $value ) {
		if ( is_array( $value ) ) {
			$clean = array();

			foreach ( $value as $key => $item ) {
				$clean[ $key ] = $this->http_scalars( $item );
			}

			return $clean;
		}

		if ( is_bool( $value ) ) {
			return $value ? '1' : '0';
		}

		return is_scalar( $value ) || null === $value ? (string) $value : '';
	}

	/**
	 * Run a handler and return its redirect target.
	 *
	 * @param callable $handler Handler callback.
	 */
	private function capture_redirect( callable $handler ): string {
		$captured = '';
		$filter   = static function ( $location ) use ( &$captured ): void {
			$captured = (string) $location;
			throw new NowCrm_Sequence_Redirect_Captured( $captured );
		};

		add_filter( 'wp_redirect', $filter, 1 );

		try {
			$handler();
		} catch ( NowCrm_Sequence_Redirect_Captured $error ) {
			$captured = $error->getMessage();
		} finally {
			remove_filter( 'wp_redirect', $filter, 1 );
			$_POST    = array();
			$_REQUEST = array();
		}

		$this->assertNotSame( '', $captured, 'The handler did not redirect.' );

		return $captured;
	}

	/**
	 * Set a job status without a transport.
	 */
	private function set_job_status( int $id, string $status ): void {
		global $wpdb;

		$wpdb->update(
			Schema::table( 'email_jobs' ),
			array(
				'status'     => $status,
				'updated_at' => gmdate( 'Y-m-d H:i:s' ),
			),
			array( 'id' => $id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
	}
}
