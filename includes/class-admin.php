<?php
/**
 * Admin side functionality of the plugin.
 *
 * @link       https://thefoxe.com/products/loggedin
 * @license    http://www.gnu.org/licenses/ GNU General Public License
 * @category   Core
 * @package    Loggedin
 * @subpackage Admin
 * @author     Joel James <me@joelsays.com>
 */

namespace DuckDev\LoggedIn;

// If this file is called directly, abort.
defined( 'WPINC' ) || die;

use WP_Session_Tokens;

/**
 * Class Admin
 *
 * @since 1.0.0
 */
class Admin {

	/**
	 * Initialize the class and set its properties.
	 *
	 * We register all our admin hooks here.
	 *
	 * @since  1.0.0
	 * @access public
	 *
	 * @return void
	 */
	public function __construct() {
		// Set options page.
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_init', array( $this, 'old_options_page' ) );

		// Process the login action.
		add_action( 'admin_init', array( $this, 'force_logout' ) );

		// Show review request.
		add_action( 'admin_notices', array( $this, 'review_notice' ) );
		add_action( 'admin_init', array( $this, 'review_action' ) );
	}

	/**
	 * Process the force logout action.
	 *
	 * This will force logout the user from all devices.
	 *
	 * @since  1.1.0
	 * @access public
	 *
	 * @return void
	 */
	public function force_logout() {
		// If force logout submit.
		if ( isset( $_REQUEST['loggedin_logout'] ) && isset( $_REQUEST['loggedin_user'] ) ) {
			// Security check.
			check_admin_referer( 'general-options' );

			// Get user.
			$user = get_userdata( (int) $_REQUEST['loggedin_user'] );

			if ( $user ) {
				// Sessions token instance.
				$manager = WP_Session_Tokens::get_instance( $user->ID );

				// Destroy all sessions.
				$manager->destroy_all();

				// Add success message.
				add_settings_error(
					'general',
					'settings_updated', // Override the settings update message.
					sprintf(
					// translators: %s User name of the logging out user.
						__( 'User %s forcefully logged out from all devices.', 'loggedin' ),
						$user->user_login
					),
					'updated'
				);
			} else {
				// Add success message.
				add_settings_error(
					'general',
					'settings_updated', // Override the settings update message.
					sprintf(
					// translators: %d User ID of the login user.
						__( 'Invalid user ID: %d', 'loggedin' ),
						intval( $_REQUEST['loggedin_user'] )
					)
				);
			}
		}
	}

	/**
	 * Register admin menu for the plugin.
	 *
	 * @since 1.4.0
	 *
	 * @return void
	 */
	public function register_menu() {
		add_options_page(
		// translators: %s lock icon.
			sprintf( __( '%s Loggedin Settings', 'loggedin' ), '🔒' ),
			// translators: %s lock icon.
			sprintf( __( '%s Loggedin', 'loggedin' ), '<span class="dashicons dashicons-lock"></span>' ),
			'manage_options',
			'loggedin',
			array( $this, 'options_page' )
		);
	}

	/**
	 * Register settings for plugin.
	 *
	 * @since 1.4.0
	 *
	 * @return void
	 */
	public function register_settings() {
		// Add new settings section.
		add_settings_section(
			'loggedin_settings',
			// translators: %s lock icon.
			sprintf( __( '%s Loggedin Settings', 'loggedin' ), '<span class="dashicons dashicons-lock"></span>' ),
			'',
			'loggedin'
		);

		// Register limit settings.
		register_setting( 'loggedin', 'loggedin_maximum' );
		// Register logic settings.
		register_setting( 'loggedin', 'loggedin_logic' );

		// Add new setting filed to set the limit.
		add_settings_field(
			'loggedin_maximum',
			'<label for="loggedin_maximum">' . __( 'Maximum Active Logins', 'loggedin' ) . '</label>',
			array( $this, 'loggedin_maximum' ),
			'loggedin',
			'loggedin_settings'
		);

		// Add new setting filed to set the limit.
		add_settings_field(
			'loggedin_logic',
			'<label for="loggedin_logic">' . __( 'Login Logic', 'loggedin' ) . '</label>',
			array( $this, 'loggedin_logic' ),
			'loggedin',
			'loggedin_settings'
		);

		// Add new setting field for force logout.
		add_settings_field(
			'loggedin_logout',
			'<label for="loggedin_logout">' . __( 'Force Logout', 'loggedin' ) . '</label>',
			array( $this, 'loggedin_logout' ),
			'loggedin',
			'loggedin_settings'
		);
	}

