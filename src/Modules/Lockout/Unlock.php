<?php
/**
 * Self-unlock for logins refused by Block New.
 *
 * When Block New refuses a login, the person has just typed the right
 * password (see {@see SessionGuard::validate_block_logic()}). That is
 * the moment to offer a way out that doesn't need an administrator:
 *
 *   1. The error carries a link with a short-lived request key.
 *   2. Opening it emails the account's address a second link, with its
 *      own key. At most one email per user per five minutes.
 *   3. Opening the emailed link signs the account out of every device,
 *      and the person logs in again.
 *
 * Proving both the password and the mailbox means a leaked password
 * alone can't be used to sign the owner out everywhere. Each key is
 * random, single-use, valid for 15 minutes and stored only as a hash,
 * in its own user meta row (never in `session_tokens`).
 *
 * @package FoxeLabs\Loggedin\Modules\Lockout
 */

declare( strict_types = 1 );

namespace FoxeLabs\Loggedin\Modules\Lockout;

use FoxeLabs\Loggedin\Sessions\SessionGuard;
use FoxeLabs\Loggedin\Settings\Settings;
use WP_Session_Tokens;
use WP_User;

defined( 'WPINC' ) || die;

/**
 * Lets a blocked user sign out their other devices by email.
 *
 * @since 3.4.0
 */
final class Unlock {

	/**
	 * User meta holding the hashed key from the login error's link.
	 *
	 * @since 3.4.0
	 */
	public const REQUEST_META = 'loggedin_unlock_request';

	/**
	 * User meta holding the hashed key from the emailed link.
	 *
	 * @since 3.4.0
	 */
	public const UNLOCK_META = 'loggedin_unlock';

	/**
	 * User meta holding when the last unlock email went out.
	 *
	 * @since 3.4.0
	 */
	public const SENT_META = 'loggedin_unlock_sent';

	/**
	 * How long a key stays valid, in seconds.
	 *
	 * @since 3.4.0
	 */
	private const TTL = 15 * MINUTE_IN_SECONDS;

	/**
	 * Shortest time between two unlock emails to one user, in seconds.
	 *
	 * @since 3.4.0
	 */
	private const RESEND_AFTER = 5 * MINUTE_IN_SECONDS;

	/**
	 * Query arg that tells the login screen which message to show.
	 *
	 * @since 3.4.0
	 */
	private const STATE_ARG = 'loggedin_unlock';

	/**
	 * Settings store.
	 *
	 * @since 3.4.0
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Set up the dependencies.
	 *
	 * @since 3.4.0
	 *
	 * @param Settings $settings Settings store.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Register hooks.
	 *
	 * @since 3.4.0
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'login_form_loggedin_unlock_request', array( $this, 'handle_request' ) );
		add_action( 'login_form_loggedin_unlock', array( $this, 'handle_unlock' ) );

		add_filter( 'login_message', array( $this, 'login_message' ) );

		// Late, so a site's own message filter runs first and the link
		// still follows whatever message it sets.
		add_filter( 'loggedin_error_message', array( $this, 'append_offer' ), 100, 2 );
	}

	/**
	 * Add the self-unlock link to the "limit reached" error.
	 *
	 * @since 3.4.0
	 *
	 * @param string       $message Error message. HTML.
	 * @param WP_User|null $user    User whose login was refused.
	 *
	 * @return string
	 */
	public function append_offer( $message, $user = null ): string {
		if ( ! $user instanceof WP_User ) {
			return (string) $message;
		}

		return (string) $message . $this->offer( $user );
	}

	/**
	 * The link added to the "limit reached" error, or '' when self-unlock is off.
	 *
	 * @since 3.4.0
	 *
	 * @param WP_User $user User whose login was refused.
	 *
	 * @return string HTML.
	 */
	public function offer( WP_User $user ): string {
		if ( ! $this->settings->get( 'unlock', false ) || ! is_email( $user->user_email ) ) {
			return '';
		}

		$url = $this->url( 'loggedin_unlock_request', $user->ID, $this->issue( $user->ID, self::REQUEST_META ) );

		return ' <a href="' . esc_url( $url ) . '">' . esc_html__( 'Email me a link to sign out my other devices.', 'loggedin' ) . '</a>';
	}

