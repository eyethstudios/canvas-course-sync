
<?php
/**
 * Canvas Course Sync Admin Page Entry Point
 *
 * @package Canvas_Course_Sync
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Include the main admin page class
require_once CCS_PLUGIN_DIR . 'includes/admin/class-ccs-admin-page.php';

/**
 * Initialize admin page functionality
 *
 * @return CCS_Admin_Page Admin page instance
 */
function ccs_init_admin_page() {
    global $canvas_course_sync;
    
    // Debug log
    if (isset($canvas_course_sync) && isset($canvas_course_sync->logger)) {
        $canvas_course_sync->logger->log('Initializing admin page');
    }
    
    $admin_page = new CCS_Admin_Page();
    return $admin_page;
}

// Hook the admin page initialization to admin_menu instead of admin_init
add_action('admin_menu', 'ccs_init_admin_page', 9);
