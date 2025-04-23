<?php
/**
 * Canvas Course Sync Admin Page
 *
 * @package Canvas_Course_Sync
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Admin Page class
 */
class CCS_Admin_Page {
    /**
     * Logger instance
     *
     * @var CCS_Logger
     */
    private $logger;

    /**
     * Constructor
     */
    public function __construct() {
        global $canvas_course_sync;
        $this->logger = $canvas_course_sync->logger ?? new CCS_Logger();
        
        // Add admin menu
        add_action('admin_menu', array($this, 'add_admin_menu'));
        
        // Register settings
        add_action('admin_init', array($this, 'register_settings'));
        
        // Add admin scripts and styles
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
    }

    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        add_menu_page(
            __('Canvas Course Sync', 'canvas-course-sync'),
            __('Canvas Sync', 'canvas-course-sync'),
            'manage_options',
            'canvas-course-sync',
            array($this, 'display_admin_page'),
            'dashicons-update',
            30
        );
    }

    /**
     * Register plugin settings
     */
    public function register_settings() {
        register_setting('ccs_api_settings', 'ccs_api_domain');
        register_setting('ccs_api_settings', 'ccs_api_token');
    }

    /**
     * Enqueue admin scripts and styles
     *
     * @param string $hook Current admin page
     */
    public function enqueue_scripts($hook) {
        if ('toplevel_page_canvas-course-sync' !== $hook) {
            return;
        }
        
        // Enqueue admin CSS
        wp_enqueue_style(
            'ccs-admin-css',
            CCS_PLUGIN_URL . 'assets/css/admin.css',
            array(),
            CCS_VERSION
        );
        
        // Enqueue admin JS
        wp_enqueue_script(
            'ccs-admin-js',
            CCS_PLUGIN_URL . 'assets/js/admin.js',
            array('jquery'),
            CCS_VERSION,
            true
        );
        
        // Localize script with data
        wp_localize_script(
            'ccs-admin-js',
            'ccsData',
            array(
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'syncNonce' => wp_create_nonce('ccs_sync_nonce'),
                'testConnectionNonce' => wp_create_nonce('ccs_test_connection_nonce')
            )
        );
    }

    /**
     * Display the admin page
     */
    public function display_admin_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        // Check if the required post type exists
        $post_type_exists = post_type_exists('courses');
        ?>
        <div class="wrap">
            <h1><?php _e('Canvas Course Sync', 'canvas-course-sync'); ?></h1>
            
            <?php if (!$post_type_exists) : ?>
                <div class="notice notice-error">
                    <p><?php _e('Error: Custom post type "courses" does not exist. Please register this post type to use this plugin.', 'canvas-course-sync'); ?></p>
                </div>
                <?php return; ?>
            <?php endif; ?>
            
            <div class="ccs-admin-container">
                <div class="ccs-admin-main">
                    <!-- API Settings -->
                    <div class="ccs-panel">
                        <h2><?php _e('Canvas API Settings', 'canvas-course-sync'); ?></h2>
                        <form method="post" action="options.php">
                            <?php settings_fields('ccs_api_settings'); ?>
                            <table class="form-table">
                                <tr>
                                    <th scope="row">
                                        <label for="ccs_api_domain"><?php _e('Canvas Domain', 'canvas-course-sync'); ?></label>
                                    </th>
                                    <td>
                                        <input type="url" name="ccs_api_domain" id="ccs_api_domain" class="regular-text" 
                                               value="<?php echo esc_url(get_option('ccs_api_domain')); ?>" 
                                               placeholder="https://canvas.instructure.com" required />
                                        <p class="description"><?php _e('Your Canvas instance URL, e.g., https://canvas.instructure.com', 'canvas-course-sync'); ?></p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">
                                        <label for="ccs_api_token"><?php _e('API Token', 'canvas-course-sync'); ?></label>
                                    </th>
                                    <td>
                                        <input type="password" name="ccs_api_token" id="ccs_api_token" class="regular-text" 
                                               value="<?php echo esc_attr(get_option('ccs_api_token')); ?>" required />
                                        <p class="description">
                                            <?php _e('Your Canvas API token. ', 'canvas-course-sync'); ?>
                                            <a href="https://community.canvaslms.com/t5/Admin-Guide/How-do-I-manage-API-access-tokens-as-an-admin/ta-p/89" target="_blank">
                                                <?php _e('How to get an API token', 'canvas-course-sync'); ?>
                                            </a>
                                        </p>
                                    </td>
                                </tr>
                            </table>
                            <?php submit_button(); ?>
                        </form>
                        <button id="ccs-test-connection" class="button button-secondary">
                            <?php _e('Test Connection', 'canvas-course-sync'); ?>
                        </button>
                        <span id="ccs-connection-status"></span>
                    </div>
                    
                    <!-- Sync Controls -->
                    <div class="ccs-panel">
                        <h2><?php _e('Synchronize Courses', 'canvas-course-sync'); ?></h2>
                        <p><?php _e('First, load the available courses from Canvas, then select which ones you want to sync.', 'canvas-course-sync'); ?></p>
                        
                        <button id="ccs-load-courses" class="button button-secondary">
                            <?php _e('Load Available Courses', 'canvas-course-sync'); ?>
                        </button>
                        <span id="ccs-loading-courses" style="display: none;">
                            <div class="ccs-spinner"></div>
                            <?php _e('Loading courses...', 'canvas-course-sync'); ?>
                        </span>
                        
                        <div id="ccs-courses-wrapper" style="display: none;">
                            <div id="ccs-course-list" class="ccs-course-list"></div>
                            
                            <button id="ccs-sync-courses" class="button button-primary">
                                <?php _e('Sync Selected Courses', 'canvas-course-sync'); ?>
                            </button>
                            
                            <div id="ccs-sync-progress" style="display: none;">
                                <p><?php _e('Syncing selected courses...', 'canvas-course-sync'); ?></p>
                                <div class="ccs-progress-bar-container">
                                    <div id="ccs-sync-progress-bar" class="ccs-progress-bar"></div>
                                </div>
                                <div id="ccs-sync-status"></div>
                            </div>
                            
                            <div id="ccs-sync-results" style="display: none;">
                                <h3><?php _e('Sync Results', 'canvas-course-sync'); ?></h3>
                                <div id="ccs-sync-message"></div>
                                <table class="ccs-results-table">
                                    <tr>
                                        <th><?php _e('Imported', 'canvas-course-sync'); ?></th>
                                        <td id="ccs-imported">0</td>
                                    </tr>
                                    <tr>
                                        <th><?php _e('Skipped', 'canvas-course-sync'); ?></th>
                                        <td id="ccs-skipped">0</td>
                                    </tr>
                                    <tr>
                                        <th><?php _e('Errors', 'canvas-course-sync'); ?></th>
                                        <td id="ccs-errors">0</td>
                                    </tr>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Logs Panel -->
                <div class="ccs-admin-sidebar">
                    <div class="ccs-panel">
                        <h2><?php _e('Sync Logs', 'canvas-course-sync'); ?></h2>
                        <button id="ccs-clear-logs" class="button button-secondary ccs-clear-logs">
                            <?php _e('Clear Logs', 'canvas-course-sync'); ?>
                        </button>
                        <?php
                        $recent_logs = $this->logger->get_recent_logs(20);
                        if (!empty($recent_logs)) :
                        ?>
                            <div class="ccs-log-container">
                                <?php foreach ($recent_logs as $log_entry) : ?>
                                    <div class="ccs-log-entry">
                                        <?php 
                                            // Color-code log entries
                                            $entry_class = '';
                                            if (strpos($log_entry, '[ERROR]') !== false) {
                                                $entry_class = 'ccs-log-error';
                                            } elseif (strpos($log_entry, '[WARNING]') !== false) {
                                                $entry_class = 'ccs-log-warning';
                                            }
                                            echo '<pre class="' . $entry_class . '">' . esc_html($log_entry) . '</pre>';
                                        ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else : ?>
                            <p><?php _e('No logs available yet.', 'canvas-course-sync'); ?></p>
                        <?php endif; ?>
                        
                        <p>
                            <?php 
                            $log_file = $this->logger->get_log_file();
                            $log_url = '';
                            $upload_dir = wp_upload_dir();
                            if (file_exists($log_file)) {
                                $log_url = str_replace($upload_dir['basedir'], $upload_dir['baseurl'], $log_file);
                                echo '<a href="' . esc_url($log_url) . '" target="_blank" class="button button-secondary">';
                                _e('View Full Log', 'canvas-course-sync');
                                echo '</a>';
                            }
                            ?>
                        </p>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
}
