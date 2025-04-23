
<?php
/**
 * Canvas Course Sync Handlers
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Get the current sync status from transient storage
 *
 * @return array|bool The current status array or false if no sync is in progress
 */
function ccs_get_sync_status() {
    return get_transient('ccs_sync_status');
}

/**
 * Update the sync status
 *
 * @param string $message The status message
 * @param array  $data    Additional data to store with the status
 */
function ccs_update_sync_status($message, $data = array()) {
    $status = array_merge(
        array('status' => $message),
        $data
    );
    set_transient('ccs_sync_status', $status, HOUR_IN_SECONDS);
}

/**
 * Clear the sync status
 */
function ccs_clear_sync_status() {
    delete_transient('ccs_sync_status');
}

