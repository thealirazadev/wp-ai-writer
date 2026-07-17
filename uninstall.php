<?php
/**
 * Uninstall routine: drop the log table and delete options and transients.
 *
 * @package WP_AI_Writer
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

$aiwr_table = $wpdb->prefix . 'aiwr_log';

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- One-time schema teardown of the plugin's own table on uninstall.
$wpdb->query( "DROP TABLE IF EXISTS {$aiwr_table}" );

delete_option( 'aiwr_settings' );
delete_option( 'aiwr_usage' );
delete_option( 'aiwr_db_version' );

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_aiwr_rl_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_aiwr_rl_' ) . '%'
	)
);
