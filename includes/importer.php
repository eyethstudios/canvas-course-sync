
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
     * Import courses from Canvas
     *
     * @param array $course_ids Array of course IDs to import
     * @return array Import results
     */
    public function import_courses($course_ids) {
        $canvas_course_sync = canvas_course_sync();
        $api = $canvas_course_sync->api;
        $logger = $canvas_course_sync->logger;
        
        $results = array(
            'imported' => 0,
            'skipped' => 0,
            'errors' => 0,
            'total' => count($course_ids),
            'message' => ''
        );
        
        foreach ($course_ids as $course_id) {
            $course_details = $api->get_course_details($course_id);
            
            if (is_wp_error($course_details)) {
                $results['errors']++;
                $logger->log('Failed to get course details for ID ' . $course_id . ': ' . $course_details->get_error_message(), 'error');
                continue;
            }
            
            // Check if course already exists
            $existing = get_posts(array(
                'post_type' => 'courses',
                'meta_key' => 'canvas_course_id',
                'meta_value' => $course_id,
                'posts_per_page' => 1
            ));
            
            if (!empty($existing)) {
                $results['skipped']++;
                continue;
            }
            
            // Create WordPress post
            $post_id = wp_insert_post(array(
                'post_title' => sanitize_text_field($course_details->name),
                'post_content' => wp_kses_post($course_details->description ?? ''),
                'post_status' => 'publish',
                'post_type' => 'courses'
            ));
            
            if ($post_id) {
                update_post_meta($post_id, 'canvas_course_id', $course_id);
                update_post_meta($post_id, 'canvas_course_code', sanitize_text_field($course_details->course_code ?? ''));
                $results['imported']++;
                $logger->log('Successfully imported course: ' . $course_details->name . ' (ID: ' . $course_id . ')');
            } else {
                $results['errors']++;
                $logger->log('Failed to create WordPress post for course ID: ' . $course_id, 'error');
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
