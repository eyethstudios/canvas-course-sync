<?php
/**
 * AJAX Handlers for Canvas Course Sync
 *
 * @package Canvas_Course_Sync
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Test Canvas API connection
 */
function ccs_test_connection_handler() {
    // Verify nonce
    if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'ccs_test_connection')) {
        wp_die('Security check failed');
    }
    
    // Check permissions
    if (!current_user_can('manage_options')) {
        wp_die('Insufficient permissions');
    }
    
    $canvas_course_sync = canvas_course_sync();
    if (!$canvas_course_sync || !$canvas_course_sync->api) {
        wp_send_json_error('Canvas API not initialized');
        return;
    }
    
    $result = $canvas_course_sync->api->test_connection();
    
    if (is_wp_error($result)) {
        wp_send_json_error($result->get_error_message());
    } else {
        wp_send_json_success('Connection successful! Canvas API is working properly.');
    }
}
add_action('wp_ajax_ccs_test_connection', 'ccs_test_connection_handler');

/**
 * Get courses from Canvas
 */
function ccs_get_courses_handler() {
    // Verify nonce
    if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'ccs_get_courses')) {
        wp_die('Security check failed');
    }
    
    // Check permissions
    if (!current_user_can('manage_options')) {
        wp_die('Insufficient permissions');
    }
    
    try {
        error_log('CCS Debug: Starting get_courses_handler');
        
        $canvas_course_sync = canvas_course_sync();
        error_log('CCS Debug: canvas_course_sync function result: ' . ($canvas_course_sync ? 'success' : 'failed'));
        
        if (!$canvas_course_sync || !$canvas_course_sync->api) {
            error_log('CCS Debug: Canvas API not initialized - canvas_course_sync: ' . ($canvas_course_sync ? 'exists' : 'null') . ', api: ' . (isset($canvas_course_sync->api) ? 'exists' : 'null'));
            wp_send_json_error('Canvas API not initialized');
            return;
        }
        
        error_log('CCS Debug: About to call get_courses()');
        // Get courses from Canvas
        $courses = $canvas_course_sync->api->get_courses();
        
        if (is_wp_error($courses)) {
            wp_send_json_error($courses->get_error_message());
            return;
        }
        
        // Filter and enhance course data
        $processed_courses = array();
        $auto_omitted_count = 0;
        
        foreach ($courses as $course) {
            // Skip courses without names
            if (empty($course['name'])) {
                continue;
            }
            
            // Validate course against catalog instead of hard-coded exclusions
            // This will be done in bulk after getting all courses
            
            // Check course status using database manager for accurate tracking
            $status = 'available';
            $exists_check = array('exists' => false);
            
            if ($canvas_course_sync->importer) {
                $exists_check = $canvas_course_sync->importer->course_exists($course['id'], $course['name']);
                
                // Check tracking table for more accurate status
                global $wpdb;
                $tracking_table = $wpdb->prefix . 'ccs_course_tracking';
                $tracking_record = $wpdb->get_row($wpdb->prepare(
                    "SELECT * FROM $tracking_table WHERE canvas_course_id = %d",
                    intval($course['id'])
                ));
                
                if ($tracking_record) {
                    // Use status from tracking table
                    $status = $tracking_record->sync_status;
                    
                    // Double-check if the WordPress post actually exists
                    if ($status === 'synced') {
                        $post = get_post($tracking_record->wordpress_post_id);
                        if (!$post || $post->post_status === 'trash') {
                            $status = 'available'; // Override if post is actually deleted/trashed
                        }
                    }
                } elseif ($exists_check['exists']) {
                    $status = 'synced';
                }
            }
            
            // Check if course is manually omitted
            $is_omitted = function_exists('ccs_is_course_omitted') ? ccs_is_course_omitted($course['id']) : false;
            
            $processed_courses[] = array(
                'id' => $course['id'],
                'name' => $course['name'],
                'course_code' => $course['course_code'] ?? '',
                'status' => $status,
                'is_omitted' => $is_omitted
            );
        }
        
        // Now validate all courses against catalog - force fresh catalog check
        if (class_exists('CCS_Catalog_Validator')) {
            $validator = new CCS_Catalog_Validator();
            
            // Force fresh catalog fetch to ensure we have the latest courses
            $validator->force_catalog_refresh();
            
            // Debug: Log before validation
            error_log('CCS Debug: Starting catalog validation for ' . count($processed_courses) . ' courses with fresh catalog data');
            
            // Get approved courses for debugging
            $approved_courses = $validator->get_approved_courses();
            error_log('CCS Debug: Catalog contains ' . count($approved_courses) . ' approved courses');
            error_log('CCS Debug: First 5 approved courses: ' . implode(', ', array_slice($approved_courses, 0, 5)));
            
            // Log the first few Canvas courses being validated
            error_log('CCS Debug: First 5 Canvas courses to validate: ' . implode(', ', array_slice(array_column($processed_courses, 'name'), 0, 5)));
            
            $validation_results = $validator->validate_against_catalog($processed_courses);
            
            // Debug: Log validation results
            error_log('CCS Debug: Validation results - Validated: ' . count($validation_results['validated']) . ', Omitted: ' . count($validation_results['omitted']) . ', Auto-omitted: ' . count($validation_results['auto_omitted_ids']));
            
            // Log some specific validation results
            if (!empty($validation_results['validated'])) {
                $validated_names = array_slice(array_column($validation_results['validated'], 'name'), 0, 3);
                error_log('CCS Debug: Sample validated courses: ' . implode(', ', $validated_names));
            }
            
            if (!empty($validation_results['omitted'])) {
                $omitted_names = array_slice(array_column($validation_results['omitted'], 'name'), 0, 3);
                error_log('CCS Debug: Sample omitted courses: ' . implode(', ', $omitted_names));
            }
            
            // Update processed courses with validation results
            $validated_courses = $validation_results['validated'];
            $auto_omitted_count = count($validation_results['auto_omitted_ids']);
            
            // Create validation report
            $validation_report = '';
            if ($auto_omitted_count > 0) {
                $validation_report = '<div class="notice notice-info"><p>';
                $validation_report .= sprintf(__('Note: %d courses were automatically omitted because they were not found in the course catalog.', 'canvas-course-sync'), $auto_omitted_count);
                $validation_report .= '</p></div>';
                
                // Add detailed report
                $validation_report .= $validator->generate_validation_report($validation_results);
            }
            
            // Debug: Log final result
            error_log('CCS Debug: Sending ' . count($validated_courses) . ' validated courses to frontend');
            
            wp_send_json_success(array(
                'courses' => $validated_courses,
                'total' => count($validated_courses),
                'auto_omitted_count' => $auto_omitted_count,
                'validation_report' => $validation_report
            ));
        } else {
            error_log('CCS Debug: CCS_Catalog_Validator class not found, falling back to all courses');
            // Fallback if validator not available
            wp_send_json_success(array(
                'courses' => $processed_courses,
                'total' => count($processed_courses),
                'auto_omitted_count' => 0,
                'validation_report' => ''
            ));
        }
        
    } catch (Exception $e) {
        wp_send_json_error('Error retrieving courses: ' . $e->getMessage());
    }
}
add_action('wp_ajax_ccs_get_courses', 'ccs_get_courses_handler');

