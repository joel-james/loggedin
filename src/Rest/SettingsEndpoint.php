<?php
/**
 * Settings REST controller.
 *
 * Backs an alternate read/write surface for the unified
 * `loggedin_settings` option. The React Settings tab actually goes
 * through `/wp/v2/settings` (via `@wordpress/core-data`'s
 * `useEntityProp`) because the option is registered with
 * `show_in_rest`, but this dedicated namespace stays useful for:
 *
 *   - Third-party integrations that want a stable, plugin-scoped URL
 *     instead of the generic site-settings endpoint.
 *   - CLI / cURL recipes in our docs.
 *
 * The route takes the same schema as the option,
 * {@see \FoxeLabs\Loggedin\Settings\Settings::properties()}, so both entry
 * points accept the same keys.
 *
 * @since 3.0.0
 * @package FoxeLabs\Loggedin\Rest
 */

declare( strict_types = 1 );

namespace FoxeLabs\Loggedin\Rest;

use FoxeLabs\Loggedin\Settings\Settings;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'WPINC' ) || die;

/**
 * REST endpoint for reading and updating plugin settings.
 *
 * @since 3.0.0
 */
final class SettingsEndpoint extends Endpoint {

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
	 * Register the `/settings` route — GET to read, POST to write.
	 *
	 * @since 3.0.0
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/settings',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_settings' ),
					'permission_callback' => array( $this, 'permission_check' ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_settings' ),
					'permission_callback' => array( $this, 'permission_check' ),
					'args'                => $this->settings->properties(),
				),
			)
		);
	}

	/**
	 * GET handler — return the full settings array.
	 *
	 * @since 3.0.0
	 *
	 * @param WP_REST_Request $request Unused.
	 *
	 * @return WP_REST_Response
	 */
	public function get_settings( WP_REST_Request $request ): WP_REST_Response {
		unset( $request );

		return new WP_REST_Response( $this->settings->all(), 200 );
	}

	/**
	 * POST handler — write a partial payload, return the result.
	 *
	 * Only the keys sent are written; {@see Settings::update()} keeps
	 * the stored value of every other key, so the route behaves as a
	 * PATCH even though `WP_REST_Server::EDITABLE` accepts POST, PUT
	 * and PATCH alike. Sanitising is left to the settings store, so
	 * it happens in one place.
	 *
	 * @since 3.0.0
	 * @since 3.4.0 Takes every key in the settings schema, not only
	 *              `maximum` and `logic`.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 *
	 * @return WP_REST_Response
	 */
	public function update_settings( WP_REST_Request $request ): WP_REST_Response {
		$values = array_intersect_key( $request->get_params(), $this->settings->properties() );

		$this->settings->update( $values );

		return new WP_REST_Response( $this->settings->all(), 200 );
	}
}
