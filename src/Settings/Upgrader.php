<?php
/**
 * Plugin upgrader.
 *
 * Runs once per version bump on the next request after an update,
 * performing any data migrations needed to keep stored state in sync
 * with the current schema: the pre-3.0 single-key options, the review
 * notice and Freemius keys renamed by their libraries, and the 3.4.0
 * defaults an upgraded site keeps off. A new version appends its own
 * step inside `maybe_upgrade()`.
 *
 * The version stamp lives in its own option (`loggedin_version`) so
 * we don't have to dig through the main settings array to detect a
 * version change.
 *
 * @since 3.0.0
 * @package FoxeLabs\Loggedin\Settings
 */

declare( strict_types = 1 );

namespace FoxeLabs\Loggedin\Settings;

use FoxeLabs\Freemius\Storage\ActivationRepository;
use FoxeLabs\Loggedin\Admin\ExemptAdminsNotice;
use FoxeLabs\Loggedin\Modules\AutoLogout\Settings as AutoLogoutSettings;
use FoxeLabs\Loggedin\Plugin;
use FoxeLabs\Loggedin\Sessions\LogoutEpoch;

defined( 'WPINC' ) || die;

/**
 * Schema upgrader — migrates legacy options to the unified store.
 *
 * @since 3.0.0
 */
final class Upgrader {

	/**
	 * Settings store.
	 *
	 * @since 3.4.0
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Set up the dependencies.
	 *
	 * @since 3.4.0
	 *
	 * @param Settings $settings Settings store.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Run any pending migrations and stamp the new version.
	 *
	 * Short-circuits when the stored version already matches the
	 * running version, so steady-state requests pay nothing beyond a
	 * single `get_option()` call.
	 *
	 * @since 3.0.0
	 *
	 * @return void
	 */
	public function maybe_upgrade(): void {
		$stored = (string) get_option( Plugin::VERSION_KEY, '' );

		if ( Plugin::VERSION === $stored ) {
			return;
		}

		// Read before any migration writes the option below.
		$is_upgrade = '' !== $stored
			|| false !== get_option( Plugin::OPTION_KEY, false )
			|| null !== get_option( 'loggedin_maximum', null );

		// On a *first* upgrade to the unified-settings schema, pull
		// the legacy single-key values into the new option.
		$this->migrate_legacy_options();

		// Move the pre-library review-notice state onto the keys
		// `foxelabs/wp-review-notice` expects.
		$this->migrate_review_notice_keys();

		// Move license activations onto the key the rebranded
		// `foxelabs/wp-freemius-client` reads.
		$this->migrate_freemius_activation_data();

		if ( $is_upgrade && ( '' === $stored || version_compare( $stored, '3.4.0', '<' ) ) ) {
			$this->keep_pre_340_behaviour();
		}

		$this->autoload_options();

		update_option( Plugin::VERSION_KEY, Plugin::VERSION );
	}

