<?php
/**
 * Provider HTTP client: non-streaming request, streaming relay, capability check.
 *
 * Everything provider-specific lives here and nowhere else: the endpoint, the auth header, the
 * request body shape, and the response parsing. Keeping it confined means the rest of the plugin,
 * and the browser contract, stay provider-independent.
 *
 * @package WP_AI_Writer
 */

defined( 'ABSPATH' ) || exit;

/**
 * Talks to the configured LLM provider API.
 */
class AIWR_Provider {

	const REQUEST_TIMEOUT = 60;

	/**
	 * Send a non-streaming generation request and return the normalized result.
	 *
	 * @param array $payload {
	 *     Provider payload.
	 *
	 *     @type string $model      Model identifier.
	 *     @type int    $max_tokens Maximum output tokens.
	 *     @type array  $messages   Ordered message array.
	 * }
	 * @return array|WP_Error Array with 'text' and 'usage' keys, or a mapped WP_Error.
	 */
	public static function request( array $payload ) {
		$settings = aiwr_get_settings();

		$response = wp_remote_post(
			AIWR_PROVIDER_ENDPOINT,
			array(
				'headers' => self::headers( $settings['api_key'] ),
				'body'    => wp_json_encode( self::build_body( $payload, false ) ),
				'timeout' => self::REQUEST_TIMEOUT,
			)
		);

		if ( is_wp_error( $response ) ) {
			return self::transport_error( $response );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = wp_remote_retrieve_body( $response );

		if ( $status < 200 || $status >= 300 ) {
			aiwr_log(
				'provider_http_error',
				array(
					'status' => $status,
					'body'   => self::snippet( $body ),
				)
			);
			return self::provider_error();
		}

		$data = json_decode( $body, true );

		if ( ! is_array( $data ) ) {
			aiwr_log( 'provider_bad_json', array( 'body' => self::snippet( $body ) ) );
			return self::provider_error();
		}

		return self::parse_response( $data );
	}

	/**
	 * Build the request body sent to the provider.
	 *
	 * @param array $payload Provider payload.
	 * @param bool  $stream  Whether to request a streamed response.
	 * @return array Request body.
	 */
	private static function build_body( array $payload, $stream ) {
		return array(
			'model'      => (string) $payload['model'],
			'max_tokens' => (int) $payload['max_tokens'],
			'messages'   => $payload['messages'],
			'stream'     => (bool) $stream,
		);
	}

	/**
	 * Request headers, including the stored key in the provider's auth header.
	 *
	 * @param string $key Provider key.
	 * @return array Headers.
	 */
	private static function headers( $key ) {
		return array(
			'Content-Type'  => 'application/json',
			'Accept'        => 'application/json',
			'Authorization' => 'Bearer ' . $key,
		);
	}

	/**
	 * Normalize the provider's JSON response into text and usage figures.
	 *
	 * @param array $data Decoded response.
	 * @return array Array with 'text' and 'usage'.
	 */
	private static function parse_response( array $data ) {
		$content = isset( $data['content'] ) ? $data['content'] : '';

		if ( is_array( $content ) ) {
			$text = '';
			foreach ( $content as $block ) {
				if ( is_array( $block ) && isset( $block['text'] ) ) {
					$text .= (string) $block['text'];
				}
			}
		} else {
			$text = (string) $content;
		}

		return array(
			'text'  => $text,
			'usage' => self::parse_usage( $data ),
		);
	}

	/**
	 * Extract input/output token usage from a provider response or terminal event.
	 *
	 * @param array $data Decoded payload containing a 'usage' object.
	 * @return array{input_tokens:int,output_tokens:int}
	 */
	public static function parse_usage( array $data ) {
		$usage = isset( $data['usage'] ) && is_array( $data['usage'] ) ? $data['usage'] : array();

		return array(
			'input_tokens'  => isset( $usage['input_tokens'] ) ? max( 0, (int) $usage['input_tokens'] ) : 0,
			'output_tokens' => isset( $usage['output_tokens'] ) ? max( 0, (int) $usage['output_tokens'] ) : 0,
		);
	}

	/**
	 * Map an HTTP transport WP_Error to the plugin's error format.
	 *
	 * @param WP_Error $error Transport error.
	 * @return WP_Error Mapped error.
	 */
	private static function transport_error( WP_Error $error ) {
		$message    = strtolower( $error->get_error_message() );
		$is_timeout = false !== strpos( $message, 'timed out' ) || false !== strpos( $message, 'timeout' ) || false !== strpos( $message, 'operation too slow' );

		aiwr_log(
			'provider_transport_error',
			array(
				'code'    => $error->get_error_code(),
				'message' => self::snippet( $error->get_error_message() ),
				'timeout' => $is_timeout,
			)
		);

		if ( $is_timeout ) {
			return new WP_Error(
				'aiwr_provider_timeout',
				__( 'The writing service took too long to respond. Please try again.', 'wp-ai-writer' ),
				array( 'status' => 504 )
			);
		}

		return self::provider_error();
	}

	/**
	 * The generic, user-safe provider error. Detail is logged, never surfaced.
	 *
	 * @return WP_Error Provider error.
	 */
	private static function provider_error() {
		return new WP_Error(
			'aiwr_provider_error',
			__( 'The writing service returned an error. Please try again.', 'wp-ai-writer' ),
			array( 'status' => 502 )
		);
	}

	/**
	 * Truncated snippet for diagnostics; never contains the key.
	 *
	 * @param string $text Text to trim.
	 * @return string Snippet.
	 */
	private static function snippet( $text ) {
		return substr( (string) $text, 0, 500 );
	}

	const STREAM_CONNECT_TIMEOUT = 15;
	const STREAM_TOTAL_TIMEOUT   = 120;

	/**
	 * Whether the request can be streamed on this host.
	 *
	 * Because wp_remote_post buffers whole responses, streaming needs a direct cURL handle and output
	 * that has not started yet.
	 *
	 * @return bool
	 */
	public static function can_stream() {
		return function_exists( 'curl_init' ) && ! headers_sent();
	}

	/**
	 * Stream a generation request, relaying each text fragment through the delta callback.
	 *
	 * This is the one sanctioned direct-cURL path in the plugin: wp_remote_* cannot relay SSE. The
	 * provider's own frames are parsed here and never leak to the browser; the caller emits the
	 * normalized events. Returns the reported usage on success or a mapped WP_Error on failure.
	 *
	 * @param array    $payload  Provider payload (model, max_tokens, messages).
	 * @param callable $on_delta Receives each decoded text fragment as it arrives.
	 * @return array{usage:array}|WP_Error
	 */
	public static function stream( array $payload, callable $on_delta ) {
		$settings = aiwr_get_settings();
		$buffer   = '';
		$state    = array(
			'usage' => array(
				'input_tokens'  => 0,
				'output_tokens' => 0,
			),
			'done'  => false,
			'error' => false,
		);

		$write = static function ( $handle, $chunk ) use ( &$buffer, &$state, $on_delta ) {
			$buffer .= $chunk;
			$pos     = strpos( $buffer, "\n\n" );
			while ( false !== $pos ) {
				$frame  = substr( $buffer, 0, $pos );
				$buffer = substr( $buffer, $pos + 2 );
				self::handle_stream_frame( $frame, $state, $on_delta );
				$pos = strpos( $buffer, "\n\n" );
			}
			return strlen( $chunk );
		};

		// phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_init, WordPress.WP.AlternativeFunctions.curl_curl_setopt_array, WordPress.WP.AlternativeFunctions.curl_curl_exec, WordPress.WP.AlternativeFunctions.curl_curl_errno, WordPress.WP.AlternativeFunctions.curl_curl_getinfo, WordPress.WP.AlternativeFunctions.curl_curl_close -- Direct cURL is the sanctioned exception for SSE relay; wp_remote_* buffers whole responses.
		$handle = curl_init();
		curl_setopt_array(
			$handle,
			array(
				CURLOPT_URL            => AIWR_PROVIDER_ENDPOINT,
				CURLOPT_POST           => true,
				CURLOPT_POSTFIELDS     => wp_json_encode( self::build_body( $payload, true ) ),
				CURLOPT_HTTPHEADER     => self::curl_headers( $settings['api_key'] ),
				CURLOPT_WRITEFUNCTION  => $write,
				CURLOPT_CONNECTTIMEOUT => self::STREAM_CONNECT_TIMEOUT,
				CURLOPT_TIMEOUT        => self::STREAM_TOTAL_TIMEOUT,
				CURLOPT_RETURNTRANSFER => false,
			)
		);

		curl_exec( $handle );
		$errno  = curl_errno( $handle );
		$status = (int) curl_getinfo( $handle, CURLINFO_RESPONSE_CODE );
		curl_close( $handle );
		// phpcs:enable WordPress.WP.AlternativeFunctions.curl_curl_init, WordPress.WP.AlternativeFunctions.curl_curl_setopt_array, WordPress.WP.AlternativeFunctions.curl_curl_exec, WordPress.WP.AlternativeFunctions.curl_curl_errno, WordPress.WP.AlternativeFunctions.curl_curl_getinfo, WordPress.WP.AlternativeFunctions.curl_curl_close

		if ( '' !== trim( $buffer ) ) {
			self::handle_stream_frame( $buffer, $state, $on_delta );
		}

		if ( $errno ) {
			aiwr_log( 'provider_stream_curl_error', array( 'errno' => $errno ) );

			if ( defined( 'CURLE_OPERATION_TIMEDOUT' ) && CURLE_OPERATION_TIMEDOUT === $errno ) {
				return new WP_Error(
					'aiwr_provider_timeout',
					__( 'The writing service took too long to respond. Please try again.', 'wp-ai-writer' ),
					array( 'status' => 504 )
				);
			}

			return self::provider_error();
		}

		if ( $status < 200 || $status >= 300 || $state['error'] || ! $state['done'] ) {
			aiwr_log(
				'provider_stream_failed',
				array(
					'status' => $status,
					'done'   => $state['done'],
				)
			);
			return self::provider_error();
		}

		return array( 'usage' => $state['usage'] );
	}

	/**
	 * Parse one provider SSE frame and dispatch its normalized effect.
	 *
	 * @param string   $frame    Raw frame text (without the trailing blank line).
	 * @param array    $state    Accumulating state (usage, done, error) by reference.
	 * @param callable $on_delta Delta callback.
	 */
	private static function handle_stream_frame( $frame, array &$state, callable $on_delta ) {
		foreach ( explode( "\n", $frame ) as $line ) {
			$line = trim( $line );

			if ( '' === $line || ':' === $line[0] || 0 !== strpos( $line, 'data:' ) ) {
				continue;
			}

			$json = trim( substr( $line, 5 ) );
			$data = json_decode( $json, true );

			if ( ! is_array( $data ) ) {
				continue;
			}

			$type = isset( $data['type'] ) ? $data['type'] : '';

			if ( 'delta' === $type && isset( $data['text'] ) ) {
				$on_delta( (string) $data['text'] );
			} elseif ( 'done' === $type ) {
				$state['usage'] = self::parse_usage( $data );
				$state['done']  = true;
			} elseif ( 'error' === $type ) {
				$state['error'] = true;
			}
		}
	}

	/**
	 * Header lines for the streaming request, including the stored key.
	 *
	 * @param string $key Provider key.
	 * @return string[]
	 */
	private static function curl_headers( $key ) {
		return array(
			'Content-Type: application/json',
			'Accept: text/event-stream',
			'Authorization: Bearer ' . $key,
		);
	}
}
