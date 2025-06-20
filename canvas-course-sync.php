
<?php
/**
 * Plugin Name: Canvas Course Sync
 * Plugin URI: https://github.com/adamdehaven/canvas-course-sync
 * Description: Sync course information from Canvas LMS to WordPress
 * Version: 2.3.7
 * Author: Adam DeHaven
 * Author URI: https://adamdehaven.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: canvas-course-sync
 * Domain Path: /languages
 * 
 * @package Canvas_Course_Sync
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('CCS_VERSION', '2.3.6');
define('CCS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('CCS_PLUGIN_URL', plugin_dir_url(__FILE__));
define('CCS_PLUGIN_BASENAME', plugin_basename(__FILE__));
define('CCS_PLUGIN_FILE', __FILE__);
define('CCS_GITHUB_REPO', 'eyethstudios/canvas-course-sync');

/**
 * Main plugin class
 */
class Canvas_Course_Sync {
    /**
     * Single instance
     */
    private static $instance = null;

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
     * Scheduler instance
     */
    public $scheduler;

    /**
     * GitHub updater instance
     */
    public $github_updater;

    /**
     * Get instance
     */
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        // Hook into WordPress initialization
        add_action('init', array($this, 'init'));
        add_action('admin_init', array($this, 'admin_init'));
        add_action('plugins_loaded', array($this, 'load_plugin'), 10);
        
        // Add WordPress hooks for proper integration
        add_action('wp_loaded', array($this, 'wp_loaded'));
        add_filter('plugin_action_links_' . plugin_basename(__FILE__), array($this, 'add_plugin_action_links'));
        
