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
        $canvas_course_sync = canvas_course_sync();
        if (!$canvas_course_sync || !$canvas_course_sync->api) {
            wp_send_json_error('Canvas API not initialized');
            return;
        }
        
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
            
            // Check if course already exists
            $exists_check = array('exists' => false);
            if ($canvas_course_sync->importer && $canvas_course_sync->importer->db_manager) {
                $exists_check = $canvas_course_sync->importer->db_manager->course_exists($course['id'], $course['name']);
            }
            
            // Check if course is manually omitted
            $is_omitted = function_exists('ccs_is_course_omitted') ? ccs_is_course_omitted($course['id']) : false;
            
            $processed_courses[] = array(
                'id' => $course['id'],
                'name' => $course['name'],
                'course_code' => $course['course_code'] ?? '',
                'status' => $exists_check['exists'] ? 'synced' : 'available',
                'is_omitted' => $is_omitted
            );
        }
        
        // Now validate all courses against catalog
        if (class_exists('CCS_Catalog_Validator')) {
            $validator = new CCS_Catalog_Validator();
            $validation_results = $validator->validate_against_catalog($processed_courses);
            
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
            
            wp_send_json_success(array(
                'courses' => $validated_courses,
                'total' => count($validated_courses),
                'auto_omitted_count' => $auto_omitted_count,
                'validation_report' => $validation_report
            ));
        } else {
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
 */
function ccs_log_js_error_handler() {
    // Verify nonce
    if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'ccs_log_js_error')) {
        wp_die('Security check failed');
    }
    
    $message = isset($_POST['message']) ? sanitize_text_field($_POST['message']) : '';
    $context = isset($_POST['context']) ? sanitize_text_field($_POST['context']) : 'JavaScript';
    
    if (!empty($message)) {
        $canvas_course_sync = canvas_course_sync();
        if ($canvas_course_sync && $canvas_course_sync->logger) {
            $canvas_course_sync->logger->log('[JS Error] ' . $context . ': ' . $message, 'error');
        }
    }
    
    wp_send_json_success('Error logged');
}
add_action('wp_ajax_ccs_log_js_error', 'ccs_log_js_error_handler');