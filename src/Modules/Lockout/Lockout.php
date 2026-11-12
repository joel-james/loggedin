<?php
/**
 * Lockout & recovery: what users see when the limit stops or ends a login.
 *
 * @since 3.4.0
 * @package FoxeLabs\Loggedin\Modules\Lockout
 */

declare( strict_types = 1 );

namespace FoxeLabs\Loggedin\Modules\Lockout;

use FoxeLabs\Loggedin\Modules\Module;
use FoxeLabs\Loggedin\Sessions\SessionStore;
use FoxeLabs\Loggedin\Settings\Settings;

defined( 'WPINC' ) || die;

/**
 * Self-unlock by email, and the notices on both sides of a login that
 * signs out other devices.
 *
 * Its settings (`unlock`, `displaced_redirect`, `displacing_notice`) are part of the core
 * `loggedin_settings` option, on Settings › Lockout & recovery. It
 * reaches the session guard only through hooks:
 * `loggedin_error_message` for the unlock link, and
 * `loggedin_sessions_displacing` for the sessions a login ends.
 *
 * @since 3.4.0
 * @since 3.4.2 Tells the new login that it signed out other devices.
 */
final class Lockout implements Module {

	/**
	 * Self-unlock by email.
	 *
	 * @since 3.4.0
	 *
	 * @var Unlock
	 */
	private Unlock $unlock;

	/**
	 * Signed out by a newer login.
	 *
	 * @since 3.4.0
	 *
	 * @var Displaced
	 */
	private Displaced $displaced;

	/**
	 * Signed out other devices by logging in.
	 *
	 * @since 3.4.2
	 *
	 * @var Displacing
	 */
	private Displacing $displacing;

	/**
	 * Build the module's parts.
	 *
	 * @since 3.4.0
	 *
	 * @param Settings     $settings Settings store.
	 * @param SessionStore $store    Session store.
	 */
	public function __construct( Settings $settings, SessionStore $store ) {
		$this->unlock     = new Unlock( $settings, $store );
		$this->displaced  = new Displaced( $settings, $store );
		$this->displacing = new Displacing( $settings );
	}

	/**
	 * Add the module's hooks.
	 *
	 * @since 3.4.0
	 *
	 * @return void
	 */
	public function register(): void {
		$this->unlock->register();
		$this->displaced->register();
		$this->displacing->register();
	}

	/**
	 * Self-unlock by email.
	 *
	 * @since 3.4.0
	 *
	 * @return Unlock
	 */
	public function unlock(): Unlock {
		return $this->unlock;
	}

	/**
	 * Signed out by a newer login.
	 *
	 * @since 3.4.0
	 *
	 * @return Displaced
	 */
	public function displaced(): Displaced {
		return $this->displaced;
	}

	/**
	 * Signed out other devices by logging in.
	 *
	 * @since 3.4.2
	 *
	 * @return Displacing
	 */
	public function displacing(): Displacing {
		return $this->displacing;
	}
}
