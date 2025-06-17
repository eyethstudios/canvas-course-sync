
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
        
        // Register settings
        add_action('admin_init', array($this, 'register_settings'));
    }

    /**
     * Register settings
     */
    public function register_settings() {
        register_setting('ccs_settings', 'ccs_canvas_domain', array(
            'sanitize_callback' => 'esc_url_raw'
        ));
        register_setting('ccs_settings', 'ccs_canvas_token', array(
            'sanitize_callback' => 'sanitize_text_field'
        ));
    }

    /**
     * Render admin page
     */
    public function render() {
        ?>
        <div class="wrap">
            <h1><?php _e('Canvas Course Sync', 'canvas-course-sync'); ?></h1>
            
            <?php
            // Handle form submission
            if (isset($_POST['submit']) && check_admin_referer('ccs_settings_nonce', 'ccs_settings_nonce')) {
                $domain = isset($_POST['ccs_canvas_domain']) ? esc_url_raw(wp_unslash($_POST['ccs_canvas_domain'])) : '';
                $token = isset($_POST['ccs_canvas_token']) ? sanitize_text_field(wp_unslash($_POST['ccs_canvas_token'])) : '';
                
                update_option('ccs_canvas_domain', $domain);
                update_option('ccs_canvas_token', $token);
                
                echo '<div class="notice notice-success"><p>' . __('Settings saved!', 'canvas-course-sync') . '</p></div>';
            }
            ?>

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
                        </table>
                        <?php submit_button(); ?>
                    </form>
                    
                    <div class="ccs-connection-test">
                        <h3><?php _e('Test Connection', 'canvas-course-sync'); ?></h3>
                        <button type="button" id="ccs-test-connection" class="button button-secondary">
                            <?php _e('Test Connection', 'canvas-course-sync'); ?>
                        </button>
                        <div id="ccs-connection-result"></div>
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
                        
                        <div id="ccs-courses-list" class="ccs-courses-container"></div>
                        
                        <button type="button" id="ccs-sync-selected" class="button button-primary" style="display: none;">
                            <?php _e('Sync Selected Courses', 'canvas-course-sync'); ?>
                        </button>
                        
                        <div id="ccs-sync-status" class="ccs-sync-status"></div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
}
