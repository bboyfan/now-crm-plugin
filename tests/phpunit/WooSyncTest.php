<?php
/**
 * WooCommerce order synchronisation.
 *
 * @package NowCRM
 */

use NowCRM\Contacts\Status;
use NowCRM\Terms\TermRepository;

/**
 * @covers \NowCRM\Woo\OrderSync
 */
final class WooSyncTest extends NowCrmTestCase {

	public function set_up(): void {
		parent::set_up();

		$this->set_settings(
			array(
				'woo_sync_enabled'                => true,
				'auto_subscribe_new_woo_contacts' => true,
			)
		);
	}

	public function test_new_order_creates_a_subscribed_contact_in_the_customer_list(): void {
		$order = $this->make_order( array( 'billing_email' => 'guest-one@example.test' ) );

		$contact = $this->contacts->find_by_email( 'guest-one@example.test' );

		$this->assertNotNull( $contact );
		$this->assertSame( Status::SUBSCRIBED, $contact['status'] );
		$this->assertSame( 'woocommerce', $contact['source'] );

		$link = $this->links->find_by_order( $order->get_id() );

		$this->assertNotNull( $link );
		$this->assertSame( (int) $contact['id'], $link['contact_id'] );

		$slugs = array_column( $this->terms->for_contact( (int) $contact['id'] ), 'slug' );
		$list  = $this->terms->find_by_slug( 'list', TermRepository::slug_for( TermRepository::customer_list_name() ) );

		$this->assertNotNull( $list, 'The customer list must exist after the first synced order.' );
		$this->assertSame( TermRepository::CUSTOMER_LIST, $list['name'] );
		$this->assertContains( $list['slug'], $slugs );
	}

	public function test_upgrade_reuses_the_legacy_customer_list_without_a_duplicate(): void {
		$legacy_name = '溫釲顧客';
		$legacy      = $this->terms->create( 'list', $legacy_name );

		$this->assertIsInt( $legacy );

		$this->make_order( array( 'billing_email' => 'upgrade@example.test' ) );

		$contact = $this->contacts->find_by_email( 'upgrade@example.test' );
		$list    = $this->terms->get( (int) $legacy );

		$this->assertSame( $legacy_name, $list['name'], 'The upgrade must not rename the existing list.' );
		$this->assertSame( 1, $this->table_count( 'terms' ), 'The upgrade must not create a second customer list.' );
		$this->assertContains(
			TermRepository::slug_for( $legacy_name ),
			array_column( $this->terms->for_contact( (int) $contact['id'] ), 'slug' )
		);
	}

	public function test_repeated_hooks_do_not_duplicate_links_or_totals(): void {
		$order = $this->make_order(
			array(
				'billing_email' => 'repeat@example.test',
				'total'         => 500,
			)
		);

		$order->update_status( 'processing' );

		// Fire the same hooks the platform fires on every save/status change.
		do_action( 'woocommerce_update_order', $order->get_id(), $order );
		do_action( 'woocommerce_order_status_changed', $order->get_id(), 'processing', 'processing', $order );

		$contact = $this->contacts->find_by_email( 'repeat@example.test' );

		$this->assertSame( 1, $this->table_count( 'order_links' ) );
		$this->assertSame( 1, $this->contacts->aggregate( (int) $contact['id'] )['order_count'] );
		$this->assertSame( 500.0, $this->contacts->aggregate( (int) $contact['id'] )['total_spent'] );

		$link = $this->links->find_by_order( $order->get_id() );

		$this->assertGreaterThan( 1, $link['sync_version'] );
	}

	public function test_status_transitions_update_the_same_link(): void {
		$order = $this->make_order(
			array(
				'billing_email' => 'status-flow@example.test',
				'total'         => 800,
			)
		);

		$contact = $this->contacts->find_by_email( 'status-flow@example.test' );
		$pending = $this->links->find_by_order( $order->get_id() );

		$this->assertSame( 0, $pending['is_counted'] );
		$this->assertSame( 0, $this->contacts->aggregate( (int) $contact['id'] )['order_count'] );

		$order->update_status( 'processing' );

		$processing = $this->links->find_by_order( $order->get_id() );

		$this->assertSame( 'processing', $processing['status'] );
		$this->assertSame( 1, $processing['is_counted'] );
		$this->assertSame( 1, $this->contacts->aggregate( (int) $contact['id'] )['order_count'] );

		$order->update_status( 'completed' );

		$this->assertSame( 'completed', $this->links->find_by_order( $order->get_id() )['status'] );
		$this->assertSame( 1, $this->table_count( 'order_links' ) );
		$this->assertSame( 800.0, $this->contacts->aggregate( (int) $contact['id'] )['total_spent'] );
	}

