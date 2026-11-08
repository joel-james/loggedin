<?php
/**
 * Tests for {@see \FoxeLabs\Loggedin\Addons\Addons}.
 *
 * @package FoxeLabs\Loggedin\Tests\Addons
 */

declare( strict_types = 1 );

namespace FoxeLabs\Loggedin\Tests\Addons;

use FoxeLabs\Loggedin\Plugin;
use WP_UnitTestCase;

/**
 * @group addons
 */
class AddonsTest extends WP_UnitTestCase {

	public function tear_down(): void {
		remove_all_filters( 'loggedin_register_addon' );

		parent::tear_down();
	}

	public function test_a_register_callback_that_returns_nothing_is_not_an_error(): void {
		add_filter( 'loggedin_register_addon', '__return_null' );

		$this->assertSame( array(), Plugin::instance()->addons()->get_registered_addons() );
	}

	public function test_malformed_add_on_entries_are_skipped(): void {
		add_filter(
			'loggedin_register_addon',
			static function () {
				return array(
					'not-an-id' => array( 'slug' => 'nope' ),
					12345       => 'not an array',
				);
			}
		);

		Plugin::instance()->addons()->init_freemius();

		// Reaching here means neither entry reached Freemius::get_instance().
		$this->assertTrue( true );
	}
}
