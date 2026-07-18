<?php
/**
 * Shared helpers: structured logger and settings accessor.
 *
 * @package WP_AI_Writer
 */

defined( 'ABSPATH' ) || exit;

/**
 * Write a single-line JSON diagnostic entry to the PHP error log when WP_DEBUG is on.
 *
 * Called at every failure branch. The API key and full prompt/response bodies are never passed in;
 * callers include only status codes, durations, and truncated diagnostics.
 *
 * @param string $event   Short machine-readable event name.
 * @param array  $context Optional structured context (no secrets, no full bodies).
 */
function aiwr_log( $event, array $context = array() ) {
	if ( ! ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ) {
		return;
	}

	$entry = array(
		'ts'    => gmdate( 'c' ),
		'event' => (string) $event,
	);

	if ( ! empty( $context ) ) {
		$entry['context'] = $context;
	}

	$encoded = wp_json_encode( $entry );

	if ( false === $encoded ) {
		$encoded = '{"event":"aiwr_log_encode_failed"}';
	}

	// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	error_log( 'aiwr ' . $encoded );
}

/**
 * Read the plugin settings, merged over the defaults.
 *
 * @return array{api_key:string,model:string,monthly_budget_tokens:int,price_input_per_mtok:float,price_output_per_mtok:float}
 */
function aiwr_get_settings() {
	$stored = get_option( AIWR_Settings::OPTION, array() );

	if ( ! is_array( $stored ) ) {
		$stored = array();
	}

	return wp_parse_args( $stored, AIWR_Settings::defaults() );
}
