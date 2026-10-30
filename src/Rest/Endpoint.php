<?php
/**
 * Base REST endpoint.
 *
 * Subclasses inherit:
 *   - the shared `loggedin/v1` namespace.
 *   - a default permission callback that requires `manage_options`.
 *   - a `register()` method that wires `register_routes()` into
 *     `rest_api_init`.
 *
 * Per-route argument schemas and callbacks live in each subclass.
 *
 * @since 3.0.0
 * @package FoxeLabs\Loggedin\Rest
 */

declare( strict_types = 1 );

namespace FoxeLabs\Loggedin\Rest;

use FoxeLabs\Loggedin\Plugin;
use WP_REST_Request;

defined( 'WPINC' ) || die;

/**
 * Base REST endpoint with shared auth and namespace plumbing.
 *
 * @since 3.0.0
 */
abstract class Endpoint {

	/**
	 * REST namespace shared by every plugin endpoint.
	 *
	 * @since 3.0.0
	 *
	 * @var string
	 */
	protected string $namespace = Plugin::REST_NAMESPACE;

	/**
	 * Register hooks: the routes, on `rest_api_init`.
	 *
	 * Routes are registered there, not earlier, because that is when
	 * WordPress builds the REST server for a request.
	 *
	 * @since 3.0.0 As `hook()`, called from each subclass.
	 * @since 3.4.0 Named `register()`, like every service, and not
	 *              repeated in each subclass.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Subclasses register their REST routes here.
	 *
	 * Implementations should call `register_rest_route()` with
	 * `$this->namespace` as the first argument so every route lives
	 * under the same namespace.
	 *
	 * @since 3.0.0
	 *
	 * @return void
	 */
	abstract public function register_routes(): void;

	/**
	 * Default permission callback — requires `manage_options`.
	 *
	 * Subclasses can override per-route by passing their own
	 * `permission_callback`. The `$request` parameter is part of the
	 * REST callback signature; we don't use it here.
	 *
	 * @since 3.0.0
	 *
	 * @param WP_REST_Request $request Unused.
	 *
	 * @return bool
	 */
	public function permission_check( WP_REST_Request $request ): bool {
		unset( $request );

		return current_user_can( 'manage_options' );
	}
}
