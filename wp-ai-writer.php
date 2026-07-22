<?php
/**
 * Plugin Name:       AI Writer
 * Plugin URI:        https://github.com/thealirazadev/wp-ai-writer
 * Description:       A writing assistant sidebar for the block editor, backed by a secured server-side LLM provider proxy.
 * Version:           0.1.0
 * Requires at least: 6.6
 * Requires PHP:      8.1
 * Author:            Ali Raza
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wp-ai-writer
 * Domain Path:       /languages
 *
 * @package WP_AI_Writer
 */

defined( 'ABSPATH' ) || exit;

define( 'AIWR_VERSION', '0.1.0' );
define( 'AIWR_PLUGIN_FILE', __FILE__ );
define( 'AIWR_PATH', plugin_dir_path( __FILE__ ) );
define( 'AIWR_URL', plugin_dir_url( __FILE__ ) );

/*
 * Endpoint for the LLM provider API.
 *
 * Deliberately a constant, never an admin setting: an admin-editable URL that receives the stored
 * key would be a server-side request forgery hazard. Define AIWR_PROVIDER_ENDPOINT in wp-config.php
 * to point at your provider (or a local mock during development). The default is the reserved
 * example host, so nothing is sent anywhere real until the endpoint is configured.
 */
if ( ! defined( 'AIWR_PROVIDER_ENDPOINT' ) ) {
	define( 'AIWR_PROVIDER_ENDPOINT', 'https://api.example.com/v1/generate' );
}

/**
 * Whether the host meets the plugin's minimum PHP and WordPress requirements.
 *
 * Registers an admin notice and returns false when it does not, so the caller can abort loading.
 */
function aiwr_requirements_met() {
	global $wp_version;

	$php_ok = version_compare( PHP_VERSION, '8.1', '>=' );
	$wp_ok  = isset( $wp_version ) && version_compare( $wp_version, '6.6', '>=' );

	if ( $php_ok && $wp_ok ) {
		return true;
	}

	add_action(
		'admin_notices',
		static function () use ( $php_ok ) {
			$message = $php_ok
				? __( 'AI Writer requires WordPress 6.6 or newer and has been deactivated.', 'wp-ai-writer' )
				: __( 'AI Writer requires PHP 8.1 or newer and has been deactivated.', 'wp-ai-writer' );

			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html( $message )
			);
		}
	);

	return false;
}

if ( ! aiwr_requirements_met() ) {
	return;
}

require_once AIWR_PATH . 'includes/aiwr-functions.php';
require_once AIWR_PATH . 'includes/class-aiwr-migrations.php';
require_once AIWR_PATH . 'includes/class-aiwr-log.php';
require_once AIWR_PATH . 'includes/class-aiwr-limits.php';
require_once AIWR_PATH . 'includes/class-aiwr-prompts.php';
require_once AIWR_PATH . 'includes/class-aiwr-provider.php';
require_once AIWR_PATH . 'includes/class-aiwr-rest.php';
require_once AIWR_PATH . 'includes/class-aiwr-settings.php';
require_once AIWR_PATH . 'includes/class-aiwr-log-screen.php';
require_once AIWR_PATH . 'includes/class-aiwr-plugin.php';

register_activation_hook( AIWR_PLUGIN_FILE, array( 'AIWR_Plugin', 'activate' ) );
register_deactivation_hook( AIWR_PLUGIN_FILE, array( 'AIWR_Plugin', 'deactivate' ) );

( new AIWR_Plugin() )->run();
