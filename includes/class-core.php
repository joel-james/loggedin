<?php
/**
 * Plugin bootstrap.
 *
 * `Core` is the singleton entry point invoked from `loggedin.php`.
 * Its only job is to wire up each module in a predictable order so
 * downstream modules can rely on their dependencies being available.
 *
 * Boot phases:
 *   1. {@see always()} — modules for every request type: the Settings
 *      store and Upgrader, plus the auth-pipeline enforcement
 *      (Session_Guard, Logout_Epoch). Not gated on `is_admin()` —
 *      logins and session checks happen on every request kind.
 *   2. {@see admin()} — wp-admin only: menu, page, asset enqueue.
 *   3. {@see addons()} — Freemius wiring. Loaded everywhere; the
 *      Freemius instances themselves are built lazily so non-admin
 *      requests don't pay for them unless a REST endpoint asks.
 *   4. {@see api()} — REST controllers. Always loaded; they only
 *      register routes on `rest_api_init`.
 *   5. {@see cli()} — WP-CLI commands. Only wired on CLI requests, so
 *      web traffic never loads the command classes.
 *
 * The `loggedin_init` action fires once boot completes so add-ons can
 * register their own modules with the same lifecycle.
 *
 * @package FoxeLabs\Loggedin
 */

declare( strict_types = 1 );

namespace FoxeLabs\Loggedin;

use FoxeLabs\Loggedin\Addons\Addons;
use FoxeLabs\Loggedin\Admin\Admin;
use FoxeLabs\Loggedin\Admin\Assets;
use FoxeLabs\Loggedin\Api\Addons as Addons_Api;
use FoxeLabs\Loggedin\Api\Sessions as Sessions_Api;
use FoxeLabs\Loggedin\Api\Settings as Settings_Api;
use FoxeLabs\Loggedin\Cli\Commands;
use FoxeLabs\Loggedin\Contracts\Singleton;
use FoxeLabs\Loggedin\Front\Logout_Epoch;
use FoxeLabs\Loggedin\Front\Session_Guard;
use FoxeLabs\Loggedin\Setup\Settings;
use FoxeLabs\Loggedin\Setup\Upgrader;

defined( 'WPINC' ) || die;

/**
 * Plugin bootstrap — wires every module and fires the public boot action.
 *
 * @since 3.0.0
 */
final class Core {

	use Singleton;

	/**
	 * Wire up every module and fire the public boot action.
	 *
	 * @since 3.0.0
	 *
	 * @return void
	 */
	protected function init(): void {
		$this->always();
		$this->admin();
		$this->addons();
		$this->api();
		$this->cli();

		/**
		 * Fires once every module has been wired up.
		 *
		 * Add-ons should hook here to register themselves — by this
		 * point the Settings store and the REST namespace are
		 * already in place, so an add-on can safely call into either
		 * during its own `init`.
		 *
		 * @since 1.3.1
		 *
		 * @param Core $core The shared `Core` instance.
		 */
		do_action( 'loggedin_init', $this );
	}

	/**
	 * Modules wired on every request type — front, wp-admin, REST,
	 * AJAX, and wp-login alike.
	 *
	 * Settings and the Upgrader come first so the request-level
	 * enforcement modules (Session_Guard, Logout_Epoch) can read
	 * settings during their own `init`.
	 */
	private function always(): void {
		Settings::instance();
		Upgrader::instance();
		Session_Guard::instance();
		Logout_Epoch::instance();
	}

	/**
	 * Admin-only modules. Gated on `is_admin()` so the menu /
	 * asset registrations don't fire on REST or front-end requests.
	 */
	private function admin(): void {
		if ( is_admin() ) {
			Admin::instance();
			Assets::instance();
		}
	}

	/**
	 * Freemius wiring.
	 *
	 * Loaded everywhere — REST endpoints (which run outside
	 * `is_admin()`) need it too. The Addons module defers the actual
	 * `Freemius::get_instance()` calls until the first read, so this
	 * line is cheap on non-admin requests.
	 */
	private function addons(): void {
		Addons::instance();
	}

	/**
	 * REST controllers. Each registers its routes on `rest_api_init`,
	 * so calling `instance()` here outside of a REST context is
	 * effectively free.
	 */
	private function api(): void {
		Settings_Api::instance();
		Addons_Api::instance();
		Sessions_Api::instance();
	}

	/**
	 * WP-CLI commands.
	 *
	 * Gated on the `WP_CLI` constant so the command classes are never
	 * autoloaded on a web request — the CLI surface costs a stock
	 * install exactly nothing.
	 */
	private function cli(): void {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			Commands::instance();
		}
	}
}
