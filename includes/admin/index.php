
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
add_action('wp_ajax_ccs_clear_logs', 'ccs_ajax_clear_logs');

/**
 * AJAX handler for testing connection
 */
function ccs_ajax_test_connection() {
    // Verify nonce
    if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'ccs_test_connection')) {
        wp_send_json_error(__('Security check failed.', 'canvas-course-sync'));
    }
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error(__('You do not have sufficient permissions to access this page.', 'canvas-course-sync'));
    }
    
    $canvas_course_sync = canvas_course_sync();
    if (!$canvas_course_sync || !$canvas_course_sync->api) {
        wp_send_json_error(__('Plugin not properly initialized.', 'canvas-course-sync'));
    }
    
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
    // Verify nonce
    if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'ccs_get_courses')) {
        wp_send_json_error(__('Security check failed.', 'canvas-course-sync'));
    }
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error(__('You do not have sufficient permissions to access this page.', 'canvas-course-sync'));
    }
    
    $canvas_course_sync = canvas_course_sync();
    if (!$canvas_course_sync || !$canvas_course_sync->api) {
        wp_send_json_error(__('Plugin not properly initialized.', 'canvas-course-sync'));
    }
    
    $courses = $canvas_course_sync->api->get_courses();
    
    if (is_wp_error($courses)) {
        wp_send_json_error($courses->get_error_message());
    } else {
        // Get existing WordPress courses for comparison
        $existing_wp_courses = get_posts(array(
            'post_type'      => 'courses',
            'post_status'    => array('draft', 'publish', 'private', 'pending'),
            'posts_per_page' => -1,
            'fields'         => 'ids'
        ));
        
        $existing_titles = array();
        $existing_canvas_ids = array();
        
        foreach ($existing_wp_courses as $post_id) {
            $title = get_the_title($post_id);
            $canvas_id = get_post_meta($post_id, 'canvas_course_id', true);
            
            if (!empty($title)) {
                $existing_titles[] = strtolower(trim($title));
            }
            if (!empty($canvas_id)) {
                $existing_canvas_ids[] = intval($canvas_id);
            }
        }
        
        // Check which courses already exist in WordPress
        foreach ($courses as $key => $course) {
            $course_id = isset($course['id']) ? intval($course['id']) : 0;
            $course_name = isset($course['name']) ? $course['name'] : '';
            
            $exists_in_wp = false;
            $match_type = '';
            
            // Check by Canvas ID first (most reliable)
            if (in_array($course_id, $existing_canvas_ids)) {
                $exists_in_wp = true;
                $match_type = 'canvas_id';
            } else if (!empty($course_name)) {
                // Check by title (case-insensitive)
                $course_title_lower = strtolower(trim($course_name));
                if (in_array($course_title_lower, $existing_titles)) {
                    $exists_in_wp = true;
                    $match_type = 'title';
                }
            }
            
            $courses[$key]['exists_in_wp'] = $exists_in_wp;
            $courses[$key]['match_type'] = $match_type;
        }
        
        wp_send_json_success($courses);
    }
}

/**
 * AJAX handler for syncing courses
 */
function ccs_ajax_sync_courses() {
    // Verify nonce
    if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'ccs_sync_courses')) {
        wp_send_json_error(__('Security check failed.', 'canvas-course-sync'));
    }
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error(__('You do not have sufficient permissions to access this page.', 'canvas-course-sync'));
    }
    
    // Sanitize course IDs
    $course_ids = array();
    if (isset($_POST['course_ids']) && is_array($_POST['course_ids'])) {
        $course_ids = array_map('intval', wp_unslash($_POST['course_ids']));
    }
    
    if (empty($course_ids)) {
        wp_send_json_error(__('No courses selected for sync.', 'canvas-course-sync'));
    }
    
    $canvas_course_sync = canvas_course_sync();
    if (!$canvas_course_sync || !$canvas_course_sync->importer) {
        wp_send_json_error(__('Plugin not properly initialized.', 'canvas-course-sync'));
    }
    
    $results = $canvas_course_sync->importer->import_courses($course_ids);
    
    wp_send_json_success($results);
}

/**
 * AJAX handler for clearing logs
 */
function ccs_ajax_clear_logs() {
    // Verify nonce
    if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'ccs_clear_logs')) {
        wp_send_json_error(__('Security check failed.', 'canvas-course-sync'));
    }
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error(__('You do not have sufficient permissions to access this page.', 'canvas-course-sync'));
    }
    
    $canvas_course_sync = canvas_course_sync();
    if (!$canvas_course_sync || !$canvas_course_sync->logger) {
        wp_send_json_error(__('Plugin not properly initialized.', 'canvas-course-sync'));
    }
    
    $canvas_course_sync->logger->clear_logs();
    
    wp_send_json_success(__('Logs cleared successfully.', 'canvas-course-sync'));
}
