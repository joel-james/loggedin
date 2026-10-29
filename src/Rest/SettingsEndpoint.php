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
 * The route mirrors the schema declared in
 * {@see \FoxeLabs\Loggedin\Settings\Settings::register_option()} so both entry
 * points behave identically.
 *
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
	 * Register hooks.
	 *
	 * @since 3.0.0
	 */
	public function register(): void {
		$this->hook();
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
					'args'                => array(
						'maximum' => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'logic'   => array(
							'type' => 'string',
							'enum' => $this->settings->logic_ids(),
						),
					),
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
	 * Reads each accepted key from the request, falling back to the
	 * currently-stored value for any key not present in the payload.
	 * This makes the endpoint behave as a true PATCH even though
	 * `WP_REST_Server::EDITABLE` accepts POST / PUT / PATCH alike.
	 *
	 * Sanitization is delegated to the Settings store so the schema
	 * is enforced in exactly one place.
	 *
	 * @since 3.0.0
	 *
	 * @param WP_REST_Request $request Incoming request.
	 *
	 * @return WP_REST_Response
	 */
	public function update_settings( WP_REST_Request $request ): WP_REST_Response {
		$store   = $this->settings;
		$current = $store->all();

		// Start from what is stored, so keys this route doesn't take
		// (`unlock`, `exempt_admins`, …) keep their values.
		$payload = array_merge(
			$current,
			array(
				'maximum' => $request->offsetExists( 'maximum' )
					? (int) $request->get_param( 'maximum' )
					: $current['maximum'],
				'logic'   => $request->offsetExists( 'logic' )
					? (string) $request->get_param( 'logic' )
					: $current['logic'],
			)
		);

		$store->update( $payload );

		return new WP_REST_Response( $store->all(), 200 );
	}
}
