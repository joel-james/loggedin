<?php
/**
 * Idle-session tracking and enforcement.
 *
 * Ends a session that has gone untouched for longer than the
 * configured idle timeout. Enforcement is server-side — the browser
 * script is a courtesy, not the control — so disabling JavaScript
 * doesn't buy anyone an immortal session.
 *
 * Activity stamps live in their own user meta row — a
 * `{ verifier: timestamp }` map, one entry per device — and never
 * inside WordPress's `session_tokens` blob.
 *
 * That separation is deliberate and load-bearing. `session_tokens` holds
 * every session for a user in one serialised value, and both core's
 * `WP_User_Meta_Session_Tokens::update_session()` and the Active
 * Sessions add-on's per-session sign-out mutate it by reading the whole
 * blob, changing one key, and writing the whole blob back, with no
 * locking. Stamping activity in there would have turned a rare race into
 * a routine one: an admin signing a session out could have had it
 * resurrected by the same user's next activity write landing a
 * millisecond later. Writing our own row means the two never contend.
 *
 * The map is keyed by the session verifier — the SHA-256 of the raw
 * token, which is exactly what core hashes tokens to and what Active
 * Sessions exposes as `token_hash` — so a session row and its activity
 * stamp can be joined later without either side changing shape.
 *
 * Writes are throttled to at most one per quarter-timeout (and never
 * more than one a minute), so a busy site doesn't pay a user-meta write
 * on every page view. Entries older than the timeout are pruned on each
 * write: such a session can never be renewed (the next request from that
 * device signs it out), so an expired stamp has no reader left.
 *
 * Auto logout deliberately does no polling: the browser script runs a
 * local timer and only contacts the server when the user is genuinely
 * interacting and the stored stamp is about to go stale.
 *
 * @since 3.4.0
 * @package FoxeLabs\Loggedin\Modules\AutoLogout
 */

declare( strict_types = 1 );

namespace FoxeLabs\Loggedin\Modules\AutoLogout;

use FoxeLabs\Loggedin\Plugin;
use FoxeLabs\Loggedin\Sessions\SessionStore;
use WP_Error;
use WP_Session_Tokens;
use WP_User;

defined( 'WPINC' ) || die;

/**
 * Tracks activity and terminates idle sessions.
 *
 * @since 3.4.0
 */
final class IdleGuard {

	/**
	 * Query arg appended to the login URL after an idle logout.
	 *
	 * @since 3.4.0
	 */
	private const NOTICE_ARG = 'loggedin_auto_logout';

	/**
	 * AJAX action name for the activity ping.
	 *
	 * @since 3.4.0
	 */
	private const ACTION = 'loggedin_auto_logout_ping';

	/**
	 * Nonce action for the activity ping.
	 *
	 * @since 3.4.0
	 */
	private const NONCE = 'loggedin_auto_logout_ping';

	/**
	 * Script handle for the front-end idle timer.
	 *
	 * @since 3.4.0
	 */
	private const HANDLE = 'loggedin-idle';

	/**
	 * Shortest interval between activity writes, in seconds.
	 *
	 * @since 3.4.0
	 */
	private const MIN_WRITE_INTERVAL = MINUTE_IN_SECONDS;

	/**
	 * Auto logout settings.
	 *
	 * @since 3.4.0
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Session store, for the verifier of the current session.
	 *
	 * @since 3.4.0
	 *
	 * @var SessionStore
	 */
	private SessionStore $store;

	/**
	 * Set up the dependencies.
	 *
	 * @since 3.4.0
	 *
	 * @param Settings     $settings Auto logout settings.
	 * @param SessionStore $store    Session store.
	 */
	public function __construct( Settings $settings, SessionStore $store ) {
		$this->settings = $settings;
		$this->store    = $store;
	}

