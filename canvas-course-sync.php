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

/**
 * Check if required files exist before including them
 */
function ccs_check_required_files() {
    $required_files = array(
        CCS_PLUGIN_DIR . 'includes/functions.php',
        CCS_PLUGIN_DIR . 'includes/logger.php',
        CCS_PLUGIN_DIR . 'includes/canvas-api.php',
        CCS_PLUGIN_DIR . 'includes/importer.php'
    );
    
    foreach ($required_files as $file) {
        if (!file_exists($file)) {
            add_action('admin_notices', function() use ($file) {
                echo '<div class="notice notice-error"><p>Canvas Course Sync: Missing required file: ' . esc_html(basename($file)) . '</p></div>';
            });
            return false;
        }
    }
    return true;
}

// Only proceed if all required files exist
if (!ccs_check_required_files()) {
    return;
}

// Include required files in proper order
require_once CCS_PLUGIN_DIR . 'includes/functions.php';
require_once CCS_PLUGIN_DIR . 'includes/logger.php';
require_once CCS_PLUGIN_DIR . 'includes/canvas-api.php';
require_once CCS_PLUGIN_DIR . 'includes/importer.php';

// Admin includes - only load if in admin and files exist
if (is_admin()) {
    $admin_files = array(
        CCS_PLUGIN_DIR . 'includes/admin/index.php',
        CCS_PLUGIN_DIR . 'includes/admin/class-ccs-admin-menu.php',
        CCS_PLUGIN_DIR . 'includes/admin/class-ccs-admin-page.php',
        CCS_PLUGIN_DIR . 'includes/admin/class-ccs-logs-display.php'
    );
    
    foreach ($admin_files as $file) {
        if (file_exists($file)) {
            require_once $file;
        }
    }
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
        add_action('admin_init', array($this, 'admin_init'));
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        
        // Initialize components with error handling
        $this->init_components();
    }

    /**
     * Initialize components safely
     */
    private function init_components() {
        try {
            // Initialize logger first
            if (class_exists('CCS_Logger')) {
                $this->logger = new CCS_Logger();
                $this->logger->log('Plugin components initializing...');
            }
            
            // Initialize API
            if (class_exists('CCS_Canvas_API')) {
                $this->api = new CCS_Canvas_API();
            }
            
            // Initialize importer
            if (class_exists('CCS_Course_Importer')) {
                $this->importer = new CCS_Course_Importer();
            }
            
            // Initialize admin components
            if (is_admin() && class_exists('CCS_Admin_Menu')) {
                $this->admin_menu = new CCS_Admin_Menu();
            }
            
            if ($this->logger) {
                $this->logger->log('Plugin components initialized successfully');
            }
        } catch (Exception $e) {
            // Log error if logger is available, otherwise fail silently
            if ($this->logger) {
                $this->logger->log('Error initializing plugin components: ' . $e->getMessage(), 'error');
            }
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
     * Admin initialization
     */
    public function admin_init() {
        // Register settings
        register_setting('ccs_settings', 'ccs_canvas_domain', array(
            'sanitize_callback' => 'esc_url_raw',
            'default' => ''
        ));
        register_setting('ccs_settings', 'ccs_canvas_token', array(
            'sanitize_callback' => 'sanitize_text_field',
            'default' => ''
        ));
        register_setting('ccs_settings', 'ccs_notification_email', array(
            'sanitize_callback' => 'sanitize_email',
            'default' => get_option('admin_email')
        ));
        register_setting('ccs_settings', 'ccs_auto_sync_enabled', array(
            'sanitize_callback' => 'absint',
            'default' => 0
        ));
    }

    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        if ($this->admin_menu && method_exists($this->admin_menu, 'add_menu')) {
            $this->admin_menu->add_menu();
        } else {
            // Fallback menu creation
            add_menu_page(
                __('Canvas Course Sync', 'canvas-course-sync'),
                __('Canvas Sync', 'canvas-course-sync'),
                'manage_options',
                'canvas-course-sync',
                array($this, 'display_admin_page'),
                'dashicons-update',
                30
            );
            
            add_submenu_page(
                'canvas-course-sync',
                __('Sync Logs', 'canvas-course-sync'),
                __('Logs', 'canvas-course-sync'),
                'manage_options',
                'canvas-course-sync-logs',
                array($this, 'display_logs_page')
            );
        }
    }

    /**
     * Display admin page
     */
    public function display_admin_page() {
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

    /**
     * Display logs page
     */
    public function display_logs_page() {
        if (class_exists('CCS_Logs_Display')) {
            $logs_display = new CCS_Logs_Display();
            $logs_display->render();
        } else {
            echo '<div class="wrap">';
            echo '<h1>' . esc_html__('Canvas Course Sync - Logs', 'canvas-course-sync') . '</h1>';
            echo '<div class="notice notice-error"><p>';
            echo esc_html__('Logs display class not found. Please check plugin installation.', 'canvas-course-sync');
            echo '</p></div>';
            echo '</div>';
        }
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

        // Check if admin script exists
        $admin_js_path = CCS_PLUGIN_URL . 'assets/js/admin.js';
        $admin_js_file = CCS_PLUGIN_DIR . 'assets/js/admin.js';
        
        if (file_exists($admin_js_file)) {
            // Enqueue admin script
            wp_enqueue_script(
                'ccs-admin',
                $admin_js_path,
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
        }

        // Check if admin CSS exists
        $admin_css_path = CCS_PLUGIN_URL . 'assets/css/admin.css';
        $admin_css_file = CCS_PLUGIN_DIR . 'assets/css/admin.css';
        
        if (file_exists($admin_css_file)) {
            // Enqueue admin styles
            wp_enqueue_style(
                'ccs-admin',
                $admin_css_path,
                array(),
                CCS_VERSION
            );
        }
    }
}

/**
 * Initialize plugin safely
 */
function canvas_course_sync() {
    static $instance = null;
    if ($instance === null) {
        try {
            $instance = new Canvas_Course_Sync();
        } catch (Exception $e) {
            // Handle initialization error gracefully
            add_action('admin_notices', function() use ($e) {
                echo '<div class="notice notice-error"><p>Canvas Course Sync initialization error: ' . esc_html($e->getMessage()) . '</p></div>';
            });
            return null;
        }
    }
    return $instance;
}

/**
 * Plugin activation hook
 */
function ccs_activate_plugin() {
    // Initialize logger to create database table
    if (class_exists('CCS_Logger')) {
        $logger = new CCS_Logger();
        $logger->log('Canvas Course Sync plugin activated');
    }
    
    // Register custom post type
    if (function_exists('ccs_register_courses_post_type')) {
        ccs_register_courses_post_type();
    }
    
    // Flush rewrite rules
    flush_rewrite_rules();
    
    // Set default options
    add_option('ccs_canvas_domain', '');
    add_option('ccs_canvas_token', '');
    add_option('ccs_notification_email', get_option('admin_email'));
    add_option('ccs_auto_sync_enabled', 0);
}

/**
 * Plugin deactivation hook
 */
function ccs_deactivate_plugin() {
    // Log deactivation
    if (class_exists('CCS_Logger')) {
        $logger = new CCS_Logger();
        $logger->log('Canvas Course Sync plugin deactivated');
    }
    
    // Flush rewrite rules
    flush_rewrite_rules();
}

// Start the plugin only after WordPress is fully loaded
add_action('plugins_loaded', function() {
    canvas_course_sync();
});

// Activation hook
register_activation_hook(__FILE__, 'ccs_activate_plugin');

// Deactivation hook
register_deactivation_hook(__FILE__, 'ccs_deactivate_plugin');
