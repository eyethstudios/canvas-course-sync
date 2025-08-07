<?php
/**
 * AJAX Handlers for Canvas Course Sync - Fixed Version
 *
 * @package Canvas_Course_Sync
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Safe error logging for AJAX handlers
 */
function ccs_ajax_log_error( $message ) {
	if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
		error_log( 'CCS AJAX Error: ' . $message );
	}
}

/**
 * Safe AJAX response wrapper
 */
function ccs_ajax_safe_response( $callback ) {
	try {
		// Set error handler
		set_error_handler( function( $severity, $message, $file, $line ) {
			if ( strpos( $file, 'canvas-course-sync' ) !== false ) {
				ccs_ajax_log_error( "PHP Error: $message in $file:$line" );
			}
		});

		call_user_func( $callback );

	} catch ( Exception $e ) {
		ccs_ajax_log_error( 'Exception in AJAX handler: ' . $e->getMessage() );
		wp_send_json_error( array( 'message' => 'An internal error occurred. Please try again.' ) );
	} catch ( Error $e ) {
		ccs_ajax_log_error( 'Fatal error in AJAX handler: ' . $e->getMessage() );
		wp_send_json_error( array( 'message' => 'A fatal error occurred. Please contact support.' ) );
	} finally {
		restore_error_handler();
	}
}

/**
 * Test Canvas API connection - FIXED
 */
function ccs_test_connection_handler() {
	ccs_ajax_safe_response( function() {
		// Verify nonce
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'ccs_test_connection' ) ) {
			wp_send_json_error( array( 'message' => 'Security check failed' ) );
			return;
		}

		// Check permissions
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions' ) );
			return;
		}

		$canvas_course_sync = canvas_course_sync();
		if ( ! $canvas_course_sync ) {
			wp_send_json_error( array( 'message' => 'Plugin not properly initialized' ) );
			return;
		}

		if ( ! isset( $canvas_course_sync->catalogApi ) || ! $canvas_course_sync->catalogApi ) {
			wp_send_json_error( array( 'message' => 'Catalog API not initialized' ) );
			return;
		}

		$result = $canvas_course_sync->catalogApi->test_connection();

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		} else {
			wp_send_json_success( array( 'message' => 'Connection successful! Catalog API is working properly.' ) );
		}
	});
}

/**
 * Get courses from Canvas - FIXED
 */
function ccs_get_courses_handler() {
	ccs_ajax_safe_response( function() {
		// Verify nonce
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'ccs_get_courses' ) ) {
			wp_send_json_error( array( 'message' => 'Security check failed' ) );
			return;
		}

		// Check permissions
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions' ) );
			return;
		}

		$canvas_course_sync = canvas_course_sync();
		if ( ! $canvas_course_sync ) {
			wp_send_json_error( array( 'message' => 'Plugin not properly initialized' ) );
			return;
		}

		if ( ! isset( $canvas_course_sync->catalogApi ) || ! $canvas_course_sync->catalogApi ) {
			wp_send_json_error( array( 'message' => 'Canvas API not initialized' ) );
			return;
		}

		// Get courses from Canvas with timeout protection
		$courses = $canvas_course_sync->catalogApi->get_courses();

		if ( is_wp_error( $courses ) ) {
			wp_send_json_error( array( 'message' => $courses->get_error_message() ) );
			return;
		}

		if ( ! is_array( $courses ) ) {
			wp_send_json_error( array( 'message' => 'Invalid courses data received' ) );
			return;
		}

		// Process courses safely
		$processed_courses = array();
		$max_courses = 100; // Limit to prevent memory issues
		$course_count = 0;

		foreach ( $courses as $course ) {
			if ( $course_count >= $max_courses ) {
				break;
			}

			// Skip invalid courses
			if ( empty( $course['title'] ) ) {
				continue;
			}

			// Safely determine course status
			$status = 'available';
			$exists_check = array( 'exists' => false );

			if ( isset( $canvas_course_sync->importer ) && $canvas_course_sync->importer ) {
				try {
					$exists_check = $canvas_course_sync->importer->course_exists( 
						isset( $course['id'] ) ? intval( $course['id'] ) : 0, 
						$course['title'] 
					);

					if ( $exists_check && isset( $exists_check['exists'] ) && $exists_check['exists'] ) {
						$status = 'synced';
					}
				} catch ( Exception $e ) {
					ccs_ajax_log_error( 'Error checking course existence: ' . $e->getMessage() );
				}
			}

			$processed_courses[] = array(
				'id' => isset( $course['id'] ) ? intval( $course['id'] ) : 0,
				'canvas_id' => isset( $course['canvas_course']['id'] ) ? intval( $course['canvas_course']['id'] ) : 0,
				'name' => sanitize_text_field( $course['title'] ),
				'course_code' => isset( $course['course_code'] ) ? sanitize_text_field( $course['course_code'] ) : '',
				'status' => $status,
			);

			$course_count++;
		}

		wp_send_json_success( array(
			'courses' => $processed_courses,
			'total' => count( $processed_courses ),
		) );
	});
}

