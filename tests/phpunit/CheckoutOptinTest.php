<?php
/**
 * Checkout newsletter consent checkbox and new-contact status.
 *
 * @package NowCRM
 */

use Automattic\WooCommerce\Blocks\Domain\Services\CheckoutFields;
use Automattic\WooCommerce\Blocks\Package;
use Automattic\WooCommerce\StoreApi\SchemaController;
use Automattic\WooCommerce\StoreApi\Schemas\V1\CheckoutSchema;
use Automattic\WooCommerce\StoreApi\StoreApi;
use NowCRM\Contacts\Status;
use NowCRM\Support\Settings;
use NowCRM\Woo\CheckoutOptin;
use NowCRM\Woo\OrderSync;

/**
 * @covers \NowCRM\Woo\CheckoutOptin
 * @covers \NowCRM\Woo\OrderSync
 */
final class CheckoutOptinTest extends NowCrmTestCase {

	public function set_up(): void {
		parent::set_up();

		$this->reset_blocks_field();
	}

	public function tear_down(): void {
		$this->reset_blocks_field();

		$_POST = array();

		parent::tear_down();
	}

	public function test_checkbox_is_off_by_default(): void {
		$this->assertFalse( Settings::bool( 'show_checkout_subscription_checkbox' ) );

		$this->assertSame( '', trim( $this->render_classic_checkout() ) );
	}

	public function test_classic_checkbox_starts_unchecked_even_when_auto_subscribe_is_on(): void {
		$this->set_settings(
			array(
				'show_checkout_subscription_checkbox' => true,
				'auto_subscribe_new_woo_contacts'     => true,
			)
		);

		$html = $this->render_classic_checkout();

		$this->assertStringContainsString( 'name="' . CheckoutOptin::FIELD_NAME . '"', $html );
		$this->assertStringContainsString( 'type="checkbox"', $html );
		$this->assertStringContainsString( '訂閱電子報', $html );
		$this->assertStringNotContainsString( 'checked', $html, 'Legacy auto-subscribe must not pre-check the consent box.' );
	}

	public function test_classic_checkbox_is_unchecked_when_auto_subscribe_is_off(): void {
		$this->set_settings(
			array(
				'show_checkout_subscription_checkbox' => true,
				'auto_subscribe_new_woo_contacts'     => false,
			)
		);

		$this->assertStringNotContainsString( 'checked', $this->render_classic_checkout() );
	}

	public function test_classic_checkbox_is_outside_the_refreshed_order_review_fragment(): void {
		$this->set_settings( array( 'show_checkout_subscription_checkbox' => true ) );

		$this->assertStringContainsString( CheckoutOptin::FIELD_NAME, $this->render_classic_checkout() );

		ob_start();
		do_action( 'woocommerce_review_order_before_submit' );
		$fragment = (string) ob_get_clean();

		$this->assertStringNotContainsString(
			CheckoutOptin::FIELD_NAME,
			$fragment,
			'WooCommerce replaces this fragment over AJAX, so the customer choice must live outside it.'
		);
	}

	public function test_failed_submission_preserves_an_unchecked_choice(): void {
		$this->set_settings(
			array(
				'show_checkout_subscription_checkbox' => true,
				'auto_subscribe_new_woo_contacts'     => true,
			)
		);

		$_POST = array( 'woocommerce-process-checkout-nonce' => 'fixture' );

		$this->assertStringNotContainsString( 'checked', $this->render_classic_checkout() );
	}

	public function test_failed_submission_preserves_a_checked_choice(): void {
		$this->set_settings(
			array(
				'show_checkout_subscription_checkbox' => true,
				'auto_subscribe_new_woo_contacts'     => false,
			)
		);

		$_POST = array(
			'woocommerce-process-checkout-nonce' => 'fixture',
			CheckoutOptin::FIELD_NAME            => '1',
		);

		$this->assertStringContainsString( 'checked', $this->render_classic_checkout() );
	}

