<?php
/**
 * Native form storage, validation, submission, and scoping.
 *
 * @package NowCRM
 */

use NowCRM\Admin\FormsPage;
use NowCRM\Admin\Menu;
use NowCRM\Forms\EntryRepository;
use NowCRM\Forms\Fields;
use NowCRM\Forms\FormRenderer;
use NowCRM\Forms\FormRepository;
use NowCRM\Forms\Submission;
use NowCRM\Support\Capabilities;
use NowCRM\Terms\TermRepository;

/**
 * Sentinel thrown by the redirect filter so a handler never reaches exit().
 */
final class NowCrm_Forms_Redirect_Captured extends \RuntimeException {}

/**
 * @covers \NowCRM\Forms\FormRepository
 * @covers \NowCRM\Forms\EntryRepository
 * @covers \NowCRM\Forms\Fields
 * @covers \NowCRM\Forms\Submission
 * @covers \NowCRM\Forms\FormRenderer
 * @covers \NowCRM\Admin\FormsPage
 */
final class FormsTest extends NowCrmTestCase {

	public function set_up(): void {
		parent::set_up();

		FormRepository::register_post_types();
		$_GET = array();
	}

	public function tear_down(): void {
		$_GET = array();
		wp_set_current_user( 0 );

		parent::tear_down();
	}

	public function test_field_validation_accepts_typed_values_and_reports_each_error(): void {
		$fields = Fields::normalize_definitions(
			array(
				array(
					'key'      => 'email',
					'label'    => 'Email',
					'type'     => 'email',
					'required' => true,
				),
				array(
					'key'      => 'age',
					'label'    => 'Age',
					'type'     => 'number',
					'required' => true,
				),
				array(
					'key'      => 'when',
					'label'    => 'When',
					'type'     => 'date',
					'required' => true,
				),
				array(
					'key'      => 'pick',
					'label'    => 'Pick',
					'type'     => 'select',
					'required' => true,
					'options'  => "A\nB",
				),
				array(
					'key'      => 'extras',
					'label'    => 'Extras',
					'type'     => 'checkbox',
					'required' => true,
					'options'  => array( 'X', 'Y' ),
				),
			)
		);

		$this->assertCount( 5, $fields );

		$missing = Fields::validate( $fields, array() );

		$this->assertWPError( $missing );
		$this->assertSame( 'nowcrm_form_invalid', $missing->get_error_code() );
		$this->assertArrayHasKey( 'email', $missing->get_error_data()['field_errors'] );
		$this->assertArrayHasKey( 'extras', $missing->get_error_data()['field_errors'] );

		$invalid = Fields::validate(
			$fields,
			array(
				'email'  => 'not-an-email',
				'age'    => 'abc',
				'when'   => '2026-02-30',
				'pick'   => 'Z',
				'extras' => array( 'Q' ),
			)
		);

		$this->assertWPError( $invalid );
		$this->assertCount( 5, $invalid->get_error_data()['field_errors'] );

		$valid = Fields::validate(
			$fields,
			array(
				'email'  => 'valid@example.test',
				'age'    => '12.5',
				'when'   => '2026-02-28',
				'pick'   => 'B',
				'extras' => array( 'X', 'Y' ),
			)
		);

		$this->assertIsArray( $valid );
		$this->assertSame( 'valid@example.test', $valid['email'] );
		$this->assertSame( '12.5', $valid['age'] );
		$this->assertSame( '2026-02-28', $valid['when'] );
		$this->assertSame( 'B', $valid['pick'] );
		$this->assertSame( array( 'X', 'Y' ), $valid['extras'] );
	}

	public function test_unsafe_markup_is_stripped_from_the_stored_entry(): void {
		$form_id = $this->make_form(
			array(
				'fields' => array(
					array(
						'key'      => 'email',
						'label'    => 'Email',
						'type'     => 'email',
						'required' => true,
					),
					array(
						'key'   => 'message',
						'label' => 'Message',
						'type'  => 'textarea',
					),
				),
			)
		);

		$result = ( new Submission() )->process(
			$form_id,
			array(
				'email'   => 'xss-form@example.test',
				'message' => '<script>alert(1)</script>Hello <b>there</b>',
			)
		);

		$this->assertNotWPError( $result );

		$entry = ( new EntryRepository() )->get( (int) $result['entry_id'], $form_id );

		$this->assertNotNull( $entry );

		$values = array();

		foreach ( $entry['data']['fields'] as $field ) {
			$values[ $field['key'] ] = $field['value'];
		}

		$this->assertArrayHasKey( 'message', $values );
		$this->assertStringNotContainsString( '<', $values['message'] );
		$this->assertStringNotContainsString( 'script', $values['message'] );
		$this->assertStringContainsString( 'Hello', $values['message'] );
	}