/**
 * Sync selected courses - FIXED
 */
function ccs_sync_courses_handler() {
	ccs_ajax_safe_response( function() {
		// Increase time limit for sync operations
		if ( ! ini_get( 'safe_mode' ) ) {
			set_time_limit( 300 ); // 5 minutes
		}

		// Verify nonce
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'ccs_sync_courses' ) ) {
			wp_send_json_error( array( 'message' => 'Security check failed' ) );
			return;
		}

		// Check permissions
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions' ) );
			return;
		}

		// Get course IDs
		$course_ids = isset( $_POST['course_ids'] ) && is_array( $_POST['course_ids'] ) 
			? array_map( 'intval', $_POST['course_ids'] ) 
			: array();

		if ( empty( $course_ids ) ) {
			wp_send_json_error( array( 'message' => 'No course IDs provided' ) );
			return;
		}

		// Limit number of courses to prevent timeouts
		if ( count( $course_ids ) > 20 ) {
			wp_send_json_error( array( 'message' => 'Too many courses selected. Please select 20 or fewer courses at a time.' ) );
			return;
		}

		$canvas_course_sync = canvas_course_sync();
		if ( ! $canvas_course_sync || ! isset( $canvas_course_sync->importer ) || ! $canvas_course_sync->importer ) {
			wp_send_json_error( array( 'message' => 'Importer not initialized' ) );
			return;
		}

		// Set sync status
		set_transient( 'ccs_sync_status', array(
			'status' => 'Starting sync...',
			'processed' => 0,
			'total' => count( $course_ids ),
		), 300 );

		// Import courses with error handling
		$result = $canvas_course_sync->importer->import_courses( $course_ids );

		// Clear sync status
		delete_transient( 'ccs_sync_status' );

		if ( ! is_array( $result ) ) {
			wp_send_json_error( array( 'message' => 'Invalid sync result' ) );
			return;
		}

		wp_send_json_success( $result );
	});
}

/**
 * Get sync status - FIXED
 */
function ccs_sync_status_handler() {
	ccs_ajax_safe_response( function() {
		// Verify nonce
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'ccs_sync_status' ) ) {
			wp_send_json_error( array( 'message' => 'Security check failed' ) );
			return;
		}

		$status = get_transient( 'ccs_sync_status' );

		if ( $status && is_array( $status ) ) {
			wp_send_json_success( $status );
		} else {
			wp_send_json_success( array(
				'status' => 'No sync in progress',
				'processed' => 0,
				'total' => 0,
			) );
		}
	});
}

/**
 * Clear logs - FIXED
 */
function ccs_clear_logs_handler() {
	ccs_ajax_safe_response( function() {
		// Verify nonce
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'ccs_clear_logs' ) ) {
			wp_send_json_error( array( 'message' => 'Security check failed' ) );
			return;
		}

		// Check permissions
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions' ) );
			return;
		}

		$canvas_course_sync = canvas_course_sync();
		if ( ! $canvas_course_sync || ! isset( $canvas_course_sync->logger ) || ! $canvas_course_sync->logger ) {
			wp_send_json_error( array( 'message' => 'Logger not initialized' ) );
			return;
		}

		$canvas_course_sync->logger->clear_logs();
		wp_send_json_success( array( 'message' => 'Logs cleared successfully' ) );
	});
}

/**
 * Refresh logs - FIXED
 */
