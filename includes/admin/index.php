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

// Register AJAX handlers - Admin only (requires manage_options capability)
add_action('wp_ajax_ccs_test_connection', 'ccs_ajax_test_connection');
add_action('wp_ajax_ccs_get_courses', 'ccs_ajax_get_courses');
add_action('wp_ajax_ccs_sync_courses', 'ccs_ajax_sync_courses');
add_action('wp_ajax_ccs_sync_status', 'ccs_ajax_sync_status');
add_action('wp_ajax_ccs_clear_logs', 'ccs_ajax_clear_logs');
add_action('wp_ajax_ccs_refresh_logs', 'ccs_ajax_refresh_logs');
add_action('wp_ajax_ccs_run_auto_sync', 'ccs_ajax_run_auto_sync');
add_action('wp_ajax_ccs_omit_courses', 'ccs_ajax_omit_courses');
add_action('wp_ajax_ccs_restore_omitted', 'ccs_ajax_restore_omitted');

/**
 * AJAX handler for testing connection
 */
function ccs_ajax_test_connection() {
    // Verify permissions and nonce
    if (!current_user_can('manage_options')) {
        wp_send_json_error(__('You do not have sufficient permissions to access this page.', 'canvas-course-sync'));
    }
    
    if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'ccs_test_connection')) {
        wp_send_json_error(__('Security check failed.', 'canvas-course-sync'));
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
    // Verify permissions and nonce
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => __('Insufficient permissions.', 'canvas-course-sync')));
        return;
    }
    
    if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'ccs_get_courses')) {
        wp_send_json_error(array('message' => __('Security check failed.', 'canvas-course-sync')));
        return;
    }
    
    $canvas_course_sync = canvas_course_sync();
    if (!$canvas_course_sync || !$canvas_course_sync->api) {
        wp_send_json_error(array('message' => __('Plugin not properly initialized.', 'canvas-course-sync')));
        return;
    }
    
    // Check Canvas credentials
    $canvas_domain = get_option('ccs_canvas_domain');
    $canvas_token = get_option('ccs_canvas_token');
    
    if (empty($canvas_domain) || empty($canvas_token)) {
        wp_send_json_error(array('message' => __('Canvas credentials not configured. Please check your API settings.', 'canvas-course-sync')));
        return;
    }
    
    try {
        $courses = $canvas_course_sync->api->get_courses();
        
        if (is_wp_error($courses)) {
            wp_send_json_error(array('message' => $courses->get_error_message()));
            return;
        }
        
        if (!is_array($courses)) {
            wp_send_json_error(array('message' => __('Invalid response format from Canvas API.', 'canvas-course-sync')));
            return;
        }

        // Validate against catalog and auto-omit non-approved courses
        $catalog_validator_path = CCS_PLUGIN_DIR . 'includes/class-ccs-catalog-validator.php';
        if (file_exists($catalog_validator_path)) {
            require_once $catalog_validator_path;
            $catalog_validator = new CCS_Catalog_Validator();
            $validation_results = $catalog_validator->validate_against_catalog($courses);
            
            // Continue with existing course processing - use validated courses
            $courses = $validation_results['validated'];
        } else {
            error_log('CCS: Catalog validator file not found at: ' . $catalog_validator_path);
            // Continue without validation
            $validation_results = array(
                'validated' => $courses,
                'omitted' => array(),
                'auto_omitted_ids' => array()
            );
        }
        
        // Get omitted courses list
        $omitted_courses = get_option('ccs_omitted_courses', array());
        if (!is_array($omitted_courses)) {
            $omitted_courses = array();
        }
        
        // Filter out excluded courses but keep omitted ones for display
        $courses = array_filter($courses, function($course) {
            $course_name = isset($course['name']) ? $course['name'] : '';
            
            // Check if excluded by title (these are permanently excluded)
            if (function_exists('ccs_is_course_excluded') && ccs_is_course_excluded($course_name)) {
                return false;
            }
            
            return true;
        });
        
        // Get existing WordPress courses for comparison
        $existing_wp_courses = get_posts(array(
            'post_type'      => 'courses',
            'post_status'    => array('draft', 'publish', 'private', 'pending'),
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'suppress_filters' => true
        ));
        
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
        
        // Check which courses already exist in WordPress and mark omitted status
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
            
            // Check if course is omitted
            $is_omitted = in_array($course_id, $omitted_courses);
            
            $courses[$key]['exists_in_wp'] = $exists_in_wp;
            $courses[$key]['match_type'] = $match_type;
            $courses[$key]['is_omitted'] = $is_omitted;
            
            // Add explicit status for easier frontend handling
            if ($is_omitted) {
                $courses[$key]['status'] = 'omitted';
                $courses[$key]['status_label'] = 'Omitted from Auto-Sync';
            } else if ($exists_in_wp) {
                if ($match_type === 'canvas_id') {
                    $courses[$key]['status'] = 'synced';
                    $courses[$key]['status_label'] = 'Already synced';
                } else {
                    $courses[$key]['status'] = 'exists';
                    $courses[$key]['status_label'] = 'Title exists in WP';
                }
            } else {
                $courses[$key]['status'] = 'new';
                $courses[$key]['status_label'] = 'Available for sync';
            }
        }
        
        // Reset array keys to ensure proper JSON encoding
        $courses = array_values($courses);
        
        // Add validation report to response
        $response = array(
            'courses' => $courses,
            'validation_report' => isset($catalog_validator) ? $catalog_validator->generate_validation_report($validation_results) : '',
            'auto_omitted_count' => count($validation_results['auto_omitted_ids'])
        );
        
        wp_send_json_success($response);
        
    } catch (Exception $e) {
        wp_send_json_error(array(
            'message' => __('An error occurred while fetching courses: ', 'canvas-course-sync') . $e->getMessage(),
            'code' => $e->getCode()
        ));
    }
}

