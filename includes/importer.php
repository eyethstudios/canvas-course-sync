
<?php
/**
 * Course Importer class for Canvas Course Sync
 *
 * @package Canvas_Course_Sync
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Course Importer class
 */
class CCS_Course_Importer {
    
    /**
     * Logger instance
     */
    private $logger;
    
    /**
     * API instance
     */
    private $api;
    
    /**
     * Constructor
     */
    public function __construct() {
        // Don't initialize in constructor to avoid circular dependency
    }
    
    /**
     * Get logger instance safely
     */
    private function get_logger() {
        if ($this->logger === null) {
            $canvas_course_sync = canvas_course_sync();
            if ($canvas_course_sync && isset($canvas_course_sync->logger)) {
                $this->logger = $canvas_course_sync->logger;
            }
        }
        return $this->logger;
    }
    
    /**
     * Get API instance safely
     */
    private function get_api() {
        if ($this->api === null) {
            $canvas_course_sync = canvas_course_sync();
            if ($canvas_course_sync && isset($canvas_course_sync->api)) {
                $this->api = $canvas_course_sync->api;
            }
        }
        return $this->api;
    }
    
    /**
     * Import courses from Canvas
     *
     * @param array $course_ids Array of course IDs to import
     * @return array Import results
     */
    public function import_courses($course_ids) {
        error_log('CCS Debug: Import courses called with IDs: ' . print_r($course_ids, true));
        
        $api = $this->get_api();
        $logger = $this->get_logger();
        
        if (!$api) {
            error_log('CCS Debug: API not available');
            throw new Exception(__('Canvas API not properly initialized.', 'canvas-course-sync'));
        }
        
        if (!$logger) {
            error_log('CCS Debug: Logger not available - continuing without logging');
        }
        
        $results = array(
            'imported' => 0,
            'skipped' => 0,
            'errors' => 0,
            'total' => count($course_ids),
            'message' => ''
        );
        
        error_log('CCS Debug: Starting import process for ' . count($course_ids) . ' courses');
        
        foreach ($course_ids as $index => $course_id) {
            error_log('CCS Debug: Processing course ID: ' . $course_id . ' (' . ($index + 1) . '/' . count($course_ids) . ')');
            
            // Update sync status
            set_transient('ccs_sync_status', array(
                'status' => sprintf(__('Processing course %d of %d...', 'canvas-course-sync'), $index + 1, count($course_ids)),
                'processed' => $index,
                'total' => count($course_ids)
            ), 300);
            
            try {
                $course_details = $api->get_course_details($course_id);
                
                if (is_wp_error($course_details)) {
                    $results['errors']++;
                    $error_msg = 'Failed to get course details for ID ' . $course_id . ': ' . $course_details->get_error_message();
                    if ($logger) $logger->log($error_msg, 'error');
                    error_log('CCS Debug: ' . $error_msg);
                    continue;
                }
                
                $course_name = isset($course_details['name']) ? $course_details['name'] : '';
                error_log('CCS Debug: Retrieved course details for "' . $course_name . '"');
                
                // Check if course should be excluded
                if (function_exists('ccs_is_course_excluded') && ccs_is_course_excluded($course_name)) {
                    $results['skipped']++;
                    if ($logger) $logger->log('Skipped excluded course: ' . $course_name . ' (ID: ' . $course_id . ')');
                    error_log('CCS Debug: Skipped excluded course: ' . $course_name);
                    continue;
                }
                
                // Check if course already exists
                $existing = get_posts(array(
                    'post_type' => 'courses',
                    'meta_key' => 'canvas_course_id',
                    'meta_value' => $course_id,
                    'posts_per_page' => 1,
                    'post_status' => 'any'
                ));
                
                if (!empty($existing)) {
                    $results['skipped']++;
                    if ($logger) $logger->log('Course already exists: ' . $course_name . ' (ID: ' . $course_id . ')');
                    error_log('CCS Debug: Course already exists: ' . $course_name);
                    continue;
                }
                
                // Create WordPress post
                $course_name = $course_name ?: 'Untitled Course';
                $course_description = isset($course_details['description']) ? $course_details['description'] : '';
                
                $post_data = array(
                    'post_title' => sanitize_text_field($course_name),
                    'post_content' => wp_kses_post($course_description),
                    'post_status' => 'publish',
                    'post_type' => 'courses',
                    'post_author' => get_current_user_id()
                );
                
                error_log('CCS Debug: Creating WordPress post with data: ' . print_r($post_data, true));
                
                $post_id = wp_insert_post($post_data);
                
                if ($post_id && !is_wp_error($post_id)) {
                    // Add Canvas metadata
                    update_post_meta($post_id, 'canvas_course_id', intval($course_id));
                    update_post_meta($post_id, 'canvas_course_code', sanitize_text_field($course_details['course_code'] ?? ''));
                    update_post_meta($post_id, 'canvas_start_at', sanitize_text_field($course_details['start_at'] ?? ''));
                    update_post_meta($post_id, 'canvas_end_at', sanitize_text_field($course_details['end_at'] ?? ''));
                    update_post_meta($post_id, 'canvas_enrollment_term_id', intval($course_details['enrollment_term_id'] ?? 0));
                    
                    $results['imported']++;
                    if ($logger) $logger->log('Successfully imported course: ' . $course_name . ' (ID: ' . $course_id . ')');
                    error_log('CCS Debug: Successfully imported course: ' . $course_name . ' (Post ID: ' . $post_id . ')');
                } else {
                    $results['errors']++;
                    $error_message = is_wp_error($post_id) ? $post_id->get_error_message() : 'Unknown error';
                    if ($logger) $logger->log('Failed to create WordPress post for course ID: ' . $course_id . ' - ' . $error_message, 'error');
                    error_log('CCS Debug: Failed to create WordPress post: ' . $error_message);
                }
                
            } catch (Exception $e) {
                $results['errors']++;
                $error_msg = 'Exception processing course ID ' . $course_id . ': ' . $e->getMessage();
                if ($logger) $logger->log($error_msg, 'error');
                error_log('CCS Debug: ' . $error_msg);
            }
        }
        
        $results['message'] = sprintf(
            __('Import completed: %d imported, %d skipped, %d errors', 'canvas-course-sync'),
            $results['imported'],
            $results['skipped'],
            $results['errors']
        );
        
        error_log('CCS Debug: Import completed with results: ' . print_r($results, true));
        
        return $results;
    }
}
