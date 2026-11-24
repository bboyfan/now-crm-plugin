<?php
/**
 * Forms module entry point. Add `Forms\Module::class` to Plugin::modules() to
 * enable private form storage, the `[nowcrm_form]` shortcode, public
 * submissions, and the admin screens.
 *
 * @package NowCRM
 */

namespace NowCRM\Forms;

use NowCRM\Admin\FormsPage;

defined( 'ABSPATH' ) || exit;

final class Module {

	/**
	 * Register hooks. Every owned hook is added here.
	 */
	public function register(): void {
		add_action( 'init', array( FormRepository::class, 'register_post_types' ) );

		FormRenderer::register();
		( new Submission() )->register();

		if ( is_admin() ) {
			FormsPage::register();
		}
	}
}
