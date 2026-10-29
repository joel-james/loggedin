<?php
/**
 * Admin asset enqueue.
 *
 * Loads the React admin bundle on the plugin's settings screen,
 * reading the dependency manifest produced by `wp-scripts build`,
 * and localises the `loggedin` JS global the bundle consumes.
 *
 * The bundle is intentionally enqueued for a single screen — the
 * plugin only has one admin page — but the screen check is centralised
 * here so adding a second page later is a one-line constant change.
 *
 * @package FoxeLabs\Loggedin\Admin
 */

declare( strict_types = 1 );

namespace FoxeLabs\Loggedin\Admin;

use FoxeLabs\Loggedin\Addons\Addons;
use FoxeLabs\Loggedin\Contracts\Singleton;
use FoxeLabs\Loggedin\Modules\AutoLogout\Settings as AutoLogoutSettings;
use FoxeLabs\Loggedin\Modules\UserSessions\ProfileSessions;
use FoxeLabs\Loggedin\Modules\UserSessions\UsersList;
use FoxeLabs\Loggedin\Plugin;
use FoxeLabs\Loggedin\Sessions\SessionGuard;
use FoxeLabs\Loggedin\Settings\Settings;

defined( 'WPINC' ) || die;

/**
 * Enqueues the React admin bundle and the localised script vars.
 *
 * @since 3.0.0
 */
final class Assets {

	use Singleton;

	/**
	 * Entry-file basename emitted by `wp-scripts build`.
	 *
	 * The build script in `package.json` compiles
	 * `assets/src/admin.js` → `build/admin.{js,css,asset.php}`. Bump
	 * this if the entry file is renamed.
	 *
	 * @since 3.0.0
	 */
	private const ENTRY = 'admin';

	/**
	 * Script + style handle prefix used in `wp_enqueue_*`.
	 *
	 * @since 3.0.0
	 */
	private const HANDLE = 'loggedin-admin';

	/**
	 * Admin screen id where the bundle should load.
	 *
	 * `add_users_page()` produces a hook of the form
	 * `users_page_<slug>` — we hard-code the resolved value rather
	 * than rebuilding it each request to keep the comparison cheap.
	 *
	 * @since 3.0.0
	 */
	private const SCREEN = 'users_page_loggedin';

