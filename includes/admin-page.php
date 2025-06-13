
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
require_once CCS_PLUGIN_DIR . 'includes/admin/class-ccs-admin-menu.php';

/**
 * Initialize admin menu
 */
function ccs_init_admin_menu() {
    // Debug log
    $canvas_course_sync = canvas_course_sync();
    if ($canvas_course_sync && isset($canvas_course_sync->logger)) {
        $canvas_course_sync->logger->log('Initializing admin menu');
    }
    
    $admin_menu = new CCS_Admin_Menu();
    $admin_menu->add_menu();
}

// Hook the admin menu registration to the correct action
add_action('admin_menu', 'ccs_init_admin_menu');
