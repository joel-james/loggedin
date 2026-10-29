<?php
/**
 * Tests for {@see \FoxeLabs\Loggedin\Modules\Lockout\Unlock}.
 *
 * Covers the two keys (issue, single use, expiry, a wrong guess), the
 * email step with its resend limit, signing out everywhere from the
 * emailed link, and the login screen messages.
 *
 * @package FoxeLabs\Loggedin\Tests\Modules\Lockout
 */

declare( strict_types = 1 );

namespace FoxeLabs\Loggedin\Tests\Modules\Lockout;

use FoxeLabs\Loggedin\Modules\Lockout\Unlock;
use FoxeLabs\Loggedin\Plugin;
use FoxeLabs\Loggedin\Tests\Support\RedirectException;
use WP_Session_Tokens;
use WP_UnitTestCase;


/**
 * @group unlock
 */
class UnlockTest extends WP_UnitTestCase {

	private int $user_id;

	public function set_up(): void {
		parent::set_up();

		$this->user_id = self::factory()->user->create( array( 'user_email' => 'owner@example.com' ) );

		// Seed sessions past the limit: new sessions are limited as they're
		// created, and these tests drive the limit by hand.
		remove_filter( 'attach_session_information', array( Plugin::instance()->session_guard(), 'enforce_on_create' ) );

		$manager = WP_Session_Tokens::get_instance( $this->user_id );
		$manager->create( time() + HOUR_IN_SECONDS );
		$manager->create( time() + HOUR_IN_SECONDS );

		add_filter( 'wp_redirect', array( $this, 'stop_redirect' ) );
		reset_phpmailer_instance();
	}

	public function tear_down(): void {
		remove_filter( 'wp_redirect', array( $this, 'stop_redirect' ) );
		delete_option( Plugin::OPTION_KEY );
		unset( $_GET['user'], $_GET['loggedin_key'], $_GET['loggedin_unlock'] );

		parent::tear_down();
	}

	/**
	 * Throw the redirect target instead of following it.
	 *
	 * @param string $location Redirect target.
	 *
	 * @throws RedirectException Always.
	 */
	public function stop_redirect( $location ) {
		throw new RedirectException( $location );
	}

	/**
	 * The key in the link that `offer()` builds.
	 *
	 * @return string Key.
	 */
	private function offered_key(): string {
		$html = Plugin::instance()->lockout()->unlock()->offer( get_user_by( 'id', $this->user_id ) );

		preg_match( '/loggedin_key=([A-Za-z0-9]+)/', $html, $match );

		return $match[1] ?? '';
	}

	/**
	 * Run a handler and return the state it redirected with.
	 *
	 * @param string $method Handler method.
	 * @param string $key    Key for the link.
	 *
	 * @return string State.
	 */
	private function follow( string $method, string $key ): string {
		$_GET['user'] = (string) $this->user_id;
		$_GET['loggedin_key'] = $key;

		try {
			Plugin::instance()->lockout()->unlock()->$method();
		} catch ( RedirectException $redirect ) {
			parse_str( (string) wp_parse_url( $redirect->getMessage(), PHP_URL_QUERY ), $args );

			return (string) ( $args['loggedin_unlock'] ?? '' );
		}

		return '';
	}

	public function test_offer_is_empty_when_unlock_is_off(): void {
		update_option( Plugin::OPTION_KEY, array( 'unlock' => false ) );

		$this->assertSame( '', Plugin::instance()->lockout()->unlock()->offer( get_user_by( 'id', $this->user_id ) ) );
	}

	public function test_a_key_works_once(): void {
		$key = $this->offered_key();

		$this->assertTrue( Plugin::instance()->lockout()->unlock()->redeem( $this->user_id, Unlock::REQUEST_META, $key ) );
		$this->assertFalse( Plugin::instance()->lockout()->unlock()->redeem( $this->user_id, Unlock::REQUEST_META, $key ) );
	}

	public function test_a_wrong_key_leaves_the_real_one_working(): void {
		$key = $this->offered_key();

		$this->assertFalse( Plugin::instance()->lockout()->unlock()->redeem( $this->user_id, Unlock::REQUEST_META, 'guess' ) );
		$this->assertTrue( Plugin::instance()->lockout()->unlock()->redeem( $this->user_id, Unlock::REQUEST_META, $key ) );
	}

	public function test_an_expired_key_fails(): void {
		$key    = $this->offered_key();
		$stored = get_user_meta( $this->user_id, Unlock::REQUEST_META, true );

		$stored['expires'] = time() - 1;
		update_user_meta( $this->user_id, Unlock::REQUEST_META, $stored );

		$this->assertFalse( Plugin::instance()->lockout()->unlock()->redeem( $this->user_id, Unlock::REQUEST_META, $key ) );
	}

	public function test_a_key_belongs_to_its_user(): void {
		$key   = $this->offered_key();
		$other = self::factory()->user->create();

		$this->assertFalse( Plugin::instance()->lockout()->unlock()->redeem( $other, Unlock::REQUEST_META, $key ) );
	}

	public function test_the_request_emails_a_link_once_per_five_minutes(): void {
		$this->assertSame( 'sent', $this->follow( 'handle_request', $this->offered_key() ) );

		$mailer = tests_retrieve_phpmailer_instance();
		$this->assertSame( 'owner@example.com', $mailer->get_recipient( 'to' )->address );
		$this->assertStringContainsString( 'action=loggedin_unlock&', $mailer->get_sent()->body );

		$this->assertSame( 'wait', $this->follow( 'handle_request', $this->offered_key() ) );
	}

	public function test_a_bad_request_link_sends_nothing(): void {
		$this->assertSame( 'invalid', $this->follow( 'handle_request', 'nope' ) );
		$this->assertFalse( tests_retrieve_phpmailer_instance()->get_sent() );
	}

	public function test_the_emailed_link_signs_out_every_device(): void {
		$this->follow( 'handle_request', $this->offered_key() );

		preg_match( '/loggedin_key=([A-Za-z0-9]+)/', tests_retrieve_phpmailer_instance()->get_sent()->body, $match );

		$this->assertSame( 'done', $this->follow( 'handle_unlock', $match[1] ) );
		$this->assertCount( 0, WP_Session_Tokens::get_instance( $this->user_id )->get_all() );
	}

	public function test_the_request_key_does_not_unlock(): void {
		$this->assertSame( 'invalid', $this->follow( 'handle_unlock', $this->offered_key() ) );
		$this->assertCount( 2, WP_Session_Tokens::get_instance( $this->user_id )->get_all() );
	}

	public function test_login_message_matches_the_state(): void {
		$this->assertSame( 'base', Plugin::instance()->lockout()->unlock()->login_message( 'base' ) );

		$_GET['loggedin_unlock'] = 'done';
		$this->assertStringContainsString( 'signed out of your other devices', Plugin::instance()->lockout()->unlock()->login_message( 'base' ) );

		$_GET['loggedin_unlock'] = 'made-up';
		$this->assertSame( 'base', Plugin::instance()->lockout()->unlock()->login_message( 'base' ) );
	}
}