	public function test_checked_classic_order_subscribes_a_new_contact_even_when_auto_subscribe_is_off(): void {
		$this->set_settings(
			array(
				'woo_sync_enabled'                    => true,
				'show_checkout_subscription_checkbox' => true,
				'auto_subscribe_new_woo_contacts'     => false,
			)
		);

		$order = $this->submit_classic_order( 'classic-checked@example.test', true );
		$link  = $this->links->find_by_order( $order->get_id() );

		$this->assertSame( Status::SUBSCRIBED, $this->contacts->find_by_email( 'classic-checked@example.test' )['status'] );
		$this->assertSame( 1, $link['marketing_optin'] );
	}

	public function test_unchecked_classic_order_creates_a_transactional_contact(): void {
		$this->set_settings(
			array(
				'woo_sync_enabled'                    => true,
				'show_checkout_subscription_checkbox' => true,
				'auto_subscribe_new_woo_contacts'     => true,
			)
		);

		$order = $this->submit_classic_order( 'classic-declined@example.test', false );
		$link  = $this->links->find_by_order( $order->get_id() );

		$this->assertSame( Status::TRANSACTIONAL, $this->contacts->find_by_email( 'classic-declined@example.test' )['status'] );
		$this->assertSame( 0, $link['marketing_optin'] );
	}

	public function test_invalid_array_value_is_not_coerced_and_records_a_decline(): void {
		$this->set_settings( array( 'show_checkout_subscription_checkbox' => true ) );

		$order = new WC_Order();
		$order->set_created_via( 'checkout' );

		$_POST = array(
			'woocommerce-process-checkout-nonce' => 'fixture',
			CheckoutOptin::FIELD_NAME            => array( '1' ),
		);

		( new CheckoutOptin() )->save_classic_checkout( $order );

		$this->assertSame( '0', $order->get_meta( CheckoutOptin::META_KEY, true ) );
		$this->assertFalse( CheckoutOptin::normalize_order_optin( $order ) );
	}

	public function test_classic_choice_is_ignored_without_a_checkout_submission(): void {
		$this->set_settings( array( 'show_checkout_subscription_checkbox' => true ) );

		$order = new WC_Order();
		$order->set_created_via( 'checkout' );

		$_POST = array( CheckoutOptin::FIELD_NAME => '1' );

		( new CheckoutOptin() )->save_classic_checkout( $order );

		$this->assertFalse( $order->meta_exists( CheckoutOptin::META_KEY ) );
	}

	public function test_classic_choice_is_ignored_for_a_non_checkout_order(): void {
		$this->set_settings( array( 'show_checkout_subscription_checkbox' => true ) );

		$order = new WC_Order();
		$order->set_created_via( 'admin' );

		$_POST = array(
			'woocommerce-process-checkout-nonce' => 'fixture',
			CheckoutOptin::FIELD_NAME            => '1',
		);

		( new CheckoutOptin() )->save_classic_checkout( $order );

		$this->assertFalse( $order->meta_exists( CheckoutOptin::META_KEY ) );
	}

	public function test_classic_choice_does_not_overwrite_blocks_consent(): void {
		$this->set_settings( array( 'show_checkout_subscription_checkbox' => true ) );

		$order = new WC_Order();
		$order->set_created_via( 'checkout' );
		$order->update_meta_data( CheckoutOptin::BLOCKS_META_KEY, '1' );

		$_POST = array( 'woocommerce-process-checkout-nonce' => 'fixture' );

		( new CheckoutOptin() )->save_classic_checkout( $order );

		$this->assertFalse( $order->meta_exists( CheckoutOptin::META_KEY ) );
		$this->assertTrue( CheckoutOptin::normalize_order_optin( $order ) );
	}

	public function test_boolean_false_meta_is_a_decline(): void {
		$order = new WC_Order();
		$order->update_meta_data( CheckoutOptin::BLOCKS_META_KEY, false );

		$this->assertFalse( CheckoutOptin::normalize_order_optin( $order ) );
	}

