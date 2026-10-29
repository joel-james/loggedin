<?php
/**
 * Tell people why they were signed out.
 *
 * Under Logout Oldest and Logout All, a new login ends older sessions.
 * The person on the older device used to find themselves signed out
 * with no reason given. Now:
 *
 *   1. When Loggedin ends a session to make room, it notes the
 *      session's verifier in the user's `loggedin_displaced` meta.
 *   2. The next request from that device carries a cookie whose
 *      session is gone, and WordPress fires
 *      `auth_cookie_bad_session_token`. If the session is one we
 *      noted, a short-lived marker cookie goes on the device.
 *   3. The login screen sees the marker and explains. With a redirect
 *      set, a front-end page view sends the device there instead.
 *
 * Matching the exact session means a sign-out for any other reason
 * (logging out, a password change, an admin's force logout) never
 * shows this message.
 *
 * @package FoxeLabs\Loggedin\Modules\Lockout
 */

declare( strict_types = 1 );

namespace FoxeLabs\Loggedin\Modules\Lockout;

use FoxeLabs\Loggedin\Sessions\SessionStore;
use FoxeLabs\Loggedin\Settings\Settings;

defined( 'WPINC' ) || die;

/**
 * Explains a sign-out caused by a newer login.
 *
 * @since 3.4.0
 */
final class Displaced {

	/**
	 * User meta: verifiers of sessions ended by a newer login, with when.
	 *
	 * @since 3.4.0
	 */
	public const META_KEY = 'loggedin_displaced';

	/**
	 * Cookie marking a device whose session a newer login ended.
	 *
	 * @since 3.4.0
	 */
	private const COOKIE = 'loggedin_displaced';

	/**
	 * How long a noted session is remembered: as long as a "Remember
	 * Me" cookie lasts, so a device that comes back days later is still
	 * told why.
	 *
	 * @since 3.4.0
	 */
	private const KEEP = 14 * DAY_IN_SECONDS;

	/**
	 * Most sessions remembered per user.
	 *
	 * @since 3.4.0
	 */
	private const LIMIT = 20;

