<?php
/**
 * Addons REST controller.
 *
 * Surfaces the addon catalogue and per-addon license operations to
 * the React Addons tab:
 *
 *   GET    /loggedin/v1/addons                 — list catalogue.
 *   POST   /loggedin/v1/addons/refresh         — bust SDK cache.
 *   POST   /loggedin/v1/addons/{id}/license    — activate.
 *   DELETE /loggedin/v1/addons/{id}/license    — deactivate.
 *
 * `{id}` is the addon's Freemius id — an integer matching the `id`
 * field on every catalogue row.
 *
 * The controller is a thin HTTP wrapper; all domain logic lives in
 * {@see \FoxeLabs\Loggedin\Addons\Catalog} and
 * {@see \FoxeLabs\Loggedin\Addons\Addons}.
 *
 * @package FoxeLabs\Loggedin\Api
 */

declare( strict_types = 1 );

namespace FoxeLabs\Loggedin\Api;

use FoxeLabs\Loggedin\Addons\Addons as Addons_Module;
use FoxeLabs\Loggedin\Addons\Catalog;
use FoxeLabs\Loggedin\Contracts\Singleton;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'WPINC' ) || die;

/**
 * REST endpoint for addon listing and activation.
 *
 * @since 3.0.0
 */
final class Addons extends Endpoint {

	use Singleton;

	/**
	 * Register hooks.
	 *
	 * @since 3.0.0
	 */
	protected function init(): void {
		$this->hook();
	}

	/**
	 * Register the four addons routes.
	 *
	 * @since 3.0.0
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/addons',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_items' ),
					'permission_callback' => array( $this, 'permission_check' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/addons/refresh',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'refresh' ),
					'permission_callback' => array( $this, 'permission_check' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/addons/(?P<id>\d+)/license',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'activate_license' ),
					'permission_callback' => array( $this, 'permission_check' ),
					'args'                => array(
						'key' => array(
							'type'     => 'string',
							'required' => true,
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'deactivate_license' ),
					'permission_callback' => array( $this, 'permission_check' ),
				),
			)
		);
	}

	/**
	 * GET /addons — shaped catalogue.
	 *
	 * Uses the SDK's day-long cache. Response body shape is
	 * `{ items: [...] }`.
	 *
	 * @since 3.0.0
	 *
	 * @param WP_REST_Request $request Unused.
	 *
	 * @return WP_REST_Response
	 */
	public function list_items( WP_REST_Request $request ): WP_REST_Response {
		unset( $request );

		return new WP_REST_Response(
			array( 'items' => Catalog::instance()->items( false ) ),
			200
		);
	}

	/**
	 * POST /addons/refresh — bypass the SDK cache and re-fetch.
	 *
	 * The SDK rate-limits its own remote requests; repeated clicks
	 * will simply return the last-known catalogue unchanged until
	 * the rate-limit window expires.
	 *
	 * @since 3.0.0
	 *
	 * @param WP_REST_Request $request Unused.
	 *
	 * @return WP_REST_Response
	 */
	public function refresh( WP_REST_Request $request ): WP_REST_Response {
		unset( $request );

		return new WP_REST_Response(
			array( 'items' => Catalog::instance()->items( true ) ),
			200
		);
	}

	/**
	 * POST /addons/{id}/license — activate a license key.
	 *
	 * Returns the fresh decorated catalogue row alongside the
	 * success flag so the React store can patch the matching card
	 * in one round-trip.
	 *
	 * @since 3.0.0
	 *
	 * @param WP_REST_Request $request REST request — captures `id`
	 *                                 and `key`.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function activate_license( WP_REST_Request $request ) {
		$addons = Addons_Module::instance();

		if ( ! $addons->is_ready() ) {
			return new WP_Error(
				'rest_no_freemius',
				__( 'Licensing is not configured.', 'loggedin' ),
				array( 'status' => 400 )
			);
		}

		// Trim only: sanitize_text_field() strips `%` followed by two
		// hex characters as a percent-encoded octet, and Freemius keys
		// can contain exactly that (e.g. `%bB`), which corrupts the key
		// and Freemius rejects it as invalid. The key is only sent to
		// the Freemius API, never output, so it needs no text cleanup.
		$id  = (int) $request['id'];
		$key = trim( (string) $request->get_param( 'key' ) );

		$result = $addons->activate_license( $id, $key );

		if ( is_wp_error( $result ) ) {
			$result->add_data( array( 'status' => 400 ) );
			return $result;
		}

		return new WP_REST_Response(
			array(
				'success' => true,
				'addon'   => Catalog::instance()->find( $id ),
			),
			200
		);
	}

	/**
	 * DELETE /addons/{id}/license — deactivate the active license.
	 *
	 * @since 3.0.0
	 *
	 * @param WP_REST_Request $request REST request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function deactivate_license( WP_REST_Request $request ) {
		$addons = Addons_Module::instance();

		if ( ! $addons->is_ready() ) {
			return new WP_Error(
				'rest_no_freemius',
				__( 'Licensing is not configured.', 'loggedin' ),
				array( 'status' => 400 )
			);
		}

		$id     = (int) $request['id'];
		$result = $addons->deactivate_license( $id );

		if ( is_wp_error( $result ) ) {
			$result->add_data( array( 'status' => 400 ) );
			return $result;
		}

		return new WP_REST_Response(
			array(
				'success' => true,
				'addon'   => Catalog::instance()->find( $id ),
			),
			200
		);
	}
}
