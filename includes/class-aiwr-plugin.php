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
		add_action( 'init', array( $this, 'ensure_scheduled_events' ) );
		add_action( AIWR_Log::CRON_HOOK, array( 'AIWR_Log', 'run_scheduled_prune' ) );

		( new AIWR_Settings() )->register();
		( new AIWR_Log_Screen() )->register();

		add_action( 'rest_api_init', array( new AIWR_Rest(), 'register_routes' ) );
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_editor_assets' ) );
	}

	/**
	 * Activation: run migrations and schedule the daily log prune.
	 */
	public static function activate() {
		AIWR_Migrations::maybe_upgrade();
		self::schedule_prune();
	}

	/**
	 * Deactivation: unschedule the daily log prune.
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( AIWR_Log::CRON_HOOK );
	}

	/**
	 * Ensure the daily prune event exists on load, in case the plugin was updated without a
	 * reactivation that would otherwise schedule it.
	 */
	public function ensure_scheduled_events() {
		self::schedule_prune();
	}

	/**
	 * Schedule the daily prune event when it is not already scheduled.
	 */
	private static function schedule_prune() {
		if ( ! wp_next_scheduled( AIWR_Log::CRON_HOOK ) ) {
			wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', AIWR_Log::CRON_HOOK );
		}
	}

	/**
	 * Enqueue the sidebar build and hand the client its REST URL and nonce.
	 *
	 * The provider key is never localized here: the browser only receives the proxy URL and a nonce.
	 */
	public function enqueue_editor_assets() {
		$asset_file = AIWR_PATH . 'build/index.asset.php';

		if ( ! file_exists( $asset_file ) ) {
			return;
		}

		$asset = require $asset_file;

		wp_enqueue_script(
			'aiwr-editor',
			AIWR_URL . 'build/index.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);

		wp_set_script_translations( 'aiwr-editor', 'wp-ai-writer', AIWR_PATH . 'languages' );

		wp_localize_script(
			'aiwr-editor',
			'aiwrEditor',
			array(
				'restUrl' => esc_url_raw( rest_url( 'aiwr/v1/generate' ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
			)
		);

		if ( file_exists( AIWR_PATH . 'build/index.css' ) ) {
			wp_enqueue_style(
				'aiwr-editor',
				AIWR_URL . 'build/index.css',
				array( 'wp-components' ),
				$asset['version']
			);
		}
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
