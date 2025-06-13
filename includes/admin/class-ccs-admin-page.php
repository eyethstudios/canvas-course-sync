
<?php
/**
 * Canvas Course Sync Admin Page
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Include components
require_once CCS_PLUGIN_DIR . 'includes/admin/class-ccs-api-settings.php';
require_once CCS_PLUGIN_DIR . 'includes/admin/class-ccs-email-settings.php';
require_once CCS_PLUGIN_DIR . 'includes/admin/class-ccs-sync-controls.php';
require_once CCS_PLUGIN_DIR . 'includes/admin/class-ccs-logs-display.php';

class CCS_Admin_Page {
    /**
     * API Settings instance
     *
     * @var CCS_API_Settings
     */
    private $api_settings;

    /**
     * Email Settings instance
     *
     * @var CCS_Email_Settings
     */
    private $email_settings;

    /**
     * Sync Controls instance
     *
     * @var CCS_Sync_Controls
     */
    private $sync_controls;

    /**
     * Logs Display instance
     *
     * @var CCS_Logs_Display
     */
    private $logs_display;

    /**
     * Constructor
     */
    public function __construct() {
        $this->api_settings = new CCS_API_Settings();
        $this->email_settings = new CCS_Email_Settings();
        $this->sync_controls = new CCS_Sync_Controls();
        $this->logs_display = new CCS_Logs_Display();

        // Register settings
        add_action('admin_init', array($this, 'register_settings'));
        
        // Enqueue admin scripts and styles
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
    }

    /**
     * Register all settings
     */
    public function register_settings() {
        $this->api_settings->register_settings();
        $this->email_settings->register_settings();
    }

    /**
     * Render the admin page
     */
    public function render() {
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            
            <div class="ccs-admin-container">
                <?php $this->api_settings->render(); ?>
                <?php $this->email_settings->render(); ?>
                <?php $this->sync_controls->render(); ?>
                <?php $this->logs_display->render(); ?>
            </div>
        </div>
        <?php
    }

    /**
     * Enqueue admin scripts and styles
     */
    public function enqueue_admin_assets($hook) {
        // Only load on our admin page
        if (strpos($hook, 'canvas-course-sync') === false) {
            return;
        }

        wp_enqueue_script(
            'ccs-admin',
            CCS_PLUGIN_URL . 'assets/js/admin.js',
            array('jquery'),
            CCS_VERSION,
            true
        );

        wp_enqueue_style(
            'ccs-admin',
            CCS_PLUGIN_URL . 'assets/css/admin.css',
            array(),
            CCS_VERSION
        );

        // Localize script with AJAX data
        wp_localize_script('ccs-admin', 'ccsData', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'syncNonce' => wp_create_nonce('ccs_sync_nonce'),
            'testConnectionNonce' => wp_create_nonce('ccs_test_connection_nonce'),
            'getCoursesNonce' => wp_create_nonce('ccs_get_courses_nonce'),
            'clearLogsNonce' => wp_create_nonce('ccs_clear_logs_nonce'),
            'syncStatusNonce' => wp_create_nonce('ccs_sync_status_nonce'),
            'autoSyncNonce' => wp_create_nonce('ccs_auto_sync_nonce')
        ));
    }
}
