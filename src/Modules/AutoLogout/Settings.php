<?php
/**
 * Auto logout option store.
 *
 * Registers both auto logout options in the `loggedin` settings group
 * and exposes them via the REST API, so the admin app reads and writes
 * them through `useEntityProp( 'root', 'site', '...' )`, the same flow
 * the core `loggedin_settings` option uses.
 *
 * Two options rather than one nested blob:
 *
 *  - {@see self::OPTION_KEY} — a fixed set of integer properties.
 *  - {@see self::ROLE_OPTION_KEY} — an open `{ role: minutes }` map,
 *    whose keys aren't knowable at schema-declaration time.
 *
 * @since 3.4.0
 * @package FoxeLabs\Loggedin\Modules\AutoLogout
 */

declare( strict_types = 1 );

namespace FoxeLabs\Loggedin\Modules\AutoLogout;

use WP_User;

defined( 'WPINC' ) || die;

/**
 * Settings store backed by two WP options.
 *
 * @since 3.4.0
 */
final class Settings {

	/**
	 * Option holding the scalar settings.
	 *
	 * @since 3.4.0
	 */
	public const OPTION_KEY = 'loggedin_auto_logout_settings';

	/**
	 * Option holding the role → idle-timeout map, in minutes.
	 *
	 * @since 3.4.0
	 */
	public const ROLE_OPTION_KEY = 'loggedin_auto_logout_role_timeouts';

	/**
	 * User meta holding each device's last activity, `{ verifier: timestamp }`.
	 *
	 * @since 3.4.0
	 */
	public const ACTIVITY_META_KEY = 'loggedin_auto_logout_activity';

	/**
	 * Option holding when an idle timeout was last changed, as a unix timestamp.
	 *
	 * @since 3.4.0
	 */
	public const CHANGED_OPTION_KEY = 'loggedin_auto_logout_changed';

	/**
	 * Largest idle timeout we'll accept, in minutes (30 days).
	 *
	 * An upper bound keeps a fat-fingered value from producing an
	 * activity stamp so far in the future that the guard effectively
	 * stops running, and gives the REST schema something to validate
	 * against.
	 *
	 * @since 3.4.0
	 */
	public const MAX_IDLE_TIMEOUT = 43200;

	/**
	 * Largest non-remembered session lifetime, in hours (1 year).
	 *
	 * @since 3.4.0
	 */
	public const MAX_SESSION_LIFETIME = 8760;

	/**
	 * Largest remembered session lifetime, in days (1 year).
	 *
	 * @since 3.4.0
	 */
	public const MAX_REMEMBER_LIFETIME = 365;

	/**
	 * Largest warning countdown, in seconds (1 hour).
	 *
	 * @since 3.4.0
	 */
	public const MAX_WARNING = 3600;

	/**
	 * Largest value of each scalar setting. Every one starts at zero.
	 *
	 * The one list the schema, {@see self::clamp()} and the settings
	 * screen's field limits read.
	 *
	 * @since 3.4.0
	 */
	public const BOUNDS = array(
		'idle_timeout'      => self::MAX_IDLE_TIMEOUT,
		'warning'           => self::MAX_WARNING,
		'session_lifetime'  => self::MAX_SESSION_LIFETIME,
		'remember_lifetime' => self::MAX_REMEMBER_LIFETIME,
	);

	/**
	 * Register hooks.
	 *
	 * Deferred to the `init` action so we use the same hook the rest of
	 * WordPress does for `register_setting()` calls.
	 *
	 * @since 3.4.0
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'register_settings' ) );

		add_action( 'add_option_' . self::OPTION_KEY, array( $this, 'note_added' ), 10, 2 );
		add_action( 'update_option_' . self::OPTION_KEY, array( $this, 'note_updated' ), 10, 3 );
		add_action( 'add_option_' . self::ROLE_OPTION_KEY, array( $this, 'note_added' ), 10, 2 );
		add_action( 'update_option_' . self::ROLE_OPTION_KEY, array( $this, 'note_updated' ), 10, 3 );
	}

	/**
	 * Note a change when either option is first stored.
	 *
	 * @since 3.4.0
	 *
	 * @param string $option Option name.
	 * @param mixed  $value  Stored value.
	 *
	 * @return void
	 */
	public function note_added( $option, $value ): void {
		$this->note_updated( array(), $value, $option );
	}

