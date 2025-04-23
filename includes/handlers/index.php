
<?php
// Prevent direct file access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Update the sync status
 * 
 * @param string $message Status message
 * @param array $data Additional status data
 */
function ccs_update_sync_status($message = '', $data = array()) {
    $status = array(
        'message' => $message,
        'timestamp' => current_time('mysql'),
        'data' => $data
    );
    
    update_option('ccs_sync_status', $status);
    return $status;
}

/**
 * Clear the sync status
 */
function ccs_clear_sync_status() {
    delete_option('ccs_sync_status');
}