	public function test_repeated_submission_never_duplicates_the_contact(): void {
		global $wpdb;

		$form_id    = $this->make_form();
		$submission = new Submission();

		$first  = $submission->process( $form_id, array( 'email' => 'same-form@example.test' ) );
		$second = $submission->process( $form_id, array( 'email' => 'same-form@example.test' ) );

		$this->assertNotWPError( $first );
		$this->assertNotWPError( $second );
		$this->assertTrue( $first['contact_created'] );
		$this->assertFalse( $second['contact_created'] );
		$this->assertSame( (int) $first['contact_id'], (int) $second['contact_id'] );

		$contacts_table = \NowCRM\Support\Schema::table( 'contacts' );
		$where          = $wpdb->prepare( 'email_normalized = %s', 'same-form@example.test' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test helper, trusted table name.
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$contacts_table} WHERE {$where}" );

		$this->assertSame( 1, $count );

		$entries = ( new EntryRepository() )->entries( $form_id );

		$this->assertSame( 2, $entries['total'] );
	}

	public function test_entries_are_scoped_to_their_form_and_never_expose_email_in_the_post_title(): void {
		$form_a = $this->make_form();
		$form_b = $this->make_form();
		$result = ( new Submission() )->process( $form_a, array( 'email' => 'scoped@example.test' ) );

		$this->assertNotWPError( $result );

		$entries = new EntryRepository();
		$entry   = $entries->get( (int) $result['entry_id'], $form_a );

		$this->assertNotNull( $entry );
		$this->assertNull( $entries->get( (int) $result['entry_id'], $form_b ) );
		$this->assertSame( 1, $entries->entries( $form_a )['total'] );
		$this->assertSame( 0, $entries->entries( $form_b )['total'] );

		$post = get_post( (int) $result['entry_id'] );

		$this->assertNotNull( $post );
		$this->assertSame( FormRepository::ENTRY_TYPE, $post->post_type );
		$this->assertSame( $form_a, (int) $post->post_parent );
		$this->assertStringNotContainsString( 'scoped@example.test', $post->post_title );

		$type = get_post_type_object( FormRepository::ENTRY_TYPE );

		$this->assertNotNull( $type );
		$this->assertFalse( $type->public );
		$this->assertFalse( $type->show_in_rest );
	}

	public function test_submission_attaches_configured_lists_and_tags_without_removing_existing_ones(): void {
		$list_id = $this->terms->create( TermRepository::TYPE_LIST, 'Form list ' . wp_generate_password( 6, false ) );
		$tag_id  = $this->terms->create( TermRepository::TYPE_TAG, 'Form tag ' . wp_generate_password( 6, false ) );

		$this->assertIsInt( $list_id );
		$this->assertIsInt( $tag_id );

		$form_id = $this->make_form(
			array(
				'lists' => array( $list_id ),
				'tags'  => array( $tag_id ),
			)
		);

		$result = ( new Submission() )->process( $form_id, array( 'email' => 'terms-form@example.test' ) );

		$this->assertNotWPError( $result );

		$ids = array_map(
			'intval',
			array_column( $this->terms->for_contact( (int) $result['contact_id'] ), 'id' )
		);

		$this->assertContains( (int) $list_id, $ids );
		$this->assertContains( (int) $tag_id, $ids );
	}

	public function test_form_settings_reject_missing_fields_and_wrong_term_types(): void {
		$no_fields = FormRepository::create(
			array(
				'title'  => 'No fields',
				'fields' => array(),
			)
		);

		$this->assertWPError( $no_fields );
		$this->assertSame( 'nowcrm_form_no_fields', $no_fields->get_error_code() );

		$no_email = FormRepository::create(
			array(
				'title'  => 'No email',
				'fields' => array(
					array(
						'label' => 'Name',
						'type'  => 'text',
					),
				),
			)
		);

		$this->assertWPError( $no_email );
		$this->assertSame( 'nowcrm_form_needs_email', $no_email->get_error_code() );

		$no_options = FormRepository::create(
			array(
				'title'  => 'No options',
				'fields' => array(
					array(
						'key'   => 'email',
						'label' => 'Email',
						'type'  => 'email',
					),
					array(
						'key'   => 'pick',
						'label' => 'Pick',
						'type'  => 'select',
					),
				),
			)
		);

		$this->assertWPError( $no_options );
		$this->assertSame( 'nowcrm_form_options', $no_options->get_error_code() );

		$list_id = $this->terms->create( TermRepository::TYPE_LIST, 'Wrong type ' . wp_generate_password( 6, false ) );
		$this->assertIsInt( $list_id );

		$form_id = $this->make_form( array( 'tags' => array( $list_id ) ) );
		$form    = FormRepository::get( $form_id );

		$this->assertNotNull( $form );
		$this->assertSame( array(), $form['tags'] );
	}

