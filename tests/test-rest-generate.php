<?php
/**
 * REST generate route tests: permissions, validation, error codes.
 *
 * The provider HTTP layer is mocked with the pre_http_request filter; no real request is ever made.
 *
 * @package WP_AI_Writer
 */

/**
 * Permission, validation, guardrail, and provider-mapping behavior of the generate route.
 *
 * @covers AIWR_Rest
 */
class AIWR_Test_Rest_Generate extends WP_UnitTestCase {

	/**
	 * Whether the mocked provider was called during a request.
	 *
	 * @var bool
	 */
	private $provider_called = false;

	public function set_up() {
		parent::set_up();

		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init' );

		update_option(
			AIWR_Settings::OPTION,
			array(
				'api_key'               => 'test-secret-key-1234',
				'model'                 => 'configured-model',
				'monthly_budget_tokens' => 500000,
				'price_input_per_mtok'  => 0.0,
				'price_output_per_mtok' => 0.0,
			)
		);

		delete_option( AIWR_Limits::USAGE_OPTION );
		$this->provider_called = false;
	}

	public function tear_down() {
		remove_all_filters( 'pre_http_request' );
		parent::tear_down();
	}

	/**
	 * Mock the provider with a successful body.
	 */
	private function mock_provider_success() {
		add_filter(
			'pre_http_request',
			function () {
				$this->provider_called = true;

				return array(
					'response' => array( 'code' => 200 ),
					'headers'  => array(),
					'body'     => wp_json_encode(
						array(
							'content' => '<p>Composting turns kitchen scraps into rich soil.</p>',
							'usage'   => array(
								'input_tokens'  => 120,
								'output_tokens' => 60,
							),
						)
					),
				);
			},
			10,
			3
		);
	}

	/**
	 * Mock the provider with a specific content payload and 200 status.
	 *
	 * @param string $content Provider content field.
	 */
	private function mock_provider_content( $content ) {
		add_filter(
			'pre_http_request',
			function () use ( $content ) {
				$this->provider_called = true;

				return array(
					'response' => array( 'code' => 200 ),
					'headers'  => array(),
					'body'     => wp_json_encode(
						array(
							'content' => $content,
							'usage'   => array(
								'input_tokens'  => 50,
								'output_tokens' => 25,
							),
						)
					),
				);
			},
			10,
			3
		);
	}

	/**
	 * Mock the provider with a server error.
	 */
	private function mock_provider_error() {
		add_filter(
			'pre_http_request',
			function () {
				$this->provider_called = true;

				return array(
					'response' => array( 'code' => 500 ),
					'headers'  => array(),
					'body'     => 'upstream failure',
				);
			},
			10,
			3
		);
	}

	/**
	 * Build a draft request as the given user.
	 *
	 * @param int|null $user_id User to act as, or null for logged out.
	 * @param array    $body    Request body override.
	 * @return WP_REST_Response
	 */
	private function dispatch_draft( $user_id, array $body = array() ) {
		if ( null === $user_id ) {
			wp_set_current_user( 0 );
		} else {
			wp_set_current_user( $user_id );
		}

		$request = new WP_REST_Request( 'POST', '/aiwr/v1/generate' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			wp_json_encode(
				wp_parse_args(
					$body,
					array(
						'action' => 'draft',
						'input'  => array( 'prompt' => 'Outline a beginners guide to composting' ),
					)
				)
			)
		);

		return rest_get_server()->dispatch( $request );
	}

	private function editor_id() {
		return self::factory()->user->create( array( 'role' => 'editor' ) );
	}

	public function test_logged_out_is_unauthorized() {
		$response = $this->dispatch_draft( null );
		$this->assertSame( 401, $response->get_status() );
	}

	public function test_subscriber_is_forbidden() {
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$response   = $this->dispatch_draft( $subscriber );
		$this->assertSame( 403, $response->get_status() );
	}

