<?php
/**
 * Minimal WooCommerce stubs for PHPStan.
 *
 * Loggedin calls two WooCommerce functions, only when WooCommerce is
 * active, so the few APIs it uses are declared here instead of adding a
 * stubs package. Scanned, never loaded.
 *
 * @package FoxeLabs\Loggedin
 */

// phpcs:ignoreFile -- Stub signatures mirror WooCommerce.

namespace {
	abstract class WC_Session {}

	final class WooCommerce {
		/**
		 * Session, or null where WooCommerce hasn't started one.
		 *
		 * @var WC_Session|null
		 */
		public $session = null;
	}

	/**
	 * @return WooCommerce
	 */
	function WC() {}

	/**
	 * @param string               $message     Notice HTML.
	 * @param string               $notice_type success, error or notice.
	 * @param array<string, mixed> $data        Extra data.
	 * @return void
	 */
	function wc_add_notice( $message, $notice_type = 'success', $data = array() ) {}
}
