<?php
/**
 * Tests for the legacy force-logout link in {@see \FoxeLabs\Loggedin\Admin\Admin}.
 *
 * @package FoxeLabs\Loggedin\Tests\Admin
 */

declare( strict_types = 1 );

namespace FoxeLabs\Loggedin\Tests\Admin;

use FoxeLabs\Loggedin\Admin\Admin;
use WP_Session_Tokens;
use WP_UnitTestCase;
use WPDieException;


/**
 * @group admin
 */
class AdminTest extends WP_UnitTestCase {

	private int $user_id;

	public function set_up(): void {
		parent::set_up();

		$this->user_id = self::factory()->user->create();
		WP_Session_Tokens::get_instance( $this->user_id )->create( time() + HOUR_IN_SECONDS );
	}

	public function tear_down(): void {
		unset( $_REQUEST['loggedin_logout'], $_REQUEST['loggedin_user'], $_REQUEST['_wpnonce'] );

		parent::tear_down();
	}

	private function request_logout(): void {
		$_REQUEST['loggedin_logout'] = '1';
		$_REQUEST['loggedin_user']   = (string) $this->user_id;
		$_REQUEST['_wpnonce']        = wp_create_nonce( 'loggedin-options' );

		Admin::instance()->force_logout();
	}

	public function test_an_admin_signs_the_user_out(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$fired = 0;
		add_action(
			'loggedin_destroy_all_sessions',
			static function () use ( &$fired ): void {
				++$fired;
			}
		);

		$this->request_logout();

		$this->assertSame( array(), WP_Session_Tokens::get_instance( $this->user_id )->get_all() );
		$this->assertSame( 1, $fired );
	}

	public function test_a_user_who_cannot_manage_options_is_refused(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		try {
			$this->request_logout();
			$this->fail( 'Expected wp_die().' );
		} catch ( WPDieException $e ) {
			unset( $e );
		}

		$this->assertCount( 1, WP_Session_Tokens::get_instance( $this->user_id )->get_all() );
	}
}
