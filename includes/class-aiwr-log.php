<?php
/**
 * Activity log table writes and queries.
 *
 * @package WP_AI_Writer
 */

defined( 'ABSPATH' ) || exit;

/**
 * Append-only activity log: one row per provider request, metadata only.
 *
 * No prompt or response content is ever stored here.
 */
class AIWR_Log {

	/**
	 * WP-Cron hook that prunes the log to the retention window.
	 */
	const CRON_HOOK = 'aiwr_prune_log';

	/**
	 * Fully qualified log table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;

		return $wpdb->prefix . 'aiwr_log';
	}

	/**
	 * Insert one activity log row.
	 *
	 * @param array $row {
	 *     Row data.
	 *
	 *     @type int         $user_id          Author of the request.
	 *     @type string      $action           Action name.
	 *     @type string      $model            Model in effect.
	 *     @type int         $input_tokens     Input tokens.
	 *     @type int         $output_tokens    Output tokens.
	 *     @type bool        $tokens_estimated Whether usage was estimated.
	 *     @type float|null  $cost_estimate    Cost estimate, or null.
	 *     @type string      $status           success|provider_error|aborted.
	 *     @type int         $duration_ms      Request duration in milliseconds.
	 * }
	 * @return int|false Insert ID, or false on failure.
	 */
	public static function record( array $row ) {
		global $wpdb;

		$cost = ( isset( $row['cost_estimate'] ) && null !== $row['cost_estimate'] )
			? (float) $row['cost_estimate']
			: null;

		$data = array(
			'user_id'          => (int) ( $row['user_id'] ?? 0 ),
			'action'           => substr( (string) ( $row['action'] ?? '' ), 0, 20 ),
			'model'            => substr( (string) ( $row['model'] ?? '' ), 0, 100 ),
			'input_tokens'     => max( 0, (int) ( $row['input_tokens'] ?? 0 ) ),
			'output_tokens'    => max( 0, (int) ( $row['output_tokens'] ?? 0 ) ),
			'tokens_estimated' => empty( $row['tokens_estimated'] ) ? 0 : 1,
			'cost_estimate'    => $cost,
			'status'           => substr( (string) ( $row['status'] ?? '' ), 0, 20 ),
			'duration_ms'      => max( 0, (int) ( $row['duration_ms'] ?? 0 ) ),
			'created_at'       => gmdate( 'Y-m-d H:i:s' ),
		);

		$formats = array( '%d', '%s', '%s', '%d', '%d', '%d', '%f', '%s', '%d', '%s' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->insert( self::table(), $data, $formats );

		if ( false === $result ) {
			aiwr_log( 'log_insert_failed', array( 'error' => substr( (string) $wpdb->last_error, 0, 200 ) ) );

			return false;
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Sum input/output tokens for a calendar month.
	 *
	 * @param string $month Month in YYYY-MM form.
	 * @return array{input_tokens:int,output_tokens:int}
	 */
	public static function monthly_sum( $month ) {
		global $wpdb;

		$start = $month . '-01 00:00:00';
		$next  = gmdate( 'Y-m-01 00:00:00', strtotime( $start . ' +1 month' ) );
		$table = self::table();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table, aggregate read, table name is not user input.
		$found = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COALESCE( SUM( input_tokens ), 0 ) AS input_tokens, COALESCE( SUM( output_tokens ), 0 ) AS output_tokens FROM {$table} WHERE created_at >= %s AND created_at < %s",
				$start,
				$next
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return array(
			'input_tokens'  => isset( $found['input_tokens'] ) ? (int) $found['input_tokens'] : 0,
			'output_tokens' => isset( $found['output_tokens'] ) ? (int) $found['output_tokens'] : 0,
		);
	}

	/**
	 * Total number of log rows.
	 *
	 * @return int
	 */
	public static function total_count() {
		global $wpdb;

		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	}

	/**
	 * Delete log rows created strictly before the retention cutoff.
	 *
	 * The boundary is exclusive (created_at < cutoff), so a row landing exactly on the cutoff is
	 * kept. A retention of zero or less keeps every row.
	 *
	 * @param int $days Retention window in days.
	 * @return int Number of rows deleted.
	 */
	public static function prune_older_than( $days ) {
		global $wpdb;

		$days = (int) $days;

		if ( $days <= 0 ) {
			return 0;
		}

		$cutoff = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
		$table  = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table, scheduled maintenance delete, table name is not user input.
		$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s", $cutoff ) );

		return is_int( $deleted ) ? $deleted : 0;
	}

	/**
	 * Cron callback: prune the log to the configured retention window, then refresh the usage counter.
	 *
	 * The retention setting is read on each run so a changed value takes effect on the next daily
	 * pass. Usage is recomputed only when rows were actually removed, since the counter is derived
	 * from the log and an aggressive retention could drop current-month rows.
	 */
	public static function run_scheduled_prune() {
		$settings = aiwr_get_settings();
		$deleted  = self::prune_older_than( $settings['log_retention_days'] );

		if ( $deleted > 0 ) {
			AIWR_Limits::refresh_usage();
		}
	}

	/**
	 * Fetch a page of log rows, newest first.
	 *
	 * @param int $per_page Rows per page.
	 * @param int $offset   Row offset.
	 * @return array[] Row arrays.
	 */
	public static function get_page( $per_page, $offset ) {
		global $wpdb;

		$table = self::table();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table, paginated admin read, table name is not user input.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} ORDER BY id DESC LIMIT %d OFFSET %d",
				max( 1, (int) $per_page ),
				max( 0, (int) $offset )
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return is_array( $rows ) ? $rows : array();
	}
}