	/**
	 * Send the unlock email, from the link in the login error.
	 *
	 * @since 3.4.0
	 *
	 * @return void
	 */
	public function handle_request(): void {
		[ $user_id, $key ] = $this->read_link();

		if ( ! $this->settings->get( 'unlock', false ) || ! $this->redeem( $user_id, self::REQUEST_META, $key ) ) {
			$this->finish( 'invalid' );
		}

		if ( time() - (int) get_user_meta( $user_id, self::SENT_META, true ) < self::RESEND_AFTER ) {
			$this->finish( 'wait' );
		}

		$user = get_userdata( $user_id );

		if ( ! $user || ! $this->send( $user, $this->issue( $user_id, self::UNLOCK_META ) ) ) {
			$this->finish( 'failed' );
		}

		update_user_meta( $user_id, self::SENT_META, time() );

		/**
		 * Fires after a blocked user is emailed a link to sign out
		 * their other devices.
		 *
		 * @since 3.4.0
		 *
		 * @param int $user_id User the email went to.
		 */
		do_action( 'loggedin_unlock_requested', $user_id );

		$this->finish( 'sent' );
	}

	/**
	 * Sign the account out everywhere, from the emailed link.
	 *
	 * @since 3.4.0
	 *
	 * @return void
	 */
	public function handle_unlock(): void {
		[ $user_id, $key ] = $this->read_link();

		if ( ! $this->settings->get( 'unlock', false ) || ! $this->redeem( $user_id, self::UNLOCK_META, $key ) ) {
			$this->finish( 'invalid' );
		}

		WP_Session_Tokens::get_instance( $user_id )->destroy_all();

		/**
		 * Fires after a user signs out of every device from the
		 * unlock email.
		 *
		 * @since 3.4.0
		 *
		 * @param int $user_id User who was signed out.
		 */
		do_action( 'loggedin_unlocked', $user_id );

		$this->finish( 'done' );
	}

	/**
	 * Explain on the login screen what happened.
	 *
	 * @since 3.4.0
	 *
	 * @param string $message Existing login-screen message markup.
	 *
	 * @return string
	 */
	public function login_message( $message ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only flag that only picks which notice to render.
		$state = isset( $_GET[ self::STATE_ARG ] ) ? sanitize_key( wp_unslash( $_GET[ self::STATE_ARG ] ) ) : '';

		$messages = array(
			'sent'    => __( 'Check your email. The link in it signs you out of your other devices, so you can log in here.', 'loggedin' ),
			'wait'    => __( 'We emailed you a link a few minutes ago. Check your email, or try again in a few minutes.', 'loggedin' ),
			'done'    => __( 'You were signed out of your other devices. Log in again.', 'loggedin' ),
			'invalid' => __( 'That link has expired or was already used. Log in again to get a new one.', 'loggedin' ),
			'failed'  => __( 'The email could not be sent. Ask the site’s administrator to sign you out of your other devices.', 'loggedin' ),
		);

		if ( ! isset( $messages[ $state ] ) ) {
			return (string) $message;
		}

		return $message . '<p class="message">' . esc_html( $messages[ $state ] ) . '</p>';
	}

	/**
	 * Store a fresh key for a user and return it.
	 *
	 * A new key replaces the user's earlier one of the same kind.
	 *
	 * @since 3.4.0
	 *
	 * @param int    $user_id User id.
	 * @param string $meta    REQUEST_META or UNLOCK_META.
	 *
	 * @return string The key, to put in a link.
	 */
	private function issue( int $user_id, string $meta ): string {
		$key = wp_generate_password( 32, false );

		update_user_meta(
			$user_id,
			$meta,
			array(
				'hash'    => hash( 'sha256', $key ),
				'expires' => time() + self::TTL,
			)
		);

		return $key;
	}

