<?php
/**
 * Sign out other sessions when a password changes.
 *
 * WordPress already refuses the old logins once a password changes:
 * every auth cookie carries a fragment of the password hash, so the
 * cookies on the user's other devices stop validating. But the session
 * records behind those cookies stay in `session_tokens` until they
 * expire, and Loggedin counts them. Under Block New that can lock
 * someone out right after they reset a forgotten password, by sessions
 * that can no longer be used.
 *
 * This removes those records when the password changes. A user who
 * changes their own password keeps the session they did it from;
 * WordPress re-issues that session's cookie with the new password.
 *
 * @package FoxeLabs\Loggedin\Front
 */

declare( strict_types = 1 );

namespace FoxeLabs\Loggedin\Front;

use FoxeLabs\Loggedin\Contracts\Singleton;
use FoxeLabs\Loggedin\Setup\Settings;
use WP_Session_Tokens;
use WP_User;

defined( 'WPINC' ) || die;

/**
 * Ends the user's other sessions on a password change.
 *
 * @since 3.4.0
 */
final class Password_Signout {

	use Singleton;

	/**
	 * Register hooks.
	 *
	 * `profile_update` covers the profile and user-edit screens and
	 * `wp_update_user()` in general; `wp_set_password` covers a
	 * password reset and any direct `wp_set_password()` call.
	 *
	 * @since 3.4.0
	 *
	 * @return void
	 */
	protected function init(): void {
		add_action( 'profile_update', array( $this, 'on_profile_update' ), 10, 2 );
		add_action( 'wp_set_password', array( $this, 'on_set_password' ), 10, 2 );
	}

	/**
	 * End the other sessions when a profile save changed the password.
	 *
	 * @since 3.4.0
	 *
	 * @param int     $user_id       User id.
	 * @param WP_User $old_user_data User as it was before the save.
	 *
	 * @return void
	 */
	public function on_profile_update( $user_id, $old_user_data ): void {
		$user = get_userdata( (int) $user_id );

		if ( ! $user || ! $old_user_data instanceof WP_User || $user->user_pass === $old_user_data->user_pass ) {
			return;
		}

		$this->sign_out( (int) $user_id );
	}

	/**
	 * End every session when a password is set directly or reset.
	 *
	 * @since 3.4.0
	 *
	 * @param string $password New password. Unused.
	 * @param int    $user_id  User id.
	 *
	 * @return void
	 */
	public function on_set_password( $password, $user_id ): void {
		unset( $password );

		$this->sign_out( (int) $user_id );
	}

	/**
	 * End the user's sessions, keeping the current one if it is theirs.
	 *
	 * @since 3.4.0
	 *
	 * @param int $user_id User id.
	 *
	 * @return void
	 */
	private function sign_out( int $user_id ): void {
		if ( $user_id <= 0 || ! Settings::instance()->get( 'password_signout', true ) ) {
			return;
		}

		$manager = WP_Session_Tokens::get_instance( $user_id );
		$token   = get_current_user_id() === $user_id ? wp_get_session_token() : '';

		if ( '' !== $token ) {
			$manager->destroy_others( $token );
		} else {
			$manager->destroy_all();
		}

		/**
		 * Fires after a password change ended the user's other sessions.
		 *
		 * @since 3.4.0
		 *
		 * @param int $user_id User whose password changed.
		 */
		do_action( 'loggedin_password_signout', $user_id );
	}
}
