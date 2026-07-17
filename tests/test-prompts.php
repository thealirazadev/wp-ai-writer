<?php
/**
 * Prompt builder tests.
 *
 * @package WP_AI_Writer
 */

/**
 * Per-action message building, presets, and output caps.
 *
 * @covers AIWR_Prompts
 */
class AIWR_Test_Prompts extends WP_UnitTestCase {

	private function user_content( $built ) {
		foreach ( $built['messages'] as $message ) {
			if ( 'user' === $message['role'] ) {
				return $message['content'];
			}
		}
		return '';
	}

	public function test_draft_builds_system_and_user_messages() {
		$built = AIWR_Prompts::build( 'draft', array( 'prompt' => 'compost guide' ) );

		$this->assertCount( 2, $built['messages'] );
		$this->assertSame( 'system', $built['messages'][0]['role'] );
		$this->assertStringContainsString( 'compost guide', $this->user_content( $built ) );
		$this->assertSame( 1500, $built['max_tokens'] );
	}

	public function test_rewrite_reflects_tone_and_length() {
		$casual = AIWR_Prompts::build(
			'rewrite',
			array( 'text' => 'Original text.' ),
			array(
				'tone'   => 'casual',
				'length' => 'shorter',
			)
		);

		$content = $this->user_content( $casual );
		$this->assertStringContainsString( 'Original text.', $content );
		$this->assertStringContainsString( 'casual', $content );
		$this->assertStringContainsString( 'concise', $content );
	}

	public function test_rewrite_differs_by_tone() {
		$professional = $this->user_content(
			AIWR_Prompts::build(
				'rewrite',
				array( 'text' => 'x' ),
				array(
					'tone'   => 'professional',
					'length' => 'same',
				)
			)
		);
		$confident    = $this->user_content(
			AIWR_Prompts::build(
				'rewrite',
				array( 'text' => 'x' ),
				array(
					'tone'   => 'confident',
					'length' => 'same',
				)
			)
		);

		$this->assertNotSame( $professional, $confident );
	}

	public function test_rewrite_falls_back_on_unknown_presets() {
		$built = AIWR_Prompts::build(
			'rewrite',
			array( 'text' => 'x' ),
			array(
				'tone'   => 'bogus',
				'length' => 'bogus',
			)
		);

		$this->assertStringContainsString( 'professional', $this->user_content( $built ) );
	}

	public function test_seo_requests_json_object() {
		$built = AIWR_Prompts::build(
			'seo',
			array(
				'title'   => 'A title',
				'content' => 'Some content.',
			)
		);

		$this->assertStringContainsString( 'seo_title', $built['messages'][0]['content'] );
		$this->assertStringContainsString( 'meta_description', $built['messages'][0]['content'] );
		$this->assertSame( 400, $built['max_tokens'] );
	}

	public function test_excerpt_builds_summary_prompt() {
		$built = AIWR_Prompts::build( 'excerpt', array( 'content' => 'A long article body.' ) );

		$this->assertStringContainsString( 'A long article body.', $this->user_content( $built ) );
		$this->assertSame( 200, $built['max_tokens'] );
	}

	public function test_unknown_action_returns_error() {
		$built = AIWR_Prompts::build( 'nonsense', array() );

		$this->assertWPError( $built );
		$this->assertSame( 'aiwr_invalid_input', $built->get_error_code() );
	}
}
