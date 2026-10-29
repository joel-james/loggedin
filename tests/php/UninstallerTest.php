<?php
/**
 * Tests for {@see \FoxeLabs\Loggedin\Uninstaller}.
 *
 * @package FoxeLabs\Loggedin\Tests
 */

declare( strict_types = 1 );

namespace FoxeLabs\Loggedin\Tests;

use FoxeLabs\Loggedin\Plugin;
use FoxeLabs\Loggedin\Sessions\LastLogin;
use FoxeLabs\Loggedin\Uninstaller;
use WP_Session_Tokens;
use WP_UnitTestCase;

/**
 * @group uninstall
 */
class UninstallerTest extends WP_UnitTestCase {

	/**
	 * Settings, review notice keys and per-user rows all go.
	 */
	public function test_removes_options_and_user_meta(): void {
		$user_id = self::factory()->user->create();

		update_option( Plugin::OPTION_KEY, array( 'maximum' => 2 ) );
		update_option( 'loggedin_logout_all_before', time() );
		update_site_option( 'loggedin_review_time', time() );
		update_user_meta( $user_id, LastLogin::META_KEY, time() );
		update_user_meta( $user_id, 'loggedin_review_dismissed', 1 );

		( new Uninstaller() )->run();

		// A registered default would mask a missing row, so pass our own.
		$this->assertNull( get_option( Plugin::OPTION_KEY, null ) );
		$this->assertFalse( get_option( 'loggedin_logout_all_before' ) );
		$this->assertFalse( get_site_option( 'loggedin_review_time' ) );
		$this->assertSame( '', get_user_meta( $user_id, LastLogin::META_KEY, true ) );
		$this->assertSame( '', get_user_meta( $user_id, 'loggedin_review_dismissed', true ) );
	}

	/**
	 * Session tokens are WordPress's data and stay.
	 */
	public function test_keeps_session_tokens(): void {
		$user_id = self::factory()->user->create();
		$manager = WP_Session_Tokens::get_instance( $user_id );
		$manager->create( time() + HOUR_IN_SECONDS );

		( new Uninstaller() )->run();

		$this->assertCount( 1, $manager->get_all() );
	}
}
