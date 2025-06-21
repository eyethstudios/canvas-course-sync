
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
     * Generate URL slug from course title
     *
     * @param string $title Course title
     * @return string URL slug
     */
    private function generate_course_slug($title) {
        // Convert to lowercase and replace spaces/special chars with hyphens
        $slug = strtolower($title);
        $slug = preg_replace('/[^a-z0-9\s\-]/', '', $slug);
        $slug = preg_replace('/[\s\-]+/', '-', $slug);
        $slug = trim($slug, '-');
        
        // Ensure it's not empty
        if (empty($slug)) {
            $slug = 'course';
        }
        
        return $slug;
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
                // STRICT duplicate prevention - check multiple ways
                $existing_by_canvas_id = get_posts(array(
                    'post_type' => 'courses',
                    'meta_query' => array(
                        array(
                            'key' => 'canvas_course_id',
                            'value' => intval($course_id),
                            'compare' => '='
                        )
                    ),
                    'posts_per_page' => 1,
                    'post_status' => 'any'
                ));
                
                if (!empty($existing_by_canvas_id)) {
                    $results['skipped']++;
                    if ($this->logger) $this->logger->log('Course already exists (Canvas ID check): ' . $course_id);
                    continue;
                }
                
                $course_details = $this->api->get_course_details($course_id);
                
                if (is_wp_error($course_details)) {
                    $results['errors']++;
                    $error_msg = 'Failed to get course details for ID ' . $course_id . ': ' . $course_details->get_error_message();
                    if ($this->logger) $this->logger->log($error_msg, 'error');
                    continue;
                }
                
                $course_name = isset($course_details['name']) ? trim($course_details['name']) : 'Untitled Course';
                
                // Additional check by exact title match to prevent any duplicates
                $existing_by_title = get_posts(array(
                    'post_type' => 'courses',
                    'title' => $course_name,
                    'posts_per_page' => 1,
                    'post_status' => 'any'
                ));
                
                if (!empty($existing_by_title)) {
                    $existing_canvas_id = get_post_meta($existing_by_title[0]->ID, 'canvas_course_id', true);
                    if (intval($existing_canvas_id) === intval($course_id)) {
                        $results['skipped']++;
                        if ($this->logger) $this->logger->log('Course already exists (title + Canvas ID match): ' . $course_name);
                        continue;
                    }
                    // If different Canvas ID, append ID to make title unique
                    $course_name = $course_name . ' (Canvas ID: ' . $course_id . ')';
                }
                
                // Generate slug-based course URL
                $course_slug = $this->generate_course_slug($course_name);
                $enrollment_url = 'https://learn.nationaldeafcenter.org/courses/' . $course_slug;
                
                // Prepare course content using content handler with course ID
                $course_content = '';
                if ($this->content_handler) {
                    // Pass course_id as part of course details for proper content generation
                    $course_details['id'] = $course_id;
                    $course_content = $this->content_handler->prepare_course_content($course_details);
                    if ($this->logger) $this->logger->log('Generated course content length: ' . strlen($course_content));
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
                    
                    // Store the slug-based course URL
                    update_post_meta($post_id, 'link', esc_url_raw($enrollment_url));
                    if ($this->logger) $this->logger->log('Added slug-based course link: ' . $enrollment_url);
                    
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
                    if ($this->logger) $this->logger->log('Successfully imported course: ' . $course_name . ' (Post ID: ' . $post_id . ', URL: ' . $enrollment_url . ')');
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
}
