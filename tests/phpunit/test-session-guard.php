<?php
/**
 * Tests for {@see \FoxeLabs\Loggedin\Front\Session_Guard}.
 *
 * Covers the three behavior modes — block, logout_oldest, allow — and
 * the `loggedin_bypass` filter that exempts specific users.
 *
 * @package FoxeLabs\Loggedin
 */

declare( strict_types = 1 );

use FoxeLabs\Loggedin\Front\Session_Guard;
use FoxeLabs\Loggedin\Plugin;
use FoxeLabs\Loggedin\Setup\Settings;

/**
 * @group session-guard
 */
class Loggedin_Session_Guard_Test extends WP_UnitTestCase {

	private int $user_id;

	public function set_up(): void {
		parent::set_up();

		$this->user_id = self::factory()->user->create( array( 'user_pass' => 'correct-horse' ) );

		// Seed two session tokens so any limit <= 2 trips the check.
		$manager = WP_Session_Tokens::get_instance( $this->user_id );
		$manager->create( time() + HOUR_IN_SECONDS );
		$manager->create( time() + HOUR_IN_SECONDS );
	}

	public function tear_down(): void {
		delete_option( Plugin::OPTION_KEY );
		remove_all_filters( 'loggedin_bypass' );
		remove_all_actions( 'loggedin_login_blocked' );

		parent::tear_down();
	}

	private function set_settings( int $maximum, string $logic ): void {
		Settings::instance()->update(
			array(
				'maximum' => $maximum,
				'logic'   => $logic,
			)
		);
	}

	public function test_block_mode_returns_wp_error_at_limit(): void {
		$this->set_settings( 1, 'block' );

		$result = Session_Guard::instance()->validate_block_logic( get_user_by( 'id', $this->user_id ), 'correct-horse' );

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
		$result = Session_Guard::instance()->validate_block_logic( $user, 'wrong' );

		// WordPress then reports the wrong password itself; the limit stays secret.
		$this->assertSame( $user, $result );
		$this->assertSame( 0, $blocked );
	}

	public function test_block_error_offers_the_unlock_link_when_on(): void {
		$this->set_settings( 1, 'block' );

		$result = Session_Guard::instance()->validate_block_logic( get_user_by( 'id', $this->user_id ), 'correct-horse' );

		$this->assertStringContainsString( 'action=loggedin_unlock_request', $result->get_error_message() );
	}

	public function test_block_error_has_no_link_when_unlock_is_off(): void {
		Settings::instance()->update(
			array(
				'maximum' => 1,
				'logic'   => 'block',
				'unlock'  => false,
			)
		);

		$result = Session_Guard::instance()->validate_block_logic( get_user_by( 'id', $this->user_id ), 'correct-horse' );

		$this->assertStringNotContainsString( 'loggedin_unlock', $result->get_error_message() );
	}

	public function test_administrators_are_exempt_while_the_setting_is_on(): void {
		$this->set_settings( 1, 'block' );

		$user = get_user_by( 'id', $this->user_id );
		$user->set_role( 'administrator' );

		$this->assertSame( $user, Session_Guard::instance()->validate_block_logic( $user, 'correct-horse' ) );

		Settings::instance()->update(
			array(
				'maximum'       => 1,
				'logic'         => 'block',
				'exempt_admins' => false,
			)
		);

		$this->assertWPError( Session_Guard::instance()->validate_block_logic( $user, 'correct-horse' ) );
	}

	public function test_block_mode_passes_when_below_limit(): void {
		$this->set_settings( 5, 'block' );

		$user   = get_user_by( 'id', $this->user_id );
		$result = Session_Guard::instance()->validate_block_logic( $user, 'correct-horse' );

		$this->assertSame( $user, $result );
	}

	public function test_allow_mode_destroys_all_sessions_at_limit(): void {
		$this->set_settings( 1, 'allow' );

		Session_Guard::instance()->validate_allow_logic( true, '', '', $this->user_id );

		$this->assertCount(
			0,
			WP_Session_Tokens::get_instance( $this->user_id )->get_all()
		);
	}

	public function test_logout_oldest_mode_removes_one_session(): void {
		$this->set_settings( 1, 'logout_oldest' );

		Session_Guard::instance()->validate_allow_logic( true, '', '', $this->user_id );

		$this->assertCount(
			1,
			WP_Session_Tokens::get_instance( $this->user_id )->get_all()
		);
	}

	public function test_bypass_filter_exempts_user(): void {
		$this->set_settings( 1, 'block' );

		add_filter( 'loggedin_bypass', '__return_true' );

		$user   = get_user_by( 'id', $this->user_id );
		$result = Session_Guard::instance()->validate_block_logic( $user, 'correct-horse' );

		$this->assertSame( $user, $result );
	}

	public function test_existing_wp_error_passes_through(): void {
		$error  = new WP_Error( 'pre_existing', 'fail' );
		$result = Session_Guard::instance()->validate_block_logic( $error );

		$this->assertSame( $error, $result );
	}
}
