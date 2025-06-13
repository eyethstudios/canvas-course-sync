<?php
/**
 * Plugin Name: Canvas Course Sync
 * Plugin URI: https://eyethstudios.com
 * Description: Synchronize courses from Canvas LMS to WordPress custom post type "courses"
 * Version: 2.0.0
 * Author: Eyeth Studios
 * Author URI: https://eyethstudios.com
 * Text Domain: canvas-course-sync
 * Domain Path: /languages
 * License: GPL v2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 * Network: false
 * Requires at least: 5.0
 * Tested up to: 6.4
 * Requires PHP: 7.4
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('CCS_VERSION', '2.0.0');
define('CCS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('CCS_PLUGIN_URL', plugin_dir_url(__FILE__));
define('CCS_PLUGIN_BASENAME', plugin_basename(__FILE__));
define('CCS_PLUGIN_FILE', __FILE__);

// Prevent multiple initializations
if (class_exists('Canvas_Course_Sync')) {
    return;
}

/**
 * Main plugin class
 */
class Canvas_Course_Sync {
    /**
     * Instance of this class
     *
     * @var Canvas_Course_Sync
     */
    protected static $instance = null;

    /**
     * Plugin file path - required by WordPress
     *
     * @var string
     */
    public $plugin = '';

    /**
     * Plugin basename - required by WordPress
     *
     * @var string
     */
    public $plugin_basename = '';

    /**
     * Logger instance
     *
     * @var CCS_Logger
     */
    public $logger;

    /**
     * Canvas API instance
     *
     * @var CCS_Canvas_API
     */
    public $api;

    /**
     * Importer instance
     *
     * @var CCS_Importer
     */
    public $importer;

    /**
     * Scheduler instance
     *
     * @var CCS_Scheduler
     */
    public $scheduler;

    /**
     * Constructor
     */
    public function __construct() {
        // Set required plugin properties FIRST
        $this->plugin = __FILE__;
        $this->plugin_basename = plugin_basename(__FILE__);
        
        // Initialize WordPress hooks
        $this->init_hooks();
        
        // Load dependencies
        $this->load_dependencies();
        
        // Initialize components after dependencies are loaded
        add_action('plugins_loaded', array($this, 'init_components'), 15);
    }

    /**
     * Return an instance of this class
     *
     * @return Canvas_Course_Sync A single instance of this class
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Initialize WordPress hooks
     */
    private function init_hooks() {
        // Register activation hook
        register_activation_hook(__FILE__, array($this, 'activate_plugin'));

        // Register deactivation hook
        register_deactivation_hook(__FILE__, array($this, 'deactivate_plugin'));

        // Load text domain
        add_action('plugins_loaded', array($this, 'load_textdomain'));
        
        // Initialize admin functionality only in admin
        if (is_admin()) {
            add_action('admin_init', array($this, 'init_admin'));
            add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
            // Add settings link with correct basename
            add_filter('plugin_action_links_' . CCS_PLUGIN_BASENAME, array($this, 'add_settings_link'));
            // Add admin menu with priority to ensure it loads
            add_action('admin_menu', array($this, 'add_admin_menu'), 10);
        }
        
        // Register metabox for course link
        add_action('add_meta_boxes', array($this, 'register_course_metaboxes'));
        
        // Add sync status column to courses list
        add_filter('manage_courses_posts_columns', array($this, 'add_sync_status_column'));
        add_action('manage_courses_posts_custom_column', array($this, 'display_sync_status_column'), 10, 2);
    }

    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        // Ensure we have the capability to add menu
        if (!current_user_can('manage_options')) {
            return;
        }

        // Add main menu page under Settings
        $hook = add_options_page(
            __('Canvas Course Sync', 'canvas-course-sync'),
            __('Canvas Course Sync', 'canvas-course-sync'),
            'manage_options',
            'canvas-course-sync',
            array($this, 'display_admin_page')
        );

