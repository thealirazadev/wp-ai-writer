<?php
/**
 * PHPUnit bootstrap: loads the WP test suite and the plugin.
 *
 * @package WP_AI_Writer
 */

$aiwr_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $aiwr_tests_dir ) {
	$aiwr_tests_dir = getenv( 'WP_PHPUNIT__DIR' );
}

if ( ! $aiwr_tests_dir && is_dir( '/wordpress-phpunit' ) ) {
	// Default location inside the @wordpress/env tests-cli container.
	$aiwr_tests_dir = '/wordpress-phpunit';
}

if ( ! $aiwr_tests_dir ) {
	$aiwr_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

// Point the WP test suite at the composer-installed polyfills when the environment does not.
if ( ! defined( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' ) && false === getenv( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' ) ) {
	$aiwr_polyfills = dirname( __DIR__ ) . '/vendor/yoast/phpunit-polyfills';

	if ( is_dir( $aiwr_polyfills ) ) {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Constant name is defined by the WordPress test suite.
		define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', $aiwr_polyfills );
	}
}

if ( ! file_exists( "{$aiwr_tests_dir}/includes/functions.php" ) ) {
	echo "Could not find the WordPress test suite at {$aiwr_tests_dir}. Set WP_TESTS_DIR or run through wp-env." . PHP_EOL; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	exit( 1 );
}

require_once "{$aiwr_tests_dir}/includes/functions.php";

/**
 * Load the plugin into the test environment.
 */
function aiwr_manually_load_plugin() {
	require dirname( __DIR__ ) . '/wp-ai-writer.php';
}
tests_add_filter( 'muplugins_loaded', 'aiwr_manually_load_plugin' );

require "{$aiwr_tests_dir}/includes/bootstrap.php";
