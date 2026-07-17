<?php
/**
 * Activity log admin screen.
 *
 * @package WP_AI_Writer
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers the Tools > AI Writer Log screen for administrators.
 */
class AIWR_Log_Screen {

	/**
	 * Register admin hooks.
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
	}

	/**
	 * Add the log screen under the Tools menu.
	 */
	public function add_menu() {
		add_management_page(
			__( 'AI Writer Log', 'wp-ai-writer' ),
			__( 'AI Writer Log', 'wp-ai-writer' ),
			'manage_options',
			'aiwr-log',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Render the log screen.
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$table = new AIWR_Log_Table();
		$table->prepare_items();
		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			<p><?php esc_html_e( 'Every request is recorded as metadata only. No prompt or response content is stored.', 'wp-ai-writer' ); ?></p>
			<?php $table->display(); ?>
		</div>
		<?php
	}
}
