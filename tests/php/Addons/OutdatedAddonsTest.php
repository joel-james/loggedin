<?php
/**
 * Tests for {@see \FoxeLabs\Loggedin\Addons\OutdatedAddons}.
 *
 * @package FoxeLabs\Loggedin\Tests\Addons
 */

declare( strict_types = 1 );

namespace FoxeLabs\Loggedin\Tests\Addons;

use FoxeLabs\Loggedin\Addons\OutdatedAddons;
use WP_UnitTestCase;


/**
 * @group addons
 */
class OutdatedAddonsTest extends WP_UnitTestCase {

	/**
	 * Fake add-on main files, removed after each test.
	 *
	 * @var array<string>
	 */
	private array $files = array();

	public function tear_down(): void {
		remove_all_filters( 'loggedin_register_addon' );

		foreach ( $this->files as $file ) {
			unlink( $file );
		}

		$this->files = array();

		parent::tear_down();
	}

	/**
	 * Register an add-on whose main file says it is at a version.
	 *
	 * @param string $slug    Add-on slug.
	 * @param string $version Version in its header.
	 *
	 * @return void
	 */
	private function addon( string $slug, string $version ): void {
		$file          = wp_tempnam( $slug );
		$this->files[] = $file;

		file_put_contents( $file, "<?php\n/**\n * Plugin Name: Loggedin - {$slug}\n * Version: {$version}\n */\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		add_filter(
			'loggedin_register_addon',
			static function ( array $addons ) use ( $slug, $file ): array {
				$addons[ crc32( $slug ) ] = array(
					'slug'      => $slug,
					'main_file' => $file,
				);

				return $addons;
			}
		);
	}

	public function test_an_add_on_older_than_the_screen_is_listed(): void {
		$this->addon( 'loggedin-active-sessions', '1.1.0' );

		$outdated = OutdatedAddons::instance()->outdated();

		$this->assertCount( 1, $outdated );
		$this->assertSame( '1.1.0', $outdated[0]['version'] );
		$this->assertSame( '1.2.0', $outdated[0]['minimum'] );
	}

	public function test_an_updated_add_on_is_not_listed(): void {
		$this->addon( 'loggedin-limit-per-role', '2.2.0' );

		$this->assertSame( array(), OutdatedAddons::instance()->outdated() );
	}

	public function test_an_add_on_without_settings_on_the_screen_is_not_listed(): void {
		$this->addon( 'loggedin-limit-per-user', '2.0.0' );

		$this->assertSame( array(), OutdatedAddons::instance()->outdated() );
	}
}