	/**
	 * Register hooks.
	 *
	 * Enforcement runs early on `init` so an expired session is closed
	 * before any of the page — admin screen or template — is built.
	 *
	 * @since 3.4.0
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'enforce' ), 5 );

		add_action( 'wp_ajax_' . self::ACTION, array( $this, 'handle_ping' ) );

		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );

		add_filter( 'login_message', array( $this, 'login_message' ) );
	}

	/**
	 * End the current session if it has been idle for too long.
	 *
	 * Runs on every request that carries a session cookie, including
	 * AJAX and REST, so a background tab can't keep a stale session
	 * alive. Application-password and cookie-less API requests have no
	 * session token and fall out at the token check.
	 *
	 * @since 3.4.0
	 *
	 * @return void
	 */
	public function enforce(): void {
		if ( ! is_user_logged_in() || $this->is_background_request() ) {
			return;
		}

		$user    = wp_get_current_user();
		$timeout = $this->settings->timeout_for( $user );

		if ( $timeout <= 0 ) {
			return;
		}

		$token = wp_get_session_token();

		if ( empty( $token ) ) {
			return;
		}

		$manager = WP_Session_Tokens::get_instance( $user->ID );
		$session = $manager->get( $token );

		if ( empty( $session ) ) {
			return;
		}

		$last_active = $this->last_active( $user->ID, $token, $session );
		$idle_for    = time() - $last_active;

		if ( $last_active > 0 && $idle_for >= $timeout ) {
			$this->log_out( $user, $timeout );

			return;
		}

		// Only a real page view counts as activity. Background XHR —
		// the heartbeat above all, which fires every 15-60s from an
		// unattended admin tab — would otherwise keep every session
		// alive forever. Genuine single-page activity comes in through
		// the explicit ping instead.
		if ( ! wp_doing_ajax() && ! wp_is_json_request() ) {
			$this->touch( $user->ID, $token, $timeout );
		}
	}

	/**
	 * AJAX handler for the browser's activity ping.
	 *
	 * The script sends this only while the user is actually
	 * interacting, and no more often than the write throttle allows —
	 * it exists so long-lived single-page screens (the block editor,
	 * a WooCommerce dashboard) keep the server-side stamp current
	 * without a full page load.
	 *
	 * @since 3.4.0
	 *
	 * @return void
	 */
	public function handle_ping(): void {
		check_ajax_referer( self::NONCE, 'nonce' );

		$user = wp_get_current_user();

		// `enforce()` has already run for this request and would have
		// logged the user out if the session were stale, so reaching
		// here means the session is live and the stamp can be renewed.
		$token = wp_get_session_token();

		if ( empty( $token ) ) {
			wp_send_json_error( array( 'active' => false ) );
		}

		$this->touch( $user->ID, $token, $this->settings->timeout_for( $user ), true );

		wp_send_json_success( array( 'active' => true ) );
	}

	/**
	 * Enqueue the idle timer on every authenticated page.
	 *
	 * No-op for logged-out visitors and for users whose resolved
	 * timeout is zero — there's nothing to count down.
	 *
	 * @since 3.4.0
	 *
	 * @return void
	 */
	public function enqueue(): void {
		if ( ! is_user_logged_in() ) {
			return;
		}

		$user    = wp_get_current_user();
		$timeout = $this->settings->timeout_for( $user );

		if ( $timeout <= 0 ) {
			return;
		}

		$asset = Plugin::asset( 'idle' );

		wp_enqueue_script( self::HANDLE, Plugin::url() . 'build/idle.js', $asset['dependencies'], $asset['version'], true );
		wp_localize_script( self::HANDLE, 'loggedinAutoLogout', $this->script_data( $user->ID, $timeout ) );

		if ( is_readable( Plugin::dir() . 'build/idle.css' ) ) {
			wp_enqueue_style( self::HANDLE, Plugin::url() . 'build/idle.css', array(), $asset['version'] );
			wp_style_add_data( self::HANDLE, 'rtl', 'replace' );
		}
	}

	/**
	 * Data the idle timer reads from `window.loggedinAutoLogout`.
	 *
	 * Seconds throughout; the script converts to milliseconds once.
	 *
	 * `lastActive` is the server's own stamp, which can be older than
	 * this page view: stamps are only rewritten every `pingAfter`
	 * seconds. The script counts from it, so it never lets the session
	 * outlive what the server will accept.
	 *
	 * @since 3.4.0
	 *
	 * @param int $user_id Current user id.
	 * @param int $timeout The current user's idle timeout, in seconds.
	 *
	 * @return array<string, mixed>
	 */
	private function script_data( int $user_id, int $timeout ): array {
		// The warning can't eat the whole timeout — a 60s warning on a
		// 1-minute timeout would open the dialog the instant the page
		// loads. Cap it at half the window.
		$warning = (int) min( $this->settings->all()['warning'], (int) floor( $timeout / 2 ) );

		return array(
			'timeout'    => $timeout,
			'warning'    => $warning,
			'pingAfter'  => $this->write_interval( $timeout ),
			'lastActive' => $this->last_active( $user_id, wp_get_session_token() ),
			'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
			'action'     => self::ACTION,
			'nonce'      => wp_create_nonce( self::NONCE ),
			'logoutUrl'  => wp_logout_url( $this->notice_url() ),
			'loginUrl'   => $this->notice_url(),
			'i18n'       => array(
				'title'    => __( 'Still there?', 'loggedin' ),
				/* translators: %s: countdown, e.g. "1:59". */
				'message'  => __( 'You have been inactive for a while. For your security you will be signed out in %s.', 'loggedin' ),
				'stay'     => __( 'Stay signed in', 'loggedin' ),
				'logout'   => __( 'Sign out now', 'loggedin' ),
				'expiring' => __( 'Signing you out…', 'loggedin' ),
			),
		);
	}

