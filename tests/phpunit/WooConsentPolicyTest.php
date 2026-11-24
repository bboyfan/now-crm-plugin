<?php
/**
 * Woo checkout consent and double opt-in handoff.
 *
 * @package NowCRM
 */

use NowCRM\Consent\Config;
use NowCRM\Contacts\Status;
use NowCRM\Woo\CheckoutOptin;

/**
 * @covers \NowCRM\Woo\CheckoutOptin
 * @covers \NowCRM\Woo\OrderSync
 * @covers \NowCRM\Consent\EntryPoints
 */
final class WooConsentPolicyTest extends NowCrmTestCase {

	public function set_up(): void {
		parent::set_up();

		Config::reset();
		delete_option( Config::LISTS_OPTION );
		delete_option( Config::PENDING_OPTION );

		$this->set_settings(
			array(
				'woo_sync_enabled'                    => true,
				'show_checkout_subscription_checkbox' => true,
				'auto_subscribe_new_woo_contacts'     => true,
			)
		);
	}

	public function test_explicit_yes_with_required_doi_starts_transactional_and_queues_one_confirmation(): void {
		Config::update( array( 'double_optin_enabled' => true ) );

		$order = $this->make_consented_order( 'woo-doi-yes@example.test', true );
		$link  = $this->links->find_by_order( $order->get_id() );
		$saved = $this->contacts->find_by_email( 'woo-doi-yes@example.test' );

		$this->assertSame( Status::TRANSACTIONAL, $saved['status'] );
		$this->assertSame( 1, $link['marketing_optin'] );
		$this->assertSame( 1, $this->table_count( 'email_jobs', "job_key LIKE 'nowcrm-doi:" . (int) $saved['id'] . ":%'" ) );
		$this->assertNotNull( Config::pending_get( (int) $saved['id'] ) );
	}

	public function test_repeated_woo_hooks_do_not_duplicate_confirmation(): void {
		Config::update( array( 'double_optin_enabled' => true ) );

		$order  = $this->make_consented_order( 'woo-doi-repeat@example.test', true );
		$saved  = $this->contacts->find_by_email( 'woo-doi-repeat@example.test' );

		do_action( 'woocommerce_update_order', $order->get_id(), $order );
		do_action( 'woocommerce_order_status_changed', $order->get_id(), 'pending', 'processing', $order );

		$this->assertSame( 1, $this->table_count( 'email_jobs', "job_key LIKE 'nowcrm-doi:" . (int) $saved['id'] . ":%'" ) );
		$this->assertSame( Status::TRANSACTIONAL, $this->contacts->get( (int) $saved['id'] )['status'] );
	}

	public function test_existing_contact_is_never_downgraded_or_reinvited(): void {
		Config::update( array( 'double_optin_enabled' => true ) );

		$contact_id = $this->make_contact(
			array(
				'email'  => 'woo-doi-existing@example.test',
				'status' => Status::SUBSCRIBED,
			)
		);

		$this->make_consented_order( 'woo-doi-existing@example.test', true );

		$this->assertSame( Status::SUBSCRIBED, $this->contacts->get( $contact_id )['status'] );
		$this->assertSame( 0, $this->table_count( 'email_jobs', "job_key LIKE 'nowcrm-doi:{$contact_id}:%'" ) );
	}

	public function test_absent_choice_with_required_doi_stays_transactional_even_with_auto_subscribe(): void {
		Config::update( array( 'double_optin_enabled' => true ) );

		$this->make_consented_order( 'woo-doi-absent@example.test', null );

		$saved = $this->contacts->find_by_email( 'woo-doi-absent@example.test' );

		$this->assertSame( Status::TRANSACTIONAL, $saved['status'] );
		$this->assertSame( 0, $this->table_count( 'email_jobs', "job_key LIKE 'nowcrm-doi:" . (int) $saved['id'] . ":%'" ) );
	}

	public function test_explicit_yes_without_doi_requirement_still_subscribes_directly(): void {
		$this->make_consented_order( 'woo-direct-yes@example.test', true );

		$saved = $this->contacts->find_by_email( 'woo-direct-yes@example.test' );

		$this->assertSame( Status::SUBSCRIBED, $saved['status'] );
		$this->assertSame( 0, $this->table_count( 'email_jobs', "job_key LIKE 'nowcrm-doi:" . (int) $saved['id'] . ":%'" ) );
	}

	/**
	 * @param bool|null $consent True yes, false decline, null no saved choice.
	 */
	private function make_consented_order( string $email, ?bool $consent ): WC_Order {
		$order = new WC_Order();
		$order->set_billing_email( $email );
		$order->set_total( 100 );
		$order->set_created_via( 'checkout' );
		$order->set_status( 'pending' );

		if ( null !== $consent ) {
			$order->update_meta_data( CheckoutOptin::META_KEY, $consent ? '1' : '0' );
		}

		$order->save();

		return wc_get_order( $order->get_id() );
	}
}
