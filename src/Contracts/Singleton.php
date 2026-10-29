<?php
/**
 * Singleton trait.
 *
 * Every module class (`Core`, `Admin\Admin`, `Settings\Settings`,
 * `Sessions\SessionGuard`, etc.) is a singleton — there is one and only
 * one instance per request, accessed via `MyClass::instance()`. This
 * trait centralises the boilerplate so individual classes only have
 * to implement an `init()` method describing what to wire up when
 * they're first instantiated.
 *
 * Why singletons here:
 *   - Each module owns a slice of WordPress hooks. Instantiating it
 *     twice would register every hook twice, which silently doubles
 *     up callbacks (notices fire twice, REST routes register twice,
 *     etc.).
 *   - Tests can call `MyClass::instance()` repeatedly without having
 *     to thread instances around through fixtures.
 *
 * @package FoxeLabs\Loggedin\Contracts
 */

declare( strict_types = 1 );

namespace FoxeLabs\Loggedin\Contracts;

defined( 'WPINC' ) || die;

trait Singleton {

	/**
	 * The one cached instance, lazily created on the first
	 * `instance()` call.
	 *
	 * Typed loosely as `static|null` so each consuming class gets back
	 * an instance of its own class (not the trait).
	 *
	 * @var static|null
	 */
	private static $instance = null;

	/**
	 * Return the shared instance, instantiating on first call.
	 *
	 * Marked `final` so subclasses can't override the cache lookup
	 * and accidentally allow a second instance through.
	 *
	 * @return static
	 */
	final public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructor is private so callers must go through `instance()`.
	 *
	 * The constructor immediately delegates to `init()`, which each
	 * consuming class implements to register its hooks.
	 */
	private function __construct() {
		$this->init();
	}

	/**
	 * Per-class wiring hook.
	 *
	 * Implementations should register WordPress action / filter
	 * callbacks here, not perform expensive work. The method runs at
	 * the moment the singleton is first requested — usually inside
	 * the phased boot in {@see \FoxeLabs\Loggedin\Core::init()}.
	 */
	abstract protected function init(): void;

	/**
	 * Singletons are not cloneable.
	 *
	 * Cloning would create a second instance and re-register hooks.
	 */
	private function __clone() {}

	/**
	 * Singletons are not unserializable.
	 *
	 * If we allowed `unserialize()`, a malicious payload could
	 * instantiate the class outside the `instance()` cache and
	 * smuggle in additional hook registrations.
	 *
	 * @throws \RuntimeException Always.
	 */
	final public function __wakeup(): void {
		throw new \RuntimeException( 'Cannot unserialize singleton.' );
	}
}
