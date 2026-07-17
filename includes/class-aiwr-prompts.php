<?php
/**
 * Per-action prompt builders.
 *
 * Each action produces a messages array plus a maximum output-token count. Five actions share this
 * one builder; there are no per-action classes.
 *
 * @package WP_AI_Writer
 */

defined( 'ABSPATH' ) || exit;

/**
 * Builds provider messages for each supported action.
 */
class AIWR_Prompts {

	/**
	 * Build the provider messages and output cap for an action.
	 *
	 * @param string $action  Action name.
	 * @param array  $input   Validated, sanitized input for the action.
	 * @param array  $options Validated options (only rewrite reads these).
	 * @return array{messages:array,max_tokens:int}|WP_Error
	 */
	public static function build( $action, array $input, array $options = array() ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- $options is read by the rewrite action in a later phase.
		switch ( $action ) {
			case 'draft':
				return self::draft( $input );
		}

		return new WP_Error(
			'aiwr_invalid_input',
			__( 'That action is not available.', 'wp-ai-writer' ),
			array( 'status' => 400 )
		);
	}

	/**
	 * Draft content from a free-text prompt.
	 *
	 * @param array $input Input with a 'prompt' key.
	 * @return array{messages:array,max_tokens:int}
	 */
	private static function draft( array $input ) {
		$system = 'You are a writing assistant inside a website editor. Produce clean body content as simple HTML using only <p>, <h2>, <h3>, <ul>, <ol>, <li>, and <blockquote> tags. Do not include a document wrapper, headings above <h2>, inline styles, scripts, or images.';

		$user = "Write content for the following request:\n\n" . $input['prompt'];

		return array(
			'messages'   => self::messages( $system, $user ),
			'max_tokens' => 1500,
		);
	}

	/**
	 * Assemble a system + user messages array.
	 *
	 * @param string $system System framing.
	 * @param string $user   User content.
	 * @return array Messages.
	 */
	private static function messages( $system, $user ) {
		return array(
			array(
				'role'    => 'system',
				'content' => $system,
			),
			array(
				'role'    => 'user',
				'content' => $user,
			),
		);
	}
}