	/**
	 * Rename legacy review-notice keys to the library's layout.
	 *
	 * Prior to 3.1 the review notice was hand-rolled in the Admin
	 * module and stored:
	 *   - option `loggedin_rating_notice` — unix timestamp of the
	 *     next scheduled show.
	 *   - user meta `loggedin_rating_notice_dismissed` — `1` when the
	 *     user clicked "No thanks".
	 *
	 * The `foxelabs/wp-review-notice` library uses the same shapes
	 * under different names (`loggedin_review_time`,
	 * `loggedin_review_dismissed`), so a straight rename preserves
	 * every user's decision.
	 *
	 * The option rename is idempotent — a second run finds nothing
	 * to move. The user-meta rename uses a single `UPDATE` on
	 * `wp_usermeta` because there is no core API to bulk-rename a
	 * meta key, and iterating users via `get_users( ['meta_key'…] )`
	 * would be O(users) on very large sites for no benefit.
	 *
	 * @since 3.0.2
	 *
	 * @return void
	 */
	protected function migrate_review_notice_keys(): void {
		$legacy_time = get_option( 'loggedin_rating_notice', null );

		// Nothing to migrate — either a fresh install or an install
		// that was already on the library's schema. Skipping here
		// also means later version bumps don't re-scan `usermeta`.
		if ( null === $legacy_time ) {
			return;
		}

		// Only seed the new option if it hasn't been written yet —
		// otherwise a fresh 7-day timer scheduled by the library on
		// this same request would be clobbered by the older
		// timestamp.
		if ( false === get_option( 'loggedin_review_time', false ) ) {
			update_option( 'loggedin_review_time', $legacy_time );
		}

		delete_option( 'loggedin_rating_notice' );

		global $wpdb;

		// One-shot rename — no caching layer to invalidate, and
		// direct SQL is the only way to bulk-rename a meta key in
		// core. Suppression is scoped to this call only.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key
		$wpdb->update(
			$wpdb->usermeta,
			array( 'meta_key' => 'loggedin_review_dismissed' ),
			array( 'meta_key' => 'loggedin_rating_notice_dismissed' )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key
	}

	/**
	 * Move Freemius activations onto the rebranded option key.
	 *
	 * The licensing library was renamed from
	 * `duckdev/freemius-plugin-licensing` to
	 * `foxelabs/wp-freemius-client` in its 3.0.0 release, and the
	 * option holding every activation moved with it:
	 *   - `duckdev_freemius_activation_data` (2.x)
	 *   - `foxelabs_freemius_activation_data` (3.x)
	 *
	 * The library deliberately performs no writes of its own, so the
	 * rename is ours to handle. Without this, the SDK reads an empty
	 * option and every license silently reports as inactive —
	 * premium update delivery stops and the addons screen shows the
	 * activation form again, with no error to explain why.
	 *
	 * The stored shape (activations keyed by Freemius plugin id) did
	 * not change, so a straight copy is enough.
	 *
	 * Ordering matters: `Plugin::boot()` runs us before it wires the
	 * add-ons, and `Addons` defers `Freemius::get_instance()`
	 * until `admin_init` or the first read, so this always lands
	 * before anything reads an activation.
	 *
	 * The addon cache transients (`duckdev_freemius_{id}_*`) are
	 * intentionally left alone — they hold nothing but a 24-hour
	 * cache of the addon catalogue, repopulate on the next API call,
	 * and WordPress expires the stale rows itself.
	 *
	 * @since 3.2.0
	 *
	 * @return void
	 */
	protected function migrate_freemius_activation_data(): void {
		$legacy = get_option( 'duckdev_freemius_activation_data', null );

		// Fresh install, or an upgrade that already ran this.
		if ( ! is_array( $legacy ) || empty( $legacy ) ) {
			return;
		}

		// Never clobber a live activation. If the new key already
		// holds data, this site activated after upgrading and the
		// stale 2.x copy would roll that back.
		$current = get_option( ActivationRepository::OPTION_KEY, null );

		if ( is_array( $current ) && ! empty( $current ) ) {
			delete_option( 'duckdev_freemius_activation_data' );

			return;
		}

		// Drop the source only once the destination is written — a
		// failed write here would otherwise lose the activation for
		// good. Deliberately the options API and not a direct
		// `UPDATE wp_options SET option_name`: raw SQL bypasses the
		// options cache and any persistent object cache, so a cached
		// read could resurrect the old value.
		if ( update_option( ActivationRepository::OPTION_KEY, $legacy ) ) {
			delete_option( 'duckdev_freemius_activation_data' );
		}
	}

	/**
	 * Migrate `loggedin_maximum` + `loggedin_logic` → `loggedin_settings`.
	 *
	 * Only runs when the new option has not yet been written —
	 * otherwise a user who has already saved settings on the new
	 * schema (perhaps from a hotfix release) would have their values
	 * silently overwritten by stale legacy keys.
	 *
	 * The legacy keys are intentionally NOT deleted here: an addon
	 * may still read them. The uninstall handler removes them when
	 * the user actually deletes the plugin.
	 *
	 * @since 3.0.0
	 *
	 * @return void
	 */
	protected function migrate_legacy_options(): void {
		$existing = get_option( Plugin::OPTION_KEY, null );
		if ( is_array( $existing ) && ! empty( $existing ) ) {
			return;
		}

		$legacy_max   = get_option( 'loggedin_maximum', null );
		$legacy_logic = get_option( 'loggedin_logic', null );

		// Nothing to migrate — fresh install or an upgrade from
		// a version that never wrote either legacy key.
		if ( null === $legacy_max && null === $legacy_logic ) {
			return;
		}

		// The two values 2.x had, and the 3.4.0 additions off: a site
		// with legacy options is always an upgrade. Not the full
		// defaults, which would switch those additions on before
		// `keep_pre_340_behaviour()` reads the stored value.
		$settings = array(
			'unlock'        => false,
			'exempt_admins' => false,
		);

		if ( null !== $legacy_max ) {
			$settings['maximum'] = max( 1, (int) $legacy_max );
		}

		if (
			null !== $legacy_logic &&
			in_array( $legacy_logic, Settings::BUILT_IN_LOGICS, true )
		) {
			$settings['logic'] = (string) $legacy_logic;
		}

		update_option( Plugin::OPTION_KEY, $settings );
	}

	/**
	 * Keep a site upgrading from before 3.4.0 working as it did.
	 *
	 * 3.4.0 turns on self-unlock by email and exempts administrators
	 * from the limit. New installs get both; an upgraded site gets
	 * neither until an admin turns them on, so nobody finds their
	 * limits behaving differently after an update. A value already
	 * stored (set on an earlier pass, or by hand) is left alone.
	 *
	 * While administrators stay limited, a one-time notice suggests
	 * the exemption ({@see ExemptAdminsNotice}).
	 *
	 * @since 3.4.0
	 *
	 * @return void
	 */
	protected function keep_pre_340_behaviour(): void {
		$stored = get_option( Plugin::OPTION_KEY, array() );
		$stored = is_array( $stored ) ? $stored : array();

		$settings = array_merge(
			$this->settings->defaults(),
			array(
				'unlock'        => false,
				'exempt_admins' => false,
			),
			$stored
		);

		update_option( Plugin::OPTION_KEY, $settings );

		if ( ! $settings['exempt_admins'] ) {
			update_option( ExemptAdminsNotice::OPTION, 1, true );
		}
	}

	/**
	 * Store the options read on every request, so they autoload.
	 *
	 * Without a persistent object cache, reading an option that was
	 * never stored costs a query on every request: WordPress only
	 * remembers a missing option until the request ends. These are
	 * read on every signed-in request (the logout epoch, the auto
	 * logout timeouts), on every admin screen (the notice flag) or on
	 * every page that checks a setting, and stay unset on most sites
	 * until an admin acts. Storing their empty value puts them in the
	 * autoloaded set. `add_option()` leaves an existing value alone.
	 *
	 * The auto logout keys are read by its module; they are listed
	 * here for the same reason the {@see \FoxeLabs\Loggedin\Uninstaller}
	 * lists them: only core knows when the plugin is installed.
	 *
	 * @since 3.4.0
	 *
	 * @return void
	 */
	protected function autoload_options(): void {
		add_option( Plugin::OPTION_KEY, array(), '', true );
		add_option( LogoutEpoch::OPTION, 0, '', true );
		add_option( ExemptAdminsNotice::OPTION, 0, '', true );
		add_option( AutoLogoutSettings::OPTION_KEY, array(), '', true );
		add_option( AutoLogoutSettings::ROLE_OPTION_KEY, array(), '', true );
	}
}
