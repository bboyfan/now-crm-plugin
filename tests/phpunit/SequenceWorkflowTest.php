<?php
/**
 * Sequence editor delay controls, shared email tools, and step performance.
 *
 * @package NowCRM
 */

use NowCRM\Admin\SequenceEditorPage;
use NowCRM\Admin\SequenceRunsPage;
use NowCRM\Email\EmailDispatcher;
use NowCRM\Email\EmailJobRepository;
use NowCRM\Email\Queue;
use NowCRM\Email\Tracking;
use NowCRM\Sequences\Engine;
use NowCRM\Sequences\SequenceRepository;
use NowCRM\Sequences\StepStats;
use NowCRM\Support\Schema;

/**
 * @covers \NowCRM\Admin\SequenceEditorPage
 * @covers \NowCRM\Admin\SequenceRunsPage
 * @covers \NowCRM\Sequences\StepStats
 * @covers \NowCRM\Sequences\SequenceRepository
 */
final class SequenceWorkflowTest extends NowCrmTestCase {

	private SequenceRepository $sequences;
	private EmailJobRepository $jobs;

	public function set_up(): void {
		parent::set_up();

		$this->sequences = new SequenceRepository();
		$this->jobs      = new EmailJobRepository();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$_GET = array();
	}

	public function tear_down(): void {
		$_GET = array();

		parent::tear_down();
	}

	/**
	 * Create one sequence with explicit steps.
	 *
	 * @param array<int, array<string, mixed>> $steps Step rows.
	 */
	private function make_sequence( array $steps, string $status = SequenceRepository::STATUS_DRAFT ): int {
		$id = $this->sequences->create(
			array(
				'name'     => 'Workflow fixture ' . wp_generate_password( 6, false ),
				'status'   => $status,
				'audience' => array( 'type' => 'all' ),
				'steps'    => $steps,
			)
		);

		$this->assertNotWPError( $id );

		return (int) $id;
	}

	/**
	 * Render the editor for one sequence.
	 */
	private function render_editor( int $id ): string {
		$_GET = array(
			'page' => 'nowcrm-sequence',
			'id'   => (string) $id,
		);

		ob_start();
		SequenceEditorPage::render();

		return (string) ob_get_clean();
	}

	/**
	 * Insert one email event row.
	 */
	private function add_event( int $job_id, int $contact_id, string $type ): void {
		global $wpdb;

		$inserted = $wpdb->insert(
			Schema::table( 'email_events' ),
			array(
				'event_key'   => $type . '-' . wp_generate_password( 12, false ),
				'type'        => $type,
				'campaign_id' => 0,
				'job_id'      => $job_id,
				'contact_id'  => $contact_id,
				'url'         => '',
				'user_agent'  => '',
				'ip_hash'     => '',
				'meta'        => null,
				'occurred_at' => gmdate( 'Y-m-d H:i:s' ),
			),
			array( '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s' )
		);

		$this->assertNotFalse( $inserted );
	}