	public function test_form_round_trip_keeps_field_order_and_forces_the_email_required(): void {
		$form_id = $this->make_form(
			array(
				'fields' => array(
					array(
						'key'   => 'name',
						'label' => 'Name',
						'type'  => 'text',
					),
					array(
						'key'      => 'email',
						'label'    => 'Email',
						'type'     => 'email',
						'required' => false,
					),
					array(
						'key'   => 'when',
						'label' => 'When',
						'type'  => 'date',
					),
				),
			)
		);

		$form = FormRepository::get( $form_id );

		$this->assertNotNull( $form );
		$this->assertSame( array( 'name', 'email', 'when' ), array_column( $form['fields'], 'key' ) );
		$this->assertTrue( $form['fields'][1]['required'] );
		$this->assertSame( 'email', $form['email_key'] );

		$updated = FormRepository::update( $form_id, array( 'title' => 'Renamed form' ) );

		$this->assertTrue( $updated );

		$renamed = FormRepository::get( $form_id );

		$this->assertNotNull( $renamed );
		$this->assertSame( 'Renamed form', $renamed['title'] );
		$this->assertCount( 3, $renamed['fields'] );
	}

	public function test_shortcode_renders_a_posted_form_and_a_success_message_only_after_redirect(): void {
		$form_id = $this->make_form();

		$html = FormRenderer::shortcode( array( 'id' => $form_id ) );

		$this->assertStringContainsString( 'nowcrm_form_submit', $html );
		$this->assertStringContainsString( 'name="nowcrm_form_hp"', $html );
		$this->assertStringContainsString( '_nowcrm_form_nonce', $html );
		$this->assertStringContainsString( 'name="nowcrm_fields[email]"', $html );
		$this->assertStringContainsString( '送出', $html );

		$this->assertSame( '', FormRenderer::shortcode( array( 'id' => 999999 ) ) );

		$_GET['nowcrm_form_success'] = (string) $form_id;

		$success = FormRenderer::shortcode( array( 'id' => $form_id ) );

		$this->assertStringContainsString( '感謝您的填寫', $success );
		$this->assertStringNotContainsString( '<form', $success );
	}

	public function test_form_submitted_action_receives_entry_contact_and_double_opt_in_context(): void {
		$list_id = $this->terms->create( TermRepository::TYPE_LIST, 'DOI list ' . wp_generate_password( 6, false ) );
		$this->assertIsInt( $list_id );

		$form_id = $this->make_form(
			array(
				'lists'         => array( $list_id ),
				'double_opt_in' => true,
			)
		);

		$captured = null;
		$listener = static function ( $entry_id, $fired_form_id, $contact_id, $payload ) use ( &$captured ): void {
			$captured = array(
				'entry_id'   => (int) $entry_id,
				'form_id'    => (int) $fired_form_id,
				'contact_id' => (int) $contact_id,
				'payload'    => $payload,
			);
		};

		add_action( 'nowcrm_form_submitted', $listener, 10, 4 );

		try {
			$result = ( new Submission() )->process( $form_id, array( 'email' => 'doi-form@example.test' ) );
		} finally {
			remove_action( 'nowcrm_form_submitted', $listener, 10 );
		}

		$this->assertNotWPError( $result );
		$this->assertIsArray( $captured );
		$this->assertSame( (int) $result['entry_id'], $captured['entry_id'] );
		$this->assertSame( $form_id, $captured['form_id'] );
		$this->assertSame( (int) $result['contact_id'], $captured['contact_id'] );
		$this->assertTrue( $captured['payload']['double_opt_in'] );
		$this->assertSame( array( $list_id ), $captured['payload']['lists'] );
	}

	public function test_deleting_a_form_removes_its_entries_but_not_the_contact(): void {
		$form_id = $this->make_form();
		$result  = ( new Submission() )->process( $form_id, array( 'email' => 'delete-form@example.test' ) );

		$this->assertNotWPError( $result );
		$this->assertNotNull( $this->contacts->find_by_email( 'delete-form@example.test' ) );

		$entry_id = (int) $result['entry_id'];

		$this->assertTrue( FormRepository::delete( $form_id ) );
		$this->assertNull( FormRepository::get( $form_id ) );
		$this->assertNull( get_post( $entry_id ) );
		$this->assertNotNull( $this->contacts->find_by_email( 'delete-form@example.test' ) );
	}

