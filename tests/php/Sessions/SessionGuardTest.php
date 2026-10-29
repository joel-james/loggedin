<?php
/**
 * Tests for {@see \FoxeLabs\Loggedin\Sessions\SessionGuard}.
 *
 * Covers the three behavior modes — block, logout_oldest, allow — and
 * the `loggedin_bypass` filter that exempts specific users.
 *
 * @package FoxeLabs\Loggedin\Tests\Sessions
 */

declare( strict_types = 1 );

namespace FoxeLabs\Loggedin\Tests\Sessions;

use FoxeLabs\Loggedin\Plugin;
use WP_Error;
use WP_Session_Tokens;
use WP_UnitTestCase;


/**
 * @group session-guard
 */
class SessionGuardTest extends WP_UnitTestCase {

	private int $user_id;

	public function set_up(): void {
		parent::set_up();

		$this->user_id = self::factory()->user->create( array( 'user_pass' => 'correct-horse' ) );

		// Seed sessions past the limit: new sessions are limited as they're
		// created, and these tests drive the limit by hand.
		remove_filter( 'attach_session_information', array( Plugin::instance()->session_guard(), 'enforce_on_create' ) );

		// Seed two session tokens so any limit <= 2 trips the check.
		$manager = WP_Session_Tokens::get_instance( $this->user_id );
		$manager->create( time() + HOUR_IN_SECONDS );
		$manager->create( time() + HOUR_IN_SECONDS );
	}

	public function tear_down(): void {
		delete_option( Plugin::OPTION_KEY );
		remove_all_filters( 'loggedin_bypass' );
		remove_all_filters( 'loggedin_reached_limit' );
		remove_all_actions( 'loggedin_login_blocked' );

		parent::tear_down();
	}

	private function set_settings( int $maximum, string $logic ): void {
		Plugin::instance()->settings()->update(
			array(
				'maximum' => $maximum,
				'logic'   => $logic,
			)
		);
	}

	public function test_block_mode_returns_wp_error_at_limit(): void {
		$this->set_settings( 1, 'block' );

		$result = Plugin::instance()->session_guard()->validate_block_logic( get_user_by( 'id', $this->user_id ), 'correct-horse' );

		$this->assertWPError( $result );
		$this->assertSame( 'login_limit_reached', $result->get_error_code() );
	}

	public function test_block_mode_leaves_a_wrong_password_to_wordpress(): void {
		$this->set_settings( 1, 'block' );

		$blocked = 0;
		add_action(
			'loggedin_login_blocked',
			static function () use ( &$blocked ) {
				++$blocked;
			}
		);

		$user   = get_user_by( 'id', $this->user_id );
		$result = Plugin::instance()->session_guard()->validate_block_logic( $user, 'wrong' );

		// WordPress then reports the wrong password itself; the limit stays secret.
		$this->assertSame( $user, $result );
		$this->assertSame( 0, $blocked );
	}

	public function test_block_error_offers_the_unlock_link_when_on(): void {
		$this->set_settings( 1, 'block' );

		$result = Plugin::instance()->session_guard()->validate_block_logic( get_user_by( 'id', $this->user_id ), 'correct-horse' );

		$this->assertStringContainsString( 'action=loggedin_unlock_request', $result->get_error_message() );
	}

	public function test_block_error_has_no_link_when_unlock_is_off(): void {
		Plugin::instance()->settings()->update(
			array(
				'maximum' => 1,
				'logic'   => 'block',
				'unlock'  => false,
			)
		);

		$result = Plugin::instance()->session_guard()->validate_block_logic( get_user_by( 'id', $this->user_id ), 'correct-horse' );

		$this->assertStringNotContainsString( 'loggedin_unlock', $result->get_error_message() );
	}

	public function test_administrators_are_exempt_while_the_setting_is_on(): void {
		$this->set_settings( 1, 'block' );

		$user = get_user_by( 'id', $this->user_id );
		$user->set_role( 'administrator' );

		$this->assertSame( $user, Plugin::instance()->session_guard()->validate_block_logic( $user, 'correct-horse' ) );

		Plugin::instance()->settings()->update(
			array(
				'maximum'       => 1,
				'logic'         => 'block',
				'exempt_admins' => false,
			)
		);

		$this->assertWPError( Plugin::instance()->session_guard()->validate_block_logic( $user, 'correct-horse' ) );
	}

	public function test_block_mode_passes_when_below_limit(): void {
		$this->set_settings( 5, 'block' );

		$user   = get_user_by( 'id', $this->user_id );
		$result = Plugin::instance()->session_guard()->validate_block_logic( $user, 'correct-horse' );

		$this->assertSame( $user, $result );
	}

	public function test_allow_mode_destroys_all_sessions_at_limit(): void {
		$this->set_settings( 1, 'allow' );

		Plugin::instance()->session_guard()->validate_allow_logic( true, '', '', $this->user_id );

		$this->assertCount(
			0,
			WP_Session_Tokens::get_instance( $this->user_id )->get_all()
		);
	}