function ccs_refresh_logs_handler() {
	ccs_ajax_safe_response( function() {
		// Verify nonce
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'ccs_refresh_logs' ) ) {
			wp_send_json_error( array( 'message' => 'Security check failed' ) );
			return;
		}

		// Check permissions
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions' ) );
			return;
		}

		$canvas_course_sync = canvas_course_sync();
		if ( ! $canvas_course_sync || ! isset( $canvas_course_sync->logger ) || ! $canvas_course_sync->logger ) {
			wp_send_json_error( array( 'message' => 'Logger not initialized' ) );
			return;
		}

		$logs = $canvas_course_sync->logger->get_recent_logs( 20 );

		ob_start();
		if ( ! empty( $logs ) && is_array( $logs ) ) {
			echo '<table class="wp-list-table widefat fixed striped">';
			echo '<thead><tr>';
			echo '<th scope="col" style="width: 150px;">' . esc_html__( 'Timestamp', 'canvas-course-sync' ) . '</th>';
			echo '<th scope="col" style="width: 80px;">' . esc_html__( 'Level', 'canvas-course-sync' ) . '</th>';
			echo '<th scope="col">' . esc_html__( 'Message', 'canvas-course-sync' ) . '</th>';
			echo '</tr></thead><tbody>';

			foreach ( $logs as $log ) {
				if ( ! is_object( $log ) ) continue;
				
				echo '<tr>';
				echo '<td>' . esc_html( isset( $log->timestamp ) ? mysql2date( 'Y-m-d H:i:s', $log->timestamp ) : 'N/A' ) . '</td>';
				echo '<td><span class="ccs-log-level ccs-log-level-' . esc_attr( $log->level ?? 'info' ) . '">' . esc_html( strtoupper( $log->level ?? 'INFO' ) ) . '</span></td>';
				echo '<td>' . esc_html( $log->message ?? 'No message' ) . '</td>';
				echo '</tr>';
			}

			echo '</tbody></table>';
		} else {
			echo '<div class="notice notice-info"><p>' . esc_html__( 'No logs found.', 'canvas-course-sync' ) . '</p></div>';
		}
		$html = ob_get_clean();

		wp_send_json_success( array( 'html' => $html ) );
	});
}

/**
 * Run auto sync - FIXED
 */
function ccs_run_auto_sync_handler() {
	ccs_ajax_safe_response( function() {
		// Increase time limit
		if ( ! ini_get( 'safe_mode' ) ) {
			set_time_limit( 300 );
		}

		// Verify nonce
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'ccs_run_auto_sync' ) ) {
			wp_send_json_error( array( 'message' => 'Security check failed' ) );
			return;
		}

		// Check permissions
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions' ) );
			return;
		}

		$canvas_course_sync = canvas_course_sync();
		if ( ! $canvas_course_sync || ! isset( $canvas_course_sync->scheduler ) || ! $canvas_course_sync->scheduler ) {
			wp_send_json_error( array( 'message' => 'Scheduler not initialized' ) );
			return;
		}

		$result = $canvas_course_sync->scheduler->run_auto_sync();

		if ( $result ) {
			wp_send_json_success( array( 'message' => 'Auto-sync completed successfully' ) );
		} else {
			wp_send_json_error( array( 'message' => 'Auto-sync failed' ) );
		}
	});
}

/**
 * Omit courses from auto-sync - FIXED
 */
function ccs_omit_courses_handler() {
	ccs_ajax_safe_response( function() {
		// Verify nonce
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'ccs_omit_courses' ) ) {
			wp_send_json_error( array( 'message' => 'Security check failed' ) );
			return;
		}

		// Check permissions
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions' ) );
			return;
		}

		$course_ids = isset( $_POST['course_ids'] ) && is_array( $_POST['course_ids'] ) 
			? array_map( 'intval', $_POST['course_ids'] ) 
			: array();

		if ( empty( $course_ids ) ) {
			wp_send_json_error( array( 'message' => 'No course IDs provided' ) );
			return;
		}

		// Get current omitted courses
		$omitted_courses = get_option( 'ccs_omitted_courses', array() );
		if ( ! is_array( $omitted_courses ) ) {
			$omitted_courses = array();
		}

		// Add new courses to omitted list
		$omitted_courses = array_unique( array_merge( $omitted_courses, $course_ids ) );

		// Save updated list
		update_option( 'ccs_omitted_courses', $omitted_courses );

		wp_send_json_success( array(
			'message' => count( $course_ids ) . ' course(s) omitted from auto-sync',
			'omitted_count' => count( $omitted_courses ),
		) );
	});
}

/**
 * Restore omitted courses - FIXED
 */
function ccs_restore_omitted_handler() {
	ccs_ajax_safe_response( function() {
		// Verify nonce
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'ccs_restore_omitted' ) ) {
			wp_send_json_error( array( 'message' => 'Security check failed' ) );
			return;
		}

		// Check permissions
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions' ) );
			return;
		}

		// Clear omitted courses list
		delete_option( 'ccs_omitted_courses' );

		wp_send_json_success( array( 'message' => 'All omitted courses restored for auto-sync' ) );
	});
}

/**
 * Cleanup deleted courses - FIXED
 */