	/**
	 * How long the marker cookie lasts, in seconds.
	 *
	 * @since 3.4.0
	 */
	private const MARKER_TTL = 10 * MINUTE_IN_SECONDS;

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
	 * @since 3.4.0
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'auth_cookie_bad_session_token', array( $this, 'detect' ) );
		add_action( 'init', array( $this, 'check_early' ), 1 );
		add_filter( 'login_message', array( $this, 'login_message' ) );
		add_action( 'template_redirect', array( $this, 'maybe_redirect' ) );
		add_action( 'loggedin_sessions_displacing', array( $this, 'record' ), 10, 2 );
	}

	/**
	 * Check a login cookie before anything is printed.
	 *
	 * WordPress checks the cookie the first time something asks who is
	 * signed in. If a theme prints before that, the marker cookie can no
	 * longer be set. Asking early, and only when a login cookie came
	 * with the request, settles it while headers can still be sent.
	 *
	 * @since 3.4.0
	 *
	 * @return void
	 */
	public function check_early(): void {
		if ( ! empty( $_COOKIE[ LOGGED_IN_COOKIE ] ) ) {
			is_user_logged_in();
		}
	}

	/**
	 * Note sessions a newer login is about to end.
	 *
	 * @since 3.4.0
	 *
	 * @param int           $user_id   User id.
	 * @param array<string> $verifiers Session verifiers.
	 *
	 * @return void
	 */
	public function record( int $user_id, array $verifiers ): void {
		if ( $user_id <= 0 || empty( $verifiers ) ) {
			return;
		}

		$now   = time();
		$noted = $this->noted( $user_id );

		foreach ( $verifiers as $verifier ) {
			$noted[ (string) $verifier ] = $now;
		}

		arsort( $noted );

		update_user_meta( $user_id, self::META_KEY, array_slice( $noted, 0, self::LIMIT, true ) );
	}

	/**
	 * Mark the device when its cookie names a session we ended.
	 *
	 * Each noted session is used once, so the auth and logged-in
	 * cookies of one request (and every later request) don't mark the
	 * device again.
	 *
	 * @since 3.4.0
	 *
	 * @param array<string, mixed> $cookie Cookie elements, among them `username` and `token`.
	 *
	 * @return void
	 */
	public function detect( $cookie ): void {
		if ( empty( $cookie['username'] ) || empty( $cookie['token'] ) ) {
			return;
		}

		$user = get_user_by( 'login', (string) $cookie['username'] );

		if ( ! $user ) {
			return;
		}

		$noted    = $this->noted( (int) $user->ID );
		$verifier = $this->store->verifier( (string) $cookie['token'] );

		if ( ! isset( $noted[ $verifier ] ) ) {
			return;
		}

		unset( $noted[ $verifier ] );

		if ( $noted ) {
			update_user_meta( (int) $user->ID, self::META_KEY, $noted );
		} else {
			delete_user_meta( (int) $user->ID, self::META_KEY );
		}

		$this->set_marker( time() + self::MARKER_TTL );
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
	public function login_message( $message ): string {
		if ( ! $this->is_marked() ) {
			return (string) $message;
		}

		$this->set_marker( time() - YEAR_IN_SECONDS );

		$text = __( 'You were signed out because your account signed in on another device.', 'loggedin' );

		/**
		 * Filter the message shown to someone signed out by a newer login.
		 *
		 * @since 3.4.0
		 *
		 * @param string $text Message text.
		 */
		$text = (string) apply_filters( 'loggedin_displaced_message', $text );

		return $message . '<p class="message">' . esc_html( $text ) . '</p>';
	}

	/**
	 * Send a marked device to the page set for it, once.
	 *
	 * Only page views on the front end; wp-admin sends signed-out
	 * people to the login screen, which explains instead.
	 *
	 * @since 3.4.0
	 *
	 * @return void
	 */
	public function maybe_redirect(): void {
		$url = (string) $this->settings->get( 'displaced_redirect', '' );

		if ( '' === $url || ! $this->is_marked() || is_user_logged_in() ) {
			return;
		}

		$this->set_marker( time() - YEAR_IN_SECONDS );

		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * The user's noted sessions still worth remembering.
	 *
	 * @since 3.4.0
	 *
	 * @param int $user_id User id.
	 *
	 * @return array<string, int> Times keyed by verifier.
	 */
	private function noted( int $user_id ): array {
		$noted = get_user_meta( $user_id, self::META_KEY, true );

		if ( ! is_array( $noted ) ) {
			return array();
		}

		$since = time() - self::KEEP;

		return array_filter(
			array_map( 'intval', $noted ),
			static function ( int $time ) use ( $since ): bool {
				return $time > $since;
			}
		);
	}

	/**
	 * Whether this device carries a marker this site set.
	 *
	 * The marker is signed, so a page on a sibling subdomain that shares
	 * the cookie domain can't plant one.
	 *
	 * @since 3.4.0
	 *
	 * @return bool
	 */
	private function is_marked(): bool {
		$value = isset( $_COOKIE[ self::COOKIE ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE ] ) ) : '';
		$parts = explode( '.', $value, 2 );

		return 2 === count( $parts )
			&& (int) $parts[0] > time()
			&& hash_equals( $this->sign( (int) $parts[0] ), $parts[1] );
	}

	/**
	 * Signature for a marker that expires at the given time.
	 *
	 * @since 3.4.0
	 *
	 * @param int $expires Unix time.
	 *
	 * @return string
	 */
	private function sign( int $expires ): string {
		return wp_hash( self::COOKIE . '|' . $expires );
	}

	/**
	 * Set or clear the marker cookie.
	 *
	 * Set on both the site and home paths, so it reaches wp-login.php
	 * and front-end pages when WordPress lives in a subdirectory.
	 *
	 * @since 3.4.0
	 *
	 * @param int $expires Unix time; in the past to clear it.
	 *
	 * @return void
	 */
	private function set_marker( int $expires ): void {
		$value = $expires > time() ? $expires . '.' . $this->sign( $expires ) : '';

		if ( ! headers_sent() ) {
			foreach ( array_unique( array( COOKIEPATH, SITECOOKIEPATH ) ) as $path ) {
				setcookie( self::COOKIE, $value, $expires, $path, COOKIE_DOMAIN, is_ssl(), true );
			}
		}

		// The rest of this request sees the marker too.
		if ( '' !== $value ) {
			$_COOKIE[ self::COOKIE ] = $value;
		} else {
			unset( $_COOKIE[ self::COOKIE ] );
		}
	}
}
