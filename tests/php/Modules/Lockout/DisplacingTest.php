<?php
/**
 * Tests for {@see \FoxeLabs\Loggedin\Modules\Lockout\Displacing}.
 *
 * @package FoxeLabs\Loggedin\Tests\Modules\Lockout
 */

declare( strict_types = 1 );

namespace FoxeLabs\Loggedin\Tests\Modules\Lockout;

use FoxeLabs\Loggedin\Plugin;
use WP_Session_Tokens;
use WP_UnitTestCase;

require_once dirname( __DIR__, 2 ) . '/Support/woocommerce-stub.php';

/**
 * @group displacing
 */
class DisplacingTest extends WP_UnitTestCase {

	private int $user_id;

	public function set_up(): void {
		parent::set_up();

		$this->user_id = self::factory()->user->create();

		remove_filter( 'attach_session_information', array( Plugin::instance()->session_guard(), 'enforce_on_create' ) );

		$manager = WP_Session_Tokens::get_instance( $this->user_id );
		$oldest  = $manager->create( time() + HOUR_IN_SECONDS );
		$manager->create( time() + HOUR_IN_SECONDS );

		// Both logins share a second; make the first one older.
		$sessions = get_user_meta( $this->user_id, 'session_tokens', true );
		$sessions[ hash( 'sha256', $oldest ) ]['login'] -= MINUTE_IN_SECONDS;
		update_user_meta( $this->user_id, 'session_tokens', $sessions );

		$GLOBALS['loggedin_test_wc']         = (object) array( 'session' => null );
		$GLOBALS['loggedin_test_wc_notices'] = array();
	}

	public function tear_down(): void {
		delete_option( Plugin::OPTION_KEY );
		unset( $_COOKIE['loggedin_displacing'], $_COOKIE['loggedin_displaced'], $GLOBALS['loggedin_test_wc'], $GLOBALS['loggedin_test_wc_notices'] );
		remove_all_actions( 'loggedin_displacing_notice' );
		remove_all_filters( 'loggedin_displacing_message' );
		wp_set_current_user( 0 );

		parent::tear_down();
	}

	/**
	 * Log in again under the given mode, with room for two sessions.
	 *
	 * @param string $logic    Login mode.
	 * @param array  $settings Other settings.
	 */
	private function log_in( string $logic, array $settings = array() ): void {
		Plugin::instance()->settings()->update(
			array_merge(
				array(
					'maximum' => 2,
					'logic'   => $logic,
				),
				$settings
			)
		);

		Plugin::instance()->session_guard()->validate_allow_logic( true, '', '', $this->user_id );
	}

	/**
	 * The wp-admin notice the next screen prints, or ''.
	 *
	 * @return string
	 */
	private function admin_notice(): string {
		Plugin::instance()->lockout()->displacing()->show_in_admin();

		ob_start();
		Plugin::instance()->lockout()->displacing()->print_admin_notice();

		return (string) ob_get_clean();
	}

	public function test_a_login_that_ends_the_oldest_session_is_told(): void {
		$this->log_in( 'logout_oldest' );
		wp_set_current_user( $this->user_id );

		$this->assertStringContainsString( 'so your oldest session was signed out', $this->admin_notice() );
	}

	public function test_logout_all_says_how_many_devices(): void {
		$this->log_in( 'allow' );
		wp_set_current_user( $this->user_id );

		$this->assertStringContainsString( 'so your 2 other devices were signed out', $this->admin_notice() );
	}

	public function test_the_notice_shows_once(): void {
		$this->log_in( 'logout_oldest' );
		wp_set_current_user( $this->user_id );

		$this->assertNotSame( '', $this->admin_notice() );
		$this->assertArrayNotHasKey( 'loggedin_displacing', $_COOKIE );
		$this->assertSame( '', $this->admin_notice() );
	}

	public function test_nothing_is_said_when_the_setting_is_off(): void {
		$this->log_in( 'logout_oldest', array( 'displacing_notice' => false ) );

		$this->assertArrayNotHasKey( 'loggedin_displacing', $_COOKIE );
	}

	public function test_nothing_is_said_when_no_session_ends(): void {
		$this->log_in( 'logout_oldest', array( 'maximum' => 5 ) );

		$this->assertArrayNotHasKey( 'loggedin_displacing', $_COOKIE );
	}

	public function test_another_user_on_the_device_is_not_told(): void {
		$this->log_in( 'logout_oldest' );
		wp_set_current_user( self::factory()->user->create() );

		$this->assertSame( '', $this->admin_notice() );
		$this->assertArrayHasKey( 'loggedin_displacing', $_COOKIE );
	}

	public function test_a_planted_cookie_is_ignored(): void {
		wp_set_current_user( $this->user_id );

		$_COOKIE['loggedin_displacing'] = $this->user_id . '.allow.3.' . ( time() + 600 ) . '.forged';

		$this->assertSame( '', $this->admin_notice() );
	}

	public function test_the_front_end_skips_woocommerce_without_a_session(): void {
		$this->log_in( 'logout_oldest' );
		wp_set_current_user( $this->user_id );

		Plugin::instance()->lockout()->displacing()->show_on_front();

		$this->assertSame( array(), $GLOBALS['loggedin_test_wc_notices'] );
		// Nothing showed it, so wp-admin still can.
		$this->assertArrayHasKey( 'loggedin_displacing', $_COOKIE );
	}

	public function test_the_front_end_adds_a_woocommerce_notice(): void {
		$GLOBALS['loggedin_test_wc'] = (object) array( 'session' => new \stdClass() );

		$this->log_in( 'logout_oldest' );
		wp_set_current_user( $this->user_id );

		Plugin::instance()->lockout()->displacing()->show_on_front();

		$this->assertCount( 1, $GLOBALS['loggedin_test_wc_notices'] );
		$this->assertStringContainsString( 'oldest session was signed out', $GLOBALS['loggedin_test_wc_notices'][0][0] );
		$this->assertSame( 'notice', $GLOBALS['loggedin_test_wc_notices'][0][1] );
		$this->assertArrayNotHasKey( 'loggedin_displacing', $_COOKIE );
	}

	public function test_a_listener_gets_the_notice_on_the_front_end(): void {
		$received = array();
		add_action(
			'loggedin_displacing_notice',
			static function ( ...$args ) use ( &$received ): void {
				$received = $args;
			},
			10,
			4
		);

		$this->log_in( 'allow' );
		wp_set_current_user( $this->user_id );

		Plugin::instance()->lockout()->displacing()->show_on_front();

		$this->assertStringContainsString( '2 other devices', $received[0] );
		$this->assertSame( $this->user_id, $received[1]->ID );
		$this->assertSame( 'allow', $received[2] );
		$this->assertSame( 2, $received[3] );
		$this->assertArrayNotHasKey( 'loggedin_displacing', $_COOKIE );
	}

	public function test_the_message_can_be_filtered(): void {
		add_filter(
			'loggedin_displacing_message',
			static function (): string {
				return 'Custom <b>text</b>';
			}
		);

		$this->log_in( 'logout_oldest' );
		wp_set_current_user( $this->user_id );

		$this->assertStringContainsString( 'Custom &lt;b&gt;text&lt;/b&gt;', $this->admin_notice() );
	}

	public function test_noting_writes_no_user_meta(): void {
		$before = get_user_meta( $this->user_id );

		Plugin::instance()->lockout()->displacing()->record( $this->user_id, array( 'abc' ) );

		$this->assertSame( $before, get_user_meta( $this->user_id ) );
		$this->assertArrayHasKey( 'loggedin_displacing', $_COOKIE );
	}
}
