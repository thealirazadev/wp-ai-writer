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
	const USAGE_OPTION        = 'aiwr_usage';

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

	/**
	 * Enforce the per-site monthly token budget before any provider call.
	 *
	 * @return true|WP_Error WP_Error aiwr_budget_exhausted (403) when the budget is reached.
	 */
	public static function check_budget() {
		$settings = aiwr_get_settings();
		$budget   = (int) $settings['monthly_budget_tokens'];

		if ( $budget <= 0 ) {
			return true;
		}

		$usage = self::get_current_usage();
		$used  = $usage['input_tokens'] + $usage['output_tokens'];

		if ( $used >= $budget ) {
			return new WP_Error(
				'aiwr_budget_exhausted',
				__( 'The monthly usage budget for this site has been reached. Ask an administrator to raise it.', 'wp-ai-writer' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Current calendar-month usage counter, resetting when the stored month is stale.
	 *
	 * @return array{month:string,input_tokens:int,output_tokens:int}
	 */
	public static function get_current_usage() {
		$month = gmdate( 'Y-m' );
		$usage = get_option( self::USAGE_OPTION );

		if ( is_array( $usage ) && isset( $usage['month'] ) && $month === $usage['month'] ) {
			return array(
				'month'         => $month,
				'input_tokens'  => isset( $usage['input_tokens'] ) ? (int) $usage['input_tokens'] : 0,
				'output_tokens' => isset( $usage['output_tokens'] ) ? (int) $usage['output_tokens'] : 0,
			);
		}

		return array(
			'month'         => $month,
			'input_tokens'  => 0,
			'output_tokens' => 0,
		);
	}
}
