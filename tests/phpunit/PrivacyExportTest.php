<?php
/**
 * WordPress personal data exporter contract.
 *
 * @package NowCRM
 */

use NowCRM\Email\EmailJobRepository;
use NowCRM\Email\SuppressionRepository;
use NowCRM\Forms\EntryRepository;
use NowCRM\Forms\FormRepository;
use NowCRM\Support\Schema;

/**
 * @covers \NowCRM\Privacy\PersonalDataExporter
 */
final class PrivacyExportTest extends NowCrmTestCase {

	private const EMAIL = 'export-subject@example.test';

	/**
	 * Export result item IDs.
	 *
	 * @param array<string, mixed> $result Exporter response.
	 * @return string[]
	 */
	private function item_ids( array $result ): array {
		return array_map(
			static fn( array $item ): string => (string) $item['item_id'],
			(array) $result['data']
		);
	}

	/**
	 * Call the registered NOW CRM exporter.
	 *
	 * @return array<string, mixed>
	 */
	private function export( string $email = self::EMAIL, int $page = 1 ): array {
		$exporters = apply_filters( 'wp_privacy_personal_data_exporters', array() );

		$this->assertArrayHasKey( 'now-crm', $exporters );
		$this->assertSame( 'NOW CRM', $exporters['now-crm']['exporter_friendly_name'] );

		return call_user_func( $exporters['now-crm']['callback'], $email, $page );
	}

