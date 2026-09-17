<?php
/**
 * `wp loggedin sessions` commands.
 *
 * The CLI counterpart to the "Force Logout" panel in wp-admin, plus a
 * read-only view of a single user's active sessions.
 *
 * Deliberately scoped to *one user at a time* — every subcommand takes
 * a `<user>` identifier — with one exception: `destroy-all`, which logs
 * out the whole site via the logout epoch (an O(1) option write, no
 * per-user iteration). A site-wide "who is logged in right now" report
 * remains the job of the Active Sessions add-on, which owns the
 * cross-user queries and the pagination that comes with them.
 *
 * @package FoxeLabs\Loggedin\Cli
 */

declare( strict_types = 1 );

namespace FoxeLabs\Loggedin\Cli;

use FoxeLabs\Loggedin\Front\Logout_Epoch;
use WP_CLI;
use WP_CLI\Fetchers\User as User_Fetcher;
use WP_CLI\Formatter;
use WP_CLI\Utils;
use WP_Session_Tokens;
use WP_User;
use WP_User_Meta_Session_Tokens;

defined( 'WPINC' ) || die;

/**
 * Inspect and destroy the active sessions of a user.
 *
 * @since 3.1.0
 */
final class Sessions_Command {

	/**
	 * Usermeta key WordPress stores session tokens under.
	 *
	 * @since 3.1.0
	 */
	private const META_KEY = 'session_tokens';

	/**
	 * Columns shown by `sessions list` when `--fields` is not given.
	 *
	 * @since 3.1.0
	 */
	private const DEFAULT_FIELDS = array( 'token', 'login', 'expiration', 'ip', 'ua' );

	/**
	 * List the active sessions of a user.
	 *
	 * Expired-but-not-yet-pruned tokens are filtered out — WordPress
	 * only cleans those when the owning user next authenticates, so the
	 * raw meta row is not a reliable "currently logged in" list.
	 *
	 * ## OPTIONS
	 *
	 * <user>
	 * : User id, login, or email address.
	 *
	 * [--field=<field>]
	 * : Print the value of a single field for each session.
	 *
	 * [--fields=<fields>]
	 * : Comma-separated list of fields to show.
	 * ---
	 * default: token,login,expiration,ip,ua
	 * ---
	 *
	 * [--format=<format>]
	 * : Render output in a particular format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 *   - yaml
	 *   - count
	 *   - ids
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     # List every active session for a user.
	 *     $ wp loggedin sessions list editor@example.com
	 *
	 *     # Grab just the token hashes, for scripting.
	 *     $ wp loggedin sessions list 42 --field=token
	 *
	 * @subcommand list
	 *
	 * @since 3.1.0
	 *
	 * @param array<int, string>    $args       Positional arguments.
	 * @param array<string, string> $assoc_args Associative arguments.
	 *
	 * @return void
	 */
	public function list_( array $args, array $assoc_args ): void {
		$user = $this->fetch_user( $args[0] );

		$this->warn_on_custom_storage( (int) $user->ID );

		$sessions = $this->sessions_for( (int) $user->ID );

		if ( empty( $sessions ) ) {
			WP_CLI::success(
				sprintf(
					/* translators: %s: user login. */
					__( 'No active sessions for %s.', 'loggedin' ),
					$user->user_login
				)
			);

			return;
		}

		// `ids` is documented as "the identifying column" across WP-CLI
		// commands; here that's the token hash, not a numeric id.
		if ( 'ids' === ( $assoc_args['format'] ?? '' ) ) {
			WP_CLI::line( implode( ' ', wp_list_pluck( $sessions, 'token' ) ) );

			return;
		}

		$formatter = new Formatter( $assoc_args, self::DEFAULT_FIELDS );
		$formatter->display_items( $sessions );
	}

	/**
	 * Count the active sessions of a user.
	 *
	 * Reads through `WP_Session_Tokens` rather than the meta row, so the
	 * count stays correct on installs that swap the session storage for
	 * Redis or Memcached via the `session_token_manager` filter.
	 *
	 * ## OPTIONS
	 *
	 * <user>
	 * : User id, login, or email address.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp loggedin sessions count 42
	 *     3
	 *
	 * @since 3.1.0
	 *
	 * @param array<int, string> $args Positional arguments.
	 *
	 * @return void
	 */
	public function count( array $args ): void {
		$user = $this->fetch_user( $args[0] );

		WP_CLI::line(
			(string) count( WP_Session_Tokens::get_instance( (int) $user->ID )->get_all() )
		);
	}

