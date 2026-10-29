<?php
/**
 * Tests for {@see \FoxeLabs\Loggedin\Setup\Settings}.
 *
 * @package FoxeLabs\Loggedin
 */

declare( strict_types = 1 );

use FoxeLabs\Loggedin\Admin\Exempt_Admins_Notice;
use FoxeLabs\Loggedin\Plugin;
use FoxeLabs\Loggedin\Setup\Settings;
use FoxeLabs\Loggedin\Setup\Upgrader;

/**
 * @group settings
 */
class Loggedin_Settings_Test extends WP_UnitTestCase {

	public function tear_down(): void {
		delete_option( Plugin::OPTION_KEY );
		delete_option( 'loggedin_maximum' );
		delete_option( 'loggedin_logic' );
		delete_option( Exempt_Admins_Notice::OPTION );
		remove_all_filters( 'loggedin_logics' );
		Settings::instance()->flush();
		Settings::instance()->register();

		parent::tear_down();
	}

	public function test_defaults_have_expected_keys(): void {
		$defaults = Settings::instance()->defaults();

		$this->assertSame( 1, $defaults['maximum'] );
		$this->assertSame( 'allow', $defaults['logic'] );
	}

	public function test_update_and_get_round_trip(): void {
		Settings::instance()->update(
			array(
				'maximum' => 5,
				'logic'   => 'block',
			)
		);

		$this->assertSame( 5, Settings::instance()->get( 'maximum' ) );
		$this->assertSame( 'block', Settings::instance()->get( 'logic' ) );
	}

	public function test_sanitize_rejects_invalid_logic(): void {
		$result = Settings::instance()->sanitize(
			array(
				'maximum' => 0,
				'logic'   => 'nonsense',
			)
		);

		$this->assertSame( 1, $result['maximum'] );
		$this->assertSame( 'allow', $result['logic'] );
	}

	public function test_all_merges_partial_stored_with_defaults(): void {
		update_option( Plugin::OPTION_KEY, array( 'maximum' => 3 ) );

		$all = Settings::instance()->all();

		$this->assertSame( 3, $all['maximum'] );
		$this->assertSame( 'allow', $all['logic'] );
	}

	public function test_all_is_memoized_within_a_request(): void {
		$settings = Settings::instance();
		$settings->flush();

		$calls    = 0;
		$counter  = function ( $defaults ) use ( &$calls ) {
			++$calls;

			return $defaults;
		};

		add_filter( 'loggedin_settings_defaults', $counter );

		$settings->all();
		$settings->all();
		$settings->get( 'logic' );

		remove_filter( 'loggedin_settings_defaults', $counter );

		// Three reads, one build. Before memoization each read
		// re-ran the defaults filter and rebuilt the merged array.
		$this->assertSame( 1, $calls );
	}

	public function test_writing_the_option_invalidates_the_cache(): void {
		$settings = Settings::instance();

		update_option( Plugin::OPTION_KEY, array( 'maximum' => 2 ) );
		$this->assertSame( 2, $settings->get( 'maximum' ) );

		// A write through the plain options API — the path the REST
		// route and WP-CLI both use — must not leave a stale read
		// behind.
		update_option( Plugin::OPTION_KEY, array( 'maximum' => 7 ) );
		$this->assertSame( 7, $settings->get( 'maximum' ) );

		// And through the store's own writer.
		$settings->update( array( 'maximum' => 3 ) );
		$this->assertSame( 3, $settings->get( 'maximum' ) );

		// Deleting falls back to defaults rather than the last value.
		delete_option( Plugin::OPTION_KEY );
		$this->assertSame( 1, $settings->get( 'maximum' ) );
	}

	public function test_new_switches_default_to_on(): void {
		$defaults = Settings::instance()->defaults();

		$this->assertTrue( $defaults['unlock'] );
		$this->assertTrue( $defaults['exempt_admins'] );
		$this->assertTrue( $defaults['password_signout'] );
	}

	public function test_sanitize_casts_switches_to_booleans(): void {
		$result = Settings::instance()->sanitize(
			array(
				'unlock'        => 0,
				'exempt_admins' => '1',
			)
		);

		$this->assertFalse( $result['unlock'] );
		$this->assertTrue( $result['exempt_admins'] );
		$this->assertTrue( $result['password_signout'] );
	}

	/**
	 * Store the option the way a version before 3.4.0 left it.
	 *
	 * Without the sanitizer, which would fill in the 3.4.0 keys.
	 *
	 * @param array $value Option value.
	 */
	private function store_old_option( array $value ): void {
		remove_all_filters( 'sanitize_option_' . Plugin::OPTION_KEY );
		update_option( Plugin::OPTION_KEY, $value );
	}

	public function test_an_upgrade_from_before_340_keeps_the_old_behaviour(): void {
		update_option( Plugin::VERSION_KEY, '3.2.0' );
		$this->store_old_option( array( 'maximum' => 2 ) );

		Upgrader::instance()->maybe_upgrade();

		$stored = get_option( Plugin::OPTION_KEY );
		$this->assertFalse( $stored['unlock'] );
		$this->assertFalse( $stored['exempt_admins'] );
		$this->assertTrue( $stored['password_signout'] );
		$this->assertSame( 2, $stored['maximum'] );
	}

