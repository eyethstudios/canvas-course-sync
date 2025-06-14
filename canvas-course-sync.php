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
     * Logger instance
     */
    public $logger = null;

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
        
        // Initialize logger after dependencies are loaded
        if (class_exists('CCS_Logger')) {
            $this->logger = new CCS_Logger();
            $this->logger->log('Plugin initialized', 'info');
        }
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
     * Add admin menu - now as top-level menu
     */
    public function add_admin_menu() {
        // Only add if user has proper permissions
        if (!current_user_can('manage_options')) {
            return;
        }

        // Add top-level menu page
        $hook = add_menu_page(
            __('Canvas Course Sync', 'canvas-course-sync'), // Page title
            __('Canvas Sync', 'canvas-course-sync'),         // Menu title
            'manage_options',                                // Capability
            'canvas-course-sync',                           // Menu slug
            array($this, 'display_admin_page'),             // Callback
            'dashicons-update',                             // Icon
            30                                              // Position
        );

        // Log menu creation
        if ($this->logger) {
            if ($hook) {
                $this->logger->log('Top-level admin menu added successfully with hook: ' . $hook, 'info');
            } else {
                $this->logger->log('Failed to add admin menu', 'error');
            }
        }
    }

    /**
     * Display admin page with tabs
     */
    public function display_admin_page() {
        // Double-check permissions
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'canvas-course-sync'));
        }

        // Get current tab
        $current_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'settings';

        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('Canvas Course Sync', 'canvas-course-sync'); ?></h1>
            
            <!-- Tab Navigation -->
            <nav class="nav-tab-wrapper">
                <a href="<?php echo admin_url('admin.php?page=canvas-course-sync&tab=settings'); ?>" 
                   class="nav-tab <?php echo $current_tab === 'settings' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('Settings', 'canvas-course-sync'); ?>
                </a>
                <a href="<?php echo admin_url('admin.php?page=canvas-course-sync&tab=logs'); ?>" 
                   class="nav-tab <?php echo $current_tab === 'logs' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('Logs', 'canvas-course-sync'); ?>
                </a>
            </nav>

            <!-- Tab Content -->
            <div class="tab-content">
                <?php
                switch ($current_tab) {
                    case 'logs':
                        $this->display_logs_tab();
                        break;
                    case 'settings':
                    default:
                        $this->display_settings_tab();
                        break;
                }
                ?>
            </div>
        </div>
        <?php
    }

    /**
     * Display settings tab
     */
    private function display_settings_tab() {
        // Use the admin page class if available
        if (class_exists('CCS_Admin_Page')) {
            $admin_page = new CCS_Admin_Page();
            $admin_page->render();
        } else {
            $this->display_fallback_settings();
        }
    }

    /**
     * Display logs tab
     */
    private function display_logs_tab() {
        ?>
        <div class="ccs-logs-section" style="margin-top: 20px;">
            <div style="margin-bottom: 15px;">
                <button id="ccs-clear-logs" class="button button-secondary">
                    <?php _e('Clear Logs', 'canvas-course-sync'); ?>
                </button>
                <button id="ccs-refresh-logs" class="button" onclick="location.reload();">
                    <?php _e('Refresh', 'canvas-course-sync'); ?>
                </button>
            </div>
            
            <div class="ccs-log-container" style="background: #fff; border: 1px solid #ccd0d4; padding: 15px; max-height: 500px; overflow-y: auto;">
                <?php
                // Get logs using logger class
                if (class_exists('CCS_Logger')) {
                    $logger = new CCS_Logger();
                    $recent_logs = $logger->get_recent_logs(50);
                    
                    if (!empty($recent_logs)) {
                        foreach ($recent_logs as $log_entry) {
                            $entry_class = '';
                            if (strpos($log_entry, '[ERROR]') !== false) {
                                $entry_class = 'color: #d63638;';
                            } elseif (strpos($log_entry, '[WARNING]') !== false) {
                                $entry_class = 'color: #dba617;';
                            } elseif (strpos($log_entry, '[INFO]') !== false) {
                                $entry_class = 'color: #2271b1;';
                            }
                            
                            echo '<div class="ccs-log-entry" style="margin-bottom: 8px; font-family: monospace; font-size: 12px;">';
                            echo '<pre style="margin: 0; white-space: pre-wrap; ' . $entry_class . '">' . esc_html($log_entry) . '</pre>';
                            echo '</div>';
                        }
                    } else {
                        echo '<p>' . __('No logs available yet.', 'canvas-course-sync') . '</p>';
                    }
                } else {
                    echo '<p style="color: #d63638;">' . __('Logger class not found. Please check plugin installation.', 'canvas-course-sync') . '</p>';
                }
                ?>
            </div>
        </div>

        <script>
        jQuery(document).ready(function($) {
            $('#ccs-clear-logs').on('click', function() {
                if (!confirm('<?php echo esc_js(__('Are you sure you want to clear all logs?', 'canvas-course-sync')); ?>')) {
                    return;
                }
                
                $.post(ajaxurl, {
                    action: 'ccs_clear_logs',
                    nonce: '<?php echo wp_create_nonce('ccs_clear_logs_nonce'); ?>'
                }, function(response) {
                    if (response.success) {
                        location.reload();
                    } else {
                        alert('<?php echo esc_js(__('Failed to clear logs', 'canvas-course-sync')); ?>: ' + response.data);
                    }
                });
            });
        });
        </script>
        <?php
    }

    /**
     * Fallback settings display
     */
    private function display_fallback_settings() {
        ?>
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
        <?php
    }

    /**
     * Add plugin action links (Settings and Logs links on plugins page)
     */
    public function add_plugin_action_links($links) {
        // Only add if user has proper permissions
        if (current_user_can('manage_options')) {
            $settings_url = admin_url('admin.php?page=canvas-course-sync&tab=settings');
            $logs_url = admin_url('admin.php?page=canvas-course-sync&tab=logs');
            
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
        
        // Enqueue course status CSS
        $course_css_file = CCS_PLUGIN_URL . 'assets/css/course-status.css';
        if (file_exists(CCS_PLUGIN_DIR . 'assets/css/course-status.css')) {
            wp_enqueue_style('ccs-course-status-css', $course_css_file, array(), CCS_VERSION);
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
            if ($this->logger) {
                $this->logger->log('Test connection failed - invalid nonce', 'error');
            }
            wp_die('Security check failed');
        }

        // Check permissions
        if (!current_user_can('manage_options')) {
            if ($this->logger) {
                $this->logger->log('Test connection failed - insufficient permissions', 'error');
            }
            wp_die('Insufficient permissions');
        }

        if ($this->logger) {
            $this->logger->log('Testing Canvas API connection...', 'info');
        }

        // Get API settings
        $domain = get_option('ccs_api_domain', '');
        $token = get_option('ccs_api_token', '');

        if (empty($domain) || empty($token)) {
            if ($this->logger) {
                $this->logger->log('Test connection failed - missing API settings', 'error');
            }
            wp_send_json_error('API domain and token are required');
            return;
        }

        // Test API connection with Canvas API class if available
        if (class_exists('CCS_Canvas_API')) {
            $api = new CCS_Canvas_API($domain, $token);
            $result = $api->test_connection();
            
            if ($this->logger) {
                $this->logger->log('Connection test result: ' . ($result ? 'SUCCESS' : 'FAILED'), $result ? 'info' : 'error');
            }
            
            if ($result) {
                wp_send_json_success('Connection successful');
            } else {
                wp_send_json_error('Connection failed - please check your API settings');
            }
        } else {
            if ($this->logger) {
                $this->logger->log('Canvas API class not found', 'error');
            }
            wp_send_json_error('Canvas API class not found');
        }
    }

    /**
     * AJAX handler for getting courses
     */
    public function ajax_get_courses() {
        // Verify nonce
        if (!wp_verify_nonce($_POST['nonce'], 'ccs_get_courses_nonce')) {
            if ($this->logger) {
                $this->logger->log('Get courses failed - invalid nonce', 'error');
            }
            wp_die('Security check failed');
        }

        // Check permissions
        if (!current_user_can('manage_options')) {
            if ($this->logger) {
                $this->logger->log('Get courses failed - insufficient permissions', 'error');
            }
            wp_die('Insufficient permissions');
        }

        if ($this->logger) {
            $this->logger->log('Fetching courses from Canvas API...', 'info');
        }

        // Get API settings
        $domain = get_option('ccs_api_domain', '');
        $token = get_option('ccs_api_token', '');

        if (empty($domain) || empty($token)) {
            if ($this->logger) {
                $this->logger->log('Get courses failed - missing API settings', 'error');
            }
            wp_send_json_error('API domain and token are required');
            return;
        }

        // Get courses with Canvas API class if available
        if (class_exists('CCS_Canvas_API')) {
            $api = new CCS_Canvas_API($domain, $token);
            $canvas_courses = $api->get_courses();
            
            if ($this->logger) {
                $this->logger->log('Canvas courses fetched: ' . count($canvas_courses) . ' courses found', 'info');
            }
            
            // Get existing WordPress courses for comparison
            $existing_wp_courses = get_posts(array(
                'post_type'      => 'courses',
                'post_status'    => array('draft', 'publish', 'private', 'pending'),
                'posts_per_page' => -1,
                'fields'         => 'ids'
            ));
            
            $existing_titles = array();
            $existing_canvas_ids = array();
            
            foreach ($existing_wp_courses as $post_id) {
                $title = get_the_title($post_id);
                $canvas_id = get_post_meta($post_id, 'canvas_course_id', true);
                
                if (!empty($title)) {
                    $existing_titles[] = strtolower(trim($title));
                }
                if (!empty($canvas_id)) {
                    $existing_canvas_ids[] = $canvas_id;
                }
            }
            
            if ($this->logger) {
                $this->logger->log('Found ' . count($existing_wp_courses) . ' existing WordPress courses for comparison', 'info');
            }
            
            // Add exists_in_wp flag to each Canvas course
            foreach ($canvas_courses as &$course) {
                $course->exists_in_wp = false;
                $course->match_type = '';
                
                // Check by Canvas ID first (most reliable)
                if (in_array($course->id, $existing_canvas_ids)) {
                    $course->exists_in_wp = true;
                    $course->match_type = 'canvas_id';
                } else {
                    // Check by title (case-insensitive)
                    $course_title_lower = strtolower(trim($course->name));
                    if (in_array($course_title_lower, $existing_titles)) {
                        $course->exists_in_wp = true;
                        $course->match_type = 'title';
                    }
                }
            }
            
            wp_send_json_success($canvas_courses);
        } else {
            if ($this->logger) {
                $this->logger->log('Canvas API class not found', 'error');
            }
            wp_send_json_error('Canvas API class not found');
        }
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
