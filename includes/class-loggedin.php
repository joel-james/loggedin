<?php
/**
 * The main functionality of the plugin.
 *
 * @link       https://duckdev.com/products/loggedin-limit-active-logins/
 * @license    http://www.gnu.org/licenses/ GNU General Public License
 * @category   Core
 * @package    Loggedin
 * @subpackage Public
 * @author     Joel James <me@joelsays.com>
 */

// If this file is called directly, abort.
defined( 'WPINC' ) || die( 'Well, get lost.' );

/**
 * Class Loggedin
 *
 * @since 1.0.0
 */
class Loggedin {

	/**
	 * Initialize the class and set its properties.
	 *
	 * We register all our common hooks here.
	 *
	 * @since  1.0.0
	 * @access public
	 *
	 * @return void
	 */
	public function __construct() {
		// Use authentication filter.
		add_filter( 'wp_authenticate_user', array( $this, 'validate_block_logic' ) );
		// Use password check filter.
		add_filter( 'check_password', array( $this, 'validate_allow_logic' ), 10, 4 );
		// Use to set cookie and show success message
		add_action( 'init', array($this, 'set_cookie') );
	}

	/**
	 * Validate if the maximum active logins limit reached.
	 *
	 * This check happens only after authentication happens and
	 * the login logic is "Allow".
	 *
	 * @param boolean $check    User Object/WPError.
	 * @param string  $password Plaintext user's password.
	 * @param string  $hash     Hash of the user's password to check against.
	 * @param int     $user_id  User ID.
	 *
	 * @since  1.0.0
	 * @access public
	 *
	 * @return bool
	 */
	public function validate_allow_logic( $check, $password, $hash, $user_id ) {
		// If the validation failed already, bail.
		if ( ! $check ) {
			return false;
		}

		// Do not allow new logins.
		if ( 'allow' === get_option( 'loggedin_logic', 'allow' ) ) {
			// Check if limit exceed.
			if ( $this->reached_limit( $user_id ) ) {
				// Sessions token instance.
				$manager = WP_Session_Tokens::get_instance( $user_id );
				// Destroy all others.
				$manager->destroy_all();
			}
		}

		if(isset($_COOKIE['loggedin_clean_session'])) {
			// Sessions token instance.
			$manager = WP_Session_Tokens::get_instance( $user_id );
			// Destroy all others.
			$manager->destroy_all();
		}

		return true;
	}

	/**
	 * Validate if the maximum active logins limit reached.
	 *
	 * This check happens only after authentication happens and
	 * the login logic is "Block".
	 *
	 * @param object $user User Object/WPError.
	 *
	 * @since  1.0.0
	 * @access public
	 *
	 * @return object User object or error object.
	 */
	public function validate_block_logic( $user ) {
		// If login validation failed already, return that error.
		if ( is_wp_error( $user ) ) {
			return $user;
		}

		// Only when block method.
		if ( 'semiBlock' === get_option( 'loggedin_logic', 'allow' ) ) {
			if (!isset($_COOKIE['loggedin_clean_session'])) {
				// Check if limit exceed.
				if ( $this->reached_limit( $user->ID ) ) {
					return new WP_Error( 'loggedin_reached_limit', $this->error_message() );
				}
			}
		}elseif ( 'block' === get_option( 'loggedin_logic', 'allow' ) ) {
			// Check if limit exceed.
			if ( $this->reached_limit( $user->ID ) ) {
				return new WP_Error( 'loggedin_reached_limit', $this->error_message() );
			}
		}

		return $user;
	}

	/**
	 * Check if the current user is allowed for another login.
	 *
	 * Count all the active logins for the current user annd
	 * check if that exceeds the maximum login limit set.
	 *
	 * @param int $user_id User ID.
	 *
	 * @since  1.0.0
	 * @access public
	 *
	 * @return boolean Limit reached or not
	 */
	private function reached_limit( $user_id ) {
		// If bypassed.
		if ( $this->bypass( $user_id ) ) {
			return false;
		}

		// Get maximum active logins allowed.
		$maximum = intval( get_option( 'loggedin_maximum', 1 ) );

		// Sessions token instance.
		$manager = WP_Session_Tokens::get_instance( $user_id );

		// Count sessions.
		$count = count( $manager->get_all() );

		// Check if limit reached.
		$reached = $count >= $maximum;

		/**
		 * Filter hook to change the limit condition.
		 *
		 * @param bool $reached Reached.
		 * @param int  $user_id User ID.
		 * @param int  $count   Active logins count.
		 *
		 * @since 1.3.0
		 * @since 1.3.1 Added count param.
		 */
		return apply_filters( 'loggedin_reached_limit', $reached, $user_id, $count );
	}

	/**
	 * Custom login limit bypassing.
	 *
	 * Filter to bypass login limit based on a condition.
	 * You can make use of this filter if you want to bypass
	 * some users or roles from limit limit.
	 *
	 * @param int $user_id User ID.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	private function bypass( $user_id ) {
		/**
		 * Filter hook to bypass the check.
		 *
		 * @param bool $bypass  Bypassed.
		 * @param int  $user_id User ID.
		 *
		 * @since 1.0.0
		 */
		return (bool) apply_filters( 'loggedin_bypass', false, $user_id );
	}

	/**
	 * Error message text if user active logins count is maximum
	 *
	 * @since  1.0.0
	 * @access public
	 *
	 * @return string Error message
	 */
	private function error_message() {
		// Error message.
		$message = __( 'Maximum no. of active logins found for this account. Please logout from another device to continue.', 'loggedin' );

		if('semiBlock' == get_option( 'loggedin_logic', 'allow' )) {
			global $wp;
			$url = home_url( add_query_arg( array(), $wp->request ) );
			$message .= '<p>Or you could logout from other account manually. Just click link below.</p>';
			$message .= '<p><a href="' . $url . '/?loggedin_clean_session=1">Delete all</a></p>';
		}

		/**
		 * Filter hook to change the error message.
		 *
		 * @param string $message Message.
		 *
		 * @since 1.0.0
		 */
		return apply_filters( 'loggedin_error_message', $message );
	}

	/**
	 * Set cookie for deleting it later
	 * 
	 * @since 1.4.0
	 * @access public
	 * 
	 * @return void
	 */
	public function set_cookie() {
		if(isset($_REQUEST['loggedin_clean_session'])) {
			if ( !isset($_COOKIE['loggedin_clean_session']) ) {
				setcookie('loggedin_clean_session', 1, time()+(10*60), '/');

				echo '<script>alert("sucess");</script>';
			}
		}
	}

}
