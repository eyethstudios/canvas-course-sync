
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
    }
    
    // Check user capabilities
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Permission denied');
    }
    
    global $canvas_course_sync;
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
        wp_send_json_error('Security check failed');
    }
    
    // Check user capabilities
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Permission denied');
    }
    
    global $canvas_course_sync;
    
    try {
        $courses = $canvas_course_sync->api->get_courses();
        wp_send_json_success($courses);
    } catch (Exception $e) {
        wp_send_json_error($e->getMessage());
    }
}
add_action('wp_ajax_ccs_get_courses', 'ccs_ajax_get_courses');