function ccs_cleanup_deleted_courses_handler() {
	ccs_ajax_safe_response( function() {
		// Verify nonce
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'ccs_cleanup_deleted' ) ) {
			wp_send_json_error( array( 'message' => 'Security check failed' ) );
			return;
		}

		// Check permissions
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions' ) );
			return;
		}

		// Get database manager instance safely
		$canvas_course_sync = canvas_course_sync();
		$logger = null;
		
		if ( $canvas_course_sync && isset( $canvas_course_sync->logger ) ) {
			$logger = $canvas_course_sync->logger;
		} elseif ( class_exists( 'CCS_Logger' ) ) {
			$logger = new CCS_Logger();
		}

		if ( ! class_exists( 'CCS_Database_Manager' ) ) {
			wp_send_json_error( array( 'message' => 'Database manager class not available' ) );
			return;
		}

		$db_manager = new CCS_Database_Manager( $logger );

		// Run cleanup
		$results = $db_manager->cleanup_deleted_courses();

		if ( ! is_array( $results ) ) {
			wp_send_json_error( array( 'message' => 'Cleanup operation failed' ) );
			return;
		}

		$message = sprintf(
			__( 'Cleanup completed: %1$d of %2$d tracked courses updated to "available" status', 'canvas-course-sync' ),
			$results['updated'] ?? 0,
			$results['checked'] ?? 0
		);

		wp_send_json_success( array(
			'message' => $message,
			'checked' => $results['checked'] ?? 0,
			'updated' => $results['updated'] ?? 0,
			'details' => $results['details'] ?? array(),
		) );
	});
}

/**
 * Toggle auto-sync setting - FIXED
 */
function ccs_toggle_auto_sync_handler() {
	ccs_ajax_safe_response( function() {
		// Verify nonce
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'ccs_toggle_auto_sync' ) ) {
			wp_send_json_error( array( 'message' => 'Security check failed' ) );
			return;
		}

		// Check permissions
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions' ) );
			return;
		}

		$enabled = isset( $_POST['enabled'] ) ? intval( $_POST['enabled'] ) : 0;

		update_option( 'ccs_auto_sync_enabled', $enabled );

		$status = $enabled ? 'enabled' : 'disabled';
		wp_send_json_success( array( 'message' => 'Auto-sync ' . $status ) );
	});
}

/**
 * Log JavaScript errors - FIXED
 */
function ccs_ajax_log_js_error() {
	ccs_ajax_safe_response( function() {
		// Verify permissions and nonce
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions' ) );
			return;
		}

		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'ccs_log_js_error' ) ) {
			wp_send_json_error( array( 'message' => 'Security check failed' ) );
			return;
		}

		// Get error details
		$message = isset( $_POST['message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['message'] ) ) : '';
		$context = isset( $_POST['context'] ) ? sanitize_text_field( wp_unslash( $_POST['context'] ) ) : '';
		$url = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : '';

		if ( empty( $message ) ) {
			wp_send_json_error( array( 'message' => 'No error message provided' ) );
			return;
		}

		// Get logger instance safely
		$canvas_course_sync = canvas_course_sync();
		$logger = null;

		if ( $canvas_course_sync && isset( $canvas_course_sync->logger ) ) {
			$logger = $canvas_course_sync->logger;
		} elseif ( class_exists( 'CCS_Logger' ) ) {
			$logger = new CCS_Logger();
		}

		if ( ! $logger ) {
			wp_send_json_error( array( 'message' => 'Logger not available' ) );
			return;
		}

		// Format error message
		$log_message = "JavaScript Error: {$message}";
		if ( ! empty( $context ) ) {
			$log_message .= " (Context: {$context})";
		}
		if ( ! empty( $url ) ) {
			$log_message .= " (URL: {$url})";
		}

		// Log the error
		$logger->log( $log_message, 'error' );

		wp_send_json_success( array( 'message' => 'Error logged successfully' ) );
	});
}

// Register all AJAX handlers with proper error handling
add_action( 'wp_ajax_ccs_test_connection', 'ccs_test_connection_handler' );
add_action( 'wp_ajax_ccs_get_courses', 'ccs_get_courses_handler' );
add_action( 'wp_ajax_ccs_sync_courses', 'ccs_sync_courses_handler' );
add_action( 'wp_ajax_ccs_sync_status', 'ccs_sync_status_handler' );
add_action( 'wp_ajax_ccs_clear_logs', 'ccs_clear_logs_handler' );
add_action( 'wp_ajax_ccs_refresh_logs', 'ccs_refresh_logs_handler' );
add_action( 'wp_ajax_ccs_run_auto_sync', 'ccs_run_auto_sync_handler' );
add_action( 'wp_ajax_ccs_omit_courses', 'ccs_omit_courses_handler' );
add_action( 'wp_ajax_ccs_restore_omitted', 'ccs_restore_omitted_handler' );
add_action( 'wp_ajax_ccs_cleanup_deleted', 'ccs_cleanup_deleted_courses_handler' );
add_action( 'wp_ajax_ccs_toggle_auto_sync', 'ccs_toggle_auto_sync_handler' );
add_action( 'wp_ajax_ccs_log_js_error', 'ccs_ajax_log_js_error' );
