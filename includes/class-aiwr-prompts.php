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
				return self::draft( $input, $options );
			case 'rewrite':
				return self::rewrite( $input, $options );
			case 'seo':
				return self::seo( $input );
			case 'excerpt':
				return self::excerpt( $input );
			case 'alt_text':
				return self::alt_text( $input );
		}

		return new WP_Error(
			'aiwr_invalid_input',
			__( 'That action is not available.', 'wp-ai-writer' ),
			array( 'status' => 400 )
		);
	}

	/**
	 * The built-in tone presets: the single allowlist shared by the draft and rewrite actions.
	 *
	 * Each key maps to the prompt fragment injected server-side. These fragments are model-facing
	 * instructions, not user-facing UI strings, so they are not translated; the human-readable
	 * labels live in the settings field and the sidebar.
	 *
	 * @return array<string,string> Tone key => prompt fragment.
	 */
	public static function tones() {
		return array(
			'professional' => 'a professional, polished tone',
			'friendly'     => 'a warm, friendly tone',
			'concise'      => 'a concise, to-the-point tone',
			'persuasive'   => 'a persuasive, compelling tone',
			'casual'       => 'a relaxed, casual tone',
			'confident'    => 'a confident, assertive tone',
		);
	}

	/**
	 * The allowed tone keys.
	 *
	 * @return string[]
	 */
	public static function tone_keys() {
		return array_keys( self::tones() );
	}

	/**
	 * Whether a tone key is in the allowlist.
	 *
	 * @param string $tone Tone key.
	 * @return bool
	 */
	public static function is_valid_tone( $tone ) {
		return is_string( $tone ) && isset( self::tones()[ $tone ] );
	}

	/**
	 * The fallback tone used when none is supplied or a supplied one is unknown.
	 *
	 * @return string
	 */
	public static function default_tone() {
		return 'professional';
	}

	/**
	 * Resolve a tone key to its prompt fragment, falling back to the default tone.
	 *
	 * @param string $tone Tone key.
	 * @return string Prompt fragment.
	 */
	private static function tone_fragment( $tone ) {
		$tones = self::tones();

		return isset( $tones[ $tone ] ) ? $tones[ $tone ] : $tones[ self::default_tone() ];
	}

	/**
	 * Draft content from a free-text prompt, in the chosen tone.
	 *
	 * @param array $input   Input with a 'prompt' key.
	 * @param array $options Options with an optional 'tone' key.
	 * @return array{messages:array,max_tokens:int}
	 */
	private static function draft( array $input, array $options = array() ) {
		$tone = self::tone_fragment( isset( $options['tone'] ) ? $options['tone'] : self::default_tone() );

		$system = 'You are a writing assistant inside a website editor. Produce clean body content as simple HTML using only <p>, <h2>, <h3>, <ul>, <ol>, <li>, and <blockquote> tags. Do not include a document wrapper, headings above <h2>, inline styles, scripts, or images.';

		$user = sprintf(
			"Write content for the following request in %s:\n\n%s",
			$tone,
			$input['prompt']
		);

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
		$lengths = array(
			'shorter' => 'noticeably more concise than the original',
			'same'    => 'about the same length as the original',
			'longer'  => 'somewhat more detailed than the original',
		);

		$tone   = self::tone_fragment( isset( $options['tone'] ) ? $options['tone'] : self::default_tone() );
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
	 * Summarize the post content into a short excerpt.
	 *
	 * @param array $input Input with a 'content' key.
	 * @return array{messages:array,max_tokens:int}
	 */
	private static function excerpt( array $input ) {
		$system = 'You are a writing assistant. Summarize the given content into a single concise excerpt of about 40 to 55 words. Return plain text only, with no HTML, quotation marks, or preamble.';

		$user = "Summarize this content into an excerpt:\n\n" . $input['content'];

		return array(
			'messages'   => self::messages( $system, $user ),
			'max_tokens' => 200,
		);
	}

	/**
	 * Describe an image for alt text using the provider's image input.
	 *
	 * @param array $input Loaded image with 'mime' and 'data' (base64) keys.
	 * @return array{messages:array,max_tokens:int}
	 */
	private static function alt_text( array $input ) {
		$system = 'You are an accessibility assistant. Describe the given image for use as alt text in fewer than 150 characters. Return only the description as plain text, with no quotation marks or preamble.';

		$user = array(
			array(
				'type' => 'text',
				'text' => 'Describe this image for alt text.',
			),
			array(
				'type' => 'image',
				'mime' => $input['mime'],
				'data' => $input['data'],
			),
		);

		return array(
			'messages'   => array(
				array(
					'role'    => 'system',
					'content' => $system,
				),
				array(
					'role'    => 'user',
					'content' => $user,
				),
			),
			'max_tokens' => 100,
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
