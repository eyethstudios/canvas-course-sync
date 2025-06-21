
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
        // Initialize handlers immediately
        $this->init_handlers();
    }

    /**
     * Initialize all handlers
     */
    private function init_handlers() {
        // Get main plugin instance
        $canvas_course_sync = canvas_course_sync();
        
        // Initialize logger
        if ($canvas_course_sync && isset($canvas_course_sync->logger)) {
            $this->logger = $canvas_course_sync->logger;
        } elseif (class_exists('CCS_Logger')) {
            $this->logger = new CCS_Logger();
        }
        
        // Initialize API
        if ($canvas_course_sync && isset($canvas_course_sync->api)) {
            $this->api = $canvas_course_sync->api;
        } elseif (class_exists('CCS_Canvas_API')) {
            $this->api = new CCS_Canvas_API();
        }
        
        // Initialize media handler
        if (!class_exists('CCS_Media_Handler')) {
            require_once plugin_dir_path(__FILE__) . 'handlers/class-ccs-media-handler.php';
        }
        $this->media_handler = new CCS_Media_Handler();
        
        // Initialize content handler  
        if (!class_exists('CCS_Content_Handler')) {
            require_once plugin_dir_path(__FILE__) . 'handlers/class-ccs-content-handler.php';
        }
        $this->content_handler = new CCS_Content_Handler();
        
        error_log('CCS Debug: Importer initialized - Logger: ' . ($this->logger ? 'yes' : 'no') . 
                  ', API: ' . ($this->api ? 'yes' : 'no') . 
                  ', Media: ' . ($this->media_handler ? 'yes' : 'no') . 
                  ', Content: ' . ($this->content_handler ? 'yes' : 'no'));
    }
    
    /**
     * Import courses from Canvas
     *
     * @param array $course_ids Array of course IDs to import
     * @return array Import results
     */
    public function import_courses($course_ids) {
        error_log('CCS Debug: Import courses called with IDs: ' . print_r($course_ids, true));
        
        if (!$this->api) {
            error_log('CCS Debug: API not available');
            throw new Exception(__('Canvas API not properly initialized.', 'canvas-course-sync'));
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
                $course_details = $this->api->get_course_details($course_id);
                
                if (is_wp_error($course_details)) {
                    $results['errors']++;
                    $error_msg = 'Failed to get course details for ID ' . $course_id . ': ' . $course_details->get_error_message();
                    if ($this->logger) $this->logger->log($error_msg, 'error');
                    error_log('CCS Debug: ' . $error_msg);
                    continue;
                }
                
                $course_name = isset($course_details['name']) ? $course_details['name'] : 'Untitled Course';
                error_log('CCS Debug: Retrieved course details for "' . $course_name . '"');
                
                // Check if course should be excluded
                if (function_exists('ccs_is_course_excluded') && ccs_is_course_excluded($course_name)) {
                    $results['skipped']++;
                    if ($this->logger) $this->logger->log('Skipped excluded course: ' . $course_name . ' (ID: ' . $course_id . ')');
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
                    if ($this->logger) $this->logger->log('Course already exists: ' . $course_name . ' (ID: ' . $course_id . ')');
                    error_log('CCS Debug: Course already exists: ' . $course_name);
                    continue;
                }
                
                // Prepare course content using content handler
                $course_content = '';
                if ($this->content_handler) {
                    error_log('CCS Debug: Using content handler to prepare course content');
                    $course_content = $this->content_handler->prepare_course_content((object)$course_details);
                    error_log('CCS Debug: Content handler returned ' . strlen($course_content) . ' characters');
                }
                
                // If content handler didn't provide content, use fallback
                if (empty($course_content)) {
                    error_log('CCS Debug: Content handler returned empty content, using fallback');
                    if (!empty($course_details['syllabus_body'])) {
                        $course_content = wp_kses_post($course_details['syllabus_body']);
                        error_log('CCS Debug: Using syllabus_body as content (' . strlen($course_content) . ' chars)');
                    } elseif (!empty($course_details['public_description'])) {
                        $course_content = wp_kses_post($course_details['public_description']);
                        error_log('CCS Debug: Using public_description as content (' . strlen($course_content) . ' chars)');
                    } elseif (!empty($course_details['description'])) {
                        $course_content = wp_kses_post($course_details['description']);
                        error_log('CCS Debug: Using description as content (' . strlen($course_content) . ' chars)');
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
                
                error_log('CCS Debug: Creating WordPress post with title: ' . $course_name);
                
                $post_id = wp_insert_post($post_data);
                
                if ($post_id && !is_wp_error($post_id)) {
                    error_log('CCS Debug: Successfully created post ID: ' . $post_id);
                    
                    // Add Canvas metadata
                    update_post_meta($post_id, 'canvas_course_id', intval($course_id));
                    update_post_meta($post_id, 'canvas_course_code', sanitize_text_field($course_details['course_code'] ?? ''));
                    update_post_meta($post_id, 'canvas_start_at', sanitize_text_field($course_details['start_at'] ?? ''));
                    update_post_meta($post_id, 'canvas_end_at', sanitize_text_field($course_details['end_at'] ?? ''));
                    update_post_meta($post_id, 'canvas_enrollment_term_id', intval($course_details['enrollment_term_id'] ?? 0));
                    
                    // Add enrollment link to custom field "link" - ensure proper URL format
                    $enrollment_url = '';
                    if (!empty($course_details['html_url'])) {
                        $enrollment_url = esc_url_raw($course_details['html_url']);
                    } elseif (!empty($course_details['calendar']['ics'])) {
                        // Sometimes the URL is in the calendar ics field
                        $enrollment_url = esc_url_raw($course_details['calendar']['ics']);
                    }
                    
                    if (!empty($enrollment_url)) {
                        $link_updated = update_post_meta($post_id, 'link', $enrollment_url);
                        error_log('CCS Debug: Enrollment link update result: ' . ($link_updated ? 'success' : 'failed') . ' - URL: ' . $enrollment_url);
                        if ($this->logger) $this->logger->log('Added enrollment link: ' . $enrollment_url);
                    } else {
                        error_log('CCS Debug: No enrollment URL found in course details');
                        error_log('CCS Debug: Available course detail keys: ' . implode(', ', array_keys($course_details)));
                        if ($this->logger) $this->logger->log('Warning: No enrollment URL found for course: ' . $course_name, 'warning');
                        
                        // Try to construct URL from domain and course ID
                        $canvas_domain = get_option('ccs_canvas_domain');
                        if (!empty($canvas_domain)) {
                            $constructed_url = rtrim($canvas_domain, '/') . '/courses/' . $course_id;
                            if (!preg_match('/^https?:\/\//', $constructed_url)) {
                                $constructed_url = 'https://' . $constructed_url;
                            }
                            update_post_meta($post_id, 'link', esc_url_raw($constructed_url));
                            error_log('CCS Debug: Constructed enrollment URL: ' . $constructed_url);
                            if ($this->logger) $this->logger->log('Constructed enrollment link: ' . $constructed_url);
                        }
                    }
                    
                    // Handle course image
                    if (!empty($course_details['image_download_url']) && $this->media_handler) {
                        error_log('CCS Debug: Setting featured image from: ' . $course_details['image_download_url']);
                        if ($this->logger) $this->logger->log('Setting featured image from: ' . $course_details['image_download_url']);
                        
                        $image_result = $this->media_handler->set_featured_image($post_id, $course_details['image_download_url'], $course_name);
                        
                        if ($image_result) {
                            error_log('CCS Debug: Successfully set featured image for: ' . $course_name);
                            if ($this->logger) $this->logger->log('Successfully set featured image for: ' . $course_name);
                        } else {
                            error_log('CCS Debug: Failed to set featured image for: ' . $course_name);
                            if ($this->logger) $this->logger->log('Failed to set featured image for: ' . $course_name, 'warning');
                        }
                    } else {
                        if (empty($course_details['image_download_url'])) {
                            error_log('CCS Debug: No image URL available for: ' . $course_name);
                        }
                        if (!$this->media_handler) {
                            error_log('CCS Debug: Media handler not available');
                        }
                    }
                    
                    $results['imported']++;
                    if ($this->logger) $this->logger->log('Successfully imported course: ' . $course_name . ' (Post ID: ' . $post_id . ')');
                    error_log('CCS Debug: Successfully imported course: ' . $course_name . ' (Post ID: ' . $post_id . ')');
                } else {
                    $results['errors']++;
                    $error_message = is_wp_error($post_id) ? $post_id->get_error_message() : 'Unknown error';
                    if ($this->logger) $this->logger->log('Failed to create post for course: ' . $course_name . ' - ' . $error_message, 'error');
                    error_log('CCS Debug: Failed to create post: ' . $error_message);
                }
                
            } catch (Exception $e) {
                $results['errors']++;
                $error_msg = 'Exception processing course ID ' . $course_id . ': ' . $e->getMessage();
                if ($this->logger) $this->logger->log($error_msg, 'error');
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
