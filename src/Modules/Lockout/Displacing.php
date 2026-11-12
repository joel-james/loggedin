<?php
/**
 * Tell a new login that it signed out the account's other devices.
 *
 * {@see Displaced} tells the device that was signed out why. This
 * tells the other side: the person whose login, under Logout Oldest or
 * Logout All, ended the account's older sessions.
 *
 *   1. When Loggedin ends sessions to make room, a short-lived, signed
 *      cookie goes on the device that is logging in. Nothing is stored
 *      in user meta, and `session_tokens` is never touched.
 *   2. On the next page that device opens, signed in as the same user:
 *      - wp-admin shows a dismissible notice;
 *      - a front-end page fires `loggedin_displacing_notice`, and adds a
 *        WooCommerce notice when WooCommerce has a session.
 *   3. The cookie is cleared once the notice is shown.
 *
 * Room is made at the end of the login request ({@see
 * \FoxeLabs\Loggedin\Sessions\SessionGuard}). A login that redirects,
 * as wp-login.php and most login forms do, has sent no output by then,
 * so the cookie can still be set. A login answered in the same request
 * (an AJAX login that prints JSON) has, and gets no notice.
 *
 * @since 3.4.2
 * @package FoxeLabs\Loggedin\Modules\Lockout
 */

declare( strict_types = 1 );

namespace FoxeLabs\Loggedin\Modules\Lockout;

use FoxeLabs\Loggedin\Settings\Settings;
use WP_User;

defined( 'WPINC' ) || die;

/**
 * Tells a new login which of the account's sessions it ended.
 *
 * @since 3.4.2
 */
final class Displacing {

	/**
	 * Cookie carrying the notice to the new login's next page.
	 *
	 * @since 3.4.2
	 */
	private const COOKIE = 'loggedin_displacing';

	/**
	 * How long the cookie lasts, in seconds.
	 *
	 * Long enough for the redirect after the login and a slow first
	 * page, short enough that a notice nobody showed doesn't turn up
	 * much later.
	 *
	 * @since 3.4.2
	 */
	private const TTL = 10 * MINUTE_IN_SECONDS;

	/**
	 * Login modes that end other sessions, and so have a notice.
	 *
	 * @since 3.4.2
	 */
	private const LOGICS = array( 'allow', 'logout_oldest' );

	/**
	 * Settings store.
	 *
	 * @since 3.4.2
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Sessions ended in this request, by user id.
	 *
	 * Logout Oldest may end several, one at a time, when the limit was
	 * lowered since they began.
	 *
	 * @since 3.4.2
	 *
	 * @var array<int, int>
	 */
	private array $ended = array();

	/**
	 * Message to print on this admin screen.
	 *
	 * @since 3.4.2
	 *
	 * @var string
	 */
	private string $admin_message = '';

	/**
	 * Set up the dependencies.
	 *
	 * @since 3.4.2
	 *
	 * @param Settings $settings Settings store.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Register hooks.
	 *
	 * @since 3.4.2
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'loggedin_sessions_displacing', array( $this, 'record' ), 10, 2 );
		add_action( 'current_screen', array( $this, 'show_in_admin' ) );
		add_action( 'template_redirect', array( $this, 'show_on_front' ) );
	}

	/**
	 * Mark the device logging in, as its login ends other sessions.
	 *
	 * @since 3.4.2
	 *
	 * @param int           $user_id   User whose sessions are ending.
	 * @param array<string> $verifiers Verifiers of those sessions.
	 *
	 * @return void
	 */
	public function record( int $user_id, array $verifiers ): void {
		if ( $user_id <= 0 || empty( $verifiers ) || ! $this->settings->get( 'displacing_notice', false ) ) {
			return;
		}

		$logic = (string) $this->settings->get( 'logic', 'allow' );

		if ( ! in_array( $logic, self::LOGICS, true ) ) {
			return;
		}

		$this->ended[ $user_id ] = ( $this->ended[ $user_id ] ?? 0 ) + count( $verifiers );

		$this->set_cookie( $this->encode( $user_id, $logic, $this->ended[ $user_id ], time() + self::TTL ) );
	}

