<?php
/**
 * Suggest "Don't limit administrators" on upgraded sites.
 *
 * New installs leave administrators out of the limit. A site upgrading
 * from before 3.4.0 keeps limiting them, so nothing changes under it
 * (see {@see \FoxeLabs\Loggedin\Settings\Upgrader::keep_pre_340_behaviour()}).
 * This notice tells such a site's admins the setting exists, once: it
 * goes away for good when someone turns the setting on or says no.
 *
 * @package FoxeLabs\Loggedin\Admin
 */

declare( strict_types = 1 );

namespace FoxeLabs\Loggedin\Admin;

use FoxeLabs\Loggedin\Plugin;
use FoxeLabs\Loggedin\Settings\Settings;

defined( 'WPINC' ) || die;

/**
 * One-time notice suggesting the administrator exemption.
 *
 * @since 3.4.0
 */
final class ExemptAdminsNotice {

	/**
	 * Option set by the Upgrader while the notice is due.
	 *
	 * @since 3.4.0
	 */
	public const OPTION = 'loggedin_suggest_exempt_admins';

	/**
	 * Query arg carrying the admin's answer: `on` or `no`.
	 *
	 * @since 3.4.0
	 */
	private const ARG = 'loggedin_exempt_admins';

	/**
	 * Nonce action for both answers.
	 *
	 * @since 3.4.0
	 */
	private const NONCE = 'loggedin-exempt-admins';

	/**
	 * Settings store.
	 *
	 * @since 3.4.0
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Set up the dependencies.
	 *
	 * @since 3.4.0
	 *
	 * @param Settings $settings Settings store.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Register hooks.
	 *
	 * @since 3.4.0
	 *
	 * @return void
	 */
	public function register(): void {
		if ( ! get_option( self::OPTION ) ) {
			return;
		}

		add_action( 'admin_init', array( $this, 'answer' ) );
		add_action( 'admin_notices', array( $this, 'notice' ) );
	}

	/**
	 * Whether the notice is still due.
	 *
	 * Turning the setting on from the Lockout & recovery page answers it too.
	 *
	 * @since 3.4.0
	 *
	 * @return bool
	 */
	public function is_due(): bool {
		if ( ! get_option( self::OPTION ) ) {
			return false;
		}

		if ( $this->settings->get( 'exempt_admins', false ) ) {
			delete_option( self::OPTION );

			return false;
		}

		return true;
	}

	/**
	 * Act on the admin's answer and return to the page they were on.
	 *
	 * @since 3.4.0
	 *
	 * @return void
	 */
	public function answer(): void {
		if ( ! isset( $_GET[ self::ARG ] ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		check_admin_referer( self::NONCE );

		if ( 'on' === sanitize_key( wp_unslash( $_GET[ self::ARG ] ) ) ) {
			$this->settings->update(
				array_merge( $this->settings->all(), array( 'exempt_admins' => true ) )
			);
		}

		delete_option( self::OPTION );

		wp_safe_redirect( remove_query_arg( array( self::ARG, '_wpnonce' ) ) );
		exit;
	}

	/**
	 * Show the suggestion on Loggedin's screen.
	 *
	 * @since 3.4.0
	 *
	 * @return void
	 */
	public function notice(): void {
		$screen = get_current_screen();

		if (
			! $screen
			|| 'users_page_' . Plugin::SLUG !== $screen->id
			|| ! current_user_can( 'manage_options' )
			|| ! $this->is_due()
		) {
			return;
		}

		printf(
			'<div class="notice notice-info"><p><strong>%1$s</strong> %2$s</p><p><a class="button button-primary" href="%3$s">%4$s</a> <a class="button" href="%5$s">%6$s</a></p></div>',
			esc_html__( 'Stop limiting administrators?', 'loggedin' ),
			esc_html__( 'Loggedin can leave users who manage the site out of the login limit, so an administrator is never locked out of their own site. It is on for new sites and stayed off here when you updated.', 'loggedin' ),
			esc_url( $this->answer_url( 'on' ) ),
			esc_html__( 'Don’t limit administrators', 'loggedin' ),
			esc_url( $this->answer_url( 'no' ) ),
			esc_html__( 'Keep limiting them', 'loggedin' )
		);
	}

	/**
	 * Link for one answer, back to the current screen.
	 *
	 * @since 3.4.0
	 *
	 * @param string $answer `on` or `no`.
	 *
	 * @return string URL.
	 */
	private function answer_url( string $answer ): string {
		return wp_nonce_url( add_query_arg( self::ARG, $answer ), self::NONCE );
	}
}
