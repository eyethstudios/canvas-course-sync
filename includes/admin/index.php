
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
    if (!ccs_user_can_manage_sync()) {
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
            wp_send_json_error(sanitize_text_field($result));
        }
    } catch (Exception $e) {
        if (isset($canvas_course_sync->logger)) {
            $canvas_course_sync->logger->log('Exception in API connection test: ' . $e->getMessage(), 'error');
        }
        wp_send_json_error(sanitize_text_field($e->getMessage()));
    }
}

/**
 * AJAX handler for getting Canvas courses - FIXED VERSION
 */
function ccs_ajax_get_courses() {
    // Debug logging
    error_log('CCS Debug: ccs_ajax_get_courses called');
    
    // Verify nonce
    if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'ccs_get_courses')) {
        error_log('CCS Debug: Nonce verification failed');
        wp_send_json_error(__('Security check failed', 'canvas-course-sync'));
        return;
    }
    
    // Check user capabilities
    if (!ccs_user_can_manage_sync()) {
        error_log('CCS Debug: User capability check failed');
        wp_send_json_error(__('Permission denied', 'canvas-course-sync'));
        return;
    }
    
    $canvas_course_sync = canvas_course_sync();
    
    if (!$canvas_course_sync || !isset($canvas_course_sync->api)) {
        error_log('CCS Debug: API not initialized');
        wp_send_json_error(__('API not initialized', 'canvas-course-sync'));
        return;
    }
    
    try {
        error_log('CCS Debug: Testing connection before getting courses');
        
        // First test the connection
        $connection_test = $canvas_course_sync->api->test_connection();
        if ($connection_test !== true) {
            if (isset($canvas_course_sync->logger)) {
                $canvas_course_sync->logger->log('Connection test failed before getting courses: ' . $connection_test, 'error');
            }
            error_log('CCS Debug: Connection test failed: ' . $connection_test);
            wp_send_json_error('Connection test failed: ' . sanitize_text_field($connection_test));
            return;
        }
        
        error_log('CCS Debug: Connection test passed, getting courses');
        
        $courses = $canvas_course_sync->api->get_courses();
        
        // Handle error response
        if (is_wp_error($courses)) {
            $error_message = $courses->get_error_message();
            if (isset($canvas_course_sync->logger)) {
                $canvas_course_sync->logger->log('Error getting courses: ' . $error_message, 'error');
            }
            error_log('CCS Debug: WP_Error getting courses: ' . $error_message);
            wp_send_json_error(sanitize_text_field($error_message));
            return;
        }
        
        // Validate courses response
        if (!is_array($courses)) {
            $error_msg = 'Invalid courses response format. Expected array, got: ' . gettype($courses);
            if (isset($canvas_course_sync->logger)) {
                $canvas_course_sync->logger->log($error_msg, 'error');
            }
            error_log('CCS Debug: ' . $error_msg);
            wp_send_json_error(sanitize_text_field($error_msg));
            return;
        }
        
        error_log('CCS Debug: Got ' . count($courses) . ' courses from Canvas API');
        
        // Process courses and check if they exist in WordPress
        $processed_courses = array();
        foreach ($courses as $course) {
            $course_data = is_object($course) ? (array) $course : $course;
            
            // Sanitize course data
            $course_data['id'] = isset($course_data['id']) ? intval($course_data['id']) : 0;
            $course_data['name'] = isset($course_data['name']) ? sanitize_text_field($course_data['name']) : '';
            
            if (empty($course_data['id']) || empty($course_data['name'])) {
                continue; // Skip invalid courses
            }
            
            // Check if course already exists in WordPress
            $existing_posts = get_posts(array(
                'post_type' => 'courses',
                'meta_key' => 'canvas_course_id',
                'meta_value' => $course_data['id'],
                'posts_per_page' => 1,
                'post_status' => array('publish', 'draft', 'private', 'pending'),
                'fields' => 'ids'
            ));
            
            $course_data['exists_in_wp'] = !empty($existing_posts);
            $processed_courses[] = $course_data;
        }
        
        error_log('CCS Debug: Processed ' . count($processed_courses) . ' courses with WP existence check');
        
        if (isset($canvas_course_sync->logger)) {
            $canvas_course_sync->logger->log('Successfully retrieved ' . count($processed_courses) . ' courses');
        }
        
        wp_send_json_success($processed_courses);
    } catch (Exception $e) {
        if (isset($canvas_course_sync->logger)) {
            $canvas_course_sync->logger->log('Exception getting courses: ' . $e->getMessage(), 'error');
        }
        error_log('CCS Debug: Exception in ccs_ajax_get_courses: ' . $e->getMessage());
        wp_send_json_error('Exception: ' . sanitize_text_field($e->getMessage()));
    }
}

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
    if (!ccs_user_can_manage_sync()) {
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
    
    // Remove any zero or negative IDs
    $course_ids = array_filter($course_ids, function($id) {
        return $id > 0;
    });
    
    if (empty($course_ids)) {
        wp_send_json_error(__('No valid course IDs provided', 'canvas-course-sync'));
        return;
    }
    
    // Limit number of courses that can be synced at once
    if (count($course_ids) > 50) {
        wp_send_json_error(__('Too many courses selected. Please select 50 or fewer courses.', 'canvas-course-sync'));
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
        wp_send_json_error(sanitize_text_field($e->getMessage()));
    }
}

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
    if (!ccs_user_can_manage_sync()) {
        wp_send_json_error(__('Permission denied', 'canvas-course-sync'));
        return;
    }
    
    $status = function_exists('ccs_get_sync_status') ? ccs_get_sync_status() : array();
    wp_send_json_success($status);
}

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
        wp_send_json_error(__('Auto-sync failed: ', 'canvas-course-sync') . sanitize_text_field($e->getMessage()));
    }
}

// CRITICAL: Register all AJAX actions immediately when this file is included
add_action('wp_ajax_ccs_test_connection', 'ccs_ajax_test_connection');
add_action('wp_ajax_ccs_get_courses', 'ccs_ajax_get_courses');
add_action('wp_ajax_ccs_sync_courses', 'ccs_ajax_sync_courses');
add_action('wp_ajax_ccs_clear_logs', 'ccs_ajax_clear_logs');
add_action('wp_ajax_ccs_sync_status', 'ccs_ajax_sync_status');
add_action('wp_ajax_ccs_run_auto_sync', 'ccs_ajax_run_auto_sync');
