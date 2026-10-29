<?php
/**
 * Constants PHPStan needs to read the plugin without WordPress loaded.
 *
 * @package FoxeLabs\Loggedin
 */

define( 'LOGGEDIN_FILE', __DIR__ . '/../../loggedin.php' );
define( 'LOGGEDIN_DIR', dirname( __DIR__, 2 ) . '/' );
define( 'WP_UNINSTALL_PLUGIN', 'loggedin/loggedin.php' );
define( 'LOGGED_IN_COOKIE', 'wordpress_logged_in_' );
define( 'COOKIEPATH', '/' );
define( 'SITECOOKIEPATH', '/' );
