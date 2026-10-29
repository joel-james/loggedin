<?php
/**
 * Auto logout: idle logout and session length.
 *
 * @package FoxeLabs\Loggedin\Modules\AutoLogout
 */

declare( strict_types = 1 );

namespace FoxeLabs\Loggedin\Modules\AutoLogout;

use FoxeLabs\Loggedin\Modules\Module;

defined( 'WPINC' ) || die;

/**
 * Signs idle sessions out and sets how long a login lasts.
 *
 * Settings › Auto logout. Keeps its settings in two options of its own
 * ({@see Settings}).
 *
 * @since 3.4.0
 */
final class AutoLogout implements Module {

	/**
	 * Auto logout settings.
	 *
	 * @since 3.4.0
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Idle logout.
	 *
	 * @since 3.4.0
	 *
	 * @var IdleGuard
	 */
	private IdleGuard $idle_guard;

	/**
	 * Session length.
	 *
	 * @since 3.4.0
	 *
	 * @var Lifetime
	 */
	private Lifetime $lifetime;

	/**
	 * Build the module's parts.
	 *
	 * @since 3.4.0
	 */
	public function __construct() {
		$this->settings   = new Settings();
		$this->idle_guard = new IdleGuard( $this->settings );
		$this->lifetime   = new Lifetime( $this->settings );
	}

	/**
	 * Add the module's hooks.
	 *
	 * @since 3.4.0
	 *
	 * @return void
	 */
	public function register(): void {
		$this->settings->register();
		$this->idle_guard->register();
		$this->lifetime->register();

		add_filter( 'loggedin_admin_script_vars', array( $this, 'script_vars' ) );
	}

	/**
	 * Give the settings screen the upper limit of each field.
	 *
	 * @since 3.4.0
	 *
	 * @param array<string, mixed> $vars Admin app data.
	 *
	 * @return array<string, mixed>
	 */
	public function script_vars( $vars ): array {
		$vars               = (array) $vars;
		$vars['autoLogout'] = array(
			'max' => array(
				'idle_timeout'      => Settings::MAX_IDLE_TIMEOUT,
				'warning'           => Settings::MAX_WARNING,
				'session_lifetime'  => Settings::MAX_SESSION_LIFETIME,
				'remember_lifetime' => Settings::MAX_REMEMBER_LIFETIME,
			),
		);

		return $vars;
	}

	/**
	 * Auto logout settings.
	 *
	 * @since 3.4.0
	 *
	 * @return Settings
	 */
	public function settings(): Settings {
		return $this->settings;
	}

	/**
	 * Idle logout.
	 *
	 * @since 3.4.0
	 *
	 * @return IdleGuard
	 */
	public function idle_guard(): IdleGuard {
		return $this->idle_guard;
	}

	/**
	 * Session length.
	 *
	 * @since 3.4.0
	 *
	 * @return Lifetime
	 */
	public function lifetime(): Lifetime {
		return $this->lifetime;
	}
}
