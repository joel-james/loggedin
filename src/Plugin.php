<?php
/**
 * The plugin: its constants, and the one place that builds and wires
 * every service.
 *
 * `loggedin.php` calls {@see Plugin::boot()} on `plugins_loaded`. Boot
 * creates each service once, passes in what it needs, and calls its
 * `register()` to add hooks. Nothing else creates services, so a
 * class's dependencies are the ones in its constructor.
 *
 * The constants and static path helpers stay usable without an
 * instance; add-ons read `Plugin::VERSION`.
 *
 * @package FoxeLabs\Loggedin
 */

declare( strict_types = 1 );

namespace FoxeLabs\Loggedin;

use FoxeLabs\Loggedin\Addons\Addons;
use FoxeLabs\Loggedin\Addons\Catalog;
use FoxeLabs\Loggedin\Addons\OutdatedAddons;
use FoxeLabs\Loggedin\Admin\Admin;
use FoxeLabs\Loggedin\Admin\Assets;
use FoxeLabs\Loggedin\Admin\ExemptAdminsNotice;
use FoxeLabs\Loggedin\Cli\Commands;
use FoxeLabs\Loggedin\Modules\AutoLogout\AutoLogout;
use FoxeLabs\Loggedin\Modules\Lockout\Lockout;
use FoxeLabs\Loggedin\Modules\Module;
use FoxeLabs\Loggedin\Modules\PasswordSignout\PasswordSignout;
use FoxeLabs\Loggedin\Modules\UserSessions\UserSessions;
use FoxeLabs\Loggedin\Rest\AddonsEndpoint;
use FoxeLabs\Loggedin\Rest\SessionsEndpoint;
use FoxeLabs\Loggedin\Rest\SettingsEndpoint;
use FoxeLabs\Loggedin\Sessions\LastLogin;
use FoxeLabs\Loggedin\Sessions\LogoutEpoch;
use FoxeLabs\Loggedin\Sessions\SessionGuard;
use FoxeLabs\Loggedin\Sessions\SessionStore;
use FoxeLabs\Loggedin\Settings\Settings;
use FoxeLabs\Loggedin\Settings\Upgrader;

defined( 'WPINC' ) || die;

