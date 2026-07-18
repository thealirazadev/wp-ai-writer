<?php
/**
 * Settings API page: provider key, model, budget, prices, and usage panel.
 *
 * @package WP_AI_Writer
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers the Settings > AI Writer page and sanitizes its option.
 *
 * The provider key is write-only: the field shows a masked placeholder, resubmitting it unchanged
 * keeps the stored key, and clearing the field deletes it. The key is never echoed in full.
 */
class AIWR_Settings {

	const OPTION       = 'aiwr_settings';
	const OPTION_GROUP = 'aiwr_settings_group';
	const PAGE         = 'aiwr-settings';

	/**
	 * Default settings values.
	 *
	 * @return array{api_key:string,model:string,monthly_budget_tokens:int,price_input_per_mtok:float,price_output_per_mtok:float}
	 */
	public static function defaults() {
		return array(
			'api_key'               => '',
			'model'                 => '',
			'monthly_budget_tokens' => 500000,
			'price_input_per_mtok'  => 0.0,
			'price_output_per_mtok' => 0.0,
		);
	}

	/**
	 * Masked representation of a stored key: bullets plus the last four characters.
	 *
	 * Resubmitting this exact string is the "keep the stored key" signal.
	 *
	 * @param string $key Stored key.
	 * @return string Masked string, or an empty string when no key is stored.
	 */
	public static function mask_key( $key ) {
		$key = (string) $key;

		if ( strlen( $key ) < 4 ) {
			return '';
		}

		return str_repeat( "\u{2022}", 8 ) . substr( $key, -4 );
	}

