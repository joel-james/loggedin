<?php
/**
 * Session guard — concurrent-login enforcement.
 *
 * Hooks into the WordPress auth pipeline at two points:
 *
 *   - `wp_authenticate_user` (early): used to reject a login outright
 *     when the configured logic is `block` and the user is already at
 *     their limit.
 *
 *   - `check_password` (later, after the user has been identified
 *     and their password verified): used to make room for the new
 *     session when the logic is `allow` (destroy every other
 *     session) or `logout_oldest` (destroy only the single oldest
 *     session).
 *
 * Both hooks consult {@see Setup\Settings} for the current limit and
 * mode, and both honour the `loggedin_bypass` filter so an addon can
 * exempt a specific user or role without forking this class.
 *
 * @package FoxeLabs\Loggedin\Front
 */

declare( strict_types = 1 );

namespace FoxeLabs\Loggedin\Front;

use FoxeLabs\Loggedin\Contracts\Singleton;
use FoxeLabs\Loggedin\Setup\Settings;
use WP_Error;
use WP_Session_Tokens;

defined( 'WPINC' ) || die;

/**
 * Concurrent-login enforcement at the wp-login pipeline.
 *
 * @since 3.0.0
 */
final class Session_Guard {

	use Singleton;

	/**
	 * Register hooks.
	 *
	 * Filter priorities are intentionally the WP default (10) — we
	 * don't need to outrun other filters on these hooks, but we do
	 * need to run *after* WordPress itself decides whether the
	 * password matches, so we can be sure the user identity is
	 * settled before we touch sessions.
	 *
	 * @since 3.0.0
	 */
	protected function init(): void {
		add_filter( 'wp_authenticate_user', array( $this, 'validate_block_logic' ), 10, 2 );
		add_filter( 'check_password', array( $this, 'validate_allow_logic' ), 10, 4 );
	}

	/**
	 * Enforce `allow` and `logout_oldest` modes after password check.
	 *
	 * `check_password` is a `bool` filter — returning `false` aborts
	 * the login. We never want to abort here (that's the job of the
	 * block-mode branch on `wp_authenticate_user`), so we always
	 * return `true`. The work in this method is destructive only:
	 * destroying existing sessions to make room for the one about
	 * to be created.
	 *
	 * @since 3.0.0
	 *
	 * @param bool   $check    Existing check state.
	 * @param string $password Plaintext password (unused).
	 * @param string $hash     Password hash (unused).
	 * @param int    $user_id  Identified user id.
	 *
	 * @return bool
	 */
	public function validate_allow_logic( $check, $password, $hash, $user_id ): bool {
		// If a prior filter has already rejected the login, leave
		// the rejection in place and bail.
		if ( ! $check ) {
			return false;
		}

		$logic = (string) Settings::instance()->get( 'logic', 'allow' );

		if (
			in_array( $logic, array( 'allow', 'logout_oldest' ), true ) &&
			$this->has_limit_reached( (int) $user_id )
		) {
			if ( 'allow' === $logic ) {
				$this->destroy_all_sessions( (int) $user_id );
			} else {
				$this->destroy_oldest_session( (int) $user_id );
			}
		}

		return true;
	}

	/**
	 * Enforce `block` mode before authentication completes.
	 *
	 * `wp_authenticate_user` runs before WordPress checks the password,
	 * so the password is checked here first. A wrong one passes
	 * through to WordPress's own "incorrect password" error: only the
	 * account's owner learns the limit was reached, and only they are
	 * offered the link to sign out the other devices.
	 *
	 * @since 3.0.0
	 * @since 3.4.0 Checks the password first and offers the unlock link.
	 *
	 * @param mixed  $user     WP_User on success, WP_Error on prior failure.
	 * @param string $password Password the visitor typed.
	 *
	 * @return mixed
	 */
	public function validate_block_logic( $user, $password = '' ) {
		// Pre-existing error from earlier in the auth pipeline —
		// propagate it untouched, don't replace it with ours.
		if ( is_wp_error( $user ) ) {
			return $user;
		}

		$logic = (string) Settings::instance()->get( 'logic', 'allow' );

		if ( 'block' !== $logic || ! $this->has_limit_reached( (int) $user->ID ) ) {
			return $user;
		}

		if ( ! wp_check_password( (string) $password, $user->user_pass, $user->ID ) ) {
			return $user;
		}

		/**
		 * Fires when a login is blocked by the concurrent-session limit.
		 *
		 * @since 2.0.0
		 *
		 * @param int $user_id User id whose login was blocked.
		 */
		do_action( 'loggedin_login_blocked', $user->ID );

		return new WP_Error(
			'login_limit_reached',
			$this->limit_error_message() . Unlock::instance()->offer( $user )
		);
	}

