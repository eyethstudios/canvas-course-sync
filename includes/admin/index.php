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
add_action('wp_ajax_ccs_sync_status', 'ccs_ajax_sync_status');
add_action('wp_ajax_ccs_clear_logs', 'ccs_ajax_clear_logs');
add_action('wp_ajax_ccs_refresh_logs', 'ccs_ajax_refresh_logs');
add_action('wp_ajax_ccs_run_auto_sync', 'ccs_ajax_run_auto_sync');
add_action('wp_ajax_ccs_omit_courses', 'ccs_ajax_omit_courses');

/**
 * AJAX handler for testing connection
 */
function ccs_ajax_test_connection() {
    error_log('CCS Debug: Test connection AJAX handler called');
    
    // Verify nonce
    if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'ccs_test_connection')) {
        error_log('CCS Debug: Test connection nonce verification failed');
        wp_send_json_error(__('Security check failed.', 'canvas-course-sync'));
    }
    
    if (!current_user_can('manage_options')) {
        error_log('CCS Debug: Test connection user does not have manage_options capability');
        wp_send_json_error(__('You do not have sufficient permissions to access this page.', 'canvas-course-sync'));
    }
    
    $canvas_course_sync = canvas_course_sync();
    if (!$canvas_course_sync || !$canvas_course_sync->api) {
        error_log('CCS Debug: Plugin or API not properly initialized');
        wp_send_json_error(__('Plugin not properly initialized.', 'canvas-course-sync'));
    }
    
    error_log('CCS Debug: Calling API test_connection method');
    $result = $canvas_course_sync->api->test_connection();
    
    if (is_wp_error($result)) {
        error_log('CCS Debug: API test_connection returned error: ' . $result->get_error_message());
        wp_send_json_error($result->get_error_message());
    } else {
        error_log('CCS Debug: API test_connection successful');
        wp_send_json_success(__('Connection successful!', 'canvas-course-sync'));
    }
}

/**
 * AJAX handler for getting courses
 */
