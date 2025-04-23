
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
 * Get the current sync status
 * 
 * @return array Current sync status
 */
function ccs_get_sync_status() {
    $status = get_option('ccs_sync_status', array(
        'message' => '',
        'timestamp' => '',
        'data' => array()
    ));
    
    return $status;
}

/**
 * Clear the sync status
 */
function ccs_clear_sync_status() {
    delete_option('ccs_sync_status');
}
