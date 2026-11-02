<?php
/**
 * One user's sessions, by verifier.
 *
 * `WP_Session_Tokens` can destroy every session, every session but the
 * current one, or one session by its raw token. Loggedin also needs to
 * end a session it only knows by verifier (the SHA-256 hash WordPress
 * keys `session_tokens` with): the oldest one, one picked on the
 * profile screen, one picked from WP-CLI. There's no API for that, so
 * it means writing the `session_tokens` user meta. This class is the
 * one place that does it.
 *
 * Guarded: it only touches the meta when the user's sessions live there
 * (WordPress's default storage). With a custom backend (Redis and the
 * like, through `session_token_manager`) it reports no sessions and
 * destroys nothing.
 *
 * `session_tokens` is shared with WordPress and other plugins, so this
 * writes it only when a session actually ends, never on a page load.
 *
 * @since 3.4.0
 * @package FoxeLabs\Loggedin\Sessions
 */

declare( strict_types = 1 );

namespace FoxeLabs\Loggedin\Sessions;

use WP_Session_Tokens;
use WP_User_Meta_Session_Tokens;

defined( 'WPINC' ) || die;

/**
 * Reads and ends single sessions in the default session storage.
 *
 * @since 3.4.0
 */
final class SessionStore {

	/**
	 * User meta WordPress keeps sessions in.
	 *
	 * @since 3.4.0
	 */
	private const META_KEY = 'session_tokens';

	/**
	 * Whether this user's sessions live in WordPress's default storage.
	 *
	 * Asks for the resolved manager rather than re-running the
	 * `session_token_manager` filter: the filter receives the user id,
	 * so a site could pick a different backend per user.
	 *
	 * @since 3.4.0
	 *
	 * @param int $user_id User id.
	 *
	 * @return bool
	 */
	public function is_default_storage( int $user_id ): bool {
		return WP_Session_Tokens::get_instance( $user_id ) instanceof WP_User_Meta_Session_Tokens;
	}

	/**
	 * The user's live sessions, keyed by verifier, newest first.
	 *
	 * Empty with a custom storage backend.
	 *
	 * @since 3.4.0
	 *
	 * @param int $user_id User id.
	 *
	 * @return array<string, array<string, mixed>> Sessions: `login`, `expiration`, `ip`, `ua`.
	 */
	public function all( int $user_id ): array {
		if ( $user_id <= 0 || ! $this->is_default_storage( $user_id ) ) {
			return array();
		}

		$stored = get_user_meta( $user_id, self::META_KEY, true );

		if ( ! is_array( $stored ) ) {
			return array();
		}

		$now = time();

		$live = array_filter(
			$stored,
			static function ( $session ) use ( $now ): bool {
				return is_array( $session ) && isset( $session['expiration'] ) && (int) $session['expiration'] > $now;
			}
		);

		uasort(
			$live,
			static function ( array $a, array $b ): int {
				return (int) ( $b['login'] ?? 0 ) <=> (int) ( $a['login'] ?? 0 );
			}
		);

		return $live;
	}

	/**
	 * Verifier of the user's oldest live session, or ''.
	 *
	 * Expired sessions are skipped: WordPress doesn't count them toward
	 * the limit, so ending one would make no room.
	 *
	 * @since 3.4.0
	 *
	 * @param int $user_id User id.
	 *
	 * @return string
	 */
	public function oldest( int $user_id ): string {
		$live = $this->all( $user_id );

		// `all()` is newest first.
		return $live ? (string) array_key_last( $live ) : '';
	}

	/**
	 * End the given sessions.
	 *
	 * @since 3.4.0
	 *
	 * @param int           $user_id   User id.
	 * @param array<string> $verifiers Session verifiers.
	 *
	 * @return int How many sessions ended.
	 */
	public function destroy( int $user_id, array $verifiers ): int {
		if ( $user_id <= 0 || empty( $verifiers ) || ! $this->is_default_storage( $user_id ) ) {
			return 0;
		}

		$stored = get_user_meta( $user_id, self::META_KEY, true );

		if ( ! is_array( $stored ) ) {
			return 0;
		}

		$ended = 0;

		foreach ( $verifiers as $verifier ) {
			if ( isset( $stored[ $verifier ] ) ) {
				unset( $stored[ $verifier ] );
				++$ended;
			}
		}

		if ( $ended ) {
			update_user_meta( $user_id, self::META_KEY, $stored );
		}

		return $ended;
	}

