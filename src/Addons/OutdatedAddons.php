<?php
/**
 * Add-ons older than the settings screen.
 *
 * Loggedin 3.4.0 rebuilt its settings screen on the Foxe Plugin Admin
 * Kit. Add-ons built before it still work (their panels and tabs are
 * drawn by the compatibility layer in `assets/src/app/pages.js`), but
 * they look out of place until they're updated. This tells the admin
 * which ones to update.
 *
 * Only add-ons that draw on Loggedin's screen are listed: an add-on
 * without settings there has nothing to look out of place.
 *
 * @package FoxeLabs\Loggedin\Addons
 */

declare( strict_types = 1 );

namespace FoxeLabs\Loggedin\Addons;

use FoxeLabs\Loggedin\Contracts\Singleton;

defined( 'WPINC' ) || die;

/**
 * Asks the admin to update add-ons built for the old settings screen.
 *
 * @since 3.4.0
 */
final class OutdatedAddons {

	use Singleton;

	/**
	 * First version of each add-on built for the new settings screen.
	 *
	 * @since 3.4.0
	 */
	private const MINIMUM = array(
		'loggedin-active-sessions' => '1.2.0',
		'loggedin-limit-per-role'  => '2.2.0',
		'loggedin-realtime-logout' => '2.2.0',
	);

	/**
	 * Register hooks.
	 *
	 * @since 3.4.0
	 *
	 * @return void
	 */
	protected function init(): void {
		add_action( 'admin_notices', array( $this, 'notice' ) );
	}

	/**
	 * Add-ons running a version older than the one the screen needs.
	 *
	 * Reads each add-on's version from its plugin header, through the
	 * `main_file` it registers with, so an add-on doesn't have to
	 * report its own version.
	 *
	 * @since 3.4.0
	 *
	 * @return array<int, array{name: string, version: string, minimum: string}>
	 */
	public function outdated(): array {
		/**
		 * Filter the first add-on versions built for the new settings screen.
		 *
		 * @since 3.4.0
		 *
		 * @param array<string, string> $minimum Versions keyed by add-on slug.
		 */
		$minimum  = (array) apply_filters( 'loggedin_addon_minimum_versions', self::MINIMUM );
		$outdated = array();

		foreach ( Addons::instance()->get_registered_addons_public() as $args ) {
			$slug = (string) ( $args['slug'] ?? '' );
			$file = (string) ( $args['main_file'] ?? '' );

			if ( ! isset( $minimum[ $slug ] ) || '' === $file || ! is_readable( $file ) ) {
				continue;
			}

			$header = get_file_data(
				$file,
				array(
					'name'    => 'Plugin Name',
					'version' => 'Version',
				)
			);

			if ( '' !== $header['version'] && version_compare( $header['version'], (string) $minimum[ $slug ], '<' ) ) {
				$outdated[] = array(
					'name'    => '' !== $header['name'] ? $header['name'] : $slug,
					'version' => $header['version'],
					'minimum' => (string) $minimum[ $slug ],
				);
			}
		}

		return $outdated;
	}

	/**
	 * List the outdated add-ons.
	 *
	 * Shown on the Plugins screen and on Loggedin's own screen only, to
	 * people who can update plugins.
	 *
	 * @since 3.4.0
	 *
	 * @return void
	 */
	public function notice(): void {
		$screen = get_current_screen();

		if (
			! current_user_can( 'update_plugins' )
			|| ! $screen
			|| ! in_array( $screen->id, array( 'plugins', 'users_page_loggedin' ), true )
		) {
			return;
		}

		$outdated = $this->outdated();

		if ( empty( $outdated ) ) {
			return;
		}

		$items = '';

		foreach ( $outdated as $addon ) {
			$items .= '<li>' . esc_html(
				sprintf(
					/* translators: 1: Add-on name, 2: Installed version, 3: First version that fits the new screen. */
					__( '%1$s %2$s: update to %3$s or later.', 'loggedin' ),
					$addon['name'],
					$addon['version'],
					$addon['minimum']
				)
			) . '</li>';
		}

		printf(
			'<div class="notice notice-warning"><p><strong>%1$s</strong> %2$s</p><ul class="ul-disc">%3$s</ul><p><a href="%4$s">%5$s</a></p></div>',
			esc_html( _n( 'A Loggedin add-on needs an update.', 'Some Loggedin add-ons need an update.', count( $outdated ), 'loggedin' ) ),
			esc_html( _n( 'It was made for the old settings screen. It still works, but its settings may look out of place until it is updated.', 'They were made for the old settings screen. They still work, but their settings may look out of place until they are updated.', count( $outdated ), 'loggedin' ) ),
			wp_kses( $items, array( 'li' => array() ) ),
			esc_url( admin_url( 'update-core.php' ) ),
			esc_html__( 'Go to Updates', 'loggedin' )
		);
	}
}