	/**
	 * Create new option field label to the default settings page.
	 *
	 * @since  1.0.0
	 * @access public
	 * @uses   register_setting()   To register new setting.
	 * @uses   add_settings_field() To add new field to for the setting.
	 *
	 * @return void
	 */
	public function options_page() {
		?>
		<div class="wrap">
			<form action="options.php" method="post">
				<?php
				settings_fields( 'loggedin' );
				do_settings_sections( 'loggedin' );
				submit_button( __( 'Save Settings', 'loggedin' ) );
				?>
			</form>
		</div>
		<?php
	}

	/**
	 * Create new option field for old settings section.
	 *
	 * @since     1.0.0
	 * @access    public
	 * @uses      register_setting()   To register new setting.
	 * @uses      add_settings_field() To add new field to for the setting.
	 * @depecated 1.4.0
	 *
	 * @return void
	 */
	public function old_options_page() {
		add_settings_section(
			'loggedin_settings',
			// translators: %s lock icon.
			sprintf( __( '%s Loggedin Settings', 'loggedin' ), '<span class="dashicons dashicons-lock"></span>' ),
			array( $this, 'loggedin_old_settings' ),
			'general'
		);

	}

	/**
	 * Old settings page section content.
	 *
	 * @since     1.0.0
	 * @access    public
	 * @uses      get_option() To get the option value.
	 * @depecated 1.4.0
	 *
	 * @return void
	 */
	public function loggedin_old_settings() {
		?>
		<p class="description">
			<?php
			printf(
			// translators: %s loggedin settings page url.
				__( 'Loggedin settings have been relocated. <a href="%s">Click here</a> to access the new settings page.', 'loggedin' ),
				esc_url( admin_url( 'options-general.php?page=loggedin' ) )
			);
			?>
		</p>
		<?php
	}

	/**
	 * Create new options field to show the limit settings.
	 *
	 * @since  1.0.0
	 * @access public
	 * @uses   get_option() To get the option value.
	 *
	 * @return void
	 */
	public function loggedin_maximum() {
		// Get settings value.
		$value = get_option( 'loggedin_maximum', 3 );
		?>
		<p><input type="number" name="loggedin_maximum" id="loggedin_maximum" min="1" value="<?php echo intval( $value ); ?>" placeholder="<?php esc_html_e( 'Enter the limit in number', 'loggedin' ); ?>" /></p>
		<p class="description"><?php esc_html_e( 'Set the maximum no. of active logins a user account can have.', 'loggedin' ); ?></p>
		<p class="description"><?php esc_html_e( 'If this limit reached, next login request will be failed and user will have to logout from one device to continue.', 'loggedin' ); ?></p>
		<p class="description"><strong><?php esc_html_e( 'Note: ', 'loggedin' ); ?></strong><?php esc_html_e( 'Even if the browser is closed, login session may exist.', 'loggedin' ); ?></p>
		<?php
	}

	/**
	 * Create new options field to show the.
	 *
	 * @since  1.2.0
	 * @access public
	 * @uses   get_option() To get the option value.
	 *
	 * @return void
	 */
	public function loggedin_logic() {
		// Get settings value.
		$value = get_option( 'loggedin_logic', 'allow' );
		?>
		<p><input type="radio" name="loggedin_logic" value="allow" <?php checked( $value, 'allow' ); ?>/> <?php esc_html_e( 'Allow', 'loggedin' ); ?></p>
		<p><input type="radio" name="loggedin_logic" value="block" <?php checked( $value, 'block' ); ?>/> <?php esc_html_e( 'Block', 'loggedin' ); ?></p>
		<p class="description"><strong><?php esc_html_e( 'Allow:', 'loggedin' ); ?></strong> <?php esc_html_e( 'Allow new login by terminating all other old sessions when the limit is reached.', 'loggedin' ); ?></p>
		<p class="description"><strong><?php esc_html_e( 'Block:', 'loggedin' ); ?></strong> <?php esc_html_e( ' Do not allow new login if the limit is reached. Users need to wait for the old login sessions to expire.', 'loggedin' ); ?></p>
		<?php
	}

