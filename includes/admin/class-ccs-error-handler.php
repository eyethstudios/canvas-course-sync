<?php
/**
 * Canvas Course Sync - Centralized Error Logger
 * Handles JavaScript error logging to server
 * 
 * @package Canvas_Course_Sync
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * AJAX handler for logging JavaScript errors
 */
function ccs_ajax_log_js_error() {
    // Verify permissions and nonce
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => __('Insufficient permissions.', 'canvas-course-sync')));
        return;
    }
    
    if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'ccs_log_js_error')) {
        wp_send_json_error(array('message' => __('Security check failed.', 'canvas-course-sync')));
        return;
    }
    
    // Get error details
    $message = isset($_POST['message']) ? sanitize_textarea_field(wp_unslash($_POST['message'])) : '';
    $context = isset($_POST['context']) ? sanitize_text_field(wp_unslash($_POST['context'])) : '';
    $details = isset($_POST['details']) ? sanitize_textarea_field(wp_unslash($_POST['details'])) : '';
    $url = isset($_POST['url']) ? esc_url_raw(wp_unslash($_POST['url'])) : '';
    $user_agent = isset($_POST['userAgent']) ? sanitize_text_field(wp_unslash($_POST['userAgent'])) : '';
    
    if (empty($message)) {
        wp_send_json_error(array('message' => __('No error message provided.', 'canvas-course-sync')));
        return;
    }
    
    // Get logger instance
    $canvas_course_sync = canvas_course_sync();
    if (!$canvas_course_sync || !$canvas_course_sync->logger) {
        wp_send_json_error(array('message' => __('Logger not available.', 'canvas-course-sync')));
        return;
    }
    
    // Format error message
    $log_message = "JavaScript Error: {$message}";
    if (!empty($context)) {
        $log_message .= " (Context: {$context})";
    }
    if (!empty($url)) {
        $log_message .= " (URL: {$url})";
    }
    if (!empty($details)) {
        $log_message .= " (Details: {$details})";
    }
    if (!empty($user_agent)) {
        $log_message .= " (User Agent: {$user_agent})";
    }
    
    // Log the error
    $canvas_course_sync->logger->log($log_message, 'error');
    
    wp_send_json_success(array('message' => __('Error logged successfully.', 'canvas-course-sync')));
}

/**
 * AJAX handler for toggling auto-sync setting
 * REMOVED - This handler is now in includes/ajax-handlers.php to avoid conflicts
 */

// Register AJAX handlers
add_action('wp_ajax_ccs_log_js_error', 'ccs_ajax_log_js_error');