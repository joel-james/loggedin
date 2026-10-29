<?php
/**
 * Tests for {@see \FoxeLabs\Loggedin\Sessions\LogoutEpoch}.
 *
 * Covers the epoch comparison (stale vs fresh sessions), the
 * `determine_current_user` rejection path, the keep-current exemption,
 * and the actions fired for add-ons.
 *
 * @package FoxeLabs\Loggedin\Tests\Sessions
 */

declare( strict_types = 1 );

namespace FoxeLabs\Loggedin\Tests\Sessions;

use FoxeLabs\Loggedin\Sessions\LogoutEpoch;
use WP_Session_Tokens;
use WP_UnitTestCase;


/**
 * @group logout-epoch
 */
class LogoutEpochTest extends WP_UnitTestCase {

	private int $user_id;

	public function set_up(): void {
		parent::set_up();

		$this->user_id = self::factory()->user->create();

		// Make sure the filter is registered even if another test
		// already instantiated the singleton.
		LogoutEpoch::instance();
	}

	public function tear_down(): void {
		delete_option( LogoutEpoch::OPTION );

		parent::tear_down();
	}

	/**
	 * Create a session with a specific login time and impersonate it —
	 * sets the current user and the session-token cookie so
	 * `wp_get_session_token()` resolves inside the filter under test.
	 */
	private function create_session( int $login_time ): string {
		$manager = WP_Session_Tokens::get_instance( $this->user_id );
		$token   = $manager->create( time() + HOUR_IN_SECONDS );

		$session          = $manager->get( $token );
		$session['login'] = $login_time;
		$manager->update( $token, $session );

		wp_set_current_user( $this->user_id );
		$_COOKIE[ LOGGED_IN_COOKIE ] = wp_generate_auth_cookie(
			$this->user_id,
			time() + HOUR_IN_SECONDS,
			'logged_in',
			$token
		);

		return $token;
	}

	public function test_logout_all_stores_epoch_and_fires_action(): void {
		$fired = 0;
		add_action(
			'loggedin_logout_all_users',
			static function () use ( &$fired ): void {
				++$fired;
			}
		);

		$epoch = LogoutEpoch::logout_all( false );

		$this->assertSame( $epoch, (int) get_option( LogoutEpoch::OPTION ) );
		$this->assertSame( 1, $fired );
	}

	public function test_stale_session_is_rejected_and_destroyed(): void {
		$token = $this->create_session( time() - DAY_IN_SECONDS );

		update_option( LogoutEpoch::OPTION, time(), true );

		$result = LogoutEpoch::instance()->invalidate_stale_session( $this->user_id );

		$this->assertFalse( $result );
		$this->assertNull(
			WP_Session_Tokens::get_instance( $this->user_id )->get( $token )
		);
	}

	public function test_fresh_session_survives(): void {
		$token = $this->create_session( time() + 10 );

		update_option( LogoutEpoch::OPTION, time(), true );

		$result = LogoutEpoch::instance()->invalidate_stale_session( $this->user_id );

		$this->assertSame( $this->user_id, $result );
		$this->assertNotEmpty(
			WP_Session_Tokens::get_instance( $this->user_id )->get( $token )
		);
	}

	public function test_no_epoch_means_no_change(): void {
		$this->create_session( time() - DAY_IN_SECONDS );

		$result = LogoutEpoch::instance()->invalidate_stale_session( $this->user_id );

		$this->assertSame( $this->user_id, $result );
	}

	public function test_logout_all_keeps_current_session(): void {
		$token = $this->create_session( time() - DAY_IN_SECONDS );

		LogoutEpoch::logout_all( true );

		// The caller's session was re-stamped, so it must pass the check.
		$result = LogoutEpoch::instance()->invalidate_stale_session( $this->user_id );

		$this->assertSame( $this->user_id, $result );
		$this->assertNotEmpty(
			WP_Session_Tokens::get_instance( $this->user_id )->get( $token )
		);
	}

	public function test_session_invalidated_action_fires(): void {
		$token = $this->create_session( time() - DAY_IN_SECONDS );

		update_option( LogoutEpoch::OPTION, time(), true );

		$captured = null;
		$verifier = null;
		add_action(
			'loggedin_session_invalidated',
			static function ( int $user_id, string $hash ) use ( &$captured, &$verifier ): void {
				$captured = $user_id;
				$verifier = $hash;
			},
			10,
			2
		);

		LogoutEpoch::instance()->invalidate_stale_session( $this->user_id );

		$this->assertSame( $this->user_id, $captured );
		// The verifier, never the raw cookie token.
		$this->assertSame( hash( 'sha256', $token ), $verifier );
	}

	public function test_request_without_session_token_passes_through(): void {
		wp_set_current_user( $this->user_id );
		unset( $_COOKIE[ LOGGED_IN_COOKIE ] );

		update_option( LogoutEpoch::OPTION, time(), true );

		$result = LogoutEpoch::instance()->invalidate_stale_session( $this->user_id );

		$this->assertSame( $this->user_id, $result );
	}
}
