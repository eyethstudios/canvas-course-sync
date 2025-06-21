
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
     * Media handler instance
     */
    private $media_handler;

    /**
     * Content handler instance
     */
    private $content_handler;
    
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
     * Get media handler instance safely
     */
    private function get_media_handler() {
        if ($this->media_handler === null) {
            // Make sure the class is loaded
            if (!class_exists('CCS_Media_Handler')) {
                require_once plugin_dir_path(__FILE__) . 'handlers/class-ccs-media-handler.php';
            }
            if (class_exists('CCS_Media_Handler')) {
                $this->media_handler = new CCS_Media_Handler();
            }
        }
        return $this->media_handler;
    }

    /**
     * Get content handler instance safely
     */
    private function get_content_handler() {
        if ($this->content_handler === null) {
            // Make sure the class is loaded
            if (!class_exists('CCS_Content_Handler')) {
                require_once plugin_dir_path(__FILE__) . 'handlers/class-ccs-content-handler.php';
            }
            if (class_exists('CCS_Content_Handler')) {
                $this->content_handler = new CCS_Content_Handler();
            }
        }
        return $this->content_handler;
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
        $media_handler = $this->get_media_handler();
        $content_handler = $this->get_content_handler();
        
        if (!$api) {
            error_log('CCS Debug: API not available');
            throw new Exception(__('Canvas API not properly initialized.', 'canvas-course-sync'));
        }
        
        if (!$logger) {
            error_log('CCS Debug: Logger not available - continuing without logging');
        }
        
        if (!$media_handler) {
            error_log('CCS Debug: Media handler not available - images will not be processed');
        }
        
        if (!$content_handler) {
            error_log('CCS Debug: Content handler not available - using basic content');
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
                
                // Prepare course content
                $course_name = $course_name ?: 'Untitled Course';
                $course_content = '';
                
                if ($content_handler) {
                    error_log('CCS Debug: Using content handler to prepare course content');
                    $course_content = $content_handler->prepare_course_content((object)$course_details);
                    if ($logger) $logger->log('Content handler returned ' . strlen($course_content) . ' characters');
                } else {
                    error_log('CCS Debug: Using fallback content preparation');
                    // Fallback content preparation
                    if (!empty($course_details['syllabus_body'])) {
                        $course_content = wp_kses_post($course_details['syllabus_body']);
                    } elseif (!empty($course_details['public_description'])) {
                        $course_content = wp_kses_post($course_details['public_description']);
                    } elseif (!empty($course_details['description'])) {
                        $course_content = wp_kses_post($course_details['description']);
                    }
                }
                
                error_log('CCS Debug: Final course content length: ' . strlen($course_content));
                
                // Create WordPress post
                $post_data = array(
                    'post_title' => sanitize_text_field($course_name),
                    'post_content' => $course_content,
                    'post_status' => 'publish',
                    'post_type' => 'courses',
                    'post_author' => get_current_user_id()
                );
                
                error_log('CCS Debug: Creating WordPress post with data: ' . print_r($post_data, true));
                
                $post_id = wp_insert_post($post_data);
                
                if ($post_id && !is_wp_error($post_id)) {
                    error_log('CCS Debug: Successfully created post ID: ' . $post_id);
                    
                    // Add Canvas metadata
                    update_post_meta($post_id, 'canvas_course_id', intval($course_id));
                    update_post_meta($post_id, 'canvas_course_code', sanitize_text_field($course_details['course_code'] ?? ''));
                    update_post_meta($post_id, 'canvas_start_at', sanitize_text_field($course_details['start_at'] ?? ''));
                    update_post_meta($post_id, 'canvas_end_at', sanitize_text_field($course_details['end_at'] ?? ''));
                    update_post_meta($post_id, 'canvas_enrollment_term_id', intval($course_details['enrollment_term_id'] ?? 0));
                    
                    // Add enrollment link - Fixed the field name
                    if (!empty($course_details['html_url'])) {
                        $enrollment_url = esc_url_raw($course_details['html_url']);
                        update_post_meta($post_id, 'link', $enrollment_url);
                        error_log('CCS Debug: Added enrollment link: ' . $enrollment_url);
                        if ($logger) $logger->log('Added enrollment link: ' . $enrollment_url);
                    }
                    
                    // Handle course image - Fixed the logic
                    if (!empty($course_details['image_download_url']) && $media_handler) {
                        error_log('CCS Debug: Attempting to set featured image from: ' . $course_details['image_download_url']);
                        if ($logger) $logger->log('Attempting to set featured image from: ' . $course_details['image_download_url']);
                        
                        $image_result = $media_handler->set_featured_image($post_id, $course_details['image_download_url'], $course_name);
                        
                        if ($image_result) {
                            error_log('CCS Debug: Successfully set featured image for course: ' . $course_name);
                            if ($logger) $logger->log('Successfully set featured image for course: ' . $course_name);
                        } else {
                            error_log('CCS Debug: Failed to set featured image for course: ' . $course_name);
                            if ($logger) $logger->log('Failed to set featured image for course: ' . $course_name, 'warning');
                        }
                    } elseif (empty($course_details['image_download_url'])) {
                        error_log('CCS Debug: No image URL available for course: ' . $course_name);
                    } elseif (!$media_handler) {
                        error_log('CCS Debug: Media handler not available for course: ' . $course_name);
                    }
                    
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
