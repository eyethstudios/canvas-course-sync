
<?php
/**
 * Plugin Name: Canvas Course Sync
 * Plugin URI: https://eyethstudios.com
 * Description: Synchronize courses from Canvas LMS to WordPress custom post type "courses"
 * Version: 1.0.0
 * Author: Eyeth Studios
 * Author URI: https://eyethstudios.com
 * Text Domain: canvas-course-sync
 * Domain Path: /languages
 * License: GPL v2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('CCS_VERSION', '1.0.0');
define('CCS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('CCS_PLUGIN_URL', plugin_dir_url(__FILE__));
define('CCS_PLUGIN_BASENAME', plugin_basename(__FILE__));

// Include required files
require_once CCS_PLUGIN_DIR . 'includes/logger.php';
require_once CCS_PLUGIN_DIR . 'includes/canvas-api.php';
require_once CCS_PLUGIN_DIR . 'includes/importer.php';
require_once CCS_PLUGIN_DIR . 'includes/admin-page.php';

/**
 * Main plugin class
 */
class Canvas_Course_Sync {
    /**
     * Instance of this class
     *
     * @var object
     */
    protected static $instance = null;

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
     * Admin page instance
     *
     * @var CCS_Admin_Page
     */
    public $admin_page;

    /**
     * Constructor
     */
    public function __construct() {
        // Initialize components
        $this->logger = new CCS_Logger();
        $this->api = new CCS_Canvas_API();
        $this->importer = new CCS_Importer();
        $this->admin_page = new CCS_Admin_Page();

        // Register activation hook
        register_activation_hook(__FILE__, array($this, 'activate_plugin'));

        // Register deactivation hook
        register_deactivation_hook(__FILE__, array($this, 'deactivate_plugin'));

        // Load text domain
        add_action('plugins_loaded', array($this, 'load_textdomain'));

        // Register AJAX handlers
        add_action('wp_ajax_ccs_sync_courses', array($this, 'ajax_sync_courses'));
        add_action('wp_ajax_ccs_test_connection', array($this, 'ajax_test_connection'));
        
        // Register metabox for course link
        add_action('add_meta_boxes', array($this, 'register_course_metaboxes'));
    }

    /**
     * Return an instance of this class
     *
     * @return object A single instance of this class
     */
    public static function get_instance() {
        if (null == self::$instance) {
            self::$instance = new self;
        }
        return self::$instance;
    }

    /**
     * Activate the plugin
     */
    public function activate_plugin() {
        // Check if courses post type exists
        if (!post_type_exists('courses')) {
            // Log error and deactivate plugin
            $this->logger->log('Error: Custom post type "courses" does not exist. Plugin cannot be activated.', 'error');
            deactivate_plugins(plugin_basename(__FILE__));
            wp_die('This plugin requires a custom post type named "courses" to be registered. Please register this post type before activating the plugin.');
        }
        
        // Create log directory if it doesn't exist
        $upload_dir = wp_upload_dir();
        $log_dir = $upload_dir['basedir'] . '/canvas-course-sync/logs';
        
        if (!file_exists($log_dir)) {
            wp_mkdir_p($log_dir);
        }
        
        // Add plugin version to options
        update_option('ccs_version', CCS_VERSION);
    }

    /**
     * Deactivate the plugin
     */
    public function deactivate_plugin() {
        // Nothing specific to do here
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
        add_meta_box(
            'ccs_course_link',
            'Canvas Course Link',
            array($this->importer, 'display_course_link_metabox'),
            'courses',
            'side',
            'default'
        );
    }

    /**
     * AJAX handler for syncing courses
     */
    public function ajax_sync_courses() {
        // Check nonce for security
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'ccs_sync_nonce')) {
            wp_send_json_error('Security check failed');
        }
        
        // Check user capabilities
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }
        
        try {
            // Start the import process
            $this->logger->log('Starting course sync process');
            $result = $this->importer->import_courses();
            
            wp_send_json_success(array(
                'message' => sprintf('Successfully imported %d courses', $result['imported']),
                'imported' => $result['imported'],
                'skipped' => $result['skipped'],
                'errors' => $result['errors']
            ));
        } catch (Exception $e) {
            $this->logger->log('Error in sync process: ' . $e->getMessage(), 'error');
            wp_send_json_error('Error: ' . $e->getMessage());
        }
    }

    /**
     * AJAX handler for testing API connection
     */
    public function ajax_test_connection() {
        // Check nonce for security
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'ccs_test_connection_nonce')) {
            wp_send_json_error('Security check failed');
        }
        
        // Check user capabilities
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }
        
        try {
            $result = $this->api->test_connection();
            
            if ($result) {
                wp_send_json_success('Connection successful!');
            } else {
                wp_send_json_error('Connection failed. Please check your API settings.');
            }
        } catch (Exception $e) {
            wp_send_json_error('Error: ' . $e->getMessage());
        }
    }
}

// Initialize the plugin
function canvas_course_sync() {
    return Canvas_Course_Sync::get_instance();
}

// Start the plugin
canvas_course_sync();