/**
 * Plugin constants, path helpers and the composition root.
 *
 * @since 3.0.0
 * @since 3.4.0 Builds and wires the services; replaces `Core`.
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
	public const VERSION = '3.4.0';

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
	 * `loggedin_logic`. See {@see Settings\Upgrader::migrate_legacy_options()}
	 * for the one-shot migration.
	 *
	 * @since 3.0.0
	 */
	public const OPTION_KEY = 'loggedin_settings';

	/**
	 * Option key storing the plugin version of the last boot.
	 *
	 * {@see Settings\Upgrader::maybe_upgrade()} compares this to
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

	/**
	 * The one instance.
	 *
	 * @since 3.4.0
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Services built so far, by class name.
	 *
	 * @since 3.4.0
	 *
	 * @var array<string, object>
	 */
	private array $services = array();

	/**
	 * Whether {@see self::boot()} has run.
	 *
	 * @since 3.4.0
	 *
	 * @var bool
	 */
	private bool $booted = false;

	/**
	 * The plugin instance.
	 *
	 * @since 3.4.0
	 *
	 * @return Plugin
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Use {@see self::instance()}.
	 *
	 * @since 3.4.0
	 */
	private function __construct() {}

	/**
	 * Build every service and add its hooks.
	 *
	 * Runs once, on `plugins_loaded`. Order matters in two places: the
	 * upgrader runs first, so everything after it reads migrated
	 * settings; and `loggedin_init` fires last, so add-ons find the
	 * plugin fully wired.
	 *
	 * @since 3.4.0
	 *
	 * @return void
	 */
	public function boot(): void {
		if ( $this->booted ) {
			return;
		}

		$this->booted = true;

		$this->upgrader()->maybe_upgrade();

		// The session limit, on every request type: logins happen on
		// wp-login, REST, AJAX and through other plugins.
		$this->settings()->register();
		$this->session_guard()->register();
		$this->logout_epoch()->register();
		$this->last_login()->register();

		foreach ( $this->modules() as $module ) {
			$module->register();
		}

		// Freemius is built lazily, so this is cheap off the admin.
		$this->addons()->register();

		if ( is_admin() ) {
			$this->admin()->register();
			$this->assets()->register();
			$this->exempt_admins_notice()->register();
			$this->outdated_addons()->register();
		}

		// Each endpoint only adds a `rest_api_init` callback here.
		$this->settings_endpoint()->register();
		$this->sessions_endpoint()->register();
		$this->addons_endpoint()->register();

		// The command classes are never loaded on a web request.
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			$this->commands()->register();
		}

		/**
		 * Fires once every service has been wired up.
		 *
		 * Add-ons should hook here to register themselves — by this
		 * point the Settings store and the REST namespace are
		 * already in place.
		 *
		 * @since 1.3.1
		 * @since 3.4.0 Passes the `Plugin` instance instead of `Core`.
		 *
		 * @param Plugin $plugin The plugin instance.
		 */
		do_action( 'loggedin_init', $this );
	}

	/**
	 * The modules, in the order they register.
	 *
	 * @since 3.4.0
	 *
	 * @return array<int, Module>
	 */
	public function modules(): array {
		return array(
			$this->auto_logout(),
			$this->lockout(),
			$this->password_signout(),
			$this->user_sessions(),
		);
	}

	/**
	 * Core settings store (`loggedin_settings`).
	 *
	 * @since 3.4.0
	 *
	 * @return Settings
	 */
	public function settings(): Settings {
		return $this->service(
			Settings::class,
			static function () {
				return new Settings();
			}
		);
	}

	/**
	 * Version migrations.
	 *
	 * @since 3.4.0
	 *
	 * @return Upgrader
	 */
	public function upgrader(): Upgrader {
		return $this->service(
			Upgrader::class,
			function () {
				return new Upgrader( $this->settings() );
			}
		);
	}

	/**
	 * Reads and ends sessions in WordPress's default storage.
	 *
	 * @since 3.4.0
	 *
	 * @return SessionStore
	 */
	public function session_store(): SessionStore {
		return $this->service(
			SessionStore::class,
			static function () {
				return new SessionStore();
			}
		);
	}

	/**
	 * Enforces the session limit.
	 *
	 * @since 3.4.0
	 *
	 * @return SessionGuard
	 */
	public function session_guard(): SessionGuard {
		return $this->service(
			SessionGuard::class,
			function () {
				return new SessionGuard( $this->settings(), $this->session_store() );
			}
		);
	}

	/**
	 * Site-wide logout.
	 *
	 * @since 3.4.0
	 *
	 * @return LogoutEpoch
	 */
	public function logout_epoch(): LogoutEpoch {
		return $this->service(
			LogoutEpoch::class,
			function () {
				return new LogoutEpoch( $this->session_store() );
			}
		);
	}

	/**
	 * Last login record.
	 *
	 * @since 3.4.0
	 *
	 * @return LastLogin
	 */
	public function last_login(): LastLogin {
		return $this->service(
			LastLogin::class,
			static function () {
				return new LastLogin();
			}
		);
	}

	/**
	 * Auto logout module.
	 *
	 * @since 3.4.0
	 *
	 * @return AutoLogout
	 */
	public function auto_logout(): AutoLogout {
		return $this->service(
			AutoLogout::class,
			static function () {
				return new AutoLogout();
			}
		);
	}

	/**
	 * Lockout & recovery module.
	 *
	 * @since 3.4.0
	 *
	 * @return Lockout
	 */
	public function lockout(): Lockout {
		return $this->service(
			Lockout::class,
			function () {
				return new Lockout( $this->settings(), $this->session_store() );
			}
		);
	}

	/**
	 * Sign out on password change module.
	 *
	 * @since 3.4.0
	 *
	 * @return PasswordSignout
	 */
	public function password_signout(): PasswordSignout {
		return $this->service(
			PasswordSignout::class,
			function () {
				return new PasswordSignout( $this->settings() );
			}
		);
	}

	/**
	 * Users screen and profile sessions module.
	 *
	 * @since 3.4.0
	 *
	 * @return UserSessions
	 */
	public function user_sessions(): UserSessions {
		return $this->service(
			UserSessions::class,
			function () {
				return new UserSessions( $this->session_store(), $this->last_login() );
			}
		);
	}

	/**
	 * Add-ons registry and Freemius wiring.
	 *
	 * @since 3.4.0
	 *
	 * @return Addons
	 */
	public function addons(): Addons {
		return $this->service(
			Addons::class,
			static function () {
				return new Addons();
			}
		);
	}

	/**
	 * Add-ons catalogue for the Add-ons page.
	 *
	 * @since 3.4.0
	 *
	 * @return Catalog
	 */
	public function catalog(): Catalog {
		return $this->service(
			Catalog::class,
			function () {
				return new Catalog( $this->addons() );
			}
		);
	}

	/**
	 * Admin menu and screen.
	 *
	 * @since 3.4.0
	 *
	 * @return Admin
	 */
	public function admin(): Admin {
		return $this->service(
			Admin::class,
			static function () {
				return new Admin();
			}
		);
	}

	/**
	 * Admin scripts and their data.
	 *
	 * @since 3.4.0
	 *
	 * @return Assets
	 */
	public function assets(): Assets {
		return $this->service(
			Assets::class,
			function () {
				return new Assets( $this->settings(), $this->addons() );
			}
		);
	}

	/**
	 * One-time notice suggesting the administrator exemption.
	 *
	 * @since 3.4.0
	 *
	 * @return ExemptAdminsNotice
	 */
	public function exempt_admins_notice(): ExemptAdminsNotice {
		return $this->service(
			ExemptAdminsNotice::class,
			function () {
				return new ExemptAdminsNotice( $this->settings() );
			}
		);
	}

	/**
	 * Notice listing add-ons too old for this screen.
	 *
	 * @since 3.4.0
	 *
	 * @return OutdatedAddons
	 */
	public function outdated_addons(): OutdatedAddons {
		return $this->service(
			OutdatedAddons::class,
			function () {
				return new OutdatedAddons( $this->addons() );
			}
		);
	}

	/**
	 * `/loggedin/v1/settings`.
	 *
	 * @since 3.4.0
	 *
	 * @return SettingsEndpoint
	 */
	public function settings_endpoint(): SettingsEndpoint {
		return $this->service(
			SettingsEndpoint::class,
			function () {
				return new SettingsEndpoint( $this->settings() );
			}
		);
	}

	/**
	 * `/loggedin/v1/sessions`.
	 *
	 * @since 3.4.0
	 *
	 * @return SessionsEndpoint
	 */
	public function sessions_endpoint(): SessionsEndpoint {
		return $this->service(
			SessionsEndpoint::class,
			static function () {
				return new SessionsEndpoint();
			}
		);
	}

	/**
	 * `/loggedin/v1/addons`.
	 *
	 * @since 3.4.0
	 *
	 * @return AddonsEndpoint
	 */
	public function addons_endpoint(): AddonsEndpoint {
		return $this->service(
			AddonsEndpoint::class,
			function () {
				return new AddonsEndpoint( $this->addons(), $this->catalog() );
			}
		);
	}

	/**
	 * `wp loggedin` commands.
	 *
	 * @since 3.4.0
	 *
	 * @return Commands
	 */
	public function commands(): Commands {
		return $this->service(
			Commands::class,
			function () {
				return new Commands( $this->session_store(), $this->settings() );
			}
		);
	}

	/**
	 * Build a service on first use and keep it.
	 *
	 * @since 3.4.0
	 *
	 * @param string   $id      Service id, its class name.
	 * @param callable $factory Builds the service.
	 *
	 * @return mixed
	 */
	private function service( string $id, callable $factory ) {
		if ( ! isset( $this->services[ $id ] ) ) {
			$this->services[ $id ] = $factory();
		}

		return $this->services[ $id ];
	}
}