/**
 * Sync selected courses
 */
function ccs_sync_courses_handler() {
    // Verify nonce
    if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'ccs_sync_courses')) {
        wp_die('Security check failed');
    }
    
    // Check permissions
    if (!current_user_can('manage_options')) {
        wp_die('Insufficient permissions');
    }
    
    // Get course IDs
    $course_ids = isset($_POST['course_ids']) ? array_map('intval', $_POST['course_ids']) : array();
    
    if (empty($course_ids)) {
        wp_send_json_error('No course IDs provided');
        return;
    }
    
    $canvas_course_sync = canvas_course_sync();
    if (!$canvas_course_sync || !$canvas_course_sync->importer) {
        wp_send_json_error('Importer not initialized');
        return;
    }
    
    try {
        // Set sync status
        set_transient('ccs_sync_status', array(
            'status' => 'Starting sync...',
            'processed' => 0,
            'total' => count($course_ids)
        ), 300);
        
        // Import courses
        $result = $canvas_course_sync->importer->import_courses($course_ids);
        
        // Clear sync status
        delete_transient('ccs_sync_status');
        
        wp_send_json_success($result);
        
    } catch (Exception $e) {
        delete_transient('ccs_sync_status');
        wp_send_json_error('Sync failed: ' . $e->getMessage());
    }
}
add_action('wp_ajax_ccs_sync_courses', 'ccs_sync_courses_handler');