function ccs_ajax_get_courses() {
    error_log('CCS Debug: ===== GET COURSES AJAX HANDLER START =====');
    error_log('CCS Debug: POST data: ' . print_r($_POST, true));
    
    // Verify nonce
    if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'ccs_get_courses')) {
        error_log('CCS Debug: Get courses nonce verification failed');
        error_log('CCS Debug: Expected nonce action: ccs_get_courses');
        error_log('CCS Debug: Received nonce: ' . (isset($_POST['nonce']) ? $_POST['nonce'] : 'NOT SET'));
        wp_send_json_error(__('Security check failed.', 'canvas-course-sync'));
    }
    
    if (!current_user_can('manage_options')) {
        error_log('CCS Debug: Get courses user does not have manage_options capability');
        wp_send_json_error(__('You do not have sufficient permissions to access this page.', 'canvas-course-sync'));
    }
    
    // Check if Canvas Course Sync plugin is loaded
    error_log('CCS Debug: Checking plugin initialization...');
    $canvas_course_sync = canvas_course_sync();
    error_log('CCS Debug: Plugin instance: ' . ($canvas_course_sync ? 'EXISTS' : 'NULL'));
    
    if (!$canvas_course_sync) {
        error_log('CCS Debug: Canvas Course Sync plugin instance not found');
        wp_send_json_error(__('Plugin not properly initialized - main instance missing.', 'canvas-course-sync'));
    }
    
    error_log('CCS Debug: Plugin API instance: ' . (isset($canvas_course_sync->api) && $canvas_course_sync->api ? 'EXISTS' : 'NULL'));
    
    if (!$canvas_course_sync->api) {
        error_log('CCS Debug: Plugin API not properly initialized');
        wp_send_json_error(__('Plugin not properly initialized - API missing.', 'canvas-course-sync'));
    }
    
    // Check Canvas credentials
    $canvas_domain = get_option('ccs_canvas_domain');
    $canvas_token = get_option('ccs_canvas_token');
    error_log('CCS Debug: Canvas domain set: ' . (!empty($canvas_domain) ? 'YES' : 'NO'));
    error_log('CCS Debug: Canvas token set: ' . (!empty($canvas_token) ? 'YES' : 'NO'));
    
    if (empty($canvas_domain) || empty($canvas_token)) {
        error_log('CCS Debug: Canvas credentials not properly configured');
        wp_send_json_error(__('Canvas credentials not configured. Please check your API settings.', 'canvas-course-sync'));
    }
    
    error_log('CCS Debug: Calling API get_courses method...');
    
    try {
        $courses = $canvas_course_sync->api->get_courses();
        error_log('CCS Debug: API get_courses call completed');
        error_log('CCS Debug: Result type: ' . gettype($courses));
        
        if (is_wp_error($courses)) {
            error_log('CCS Debug: API get_courses returned WP_Error: ' . $courses->get_error_message());
            error_log('CCS Debug: Error code: ' . $courses->get_error_code());
            wp_send_json_error($courses->get_error_message());
        }
        
        if (!is_array($courses)) {
            error_log('CCS Debug: API get_courses did not return an array. Type: ' . gettype($courses));
            error_log('CCS Debug: Actual value: ' . print_r($courses, true));
            wp_send_json_error(__('Invalid response format from Canvas API.', 'canvas-course-sync'));
        }
        
        error_log('CCS Debug: Raw courses count from API: ' . count($courses));
        error_log('CCS Debug: First 2 raw courses: ' . print_r(array_slice($courses, 0, 2), true));
        
        // Filter out excluded courses
        $original_count = count($courses);
        $courses = array_filter($courses, function($course) {
            $course_name = isset($course['name']) ? $course['name'] : '';
            $course_id = isset($course['id']) ? intval($course['id']) : 0;
            
            // Check if excluded by title
            $is_excluded = ccs_is_course_excluded($course_name);
            if ($is_excluded) {
                error_log('CCS Debug: Excluding course by title: ' . $course_name);
                return false;
            }
            
            // Check if omitted by user
            $is_omitted = ccs_is_course_omitted($course_id);
            if ($is_omitted) {
                error_log('CCS Debug: Excluding omitted course: ' . $course_name . ' (ID: ' . $course_id . ')');
                return false;
            }
            
            return true;
        });
        
        error_log('CCS Debug: After filtering exclusions and omissions: ' . count($courses) . ' courses (was ' . $original_count . ')');
        
        // Get existing WordPress courses for comparison
        error_log('CCS Debug: Getting existing WP courses...');
        $existing_wp_courses = get_posts(array(
            'post_type'      => 'courses',
            'post_status'    => array('draft', 'publish', 'private', 'pending'),
            'posts_per_page' => -1,
            'fields'         => 'ids'
        ));
        
        error_log('CCS Debug: Found ' . count($existing_wp_courses) . ' existing WP courses');
        
        $existing_titles = array();
        $existing_canvas_ids = array();
        
        foreach ($existing_wp_courses as $post_id) {
            $title = get_the_title($post_id);
            $canvas_id = get_post_meta($post_id, 'canvas_course_id', true);
            
            if (!empty($title)) {
                $existing_titles[strtolower(trim($title))] = $title;
            }
            if (!empty($canvas_id)) {
                $existing_canvas_ids[] = intval($canvas_id);
            }
        }
        
        error_log('CCS Debug: Existing title count: ' . count($existing_titles));
        error_log('CCS Debug: Existing Canvas ID count: ' . count($existing_canvas_ids));
        
        // Check which courses already exist in WordPress
        foreach ($courses as $key => $course) {
            $course_id = isset($course['id']) ? intval($course['id']) : 0;
            $course_name = isset($course['name']) ? trim($course['name']) : '';
            
            $exists_in_wp = false;
            $match_type = '';
            
            // Check by Canvas ID first (most reliable)
            if ($course_id > 0 && in_array($course_id, $existing_canvas_ids)) {
                $exists_in_wp = true;
                $match_type = 'canvas_id';
            } else if (!empty($course_name)) {
                // Check by title (case-insensitive, trimmed)
                $course_name_normalized = strtolower($course_name);
                if (isset($existing_titles[$course_name_normalized])) {
                    $exists_in_wp = true;
                    $match_type = 'title';
                }
            }
            
            $courses[$key]['exists_in_wp'] = $exists_in_wp;
            $courses[$key]['match_type'] = $match_type;
            
            // Add explicit status for easier frontend handling
            if ($exists_in_wp) {
                if ($match_type === 'canvas_id') {
                    $courses[$key]['status'] = 'synced';
                    $courses[$key]['status_label'] = 'Already synced';
                } else {
                    $courses[$key]['status'] = 'exists';
                    $courses[$key]['status_label'] = 'Title exists in WP';
                }
            } else {
                $courses[$key]['status'] = 'new';
                $courses[$key]['status_label'] = 'New';
            }
        }
        
        error_log('CCS Debug: Final processed courses count: ' . count($courses));
        error_log('CCS Debug: Sample processed course: ' . print_r(array_slice($courses, 0, 1), true));
        
        // Reset array keys to ensure proper JSON encoding
        $courses = array_values($courses);
        
        error_log('CCS Debug: About to send JSON success response');
        error_log('CCS Debug: ===== GET COURSES AJAX HANDLER SUCCESS =====');
        
        wp_send_json_success($courses);
        
    } catch (Exception $e) {
        error_log('CCS Debug: Exception in get_courses: ' . $e->getMessage());
        error_log('CCS Debug: Exception trace: ' . $e->getTraceAsString());
        wp_send_json_error(__('An error occurred while fetching courses: ', 'canvas-course-sync') . $e->getMessage());
    } catch (Error $e) {
        error_log('CCS Debug: Fatal error in get_courses: ' . $e->getMessage());
        error_log('CCS Debug: Error trace: ' . $e->getTraceAsString());
        wp_send_json_error(__('A fatal error occurred while fetching courses: ', 'canvas-course-sync') . $e->getMessage());
    }
    
    error_log('CCS Debug: ===== GET COURSES AJAX HANDLER END (should not reach here) =====');
}