	/**
	 * Destroy the sessions of a user, logging them out.
	 *
	 * Without `--token`, every session for the user is destroyed — the
	 * same operation as the Force Logout panel in wp-admin, and it fires
	 * the same `loggedin_destroy_all_sessions` action so add-ons see
	 * both paths.
	 *
	 * ## OPTIONS
	 *
	 * <user>
	 * : User id, login, or email address.
	 *
	 * [--token=<hash>]
	 * : Destroy only this single session. Use the `token` column from
	 * `wp loggedin sessions list` to get the value.
	 *
	 * [--yes]
	 * : Skip the confirmation prompt.
	 *
	 * ## EXAMPLES
	 *
	 *     # Log a user out everywhere.
	 *     $ wp loggedin sessions destroy editor@example.com --yes
	 *     Success: Destroyed 3 sessions for editor.
	 *
	 *     # Log a user out of one device only.
	 *     $ wp loggedin sessions destroy 42 --token=e3b0c442... --yes
	 *     Success: Destroyed 1 session for editor.
	 *
	 * @since 3.1.0
	 *
	 * @param array<int, string>    $args       Positional arguments.
	 * @param array<string, string> $assoc_args Associative arguments.
	 *
	 * @return void
	 */
	public function destroy( array $args, array $assoc_args ): void {
		$user = $this->fetch_user( $args[0] );

		// Presence of the flag decides the mode, not its value. Reading
		// the value alone would turn `--token=$EMPTY_VAR` — a stock
		// scripting mistake — into "destroy every session for this
		// user", which is the opposite of what the caller asked for.
		if ( array_key_exists( 'token', $assoc_args ) ) {
			$token = trim( (string) Utils\get_flag_value( $assoc_args, 'token', '' ) );

			if ( '' === $token ) {
				WP_CLI::error( __( '--token was passed without a value. Drop the flag entirely to destroy all sessions for the user.', 'loggedin' ) );
			}

			$this->destroy_single( $user, $token, $assoc_args );

			return;
		}

		$count = count( WP_Session_Tokens::get_instance( (int) $user->ID )->get_all() );

		if ( 0 === $count ) {
			WP_CLI::success(
				sprintf(
					/* translators: %s: user login. */
					__( 'No active sessions for %s.', 'loggedin' ),
					$user->user_login
				)
			);

			return;
		}

		WP_CLI::confirm(
			sprintf(
				/* translators: 1: session count, 2: user login. */
				__( 'Are you sure you want to destroy %1$d session(s) for %2$s?', 'loggedin' ),
				$count,
				$user->user_login
			),
			$assoc_args
		);

		WP_Session_Tokens::get_instance( (int) $user->ID )->destroy_all();

		/** This action is documented in includes/front/class-session-guard.php */
		do_action( 'loggedin_destroy_all_sessions', (int) $user->ID );

		WP_CLI::success(
			sprintf(
				/* translators: 1: session count, 2: user login. */
				__( 'Destroyed %1$d session(s) for %2$s.', 'loggedin' ),
				$count,
				$user->user_login
			)
		);
	}

	/**
	 * Log out every user on the site.
	 *
	 * Stores a logout epoch instead of iterating users — a single
	 * option write, so it completes instantly at any user count. Each
	 * stale session is destroyed on its owner's next request. Because
	 * no per-user iteration happens, `loggedin_destroy_all_sessions`
	 * does not fire; add-ons listen for `loggedin_logout_all_users`
	 * instead.
	 *
	 * There is no current session on the CLI, so — unlike the wp-admin
	 * button — nobody is exempted: literally every session is logged
	 * out, including any belonging to the person running the command.
	 *
	 * ## OPTIONS
	 *
	 * [--yes]
	 * : Skip the confirmation prompt.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp loggedin sessions destroy-all --yes
	 *     Success: All users will be logged out on their next request.
	 *
	 * @subcommand destroy-all
	 *
	 * @since 3.3.0
	 *
	 * @param array<int, string>    $args       Positional arguments (unused).
	 * @param array<string, string> $assoc_args Associative arguments.
	 *
	 * @return void
	 */
	public function destroy_all( array $args, array $assoc_args ): void {
		unset( $args );

		WP_CLI::confirm(
			__( 'Are you sure you want to log out every user on this site?', 'loggedin' ),
			$assoc_args
		);

		Logout_Epoch::logout_all( false );

		WP_CLI::success( __( 'All users will be logged out on their next request.', 'loggedin' ) );
	}

