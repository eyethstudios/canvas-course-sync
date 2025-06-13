
<?php
/**
 * Canvas Course Sync Admin Page
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Include components only if files exist
$components = array(
    'class-ccs-api-settings.php',
    'class-ccs-email-settings.php', 
    'class-ccs-sync-controls.php',
    'class-ccs-logs-display.php'
);

foreach ($components as $component) {
    $file_path = CCS_PLUGIN_DIR . 'includes/admin/' . $component;
    if (file_exists($file_path)) {
        require_once $file_path;
    }
}

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
        // Initialize components if classes exist
        if (class_exists('CCS_API_Settings')) {
            $this->api_settings = new CCS_API_Settings();
        }
        if (class_exists('CCS_Email_Settings')) {
            $this->email_settings = new CCS_Email_Settings();
        }
        if (class_exists('CCS_Sync_Controls')) {
            $this->sync_controls = new CCS_Sync_Controls();
        }
        if (class_exists('CCS_Logs_Display')) {
            $this->logs_display = new CCS_Logs_Display();
        }

        // Register settings
        add_action('admin_init', array($this, 'register_settings'));
        
        // Enqueue admin scripts and styles
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
    }

    /**
     * Register all settings
     */
    public function register_settings() {
        if ($this->api_settings && method_exists($this->api_settings, 'register_settings')) {
            $this->api_settings->register_settings();
        }
        if ($this->email_settings && method_exists($this->email_settings, 'register_settings')) {
            $this->email_settings->register_settings();
        }
    }

    /**
     * Render the admin page
     */
    public function render() {
        $canvas_course_sync = canvas_course_sync();
        if ($canvas_course_sync && isset($canvas_course_sync->logger)) {
            $canvas_course_sync->logger->log('Rendering admin page components');
        }
        
        ?>
        <div class="ccs-admin-container">
            <?php if ($this->api_settings) { 
                $this->api_settings->render(); 
            } else {
                echo '<div class="notice notice-warning"><p>API Settings component not loaded</p></div>';
            } ?>
            
            <?php if ($this->email_settings) { 
                $this->email_settings->render(); 
            } else {
                echo '<div class="notice notice-warning"><p>Email Settings component not loaded</p></div>';
            } ?>
            
            <?php if ($this->sync_controls) { 
                $this->sync_controls->render(); 
            } else {
                echo '<div class="notice notice-warning"><p>Sync Controls component not loaded</p></div>';
            } ?>
            
            <?php if ($this->logs_display) { 
                $this->logs_display->render(); 
            } else {
                echo '<div class="notice notice-warning"><p>Logs Display component not loaded</p></div>';
            } ?>
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

        $js_file = CCS_PLUGIN_URL . 'assets/js/admin.js';
        $css_file = CCS_PLUGIN_URL . 'assets/css/admin.css';

        if (file_exists(CCS_PLUGIN_DIR . 'assets/js/admin.js')) {
            wp_enqueue_script(
                'ccs-admin',
                $js_file,
                array('jquery'),
                CCS_VERSION,
                true
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

        if (file_exists(CCS_PLUGIN_DIR . 'assets/css/admin.css')) {
            wp_enqueue_style(
                'ccs-admin',
                $css_file,
                array(),
                CCS_VERSION
            );
        }
    }
}
