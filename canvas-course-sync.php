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
 * Requires PHP: 7.4
 * Network: false
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
define('CCS_PLUGIN_BASENAME', plugin_basename(__FILE__));

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
        add_action('plugins_loaded', array($this, 'init_plugin'));
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
        
        // Add plugin action links
        add_filter('plugin_action_links_' . CCS_PLUGIN_BASENAME, array($this, 'add_action_links'));
    }

    /**
     * Initialize plugin
     */
    public function init_plugin() {
        // Check WordPress version
        if (!$this->check_requirements()) {
            return;
        }
        
        // Load textdomain
        load_plugin_textdomain('canvas-course-sync', false, dirname(plugin_basename(__FILE__)) . '/languages');
        
        // Load dependencies
        $this->load_dependencies();
        
        // Initialize components
        $this->init_components();

        // Initialize admin functionality
        if (is_admin()) {
            $this->init_admin();
        }
        
        // Add WordPress hooks
        add_action('init', array($this, 'init_hooks'));
    }

    /**
     * Check plugin requirements
     */
    private function check_requirements() {
        global $wp_version;
        
        if (version_compare($wp_version, '5.0', '<')) {
            add_action('admin_notices', array($this, 'wp_version_notice'));
            return false;
        }
        
        if (version_compare(PHP_VERSION, '7.4', '<')) {
            add_action('admin_notices', array($this, 'php_version_notice'));
            return false;
        }
        
        return true;
    }

    /**
     * WordPress version notice
     */
    public function wp_version_notice() {
        echo '<div class="notice notice-error"><p>';
        echo esc_html__('Canvas Course Sync requires WordPress 5.0 or higher.', 'canvas-course-sync');
        echo '</p></div>';
    }

    /**
     * PHP version notice
     */
    public function php_version_notice() {
        echo '<div class="notice notice-error"><p>';
        echo esc_html__('Canvas Course Sync requires PHP 7.4 or higher.', 'canvas-course-sync');
        echo '</p></div>';
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
            // CRITICAL: Include AJAX handlers immediately
            require_once CCS_PLUGIN_DIR . 'includes/admin/index.php';
        }

        // Scheduler
        if (file_exists(CCS_PLUGIN_DIR . 'includes/class-ccs-scheduler.php')) {
            require_once CCS_PLUGIN_DIR . 'includes/class-ccs-scheduler.php';
        }
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
     * Initialize WordPress hooks
     */
    public function init_hooks() {
        // Add any additional hooks here
        do_action('ccs_init_hooks');
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
            
            // Add admin notices hook
            add_action('admin_notices', array($this, 'admin_notices'));
        }
    }

    /**
     * Display admin notices
     */
    public function admin_notices() {
        // Check if settings are configured
        $domain = get_option('ccs_canvas_domain');
        $token = get_option('ccs_canvas_token');
        
        if ((empty($domain) || empty($token)) && $this->is_plugin_page()) {
            echo '<div class="notice notice-warning"><p>';
            echo wp_kses_post(__('Canvas Course Sync is not fully configured. Please <a href="' . admin_url('admin.php?page=canvas-course-sync') . '">configure your Canvas settings</a>.', 'canvas-course-sync'));
            echo '</p></div>';
        }
    }

    /**
     * Check if we're on a plugin page
     */
    private function is_plugin_page() {
        $screen = get_current_screen();
        return $screen && strpos($screen->id, 'canvas-course-sync') !== false;
    }

    /**
     * Enqueue admin assets
     */
    public function enqueue_admin_assets($hook) {
        // Debug: Log the current hook
        error_log('CCS Debug: Current admin hook: ' . $hook);
        
        // Check if we're on any Canvas Course Sync admin page
        $plugin_pages = array(
            'toplevel_page_canvas-course-sync',
            'canvas-course-sync_page_canvas-course-sync-logs',
            'canvas-course-sync_page_canvas-course-sync-settings'
        );
        
        $is_plugin_page = in_array($hook, $plugin_pages) || strpos($hook, 'canvas-course-sync') !== false;
        
        if (!$is_plugin_page) {
            error_log('CCS Debug: Not a plugin page, skipping asset enqueue');
            return;
        }

        error_log('CCS Debug: Enqueuing admin assets for hook: ' . $hook);

        // Enqueue admin CSS
        wp_enqueue_style(
            'ccs-admin-css',
            CCS_PLUGIN_URL . 'assets/css/admin.css',
            array(),
            CCS_VERSION
        );

        // Enqueue jQuery
        wp_enqueue_script('jquery');

        // Enqueue test script first for debugging
        wp_enqueue_script(
            'ccs-test-js',
            CCS_PLUGIN_URL . 'assets/js/test-buttons.js',
            array('jquery'),
            CCS_VERSION,
            true
        );

        // Enqueue main admin JavaScript
        wp_enqueue_script(
            'ccs-admin-js',
            CCS_PLUGIN_URL . 'assets/js/admin.js',
            array('jquery', 'ccs-test-js'),
            CCS_VERSION,
            true
        );

        // Create nonces
        $test_nonce = wp_create_nonce('ccs_test_connection');
        $courses_nonce = wp_create_nonce('ccs_get_courses');
        $sync_nonce = wp_create_nonce('ccs_sync_courses');
        $logs_nonce = wp_create_nonce('ccs_clear_logs');
        $status_nonce = wp_create_nonce('ccs_sync_status');
        $auto_sync_nonce = wp_create_nonce('ccs_auto_sync');

        error_log('CCS Debug: Created nonces - test: ' . substr($test_nonce, 0, 10) . '..., courses: ' . substr($courses_nonce, 0, 10) . '...');

        // Localize script with AJAX data
        $localize_data = array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('ccs_admin_nonce'),
            'testConnectionNonce' => $test_nonce,
            'getCoursesNonce' => $courses_nonce,
            'syncCoursesNonce' => $sync_nonce,
            'clearLogsNonce' => $logs_nonce,
            'syncStatusNonce' => $status_nonce,
            'autoSyncNonce' => $auto_sync_nonce,
            'debug' => defined('WP_DEBUG') && WP_DEBUG,
            'strings' => array(
                'testing' => __('Testing...', 'canvas-course-sync'),
                'loading' => __('Loading...', 'canvas-course-sync'),
                'syncing' => __('Syncing...', 'canvas-course-sync'),
                'success' => __('Success!', 'canvas-course-sync'),
                'error' => __('Error', 'canvas-course-sync'),
                'selectCourses' => __('Please select at least one course to sync.', 'canvas-course-sync'),
            )
        );

        wp_localize_script('ccs-admin-js', 'ccsAjax', $localize_data);
        wp_localize_script('ccs-test-js', 'ccsAjax', $localize_data);
        
        error_log('CCS Debug: Localized script data with AJAX URL: ' . $localize_data['ajaxUrl']);
    }

    /**
     * Add action links to plugin page
     */
    public function add_action_links($links) {
        $settings_link = '<a href="' . admin_url('admin.php?page=canvas-course-sync') . '">' . __('Settings', 'canvas-course-sync') . '</a>';
        array_unshift($links, $settings_link);
        return $links;
    }

    /**
     * Plugin activation
     */
    public function activate() {
        // Call activation function
        if (function_exists('ccs_activate_plugin')) {
            ccs_activate_plugin();
        }
        
        // Update version
        update_option('ccs_version', CCS_VERSION);
        
        // Log activation
        if ($this->logger) {
            $this->logger->log('Plugin activated - version ' . CCS_VERSION);
        }
    }

    /**
     * Plugin deactivation
     */
    public function deactivate() {
        // Call deactivation function
        if (function_exists('ccs_deactivate_plugin')) {
            ccs_deactivate_plugin();
        }
        
        // Log deactivation
        if ($this->logger) {
            $this->logger->log('Plugin deactivated');
        }
    }
}

// Initialize the plugin
function canvas_course_sync() {
    return Canvas_Course_Sync::get_instance();
}

// Start the plugin
canvas_course_sync();
