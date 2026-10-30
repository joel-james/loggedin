<?php
/**
 * Loggedin on the Users screen.
 *
 * Adds a "Sessions" count and a sortable "Last login" column, and a
 * "Sign out everywhere" row and bulk action. Counts only: seeing each
 * device of another user belongs to the Active Sessions add-on.
 *
 * The list table loads users with `all_with_meta`, so the session count
 * and the last login read user meta already in the cache: no extra query
 * per row.
 *
 * @since 3.4.0
 * @package FoxeLabs\Loggedin\Modules\UserSessions
 */

declare( strict_types = 1 );

namespace FoxeLabs\Loggedin\Modules\UserSessions;

use FoxeLabs\Loggedin\Sessions\LastLogin;
use FoxeLabs\Loggedin\Sessions\SessionStore;
use WP_Session_Tokens;
use WP_User;
use WP_User_Query;

defined( 'WPINC' ) || die;

/**
 * Columns and actions on the Users list.
 *
 * @since 3.4.0
 */
final class UsersList {

	/**
	 * Row and bulk action name.
	 *
	 * @since 3.4.0
	 */
	private const ACTION = 'loggedin_sign_out';

	/**
	 * Query arg carrying how many users were signed out.
	 *
	 * @since 3.4.0
	 */
	private const DONE_ARG = 'loggedin_signed_out';

	/**
	 * Last login record.
	 *
	 * @since 3.4.0
	 *
	 * @var LastLogin
	 */
	private LastLogin $last_login;

	/**
	 * Session store.
	 *
	 * @since 3.4.0
	 *
	 * @var SessionStore
	 */
	private SessionStore $store;

	/**
	 * Set up the dependencies.
	 *
	 * @since 3.4.0
	 *
	 * @param LastLogin    $last_login Last login record.
	 * @param SessionStore $store      Session store.
	 */
	public function __construct( LastLogin $last_login, SessionStore $store ) {
		$this->last_login = $last_login;
		$this->store      = $store;
	}

	/**
	 * Register hooks.
	 *
	 * @since 3.4.0
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'manage_users_columns', array( $this, 'columns' ) );
		add_filter( 'manage_users_sortable_columns', array( $this, 'sortable_columns' ) );
		add_filter( 'manage_users_custom_column', array( $this, 'column' ), 10, 3 );
		add_action( 'pre_get_users', array( $this, 'sort' ) );

		add_filter( 'user_row_actions', array( $this, 'row_actions' ), 10, 2 );
		add_filter( 'bulk_actions-users', array( $this, 'bulk_actions' ) );
		add_filter( 'handle_bulk_actions-users', array( $this, 'handle_bulk_action' ), 10, 3 );
		add_action( 'load-users.php', array( $this, 'handle_row_action' ) );
		add_action( 'admin_notices', array( $this, 'notice' ) );
	}

	/**
	 * Add the two columns.
	 *
	 * @since 3.4.0
	 *
	 * @param array<string, string> $columns Columns.
	 *
	 * @return array<string, string>
	 */
	public function columns( $columns ): array {
		$columns['loggedin_sessions']   = __( 'Sessions', 'loggedin' );
		$columns['loggedin_last_login'] = __( 'Last login', 'loggedin' );

		return (array) $columns;
	}

	/**
	 * Make "Last login" sortable.
	 *
	 * @since 3.4.0
	 *
	 * @param array<string, mixed> $columns Sortable columns.
	 *
	 * @return array<string, mixed>
	 */
	public function sortable_columns( $columns ): array {
		// Newest first on the first click, like core's date columns.
		$columns['loggedin_last_login'] = array( 'loggedin_last_login', true );

		return (array) $columns;
	}