	/**
	 * Note a change when an idle timeout changes.
	 *
	 * Only the timeouts count: the warning and the two lifetimes don't
	 * move any session's idle deadline.
	 *
	 * @since 3.4.0
	 *
	 * @param mixed  $old_value Previous value.
	 * @param mixed  $value     New value.
	 * @param string $option    Option name.
	 *
	 * @return void
	 */
	public function note_updated( $old_value, $value, $option = self::OPTION_KEY ): void {
		$old = is_array( $old_value ) ? $old_value : array();
		$new = is_array( $value ) ? $value : array();

		if ( self::OPTION_KEY === $option ) {
			$old = (int) ( $old['idle_timeout'] ?? 0 );
			$new = (int) ( $new['idle_timeout'] ?? 0 );
		}

		if ( $old != $new ) { // phpcs:ignore Universal.Operators.StrictComparisons.LooseNotEqual -- Role maps are equal whatever their key order.
			$this->note_change();
		}
	}

	/**
	 * Record that an idle timeout changed, now.
	 *
	 * Autoloaded: the idle guard reads it for any session it hasn't
	 * stamped yet.
	 *
	 * @since 3.4.0
	 *
	 * @return void
	 */
	public function note_change(): void {
		update_option( self::CHANGED_OPTION_KEY, time(), true );
	}

	/**
	 * When an idle timeout was last changed.
	 *
	 * @since 3.4.0
	 *
	 * @return int Unix timestamp, or 0 if never.
	 */
	public function changed_at(): int {
		return (int) get_option( self::CHANGED_OPTION_KEY, 0 );
	}

	/**
	 * Default value for every scalar setting.
	 *
	 * @since 3.4.0
	 *
	 * @return array{idle_timeout: int, warning: int, session_lifetime: int, remember_lifetime: int}
	 */
	public function defaults(): array {
		return array(
			// Zero means idle logout is off: the default, so it never
			// switches on by itself.
			'idle_timeout'      => 0,
			'warning'           => 60,
			// Zero means "leave WordPress's own cookie duration alone".
			'session_lifetime'  => 0,
			'remember_lifetime' => 0,
		);
	}

	/**
	 * Return every scalar setting, merged over the defaults.
	 *
	 * Values are clamped on read as well as on write: a stored option
	 * can predate a bound (or have been written straight to the DB by
	 * another plugin), and every caller expects an in-range integer.
	 *
	 * @since 3.4.0
	 *
	 * @return array{idle_timeout: int, warning: int, session_lifetime: int, remember_lifetime: int}
	 */
	public function all(): array {
		$stored = get_option( self::OPTION_KEY, array() );

		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		return $this->clamp( array_merge( $this->defaults(), $stored ) );
	}

