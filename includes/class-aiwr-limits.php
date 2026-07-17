<?php
/**
 * Rate limiter, monthly budget check, and usage counter.
 *
 * @package WP_AI_Writer
 */

defined( 'ABSPATH' ) || exit;

/**
 * Server-side guardrails run before any provider call.
 */
class AIWR_Limits {

	const RATE_PREFIX         = 'aiwr_rl_';
	const RATE_WINDOW_SECONDS = 60;
	const RATE_DEFAULT_LIMIT  = 10;

	/**
	 * Enforce the per-user requests-per-minute limit with a fixed 60s window.
	 *
	 * @param int $user_id Current user ID.
	 * @return true|WP_Error WP_Error aiwr_rate_limited (429) when the limit is hit.
	 */
	public static function check_rate_limit( $user_id ) {
		$limit = (int) apply_filters( 'aiwr_requests_per_minute', self::RATE_DEFAULT_LIMIT );

		if ( $limit <= 0 ) {
			return true;
		}

		$key   = self::RATE_PREFIX . (int) $user_id;
		$now   = time();
		$state = get_transient( $key );

		if ( ! is_array( $state ) || ! isset( $state['window_start'], $state['count'] )
			|| ( $now - (int) $state['window_start'] ) >= self::RATE_WINDOW_SECONDS ) {
			$state = array(
				'count'        => 0,
				'window_start' => $now,
			);
		}

		if ( (int) $state['count'] >= $limit ) {
			$retry_after = self::RATE_WINDOW_SECONDS - ( $now - (int) $state['window_start'] );

			return new WP_Error(
				'aiwr_rate_limited',
				sprintf(
					/* translators: %d: number of seconds to wait before retrying. */
					__( 'Too many requests. Please wait %d seconds and try again.', 'wp-ai-writer' ),
					max( 1, $retry_after )
				),
				array( 'status' => 429 )
			);
		}

		++$state['count'];
		set_transient( $key, $state, self::RATE_WINDOW_SECONDS );

		return true;
	}
}
