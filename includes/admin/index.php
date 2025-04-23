
<?php
// Prevent direct file access
if (!defined('ABSPATH')) {
    exit;
}

// Include handlers
require_once CCS_PLUGIN_DIR . 'includes/handlers/index.php';

/**
 * AJAX handler for clearing logs
 */
function ccs_ajax_clear_logs() {
    // Check nonce for security
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'ccs_clear_logs_nonce')) {
        wp_send_json_error('Security check failed');
        return;
    }
    
    // Check user capabilities
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Permission denied');
        return;
    }
    
    global $canvas_course_sync;
    
    if (!isset($canvas_course_sync->logger)) {
        wp_send_json_error('Logger not initialized');
        return;
    }
    
    $result = $canvas_course_sync->logger->clear_logs();
    
    if ($result) {
        wp_send_json_success('Logs cleared successfully');
    } else {
        wp_send_json_error('Failed to clear logs');
    }
}
add_action('wp_ajax_ccs_clear_logs', 'ccs_ajax_clear_logs');

/**
 * AJAX handler for getting sync status
 */
function ccs_ajax_sync_status() {
    // Check nonce for security
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'ccs_sync_status_nonce')) {
        wp_send_json_error('Security check failed');
    }
    
    // Check user capabilities
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Permission denied');
    }
    
    $status = ccs_get_sync_status();
    wp_send_json_success($status);
}
add_action('wp_ajax_ccs_sync_status', 'ccs_ajax_sync_status');

/**
 * AJAX handler for getting Canvas courses
 */
function ccs_ajax_get_courses() {
    // Check nonce for security
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'ccs_get_courses_nonce')) {
        error_log('Canvas Course Sync: Get courses nonce check failed');
        wp_send_json_error('Security check failed');
        return;
    }
    
    // Check user capabilities
    if (!current_user_can('manage_options')) {
        error_log('Canvas Course Sync: Get courses permission check failed');
        wp_send_json_error('Permission denied');
        return;
    }
    
    global $canvas_course_sync;
    
    if (!isset($canvas_course_sync->api)) {
        error_log('Canvas Course Sync: API not initialized');
        wp_send_json_error('API not initialized');
        return;
    }
    
    try {
        error_log('Canvas Course Sync: Attempting to get courses');
        $courses = $canvas_course_sync->api->get_courses();
        
        // Add error logging for debugging
        if (is_wp_error($courses)) {
            $error_message = $courses->get_error_message();
            error_log('Canvas Course Sync: Error getting courses: ' . $error_message);
            $canvas_course_sync->logger->log('Error getting courses: ' . $error_message, 'error');
            wp_send_json_error($error_message);
            return;
        }
        
        // Log success message with count
        $count = is_array($courses) ? count($courses) : 'unknown number of';
        error_log('Canvas Course Sync: Successfully retrieved ' . $count . ' courses');
        $canvas_course_sync->logger->log('Successfully retrieved ' . $count . ' courses from API');
        
        wp_send_json_success($courses);
    } catch (Exception $e) {
        error_log('Canvas Course Sync: Exception getting courses: ' . $e->getMessage());
        $canvas_course_sync->logger->log('Exception getting courses: ' . $e->getMessage(), 'error');
        wp_send_json_error($e->getMessage());
    }
}
add_action('wp_ajax_ccs_get_courses', 'ccs_ajax_get_courses');

/**
 * AJAX handler for testing the API connection
 */
function ccs_ajax_test_connection() {
    // Check nonce for security
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'ccs_test_connection_nonce')) {
        wp_send_json_error('Security check failed');
        return;
    }
    
    // Check user capabilities
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Permission denied');
        return;
    }
    
    global $canvas_course_sync;
    
    try {
        $result = $canvas_course_sync->api->test_connection();
        if ($result === true) {
            wp_send_json_success('Connection successful!');
        } else {
            wp_send_json_error('Connection failed: ' . $result);
        }
    } catch (Exception $e) {
        wp_send_json_error('Exception: ' . $e->getMessage());
    }
}
add_action('wp_ajax_ccs_test_connection', 'ccs_ajax_test_connection');
