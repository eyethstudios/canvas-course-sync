<?php
/**
 * Plugin Name: Canvas Course Sync
 * Plugin URI: https://github.com/eyethstudios/canvas-course-sync
 * Description: Sync course information from Canvas LMS to WordPress
 * Version: 3.1.2
 * Author: Eyeth Studios
 * Author URI: http://eyethstudios.com
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

// !!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!
// IMPORTANT: When adding new features or making significant changes, remember to:
// 1. Update the version number in the plugin header above
// 2. Update the CCS_VERSION constant below
// 3. Update the Stable tag in readme.txt
// 4. Add a changelog entry in readme.txt
// !!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!

// Define plugin constants
define('CCS_VERSION', '3.1.2');
define('CCS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('CCS_PLUGIN_URL', plugin_dir_url(__FILE__));
define('CCS_PLUGIN_BASENAME', plugin_basename(__FILE__));
define('CCS_PLUGIN_FILE', __FILE__);
define('CCS_GITHUB_REPO', 'eyethstudios/canvas-course-sync');
define('CCS_DEFAULT_CATALOG_URL', 'https://learn.nationaldeafcenter.org/');

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
        
        // Initialize GitHub updater early so AJAX handlers are registered
        if (is_admin()) {
            add_action('plugins_loaded', array($this, 'init_github_updater'), 5);
        }
    }

    /**
     * Initialize GitHub updater
     */
    public function init_github_updater() {
        // Make sure the class exists before instantiating
        if (class_exists('CCS_GitHub_Updater')) {
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
            
            // Add AJAX handlers
            $this->register_ajax_handlers();
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
            'includes/class-ccs-github-updater.php',
            'includes/class-ccs-database-manager.php',
            'includes/class-ccs-catalog-validator.php',
            'includes/class-ccs-slug-generator.php',
            'includes/handlers/class-ccs-media-handler.php',
            'includes/handlers/class-ccs-content-handler.php'
        );
        
        // Load AJAX handlers
        require_once CCS_PLUGIN_DIR . 'includes/ajax-handlers.php';
        
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
                'includes/admin/class-ccs-email-settings.php',
                'includes/admin/class-ccs-version-manager.php',
                'includes/admin/class-ccs-error-handler.php'
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
     * Initialize components safely with dependency injection
     */
    public function init_components() {
        // Initialize logger first
        if (class_exists('CCS_Logger')) {
            $this->logger = new CCS_Logger();
        }
        
        // Initialize API with logger dependency
        if (class_exists('CCS_Canvas_API')) {
            $this->api = new CCS_Canvas_API($this->logger);
        }
        
        // Initialize importer with all dependencies
        if (class_exists('CCS_Importer')) {
            // Create other dependencies first
            $media_handler = class_exists('CCS_Media_Handler') ? new CCS_Media_Handler() : null;
            $content_handler = class_exists('CCS_Content_Handler') ? new CCS_Content_Handler() : null;
            $db_manager = class_exists('CCS_Database_Manager') ? new CCS_Database_Manager($this->logger) : null;
            $slug_generator = class_exists('CCS_Slug_Generator') ? new CCS_Slug_Generator($this->logger) : null;
            
            // Only create importer if all dependencies are available
            if ($this->logger && $this->api && $media_handler && $content_handler && $db_manager && $slug_generator) {
                $this->importer = new CCS_Importer(
                    $this->logger,
                    $this->api,
                    $media_handler,
                    $content_handler,
                    $db_manager,
                    $slug_generator
                );
            }
        }
        
        // Initialize scheduler with dependencies
        if (class_exists('CCS_Scheduler')) {
            $this->scheduler = new CCS_Scheduler($this->logger, $this->api, $this->importer);
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
        register_setting('ccs_settings', 'ccs_catalog_url', array(
            'type' => 'string',
            'sanitize_callback' => 'esc_url_raw',
            'default' => CCS_DEFAULT_CATALOG_URL,
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
        // Define plugin pages where scripts should load
        $plugin_pages = array(
            'canvas-course-sync',
            'canvas-course-sync-settings', 
            'canvas-course-sync-logs'
        );
        
        $is_plugin_page = false;
        foreach ($plugin_pages as $page) {
            if (strpos($hook, $page) !== false) {
                $is_plugin_page = true;
                break;
            }
        }
        
        // Only load on plugin pages or plugins.php for updater
        if (!$is_plugin_page && $hook !== 'plugins.php') {
            return;
        }
        
        // Enqueue styles
        $this->enqueue_admin_styles();
        
        // Enqueue scripts based on page
        if ($is_plugin_page) {
            $this->enqueue_plugin_scripts();
        }
        
        if ($hook === 'plugins.php') {
            $this->enqueue_updater_script();
        }
    }
    
    /**
     * Enqueue admin styles
     */
    private function enqueue_admin_styles() {
        wp_enqueue_style(
            'ccs-admin-css',
            plugin_dir_url(__FILE__) . 'assets/css/admin.css',
            array(),
            CCS_VERSION
        );
    }
    
    /**
     * Enqueue main plugin scripts and localize data
     */
    private function enqueue_plugin_scripts() {
        // Enqueue consolidated admin JavaScript
        wp_enqueue_script(
            'ccs-admin-js',
            plugin_dir_url(__FILE__) . 'assets/js/admin.js',
            array('jquery'),
            CCS_VERSION,
            true
        );
        
        // Localize script with comprehensive AJAX data
        wp_localize_script('ccs-admin-js', 'ccsAjax', $this->get_ajax_data());
    }
    
    /**
     * Enqueue updater script for plugins page
     */
    private function enqueue_updater_script() {
        wp_enqueue_script(
            'ccs-updater-js',
            plugin_dir_url(__FILE__) . 'assets/js/updater.js',
            array(),
            CCS_VERSION,
            true
        );
        
        // Localize updater with its own nonce
        wp_localize_script('ccs-updater-js', 'ccsUpdaterData', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('ccs_check_updates')
        ));
    }
    
    /**
     * Get AJAX data for localization
     *
     * @return array Consolidated AJAX data
     */
    private function get_ajax_data() {
        return array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonces' => array(
                'testConnection' => wp_create_nonce('ccs_test_connection'),
                'getCourses' => wp_create_nonce('ccs_get_courses'),
                'syncCourses' => wp_create_nonce('ccs_sync_courses'),
                'syncStatus' => wp_create_nonce('ccs_sync_status'),
                'clearLogs' => wp_create_nonce('ccs_clear_logs'),
                'refreshLogs' => wp_create_nonce('ccs_refresh_logs'),
                'runAutoSync' => wp_create_nonce('ccs_run_auto_sync'),
                'omitCourses' => wp_create_nonce('ccs_omit_courses'),
                'restoreOmitted' => wp_create_nonce('ccs_restore_omitted'),
                'logError' => wp_create_nonce('ccs_log_js_error'),
                'toggleAutoSync' => wp_create_nonce('ccs_toggle_auto_sync'),
                'checkUpdates' => wp_create_nonce('ccs_check_updates')
            ),
            'messages' => array(
                'confirmSync' => __('Are you sure you want to sync the selected courses?', 'canvas-course-sync'),
                'confirmOmit' => __('Are you sure you want to omit the selected courses?', 'canvas-course-sync'),
                'confirmRestore' => __('Are you sure you want to restore all omitted courses?', 'canvas-course-sync'),
                'confirmClearLogs' => __('Are you sure you want to clear all logs?', 'canvas-course-sync'),
                'noCoursesSelected' => __('Please select at least one course.', 'canvas-course-sync'),
                'connectionSuccess' => __('Connection successful!', 'canvas-course-sync'),
                'connectionFailed' => __('Connection failed. Please check your settings.', 'canvas-course-sync')
            ),
            'settings' => array(
                'debugMode' => defined('WP_DEBUG') && WP_DEBUG,
                'pluginVersion' => CCS_VERSION
            )
        );
    }
    
    /**
     * Register AJAX handlers (now loaded from separate file)
     */
    private function register_ajax_handlers() {
        // AJAX handlers are now registered in includes/ajax-handlers.php
        // This method exists for backward compatibility
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