	/**
	 * Register admin hooks.
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	/**
	 * Add the options page under the Settings menu.
	 */
	public function add_menu() {
		add_options_page(
			__( 'AI Writer', 'wp-ai-writer' ),
			__( 'AI Writer', 'wp-ai-writer' ),
			'manage_options',
			self::PAGE,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Register the setting, its sections, and its fields.
	 */
	public function register_settings() {
		register_setting(
			self::OPTION_GROUP,
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => self::defaults(),
			)
		);

		add_settings_section(
			'aiwr_provider',
			__( 'Provider', 'wp-ai-writer' ),
			static function () {
				echo '<p>' . esc_html__( 'Connection to the LLM provider API. The key is stored on the server and never sent to the browser.', 'wp-ai-writer' ) . '</p>';
			},
			self::PAGE
		);

		add_settings_field( 'aiwr_api_key', __( 'API key', 'wp-ai-writer' ), array( $this, 'field_api_key' ), self::PAGE, 'aiwr_provider' );
		add_settings_field( 'aiwr_model', __( 'Model identifier', 'wp-ai-writer' ), array( $this, 'field_model' ), self::PAGE, 'aiwr_provider' );

		add_settings_section(
			'aiwr_budget',
			__( 'Budget and pricing', 'wp-ai-writer' ),
			static function () {
				echo '<p>' . esc_html__( 'Cap monthly usage and optionally estimate spend. Leave prices blank to skip cost estimates.', 'wp-ai-writer' ) . '</p>';
			},
			self::PAGE
		);

		add_settings_field( 'aiwr_budget_field', __( 'Monthly token budget', 'wp-ai-writer' ), array( $this, 'field_budget' ), self::PAGE, 'aiwr_budget' );
		add_settings_field( 'aiwr_price_input', __( 'Input price (per million tokens)', 'wp-ai-writer' ), array( $this, 'field_price_input' ), self::PAGE, 'aiwr_budget' );
		add_settings_field( 'aiwr_price_output', __( 'Output price (per million tokens)', 'wp-ai-writer' ), array( $this, 'field_price_output' ), self::PAGE, 'aiwr_budget' );
	}

	/**
	 * Render the API key field (masked).
	 */
	public function field_api_key() {
		$settings = aiwr_get_settings();
		$masked   = self::mask_key( $settings['api_key'] );
		?>
		<input type="text" class="regular-text" autocomplete="off" spellcheck="false"
			name="<?php echo esc_attr( self::OPTION ); ?>[api_key]"
			value="<?php echo esc_attr( $masked ); ?>" />
		<p class="description">
			<?php esc_html_e( 'Leave the masked value to keep the stored key. Clear the field to delete it. Enter a new key to replace it.', 'wp-ai-writer' ); ?>
		</p>
		<?php
	}

	/**
	 * Render the model identifier field.
	 */
	public function field_model() {
		$settings = aiwr_get_settings();
		?>
		<input type="text" class="regular-text" spellcheck="false"
			name="<?php echo esc_attr( self::OPTION ); ?>[model]"
			value="<?php echo esc_attr( $settings['model'] ); ?>" />
		<p class="description">
			<?php esc_html_e( 'The model identifier string as documented by your provider.', 'wp-ai-writer' ); ?>
		</p>
		<?php
	}

	/**
	 * Render the monthly budget field.
	 */
	public function field_budget() {
		$settings = aiwr_get_settings();
		?>
		<input type="number" min="0" step="1" class="regular-text"
			name="<?php echo esc_attr( self::OPTION ); ?>[monthly_budget_tokens]"
			value="<?php echo esc_attr( (string) $settings['monthly_budget_tokens'] ); ?>" />
		<p class="description">
			<?php esc_html_e( 'Combined input and output tokens allowed per calendar month. Set to 0 for no cap.', 'wp-ai-writer' ); ?>
		</p>
		<?php
	}

	/**
	 * Render the input price field.
	 */
	public function field_price_input() {
		$this->render_price_field( 'price_input_per_mtok' );
	}

	/**
	 * Render the output price field.
	 */
	public function field_price_output() {
		$this->render_price_field( 'price_output_per_mtok' );
	}

	/**
	 * Render a price field.
	 *
	 * @param string $key Settings key.
	 */
	private function render_price_field( $key ) {
		$settings = aiwr_get_settings();
		$value    = 0 < (float) $settings[ $key ] ? (string) $settings[ $key ] : '';
		?>
		<input type="number" min="0" step="0.000001" class="regular-text"
			name="<?php echo esc_attr( self::OPTION . '[' . $key . ']' ); ?>"
			value="<?php echo esc_attr( $value ); ?>" />
		<?php
	}

	/**
	 * Sanitize the submitted settings.
	 *
	 * Starts from the stored values so a rejected field never overwrites a good one.
	 *
	 * @param mixed $raw Raw submitted values.
	 * @return array Sanitized settings.
	 */
	public function sanitize( $raw ) {
		$stored = get_option( self::OPTION );
		$stored = is_array( $stored ) ? wp_parse_args( $stored, self::defaults() ) : self::defaults();
		$out    = $stored;
		$errors = array();

		$raw = is_array( $raw ) ? $raw : array();

		// API key: blank clears, unchanged mask keeps, anything else replaces.
		$incoming = isset( $raw['api_key'] ) ? trim( (string) wp_unslash( $raw['api_key'] ) ) : '';
		if ( '' === $incoming ) {
			$out['api_key'] = '';
		} elseif ( self::mask_key( $stored['api_key'] ) === $incoming ) {
			$out['api_key'] = $stored['api_key'];
		} else {
			$out['api_key'] = sanitize_text_field( $incoming );
		}

		$out['model'] = isset( $raw['model'] ) ? sanitize_text_field( wp_unslash( $raw['model'] ) ) : $stored['model'];

		$budget = isset( $raw['monthly_budget_tokens'] ) ? wp_unslash( $raw['monthly_budget_tokens'] ) : '';
		if ( ! is_numeric( $budget ) || (int) $budget < 0 ) {
			$errors[] = __( 'monthly token budget', 'wp-ai-writer' );
		} else {
			$out['monthly_budget_tokens'] = (int) $budget;
		}

		foreach ( array( 'price_input_per_mtok', 'price_output_per_mtok' ) as $price_key ) {
			$price = isset( $raw[ $price_key ] ) ? trim( (string) wp_unslash( $raw[ $price_key ] ) ) : '';
			if ( '' === $price ) {
				$out[ $price_key ] = 0.0;
			} elseif ( ! is_numeric( $price ) || (float) $price < 0 ) {
				$errors[] = __( 'token price', 'wp-ai-writer' );
			} else {
				$out[ $price_key ] = (float) $price;
			}
		}

		if ( ! empty( $errors ) ) {
			add_settings_error(
				self::OPTION,
				'aiwr_invalid_settings',
				sprintf(
					/* translators: %s: comma-separated list of rejected field names. */
					__( 'These values were invalid and were not saved: %s. Previous values were kept.', 'wp-ai-writer' ),
					implode( ', ', array_unique( $errors ) )
				),
				'error'
			);
		}

		return $out;
	}

	/**
	 * Render the settings page.
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			<?php settings_errors( self::OPTION ); ?>
			<form action="options.php" method="post">
				<?php
				settings_fields( self::OPTION_GROUP );
				do_settings_sections( self::PAGE );
				submit_button();
				?>
			</form>
			<?php $this->render_usage_panel(); ?>
		</div>
		<?php
	}

	/**
	 * Render the current-month usage panel.
	 */
	private function render_usage_panel() {
		$settings = aiwr_get_settings();
		$usage    = AIWR_Limits::get_current_usage();
		$budget   = (int) $settings['monthly_budget_tokens'];
		$total    = $usage['input_tokens'] + $usage['output_tokens'];
		?>
		<h2><?php esc_html_e( 'Usage this month', 'wp-ai-writer' ); ?></h2>
		<?php if ( 0 === $total ) : ?>
			<p><?php esc_html_e( 'No usage recorded this month.', 'wp-ai-writer' ); ?></p>
		<?php else : ?>
			<table class="widefat striped" style="max-width:32rem;">
				<tbody>
					<tr>
						<th scope="row"><?php esc_html_e( 'Input tokens', 'wp-ai-writer' ); ?></th>
						<td><?php echo esc_html( number_format_i18n( $usage['input_tokens'] ) ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Output tokens', 'wp-ai-writer' ); ?></th>
						<td><?php echo esc_html( number_format_i18n( $usage['output_tokens'] ) ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Total tokens', 'wp-ai-writer' ); ?></th>
						<td><?php echo esc_html( number_format_i18n( $total ) ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Monthly budget', 'wp-ai-writer' ); ?></th>
						<td>
							<?php
							if ( 0 < $budget ) {
								$percent = min( 100, (int) round( $total / $budget * 100 ) );
								printf(
									/* translators: 1: budget token count, 2: percent of budget used. */
									esc_html__( '%1$s tokens (%2$d%% used)', 'wp-ai-writer' ),
									esc_html( number_format_i18n( $budget ) ),
									(int) $percent
								);
							} else {
								esc_html_e( 'No cap', 'wp-ai-writer' );
							}
							?>
						</td>
					</tr>
				</tbody>
			</table>
		<?php endif; ?>
		<?php
	}
}
