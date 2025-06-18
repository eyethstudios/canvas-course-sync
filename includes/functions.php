
<?php
/**
 * General functions for Canvas Course Sync
 *
 * @package Canvas_Course_Sync
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Get plugin instance
 */
function ccs_get_instance() {
    return canvas_course_sync();
}

/**
 * Check if Canvas credentials are set
 */
function ccs_has_credentials() {
    $domain = get_option('ccs_canvas_domain');
    $token = get_option('ccs_canvas_token');
    
    return !empty($domain) && !empty($token);
}

/**
 * Get Canvas domain
 */
function ccs_get_canvas_domain() {
    return get_option('ccs_canvas_domain', '');
}

/**
 * Get Canvas token (masked for display)
 */
function ccs_get_canvas_token_masked() {
    $token = get_option('ccs_canvas_token', '');
    if (empty($token)) {
        return '';
    }
    
    if (strlen($token) <= 12) {
        return str_repeat('*', strlen($token));
    }
    
    return substr($token, 0, 8) . '...' . substr($token, -4);
}

/**
 * Get Canvas token (full - for API use only)
 */
function ccs_get_canvas_token() {
    return get_option('ccs_canvas_token', '');
}

/**
 * Validate Canvas domain format
 *
 * @param string $domain Domain to validate
 * @return bool|WP_Error True if valid, WP_Error if invalid
 */
function ccs_validate_canvas_domain($domain) {
    if (empty($domain)) {
        return new WP_Error('empty_domain', __('Canvas domain cannot be empty.', 'canvas-course-sync'));
    }
    
    // Ensure it's a valid URL
    if (!filter_var($domain, FILTER_VALIDATE_URL)) {
        return new WP_Error('invalid_url', __('Canvas domain must be a valid URL.', 'canvas-course-sync'));
    }
    
    // Ensure it uses HTTPS
    if (strpos($domain, 'https://') !== 0) {
        return new WP_Error('not_https', __('Canvas domain must use HTTPS.', 'canvas-course-sync'));
    }
    
    return true;
}

/**
 * Validate Canvas API token format
 *
 * @param string $token Token to validate
 * @return bool|WP_Error True if valid, WP_Error if invalid
 */
function ccs_validate_canvas_token($token) {
    if (empty($token)) {
        return new WP_Error('empty_token', __('Canvas API token cannot be empty.', 'canvas-course-sync'));
    }
    
    // Basic token format validation (Canvas tokens are typically long alphanumeric strings)
    if (strlen($token) < 32) {
        return new WP_Error('token_too_short', __('Canvas API token appears to be too short.', 'canvas-course-sync'));
    }
    
    if (!preg_match('/^[a-zA-Z0-9~]+$/', $token)) {
        return new WP_Error('invalid_token_format', __('Canvas API token contains invalid characters.', 'canvas-course-sync'));
    }
    
    return true;
}

/**
 * Get notification email
 */
function ccs_get_notification_email() {
    return get_option('ccs_notification_email', get_option('admin_email'));
}

/**
 * Check if auto-sync is enabled
 */
function ccs_is_auto_sync_enabled() {
    return (bool) get_option('ccs_auto_sync_enabled', false);
}

/**
 * Log helper function
 *
 * @param string $message Message to log
 * @param string $level Log level
 */
function ccs_log($message, $level = 'info') {
    $canvas_course_sync = canvas_course_sync();
    if ($canvas_course_sync && $canvas_course_sync->logger) {
        $canvas_course_sync->logger->log($message, $level);
    }
}

/**
 * Get courses post type name (for flexibility)
 */
function ccs_get_courses_post_type() {
    return apply_filters('ccs_courses_post_type', 'courses');
}

/**
 * Check if courses post type exists
 */
function ccs_courses_post_type_exists() {
    return post_type_exists(ccs_get_courses_post_type());
}

/**
 * Format Canvas date for display
 *
 * @param string $canvas_date Canvas date string
 * @return string Formatted date
 */
function ccs_format_canvas_date($canvas_date) {
    if (empty($canvas_date)) {
        return __('Not set', 'canvas-course-sync');
    }
    
    $date = date_create($canvas_date);
    if (!$date) {
        return __('Invalid date', 'canvas-course-sync');
    }
    
    return date_format($date, get_option('date_format') . ' ' . get_option('time_format'));
}

/**
 * Get plugin information
 */
function ccs_get_plugin_info() {
    return array(
        'version' => CCS_VERSION,
        'name' => __('Canvas Course Sync', 'canvas-course-sync'),
        'has_credentials' => ccs_has_credentials(),
        'auto_sync_enabled' => ccs_is_auto_sync_enabled(),
        'courses_post_type_exists' => ccs_courses_post_type_exists(),
        'notification_email' => ccs_get_notification_email()
    );
}
