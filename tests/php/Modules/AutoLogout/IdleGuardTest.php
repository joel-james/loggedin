<?php
/**
 * Idle-guard tests for auto logout.
 *
 * These are integration tests: they stand up a real session token and a
 * matching `logged_in` cookie so `wp_get_session_token()` resolves,
 * then drive `enforce()` and assert on what happened to the session.
 *
 * Every test runs with `wp_doing_ajax` filtered on where a logout is
 * expected, because the page-view branch ends in `wp_safe_redirect()`
 * plus `exit` and would take the test runner with it. The AJAX branch
 * exercises exactly the same termination path minus the redirect.
 *
 * @package FoxeLabs\Loggedin\Tests\Modules\AutoLogout
 */

declare( strict_types = 1 );

namespace FoxeLabs\Loggedin\Tests\Modules\AutoLogout;

use FoxeLabs\Loggedin\Modules\AutoLogout\IdleGuard;
use FoxeLabs\Loggedin\Modules\AutoLogout\Settings;
use FoxeLabs\Loggedin\Sessions\SessionGuard;
use WP_Session_Tokens;
use WP_UnitTestCase;


/**
 * @group idle-guard
 */
class IdleGuardTest extends WP_UnitTestCase {

	/**
	 * Raw session token for the signed-in user, as the cookie carries it.
	 *
	 * @var string
	 */
	private $token = '';

	public function set_up(): void {
		parent::set_up();

		// These tests sign one user in on several devices.
		remove_filter( 'attach_session_information', array( SessionGuard::instance(), 'enforce_on_create' ) );
	}

	public function tear_down(): void {
		delete_option( Settings::OPTION_KEY );
		delete_option( Settings::ROLE_OPTION_KEY );
		delete_metadata( 'user', 0, Settings::ACTIVITY_META_KEY, '', true );

		unset( $_COOKIE[ LOGGED_IN_COOKIE ] );

		remove_all_filters( 'wp_doing_ajax' );
		remove_all_actions( 'loggedin_auto_logout_expired' );

		$this->token = '';

		parent::tear_down();
	}

	/**
	 * Sign a user in with a real session token and cookie.
	 *
	 * `wp_set_current_user()` alone isn't enough — the guard reads the
	 * session token out of the `logged_in` cookie, which the test suite
	 * never sets.
	 *
	 * @param string $role User role to create.
	 *
	 * @return int User id.
	 */
	private function sign_in( string $role = 'editor' ): int {
		$user_id    = $this->factory->user->create( array( 'role' => $role ) );
		$expiration = time() + DAY_IN_SECONDS;

		$this->token = WP_Session_Tokens::get_instance( $user_id )->create( $expiration );

		$_COOKIE[ LOGGED_IN_COOKIE ] = wp_generate_auth_cookie(
			$user_id,
			$expiration,
			'logged_in',
			$this->token
		);

		wp_set_current_user( $user_id );

		return $user_id;
	}

	/**
	 * Verifier for a raw token — the activity map's key.
	 *
	 * @param string $token Raw session token.
	 *
	 * @return string
	 */
	private function verifier( string $token ): string {
		return hash( 'sha256', $token );
	}

	/**
	 * Rewrite a device's activity stamp.
	 *
	 * @param int         $user_id User id.
	 * @param int         $stamp   Unix timestamp to store.
	 * @param string|null $token   Raw token; defaults to the signed-in one.
	 *
	 * @return void
	 */
	private function set_last_active( int $user_id, int $stamp, ?string $token = null ): void {
		$map = get_user_meta( $user_id, Settings::ACTIVITY_META_KEY, true );
		$map = is_array( $map ) ? $map : array();

		$map[ $this->verifier( $token ?? $this->token ) ] = $stamp;

		update_user_meta( $user_id, Settings::ACTIVITY_META_KEY, $map );
	}

	/**
	 * Read a device's activity stamp.
	 *
	 * @param int         $user_id User id.
	 * @param string|null $token   Raw token; defaults to the signed-in one.
	 *
	 * @return int Zero when no stamp has been written.
	 */
	private function get_last_active( int $user_id, ?string $token = null ): int {
		$map = get_user_meta( $user_id, Settings::ACTIVITY_META_KEY, true );

		if ( ! is_array( $map ) ) {
			return 0;
		}

		return (int) ( $map[ $this->verifier( $token ?? $this->token ) ] ?? 0 );
	}