        // Initialize GitHub updater
        add_action('init', array($this, 'init_github_updater'));
    }

    /**
     * Initialize GitHub updater
     */
    public function init_github_updater() {
        if (is_admin()) {
            $this->github_updater = new CCS_GitHub_Updater(CCS_PLUGIN_FILE, CCS_GITHUB_REPO, CCS_VERSION);
        }
    }

    /**
     * Load plugin components
     */
    public function load_plugin() {
        // Load required files first
        $this->load_required_files();
        
        // Initialize components after WordPress is fully loaded
        add_action('wp_loaded', array($this, 'init_components'));
        
        // Initialize admin
        if (is_admin()) {
            add_action('admin_menu', array($this, 'add_admin_menu'));
            add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        }
    }

    /**
     * Load required files
     */
    private function load_required_files() {
        $required_files = array(
            'includes/functions.php',
            'includes/logger.php',
            'includes/canvas-api.php',
            'includes/importer.php',
            'includes/class-ccs-scheduler.php',
            'includes/class-ccs-github-updater.php'
        );
        
        foreach ($required_files as $file) {
            $file_path = CCS_PLUGIN_DIR . $file;
            if (file_exists($file_path)) {
                require_once $file_path;
            }
        }
        
        // Load admin files if in admin
        if (is_admin()) {
            $admin_files = array(
                'includes/admin/index.php',
                'includes/admin/class-ccs-admin-menu.php',
                'includes/admin/class-ccs-admin-page.php',
                'includes/admin/class-ccs-logs-display.php',
                'includes/admin/class-ccs-email-settings.php'
            );
            
            foreach ($admin_files as $file) {
                $file_path = CCS_PLUGIN_DIR . $file;
                if (file_exists($file_path)) {
                    require_once $file_path;
                }
            }
        }
    }

    /**
     * Initialize components safely
     */
    public function init_components() {
        // Initialize logger first
        if (class_exists('CCS_Logger')) {
            $this->logger = new CCS_Logger();
        }
        
        // Initialize API
        if (class_exists('CCS_Canvas_API')) {
            $this->api = new CCS_Canvas_API();
        }
        
        // Initialize importer
        if (class_exists('CCS_Course_Importer')) {
            $this->importer = new CCS_Course_Importer();
        }
        
        // Initialize scheduler
        if (class_exists('CCS_Scheduler')) {
            $this->scheduler = new CCS_Scheduler();
        }
        
        // Initialize admin components
        if (is_admin() && class_exists('CCS_Admin_Menu')) {
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
     * WordPress loaded hook
     */
    public function wp_loaded() {
        // Additional initialization after WordPress is fully loaded
        do_action('ccs_loaded');
    }

    /**
     * Admin initialization
     */
    public function admin_init() {
        // Register settings with consistent naming
        register_setting('ccs_settings', 'ccs_canvas_domain', array(
            'type' => 'string',
            'sanitize_callback' => 'esc_url_raw',
            'default' => '',
            'show_in_rest' => false
        ));
        register_setting('ccs_settings', 'ccs_canvas_token', array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => '',
            'show_in_rest' => false
        ));
        register_setting('ccs_settings', 'ccs_notification_email', array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_email',
            'default' => get_option('admin_email'),
            'show_in_rest' => false
        ));
        register_setting('ccs_settings', 'ccs_auto_sync_enabled', array(
            'type' => 'boolean',
            'sanitize_callback' => 'rest_sanitize_boolean',
            'default' => false,
            'show_in_rest' => false
        ));
    }

    /**
     * Add plugin action links
     */
    public function add_plugin_action_links($links) {
        $settings_link = '<a href="' . admin_url('admin.php?page=canvas-course-sync') . '">' . __('Settings', 'canvas-course-sync') . '</a>';
        array_unshift($links, $settings_link);
        return $links;
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
        // Only load on our plugin pages
        if (strpos($hook, 'canvas-course-sync') === false && $hook !== 'plugins.php') {
            return;
        }
        
        // Enqueue admin CSS
        wp_enqueue_style(
            'ccs-admin-css',
            plugin_dir_url(__FILE__) . 'assets/css/admin.css',
            array(),
            CCS_VERSION
        );
        
        // Enqueue admin JavaScript as regular script (not module)
        wp_enqueue_script(
            'ccs-admin-js',
            plugin_dir_url(__FILE__) . 'assets/js/admin.js',
            array('jquery'),
            CCS_VERSION,
            true
        );
        
        // Enqueue updater JavaScript on plugins page
        if ($hook === 'plugins.php') {
            wp_enqueue_script(
                'ccs-updater-js',
                plugin_dir_url(__FILE__) . 'assets/js/modules/updater.js',
                array(),
                CCS_VERSION,
                true
            );
        }
        
        // Localize script with AJAX data
        wp_localize_script('ccs-admin-js', 'ccsAjax', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'testConnectionNonce' => wp_create_nonce('ccs_test_connection'),
            'getCoursesNonce' => wp_create_nonce('ccs_get_courses'),
            'syncCoursesNonce' => wp_create_nonce('ccs_sync_courses'),
            'syncStatusNonce' => wp_create_nonce('ccs_sync_status'),
            'clearLogsNonce' => wp_create_nonce('ccs_clear_logs'),
            'refreshLogsNonce' => wp_create_nonce('ccs_refresh_logs'),
            'runAutoSyncNonce' => wp_create_nonce('ccs_run_auto_sync')
        ));
        
        // Add updater nonce for plugins page
        if ($hook === 'plugins.php') {
            wp_localize_script('ccs-updater-js', 'ccsUpdaterNonce', wp_create_nonce('ccs_check_updates'));
        }
        
        error_log('CCS Debug: Admin scripts enqueued for hook: ' . $hook);
    }
}

/**
 * Get plugin instance
 */
function canvas_course_sync() {
    return Canvas_Course_Sync::get_instance();
}

/**
 * Plugin activation hook
 */
function ccs_activate_plugin() {
    // Set default options with proper WordPress option handling
    add_option('ccs_canvas_domain', '');
    add_option('ccs_canvas_token', '');
    add_option('ccs_notification_email', get_option('admin_email'));
    add_option('ccs_auto_sync_enabled', 0);
    
    // Create logger table
    if (class_exists('CCS_Logger')) {
        $logger = new CCS_Logger();
        // Force table creation on activation
        $logger->ensure_table_exists();
    }
    
    // Flush rewrite rules
    flush_rewrite_rules();
    
    // Log activation
    do_action('ccs_plugin_activated');
}

/**
 * Plugin deactivation hook
 */
function ccs_deactivate_plugin() {
    // Flush rewrite rules
    flush_rewrite_rules();
    
    // Log deactivation
    do_action('ccs_plugin_deactivated');
}

// Initialize plugin
canvas_course_sync();

// Activation hook
register_activation_hook(__FILE__, 'ccs_activate_plugin');

// Deactivation hook
register_deactivation_hook(__FILE__, 'ccs_deactivate_plugin');
