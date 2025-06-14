
<?php
/**
 * Plugin Name: Canvas Course Sync
 * Plugin URI: https://github.com/yourusername/canvas-course-sync
 * Description: A WordPress plugin to synchronize courses from Canvas LMS to a WordPress custom post type.
 * Version: 2.3.0
 * Author: Your Name
 * Author URI: https://yourwebsite.com
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
    private $logger = null;
    
    /**
     * Canvas API instance
     */
    private $api = null;
    
    /**
     * Importer instance
     */
    private $importer = null;

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
        // Load dependencies here instead of in constructor
        $this->load_dependencies();
        
        // Initialize logger after dependencies are loaded
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

        // Initialize admin functionality
        if (is_admin()) {
            $this->init_admin();
        }

        // Add AJAX handlers
        $this->init_ajax_handlers();
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
            require_once CCS_PLUGIN_DIR . 'includes/admin-page.php';
            require_once CCS_PLUGIN_DIR . 'includes/admin/class-ccs-admin-menu.php';
            require_once CCS_PLUGIN_DIR . 'includes/admin/class-ccs-admin-page.php';
            require_once CCS_PLUGIN_DIR . 'includes/admin/class-ccs-api-settings.php';
            require_once CCS_PLUGIN_DIR . 'includes/admin/class-ccs-email-settings.php';
            require_once CCS_PLUGIN_DIR . 'includes/admin/class-ccs-logs-display.php';
            require_once CCS_PLUGIN_DIR . 'includes/admin/class-ccs-sync-controls.php';
        }

        // Handler includes
        require_once CCS_PLUGIN_DIR . 'includes/handlers/class-ccs-ajax-handler.php';
        require_once CCS_PLUGIN_DIR . 'includes/handlers/class-ccs-content-handler.php';
        require_once CCS_PLUGIN_DIR . 'includes/handlers/class-ccs-media-handler.php';

        // Scheduler
        require_once CCS_PLUGIN_DIR . 'includes/class-ccs-scheduler.php';
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
        // Only load on our admin page
        if ($hook !== 'settings_page_canvas-course-sync') {
            return;
        }

        // Enqueue admin JavaScript
        wp_enqueue_script(
            'ccs-admin-js',
            CCS_PLUGIN_URL . 'assets/js/admin.js',
            array('jquery'),
            CCS_VERSION,
            true
        );

        // Enqueue admin CSS
        wp_enqueue_style(
            'ccs-admin-css',
            CCS_PLUGIN_URL . 'assets/css/admin.css',
            array(),
            CCS_VERSION
        );

        // Localize script with nonces and ajax URL
        wp_localize_script('ccs-admin-js', 'ccsNonces', array(
            'test_connection' => wp_create_nonce('ccs_test_connection'),
            'get_courses' => wp_create_nonce('ccs_get_courses'),
            'sync_courses' => wp_create_nonce('ccs_sync_courses')
        ));
    }

    /**
     * Initialize AJAX handlers
     */
    private function init_ajax_handlers() {
        if (class_exists('CCS_Ajax_Handler')) {
            new CCS_Ajax_Handler();
        }

        // Legacy AJAX handlers for backward compatibility
        add_action('wp_ajax_ccs_test_connection', array($this, 'ajax_test_connection'));
        add_action('wp_ajax_ccs_get_courses', array($this, 'ajax_get_courses'));
        add_action('wp_ajax_ccs_sync_courses', array($this, 'ajax_sync_courses'));
    }

    /**
     * AJAX handler for testing connection
     */
    public function ajax_test_connection() {
        check_ajax_referer('ccs_test_connection', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        if ($this->api) {
            $result = $this->api->test_connection();
            if ($result) {
                wp_send_json_success('Connection successful');
            } else {
                wp_send_json_error('Connection failed');
            }
        } else {
            wp_send_json_error('API not initialized');
        }
    }

    /**
     * AJAX handler for getting courses
     */
    public function ajax_get_courses() {
        check_ajax_referer('ccs_get_courses', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        if ($this->api) {
            $courses = $this->api->get_courses();
            if ($courses !== false) {
                wp_send_json_success($courses);
            } else {
                wp_send_json_error('Failed to fetch courses');
            }
        } else {
            wp_send_json_error('API not initialized');
        }
    }

    /**
     * AJAX handler for syncing courses
     */
    public function ajax_sync_courses() {
        check_ajax_referer('ccs_sync_courses', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        $course_ids = isset($_POST['course_ids']) ? array_map('intval', $_POST['course_ids']) : array();
        
        if (empty($course_ids)) {
            wp_send_json_error('No courses selected');
            return;
        }

        if ($this->api && $this->importer) {
            $courses = $this->api->get_courses();
            if ($courses === false) {
                wp_send_json_error('Failed to fetch courses from Canvas');
                return;
            }

            // Filter courses to only the selected ones
            $selected_courses = array_filter($courses, function($course) use ($course_ids) {
                return in_array($course['id'], $course_ids);
            });

            if (empty($selected_courses)) {
                wp_send_json_error('Selected courses not found');
                return;
            }

            // Import the selected courses
            $result = $this->importer->import_courses($selected_courses);
            
            wp_send_json_success($result);
        } else {
            wp_send_json_error('API or Importer not initialized');
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