/**
 * AJAX handler for syncing courses
 */
function ccs_ajax_sync_courses() {
    // Verify permissions and nonce
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => __('You do not have sufficient permissions to access this page.', 'canvas-course-sync')));
    }
    
    if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'ccs_sync_courses')) {
        wp_send_json_error(array('message' => __('Security check failed.', 'canvas-course-sync')));
    }
    
    // Sanitize course IDs
    $course_ids = array();
    if (isset($_POST['course_ids']) && is_array($_POST['course_ids'])) {
        $course_ids = array_map('intval', wp_unslash($_POST['course_ids']));
        $course_ids = array_filter($course_ids, function($id) { return $id > 0; });
    }
    
    if (empty($course_ids)) {
        wp_send_json_error(array('message' => __('No valid courses selected for sync.', 'canvas-course-sync')));
    }
    
    $canvas_course_sync = canvas_course_sync();
    if (!$canvas_course_sync || !$canvas_course_sync->importer) {
        wp_send_json_error(array('message' => __('Plugin not properly initialized.', 'canvas-course-sync')));
    }
    
    // Set sync status for polling
    set_transient('ccs_sync_status', array(
        'status' => __('Starting course sync...', 'canvas-course-sync'),
        'processed' => 0,
        'total' => count($course_ids)
    ), 300);
    
    try {
        $results = $canvas_course_sync->importer->import_courses($course_ids);
        
        // Clear sync status
        delete_transient('ccs_sync_status');
        
        // Ensure proper result structure
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
        // Clear sync status
        delete_transient('ccs_sync_status');
        
        wp_send_json_error(array(
            'message' => sprintf(__('Import failed: %s', 'canvas-course-sync'), $e->getMessage())
        ));
    }
}

/**
 * AJAX handler for omitting courses
 */
function ccs_ajax_omit_courses() {
    error_log('CCS: ccs_ajax_omit_courses called');
    
    // Verify permissions and nonce
    if (!current_user_can('manage_options')) {
        error_log('CCS: Omit courses - insufficient permissions');
        wp_send_json_error(array('message' => __('You do not have sufficient permissions to access this page.', 'canvas-course-sync')));
    }
    
    $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
    if (!wp_verify_nonce($nonce, 'ccs_omit_courses')) {
        error_log('CCS: Omit courses - nonce verification failed');
        wp_send_json_error(array('message' => __('Security check failed.', 'canvas-course-sync')));
    }
    
    // Get course IDs to omit
    $course_ids = isset($_POST['course_ids']) ? array_map('intval', wp_unslash($_POST['course_ids'])) : array();
    $course_ids = array_filter($course_ids, function($id) { return $id > 0; });
    
    error_log('CCS: Course IDs to omit: ' . print_r($course_ids, true));
    
    if (empty($course_ids)) {
        wp_send_json_error(array('message' => __('No valid courses selected to omit.', 'canvas-course-sync')));
    }
    
    // Get existing omitted courses
    $omitted_courses = get_option('ccs_omitted_courses', array());
    if (!is_array($omitted_courses)) {
        $omitted_courses = array();
    }
    
    error_log('CCS: Current omitted courses: ' . print_r($omitted_courses, true));
    
    // Add new courses to omitted list (avoid duplicates)
    $newly_omitted = 0;
    foreach ($course_ids as $course_id) {
        if (!in_array($course_id, $omitted_courses)) {
            $omitted_courses[] = $course_id;
            $newly_omitted++;
        }
    }
    
    // Save updated omitted courses list
    $update_result = update_option('ccs_omitted_courses', $omitted_courses);
    error_log('CCS: Updated omitted courses list. New count: ' . count($omitted_courses));
    error_log('CCS: Update option result: ' . ($update_result ? 'success' : 'failed'));
    
    wp_send_json_success(array(
        'message' => sprintf(__('Successfully omitted %d course(s) from future auto-syncing.', 'canvas-course-sync'), $newly_omitted),
        'omitted_count' => $newly_omitted,
        'total_omitted' => count($omitted_courses)
    ));
}