	/**
	 * Show the notice on the first wp-admin screen after the login.
	 *
	 * `current_screen` runs on screens only, never on admin-ajax or
	 * admin-post, and before anything is printed, so the cookie can be
	 * cleared here.
	 *
	 * @since 3.4.2
	 *
	 * @return void
	 */
	public function show_in_admin(): void {
		$notice = $this->pending();

		if ( null === $notice ) {
			return;
		}

		$this->clear();

		$this->admin_message = $notice['message'];

		add_action( 'all_admin_notices', array( $this, 'print_admin_notice' ) );
	}

	/**
	 * Print the wp-admin notice.
	 *
	 * @since 3.4.2
	 *
	 * @return void
	 */
	public function print_admin_notice(): void {
		if ( '' === $this->admin_message ) {
			return;
		}

		wp_admin_notice(
			esc_html( $this->admin_message ),
			array(
				'type'        => 'info',
				'dismissible' => true,
			)
		);

		$this->admin_message = '';
	}

	/**
	 * Pass the notice to the first front-end page after the login.
	 *
	 * The cookie stays when nothing could show the notice, so the next
	 * wp-admin screen still can.
	 *
	 * @since 3.4.2
	 *
	 * @return void
	 */
	public function show_on_front(): void {
		$notice = $this->pending();

		if ( null === $notice ) {
			return;
		}

		/**
		 * Fires on the first front-end page after a login that signed
		 * out the account's other sessions.
		 *
		 * For membership plugins and themes that show notices their
		 * own way. Once a callback is hooked here, Loggedin treats the
		 * notice as shown and doesn't carry it to wp-admin.
		 *
		 * @since 3.4.2
		 *
		 * @param string  $message Notice text. Plain text, not escaped.
		 * @param WP_User $user    User who logged in.
		 * @param string  $logic   Login mode that ended the sessions: `allow` (Logout All) or `logout_oldest`.
		 * @param int     $count   Sessions the login ended.
		 */
		do_action( 'loggedin_displacing_notice', $notice['message'], $notice['user'], $notice['logic'], $notice['count'] );

		$shown = has_action( 'loggedin_displacing_notice' );

		if ( $this->has_woocommerce_session() ) {
			wc_add_notice( esc_html( $notice['message'] ), 'notice' );
			$shown = true;
		}

		if ( $shown ) {
			$this->clear();
		}
	}

	/**
	 * The notice waiting for the signed-in user on this device, if any.
	 *
	 * @since 3.4.2
	 *
	 * @return array{message: string, user: WP_User, logic: string, count: int}|null
	 */
	private function pending(): ?array {
		// The cookie first: it is missing on almost every request.
		$value = isset( $_COOKIE[ self::COOKIE ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE ] ) ) : '';

		if ( '' === $value ) {
			return null;
		}

		$parts = explode( '.', $value );

		if ( 5 !== count( $parts ) ) {
			return null;
		}

		list( $user_id, $logic, $count, $expires, $signature ) = $parts;

		$user_id = (int) $user_id;
		$count   = (int) $count;
		$expires = (int) $expires;

		if (
			$expires <= time() ||
			$count < 1 ||
			! in_array( $logic, self::LOGICS, true ) ||
			! hash_equals( $this->sign( $user_id, $logic, $count, $expires ), $signature ) ||
			get_current_user_id() !== $user_id
		) {
			return null;
		}

		$user = wp_get_current_user();

