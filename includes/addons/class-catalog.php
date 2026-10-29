<?php
/**
 * Addon catalogue service.
 *
 * Owns everything about *assembling* the addon catalogue the React
 * Addons tab renders: pulls the raw addon list from the Freemius SDK,
 * decorates each row with local install / license state, and returns
 * a flat list of items in the shape the React layer consumes.
 *
 * This is the domain layer behind {@see \FoxeLabs\Loggedin\Api\Addons}
 * — the REST controller stays a thin HTTP wrapper. Keeping the
 * catalogue here rather than in the controller means it can feed
 * WP-CLI or tests without going through a `WP_REST_Request`.
 *
 * @package FoxeLabs\Loggedin\Addons
 */

declare( strict_types = 1 );

namespace FoxeLabs\Loggedin\Addons;

use FoxeLabs\Loggedin\Contracts\Singleton;

defined( 'WPINC' ) || die;

/**
 * Static catalog of available addons.
 *
 * @since 3.0.0
 */
final class Catalog {

	use Singleton;

	/**
	 * No hooks; service is invoked directly.
	 */
	protected function init(): void {}

	/**
	 * Build the decorated catalogue for the React UI.
	 *
	 * The SDK returns rows in a slightly noisy shape; this method
	 * extracts just what the React side consumes and decorates each
	 * row with:
	 *
	 *   - `is_active`         — whether the addon plugin has
	 *                           registered itself locally (i.e. it's
	 *                           installed and active).
	 *   - `is_license_active` — whether the stored license is
	 *                           currently activated on Freemius.
	 *   - `license_key`       — raw key value, so the input can
	 *                           pre-fill and go read-only when active.
	 *
	 * Uses the SDK's day-long cache unless `$force` is set.
	 *
	 * @since 3.0.0
	 *
	 * @param bool $force Force a Freemius API refresh.
	 *
	 * @return array<int, array> Empty array when the SDK isn't ready
	 *                           or returns nothing.
	 */
	public function items( bool $force = false ): array {
		$addons     = Addons::instance();
		$catalogue  = $addons->get_addons( $force );
		$registered = $addons->get_registered_addons_public();
		$licenses   = $addons->get_license_items();

		$items = array();

		foreach ( (array) $catalogue as $addon ) {
			$id      = (int) ( $addon['id'] ?? 0 );
			$license = $licenses[ $id ] ?? array();

			$items[] = array(
				'id'                => $id,
				'title'             => (string) ( $addon['title'] ?? '' ),
				'icon'              => (string) ( $addon['icon'] ?? '' ),
				'link'              => (string) ( $addon['link'] ?? '' ),
				'description'       => (string) ( $addon['info']['description'] ?? '' ),
				'homepage'          => (string) ( $addon['info']['url'] ?? '' ),
				'is_premium'        => (bool) ( $addon['is_premium'] ?? false ),
				'is_active'         => isset( $registered[ $id ] ),
				'is_license_active' => $this->license_is_active( $license ),
				'license_key'       => (string) ( $license['key'] ?? '' ),
				'banner'            => (string) ( $addon['info']['card_banner_url'] ?? '' ),
				'banner_large'      => (string) ( $addon['info']['banner_url'] ?? '' ),
			);
		}

		/**
		 * Filter the shaped addon catalogue before it goes over REST.
		 *
		 * Useful for self-hosted / white-label builds that want to
		 * splice in extra rows without standing up a separate
		 * Freemius project.
		 *
		 * @since 3.0.0
		 *
		 * @param array $items     Shaped catalogue rows.
		 * @param array $catalogue Raw SDK catalogue rows.
		 */
		return (array) apply_filters( 'loggedin_addons_catalog', $items, $catalogue );
	}

	/**
	 * Return the shaped row for a single addon id.
	 *
	 * Used by the activate / deactivate handlers so the response can
	 * carry the fresh row back to the React layer in one round-trip.
	 *
	 * @since 3.0.0
	 *
	 * @param int $id Addon Freemius id.
	 *
	 * @return array Empty array when the id isn't in the catalogue.
	 */
	public function find( int $id ): array {
		foreach ( $this->items( false ) as $addon ) {
			if ( (int) $addon['id'] === $id ) {
				return $addon;
			}
		}

		return array();
	}

	/**
	 * Translate a license row's status into a boolean for the UI.
	 *
	 * The SDK uses an `Activation::STATUS_*` constant string for the
	 * status field. `activated` is the only "live" state — anything
	 * else (expired, deactivated, empty) renders as inactive.
	 *
	 * @since 3.0.0
	 *
	 * @param array $license License row from {@see Addons::get_license_items()}.
	 *
	 * @return bool
	 */
	private function license_is_active( array $license ): bool {
		return isset( $license['status'] ) && 'activated' === $license['status'];
	}
}