	public function test_blocks_field_is_registered_through_the_woocommerce_service(): void {
		$this->set_settings(
			array(
				'show_checkout_subscription_checkbox' => true,
				'auto_subscribe_new_woo_contacts'     => true,
			)
		);

		$fields = $this->register_blocks_field();
		$field  = $fields->get_additional_fields()[ CheckoutOptin::FIELD_ID ] ?? null;

		$this->assertNotNull( $field );
		$this->assertSame( '訂閱電子報', $field['label'] );
		$this->assertSame( 'checkbox', $field['type'] );
		$this->assertSame( 'order', $field['location'] );
		$this->assertFalse( $field['required'] );
		$this->assertStringContainsString( '(optional)', $field['optionalLabel'] );
	}

	public function test_blocks_field_is_not_registered_when_the_checkbox_is_hidden(): void {
		$this->set_settings( array( 'show_checkout_subscription_checkbox' => false ) );

		$fields = $this->checkout_fields();
		$this->assertNotNull( $fields );

		( new CheckoutOptin() )->register_blocks_field();

		$this->assertArrayNotHasKey( CheckoutOptin::FIELD_ID, $fields->get_additional_fields() );
	}

	public function test_blocks_checkbox_defaults_to_unchecked_on_an_in_progress_store_api_order(): void {
		$this->set_settings(
			array(
				'show_checkout_subscription_checkbox' => true,
				'auto_subscribe_new_woo_contacts'     => true,
			)
		);

		$fields = $this->register_blocks_field();
		$order  = $this->make_draft_order( 'blocks-default@example.test' );

		// CheckoutFieldsStorage returns '' for an unset checkbox; it is not a
		// stored false. CheckboxFieldType::from_storage() maps null/'' to null.
		$this->assertSame( '', $fields->get_field_from_object( CheckoutOptin::FIELD_ID, $order, 'other' ) );
		$this->assertNull( CheckoutOptin::normalize_order_optin( $order ), 'No choice is not a stored decline.' );
	}

	public function test_blocks_persisted_true_round_trips_independently_of_the_default(): void {
		$this->set_settings(
			array(
				'show_checkout_subscription_checkbox' => true,
				'auto_subscribe_new_woo_contacts'     => true,
			)
		);

		$fields = $this->register_blocks_field();
		$order  = $this->make_draft_order( 'blocks-stored-true@example.test' );

		$fields->persist_field_for_order( CheckoutOptin::FIELD_ID, true, $order, 'other' );
		$order->save();

		$this->assertTrue(
			$fields->get_field_from_object( CheckoutOptin::FIELD_ID, wc_get_order( $order->get_id() ), 'other' )
		);
	}

	public function test_blocks_default_is_not_added_to_a_historical_order(): void {
		$this->set_settings(
			array(
				'show_checkout_subscription_checkbox' => true,
				'auto_subscribe_new_woo_contacts'     => true,
			)
		);

		$fields = $this->register_blocks_field();

		$order = new WC_Order();
		$order->set_billing_email( 'historical@example.test' );
		$order->set_created_via( 'checkout' );
		$order->set_status( 'completed' );
		$order->save();

		$this->assertSame( '', $fields->get_field_from_object( CheckoutOptin::FIELD_ID, $order, 'other' ) );
	}

	public function test_blocks_stored_false_stays_false(): void {
		$this->set_settings(
			array(
				'show_checkout_subscription_checkbox' => true,
				'auto_subscribe_new_woo_contacts'     => true,
			)
		);

		$fields = $this->register_blocks_field();
		$order  = $this->make_draft_order( 'blocks-stored-false@example.test' );

		$fields->persist_field_for_order( CheckoutOptin::FIELD_ID, false, $order, 'other' );
		$order->save();

		$stored = wc_get_order( $order->get_id() );

		$this->assertSame( '0', $stored->get_meta( CheckoutOptin::BLOCKS_META_KEY, true ) );
		$this->assertFalse( $fields->get_field_from_object( CheckoutOptin::FIELD_ID, $stored, 'other' ) );
	}

	public function test_blocks_schema_exposes_an_optional_boolean_field(): void {
		$this->set_settings( array( 'show_checkout_subscription_checkbox' => true ) );

		$this->register_blocks_field();

		$schema = StoreApi::container()->get( SchemaController::class )->get( CheckoutSchema::IDENTIFIER );
		$field  = $schema->get_properties()['additional_fields']['properties'][ CheckoutOptin::FIELD_ID ] ?? null;

		$this->assertNotNull( $field );
		$this->assertSame( 'boolean', $field['type'] );
		$this->assertFalse( $field['required'] );
	}

