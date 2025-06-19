
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
        $auto_sync_enabled = get_option('ccs_auto_sync_enabled', false);
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
                            <label class="ccs-toggle-switch">
                                <input type="checkbox" name="ccs_auto_sync_enabled" id="ccs_auto_sync_enabled" value="1" 
                                       <?php checked($auto_sync_enabled, 1); ?> />
                                <span class="ccs-toggle-slider"></span>
                            </label>
                            <p class="description"><?php _e('Automatically sync new courses from Canvas once per week', 'canvas-course-sync'); ?></p>
                        </td>
                    </tr>
                    <tr id="ccs-email-row" style="<?php echo $auto_sync_enabled ? '' : 'display: none;'; ?>">
                        <th scope="row">
                            <label for="ccs_notification_email"><?php _e('Notification Email', 'canvas-course-sync'); ?></label>
                        </th>
                        <td>
                            <input type="email" name="ccs_notification_email" id="ccs_notification_email" class="regular-text" 
                                   value="<?php echo esc_attr(get_option('ccs_notification_email', get_option('admin_email'))); ?>" 
                                   <?php echo $auto_sync_enabled ? 'required' : ''; ?> />
                            <p class="description"><?php _e('Email address to receive notifications when new courses are synced', 'canvas-course-sync'); ?></p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
            
            <div id="ccs-manual-sync">
                <h3><?php _e('Manual Trigger', 'canvas-course-sync'); ?></h3>
                <button id="ccs-trigger-auto-sync" class="button button-secondary">
                    <?php _e('Run Auto-Sync Now', 'canvas-course-sync'); ?>
                </button>
                <div id="ccs-auto-sync-result"></div>
            </div>
        </div>

        <style>
        .ccs-toggle-switch {
            position: relative;
            display: inline-block;
            width: 50px;
            height: 24px;
        }

        .ccs-toggle-switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }

        .ccs-toggle-slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: #ccc;
            transition: .4s;
            border-radius: 24px;
        }

        .ccs-toggle-slider:before {
            position: absolute;
            content: "";
            height: 18px;
            width: 18px;
            left: 3px;
            bottom: 3px;
            background-color: white;
            transition: .4s;
            border-radius: 50%;
        }

        input:checked + .ccs-toggle-slider {
            background-color: #0073aa;
        }

        input:checked + .ccs-toggle-slider:before {
            transform: translateX(26px);
        }
        </style>

        <script>
        jQuery(document).ready(function($) {
            $('#ccs_auto_sync_enabled').change(function() {
                const isChecked = $(this).is(':checked');
                const emailRow = $('#ccs-email-row');
                const manualSync = $('#ccs-manual-sync');
                const emailInput = $('#ccs_notification_email');
                
                if (isChecked) {
                    emailRow.show();
                    manualSync.show();
                    emailInput.prop('required', true);
                } else {
                    emailRow.hide();
                    manualSync.hide();
                    emailInput.prop('required', false);
                }
            });
        });
        </script>
        <?php
    }
}
