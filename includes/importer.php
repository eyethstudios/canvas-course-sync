
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
    }
    
    /**
     * Import courses from Canvas
     *
     * @param array $course_ids Array of course IDs to import
     * @return array Import results
     */
    public function import_courses($course_ids) {
        if (!$this->api) {
            throw new Exception(__('Canvas API not properly initialized.', 'canvas-course-sync'));
        }
        
        $results = array(
            'imported' => 0,
            'skipped' => 0,
            'errors' => 0,
            'total' => count($course_ids),
            'message' => ''
        );
        
        foreach ($course_ids as $index => $course_id) {
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
                    continue;
                }
                
                $course_name = isset($course_details['name']) ? $course_details['name'] : 'Untitled Course';
                
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
                    continue;
                }
                
                // Prepare course content using content handler
                $course_content = '';
                if ($this->content_handler) {
                    $course_content = $this->content_handler->prepare_course_content((object)$course_details);
                }
                
                // If content handler didn't provide content, use fallback
                if (empty($course_content)) {
                    if (!empty($course_details['syllabus_body'])) {
                        $course_content = wp_kses_post($course_details['syllabus_body']);
                    } elseif (!empty($course_details['public_description'])) {
                        $course_content = wp_kses_post($course_details['public_description']);
                    } elseif (!empty($course_details['description'])) {
                        $course_content = wp_kses_post($course_details['description']);
                    }
                }
                
                // Create WordPress post
                $post_data = array(
                    'post_title' => sanitize_text_field($course_name),
                    'post_content' => $course_content,
                    'post_status' => 'publish',
                    'post_type' => 'courses',
                    'post_author' => get_current_user_id()
                );
                
                $post_id = wp_insert_post($post_data);
                
                if ($post_id && !is_wp_error($post_id)) {
                    // Add Canvas metadata
                    update_post_meta($post_id, 'canvas_course_id', intval($course_id));
                    update_post_meta($post_id, 'canvas_course_code', sanitize_text_field($course_details['course_code'] ?? ''));
                    update_post_meta($post_id, 'canvas_start_at', sanitize_text_field($course_details['start_at'] ?? ''));
                    update_post_meta($post_id, 'canvas_end_at', sanitize_text_field($course_details['end_at'] ?? ''));
                    update_post_meta($post_id, 'canvas_enrollment_term_id', intval($course_details['enrollment_term_id'] ?? 0));
                    
                    // Generate and save student enrollment URL - FIXED
                    $enrollment_url = $this->generate_student_enrollment_url($course_id);
                    if (!empty($enrollment_url)) {
                        update_post_meta($post_id, 'link', esc_url_raw($enrollment_url));
                        if ($this->logger) $this->logger->log('Added student enrollment link: ' . $enrollment_url);
                    } else {
                        // Fallback URL generation
                        $canvas_domain = get_option('ccs_canvas_domain', '');
                        if (!empty($canvas_domain)) {
                            $canvas_domain = rtrim($canvas_domain, '/');
                            if (!preg_match('/^https?:\/\//', $canvas_domain)) {
                                $canvas_domain = 'https://' . $canvas_domain;
                            }
                            $fallback_url = $canvas_domain . '/courses/' . $course_id;
                            update_post_meta($post_id, 'link', esc_url_raw($fallback_url));
                            if ($this->logger) $this->logger->log('Added fallback enrollment link: ' . $fallback_url);
                        } else {
                            if ($this->logger) $this->logger->log('Warning: No Canvas domain configured, cannot generate enrollment URL', 'warning');
                        }
                    }
                    
                    // Handle course image
                    if (!empty($course_details['image_download_url']) && $this->media_handler) {
                        $image_result = $this->media_handler->set_featured_image($post_id, $course_details['image_download_url'], $course_name);
                        
                        if ($image_result) {
                            if ($this->logger) $this->logger->log('Successfully set featured image for: ' . $course_name);
                        } else {
                            if ($this->logger) $this->logger->log('Failed to set featured image for: ' . $course_name, 'warning');
                        }
                    }
                    
                    $results['imported']++;
                    if ($this->logger) $this->logger->log('Successfully imported course: ' . $course_name . ' (Post ID: ' . $post_id . ')');
                } else {
                    $results['errors']++;
                    $error_message = is_wp_error($post_id) ? $post_id->get_error_message() : 'Unknown error';
                    if ($this->logger) $this->logger->log('Failed to create post for course: ' . $course_name . ' - ' . $error_message, 'error');
                }
                
            } catch (Exception $e) {
                $results['errors']++;
                $error_msg = 'Exception processing course ID ' . $course_id . ': ' . $e->getMessage();
                if ($this->logger) $this->logger->log($error_msg, 'error');
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

    /**
     * Generate student enrollment URL for a course
     *
     * @param int $course_id Course ID
     * @return string Student enrollment URL
     */
    private function generate_student_enrollment_url($course_id) {
        // Get Canvas domain from settings
        $canvas_domain = get_option('ccs_canvas_domain');
        if (empty($canvas_domain)) {
            if ($this->logger) $this->logger->log('Warning: Canvas domain not configured', 'warning');
            return '';
        }
        
        // Clean up domain
        $canvas_domain = trim($canvas_domain);
        $canvas_domain = rtrim($canvas_domain, '/');
        
        // Add protocol if missing
        if (!preg_match('/^https?:\/\//', $canvas_domain)) {
            $canvas_domain = 'https://' . $canvas_domain;
        }
        
        // Generate student enrollment URL (not edit URL)
        $enrollment_url = $canvas_domain . '/courses/' . $course_id;
        
        if ($this->logger) $this->logger->log('Generated enrollment URL: ' . $enrollment_url . ' for course ID: ' . $course_id);
        
        return $enrollment_url;
    }
}
