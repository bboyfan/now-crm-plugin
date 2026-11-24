<?php
/**
 * Segment module hooks.
 *
 * Add this class to Plugin::modules() to register the private segment post
 * type and the admin screen handlers.
 *
 * @package NowCRM
 */

namespace NowCRM\Segments;

use NowCRM\Admin\SegmentsPage;

defined( 'ABSPATH' ) || exit;

final class Module {

	/**
	 * Register the post type and admin handlers.
	 */
	public function register(): void {
		add_action( 'init', array( SegmentRepository::class, 'register_post_type' ) );

		SegmentsPage::register();
	}
}
