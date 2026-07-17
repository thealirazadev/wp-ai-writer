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
	public static function build( $action, array $input, array $options = array() ) {
		switch ( $action ) {
			case 'draft':
				return self::draft( $input );
			case 'rewrite':
				return self::rewrite( $input, $options );
			case 'seo':
				return self::seo( $input );
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
	 * Rewrite the selected block text with a tone and length preset.
	 *
	 * @param array $input   Input with a 'text' key.
	 * @param array $options Options with 'tone' and 'length' keys.
	 * @return array{messages:array,max_tokens:int}
	 */
	private static function rewrite( array $input, array $options ) {
		$tones = array(
			'professional' => 'a professional, polished tone',
			'friendly'     => 'a warm, friendly tone',
			'casual'       => 'a relaxed, casual tone',
			'confident'    => 'a confident, assertive tone',
		);

		$lengths = array(
			'shorter' => 'noticeably more concise than the original',
			'same'    => 'about the same length as the original',
			'longer'  => 'somewhat more detailed than the original',
		);

		$tone   = isset( $tones[ $options['tone'] ] ) ? $tones[ $options['tone'] ] : $tones['professional'];
		$length = isset( $lengths[ $options['length'] ] ) ? $lengths[ $options['length'] ] : $lengths['same'];

		$system = 'You are a writing assistant inside a website editor. Rewrite the user text, preserving its meaning. Return only simple HTML using <p>, <h2>, <h3>, <ul>, <ol>, <li>, and <blockquote> tags, with no document wrapper, styles, scripts, or commentary.';

		$user = sprintf(
			"Rewrite the following in %s, and make it %s:\n\n%s",
			$tone,
			$length,
			$input['text']
		);

		return array(
			'messages'   => self::messages( $system, $user ),
			'max_tokens' => 1500,
		);
	}

	/**
	 * Produce an SEO title and meta description from the post title and content.
	 *
	 * @param array $input Input with 'title' and 'content' keys.
	 * @return array{messages:array,max_tokens:int}
	 */
	private static function seo( array $input ) {
		$system = 'You are an SEO assistant. From the given page title and content, write a compelling SEO title of at most 60 characters and a meta description of at most 160 characters. Respond with a single JSON object exactly in the form {"seo_title": "...", "meta_description": "..."} and nothing else.';

		$user = sprintf(
			"Title: %s\n\nContent:\n%s",
			$input['title'],
			$input['content']
		);

		return array(
			'messages'   => self::messages( $system, $user ),
			'max_tokens' => 400,
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
