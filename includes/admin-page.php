
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
 * Initialize admin menu - this function will be called on admin_menu hook
 */
function ccs_init_admin_menu() {
    // Early return if not in admin or user doesn't have permissions
    if (!is_admin() || !current_user_can('manage_options')) {
        return;
    }
    
    $canvas_course_sync = canvas_course_sync();
    if ($canvas_course_sync && isset($canvas_course_sync->logger)) {
        $canvas_course_sync->logger->log('Admin menu initialization started - User can manage options: ' . (current_user_can('manage_options') ? 'yes' : 'no'));
    }
    
    // Check if required class exists
    if (!class_exists('CCS_Admin_Menu')) {
        if ($canvas_course_sync && isset($canvas_course_sync->logger)) {
            $canvas_course_sync->logger->log('CCS_Admin_Menu class not found during menu registration', 'error');
        }
        return;
    }
    
    // Create and register the menu
    try {
        $admin_menu = new CCS_Admin_Menu();
        $admin_menu->add_menu();
        
        if ($canvas_course_sync && isset($canvas_course_sync->logger)) {
            $canvas_course_sync->logger->log('Admin menu registered successfully');
        }
    } catch (Exception $e) {
        if ($canvas_course_sync && isset($canvas_course_sync->logger)) {
            $canvas_course_sync->logger->log('Error registering admin menu: ' . $e->getMessage(), 'error');
        }
    }
}

// Hook the admin menu registration to the correct action with higher priority
add_action('admin_menu', 'ccs_init_admin_menu', 10);