	/**
	 * Return the stored role → idle-timeout map, in minutes.
	 *
	 * Always returns an array — a clobbered option (someone wrote a
	 * non-array via `update_option`) collapses to an empty map so
	 * callers can iterate without a type guard.
	 *
	 * @since 3.4.0
	 *
	 * @return array<string, int>
	 */
	public function role_timeouts(): array {
		$stored = get_option( self::ROLE_OPTION_KEY, array() );

		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * Resolve the idle timeout that applies to a user, in seconds.
	 *
	 * Role overrides win over the global value, and where a user holds
	 * several roles with overrides the *shortest* one applies. That is
	 * deliberately the opposite of the Limit Per Role add-on's
	 * permissive-wins rule: a session limit denies access, so the loose
	 * value is the safe fallback, whereas an idle timeout only ends an
	 * unattended session, and the tighter value is what a
	 * compliance-driven site is configuring this for in the first
	 * place.
	 *
	 * @since 3.4.0
	 *
	 * @param WP_User|null $user User to resolve for.
	 *
	 * @return int Seconds, or 0 when idle logout is off for this user.
	 */
	public function timeout_for( ?WP_User $user ): int {
		$settings = $this->all();
		$minutes  = $settings['idle_timeout'];

		if ( $user instanceof WP_User && ! empty( $user->roles ) ) {
			$overrides = $this->role_timeouts();

			$matched = array();
			foreach ( $user->roles as $role ) {
				if ( isset( $overrides[ $role ] ) && (int) $overrides[ $role ] > 0 ) {
					$matched[] = (int) $overrides[ $role ];
				}
			}

			if ( ! empty( $matched ) ) {
				$minutes = min( $matched );
			}
		}

		/**
		 * Filter the resolved idle timeout for a user, in minutes.
		 *
		 * Return 0 to exempt the user from idle logout entirely — the
		 * hook every "never log out my API robot / kiosk display"
		 * request ends up needing.
		 *
		 * @since 3.4.0
		 *
		 * @param int          $minutes Resolved timeout in minutes.
		 * @param WP_User|null $user    User being resolved.
		 */
		$minutes = (int) apply_filters( 'loggedin_auto_logout_timeout', $minutes, $user );

		return max( 0, $minutes ) * MINUTE_IN_SECONDS;
	}

	/**
	 * Register both options with WordPress (REST-exposed).
	 *
	 * @since 3.4.0
	 *
	 * @return void
	 */
	public function register_settings(): void {
		register_setting(
			'loggedin',
			self::OPTION_KEY,
			array(
				'type'              => 'object',
				'default'           => $this->defaults(),
				'sanitize_callback' => array( $this, 'sanitize' ),
				'show_in_rest'      => array(
					'schema' => array(
						'type'                 => 'object',
						'additionalProperties' => false,
						'properties'           => array_map(
							static function ( int $max ): array {
								return array(
									'type'    => 'integer',
									'minimum' => 0,
									'maximum' => $max,
								);
							},
							self::BOUNDS
						),
					),
				),
			)
		);

		register_setting(
			'loggedin',
			self::ROLE_OPTION_KEY,
			array(
				'type'              => 'object',
				'default'           => array(),
				'sanitize_callback' => array( $this, 'sanitize_roles' ),
				'show_in_rest'      => array(
					'schema' => array(
						'type'                 => 'object',
						// Role slugs are site-specific (custom roles,
						// multisite differences) so they can't be
						// enumerated at registration time.
						'additionalProperties' => array(
							'type'    => 'integer',
							'minimum' => 0,
							'maximum' => self::MAX_IDLE_TIMEOUT,
						),
					),
				),
			)
		);
	}

	/**
	 * Sanitize the scalar settings option.
	 *
	 * Unknown keys are dropped and every known key is clamped into
	 * range, so a partial payload from the REST layer still round-trips
	 * to a complete, valid option.
	 *
	 * @since 3.4.0
	 *
	 * @param mixed $value Raw input.
	 *
	 * @return array{idle_timeout: int, warning: int, session_lifetime: int, remember_lifetime: int}
	 */
	public function sanitize( $value ): array {
		if ( ! is_array( $value ) ) {
			return $this->defaults();
		}

		$clean = $this->defaults();

		foreach ( array_keys( $clean ) as $key ) {
			if ( isset( $value[ $key ] ) ) {
				$clean[ $key ] = (int) $value[ $key ];
			}
		}

		return $this->clamp( $clean );
	}

	/**
	 * Sanitize a role → idle-timeout map.
	 *
	 * Drops keys that aren't real role slugs, clamps values into range,
	 * and removes zero entries so the stored option only carries roles
	 * that actually override the global timeout.
	 *
	 * @since 3.4.0
	 *
	 * @param mixed $value Raw input.
	 *
	 * @return array<string, int>
	 */
	public function sanitize_roles( $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}

		// Resolve the role catalogue once per call — `wp_roles()` is
		// cheap but `get_names()` builds a fresh array each time.
		$valid_roles = array_keys( wp_roles()->get_names() );
		$clean       = array();

		foreach ( $value as $role => $minutes ) {
			// Unknown role slug — strip silently rather than throwing;
			// the REST layer already rejects type mismatches, anything
			// landing here is either a custom role removed since save
			// or a hand-crafted payload.
			if ( ! in_array( $role, $valid_roles, true ) ) {
				continue;
			}

			$minutes = min( self::MAX_IDLE_TIMEOUT, (int) $minutes );
			if ( $minutes > 0 ) {
				$clean[ (string) $role ] = $minutes;
			}
		}

		return $clean;
	}

	/**
	 * Clamp every scalar setting into its documented range.
	 *
	 * @since 3.4.0
	 *
	 * @param array<string, mixed> $settings Settings with all keys present.
	 *
	 * @return array{idle_timeout: int, warning: int, session_lifetime: int, remember_lifetime: int}
	 */
	private function clamp( array $settings ): array {
		$clean = array();
		foreach ( self::BOUNDS as $key => $max ) {
			$clean[ $key ] = min( $max, max( 0, (int) ( $settings[ $key ] ?? 0 ) ) );
		}

		return $clean;
	}
}
