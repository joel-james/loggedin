<?php
/**
 * Uninstall routine.
 *
 * Fired by WordPress when the plugin is deleted from the Plugins
 * screen. See FoxeLabs\Loggedin\Uninstaller for what is removed.
 *
 * @package FoxeLabs\Loggedin
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( ! is_readable( __DIR__ . '/vendor/autoload.php' ) ) {
	return;
}

require_once __DIR__ . '/vendor/autoload.php';

( new FoxeLabs\Loggedin\Uninstaller() )->run();
