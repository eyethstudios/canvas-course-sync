
<?php
/**
 * Admin includes for Canvas Course Sync
 *
 * @package Canvas_Course_Sync
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Register AJAX handlers
add_action('wp_ajax_ccs_test_connection', 'ccs_ajax_test_connection');
add_action('wp_ajax_ccs_get_courses', 'ccs_ajax_get_courses');
add_action('wp_ajax_ccs_sync_courses', 'ccs_ajax_sync_courses');

/**
 * AJAX handler for testing connection
 */
function ccs_ajax_test_connection() {
    check_ajax_referer('ccs_test_connection', 'nonce');
    
    if (!current_user_can('manage_options')) {
        wp_die(__('You do not have sufficient permissions to access this page.'));
    }
    
    $canvas_course_sync = canvas_course_sync();
    $result = $canvas_course_sync->api->test_connection();
    
    if (is_wp_error($result)) {
        wp_send_json_error($result->get_error_message());
    } else {
        wp_send_json_success(__('Connection successful!', 'canvas-course-sync'));
    }
}

/**
 * AJAX handler for getting courses
 */
function ccs_ajax_get_courses() {
    check_ajax_referer('ccs_get_courses', 'nonce');
    
    if (!current_user_can('manage_options')) {
        wp_die(__('You do not have sufficient permissions to access this page.'));
    }
    
    $canvas_course_sync = canvas_course_sync();
    $courses = $canvas_course_sync->api->get_courses();
    
    if (is_wp_error($courses)) {
        wp_send_json_error($courses->get_error_message());
    } else {
        wp_send_json_success($courses);
    }
}

/**
 * AJAX handler for syncing courses
 */
function ccs_ajax_sync_courses() {
    check_ajax_referer('ccs_sync_courses', 'nonce');
    
    if (!current_user_can('manage_options')) {
        wp_die(__('You do not have sufficient permissions to access this page.'));
    }
    
    $course_ids = isset($_POST['course_ids']) ? array_map('intval', $_POST['course_ids']) : array();
    
    if (empty($course_ids)) {
        wp_send_json_error(__('No courses selected for sync.', 'canvas-course-sync'));
    }
    
    $canvas_course_sync = canvas_course_sync();
    $results = $canvas_course_sync->importer->import_courses($course_ids);
    
    wp_send_json_success($results);
}
