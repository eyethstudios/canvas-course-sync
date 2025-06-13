
<?php
/**
 * Canvas Course Sync Helper Functions
 *
 * @package Canvas_Course_Sync
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Get current sync status
 *
 * @return array|false Sync status data or false if no sync in progress
 */
function ccs_get_sync_status() {
    return get_transient('ccs_sync_status');
}

/**
 * Update sync status
 *
 * @param string $message Status message
 * @param array $data Additional status data
 */
function ccs_update_sync_status($message, $data = array()) {
    $status = array(
        'message' => $message,
        'timestamp' => current_time('timestamp'),
        'data' => $data
    );
    
    set_transient('ccs_sync_status', $status, HOUR_IN_SECONDS);
}

/**
 * Clear sync status
 */
function ccs_clear_sync_status() {
    delete_transient('ccs_sync_status');
}
