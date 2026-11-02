<?php
/**
 * A session manager that isn't WordPress's user meta one.
 *
 * @package FoxeLabs\Loggedin\Tests\Support
 */

declare( strict_types = 1 );

namespace FoxeLabs\Loggedin\Tests\Support;

use WP_Session_Tokens;

/**
 * Stores nothing; stands in for a Redis or other custom backend.
 */
class CustomSessionTokens extends WP_Session_Tokens {
	protected function get_sessions() {
		return array();
	}

	protected function get_session( $verifier ) {
		return null;
	}

	protected function update_session( $verifier, $session = null ) {}

	protected function destroy_other_sessions( $verifier ) {}

	protected function destroy_all_sessions() {}

	public static function drop_sessions() {}
}