/**
 * Get sync status
 */
function ccs_sync_status_handler() {
    // Verify nonce
    if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'ccs_sync_status')) {
        wp_die('Security check failed');
    }
    
    $status = get_transient('ccs_sync_status');
    
    if ($status) {
        wp_send_json_success($status);
    } else {
        wp_send_json_success(array(
            'status' => 'No sync in progress',
            'processed' => 0,
            'total' => 0
        ));
    }
}
add_action('wp_ajax_ccs_sync_status', 'ccs_sync_status_handler');

/**
 * Clear logs
 */
function ccs_clear_logs_handler() {
    // Verify nonce
    if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'ccs_clear_logs')) {
        wp_die('Security check failed');
    }
    
    // Check permissions
    if (!current_user_can('manage_options')) {
        wp_die('Insufficient permissions');
    }
    
    $canvas_course_sync = canvas_course_sync();
    if (!$canvas_course_sync || !$canvas_course_sync->logger) {
        wp_send_json_error('Logger not initialized');
        return;
    }
    
    $canvas_course_sync->logger->clear_logs();
    wp_send_json_success('Logs cleared successfully');
}
add_action('wp_ajax_ccs_clear_logs', 'ccs_clear_logs_handler');

/**
 * Refresh logs
 */
function ccs_refresh_logs_handler() {
    // Verify nonce
    if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'ccs_refresh_logs')) {
        wp_die('Security check failed');
    }
    
    // Check permissions
    if (!current_user_can('manage_options')) {
        wp_die('Insufficient permissions');
    }
    
    $canvas_course_sync = canvas_course_sync();
    if (!$canvas_course_sync || !$canvas_course_sync->logger) {
        wp_send_json_error('Logger not initialized');
        return;
    }
    
    $logs = $canvas_course_sync->logger->get_recent_logs(20);
    
    ob_start();
    if (!empty($logs)) {
        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<thead><tr>';
        echo '<th scope="col" style="width: 150px;">' . __('Timestamp', 'canvas-course-sync') . '</th>';
        echo '<th scope="col" style="width: 80px;">' . __('Level', 'canvas-course-sync') . '</th>';
        echo '<th scope="col">' . __('Message', 'canvas-course-sync') . '</th>';
        echo '</tr></thead><tbody>';
        
        foreach ($logs as $log) {
            echo '<tr>';
            echo '<td>' . esc_html(mysql2date('Y-m-d H:i:s', $log->timestamp ?? '')) . '</td>';
            echo '<td><span class="ccs-log-level ccs-log-level-' . esc_attr($log->level ?? 'info') . '">' . esc_html(strtoupper($log->level ?? 'INFO')) . '</span></td>';
            echo '<td>' . esc_html($log->message ?? '') . '</td>';
            echo '</tr>';
        }
        
        echo '</tbody></table>';
    } else {
        echo '<div class="notice notice-info"><p>' . __('No logs found.', 'canvas-course-sync') . '</p></div>';
    }
    $html = ob_get_clean();
    
    wp_send_json_success(array('html' => $html));
}
add_action('wp_ajax_ccs_refresh_logs', 'ccs_refresh_logs_handler');

/**
 * Run auto sync
 */
function ccs_run_auto_sync_handler() {
    // Verify nonce
    if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'ccs_run_auto_sync')) {
        wp_die('Security check failed');
    }
    
    // Check permissions
    if (!current_user_can('manage_options')) {
        wp_die('Insufficient permissions');
    }
    
    $canvas_course_sync = canvas_course_sync();
    if (!$canvas_course_sync || !$canvas_course_sync->scheduler) {
        wp_send_json_error('Scheduler not initialized');
        return;
    }
    
    $result = $canvas_course_sync->scheduler->run_auto_sync();
    
    if ($result) {
        wp_send_json_success(array('message' => 'Auto-sync completed successfully'));
    } else {
        wp_send_json_error('Auto-sync failed');
    }
}
add_action('wp_ajax_ccs_run_auto_sync', 'ccs_run_auto_sync_handler');