	public function test_an_upgrade_keeps_a_value_already_chosen(): void {
		update_option( Plugin::VERSION_KEY, '3.2.0' );
		$this->store_old_option( array( 'unlock' => true ) );

		Upgrader::instance()->maybe_upgrade();

		$this->assertTrue( get_option( Plugin::OPTION_KEY )['unlock'] );
	}

	public function test_a_new_install_gets_the_new_defaults(): void {
		delete_option( Plugin::VERSION_KEY );
		delete_option( Plugin::OPTION_KEY );

		Upgrader::instance()->maybe_upgrade();

		$this->assertTrue( Settings::instance()->get( 'unlock' ) );
		$this->assertTrue( Settings::instance()->get( 'exempt_admins' ) );
	}

	public function test_an_upgrade_that_keeps_limiting_admins_suggests_the_exemption(): void {
		update_option( Plugin::VERSION_KEY, '3.2.0' );
		$this->store_old_option( array( 'maximum' => 2 ) );

		Upgrader::instance()->maybe_upgrade();

		$this->assertTrue( Exempt_Admins_Notice::instance()->is_due() );
	}

	public function test_the_suggestion_goes_once_admins_are_exempt(): void {
		update_option( Exempt_Admins_Notice::OPTION, 1 );
		Settings::instance()->update( array( 'exempt_admins' => true ) );

		$this->assertFalse( Exempt_Admins_Notice::instance()->is_due() );
		$this->assertFalse( get_option( Exempt_Admins_Notice::OPTION ) );
	}

	public function test_a_new_install_gets_no_suggestion(): void {
		delete_option( Plugin::VERSION_KEY );
		delete_option( Plugin::OPTION_KEY );

		Upgrader::instance()->maybe_upgrade();

		$this->assertFalse( Exempt_Admins_Notice::instance()->is_due() );
	}

	public function test_the_message_keeps_a_link_and_drops_scripts(): void {
		$out = Settings::instance()->sanitize(
			array( 'message' => ' Too many devices. <a href="https://example.org/help">Help</a><script>x()</script> ' )
		);

		$this->assertSame( 'Too many devices. <a href="https://example.org/help">Help</a>x()', $out['message'] );
	}

	public function test_the_redirect_must_be_a_web_address(): void {
		$this->assertSame( 'https://example.org/account', Settings::instance()->sanitize( array( 'displaced_redirect' => ' https://example.org/account ' ) )['displaced_redirect'] );
		$this->assertSame( '', Settings::instance()->sanitize( array( 'displaced_redirect' => 'javascript:alert(1)' ) )['displaced_redirect'] );
	}

	public function test_the_redirect_must_stay_on_this_site(): void {
		$this->assertSame( '', Settings::instance()->sanitize( array( 'displaced_redirect' => 'https://elsewhere.test/page' ) )['displaced_redirect'] );
	}

	public function test_a_partial_update_keeps_the_other_settings(): void {
		update_option( Plugin::VERSION_KEY, '3.2.0' );
		$this->store_old_option( array( 'maximum' => 2 ) );
		Upgrader::instance()->maybe_upgrade();

		Settings::instance()->update( array( 'maximum' => 3 ) );

		$this->assertFalse( Settings::instance()->get( 'exempt_admins' ) );
		$this->assertSame( 3, Settings::instance()->get( 'maximum' ) );
	}

	/**
	 * Register a `queue` mode the way an add-on would.
	 */
	private function add_queue_mode(): void {
		add_filter(
			'loggedin_logics',
			static function ( array $logics ): array {
				$logics['queue'] = array(
					'label' => 'Queue',
					'desc'  => 'Wait for a free slot.',
				);
				return $logics;
			}
		);
		Settings::instance()->flush();
	}

	public function test_a_mode_an_addon_adds_can_be_saved(): void {
		$this->add_queue_mode();

		Settings::instance()->update( array( 'logic' => 'queue' ) );

		$this->assertSame( 'queue', Settings::instance()->get( 'logic' ) );
		$this->assertArrayHasKey( 'queue', Settings::instance()->logics() );
	}

	public function test_a_mode_an_addon_adds_saves_over_rest(): void {
		$this->add_queue_mode();
		Settings::instance()->register();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$request = new WP_REST_Request( 'POST', '/wp/v2/settings' );
		$request->set_body_params(
			array(
				Plugin::OPTION_KEY => array_merge( Settings::instance()->all(), array( 'logic' => 'queue' ) ),
			)
		);
		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'queue', Settings::instance()->get( 'logic' ) );
	}

	public function test_a_stored_mode_whose_addon_is_gone_reads_as_the_default(): void {
		$this->add_queue_mode();
		Settings::instance()->update( array( 'logic' => 'queue' ) );

		remove_all_filters( 'loggedin_logics' );
		Settings::instance()->flush();

		$this->assertSame( 'allow', Settings::instance()->get( 'logic' ) );
		$this->assertSame( 'allow', get_option( Plugin::OPTION_KEY )['logic'] );
	}

	public function test_the_built_in_modes_stay_allowed_if_a_filter_drops_them(): void {
		add_filter( 'loggedin_logics', '__return_empty_array' );
		Settings::instance()->flush();

		Settings::instance()->update( array( 'logic' => 'block' ) );

		$this->assertSame( 'block', Settings::instance()->get( 'logic' ) );
	}
}
