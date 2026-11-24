<?php
/**
 * Editor module bootstrap.
 *
 * @package NowCRM
 */

namespace NowCRM\Editor;

defined( 'ABSPATH' ) || exit;

final class Module {

	public function register(): void {
		( new DummyPost() )->register();
		( new Routes() )->register();
		( new Patterns() )->register();
	}
}
