
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
 * Debug function to check if menu is being registered
 */
function ccs_debug_menu_registration() {
    $canvas_course_sync = canvas_course_sync();
    if ($canvas_course_sync && isset($canvas_course_sync->logger)) {
        $canvas_course_sync->logger->log('Admin menu hook fired - current user can manage_options: ' . (current_user_can('manage_options') ? 'yes' : 'no'));
        $canvas_course_sync->logger->log('Post type courses exists: ' . (post_type_exists('courses') ? 'yes' : 'no'));
    }
}

/**
 * Initialize admin menu
 */
function ccs_init_admin_menu() {
    // Debug logging
    ccs_debug_menu_registration();
    
    $canvas_course_sync = canvas_course_sync();
    if ($canvas_course_sync && isset($canvas_course_sync->logger)) {
        $canvas_course_sync->logger->log('Initializing admin menu');
    }
    
    if (!class_exists('CCS_Admin_Menu')) {
        if ($canvas_course_sync && isset($canvas_course_sync->logger)) {
            $canvas_course_sync->logger->log('CCS_Admin_Menu class not found', 'error');
        }
        return;
    }
    
    $admin_menu = new CCS_Admin_Menu();
    $admin_menu->add_menu();
}

// Hook the admin menu registration to the correct action
add_action('admin_menu', 'ccs_init_admin_menu');
