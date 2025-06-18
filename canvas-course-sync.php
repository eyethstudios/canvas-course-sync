
<?php
/**
 * Plugin Name: Canvas Course Sync
 * Plugin URI: https://github.com/eyethstudios/canvas-course-sync
 * Description: Synchronize courses from Canvas LMS to WordPress with full API integration and course management.
 * Version: 1.0.0
 * Author: Eyeth Studios
 * Author URI: http://eyethstudios.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: canvas-course-sync
 * Domain Path: /languages
 * Requires at least: 5.0
 * Tested up to: 6.4
 * Requires PHP: 7.4
 * Network: false
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('CCS_VERSION', '1.0.0');
define('CCS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('CCS_PLUGIN_URL', plugin_dir_url(__FILE__));
define('CCS_PLUGIN_FILE', __FILE__);

// Include required files in proper order
require_once CCS_PLUGIN_DIR . 'includes/functions.php';
require_once CCS_PLUGIN_DIR . 'includes/logger.php';
require_once CCS_PLUGIN_DIR . 'includes/canvas-api.php';
require_once CCS_PLUGIN_DIR . 'includes/importer.php';

// Admin includes
if (is_admin()) {
    require_once CCS_PLUGIN_DIR . 'includes/admin/index.php';
    require_once CCS_PLUGIN_DIR . 'includes/admin/class-ccs-admin-menu.php';
    require_once CCS_PLUGIN_DIR . 'includes/admin/class-ccs-admin-page.php';
    require_once CCS_PLUGIN_DIR . 'includes/admin/class-ccs-logs-display.php';
}

/**
 * Main plugin class
 */
class Canvas_Course_Sync {
    /**
     * Logger instance
     */
    public $logger;

    /**
     * Canvas API instance
     */
    public $api;

    /**
     * Course importer instance
     */
    public $importer;

    /**
     * Admin menu instance
     */
    public $admin_menu;

    /**
     * Constructor
     */
    public function __construct() {
        // Hook into WordPress initialization
        add_action('init', array($this, 'init'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        
        // Initialize components
        $this->logger = new CCS_Logger();
        $this->api = new CCS_Canvas_API();
        $this->importer = new CCS_Course_Importer();
        
        // Initialize admin components
        if (is_admin()) {
            $this->admin_menu = new CCS_Admin_Menu();
        }
    }

    /**
     * Initialize plugin
     */
    public function init() {
        // Load text domain for translations
        load_plugin_textdomain('canvas-course-sync', false, dirname(plugin_basename(__FILE__)) . '/languages');
    }

    /**
     * Enqueue admin scripts and styles
     */
    public function enqueue_admin_scripts($hook) {
        // Only load on our admin pages
        if (!in_array($hook, array('toplevel_page_canvas-course-sync', 'canvas-sync_page_canvas-course-sync-logs'))) {
            return;
        }

        // Enqueue jQuery if not already loaded
        wp_enqueue_script('jquery');

        // Enqueue admin script
        wp_enqueue_script(
            'ccs-admin',
            CCS_PLUGIN_URL . 'assets/js/admin.js',
            array('jquery'),
            CCS_VERSION,
            true
        );

        // Localize script with AJAX data
        wp_localize_script('ccs-admin', 'ccsAjax', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'testConnectionNonce' => wp_create_nonce('ccs_test_connection'),
            'getCoursesNonce' => wp_create_nonce('ccs_get_courses'),
            'syncCoursesNonce' => wp_create_nonce('ccs_sync_courses'),
            'clearLogsNonce' => wp_create_nonce('ccs_clear_logs'),
            'autoSyncNonce' => wp_create_nonce('ccs_auto_sync'),
            'syncStatusNonce' => wp_create_nonce('ccs_sync_status'),
            'strings' => array(
                'testing' => __('Testing...', 'canvas-course-sync'),
                'loading' => __('Loading...', 'canvas-course-sync'),
                'syncing' => __('Syncing...', 'canvas-course-sync'),
                'selectCourses' => __('Please select at least one course to sync.', 'canvas-course-sync'),
            ),
        ));

        // Enqueue admin styles
        wp_enqueue_style(
            'ccs-admin',
            CCS_PLUGIN_URL . 'assets/css/admin.css',
            array(),
            CCS_VERSION
        );
    }
}

/**
 * Initialize plugin
 */
function canvas_course_sync() {
    static $instance = null;
    if ($instance === null) {
        $instance = new Canvas_Course_Sync();
    }
    return $instance;
}

// Start the plugin
canvas_course_sync();

// Activation hook
register_activation_hook(__FILE__, 'ccs_activate_plugin');

// Deactivation hook
register_deactivation_hook(__FILE__, 'ccs_deactivate_plugin');
