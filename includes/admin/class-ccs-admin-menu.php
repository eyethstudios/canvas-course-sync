
<?php
/**
 * Canvas Course Sync Admin Menu Registration
 *
 * @package Canvas_Course_Sync
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Admin Menu Registration class
 */
class CCS_Admin_Menu {
    /**
     * Constructor
     */
    public function __construct() {
        // Constructor doesn't need to do anything
    }
    
    /**
     * Add admin menu
     */
    public function add_menu() {
        // Debug log
        $canvas_course_sync = canvas_course_sync();
        if ($canvas_course_sync && isset($canvas_course_sync->logger)) {
            $canvas_course_sync->logger->log('Adding admin menu page');
        }
        
        add_menu_page(
            __('Canvas Course Sync', 'canvas-course-sync'),     // Page title
            __('Course Sync', 'canvas-course-sync'),            // Menu title
            'manage_options',                                    // Capability
            'canvas-course-sync',                               // Menu slug
            array($this, 'display_admin_page'),                // Callback function
            'dashicons-update',                                 // Icon
            30                                                  // Position
        );
    }

    /**
     * Display the admin page
     */
    public function display_admin_page() {
        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.'));
        }
        
        // Check if the required post type exists
        $post_type_exists = post_type_exists('courses');
        ?>
        <div class="wrap">
            <h1><?php _e('Canvas Course Sync', 'canvas-course-sync'); ?></h1>
            
            <?php if (!$post_type_exists) : ?>
                <div class="notice notice-error">
                    <p><?php _e('Error: Custom post type "courses" does not exist. Please register this post type to use this plugin.', 'canvas-course-sync'); ?></p>
                </div>
                <?php return; ?>
            <?php endif; ?>
            
            <div class="ccs-admin-container">
                <div class="ccs-admin-main">
                    <?php 
                    // Initialize and render admin page components
                    if (class_exists('CCS_Admin_Page')) {
                        $admin_page = new CCS_Admin_Page();
                        $admin_page->render();
                    } else {
                        echo '<p>Admin page components not loaded properly.</p>';
                    }
                    ?>
                </div>
            </div>
        </div>
        <?php
    }
}