/**
 * AJAX handler for syncing courses
 */
function ccs_ajax_sync_courses() {
    error_log('CCS Debug: Sync courses AJAX handler called');
    
    // Verify nonce
    if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'ccs_sync_courses')) {
        error_log('CCS Debug: Nonce verification failed');
        wp_send_json_error(array('message' => __('Security check failed.', 'canvas-course-sync')));
    }
    
    if (!current_user_can('manage_options')) {
        error_log('CCS Debug: User does not have manage_options capability');
        wp_send_json_error(array('message' => __('You do not have sufficient permissions to access this page.', 'canvas-course-sync')));
    }
    
    // Sanitize course IDs
    $course_ids = array();
    if (isset($_POST['course_ids']) && is_array($_POST['course_ids'])) {
        $course_ids = array_map('intval', wp_unslash($_POST['course_ids']));
        $course_ids = array_filter($course_ids, function($id) { return $id > 0; });
    }
    
    error_log('CCS Debug: Course IDs to sync: ' . print_r($course_ids, true));
    
    if (empty($course_ids)) {
        error_log('CCS Debug: No valid courses selected for sync');
        wp_send_json_error(array('message' => __('No valid courses selected for sync.', 'canvas-course-sync')));
    }
    
    $canvas_course_sync = canvas_course_sync();
    if (!$canvas_course_sync) {
        error_log('CCS Debug: Canvas Course Sync instance not found');
        wp_send_json_error(array('message' => __('Plugin not properly initialized.', 'canvas-course-sync')));
    }
    
    if (!$canvas_course_sync->importer) {
        error_log('CCS Debug: Importer not initialized');
        wp_send_json_error(array('message' => __('Course importer not properly initialized.', 'canvas-course-sync')));
    }
    
    // Set sync status for polling
    set_transient('ccs_sync_status', array(
        'status' => __('Starting course sync...', 'canvas-course-sync'),
        'processed' => 0,
        'total' => count($course_ids)
    ), 300);
    
    error_log('CCS Debug: Starting course import process');
    
    try {
        $results = $canvas_course_sync->importer->import_courses($course_ids);
        error_log('CCS Debug: Import results: ' . print_r($results, true));
        
        // Clear sync status
        delete_transient('ccs_sync_status');
        
        // Ensure we have proper result structure
        if (!isset($results['message'])) {
            $results['message'] = sprintf(
                __('Import completed: %d imported, %d skipped, %d errors', 'canvas-course-sync'),
                $results['imported'] ?? 0,
                $results['skipped'] ?? 0,
                $results['errors'] ?? 0
            );
        }
        
        wp_send_json_success($results);
    } catch (Exception $e) {
        error_log('CCS Debug: Exception during import: ' . $e->getMessage());
        error_log('CCS Debug: Exception trace: ' . $e->getTraceAsString());
        
        // Clear sync status
        delete_transient('ccs_sync_status');
        
        wp_send_json_error(array(
            'message' => sprintf(__('Import failed: %s', 'canvas-course-sync'), $e->getMessage())
        ));
    }
}

