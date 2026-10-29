<?php
/**
 * Tests for {@see \FoxeLabs\Loggedin\Modules\PasswordSignout\PasswordSignout}.
 *
 * @package FoxeLabs\Loggedin\Tests\Modules\PasswordSignout
 */

declare( strict_types = 1 );

namespace FoxeLabs\Loggedin\Tests\Modules\PasswordSignout;

use FoxeLabs\Loggedin\Plugin;
use FoxeLabs\Loggedin\Sessions\SessionGuard;
use WP_Session_Tokens;
use WP_UnitTestCase;


/**
 * @group password-signout
 */
class PasswordSignoutTest extends WP_UnitTestCase {

	private int $user_id;

	public function set_up(): void {
		parent::set_up();

		$this->user_id = self::factory()->user->create();

		// Seed sessions past the limit: new sessions are limited as they're
		// created, and these tests drive the limit by hand.
		remove_filter( 'attach_session_information', array( SessionGuard::instance(), 'enforce_on_create' ) );

		$manager = WP_Session_Tokens::get_instance( $this->user_id );
		$manager->create( time() + HOUR_IN_SECONDS );
		$manager->create( time() + HOUR_IN_SECONDS );
	}

	public function tear_down(): void {
		delete_option( Plugin::OPTION_KEY );

		parent::tear_down();
	}

	private function sessions(): int {
		return count( WP_Session_Tokens::get_instance( $this->user_id )->get_all() );
	}

	public function test_a_password_reset_ends_every_session(): void {
		reset_password( get_user_by( 'id', $this->user_id ), 'new-password-1' );

		$this->assertSame( 0, $this->sessions() );
	}

	public function test_an_admin_changing_the_password_ends_every_session(): void {
		wp_update_user(
			array(
				'ID'        => $this->user_id,
				'user_pass' => 'new-password-2',
			)
		);

		$this->assertSame( 0, $this->sessions() );
	}

	public function test_a_profile_save_without_a_new_password_changes_nothing(): void {
		wp_update_user(
			array(
				'ID'           => $this->user_id,
				'display_name' => 'Renamed',
			)
		);

		$this->assertSame( 2, $this->sessions() );
	}

	public function test_nothing_happens_while_the_setting_is_off(): void {
		update_option( Plugin::OPTION_KEY, array( 'password_signout' => false ) );

		reset_password( get_user_by( 'id', $this->user_id ), 'new-password-3' );

		$this->assertSame( 2, $this->sessions() );
	}
}
