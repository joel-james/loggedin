<?php
/**
 * WP-CLI command registrar.
 *
 * Boots the `wp loggedin ...` command tree. Loaded from
 * {@see \FoxeLabs\Loggedin\Core::cli()}, which only runs the module when
 * the request is actually a WP-CLI one — so nothing in this directory
 * is ever touched on a web request.
 *
 * Command classes are registered as *instances* rather than class
 * names. WP-CLI happily accepts either, but passing an instance keeps
 * the constructor contract ours (no implicit `new` by the framework)
 * and mirrors how the rest of the plugin hands modules around.
 *
 * @package FoxeLabs\Loggedin\Cli
 */

declare( strict_types = 1 );

namespace FoxeLabs\Loggedin\Cli;

use FoxeLabs\Loggedin\Contracts\Singleton;
use WP_CLI;

defined( 'WPINC' ) || die;

/**
 * Registers every `wp loggedin` subcommand.
 *
 * @since 3.1.0
 */
final class Commands {

	use Singleton;

	/**
	 * Register the command tree.
	 *
	 * The `class_exists` guard is belt-and-braces: `Core` already
	 * gates this module behind the `WP_CLI` constant, but the check
	 * keeps the class safe to instantiate directly from a test.
	 *
	 * @since 3.1.0
	 */
	protected function init(): void {
		if ( ! class_exists( WP_CLI::class ) ) {
			return;
		}

		WP_CLI::add_command( 'loggedin sessions', new SessionsCommand() );
		WP_CLI::add_command( 'loggedin settings', new SettingsCommand() );

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
