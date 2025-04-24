
<?php
/**
 * Canvas Course Sync API Settings Component
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class CCS_API_Settings {
    /**
     * Register settings
     */
    public function register_settings() {
        register_setting('ccs_api_settings', 'ccs_api_domain');
        register_setting('ccs_api_settings', 'ccs_api_key'); // Changed from ccs_api_token to ccs_api_key
    }

    /**
     * Render API settings section
     */
    public function render() {
        ?>
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
                            <label for="ccs_api_key"><?php _e('API Key', 'canvas-course-sync'); ?></label>
                        </th>
                        <td>
                            <input type="password" name="ccs_api_key" id="ccs_api_key" class="regular-text" 
                                   value="<?php echo esc_attr(get_option('ccs_api_key')); ?>" required />
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
            <div id="ccs-connection-result"></div>
        </div>
        <?php
    }
}
