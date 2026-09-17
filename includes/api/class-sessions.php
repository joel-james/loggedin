<?php
/**
 * Sessions REST controller.
 *
 * Backs the "Force Logout" panel on the Settings tab. Accepts a
 * single identifier — id, email, or username — resolves it to a
 * `WP_User`, and destroys every active session for that user.
 *
 * Routes:
 *
 *   POST /loggedin/v1/sessions/destroy       body: { user: string }
 *   POST /loggedin/v1/sessions/destroy-all   (no body)
 *
 * `destroy-all` bumps the {@see Logout_Epoch} instead of iterating
 * users, so it is O(1) regardless of user count. The calling admin's
 * own session is kept alive.
 *
 * The same identifier-resolution rules apply across all three input
 * shapes — numeric strings look up by id, anything with an `@` looks
 * up by email, and anything else looks up by login. That keeps the
 * React side a single text input with a hint, rather than a select
 * + value pair.
 *
 * @package FoxeLabs\Loggedin\Api
 */

declare( strict_types = 1 );

namespace FoxeLabs\Loggedin\Api;

use FoxeLabs\Loggedin\Contracts\Singleton;
use FoxeLabs\Loggedin\Front\Logout_Epoch;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WP_Session_Tokens;
use WP_User;

defined( 'WPINC' ) || die;

/**
 * REST endpoint for listing and force-destroying user sessions.
 *
 * @since 3.0.0
 */
final class Sessions extends Endpoint {

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
	 * Register the `/sessions/destroy` and `/sessions/destroy-all` routes.
	 *
	 * @since 3.0.0
	 * @since 3.3.0 Added `/sessions/destroy-all`.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/sessions/destroy-all',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'destroy_all_sessions' ),
				'permission_callback' => array( $this, 'permission_check' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/sessions/destroy',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'destroy_sessions' ),
				'permission_callback' => array( $this, 'permission_check' ),
				'args'                => array(
					'user' => array(
						'type'        => 'string',
						'required'    => true,
						'description' => __( 'User id, email, or login.', 'loggedin' ),
					),
				),
			)
		);
	}

	/**
	 * POST handler — resolve the identifier and destroy all sessions.
	 *
	 * Response body shape on success:
	 *   { success: true, user: { id, login, display_name } }
	 *
	 * On failure we return a `WP_Error` with HTTP 400 so the React
	 * layer's `apiFetch` rejects with a useful message.
	 *
	 * @since 3.0.0
	 *
	 * @param WP_REST_Request $request Incoming request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function destroy_sessions( WP_REST_Request $request ) {
		$identifier = trim( (string) $request->get_param( 'user' ) );

		if ( '' === $identifier ) {
			return new WP_Error(
				'missing_identifier',
				__( 'Enter a user id, email, or username.', 'loggedin' ),
				array( 'status' => 400 )
			);
		}

		$user = $this->resolve_user( $identifier );

		if ( ! $user instanceof WP_User ) {
			return new WP_Error(
				'user_not_found',
				sprintf(
					// translators: %s the identifier supplied in the request.
					__( 'No user found matching "%s".', 'loggedin' ),
					$identifier
				),
				array( 'status' => 404 )
			);
		}

		WP_Session_Tokens::get_instance( $user->ID )->destroy_all();

		/**
		 * Fires after a user has been force-logged-out via the
		 * Settings panel.
		 *
		 * Mirrors the action triggered by the legacy
		 * `admin.php?loggedin_logout=1` flow so addons listening to
		 * one path also see the other.
		 *
		 * @since 3.0.0
		 *
		 * @param int $user_id Id of the user whose sessions were destroyed.
		 */
		do_action( 'loggedin_destroy_all_sessions', $user->ID );

		return new WP_REST_Response(
			array(
				'success' => true,
				'user'    => array(
					'id'           => (int) $user->ID,
					'login'        => (string) $user->user_login,
					'display_name' => (string) $user->display_name,
				),
			),
			200
		);
	}

	/**
	 * POST handler — log out every user on the site.
	 *
	 * Delegates to {@see Logout_Epoch::logout_all()}, which stores a
	 * validity epoch instead of touching each user's sessions. The
	 * requesting admin's own session is re-stamped so they stay
	 * logged in.
	 *
	 * Response body shape:
	 *   { success: true, epoch: <int> }
	 *
	 * @since 3.3.0
	 *
	 * @param WP_REST_Request $request Incoming request (unused).
	 *
	 * @return WP_REST_Response
	 */
	public function destroy_all_sessions( WP_REST_Request $request ): WP_REST_Response {
		unset( $request );

		$epoch = Logout_Epoch::logout_all();

		return new WP_REST_Response(
			array(
				'success' => true,
				'epoch'   => $epoch,
			),
			200
		);
	}

	/**
	 * Translate a user-supplied identifier into a `WP_User`.
	 *
	 * Lookup order:
	 *
	 *   1. Numeric strings → `get_user_by('id', …)`.
	 *   2. Anything containing `@` → `get_user_by('email', …)`.
	 *   3. Everything else → `get_user_by('login', …)`.
	 *
	 * Returns `false` (the default WP behavior) when the lookup
	 * doesn't find a row, which the caller surfaces as a 404.
	 *
	 * @since 3.0.0
	 *
	 * @param string $identifier Raw identifier from the request body.
	 *
	 * @return WP_User|false
	 */
	private function resolve_user( string $identifier ) {
		if ( ctype_digit( $identifier ) ) {
			return get_user_by( 'id', (int) $identifier );
		}

		if ( false !== strpos( $identifier, '@' ) ) {
			return get_user_by( 'email', $identifier );
		}

		return get_user_by( 'login', $identifier );
	}
}