	/**
	 * Destroy every active session for the user.
	 *
	 * Delegates to {@see WP_Session_Tokens::destroy_all()} so the
	 * configured session storage (user-meta by default, but pluggable
	 * via the `session_token_manager` filter) handles the actual
	 * deletion.
	 *
	 * @since 3.0.0
	 *
	 * @param int $user_id User id.
	 *
	 * @return void
	 */
	protected function destroy_all_sessions( int $user_id ): void {
		WP_Session_Tokens::get_instance( $user_id )->destroy_all();

		/**
		 * Fires after every session is destroyed for a user.
		 *
		 * @since 2.0.0
		 *
		 * @param int $user_id User id whose sessions were cleared.
		 */
		do_action( 'loggedin_destroy_all_sessions', $user_id );
	}

	/**
	 * Destroy only the oldest active session for the user.
	 *
	 * Reads the raw `session_tokens` user meta directly because the
	 * `WP_Session_Tokens` API doesn't expose a "drop the oldest"
	 * primitive. This is documented as user-meta-storage only —
	 * sites using a custom storage backend (e.g. Redis) should
	 * configure the `allow` mode instead.
	 *
	 * @since 3.0.0
	 *
	 * @param int $user_id User id.
	 *
	 * @return void
	 */
	protected function destroy_oldest_session( int $user_id ): void {
		$sessions = get_user_meta( $user_id, 'session_tokens', true );

		if ( ! is_array( $sessions ) || empty( $sessions ) ) {
			return;
		}

		$oldest_token = '';
		$oldest_time  = PHP_INT_MAX;

		foreach ( $sessions as $token => $session ) {
			$login = isset( $session['login'] ) ? (int) $session['login'] : PHP_INT_MAX;
			if ( $login <= $oldest_time ) {
				$oldest_time  = $login;
				$oldest_token = $token;
			}
		}

		if ( '' !== $oldest_token ) {
			unset( $sessions[ $oldest_token ] );
			update_user_meta( $user_id, 'session_tokens', $sessions );

			/**
			 * Fires after the oldest session is destroyed for a user.
			 *
			 * @since 2.0.0
			 *
			 * @param int $user_id User id whose oldest session was cleared.
			 */
			do_action( 'loggedin_destroy_oldest_session', $user_id );
		}
	}

	/**
	 * Is this user already at the configured session limit?
	 *
	 * Returns `false` early when:
	 *   - The user id is empty (defensive guard — shouldn't happen
	 *     in the normal auth flow).
	 *   - The `loggedin_bypass` filter exempts the user (addons /
	 *     site-specific snippets use this for roles like `super_admin`).
	 *
	 * @since 3.0.0
	 *
	 * @param int $user_id User id.
	 *
	 * @return bool
	 */
	protected function has_limit_reached( int $user_id ): bool {
		if ( empty( $user_id ) || $this->is_bypassed( $user_id ) ) {
			return false;
		}

		$maximum = (int) Settings::instance()->get( 'maximum', 1 );
		$count   = count( WP_Session_Tokens::get_instance( $user_id )->get_all() );
		$reached = $count >= $maximum;

		/**
		 * Filter the limit-reached result.
		 *
		 * Lets addons override the comparison entirely — e.g. to
		 * exempt users with a specific meta flag while still
		 * counting their sessions for telemetry.
		 *
		 * @since 1.3.0
		 * @since 1.3.1 Added the `$count` parameter.
		 *
		 * @param bool $reached Whether the limit has been reached.
		 * @param int  $user_id User id.
		 * @param int  $count   Active session count.
		 */
		return (bool) apply_filters( 'loggedin_reached_limit', $reached, $user_id, $count );
	}

	/**
	 * Should this user bypass the limit entirely?
	 *
	 * @since 3.0.0
	 * @since 3.4.0 Administrators bypass it while `exempt_admins` is on.
	 *
	 * @param int $user_id User id.
	 *
	 * @return bool
	 */
	protected function is_bypassed( int $user_id ): bool {
		// "Don't limit administrators": anyone who can manage the site.
		$bypass = Settings::instance()->get( 'exempt_admins', false )
			&& user_can( $user_id, 'manage_options' );

		/**
		 * Filter to bypass the concurrent-session check for a user.
		 *
		 * @since 1.0.0
		 * @since 3.4.0 Defaults to true for administrators while the
		 *              "Don't limit administrators" setting is on.
		 *
		 * @param bool $bypass  Whether the user skips the limit.
		 * @param int  $user_id User id being checked.
		 */
		return (bool) apply_filters( 'loggedin_bypass', $bypass, $user_id );
	}

	/**
	 * Error shown to users blocked by the `block` mode.
	 *
	 * @since 3.0.0
	 *
	 * @return string
	 */
	protected function limit_error_message(): string {
		$message = __(
			"You've reached the maximum number of active logins for this account. Please log out from another device to continue.",
			'loggedin'
		);

		/**
		 * Filter the login-blocked error message.
		 *
		 * @since 1.0.0
		 *
		 * @param string $message The message shown on wp-login.
		 */
		return (string) apply_filters( 'loggedin_error_message', $message );
	}
}
