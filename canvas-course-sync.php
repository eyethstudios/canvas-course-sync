
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

/**
 * Main plugin class
 */
class Canvas_Course_Sync {
    /**
     * Instance of this class
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
     * Importer instance
     */
    public $importer;

    /**
     * Scheduler instance
     */
    public $scheduler;

    /**
     * Constructor
     */
    private function __construct() {
        $this->init_hooks();
    }

    /**
     * Get singleton instance
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
        // Activation/deactivation hooks
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));

        // Load plugin
        add_action('plugins_loaded', array($this, 'load_textdomain'));
        add_action('plugins_loaded', array($this, 'load_dependencies'));
        add_action('init', array($this, 'init_components'));

        // Admin hooks
        if (is_admin()) {
            add_action('admin_menu', array($this, 'add_admin_menu'));
            add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
            add_action('admin_init', array($this, 'register_settings'));
            add_filter('plugin_action_links_' . plugin_basename(__FILE__), array($this, 'add_settings_link'));
        }
    }

    /**
     * Load plugin textdomain
     */
    public function load_textdomain() {
        load_plugin_textdomain(
            'canvas-course-sync',
            false,
            dirname(plugin_basename(__FILE__)) . '/languages/'
        );
    }

    /**
     * Load plugin dependencies
     */
    public function load_dependencies() {
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
            }
        }

        // Load admin files
        if (is_admin()) {
            $admin_file = CCS_PLUGIN_DIR . 'includes/admin/index.php';
            if (file_exists($admin_file)) {
                require_once $admin_file;
            }
        }
    }

    /**
     * Initialize plugin components
     */
    public function init_components() {
        // Initialize logger
        if (class_exists('CCS_Logger')) {
            $this->logger = new CCS_Logger();
        }

        // Initialize API
        if (class_exists('CCS_Canvas_API')) {
            $this->api = new CCS_Canvas_API();
        }

        // Initialize importer
        if (class_exists('CCS_Importer')) {
            $this->importer = new CCS_Importer();
        }

        // Initialize scheduler
        if (class_exists('CCS_Scheduler')) {
            $this->scheduler = new CCS_Scheduler();
        }
    }

    /**
     * Register plugin settings
     */
    public function register_settings() {
        // API Settings
        register_setting('ccs_api_settings', 'ccs_api_domain', array(
            'type' => 'string',
            'sanitize_callback' => 'esc_url_raw',
            'default' => ''
        ));

        register_setting('ccs_api_settings', 'ccs_api_token', array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => ''
        ));

        // Email Settings
        register_setting('ccs_email_settings', 'ccs_notification_email', array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_email',
            'default' => ''
        ));

        register_setting('ccs_email_settings', 'ccs_auto_sync_enabled', array(
            'type' => 'boolean',
            'sanitize_callback' => 'rest_sanitize_boolean',
            'default' => false
        ));
    }

    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        if (!current_user_can('manage_options')) {
            return;
        }

        add_options_page(
            __('Canvas Course Sync', 'canvas-course-sync'),
            __('Canvas Course Sync', 'canvas-course-sync'),
            'manage_options',
            'canvas-course-sync',
            array($this, 'display_admin_page')
        );
    }

    /**
     * Display admin page
     */
    public function display_admin_page() {
        $admin_page_file = CCS_PLUGIN_DIR . 'includes/admin/class-ccs-admin-page.php';
        if (file_exists($admin_page_file)) {
            require_once $admin_page_file;
            if (class_exists('CCS_Admin_Page')) {
                $admin_page = new CCS_Admin_Page();
                $admin_page->render();
                return;
            }
        }

        // Fallback if admin page class not found
        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Canvas Course Sync', 'canvas-course-sync') . '</h1>';
        echo '<div class="notice notice-error"><p>';
        echo esc_html__('Admin page class not found. Please check plugin installation.', 'canvas-course-sync');
        echo '</p></div>';
        echo '</div>';
    }

    /**
     * Add settings link to plugins page
     */
    public function add_settings_link($links) {
        if (current_user_can('manage_options')) {
            $settings_link = '<a href="' . esc_url(admin_url('options-general.php?page=canvas-course-sync')) . '">' .
                            esc_html__('Settings', 'canvas-course-sync') . '</a>';
            array_unshift($links, $settings_link);
        }
        return $links;
    }

    /**
     * Enqueue admin assets
     */
    public function enqueue_admin_assets($hook) {
        if (strpos($hook, 'canvas-course-sync') === false) {
            return;
        }

        // Enqueue CSS
        $css_file = CCS_PLUGIN_URL . 'assets/css/admin.css';
        if (file_exists(CCS_PLUGIN_DIR . 'assets/css/admin.css')) {
            wp_enqueue_style('ccs-admin-css', $css_file, array(), CCS_VERSION);
        }

        // Enqueue JS
        $js_file = CCS_PLUGIN_URL . 'assets/js/admin.js';
        if (file_exists(CCS_PLUGIN_DIR . 'assets/js/admin.js')) {
            wp_enqueue_script('ccs-admin-js', $js_file, array('jquery'), CCS_VERSION, true);

            // Localize script for AJAX
            wp_localize_script('ccs-admin-js', 'ccsNonces', array(
                'test_connection' => wp_create_nonce('ccs_test_connection_nonce'),
                'get_courses' => wp_create_nonce('ccs_get_courses_nonce'),
                'sync_courses' => wp_create_nonce('ccs_sync_nonce'),
                'sync_status' => wp_create_nonce('ccs_sync_status_nonce')
            ));
        }
    }

    /**
     * Plugin activation
     */
    public function activate() {
        // Check requirements
        if (version_compare(PHP_VERSION, '7.4', '<')) {
            deactivate_plugins(plugin_basename(__FILE__));
            wp_die(__('Canvas Course Sync requires PHP 7.4 or higher.', 'canvas-course-sync'));
        }

        if (version_compare(get_bloginfo('version'), '5.0', '<')) {
            deactivate_plugins(plugin_basename(__FILE__));
            wp_die(__('Canvas Course Sync requires WordPress 5.0 or higher.', 'canvas-course-sync'));
        }

        // Create log directory
        $upload_dir = wp_upload_dir();
        $log_dir = $upload_dir['basedir'] . '/canvas-course-sync/logs';

        if (!file_exists($log_dir)) {
            wp_mkdir_p($log_dir);
            file_put_contents($log_dir . '/.htaccess', "Order deny,allow\nDeny from all");
        }

        update_option('ccs_version', CCS_VERSION);
        flush_rewrite_rules();
    }

    /**
     * Plugin deactivation
     */
    public function deactivate() {
        wp_clear_scheduled_hook('ccs_weekly_sync');
        flush_rewrite_rules();
    }
}

/**
 * Initialize the plugin
 */
function canvas_course_sync() {
    return Canvas_Course_Sync::get_instance();
}

// Initialize plugin
canvas_course_sync();

/**
 * Uninstall hook
 */
register_uninstall_hook(__FILE__, 'ccs_uninstall_plugin');

function ccs_uninstall_plugin() {
    $uninstall_file = plugin_dir_path(__FILE__) . 'uninstall.php';
    if (file_exists($uninstall_file)) {
        include_once $uninstall_file;
    }
}