	public function test_exporter_covers_now_stores_without_secrets(): void {
		global $wpdb;

		$contact = $this->make_contact(
			array(
				'email'      => self::EMAIL,
				'first_name' => 'Export',
				'last_name'  => 'Subject',
			)
		);

		$values = Schema::table( 'contact_field_values' );
		$wpdb->insert(
			$values,
			array(
				'contact_id' => $contact,
				'field_key'  => 'company',
				'value'      => wp_json_encode( 'Fixture Co' ),
				'updated_at' => gmdate( 'Y-m-d H:i:s' ),
			)
		);
		$field_id = (int) $wpdb->insert_id;

		$term_id = $this->terms->create( 'list', 'Export VIP' );
		$this->assertIsInt( $term_id );
		$this->terms->attach( $contact, (int) $term_id );
		$link_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM ' . Schema::table( 'contact_terms' ) . ' WHERE contact_id = %d AND term_id = %d',
				$contact,
				(int) $term_id
			)
		);

		$status_id = $this->history->record( $contact, null, 'subscribed', 'fixture', 'PRIVATE-STAFF-NOTE', 99 );

		$this->assertTrue(
			$this->links->upsert(
				array(
					'order_id'        => 9001,
					'contact_id'      => $contact,
					'billing_email'   => self::EMAIL,
					'status'          => 'completed',
					'total'           => 120,
					'net_total'       => 100,
					'currency'        => 'TWD',
					'is_counted'      => true,
					'date_created'    => '2024-01-01 00:00:00',
				)
			)
		);

		$jobs = new EmailJobRepository();
		$job  = $jobs->enqueue(
			array(
				'job_key'    => 'export-job',
				'kind'       => 'campaign',
				'campaign_id' => 44,
				'contact_id' => $contact,
				'recipient'  => self::EMAIL,
				'subject'    => 'Export subject',
				'payload'    => array(
					'template' => array( 'content' => 'SECRET-JOB-BODY' ),
				),
			)
		);
		$this->assertIsInt( $job );

		$wpdb->update(
			Schema::table( 'email_jobs' ),
			array( 'claim_token' => 'CLAIM-TOKEN-MARKER' ),
			array( 'id' => (int) $job )
		);

		$events = Schema::table( 'email_events' );
		$wpdb->insert(
			$events,
			array(
				'event_key'  => 'export-event',
				'type'       => 'click',
				'campaign_id' => 44,
				'job_id'     => (int) $job,
				'contact_id' => $contact,
				'url'        => 'https://example.test/?token=SECRET-RECOVERY-URL',
				'user_agent' => 'USER-AGENT-MARKER',
				'ip_hash'    => 'IP-HASH-MARKER',
				'meta'       => 'EVENT-META-MARKER',
				'occurred_at' => gmdate( 'Y-m-d H:i:s' ),
			)
		);
		$event_id = (int) $wpdb->insert_id;

		$carts = Schema::table( 'carts' );
		$wpdb->insert(
			$carts,
			array(
				'cart_cycle_key'   => 'export-cycle',
				'session_key'      => 'SESSION-MARKER',
				'email'            => self::EMAIL,
				'email_normalized' => self::EMAIL,
				'status'           => 'abandoned',
				'cart_snapshot'    => 'CART-SNAPSHOT-MARKER',
				'item_count'       => 2,
				'total'            => 55,
				'currency'         => 'TWD',
				'last_activity_at' => gmdate( 'Y-m-d H:i:s' ),
				'created_at'       => gmdate( 'Y-m-d H:i:s' ),
				'updated_at'       => gmdate( 'Y-m-d H:i:s' ),
			)
		);
		$cart_id = (int) $wpdb->insert_id;

		$form_id  = wp_insert_post(
			array(
				'post_type'   => FormRepository::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'Export form',
			)
		);
		$entry_id = wp_insert_post(
			array(
				'post_type'   => FormRepository::ENTRY_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'Entry',
				'post_parent' => (int) $form_id,
			)
		);
		update_post_meta( (int) $entry_id, EntryRepository::META_EMAIL, self::EMAIL );
		update_post_meta( (int) $entry_id, EntryRepository::META_CONTACT, (string) $contact );
		update_post_meta(
			(int) $entry_id,
			EntryRepository::META_DATA,
			wp_slash(
				wp_json_encode(
					array(
						'email'   => self::EMAIL,
						'fields'  => array(
							array( 'key' => 'city', 'label' => 'City', 'type' => 'text', 'value' => 'Taipei' ),
						),
					)
				)
			)
		);

		$ses = Schema::table( 'ses_events' );
		$wpdb->insert(
			$ses,
			array(
				'sns_message_id'  => 'sns-export',
				'event_key'       => 'ses-export',
				'event_type'      => 'Delivery',
				'email_normalized' => self::EMAIL,
				'payload'         => 'RAW-SNS-BODY',
				'processed'       => 1,
				'received_at'     => gmdate( 'Y-m-d H:i:s' ),
			)
		);
		$ses_id = (int) $wpdb->insert_id;

		$suppressions = new SuppressionRepository();
		$this->assertTrue( $suppressions->add( self::EMAIL, SuppressionRepository::REASON_UNSUBSCRIBED, 'fixture', $contact ) );
		$suppression_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM ' . Schema::table( 'suppressions' ) . ' WHERE email_normalized = %s',
				self::EMAIL
			)
		);

		// A job assigned to another contact must never leak, even with the same recipient.
		$other = $this->make_contact( array( 'email' => 'other-contact@example.test' ) );
		$leak  = $jobs->enqueue(
			array(
				'job_key'   => 'leak-job',
				'kind'      => 'custom',
				'contact_id' => $other,
				'recipient' => self::EMAIL,
				'subject'   => 'Other contact job',
			)
		);
		$this->assertIsInt( $leak );

		$wpdb->insert(
			$events,
			array(
				'event_key'  => 'leak-event',
				'type'       => 'open',
				'campaign_id' => 44,
				'job_id'     => (int) $leak,
				'contact_id' => $other,
				'occurred_at' => gmdate( 'Y-m-d H:i:s' ),
			)
		);
		$leak_event = (int) $wpdb->insert_id;

		$result = $this->export();
		$ids    = $this->item_ids( $result );

		$this->assertTrue( $result['done'] );
		$this->assertContains( 'profile:' . $contact, $ids );
		$this->assertContains( 'field:' . $field_id, $ids );
		$this->assertContains( 'membership:' . $link_id, $ids );
		$this->assertContains( 'status:' . $status_id, $ids );
		$this->assertContains( 'order:9001', $ids );
		$this->assertContains( 'job:' . (int) $job, $ids );
		$this->assertContains( 'event:' . $event_id, $ids );
		$this->assertContains( 'cart:' . $cart_id, $ids );
		$this->assertContains( 'form-entry:' . (int) $entry_id, $ids );
		$this->assertContains( 'ses:' . $ses_id, $ids );
		$this->assertContains( 'suppression:' . $suppression_id, $ids );
		$this->assertNotContains( 'job:' . (int) $leak, $ids );
		$this->assertNotContains( 'event:' . $leak_event, $ids );

		$json = (string) wp_json_encode( $result );

		foreach ( array(
			'PRIVATE-STAFF-NOTE',
			'SECRET-JOB-BODY',
			'CLAIM-TOKEN-MARKER',
			'SECRET-RECOVERY-URL',
			'USER-AGENT-MARKER',
			'IP-HASH-MARKER',
			'EVENT-META-MARKER',
			'SESSION-MARKER',
			'CART-SNAPSHOT-MARKER',
			'RAW-SNS-BODY',
		) as $secret ) {
			$this->assertStringNotContainsString( $secret, $json );
		}

		$this->assertStringContainsString( 'Fixture Co', $json );
		$this->assertStringContainsString( 'Taipei', $json );
	}

	public function test_export_paginates_each_bounded_store(): void {
		global $wpdb;

		$contact = $this->make_contact( array( 'email' => self::EMAIL ) );
		$values  = Schema::table( 'contact_field_values' );

		for ( $index = 1; $index <= 101; $index++ ) {
			$wpdb->insert(
				$values,
				array(
					'contact_id' => $contact,
					'field_key'  => sprintf( 'field_%03d', $index ),
					'value'      => wp_json_encode( 'value-' . $index ),
					'updated_at' => gmdate( 'Y-m-d H:i:s' ),
				)
			);
		}

		$first = $this->export( 1 );

		$this->assertFalse( $first['done'] );
		$this->assertCount( 100, $this->field_items( $first ) );

		$second = $this->export( 2 );

		$this->assertTrue( $second['done'] );
		$this->assertCount( 1, $this->field_items( $second ) );
	}

	public function test_orders_require_exact_contact_ownership(): void {
		$subject = $this->make_contact( array( 'email' => self::EMAIL ) );
		$owner   = $this->make_contact( array( 'email' => 'order-owner@example.test' ) );

		$this->assertTrue(
			$this->links->upsert(
				array(
					'order_id'     => 9101,
					'contact_id'   => $subject,
					'billing_email' => self::EMAIL,
					'status'       => 'completed',
					'currency'     => 'TWD',
					'is_counted'   => true,
				)
			)
		);

		// Another contact owns this order even though billing email matches.
		$this->assertTrue(
			$this->links->upsert(
				array(
					'order_id'     => 9102,
					'contact_id'   => $owner,
					'billing_email' => self::EMAIL,
					'status'       => 'completed',
					'currency'     => 'TWD',
					'is_counted'   => true,
				)
			)
		);

		$ids = $this->item_ids( $this->export() );

		$this->assertContains( 'order:9101', $ids );
		$this->assertNotContains( 'order:9102', $ids );

		// No current contact: an address match never licenses order rows.
		$ghost_email = 'ghost-orders@example.test';

		$this->assertTrue(
			$this->links->upsert(
				array(
					'order_id'     => 9103,
					'contact_id'   => $owner,
					'billing_email' => $ghost_email,
					'status'       => 'completed',
					'currency'     => 'TWD',
					'is_counted'   => true,
				)
			)
		);

		$ghost_ids = $this->item_ids( $this->export( $ghost_email ) );

		$this->assertNotContains( 'order:9103', $ghost_ids );
		$this->assertSame(
			array(),
			array_values(
				array_filter(
					$ghost_ids,
					static fn( string $id ): bool => str_starts_with( $id, 'order:' )
				)
			)
		);
	}

	public function test_dangling_email_history_requires_no_current_contact(): void {
		global $wpdb;

		$email  = 'dangling@example.test';
		$gone   = $this->make_contact( array( 'email' => $email ) );
		$jobs   = new EmailJobRepository();

		$job = $jobs->enqueue(
			array(
				'job_key'    => 'dangling-job',
				'kind'       => 'custom',
				'contact_id' => $gone,
				'recipient'  => $email,
				'subject'    => 'Dangling job',
			)
		);
		$this->assertIsInt( $job );

		$wpdb->insert(
			Schema::table( 'email_events' ),
			array(
				'event_key'  => 'dangling-event',
				'type'       => 'open',
				'job_id'     => (int) $job,
				'contact_id' => $gone,
				'occurred_at' => gmdate( 'Y-m-d H:i:s' ),
			)
		);
		$event = (int) $wpdb->insert_id;

		$this->assertTrue( $this->contacts->delete( $gone ) );

		$dangling_ids = $this->item_ids( $this->export( $email ) );

		$this->assertContains( 'job:' . (int) $job, $dangling_ids );
		$this->assertContains( 'event:' . $event, $dangling_ids );

		// A replacement contact must not inherit the dangling history by email.
		$replacement = $this->make_contact( array( 'email' => $email ) );
		$owned_ids    = $this->item_ids( $this->export( $email ) );

		$this->assertContains( 'profile:' . $replacement, $owned_ids );
		$this->assertNotContains( 'job:' . (int) $job, $owned_ids );
		$this->assertNotContains( 'event:' . $event, $owned_ids );

		// A truly ownerless job still exports with its own engagement event.
		$ownerless_email = 'ownerless@example.test';
		$ownerless       = $jobs->enqueue(
			array(
				'job_key'   => 'ownerless-job',
				'kind'      => 'custom',
				'recipient' => $ownerless_email,
				'subject'   => 'Ownerless job',
			)
		);
		$this->assertIsInt( $ownerless );

		$wpdb->insert(
			Schema::table( 'email_events' ),
			array(
				'event_key'  => 'ownerless-event',
				'type'       => 'click',
				'job_id'     => (int) $ownerless,
				'contact_id' => 0,
				'occurred_at' => gmdate( 'Y-m-d H:i:s' ),
			)
		);
		$ownerless_event = (int) $wpdb->insert_id;

		$ownerless_ids = $this->item_ids( $this->export( $ownerless_email ) );

		$this->assertContains( 'job:' . (int) $ownerless, $ownerless_ids );
		$this->assertContains( 'event:' . $ownerless_event, $ownerless_ids );
	}

	/**
	 * Field items in one exporter response.
	 *
	 * @param array<string, mixed> $result Exporter response.
	 * @return array<int, array<string, mixed>>
	 */
	private function field_items( array $result ): array {
		return array_values(
			array_filter(
				(array) $result['data'],
				static fn( array $item ): bool => 'nowcrm-custom-fields' === $item['group_id']
			)
		);
	}

	public function test_form_entries_follow_ownership_not_stored_email(): void {
		$form = (int) wp_insert_post(
			array(
				'post_type'   => FormRepository::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'Ownership form',
			)
		);

		// The address moved to A, but the entry still belongs to B.
		$owner = $this->make_contact( array( 'email' => 'entry-owner@example.test' ) );
		$subject = $this->make_contact( array( 'email' => self::EMAIL ) );

		$other_owned = $this->make_entry( $form, self::EMAIL, $owner, 'OWNER-B-SECRET' );
		$guest       = $this->make_entry( $form, self::EMAIL, null, 'GUEST-VALUE' );
		$guest_zero  = $this->make_entry( $form, self::EMAIL, 0, 'GUEST-ZERO-VALUE' );
		$own_entry   = $this->make_entry( $form, 'old-subject@example.test', $subject, 'OWNED-VALUE' );

		$result = $this->export();
		$ids    = $this->item_ids( $result );
		$json   = (string) wp_json_encode( $result );

		$this->assertContains( 'form-entry:' . $guest, $ids );
		$this->assertContains( 'form-entry:' . $guest_zero, $ids );
		$this->assertContains( 'form-entry:' . $own_entry, $ids );
		$this->assertNotContains( 'form-entry:' . $other_owned, $ids );

		$this->assertStringContainsString( 'GUEST-VALUE', $json );
		$this->assertStringContainsString( 'GUEST-ZERO-VALUE', $json );
		$this->assertStringContainsString( 'OWNED-VALUE', $json );
		$this->assertStringNotContainsString( 'OWNER-B-SECRET', $json );

		// No contact now holds the address: owned entries still stay private.
		$ghost_email = 'ghost-request@example.test';
		$ghost_guest = $this->make_entry( $form, $ghost_email, null, 'GHOST-GUEST-VALUE' );
		$ghost_owned = $this->make_entry( $form, $ghost_email, $owner, 'GHOST-OWNER-SECRET' );

		$ghost = $this->export( $ghost_email );
		$ghost_ids = $this->item_ids( $ghost );
		$ghost_json = (string) wp_json_encode( $ghost );

		$this->assertContains( 'form-entry:' . $ghost_guest, $ghost_ids );
		$this->assertNotContains( 'form-entry:' . $ghost_owned, $ghost_ids );
		$this->assertStringContainsString( 'GHOST-GUEST-VALUE', $ghost_json );
		$this->assertStringNotContainsString( 'GHOST-OWNER-SECRET', $ghost_json );
	}

	/**
	 * Create a form entry with a submitted city field.
	 *
	 * @param int      $form_id    Form post ID.
	 * @param string   $email      Stored entry email.
	 * @param int|null $contact_id Owner contact, null for an absent owner.
	 * @param string   $city       Submitted city value.
	 */
	private function make_entry( int $form_id, string $email, ?int $contact_id, string $city ): int {
		$entry = (int) wp_insert_post(
			array(
				'post_type'   => FormRepository::ENTRY_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'Entry',
				'post_parent' => $form_id,
			)
		);

		update_post_meta( $entry, EntryRepository::META_EMAIL, $email );

		if ( null !== $contact_id ) {
			update_post_meta( $entry, EntryRepository::META_CONTACT, (string) $contact_id );
		}

		update_post_meta(
			$entry,
			EntryRepository::META_DATA,
			wp_slash(
				wp_json_encode(
					array(
						'email'  => $email,
						'fields' => array(
							array( 'key' => 'city', 'label' => 'City', 'type' => 'text', 'value' => $city ),
						),
					)
				)
			)
		);

		return $entry;
	}
}
