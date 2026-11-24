<?php
/**
 * WooCommerce processing-email handoff. NOW CRM does not send transactional
 * mail in V1; it gives the operator a guarded switch plus a checklist so the
 * Woo email and the FluentCRM automation never own the same message.
 *
 * FluentCRM detection is read-only and deliberately tri-state: a missing table
 * is `unavailable`, a failed probe is `unknown`, and neither is reported safe.
 *
 * @package NowCRM
 */

namespace NowCRM\Woo;

use NowCRM\FluentCRM\Adapter;
use NowCRM\Support\Capabilities;

defined( 'ABSPATH' ) || exit;

final class ProcessingHandoff {

	public const OPTION = 'woocommerce_customer_processing_order_settings';

	public const FLUENT_CONFLICT    = 'conflict';
	public const FLUENT_CLEAR       = 'clear';
	public const FLUENT_UNKNOWN     = 'unknown';
	public const FLUENT_UNAVAILABLE = 'unavailable';

	/**
	 * Register the admin action.
	 */
	public function register(): void {
		add_action( 'admin_post_nowcrm_toggle_woo_processing', array( $this, 'toggle' ) );
	}

	/**
	 * Current state for the tools screen.
	 *
	 * @return array<string, mixed>
	 */
	public function status(): array {
		$enabled = null;
		$exists  = false;

		if ( function_exists( 'WC' ) && WC()->mailer() ) {
			$emails = WC()->mailer()->get_emails();
			$email  = $emails['WC_Email_Customer_Processing_Order'] ?? null;

			if ( $email ) {
				$exists  = true;
				$enabled = (bool) $email->is_enabled();
			}
		}

		$fluent = $this->detect_fluent_processing();

		return array(
			'woo_email_exists'  => $exists,
			'woo_enabled'       => $enabled,
			'fluentcrm_active'  => $this->fluentcrm_active(),
			'fluentcrm_tables'  => ( new Adapter() )->available(),
			'fluent_state'      => $fluent['state'],
			'fluent_published'  => $fluent['count'],
			'fluent_reason'     => $fluent['reason'],
			'owner'             => $this->owner( $enabled, $fluent['state'] ),
			'option'            => (array) get_option( self::OPTION, array() ),
		);
	}

	/**
	 * Toggle the native Woo processing email.
	 *
	 * Enabling is refused when a published FluentCRM processing automation is
	 * readable. When the automation state cannot be read, the operator must
	 * confirm the manual checklist. Disabling always needs an explicit
	 * acknowledgement that another owner will send the message.
	 */
	public function toggle(): void {
		if ( ! Capabilities::can_manage_settings() ) {
			wp_die( esc_html__( '沒有權限。', 'now-crm' ), 403 );
		}

		check_admin_referer( 'nowcrm_toggle_woo_processing' );

		$status = $this->status();

		if ( empty( $status['woo_email_exists'] ) ) {
			$this->redirect( 'woo_processing_unavailable' );
		}

		$enable = isset( $_POST['enabled'] ) && '1' === (string) wp_unslash( $_POST['enabled'] );

		if ( $enable ) {
			if ( self::FLUENT_CONFLICT === $status['fluent_state'] ) {
				$this->redirect( 'woo_processing_conflict' );
			}

			if ( self::FLUENT_CLEAR !== $status['fluent_state'] && ! $this->acknowledged( 'acknowledge_unknown_owner' ) ) {
				$this->redirect( 'woo_processing_ack_required' );
			}
		} elseif ( ! $this->acknowledged( 'acknowledge_alternative_owner' ) ) {
			$this->redirect( 'woo_processing_alternative_required' );
		}

		$option = (array) get_option( self::OPTION, array() );

		$option['enabled'] = $enable ? 'yes' : 'no';

		update_option( self::OPTION, $option, false );

		// WooCommerce caches email settings per request; a redirect reloads them.
		$this->redirect( $enable ? 'woo_processing_on' : 'woo_processing_off' );
	}

	/**
	 * Whether one explicit acknowledgement checkbox was posted.
	 */
	private function acknowledged( string $field ): bool {
		return isset( $_POST[ $field ] ) && '1' === (string) wp_unslash( $_POST[ $field ] );
	}