/**
 * AJAX handler for restoring omitted courses
 */
function ccs_ajax_restore_omitted() {
    error_log('CCS: ccs_ajax_restore_omitted called');
    
    // Verify permissions and nonce
    if (!current_user_can('manage_options')) {
        error_log('CCS: Restore omitted - insufficient permissions');
        wp_send_json_error(array('message' => __('You do not have sufficient permissions to access this page.', 'canvas-course-sync')));
    }
    
    $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
    if (!wp_verify_nonce($nonce, 'ccs_restore_omitted')) {
        error_log('CCS: Restore omitted - nonce verification failed');
        wp_send_json_error(array('message' => __('Security check failed.', 'canvas-course-sync')));
    }
    
    // Get current omitted courses count
    $omitted_courses = get_option('ccs_omitted_courses', array());
    $count = is_array($omitted_courses) ? count($omitted_courses) : 0;
    
    error_log('CCS: Restoring ' . $count . ' omitted courses');
    
    // Clear the omitted courses list
    $update_result = update_option('ccs_omitted_courses', array());
    error_log('CCS: Clear omitted courses result: ' . ($update_result ? 'success' : 'failed'));
    
    wp_send_json_success(array(
        'message' => sprintf(__('Successfully restored %d omitted course(s) for future auto-syncing.', 'canvas-course-sync'), $count),
        'restored_count' => $count
    ));
}

/**
 * AJAX handler for sync status
 */
function ccs_ajax_sync_status() {
    // Verify permissions and nonce
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => __('Insufficient permissions.', 'canvas-course-sync')));
        return;
    }
    
    if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'ccs_sync_status')) {
        wp_send_json_error(array('message' => __('Security check failed.', 'canvas-course-sync')));
        return;
    }
    
    // Get sync status from transient
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
    // Verify nonce and permissions
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => __('Insufficient permissions.', 'canvas-course-sync')));
        return;
    }
    
    if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'ccs_clear_logs')) {
        wp_send_json_error(array('message' => __('Security check failed.', 'canvas-course-sync')));
        return;
    }
    
    $canvas_course_sync = canvas_course_sync();
    if (!$canvas_course_sync || !$canvas_course_sync->logger) {
        wp_send_json_error(array('message' => __('Plugin not properly initialized.', 'canvas-course-sync')));
        return;
    }
    
    $canvas_course_sync->logger->clear_logs();
    
    wp_send_json_success(__('Logs cleared successfully.', 'canvas-course-sync'));
}

/**
 * AJAX handler for refreshing logs
 */
function ccs_ajax_refresh_logs() {
    // Verify nonce and permissions
    if (!current_user_can('manage_options')) {
        wp_send_json_error(__('You do not have sufficient permissions to access this page.', 'canvas-course-sync'));
    }
    
    if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'ccs_refresh_logs')) {
        wp_send_json_error(__('Security check failed.', 'canvas-course-sync'));
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
        echo '<div class="notice notice-info"><p>' . esc_html__('No logs found.', 'canvas-course-sync') . '</p></div>';
    } else {
        ?>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th scope="col" style="width: 150px;"><?php esc_html_e('Timestamp', 'canvas-course-sync'); ?></th>
                    <th scope="col" style="width: 80px;"><?php esc_html_e('Level', 'canvas-course-sync'); ?></th>
                    <th scope="col"><?php esc_html_e('Message', 'canvas-course-sync'); ?></th>
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
    // Verify nonce and permissions
    if (!current_user_can('manage_options')) {
        wp_send_json_error(__('You do not have sufficient permissions to access this page.',  'canvas-course-sync'));
    }
    
    if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'ccs_run_auto_sync')) {
        wp_send_json_error(__('Security check failed.', 'canvas-course-sync'));
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
