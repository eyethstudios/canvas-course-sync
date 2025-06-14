<?php
/**
 * Plugin Name: Canvas Course Sync
 * Plugin URI: https://eyethstudios.com
 * Description: Synchronize courses from Canvas LMS to WordPress custom post type "courses"
 * Version: 2.1
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
define('CCS_VERSION', '2.1');
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
        add_action('init', array($this, 'init_plugin'));

        // Admin hooks - ensure they run at the right time
        if (is_admin()) {
            add_action('admin_menu', array($this, 'add_admin_menu'));
            add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
            add_action('admin_init', array($this, 'register_settings'));
            
            // Add settings and logs links to plugins page
            add_filter('plugin_action_links_' . plugin_basename(__FILE__), array($this, 'add_plugin_action_links'));
            
            // Add AJAX handlers
            add_action('wp_ajax_ccs_test_connection', array($this, 'ajax_test_connection'));
            add_action('wp_ajax_ccs_get_courses', array($this, 'ajax_get_courses'));
            add_action('wp_ajax_ccs_sync_courses', array($this, 'ajax_sync_courses'));
            add_action('wp_ajax_ccs_clear_logs', array($this, 'ajax_clear_logs'));
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
     * Initialize plugin
     */
    public function init_plugin() {
        // Load dependencies here instead of in constructor
        $this->load_dependencies();
    }

    /**
     * Load plugin dependencies
     */
    private function load_dependencies() {
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
            $admin_files = array(
                'includes/admin/class-ccs-admin-page.php'
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
     * Add admin menu - simplified and guaranteed to work
     */
    public function add_admin_menu() {
        // Only add if user has proper permissions
        if (!current_user_can('manage_options')) {
            return;
        }

        // Add the menu page under Settings
        $hook = add_options_page(
            __('Canvas Course Sync', 'canvas-course-sync'), // Page title
            __('Canvas Course Sync', 'canvas-course-sync'), // Menu title
            'manage_options',                                // Capability
            'canvas-course-sync',                           // Menu slug
            array($this, 'display_admin_page')             // Callback
        );

        // Ensure the hook was created successfully
        if ($hook) {
            error_log('CCS: Admin menu added successfully with hook: ' . $hook);
        } else {
            error_log('CCS: Failed to add admin menu');
        }
    }

    /**
     * Display admin page
     */
    public function display_admin_page() {
        // Double-check permissions
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'canvas-course-sync'));
        }

        // Use the admin page class if available
        if (class_exists('CCS_Admin_Page')) {
            $admin_page = new CCS_Admin_Page();
            $admin_page->render();
        } else {
            // Fallback basic admin page
            ?>
            <div class="wrap">
                <h1><?php echo esc_html__('Canvas Course Sync', 'canvas-course-sync'); ?></h1>
                <div class="notice notice-warning">
                    <p><?php echo esc_html__('Admin page class not found. Please check plugin installation.', 'canvas-course-sync'); ?></p>
                </div>
                
                <!-- Basic settings form as fallback -->
                <form method="post" action="options.php">
                    <?php settings_fields('ccs_api_settings'); ?>
                    <table class="form-table">
                        <tr>
                            <th scope="row"><?php echo esc_html__('Canvas Domain', 'canvas-course-sync'); ?></th>
                            <td>
                                <input type="url" name="ccs_api_domain" value="<?php echo esc_attr(get_option('ccs_api_domain', '')); ?>" class="regular-text" />
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php echo esc_html__('Canvas API Token', 'canvas-course-sync'); ?></th>
                            <td>
                                <input type="password" name="ccs_api_token" value="<?php echo esc_attr(get_option('ccs_api_token', '')); ?>" class="regular-text" />
                            </td>
                        </tr>
                    </table>
                    <?php submit_button(); ?>
                </form>
            </div>
            <?php
        }
    }

    /**
     * Add plugin action links (Settings and Logs links on plugins page)
     */
    public function add_plugin_action_links($links) {
        // Only add if user has proper permissions
        if (current_user_can('manage_options')) {
            $settings_url = admin_url('options-general.php?page=canvas-course-sync');
            $logs_url = admin_url('options-general.php?page=canvas-course-sync&tab=logs');
            
            $settings_link = '<a href="' . esc_url($settings_url) . '">' . esc_html__('Settings', 'canvas-course-sync') . '</a>';
            $logs_link = '<a href="' . esc_url($logs_url) . '">' . esc_html__('Logs', 'canvas-course-sync') . '</a>';
            
            // Add links at the beginning of the array
            array_unshift($links, $logs_link, $settings_link);
        }
        
        return $links;
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

        // Enqueue JS
        $js_file = CCS_PLUGIN_URL . 'assets/js/admin.js';
        if (file_exists(CCS_PLUGIN_DIR . 'assets/js/admin.js')) {
            wp_enqueue_script('ccs-admin-js', $js_file, array('jquery'), CCS_VERSION, true);

            // Localize script for AJAX
            wp_localize_script('ccs-admin-js', 'ccsNonces', array(
                'test_connection' => wp_create_nonce('ccs_test_connection_nonce'),
                'get_courses' => wp_create_nonce('ccs_get_courses_nonce'),
                'sync_courses' => wp_create_nonce('ccs_sync_nonce'),
                'sync_status' => wp_create_nonce('ccs_sync_status_nonce'),
                'clear_logs' => wp_create_nonce('ccs_clear_logs_nonce')
            ));
        }
    }

    /**
     * AJAX handler for testing connection
     */
    public function ajax_test_connection() {
        // Verify nonce
        if (!wp_verify_nonce($_POST['nonce'], 'ccs_test_connection_nonce')) {
            wp_die('Security check failed');
        }

        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }

        // Basic response for now
        wp_send_json_success('Connection test completed (placeholder)');
    }

    /**
     * AJAX handler for getting courses
     */
    public function ajax_get_courses() {
        // Verify nonce
        if (!wp_verify_nonce($_POST['nonce'], 'ccs_get_courses_nonce')) {
            wp_die('Security check failed');
        }

        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }

        // Basic response for now
        wp_send_json_success(array());
    }

    /**
     * AJAX handler for syncing courses
     */
    public function ajax_sync_courses() {
        // Verify nonce
        if (!wp_verify_nonce($_POST['nonce'], 'ccs_sync_nonce')) {
            wp_die('Security check failed');
        }

        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }

        // Basic response for now
        wp_send_json_success(array(
            'message' => 'Sync completed (placeholder)',
            'imported' => 0,
            'skipped' => 0,
            'errors' => 0,
            'total' => 0
        ));
    }

    /**
     * AJAX handler for clearing logs
     */
    public function ajax_clear_logs() {
        // Verify nonce
        if (!wp_verify_nonce($_POST['nonce'], 'ccs_clear_logs_nonce')) {
            wp_die('Security check failed');
        }

        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }

        // Clear logs using logger
        if (class_exists('CCS_Logger')) {
            $logger = new CCS_Logger();
            $result = $logger->clear_logs();
            
            if ($result) {
                wp_send_json_success('Logs cleared successfully');
            } else {
                wp_send_json_error('Failed to clear logs');
            }
        } else {
            wp_send_json_error('Logger class not found');
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
