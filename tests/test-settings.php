<?php
/**
 * Settings page field registration.
 *
 * @package WP_AI_Writer
 */

/**
 * The settings fields must associate every control with its label for accessibility.
 *
 * @covers AIWR_Settings
 */
class AIWR_Test_Settings extends WP_UnitTestCase {

	/**
	 * Collect the label_for value registered for each field on the settings page.
	 *
	 * @return array<string,string> Field id => label_for.
	 */
	private function registered_label_fors() {
		global $wp_settings_fields;

		$wp_settings_fields = array();
		( new AIWR_Settings() )->register_settings();

		$found = array();

		foreach ( $wp_settings_fields[ AIWR_Settings::PAGE ] as $section_fields ) {
			foreach ( $section_fields as $field_id => $field ) {
				$found[ $field_id ] = isset( $field['args']['label_for'] ) ? (string) $field['args']['label_for'] : '';
			}
		}

		return $found;
	}

	public function test_every_settings_field_declares_a_label_for() {
		$label_fors = $this->registered_label_fors();

		$this->assertNotEmpty( $label_fors );

		foreach ( $label_fors as $field_id => $label_for ) {
			$this->assertNotSame( '', $label_for, "Field {$field_id} must declare a label_for for accessibility." );
		}
	}

	public function test_label_for_targets_match_the_rendered_input_ids() {
		$label_fors = $this->registered_label_fors();

		$this->assertSame( 'aiwr_api_key', $label_fors['aiwr_api_key'] );
		$this->assertSame( 'aiwr_model', $label_fors['aiwr_model'] );
		$this->assertSame( 'aiwr_monthly_budget_tokens', $label_fors['aiwr_budget_field'] );
		$this->assertSame( 'aiwr_log_retention_days', $label_fors['aiwr_log_retention'] );

		// The rendered input carries the id the label points at.
		$settings = new AIWR_Settings();

		ob_start();
		$settings->field_api_key();
		$api_html = ob_get_clean();
		$this->assertStringContainsString( 'id="aiwr_api_key"', $api_html );
		$this->assertStringContainsString( 'aria-describedby="aiwr_api_key_desc"', $api_html );
		$this->assertStringContainsString( 'id="aiwr_api_key_desc"', $api_html );
	}
}
