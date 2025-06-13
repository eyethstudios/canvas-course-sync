
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
        // We don't need to add the action here as it's called directly
    }
    
    /**
     * Add admin menu
     */
    public function add_menu() {
        add_menu_page(
            __('Courses Sync', 'canvas-course-sync'),
            __('Courses Sync', 'canvas-course-sync'),
            'manage_options',
            'canvas-course-sync',
            array($this, 'display_admin_page'),
            'dashicons-update',
            30
        );
    }

    /**
     * Display the admin page
     */
    public function display_admin_page() {
        if (!current_user_can('manage_options')) {
            return;
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
                    // Initialize and render admin page components directly
                    $canvas_course_sync = canvas_course_sync();
                    if ($canvas_course_sync && isset($canvas_course_sync->logger)) {
                        // Initialize admin page to render components
                        if (class_exists('CCS_Admin_Page')) {
                            $admin_page = new CCS_Admin_Page();
                            $admin_page->render();
                        }
                    }
                    ?>
                </div>
            </div>
        </div>
        <?php
    }
}
