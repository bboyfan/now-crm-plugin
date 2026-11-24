<?php
/**
 * Shared helpers for the NOW CRM test suite.
 *
 * @package NowCRM
 */

use NowCRM\Contacts\ContactRepository;
use NowCRM\Contacts\StatusHistoryRepository;
use NowCRM\Orders\OrderLinkRepository;
use NowCRM\Support\Settings;
use NowCRM\Terms\TermRepository;

/**
 * Base test case with repositories and fictitious fixtures.
 */
abstract class NowCrmTestCase extends WP_UnitTestCase {

	protected ContactRepository $contacts;
	protected StatusHistoryRepository $history;
	protected TermRepository $terms;
	protected OrderLinkRepository $links;

	/**
	 * Query filter that keeps campaign transactions inside the test transaction.
	 */
	private ?Closure $campaign_transaction_filter = null;

	public function set_up(): void {
		parent::set_up();

		$this->contacts = new ContactRepository();
		$this->history  = new StatusHistoryRepository();
		$this->terms    = new TermRepository();
		$this->links    = new OrderLinkRepository();

		Settings::reset();

		delete_option( 'nowcrm_captured_mail' );
		wp_clear_scheduled_hook( 'nowcrm_send_batch' );

		// WordPress wraps each test in a transaction. Keep campaign transactions
		// inside it so the test runner can still roll back the fixtures.
		$this->campaign_transaction_filter = static fn( string $sql ): string => match ( $sql ) {
			'START TRANSACTION' => 'SAVEPOINT nowcrm_campaign',
			'COMMIT'            => 'RELEASE SAVEPOINT nowcrm_campaign',
			'ROLLBACK'          => 'ROLLBACK TO SAVEPOINT nowcrm_campaign',
			default             => $sql,
		};

		add_filter( 'query', $this->campaign_transaction_filter );

		$_POST    = array();
		$_REQUEST = array();
	}

	/**
	 * Detach the campaign rewrite so the base class can run its bare ROLLBACK.
	 *
	 * The filter only exists to translate statements issued inside a test. The
	 * WordPress test library ends every test with `ROLLBACK`, which must reach
	 * the database unchanged or the harness leaves the fixture transaction open.
	 */
	public function tear_down(): void {
		if ( null !== $this->campaign_transaction_filter ) {
			remove_filter( 'query', $this->campaign_transaction_filter );
			$this->campaign_transaction_filter = null;
		}

		parent::tear_down();
	}

	/**
	 * Create a contact with fictitious data.
	 *
	 * @param array<string, mixed> $overrides Field overrides.
	 */
	protected function make_contact( array $overrides = array() ): int {
		$id = $this->contacts->create(
			array_merge(
				array(
					'email'      => 'fixture-' . wp_generate_password( 8, false ) . '@example.test',
					'first_name' => 'Test',
					'last_name'  => 'Buyer',
					'source'     => 'test',
				),
				$overrides
			)
		);

		$this->assertIsInt( $id, 'Fixture contact creation failed.' );

		return (int) $id;
	}

	/**
	 * Create a WooCommerce order with fictitious data.
	 *
	 * @param array<string, mixed> $args billing_email, total, status, customer_id.
	 */
	protected function make_order( array $args = array() ): \WC_Order {
		$args = array_merge(
			array(
				'billing_email' => 'buyer-' . wp_generate_password( 8, false ) . '@example.test',
				'total'         => 100,
				'status'        => '',
				'customer_id'   => 0,
			),
			$args
		);

		$order = wc_create_order(
			array(
				'customer_id' => (int) $args['customer_id'],
			)
		);

		$order->set_billing_email( $args['billing_email'] );
		$order->set_total( (float) $args['total'] );
		$order->save();

		if ( '' !== $args['status'] ) {
			$order->update_status( $args['status'] );
		}

		return wc_get_order( $order->get_id() );
	}