	/**
	 * Human owner state. Never returns "safe" for unknown or unavailable data.
	 */
	private function owner( ?bool $native_enabled, string $fluent_state ): string {
		if ( true === $native_enabled ) {
			return self::FLUENT_CLEAR === $fluent_state ? 'woo_native' : 'woo_native_unverified';
		}

		if ( self::FLUENT_CONFLICT === $fluent_state ) {
			return 'fluent';
		}

		if ( self::FLUENT_CLEAR === $fluent_state ) {
			return 'none_or_other';
		}

		return 'unknown';
	}

	/**
	 * Published FluentCRM automations for `woocommerce_order_status_processing`.
	 *
	 * Reads the known current-site `fc_funnels` table only. A retained row is
	 * not a conflict unless both FluentCRM Core and Pro are active, because the
	 * trigger class lives in Pro. It never loads FluentCRM code and never writes.
	 *
	 * @return array{state: string, count: int, reason: string}
	 */
	private function detect_fluent_processing(): array {
		global $wpdb;

		$runtime = $this->fluent_runtime();

		if ( 'active' !== $runtime ) {
			return array(
				'state'  => self::FLUENT_UNKNOWN,
				'count'  => 0,
				'reason' => 'unknown' === $runtime ? 'runtime_unknown' : 'runtime_inactive',
			);
		}

		$table = $this->fluent_table( 'funnels' );

		if ( '' === $table ) {
			return array(
				'state'  => self::FLUENT_UNAVAILABLE,
				'count'  => 0,
				'reason' => 'funnels_table_missing',
			);
		}

		$suppressed       = $wpdb->suppress_errors( true );
		$wpdb->last_error = '';
		$count            = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE type = %s AND trigger_name = %s AND status = %s",
				'funnels',
				'woocommerce_order_status_processing',
				'published'
			)
		);
		$error = $wpdb->last_error;
		$wpdb->suppress_errors( $suppressed );

		if ( '' !== $error || null === $count ) {
			return array(
				'state'  => self::FLUENT_UNKNOWN,
				'count'  => 0,
				'reason' => 'funnels_query_failed',
			);
		}

		$count = (int) $count;

		return array(
			'state'  => $count > 0 ? self::FLUENT_CONFLICT : self::FLUENT_CLEAR,
			'count'  => $count,
			'reason' => '',
		);
	}

	/**
	 * Resolve the current-site FluentCRM table, including temporary test tables.
	 */
	private function fluent_table( string $name ): string {
		global $wpdb;

		$candidate = $wpdb->prefix . 'fc_' . $name;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- schema probe.
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $candidate ) ) );

		if ( $exists === $candidate ) {
			return $candidate;
		}

		$suppressed       = $wpdb->suppress_errors( true );
		$wpdb->last_error = '';
		$wpdb->get_var( "SELECT 1 FROM {$candidate} LIMIT 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- schema probe.
		$error = $wpdb->last_error;
		$wpdb->suppress_errors( $suppressed );

		return '' === $error ? $candidate : '';
	}

	/**
	 * Return to the Woo handoff tab with one allow-listed notice.
	 */
	private function redirect( string $notice ): void {
		wp_safe_redirect(
			add_query_arg(
				'nowcrm_notice',
				$notice,
				admin_url( 'admin.php?page=nowcrm-tools&tab=woo' )
			)
		);
		exit;
	}

	/**
	 * Whether FluentCRM Core and Pro are both active for this site.
	 *
	 * The processing trigger lives in Pro; retained rows from an inactive
	 * install cannot run. A plugin API that is unavailable is not proof of
	 * executability, so it returns `unknown`.
	 *
	 * @return string active|inactive|unknown
	 */
	private function fluent_runtime(): string {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		if ( ! function_exists( 'is_plugin_active' ) ) {
			return 'unknown';
		}

		$core = is_plugin_active( 'fluent-crm/fluent-crm.php' );
		$pro  = is_plugin_active( 'fluentcampaign-pro/fluentcampaign-pro.php' );

		return $core && $pro ? 'active' : 'inactive';
	}

	/**
	 * Whether WordPress considers the FluentCRM plugin active.
	 */
	private function fluentcrm_active(): bool {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		return function_exists( 'is_plugin_active' ) && is_plugin_active( 'fluent-crm/fluent-crm.php' );
	}
}