        if ($hook && $this->logger) {
            $this->logger->log('Admin menu page added successfully with hook: ' . $hook);
        } elseif (!$hook) {
            error_log('Canvas Course Sync: Failed to add admin menu page');
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

    /**
     * Add settings link to plugin actions
     */
    public function add_settings_link($links) {
        $settings_link = '<a href="' . admin_url('options-general.php?page=canvas-course-sync') . '">' . __('Settings', 'canvas-course-sync') . '</a>';
        array_unshift($links, $settings_link);
        return $links;
    }

    /**
     * Load plugin dependencies
     */
    private function load_dependencies() {
        // Include required files only if they exist
        $required_files = array(
            'includes/functions.php',
            'includes/logger.php',
            'includes/canvas-api.php',
            'includes/importer.php',
            'includes/class-ccs-scheduler.php'
        );

        foreach ($required_files as $file) {
            $file_path = CCS_PLUGIN_DIR . $file;
            if (file_exists($file_path)) {
                require_once $file_path;
            } else {
                error_log('Canvas Course Sync: Missing required file - ' . $file);
            }
        }

        // Include admin page files only in admin
        if (is_admin()) {
            $admin_file = CCS_PLUGIN_DIR . 'includes/admin-page.php';
            if (file_exists($admin_file)) {
                require_once $admin_file;
            } else {
                error_log('Canvas Course Sync: Missing admin file - includes/admin-page.php');
            }
        }
    }

    /**
     * Enqueue admin assets
     */
    public function enqueue_admin_assets($hook) {
        // Only load on our admin page
        if (strpos($hook, 'canvas-course-sync') === false) {
            return;
        }
        
        // Enqueue CSS
        $css_file = CCS_PLUGIN_URL . 'assets/css/admin.css';
        if (file_exists(CCS_PLUGIN_DIR . 'assets/css/admin.css')) {
            wp_enqueue_style('ccs-admin-css', $css_file, array(), CCS_VERSION);
        }
        
        // Enqueue JavaScript
        $js_file = CCS_PLUGIN_URL . 'assets/js/admin.js';
        if (file_exists(CCS_PLUGIN_DIR . 'assets/js/admin.js')) {
            wp_enqueue_script('ccs-admin-js', $js_file, array('jquery'), CCS_VERSION, true);
        }
    }

    /**
     * Initialize components after all plugins are loaded
     */
    public function init_components() {
        // Initialize components with error checking
        if (class_exists('CCS_Logger')) {
            $this->logger = new CCS_Logger();
            $this->logger->log('Canvas Course Sync plugin initializing');
        } else {
            error_log('Canvas Course Sync: CCS_Logger class not found');
        }
        
        if (class_exists('CCS_Canvas_API')) {
            $this->api = new CCS_Canvas_API();
        } else {
            if ($this->logger) {
                $this->logger->log('CCS_Canvas_API class not found', 'error');
            }
        }
        
        if (class_exists('CCS_Importer')) {
            $this->importer = new CCS_Importer();
        } else {
            if ($this->logger) {
                $this->logger->log('CCS_Importer class not found', 'error');
            }
        }
        
        if (class_exists('CCS_Scheduler')) {
            $this->scheduler = new CCS_Scheduler();
        } else {
            if ($this->logger) {
                $this->logger->log('CCS_Scheduler class not found', 'error');
            }
        }
        
        if ($this->logger) {
            $this->logger->log('Canvas Course Sync components initialized');
        }
    }

    /**
     * Initialize admin functionality
     */
    public function init_admin() {
        // Include AJAX handlers only in admin and if file exists
        $admin_handlers_file = CCS_PLUGIN_DIR . 'includes/admin/index.php';
        if (file_exists($admin_handlers_file)) {
            require_once $admin_handlers_file;
        }
    }

    /**
     * Activate the plugin
     */
    public function activate_plugin() {
        // Check PHP version
        if (version_compare(PHP_VERSION, '7.4', '<')) {
            deactivate_plugins(plugin_basename(__FILE__));
            wp_die(__('Canvas Course Sync requires PHP 7.4 or higher. Your current PHP version is ' . PHP_VERSION, 'canvas-course-sync'));
        }

        // Check WordPress version
        if (version_compare(get_bloginfo('version'), '5.0', '<')) {
            deactivate_plugins(plugin_basename(__FILE__));
            wp_die(__('Canvas Course Sync requires WordPress 5.0 or higher. Your current WordPress version is ' . get_bloginfo('version'), 'canvas-course-sync'));
        }

        // Check if courses post type exists
        if (!post_type_exists('courses')) {
            // Log error and deactivate plugin
            error_log('Canvas Course Sync: Custom post type "courses" does not exist');
            deactivate_plugins(plugin_basename(__FILE__));
            wp_die(__('This plugin requires a custom post type named "courses" to be registered. Please register this post type before activating the plugin.', 'canvas-course-sync'));
        }
        
        // Create log directory if it doesn't exist
        $upload_dir = wp_upload_dir();
        $log_dir = $upload_dir['basedir'] . '/canvas-course-sync/logs';
        
        if (!file_exists($log_dir)) {
            wp_mkdir_p($log_dir);
            // Create .htaccess to protect log files
            $htaccess_content = "Order deny,allow\nDeny from all";
            file_put_contents($log_dir . '/.htaccess', $htaccess_content);
        }
        
        // Add plugin version to options
        update_option('ccs_version', CCS_VERSION);
        
        // Clear any transients or cached data to prevent authorization errors
        delete_transient('ccs_auth_test');
        
        // Force refresh admin menu registration on next admin load
        delete_option('ccs_admin_menu_registered');
        
        // Flush rewrite rules
        flush_rewrite_rules();
        
        // Log successful activation
        error_log('Canvas Course Sync plugin activated successfully');
    }

    /**
     * Deactivate the plugin
     */
    public function deactivate_plugin() {
        // Clear any scheduled events
        wp_clear_scheduled_hook('ccs_weekly_sync');
        
        // Clear transients
        delete_transient('ccs_auth_test');
        delete_option('ccs_admin_menu_registered');
        
        // Flush rewrite rules
        flush_rewrite_rules();
        
        // Log deactivation
        error_log('Canvas Course Sync plugin deactivated');
    }

    /**
     * Load the plugin text domain for translation
     */
    public function load_textdomain() {
        load_plugin_textdomain('canvas-course-sync', false, dirname(plugin_basename(__FILE__)) . '/languages/');
    }
    
    /**
     * Register metaboxes for courses post type
     */
    public function register_course_metaboxes() {
        if (post_type_exists('courses') && $this->importer && method_exists($this->importer, 'display_course_link_metabox')) {
            add_meta_box(
                'ccs_course_link',
                __('Canvas Course Link', 'canvas-course-sync'),
                array($this->importer, 'display_course_link_metabox'),
                'courses',
                'side',
                'default'
            );
        }
    }

    /**
     * Add sync status column to courses list
     */
    public function add_sync_status_column($columns) {
        if (post_type_exists('courses')) {
            $columns['sync_status'] = __('Sync Status', 'canvas-course-sync');
        }
        return $columns;
    }

    /**
     * Display sync status in courses list
     */
    public function display_sync_status_column($column, $post_id) {
        if ($column === 'sync_status') {
            $canvas_id = get_post_meta($post_id, 'canvas_course_id', true);
            if (!empty($canvas_id)) {
                echo '<span class="ccs-badge ccs-badge-synced">' . esc_html__('Synced', 'canvas-course-sync') . '</span>';
            } else {
                echo '<span class="ccs-badge ccs-badge-manual">' . esc_html__('Not Yet Synced', 'canvas-course-sync') . '</span>';
            }
        }
    }
}

// Initialize the plugin
function canvas_course_sync_init() {
    return Canvas_Course_Sync::get_instance();
}

// Hook into plugins_loaded to ensure all plugins are loaded
add_action('plugins_loaded', 'canvas_course_sync_init', 10);

// Make the plugin globally accessible
function canvas_course_sync() {
    return Canvas_Course_Sync::get_instance();
}

// Add uninstall hook
register_uninstall_hook(__FILE__, 'ccs_uninstall_plugin');

/**
 * Uninstall function
 */
function ccs_uninstall_plugin() {
    // Only run if uninstall.php exists
    $uninstall_file = plugin_dir_path(__FILE__) . 'uninstall.php';
    if (file_exists($uninstall_file)) {
        include_once $uninstall_file;
    }
}
