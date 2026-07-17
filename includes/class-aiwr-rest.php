<?php
/**
 * REST proxy: route registration, permission callbacks, and generate handler.
 *
 * The plugin exposes exactly one application route. Settings and the activity log are
 * server-rendered admin screens, not REST endpoints.
 *
 * @package WP_AI_Writer
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers and handles POST /aiwr/v1/generate.
 */
class AIWR_Rest {

	const REST_NAMESPACE = 'aiwr/v1';

	/**
	 * Register the generate route.
	 */
	public function register_routes() {
		register_rest_route(
			self::REST_NAMESPACE,
			'/generate',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'generate' ),
				'permission_callback' => array( $this, 'permission_check' ),
			)
		);
	}

	/**
	 * Permission callback: logged in, can edit posts, and can edit the target post when given.
	 *
	 * Core REST verifies the X-WP-Nonce header before this runs.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public function permission_check( WP_REST_Request $request ) {
		if ( ! is_user_logged_in() || ! current_user_can( 'edit_posts' ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You are not allowed to use the writing assistant.', 'wp-ai-writer' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		$post_id = (int) $request->get_param( 'post_id' );

		if ( $post_id > 0 && ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You are not allowed to edit this post.', 'wp-ai-writer' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		return true;
	}

	/**
	 * Handle a generation request.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function generate( WP_REST_Request $request ) {
		$settings = aiwr_get_settings();

		if ( '' === $settings['api_key'] || '' === $settings['model'] ) {
			return new WP_Error(
				'aiwr_not_configured',
				__( 'The writing assistant is not configured yet. Ask an administrator to add the provider key and model.', 'wp-ai-writer' ),
				array( 'status' => 409 )
			);
		}

		$action = sanitize_key( (string) $request->get_param( 'action' ) );
		$valid  = $this->validate( $action, $request );

		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		$rate_limit = AIWR_Limits::check_rate_limit( get_current_user_id() );

		if ( is_wp_error( $rate_limit ) ) {
			return $rate_limit;
		}

		$prompt = AIWR_Prompts::build( $action, $valid['input'], $valid['options'] );

		if ( is_wp_error( $prompt ) ) {
			return $prompt;
		}

		$result = AIWR_Provider::request(
			array(
				'model'      => $settings['model'],
				'max_tokens' => $prompt['max_tokens'],
				'messages'   => $prompt['messages'],
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$usage = $result['usage'];
		$cost  = $this->cost_estimate( $usage, $settings );

		return rest_ensure_response(
			array(
				'action'        => $action,
				'result'        => $this->shape_result( $action, $result['text'] ),
				'usage'         => $usage,
				'cost_estimate' => $cost,
			)
		);
	}

	/**
	 * Validate and sanitize the request input for an action.
	 *
	 * @param string          $action  Action name.
	 * @param WP_REST_Request $request Request.
	 * @return array{input:array,options:array}|WP_Error
	 */
	private function validate( $action, WP_REST_Request $request ) {
		$input = $request->get_param( 'input' );
		$input = is_array( $input ) ? $input : array();

		switch ( $action ) {
			case 'draft':
				return $this->validate_draft( $input );
		}

		return $this->invalid( __( 'That action is not available.', 'wp-ai-writer' ) );
	}

	/**
	 * Validate the draft action input.
	 *
	 * @param array $input Raw input.
	 * @return array{input:array,options:array}|WP_Error
	 */
	private function validate_draft( array $input ) {
		$prompt = isset( $input['prompt'] ) ? trim( sanitize_textarea_field( (string) $input['prompt'] ) ) : '';
		$length = mb_strlen( $prompt );

		if ( $length < 1 ) {
			return $this->invalid( __( 'Enter a prompt to generate a draft.', 'wp-ai-writer' ) );
		}

		if ( $length > 2000 ) {
			return $this->invalid( __( 'The prompt must be 2000 characters or fewer.', 'wp-ai-writer' ) );
		}

		return array(
			'input'   => array( 'prompt' => $prompt ),
			'options' => array(),
		);
	}

	/**
	 * Shape the provider text into the action's result payload, sanitizing HTML server-side.
	 *
	 * @param string $action Action name.
	 * @param string $text   Provider text.
	 * @return array Result payload.
	 */
	private function shape_result( $action, $text ) {
		switch ( $action ) {
			case 'draft':
				return array( 'html' => wp_kses_post( $text ) );
		}

		return array();
	}

	/**
	 * Estimate cost from usage and the configured prices, or null when prices are unset.
	 *
	 * @param array $usage    Token usage.
	 * @param array $settings Plugin settings.
	 * @return float|null
	 */
	private function cost_estimate( array $usage, array $settings ) {
		$input_price  = (float) $settings['price_input_per_mtok'];
		$output_price = (float) $settings['price_output_per_mtok'];

		if ( 0.0 >= $input_price && 0.0 >= $output_price ) {
			return null;
		}

		$cost = ( $usage['input_tokens'] / 1000000 ) * $input_price
			+ ( $usage['output_tokens'] / 1000000 ) * $output_price;

		return round( $cost, 6 );
	}

	/**
	 * Build a friendly invalid-input error.
	 *
	 * @param string $message Field-specific message.
	 * @return WP_Error
	 */
	private function invalid( $message ) {
		return new WP_Error( 'aiwr_invalid_input', $message, array( 'status' => 400 ) );
	}
}
