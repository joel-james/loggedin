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
 *   - `attach_session_information` (as a session is created): used to
 *     make room for the new session when the logic is `allow` (end
 *     every other session) or `logout_oldest` (end only the oldest).
 *     Every login that creates a session passes through it, including
 *     social login, magic link and popup login plugins that call
 *     `wp_set_auth_cookie()` directly. Block New can't refuse a login
 *     at this point, so those logins aren't limited under Block New.
 *
 * Room is made at the end of the request, and only if the new session
 * still exists then. 2FA plugins (Two Factor, WP 2FA) let WordPress
 * create a session after the password, then destroy it and ask for the
 * second factor: someone with only the password must not be able to
 * sign the account out of its devices.
 *
 * Before 3.4.0 room was made on `check_password`. That filter runs on
 * every password check, not only on logins: WooCommerce's "current
 * password" field, application passwords before WordPress 6.8, and a
 * 2FA plugin's first step all ended sessions with no new one to follow.
 *
 * Both consult {@see Settings\Settings} for the current limit and
 * mode, and both honour the `loggedin_bypass` filter so an addon can
 * exempt a specific user or role without forking this class.
 *
 * @since 3.0.0
 * @package FoxeLabs\Loggedin\Sessions
 */

declare( strict_types = 1 );

namespace FoxeLabs\Loggedin\Sessions;

use FoxeLabs\Loggedin\Settings\Settings;
use WP_Error;
use WP_Session_Tokens;
use WP_User;

defined( 'WPINC' ) || die;

/**
 * Concurrent-login enforcement at the wp-login pipeline.
 *
 * @since 3.0.0
 */
final class SessionGuard {

	/**
	 * Error code of a login refused by Block New.
	 *
	 * @since 3.4.0
	 */
	public const ERROR_CODE = 'login_limit_reached';

	/**
	 * Settings store.
	 *
	 * @since 3.4.0
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Session store.
	 *
	 * @since 3.4.0
	 *
	 * @var SessionStore
	 */
	private SessionStore $store;

	/**
	 * Users given a session in this request, with the verifiers of the
	 * sessions they had before it.
	 *
	 * @since 3.4.0
	 *
	 * @var array<int, array<int, string>>
	 */
	private array $pending = array();

	/**
	 * The Block New message shown in this request, if a login was refused.
	 *
	 * @since 3.4.0
	 *
	 * @var string
	 */
	private string $blocked_message = '';

	/**
	 * Set up the dependencies.
	 *
	 * @since 3.4.0
	 *
	 * @param Settings     $settings Settings store.
	 * @param SessionStore $store    Session store.
	 */
	public function __construct( Settings $settings, SessionStore $store ) {
		$this->settings = $settings;
		$this->store    = $store;
	}

	/**
	 * Register hooks.
	 *
	 * Default priorities: neither filter needs to run before others.
	 *
	 * @since 3.0.0
	 * @since 3.4.0 No longer hooks `check_password`; see the file header.
	 *              Keeps the Block New message visible through Ultimate
	 *              Member and plugins that rewrite login errors.
	 */
	public function register(): void {
		add_filter( 'wp_authenticate_user', array( $this, 'validate_block_logic' ), 10, 2 );
		add_filter( 'attach_session_information', array( $this, 'enforce_on_create' ), 10, 2 );
		add_filter( 'login_errors', array( $this, 'keep_blocked_message' ), 99 );
		add_filter( 'um_custom_authenticate_error_codes', array( $this, 'add_error_code' ) );
	}

	/**
	 * Note a session being created, to make room for it later.
	 *
	 * Runs for every new session, before it is stored. Notes which
	 * sessions the user had, and {@see self::make_room_for_new_sessions()}
	 * makes room among those at the end of the request.
	 *
	 * With a custom session backend the sessions can't be listed, so
	 * room is made at once instead.
	 *
	 * @since 3.4.0
	 *
	 * @param array<string, mixed> $session Session data being stored. Returned unchanged.
	 * @param int                  $user_id User the session belongs to.
	 *
	 * @return array<string, mixed>
	 */
	public function enforce_on_create( $session, $user_id ): array {
		$user_id = (int) $user_id;

		if ( ! $this->store->is_default_storage( $user_id ) ) {
			$this->make_room( $user_id );

			return (array) $session;
		}

		if ( ! isset( $this->pending[ $user_id ] ) ) {
			$this->pending[ $user_id ] = $this->store->verifiers( $user_id );

			add_action( 'shutdown', array( $this, 'make_room_for_new_sessions' ) );
		}

		return (array) $session;
	}

