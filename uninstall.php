
<?php
/**
 * Uninstall Canvas Course Sync
 *
 * @package Canvas_Course_Sync
 */

// Exit if accessed directly
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Delete plugin options
delete_option('ccs_api_domain');
delete_option('ccs_api_token');
delete_option('ccs_version');

// Clean up logs directory
$upload_dir = wp_upload_dir();
$log_dir = $upload_dir['basedir'] . '/canvas-course-sync/logs';

if (file_exists($log_dir)) {
    $files = glob($log_dir . '/*');
    foreach ($files as $file) {
        if (is_file($file)) {
            @unlink($file);
        }
    }
    @rmdir($log_dir);
    @rmdir(dirname($log_dir));
}
