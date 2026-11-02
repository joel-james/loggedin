<?php
/**
 * PHPUnit bootstrap.
 *
 * Expectations:
 *  - Run `bin/install-wp-tests.sh <db_name> <db_user> <db_pass> [db_host] [wp_version]`
 *    once to install the WordPress test scaffolding under /tmp/.
 *  - Run `composer install` so the Yoast PHPUnit polyfills are present.
 *
 * @package FoxeLabs\Loggedin
 */

declare( strict_types = 1 );

$loggedin_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $loggedin_tests_dir ) {
	$loggedin_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

if ( ! file_exists( $loggedin_tests_dir . '/includes/functions.php' ) ) {
	fwrite(
		STDERR,
		'Could not find ' . $loggedin_tests_dir . '/includes/functions.php — install the WordPress test suite by running bin/install-wp-tests.sh first.' . PHP_EOL
	);
	exit( 1 );
}

require_once dirname( __DIR__, 2 ) . '/vendor/yoast/phpunit-polyfills/phpunitpolyfills-autoload.php';
require_once $loggedin_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function (): void {
		require dirname( __DIR__, 2 ) . '/loggedin.php';
	}
);

require $loggedin_tests_dir . '/includes/bootstrap.php';
