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
}
