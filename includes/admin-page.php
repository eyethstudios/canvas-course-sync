
<?php
/**
 * Canvas Course Sync Admin Page
 *
 * @package Canvas_Course_Sync
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Include component files
require_once CCS_PLUGIN_DIR . 'includes/admin/class-ccs-api-settings.php';
require_once CCS_PLUGIN_DIR . 'includes/admin/class-ccs-sync-controls.php';
require_once CCS_PLUGIN_DIR . 'includes/admin/class-ccs-logs-display.php';
require_once CCS_PLUGIN_DIR . 'includes/admin/index.php';

/**
 * Admin Page class
 */
class CCS_Admin_Page {
    /**
     * Logger instance
     *
     * @var CCS_Logger
     */
    private $logger;

    /**
     * Component instances
     */
    private $api_settings;
    private $sync_controls;
    private $logs_display;

    /**
     * Constructor
     */
    public function __construct() {
        global $canvas_course_sync;
        $this->logger = $canvas_course_sync->logger ?? new CCS_Logger();
        
        // Initialize components
        $this->api_settings = new CCS_API_Settings();
        $this->sync_controls = new CCS_Sync_Controls();
        $this->logs_display = new CCS_Logs_Display($this->logger);
        
        // Add admin menu
        add_action('admin_menu', array($this, 'add_admin_menu'));
        
        // Register settings
        add_action('admin_init', array($this->api_settings, 'register_settings'));
        
        // Add admin scripts and styles
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
    }

    /**
     * Add admin menu
     */
    public function add_admin_menu() {
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
     * Enqueue admin scripts and styles
     *
     * @param string $hook Current admin page
     */
    public function enqueue_scripts($hook) {
        if ('toplevel_page_canvas-course-sync' !== $hook) {
            return;
        }
        
        // Enqueue admin CSS
        wp_enqueue_style(
            'ccs-admin-css',
            CCS_PLUGIN_URL . 'assets/css/admin.css',
            array(),
            CCS_VERSION
        );
        
        // Enqueue admin JS
        wp_enqueue_script(
            'ccs-admin-js',
            CCS_PLUGIN_URL . 'assets/js/admin.js',
            array('jquery'),
            CCS_VERSION,
            true
        );
        
        // Localize script with data and all necessary nonces
        wp_localize_script(
            'ccs-admin-js',
            'ccsData',
            array(
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'syncNonce' => wp_create_nonce('ccs_sync_nonce'),
                'testConnectionNonce' => wp_create_nonce('ccs_test_connection_nonce'),
                'clearLogsNonce' => wp_create_nonce('ccs_clear_logs_nonce'),
                'getCoursesNonce' => wp_create_nonce('ccs_get_courses_nonce'),
                'syncStatusNonce' => wp_create_nonce('ccs_sync_status_nonce')
            )
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
                    $this->api_settings->render();
                    $this->sync_controls->render();
                    ?>
                </div>
                
                <div class="ccs-admin-sidebar">
                    <?php $this->logs_display->render(); ?>
                </div>
            </div>
        </div>
        <?php
    }
}
