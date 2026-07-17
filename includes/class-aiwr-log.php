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
}
