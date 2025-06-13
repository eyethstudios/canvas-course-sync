
<?php
// Prevent direct file access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * AJAX handler for clearing logs
 */
function ccs_ajax_clear_logs() {
    $canvas_course_sync = canvas_course_sync();
    
    // Debug log for tracking
    if ($canvas_course_sync && isset($canvas_course_sync->logger)) {
        $canvas_course_sync->logger->log('Clear logs AJAX request received');
    }
    
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
    
    if (!$canvas_course_sync || !isset($canvas_course_sync->logger)) {
        wp_send_json_error('Logger not initialized');
        return;
    }
    
    $result = $canvas_course_sync->logger->clear_logs();
    
    if ($result) {
        $canvas_course_sync->logger->log('Logs cleared successfully');
        wp_send_json_success('Logs cleared successfully');
    } else {
        $canvas_course_sync->logger->log('Failed to clear logs', 'error');
        wp_send_json_error('Failed to clear logs');
    }
}
add_action('wp_ajax_ccs_clear_logs', 'ccs_ajax_clear_logs');

/**
 * AJAX handler for getting sync status
 */
function ccs_ajax_sync_status() {
    $canvas_course_sync = canvas_course_sync();
    
    // Debug log
    if ($canvas_course_sync && isset($canvas_course_sync->logger)) {
        $canvas_course_sync->logger->log('Sync status AJAX request received');
    }
    
    // Check nonce for security
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'ccs_sync_status_nonce')) {
        wp_send_json_error('Security check failed');
        return;
    }
    
    // Check user capabilities
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Permission denied');
        return;
    }
    
    $status = ccs_get_sync_status();
    wp_send_json_success($status);
}
add_action('wp_ajax_ccs_sync_status', 'ccs_ajax_sync_status');

/**
 * AJAX handler for getting Canvas courses
 */
function ccs_ajax_get_courses() {
    $canvas_course_sync = canvas_course_sync();
    
    // Debug log
    if ($canvas_course_sync && isset($canvas_course_sync->logger)) {
        $canvas_course_sync->logger->log('Get courses AJAX request received');
    }
    
    // Check nonce for security
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'ccs_get_courses_nonce')) {
        wp_send_json_error('Security check failed');
        return;
    }
    
    // Check user capabilities
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Permission denied');
        return;
    }
    
    if (!$canvas_course_sync || !isset($canvas_course_sync->api)) {
        wp_send_json_error('API not initialized');
        return;
    }
    
    try {
        $courses = $canvas_course_sync->api->get_courses();
        
        // Handle error response
        if (is_wp_error($courses)) {
            $error_message = $courses->get_error_message();
            $canvas_course_sync->logger->log('Error getting courses: ' . $error_message, 'error');
            wp_send_json_error($error_message);
            return;
        }
        
        // Log success message with count
        $count = is_array($courses) ? count($courses) : 0;
        $canvas_course_sync->logger->log('Successfully retrieved ' . $count . ' courses from API');
        
        wp_send_json_success($courses);
    } catch (Exception $e) {
        $canvas_course_sync->logger->log('Exception getting courses: ' . $e->getMessage(), 'error');
        wp_send_json_error($e->getMessage());
    }
}
add_action('wp_ajax_ccs_get_courses', 'ccs_ajax_get_courses');

/**
 * AJAX handler for testing the API connection
 */
function ccs_ajax_test_connection() {
    $canvas_course_sync = canvas_course_sync();
    
    // Debug log
    if ($canvas_course_sync && isset($canvas_course_sync->logger)) {
        $canvas_course_sync->logger->log('Test connection AJAX request received');
    }
    
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
    
    if (!$canvas_course_sync || !isset($canvas_course_sync->api)) {
        wp_send_json_error('API not initialized');
        return;
    }
    
    try {
        $result = $canvas_course_sync->api->test_connection();
        if ($result === true) {
            $canvas_course_sync->logger->log('API connection test successful');
            wp_send_json_success('Connection successful!');
        } else {
            $canvas_course_sync->logger->log('API connection test failed: ' . $result, 'error');
            wp_send_json_error('Connection failed: ' . $result);
        }
    } catch (Exception $e) {
        $canvas_course_sync->logger->log('Exception in API connection test: ' . $e->getMessage(), 'error');
        wp_send_json_error('Exception: ' . $e->getMessage());
    }
}
add_action('wp_ajax_ccs_test_connection', 'ccs_ajax_test_connection');

/**
 * AJAX handler for syncing selected courses
 */
function ccs_ajax_sync_courses() {
    $canvas_course_sync = canvas_course_sync();
    
    // Debug log
    if ($canvas_course_sync && isset($canvas_course_sync->logger)) {
        $canvas_course_sync->logger->log('Sync courses AJAX request received');
    }
    
    // Check nonce for security
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'ccs_sync_nonce')) {
        wp_send_json_error('Security check failed');
        return;
    }
    
    // Check user capabilities
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Permission denied');
        return;
    }
    
    if (!$canvas_course_sync || !isset($canvas_course_sync->importer)) {
        wp_send_json_error('Importer not initialized');
        return;
    }
    
    // Get course IDs from request
    $course_ids = isset($_POST['course_ids']) ? array_map('intval', $_POST['course_ids']) : array();
    
    if (empty($course_ids)) {
        wp_send_json_error('No course IDs provided');
        return;
    }
    
    try {
        $result = $canvas_course_sync->importer->import_courses($course_ids);
        wp_send_json_success($result);
    } catch (Exception $e) {
        $canvas_course_sync->logger->log('Exception during course sync: ' . $e->getMessage(), 'error');
        wp_send_json_error('Sync failed: ' . $e->getMessage());
    }
}
add_action('wp_ajax_ccs_sync_courses', 'ccs_ajax_sync_courses');

/**
 * AJAX handler for running auto-sync
 */
function ccs_ajax_run_auto_sync() {
    $canvas_course_sync = canvas_course_sync();
    
    // Debug log
    if ($canvas_course_sync && isset($canvas_course_sync->logger)) {
        $canvas_course_sync->logger->log('Auto-sync AJAX request received');
    }
    
    // Check nonce for security
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'ccs_auto_sync_nonce')) {
        wp_send_json_error('Security check failed');
        return;
    }
    
    // Check user capabilities
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Permission denied');
        return;
    }
    
    if (!$canvas_course_sync || !isset($canvas_course_sync->scheduler)) {
        wp_send_json_error('Scheduler not initialized');
        return;
    }
    
    try {
        $result = $canvas_course_sync->scheduler->run_auto_sync();
        
        if ($result) {
            wp_send_json_success(array('message' => 'Auto-sync completed successfully'));
        } else {
            wp_send_json_error('Auto-sync failed. Check logs for details.');
        }
    } catch (Exception $e) {
        $canvas_course_sync->logger->log('Exception during auto-sync: ' . $e->getMessage(), 'error');
        wp_send_json_error('Auto-sync failed: ' . $e->getMessage());
    }
}
add_action('wp_ajax_ccs_run_auto_sync', 'ccs_ajax_run_auto_sync');
