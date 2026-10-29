<?php
/**
 * `wp loggedin settings` commands.
 *
 * Thin CLI layer over {@see \FoxeLabs\Loggedin\Settings\Settings}. Every
 * write goes through `Settings::update()`, which means the CLI shares
 * the exact same sanitizer as the REST route and a direct
 * `update_option()` call — there is no second validation path to keep
 * in sync.
 *
 * The known-key list is read from `Settings::defaults()` rather than
 * hard-coded, so a key introduced by an add-on through the
 * `loggedin_settings_defaults` filter is readable and writable here
 * without touching this class.
 *
 * @package FoxeLabs\Loggedin\Cli
 */

declare( strict_types = 1 );

namespace FoxeLabs\Loggedin\Cli;

use FoxeLabs\Loggedin\Settings\Settings;
use WP_CLI;
use WP_CLI\Formatter;

defined( 'WPINC' ) || die;

/**
 * Read and write the plugin settings.
 *
 * @since 3.1.0
 */
final class SettingsCommand {

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
	 * @param Settings $settings Settings store.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * List every setting and its current value.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Render output in a particular format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 *   - yaml
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp loggedin settings list
	 *     +---------+-------+
	 *     | key     | value |
	 *     +---------+-------+
	 *     | maximum | 3     |
	 *     | logic   | block |
	 *     +---------+-------+
	 *
	 * @subcommand list
	 *
	 * @since 3.1.0
	 *
	 * @param array<int, string>    $args       Positional arguments.
	 * @param array<string, string> $assoc_args Associative arguments.
	 *
	 * @return void
	 */
	public function list_( array $args, array $assoc_args ): void {
		$rows = array();

		foreach ( $this->settings->all() as $key => $value ) {
			$rows[] = array(
				'key'   => (string) $key,
				'value' => is_scalar( $value ) ? (string) $value : wp_json_encode( $value ),
			);
		}

		$formatter = new Formatter( $assoc_args, array( 'key', 'value' ) );
		$formatter->display_items( $rows );
	}

	/**
	 * Get the value of a single setting.
	 *
	 * ## OPTIONS
	 *
	 * <key>
	 * : Setting to read. Run `wp loggedin settings list` for the
	 * available keys.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp loggedin settings get maximum
	 *     3
	 *
	 * @since 3.1.0
	 *
	 * @param array<int, string> $args Positional arguments.
	 *
	 * @return void
	 */
	public function get( array $args ): void {
		$key = $this->validate_key( $args[0] );

		$value = $this->settings->get( $key );

		WP_CLI::line( is_scalar( $value ) ? (string) $value : (string) wp_json_encode( $value ) );
	}

	/**
	 * Update the value of a single setting.
	 *
	 * The value is run through the shared sanitizer *before* anything is
	 * written, and a value the sanitizer would alter is rejected
	 * outright. Letting the write through would mean `set logic
	 * nonsense` silently resets a site from `block` to the default
	 * `allow` — a typo would quietly loosen the very limit the plugin
	 * exists to enforce.
	 *
	 * ## OPTIONS
	 *
	 * <key>
	 * : Setting to write. Run `wp loggedin settings list` for the
	 * available keys.
	 *
	 * <value>
	 * : New value. For `logic`, one of: allow, logout_oldest, block.
	 *
	 * ## EXAMPLES
	 *
	 *     # Allow three concurrent sessions per account.
	 *     $ wp loggedin settings set maximum 3
	 *     Success: Updated maximum to 3.
	 *
	 *     # Block the new login instead of freeing a slot.
	 *     $ wp loggedin settings set logic block
	 *     Success: Updated logic to block.
	 *
	 * @since 3.1.0
	 *
	 * @param array<int, string> $args Positional arguments.
	 *
	 * @return void
	 */
	public function set( array $args ): void {
		$key      = $this->validate_key( $args[0] );
		$value    = (string) $args[1];
		$settings = $this->settings;
		$typed    = $value;

		// On/off settings take true/false, yes/no, on/off or 1/0.
		if ( is_bool( $settings->defaults()[ $key ] ) ) {
			$typed = filter_var( $value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );
		}

		$candidate = array_merge( $settings->all(), array( $key => $typed ) );
		$sanitized = $settings->sanitize( $candidate );

		if (
			null === $typed
			|| ! isset( $sanitized[ $key ] )
			|| ( is_bool( $typed ) ? $sanitized[ $key ] !== $typed : (string) $sanitized[ $key ] !== $value )
		) {
			WP_CLI::error(
				sprintf(
					/* translators: 1: submitted value, 2: setting key. */
					__( '"%1$s" is not a valid value for %2$s. Nothing was changed.', 'loggedin' ),
					$value,
					$key
				)
			);
		}

		$settings->update( $candidate );

		WP_CLI::success(
			sprintf(
				/* translators: 1: setting key, 2: stored value. */
				__( 'Updated %1$s to %2$s.', 'loggedin' ),
				$key,
				is_bool( $settings->get( $key ) )
					? wp_json_encode( $settings->get( $key ) )
					: (string) $settings->get( $key )
			)
		);
	}

	/**
	 * Ensure the key is one the settings store knows about.
	 *
	 * Halts the command with the list of valid keys when it isn't —
	 * cheaper for the user than a silent no-op write.
	 *
	 * @since 3.1.0
	 *
	 * @param string $key Raw key from the command line.
	 *
	 * @return string
	 */
	private function validate_key( string $key ): string {
		$known = array_keys( $this->settings->defaults() );

		if ( ! in_array( $key, $known, true ) ) {
			WP_CLI::error(
				sprintf(
					/* translators: 1: submitted key, 2: comma-separated list of valid keys. */
					__( 'Unknown setting "%1$s". Available settings: %2$s.', 'loggedin' ),
					$key,
					implode( ', ', $known )
				)
			);
		}

		return $key;
	}
}
