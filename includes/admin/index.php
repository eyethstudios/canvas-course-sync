
<?php
// Prevent direct file access
if (!defined('ABSPATH')) {
    exit;
}

// Include AJAX handler functions only if we're in admin context
if (!is_admin()) {
    return;
}

/**
 * AJAX handler for testing the API connection
 */
function ccs_ajax_test_connection() {
    // Verify nonce
    if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'ccs_test_connection')) {
        wp_send_json_error(__('Security check failed', 'canvas-course-sync'));
        return;
    }
    
    // Check user capabilities
    if (!current_user_can('manage_options')) {
        wp_send_json_error(__('Permission denied', 'canvas-course-sync'));
        return;
    }
    
    $canvas_course_sync = canvas_course_sync();
    
    if (!$canvas_course_sync || !isset($canvas_course_sync->api)) {
        wp_send_json_error(__('API not initialized', 'canvas-course-sync'));
        return;
    }
    
    try {
        $result = $canvas_course_sync->api->test_connection();
        if ($result === true) {
            if (isset($canvas_course_sync->logger)) {
                $canvas_course_sync->logger->log('API connection test successful');
            }
            wp_send_json_success(__('Connection successful! Canvas API is responding correctly.', 'canvas-course-sync'));
        } else {
            if (isset($canvas_course_sync->logger)) {
                $canvas_course_sync->logger->log('API connection test failed: ' . $result, 'error');
            }
            wp_send_json_error($result);
        }
    } catch (Exception $e) {
        if (isset($canvas_course_sync->logger)) {
            $canvas_course_sync->logger->log('Exception in API connection test: ' . $e->getMessage(), 'error');
        }
        wp_send_json_error($e->getMessage());
    }
}
add_action('wp_ajax_ccs_test_connection', 'ccs_ajax_test_connection');

/**
 * AJAX handler for getting Canvas courses
 */
function ccs_ajax_get_courses() {
    // Verify nonce
    if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'ccs_get_courses')) {
        wp_send_json_error(__('Security check failed', 'canvas-course-sync'));
        return;
    }
    
    // Check user capabilities
    if (!current_user_can('manage_options')) {
        wp_send_json_error(__('Permission denied', 'canvas-course-sync'));
        return;
    }
    
    $canvas_course_sync = canvas_course_sync();
    
    if (!$canvas_course_sync || !isset($canvas_course_sync->api)) {
        wp_send_json_error(__('API not initialized', 'canvas-course-sync'));
        return;
    }
    
    try {
        // First test the connection
        $connection_test = $canvas_course_sync->api->test_connection();
        if ($connection_test !== true) {
            if (isset($canvas_course_sync->logger)) {
                $canvas_course_sync->logger->log('Connection test failed before getting courses: ' . $connection_test, 'error');
            }
            wp_send_json_error('Connection test failed: ' . $connection_test);
            return;
        }
        
        $courses = $canvas_course_sync->api->get_courses();
        
        // Handle error response
        if (is_wp_error($courses)) {
            $error_message = $courses->get_error_message();
            if (isset($canvas_course_sync->logger)) {
                $canvas_course_sync->logger->log('Error getting courses: ' . $error_message, 'error');
            }
            wp_send_json_error($error_message);
            return;
        }
        
        // Validate courses response
        if (!is_array($courses)) {
            $error_msg = 'Invalid courses response format. Expected array, got: ' . gettype($courses);
            if (isset($canvas_course_sync->logger)) {
                $canvas_course_sync->logger->log($error_msg, 'error');
            }
            wp_send_json_error($error_msg);
            return;
        }
        
        // Process courses and check if they exist in WordPress
        $processed_courses = array();
        foreach ($courses as $course) {
            $course_data = is_object($course) ? (array) $course : $course;
            
            // Check if course already exists in WordPress
            $existing_posts = get_posts(array(
                'post_type' => 'canvas_course',
                'meta_key' => 'canvas_course_id',
                'meta_value' => $course_data['id'],
                'posts_per_page' => 1
            ));
            
            $course_data['exists_in_wp'] = !empty($existing_posts);
            $processed_courses[] = $course_data;
        }
        
        if (isset($canvas_course_sync->logger)) {
            $canvas_course_sync->logger->log('Successfully retrieved ' . count($processed_courses) . ' courses');
        }
        
        wp_send_json_success($processed_courses);
    } catch (Exception $e) {
        if (isset($canvas_course_sync->logger)) {
            $canvas_course_sync->logger->log('Exception getting courses: ' . $e->getMessage(), 'error');
        }
        wp_send_json_error('Exception: ' . $e->getMessage());
    }
}
add_action('wp_ajax_ccs_get_courses', 'ccs_ajax_get_courses');

/**
 * AJAX handler for syncing selected courses
 */
