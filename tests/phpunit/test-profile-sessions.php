<?php
/**
 * Tests for {@see \FoxeLabs\Loggedin\Admin\Profile_Sessions}.
 *
 * @package FoxeLabs\Loggedin
 */

declare( strict_types = 1 );

use FoxeLabs\Loggedin\Admin\Profile_Sessions;
use FoxeLabs\Loggedin\Front\Session_Guard;
use FoxeLabs\Loggedin\Front\Session_Store;

/**
 * Thrown in place of a redirect, so a test can read where it went.
 */
class Loggedin_Profile_Redirect extends Exception {}

/**
 * @group profile-sessions
 */
class Loggedin_Profile_Sessions_Test extends WP_UnitTestCase {

	private int $user_id;

	private string $current = '';

	private string $other = '';

	public function set_up(): void {
		parent::set_up();

		$this->user_id = self::factory()->user->create();
		wp_set_current_user( $this->user_id );

		remove_filter( 'attach_session_information', array( Session_Guard::instance(), 'enforce_on_create' ) );

		$manager       = WP_Session_Tokens::get_instance( $this->user_id );
		$this->current = $manager->create( time() + HOUR_IN_SECONDS );
		$this->other   = $manager->create( time() + HOUR_IN_SECONDS );

		// `wp_get_session_token()` reads the logged-in cookie.
		$_COOKIE[ LOGGED_IN_COOKIE ] = wp_generate_auth_cookie( $this->user_id, time() + HOUR_IN_SECONDS, 'logged_in', $this->current );

		add_filter( 'wp_redirect', array( $this, 'stop_redirect' ) );
	}

	public function tear_down(): void {
		unset( $_COOKIE[ LOGGED_IN_COOKIE ], $_GET['session'], $_REQUEST['_wpnonce'] );

		parent::tear_down();
	}

	/**
	 * Throw the redirect target instead of following it.
	 *
	 * @param string $location Redirect target.
	 *
	 * @throws Loggedin_Profile_Redirect Always.
	 */
	public function stop_redirect( $location ) {
		throw new Loggedin_Profile_Redirect( $location );
	}

	/**
	 * Follow the Sign out link for a session.
	 *
	 * @param string $verifier Session verifier.
	 *
	 * @return string Where it redirected to.
	 */
	private function sign_out( string $verifier ): string {
		$_GET['session']      = $verifier;
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'loggedin_end_session_' . $verifier );

		try {
			Profile_Sessions::instance()->end_session();
		} catch ( Loggedin_Profile_Redirect $redirect ) {
			return $redirect->getMessage();
		}

		return '';
	}

	public function test_another_device_can_be_signed_out(): void {
		$location = $this->sign_out( Session_Store::instance()->verifier( $this->other ) );

		$this->assertStringContainsString( 'loggedin_session_ended=1', $location );
		$this->assertNull( WP_Session_Tokens::get_instance( $this->user_id )->get( $this->other ) );
	}

	public function test_this_device_is_never_signed_out_here(): void {
		$location = $this->sign_out( Session_Store::instance()->verifier( $this->current ) );

		$this->assertStringContainsString( 'loggedin_session_ended=0', $location );
		$this->assertNotNull( WP_Session_Tokens::get_instance( $this->user_id )->get( $this->current ) );
	}

	public function test_the_list_shows_each_device_once(): void {
		ob_start();
		Profile_Sessions::instance()->own( get_user_by( 'id', $this->user_id ) );
		$html = (string) ob_get_clean();

		$this->assertSame( 1, substr_count( $html, 'This device' ) );
		$this->assertSame( 1, substr_count( $html, 'loggedin_end_session' ) );
	}
}