	public function test_forms_landing_paginates_and_reports_real_entry_usage(): void {
		$this->as_admin();

		$ids = array();

		for ( $index = 1; $index <= 12; $index++ ) {
			$ids[] = $this->make_form( array( 'title' => sprintf( 'Paged form %02d', $index ) ) );
		}

		$entry = ( new EntryRepository() )->create(
			$ids[0],
			array(
				'email'         => 'reader@example.test',
				'contact_id'    => 0,
				'fields'        => array(),
				'lists'         => array(),
				'tags'          => array(),
				'double_opt_in' => false,
			)
		);

		$this->assertIsInt( $entry );

		$_GET = array( 'page' => FormsPage::SLUG );
		$html = $this->render_forms();

		$this->assertSame( 10, substr_count( $html, 'data-form-id="' ) );
		$this->assertStringContainsString( '共 12 筆', $html );
		$this->assertStringContainsString( 'Paged form 01', $html );
		$this->assertStringContainsString( 'data-entry-count="1"', $html );
		$this->assertStringContainsString( '[nowcrm_form id=&quot;' . $ids[0] . '&quot;]', $html );

		$_GET['paged'] = '2';
		$html          = $this->render_forms();

		$this->assertSame( 2, substr_count( $html, 'data-form-id="' ) );
		$this->assertStringContainsString( 'Paged form 11', $html );
	}

	public function test_forms_landing_filters_by_status_and_search(): void {
		$this->as_admin();

		$this->make_form( array( 'title' => 'Published fixture form' ) );
		$draft = $this->make_form( array( 'title' => 'Draft fixture form' ) );

		wp_update_post(
			array(
				'ID'          => $draft,
				'post_status' => 'draft',
			)
		);

		$_GET = array(
			'page'   => FormsPage::SLUG,
			'status' => 'draft',
		);
		$html = $this->render_forms();

		$this->assertStringContainsString( 'Draft fixture form', $html );
		$this->assertStringContainsString( '草稿', $html );
		$this->assertStringNotContainsString( 'Published fixture form', $html );

		$_GET = array(
			'page' => FormsPage::SLUG,
			's'    => 'Published fixture',
		);
		$html = $this->render_forms();

		$this->assertStringContainsString( 'Published fixture form', $html );
		$this->assertStringNotContainsString( 'Draft fixture form', $html );
	}

	public function test_create_route_is_discrete_and_validation_failure_keeps_it(): void {
		$this->as_admin();

		$_GET = array(
			'page' => FormsPage::SLUG,
			'new'  => '1',
		);
		$html = $this->render_forms();

		$this->assertStringContainsString( 'id="nowcrm-forms-create"', $html );
		$this->assertStringContainsString( 'nowcrm_create_form', $html );
		$this->assertStringNotContainsString( 'id="nowcrm-forms"', $html );

		$_POST    = array(
			'action'   => 'nowcrm_create_form',
			'_wpnonce' => wp_create_nonce( 'nowcrm_create_form' ),
			'title'    => '',
		);
		$_REQUEST = $_POST;

		$captured = $this->capture_redirect( static fn() => FormsPage::handle_create() );
		$query    = (string) wp_parse_url( $captured, PHP_URL_QUERY );
		$args     = array();

		parse_str( $query, $args );

		$this->assertSame( FormsPage::SLUG, $args['page'] ?? '' );
		$this->assertSame( '1', $args['new'] ?? '' );
		$this->assertSame( 'title_required', $args['nowcrm_forms_notice'] ?? '' );

		$_GET = array(
			'page'                => FormsPage::SLUG,
			'new'                 => '1',
			'nowcrm_forms_notice' => 'title_required',
		);
		$html = $this->render_forms();

		$this->assertStringContainsString( '請輸入表單名稱。', $html );
		$this->assertStringContainsString( 'id="nowcrm-forms-create"', $html );
	}

