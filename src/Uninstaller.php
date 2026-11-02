<?php
/**
 * Uninstall routine.
 *
 * @since 3.4.0
 * @package FoxeLabs\Loggedin
 */

declare( strict_types = 1 );

namespace FoxeLabs\Loggedin;

use FoxeLabs\Loggedin\Admin\ExemptAdminsNotice;
use FoxeLabs\Loggedin\Modules\AutoLogout\Settings as AutoLogoutSettings;
use FoxeLabs\Loggedin\Modules\Lockout\Displaced;
use FoxeLabs\Loggedin\Modules\Lockout\Unlock;
use FoxeLabs\Loggedin\Sessions\LastLogin;
use FoxeLabs\Loggedin\Sessions\LogoutEpoch;

defined( 'WPINC' ) || die;

/**
 * Removes Loggedin's data when the plugin is deleted.
 *
 * Leaves the site as it would be had Loggedin never been installed.
 * `session_tokens` stays: it is WordPress's own data, and deleting it
 * would sign every user out for no reason.
 *
 * Runs without the plugin booted, so it reads only class constants
 * and never builds a service.
 *
 * @since 3.4.0
 */
final class Uninstaller {

	/**
	 * Options stored per site.
	 *
	 * Deleting the logout epoch means a session signed out by "Sign
	 * out all users" whose owner never came back works again if its
	 * cookie hasn't expired. Acceptable: the plugin enforcing that
	 * logout is going away.
	 *
	 * @since 3.4.0
	 *
	 * @var string[]
	 */
	private const OPTIONS = array(
		Plugin::OPTION_KEY,
		Plugin::VERSION_KEY,
		LogoutEpoch::OPTION,
		AutoLogoutSettings::OPTION_KEY,
		AutoLogoutSettings::ROLE_OPTION_KEY,
		AutoLogoutSettings::CHANGED_OPTION_KEY,
		ExemptAdminsNotice::OPTION,
		// Before 3.0, when each setting had its own option.
		'loggedin_maximum',
		'loggedin_logic',
		// Before 3.0.2, when the review notice had its own keys.
		'loggedin_rating_notice',
	);

	/**
	 * Options stored once per network.
	 *
	 * @since 3.4.0
	 *
	 * @var string[]
	 */
	private const NETWORK_OPTIONS = array(
		'loggedin_review_time',
	);

	/**
	 * User meta keys. User meta is shared by every site on a network.
	 *
	 * @since 3.4.0
	 *
	 * @var string[]
	 */
	private const USER_META = array(
		LastLogin::META_KEY,
		AutoLogoutSettings::ACTIVITY_META_KEY,
		Displaced::META_KEY,
		Unlock::REQUEST_META,
		Unlock::UNLOCK_META,
		Unlock::SENT_META,
		'loggedin_review_dismissed',
		// Before 3.0.2.
		'loggedin_rating_notice_dismissed',
	);

	/**
	 * Remove everything, on every site of a network.
	 *
	 * @since 3.4.0
	 *
	 * @return void
	 */
	public function run(): void {
		if ( is_multisite() ) {
			$site_ids = get_sites(
				array(
					'fields' => 'ids',
					'number' => 0,
				)
			);

			foreach ( $site_ids as $site_id ) {
				switch_to_blog( (int) $site_id );
				$this->remove_site_options();
				restore_current_blog();
			}
		} else {
			$this->remove_site_options();
		}

		foreach ( self::NETWORK_OPTIONS as $option ) {
			delete_site_option( $option );
		}

		foreach ( self::USER_META as $key ) {
			delete_metadata( 'user', 0, $key, '', true );
		}

		/**
		 * Fires after Loggedin has removed its data on uninstall.
		 *
		 * Add-ons clean up after themselves in their own uninstall
		 * routine; this is for code that extends Loggedin from a theme
		 * or a site plugin.
		 *
		 * @since 3.4.0
		 */
		do_action( 'loggedin_uninstalled' );
	}

	/**
	 * Remove the current site's options.
	 *
	 * @since 3.4.0
	 *
	 * @return void
	 */
	private function remove_site_options(): void {
		foreach ( self::OPTIONS as $option ) {
			delete_option( $option );
		}
	}
}
