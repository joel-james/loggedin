<?php
/**
 * Tests for {@see \FoxeLabs\Loggedin\Admin\Assets}.
 *
 * @package FoxeLabs\Loggedin\Tests\Admin
 */

declare( strict_types = 1 );

namespace FoxeLabs\Loggedin\Tests\Admin;

use FoxeLabs\Loggedin\Admin\Assets;
use WP_UnitTestCase;


/**
 * @group assets
 */
class AssetsTest extends WP_UnitTestCase {

	public function test_the_jsx_runtime_is_registered_where_wordpress_lacks_it(): void {
		wp_deregister_script( 'react-jsx-runtime' );

		Assets::instance()->register_jsx_runtime( 'users_page_loggedin' );

		$this->assertStringContainsString( 'loggedin/build/react-jsx-runtime.js', wp_scripts()->registered['react-jsx-runtime']->src );
	}

	public function test_wordpress_own_jsx_runtime_is_kept(): void {
		wp_deregister_script( 'react-jsx-runtime' );
		wp_register_script( 'react-jsx-runtime', '/wp-includes/js/dist/vendor/react-jsx-runtime.js', array( 'react' ), '1', true );

		Assets::instance()->register_jsx_runtime( 'users_page_loggedin' );

		$this->assertSame( '/wp-includes/js/dist/vendor/react-jsx-runtime.js', wp_scripts()->registered['react-jsx-runtime']->src );
	}

	public function test_other_screens_are_left_alone(): void {
		wp_deregister_script( 'react-jsx-runtime' );

		Assets::instance()->register_jsx_runtime( 'index.php' );

		$this->assertFalse( wp_script_is( 'react-jsx-runtime', 'registered' ) );
	}
}
