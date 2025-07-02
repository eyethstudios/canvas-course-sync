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
            
            // Validate inputs
            $validation_errors = array();
            
            // Validate Canvas domain
            if (!empty($domain)) {
                // Remove protocol for validation
                $domain_clean = preg_replace('/^https?:\/\//', '', $domain);
                if (empty($domain_clean) || !filter_var('https://' . $domain_clean, FILTER_VALIDATE_URL)) {
                    $validation_errors[] = __('Canvas domain must be a valid URL (e.g., myschool.instructure.com)', 'canvas-course-sync');
                } elseif (!preg_match('/\.(instructure\.com|canvas\.net|beta\.instructure\.com)$/i', $domain_clean) && 
                         !preg_match('/canvas/i', $domain_clean)) {
                    $validation_errors[] = __('Canvas domain should be a Canvas LMS URL (typically ends with .instructure.com)', 'canvas-course-sync');
                }
            } else {
                $validation_errors[] = __('Canvas domain is required', 'canvas-course-sync');
            }
            
            // Validate Canvas token
            if (!empty($token)) {
                if (strlen($token) < 10) {
                    $validation_errors[] = __('Canvas API token appears to be too short (minimum 10 characters)', 'canvas-course-sync');
                } elseif (strlen($token) > 500) {
                    $validation_errors[] = __('Canvas API token appears to be too long (maximum 500 characters)', 'canvas-course-sync');
                } elseif (!preg_match('/^[a-zA-Z0-9~_-]+$/', $token)) {
                    $validation_errors[] = __('Canvas API token contains invalid characters', 'canvas-course-sync');
                }
            } else {
                $validation_errors[] = __('Canvas API token is required', 'canvas-course-sync');
            }
            
            // Validate catalog URL
            if (!empty($catalog_url) && !filter_var($catalog_url, FILTER_VALIDATE_URL)) {
                $validation_errors[] = __('Catalog URL must be a valid URL', 'canvas-course-sync');
            }
            
            // Save settings only if validation passes
            if (empty($validation_errors)) {
                update_option('ccs_canvas_domain', $domain);
                update_option('ccs_canvas_token', $token);
                update_option('ccs_catalog_url', $catalog_url);
                
                echo '<div class="notice notice-success"><p>' . __('Settings saved successfully!', 'canvas-course-sync') . '</p></div>';
                
                if ($this->logger) {
                    $this->logger->log('Settings updated via admin page - Domain: ' . $domain);
                }
            } else {
                // Display validation errors
                echo '<div class="notice notice-error"><p><strong>' . __('Validation errors:', 'canvas-course-sync') . '</strong></p><ul>';
                foreach ($validation_errors as $error) {
                    echo '<li>' . esc_html($error) . '</li>';
                }
                echo '</ul></div>';
                
                if ($this->logger) {
                    $this->logger->log('Settings validation failed: ' . implode(', ', $validation_errors), 'warning');
                }
            }
        }

        // Enqueue scripts and styles
        wp_enqueue_script('jquery');
        
        // Enqueue admin script
        wp_enqueue_script(
            'ccs-admin-js',
            CCS_PLUGIN_URL . 'assets/js/modules/core.js',
            array('jquery'),
            CCS_VERSION,
            true
        );
        
        // Localize script with AJAX data
        wp_localize_script('ccs-admin-js', 'ccsAjax', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'testConnectionNonce' => wp_create_nonce('ccs_test_connection'),
            'getCoursesNonce' => wp_create_nonce('ccs_get_courses'),
            'syncCoursesNonce' => wp_create_nonce('ccs_sync_courses'),
            'syncStatusNonce' => wp_create_nonce('ccs_sync_status'),
            'clearLogsNonce' => wp_create_nonce('ccs_clear_logs'),
            'refreshLogsNonce' => wp_create_nonce('ccs_refresh_logs'),
            'runAutoSyncNonce' => wp_create_nonce('ccs_run_auto_sync'),
            'omitCoursesNonce' => wp_create_nonce('ccs_omit_courses'),
            'restoreOmittedNonce' => wp_create_nonce('ccs_restore_omitted')
        ));
        
        // Enqueue admin styles
        wp_enqueue_style(
            'ccs-admin',
            CCS_PLUGIN_URL . 'assets/css/admin.css',
            array(),
            CCS_VERSION
        );
        
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
                                           value="<?php echo esc_url(get_option('ccs_catalog_url', 'https://learn.nationaldeafcenter.org/')); ?>" 
                                           placeholder="https://learn.nationaldeafcenter.org/" />
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
                        
                        <div id="ccs-courses-list" class="ccs-courses-container" style="margin: 15px 0;"></div>
                        
                        <button type="button" id="ccs-sync-selected" class="button button-primary" style="display: none; margin: 10px 0;">
                            <?php _e('Sync Selected Courses', 'canvas-course-sync'); ?>
                        </button>
                        
                        <div id="ccs-sync-status" class="ccs-sync-status" style="margin-top: 10px;"></div>
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
                <div class="ccs-panel" style="margin-top: 20px; padding: 15px; background: #f9f9f9; border-left: 4px solid #00a0d2;">
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
                </div>
            </div>
        </div>
        
        <style>
        .ccs-log-level {
            padding: 2px 6px;
            border-radius: 3px;
            font-size: 11px;
            font-weight: bold;
        }
        .ccs-log-level-info {
            background: #d1ecf1;
            color: #0c5460;
        }
        .ccs-log-level-warning {
            background: #fff3cd;
            color: #856404;
        }
        .ccs-log-level-error {
            background: #f8d7da;
            color: #721c24;
        }
        .ccs-success {
            color: green; 
            padding: 10px; 
            background: #d4edda; 
            border: 1px solid #c3e6cb; 
            border-radius: 4px;
        }
        .ccs-error {
            color: #721c24; 
            padding: 10px; 
            background: #f8d7da; 
            border: 1px solid #f5c6cb; 
            border-radius: 4px;
        }
        </style>
        <?php
    }
}
