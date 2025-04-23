
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

// Include admin component files
require_once CCS_PLUGIN_DIR . 'includes/admin/class-ccs-admin-menu.php';
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
    private $admin_menu;
    private $api_settings;
    private $sync_controls;
    private $logs_display;

    /**
     * Constructor
     */
    public function __construct() {
        global $canvas_course_sync;
        
        // Get the logger from the global instance or create a new one
        if (isset($canvas_course_sync) && isset($canvas_course_sync->logger)) {
            $this->logger = $canvas_course_sync->logger;
        } else {
            $this->logger = new CCS_Logger();
        }
        
        // Initialize components
        $this->admin_menu = new CCS_Admin_Menu();
        $this->api_settings = new CCS_API_Settings();
        $this->sync_controls = new CCS_Sync_Controls();
        $this->logs_display = new CCS_Logs_Display($this->logger);
        
        // Register settings
        add_action('admin_init', array($this->api_settings, 'register_settings'));
        
        // Add admin scripts and styles
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
        
        // Register component rendering hooks
        add_action('ccs_render_api_settings', array($this->api_settings, 'render'));
        add_action('ccs_render_sync_controls', array($this->sync_controls, 'render'));
        add_action('ccs_render_logs_display', array($this->logs_display, 'render'));
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
}