	/**
	 * Check a key from a link and use it up.
	 *
	 * A wrong key leaves the stored one in place, so a guess can't
	 * spoil the owner's real link.
	 *
	 * @since 3.4.0
	 *
	 * @param int    $user_id User id from the link.
	 * @param string $meta    REQUEST_META or UNLOCK_META.
	 * @param string $key     Key from the link.
	 *
	 * @return bool Whether the key was valid.
	 */
	public function redeem( int $user_id, string $meta, string $key ): bool {
		$stored = $user_id > 0 ? get_user_meta( $user_id, $meta, true ) : null;

		if ( ! is_array( $stored ) || '' === $key || empty( $stored['hash'] ) ) {
			return false;
		}

		if ( ! hash_equals( (string) $stored['hash'], hash( 'sha256', $key ) ) ) {
			return false;
		}

		delete_user_meta( $user_id, $meta );

		return (int) ( $stored['expires'] ?? 0 ) >= time();
	}

	/**
	 * Email the user the link that signs them out everywhere.
	 *
	 * @since 3.4.0
	 *
	 * @param WP_User $user User to email.
	 * @param string  $key  Unlock key.
	 *
	 * @return bool Whether the email was handed to the mailer.
	 */
	private function send( WP_User $user, string $key ): bool {
		$site = wp_specialchars_decode( (string) get_option( 'blogname' ), ENT_QUOTES );

		$email = array(
			'to'      => $user->user_email,
			/* translators: %s: Site title. */
			'subject' => sprintf( __( '[%s] Sign out of your other devices', 'loggedin' ), $site ),
			'message' => implode(
				"\n\n",
				array(
					sprintf(
						/* translators: 1: Username, 2: Site title. */
						__( 'Someone, hopefully you, tried to log in as %1$s on %2$s. The account is already signed in on as many devices as the site allows.', 'loggedin' ),
						$user->user_login,
						$site
					),
					__( 'To sign out of every device and log in again, open this link within 15 minutes:', 'loggedin' ),
					$this->url( 'loggedin_unlock', $user->ID, $key ),
					__( 'If this wasn’t you, ignore this email: nothing changes, and you should change your password.', 'loggedin' ),
				)
			),
		);

		/**
		 * Filter the unlock email before it is sent.
		 *
		 * @since 3.4.0
		 *
		 * @param array   $email `to`, `subject` and `message`.
		 * @param WP_User $user  User being emailed.
		 */
		$email = (array) apply_filters( 'loggedin_unlock_email', $email, $user );

		return (bool) wp_mail( $email['to'], $email['subject'], $email['message'] );
	}

	/**
	 * Address of one of the unlock steps on the login screen.
	 *
	 * @since 3.4.0
	 *
	 * @param string $action  Login screen action.
	 * @param int    $user_id User id.
	 * @param string $key     Key.
	 *
	 * @return string URL.
	 */
	private function url( string $action, int $user_id, string $key ): string {
		return add_query_arg(
			array(
				'action'       => $action,
				'user'         => $user_id,
				// Not `key`: wp-login.php treats any `key` as a password reset.
				'loggedin_key' => $key,
			),
			wp_login_url()
		);
	}

	/**
	 * Read the user id and key from the current link.
	 *
	 * @since 3.4.0
	 *
	 * @return array{0: int, 1: string}
	 */
	private function read_link(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- The key in the link is the proof; a nonce can't cross an email.
		$user_id = isset( $_GET['user'] ) ? absint( $_GET['user'] ) : 0;
		$key     = isset( $_GET['loggedin_key'] ) ? preg_replace( '/[^A-Za-z0-9]/', '', sanitize_text_field( wp_unslash( $_GET['loggedin_key'] ) ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		return array( $user_id, (string) $key );
	}

	/**
	 * Return to the login screen with a message, and stop.
	 *
	 * @since 3.4.0
	 *
	 * @param string $state Message to show: a key of {@see self::login_message()}'s list.
	 *
	 * @return void
	 */
	private function finish( string $state ): void {
		wp_safe_redirect( add_query_arg( self::STATE_ARG, $state, wp_login_url() ) );
		exit;
	}
}