	public function test_entry_contact_links_open_the_contact_screen(): void {
		$this->as_admin();

		$form_id = $this->make_form();
		$result  = ( new Submission() )->process( $form_id, array( 'email' => 'entry-contact@example.test' ) );

		$this->assertNotWPError( $result );

		$entry_id   = (int) $result['entry_id'];
		$contact_id = (int) $result['contact_id'];
		$detail     = esc_url( Menu::page_url( Menu::CONTACT_SLUG, array( 'id' => $contact_id ) ) );
		$wrong      = esc_url( Menu::page_url( Menu::SLUG, array( 'id' => $contact_id ) ) );

		$_GET = array(
			'page' => FormsPage::SLUG,
			'form' => (string) $form_id,
			'view' => 'entries',
		);
		$html = $this->render_forms();

		$this->assertStringContainsString( $detail, $html );
		$this->assertStringNotContainsString( $wrong, $html );

		$_GET['view']  = 'entry';
		$_GET['entry'] = (string) $entry_id;
		$html          = $this->render_forms();

		$this->assertStringContainsString( $detail, $html );
		$this->assertStringNotContainsString( $wrong, $html );
	}

	public function test_filtered_form_list_context_survives_the_editor(): void {
		$this->as_admin();

		$form_id = $this->make_form();
		$filters = array(
			's'      => 'Fixture',
			'status' => 'publish',
			'paged'  => 2,
		);

		$_GET = array_merge(
			array(
				'page' => FormsPage::SLUG,
				'form' => (string) $form_id,
			),
			$filters,
			array( 'paged' => '2' )
		);
		$html = $this->render_forms();

		$this->assertStringContainsString( esc_url( Menu::page_url( FormsPage::SLUG, $filters ) ), $html );
		$this->assertStringContainsString( esc_url( FormsPage::entries_url( $form_id, $filters ) ), $html );
	}

	public function test_entry_detail_returns_to_the_same_entries_page_and_list_filters(): void {
		$this->as_admin();

		$form_id = $this->make_form();
		$result  = ( new Submission() )->process( $form_id, array( 'email' => 'entry-back@example.test' ) );

		$this->assertNotWPError( $result );

		$entry_id = (int) $result['entry_id'];
		$context  = array(
			's'      => 'Fixture',
			'status' => 'draft',
			'paged'  => 2,
			'epaged' => 2,
		);

		$_GET = array_merge(
			array(
				'page'  => FormsPage::SLUG,
				'form'  => (string) $form_id,
				'view'  => 'entry',
				'entry' => (string) $entry_id,
			),
			$context,
			array(
				'paged'  => '2',
				'epaged' => '2',
			)
		);
		$html = $this->render_forms();

		$this->assertStringContainsString( esc_url( FormsPage::entries_url( $form_id, $context ) ), $html );
		$this->assertStringContainsString(
			esc_url(
				Menu::page_url(
					FormsPage::SLUG,
					array(
						's'      => 'Fixture',
						'status' => 'draft',
						'paged'  => 2,
					)
				)
			),
			$html
		);
	}

	/**
	 * Use an administrator with the CRM capability for admin screens.
	 */
	private function as_admin(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$user    = get_user_by( 'id', $user_id );

		if ( $user ) {
			$user->add_cap( Capabilities::MANAGE_CONTACTS );
		}

		wp_set_current_user( $user_id );
	}

	/**
	 * Render the forms screen as the current user.
	 */
	private function render_forms(): string {
		ob_start();
		FormsPage::render();

		return (string) ob_get_clean();
	}

	/**
	 * Capture a handler redirect without reaching exit().
	 *
	 * @param callable $handler Handler.
	 */
	private function capture_redirect( callable $handler ): string {
		$captured = '';
		$filter   = static function ( $location ) use ( &$captured ): void {
			$captured = (string) $location;
			throw new NowCrm_Forms_Redirect_Captured( $captured );
		};

		add_filter( 'wp_redirect', $filter, 1 );

		try {
			$handler();
		} catch ( NowCrm_Forms_Redirect_Captured $exception ) {
			$captured = $exception->getMessage();
		} finally {
			remove_filter( 'wp_redirect', $filter, 1 );
		}

		$this->assertNotSame( '', $captured, 'The handler did not redirect.' );

		return $captured;
	}

	/**
	 * Create a form with a default email + name field set.
	 *
	 * @param array<string, mixed> $overrides Overrides for FormRepository::create().
	 */
	private function make_form( array $overrides = array() ): int {
		$id = FormRepository::create(
			array_merge(
				array(
					'title'  => 'Fixture form ' . wp_generate_password( 6, false ),
					'fields' => array(
						array(
							'key'      => 'email',
							'label'    => 'Email',
							'type'     => 'email',
							'required' => true,
						),
						array(
							'key'   => 'name',
							'label' => 'Name',
							'type'  => 'text',
						),
					),
				),
				$overrides
			)
		);

		$this->assertIsInt( $id, 'Fixture form creation failed.' );

		return (int) $id;
	}
}
