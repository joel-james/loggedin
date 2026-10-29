<?php
/**
 * Settings store tests for auto logout.
 *
 * Covers the read-side clamping, both sanitisers, the strictest-role
 * resolution rule, and the WordPress integration — `init()` hooks
 * `init` and `register_settings()` actually registers both options with
 * the `loggedin` group.
 *
 * @package FoxeLabs\Loggedin\Tests\Modules\AutoLogout
 */

declare( strict_types = 1 );

namespace FoxeLabs\Loggedin\Tests\Modules\AutoLogout;

use FoxeLabs\Loggedin\Modules\AutoLogout\Settings;
use FoxeLabs\Loggedin\Plugin;
use WP_UnitTestCase;


/**
 * @group settings
 */
class SettingsTest extends WP_UnitTestCase {

	public function tear_down(): void {
		delete_option( Settings::OPTION_KEY );
		delete_option( Settings::ROLE_OPTION_KEY );
		remove_all_actions( 'init' );
		remove_all_filters( 'loggedin_auto_logout_timeout' );

		parent::tear_down();
	}

	public function test_all_returns_defaults_when_unset(): void {
		$this->assertSame( Plugin::instance()->auto_logout()->settings()->defaults(), Plugin::instance()->auto_logout()->settings()->all() );
	}

	public function test_defaults_ship_with_idle_logout_off(): void {
		$defaults = Plugin::instance()->auto_logout()->settings()->defaults();

		$this->assertSame( 0, $defaults['idle_timeout'] );
		$this->assertSame( 0, $defaults['session_lifetime'] );
		$this->assertSame( 0, $defaults['remember_lifetime'] );
	}

	public function test_all_merges_partial_option_over_defaults(): void {
		update_option( Settings::OPTION_KEY, array( 'idle_timeout' => 20 ) );

		$settings = Plugin::instance()->auto_logout()->settings()->all();

		$this->assertSame( 20, $settings['idle_timeout'] );
		$this->assertSame( 60, $settings['warning'] );
	}

	public function test_all_clamps_out_of_range_stored_values(): void {
		update_option(
			Settings::OPTION_KEY,
			array(
				'idle_timeout'      => Settings::MAX_IDLE_TIMEOUT + 1000,
				'warning'           => -5,
				'session_lifetime'  => Settings::MAX_SESSION_LIFETIME + 1,
				'remember_lifetime' => -1,
			)
		);

		$settings = Plugin::instance()->auto_logout()->settings()->all();

		$this->assertSame( Settings::MAX_IDLE_TIMEOUT, $settings['idle_timeout'] );
		$this->assertSame( 0, $settings['warning'] );
		$this->assertSame( Settings::MAX_SESSION_LIFETIME, $settings['session_lifetime'] );
		$this->assertSame( 0, $settings['remember_lifetime'] );
	}

	public function test_all_recovers_from_a_clobbered_option(): void {
		update_option( Settings::OPTION_KEY, 'not an array' );

		$this->assertSame( Plugin::instance()->auto_logout()->settings()->defaults(), Plugin::instance()->auto_logout()->settings()->all() );
	}

	public function test_sanitize_drops_unknown_keys(): void {
		$clean = Plugin::instance()->auto_logout()->settings()->sanitize(
			array(
				'idle_timeout' => 30,
				'evil'         => 'payload',
			)
		);

		$this->assertArrayNotHasKey( 'evil', $clean );
		$this->assertSame( 30, $clean['idle_timeout'] );
	}

	public function test_sanitize_returns_defaults_for_garbage(): void {
		$this->assertSame( Plugin::instance()->auto_logout()->settings()->defaults(), Plugin::instance()->auto_logout()->settings()->sanitize( 'abc' ) );
		$this->assertSame( Plugin::instance()->auto_logout()->settings()->defaults(), Plugin::instance()->auto_logout()->settings()->sanitize( null ) );
	}

	public function test_sanitize_roles_drops_unknown_and_zero_entries(): void {
		$clean = Plugin::instance()->auto_logout()->settings()->sanitize_roles(
			array(
				'editor'          => 5,
				'not_a_real_role' => 10,
				'subscriber'      => 0,
			)
		);

		$this->assertSame( array( 'editor' => 5 ), $clean );
	}

