
<?php
/**
 * Canvas Course Sync Admin Menu Handler
 *
 * @package Canvas_Course_Sync
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Admin Menu class
 */
class CCS_Admin_Menu {
    /**
     * Logger instance
     *
     * @var CCS_Logger
     */
    private $logger;

    /**
     * Constructor
     */
    public function __construct() {
        $canvas_course_sync = canvas_course_sync();
        $this->logger = ($canvas_course_sync && isset($canvas_course_sync->logger)) ? $canvas_course_sync->logger : null;
    }

    /**
     * Add admin menu
     */
    public function add_menu() {
        // Add top-level menu page
        add_menu_page(
            __('Canvas Course Sync', 'canvas-course-sync'),  // Page title
            __('Canvas Sync', 'canvas-course-sync'),         // Menu title
            'manage_options',                                 // Capability
            'canvas-course-sync',                            // Menu slug
            array($this, 'display_admin_page'),              // Callback function
            'dashicons-update',                              // Icon (sync/update icon)
            30                                               // Position (after Comments)
        );

        if ($this->logger) {
            $this->logger->log('Admin menu page added successfully');
        }
    }

    /**
     * Display admin page
     */
    public function display_admin_page() {
        // Check if admin page class exists
        if (class_exists('CCS_Admin_Page')) {
            $admin_page = new CCS_Admin_Page();
            $admin_page->render();
        } else {
            echo '<div class="wrap">';
            echo '<h1>' . esc_html__('Canvas Course Sync', 'canvas-course-sync') . '</h1>';
            echo '<div class="notice notice-error"><p>';
            echo esc_html__('Admin page class not found. Please check plugin installation.', 'canvas-course-sync');
            echo '</p></div>';
            echo '</div>';
        }
    }
}
