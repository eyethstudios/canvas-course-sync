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
        $canvas_course_sync = canvas_course_sync();
        $this->logger = ($canvas_course_sync && isset($canvas_course_sync->logger)) ? $canvas_course_sync->logger : null;
    }

    /**
     * Render the admin page
     */
    public function render() {
        // Check user permissions
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'canvas-course-sync'));
        }

        // Get current settings
        $api_domain = get_option('ccs_api_domain', '');
        $api_token = get_option('ccs_api_token', '');
        $notification_email = get_option('ccs_notification_email', '');
        $auto_sync_enabled = get_option('ccs_auto_sync_enabled', false);

        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('Canvas Course Sync', 'canvas-course-sync'); ?></h1>
            
            <?php $this->display_notices(); ?>
            
            <div class="ccs-admin-container">
                <div class="ccs-settings-section">
                    <h2><?php echo esc_html__('API Settings', 'canvas-course-sync'); ?></h2>
                    <form method="post" action="options.php">
                        <?php
                        settings_fields('ccs_api_settings');
                        do_settings_sections('ccs_api_settings');
                        ?>
                        <table class="form-table">
                            <tr>
                                <th scope="row">
                                    <label for="ccs_api_domain"><?php echo esc_html__('Canvas Domain', 'canvas-course-sync'); ?></label>
                                </th>
                                <td>
                                    <input type="url" id="ccs_api_domain" name="ccs_api_domain" 
                                           value="<?php echo esc_attr($api_domain); ?>" 
                                           class="regular-text" 
                                           placeholder="https://your-institution.instructure.com" />
                                    <p class="description"><?php echo esc_html__('Your Canvas LMS domain URL', 'canvas-course-sync'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="ccs_api_token"><?php echo esc_html__('Canvas API Token', 'canvas-course-sync'); ?></label>
                                </th>
                                <td>
                                    <input type="password" id="ccs_api_token" name="ccs_api_token" 
                                           value="<?php echo esc_attr($api_token); ?>" 
                                           class="regular-text" />
                                    <p class="description"><?php echo esc_html__('Your Canvas API access token', 'canvas-course-sync'); ?></p>
                                </td>
                            </tr>
                        </table>
                        <?php submit_button(__('Save API Settings', 'canvas-course-sync')); ?>
                    </form>
                </div>

                <div class="ccs-settings-section">
                    <h2><?php echo esc_html__('Sync Controls', 'canvas-course-sync'); ?></h2>
                    <div class="ccs-sync-controls">
                        <button type="button" id="ccs-test-connection" class="button">
                            <?php echo esc_html__('Test Connection', 'canvas-course-sync'); ?>
                        </button>
                        <button type="button" id="ccs-get-courses" class="button">
                            <?php echo esc_html__('Load Courses', 'canvas-course-sync'); ?>
                        </button>
                        <button type="button" id="ccs-sync-selected" class="button button-primary" disabled>
                            <?php echo esc_html__('Sync Selected Courses', 'canvas-course-sync'); ?>
                        </button>
                    </div>
                    <div id="ccs-courses-list" style="margin-top: 20px;"></div>
                    <div id="ccs-sync-status" style="margin-top: 20px;"></div>
                </div>

                <div class="ccs-settings-section">
                    <h2><?php echo esc_html__('Email Settings', 'canvas-course-sync'); ?></h2>
                    <form method="post" action="options.php">
                        <?php
                        settings_fields('ccs_email_settings');
                        do_settings_sections('ccs_email_settings');
                        ?>
                        <table class="form-table">
                            <tr>
                                <th scope="row">
                                    <label for="ccs_notification_email"><?php echo esc_html__('Notification Email', 'canvas-course-sync'); ?></label>
                                </th>
                                <td>
                                    <input type="email" id="ccs_notification_email" name="ccs_notification_email" 
                                           value="<?php echo esc_attr($notification_email); ?>" 
                                           class="regular-text" />
                                    <p class="description"><?php echo esc_html__('Email address for sync notifications', 'canvas-course-sync'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="ccs_auto_sync_enabled"><?php echo esc_html__('Enable Auto Sync', 'canvas-course-sync'); ?></label>
                                </th>
                                <td>
                                    <input type="checkbox" id="ccs_auto_sync_enabled" name="ccs_auto_sync_enabled" 
                                           value="1" <?php checked($auto_sync_enabled, true); ?> />
                                    <p class="description"><?php echo esc_html__('Automatically sync new courses weekly', 'canvas-course-sync'); ?></p>
                                </td>
                            </tr>
                        </table>
                        <?php submit_button(__('Save Email Settings', 'canvas-course-sync')); ?>
                    </form>
                </div>
            </div>
        </div>

        <?php
        // Create nonces for AJAX calls - properly output as hidden fields
        wp_nonce_field('ccs_test_connection_nonce', 'ccs_test_connection_nonce', false);
        wp_nonce_field('ccs_get_courses_nonce', 'ccs_get_courses_nonce', false);
        wp_nonce_field('ccs_sync_nonce', 'ccs_sync_nonce', false);
        wp_nonce_field('ccs_sync_status_nonce', 'ccs_sync_status_nonce', false);
        ?>
        <script type="text/javascript">
            // Make ajaxurl available for our admin script
            window.ajaxurl = '<?php echo admin_url('admin-ajax.php'); ?>';
        </script>
        <?php
    }

    /**
     * Display admin notices
     */
    private function display_notices() {
        // Check if courses post type exists
        if (!post_type_exists('courses')) {
            echo '<div class="notice notice-error"><p>';
            echo esc_html__('Error: Custom post type "courses" does not exist. Please register this post type to use this plugin.', 'canvas-course-sync');
            echo '</p></div>';
            return;
        }

        // Check if API is configured
        $domain = get_option('ccs_api_domain', '');
        $token = get_option('ccs_api_token', '');

        if (empty($domain) || empty($token)) {
            echo '<div class="notice notice-warning"><p>';
            echo esc_html__('Please configure your Canvas API settings below to begin syncing courses.', 'canvas-course-sync');
            echo '</p></div>';
        }
    }
}
