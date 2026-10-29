<?php
/**
 * Admin module.
 *
 * Responsible for the wp-admin surface area that is *not* the React
 * app itself:
 *
 *   - Registering the menu item under Users → Loggedin and emitting
 *     the React mount point div.
 *   - The "force logout this user from all devices" action handler
 *     (link rendered by addon code or admin pages outside this
 *     plugin).
 *   - The review-request notice (delegated to the
 *     `foxelabs/wp-review-notice` library and scoped to the plugin
 *     settings screen).
 *
 * Asset enqueueing for the React bundle lives in a sibling class —
 * {@see Assets} — so this file doesn't have to know how the bundle
 * is built or what dependencies it declares.
 *
 * @package FoxeLabs\Loggedin\Admin
 */

declare( strict_types = 1 );

namespace FoxeLabs\Loggedin\Admin;

use FoxeLabs\Loggedin\Plugin;
use FoxeLabs\Reviews\Notice as Review_Notice;
use WP_Session_Tokens;

defined( 'WPINC' ) || die;

/**
 * Admin bootstrap — menu, assets, and React app shell.
 *
 * @since 3.0.0
 */
final class Admin {

	/**
	 * Register hooks.
	 *
	 * @since 3.0.0
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'force_logout' ) );

		Review_Notice::create(
			Plugin::SLUG,
			'Loggedin',
			array(
				'days'    => 7,
				'domain'  => 'loggedin',
				'screens' => array( 'users_page_' . Plugin::SLUG ),
			)
		)->register();
	}

	/**
	 * Process a "force logout" request.
	 *
	 * Linked to from outside the React app (e.g. a user-row action on
	 * the core Users list table contributed by an addon). Looks for
	 * `?loggedin_logout=1&loggedin_user=<id>&_wpnonce=...`, verifies
	 * the nonce and the same capability as the Sessions page, destroys
	 * every session for the target user, and surfaces a
	 * `settings_errors`-based admin notice with the result.
	 *
	 * @since 3.0.0
	 * @since 3.4.0 Requires `manage_options` and fires `loggedin_destroy_all_sessions`.
	 *
	 * @return void
	 */
	public function force_logout(): void {
		if ( ! isset( $_REQUEST['loggedin_logout'], $_REQUEST['loggedin_user'] ) ) {
			return;
		}

		check_admin_referer( 'loggedin-options' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die(
				esc_html__( 'Sorry, you are not allowed to sign users out.', 'loggedin' ),
				'',
				array( 'response' => 403 )
			);
		}

		$user = get_userdata( (int) $_REQUEST['loggedin_user'] );

		if ( $user ) {
			WP_Session_Tokens::get_instance( $user->ID )->destroy_all();

			/** This action is documented in src/Rest/SessionsEndpoint.php */
			do_action( 'loggedin_destroy_all_sessions', $user->ID );

			add_settings_error(
				'general',
				'settings_updated',
				sprintf(
					// translators: %s user login of the user being logged out.
					__( 'The user "%s" was forcefully logged out from all devices.', 'loggedin' ),
					$user->user_login
				),
				'updated'
			);
		} else {
			add_settings_error(
				'general',
				'settings_updated',
				sprintf(
					// translators: %d the invalid user id supplied in the request.
					__( 'Invalid user ID: %d', 'loggedin' ),
					(int) $_REQUEST['loggedin_user']
				)
			);
		}
	}

	/**
	 * Register the Users → Loggedin menu item.
	 *
	 * Kept under the Users menu (and not promoted to a top-level
	 * item) so existing bookmarks of `users.php?page=loggedin`
	 * keep working through this refactor.
	 *
	 * @since 3.0.0
	 * @since 3.4.0 Replaces WordPress's footer on the screen.
	 *
	 * @return void
	 */
	public function register_menu(): void {
		$hook = add_users_page(
			__( 'Loggedin', 'loggedin' ),
			// translators: %s lock icon.
			sprintf( __( '%s Loggedin', 'loggedin' ), '<span class="dashicons dashicons-lock"></span>' ),
			'manage_options',
			Plugin::SLUG,
			array( $this, 'render_page' )
		);

		if ( $hook ) {
			add_action( 'load-' . $hook, array( $this, 'replace_footer' ) );
		}
	}

	/**
	 * Empty WordPress's admin footer on this screen.
	 *
	 * The app shows its own footer (PageFooter) with Loggedin's links;
	 * the stylesheet hides the empty `#wpfooter`. Every other admin
	 * screen keeps WordPress's footer.
	 *
	 * @since 3.4.0
	 *
	 * @return void
	 */
	public function replace_footer(): void {
		add_filter( 'admin_footer_text', '__return_empty_string', 99 );
		add_filter( 'update_footer', '__return_empty_string', 99 );
	}

	/**
	 * Render the React mount point.
	 *
	 * The bundle's entry (`assets/src/admin.js`) looks for
	 * `#loggedin-admin` and bails silently if it's missing.
	 * `.loggedin-admin` is the root every kit style is scoped under.
	 *
	 * We intentionally skip WordPress's `.wrap` wrapper: the screen
	 * lays out its own sidebar and header edge to edge. The app moves
	 * admin notices into its content column on mount.
	 *
	 * @since 3.0.0
	 *
	 * @return void
	 */
	public function render_page(): void {
		echo '<div id="loggedin-admin" class="loggedin-admin">';
		echo '<noscript><div class="notice notice-error"><p>' . esc_html__( 'The Loggedin settings need JavaScript. Turn it on in your browser to change them.', 'loggedin' ) . '</p></div></noscript>';
		echo '</div>';
	}
}
