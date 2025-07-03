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
     * Email settings instance
     *
     * @var CCS_Email_Settings
     */
    private $email_settings;

    /**
     * Script verifier instance
     *
     * @var CCS_Script_Verifier
     */
    private $script_verifier;

    /**
     * Constructor
     */
    public function __construct() {
        $canvas_course_sync = canvas_course_sync();
        $this->logger = ($canvas_course_sync && isset($canvas_course_sync->logger)) ? $canvas_course_sync->logger : null;
        
        // Initialize email settings
        if (class_exists('CCS_Email_Settings')) {
            $this->email_settings = new CCS_Email_Settings();
        }
        
        // Initialize script verifier for admin pages
        if (!class_exists('CCS_Script_Verifier')) {
            require_once plugin_dir_path(__FILE__) . '../class-ccs-script-verifier.php';
        }
        $this->script_verifier = new CCS_Script_Verifier();
        
        // Script verifier initialized
    }

    /**
     * Render admin page
     */
    public function render() {
        // Handle form submission first
        if (isset($_POST['submit']) && check_admin_referer('ccs_settings_nonce', 'ccs_settings_nonce')) {
            $domain = isset($_POST['ccs_canvas_domain']) ? esc_url_raw(wp_unslash($_POST['ccs_canvas_domain'])) : '';
            $token = isset($_POST['ccs_canvas_token']) ? sanitize_text_field(wp_unslash($_POST['ccs_canvas_token'])) : '';
            $catalog_url = isset($_POST['ccs_catalog_url']) ? esc_url_raw(wp_unslash($_POST['ccs_catalog_url'])) : '';
            
            update_option('ccs_canvas_domain', $domain);
            update_option('ccs_canvas_token', $token);
            update_option('ccs_catalog_url', $catalog_url);
            
            echo '<div class="notice notice-success"><p>' . __('Settings saved!', 'canvas-course-sync') . '</p></div>';
            
            if ($this->logger) {
                $this->logger->log('Settings updated via admin page');
            }
        }

        // NOTE: Scripts are now enqueued in main plugin file - do not enqueue here
        
        ?>
        <div class="wrap">
            <h1><?php _e('Canvas Course Sync', 'canvas-course-sync'); ?></h1>

            <div class="ccs-admin-container">
                <!-- API Settings Panel -->
                <div class="ccs-panel">
                    <h2><?php _e('Canvas API Settings', 'canvas-course-sync'); ?></h2>
                    <form method="post" action="">
                        <?php wp_nonce_field('ccs_settings_nonce', 'ccs_settings_nonce'); ?>
                        <table class="form-table">
                            <tr>
                                <th scope="row">
                                    <label for="ccs_canvas_domain"><?php _e('Canvas Domain', 'canvas-course-sync'); ?></label>
                                </th>
                                <td>
                                    <input type="url" name="ccs_canvas_domain" id="ccs_canvas_domain" class="regular-text" 
                                           value="<?php echo esc_url(get_option('ccs_canvas_domain')); ?>" 
                                           placeholder="https://canvas.instructure.com" />
                                    <p class="description"><?php _e('Your Canvas instance URL', 'canvas-course-sync'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="ccs_canvas_token"><?php _e('API Token', 'canvas-course-sync'); ?></label>
                                </th>
                                <td>
                                    <input type="password" name="ccs_canvas_token" id="ccs_canvas_token" class="regular-text" 
                                           value="<?php echo esc_attr(get_option('ccs_canvas_token')); ?>" />
                                    <p class="description">
                                        <?php _e('Your Canvas API token. ', 'canvas-course-sync'); ?>
                                        <a href="https://community.canvaslms.com/docs/DOC-10806-4214724194" target="_blank">
                                            <?php _e('How to get an API token', 'canvas-course-sync'); ?>
                                        </a>
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="ccs_catalog_url"><?php _e('Course Catalog URL', 'canvas-course-sync'); ?></label>
                                </th>
                                <td>
                                    <input type="url" name="ccs_catalog_url" id="ccs_catalog_url" class="regular-text" 
                                           value="<?php echo esc_url(get_option('ccs_catalog_url', CCS_DEFAULT_CATALOG_URL)); ?>" 
                                           placeholder="<?php echo esc_attr(CCS_DEFAULT_CATALOG_URL); ?>" />
                                    <p class="description">
                                        <?php _e('URL of the course catalog to validate courses against. Only courses found in this catalog will be synced.', 'canvas-course-sync'); ?>
                                    </p>
                                </td>
                            </tr>
                        </table>
                        <?php submit_button(); ?>
                    </form>
                    
                    <div class="ccs-connection-test">
                        <h3><?php _e('Test Connection', 'canvas-course-sync'); ?></h3>
                        <p><?php _e('Click the button below to test your Canvas API connection.', 'canvas-course-sync'); ?></p>
                        <button type="button" id="ccs-test-connection" class="button button-secondary">
                            <?php _e('Test Connection', 'canvas-course-sync'); ?>
                        </button>
                        <div id="ccs-connection-result" style="margin-top: 10px;"></div>
                    </div>
                </div>

                <!-- Course Sync Panel -->
                <div class="ccs-panel">
                    <h2><?php _e('Synchronize Courses', 'canvas-course-sync'); ?></h2>
                    <p><?php _e('Load courses from Canvas and select which ones to sync to WordPress.', 'canvas-course-sync'); ?></p>
                    
                    <div class="ccs-sync-controls">
                        <button type="button" id="ccs-get-courses" class="button button-secondary">
                            <?php _e('Get Courses', 'canvas-course-sync'); ?>
                        </button>
                        
                        <span id="ccs-loading-courses" style="display: none;">
                            <div class="ccs-spinner"></div>
                            <?php _e('Loading courses...', 'canvas-course-sync'); ?>
                        </span>
                        
                        <div id="ccs-courses-wrapper" style="display: none; margin-top: 20px;">
                            <!-- Course list container -->
                            <div id="ccs-course-list" class="ccs-course-list" style="margin-bottom: 20px;"></div>
                            
                            <!-- Action buttons -->
                            <div class="ccs-action-buttons">
                                <h3><?php _e('Course Actions', 'canvas-course-sync'); ?></h3>
                                
                                <div class="ccs-button-group" style="margin-bottom: 15px;">
                                    <button id="ccs-select-all" class="button">
                                        <?php _e('Select All', 'canvas-course-sync'); ?>
                                    </button>
                                    <button id="ccs-deselect-all" class="button">
                                        <?php _e('Deselect All', 'canvas-course-sync'); ?>
                                    </button>
                                </div>
                                
                                <div class="ccs-button-group">
                                    <button id="ccs-sync-selected" class="button button-primary">
                                        <?php _e('Sync Selected Courses', 'canvas-course-sync'); ?>
                                    </button>
                                    <button id="ccs-omit-selected" class="button ccs-omit-btn">
                                        <?php _e('Omit Selected from Auto-Sync', 'canvas-course-sync'); ?>
                                    </button>
                                    <button id="ccs-restore-omitted" class="button ccs-restore-btn">
                                        <?php _e('Restore All Omitted Courses', 'canvas-course-sync'); ?>
                                    </button>
                                    <button id="ccs-cleanup-deleted" class="button ccs-cleanup-btn">
                                        <?php _e('Cleanup Deleted Courses', 'canvas-course-sync'); ?>
                                    </button>
                                </div>
                            </div>
                            
                            <!-- Progress and results sections -->
                            <div id="ccs-sync-progress" style="display: none; margin-top: 20px;">
                                <p><?php _e('Syncing selected courses...', 'canvas-course-sync'); ?></p>
                                <div class="ccs-progress-bar-container">
                                    <div id="ccs-sync-progress-bar" class="ccs-progress-bar"></div>
                                </div>
                                <div id="ccs-sync-status"></div>
                            </div>
                            
                            <div id="ccs-sync-results" style="display: none; margin-top: 20px;">
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

                <!-- Auto-Sync Settings Panel -->
                <?php if ($this->email_settings): ?>
                    <?php $this->email_settings->render(); ?>
                <?php endif; ?>

                <!-- Logs Panel -->
                <div class="ccs-panel">
                    <h2><?php _e('System Logs', 'canvas-course-sync'); ?></h2>
                    <div class="ccs-log-controls">
                        <button type="button" id="ccs-refresh-logs" class="button button-secondary">
                            <?php _e('Refresh Logs', 'canvas-course-sync'); ?>
                        </button>
                        <button type="button" id="ccs-clear-logs" class="button button-secondary">
                            <?php _e('Clear Logs', 'canvas-course-sync'); ?>
                        </button>
                    </div>
                    <div id="ccs-logs-display" style="margin-top: 15px;">
                        <?php
                        // Display initial logs
                        if ($this->logger) {
                            $logs = $this->logger->get_recent_logs(20);
                            if (!empty($logs)) {
                                echo '<table class="wp-list-table widefat fixed striped">';
                                echo '<thead><tr>';
                                echo '<th scope="col" style="width: 150px;">' . __('Timestamp', 'canvas-course-sync') . '</th>';
                                echo '<th scope="col" style="width: 80px;">' . __('Level', 'canvas-course-sync') . '</th>';
                                echo '<th scope="col">' . __('Message', 'canvas-course-sync') . '</th>';
                                echo '</tr></thead><tbody>';
                                
                                foreach ($logs as $log) {
                                    echo '<tr>';
                                    echo '<td>' . esc_html(mysql2date('Y-m-d H:i:s', $log->timestamp ?? '')) . '</td>';
                                    echo '<td><span class="ccs-log-level ccs-log-level-' . esc_attr($log->level ?? 'info') . '">' . esc_html(strtoupper($log->level ?? 'INFO')) . '</span></td>';
                                    echo '<td>' . esc_html($log->message ?? '') . '</td>';
                                    echo '</tr>';
                                }
                                
                                echo '</tbody></table>';
                            } else {
                                echo '<div class="notice notice-info"><p>' . __('No logs found.', 'canvas-course-sync') . '</p></div>';
                            }
                        }
                        ?>
                    </div>
                </div>

                <!-- Debug Panel -->
                <div class="ccs-panel ccs-debug-panel">
                    <h3><?php _e('Debug Information', 'canvas-course-sync'); ?></h3>
                    <p><strong>Plugin Version:</strong> <?php echo esc_html(CCS_VERSION); ?></p>
                    <p><strong>Canvas Domain:</strong> <?php echo esc_html(get_option('ccs_canvas_domain', 'Not set')); ?></p>
                    <p><strong>API Token:</strong> <?php echo get_option('ccs_canvas_token') ? 'Set' : 'Not set'; ?></p>
                    <p><strong>Courses Post Type:</strong> <?php echo post_type_exists('courses') ? 'Registered' : 'Not registered'; ?></p>
                    <p><strong>Database Table:</strong> 
                        <?php 
                        global $wpdb;
                        $table_name = $wpdb->prefix . 'ccs_logs';
                        $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_name));
                        echo $table_exists ? 'Created' : 'Missing';
                        ?>
                    </p>
                    <p><strong>JavaScript Status:</strong> <span id="js-status">Loading...</span></p>
                    <p><strong>AJAX Object:</strong> <span id="ajax-status">Checking...</span></p>
                </div>
            </div>
        </div>
        <?php
    }
}