	/**
	 * Create new options field to the settings page.
	 *
	 * @since  1.0.0
	 * @access public
	 * @uses   get_option() To get the option value.
	 *
	 * @return void
	 */
	public function loggedin_logout() {
		?>
		<input type="number" name="loggedin_user" min="1" placeholder="<?php esc_html_e( 'Enter user ID', 'loggedin' ); ?>"/>
		<input type="submit" name="loggedin_logout" id="loggedin_logout" class="button" value="<?php esc_html_e( 'Force Logout', 'loggedin' ); ?>"/>
		<p class="description"><?php esc_html_e( 'If you would like to force logout a user from all the devices, enter the user ID.', 'loggedin' ); ?></p>
		<?php
	}

	/**
	 * Show admin to ask for review in wp.org.
	 *
	 * Show admin notice only inside our plugin's settings page.
	 * Hide the notice permanently if user dismissed it.
	 *
	 * @since 1.1.0
	 *
	 * @return void|bool
	 */
	public function review_notice() {
		global $pagenow;

		// Only on our settings page.
		if ( 'options-general.php' === $pagenow ) {
			// Only for admins.
			if ( ! current_user_can( 'manage_options' ) ) {
				return false;
			}

			// Get the notice time.
			$notice_time = get_option( 'loggedin_rating_notice' );

			// If not set, set now and bail.
			if ( ! $notice_time ) {
				// Set to next week.
				return add_option( 'loggedin_rating_notice', time() + 604800 );
			}

			// Current logged in user.
			$current_user = wp_get_current_user();

			// Did the current user already dismiss?.
			$dismissed = get_user_meta( $current_user->ID, 'loggedin_rating_notice_dismissed', true );

			// Continue only when allowed.
			if ( (int) $notice_time <= time() && ! $dismissed ) {
				?>
				<div class="notice notice-success">
					<p>
						<?php
						printf(
						// translators: %1$s Current user's name. %2$s Plugin name.
							__( 'Hey %1$s, I noticed you\'ve been using %2$s plugin for more than 1 week – that’s awesome! Could you please do me a BIG favor and give it a 5-star rating on WordPress? Just to help us spread the word and boost our motivation.', 'loggedin' ),
							empty( $current_user->display_name ) ? esc_html__( 'there', 'loggedin' ) : esc_html( ucwords( $current_user->display_name ) ),
							'<strong>Loggedin - Limit Active Logins</strong>'
						);
						?>
					</p>
					<p>
						<a href="https://wordpress.org/support/plugin/loggedin/reviews/#new-post" target="_blank">
							<?php esc_html_e( 'Ok, you deserve it', 'loggedin' ); ?>
						</a>
					</p>
					<p>
						<a href="<?php echo esc_url( add_query_arg( 'loggedin_rating', 'later' ) ); // later. ?>">
							<?php esc_html_e( 'Nope, maybe later', 'loggedin' ); ?>
						</a>
					</p>
					<p>
						<a href="<?php echo esc_url( add_query_arg( 'loggedin_rating', 'dismiss' ) ); // dismiss link. ?>">
							<?php esc_html_e( 'I already did', 'loggedin' ); ?>
						</a>
					</p>
				</div>
				<?php
			}
		}
	}

	/**
	 * Handle review notice actions.
	 *
	 * If dismissed set a user meta for the current user and do not show again.
	 * If agreed to review later, update the review timestamp to after 2 weeks.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function review_action() {
		// Get the current review action.
		// phpcs:ignore
		$action = isset( $_REQUEST['loggedin_rating'] ) ? $_REQUEST['loggedin_rating'] : '';

		switch ( $action ) {
			case 'later':
				// Let's show after another 2 weeks.
				update_option( 'loggedin_rating_notice', time() + 1209600 );
				break;
			case 'dismiss':
				// Do not show again to this user.
				update_user_meta( get_current_user_id(), 'loggedin_rating_notice_dismissed', 1 );
				break;
		}
	}
}