	/**
	 * Explain the sign-out on the login screen.
	 *
	 * @since 3.4.0
	 *
	 * @param string $message Existing login-screen message markup.
	 *
	 * @return string
	 */
	public function login_message( string $message ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only flag that only picks which notice to render.
		if ( empty( $_GET[ self::NOTICE_ARG ] ) ) {
			return $message;
		}

		return $message . '<p class="message">' . esc_html__(
			'You were signed out because your session was inactive.',
			'loggedin'
		) . '</p>';
	}

	/**
	 * Terminate the session and send the user to the login screen.
	 *
	 * `wp_logout()` destroys only the current session token, so other
	 * devices the user is signed in on are left alone.
	 *
	 * @since 3.4.0
	 *
	 * @param WP_User $user    User being signed out.
	 * @param int     $timeout Resolved timeout in seconds.
	 *
	 * @return void
	 */
	private function log_out( WP_User $user, int $timeout ): void {
		/**
		 * Fires just before an idle session is terminated.
		 *
		 * @since 3.4.0
		 *
		 * @param int $user_id User whose session is ending.
		 * @param int $timeout Resolved timeout in seconds.
		 */
		do_action( 'loggedin_auto_logout_expired', $user->ID, $timeout );

		wp_logout();

		// A REST request is refused with an error the admin apps
		// recognise, so they can send the user to the login screen
		// instead of showing a bare 401. AJAX callers get the failure
		// the now-missing cookie produces. Only page views redirect.
		if ( wp_is_json_request() ) {
			add_filter( 'rest_authentication_errors', array( $this, 'rest_error' ) );

			return;
		}

		if ( wp_doing_ajax() ) {
			return;
		}

		wp_safe_redirect( $this->notice_url() );
		exit;
	}

	/**
	 * Refuse the REST request whose session was just ended for idling.
	 *
	 * The error carries the login URL, so an admin app can send the
	 * user there (see `assets/src/admin/utils/api-init.js`).
	 *
	 * @since 3.4.0
	 *
	 * @param WP_Error|null|true $result Result of earlier authentication checks.
	 *
	 * @return WP_Error
	 */
	public function rest_error( $result ): WP_Error {
		unset( $result );

		return new WP_Error(
			'loggedin_idle_logout',
			__( 'You were signed out because your session was inactive.', 'loggedin' ),
			array(
				'status'    => 401,
				'login_url' => $this->notice_url(),
			)
		);
	}

	/**
	 * Renew the activity stamp on the current session.
	 *
	 * Throttled: a write only happens once the stamp is old enough to
	 * be worth refreshing, which keeps a busy site from paying a
	 * user-meta write per page view. `$force` bypasses the throttle for
	 * the explicit ping, which the script already rate-limits itself.
	 *
	 * @since 3.4.0
	 *
	 * @param int    $user_id User id.
	 * @param string $token   Raw session token from the auth cookie.
	 * @param int    $timeout Resolved timeout in seconds.
	 * @param bool   $force   Skip the throttle.
	 *
	 * @return void
	 */
	private function touch( int $user_id, string $token, int $timeout, bool $force = false ): void {
		if ( $timeout <= 0 ) {
			return;
		}

		// Reading `session_tokens` is fine — only writing it would
		// contend with the admin's sign-out. Skipping the write for a
		// session that no longer exists keeps stray entries out of the
		// map when a ping arrives just after the session was destroyed.
		if ( empty( WP_Session_Tokens::get_instance( $user_id )->get( $token ) ) ) {
			return;
		}

		$now      = time();
		$map      = $this->activity_map( $user_id );
		$verifier = $this->store->verifier( $token );
		$last     = (int) ( $map[ $verifier ] ?? 0 );

		if ( ! $force && ( $now - $last ) < $this->write_interval( $timeout ) ) {
			return;
		}

		$map[ $verifier ] = $now;

		update_user_meta(
			$user_id,
			Settings::ACTIVITY_META_KEY,
			$this->prune( $map, $verifier, $now - $timeout )
		);
	}

