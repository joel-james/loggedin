<?php
/**
 * Last login time per user.
 *
 * One user meta value, written once per login, read by the Users list's
 * "Last login" column. Nothing else writes it, and it never touches
 * `session_tokens`.
 *
 * @package FoxeLabs\Loggedin\Front
 */

declare( strict_types = 1 );

namespace FoxeLabs\Loggedin\Front;

use FoxeLabs\Loggedin\Contracts\Singleton;
use WP_User;

defined( 'WPINC' ) || die;

/**
 * Records when each user last logged in.
 *
 * @since 3.4.0
 */
final class Last_Login {

	use Singleton;

	/**
	 * User meta holding the Unix time of the last login.
	 *
	 * @since 3.4.0
	 */
	public const META_KEY = 'loggedin_last_login';

	/**
	 * Register hooks.
	 *
	 * @since 3.4.0
	 *
	 * @return void
	 */
	protected function init(): void {
		add_action( 'wp_login', array( $this, 'record' ), 10, 2 );
	}

	/**
	 * Stamp the login time.
	 *
	 * @since 3.4.0
	 *
	 * @param string  $user_login Username. Unused.
	 * @param WP_User $user       User who logged in.
	 *
	 * @return void
	 */
	public function record( $user_login, $user ): void {
		unset( $user_login );

		if ( $user instanceof WP_User ) {
			update_user_meta( $user->ID, self::META_KEY, time() );
		}
	}

	/**
	 * When the user last logged in, or 0 when no login is recorded.
	 *
	 * @since 3.4.0
	 *
	 * @param int $user_id User id.
	 *
	 * @return int Unix time.
	 */
	public function get( int $user_id ): int {
		return (int) get_user_meta( $user_id, self::META_KEY, true );
	}
}
