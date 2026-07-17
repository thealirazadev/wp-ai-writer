<?php
/**
 * Versioned database migrations.
 *
 * @package WP_AI_Writer
 */

defined( 'ABSPATH' ) || exit;

/**
 * Creates and upgrades the activity log table, keyed off the aiwr_db_version option.
 *
 * Applied migrations are never edited afterward: a schema change is a new version and a new branch
 * in maybe_upgrade().
 */
class AIWR_Migrations {

	const DB_VERSION     = '1';
	const VERSION_OPTION = 'aiwr_db_version';

	/**
	 * Run any outstanding migrations. Cheap enough to call on every load (one option read).
	 */
	public static function maybe_upgrade() {
		if ( self::DB_VERSION === get_option( self::VERSION_OPTION ) ) {
			return;
		}

		self::install();

		update_option( self::VERSION_OPTION, self::DB_VERSION, false );
	}

	/**
	 * Create the log table via dbDelta.
	 */
	private static function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table           = $wpdb->prefix . 'aiwr_log';
		$charset_collate = $wpdb->get_charset_collate();

		// dbDelta is whitespace-sensitive: two spaces after PRIMARY KEY, one field/key per line.
		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			action varchar(20) NOT NULL DEFAULT '',
			model varchar(100) NOT NULL DEFAULT '',
			input_tokens int(10) unsigned NOT NULL DEFAULT 0,
			output_tokens int(10) unsigned NOT NULL DEFAULT 0,
			tokens_estimated tinyint(1) NOT NULL DEFAULT 0,
			cost_estimate decimal(10,6) DEFAULT NULL,
			status varchar(20) NOT NULL DEFAULT '',
			duration_ms int(10) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			KEY user_id (user_id),
			KEY created_at (created_at)
		) {$charset_collate};";

		dbDelta( $sql );
	}
}