	/**
	 * Destroy one session identified by its token hash.
	 *
	 * `WP_Session_Tokens` can only destroy a session given the *raw*
	 * verifier, which is never stored — the meta row is keyed by its
	 * hash. So single-session destroys write the meta row directly,
	 * which limits them to the default storage backend.
	 *
	 * @since 3.1.0
	 *
	 * @param WP_User               $user       Target user.
	 * @param string                $token      Token hash from `sessions list`.
	 * @param array<string, string> $assoc_args Associative arguments.
	 *
	 * @return void
	 */
	private function destroy_single( WP_User $user, string $token, array $assoc_args ): void {
		if ( ! $this->is_default_storage( (int) $user->ID ) ) {
			WP_CLI::error(
				__( 'Destroying a single session requires the default session storage. Drop --token to destroy all sessions for the user instead.', 'loggedin' )
			);
		}

		$sessions = get_user_meta( (int) $user->ID, self::META_KEY, true );

		if ( ! is_array( $sessions ) || ! isset( $sessions[ $token ] ) ) {
			WP_CLI::error(
				sprintf(
					/* translators: 1: token hash, 2: user login. */
					__( 'No session matching token "%1$s" for %2$s.', 'loggedin' ),
					$token,
					$user->user_login
				)
			);
		}

		WP_CLI::confirm(
			sprintf(
				/* translators: %s: user login. */
				__( 'Are you sure you want to destroy this session for %s?', 'loggedin' ),
				$user->user_login
			),
			$assoc_args
		);

		unset( $sessions[ $token ] );
		update_user_meta( (int) $user->ID, self::META_KEY, $sessions );

		/**
		 * Fires after a single session is destroyed from the CLI.
		 *
		 * @since 3.1.0
		 *
		 * @param int    $user_id User id whose session was destroyed.
		 * @param string $token   Hashed token of the destroyed session.
		 */
		do_action( 'loggedin_destroy_session', (int) $user->ID, $token );

		WP_CLI::success(
			sprintf(
				/* translators: %s: user login. */
				__( 'Destroyed 1 session for %s.', 'loggedin' ),
				$user->user_login
			)
		);
	}

	/**
	 * Return the active sessions of a user as printable rows.
	 *
	 * Timestamps are rendered in the site timezone rather than left raw
	 * — a CLI table of unix epochs helps nobody, and `--format=json`
	 * consumers get the same formatted strings so the two outputs don't
	 * disagree.
	 *
	 * @since 3.1.0
	 *
	 * @param int $user_id User id.
	 *
	 * @return array<int, array<string, string|int>>
	 */
	private function sessions_for( int $user_id ): array {
		$raw = get_user_meta( $user_id, self::META_KEY, true );

		if ( ! is_array( $raw ) ) {
			return array();
		}

		$now  = time();
		$rows = array();

		foreach ( $raw as $verifier => $session ) {
			if ( ! is_array( $session ) || ! isset( $session['expiration'] ) ) {
				continue;
			}

			$expiration = (int) $session['expiration'];

			if ( $expiration <= $now ) {
				continue;
			}

			$login = isset( $session['login'] ) ? (int) $session['login'] : 0;

			$rows[] = array(
				'token'      => (string) $verifier,
				'login'      => $login ? wp_date( 'Y-m-d H:i:s', $login ) : '',
				'expiration' => wp_date( 'Y-m-d H:i:s', $expiration ),
				'ip'         => isset( $session['ip'] ) ? (string) $session['ip'] : '',
				'ua'         => isset( $session['ua'] ) ? (string) $session['ua'] : '',
				'_login'     => $login,
			);
		}

		// Newest first — matches the order the admin UI uses.
		usort(
			$rows,
			static function ( array $a, array $b ): int {
				return $b['_login'] <=> $a['_login'];
			}
		);

		return array_map(
			static function ( array $row ): array {
				unset( $row['_login'] );

				return $row;
			},
			$rows
		);
	}

	/**
	 * Resolve a user identifier, halting the command when it misses.
	 *
	 * Uses WP-CLI's own fetcher so `<user>` accepts an id, a login, or
	 * an email address — the same three shapes the Force Logout panel
	 * takes — and produces the standard "Could not find the user"
	 * error message users already know from `wp user`.
	 *
	 * @since 3.1.0
	 *
	 * @param string $identifier Raw identifier from the command line.
	 *
	 * @return WP_User
	 */
	private function fetch_user( string $identifier ): WP_User {
		$fetcher = new User_Fetcher();

		return $fetcher->get_check( $identifier );
	}

	/**
	 * Is this user's session storage the default usermeta one?
	 *
	 * Asks `WP_Session_Tokens` for the resolved manager instead of
	 * re-running the `session_token_manager` filter ourselves — the
	 * filter receives the user id, so a site could legitimately return
	 * a different backend per user, and only the resolved instance
	 * reflects that.
	 *
	 * @since 3.1.0
	 *
	 * @param int $user_id User id.
	 *
	 * @return bool
	 */
	private function is_default_storage( int $user_id ): bool {
		return WP_Session_Tokens::get_instance( $user_id ) instanceof WP_User_Meta_Session_Tokens;
	}

	/**
	 * Warn when session details can't be read from a custom backend.
	 *
	 * Listing reads the meta row directly, so a site that has swapped
	 * the storage would otherwise see a silently empty table.
	 *
	 * @since 3.1.0
	 *
	 * @param int $user_id User id.
	 *
	 * @return void
	 */
	private function warn_on_custom_storage( int $user_id ): void {
		if ( ! $this->is_default_storage( $user_id ) ) {
			WP_CLI::warning(
				__( 'This site uses a custom session storage backend. Session details are read from the default usermeta store, so this list may be empty or incomplete.', 'loggedin' )
			);
		}
	}
}