	/**
	 * @dataProvider blocks_checkout_cases
	 *
	 * @param bool|null $consent        Persisted checkbox value. Null means the native checkbox is missing.
	 * @param string    $expected_status Expected contact status.
	 * @param int|null  $expected_link   Expected order-link consent.
	 */
	public function test_blocks_draft_order_uses_persisted_field_metadata( ?bool $consent, string $expected_status, ?int $expected_link ): void {
		$this->set_settings(
			array(
				'woo_sync_enabled'                    => true,
				'show_checkout_subscription_checkbox' => true,
				'auto_subscribe_new_woo_contacts'     => true,
			)
		);

		$fields = $this->register_blocks_field();
		$email  = 'blocks-' . wp_generate_password( 8, false ) . '@example.test';
		$order  = $this->make_draft_order( $email );

		$this->assertNull( $this->contacts->find_by_email( $email ), 'A draft order must not create a contact.' );

		if ( null !== $consent ) {
			$fields->persist_field_for_order( CheckoutOptin::FIELD_ID, $consent, $order, 'other' );
			$order->save();
		}

		$order->update_status( 'pending' );

		$link = $this->links->find_by_order( $order->get_id() );

		$this->assertSame( $expected_status, $this->contacts->find_by_email( $email )['status'] );
		$this->assertSame( $expected_link, $link['marketing_optin'] );

		if ( null !== $consent ) {
			$this->assertSame(
				$consent ? '1' : '0',
				wc_get_order( $order->get_id() )->get_meta( CheckoutOptin::BLOCKS_META_KEY, true )
			);
		}
	}

	/**
	 * @return array<string, array{0:bool|null,1:string,2:int|null}>
	 */
	public function blocks_checkout_cases(): array {
		return array(
			'checked'   => array( true, Status::SUBSCRIBED, 1 ),
			'unchecked' => array( false, Status::TRANSACTIONAL, 0 ),
			'missing'   => array( null, Status::TRANSACTIONAL, null ),
		);
	}

	/**
	 * @dataProvider new_contact_status_cases
	 *
	 * @param bool        $auto_subscribe    Auto-subscribe setting.
	 * @param bool        $checkbox_enabled  Checkout checkbox setting.
	 * @param string      $created_via       WooCommerce order origin.
	 * @param string|null $optin             Saved classic consent.
	 * @param string      $expected_status   Expected contact status.
	 * @param int|null    $expected_link     Expected order-link consent.
	 */
	public function test_new_contact_status_follows_saved_consent(
		bool $auto_subscribe,
		bool $checkbox_enabled,
		string $created_via,
		?string $optin,
		string $expected_status,
		?int $expected_link
	): void {
		$this->set_settings(
			array(
				'woo_sync_enabled'                    => true,
				'show_checkout_subscription_checkbox' => $checkbox_enabled,
				'auto_subscribe_new_woo_contacts'     => $auto_subscribe,
			)
		);

		$email = 'matrix-' . wp_generate_password( 8, false ) . '@example.test';
		$order = new WC_Order();
		$order->set_billing_email( $email );
		$order->set_total( 100 );
		$order->set_created_via( $created_via );
		$order->set_status( 'pending' );

		if ( null !== $optin ) {
			$order->update_meta_data( CheckoutOptin::META_KEY, $optin );
		}

		$order->save();

		$link = $this->links->find_by_order( $order->get_id() );

		$this->assertSame( $expected_status, $this->contacts->find_by_email( $email )['status'] );
		$this->assertSame( $expected_link, $link['marketing_optin'] );
	}

