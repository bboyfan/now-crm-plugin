<?php
/**
 * Read-only NOW CRM contact panel on the WooCommerce order screen.
 *
 * The placement follows the reference product: a side metabox on both the
 * HPOS and legacy order editors, shown only when the order resolves to a
 * contact. The panel never writes, and it never re-binds a stored link whose
 * contact was deleted.
 *
 * @package NowCRM
 */

namespace NowCRM\Admin;

use NowCRM\Contacts\ContactRepository;
use NowCRM\Contacts\Status;
use NowCRM\Email\SuppressionRepository;
use NowCRM\Orders\OrderLinkRepository;
use NowCRM\Support\Capabilities;
use NowCRM\Terms\TermRepository;

defined( 'ABSPATH' ) || exit;

final class OrderContactPanel {

	public const ID = 'nowcrm_order_contact';

	/**
	 * Number of lists or tags shown before the remainder collapses into +N.
	 */
	private const TERM_PREVIEW = 3;

	/**
	 * Register the order-screen hooks.
	 */
	public function register(): void {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return;
		}

		add_action( 'add_meta_boxes', array( $this, 'maybe_add' ), 99, 2 );
	}

	/**
	 * Screen IDs of the order editor for the active storage mode.
	 *
	 * @return string[]
	 */
	public static function screen_ids(): array {
		// Both storage modes are accepted so the panel keeps working when the
		// store switches between HPOS and the legacy post table.
		$ids = array( 'shop_order', 'woocommerce_page_wc-orders', 'admin_page_wc-orders' );

		if ( function_exists( 'wc_get_page_screen_id' ) ) {
			$screen_id = wc_get_page_screen_id( 'shop_order' );

			if ( is_string( $screen_id ) && '' !== $screen_id ) {
				$ids[] = $screen_id;
			}
		}

		return array_values( array_unique( $ids ) );
	}

	/**
	 * Add the panel when the order resolves to a contact.
	 *
	 * @param string $screen_id Current screen ID.
	 * @param mixed  $object    Order object (HPOS) or post object (legacy).
	 */
	public function maybe_add( string $screen_id, $object ): void {
		if ( ! in_array( $screen_id, self::screen_ids(), true ) ) {
			return;
		}

		if ( ! Capabilities::can_manage_contacts() ) {
			return;
		}

		if ( ! apply_filters( 'nowcrm_order_contact_panel_allowed', true, $object ) ) {
			return;
		}

		$order = self::order_from( $object );

		if ( null === $order || 'shop_order' !== $order->get_type() ) {
			return;
		}

		if ( ! current_user_can( 'edit_shop_order', $order->get_id() ) ) {
			return;
		}

		$context = self::build_context( $order );

		if ( null === $context ) {
			return;
		}

		add_meta_box(
			self::ID,
			__( 'NOW CRM', 'now-crm' ),
			static function () use ( $context ): void {
				self::render( $context );
			},
			$screen_id,
			'side',
			'low'
		);
	}

	/**
	 * Normalize the meta box object argument.
	 */
	private static function order_from( $object ): ?\WC_Order {
		if ( $object instanceof \WC_Order ) {
			return $object;
		}

		if ( $object instanceof \WP_Post ) {
			$order = wc_get_order( $object->ID );

			return $order instanceof \WC_Order ? $order : null;
		}

		return null;
	}

	/**
	 * Resolve the contact and the data the panel shows.
	 *
	 * @return array<string, mixed>|null
	 */
	private static function build_context( \WC_Order $order ): ?array {
		$link       = ( new OrderLinkRepository() )->find_by_order( $order->get_id() );
		$repository = new ContactRepository();
		$contact    = null;

		if ( null !== $link && (int) $link['contact_id'] > 0 ) {
			$contact = $repository->get( (int) $link['contact_id'] );

			// A deleted contact must not be replaced by whoever holds the same
			// address now: the stored link is the only ownership fact.
			if ( null === $contact ) {
				return null;
			}
		} else {
			$email = sanitize_email( (string) $order->get_billing_email() );

			if ( '' !== $email ) {
				$contact = $repository->find_by_email( $email );
			}
		}

		if ( null === $contact ) {
			return null;
		}

		$contact_id = (int) $contact['id'];

		return array(
			'contact'     => $contact,
			'linked'      => null !== $link,
			'optin'       => null !== $link ? $link['marketing_optin'] : null,
			'aggregate'   => $repository->aggregate( $contact_id ),
			'terms'       => ( new TermRepository() )->for_contact( $contact_id ),
			'suppression' => ( new SuppressionRepository() )->find( (string) $contact['email'] ),
		);
	}

	/**
	 * Render the resolved contact summary.
	 *
	 * @param array<string, mixed> $context Result of build_context().
	 */
	private static function render( array $context ): void {
		$contact   = $context['contact'];
		$email     = (string) $contact['email'];
		$name      = Menu::contact_name( $contact );
		$status    = (string) $contact['status'];
		$aggregate = $context['aggregate'];
		$profile   = Menu::page_url(
			Menu::CONTACT_SLUG,
			array(
				'id'  => (int) $contact['id'],
				'tab' => 'purchases',
			)
		);
		?>
		<div class="nowcrm-wrap nowcrm-order-panel">
			<div class="nowcrm-order-panel__identity">
				<span class="nowcrm-avatar nowcrm-order-panel__avatar" aria-hidden="true">
					<?php echo esc_html( Menu::initials( $name, $email ) ); ?>
				</span>
				<div class="nowcrm-order-panel__identity-text">
					<p class="nowcrm-order-panel__name">
						<a href="<?php echo esc_url( $profile ); ?>"><?php echo esc_html( $name ); ?></a>
					</p>
					<p class="nowcrm-order-panel__email"><?php echo esc_html( $email ); ?></p>
				</div>
			</div>

			<p class="nowcrm-order-panel__status">
				<span class="nowcrm-status-badge nowcrm-status-badge--<?php echo esc_attr( $status ); ?>">
					<span class="nowcrm-status-dot" aria-hidden="true"></span>
					<?php echo esc_html( Status::label( $status ) ); ?>
				</span>
			</p>

			<dl class="nowcrm-order-panel__stats">
				<div>
					<dt><?php esc_html_e( '計入訂單', 'now-crm' ); ?></dt>
					<dd><?php echo esc_html( (string) (int) $aggregate['order_count'] ); ?></dd>
				</div>
				<div>
					<dt><?php esc_html_e( '累計消費', 'now-crm' ); ?></dt>
					<dd>
						<?php
						echo esc_html(
							Menu::format_amount(
								(float) $aggregate['total_spent'],
								(string) $aggregate['currency']
							)
						);
						?>
					</dd>
				</div>
				<div>
					<dt><?php esc_html_e( '最近訂單', 'now-crm' ); ?></dt>
					<dd><?php echo esc_html( Menu::format_datetime( $aggregate['last_order_at'] ) ); ?></dd>
				</div>
			</dl>

			<p class="nowcrm-order-panel__meta">
				<span><?php esc_html_e( '本單行銷同意', 'now-crm' ); ?></span>
				<strong><?php echo esc_html( self::optin_label( $context['optin'] ) ); ?></strong>
			</p>

			<?php if ( null !== $context['suppression'] ) : ?>
				<p class="nowcrm-order-panel__notice">
					<?php
					echo esc_html(
						sprintf(
							/* translators: %s: suppression reason, for example "bounced". */
							__( '已抑制行銷信：%s', 'now-crm' ),
							self::suppression_label( (string) $context['suppression']['reason'] )
						)
					);
					?>
				</p>
			<?php endif; ?>

			<?php self::render_terms( (array) $context['terms'] ); ?>

			<?php if ( ! $context['linked'] ) : ?>
				<p class="nowcrm-order-panel__notice">
					<?php esc_html_e( '此訂單尚未建立 NOW 關聯，以上為 Email 比對結果。', 'now-crm' ); ?>
				</p>
			<?php endif; ?>

			<p class="nowcrm-order-panel__action-row">
				<a class="button nowcrm-order-panel__action" href="<?php echo esc_url( $profile ); ?>">
					<?php esc_html_e( '開啟聯絡人', 'now-crm' ); ?>
				</a>
			</p>
		</div>
		<?php
	}

	/**
	 * Lists and tags of the contact, three per group.
	 *
	 * @param array<int, array<string, mixed>> $terms Terms of the contact.
	 */
	private static function render_terms( array $terms ): void {
		if ( array() === $terms ) {
			return;
		}

		$groups = array(
			TermRepository::TYPE_LIST => array(
				'label' => __( '名單', 'now-crm' ),
				'names' => array(),
			),
			TermRepository::TYPE_TAG  => array(
				'label' => __( '標籤', 'now-crm' ),
				'names' => array(),
			),
		);

		foreach ( $terms as $term ) {
			$type = (string) ( $term['type'] ?? '' );

			if ( isset( $groups[ $type ] ) ) {
				$groups[ $type ]['names'][] = (string) ( $term['name'] ?? '' );
			}
		}

		foreach ( $groups as $group ) {
			if ( array() === $group['names'] ) {
				continue;
			}

			$shown = array_slice( $group['names'], 0, self::TERM_PREVIEW );
			$more  = count( $group['names'] ) - count( $shown );
			?>
			<div class="nowcrm-order-panel__terms">
				<span class="nowcrm-order-panel__terms-label"><?php echo esc_html( $group['label'] ); ?></span>
				<span class="nowcrm-order-panel__chips">
					<?php foreach ( $shown as $name ) : ?>
						<span class="nowcrm-chip"><?php echo esc_html( $name ); ?></span>
					<?php endforeach; ?>
					<?php if ( $more > 0 ) : ?>
						<span class="nowcrm-chip nowcrm-chip--more">
							<?php echo esc_html( '+' . (string) $more ); ?>
						</span>
					<?php endif; ?>
				</span>
			</div>
			<?php
		}
	}

	/**
	 * Marketing consent recorded on this order.
	 *
	 * @param int|null $optin Stored value.
	 */
	private static function optin_label( ?int $optin ): string {
		if ( null === $optin ) {
			return __( '未記錄', 'now-crm' );
		}

		return 1 === $optin ? __( '已同意', 'now-crm' ) : __( '未同意', 'now-crm' );
	}

	/**
	 * Human readable suppression reason.
	 *
	 * @param string $reason Stored reason key.
	 */
	private static function suppression_label( string $reason ): string {
		$labels = array(
			SuppressionRepository::REASON_UNSUBSCRIBED => __( '已退訂', 'now-crm' ),
			SuppressionRepository::REASON_BOUNCED      => __( '退信', 'now-crm' ),
			SuppressionRepository::REASON_COMPLAINED   => __( '投訴', 'now-crm' ),
			SuppressionRepository::REASON_MANUAL       => __( '手動', 'now-crm' ),
		);

		return $labels[ $reason ] ?? $reason;
	}
}
