<?php
/**
 * Log, migration, and uninstall tests.
 *
 * @package WP_AI_Writer
 */

/**
 * Activity log writes, queries, migration, and uninstall cleanup.
 *
 * @covers AIWR_Log
 */
class AIWR_Test_Log extends WP_UnitTestCase {

	private function insert_row( $overrides = array() ) {
		return AIWR_Log::record(
			array_merge(
				array(
					'user_id'       => 1,
					'action'        => 'draft',
					'model'         => 'a-model',
					'input_tokens'  => 100,
					'output_tokens' => 50,
					'cost_estimate' => 0.5,
					'status'        => 'success',
					'duration_ms'   => 120,
				),
				$overrides
			)
		);
	}

	public function test_record_inserts_a_row() {
		$id = $this->insert_row();

		$this->assertIsInt( $id );
		$this->assertGreaterThan( 0, $id );
		$this->assertSame( 1, $this->count_rows() );
	}

	public function test_record_stores_null_cost() {
		$this->insert_row( array( 'cost_estimate' => null ) );

		global $wpdb;
		$table = AIWR_Log::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$cost = $wpdb->get_var( "SELECT cost_estimate FROM {$table} ORDER BY id DESC LIMIT 1" );

		$this->assertNull( $cost );
	}

	public function test_monthly_sum_totals_current_month() {
		$this->insert_row(
			array(
				'input_tokens'  => 10,
				'output_tokens' => 5,
			)
		);
		$this->insert_row(
			array(
				'input_tokens'  => 20,
				'output_tokens' => 7,
			)
		);

		$sum = AIWR_Log::monthly_sum( gmdate( 'Y-m' ) );

		$this->assertSame( 30, $sum['input_tokens'] );
		$this->assertSame( 12, $sum['output_tokens'] );
	}

	public function test_monthly_sum_ignores_other_months() {
		$this->insert_row( array( 'input_tokens' => 99 ) );

		$sum = AIWR_Log::monthly_sum( '2000-01' );

		$this->assertSame( 0, $sum['input_tokens'] );
	}

	public function test_get_page_orders_newest_first_and_paginates() {
		for ( $i = 0; $i < 25; $i++ ) {
			$this->insert_row( array( 'duration_ms' => $i ) );
		}

		$first  = AIWR_Log::get_page( 20, 0 );
		$second = AIWR_Log::get_page( 20, 20 );

		$this->assertCount( 20, $first );
		$this->assertCount( 5, $second );
		$this->assertGreaterThan(
			(int) $second[0]['id'],
			(int) $first[0]['id'],
			'The first page should hold the newest rows.'
		);
	}

	public function test_total_count() {
		$this->insert_row();
		$this->insert_row();

		$this->assertSame( 2, AIWR_Log::total_count() );
	}

	public function test_prune_older_than_deletes_strictly_older_rows() {
		$old      = $this->insert_row();
		$boundary = $this->insert_row();
		$recent   = $this->insert_row();

		// The cutoff for a 30-day window sits 30 days back. A one-hour margin keeps the boundary
		// assertion clear of sub-second drift while the prune recomputes the cutoff.
		$this->backdate( $old, 30 * DAY_IN_SECONDS + HOUR_IN_SECONDS );
		$this->backdate( $boundary, 30 * DAY_IN_SECONDS - HOUR_IN_SECONDS );

		$deleted = AIWR_Log::prune_older_than( 30 );

		$this->assertSame( 1, $deleted );
		$this->assertFalse( $this->row_exists( $old ) );
		$this->assertTrue( $this->row_exists( $boundary ) );
		$this->assertTrue( $this->row_exists( $recent ) );
	}

	public function test_prune_older_than_zero_keeps_all_rows() {
		$id = $this->insert_row();
		$this->backdate( $id, 3650 * DAY_IN_SECONDS );

		$this->assertSame( 0, AIWR_Log::prune_older_than( 0 ) );
		$this->assertTrue( $this->row_exists( $id ) );
	}

	public function test_scheduled_prune_uses_the_retention_setting() {
		update_option(
			AIWR_Settings::OPTION,
			array_merge( AIWR_Settings::defaults(), array( 'log_retention_days' => 15 ) )
		);

		$old   = $this->insert_row();
		$fresh = $this->insert_row();
		$this->backdate( $old, 20 * DAY_IN_SECONDS );

		AIWR_Log::run_scheduled_prune();

		$this->assertFalse( $this->row_exists( $old ) );
		$this->assertTrue( $this->row_exists( $fresh ) );
	}

	private function backdate( $id, $seconds_ago ) {
		global $wpdb;
		$table = AIWR_Log::table();
		$when  = gmdate( 'Y-m-d H:i:s', time() - (int) $seconds_ago );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET created_at = %s WHERE id = %d", $when, $id ) );
	}

	private function row_exists( $id ) {
		global $wpdb;
		$table = AIWR_Log::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE id = %d", $id ) ) > 0;
	}

	public function test_migration_created_table_and_version() {
		global $wpdb;
		$table = AIWR_Log::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

		$this->assertSame( $table, $found );
		$this->assertSame( AIWR_Migrations::DB_VERSION, get_option( AIWR_Migrations::VERSION_OPTION ) );
	}

	public function test_uninstall_removes_table_options_and_transients() {
		global $wpdb;

		update_option( 'aiwr_settings', array( 'api_key' => 'secret' ) );
		update_option( 'aiwr_usage', array( 'month' => gmdate( 'Y-m' ) ) );
		set_transient(
			'aiwr_rl_9',
			array(
				'count'        => 1,
				'window_start' => time(),
			),
			60
		);

		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'wp-ai-writer/wp-ai-writer.php' );
		}

		// The test case rewrites CREATE/DROP TABLE into their TEMPORARY forms, which would turn the
		// uninstall drop into a no-op against the real table and quietly pass the assertion below.
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );

		require dirname( __DIR__ ) . '/uninstall.php';

		$this->assertFalse( get_option( 'aiwr_settings' ) );
		$this->assertFalse( get_option( 'aiwr_usage' ) );
		$this->assertFalse( get_option( AIWR_Migrations::VERSION_OPTION ) );

		$table = AIWR_Log::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$this->assertNull( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$transients = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( '_transient_aiwr_rl_' ) . '%' ) );
		$this->assertSame( 0, $transients );

		// Recreate the table so later tests keep a clean schema.
		AIWR_Migrations::maybe_upgrade();
	}

	private function count_rows() {
		global $wpdb;
		$table = AIWR_Log::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	}
}