	/**
	 * Mark one job with a terminal or pending status.
	 */
	private function set_job_status( int $job_id, string $status ): void {
		global $wpdb;

		$wpdb->update(
			Schema::table( 'email_jobs' ),
			array( 'status' => $status ),
			array( 'id' => $job_id ),
			array( '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Enqueue one job and return its ID.
	 *
	 * @param array<string, mixed> $overrides Job overrides.
	 */
	private function add_job( array $overrides ): int {
		$job = $this->jobs->enqueue(
			array_merge(
				array(
					'job_key'      => 'sequence:1:tracker:1:step:0',
					'kind'         => 'sequence',
					'contact_id'   => 0,
					'recipient'    => 'fixture-' . wp_generate_password( 8, false ) . '@example.test',
					'subject'      => 'Fixture',
					'payload'      => array(),
					'available_at' => gmdate( 'Y-m-d H:i:s' ),
				),
				$overrides
			)
		);

		$this->assertNotWPError( $job );

		return (int) $job;
	}

	public function test_delay_parts_keep_legacy_seconds_and_show_total(): void {
		$this->assertSame( array( 'value' => 0, 'unit' => 'minutes', 'seconds' => 0 ), SequenceEditorPage::delay_parts( 0 ) );
		$this->assertSame( array( 'value' => 1, 'unit' => 'minutes', 'seconds' => 30 ), SequenceEditorPage::delay_parts( 90 ) );
		$this->assertSame( array( 'value' => 1, 'unit' => 'hours', 'seconds' => 0 ), SequenceEditorPage::delay_parts( 3600 ) );
		$this->assertSame( array( 'value' => 1, 'unit' => 'days', 'seconds' => 0 ), SequenceEditorPage::delay_parts( 86400 ) );
		$this->assertSame( '1 分鐘 30 秒', SequenceEditorPage::format_delay( 90 ) );
		$this->assertSame( '1 天 2 小時 3 分鐘 4 秒', SequenceEditorPage::format_delay( 93784 ) );
	}

	public function test_editor_renders_unit_delay_and_workflow_scope_and_round_trips_content(): void {
		$id = $this->make_sequence(
			array(
				array(
					'name'          => 'Welcome',
					'subject'       => 'First subject',
					'preheader'     => 'First preheader',
					'mode'          => 'html',
					'content'       => '<p>First</p>',
					'delay_seconds' => 90,
				),
				array(
					'name'          => 'Follow up',
					'subject'       => 'Second subject',
					'preheader'     => '',
					'mode'          => 'blocks',
					'content'       => '<!-- wp:paragraph --><p>Second</p><!-- /wp:paragraph -->',
					'delay_seconds' => 86405,
				),
			)
		);

		$html = $this->render_editor( $id );

		$this->assertStringContainsString( 'data-workflow-email', $html );
		$this->assertStringContainsString( 'data-workflow-source-id="' . $id . '"', $html );
		$this->assertSame( 3, substr_count( $html, 'data-workflow-source-id="' . $id . '"' ) );
		$this->assertStringContainsString( 'data-workflow-field="subject"', $html );
		$this->assertStringContainsString( 'data-workflow-field="preheader"', $html );
		$this->assertStringContainsString( 'data-workflow-field="mode"', $html );
		$this->assertStringContainsString( 'data-workflow-field="content"', $html );
		$this->assertSame( 3, substr_count( $html, 'data-workflow-tools-source="sequence"' ) );
		$this->assertStringContainsString( 'data-workflow-template', $html );
		$this->assertStringContainsString( 'data-workflow-preview', $html );
		$this->assertStringContainsString( 'data-workflow-test', $html );
		$this->assertStringContainsString( 'data-delay-value', $html );
		$this->assertStringContainsString( 'data-delay-unit', $html );
		$this->assertStringContainsString( 'name="steps[0][delay_seconds]"', $html );
		$this->assertStringContainsString( 'value="90" data-delay-seconds', $html );
		$this->assertStringContainsString( '加入後 1 分鐘 30 秒（90 秒）', $html );
		$this->assertStringContainsString( '加入後 1 天 5 秒（86405 秒）', $html );

		$updated = $this->sequences->update(
			$id,
			array(
				'name'     => 'Workflow fixture edited',
				'status'   => SequenceRepository::STATUS_DRAFT,
				'audience' => array( 'type' => 'all' ),
				'steps'    => array(
					array(
						'name'              => 'Welcome',
						'subject'           => 'First subject',
						'preheader'         => 'First preheader',
						'mode'              => 'html',
						'content'           => '<p>First</p>',
						'delay_value'       => '1',
						'delay_unit'        => 'minutes',
						'delay_seconds_part' => '30',
					),
					array(
						'name'              => 'Follow up',
						'subject'           => 'Second subject',
						'preheader'         => 'Updated preheader',
						'mode'              => 'blocks',
						'content'           => '<p>Second</p>',
						'delay_value'       => '1',
						'delay_unit'        => 'days',
						'delay_seconds_part' => '5',
					),
				),
			)
		);

		$this->assertNotWPError( $updated );

		$saved = $this->sequences->get( $id );

		$this->assertNotNull( $saved );
		$this->assertSame( array( 90, 86405 ), array_column( $saved['steps'], 'delay_seconds' ) );
		$this->assertSame( array( 'html', 'blocks' ), array_column( $saved['steps'], 'mode' ) );
		$this->assertSame( 'First preheader', $saved['steps'][0]['preheader'] );
		$this->assertSame( '<p>Second</p>', $saved['steps'][1]['content'] );

		$uuid_first  = (string) $saved['steps'][0]['uuid'];
		$uuid_second = (string) $saved['steps'][1]['uuid'];

		$this->assertNotEmpty( $uuid_first );
		$this->assertNotEmpty( $uuid_second );

		$html_after = $this->render_editor( $id );

		$this->assertStringContainsString( 'value="' . $uuid_first . '" data-step-uuid', $html_after );
		$this->assertStringContainsString( 'value="' . $uuid_second . '" data-step-uuid', $html_after );

		$reordered = $this->sequences->update(
			$id,
			array(
				'name'     => 'Workflow fixture edited',
				'status'   => SequenceRepository::STATUS_DRAFT,
				'audience' => array( 'type' => 'all' ),
				'steps'    => array(
					array(
						'name'          => 'Follow up',
						'subject'       => 'Second subject',
						'preheader'     => 'Updated preheader',
						'mode'          => 'blocks',
						'content'       => '<p>Second</p>',
						'delay_seconds' => 0,
						'uuid'          => $uuid_second,
					),
					array(
						'name'          => 'Welcome',
						'subject'       => 'First subject',
						'preheader'     => 'First preheader',
						'mode'          => 'html',
						'content'       => '<p>First</p>',
						'delay_seconds' => 86405,
						'uuid'          => $uuid_first,
					),
				),
			)
		);

		$this->assertNotWPError( $reordered );

		$moved = $this->sequences->get( $id );

		$this->assertNotNull( $moved );
		$this->assertSame( $uuid_second, $moved['steps'][0]['uuid'] );
		$this->assertSame( 'Second subject', $moved['steps'][0]['subject'] );
		$this->assertSame( $uuid_first, $moved['steps'][1]['uuid'] );
		$this->assertSame( 'First subject', $moved['steps'][1]['subject'] );
	}

	public function test_step_stats_count_only_sequence_jobs_and_unique_engagement(): void {
		$sequence = $this->make_sequence(
			array(
				array( 'name' => 'First', 'subject' => 'First', 'mode' => 'html', 'content' => '<p>First</p>', 'delay_seconds' => 0 ),
				array( 'name' => 'Second', 'subject' => 'Second', 'mode' => 'html', 'content' => '<p>Second</p>', 'delay_seconds' => 60 ),
			)
		);
		$contact = $this->make_contact();
		$saved   = $this->sequences->get( $sequence );

		$this->assertNotNull( $saved );
		$this->assertNotEmpty( $saved['steps'][0]['uuid'] );
		$this->assertNotEmpty( $saved['steps'][1]['uuid'] );

		$uuid0 = (string) $saved['steps'][0]['uuid'];
		$uuid1 = (string) $saved['steps'][1]['uuid'];

		$accepted = $this->add_job(
			array(
				'job_key'    => 'sequence:' . $sequence . ':tracker:10:step:0',
				'contact_id' => $contact,
				'payload'    => array( 'step_index' => 0, 'step_uuid' => $uuid0 ),
			)
		);
		$queued = $this->add_job(
			array(
				'job_key'    => 'sequence:' . $sequence . ':tracker:11:step:0',
				'contact_id' => $contact,
				'payload'    => array( 'step_index' => 0, 'step_uuid' => $uuid0 ),
			)
		);
		$second = $this->add_job(
			array(
				'job_key'    => 'sequence:' . $sequence . ':tracker:10:step:1',
				'contact_id' => $contact,
				'payload'    => array( 'step_index' => 1, 'step_uuid' => $uuid1 ),
			)
		);
		$failed = $this->add_job(
			array(
				'job_key'    => 'sequence:' . $sequence . ':tracker:12:step:1',
				'contact_id' => $contact,
				'payload'    => array( 'step_index' => 1, 'step_uuid' => $uuid1 ),
			)
		);
		$test_job = $this->add_job(
			array(
				'job_key'    => 'sequence:' . $sequence . ':tracker:13:step:0',
				'kind'       => 'test',
				'contact_id' => $contact,
				'payload'    => array( 'step_index' => 0, 'step_uuid' => $uuid0 ),
			)
		);
		$other_sequence = $this->add_job(
			array(
				'job_key'    => 'sequence:' . ( $sequence + 1000 ) . ':tracker:10:step:0',
				'contact_id' => $contact,
				'payload'    => array( 'step_index' => 0, 'step_uuid' => $uuid0 ),
			)
		);

		$this->set_job_status( $accepted, EmailJobRepository::STATUS_ACCEPTED );
		$this->set_job_status( $second, EmailJobRepository::STATUS_ACCEPTED );
		$this->set_job_status( $failed, EmailJobRepository::STATUS_FAILED );
		$this->set_job_status( $test_job, EmailJobRepository::STATUS_ACCEPTED );
		$this->set_job_status( $other_sequence, EmailJobRepository::STATUS_ACCEPTED );

		$this->add_event( $accepted, $contact, 'open' );
		$this->add_event( $accepted, $contact, 'open' );
		$this->add_event( $accepted, $contact, 'click' );
		$this->add_event( $accepted, $contact, 'click' );
		$this->add_event( $queued, $contact, 'open' );
		$this->add_event( $failed, $contact, 'click' );
		$this->add_event( $test_job, $contact, 'open' );
		$this->add_event( $other_sequence, $contact, 'click' );

		$stats = ( new StepStats() )->for_sequence( $sequence );

		$this->assertSame( 2, $stats['steps'][ $uuid0 ]['total'] );
		$this->assertSame( 1, $stats['steps'][ $uuid0 ]['accepted'] );
		$this->assertSame( 1, $stats['steps'][ $uuid0 ]['queued'] );
		$this->assertSame( 1, $stats['steps'][ $uuid0 ]['opens'] );
		$this->assertSame( 1, $stats['steps'][ $uuid0 ]['clicks'] );

		$this->assertSame( 2, $stats['steps'][ $uuid1 ]['total'] );
		$this->assertSame( 1, $stats['steps'][ $uuid1 ]['accepted'] );
		$this->assertSame( 1, $stats['steps'][ $uuid1 ]['failed'] );
		$this->assertSame( 0, $stats['steps'][ $uuid1 ]['opens'] );
		$this->assertSame( 0, $stats['steps'][ $uuid1 ]['clicks'] );
		$this->assertSame( array(), $stats['legacy'] );

		$this->assertSame( 4, $stats['totals']['total'] );
		$this->assertSame( 2, $stats['totals']['accepted'] );
		$this->assertSame( 1, $stats['totals']['opens'] );
		$this->assertSame( 1, $stats['totals']['clicks'] );
		$this->assertSame( 50.0, StepStats::rate( $stats['totals'], 'opens' ) );
		$this->assertNull( StepStats::rate( array( 'accepted' => 0, 'clicks' => 5 ), 'clicks' ) );
	}

	public function test_sequence_tracking_needs_enqueue_marker_and_current_setting(): void {
		$this->set_settings(
			array(
				'email_sending_enabled'     => true,
				'email_transport'           => 'capture',
				'sequence_tracking_enabled' => false,
			)
		);

		$step = array(
			'name'          => 'Tracked',
			'mode'          => 'html',
			'content'       => '<p><a href="https://example.test/offer">Offer</a></p>',
			'delay_seconds' => 0,
		);

		$off_at_enqueue = $this->make_sequence(
			array( array( 'subject' => 'Off at enqueue' ) + $step ),
			SequenceRepository::STATUS_ACTIVE
		);
		$contact_a = $this->make_contact();

		( new Engine() )->enroll( $off_at_enqueue, array( $contact_a ) );

		$this->set_settings( array( 'sequence_tracking_enabled' => true ) );
		( new Queue() )->run();

		$log = (array) get_option( EmailDispatcher::CAPTURE_OPTION, array() );

		$this->assertCount( 1, $log );

		$entries = array_column( $log, null, 'subject' );
		$entry   = $entries['Off at enqueue'];

		$this->assertStringNotContainsString( 'nowcrm/v1/open/', $entry['html'] );
		$this->assertStringNotContainsString( 'nowcrm/v1/click/', $entry['html'] );

		$on_at_enqueue = $this->make_sequence(
			array( array( 'subject' => 'On at enqueue' ) + $step ),
			SequenceRepository::STATUS_ACTIVE
		);
		$contact_b = $this->make_contact();

		( new Engine() )->enroll( $on_at_enqueue, array( $contact_b ) );

		$this->set_settings( array( 'sequence_tracking_enabled' => false ) );
		( new Queue() )->run();

		$log = (array) get_option( EmailDispatcher::CAPTURE_OPTION, array() );

		$this->assertCount( 2, $log );

		$entries = array_column( $log, null, 'subject' );
		$entry   = $entries['On at enqueue'];

		$this->assertStringNotContainsString( 'nowcrm/v1/open/', $entry['html'] );
		$this->assertStringNotContainsString( 'nowcrm/v1/click/', $entry['html'] );

		$enabled = $this->make_sequence(
			array( array( 'subject' => 'Tracking enabled' ) + $step ),
			SequenceRepository::STATUS_ACTIVE
		);
		$contact_c = $this->make_contact();

		$this->set_settings( array( 'sequence_tracking_enabled' => true ) );
		( new Engine() )->enroll( $enabled, array( $contact_c ) );
		( new Queue() )->run();

		$log = (array) get_option( EmailDispatcher::CAPTURE_OPTION, array() );

		$this->assertCount( 3, $log );

		$entries = array_column( $log, null, 'subject' );
		$entry   = $entries['Tracking enabled'];

		$this->assertStringContainsString( 'nowcrm/v1/open/', $entry['html'] );
		$this->assertStringContainsString( 'nowcrm/v1/click/', $entry['html'] );
		$this->assertStringNotContainsString( 'utm_source=', $entry['html'] );
	}

	public function test_sequence_tokens_record_unique_events_and_match_step_uuid(): void {
		$this->set_settings(
			array(
				'email_sending_enabled'     => true,
				'email_transport'           => 'capture',
				'sequence_tracking_enabled' => true,
			)
		);

		$sequence = $this->make_sequence(
			array(
				array(
					'name'          => 'Clicked',
					'subject'       => 'Token fixture',
					'mode'          => 'html',
					'content'       => '<p><a href="https://example.test/offer">Offer</a></p>',
					'delay_seconds' => 0,
				),
			),
			SequenceRepository::STATUS_ACTIVE
		);
		$contact = $this->make_contact();

		( new Engine() )->enroll( $sequence, array( $contact ) );
		( new Queue() )->run();

		$log  = (array) get_option( EmailDispatcher::CAPTURE_OPTION, array() );
		$html = rawurldecode( html_entity_decode( (string) $log[0]['html'], ENT_QUOTES ) );

		$this->assertSame( 1, preg_match( '#nowcrm/v1/open/([A-Za-z0-9_.\-]+)#', $html, $open_match ) );
		$this->assertSame( 1, preg_match( '#nowcrm/v1/click/([A-Za-z0-9_.\-]+)#', $html, $click_match ) );

		$tracking = new Tracking();
		$open     = $tracking->verify( $open_match[1] );
		$click    = $tracking->verify( $click_match[1] );

		$this->assertIsArray( $open );
		$this->assertIsArray( $click );
		$this->assertSame( 0, (int) $open['c'] );
		$this->assertSame( $contact, (int) $open['t'] );
		$this->assertSame( (int) $open['j'], (int) $click['j'] );
		$this->assertGreaterThan( 0, (int) $open['j'] );

		$job_id = (int) $open['j'];

		$tracking->record_open( $open );
		$tracking->record_open( $open );
		$tracking->record_click( $click, (string) $click['u'] );
		$tracking->record_click( $click, (string) $click['u'] );

		$this->assertSame( 1, $this->table_count( 'email_events', "type = 'open' AND job_id = {$job_id}" ) );
		$this->assertSame( 1, $this->table_count( 'email_events', "type = 'click' AND job_id = {$job_id}" ) );

		$saved = $this->sequences->get( $sequence );
		$uuid  = (string) $saved['steps'][0]['uuid'];
		$stats = ( new StepStats() )->for_sequence( $sequence );

		$this->assertSame( 1, $stats['steps'][ $uuid ]['accepted'] );
		$this->assertSame( 1, $stats['steps'][ $uuid ]['opens'] );
		$this->assertSame( 1, $stats['steps'][ $uuid ]['clicks'] );
		$this->assertSame( 100.0, StepStats::rate( $stats['steps'][ $uuid ], 'opens' ) );
	}

	public function test_legacy_jobs_report_by_historical_position_not_current_name(): void {
		$sequence = $this->make_sequence(
			array(
				array( 'name' => 'Current name', 'subject' => 'Current subject', 'mode' => 'html', 'content' => '<p>Current</p>', 'delay_seconds' => 0 ),
			)
		);
		$contact = $this->make_contact();
		$saved   = $this->sequences->get( $sequence );
		$uuid    = (string) $saved['steps'][0]['uuid'];

		$legacy = $this->add_job(
			array(
				'job_key'    => 'sequence:' . $sequence . ':tracker:20:step:0',
				'contact_id' => $contact,
				'payload'    => array( 'step_index' => 0, 'step_name' => 'Old name' ),
			)
		);

		$this->set_job_status( $legacy, EmailJobRepository::STATUS_ACCEPTED );
		$this->add_event( $legacy, $contact, 'open' );
		$this->add_event( $legacy, $contact, 'click' );

		$stats = ( new StepStats() )->for_sequence( $sequence );

		$this->assertArrayNotHasKey( $uuid, $stats['steps'] );
		$this->assertSame( 1, $stats['legacy'][0]['accepted'] );
		$this->assertSame( 1, $stats['legacy'][0]['opens'] );
		$this->assertSame( 1, $stats['legacy'][0]['clicks'] );

		$_GET = array(
			'page' => 'nowcrm-sequence-runs',
			'id'   => (string) $sequence,
		);

		ob_start();
		SequenceRunsPage::render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( '歷史位置 0（舊資料，未綁定目前步驟）', $html );
		$this->assertStringContainsString( '1. Current name', $html );
	}
}