	public function test_refund_reduces_spend_and_full_refund_removes_it(): void {
		$order = $this->make_order(
			array(
				'billing_email' => 'refund-spend@example.test',
				'total'         => 1000,
				'status'        => 'completed',
			)
		);

		$contact = $this->contacts->find_by_email( 'refund-spend@example.test' );

		$this->assertSame( 1000.0, $this->contacts->aggregate( (int) $contact['id'] )['total_spent'] );

		wc_create_refund(
			array(
				'order_id' => $order->get_id(),
				'amount'   => 400,
				'reason'   => 'fixture partial refund',
			)
		);

		$this->assertSame( 600.0, $this->contacts->aggregate( (int) $contact['id'] )['total_spent'] );

		wc_create_refund(
			array(
				'order_id' => $order->get_id(),
				'amount'   => 600,
				'reason'   => 'fixture full refund',
			)
		);

		$aggregate = $this->contacts->aggregate( (int) $contact['id'] );

		$this->assertSame( 0, $aggregate['order_count'] );
		$this->assertSame( 0.0, $aggregate['total_spent'] );
		$this->assertSame( 0, $this->links->find_by_order( $order->get_id() )['is_counted'] );
	}

	public function test_cancelled_order_stops_counting(): void {
		$order = $this->make_order(
			array(
				'billing_email' => 'cancel@example.test',
				'total'         => 300,
				'status'        => 'processing',
			)
		);

		$contact = $this->contacts->find_by_email( 'cancel@example.test' );

		$this->assertSame( 1, $this->contacts->aggregate( (int) $contact['id'] )['order_count'] );

		$order->update_status( 'cancelled' );

		$this->assertSame( 0, $this->contacts->aggregate( (int) $contact['id'] )['order_count'] );
		$this->assertSame( 1, $this->table_count( 'order_links' ), 'The link row stays for auditing.' );
	}

