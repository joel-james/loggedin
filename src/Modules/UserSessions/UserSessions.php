<?php
/**
 * Sessions on the Users screen and on profiles.
 *
 * @since 3.4.0
 * @package FoxeLabs\Loggedin\Modules\UserSessions
 */

declare( strict_types = 1 );

namespace FoxeLabs\Loggedin\Modules\UserSessions;

use FoxeLabs\Loggedin\Modules\Module;
use FoxeLabs\Loggedin\Sessions\LastLogin;
use FoxeLabs\Loggedin\Sessions\SessionStore;

defined( 'WPINC' ) || die;

/**
 * The Sessions and Last login columns on Users, and "Your sessions" on profiles.
 *
 * Both are wp-admin screens, so the hooks are only added there.
 *
 * @since 3.4.0
 */
final class UserSessions implements Module {

	/**
	 * Users screen columns and actions.
	 *
	 * @since 3.4.0
	 *
	 * @var UsersList
	 */
	private UsersList $users_list;

	/**
	 * Sessions on the profile screen.
	 *
	 * @since 3.4.0
	 *
	 * @var ProfileSessions
	 */
	private ProfileSessions $profile_sessions;

	/**
	 * Build the module's parts.
	 *
	 * @since 3.4.0
	 *
	 * @param SessionStore $store      Session store.
	 * @param LastLogin    $last_login Last login record.
	 */
	public function __construct( SessionStore $store, LastLogin $last_login ) {
		$this->users_list       = new UsersList( $last_login, $store );
		$this->profile_sessions = new ProfileSessions( $store );
	}

	/**
	 * Add the module's hooks, in wp-admin only.
	 *
	 * @since 3.4.0
	 *
	 * @return void
	 */
	public function register(): void {
		if ( ! is_admin() ) {
			return;
		}

		$this->users_list->register();
		$this->profile_sessions->register();
	}

	/**
	 * Users screen columns and actions.
	 *
	 * @since 3.4.0
	 *
	 * @return UsersList
	 */
	public function users_list(): UsersList {
		return $this->users_list;
	}

	/**
	 * Sessions on the profile screen.
	 *
	 * @since 3.4.0
	 *
	 * @return ProfileSessions
	 */
	public function profile_sessions(): ProfileSessions {
		return $this->profile_sessions;
	}
}
