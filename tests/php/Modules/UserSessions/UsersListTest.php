<?php
/**
 * Tests for {@see \FoxeLabs\Loggedin\Modules\UserSessions\UsersList} and
 * {@see \FoxeLabs\Loggedin\Sessions\LastLogin}.
 *
 * @package FoxeLabs\Loggedin\Tests\Modules\UserSessions
 */

declare( strict_types = 1 );

namespace FoxeLabs\Loggedin\Tests\Modules\UserSessions;

use FoxeLabs\Loggedin\Modules\UserSessions\UsersList;
use FoxeLabs\Loggedin\Plugin;
use FoxeLabs\Loggedin\Sessions\LastLogin;
use WP_Session_Tokens;
use WP_UnitTestCase;
use WP_User_Query;


/**
 * @group users-list
 */
class UsersListTest extends WP_UnitTestCase {

	private int $user_id;

	public function set_up(): void {
		parent::set_up();

		$this->user_id = self::factory()->user->create();
		WP_Session_Tokens::get_instance( $this->user_id )->create( time() + HOUR_IN_SECONDS );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	public function test_a_login_records_the_time(): void {
		do_action( 'wp_login', 'someone', get_user_by( 'id', $this->user_id ) );

		$this->assertEqualsWithDelta( time(), Plugin::instance()->last_login()->get( $this->user_id ), 2 );
	}

	public function test_the_sessions_column_counts_sessions(): void {
		$this->assertSame( '1', Plugin::instance()->user_sessions()->users_list()->column( '', 'loggedin_sessions', $this->user_id ) );
	}

	public function test_the_last_login_column_says_when_nothing_is_recorded(): void {
		$this->assertStringContainsString(
			'No login recorded',
			Plugin::instance()->user_sessions()->users_list()->column( '', 'loggedin_last_login', $this->user_id )
		);
	}

	public function test_other_columns_pass_through(): void {
		$this->assertSame( 'kept', Plugin::instance()->user_sessions()->users_list()->column( 'kept', 'email', $this->user_id ) );
	}

	public function test_sorting_by_last_login_keeps_users_without_one(): void {
		$never = self::factory()->user->create();
		update_user_meta( $this->user_id, LastLogin::META_KEY, time() );

		set_current_screen( 'users' );

		// UsersList boots only in wp-admin, so its hook isn't there yet.
		add_action( 'pre_get_users', array( Plugin::instance()->user_sessions()->users_list(), 'sort' ) );

		$query = new WP_User_Query(
			array(
				'include' => array( $this->user_id, $never ),
				'orderby' => 'loggedin_last_login',
				'order'   => 'DESC',
				'fields'  => 'ID',
			)
		);

		$this->assertSame( array( $this->user_id, $never ), array_map( 'intval', $query->get_results() ) );
	}

	public function test_the_bulk_action_signs_users_out(): void {
		$sendback = Plugin::instance()->user_sessions()->users_list()->handle_bulk_action( 'users.php', 'loggedin_sign_out', array( $this->user_id ) );

		$this->assertStringContainsString( 'loggedin_signed_out=1', $sendback );
		$this->assertCount( 0, WP_Session_Tokens::get_instance( $this->user_id )->get_all() );
	}

	public function test_the_bulk_action_needs_permission(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$sendback = Plugin::instance()->user_sessions()->users_list()->handle_bulk_action( 'users.php', 'loggedin_sign_out', array( $this->user_id ) );

		$this->assertStringContainsString( 'loggedin_signed_out=0', $sendback );
		$this->assertCount( 1, WP_Session_Tokens::get_instance( $this->user_id )->get_all() );
	}
}