		return array(
			'message' => $this->message( $user, $logic, $count ),
			'user'    => $user,
			'logic'   => $logic,
			'count'   => $count,
		);
	}

	/**
	 * The notice text.
	 *
	 * @since 3.4.2
	 *
	 * @param WP_User $user  User who logged in.
	 * @param string  $logic `allow` or `logout_oldest`.
	 * @param int     $count Sessions the login ended.
	 *
	 * @return string Plain text.
	 */
	private function message( WP_User $user, string $logic, int $count ): string {
		if ( 'logout_oldest' === $logic ) {
			$text = 1 === $count
				? __( 'Your account reached its device limit, so your oldest session was signed out.', 'loggedin' )
				: sprintf(
					/* translators: %d: number of sessions signed out. */
					_n(
						'Your account reached its device limit, so your %d oldest session was signed out.',
						'Your account reached its device limit, so your %d oldest sessions were signed out.',
						$count,
						'loggedin'
					),
					$count
				);
		} else {
			$text = 1 === $count
				? __( 'Your account reached its device limit, so your other device was signed out.', 'loggedin' )
				: sprintf(
					/* translators: %d: number of devices signed out. */
					_n(
						'Your account reached its device limit, so your %d other device was signed out.',
						'Your account reached its device limit, so your %d other devices were signed out.',
						$count,
						'loggedin'
					),
					$count
				);
		}

		/**
		 * Filter the notice shown after a login signs out other sessions.
		 *
		 * @since 3.4.2
		 *
		 * @param string  $text  Notice text. Plain text: it is escaped when shown.
		 * @param WP_User $user  User who logged in.
		 * @param string  $logic Login mode that ended the sessions: `allow` (Logout All) or `logout_oldest`.
		 * @param int     $count Sessions the login ended.
		 */
		return (string) apply_filters( 'loggedin_displacing_message', $text, $user, $logic, $count );
	}

	/**
	 * Whether WooCommerce is running with a session to hold a notice.
	 *
	 * `WC()->session` is null outside front-end requests, and on any
	 * request where WooCommerce hasn't started one.
	 *
	 * @since 3.4.2
	 *
	 * @return bool
	 */
	private function has_woocommerce_session(): bool {
		return function_exists( 'WC' ) && function_exists( 'wc_add_notice' ) && null !== WC()->session;
	}

	/**
	 * The cookie value: who, which mode, how many, until when, signed.
	 *
	 * @since 3.4.2
	 *
	 * @param int    $user_id User id.
	 * @param string $logic   Login mode.
	 * @param int    $count   Sessions ended.
	 * @param int    $expires Unix time.
	 *
	 * @return string
	 */
	private function encode( int $user_id, string $logic, int $count, int $expires ): string {
		return implode( '.', array( $user_id, $logic, $count, $expires, $this->sign( $user_id, $logic, $count, $expires ) ) );
	}

	/**
	 * Signature over the cookie's fields.
	 *
	 * Signed, so a page on a sibling subdomain that shares the cookie
	 * domain can't plant a notice.
	 *
	 * @since 3.4.2
	 *
	 * @param int    $user_id User id.
	 * @param string $logic   Login mode.
	 * @param int    $count   Sessions ended.
	 * @param int    $expires Unix time.
	 *
	 * @return string
	 */
	private function sign( int $user_id, string $logic, int $count, int $expires ): string {
		return wp_hash( self::COOKIE . '|' . $user_id . '|' . $logic . '|' . $count . '|' . $expires );
	}

	/**
	 * Clear the cookie.
	 *
	 * @since 3.4.2
	 *
	 * @return void
	 */
	private function clear(): void {
		$this->set_cookie( '' );
	}

	/**
	 * Set the cookie, or clear it with an empty value.
	 *
	 * Set on both the site and home paths, so it reaches wp-admin and
	 * front-end pages when WordPress lives in a subdirectory.
	 *
	 * @since 3.4.2
	 *
	 * @param string $value Cookie value; empty to clear it.
	 *
	 * @return void
	 */
	private function set_cookie( string $value ): void {
		if ( ! headers_sent() ) {
			$expires = '' === $value ? time() - YEAR_IN_SECONDS : time() + self::TTL;
			// COOKIE_DOMAIN is false before WordPress 6.6; setcookie()
			// refuses that under strict types.
			$domain = (string) COOKIE_DOMAIN;

			foreach ( array_unique( array( COOKIEPATH, SITECOOKIEPATH ) ) as $path ) {
				setcookie( self::COOKIE, $value, $expires, $path, $domain, is_ssl(), true );
			}
		}

		// The rest of this request sees it too.
		if ( '' !== $value ) {
			$_COOKIE[ self::COOKIE ] = $value;
		} else {
			unset( $_COOKIE[ self::COOKIE ] );
		}
	}
}
