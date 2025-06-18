
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
    
    return substr($token, 0, 8) . '...' . substr($token, -4);
}