/**
 * AJAX handler for sync status
 */
function ccs_ajax_sync_status() {
    // Verify nonce
    if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'ccs_sync_status')) {
        wp_send_json_error(__('Security check failed.', 'canvas-course-sync'));
    }
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error(__('You do not have sufficient permissions to access this page.', 'canvas-course-sync'));
    }
    
    // Get sync status from transient or option
    $sync_status = get_transient('ccs_sync_status');
    
    if ($sync_status) {
        wp_send_json_success($sync_status);
    } else {
        wp_send_json_success(array(
            'status' => __('No sync in progress', 'canvas-course-sync'),
            'processed' => 0,
            'total' => 0
        ));
    }
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

/**
 * AJAX handler for refreshing logs
 */
function ccs_ajax_refresh_logs() {
    // Verify nonce
    if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'ccs_refresh_logs')) {
        wp_send_json_error(__('Security check failed.', 'canvas-course-sync'));
    }
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error(__('You do not have sufficient permissions to access this page.', 'canvas-course-sync'));
    }
    
    $canvas_course_sync = canvas_course_sync();
    if (!$canvas_course_sync || !$canvas_course_sync->logger) {
        wp_send_json_error(__('Plugin not properly initialized.', 'canvas-course-sync'));
    }
    
    // Get fresh logs
    $logs = $canvas_course_sync->logger->get_recent_logs(50);
    
    // Generate HTML for logs
    ob_start();
    if (empty($logs)) {
        echo '<div class="notice notice-info"><p>' . __('No logs found.', 'canvas-course-sync') . '</p></div>';
    } else {
        ?>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th scope="col" style="width: 150px;"><?php _e('Timestamp', 'canvas-course-sync'); ?></th>
                    <th scope="col" style="width: 80px;"><?php _e('Level', 'canvas-course-sync'); ?></th>
                    <th scope="col"><?php _e('Message', 'canvas-course-sync'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($logs as $log): ?>
                    <tr>
                        <td>
                            <?php 
                            $timestamp = isset($log->timestamp) ? $log->timestamp : '';
                            echo esc_html(mysql2date('Y-m-d H:i:s', $timestamp));
                            ?>
                        </td>
                        <td>
                            <span class="ccs-log-level ccs-log-level-<?php echo esc_attr($log->level ?? 'info'); ?>">
                                <?php echo esc_html(strtoupper($log->level ?? 'INFO')); ?>
                            </span>
                        </td>
                        <td><?php echo esc_html($log->message ?? ''); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        
        <style>
        .ccs-log-level {
            padding: 2px 6px;
            border-radius: 3px;
            font-size: 11px;
            font-weight: bold;
        }
        .ccs-log-level-info {
            background: #d1ecf1;
            color: #0c5460;
        }
        .ccs-log-level-warning {
            background: #fff3cd;
            color: #856404;
        }
        .ccs-log-level-error {
            background: #f8d7da;
            color: #721c24;
        }
        </style>
        <?php
    }
    $html = ob_get_clean();
    
    wp_send_json_success(array('html' => $html));
}

