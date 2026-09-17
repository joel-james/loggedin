<?php
/**
 * Plugin entry file.
 *
 * Defines the path / file constants, loads the Composer autoloader,
 * loads the text domain, and hands off to {@see Core::instance()} to
 * boot every module in phased order.
 *
 * This file is intentionally minimal — the rule of thumb is "no
 * runtime logic in the bootstrap." Each subsystem (settings storage,
 * session guard, admin UI, REST API, Freemius) lives behind its own
 * class under `includes/` and registers its own hooks from there.
 *
 * @package           FoxeLabs\Loggedin
 * @author            Joel James
 * @copyright         2025 Joel James
 * @license           GPL-2.0+
 *
 * @wordpress-plugin
 * Plugin Name:       Loggedin - Session Manager, Limit Concurrent Logins & Force Logout
 * Plugin URI:        https://foxelabs.com/software/plugins/loggedin
 * Description:       Limit an account to a specific number of simultaneous logins across all devices.
 * Version:           3.3.0
 * Author:            Joel James
 * Author URI:        https://foxelabs.com/
 * Donate link:       https://paypal.me/JoelCJ
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       loggedin
 * Domain Path:       /languages
 * Requires PHP:      7.4
 * Requires at least: 6.0
 */

namespace FoxeLabs\Loggedin;

defined( 'WPINC' ) || die;

/**
 * Absolute filesystem path to the plugin directory, with trailing slash.
 *
 * Used everywhere we need to include or read a file shipped inside
 * the plugin (the autoloader, the React bundle in `build/`, the
 * language files, etc.). Defined here once so individual classes
 * can read it via `Plugin::dir()` without ever recomputing it.
 */
define( 'LOGGEDIN_DIR', plugin_dir_path( __FILE__ ) );

/**
 * Absolute filesystem path to *this file*.
 *
 * Freemius needs the main plugin file path during instance creation
 * (it derives the plugin basename from it). Captured here so we don't
 * have to thread it through every constructor.
 */
define( 'LOGGEDIN_FILE', __FILE__ );

if ( ! function_exists( __NAMESPACE__ . '\\init' ) ) {
	/**
	 * Boot the plugin once core has finished loading.
	 *
	 * Wrapped in a `function_exists` guard so a second copy of the
	 * plugin loaded from a different directory (a stray symlink, a
	 * dev clone) can't redefine the function and silently shadow the
	 * first init call. The function:
	 *
	 *   1. Pulls in the Composer autoloader.
	 *   2. Hands off to {@see Core::instance()}, which is responsible
	 *      for wiring every module in order.
	 *
	 * The text domain is not loaded here: since WordPress 4.6 core
	 * loads translations for a wordpress.org-hosted plugin on first
	 * use, keyed on the `Text Domain` header.
	 *
	 * @since 2.0.0
	 *
	 * @return void
	 */
	function init(): void {
		require __DIR__ . '/vendor/autoload.php';

		alias_legacy_classes();

		Core::instance();
	}
}

if ( ! function_exists( __NAMESPACE__ . '\\alias_legacy_classes' ) ) {
	/**
	 * Keep the pre-3.2.0 `DuckDev\Loggedin` class names resolvable.
	 *
	 * The namespace moved to `FoxeLabs\Loggedin` with the Duck Dev to
	 * Foxe Labs rebrand. Add-ons are separate plugins that WordPress
	 * updates independently, so a site can — and often will — run the
	 * new parent alongside an add-on built against the old names.
	 *
	 * `Plugin` is the only parent class add-ons reference across the
	 * boundary: each one guards its boot with
	 * `class_exists( '\DuckDev\Loggedin\Plugin' )`, and Active Sessions
	 * also imports it. Without the alias that guard reports false and
	 * every add-on silently disables itself. Everything else crossing
	 * the boundary is a `loggedin_`-prefixed hook, and those names did
	 * not change.
	 *
	 * Safe to drop once all four add-ons have shipped a release built
	 * against `FoxeLabs\Loggedin` and had time to roll out.
	 *
	 * @since 3.2.0
	 *
	 * @return void
	 */
	function alias_legacy_classes(): void {
		if ( ! class_exists( '\\DuckDev\\Loggedin\\Plugin', false ) ) {
			class_alias( Plugin::class, '\\DuckDev\\Loggedin\\Plugin' );
		}
	}
}

add_action( 'plugins_loaded', __NAMESPACE__ . '\\init' );
