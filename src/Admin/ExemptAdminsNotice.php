<?php
/**
 * Suggest "Don't limit administrators" on upgraded sites.
 *
 * New installs leave administrators out of the limit. A site upgrading
 * from before 3.4.0 keeps limiting them, so nothing changes under it
 * (see {@see \FoxeLabs\Loggedin\Settings\Upgrader::keep_pre_340_behaviour()}).
 * This notice tells such a site's admins the setting exists, once: it
 * goes away for good when someone turns the setting on or says no. It
 * shows inside Loggedin's screen, drawn by the app from the script vars.
 *
 * @since 3.4.0
 * @package FoxeLabs\Loggedin\Admin
 */

declare( strict_types = 1 );

namespace FoxeLabs\Loggedin\Admin;

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
	 * `1` while due, `0` once answered. Kept (rather than deleted) and
	 * autoloaded, because {@see self::register()} reads it on every
	 * admin request.
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
		add_filter( 'loggedin_admin_script_vars', array( $this, 'script_vars' ) );
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
			update_option( self::OPTION, 0, true );

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
			$this->settings->update( array( 'exempt_admins' => true ) );
		}

		update_option( self::OPTION, 0, true );

		wp_safe_redirect( remove_query_arg( array( self::ARG, '_wpnonce' ) ) );
		exit;
	}

	/**
	 * Add the suggestion to the notices Loggedin's screen shows.
	 *
	 * @since 3.4.0
	 * @since 3.4.1 Survives an earlier callback that returns a non-array.
	 *
	 * @param array<string, mixed> $vars Script vars for the admin app.
	 *
	 * @return array<string, mixed>
	 */
	public function script_vars( $vars ): array {
		$vars            = (array) $vars;
		$vars['notices'] = (array) ( $vars['notices'] ?? array() );

		if ( ! current_user_can( 'manage_options' ) || ! $this->is_due() ) {
			return $vars;
		}

		$vars['notices'][] = array(
			'id'      => 'exempt-admins',
			'tone'    => 'info',
			'title'   => __( 'Stop limiting administrators?', 'loggedin' ),
			'text'    => __( 'Loggedin can leave users who manage the site out of the login limit, so an administrator is never locked out of their own site. It is on for new sites and stayed off here when you updated.', 'loggedin' ),
			'actions' => array(
				array(
					'label'   => __( 'Don’t limit administrators', 'loggedin' ),
					'href'    => esc_url_raw( $this->answer_url( 'on' ) ),
					'primary' => true,
				),
				array(
					'label' => __( 'Keep limiting them', 'loggedin' ),
					'href'  => esc_url_raw( $this->answer_url( 'no' ) ),
				),
			),
		);

		return $vars;
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