	/**
	 * Register hooks.
	 *
	 * @since 3.0.0
	 *
	 * @return void
	 */
	protected function init(): void {
		add_action( 'admin_enqueue_scripts', array( $this, 'register_jsx_runtime' ), 0 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Register `react-jsx-runtime` where WordPress doesn't (6.5).
	 *
	 * The admin bundle, and the bundles of add-ons that draw on this
	 * screen, depend on it. WordPress registers it from 6.6; without it,
	 * 6.5 drops those bundles and the screen stays blank. Runs before
	 * any bundle is enqueued, and only on this screen.
	 *
	 * @since 3.4.0
	 *
	 * @param string $hook Current admin screen hook.
	 *
	 * @return void
	 */
	public function register_jsx_runtime( string $hook ): void {
		if ( self::SCREEN !== $hook || wp_script_is( 'react-jsx-runtime', 'registered' ) ) {
			return;
		}

		$asset = $this->manifest( Plugin::dir() . 'build/react-jsx-runtime.asset.php' );

		wp_register_script(
			'react-jsx-runtime',
			Plugin::url() . 'build/react-jsx-runtime.js',
			array( 'react' ),
			$asset['version'],
			true
		);
	}

	/**
	 * Enqueue the React bundle on the plugin admin page.
	 *
	 * @since 3.0.0
	 *
	 * @param string $hook Current admin screen hook.
	 *
	 * @return void
	 */
	public function enqueue( string $hook ): void {
		if ( self::SCREEN !== $hook ) {
			return;
		}

		$build_dir = Plugin::dir() . 'build/';
		$build_url = Plugin::url() . 'build/';
		$asset     = $this->manifest( $build_dir . self::ENTRY . '.asset.php' );

		wp_enqueue_script(
			self::HANDLE,
			$build_url . self::ENTRY . '.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);

		wp_set_script_translations(
			self::HANDLE,
			Plugin::TEXT_DOMAIN,
			Plugin::dir() . 'languages'
		);

		wp_localize_script( self::HANDLE, 'loggedin', $this->script_vars() );

		$css_path = $build_dir . self::ENTRY . '.css';
		if ( is_readable( $css_path ) ) {
			wp_enqueue_style(
				self::HANDLE,
				$build_url . self::ENTRY . '.css',
				array( 'wp-components' ),
				$asset['version']
			);
			wp_style_add_data( self::HANDLE, 'rtl', 'replace' );
		}
	}

	/**
	 * Read the `*.asset.php` manifest emitted by `wp-scripts`.
	 *
	 * The manifest declares the script's dependency list (e.g.
	 * `wp-element`, `wp-components`) and a content-hash version
	 * string. Falls back to a hand-curated dependency list and the
	 * plugin version when the build artefact is missing — useful
	 * during development before the first `npm run build`.
	 *
	 * @since 3.0.0
	 *
	 * @param string $path Absolute path to `<entry>.asset.php`.
	 *
	 * @return array{dependencies: array<string>, version: string}
	 */
	private function manifest( string $path ): array {
		if ( is_readable( $path ) ) {
			$manifest = include $path;

			if ( is_array( $manifest ) ) {
				return array(
					'dependencies' => $manifest['dependencies'] ?? array(),
					'version'      => $manifest['version'] ?? Plugin::VERSION,
				);
			}
		}

		return array(
			'dependencies' => array(
				'wp-api-fetch',
				'wp-components',
				'wp-core-data',
				'wp-data',
				'wp-dom-ready',
				'wp-element',
				'wp-hooks',
				'wp-i18n',
				'wp-notices',
			),
			'version'      => Plugin::VERSION,
		);
	}

	/**
	 * Build the `loggedin` JS global the React app consumes.
	 *
	 * Filterable so add-ons can inject their own payload (feature
	 * flags, license state, etc.) without forking this file.
	 *
	 * @since 3.0.0
	 *
	 * @return array<string, mixed>
	 */
	private function script_vars(): array {
		$vars = array(
			'version'        => Plugin::VERSION,
			'slug'           => Plugin::SLUG,
			'name'           => __( 'Loggedin', 'loggedin' ),
			'page'           => self::ENTRY,
			'restUrl'        => esc_url_raw( rest_url( Plugin::REST_NAMESPACE . '/' ) ),
			'restNonce'      => wp_create_nonce( 'wp_rest' ),
			'adminUrl'       => esc_url_raw( admin_url() ),
			'homeUrl'        => esc_url_raw( home_url( '/' ) ),
			'wpVersion'      => get_bloginfo( 'version' ),
			'phpVersion'     => PHP_VERSION,
			'urls'           => array(
				'docs'     => 'https://docs.foxelabs.com/software/loggedin/',
				'support'  => 'https://wordpress.org/support/plugin/loggedin/',
				'review'   => 'https://wordpress.org/support/plugin/loggedin/reviews/#new-post',
				'contact'  => 'https://foxelabs.com/contact',
				'foxelabs' => 'https://foxelabs.com',
			),
			'logics'         => $this->logics_payload(),
			'defaultMessage' => SessionGuard::default_message(),
			'activeAddons'   => $this->active_addon_slugs(),
			'roles'          => array_map( 'translate_user_role', wp_roles()->get_names() ),
			'autoLogout'     => array(
				'max' => array(
					'idle_timeout'      => AutoLogoutSettings::MAX_IDLE_TIMEOUT,
					'warning'           => AutoLogoutSettings::MAX_WARNING,
					'session_lifetime'  => AutoLogoutSettings::MAX_SESSION_LIFETIME,
					'remember_lifetime' => AutoLogoutSettings::MAX_REMEMBER_LIFETIME,
				),
			),
			'schema'         => $this->settings_schema(),
		);

		/**
		 * Filter the localised script vars for the admin app.
		 *
		 * @since 3.0.0
		 * @since 3.4.0 Adds `wpVersion`, `phpVersion`, `urls`, `defaultMessage`, `homeUrl`, `activeAddons`, `roles`, `autoLogout` and `schema`.
		 *
		 * @param array $vars Vars passed into the React tree.
		 */
		return (array) apply_filters( 'loggedin_admin_script_vars', $vars );
	}

	/**
	 * Shape the login-logic catalogue for the React select.
	 *
	 * Reads the same `loggedin_logics` filter that the legacy template
	 * code used, so addons that extend the list continue to work.
	 *
	 * @since 3.0.0
	 * @since 3.4.0 Built by {@see Settings::logics()}, which also decides what can be saved.
	 *
	 * @return array<string, array{label: string, desc: string}>
	 */
	private function logics_payload(): array {
		return Settings::instance()->logics();
	}

	/**
	 * Slugs of the add-ons installed and registered on this site.
	 *
	 * The admin app hides its one-line add-on hints for anything listed
	 * here, so a site never sees a pitch for an add-on it already runs.
	 *
	 * @since 3.4.0
	 *
	 * @return array<int, string>
	 */
	private function active_addon_slugs(): array {
		$slugs = array();

		foreach ( Addons::instance()->get_registered_addons_public() as $args ) {
			if ( ! empty( $args['slug'] ) ) {
				$slugs[] = (string) $args['slug'];
			}
		}

		return $slugs;
	}

	/**
	 * Pages, cards and fields declared by add-ons in PHP.
	 *
	 * Lets an add-on put its settings on the screen without shipping
	 * an admin bundle: core draws the fields with its own controls and
	 * the page header's Save saves them. Each field's `option` must be
	 * registered with `show_in_rest`.
	 *
	 * Shape:
	 *
	 *     array(
	 *         'pages'  => array(
	 *             array( 'id' => 'my-page', 'title' => '…', 'group' => 'settings', 'icon' => 'clock', 'after' => 'login-limits' ),
	 *         ),
	 *         'cards'  => array(
	 *             array( 'id' => 'my-card', 'page' => 'my-page', 'title' => '…', 'description' => '…' ),
	 *         ),
	 *         'fields' => array(
	 *             array( 'card' => 'my-card', 'option' => 'my_option', 'key' => 'minutes', 'type' => 'number', 'label' => '…', 'unit' => 'minutes', 'help' => '…', 'min' => 1 ),
	 *         ),
	 *     )
	 *
	 * A page joins the sidebar group `group` names: `settings` (the
	 * default), `manage` or `more`. A card goes on the page `page` names,
	 * a core page (`login-limits`, `lockout`, `auto-logout`, `sessions`) or one
	 * declared here; without one it goes on Login limits. Field `type`
	 * is one of `number`, `select`, `toggle`, `text` or `role-table`.
	 * `number` takes `unit`, `min` and `max`; `select` takes `options`
	 * (`value`, `label`, optional `help`) and `cast` (`int`); any field
	 * takes `default` and `show_if` (`key`, `equals`).
	 *
	 * A field with a `key` edits that key of an object option. Leave
	 * `key` out to edit a whole option that holds one value. A
	 * `role-table` always edits a whole option holding a
	 * `{ role: number }` map, one row per role, and takes `label` (the
	 * number's), `unit`, `min`, `max`, `help`, `add_label`, and `default`
	 * or `default_from` (an `option.key` such as
	 * `loggedin_settings.maximum`) for a new row's number. A `number`
	 * field may add `unit_one`, the unit's singular, used when the value
	 * is 1.
	 *
	 * @since 3.4.0
	 *
	 * @return array{pages: array<int, array>, cards: array<int, array>, fields: array<int, array>}
	 */
	private function settings_schema(): array {
		/**
		 * Filter the settings pages, cards and fields add-ons declare.
		 *
		 * @since 3.4.0
		 *
		 * @param array $schema `pages`, `cards` and `fields` lists.
		 */
		$schema = (array) apply_filters(
			'loggedin_settings_schema',
			array(
				'pages'  => array(),
				'cards'  => array(),
				'fields' => array(),
			)
		);

		$lists = array();

		foreach ( array( 'pages', 'cards', 'fields' ) as $key ) {
			$lists[ $key ] = array_values( array_filter( (array) ( $schema[ $key ] ?? array() ), 'is_array' ) );
		}

		return $lists;
	}
}