	public function test_register_wires_enforcement_and_assets(): void {
		IdleGuard::instance();

		$this->assertSame( 5, has_action( 'init', array( IdleGuard::instance(), 'enforce' ) ) );
		$this->assertNotFalse(
			has_action( 'wp_enqueue_scripts', array( IdleGuard::instance(), 'enqueue' ) )
		);
		$this->assertNotFalse(
			has_action( 'admin_enqueue_scripts', array( IdleGuard::instance(), 'enqueue' ) )
		);
		$this->assertNotFalse(
			has_filter( 'login_message', array( IdleGuard::instance(), 'login_message' ) )
		);
	}

	public function test_enforce_does_nothing_when_idle_logout_is_off(): void {
		$user_id = $this->sign_in();

		IdleGuard::instance()->enforce();

		$this->assertTrue( is_user_logged_in() );
		// No stamp is written either — an untracked feature shouldn't
		// be paying for user-meta writes.
		$this->assertSame( 0, $this->get_last_active( $user_id ) );
	}

	public function test_enforce_stamps_activity_on_a_page_view(): void {
		update_option( Settings::OPTION_KEY, array( 'idle_timeout' => 30 ) );

		$user_id = $this->sign_in();

		IdleGuard::instance()->enforce();

		$this->assertGreaterThan( 0, $this->get_last_active( $user_id ) );
		$this->assertTrue( is_user_logged_in() );
	}

	public function test_enforce_throttles_repeat_stamps(): void {
		update_option( Settings::OPTION_KEY, array( 'idle_timeout' => 60 ) );

		$user_id = $this->sign_in();

		// A stamp from 30s ago is well inside the write interval
		// (a quarter of 60 minutes), so it must be left alone.
		$recent = time() - 30;
		$this->set_last_active( $user_id, $recent );

		IdleGuard::instance()->enforce();

		$this->assertSame( $recent, $this->get_last_active( $user_id ) );
	}

	public function test_enforce_refreshes_a_stale_stamp(): void {
		update_option( Settings::OPTION_KEY, array( 'idle_timeout' => 60 ) );

		$user_id = $this->sign_in();

		// Older than the quarter-timeout write interval but younger
		// than the timeout itself: refresh, don't log out.
		$stale = time() - ( 20 * MINUTE_IN_SECONDS );
		$this->set_last_active( $user_id, $stale );

		IdleGuard::instance()->enforce();

		$this->assertGreaterThan( $stale, $this->get_last_active( $user_id ) );
		$this->assertTrue( is_user_logged_in() );
	}

	public function test_enforce_logs_out_an_idle_session(): void {
		update_option( Settings::OPTION_KEY, array( 'idle_timeout' => 15 ) );
		add_filter( 'wp_doing_ajax', '__return_true' );

		$user_id = $this->sign_in();
		$this->set_last_active( $user_id, time() - ( 16 * MINUTE_IN_SECONDS ) );

		$fired = 0;
		add_action(
			'loggedin_auto_logout_expired',
			static function () use ( &$fired ) {
				++$fired;
			}
		);

		IdleGuard::instance()->enforce();

		$this->assertSame( 1, $fired );
		$this->assertFalse( is_user_logged_in() );

		// Only this device's session is destroyed.
		$this->assertNull(
			WP_Session_Tokens::get_instance( $user_id )->get( $this->token )
		);
	}

	public function test_enforce_leaves_other_devices_signed_in(): void {
		update_option( Settings::OPTION_KEY, array( 'idle_timeout' => 15 ) );
		add_filter( 'wp_doing_ajax', '__return_true' );

		$user_id = $this->sign_in();

		$other = WP_Session_Tokens::get_instance( $user_id )->create( time() + DAY_IN_SECONDS );

		$this->set_last_active( $user_id, time() - ( 16 * MINUTE_IN_SECONDS ) );

		IdleGuard::instance()->enforce();

		$this->assertNotNull(
			WP_Session_Tokens::get_instance( $user_id )->get( $other )
		);
	}

	public function test_a_role_override_shortens_the_window(): void {
		update_option( Settings::OPTION_KEY, array( 'idle_timeout' => 120 ) );
		update_option( Settings::ROLE_OPTION_KEY, array( 'editor' => 5 ) );
		add_filter( 'wp_doing_ajax', '__return_true' );

		$user_id = $this->sign_in( 'editor' );

		// Ten minutes idle: inside the 120-minute global window, past
		// the 5-minute rule for editors.
		$this->set_last_active( $user_id, time() - ( 10 * MINUTE_IN_SECONDS ) );

		IdleGuard::instance()->enforce();

		$this->assertFalse( is_user_logged_in() );
	}

