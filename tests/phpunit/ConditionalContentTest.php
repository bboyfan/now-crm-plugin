<?php
/**
 * Conditional campaign content: per-recipient evaluation and frozen snapshots.
 *
 * @package NowCRM
 */

use NowCRM\Campaign\Campaigns;
use NowCRM\Contacts\Status;
use NowCRM\Email\ConditionalContent;
use NowCRM\Email\EmailRenderer;
use NowCRM\Templates\TemplateRepository;
use NowCRM\Terms\TermRepository;

final class ConditionalContentTest extends NowCrmTestCase {

	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	/**
	 * Serialize one conditional block.
	 *
	 * @param array<string, string> $attributes field, operator, value.
	 */
	private function block( array $attributes, string $inner = '<p>Only for members</p>' ): string {
		return '<!-- wp:nowcrm/conditional ' . wp_json_encode( $attributes ) . ' -->' . $inner . '<!-- /wp:nowcrm/conditional -->';
	}

	private function render( string $content, string $email, string $mode = 'blocks' ): string {
		return ( new EmailRenderer() )->render(
			array( 'mode' => $mode, 'content' => $content, 'subject' => 'Subject', 'footer_text' => '' ),
			array( 'email' => $email )
		)['html'];
	}

	public function test_matches_and_does_not_match_native_contact_fields(): void {
		$customer = $this->make_contact( array( 'contact_type' => 'customer', 'country' => 'US' ) );
		$lead     = $this->make_contact( array( 'contact_type' => 'lead', 'country' => 'TW' ) );
		$email    = (string) $this->contacts->get( $customer )['email'];
		$other    = (string) $this->contacts->get( $lead )['email'];

		$type = $this->block( array( 'field' => 'contact_type', 'operator' => 'is', 'value' => 'customer' ) );
		$this->assertStringContainsString( 'Only for members', $this->render( $type, $email ) );
		$this->assertStringNotContainsString( 'Only for members', $this->render( $type, $other ) );

		$country = $this->block( array( 'field' => 'country', 'operator' => 'is', 'value' => 'US' ) );
		$this->assertStringContainsString( 'Only for members', $this->render( $country, $email ) );
		$this->assertStringNotContainsString( 'Only for members', $this->render( $country, $other ) );

		$not = $this->block( array( 'field' => 'contact_type', 'operator' => 'is_not', 'value' => 'customer' ) );
		$this->assertStringContainsString( 'Only for members', $this->render( $not, $other ) );
		$this->assertStringNotContainsString( 'Only for members', $this->render( $not, $email ) );

		$status = $this->make_contact( array( 'status' => Status::UNSUBSCRIBED ) );
		$status_email = (string) $this->contacts->get( $status )['email'];
		$status_block = $this->block( array( 'field' => 'status', 'operator' => 'is', 'value' => Status::UNSUBSCRIBED ) );
		$this->assertStringContainsString( 'Only for members', $this->render( $status_block, $status_email ) );
		$this->assertStringNotContainsString( 'Only for members', $this->render( $status_block, $email ) );
	}

	public function test_nested_conditions_and_list_or_tag_membership(): void {
		$id  = $this->make_contact( array( 'contact_type' => 'customer' ) );
		$email = (string) $this->contacts->get( $id )['email'];
		$list = $this->terms->create( TermRepository::TYPE_LIST, 'VIP' );
		$tag  = $this->terms->create( TermRepository::TYPE_TAG, 'Early' );
		$this->assertIsInt( $list );
		$this->assertIsInt( $tag );
		$this->terms->attach( $id, $list );
		$this->terms->attach( $id, $tag );

		$inner = $this->block( array( 'field' => 'list', 'operator' => 'is', 'value' => (string) $list ) );
		$nested = $this->block( array( 'field' => 'contact_type', 'operator' => 'is', 'value' => 'customer' ), $inner );
		$this->assertStringContainsString( 'Only for members', $this->render( $nested, $email ) );

		$missing = $this->block( array( 'field' => 'contact_type', 'operator' => 'is', 'value' => 'lead' ), $inner );
		$this->assertStringNotContainsString( 'Only for members', $this->render( $missing, $email ) );

		$tag_block = $this->block( array( 'field' => 'tag', 'operator' => 'is', 'value' => (string) $tag ) );
		$this->assertStringContainsString( 'Only for members', $this->render( $tag_block, $email ) );
		$this->assertStringNotContainsString(
			'Only for members',
			$this->render( $this->block( array( 'field' => 'list', 'operator' => 'is', 'value' => (string) ( $list + 1000 ) ) ), $email )
		);
	}