	public function test_sanitize_roles_clamps_to_the_maximum(): void {
		$clean = Plugin::instance()->auto_logout()->settings()->sanitize_roles(
			array( 'editor' => Settings::MAX_IDLE_TIMEOUT + 99 )
		);

		$this->assertSame( Settings::MAX_IDLE_TIMEOUT, $clean['editor'] );
	}

	public function test_sanitize_roles_returns_empty_for_non_array(): void {
		$this->assertSame( array(), Plugin::instance()->auto_logout()->settings()->sanitize_roles( 'nope' ) );
	}

	public function test_timeout_for_returns_zero_when_disabled(): void {
		$user = $this->factory->user->create_and_get( array( 'role' => 'editor' ) );

		$this->assertSame( 0, Plugin::instance()->auto_logout()->settings()->timeout_for( $user ) );
	}

	public function test_timeout_for_uses_the_global_value_in_seconds(): void {
		update_option( Settings::OPTION_KEY, array( 'idle_timeout' => 10 ) );

		$user = $this->factory->user->create_and_get( array( 'role' => 'editor' ) );

		$this->assertSame( 600, Plugin::instance()->auto_logout()->settings()->timeout_for( $user ) );
	}

	public function test_role_override_beats_the_global_value(): void {
		update_option( Settings::OPTION_KEY, array( 'idle_timeout' => 60 ) );
		update_option( Settings::ROLE_OPTION_KEY, array( 'editor' => 5 ) );

		$user = $this->factory->user->create_and_get( array( 'role' => 'editor' ) );

		$this->assertSame( 300, Plugin::instance()->auto_logout()->settings()->timeout_for( $user ) );
	}

	public function test_shortest_role_override_wins_for_multi_role_users(): void {
		update_option( Settings::OPTION_KEY, array( 'idle_timeout' => 60 ) );
		update_option(
			Settings::ROLE_OPTION_KEY,
			array(
				'editor'     => 30,
				'subscriber' => 5,
			)
		);

		$user = $this->factory->user->create_and_get( array( 'role' => 'editor' ) );
		$user->add_role( 'subscriber' );

		// Strictest-wins: an idle timeout is a security control, so the
		// tighter of two applicable rules is the safe resolution.
		$this->assertSame( 300, Plugin::instance()->auto_logout()->settings()->timeout_for( get_user_by( 'id', $user->ID ) ) );
	}

	public function test_role_override_is_ignored_when_the_role_has_no_rule(): void {
		update_option( Settings::OPTION_KEY, array( 'idle_timeout' => 60 ) );
		update_option( Settings::ROLE_OPTION_KEY, array( 'administrator' => 5 ) );

		$user = $this->factory->user->create_and_get( array( 'role' => 'editor' ) );

		$this->assertSame( 3600, Plugin::instance()->auto_logout()->settings()->timeout_for( $user ) );
	}

	public function test_timeout_for_handles_a_null_user(): void {
		update_option( Settings::OPTION_KEY, array( 'idle_timeout' => 10 ) );

		$this->assertSame( 600, Plugin::instance()->auto_logout()->settings()->timeout_for( null ) );
	}

	public function test_filter_can_exempt_a_user(): void {
		update_option( Settings::OPTION_KEY, array( 'idle_timeout' => 10 ) );

		add_filter( 'loggedin_auto_logout_timeout', '__return_zero' );

		$user = $this->factory->user->create_and_get( array( 'role' => 'editor' ) );

		$this->assertSame( 0, Plugin::instance()->auto_logout()->settings()->timeout_for( $user ) );
	}

	public function test_register_hooks_init(): void {
		Plugin::instance()->auto_logout()->settings();

		$this->assertNotFalse(
			has_action( 'init', array( Plugin::instance()->auto_logout()->settings(), 'register_settings' ) )
		);
	}

	public function test_register_settings_registers_both_options(): void {
		Plugin::instance()->auto_logout()->settings()->register_settings();

		$registered = get_registered_settings();

		$this->assertArrayHasKey( Settings::OPTION_KEY, $registered );
		$this->assertArrayHasKey( Settings::ROLE_OPTION_KEY, $registered );
		$this->assertSame( 'object', $registered[ Settings::OPTION_KEY ]['type'] );
		$this->assertSame( 'object', $registered[ Settings::ROLE_OPTION_KEY ]['type'] );
	}
}
