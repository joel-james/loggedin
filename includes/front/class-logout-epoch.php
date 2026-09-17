<?php
/**
 * Logout epoch — site-wide "log everyone out" support.
 *
 * Instead of iterating every user and destroying their sessions (which
 * doesn't scale past a few thousand users), a single option stores the
 * timestamp of the last "log out everyone" request. On each request we
 * compare the current session's `login` time against that epoch: any
 * session created before it is destroyed on its next request and the
 * visitor is treated as logged out immediately.
 *
 * Cost profile:
 *   - Triggering: one autoloaded option write. O(1) at any user count.
 *   - Per request: one option read (autoloaded, so free) and — only for
 *     logged-in visitors while an epoch is set — one session lookup that
 *     WordPress performs anyway later in the request.
 *
 * Stale `session_tokens` meta rows are cleaned lazily: each is deleted
 * the next time its owner shows up, and rows for users who never return
 * expire with the tokens themselves. No sweep cron is needed for
 * correctness because the epoch check is what decides validity.
 *
 * Works with custom session storage backends too — everything goes
 * through the `WP_Session_Tokens` API, never the raw meta row.
 *
 * @package FoxeLabs\Loggedin\Front
 */

declare( strict_types = 1 );

namespace FoxeLabs\Loggedin\Front;

use FoxeLabs\Loggedin\Contracts\Singleton;
use WP_Session_Tokens;

defined( 'WPINC' ) || die;

/**
 * Site-wide logout via a session-validity epoch.
 *
 * @since 3.3.0
 */
final class Logout_Epoch {

	use Singleton;

	/**
	 * Option storing the unix timestamp sessions must be newer than.
	 *
	 * Autoloaded intentionally — it is read on every logged-in request,
	 * so a non-autoloaded option would cost an extra query per request.
	 *
	 * @since 3.3.0
	 */
	public const OPTION = 'loggedin_logout_all_before';

	/**
	 * Register hooks.
	 *
	 * Priority 30 on `determine_current_user` so we run after core's
	 * cookie validation (priority 10-20) has settled on a user id, but
	 * before most plugins consume the result.
	 *
	 * @since 3.3.0
	 */
	protected function init(): void {
		add_filter( 'determine_current_user', array( $this, 'invalidate_stale_session' ), 30 );
	}

	/**
	 * Log out sessions created before the stored epoch.
	 *
	 * Runs as a `determine_current_user` filter so a stale session is
	 * rejected on the very request that carries it — the visitor doesn't
	 * get one last authenticated page view.
	 *
	 * Requests authenticated without a session token (application
	 * passwords, REST nonce-less auth plugins) pass through untouched:
	 * they have no `login` time to compare and were never part of the
	 * cookie-session population being logged out.
	 *
	 * @since 3.3.0
	 *
	 * @param int|false $user_id User id resolved by earlier filters.
	 *
	 * @return int|false
	 */
	public function invalidate_stale_session( $user_id ) {
		if ( empty( $user_id ) ) {
			return $user_id;
		}

		$epoch = (int) get_option( self::OPTION, 0 );

		if ( 0 === $epoch ) {
			return $user_id;
		}

		$token = (string) wp_get_session_token();

		if ( '' === $token ) {
			return $user_id;
		}

		$manager = WP_Session_Tokens::get_instance( (int) $user_id );
		$session = $manager->get( $token );

		if ( ! is_array( $session ) ) {
			// Token no longer maps to a session — core will reject the
			// cookie on its own; nothing for us to decide.
			return $user_id;
		}

		$login = isset( $session['login'] ) ? (int) $session['login'] : 0;

		// Strictly older than the epoch. A session written in the same
		// second as the trigger (including the triggering admin's own,
		// re-stamped by `logout_all()`) survives.
		if ( $login >= $epoch ) {
			return $user_id;
		}

		$manager->destroy( $token );

		// Stop the browser re-sending the now-dead cookies. Guarded for
		// the rare late-`determine_current_user` call after output began.
		if ( ! headers_sent() ) {
			wp_clear_auth_cookie();
		}

		/**
		 * Fires after a session is invalidated by the logout epoch.
		 *
		 * @since 3.3.0
		 *
		 * @param int    $user_id User id whose session was invalidated.
		 * @param string $token   Hashed token of the destroyed session.
		 */
		do_action( 'loggedin_session_invalidated', (int) $user_id, $token );

		return false;
	}

	/**
	 * Log out every user on the site.
	 *
	 * Bumps the epoch to now. Every session created before this moment
	 * is destroyed on its owner's next request.
	 *
	 * @since 3.3.0
	 *
	 * @param bool $keep_current Keep the calling user's own session
	 *                           alive (default). Pass `false` to log
	 *                           the caller out too — the CLI does this
	 *                           since it has no session anyway.
	 *
	 * @return int The epoch timestamp that was stored.
	 */
	public static function logout_all( bool $keep_current = true ): int {
		$epoch = time();

		update_option( self::OPTION, $epoch, true );

		if ( $keep_current ) {
			self::preserve_current_session( $epoch );
		}

		/**
		 * Fires after a site-wide logout has been triggered.
		 *
		 * Add-ons (Active Sessions, Real-time Logout) should treat this
		 * as "every session older than `$epoch` is now dead" rather
		 * than expecting per-user `loggedin_destroy_all_sessions`
		 * events — those never fire for a bulk logout.
		 *
		 * @since 3.3.0
		 *
		 * @param int $epoch Timestamp sessions must be newer than.
		 */
		do_action( 'loggedin_logout_all_users', $epoch );

		return $epoch;
	}

	/**
	 * Re-stamp the caller's session so it survives the epoch.
	 *
	 * Sets the session's `login` time to the epoch itself; the validity
	 * check uses a strict `<`, so an exact match passes. This is a
	 * one-off write to the session store triggered by an explicit admin
	 * action — not a per-request write.
	 *
	 * @since 3.3.0
	 *
	 * @param int $epoch Epoch timestamp just stored.
	 *
	 * @return void
	 */
	private static function preserve_current_session( int $epoch ): void {
		$user_id = get_current_user_id();

		if ( 0 === $user_id ) {
			return;
		}

		$token = (string) wp_get_session_token();

		if ( '' === $token ) {
			return;
		}

		$manager = WP_Session_Tokens::get_instance( $user_id );
		$session = $manager->get( $token );

		if ( ! is_array( $session ) ) {
			return;
		}

		$session['login'] = $epoch;
		$manager->update( $token, $session );
	}
}
