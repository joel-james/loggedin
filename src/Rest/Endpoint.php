<?php
/**
 * Base REST endpoint.
 *
 * Subclasses inherit:
 *   - the shared `loggedin/v1` namespace.
 *   - a default permission callback that requires `manage_options`.
 *   - a `hook()` method that wires `register_routes()` into
 *     `rest_api_init`.
 *
 * Per-route argument schemas and callbacks live in each subclass.
 *
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
	 * @var string
	 */
	protected string $namespace = Plugin::REST_NAMESPACE;

	/**
	 * Wire `register_routes()` into `rest_api_init`.
	 *
	 * Called from the subclass `init()` method so route registration
	 * happens at the right point in the request lifecycle (after
	 * permission callbacks are guaranteed to have user-context
	 * available).
	 *
	 * @since 3.0.0
	 *
	 * @return void
	 */
	public function hook(): void {
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
