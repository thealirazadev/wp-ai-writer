<?php
/**
 * Plugin orchestrator: wires the plugin classes together.
 *
 * @package WP_AI_Writer
 */

defined( 'ABSPATH' ) || exit;

/**
 * Boots the plugin's surfaces and hooks.
 */
class AIWR_Plugin {

	/**
	 * Register hooks and instantiate the plugin's components.
	 */
	public function run() {
		add_action( 'plugins_loaded', array( 'AIWR_Migrations', 'maybe_upgrade' ) );
		add_action( 'init', array( $this, 'load_textdomain' ) );

		( new AIWR_Settings() )->register();
	}

	/**
	 * Load the plugin translations.
	 */
	public function load_textdomain() {
		load_plugin_textdomain(
			'wp-ai-writer',
			false,
			dirname( plugin_basename( AIWR_PLUGIN_FILE ) ) . '/languages'
		);
	}
}
