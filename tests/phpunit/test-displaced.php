<?php
/**
 * Tests for {@see \FoxeLabs\Loggedin\Front\Displaced}.
 *
 * @package FoxeLabs\Loggedin
 */

declare( strict_types = 1 );

use FoxeLabs\Loggedin\Front\Displaced;
use FoxeLabs\Loggedin\Front\Session_Guard;
use FoxeLabs\Loggedin\Plugin;
use FoxeLabs\Loggedin\Setup\Settings;

/**
 * @group displaced
 */
class Loggedin_Displaced_Test extends WP_UnitTestCase {

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

		remove_filter( 'attach_session_information', array( Session_Guard::instance(), 'enforce_on_create' ) );

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
		Settings::instance()->update(
			array(
				'maximum' => 2,
				'logic'   => 'logout_oldest',
			)
		);

		Session_Guard::instance()->validate_allow_logic( true, '', '', $this->user_id );
	}

	public function test_the_ended_session_is_told_why(): void {
		$this->displace_oldest();

		Displaced::instance()->detect( $this->cookie( $this->oldest ) );

		$this->assertStringContainsString( 'signed in on another device', Displaced::instance()->login_message( '' ) );
	}

	public function test_the_message_shows_once(): void {
		$this->displace_oldest();

		Displaced::instance()->detect( $this->cookie( $this->oldest ) );
		Displaced::instance()->login_message( '' );

		$this->assertSame( '', Displaced::instance()->login_message( '' ) );

		// The noted session is used up too, so a later request doesn't mark the device again.
		Displaced::instance()->detect( $this->cookie( $this->oldest ) );
		$this->assertSame( '', Displaced::instance()->login_message( '' ) );
	}

	public function test_a_session_ended_another_way_is_not_told_this(): void {
		WP_Session_Tokens::get_instance( $this->user_id )->destroy_all();

		Displaced::instance()->detect( $this->cookie( $this->oldest ) );

		$this->assertSame( '', Displaced::instance()->login_message( '' ) );
	}

	public function test_logout_all_notes_every_session(): void {
		Settings::instance()->update(
			array(
				'maximum' => 2,
				'logic'   => 'allow',
			)
		);

		Session_Guard::instance()->validate_allow_logic( true, '', '', $this->user_id );

		$this->assertCount( 2, get_user_meta( $this->user_id, Displaced::META_KEY, true ) );
	}

	public function test_noting_never_writes_the_session_tokens_blob(): void {
		$before = get_user_meta( $this->user_id, 'session_tokens', true );

		Displaced::instance()->record( $this->user_id, array( 'abc' ) );

		$this->assertSame( $before, get_user_meta( $this->user_id, 'session_tokens', true ) );
	}

	public function test_a_planted_marker_is_ignored(): void {
		$_COOKIE['loggedin_displaced'] = '1';

		$this->assertSame( '', Displaced::instance()->login_message( '' ) );

		$_COOKIE['loggedin_displaced'] = ( time() + 600 ) . '.forged';

		$this->assertSame( '', Displaced::instance()->login_message( '' ) );
	}
}
