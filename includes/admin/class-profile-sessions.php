<?php
/**
 * "Your sessions" on the profile screen.
 *
 * On their own profile, users see every device they're signed in on
 * (a short device name, the address it signed in from with the last
 * part hidden, and when) and can sign any other device out. WordPress
 * already puts "Log Out Everywhere Else" on the same screen, so this
 * adds the list and leaves that button as the way to end the rest.
 *
 * On someone else's profile it shows only how many devices they're on,
 * next to WordPress's own "Log Out Everywhere". Seeing another user's
 * devices one by one belongs to the Active Sessions add-on.
 *
 * @package FoxeLabs\Loggedin\Admin
 */

declare( strict_types = 1 );

namespace FoxeLabs\Loggedin\Admin;

use FoxeLabs\Loggedin\Contracts\Singleton;
use FoxeLabs\Loggedin\Front\Session_Store;
use WP_Session_Tokens;
use WP_User;

defined( 'WPINC' ) || die;

/**
 * Lists a user's own sessions on their profile.
 *
 * @since 3.4.0
 */
final class Profile_Sessions {

	use Singleton;

	/**
	 * `admin-post.php` action that ends one of the user's sessions.
	 *
	 * @since 3.4.0
	 */
	private const ACTION = 'loggedin_end_session';

	/**
	 * Query arg telling the profile screen a session was ended.
	 *
	 * @since 3.4.0
	 */
	private const DONE_ARG = 'loggedin_session_ended';

