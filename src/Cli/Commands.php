<?php
/**
 * WP-CLI command registrar.
 *
 * Boots the `wp loggedin ...` command tree. Registered by
 * {@see \FoxeLabs\Loggedin\Plugin::boot()}, which only does so when
 * the request is actually a WP-CLI one — so nothing in this directory
 * is ever touched on a web request.
 *
 * Command classes are registered as *instances* rather than class
 * names. WP-CLI happily accepts either, but passing an instance keeps
 * the constructor contract ours (no implicit `new` by the framework)
 * and mirrors how the rest of the plugin hands modules around.
 *
 * @since 3.1.0
 * @package FoxeLabs\Loggedin\Cli
 */

declare( strict_types = 1 );

namespace FoxeLabs\Loggedin\Cli;

use FoxeLabs\Loggedin\Sessions\SessionStore;
use FoxeLabs\Loggedin\Settings\Settings;
use WP_CLI;

defined( 'WPINC' ) || die;

/**
 * Registers every `wp loggedin` subcommand.
 *
 * @since 3.1.0
 */
final class Commands {

	/**
	 * Session store.
	 *
	 * @since 3.4.0
	 *
	 * @var SessionStore
	 */
	private SessionStore $store;

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
	 * @param SessionStore $store    Session store.
	 * @param Settings     $settings Settings store.
	 */
	public function __construct( SessionStore $store, Settings $settings ) {
		$this->store    = $store;
		$this->settings = $settings;
	}

	/**
	 * Register the command tree.
	 *
	 * The `class_exists` guard is belt-and-braces: `Plugin` already
	 * gates this module behind the `WP_CLI` constant, but the check
	 * keeps the class safe to instantiate directly from a test.
	 *
	 * @since 3.1.0
	 */
	public function register(): void {
		if ( ! class_exists( WP_CLI::class ) ) {
			return;
		}

		WP_CLI::add_command( 'loggedin sessions', new SessionsCommand( $this->store ) );
		WP_CLI::add_command( 'loggedin settings', new SettingsCommand( $this->settings ) );

		/**
		 * Fires after the core `wp loggedin` commands are registered.
		 *
		 * Add-ons should hook here to register their own subcommands
		 * under the same namespace — by this point the parent command
		 * exists, so `wp loggedin my-thing` resolves correctly.
		 *
		 * @since 3.1.0
		 */
		do_action( 'loggedin_cli_init' );
	}
}
