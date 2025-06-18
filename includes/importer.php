
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
        $canvas_course_sync = canvas_course_sync();
        if ($canvas_course_sync) {
            $this->logger = $canvas_course_sync->logger;
            $this->api = $canvas_course_sync->api;
        }
    }
    
    /**
     * Import courses from Canvas
     *
     * @param array $course_ids Array of course IDs to import
     * @return array Import results
     */
    public function import_courses($course_ids) {
        if (!$this->api || !$this->logger) {
            return array(
                'imported' => 0,
                'skipped' => 0,
                'errors' => 1,
                'total' => 0,
                'message' => __('Plugin components not properly initialized.', 'canvas-course-sync')
            );
        }
        
        $results = array(
            'imported' => 0,
            'skipped' => 0,
            'errors' => 0,
            'total' => count($course_ids),
            'message' => ''
        );
        
        foreach ($course_ids as $course_id) {
            $course_details = $this->api->get_course_details($course_id);
            
            if (is_wp_error($course_details)) {
                $results['errors']++;
                $this->logger->log('Failed to get course details for ID ' . $course_id . ': ' . $course_details->get_error_message(), 'error');
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
                $this->logger->log('Course already exists: ' . $course_details->name . ' (ID: ' . $course_id . ')');
                continue;
            }
            
            // Create WordPress post
            $post_data = array(
                'post_title' => sanitize_text_field($course_details->name ?? 'Untitled Course'),
                'post_content' => wp_kses_post($course_details->description ?? ''),
                'post_status' => 'publish',
                'post_type' => 'courses',
                'post_author' => get_current_user_id()
            );
            
            $post_id = wp_insert_post($post_data);
            
            if ($post_id && !is_wp_error($post_id)) {
                // Add Canvas metadata
                update_post_meta($post_id, 'canvas_course_id', intval($course_id));
                update_post_meta($post_id, 'canvas_course_code', sanitize_text_field($course_details->course_code ?? ''));
                update_post_meta($post_id, 'canvas_start_at', sanitize_text_field($course_details->start_at ?? ''));
                update_post_meta($post_id, 'canvas_end_at', sanitize_text_field($course_details->end_at ?? ''));
                update_post_meta($post_id, 'canvas_enrollment_term_id', intval($course_details->enrollment_term_id ?? 0));
                
                $results['imported']++;
                $this->logger->log('Successfully imported course: ' . $course_details->name . ' (ID: ' . $course_id . ')');
            } else {
                $results['errors']++;
                $error_message = is_wp_error($post_id) ? $post_id->get_error_message() : 'Unknown error';
                $this->logger->log('Failed to create WordPress post for course ID: ' . $course_id . ' - ' . $error_message, 'error');
            }
        }
        
        $results['message'] = sprintf(
            __('Import completed: %d imported, %d skipped, %d errors', 'canvas-course-sync'),
            $results['imported'],
            $results['skipped'],
            $results['errors']
        );
        
        return $results;
    }
}
