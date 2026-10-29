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
 * A third point covers logins that never pass through `authenticate`:
 *
 *   - `attach_session_information` (as a session is created): social
 *     login, magic link and popup login plugins call
 *     `wp_set_auth_cookie()` directly, so neither check above runs.
 *     Logout Oldest and Logout All are enforced here too. Block New
 *     can't refuse a login at this point, so those logins aren't
 *     limited under Block New.
 *
 * All three consult {@see Setup\Settings} for the current limit and
 * mode, and all honour the `loggedin_bypass` filter so an addon can
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
		add_filter( 'attach_session_information', array( $this, 'enforce_on_create' ), 10, 2 );
	}

	/**
	 * Make room as a session is created, for logins that skip `authenticate`.
	 *
	 * Runs for every new session. A login through the login form
	 * already made room in {@see self::validate_allow_logic()}, so the
	 * user is normally under the limit by now and nothing more ends.
	 *
	 * @since 3.4.0
	 *
	 * @param array $session Session data being stored. Returned unchanged.
	 * @param int   $user_id User the session belongs to.
	 *
	 * @return array
	 */
	public function enforce_on_create( $session, $user_id ): array {
		$this->make_room( (int) $user_id );

		return (array) $session;
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

		$this->make_room( (int) $user_id );

		return true;
	}

	/**
	 * Under Logout All or Logout Oldest, end sessions to fit the new one.
	 *
	 * @since 3.4.0
	 *
	 * @param int $user_id User about to get a new session.
	 *
	 * @return void
	 */
	protected function make_room( int $user_id ): void {
		$logic = (string) Settings::instance()->get( 'logic', 'allow' );

		if (
			! in_array( $logic, array( 'allow', 'logout_oldest' ), true ) ||
			! $this->has_limit_reached( $user_id )
		) {
			return;
		}

		if ( 'allow' === $logic ) {
			$this->destroy_all_sessions( $user_id );

			return;
		}

		// One at a time until there's room: the limit may have been
		// lowered since these sessions began. Stops when nothing more
		// can be ended, so a filter that always reports the limit as
		// reached can't loop forever.
		do {
			$ended = $this->destroy_oldest_session( $user_id );
		} while ( $ended && $this->has_limit_reached( $user_id ) );
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
		Displaced::instance()->record( $user_id, Session_Store::instance()->verifiers( $user_id ) );

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
	 * The `WP_Session_Tokens` API has no "drop the oldest" primitive,
	 * so this goes through {@see Session_Store}, which needs WordPress's
	 * default session storage (user meta). Sites using a custom backend
	 * (e.g. Redis) should configure the `allow` mode instead.
	 *
	 * @since 3.0.0
	 * @since 3.4.0 Goes through Session_Store, skips expired sessions and
	 *              reports whether one ended.
	 *
	 * @param int $user_id User id.
	 *
	 * @return bool Whether a session ended.
	 */
	protected function destroy_oldest_session( int $user_id ): bool {
		$store  = Session_Store::instance();
		$oldest = $store->oldest( $user_id );

		if ( '' === $oldest ) {
			return false;
		}

		Displaced::instance()->record( $user_id, array( $oldest ) );

		if ( ! $store->destroy( $user_id, array( $oldest ) ) ) {
			return false;
		}

		/**
		 * Fires after the oldest session is destroyed for a user.
		 *
		 * @since 2.0.0
		 *
		 * @param int $user_id User id whose oldest session was cleared.
		 */
		do_action( 'loggedin_destroy_oldest_session', $user_id );

		return true;
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
	 * The message set on the Lockout & recovery page, else the default.
	 * The `loggedin_error_message` filter has the last word.
	 *
	 * @since 3.0.0
	 * @since 3.4.0 Uses the `message` setting.
	 *
	 * @return string
	 */
	protected function limit_error_message(): string {
		$message = (string) Settings::instance()->get( 'message', '' );

		if ( '' === trim( $message ) ) {
			$message = self::default_message();
		}

		/**
		 * Filter the login-blocked error message.
		 *
		 * @since 1.0.0
		 *
		 * @param string $message The message shown on wp-login.
		 */
		return (string) apply_filters( 'loggedin_error_message', $message );
	}

	/**
	 * The built-in blocked-login message.
	 *
	 * @since 3.4.0
	 *
	 * @return string
	 */
	public static function default_message(): string {
		return __(
			"You've reached the maximum number of active logins for this account. Please log out from another device to continue.",
			'loggedin'
		);
	}
}
