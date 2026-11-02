<?php
/**
 * Addons module — Freemius wiring + license operations.
 *
 * This module is the bridge between the plugin and the
 * `foxelabs/wp-freemius-client` SDK. It owns:
 *
 *   - Building Freemius instances for the parent plugin and each
 *     registered addon (lazily — see {@see maybe_init_freemius()}).
 *   - Looking up the catalogue / license state.
 *   - Activating + deactivating license keys.
 *
 * The REST routes live in {@see \FoxeLabs\Loggedin\Rest\AddonsEndpoint}; the
 * shaped catalogue lives in {@see Catalog}. This class is the only
 * place that talks to the SDK directly.
 *
 * @since 3.0.0
 * @package FoxeLabs\Loggedin\Addons
 */

declare( strict_types = 1 );

namespace FoxeLabs\Loggedin\Addons;

use FoxeLabs\Freemius\Freemius;
use FoxeLabs\Loggedin\Plugin;
use WP_Error;

defined( 'WPINC' ) || die;

/**
 * Addons registry and lifecycle manager.
 *
 * @since 3.0.0
 */
final class Addons {

	/**
	 * Cache of Freemius SDK instances keyed by plugin / addon id.
	 *
	 * @since 3.0.0
	 *
	 * @var array<int, Freemius>
	 */
	protected array $freemius = array();

	/**
	 * Register hooks.
	 *
	 * The eager init runs on `admin_init` so the SDK can attach its
	 * own admin-side wiring (notices, settings links). REST + CLI
	 * requests (which don't fire `admin_init`) go through the lazy
	 * path in {@see maybe_init_freemius()} instead.
	 *
	 * @since 3.0.0
	 */
	public function register(): void {
		add_action( 'admin_init', array( $this, 'init_freemius' ) );
	}

	/**
	 * Is the SDK ready to talk to Freemius?
	 *
	 * Returns false when there are no Freemius instances built yet
	 * AND there's no parent-plugin Freemius id we could build one
	 * for — i.e. licensing isn't configured. The REST controller
	 * uses this to surface a clear error instead of a 500.
	 *
	 * @since 3.0.0
	 *
	 * @return bool
	 */
	public function is_ready(): bool {
		$this->maybe_init_freemius();

		return isset( $this->freemius[ Plugin::FREEMIUS_ID ] );
	}

	/**
	 * Return the Freemius instance for a given addon id.
	 *
	 * @since 3.0.0
	 *
	 * @param int $id Addon Freemius id.
	 *
	 * @return Freemius|null
	 */
	public function freemius_for( int $id ): ?Freemius {
		$this->maybe_init_freemius();

		return $this->freemius[ $id ] ?? null;
	}

	/**
	 * Raw addon catalogue from the Freemius SDK.
	 *
	 * Pass `$force = true` to bypass the SDK's day-long cache.
	 *
	 * @since 3.0.0
	 *
	 * @param bool $force Force-refresh the cached catalogue.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_addons( bool $force = false ): array {
		$this->maybe_init_freemius();

		if ( ! isset( $this->freemius[ Plugin::FREEMIUS_ID ] ) ) {
			return array();
		}

		return $this->freemius[ Plugin::FREEMIUS_ID ]->addon()->get_addons( $force );
	}

	/**
	 * Map of license rows keyed by addon id.
	 *
	 * Shape:
	 *   [
	 *     <addon_id> => [
	 *       'key'    => string,   // raw license key
	 *       'status' => string,   // Activation::STATUS_* constant
	 *       'plugin' => array,    // Freemius plugin metadata
	 *     ],
	 *     ...
	 *   ]
	 *
	 * @since 3.0.0
	 *
	 * @return array<int, array{key: string, status: string, plugin: array<string, mixed>}>
	 */
	public function get_license_items(): array {
		$this->maybe_init_freemius();

		$items  = array();
		$addons = $this->get_registered_addons();

		foreach ( $addons as $id => $args ) {
			if ( ! isset( $this->freemius[ $id ] ) ) {
				continue;
			}

			$activation = $this->freemius[ $id ]->license()->get_activation();

			$items[ $id ] = array(
				'key'    => $activation->license_key(),
				'status' => $activation->status(),
				'plugin' => $this->freemius[ $id ]->plugin()->get_data(),
			);
		}

		return $items;
	}

	/**
	 * Activate a license key for the given addon.
	 *
	 * Delegates to the addon's Freemius client and returns whatever
	 * the SDK returns — usually `true` on success or a `WP_Error`
	 * the REST controller can forward verbatim.
	 *
	 * @since 3.0.0
	 *
	 * @param int    $id  Addon Freemius id.
	 * @param string $key License key to activate.
	 *
	 * @return true|WP_Error
	 */
	public function activate_license( int $id, string $key ) {
		$freemius = $this->freemius_for( $id );

		if ( null === $freemius ) {
			return new WP_Error(
				'addon_not_initialized',
				__( 'Addon not initialized.', 'loggedin' )
			);
		}

		$result = $freemius->license()->activate( $key );

		return is_wp_error( $result ) ? $result : true;
	}

	/**
	 * Deactivate the stored license key for the given addon.
	 *
	 * @since 3.0.0
	 *
	 * @param int $id Addon Freemius id.
	 *
	 * @return true|WP_Error
	 */
	public function deactivate_license( int $id ) {
		$freemius = $this->freemius_for( $id );

		if ( null === $freemius ) {
			return new WP_Error(
				'addon_not_initialized',
				__( 'Addon not initialized.', 'loggedin' )
			);
		}

		$result = $freemius->license()->deactivate();

		return is_wp_error( $result ) ? $result : true;
	}

	/**
	 * Lazily build the Freemius instances on first need.
	 *
	 * @since 3.0.0
	 *
	 * @return void
	 */
	protected function maybe_init_freemius(): void {
		if ( isset( $this->freemius[ Plugin::FREEMIUS_ID ] ) ) {
			return;
		}

		$this->init_freemius();
	}

	/**
	 * Build Freemius instances for the parent plugin + every addon.
	 *
	 * @since 3.0.0
	 *
	 * @return void
	 */
	public function init_freemius(): void {
		if ( isset( $this->freemius[ Plugin::FREEMIUS_ID ] ) ) {
			return;
		}

		$this->freemius[ Plugin::FREEMIUS_ID ] = Freemius::get_instance(
			Plugin::FREEMIUS_ID,
			array(
				'slug'       => Plugin::SLUG,
				'is_premium' => false,
				'main_file'  => Plugin::file(),
				'public_key' => 'pk_4c8df806d035c90805a97eae5fba5',
				'has_addons' => true,
			)
		);

		foreach ( $this->get_registered_addons() as $id => $args ) {
			$this->freemius[ $id ] = Freemius::get_instance( $id, $args );
		}
	}

	/**
	 * List of addons registered by sibling addon plugins.
	 *
	 * Not memoized: add-ons register on this filter as their main file
	 * loads, and a cached copy taken earlier would miss later ones.
	 *
	 * @since 3.0.0
	 * @since 3.4.0 Public, replacing `get_registered_addons_public()`.
	 *
	 * @return array<int, array<string, mixed>> Addons keyed by Freemius id.
	 */
	public function get_registered_addons(): array {
		/**
		 * Filter the list of registered addons.
		 *
		 * Addon plugins hook here and append themselves so the
		 * parent can build a Freemius instance for them.
		 *
		 * @since 2.0.0
		 *
		 * @param array $addons Addons keyed by Freemius id.
		 */
		return apply_filters( 'loggedin_register_addon', array() );
	}
}