	/**
	 * When a session was last active, as far as the server knows.
	 *
	 * A session with no stamp yet counts from its login, or from when
	 * the timeout last changed if that is later. Login alone would sign
	 * out, the moment idle logout is turned on, every session older than
	 * the timeout, the admin who turned it on included. A session whose
	 * stamp was pruned still ages out: its window had already closed.
	 *
	 * @since 3.4.0
	 *
	 * @param int                       $user_id User id.
	 * @param string                    $token   Raw session token.
	 * @param array<string, mixed>|null $session The session, if already read.
	 *
	 * @return int Unix timestamp, or 0 for an unknown session.
	 */
	private function last_active( int $user_id, string $token, ?array $session = null ): int {
		$map      = $this->activity_map( $user_id );
		$verifier = $this->store->verifier( $token );

		if ( isset( $map[ $verifier ] ) ) {
			return (int) $map[ $verifier ];
		}

		$session = $session ?? WP_Session_Tokens::get_instance( $user_id )->get( $token );

		if ( empty( $session ) ) {
			return 0;
		}

		return max( (int) ( $session['login'] ?? 0 ), $this->settings->changed_at() );
	}

	/**
	 * Read the user's `{ verifier: timestamp }` activity map.
	 *
	 * Always returns an array — a clobbered meta row collapses to an
	 * empty map so callers can index it without a type guard.
	 *
	 * @since 3.4.0
	 *
	 * @param int $user_id User id.
	 *
	 * @return array<string, int>
	 */
	private function activity_map( int $user_id ): array {
		$stored = get_user_meta( $user_id, Settings::ACTIVITY_META_KEY, true );

		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * Drop stamps for the user's other devices that have gone stale.
	 *
	 * A stamp older than the timeout belongs to a session that is
	 * already over: the next request from that device signs it out, and
	 * until then the login-time fallback in {@see self::enforce()}
	 * reaches the same verdict. Keeping it would only grow the row.
	 *
	 * The current device is never pruned — it was just stamped.
	 *
	 * @since 3.4.0
	 *
	 * @param array<string, int> $map     Activity map.
	 * @param string             $keep    Verifier to preserve regardless of age.
	 * @param int                $cutoff  Drop stamps older than this timestamp.
	 *
	 * @return array<string, int>
	 */
	private function prune( array $map, string $keep, int $cutoff ): array {
		foreach ( $map as $verifier => $stamp ) {
			if ( $verifier !== $keep && (int) $stamp < $cutoff ) {
				unset( $map[ $verifier ] );
			}
		}

		return $map;
	}

	/**
	 * How often the activity stamp may be rewritten, in seconds.
	 *
	 * A quarter of the timeout keeps the stored value accurate to
	 * within 25% of the window while cutting writes to a handful per
	 * session; the one-minute floor stops a very short timeout from
	 * turning every request into a write.
	 *
	 * @since 3.4.0
	 *
	 * @param int $timeout Resolved timeout in seconds.
	 *
	 * @return int
	 */
	private function write_interval( int $timeout ): int {
		return (int) max( self::MIN_WRITE_INTERVAL, floor( $timeout / 4 ) );
	}

	/**
	 * Login screen URL carrying the "you were signed out" flag.
	 *
	 * Where every idle sign-out ends: the server's redirect, the script's
	 * logout link and the REST error all send the user here, so the
	 * login screen can explain what happened. The flag must be on the
	 * login URL itself: inside `redirect_to` the screen never sees it.
	 *
	 * @since 3.4.0
	 *
	 * @return string
	 */
	private function notice_url(): string {
		return add_query_arg( self::NOTICE_ARG, '1', wp_login_url() );
	}

	/**
	 * Whether this request is machinery rather than a person.
	 *
	 * Cron and WP-CLI run as a user on some setups; neither represents
	 * someone sitting at a browser, so they must not renew a stamp —
	 * and logging "them" out mid-run would be worse than useless.
	 *
	 * @since 3.4.0
	 *
	 * @return bool
	 */
	private function is_background_request(): bool {
		return wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI );
	}
}
