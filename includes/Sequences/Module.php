<?php
/**
 * Sequence module hooks.
 *
 * Add this class to Plugin::modules() to own the private sequence post type,
 * the sequence tracker table, and the due-step cron.
 *
 * @package NowCRM
 */

namespace NowCRM\Sequences;

defined( 'ABSPATH' ) || exit;

final class Module {

	/**
	 * Register the post type, scheduler, and public seams.
	 */
	public function register(): void {
		add_action( 'init', array( SequenceRepository::class, 'register_post_type' ) );

		( new Scheduler() )->register();
	}
}