/**
 * Omit courses from auto-sync
 */
function ccs_omit_courses_handler() {
    // Verify nonce
    if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'ccs_omit_courses')) {
        wp_die('Security check failed');
    }
    
    // Check permissions
    if (!current_user_can('manage_options')) {
        wp_die('Insufficient permissions');
    }
    
    $course_ids = isset($_POST['course_ids']) ? array_map('intval', $_POST['course_ids']) : array();
    
    if (empty($course_ids)) {
        wp_send_json_error('No course IDs provided');
        return;
    }
    
    // Get current omitted courses
    $omitted_courses = get_option('ccs_omitted_courses', array());
    
    // Add new courses to omitted list
    $omitted_courses = array_unique(array_merge($omitted_courses, $course_ids));
    
    // Save updated list
    update_option('ccs_omitted_courses', $omitted_courses);
    
    wp_send_json_success(array(
        'message' => count($course_ids) . ' course(s) omitted from auto-sync',
        'omitted_count' => count($omitted_courses)
    ));
}
add_action('wp_ajax_ccs_omit_courses', 'ccs_omit_courses_handler');

/**
 * Restore omitted courses
 */
function ccs_restore_omitted_handler() {
    // Verify nonce
    if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'ccs_restore_omitted')) {
        wp_die('Security check failed');
    }
    
    // Check permissions
    if (!current_user_can('manage_options')) {
        wp_die('Insufficient permissions');
    }
    
    // Clear omitted courses list
    delete_option('ccs_omitted_courses');
    
    wp_send_json_success(array('message' => 'All omitted courses restored for auto-sync'));
}
add_action('wp_ajax_ccs_restore_omitted', 'ccs_restore_omitted_handler');

/**
 * Cleanup deleted courses - update sync status from 'synced' to 'available'
 */
function ccs_cleanup_deleted_courses_handler() {
    // Verify nonce
    if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'ccs_cleanup_deleted')) {
        wp_die('Security check failed');
    }
    
    // Check permissions
    if (!current_user_can('manage_options')) {
        wp_die('Insufficient permissions');
    }
    
    // Get database manager instance
    $logger = class_exists('CCS_Logger') ? new CCS_Logger() : null;
    $db_manager = new CCS_Database_Manager($logger);
    
    // Run cleanup
    $results = $db_manager->cleanup_deleted_courses();
    
    $message = sprintf(
        __('Cleanup completed: %d of %d tracked courses updated to "available" status', 'canvas-course-sync'),
        $results['updated'],
        $results['checked']
    );
    
    wp_send_json_success(array(
        'message' => $message,
        'checked' => $results['checked'],
        'updated' => $results['updated'],
        'details' => $results['details']
    ));
}
add_action('wp_ajax_ccs_cleanup_deleted', 'ccs_cleanup_deleted_courses_handler');

/**
 * Toggle auto-sync setting
 */
function ccs_toggle_auto_sync_handler() {
    // Verify nonce
    if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'ccs_toggle_auto_sync')) {
        wp_die('Security check failed');
    }
    
    // Check permissions
    if (!current_user_can('manage_options')) {
        wp_die('Insufficient permissions');
    }
    
    $enabled = isset($_POST['enabled']) ? intval($_POST['enabled']) : 0;
    
    update_option('ccs_auto_sync_enabled', $enabled);
    
    $status = $enabled ? 'enabled' : 'disabled';
    wp_send_json_success(array('message' => 'Auto-sync ' . $status));
}
add_action('wp_ajax_ccs_toggle_auto_sync', 'ccs_toggle_auto_sync_handler');

/**
 * Log JavaScript errors
 * REMOVED - This handler is now in includes/admin/class-ccs-error-handler.php to avoid conflicts
 */