<?php
/**
 * Activity log list table.
 *
 * @package WP_AI_Writer
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Paginated, read-only table of activity log rows. Metadata only; no prompt or response content.
 */
class AIWR_Log_Table extends WP_List_Table {

	const PER_PAGE = 20;

	/**
	 * Column definitions.
	 *
	 * @return array
	 */
	public function get_columns() {
		return array(
			'created_at' => __( 'Date', 'wp-ai-writer' ),
			'user'       => __( 'User', 'wp-ai-writer' ),
			'action'     => __( 'Action', 'wp-ai-writer' ),
			'model'      => __( 'Model', 'wp-ai-writer' ),
			'tokens'     => __( 'Tokens (in / out)', 'wp-ai-writer' ),
			'cost'       => __( 'Cost estimate', 'wp-ai-writer' ),
			'status'     => __( 'Status', 'wp-ai-writer' ),
		);
	}

	/**
	 * Load the current page of rows.
	 */
	public function prepare_items() {
		$current = $this->get_pagenum();
		$offset  = ( $current - 1 ) * self::PER_PAGE;

		$this->items           = AIWR_Log::get_page( self::PER_PAGE, $offset );
		$this->_column_headers = array( $this->get_columns(), array(), array() );

		$this->set_pagination_args(
			array(
				'total_items' => AIWR_Log::total_count(),
				'per_page'    => self::PER_PAGE,
			)
		);
	}

	/**
	 * Message for the empty table.
	 */
	public function no_items() {
		esc_html_e( 'No activity has been recorded yet.', 'wp-ai-writer' );
	}

	/**
	 * Default text rendering for a column, escaped.
	 *
	 * @param array  $item        Row.
	 * @param string $column_name Column key.
	 * @return string
	 */
	public function column_default( $item, $column_name ) {
		switch ( $column_name ) {
			case 'created_at':
				return esc_html( get_date_from_gmt( $item['created_at'] ) );
			case 'action':
				return esc_html( $item['action'] );
			case 'model':
				return esc_html( $item['model'] );
			default:
				return '';
		}
	}

	/**
	 * User column.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	public function column_user( $item ) {
		$user = get_userdata( (int) $item['user_id'] );

		return esc_html( $user ? $user->display_name : __( 'Unknown', 'wp-ai-writer' ) );
	}

	/**
	 * Tokens column, with an estimated marker when applicable.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	public function column_tokens( $item ) {
		$text = sprintf(
			'%s / %s',
			number_format_i18n( (int) $item['input_tokens'] ),
			number_format_i18n( (int) $item['output_tokens'] )
		);

		if ( ! empty( $item['tokens_estimated'] ) ) {
			$text .= ' ' . __( '(estimated)', 'wp-ai-writer' );
		}

		return esc_html( $text );
	}

	/**
	 * Cost column, or a note when prices are unset.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	public function column_cost( $item ) {
		if ( null === $item['cost_estimate'] ) {
			return esc_html__( 'Not set', 'wp-ai-writer' );
		}

		return esc_html( number_format_i18n( (float) $item['cost_estimate'], 6 ) );
	}

	/**
	 * Status column with a friendly, text-paired label.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	public function column_status( $item ) {
		$labels = array(
			'success'        => __( 'Succeeded', 'wp-ai-writer' ),
			'provider_error' => __( 'Provider error', 'wp-ai-writer' ),
			'aborted'        => __( 'Aborted', 'wp-ai-writer' ),
		);

		$status = (string) $item['status'];

		return esc_html( isset( $labels[ $status ] ) ? $labels[ $status ] : $status );
	}
}
