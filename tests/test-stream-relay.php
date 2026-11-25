<?php
/**
 * Streaming relay sanitization tests.
 *
 * @package WP_AI_Writer
 */

/**
 * Per-fragment sanitization of streamed provider text.
 *
 * @covers AIWR_Rest::sanitize_stream_chunk
 */
class AIWR_Test_Stream_Relay extends WP_UnitTestCase {

	private function relay( array $fragments ) {
		$pending = '';
		$out     = '';

		foreach ( $fragments as $fragment ) {
			$out .= AIWR_Rest::sanitize_stream_chunk( $pending, $fragment );
		}

		return $out . AIWR_Rest::sanitize_stream_chunk( $pending, '', true );
	}

	public function test_tags_split_across_fragments_survive_intact() {
		$full      = '<p>Hello <strong>world</strong></p><ul><li>One</li></ul>';
		$fragments = array( '<p>Hel', 'lo <st', 'rong>world</', 'strong></p><u', 'l><li>One</li></ul>' );

		$this->assertSame( wp_kses_post( $full ), $this->relay( $fragments ) );
	}

	public function test_entities_split_across_fragments_survive_intact() {
		$full = '<p>Salt &amp; pepper</p>';

		$this->assertSame( wp_kses_post( $full ), $this->relay( array( '<p>Salt &am', 'p; pepper</p>' ) ) );
	}

	public function test_unfinished_tag_is_held_back_until_complete() {
		$pending = '';

		$this->assertSame( '<p>Hi', AIWR_Rest::sanitize_stream_chunk( $pending, '<p>Hi</' ) );
		$this->assertSame( '</', $pending );
		$this->assertSame( '</p>', AIWR_Rest::sanitize_stream_chunk( $pending, 'p>' ) );
		$this->assertSame( '', $pending );
	}

	public function test_disallowed_markup_is_still_stripped_when_split() {
		$out = $this->relay( array( '<p>ok</p><scr', 'ipt>alert(1)</scr', 'ipt>' ) );

		$this->assertStringNotContainsString( '<script', $out );
		$this->assertStringContainsString( '<p>ok</p>', $out );
	}

	public function test_final_flush_sanitizes_a_dangling_tail() {
		$out = $this->relay( array( '<p>end</p><scr' ) );

		$this->assertStringNotContainsString( '<scr', $out );
		$this->assertStringContainsString( '<p>end</p>', $out );
	}
}
