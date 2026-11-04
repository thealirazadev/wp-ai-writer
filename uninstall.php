<?php
/**
 * Uninstall routine: drop the log table and delete options and transients.
 *
 * On multisite the plugin creates a log table and options on every site it runs on, so the cleanup
 * runs once per site in the network.
 *
 * @package WP_AI_Writer
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$aiwr_uninstall_site = static function () {
	global $wpdb;

	$table = $wpdb->prefix . 'aiwr_log';

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- One-time schema teardown of the plugin's own table on uninstall.
	$wpdb->query( "DROP TABLE IF EXISTS {$table}" );

	delete_option( 'aiwr_settings' );
	delete_option( 'aiwr_usage' );
	delete_option( 'aiwr_db_version' );

	wp_clear_scheduled_hook( 'aiwr_prune_log' );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( '_transient_aiwr_rl_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_aiwr_rl_' ) . '%'
		)
	);
};

if ( is_multisite() ) {
	$aiwr_site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( $aiwr_site_ids as $aiwr_site_id ) {
		switch_to_blog( (int) $aiwr_site_id );
		$aiwr_uninstall_site();
		restore_current_blog();
	}
} else {
	$aiwr_uninstall_site();
}