	public function test_stamping_never_writes_the_session_tokens_blob(): void {
		update_option( Settings::OPTION_KEY, array( 'idle_timeout' => 30 ) );

		$user_id = $this->sign_in();
		$before  = get_user_meta( $user_id, 'session_tokens', true );

		IdleGuard::instance()->enforce();

		// The whole point of the separate meta row: activity writes must
		// not touch the blob the Active Sessions add-on mutates, or an
		// admin's per-session sign-out can be undone by a stamp landing
		// a moment later.
		$this->assertSame( $before, get_user_meta( $user_id, 'session_tokens', true ) );
		$this->assertGreaterThan( 0, $this->get_last_active( $user_id ) );
	}

	public function test_a_destroyed_sibling_session_stays_destroyed(): void {
		update_option( Settings::OPTION_KEY, array( 'idle_timeout' => 30 ) );

		$user_id = $this->sign_in();
		$other   = WP_Session_Tokens::get_instance( $user_id )->create( time() + DAY_IN_SECONDS );

		WP_Session_Tokens::get_instance( $user_id )->destroy( $other );

		IdleGuard::instance()->enforce();

		// A single-process test can't genuinely interleave two requests,
		// so this doesn't reproduce the race — the real guarantee is the
		// blob-untouched assertion in the test above, which is what makes
		// the race impossible rather than merely unlikely. This covers
		// the observable half: stamping leaves other sessions alone.
		$this->assertNull(
			WP_Session_Tokens::get_instance( $user_id )->get( $other ),
			'A destroyed session must not reappear after an activity write.'
		);
	}

	public function test_stale_stamps_for_other_devices_are_pruned(): void {
		update_option( Settings::OPTION_KEY, array( 'idle_timeout' => 15 ) );

		$user_id = $this->sign_in();
		$other   = WP_Session_Tokens::get_instance( $user_id )->create( time() + DAY_IN_SECONDS );

		// A second device that went idle long ago, and the current one
		// due for a refresh.
		$this->set_last_active( $user_id, time() - ( 60 * MINUTE_IN_SECONDS ), $other );
		$this->set_last_active( $user_id, time() - ( 10 * MINUTE_IN_SECONDS ) );

		IdleGuard::instance()->enforce();

		$this->assertSame( 0, $this->get_last_active( $user_id, $other ) );
		$this->assertGreaterThan( 0, $this->get_last_active( $user_id ) );
	}

	public function test_a_fresh_stamp_for_another_device_is_kept(): void {
		update_option( Settings::OPTION_KEY, array( 'idle_timeout' => 60 ) );

		$user_id = $this->sign_in();
		$other   = WP_Session_Tokens::get_instance( $user_id )->create( time() + DAY_IN_SECONDS );

		$recent = time() - ( 5 * MINUTE_IN_SECONDS );
		$this->set_last_active( $user_id, $recent, $other );
		$this->set_last_active( $user_id, time() - ( 20 * MINUTE_IN_SECONDS ) );

		IdleGuard::instance()->enforce();

		$this->assertSame( $recent, $this->get_last_active( $user_id, $other ) );
	}

	public function test_the_map_is_keyed_by_the_active_sessions_token_hash(): void {
		update_option( Settings::OPTION_KEY, array( 'idle_timeout' => 30 ) );

		$user_id = $this->sign_in();

		IdleGuard::instance()->enforce();

		$map      = get_user_meta( $user_id, Settings::ACTIVITY_META_KEY, true );
		$verifier = array_key_first( $map );

		// Active Sessions routes per-session actions on a 64-char hex
		// verifier and reports it as `token_hash`; the stamp must be
		// keyed the same way for the two to be joinable later.
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $verifier );
		$this->assertArrayHasKey(
			$verifier,
			get_user_meta( $user_id, 'session_tokens', true )
		);
	}

	public function test_enforce_ignores_logged_out_visitors(): void {
		update_option( Settings::OPTION_KEY, array( 'idle_timeout' => 15 ) );

		wp_set_current_user( 0 );

		IdleGuard::instance()->enforce();

		$this->assertFalse( is_user_logged_in() );
	}

	public function test_login_message_only_appears_with_the_flag(): void {
		unset( $_GET['loggedin_auto_logout'] );
		$this->assertSame( 'base', IdleGuard::instance()->login_message( 'base' ) );

		$_GET['loggedin_auto_logout'] = '1';
		$this->assertStringContainsString(
			'inactive',
			IdleGuard::instance()->login_message( 'base' )
		);

		unset( $_GET['loggedin_auto_logout'] );
	}

	public function test_rest_error_carries_the_login_url(): void {
		$error = IdleGuard::instance()->rest_error( null );

		$this->assertSame( 'loggedin_idle_logout', $error->get_error_code() );
		$this->assertSame( 401, $error->get_error_data()['status'] );
		$this->assertStringContainsString(
			'loggedin_auto_logout',
			$error->get_error_data()['login_url']
		);
	}
}
