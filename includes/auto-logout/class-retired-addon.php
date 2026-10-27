<?php
/**
 * The retired Auto Logout add-on.
 *
 * Auto logout used to be a paid add-on (`loggedin-auto-logout` 1.0).
 * It is part of core now, with the same option, meta and hook names, so
 * a site that still has the add-on active must not run both: core stops
 * the add-on from booting and takes over with the settings it left
 * behind, then asks the admin to remove it.
 *
 * @package FoxeLabs\Loggedin\Auto_Logout
 */

declare( strict_types = 1 );

namespace FoxeLabs\Loggedin\Auto_Logout;

use FoxeLabs\Loggedin\Contracts\Singleton;

defined( 'WPINC' ) || die;

/**
 * Keeps the retired add-on from running beside core.
 *
 * @since 3.4.0
 */
final class Retired_Addon {

	use Singleton;

	/**
	 * The add-on's bootstrap class.
	 *
	 * No leading backslash: the add-on hooks `Plugin::class`, and
	 * `remove_action()` only matches the same spelling.
	 *
	 * @since 3.4.0
	 */
	private const ADDON_CLASS = 'FoxeLabs\\Loggedin\\AutoLogout\\Plugin';

	/**
	 * The add-on's Freemius id, left out of the Add-ons page.
	 *
	 * @since 3.4.0
	 */
	public const FREEMIUS_ID = 38081;

	/**
	 * Register hooks.
	 *
	 * Runs while core boots, before `loggedin_init` fires, so the
	 * add-on's own `loggedin_init` callback can still be taken off.
	 *
	 * @since 3.4.0
	 *
	 * @return void
	 */
	protected function init(): void {
		if ( ! $this->is_active() ) {
			return;
		}

		// The add-on registers its settings, idle guard, session length
		// and admin panel from this one callback.
		remove_action( 'loggedin_init', array( self::ADDON_CLASS, 'boot' ) );

		add_action( 'admin_notices', array( $this, 'notice' ) );
	}

	/**
	 * Whether the add-on's code is loaded.
	 *
	 * @since 3.4.0
	 *
	 * @return bool
	 */
	public function is_active(): bool {
		return class_exists( self::ADDON_CLASS );
	}

	/**
	 * Tell the admin the add-on can go.
	 *
	 * Shown on the Plugins screen and on Loggedin's own screen only, to
	 * people who can remove plugins.
	 *
	 * @since 3.4.0
	 *
	 * @return void
	 */
	public function notice(): void {
		$screen = get_current_screen();

		if (
			! current_user_can( 'activate_plugins' )
			|| ! $screen
			|| ! in_array( $screen->id, array( 'plugins', 'users_page_loggedin' ), true )
		) {
			return;
		}

		printf(
			'<div class="notice notice-info"><p><strong>%1$s</strong> %2$s</p></div>',
			esc_html__( 'Auto logout is now part of Loggedin.', 'loggedin' ),
			esc_html__( 'Your settings carried over and Loggedin runs them itself, so you can deactivate and delete the Loggedin - Auto Logout add-on.', 'loggedin' )
		);
	}
}