/**
 * AJAX handler for running auto-sync manually
 */
function ccs_ajax_run_auto_sync() {
    // Verify nonce
    if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'ccs_run_auto_sync')) {
        wp_send_json_error(__('Security check failed.', 'canvas-course-sync'));
    }
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error(__('You do not have sufficient permissions to access this page.', 'canvas-course-sync'));
    }
    
    $canvas_course_sync = canvas_course_sync();
    if (!$canvas_course_sync || !$canvas_course_sync->scheduler) {
        wp_send_json_error(__('Scheduler not properly initialized.', 'canvas-course-sync'));
    }
    
    $result = $canvas_course_sync->scheduler->run_auto_sync();
    
    if ($result) {
        wp_send_json_success(array(
            'message' => __('Auto-sync completed successfully. Check the logs for details.', 'canvas-course-sync')
        ));
    } else {
        wp_send_json_error(__('Auto-sync failed. Check the logs for details.', 'canvas-course-sync'));
    }
}

/**
 * AJAX handler for omitting courses
 */
function ccs_ajax_omit_courses() {
    error_log('CCS Debug: Omit courses AJAX handler called');
    
    // Verify nonce - use existing nonce for now
    if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'ccs_get_courses')) {
        error_log('CCS Debug: Omit courses nonce verification failed');
        wp_send_json_error(__('Security check failed.', 'canvas-course-sync'));
    }
    
    if (!current_user_can('manage_options')) {
        error_log('CCS Debug: User does not have manage_options capability');
        wp_send_json_error(__('You do not have sufficient permissions to access this page.', 'canvas-course-sync'));
    }
    
    // Get courses to omit
    $courses_to_omit = array();
    if (isset($_POST['courses']) && is_array($_POST['courses'])) {
        $courses_to_omit = array_map(function($course) {
            return array(
                'id' => intval($course['id']),
                'name' => sanitize_text_field($course['name'])
            );
        }, wp_unslash($_POST['courses']));
    }
    
    error_log('CCS Debug: Courses to omit: ' . print_r($courses_to_omit, true));
    
    if (empty($courses_to_omit)) {
        error_log('CCS Debug: No courses provided to omit');
        wp_send_json_error(array('message' => __('No courses selected to omit.', 'canvas-course-sync')));
    }
    
    // Get existing omitted courses
    $omitted_courses = get_option('ccs_omitted_courses', array());
    if (!is_array($omitted_courses)) {
        $omitted_courses = array();
    }
    
    // Add new courses to omitted list
    foreach ($courses_to_omit as $course) {
        $course_key = 'id_' . $course['id'];
        $omitted_courses[$course_key] = array(
            'id' => $course['id'],
            'name' => $course['name'],
            'omitted_at' => current_time('mysql')
        );
    }
    
    // Save updated omitted courses list
    $updated = update_option('ccs_omitted_courses', $omitted_courses);
    
    if ($updated) {
        error_log('CCS Debug: Successfully omitted ' . count($courses_to_omit) . ' course(s)');
        wp_send_json_success(array(
            'message' => sprintf(__('Successfully omitted %d course(s) from future syncing.', 'canvas-course-sync'), count($courses_to_omit))
        ));
    } else {
        error_log('CCS Debug: Failed to update omitted courses option');
        wp_send_json_error(array('message' => __('Failed to save omitted courses.', 'canvas-course-sync')));
    }
}