	public function test_logout_oldest_mode_removes_one_session(): void {
		$this->set_settings( 2, 'logout_oldest' );

		Plugin::instance()->session_guard()->validate_allow_logic( true, '', '', $this->user_id );

		$this->assertCount(
			1,
			WP_Session_Tokens::get_instance( $this->user_id )->get_all()
		);
	}

	public function test_bypass_filter_exempts_user(): void {
		$this->set_settings( 1, 'block' );

		add_filter( 'loggedin_bypass', '__return_true' );

		$user   = get_user_by( 'id', $this->user_id );
		$result = Plugin::instance()->session_guard()->validate_block_logic( $user, 'correct-horse' );

		$this->assertSame( $user, $result );
	}

	public function test_existing_wp_error_passes_through(): void {
		$error  = new WP_Error( 'pre_existing', 'fail' );
		$result = Plugin::instance()->session_guard()->validate_block_logic( $error );

		$this->assertSame( $error, $result );
	}

	/**
	 * A session made the way social and magic-link logins make one.
	 *
	 * @return string Raw token.
	 */
	private function create_like_a_plugin(): string {
		add_filter( 'attach_session_information', array( Plugin::instance()->session_guard(), 'enforce_on_create' ), 10, 2 );

		return WP_Session_Tokens::get_instance( $this->user_id )->create( time() + HOUR_IN_SECONDS );
	}

	public function test_logout_all_applies_to_sessions_made_outside_the_login_form(): void {
		$this->set_settings( 2, 'allow' );

		$token = $this->create_like_a_plugin();

		$sessions = WP_Session_Tokens::get_instance( $this->user_id );
		$this->assertCount( 1, $sessions->get_all() );
		$this->assertNotNull( $sessions->get( $token ) );
	}

	public function test_logout_oldest_applies_to_sessions_made_outside_the_login_form(): void {
		$this->set_settings( 2, 'logout_oldest' );

		$this->create_like_a_plugin();

		$this->assertCount( 2, WP_Session_Tokens::get_instance( $this->user_id )->get_all() );
	}

	public function test_block_new_cannot_refuse_a_session_made_outside_the_login_form(): void {
		$this->set_settings( 2, 'block' );

		$this->create_like_a_plugin();

		$this->assertCount( 3, WP_Session_Tokens::get_instance( $this->user_id )->get_all() );
	}

	public function test_a_session_under_the_limit_ends_nothing(): void {
		$this->set_settings( 5, 'allow' );

		$this->create_like_a_plugin();

		$this->assertCount( 3, WP_Session_Tokens::get_instance( $this->user_id )->get_all() );
	}

	public function test_the_blocked_login_message_can_be_changed(): void {
		Plugin::instance()->settings()->update(
			array(
				'maximum' => 1,
				'logic'   => 'block',
				'unlock'  => false,
				'message' => 'Sign out of a device first.',
			)
		);

		$result = Plugin::instance()->session_guard()->validate_block_logic( get_user_by( 'id', $this->user_id ), 'correct-horse' );

		$this->assertSame( 'Sign out of a device first.', $result->get_error_message() );
	}

	public function test_logout_oldest_skips_expired_sessions(): void {
		$this->set_settings( 2, 'logout_oldest' );

		$sessions            = get_user_meta( $this->user_id, 'session_tokens', true );
		$sessions['expired'] = array(
			'expiration' => time() - 1,
			'login'      => time() - DAY_IN_SECONDS,
		);
		update_user_meta( $this->user_id, 'session_tokens', $sessions );

		$this->create_like_a_plugin();

		$this->assertCount( 2, WP_Session_Tokens::get_instance( $this->user_id )->get_all() );
	}

	public function test_logout_oldest_makes_room_after_the_limit_is_lowered(): void {
		remove_filter( 'attach_session_information', array( Plugin::instance()->session_guard(), 'enforce_on_create' ) );
		WP_Session_Tokens::get_instance( $this->user_id )->create( time() + HOUR_IN_SECONDS );

		$this->set_settings( 1, 'logout_oldest' );

		Plugin::instance()->session_guard()->validate_allow_logic( true, '', '', $this->user_id );

		$this->assertCount( 0, WP_Session_Tokens::get_instance( $this->user_id )->get_all() );
	}

	public function test_a_filter_that_never_lets_up_cannot_loop_forever(): void {
		$this->set_settings( 1, 'logout_oldest' );
		add_filter( 'loggedin_reached_limit', '__return_true' );

		Plugin::instance()->session_guard()->validate_allow_logic( true, '', '', $this->user_id );

		$this->assertCount( 0, WP_Session_Tokens::get_instance( $this->user_id )->get_all() );
	}
}