	/**
	 * Print a cell.
	 *
	 * @since 3.4.0
	 *
	 * @param string $output  Cell so far.
	 * @param string $column  Column name.
	 * @param int    $user_id User id.
	 *
	 * @return string
	 */
	public function column( $output, $column, $user_id ): string {
		if ( 'loggedin_sessions' === $column ) {
			return (string) count( WP_Session_Tokens::get_instance( (int) $user_id )->get_all() );
		}

		if ( 'loggedin_last_login' !== $column ) {
			return (string) $output;
		}

		$time = $this->last_login->get( (int) $user_id );

		if ( ! $time ) {
			return '<span aria-hidden="true">—</span><span class="screen-reader-text">' . esc_html__( 'No login recorded', 'loggedin' ) . '</span>';
		}

		return sprintf(
			'<time datetime="%1$s" title="%2$s">%3$s</time>',
			esc_attr( wp_date( 'c', $time ) ),
			esc_attr( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $time ) ),
			esc_html(
				sprintf(
					/* translators: %s: Time since, e.g. "3 hours". */
					__( '%s ago', 'loggedin' ),
					human_time_diff( $time )
				)
			)
		);
	}

	/**
	 * Sort by last login when asked, keeping users who never logged in.
	 *
	 * Two named clauses (the meta exists, or it doesn't) so ordering by
	 * the meta value doesn't drop users without one.
	 *
	 * @since 3.4.0
	 *
	 * @param WP_User_Query $query Query.
	 *
	 * @return void
	 */
	public function sort( $query ): void {
		if ( ! is_admin() || 'loggedin_last_login' !== $query->get( 'orderby' ) ) {
			return;
		}

		$query->set(
			'meta_query',
			array(
				'relation'                 => 'OR',
				'loggedin_last_login'      => array(
					'key'  => LastLogin::META_KEY,
					'type' => 'NUMERIC',
				),
				'loggedin_last_login_none' => array(
					'key'     => LastLogin::META_KEY,
					'compare' => 'NOT EXISTS',
				),
			)
		);
		$query->set( 'orderby', 'loggedin_last_login' );
	}

	/**
	 * Add "Sign out everywhere" to a row.
	 *
	 * @since 3.4.0
	 *
	 * @param array<string, string> $actions Row actions.
	 * @param WP_User               $user    User in the row.
	 *
	 * @return array<string, string>
	 */
	public function row_actions( $actions, $user ): array {
		if ( ! $user instanceof WP_User || ! current_user_can( 'edit_user', $user->ID ) ) {
			return (array) $actions;
		}

		$url = wp_nonce_url(
			add_query_arg(
				array(
					'action' => self::ACTION,
					'user'   => $user->ID,
				),
				admin_url( 'users.php' )
			),
			self::ACTION . '_' . $user->ID
		);

		$actions['loggedin_sign_out'] = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Sign out everywhere', 'loggedin' ) . '</a>';

		return (array) $actions;
	}

	/**
	 * Add "Sign out everywhere" to the bulk actions.
	 *
	 * @since 3.4.0
	 *
	 * @param array<string, string> $actions Bulk actions.
	 *
	 * @return array<string, string>
	 */
	public function bulk_actions( $actions ): array {
		$actions[ self::ACTION ] = __( 'Sign out everywhere', 'loggedin' );

		return (array) $actions;
	}

	/**
	 * Run the bulk action.
	 *
	 * WordPress has checked the bulk-users nonce before this filter runs.
	 *
	 * @since 3.4.0
	 *
	 * @param string     $sendback Where to go afterwards.
	 * @param string     $action   Bulk action.
	 * @param array<int> $user_ids Selected users.
	 *
	 * @return string
	 */
	public function handle_bulk_action( $sendback, $action, $user_ids ): string {
		if ( self::ACTION !== $action ) {
			return (string) $sendback;
		}

		$count = 0;

		foreach ( (array) $user_ids as $user_id ) {
			if ( $this->sign_out( (int) $user_id ) ) {
				++$count;
			}
		}

		return add_query_arg( self::DONE_ARG, $count, remove_query_arg( self::DONE_ARG, (string) $sendback ) );
	}

	/**
	 * Run the row action.
	 *
	 * @since 3.4.0
	 *
	 * @return void
	 */
	public function handle_row_action(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only routes the request; the nonce is checked below.
		if ( ! isset( $_GET['action'], $_GET['user'] ) || self::ACTION !== $_GET['action'] ) {
			return;
		}

		$user_id = absint( $_GET['user'] );

		check_admin_referer( self::ACTION . '_' . $user_id );

		$count = $this->sign_out( $user_id ) ? 1 : 0;

		wp_safe_redirect( add_query_arg( self::DONE_ARG, $count, admin_url( 'users.php' ) ) );
		exit;
	}

	/**
	 * Say how many users were signed out.
	 *
	 * @since 3.4.0
	 *
	 * @return void
	 */
	public function notice(): void {
		$screen = get_current_screen();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only count for the notice.
		if ( ! $screen || 'users' !== $screen->id || ! isset( $_GET[ self::DONE_ARG ] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only count for the notice.
		$count = absint( $_GET[ self::DONE_ARG ] );

		printf(
			'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
			esc_html(
				sprintf(
					/* translators: %s: Number of users. */
					_n(
						'%s user was signed out of every device.',
						'%s users were signed out of every device.',
						$count,
						'loggedin'
					),
					number_format_i18n( $count )
				)
			)
		);
	}

	/**
	 * End a user's sessions, if the current user may.
	 *
	 * Signing yourself out keeps the session you are using, so the
	 * screen doesn't log you out.
	 *
	 * @since 3.4.0
	 *
	 * @param int $user_id User id.
	 *
	 * @return bool Whether the user was signed out.
	 */
	private function sign_out( int $user_id ): bool {
		if ( $user_id <= 0 || ! current_user_can( 'edit_user', $user_id ) || ! get_userdata( $user_id ) ) {
			return false;
		}

		$this->store->end_all( $user_id, true );

		/**
		 * Fires after an admin signs a user out of every device from
		 * the Users screen.
		 *
		 * @since 3.4.0
		 *
		 * @param int $user_id User who was signed out.
		 */
		do_action( 'loggedin_users_list_sign_out', $user_id );

		return true;
	}
}
