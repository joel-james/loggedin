<?php
/**
 * Tests for {@see \FoxeLabs\Loggedin\Sessions\SessionStore}.
 *
 * @package FoxeLabs\Loggedin\Tests\Sessions
 */

declare( strict_types = 1 );

namespace FoxeLabs\Loggedin\Tests\Sessions;

use FoxeLabs\Loggedin\Plugin;
use FoxeLabs\Loggedin\Tests\Support\CustomSessionTokens;
use WP_Session_Tokens;
use WP_UnitTestCase;


/**
 * @group session-store
 */
class SessionStoreTest extends WP_UnitTestCase {

	private int $user_id;

	public function set_up(): void {
		parent::set_up();

		$this->user_id = self::factory()->user->create();

		remove_filter( 'attach_session_information', array( Plugin::instance()->session_guard(), 'enforce_on_create' ) );
	}

	public function test_it_ends_only_the_sessions_named(): void {
		$manager = WP_Session_Tokens::get_instance( $this->user_id );
		$keep    = $manager->create( time() + HOUR_IN_SECONDS );
		$end     = $manager->create( time() + HOUR_IN_SECONDS );
		$store   = Plugin::instance()->session_store();

		$this->assertSame( 1, $store->destroy( $this->user_id, array( $store->verifier( $end ), 'not-a-session' ) ) );
		$this->assertNotNull( $manager->get( $keep ) );
		$this->assertNull( $manager->get( $end ) );
	}

	public function test_expired_sessions_are_not_listed(): void {
		$manager = WP_Session_Tokens::get_instance( $this->user_id );
		$manager->create( time() + HOUR_IN_SECONDS );

		$sessions = get_user_meta( $this->user_id, 'session_tokens', true );
		$sessions['expired'] = array(
			'expiration' => time() - 1,
			'login'      => time() - HOUR_IN_SECONDS,
		);
		update_user_meta( $this->user_id, 'session_tokens', $sessions );

		$this->assertCount( 1, Plugin::instance()->session_store()->all( $this->user_id ) );
		$this->assertNotSame( 'expired', Plugin::instance()->session_store()->oldest( $this->user_id ) );
	}

	public function test_a_custom_storage_backend_is_left_alone(): void {
		WP_Session_Tokens::get_instance( $this->user_id )->create( time() + HOUR_IN_SECONDS );

		add_filter(
			'session_token_manager',
			static function () {
				return CustomSessionTokens::class;
			}
		);

		$store = Plugin::instance()->session_store();

		$this->assertSame( array(), $store->all( $this->user_id ) );
		$this->assertSame( 0, $store->destroy( $this->user_id, array( 'anything' ) ) );
	}

	/**
	 * @dataProvider devices
	 *
	 * @param string $ua       User agent.
	 * @param string $expected Device name.
	 */
	public function test_devices_are_named( string $ua, string $expected ): void {
		$this->assertSame( $expected, Plugin::instance()->session_store()->device( $ua ) );
	}

	public function devices(): array {
		return array(
			'chrome mac'  => array( 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36', 'Chrome on macOS' ),
			'safari ios'  => array( 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1', 'Safari on iPhone' ),
			'edge win'    => array( 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36 Edg/130.0.0.0', 'Edge on Windows' ),
			'firefox lin' => array( 'Mozilla/5.0 (X11; Linux x86_64; rv:131.0) Gecko/20100101 Firefox/131.0', 'Firefox on Linux' ),
			'android'     => array( 'Mozilla/5.0 (Linux; Android 14) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Mobile Safari/537.36', 'Chrome on Android' ),
			'unknown'     => array( '', 'Unknown device' ),
		);
	}
}
