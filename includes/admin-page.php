
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

// Include the main admin page class
require_once CCS_PLUGIN_DIR . 'includes/admin/class-ccs-admin-page.php';

// Initialize admin page functionality
$ccs_admin_page = new CCS_Admin_Page();
