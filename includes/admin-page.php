
<?php
/**
 * Canvas Course Sync Admin Page Entry Point
 *
 * @package Canvas_Course_Sync
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Only load in admin
if (!is_admin()) {
    return;
}

// Include the main admin page class
$admin_files = array(
    'includes/admin/class-ccs-admin-page.php'
);

foreach ($admin_files as $file) {
    $file_path = CCS_PLUGIN_DIR . $file;
    if (file_exists($file_path)) {
        require_once $file_path;
    } else {
        error_log('Canvas Course Sync: Missing admin file - ' . $file);
    }
}

// Initialize admin settings only once
add_action('admin_init', 'ccs_init_admin_settings', 5);

/**
 * Initialize admin settings
 */
function ccs_init_admin_settings() {
    // Prevent multiple registrations
    static $settings_registered = false;
    if ($settings_registered) {
        return;
    }
    $settings_registered = true;
    
    // Register settings for API configuration
    register_setting(
        'ccs_api_settings',
        'ccs_api_domain',
        array(
            'type' => 'string',
            'sanitize_callback' => 'esc_url_raw',
            'default' => ''
        )
    );
    
    register_setting(
        'ccs_api_settings',
        'ccs_api_token',
        array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => ''
        )
    );
    
    // Register settings for email notifications
    register_setting(
        'ccs_email_settings',
        'ccs_notification_email',
        array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_email',
            'default' => ''
        )
    );
    
    register_setting(
        'ccs_email_settings',
        'ccs_auto_sync_enabled',
        array(
            'type' => 'boolean',
            'sanitize_callback' => 'rest_sanitize_boolean',
            'default' => false
        )
    );
}

// Add admin notices for important messages - only on our admin page
add_action('admin_notices', 'ccs_admin_notices');

/**
 * Display admin notices
 */
function ccs_admin_notices() {
    // Check if on our admin page
    $screen = get_current_screen();
    if (!$screen || strpos($screen->id, 'canvas-course-sync') === false) {
        return;
    }
    
    // Check if user has permissions
    if (!current_user_can('manage_options')) {
        return;
    }
    
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
        echo esc_html__('Canvas Course Sync: Please configure your Canvas API settings to begin syncing courses.', 'canvas-course-sync');
        echo '</p></div>';
    }
}
