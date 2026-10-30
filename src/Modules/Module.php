<?php
/**
 * A feature built around the session limit.
 *
 * @since 3.4.0
 * @package FoxeLabs\Loggedin\Modules
 */

declare( strict_types = 1 );

namespace FoxeLabs\Loggedin\Modules;

defined( 'WPINC' ) || die;

/**
 * What every module under `src/Modules/` implements.
 *
 * A module owns its settings, hooks and screens. It may use the core
 * services it is given (settings, the session store), but never
 * another module: modules talk to the rest of the plugin through
 * WordPress hooks.
 *
 * @since 3.4.0
 */
interface Module {

	/**
	 * Add the module's hooks.
	 *
	 * Called once, while the plugin boots on `plugins_loaded`.
	 *
	 * @since 3.4.0
	 *
	 * @return void
	 */
	public function register(): void;
}
