
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
     * Constructor
     */
    public function __construct() {
        add_action('admin_init', array($this, 'register_settings'));
    }

    /**
     * Register settings
     */
    public function register_settings() {
        // Register API settings
        register_setting('ccs_api_settings', 'ccs_api_domain');
        register_setting('ccs_api_settings', 'ccs_api_token');
        
        // Register email settings
        register_setting('ccs_email_settings', 'ccs_notification_email');
        register_setting('ccs_email_settings', 'ccs_auto_sync_enabled');
    }

    /**
     * Render the admin page
     */
    public function render() {
        // Double-check user permissions
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'canvas-course-sync'));
        }

        // Handle form submissions
        if (isset($_POST['submit'])) {
            $this->handle_form_submission();
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
                <!-- API Settings Section -->
                <div class="ccs-settings-section">
                    <h2><?php echo esc_html__('API Settings', 'canvas-course-sync'); ?></h2>
                    <form method="post" action="">
                        <?php wp_nonce_field('ccs_api_settings', 'ccs_api_nonce'); ?>
                        <input type="hidden" name="ccs_form_type" value="api_settings" />
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
                                           class="regular-text" 
                                           autocomplete="off" />
                                    <p class="description"><?php echo esc_html__('Your Canvas API access token', 'canvas-course-sync'); ?></p>
                                </td>
                            </tr>
                        </table>
                        <?php submit_button(__('Save API Settings', 'canvas-course-sync')); ?>
                    </form>
                </div>

                <!-- Sync Controls Section -->
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

                <!-- Email Settings Section -->
                <div class="ccs-settings-section">
                    <h2><?php echo esc_html__('Email Settings', 'canvas-course-sync'); ?></h2>
                    <form method="post" action="">
                        <?php wp_nonce_field('ccs_email_settings', 'ccs_email_nonce'); ?>
                        <input type="hidden" name="ccs_form_type" value="email_settings" />
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

                <!-- Plugin Info Section -->
                <div class="ccs-settings-section">
                    <h2><?php echo esc_html__('Plugin Information', 'canvas-course-sync'); ?></h2>
                    <table class="form-table">
                        <tr>
                            <th scope="row"><?php echo esc_html__('Plugin Version', 'canvas-course-sync'); ?></th>
                            <td><?php echo esc_html(CCS_VERSION); ?></td>
                        </tr>
                        <tr>
                            <th scope="row"><?php echo esc_html__('WordPress Version', 'canvas-course-sync'); ?></th>
                            <td><?php echo esc_html(get_bloginfo('version')); ?></td>
                        </tr>
                        <tr>
                            <th scope="row"><?php echo esc_html__('PHP Version', 'canvas-course-sync'); ?></th>
                            <td><?php echo esc_html(PHP_VERSION); ?></td>
                        </tr>
                    </table>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Handle form submissions
     */
    private function handle_form_submission() {
        $form_type = isset($_POST['ccs_form_type']) ? $_POST['ccs_form_type'] : '';
        
        if ($form_type === 'api_settings' && wp_verify_nonce($_POST['ccs_api_nonce'], 'ccs_api_settings')) {
            update_option('ccs_api_domain', sanitize_url($_POST['ccs_api_domain']));
            update_option('ccs_api_token', sanitize_text_field($_POST['ccs_api_token']));
            
            echo '<div class="notice notice-success"><p>' . esc_html__('API settings saved successfully.', 'canvas-course-sync') . '</p></div>';
        }
        
        if ($form_type === 'email_settings' && wp_verify_nonce($_POST['ccs_email_nonce'], 'ccs_email_settings')) {
            update_option('ccs_notification_email', sanitize_email($_POST['ccs_notification_email']));
            update_option('ccs_auto_sync_enabled', isset($_POST['ccs_auto_sync_enabled']) ? 1 : 0);
            
            echo '<div class="notice notice-success"><p>' . esc_html__('Email settings saved successfully.', 'canvas-course-sync') . '</p></div>';
        }
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
        } else {
            echo '<div class="notice notice-info"><p>';
            echo esc_html__('Plugin is configured and ready to use.', 'canvas-course-sync');
            echo '</p></div>';
        }
    }
}
