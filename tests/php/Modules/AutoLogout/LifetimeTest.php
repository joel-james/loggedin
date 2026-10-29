<?php
/**
 * Cookie-lifetime tests for auto logout.
 *
 * Covers the `auth_cookie_expiration` override: each duration is
 * independent, zero means "leave WordPress alone", and the filter is
 * actually wired at a priority that beats the default.
 *
 * @package FoxeLabs\Loggedin\Tests\Modules\AutoLogout
 */

declare( strict_types = 1 );

namespace FoxeLabs\Loggedin\Tests\Modules\AutoLogout;

use FoxeLabs\Loggedin\Modules\AutoLogout\Lifetime;
use FoxeLabs\Loggedin\Modules\AutoLogout\Settings;
use WP_UnitTestCase;


/**
 * @group lifetime
 */
class LifetimeTest extends WP_UnitTestCase {

	public function tear_down(): void {
		delete_option( Settings::OPTION_KEY );
		remove_all_filters( 'auth_cookie_expiration' );

		parent::tear_down();
	}

	public function test_unset_lifetimes_pass_the_wordpress_value_through(): void {
		$this->assertSame( 172800, Lifetime::instance()->expiration( 172800, 1, false ) );
		$this->assertSame( 1209600, Lifetime::instance()->expiration( 1209600, 1, true ) );
	}

	public function test_session_lifetime_is_applied_in_hours(): void {
		update_option( Settings::OPTION_KEY, array( 'session_lifetime' => 8 ) );

		$this->assertSame( 8 * HOUR_IN_SECONDS, Lifetime::instance()->expiration( 172800, 1, false ) );
	}

	public function test_remember_lifetime_is_applied_in_days(): void {
		update_option( Settings::OPTION_KEY, array( 'remember_lifetime' => 3 ) );

		$this->assertSame( 3 * DAY_IN_SECONDS, Lifetime::instance()->expiration( 1209600, 1, true ) );
	}

	public function test_each_duration_is_independent(): void {
		update_option( Settings::OPTION_KEY, array( 'session_lifetime' => 4 ) );

		// Remember-me was left at zero, so it keeps the core value even
		// though the normal duration was overridden.
		$this->assertSame( 4 * HOUR_IN_SECONDS, Lifetime::instance()->expiration( 172800, 1, false ) );
		$this->assertSame( 1209600, Lifetime::instance()->expiration( 1209600, 1, true ) );
	}

	public function test_register_hooks_the_expiration_filter(): void {
		Lifetime::instance();

		$this->assertSame(
			20,
			has_filter( 'auth_cookie_expiration', array( Lifetime::instance(), 'expiration' ) )
		);
	}

	public function test_the_hooked_filter_overrides_an_earlier_one(): void {
		update_option( Settings::OPTION_KEY, array( 'session_lifetime' => 2 ) );

		add_filter( 'auth_cookie_expiration', static fn() => 99, 10 );
		Lifetime::instance();

		$this->assertSame(
			2 * HOUR_IN_SECONDS,
			(int) apply_filters( 'auth_cookie_expiration', 172800, 1, false )
		);
	}
}