	/**
	 * Make room for the sessions created in this request.
	 *
	 * Skips a user whose new session no longer exists: a 2FA plugin
	 * destroyed it to ask for the second factor, or the user was signed
	 * out again. Also skips sessions an administrator opened as the
	 * user, which aren't the user signing in.
	 *
	 * @since 3.4.0
	 *
	 * @return void
	 */
	public function make_room_for_new_sessions(): void {
		$pending       = $this->pending;
		$this->pending = array();

		foreach ( $pending as $user_id => $before ) {
			$sessions = $this->store->all( $user_id );
			$new      = array_diff_key( $sessions, array_flip( $before ) );

			if ( empty( $new ) || $this->all_switched( $new ) ) {
				continue;
			}

			$this->make_room( $user_id, array_map( 'strval', array_keys( array_diff_key( $sessions, $new ) ) ) );
		}
	}

	/**
	 * Whether every one of these sessions was opened by switching user.
	 *
	 * User Switching marks the session it creates for the user switched
	 * to with `switched_from_id`. Treating it as a login would sign the
	 * user out of their own devices whenever support looked at their
	 * account.
	 *
	 * @since 3.4.0
	 *
	 * @param array<string, array<string, mixed>> $sessions Sessions by verifier.
	 *
	 * @return bool
	 */
	private function all_switched( array $sessions ): bool {
		foreach ( $sessions as $session ) {
			if ( empty( $session['switched_from_id'] ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Enforce `allow` and `logout_oldest` modes after a password check.
	 *
	 * No longer hooked: {@see self::enforce_on_create()} makes room as
	 * the session is created instead. Kept, with its `check_password`
	 * signature, for code that calls it directly.
	 *
	 * @since 3.0.0
	 * @since 3.4.0 Not hooked to `check_password`.
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
	 * @param int                     $user_id User getting a new session.
	 * @param array<int, string>|null $others  Verifiers of the sessions that
	 *                                         count against the limit, when
	 *                                         the new one is already stored.
	 *                                         Null: every session counts.
	 *
	 * @return void
	 */
	protected function make_room( int $user_id, ?array $others = null ): void {
		$logic = (string) $this->settings->get( 'logic', 'allow' );

		if (
			! in_array( $logic, array( 'allow', 'logout_oldest' ), true ) ||
			! $this->has_limit_reached( $user_id, null === $others ? null : count( $others ) )
		) {
			return;
		}

		if ( 'allow' === $logic ) {
			$this->destroy_all_sessions( $user_id, $others );

			return;
		}

		// One at a time until there's room: the limit may have been
		// lowered since these sessions began. Stops when nothing more
		// can be ended, so a filter that always reports the limit as
		// reached can't loop forever.
		do {
			$ended = $this->destroy_oldest_session( $user_id, $others );
			$left  = null;

			if ( null !== $others ) {
				$others = array_values( array_diff( $others, array( $ended ) ) );
				$left   = count( $others );
			}
		} while ( '' !== $ended && $this->has_limit_reached( $user_id, $left ) );
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

		$logic = (string) $this->settings->get( 'logic', 'allow' );

		if ( 'block' !== $logic || ! $this->has_limit_reached( (int) $user->ID ) ) {
			return $user;
		}

		// WordPress never gets here without a password. An empty one
		// means a plugin signed the user in some other way (Nextend
		// Social Login's "Login restriction") and asks whether it may.
		if ( '' !== (string) $password && ! wp_check_password( (string) $password, $user->user_pass, $user->ID ) ) {
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

		$this->blocked_message = $this->limit_error_message( $user );

		return new WP_Error( self::ERROR_CODE, $this->blocked_message );
	}

	/**
	 * Show the Block New message on the login screen, if a plugin hid it.
	 *
	 * Security plugins such as Limit Login Attempts Reloaded replace
	 * every login error with "Incorrect username or password", which
	 * hides the limit and the unlock link. The message only appears
	 * after the right password, so it tells nobody which accounts exist.
	 *
	 * @since 3.4.0
	 *
	 * @param string $errors Login errors HTML.
	 *
	 * @return string
	 */
	public function keep_blocked_message( $errors ): string {
		$errors = (string) $errors;

		if ( '' === $this->blocked_message || false !== strpos( $errors, $this->blocked_message ) ) {
			return $errors;
		}

		return $this->blocked_message;
	}

	/**
	 * Let Ultimate Member show the Block New message.
	 *
	 * Ultimate Member's login form shows "Password is incorrect" for any
	 * error code it doesn't know.
	 *
	 * @since 3.4.0
	 *
	 * @param mixed $codes Error codes Ultimate Member shows as they are.
	 *
	 * @return array<int, string>
	 */
	public function add_error_code( $codes ): array {
		$codes   = (array) $codes;
		$codes[] = self::ERROR_CODE;

		return $codes;
	}

	/**
	 * Destroy every active session for the user.
	 *
	 * Notes the sessions for {@see self::displacing()} first, then ends
	 * them through {@see SessionStore::end_all()}, which works with any
	 * storage backend.
	 *
	 * @since 3.0.0
	 * @since 3.4.0 Added the `$others` parameter.
	 *
	 * @param int                     $user_id User id.
	 * @param array<int, string>|null $others  Verifiers to end, keeping the
	 *                                         rest. Null: end every session.
	 *
	 * @return void
	 */
	protected function destroy_all_sessions( int $user_id, ?array $others = null ): void {
		if ( null === $others ) {
			$this->displacing( $user_id, $this->store->verifiers( $user_id ) );
			$this->store->end_all( $user_id );

			return;
		}

		$this->displacing( $user_id, $others );
		$this->store->end_all( $user_id, false, array_values( array_diff( $this->store->verifiers( $user_id ), $others ) ) );
	}

	/**
	 * Destroy only the oldest active session for the user.
	 *
	 * The `WP_Session_Tokens` API has no "drop the oldest" primitive,
	 * so this goes through {@see SessionStore}, which needs WordPress's
	 * default session storage (user meta). Sites using a custom backend
	 * (e.g. Redis) should configure the `allow` mode instead.
	 *
	 * @since 3.0.0
	 * @since 3.4.0 Goes through SessionStore, skips expired sessions,
	 *              picks only among `$among` and returns the verifier
	 *              of the session it ended.
	 *
	 * @param int                     $user_id User id.
	 * @param array<int, string>|null $among   Verifiers to pick from.
	 *                                         Null: any session.
	 *
	 * @return string Verifier of the session ended, or '' if none was.
	 */
	protected function destroy_oldest_session( int $user_id, ?array $among = null ): string {
		$store = $this->store;

		if ( null === $among ) {
			$oldest = $store->oldest( $user_id );
		} else {
			// `all()` is newest first.
			$candidates = array_intersect( array_keys( $store->all( $user_id ) ), $among );
			$oldest     = (string) end( $candidates );
		}

		if ( '' === $oldest ) {
			return '';
		}

		$this->displacing( $user_id, array( $oldest ) );

		if ( ! $store->destroy( $user_id, array( $oldest ) ) ) {
			return '';
		}

		/**
		 * Fires after the oldest session is destroyed for a user.
		 *
		 * @since 2.0.0
		 *
		 * @param int $user_id User id whose oldest session was cleared.
		 */
		do_action( 'loggedin_destroy_oldest_session', $user_id );

		return $oldest;
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
	 * @since 3.4.0 Added the `$count` parameter.
	 *
	 * @param int      $user_id User id.
	 * @param int|null $count   Sessions that count against the limit.
	 *                          Null: count every live session.
	 *
	 * @return bool
	 *
	 * @phpstan-impure
	 */
	protected function has_limit_reached( int $user_id, ?int $count = null ): bool {
		if ( empty( $user_id ) || $this->is_bypassed( $user_id ) ) {
			return false;
		}

		$maximum = (int) $this->settings->get( 'maximum', 1 );
		$count   = $count ?? count( WP_Session_Tokens::get_instance( $user_id )->get_all() );
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
		$bypass = $this->settings->get( 'exempt_admins', false )
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
	 * Announce sessions a new login is about to end.
	 *
	 * Fires before they end, while their verifiers still exist, so a
	 * listener can remember them. The Lockout module uses it to tell
	 * those devices why they were signed out.
	 *
	 * @since 3.4.0
	 *
	 * @param int           $user_id   User id.
	 * @param array<string> $verifiers Verifiers of the sessions about to end.
	 *
	 * @return void
	 */
	protected function displacing( int $user_id, array $verifiers ): void {
		if ( empty( $verifiers ) ) {
			return;
		}

		/**
		 * Fires before a new login ends a user's other sessions.
		 *
		 * Covers Logout All and Logout Oldest, on the login form and on
		 * logins that skip it.
		 *
		 * @since 3.4.0
		 *
		 * @param int           $user_id   User whose sessions are ending.
		 * @param array<string> $verifiers Verifiers (hashed tokens) of those sessions.
		 */
		do_action( 'loggedin_sessions_displacing', $user_id, $verifiers );
	}

	/**
	 * Error shown to users blocked by the `block` mode.
	 *
	 * The message set on the Lockout & recovery page, else the default.
	 * The `loggedin_error_message` filter has the last word.
	 *
	 * @since 3.0.0
	 * @since 3.4.0 Uses the `message` setting, and passes the user to the filter.
	 *
	 * @param WP_User $user User whose login was refused.
	 *
	 * @return string
	 */
	protected function limit_error_message( WP_User $user ): string {
		$message = (string) $this->settings->get( 'message', '' );

		if ( '' === trim( $message ) ) {
			$message = self::default_message();
		}

		/**
		 * Filter the login-blocked error message.
		 *
		 * The Lockout module appends its self-unlock link here, at
		 * priority 100.
		 *
		 * @since 1.0.0
		 * @since 3.4.0 Added the `$user` parameter.
		 *
		 * @param string   $message The message shown on wp-login. HTML.
		 * @param WP_User $user    User whose login was refused.
		 */
		return (string) apply_filters( 'loggedin_error_message', $message, $user );
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
