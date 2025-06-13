
<?php
/**
 * Canvas Course Sync Email Settings Component
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class CCS_Email_Settings {
    /**
     * Constructor
     */
    public function __construct() {
        // This component is rendered directly in the admin page, not via action hooks
    }

    /**
     * Register settings
     */
    public function register_settings() {
        register_setting('ccs_email_settings', 'ccs_notification_email');
        register_setting('ccs_email_settings', 'ccs_auto_sync_enabled');
    }

    /**
     * Render email settings section
     */
    public function render() {
        ?>
        <div class="ccs-panel">
            <h2><?php _e('Auto-Sync Settings', 'canvas-course-sync'); ?></h2>
            <form method="post" action="options.php">
                <?php settings_fields('ccs_email_settings'); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="ccs_auto_sync_enabled"><?php _e('Enable Auto-Sync', 'canvas-course-sync'); ?></label>
                        </th>
                        <td>
                            <input type="checkbox" name="ccs_auto_sync_enabled" id="ccs_auto_sync_enabled" value="1" 
                                   <?php checked(get_option('ccs_auto_sync_enabled'), 1); ?> />
                            <p class="description"><?php _e('Automatically sync new courses from Canvas once per week', 'canvas-course-sync'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="ccs_notification_email"><?php _e('Notification Email', 'canvas-course-sync'); ?></label>
                        </th>
                        <td>
                            <input type="email" name="ccs_notification_email" id="ccs_notification_email" class="regular-text" 
                                   value="<?php echo esc_attr(get_option('ccs_notification_email')); ?>" />
                            <p class="description"><?php _e('Email address to receive notifications when new courses are synced', 'canvas-course-sync'); ?></p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
            
            <h3><?php _e('Manual Trigger', 'canvas-course-sync'); ?></h3>
            <button id="ccs-trigger-auto-sync" class="button button-secondary">
                <?php _e('Run Auto-Sync Now', 'canvas-course-sync'); ?>
            </button>
            <div id="ccs-auto-sync-result"></div>
        </div>
        <?php
    }
}