	/**
	 * Create a draft campaign with a raw HTML body.
	 *
	 * @param array<string, mixed> $overrides Post/meta overrides.
	 */
	protected function make_campaign( array $overrides = array() ): int {
		$id = wp_insert_post(
			array(
				'post_type'   => \NowCRM\Campaign\Campaigns::POST_TYPE,
				'post_status' => 'draft',
				'post_title'  => $overrides['title'] ?? 'Fixture campaign ' . wp_generate_password( 6, false ),
				'post_content' => $overrides['content'] ?? '<p>Fixture</p>',
			),
			true
		);

		$this->assertIsInt( $id );

		update_post_meta( $id, \NowCRM\Campaign\Campaigns::META_MODE, 'html' );
		update_post_meta( $id, \NowCRM\Campaign\Campaigns::META_HTML, wp_slash( $overrides['html'] ?? '<p>Hello {{first_name}}</p><p><a href="https://example.test/offer">Offer</a></p>' ) );
		update_post_meta( $id, \NowCRM\Campaign\Campaigns::META_SUBJECT, $overrides['subject'] ?? 'Fixture subject' );
		update_post_meta(
			$id,
			\NowCRM\Campaign\Campaigns::META_AUDIENCE,
			wp_slash(
				(string) wp_json_encode(
					$overrides['audience'] ?? array(
						'scope'   => 'all',
							'include' => array(),
						'exclude' => array(),
					)
				)
			)
		);
		update_post_meta( $id, \NowCRM\Campaign\Campaigns::META_STATUS, $overrides['status'] ?? \NowCRM\Campaign\Campaigns::STATUS_DRAFT );

		return (int) $id;
	}

	/**
	 * Create one captured cart.
	 *
	 * @param array<string, mixed> $overrides Data overrides.
	 * @return array<string, mixed>
	 */
	protected function make_cart( array $overrides = array() ): array {
		$repo = new \NowCRM\Cart\CartRepository();

		$cart = $repo->record_activity(
			array_merge(
				array(
					'cycle_key'   => 'fixture' . bin2hex( random_bytes( 10 ) ),
					'session_key' => 'session-' . wp_generate_password( 8, false ),
					'user_id'     => 0,
					'email'       => 'cart-' . wp_generate_password( 8, false ) . '@example.test',
					'snapshot'    => array( array( 'product_id' => 1, 'variation_id' => 0, 'quantity' => 1, 'variation' => array() ) ),
					'cart_hash'   => 'hash-' . wp_generate_password( 6, false ),
					'item_count'  => 1,
					'total'       => 100,
					'currency'    => 'TWD',
				),
				$overrides
			)
		);

		$this->assertNotWPError( $cart );

		return $cart;
	}

	/**
	 * Enable or disable a group of settings.
	 *
	 * @param array<string, bool> $patch Settings patch.
	 */
	protected function set_settings( array $patch ): void {
		Settings::update( $patch );
	}

	/**
	 * Row count of a plugin table.
	 */
	protected function table_count( string $table, string $where = '1=1' ): int {
		global $wpdb;

		$name = \NowCRM\Support\Schema::table( $table );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- test helper, trusted table name.
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$name} WHERE {$where}" );
	}

	/**
	 * Whether a named index exists on a plugin table.
	 */
	protected function has_index( string $table, string $index ): bool {
		global $wpdb;

		$name = \NowCRM\Support\Schema::table( $table );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test helper, trusted table name.
		$rows = $wpdb->get_results( "SHOW INDEX FROM {$name}", ARRAY_A );

		foreach ( (array) $rows as $row ) {
			if ( $index === $row['Key_name'] ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether a named index is unique.
	 */
	protected function index_is_unique( string $table, string $index ): bool {
		global $wpdb;

		$name = \NowCRM\Support\Schema::table( $table );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test helper, trusted table name.
		$rows = $wpdb->get_results( "SHOW INDEX FROM {$name}", ARRAY_A );

		foreach ( (array) $rows as $row ) {
			if ( $index === $row['Key_name'] ) {
				return 0 === (int) $row['Non_unique'];
			}
		}

		return false;
	}
}
