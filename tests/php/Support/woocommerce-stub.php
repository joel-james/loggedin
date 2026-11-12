<?php
/**
 * Stand-ins for the two WooCommerce functions Loggedin calls.
 *
 * WooCommerce isn't installed in the test suite. A test that loads this
 * sets `$GLOBALS['loggedin_test_wc']` to an object whose `session` is
 * null (no session) or anything else (a session), and reads the notices
 * added from `$GLOBALS['loggedin_test_wc_notices']`.
 *
 * @package FoxeLabs\Loggedin\Tests\Support
 */

declare( strict_types = 1 );

if ( ! function_exists( 'WC' ) ) {
	/**
	 * The WooCommerce instance.
	 *
	 * @return object
	 */
	function WC() { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName -- WooCommerce's name.
		return $GLOBALS['loggedin_test_wc'] ?? (object) array( 'session' => null );
	}
}

if ( ! function_exists( 'wc_add_notice' ) ) {
	/**
	 * Add a store notice.
	 *
	 * @param string $message Notice HTML.
	 * @param string $type    Notice type.
	 */
	function wc_add_notice( $message, $type = 'success' ): void {
		$GLOBALS['loggedin_test_wc_notices'][] = array( $message, $type );
	}
}
