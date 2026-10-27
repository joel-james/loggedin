<?php
/**
 * Auth-cookie lifetime control.
 *
 * WordPress hard-codes how long a login lasts — two days normally,
 * fourteen with "Remember Me" — and the only supported way to change
 * that is the `auth_cookie_expiration` filter. This module puts both
 * durations behind a setting instead.
 *
 * This is the absolute half of auto logout: the idle timeout ends a
 * session that stops being used, the lifetime ends it regardless of how
 * active it was. Sites with a compliance requirement generally need
 * both.
 *
 * @package FoxeLabs\Loggedin\Auto_Logout
 */

declare( strict_types = 1 );

namespace FoxeLabs\Loggedin\Auto_Logout;

use FoxeLabs\Loggedin\Contracts\Singleton;

defined( 'WPINC' ) || die;

/**
 * Overrides the WordPress auth-cookie durations.
 *
 * @since 3.4.0
 */
final class Lifetime {

	use Singleton;

	/**
	 * Register hooks.
	 *
	 * Priority 20 so a site that sets its own duration at the default
	 * priority still gets overridden by the configured value — an
	 * explicit setting in the UI should beat a stray snippet.
	 *
	 * @since 3.4.0
	 *
	 * @return void
	 */
	protected function init(): void {
		add_filter( 'auth_cookie_expiration', array( $this, 'expiration' ), 20, 3 );
	}

	/**
	 * Return the configured session length, in seconds.
	 *
	 * Either setting left at zero means "leave this one alone", so a
	 * site can shorten remembered logins without touching normal ones.
	 *
	 * @since 3.4.0
	 *
	 * @param int  $length   Duration WordPress (or an earlier filter) chose.
	 * @param int  $user_id  User the cookie is for. Unused.
	 * @param bool $remember Whether "Remember Me" was ticked.
	 *
	 * @return int
	 */
	public function expiration( $length, $user_id, $remember ): int {
		$settings = Settings::instance()->all();

		if ( $remember ) {
			return $settings['remember_lifetime'] > 0
				? $settings['remember_lifetime'] * DAY_IN_SECONDS
				: (int) $length;
		}

		return $settings['session_lifetime'] > 0
			? $settings['session_lifetime'] * HOUR_IN_SECONDS
			: (int) $length;
	}
}