	public function test_unknown_conditions_and_malformed_markers_fail_closed(): void {
		$id    = $this->make_contact();
		$email = (string) $this->contacts->get( $id )['email'];

		$this->assertStringNotContainsString(
			'Only for members',
			$this->render( $this->block( array( 'field' => 'role', 'operator' => 'is', 'value' => 'admin' ) ), $email )
		);
		$this->assertStringNotContainsString(
			'Only for members',
			$this->render( $this->block( array( 'field' => 'contact_type', 'operator' => 'contains', 'value' => 'customer' ) ), $email )
		);
		$this->assertStringNotContainsString(
			'Only for members',
			$this->render( $this->block( array( 'field' => 'contact_type', 'operator' => 'is', 'value' => 'not-a-type' ) ), $email )
		);

		$html = '<p>Visible</p><!--nowcrm:if:YmFk--><p>Hidden</p><!--/nowcrm:if-->'
			. '<!--/nowcrm:if--><p>Tail</p>';
		$rendered = $this->render( $html, $email, 'html' );
		$this->assertStringContainsString( 'Visible', $rendered );
		$this->assertStringContainsString( 'Tail', $rendered );
		$this->assertStringNotContainsString( 'Hidden', $rendered );
	}

	public function test_snapshot_and_template_copy_preserve_conditions(): void {
		$blocks = $this->block( array( 'field' => 'contact_type', 'operator' => 'is', 'value' => 'customer' ) );
		$template_id = ( new TemplateRepository() )->create(
			array(
				'name'    => 'Conditional template',
				'mode'    => Campaigns::MODE_BLOCKS,
				'blocks'  => $blocks,
				'html'    => '',
				'subject' => 'Subject',
				'status'  => TemplateRepository::STATUS_PUBLISH,
			)
		);
		$this->assertIsInt( $template_id );

		$campaign = Campaigns::create_draft( 'Conditional campaign', Campaigns::MODE_BLOCKS, $template_id );
		$this->assertIsInt( $campaign );
		$this->assertStringContainsString( 'wp:nowcrm/conditional', (string) get_post( $campaign )->post_content );

		$snapshot = Campaigns::snapshot( $campaign, Campaigns::draft( get_post( $campaign ) ) );
		$this->assertStringContainsString( 'nowcrm:if', $snapshot['content'] );
		$this->assertStringContainsString( 'Only for members', $snapshot['content'] );

		$customer = $this->make_contact( array( 'contact_type' => 'customer' ) );
		$lead     = $this->make_contact( array( 'contact_type' => 'lead' ) );
		$customer_html = ( new EmailRenderer() )->render(
			$snapshot + array( 'subject' => 'Subject' ),
			array( 'email' => (string) $this->contacts->get( $customer )['email'] )
		)['html'];
		$lead_html = ( new EmailRenderer() )->render(
			$snapshot + array( 'subject' => 'Subject' ),
			array( 'email' => (string) $this->contacts->get( $lead )['email'] )
		)['html'];

		$this->assertStringContainsString( 'Only for members', $customer_html );
		$this->assertStringNotContainsString( 'Only for members', $lead_html );
	}

	public function test_preview_uses_the_current_contact(): void {
		$email = (string) wp_get_current_user()->user_email;
		$this->make_contact( array( 'email' => $email, 'contact_type' => 'customer' ) );

		$campaign = Campaigns::create_draft( 'Preview campaign', Campaigns::MODE_BLOCKS );
		$this->assertIsInt( $campaign );
		$content = $this->block( array( 'field' => 'contact_type', 'operator' => 'is', 'value' => 'customer' ), '<p>Visible in preview</p>' )
			. $this->block( array( 'field' => 'contact_type', 'operator' => 'is', 'value' => 'lead' ), '<p>Hidden in preview</p>' );
		wp_update_post( array( 'ID' => $campaign, 'post_content' => wp_slash( $content ) ) );

		$request  = new WP_REST_Request( 'GET', '/nowcrm/v1/campaigns/' . $campaign . '/preview' );
		$response = rest_do_request( $request );
		$this->assertSame( 200, $response->get_status() );
		$html = (string) $response->get_data()['html'];
		$this->assertStringContainsString( 'Visible in preview', $html );
		$this->assertStringNotContainsString( 'Hidden in preview', $html );
	}
}
