<?php
/**
 * Administration entry point for the campaign editor and delivery report.
 *
 * @package NowCRM
 */

namespace NowCRM\Admin;

use NowCRM\Campaign\Campaigns;

defined( 'ABSPATH' ) || exit;

final class CampaignPage {

	public static function url( int $id ): string {
		return Menu::page_url( Menu::CAMPAIGNS_SLUG, array( 'campaign' => $id ) );
	}

	public static function render( int $id ): void {
		if ( ! Campaigns::can_edit( $id ) ) {
			wp_die( esc_html__( '無法開啟這封電子報。', 'now-crm' ), '', array( 'response' => 403 ) );
		}

		?>
		<div class="wrap nowcrm-wrap nowcrm-workspace-wrap" id="nowcrm-campaign-workspace">
			<p role="status"><?php esc_html_e( '正在載入電子報…', 'now-crm' ); ?></p>
			<noscript><?php esc_html_e( '請啟用 JavaScript 以編輯電子報。', 'now-crm' ); ?></noscript>
		</div>
		<?php
	}

	public static function enqueue( int $id, string $list_url = '', string $list_label = '', bool $recurring = false ): void {
		if ( ! Campaigns::can_edit( $id ) ) {
			return;
		}
		$asset = require NOWCRM_DIR . '/assets/build/campaign-editor.asset.php';
		// The raw-HTML editor is a code surface, not a plain textarea. Core
		// supplies CodeMirror and the per-user syntax/indent preferences.
		$code_editor = function_exists( 'wp_enqueue_code_editor' )
			? wp_enqueue_code_editor( array( 'type' => 'text/html' ) )
			: false;

		if ( is_array( $code_editor ) && isset( $code_editor['codemirror'] ) && is_array( $code_editor['codemirror'] ) ) {
			// Email HTML is full of conditional comments and legacy attributes;
			// lint noise makes the source harder to read. Wrap long lines instead.
			$code_editor['codemirror']['lint']         = false;
			$code_editor['codemirror']['lineWrapping'] = true;
		}

		wp_enqueue_style( 'wp-components' );
		wp_enqueue_style( 'nowcrm-campaign-workspace', plugins_url( 'assets/css/campaign-editor.css', NOWCRM_FILE ), array(), (string) filemtime( NOWCRM_DIR . '/assets/css/campaign-editor.css' ) );
		wp_enqueue_script(
			'nowcrm-campaign-workspace', plugins_url( 'assets/build/campaign-editor.js', NOWCRM_FILE ),
			$asset['dependencies'], $asset['version'], true
		);
		wp_localize_script(
			'nowcrm-campaign-workspace',
			'nowcrmCampaignWorkspace',
			array(
				'endpoint'     => rest_url( 'nowcrm/v1/campaigns/' . $id ),
				'nonce'        => wp_create_nonce( 'wp_rest' ),
				'listUrl'      => '' !== $list_url ? $list_url : Menu::page_url( Menu::CAMPAIGNS_SLUG ),
				'listLabel'    => '' !== $list_label ? $list_label : __( '← 電子報', 'now-crm' ),
				'recurring'    => $recurring,
				'emailLogUrl'  => Menu::page_url( Menu::EMAIL_SLUG, array( 'campaign_id' => $id, 'kind' => 'campaign' ) ),
				'testLogUrl'   => Menu::page_url( Menu::EMAIL_SLUG, array( 'campaign_id' => $id, 'kind' => 'test' ) ),
				'currentEmail' => wp_get_current_user()->user_email,
				'timezone'     => wp_timezone_string(),
				'contentFrameUrl' => ContentEditorPage::url( 'campaign', $id, true ),
				'campaignId'      => $id,
				'tab'             => sanitize_key( Menu::query( 'tab' ) ),
				'codeEditor'      => is_array( $code_editor ) ? $code_editor : null,
				'recurringActions' => $recurring
					? array(
						'adminPostUrl'    => admin_url( 'admin-post.php' ),
						'duplicateAction' => RecurringPage::DUPLICATE_ACTION,
						'duplicateNonce'  => wp_create_nonce( RecurringPage::DUPLICATE_ACTION ),
						'deleteAction'    => RecurringPage::DELETE_ACTION,
						'deleteNonce'     => wp_create_nonce( RecurringPage::DELETE_ACTION ),
						'canDelete'       => current_user_can( 'delete_post', $id ),
					)
					: null,
			)
		);
	}
}
