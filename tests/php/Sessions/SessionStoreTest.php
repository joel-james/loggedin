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

		$sessions            = get_user_meta( $this->user_id, 'session_tokens', true );
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

	public function test_ending_every_session_fires_the_hook(): void {
		WP_Session_Tokens::get_instance( $this->user_id )->create( time() + HOUR_IN_SECONDS );
		$fired = array();
		add_action(
			'loggedin_destroy_all_sessions',
			static function ( $user_id ) use ( &$fired ) {
				$fired[] = $user_id;
			}
		);

		Plugin::instance()->session_store()->end_all( $this->user_id );

		$this->assertSame( array( $this->user_id ), $fired );
		$this->assertCount( 0, WP_Session_Tokens::get_instance( $this->user_id )->get_all() );
	}

	public function test_ending_your_own_sessions_can_keep_this_one(): void {
		$manager = WP_Session_Tokens::get_instance( $this->user_id );
		$current = $manager->create( time() + HOUR_IN_SECONDS );
		$manager->create( time() + HOUR_IN_SECONDS );

		wp_set_current_user( $this->user_id );
		$_COOKIE[ LOGGED_IN_COOKIE ] = wp_generate_auth_cookie( $this->user_id, time() + HOUR_IN_SECONDS, 'logged_in', $current );

		Plugin::instance()->session_store()->end_all( $this->user_id, true );

		unset( $_COOKIE[ LOGGED_IN_COOKIE ] );
		$this->assertNotNull( $manager->get( $current ) );
		$this->assertCount( 1, $manager->get_all() );
	}

	public function test_ending_every_session_can_keep_named_ones(): void {
		$manager = WP_Session_Tokens::get_instance( $this->user_id );
		$manager->create( time() + HOUR_IN_SECONDS );
		$kept  = $manager->create( time() + HOUR_IN_SECONDS );
		$store = Plugin::instance()->session_store();
		$fired = 0;
		add_action(
			'loggedin_destroy_all_sessions',
			static function () use ( &$fired ) {
				++$fired;
			}
		);

		$store->end_all( $this->user_id, false, array( $store->verifier( $kept ) ) );

		$this->assertNotNull( $manager->get( $kept ) );
		$this->assertCount( 1, $manager->get_all() );
		$this->assertSame( 1, $fired );
	}

	public function test_a_site_can_name_devices_itself(): void {
		add_filter(
			'loggedin_session_device',
			static function ( $name, $ua ) {
				return 'ua:' . $ua;
			},
			10,
			2
		);

		$this->assertSame( 'ua:Agent/1.0', Plugin::instance()->session_store()->device( 'Agent/1.0' ) );
	}
}