	/**
	 * Register hooks.
	 *
	 * @since 3.4.0
	 *
	 * @return void
	 */
	protected function init(): void {
		add_action( 'show_user_profile', array( $this, 'own' ) );
		add_action( 'edit_user_profile', array( $this, 'other' ) );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'end_session' ) );
		add_action( 'admin_notices', array( $this, 'notice' ) );
	}

	/**
	 * The user's own sessions, one row per device.
	 *
	 * @since 3.4.0
	 *
	 * @param WP_User $user User whose profile this is.
	 *
	 * @return void
	 */
	public function own( $user ): void {
		if ( ! $user instanceof WP_User ) {
			return;
		}

		$store    = Session_Store::instance();
		$sessions = $store->all( (int) $user->ID );
		$current  = $store->verifier( wp_get_session_token() );

		echo '<h2 id="loggedin-sessions">' . esc_html__( 'Your sessions', 'loggedin' ) . '</h2>';

		if ( ! $store->is_default_storage( (int) $user->ID ) ) {
			echo '<p>' . esc_html( $this->count_text( count( WP_Session_Tokens::get_instance( $user->ID )->get_all() ) ) ) . '</p>';

			return;
		}

		echo '<p class="description">' . esc_html__( 'Every device you are signed in on. Sign out any you don’t recognise, then change your password.', 'loggedin' ) . '</p>';

		echo '<table class="widefat striped loggedin-profile-sessions" style="max-width:720px;margin-top:12px">';
		echo '<thead><tr>';
		echo '<th scope="col">' . esc_html__( 'Device', 'loggedin' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Address', 'loggedin' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Signed in', 'loggedin' ) . '</th>';
		echo '<th scope="col"><span class="screen-reader-text">' . esc_html__( 'Actions', 'loggedin' ) . '</span></th>';
		echo '</tr></thead><tbody>';

		foreach ( $sessions as $verifier => $session ) {
			$login  = (int) ( $session['login'] ?? 0 );
			$ip     = (string) ( $session['ip'] ?? '' );
			$device = $store->device( (string) ( $session['ua'] ?? '' ) );

			echo '<tr>';
			echo '<td>' . esc_html( $device ) . '</td>';
			echo '<td>' . esc_html( '' !== $ip ? (string) wp_privacy_anonymize_ip( $ip ) : '—' ) . '</td>';
			echo '<td>' . ( $login ? '<time datetime="' . esc_attr( gmdate( 'c', $login ) ) . '" title="' . esc_attr( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $login ) ) . '">' . esc_html( $this->ago( $login ) ) . '</time>' : '—' ) . '</td>';
			echo '<td style="text-align:end">';

			if ( $verifier === $current ) {
				echo '<strong>' . esc_html__( 'This device', 'loggedin' ) . '</strong>';
			} else {
				// Every row's button says "Sign out"; the label names the device for screen readers.
				printf(
					'<a class="button button-small" href="%1$s" aria-label="%2$s">%3$s</a>',
					esc_url( $this->end_url( (string) $verifier ) ),
					/* translators: 1: Device, such as "Chrome on macOS". 2: How long ago it signed in. */
					esc_attr( sprintf( __( 'Sign out %1$s, signed in %2$s', 'loggedin' ), $device, $login ? $this->ago( $login ) : '—' ) ),
					esc_html__( 'Sign out', 'loggedin' )
				);
			}

			echo '</td></tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * How many devices another user is on.
	 *
	 * @since 3.4.0
	 *
	 * @param WP_User $user User being edited.
	 *
	 * @return void
	 */
	public function other( $user ): void {
		if ( ! $user instanceof WP_User ) {
			return;
		}

		$count = count( WP_Session_Tokens::get_instance( $user->ID )->get_all() );

		echo '<h2 id="loggedin-sessions">' . esc_html__( 'Sessions', 'loggedin' ) . '</h2>';
		echo '<p>' . esc_html( $this->count_text( $count ) ) . '</p>';
	}

	/**
	 * End one of the current user's sessions, then go back to the profile.
	 *
	 * @since 3.4.0
	 *
	 * @return void
	 */
	public function end_session(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Checked just below, against the session it names.
		$verifier = isset( $_GET['session'] ) ? preg_replace( '/[^a-f0-9]/', '', strtolower( sanitize_text_field( wp_unslash( $_GET['session'] ) ) ) ) : '';
		$user_id  = get_current_user_id();

		check_admin_referer( self::ACTION . '_' . $verifier );

		$ended = 0;

		// Never this device: signing out here is what Log Out is for.
		if ( $user_id && '' !== $verifier && Session_Store::instance()->verifier( wp_get_session_token() ) !== $verifier ) {
			$ended = Session_Store::instance()->destroy( $user_id, array( (string) $verifier ) );
		}

		if ( $ended ) {
			/**
			 * Fires after a user signs one of their devices out from their profile.
			 *
			 * @since 3.4.0
			 *
			 * @param int    $user_id  User id.
			 * @param string $verifier Verifier of the session that ended.
			 */
			do_action( 'loggedin_destroy_session', $user_id, (string) $verifier );
		}

		wp_safe_redirect( add_query_arg( self::DONE_ARG, $ended ? '1' : '0', admin_url( 'profile.php' ) ) . '#loggedin-sessions' );
		exit;
	}

	/**
	 * Confirm the sign-out on the profile screen.
	 *
	 * @since 3.4.0
	 *
	 * @return void
	 */
	public function notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only flag that only picks which notice to render.
		$done = isset( $_GET[ self::DONE_ARG ] ) ? sanitize_key( wp_unslash( $_GET[ self::DONE_ARG ] ) ) : '';

		if ( '' === $done ) {
			return;
		}

		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			'1' === $done ? 'success' : 'warning',
			esc_html(
				'1' === $done
					? __( 'That device was signed out.', 'loggedin' )
					: __( 'That device was already signed out.', 'loggedin' )
			)
		);
	}

	/**
	 * Address of the link that signs one device out.
	 *
	 * @since 3.4.0
	 *
	 * @param string $verifier Session verifier.
	 *
	 * @return string URL.
	 */
	private function end_url( string $verifier ): string {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action'  => self::ACTION,
					'session' => $verifier,
				),
				admin_url( 'admin-post.php' )
			),
			self::ACTION . '_' . $verifier
		);
	}

	/**
	 * "Signed in on 3 devices."
	 *
	 * @since 3.4.0
	 *
	 * @param int $count Sessions.
	 *
	 * @return string
	 */
	private function count_text( int $count ): string {
		if ( 0 === $count ) {
			return __( 'Not signed in on any device.', 'loggedin' );
		}

		/* translators: %d: Number of devices. */
		return sprintf( _n( 'Signed in on %d device.', 'Signed in on %d devices.', $count, 'loggedin' ), $count );
	}

	/**
	 * "5 minutes ago".
	 *
	 * @since 3.4.0
	 *
	 * @param int $time Unix time.
	 *
	 * @return string
	 */
	private function ago( int $time ): string {
		/* translators: %s: Time span, e.g. "5 minutes". */
		return sprintf( __( '%s ago', 'loggedin' ), human_time_diff( $time ) );
	}
}