function ccs_ajax_sync_courses() {
    // Verify nonce
    if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'ccs_sync_courses')) {
        wp_send_json_error(__('Security check failed', 'canvas-course-sync'));
        return;
    }
    
    // Check user capabilities
    if (!current_user_can('manage_options')) {
        wp_send_json_error(__('Permission denied', 'canvas-course-sync'));
        return;
    }
    
    $canvas_course_sync = canvas_course_sync();
    
    if (!$canvas_course_sync || !isset($canvas_course_sync->importer)) {
        wp_send_json_error(__('Importer not initialized', 'canvas-course-sync'));
        return;
    }
    
    // Get course IDs from request and sanitize
    $course_ids = isset($_POST['course_ids']) ? array_map('intval', wp_unslash($_POST['course_ids'])) : array();
    
    if (empty($course_ids)) {
        wp_send_json_error(__('No course IDs provided', 'canvas-course-sync'));
        return;
    }
    
    try {
        $result = $canvas_course_sync->importer->import_courses($course_ids);
        if (isset($canvas_course_sync->logger)) {
            $canvas_course_sync->logger->log('Successfully synced courses: ' . implode(', ', $course_ids));
        }
        wp_send_json_success($result);
    } catch (Exception $e) {
        if (isset($canvas_course_sync->logger)) {
            $canvas_course_sync->logger->log('Exception during course sync: ' . $e->getMessage(), 'error');
        }
        wp_send_json_error($e->getMessage());
    }
}
add_action('wp_ajax_ccs_sync_courses', 'ccs_ajax_sync_courses');

/**
 * AJAX handler for clearing logs
 */
function ccs_ajax_clear_logs() {
    // Verify nonce first
    if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'ccs_clear_logs')) {
        wp_send_json_error(__('Security check failed', 'canvas-course-sync'));
        return;
    }
    
    // Check user capabilities
    if (!current_user_can('manage_options')) {
        wp_send_json_error(__('Permission denied', 'canvas-course-sync'));
        return;
    }
    
    $canvas_course_sync = canvas_course_sync();
    
    if (!$canvas_course_sync || !isset($canvas_course_sync->logger)) {
        wp_send_json_error(__('Logger not initialized', 'canvas-course-sync'));
        return;
    }
    
    $result = $canvas_course_sync->logger->clear_logs();
    
    if ($result) {
        $canvas_course_sync->logger->log('Logs cleared successfully');
        wp_send_json_success(__('Logs cleared successfully', 'canvas-course-sync'));
    } else {
        $canvas_course_sync->logger->log('Failed to clear logs', 'error');
        wp_send_json_error(__('Failed to clear logs', 'canvas-course-sync'));
    }
}
add_action('wp_ajax_ccs_clear_logs', 'ccs_ajax_clear_logs');

/**
 * AJAX handler for getting sync status
 */
function ccs_ajax_sync_status() {
    // Verify nonce first
    if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'ccs_sync_status')) {
        wp_send_json_error(__('Security check failed', 'canvas-course-sync'));
        return;
    }
    
    // Check user capabilities
    if (!current_user_can('manage_options')) {
        wp_send_json_error(__('Permission denied', 'canvas-course-sync'));
        return;
    }
    
    $status = function_exists('ccs_get_sync_status') ? ccs_get_sync_status() : array();
    wp_send_json_success($status);
}
add_action('wp_ajax_ccs_sync_status', 'ccs_ajax_sync_status');

/**
 * AJAX handler for running auto-sync
 */
function ccs_ajax_run_auto_sync() {
    // Verify nonce first
    if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'ccs_auto_sync')) {
        wp_send_json_error(__('Security check failed', 'canvas-course-sync'));
        return;
    }
    
    // Check user capabilities
    if (!current_user_can('manage_options')) {
        wp_send_json_error(__('Permission denied', 'canvas-course-sync'));
        return;
    }
    
    $canvas_course_sync = canvas_course_sync();
    
    if (!$canvas_course_sync || !isset($canvas_course_sync->scheduler)) {
        wp_send_json_error(__('Scheduler not initialized', 'canvas-course-sync'));
        return;
    }
    
    try {
        $result = $canvas_course_sync->scheduler->run_auto_sync();
        
        if ($result) {
            wp_send_json_success(array('message' => __('Auto-sync completed successfully', 'canvas-course-sync')));
        } else {
            wp_send_json_error(__('Auto-sync failed. Check logs for details.', 'canvas-course-sync'));
        }
    } catch (Exception $e) {
        if (isset($canvas_course_sync->logger)) {
            $canvas_course_sync->logger->log('Exception during auto-sync: ' . $e->getMessage(), 'error');
        }
        wp_send_json_error(__('Auto-sync failed: ', 'canvas-course-sync') . $e->getMessage());
    }
}
add_action('wp_ajax_ccs_run_auto_sync', 'ccs_ajax_run_auto_sync');
