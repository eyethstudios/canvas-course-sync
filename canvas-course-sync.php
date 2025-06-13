
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

// Prevent multiple initializations
if (class_exists('Canvas_Course_Sync')) {
    return;
}

// Include required files only if they exist
$required_files = array(
    'includes/functions.php',
    'includes/logger.php',
    'includes/canvas-api.php',
    'includes/importer.php',
    'includes/admin-page.php',
    'includes/class-ccs-scheduler.php'
);

foreach ($required_files as $file) {
    $file_path = CCS_PLUGIN_DIR . $file;
    if (file_exists($file_path)) {
        require_once $file_path;
    }
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
        // Initialize components with error checking
        if (class_exists('CCS_Logger')) {
            $this->logger = new CCS_Logger();
        }
        
        if (class_exists('CCS_Canvas_API')) {
            $this->api = new CCS_Canvas_API();
        }
        
        if (class_exists('CCS_Importer')) {
            $this->importer = new CCS_Importer();
        }
        
        if (class_exists('CCS_Scheduler')) {
            $this->scheduler = new CCS_Scheduler();
        }
        
        // Register hooks
        $this->init_hooks();
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
        
        // Initialize admin functionality
        add_action('admin_init', array($this, 'init_admin'));
        
        // Register metabox for course link
        add_action('add_meta_boxes', array($this, 'register_course_metaboxes'));
        
        // Add sync status column to courses list
        add_filter('manage_courses_posts_columns', array($this, 'add_sync_status_column'));
        add_action('manage_courses_posts_custom_column', array($this, 'display_sync_status_column'), 10, 2);
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
     * Initialize admin functionality
     */
    public function init_admin() {
        if (is_admin()) {
            // Include AJAX handlers
            $admin_handlers_file = CCS_PLUGIN_DIR . 'includes/admin/index.php';
            if (file_exists($admin_handlers_file)) {
                require_once $admin_handlers_file;
            }
        }
    }

    /**
     * Activate the plugin
     */
    public function activate_plugin() {
        // Check if courses post type exists
        if (!post_type_exists('courses')) {
            // Log error and deactivate plugin
            if ($this->logger) {
                $this->logger->log('Error: Custom post type "courses" does not exist. Plugin cannot be activated.', 'error');
            }
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
        // Clear any scheduled events
        wp_clear_scheduled_hook('ccs_weekly_sync');
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
        if ($this->importer && method_exists($this->importer, 'display_course_link_metabox')) {
            add_meta_box(
                'ccs_course_link',
                'Canvas Course Link',
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
        $columns['sync_status'] = __('Sync Status', 'canvas-course-sync');
        return $columns;
    }

    /**
     * Display sync status in courses list
     */
    public function display_sync_status_column($column, $post_id) {
        if ($column === 'sync_status') {
            $canvas_id = get_post_meta($post_id, 'canvas_course_id', true);
            if (!empty($canvas_id)) {
                echo '<span class="ccs-badge ccs-badge-synced">' . __('Synced', 'canvas-course-sync') . '</span>';
            } else {
                echo '<span class="ccs-badge ccs-badge-manual">' . __('Not Yet Synced', 'canvas-course-sync') . '</span>';
            }
        }
    }
}

// Initialize the plugin after all plugins are loaded
function canvas_course_sync_init() {
    // Use a different global variable name to avoid conflicts
    if (!isset($GLOBALS['canvas_course_sync_instance'])) {
        $GLOBALS['canvas_course_sync_instance'] = Canvas_Course_Sync::get_instance();
    }
    
    return $GLOBALS['canvas_course_sync_instance'];
}

// Hook into plugins_loaded with priority 20 to ensure other plugins load first
add_action('plugins_loaded', 'canvas_course_sync_init', 20);

// Make the plugin globally accessible
function canvas_course_sync() {
    return isset($GLOBALS['canvas_course_sync_instance']) ? $GLOBALS['canvas_course_sync_instance'] : null;
}
