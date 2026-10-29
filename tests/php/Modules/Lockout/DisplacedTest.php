<?php
/**
 * Tests for {@see \FoxeLabs\Loggedin\Modules\Lockout\Displaced}.
 *
 * @package FoxeLabs\Loggedin\Tests\Modules\Lockout
 */

declare( strict_types = 1 );

namespace FoxeLabs\Loggedin\Tests\Modules\Lockout;

use FoxeLabs\Loggedin\Modules\Lockout\Displaced;
use FoxeLabs\Loggedin\Plugin;
use WP_Session_Tokens;
use WP_UnitTestCase;


/**
 * @group displaced
 */
class DisplacedTest extends WP_UnitTestCase {

	private int $user_id;

	/**
	 * Raw token of the first (oldest) session.
	 *
	 * @var string
	 */
	private string $oldest = '';

	public function set_up(): void {
		parent::set_up();

		$this->user_id = self::factory()->user->create( array( 'user_login' => 'displaced-user' ) );

		remove_filter( 'attach_session_information', array( Plugin::instance()->session_guard(), 'enforce_on_create' ) );

		$manager      = WP_Session_Tokens::get_instance( $this->user_id );
		$this->oldest = $manager->create( time() + HOUR_IN_SECONDS );
		$manager->create( time() + HOUR_IN_SECONDS );

		// Both logins share a second; make the first one older.
		$sessions = get_user_meta( $this->user_id, 'session_tokens', true );
		$sessions[ hash( 'sha256', $this->oldest ) ]['login'] -= MINUTE_IN_SECONDS;
		update_user_meta( $this->user_id, 'session_tokens', $sessions );
	}

	public function tear_down(): void {
		delete_option( Plugin::OPTION_KEY );
		unset( $_COOKIE['loggedin_displaced'] );

		parent::tear_down();
	}

	/**
	 * The cookie elements a request from the given session carries.
	 *
	 * @param string $token Raw token.
	 *
	 * @return array
	 */
	private function cookie( string $token ): array {
		return array(
			'username' => 'displaced-user',
			'token'    => $token,
		);
	}

	private function displace_oldest(): void {
		Plugin::instance()->settings()->update(
			array(
				'maximum' => 2,
				'logic'   => 'logout_oldest',
			)
		);

		Plugin::instance()->session_guard()->validate_allow_logic( true, '', '', $this->user_id );
	}

	public function test_the_ended_session_is_told_why(): void {
		$this->displace_oldest();

		Plugin::instance()->lockout()->displaced()->detect( $this->cookie( $this->oldest ) );

		$this->assertStringContainsString( 'signed in on another device', Plugin::instance()->lockout()->displaced()->login_message( '' ) );
	}

	public function test_the_message_shows_once(): void {
		$this->displace_oldest();

		Plugin::instance()->lockout()->displaced()->detect( $this->cookie( $this->oldest ) );
		Plugin::instance()->lockout()->displaced()->login_message( '' );

		$this->assertSame( '', Plugin::instance()->lockout()->displaced()->login_message( '' ) );

		// The noted session is used up too, so a later request doesn't mark the device again.
		Plugin::instance()->lockout()->displaced()->detect( $this->cookie( $this->oldest ) );
		$this->assertSame( '', Plugin::instance()->lockout()->displaced()->login_message( '' ) );
	}

	public function test_a_session_ended_another_way_is_not_told_this(): void {
		WP_Session_Tokens::get_instance( $this->user_id )->destroy_all();

		Plugin::instance()->lockout()->displaced()->detect( $this->cookie( $this->oldest ) );

		$this->assertSame( '', Plugin::instance()->lockout()->displaced()->login_message( '' ) );
	}

	public function test_logout_all_notes_every_session(): void {
		Plugin::instance()->settings()->update(
			array(
				'maximum' => 2,
				'logic'   => 'allow',
			)
		);

		Plugin::instance()->session_guard()->validate_allow_logic( true, '', '', $this->user_id );

		$this->assertCount( 2, get_user_meta( $this->user_id, Displaced::META_KEY, true ) );
	}

	public function test_noting_never_writes_the_session_tokens_blob(): void {
		$before = get_user_meta( $this->user_id, 'session_tokens', true );

		Plugin::instance()->lockout()->displaced()->record( $this->user_id, array( 'abc' ) );

		$this->assertSame( $before, get_user_meta( $this->user_id, 'session_tokens', true ) );
	}

	public function test_a_planted_marker_is_ignored(): void {
		$_COOKIE['loggedin_displaced'] = '1';

		$this->assertSame( '', Plugin::instance()->lockout()->displaced()->login_message( '' ) );

		$_COOKIE['loggedin_displaced'] = ( time() + 600 ) . '.forged';

		$this->assertSame( '', Plugin::instance()->lockout()->displaced()->login_message( '' ) );
	}
}
