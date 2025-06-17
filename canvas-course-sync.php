
<?php
/**
 * Plugin Name: Canvas Course Sync
 * Plugin URI: https://github.com/yourusername/canvas-course-sync
 * Description: A WordPress plugin to synchronize courses from Canvas LMS to a WordPress custom post type.
 * Version: 2.3.0
 * Author: Eyeth Studios
 * Author URI: http://eyethstudios.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: canvas-course-sync
 * Requires at least: 5.0
 * Tested up to: 6.4
 * Requires PHP: 7.0
 *
 * @package Canvas_Course_Sync
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('CCS_VERSION', '2.3.0');
define('CCS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('CCS_PLUGIN_URL', plugin_dir_url(__FILE__));
define('CCS_PLUGIN_FILE', __FILE__);

/**
 * Main Canvas Course Sync Class
 */
class Canvas_Course_Sync {
    
    /**
     * Single instance of the class
     */
    private static $instance = null;
    
    /**
     * Logger instance
     */
    public $logger = null;
    
    /**
     * Canvas API instance
     */
    public $api = null;
    
    /**
     * Importer instance
     */
    public $importer = null;

    /**
     * Get single instance
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        add_action('init', array($this, 'init_plugin'));
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
    }

    /**
     * Initialize plugin
     */
    public function init_plugin() {
        // Load dependencies
        $this->load_dependencies();
        
        // Initialize components
        $this->init_components();

        // Initialize admin functionality
        if (is_admin()) {
            $this->init_admin();
        }
    }

    /**
     * Load plugin dependencies
     */
    private function load_dependencies() {
        // Core includes
        require_once CCS_PLUGIN_DIR . 'includes/functions.php';
        require_once CCS_PLUGIN_DIR . 'includes/logger.php';
        require_once CCS_PLUGIN_DIR . 'includes/canvas-api.php';
        require_once CCS_PLUGIN_DIR . 'includes/importer.php';

        // Admin includes
        if (is_admin()) {
            require_once CCS_PLUGIN_DIR . 'includes/admin/class-ccs-admin-menu.php';
            require_once CCS_PLUGIN_DIR . 'includes/admin/class-ccs-admin-page.php';
            require_once CCS_PLUGIN_DIR . 'includes/admin/class-ccs-logs-display.php';
            require_once CCS_PLUGIN_DIR . 'includes/admin/index.php';
        }

        // Scheduler
        require_once CCS_PLUGIN_DIR . 'includes/class-ccs-scheduler.php';
    }

    /**
     * Initialize components
     */
    private function init_components() {
        // Initialize logger
        if (class_exists('CCS_Logger')) {
            $this->logger = new CCS_Logger();
            $this->logger->log('Plugin initialized', 'info');
        }

        // Initialize API
        if (class_exists('CCS_Canvas_API')) {
            $this->api = new CCS_Canvas_API();
            if ($this->logger) {
                $this->logger->log('Canvas API initialized', 'info');
            }
        }

        // Initialize importer
        if (class_exists('CCS_Importer')) {
            $this->importer = new CCS_Importer();
            if ($this->logger) {
                $this->logger->log('Importer initialized', 'info');
            }
        }
    }

    /**
     * Initialize admin functionality
     */
    private function init_admin() {
        if (class_exists('CCS_Admin_Menu')) {
            $admin_menu = new CCS_Admin_Menu();
            add_action('admin_menu', array($admin_menu, 'add_menu'));
            
            // Enqueue admin scripts and styles
            add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
        }
    }

    /**
     * Enqueue admin assets
     */
    public function enqueue_admin_assets($hook) {
        // Only load on Canvas Course Sync admin pages
        if (strpos($hook, 'canvas-course-sync') === false) {
            return;
        }

        // Force jQuery to load in footer to ensure proper initialization
        wp_enqueue_script('jquery');

        // Enqueue admin JavaScript with jQuery dependency
        wp_enqueue_script(
            'ccs-admin-js',
            CCS_PLUGIN_URL . 'assets/js/admin.js',
            array('jquery'),
            CCS_VERSION,
            true // Load in footer
        );

        // Enqueue admin CSS
        wp_enqueue_style(
            'ccs-admin-css',
            CCS_PLUGIN_URL . 'assets/css/admin.css',
            array(),
            CCS_VERSION
        );

        // Localize script with AJAX data - this is critical for button functionality
        wp_localize_script('ccs-admin-js', 'ccsAjax', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('ccs_admin_nonce'),
            'testConnectionNonce' => wp_create_nonce('ccs_test_connection'),
            'getCoursesNonce' => wp_create_nonce('ccs_get_courses'),
            'syncCoursesNonce' => wp_create_nonce('ccs_sync_courses'),
            'clearLogsNonce' => wp_create_nonce('ccs_clear_logs'),
            'syncStatusNonce' => wp_create_nonce('ccs_sync_status'),
            'autoSyncNonce' => wp_create_nonce('ccs_auto_sync'),
            'debug' => defined('WP_DEBUG') && WP_DEBUG
        ));
        
        // Add inline debug script
        if (defined('WP_DEBUG') && WP_DEBUG) {
            wp_add_inline_script('ccs-admin-js', '
                console.log("CCS: Script enqueued on hook:", "' . $hook . '");
                console.log("CCS: AJAX URL:", "' . admin_url('admin-ajax.php') . '");
            ', 'before');
        }
    }

    /**
     * Plugin activation
     */
    public function activate() {
        // Create upload directory for logs
        $upload_dir = wp_upload_dir();
        $log_dir = $upload_dir['basedir'] . '/canvas-course-sync/logs';
        
        if (!file_exists($log_dir)) {
            wp_mkdir_p($log_dir);
        }

        // Set default options
        add_option('ccs_version', CCS_VERSION);
        
        // Flush rewrite rules
        flush_rewrite_rules();
    }

    /**
     * Plugin deactivation
     */
    public function deactivate() {
        // Clear scheduled events if any
        wp_clear_scheduled_hook('ccs_auto_sync');
        
        // Flush rewrite rules
        flush_rewrite_rules();
    }
}

// Initialize the plugin
function canvas_course_sync() {
    return Canvas_Course_Sync::get_instance();
}

// Start the plugin
canvas_course_sync();
