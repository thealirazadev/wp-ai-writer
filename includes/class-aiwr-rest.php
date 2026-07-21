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
		$started  = microtime( true );
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

		$budget = AIWR_Limits::check_budget();

		if ( is_wp_error( $budget ) ) {
			return $budget;
		}

		if ( 'alt_text' === $action ) {
			$image = $this->load_attachment_image( $valid['input']['attachment_id'] );

			if ( is_wp_error( $image ) ) {
				return $image;
			}

			$valid['input'] = $image;
		}

		$prompt = AIWR_Prompts::build( $action, $valid['input'], $valid['options'] );

		if ( is_wp_error( $prompt ) ) {
			return $prompt;
		}

		$payload = array(
			'model'      => $settings['model'],
			'max_tokens' => $prompt['max_tokens'],
			'messages'   => $prompt['messages'],
		);

		if ( $request->get_param( 'stream' ) && $this->action_can_stream( $action ) && AIWR_Provider::can_stream() ) {
			$this->stream_response( $action, $payload, $settings, $started );
			// stream_response sends its own response and exits.
		}

		$result = AIWR_Provider::request( $payload );

		if ( is_wp_error( $result ) ) {
			AIWR_Log::record(
				array(
					'user_id'     => get_current_user_id(),
					'action'      => $action,
					'model'       => $settings['model'],
					'status'      => 'provider_error',
					'duration_ms' => $this->elapsed_ms( $started ),
				)
			);

			return $result;
		}

		$usage = $result['usage'];
		$cost  = $this->cost_estimate( $usage, $settings );

		AIWR_Log::record(
			array(
				'user_id'       => get_current_user_id(),
				'action'        => $action,
				'model'         => $settings['model'],
				'input_tokens'  => $usage['input_tokens'],
				'output_tokens' => $usage['output_tokens'],
				'cost_estimate' => $cost,
				'status'        => 'success',
				'duration_ms'   => $this->elapsed_ms( $started ),
			)
		);

		AIWR_Limits::add_usage( $usage['input_tokens'], $usage['output_tokens'] );

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
	 * Milliseconds elapsed since a microtime marker.
	 *
	 * @param float $started microtime( true ) marker.
	 * @return int
	 */
	private function elapsed_ms( $started ) {
		return (int) round( ( microtime( true ) - $started ) * 1000 );
	}

	/**
	 * Whether an action supports streaming. Short structured outputs are always JSON.
	 *
	 * @param string $action Action name.
	 * @return bool
	 */
	private function action_can_stream( $action ) {
		return in_array( $action, array( 'draft', 'rewrite', 'excerpt' ), true );
	}

	/**
	 * Relay a streamed generation as normalized SSE events, then log and exit.
	 *
	 * This route self-manages its output: it sends event-stream headers, relays provider deltas, and
	 * emits a terminal done/error event, so it bypasses the normal REST response serialization.
	 *
	 * @param string $action   Action name.
	 * @param array  $payload  Provider payload.
	 * @param array  $settings Plugin settings.
	 * @param float  $started  microtime( true ) marker.
	 */
	private function stream_response( $action, $payload, $settings, $started ) {
		$this->send_stream_headers();

		$assembled = '';
		$saw_delta = false;

		$on_delta = function ( $text ) use ( &$assembled, &$saw_delta ) {
			$clean      = wp_kses_post( $text );
			$assembled .= $clean;
			$saw_delta  = true;
			$this->emit_event( 'delta', array( 'text' => $clean ) );
		};

		$outcome  = AIWR_Provider::stream( $payload, $on_delta );
		$duration = $this->elapsed_ms( $started );
		$user_id  = get_current_user_id();

		if ( is_wp_error( $outcome ) ) {
			$error_data = $outcome->get_error_data();
			$status     = isset( $error_data['status'] ) ? (int) $error_data['status'] : 502;

			$this->emit_event(
				'error',
				array(
					'code'    => $outcome->get_error_code(),
					'message' => $outcome->get_error_message(),
					'data'    => array( 'status' => $status ),
				)
			);

			$estimated_output = $saw_delta ? (int) round( strlen( $assembled ) / 4 ) : 0;

			AIWR_Log::record(
				array(
					'user_id'          => $user_id,
					'action'           => $action,
					'model'            => $settings['model'],
					'output_tokens'    => $estimated_output,
					'tokens_estimated' => $saw_delta,
					'status'           => $saw_delta ? 'aborted' : 'provider_error',
					'duration_ms'      => $duration,
				)
			);

			if ( $saw_delta ) {
				AIWR_Limits::add_usage( 0, $estimated_output );
			}

			exit;
		}

		$usage = $outcome['usage'];
		$cost  = $this->cost_estimate( $usage, $settings );

		$this->emit_event(
			'done',
			array(
				'usage'         => $usage,
				'cost_estimate' => $cost,
			)
		);

		AIWR_Log::record(
			array(
				'user_id'       => $user_id,
				'action'        => $action,
				'model'         => $settings['model'],
				'input_tokens'  => $usage['input_tokens'],
				'output_tokens' => $usage['output_tokens'],
				'cost_estimate' => $cost,
				'status'        => 'success',
				'duration_ms'   => $duration,
			)
		);

		AIWR_Limits::add_usage( $usage['input_tokens'], $usage['output_tokens'] );

		exit;
	}

	/**
	 * Send the event-stream headers and disable output buffering and compression.
	 */
	private function send_stream_headers() {
		if ( function_exists( 'apache_setenv' ) ) {
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_apache_setenv, WordPress.PHP.NoSilencedErrors.Discouraged
			@apache_setenv( 'no-gzip', '1' );
		}

		// phpcs:ignore WordPress.PHP.IniSet.Risky, WordPress.PHP.NoSilencedErrors.Discouraged
		@ini_set( 'zlib.output_compression', '0' );

		while ( ob_get_level() > 0 ) {
			ob_end_flush();
		}

		ob_implicit_flush( true );

		header( 'Content-Type: text/event-stream; charset=utf-8' );
		header( 'Cache-Control: no-cache' );
		header( 'X-Accel-Buffering: no' );
	}

	/**
	 * Write one normalized SSE event and flush it to the client.
	 *
	 * @param string $event Event name (delta, done, error).
	 * @param array  $data  Event payload.
	 */
	private function emit_event( $event, array $data ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Controlled event name and JSON-encoded payload for an SSE frame.
		echo 'event: ' . $event . "\n";
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON-encoded SSE payload.
		echo 'data: ' . wp_json_encode( $data ) . "\n\n";
		flush();
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
			case 'rewrite':
				return $this->validate_rewrite( $input, $request->get_param( 'options' ) );
			case 'seo':
				return $this->validate_seo( $input );
			case 'excerpt':
				return $this->validate_excerpt( $input );
			case 'alt_text':
				return $this->validate_alt_text( $input );
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
	 * Validate the rewrite action input and options.
	 *
	 * @param array $input   Raw input.
	 * @param mixed $options Raw options.
	 * @return array{input:array,options:array}|WP_Error
	 */
	private function validate_rewrite( array $input, $options ) {
		$text   = isset( $input['text'] ) ? trim( sanitize_textarea_field( (string) $input['text'] ) ) : '';
		$length = mb_strlen( $text );

		if ( $length < 1 ) {
			return $this->invalid( __( 'Select a block with text to rewrite.', 'wp-ai-writer' ) );
		}

		if ( $length > 8000 ) {
			return $this->invalid( __( 'The selected text must be 8000 characters or fewer.', 'wp-ai-writer' ) );
		}

		$options = is_array( $options ) ? $options : array();
		$tone    = isset( $options['tone'] ) ? sanitize_key( $options['tone'] ) : 'professional';
		$len     = isset( $options['length'] ) ? sanitize_key( $options['length'] ) : 'same';

		if ( ! in_array( $tone, array( 'professional', 'friendly', 'casual', 'confident' ), true ) ) {
			return $this->invalid( __( 'Choose a valid tone.', 'wp-ai-writer' ) );
		}

		if ( ! in_array( $len, array( 'shorter', 'same', 'longer' ), true ) ) {
			return $this->invalid( __( 'Choose a valid length.', 'wp-ai-writer' ) );
		}

		return array(
			'input'   => array( 'text' => $text ),
			'options' => array(
				'tone'   => $tone,
				'length' => $len,
			),
		);
	}

	/**
	 * Validate the seo action input. Content over the cap is truncated, not rejected.
	 *
	 * @param array $input Raw input.
	 * @return array{input:array,options:array}|WP_Error
	 */
	private function validate_seo( array $input ) {
		$title = isset( $input['title'] ) ? sanitize_text_field( (string) $input['title'] ) : '';
		if ( mb_strlen( $title ) > 300 ) {
			$title = mb_substr( $title, 0, 300 );
		}

		$content = isset( $input['content'] ) ? trim( wp_strip_all_tags( (string) $input['content'] ) ) : '';

		if ( mb_strlen( $content ) < 1 ) {
			return $this->invalid( __( 'Add some content to the post before generating SEO text.', 'wp-ai-writer' ) );
		}

		if ( mb_strlen( $content ) > 20000 ) {
			$content = mb_substr( $content, 0, 20000 );
		}

		return array(
			'input'   => array(
				'title'   => $title,
				'content' => $content,
			),
			'options' => array(),
		);
	}

	/**
	 * Validate the excerpt action input. Content over the cap is truncated, not rejected.
	 *
	 * @param array $input Raw input.
	 * @return array{input:array,options:array}|WP_Error
	 */
	private function validate_excerpt( array $input ) {
		$content = isset( $input['content'] ) ? trim( wp_strip_all_tags( (string) $input['content'] ) ) : '';

		if ( mb_strlen( $content ) < 1 ) {
			return $this->invalid( __( 'Add some content to the post before generating an excerpt.', 'wp-ai-writer' ) );
		}

		if ( mb_strlen( $content ) > 20000 ) {
			$content = mb_substr( $content, 0, 20000 );
		}

		return array(
			'input'   => array( 'content' => $content ),
			'options' => array(),
		);
	}

	/**
	 * Validate the alt_text action input.
	 *
	 * @param array $input Raw input.
	 * @return array{input:array,options:array}|WP_Error
	 */
	private function validate_alt_text( array $input ) {
		$attachment_id = isset( $input['attachment_id'] ) ? (int) $input['attachment_id'] : 0;

		if ( $attachment_id < 1 ) {
			return $this->invalid( __( 'Select an image to describe.', 'wp-ai-writer' ) );
		}

		return array(
			'input'   => array( 'attachment_id' => $attachment_id ),
			'options' => array(),
		);
	}

	/**
	 * Load, validate, and base64-encode a local attachment for the provider's image input.
	 *
	 * The attachment ID comes from the request body, so it needs its own object-level capability
	 * check: the route's edit_post check only covers the optional post_id. Without this, any user
	 * with edit_posts could have the site read and describe media they cannot edit.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array{mime:string,data:string}|WP_Error rest_forbidden (403) when the user may not edit
	 *                                                 the attachment, aiwr_image_unreadable (422)
	 *                                                 on any other failure.
	 */
	private function load_attachment_image( $attachment_id ) {
		if ( 'attachment' !== get_post_type( $attachment_id ) ) {
			return $this->image_error();
		}

		if ( ! current_user_can( 'edit_post', $attachment_id ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You are not allowed to use that image.', 'wp-ai-writer' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		$path = get_attached_file( $attachment_id );

		if ( ! $path || ! file_exists( $path ) ) {
			return $this->image_error();
		}

		$filetype = wp_check_filetype( $path );
		$mime     = is_array( $filetype ) ? (string) $filetype['type'] : '';

		if ( ! in_array( $mime, array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp' ), true ) ) {
			return $this->image_error();
		}

		$size = filesize( $path );

		if ( false === $size || $size > 5 * 1024 * 1024 ) {
			return $this->image_error();
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a validated local attachment file, not a remote resource.
		$bytes = file_get_contents( $path );

		if ( false === $bytes ) {
			return $this->image_error();
		}

		return array(
			'mime' => $mime,
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Encoding image bytes for provider transport, not obfuscation.
			'data' => base64_encode( $bytes ),
		);
	}

	/**
	 * The image-unreadable error used for missing, unsupported, or oversized attachments.
	 *
	 * @return WP_Error
	 */
	private function image_error() {
		return new WP_Error(
			'aiwr_image_unreadable',
			__( 'That image could not be read. It may be missing, too large, or an unsupported type.', 'wp-ai-writer' ),
			array( 'status' => 422 )
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
			case 'rewrite':
				return array( 'html' => wp_kses_post( $text ) );
			case 'seo':
				return $this->shape_seo( $text );
			case 'excerpt':
				return array( 'excerpt' => trim( wp_strip_all_tags( $text ) ) );
			case 'alt_text':
				return array( 'alt_text' => $this->truncate( sanitize_text_field( wp_strip_all_tags( $text ) ), 150 ) );
		}

		return array();
	}

	/**
	 * Parse the provider's seo output into a truncated title and meta description.
	 *
	 * @param string $text Provider text (expected to contain a JSON object).
	 * @return array{seo_title:string,meta_description:string}
	 */
	private function shape_seo( $text ) {
		$data  = json_decode( $this->extract_json( $text ), true );
		$title = is_array( $data ) && isset( $data['seo_title'] ) ? (string) $data['seo_title'] : '';
		$desc  = is_array( $data ) && isset( $data['meta_description'] ) ? (string) $data['meta_description'] : '';

		if ( ! is_array( $data ) ) {
			$lines = preg_split( '/\r?\n/', trim( wp_strip_all_tags( $text ) ) );
			$title = isset( $lines[0] ) ? $lines[0] : '';
			$desc  = count( $lines ) > 1 ? trim( implode( ' ', array_slice( $lines, 1 ) ) ) : '';
		}

		return array(
			'seo_title'        => $this->truncate( sanitize_text_field( $title ), 60 ),
			'meta_description' => $this->truncate( sanitize_text_field( $desc ), 160 ),
		);
	}

	/**
	 * Extract the first JSON object from a string, tolerating surrounding text.
	 *
	 * @param string $text Text possibly wrapping a JSON object.
	 * @return string JSON substring, or the original text.
	 */
	private function extract_json( $text ) {
		$start = strpos( $text, '{' );
		$end   = strrpos( $text, '}' );

		if ( false === $start || false === $end || $end < $start ) {
			return $text;
		}

		return substr( $text, $start, $end - $start + 1 );
	}

	/**
	 * Trim a string to a maximum character length.
	 *
	 * @param string $text  Text.
	 * @param int    $limit Maximum length.
	 * @return string
	 */
	private function truncate( $text, $limit ) {
		$text = trim( $text );

		return mb_strlen( $text ) > $limit ? trim( mb_substr( $text, 0, $limit ) ) : $text;
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