	public function test_missing_configuration_returns_conflict() {
		update_option(
			AIWR_Settings::OPTION,
			array(
				'api_key' => '',
				'model'   => '',
			)
		);

		$response = $this->dispatch_draft( $this->editor_id() );
		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'aiwr_not_configured', $response->get_data()['code'] );
	}

	public function test_empty_prompt_is_invalid() {
		$response = $this->dispatch_draft( $this->editor_id(), array( 'input' => array( 'prompt' => '' ) ) );
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'aiwr_invalid_input', $response->get_data()['code'] );
	}

	public function test_oversized_prompt_is_invalid() {
		$response = $this->dispatch_draft( $this->editor_id(), array( 'input' => array( 'prompt' => str_repeat( 'a', 2001 ) ) ) );
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'aiwr_invalid_input', $response->get_data()['code'] );
	}

	public function test_unsupported_action_is_invalid() {
		$response = $this->dispatch_draft( $this->editor_id(), array( 'action' => 'rewrite' ) );
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'aiwr_invalid_input', $response->get_data()['code'] );
	}

	public function test_draft_success_returns_html_and_records_usage() {
		$this->mock_provider_success();
		$editor = $this->editor_id();

		$response = $this->dispatch_draft( $editor );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $this->provider_called );
		$this->assertArrayHasKey( 'html', $data['result'] );
		$this->assertStringContainsString( 'Composting', $data['result']['html'] );
		$this->assertSame( 120, $data['usage']['input_tokens'] );
		$this->assertNull( $data['cost_estimate'] );

		$usage = AIWR_Limits::get_current_usage();
		$this->assertSame( 120, $usage['input_tokens'] );
		$this->assertSame( 60, $usage['output_tokens'] );

		$this->assertSame( 1, $this->log_row_count( 'success' ) );
	}

	public function test_key_never_appears_in_the_response() {
		$this->mock_provider_success();
		$response = $this->dispatch_draft( $this->editor_id() );
		$this->assertStringNotContainsString( 'test-secret-key-1234', wp_json_encode( $response->get_data() ) );
	}

	public function test_budget_exhausted_blocks_the_provider() {
		update_option(
			AIWR_Settings::OPTION,
			array(
				'api_key'               => 'test-secret-key-1234',
				'model'                 => 'configured-model',
				'monthly_budget_tokens' => 100,
			)
		);
		update_option(
			AIWR_Limits::USAGE_OPTION,
			array(
				'month'         => gmdate( 'Y-m' ),
				'input_tokens'  => 100,
				'output_tokens' => 0,
			)
		);
		$this->mock_provider_success();

		$response = $this->dispatch_draft( $this->editor_id() );
		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'aiwr_budget_exhausted', $response->get_data()['code'] );
		$this->assertFalse( $this->provider_called );
	}

	public function test_eleventh_request_is_rate_limited() {
		$this->mock_provider_success();
		$editor = $this->editor_id();

		for ( $i = 0; $i < 10; $i++ ) {
			$this->dispatch_draft( $editor );
		}

		$response = $this->dispatch_draft( $editor );
		$this->assertSame( 429, $response->get_status() );
		$this->assertSame( 'aiwr_rate_limited', $response->get_data()['code'] );
	}

	public function test_provider_error_maps_to_502_and_logs() {
		$this->mock_provider_error();

		$response = $this->dispatch_draft( $this->editor_id() );
		$this->assertSame( 502, $response->get_status() );
		$this->assertSame( 'aiwr_provider_error', $response->get_data()['code'] );
		$this->assertSame( 1, $this->log_row_count( 'provider_error' ) );
	}

	public function test_rewrite_returns_html() {
		$this->mock_provider_content( '<p>Rewritten paragraph.</p>' );

		$response = $this->dispatch_draft(
			$this->editor_id(),
			array(
				'action'  => 'rewrite',
				'input'   => array( 'text' => 'Original paragraph.' ),
				'options' => array(
					'tone'   => 'casual',
					'length' => 'shorter',
				),
			)
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertStringContainsString( 'Rewritten', $response->get_data()['result']['html'] );
	}

	public function test_rewrite_rejects_empty_text() {
		$response = $this->dispatch_draft(
			$this->editor_id(),
			array(
				'action' => 'rewrite',
				'input'  => array( 'text' => '' ),
			)
		);

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'aiwr_invalid_input', $response->get_data()['code'] );
	}

	public function test_rewrite_rejects_invalid_tone() {
		$response = $this->dispatch_draft(
			$this->editor_id(),
			array(
				'action'  => 'rewrite',
				'input'   => array( 'text' => 'Some text.' ),
				'options' => array( 'tone' => 'zany' ),
			)
		);

		$this->assertSame( 400, $response->get_status() );
	}

	public function test_seo_truncates_title_and_description() {
		$this->mock_provider_content(
			wp_json_encode(
				array(
					'seo_title'        => str_repeat( 'A', 120 ),
					'meta_description' => str_repeat( 'B', 300 ),
				)
			)
		);

		$response = $this->dispatch_draft(
			$this->editor_id(),
			array(
				'action' => 'seo',
				'input'  => array(
					'title'   => 'A page',
					'content' => 'Enough content to summarize.',
				),
			)
		);

		$data = $response->get_data();
		$this->assertSame( 200, $response->get_status() );
		$this->assertLessThanOrEqual( 60, mb_strlen( $data['result']['seo_title'] ) );
		$this->assertLessThanOrEqual( 160, mb_strlen( $data['result']['meta_description'] ) );
	}

	public function test_seo_rejects_empty_content() {
		$response = $this->dispatch_draft(
			$this->editor_id(),
			array(
				'action' => 'seo',
				'input'  => array(
					'title'   => 'A page',
					'content' => '',
				),
			)
		);

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'aiwr_invalid_input', $response->get_data()['code'] );
	}

	public function test_excerpt_strips_html() {
		$this->mock_provider_content( '<p>A tidy summary.</p>' );

		$response = $this->dispatch_draft(
			$this->editor_id(),
			array(
				'action' => 'excerpt',
				'input'  => array( 'content' => 'A long body of content to summarize.' ),
			)
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'A tidy summary.', $response->get_data()['result']['excerpt'] );
	}

	public function test_alt_text_rejects_missing_id() {
		$response = $this->dispatch_draft(
			$this->editor_id(),
			array(
				'action' => 'alt_text',
				'input'  => array( 'attachment_id' => 0 ),
			)
		);

		$this->assertSame( 400, $response->get_status() );
	}

	public function test_alt_text_missing_attachment_is_unreadable() {
		$this->mock_provider_content( 'unused' );

		$response = $this->dispatch_draft(
			$this->editor_id(),
			array(
				'action' => 'alt_text',
				'input'  => array( 'attachment_id' => 987654 ),
			)
		);

		$this->assertSame( 422, $response->get_status() );
		$this->assertSame( 'aiwr_image_unreadable', $response->get_data()['code'] );
		$this->assertFalse( $this->provider_called );
	}

	public function test_alt_text_rejects_non_image_attachment() {
		// The basename drives the upload, and wp_tempnam() would hand back a .tmp name that
		// wp_upload_bits() refuses, so no attachment would be created to reject.
		$file = trailingslashit( get_temp_dir() ) . 'aiwr-note.txt';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture on the local filesystem.
		file_put_contents( $file, 'plain text, not an image' );
		$attachment_id = self::factory()->attachment->create_upload_object( $file );

		$this->assertIsInt( $attachment_id );
		$this->assertSame( 'text/plain', get_post_mime_type( $attachment_id ) );

		$response = $this->dispatch_draft(
			$this->editor_id(),
			array(
				'action' => 'alt_text',
				'input'  => array( 'attachment_id' => $attachment_id ),
			)
		);

		$this->assertSame( 422, $response->get_status() );
		$this->assertSame( 'aiwr_image_unreadable', $response->get_data()['code'] );
	}

	public function test_alt_text_rejects_an_attachment_the_user_cannot_edit() {
		$owner = $this->editor_id();
		wp_set_current_user( $owner );
		$attachment_id = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );

		$this->mock_provider_content( 'a description that must never be produced' );

		// A contributor has edit_posts, so it clears the route, but not edit_others_posts.
		$contributor = self::factory()->user->create( array( 'role' => 'contributor' ) );

		$response = $this->dispatch_draft(
			$contributor,
			array(
				'action' => 'alt_text',
				'input'  => array( 'attachment_id' => $attachment_id ),
			)
		);

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'rest_forbidden', $response->get_data()['code'] );
		$this->assertFalse( $this->provider_called );
	}

	public function test_alt_text_success_truncates_to_150() {
		$attachment_id = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
		$this->mock_provider_content( str_repeat( 'A vivid description of the scene. ', 20 ) );

		$response = $this->dispatch_draft(
			$this->editor_id(),
			array(
				'action' => 'alt_text',
				'input'  => array( 'attachment_id' => $attachment_id ),
			)
		);

		$data = $response->get_data();
		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $this->provider_called );
		$this->assertLessThanOrEqual( 150, mb_strlen( $data['result']['alt_text'] ) );
	}

	/**
	 * Count log rows with a given status.
	 *
	 * @param string $status Status value.
	 * @return int
	 */
	private function log_row_count( $status ) {
		global $wpdb;
		$table = AIWR_Log::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status = %s", $status ) );
	}
}
