<?php
/**
 * Minimal WP-CLI stubs for PHPStan.
 *
 * php-stubs/wp-cli-stubs requires an older php-stubs/wordpress-stubs, so the
 * few WP-CLI APIs Loggedin uses are declared here. Scanned, never loaded.
 *
 * @package FoxeLabs\Loggedin
 */

// phpcs:ignoreFile -- Stub signatures mirror WP-CLI.

namespace {
	class WP_CLI {
		/**
		 * @param string          $name     Command name.
		 * @param callable|object $callable Command.
		 * @param array<string, mixed> $args Options.
		 * @return bool
		 */
		public static function add_command( $name, $callable, $args = array() ) {}

		/**
		 * @param string $message Message.
		 * @return void
		 */
		public static function line( $message = '' ) {}

		/**
		 * @param string $message Message.
		 * @return void
		 */
		public static function success( $message ) {}

		/**
		 * @param string $message Message.
		 * @return void
		 */
		public static function warning( $message ) {}

		/**
		 * @param string|\WP_Error $message Message.
		 * @param bool|int         $exit    Whether to exit.
		 * @return never
		 */
		public static function error( $message, $exit = true ) {}

		/**
		 * @param string               $question   Question.
		 * @param array<string, mixed> $assoc_args Arguments.
		 * @return void
		 */
		public static function confirm( $question, $assoc_args = array() ) {}
	}
}

namespace WP_CLI {
	class Formatter {
		/**
		 * @param array<string, mixed> $assoc_args Arguments.
		 * @param array<int, string>   $fields     Fields.
		 * @param string|false         $prefix     Prefix.
		 */
		public function __construct( &$assoc_args, $fields = null, $prefix = false ) {}

		/**
		 * @param iterable<mixed>         $items       Items.
		 * @param bool|array<int, string> $ascii_pre_colorized Colorized.
		 * @return void
		 */
		public function display_items( $items, $ascii_pre_colorized = false ) {}
	}
}

namespace WP_CLI\Fetchers {
	class User {
		/**
		 * @param string $arg User ID, email or login.
		 * @return \WP_User
		 */
		public function get_check( $arg ) {}
	}
}

namespace WP_CLI\Utils {
	/**
	 * @param array<string, mixed> $assoc_args Arguments.
	 * @param string               $flag       Flag.
	 * @param mixed                $default    Default.
	 * @return mixed
	 */
	function get_flag_value( $assoc_args, $flag, $default = null ) {}
}