	/**
	 * Sign a user out of every device.
	 *
	 * Goes through `WP_Session_Tokens`, so it works with any storage
	 * backend. Every "sign out everywhere" path calls this, so
	 * `loggedin_destroy_all_sessions` fires for all of them; each
	 * caller fires its own, more specific hook as well.
	 *
	 * @since 3.4.0
	 *
	 * @param int                $user_id      User id.
	 * @param bool               $keep_current Keep the session making this request when
	 *                                         it is the user's own, so signing yourself
	 *                                         out doesn't end the request you made.
	 * @param array<int, string> $keep         Verifiers of sessions to keep as well,
	 *                                         such as one created in this request.
	 *                                         Default storage only.
	 *
	 * @return void
	 */
	public function end_all( int $user_id, bool $keep_current = false, array $keep = array() ): void {
		if ( $user_id <= 0 ) {
			return;
		}

		$manager = WP_Session_Tokens::get_instance( $user_id );
		$token   = $keep_current && get_current_user_id() === $user_id ? wp_get_session_token() : '';

		if ( ! empty( $keep ) && $this->is_default_storage( $user_id ) ) {
			if ( '' !== $token ) {
				$keep[] = $this->verifier( $token );
			}

			$stored = get_user_meta( $user_id, self::META_KEY, true );
			$this->destroy( $user_id, array_diff( array_map( 'strval', array_keys( is_array( $stored ) ? $stored : array() ) ), $keep ) );
		} elseif ( '' !== $token ) {
			$manager->destroy_others( $token );
		} else {
			$manager->destroy_all();
		}

		/**
		 * Fires after a user is signed out of every device.
		 *
		 * Fires for every path: a limit reached under Logout All, a
		 * forced sign-out from the settings screen, the Users screen,
		 * the REST API or WP-CLI, a password change, and the unlock
		 * email. When users sign themselves out, the session they are
		 * using is kept.
		 *
		 * @since 2.0.0
		 * @since 3.4.0 Fires for the Users screen, password changes and
		 *              the unlock email too.
		 *
		 * @param int $user_id User whose sessions were ended.
		 */
		do_action( 'loggedin_destroy_all_sessions', $user_id );
	}

	/**
	 * Verifiers of the user's live sessions.
	 *
	 * @since 3.4.0
	 *
	 * @param int $user_id User id.
	 *
	 * @return array<int, string>
	 */
	public function verifiers( int $user_id ): array {
		return array_map( 'strval', array_keys( $this->all( $user_id ) ) );
	}

	/**
	 * Verifier of a raw session token, as WordPress computes it.
	 *
	 * Mirrors the private `WP_Session_Tokens::hash_token()`.
	 *
	 * @since 3.4.0
	 *
	 * @param string $token Raw token, from a cookie.
	 *
	 * @return string
	 */
	public function verifier( string $token ): string {
		return function_exists( 'hash' ) ? hash( 'sha256', $token ) : sha1( $token );
	}

	/**
	 * A short name for the device a session was made on.
	 *
	 * "Chrome on macOS", "Safari on iPhone". Enough for someone to
	 * recognise their own devices; not a full user-agent parser, which
	 * a site can plug in through `loggedin_session_device`.
	 *
	 * @since 3.4.0
	 *
	 * @param string $ua User agent stored with the session.
	 *
	 * @return string
	 */
	public function device( string $ua ): string {
		/**
		 * Filter the device name shown for a session.
		 *
		 * Shown on the profile screen's session list.
		 *
		 * @since 3.4.0
		 *
		 * @param string $name Device name, such as "Chrome on macOS".
		 * @param string $ua   User agent stored with the session.
		 */
		return (string) apply_filters( 'loggedin_session_device', $this->parse_device( $ua ), $ua );
	}

	/**
	 * Name the browser and system from a user agent.
	 *
	 * "Chrome on macOS", "Safari on iPhone". Enough for someone to
	 * recognise their own devices; not a full user-agent parser.
	 *
	 * @since 3.4.0
	 *
	 * @param string $ua User agent stored with the session.
	 *
	 * @return string
	 */
	private function parse_device( string $ua ): string {
		$browsers = array(
			'Edg/'           => 'Edge',
			'OPR/'           => 'Opera',
			'SamsungBrowser' => 'Samsung Internet',
			'Firefox/'       => 'Firefox',
			'FxiOS'          => 'Firefox',
			'CriOS'          => 'Chrome',
			'Chrome/'        => 'Chrome',
			'Safari/'        => 'Safari',
		);

		$systems = array(
			'iPhone'    => 'iPhone',
			'iPad'      => 'iPad',
			'Android'   => 'Android',
			'Windows'   => 'Windows',
			'CrOS'      => 'ChromeOS',
			'Mac OS X'  => 'macOS',
			'Macintosh' => 'macOS',
			'Linux'     => 'Linux',
		);

		$browser = '';
		$system  = '';

		foreach ( $browsers as $needle => $name ) {
			if ( false !== strpos( $ua, $needle ) ) {
				$browser = $name;
				break;
			}
		}

		foreach ( $systems as $needle => $name ) {
			if ( false !== strpos( $ua, $needle ) ) {
				$system = $name;
				break;
			}
		}

		if ( $browser && $system ) {
			/* translators: 1: Browser name, 2: Operating system or device. */
			return sprintf( __( '%1$s on %2$s', 'loggedin' ), $browser, $system );
		}

		if ( $browser || $system ) {
			return $browser . $system;
		}

		return __( 'Unknown device', 'loggedin' );
	}
}
