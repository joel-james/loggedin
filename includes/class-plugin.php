<?php
/**
 * Static plugin metadata container.
 *
 * Every module needs access to a small set of constants (slug, text
 * domain, REST namespace, option keys) and a couple of derived paths
 * (the main file, the plugin URL). Collecting them on one final
 * class means:
 *
 *   - Constants live in one place; renaming the slug or bumping the
 *     version touches a single file.
 *   - There's nothing to instantiate, nothing to mock — the class is
 *     final and has no state, so it's safe to call from any context
 *     (REST request, CLI, cron).
 *
 * @package FoxeLabs\Loggedin
 */

declare( strict_types = 1 );

namespace FoxeLabs\Loggedin;

defined( 'WPINC' ) || die;

/**
 * Plugin-wide constants and path helpers.
 *
 * @since 3.0.0
 */
final class Plugin {

	/**
	 * Current plugin version.
	 *
	 * Kept in sync with the `Version:` header in `loggedin.php` and
	 * the `version` field in `composer.json` / `package.json`. The
	 * value also seeds the asset cache-bust query string when the
	 * wp-scripts manifest is missing.
	 *
	 * @since 3.0.0
	 */
	public const VERSION = '3.3.0';

	/**
	 * Plugin slug used in menu URLs, asset handles and CSS classes.
	 *
	 * @since 3.0.0
	 */
	public const SLUG = 'loggedin';

	/**
	 * Text domain for `__()` / `_e()` calls.
	 *
	 * Kept as a separate constant from `SLUG` (even though they share
	 * the same value today) so a future rebrand of the menu slug
	 * doesn't accidentally break every translation lookup.
	 *
	 * @since 3.0.0
	 */
	public const TEXT_DOMAIN = 'loggedin';

	/**
	 * Freemius plugin id for the Loggedin product.
	 *
	 * @since 3.0.0
	 */
	public const FREEMIUS_ID = 19328;

	/**
	 * Option key holding the unified settings array.
	 *
	 * Replaces the pre-3.0 single-key options `loggedin_maximum` and
	 * `loggedin_logic`. See {@see Setup\Upgrader::migrate_legacy_options()}
	 * for the one-shot migration.
	 *
	 * @since 3.0.0
	 */
	public const OPTION_KEY = 'loggedin_settings';

	/**
	 * Option key storing the plugin version of the last boot.
	 *
	 * {@see Setup\Upgrader::maybe_upgrade()} compares this to
	 * {@see self::VERSION} on every load to decide whether to run a
	 * data migration.
	 *
	 * @since 3.0.0
	 */
	public const VERSION_KEY = 'loggedin_version';

	/**
	 * REST API namespace (without leading slash).
	 *
	 * Routes registered by `Api\*` controllers are prefixed with this
	 * value, and `Admin\Assets` localises `restUrl` to the resolved
	 * `rest_url( self::REST_NAMESPACE . '/' )` so React code can call
	 * `apiFetch({ path: 'settings' })` without hard-coding the
	 * namespace.
	 *
	 * @since 3.0.0
	 */
	public const REST_NAMESPACE = 'loggedin/v1';

	/**
	 * Absolute path to the main plugin file.
	 *
	 * Freemius and `plugin_basename()` both need this to resolve the
	 * plugin's identity inside WordPress. Wrapper around the
	 * `LOGGEDIN_FILE` constant defined in `loggedin.php` so callers
	 * don't have to know which side defines what.
	 *
	 * @since 3.0.0
	 *
	 * @return string
	 */
	public static function file(): string {
		return LOGGEDIN_FILE;
	}

	/**
	 * Absolute filesystem path to the plugin directory (with slash).
	 *
	 * @since 3.0.0
	 *
	 * @return string
	 */
	public static function dir(): string {
		return LOGGEDIN_DIR;
	}

	/**
	 * Public URL to the plugin directory (with trailing slash).
	 *
	 * @since 3.0.0
	 *
	 * @return string
	 */
	public static function url(): string {
		return plugin_dir_url( self::file() );
	}

	/**
	 * `plugin_basename()` of the main plugin file — e.g.
	 * `loggedin/loggedin.php`.
	 *
	 * Used wherever WordPress identifies plugins by their basename
	 * (plugin action links, the `plugin_action_links_*` filter, etc.).
	 *
	 * @since 3.0.0
	 *
	 * @return string
	 */
	public static function basename(): string {
		return plugin_basename( self::file() );
	}
}
