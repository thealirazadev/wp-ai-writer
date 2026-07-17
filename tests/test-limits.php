<?php
/**
 * Limits tests: rate-limit window, budget, usage counter.
 *
 * @package WP_AI_Writer
 */

/**
 * Rate limit, budget, and usage counter behavior.
 *
 * @covers AIWR_Limits
 */
class AIWR_Test_Limits extends WP_UnitTestCase {

	/**
	 * Reset the usage counter and settings before each test.
	 */
	public function set_up() {
		parent::set_up();
		delete_option( AIWR_Limits::USAGE_OPTION );
		delete_option( AIWR_Settings::OPTION );
	}

	public function test_rate_limit_allows_up_to_the_limit_then_blocks() {
		$user_id = 42;

		for ( $i = 0; $i < 10; $i++ ) {
			$this->assertTrue( AIWR_Limits::check_rate_limit( $user_id ), "Request {$i} should be allowed." );
		}

		$blocked = AIWR_Limits::check_rate_limit( $user_id );
		$this->assertWPError( $blocked );
		$this->assertSame( 'aiwr_rate_limited', $blocked->get_error_code() );
		$this->assertSame( 429, $blocked->get_error_data()['status'] );
	}

	public function test_rate_limit_is_per_user() {
		$first  = 1;
		$second = 2;

		for ( $i = 0; $i < 10; $i++ ) {
			AIWR_Limits::check_rate_limit( $first );
		}

		$this->assertWPError( AIWR_Limits::check_rate_limit( $first ) );
		$this->assertTrue( AIWR_Limits::check_rate_limit( $second ) );
	}

	public function test_rate_limit_resets_after_the_window() {
		$user_id = 7;

		set_transient(
			AIWR_Limits::RATE_PREFIX . $user_id,
			array(
				'count'        => 10,
				'window_start' => time() - ( AIWR_Limits::RATE_WINDOW_SECONDS + 1 ),
			),
			AIWR_Limits::RATE_WINDOW_SECONDS
		);

		$this->assertTrue( AIWR_Limits::check_rate_limit( $user_id ) );
	}

	public function test_rate_limit_can_be_disabled_by_filter() {
		add_filter( 'aiwr_requests_per_minute', '__return_zero' );

		for ( $i = 0; $i < 25; $i++ ) {
			$this->assertTrue( AIWR_Limits::check_rate_limit( 99 ) );
		}

		remove_filter( 'aiwr_requests_per_minute', '__return_zero' );
	}

	public function test_budget_is_uncapped_when_zero() {
		update_option( AIWR_Settings::OPTION, array( 'monthly_budget_tokens' => 0 ) );
		update_option(
			AIWR_Limits::USAGE_OPTION,
			array(
				'month'         => gmdate( 'Y-m' ),
				'input_tokens'  => 999999,
				'output_tokens' => 999999,
			)
		);

		$this->assertTrue( AIWR_Limits::check_budget() );
	}

	public function test_budget_exhausted_returns_error() {
		update_option( AIWR_Settings::OPTION, array( 'monthly_budget_tokens' => 100 ) );
		update_option(
			AIWR_Limits::USAGE_OPTION,
			array(
				'month'         => gmdate( 'Y-m' ),
				'input_tokens'  => 60,
				'output_tokens' => 40,
			)
		);

		$error = AIWR_Limits::check_budget();
		$this->assertWPError( $error );
		$this->assertSame( 'aiwr_budget_exhausted', $error->get_error_code() );
		$this->assertSame( 403, $error->get_error_data()['status'] );
	}

	public function test_budget_allows_below_the_cap() {
		update_option( AIWR_Settings::OPTION, array( 'monthly_budget_tokens' => 100 ) );
		update_option(
			AIWR_Limits::USAGE_OPTION,
			array(
				'month'         => gmdate( 'Y-m' ),
				'input_tokens'  => 40,
				'output_tokens' => 40,
			)
		);

		$this->assertTrue( AIWR_Limits::check_budget() );
	}

	public function test_add_usage_increments_the_counter() {
		AIWR_Limits::add_usage( 100, 50 );
		AIWR_Limits::add_usage( 10, 5 );

		$usage = AIWR_Limits::get_current_usage();
		$this->assertSame( 110, $usage['input_tokens'] );
		$this->assertSame( 55, $usage['output_tokens'] );
		$this->assertSame( gmdate( 'Y-m' ), $usage['month'] );
	}

	public function test_month_rollover_recomputes_from_the_log() {
		update_option(
			AIWR_Limits::USAGE_OPTION,
			array(
				'month'         => '2000-01',
				'input_tokens'  => 5000,
				'output_tokens' => 5000,
			)
		);

		$usage = AIWR_Limits::get_current_usage();
		$this->assertSame( gmdate( 'Y-m' ), $usage['month'] );
		$this->assertSame( 0, $usage['input_tokens'] );
		$this->assertSame( 0, $usage['output_tokens'] );
	}
}
