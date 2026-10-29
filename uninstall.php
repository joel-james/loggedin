<?php
/**
 * Plugin uninstall handler.
 *
 * Fired by WordPress when the user clicks "Delete" on the Plugins
 * screen and confirms. Our job here is to leave the site in the same
 * state it would be in if the plugin had never been installed:
 *
 *   - Delete the unified settings option and the version marker.
 *   - Delete the legacy single-key options from pre-3.0 installs.
 *   - Delete the review-notice scheduling option and the per-user
 *     dismissal meta.
 *
 * We intentionally do NOT touch the `session_tokens` user meta — that
 * is core WordPress data, not ours; nuking it would log every user
 * out unnecessarily.
 *
 * @package FoxeLabs\Loggedin
 */

// `WP_UNINSTALL_PLUGIN` is set by core only when this file is invoked
// through the official uninstall path. Direct browser hits to this
// file bail here.
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// Current option keys.
delete_option( 'loggedin_settings' );
delete_option( 'loggedin_version' );

// Legacy single-key options — kept here in addition to the migrator
// so that a clean uninstall removes them even on sites that never ran
// a version with the upgrader.
delete_option( 'loggedin_maximum' );
delete_option( 'loggedin_logic' );

// Review-notice scheduling.
delete_option( 'loggedin_rating_notice' );

// Site-wide logout epoch. Deleting it means sessions that were logged
// out via "Logout All Users" but whose owners never returned become
// valid again if their cookies haven't expired — acceptable, since the
// plugin enforcing the logout is being removed anyway.
delete_option( 'loggedin_logout_all_before' );

// Auto logout settings.
delete_option( 'loggedin_auto_logout_settings' );
delete_option( 'loggedin_auto_logout_role_timeouts' );
delete_option( 'loggedin_suggest_exempt_admins' );

global $wpdb;

/*
 * Drop the per-user "I dismissed the review notice" meta in a single
 * query. `WP_User_Query` would iterate users one at a time, which
 * scales poorly on large sites; a direct `wpdb->delete` is the
 * pragmatic choice here even though it bypasses any cache layer
 * stacked on top of the usermeta table (the cache is invalidated
 * automatically on the next read).
 */
$wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->usermeta,
	array(
		'meta_key' => 'loggedin_rating_notice_dismissed', // phpcs:ignore WordPress.DB.SlowDBQuery
	)
);

// Per-user rows: auto logout's activity stamps, the last login time,
// sessions ended by a newer login, and the self-unlock keys and send times.
$loggedin_user_meta = array(
	'loggedin_auto_logout_activity',
	'loggedin_last_login',
	'loggedin_displaced',
	'loggedin_unlock_request',
	'loggedin_unlock',
	'loggedin_unlock_sent',
);

foreach ( $loggedin_user_meta as $loggedin_meta_key ) {
	$wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->usermeta,
		array(
			'meta_key' => $loggedin_meta_key, // phpcs:ignore WordPress.DB.SlowDBQuery
		)
	);
}