	/**
	 * @return array<string, array{0:bool,1:bool,2:string,3:string|null,4:string,5:int|null}>
	 */
	public function new_contact_status_cases(): array {
		return array(
			'classic checked'                    => array( true, true, 'checkout', '1', Status::SUBSCRIBED, 1 ),
			'classic unchecked'                  => array( true, true, 'checkout', '0', Status::TRANSACTIONAL, 0 ),
			'classic missing while shown'        => array( true, true, 'checkout', null, Status::TRANSACTIONAL, null ),
			'classic hidden without consent'     => array( true, false, 'checkout', null, Status::SUBSCRIBED, null ),
			'classic hidden after decline'       => array( true, false, 'checkout', '0', Status::TRANSACTIONAL, 0 ),
			'auto subscribe off with consent'    => array( false, true, 'checkout', '1', Status::SUBSCRIBED, 1 ),
			'auto subscribe off without consent' => array( false, true, 'checkout', null, Status::TRANSACTIONAL, null ),
			'auto subscribe off after decline'   => array( false, true, 'checkout', '0', Status::TRANSACTIONAL, 0 ),
			'store-api missing consent'          => array( true, true, 'store-api', null, Status::TRANSACTIONAL, null ),
			'admin order without consent'        => array( true, true, 'admin', null, Status::SUBSCRIBED, null ),
			'admin order after decline'          => array( true, true, 'admin', '0', Status::TRANSACTIONAL, 0 ),
		);
	}

	public function test_existing_contact_status_is_never_changed_by_checkout_consent(): void {
		$this->set_settings(
			array(
				'woo_sync_enabled'                    => true,
				'show_checkout_subscription_checkbox' => true,
				'auto_subscribe_new_woo_contacts'     => true,
			)
		);

		$contact_id = $this->make_contact(
			array(
				'email'  => 'unsubscribed-optin@example.test',
				'status' => Status::UNSUBSCRIBED,
			)
		);

		$order = new WC_Order();
		$order->set_billing_email( 'unsubscribed-optin@example.test' );
		$order->set_total( 100 );
		$order->set_created_via( 'checkout' );
		$order->set_status( 'pending' );
		$order->update_meta_data( CheckoutOptin::META_KEY, '1' );
		$order->save();

		$link = $this->links->find_by_order( $order->get_id() );

		$this->assertSame( Status::UNSUBSCRIBED, $this->contacts->get( $contact_id )['status'] );
		$this->assertSame( $contact_id, $link['contact_id'] );
		$this->assertSame( 1, $link['marketing_optin'] );
	}

	/**
	 * Run a native classic checkout create-order hook, then save.
	 */
	private function submit_classic_order( string $email, bool $checked ): WC_Order {
		$_POST = array( 'woocommerce-process-checkout-nonce' => 'fixture' );

		if ( $checked ) {
			$_POST[ CheckoutOptin::FIELD_NAME ] = '1';
		}

		$order = new WC_Order();
		$order->set_billing_email( $email );
		$order->set_total( 100 );
		$order->set_created_via( 'checkout' );
		$order->set_status( 'pending' );

		do_action( 'woocommerce_checkout_create_order', $order, array() );
		$order->save();

		$_POST = array();

		return wc_get_order( $order->get_id() );
	}

	/**
	 * Create an in-progress Store API draft order.
	 */
	private function make_draft_order( string $email ): WC_Order {
		$order = new WC_Order();
		$order->set_billing_email( $email );
		$order->set_total( 100 );
		$order->set_created_via( 'store-api' );
		$order->set_status( 'checkout-draft' );
		$order->save();

		return wc_get_order( $order->get_id() );
	}

	private function render_classic_checkout(): string {
		ob_start();
		do_action( 'woocommerce_checkout_before_order_review' );

		return (string) ob_get_clean();
	}

	private function register_blocks_field(): CheckoutFields {
		$fields = $this->checkout_fields();
		$this->assertInstanceOf( CheckoutFields::class, $fields );

		( new CheckoutOptin() )->register_blocks_field();

		return $fields;
	}

	private function reset_blocks_field(): void {
		$fields = $this->checkout_fields();

		if ( null !== $fields ) {
			$fields->deregister_checkout_field( CheckoutOptin::FIELD_ID );
		}
	}

	private function checkout_fields(): ?CheckoutFields {
		if ( ! class_exists( Package::class ) || ! class_exists( CheckoutFields::class ) ) {
			return null;
		}

		try {
			return Package::container()->get( CheckoutFields::class );
		} catch ( Throwable $error ) {
			return null;
		}
	}
}
