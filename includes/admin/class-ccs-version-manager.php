<?php
/**
 * Canvas Course Sync Version Manager
 *
 * This class helps ensure version consistency across all plugin files
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CCS_Version_Manager {

	/**
	 * Get current version from main plugin file
	 */
	public static function get_current_version() {
		return CCS_VERSION;
	}

	/**
	 * Check if all version references are consistent
	 */
	public static function check_version_consistency() {
		$issues = array();

		// Check plugin header version
		$plugin_data = get_plugin_data( CCS_PLUGIN_FILE );
		if ( $plugin_data['Version'] !== CCS_VERSION ) {
			$issues[] = sprintf(
				'Plugin header version (%s) does not match CCS_VERSION constant (%s)',
				$plugin_data['Version'],
				CCS_VERSION
			);
		}

		// Check readme.txt stable tag (if file exists)
		$readme_path = CCS_PLUGIN_DIR . 'readme.txt';
		if ( file_exists( $readme_path ) ) {
			$readme_content = file_get_contents( $readme_path );
			if ( preg_match( '/Stable tag:\s*(.+)/', $readme_content, $matches ) ) {
				$readme_version = trim( $matches[1] );
				if ( $readme_version !== CCS_VERSION ) {
					$issues[] = sprintf(
						'Readme.txt stable tag (%s) does not match CCS_VERSION constant (%s)',
						$readme_version,
						CCS_VERSION
					);
				}
			}
		}

		return $issues;
	}

	/**
	 * Display admin notice if versions are inconsistent
	 */
	public static function maybe_show_version_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$issues = self::check_version_consistency();

		if ( ! empty( $issues ) ) {
			echo '<div class="notice notice-warning">';
			echo '<p><strong>Canvas Course Sync Version Inconsistency:</strong></p>';
			echo '<ul>';
			foreach ( $issues as $issue ) {
				echo '<li>' . esc_html( $issue ) . '</li>';
			}
			echo '</ul>';
			echo '<p>Please update all version references before releasing.</p>';
			echo '</div>';
		}
	}
}

// Hook to show version notice in admin
add_action( 'admin_notices', array( 'CCS_Version_Manager', 'maybe_show_version_notice' ) );