	public function test_guest_and_member_orders_are_both_linked(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'customer' ) );

		$member = $this->make_order(
			array(
				'billing_email' => 'member@example.test',
				'customer_id'   => $user_id,
			)
		);

		$guest = $this->make_order( array( 'billing_email' => 'guest-two@example.test' ) );

		$member_contact = $this->contacts->find_by_email( 'member@example.test' );
		$guest_contact  = $this->contacts->find_by_email( 'guest-two@example.test' );

		$this->assertSame( $user_id, $member_contact['wp_user_id'] );
		$this->assertNull( $guest_contact['wp_user_id'] );
		$this->assertNotNull( $this->links->find_by_order( $member->get_id() ) );
		$this->assertNotNull( $this->links->find_by_order( $guest->get_id() ) );
	}

	public function test_existing_user_link_is_never_overwritten(): void {
		$first_user  = self::factory()->user->create( array( 'role' => 'customer' ) );
		$second_user = self::factory()->user->create( array( 'role' => 'customer' ) );
		$contact_id  = $this->make_contact(
			array(
				'email'      => 'bound@example.test',
				'wp_user_id' => $first_user,
			)
		);

		$this->make_order(
			array(
				'billing_email' => 'bound@example.test',
				'customer_id'   => $second_user,
			)
		);

		$this->assertSame( $first_user, $this->contacts->get( $contact_id )['wp_user_id'] );
	}

	public function test_billing_email_change_rebinds_the_order(): void {
		$order = $this->make_order(
			array(
				'billing_email' => 'old-address@example.test',
				'total'         => 420,
				'status'        => 'processing',
			)
		);

		$old_contact = $this->contacts->find_by_email( 'old-address@example.test' );

		$this->assertSame( 420.0, $this->contacts->aggregate( (int) $old_contact['id'] )['total_spent'] );

		$order->set_billing_email( 'new-address@example.test' );
		$order->save();

		$new_contact = $this->contacts->find_by_email( 'new-address@example.test' );
		$link        = $this->links->find_by_order( $order->get_id() );

		$this->assertNotNull( $new_contact );
		$this->assertSame( (int) $new_contact['id'], $link['contact_id'] );
		$this->assertSame( 420.0, $this->contacts->aggregate( (int) $new_contact['id'] )['total_spent'] );
		$this->assertSame( 0, $this->contacts->aggregate( (int) $old_contact['id'] )['order_count'] );
		$this->assertSame( 1, $this->table_count( 'order_links' ) );
	}

	public function test_suppressed_contacts_are_never_resubscribed(): void {
		$contact_id = $this->make_contact(
			array(
				'email'  => 'suppressed@example.test',
				'status' => Status::UNSUBSCRIBED,
			)
		);

		$events_before = count( $this->history->for_contact( $contact_id ) );

		$this->make_order(
			array(
				'billing_email' => 'suppressed@example.test',
				'total'         => 100,
				'status'        => 'processing',
			)
		);

		$contact = $this->contacts->get( $contact_id );

		$this->assertSame( Status::UNSUBSCRIBED, $contact['status'] );
		$this->assertCount( $events_before, $this->history->for_contact( $contact_id ) );
		$this->assertSame( 1, $this->links->find_by_order( $this->last_order_id() ) ? 1 : 0 );
	}

	public function test_bounced_and_complained_contacts_are_never_resubscribed(): void {
		foreach ( array( Status::BOUNCED, Status::COMPLAINED ) as $index => $status ) {
			$email      = "suppressed-{$index}@example.test";
			$contact_id = $this->make_contact(
				array(
					'email'  => $email,
					'status' => $status,
				)
			);

			$this->make_order(
				array(
					'billing_email' => $email,
					'status'        => 'completed',
				)
			);

			$this->assertSame( $status, $this->contacts->get( $contact_id )['status'] );
		}
	}

	public function test_auto_subscribe_off_creates_transactional_contacts(): void {
		$this->set_settings( array( 'auto_subscribe_new_woo_contacts' => false ) );

		$this->make_order( array( 'billing_email' => 'transactional@example.test' ) );

		$contact = $this->contacts->find_by_email( 'transactional@example.test' );

		$this->assertNotNull( $contact );
		$this->assertSame( Status::TRANSACTIONAL, $contact['status'] );

		$list = $this->terms->find_by_slug( 'list', TermRepository::slug_for( TermRepository::customer_list_name() ) );

		$this->assertNotNull( $list, 'Customer list membership is independent of marketing consent.' );
		$this->assertContains( (int) $contact['id'], $this->terms->contact_ids( (int) $list['id'] ) );
	}

	public function test_disabled_sync_writes_nothing(): void {
		$this->set_settings( array( 'woo_sync_enabled' => false ) );

		$this->make_order(
			array(
				'billing_email' => 'disabled@example.test',
				'status'        => 'processing',
			)
		);

		$this->assertSame( 0, $this->table_count( 'contacts' ) );
		$this->assertSame( 0, $this->table_count( 'order_links' ) );
	}

	public function test_order_deletion_removes_the_link(): void {
		$order = $this->make_order( array( 'billing_email' => 'delete-me@example.test' ) );
		$id    = $order->get_id();

		$this->assertNotNull( $this->links->find_by_order( $id ) );

		$order->delete( true );

		$this->assertNull( $this->links->find_by_order( $id ) );
		$this->assertNotNull( $this->contacts->find_by_email( 'delete-me@example.test' ) );
	}

	public function test_two_orders_for_one_email_share_the_contact(): void {
		$this->make_order(
			array(
				'billing_email' => 'twice@example.test',
				'total'         => 100,
				'status'        => 'completed',
			)
		);

		$this->make_order(
			array(
				'billing_email' => 'twice@example.test',
				'total'         => 250,
				'status'        => 'processing',
			)
		);

		$contact = $this->contacts->find_by_email( 'twice@example.test' );

		$this->assertSame( 1, $this->table_count( 'contacts' ) );
		$this->assertSame( 2, $this->table_count( 'order_links' ) );
		$this->assertSame( 2, $this->contacts->aggregate( (int) $contact['id'] )['order_count'] );
		$this->assertSame( 350.0, $this->contacts->aggregate( (int) $contact['id'] )['total_spent'] );
	}

	public function test_unpaid_order_link_has_null_dates_and_no_consent(): void {
		$order = $this->make_order( array( 'billing_email' => 'null-dates@example.test' ) );

		$link = $this->links->find_by_order( $order->get_id() );

		$this->assertNotNull( $link );
		$this->assertNotNull( $link['date_created'] );
		$this->assertNull( $link['date_paid'], 'An unpaid order must not store a zero date.' );
		$this->assertNull( $link['date_completed'] );
		$this->assertNull( $link['marketing_optin'], 'Consent is unknown, not declined, without a checkout checkbox.' );
	}

	public function test_order_sync_never_sends_email(): void {
		$sent = 0;

		add_filter(
			'pre_wp_mail',
			static function () use ( &$sent ) {
				++$sent;

				return true;
			}
		);

		$this->make_order(
			array(
				'billing_email' => 'silent@example.test',
				'total'         => 100,
				'status'        => 'processing',
			)
		);

		$this->assertSame( 0, $sent, 'V0.1 must not send any mail while syncing orders.' );
	}

	/**
	 * ID of the most recently created order in the test transaction.
	 */
	private function last_order_id(): int {
		$orders = wc_get_orders(
			array(
				'limit'   => 1,
				'orderby' => 'date',
				'order'   => 'DESC',
				'return'  => 'ids',
			)
		);

		return (int) ( $orders[0] ?? 0 );
	}
}
