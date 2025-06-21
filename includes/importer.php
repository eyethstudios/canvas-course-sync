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
        error_log('CCS_Importer: import_courses() called at ' . current_time('mysql'));
        error_log('CCS_Importer: Course IDs to import: ' . print_r($course_ids, true));
        
        if (!$this->api) {
            error_log('CCS_Importer: ERROR - Canvas API not properly initialized');
            throw new Exception(__('Canvas API not properly initialized.', 'canvas-course-sync'));
        }
        
        $results = array(
            'imported' => 0,
            'skipped' => 0,
            'errors' => 0,
            'total' => count($course_ids),
            'message' => ''
        );
        
        error_log('CCS_Importer: Starting import of ' . count($course_ids) . ' courses');
        
        foreach ($course_ids as $index => $course_id) {
            error_log('CCS_Importer: Processing course ' . ($index + 1) . ' of ' . count($course_ids) . ' - Canvas ID: ' . $course_id);
            
            // Update sync status
            set_transient('ccs_sync_status', array(
                'status' => sprintf(__('Processing course %d of %d...', 'canvas-course-sync'), $index + 1, count($course_ids)),
                'processed' => $index,
                'total' => count($course_ids)
            ), 300);
            
            try {
                // ENHANCED duplicate prevention with detailed logging
                error_log('CCS_Importer: Checking for existing course with Canvas ID: ' . $course_id);
                
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
                
                error_log('CCS_Importer: Canvas ID check query result: ' . print_r($existing_by_canvas_id, true));
                
                if (!empty($existing_by_canvas_id)) {
                    $existing_post = $existing_by_canvas_id[0];
                    error_log('CCS_Importer: DUPLICATE FOUND - Course already exists (Canvas ID check)');
                    error_log('CCS_Importer: Existing post ID: ' . $existing_post->ID . ', Title: ' . $existing_post->post_title);
                    $results['skipped']++;
                    if ($this->logger) $this->logger->log('Course already exists (Canvas ID check): ' . $course_id . ' - Post ID: ' . $existing_post->ID);
                    continue;
                }
                
                error_log('CCS_Importer: No existing course found by Canvas ID, fetching course details...');
                $course_details = $this->api->get_course_details($course_id);
                
                if (is_wp_error($course_details)) {
                    error_log('CCS_Importer: ERROR - Failed to get course details: ' . $course_details->get_error_message());
                    $results['errors']++;
                    $error_msg = 'Failed to get course details for ID ' . $course_id . ': ' . $course_details->get_error_message();
                    if ($this->logger) $this->logger->log($error_msg, 'error');
                    continue;
                }
                
                error_log('CCS_Importer: Course details received: ' . print_r($course_details, true));
                
                $course_name = isset($course_details['name']) ? trim($course_details['name']) : 'Untitled Course';
                error_log('CCS_Importer: Course name: ' . $course_name);
                
                // Additional check by exact title match to prevent any duplicates
                error_log('CCS_Importer: Checking for existing course by title: ' . $course_name);
                
                $existing_by_title = get_posts(array(
                    'post_type' => 'courses',
                    'title' => $course_name,
                    'posts_per_page' => 1,
                    'post_status' => 'any'
                ));
                
                error_log('CCS_Importer: Title check query result: ' . print_r($existing_by_title, true));
                
                if (!empty($existing_by_title)) {
                    $existing_canvas_id = get_post_meta($existing_by_title[0]->ID, 'canvas_course_id', true);
                    error_log('CCS_Importer: Found existing course by title - Canvas ID: ' . $existing_canvas_id);
                    
                    if (intval($existing_canvas_id) === intval($course_id)) {
                        error_log('CCS_Importer: DUPLICATE FOUND - Same Canvas ID, skipping');
                        $results['skipped']++;
                        if ($this->logger) $this->logger->log('Course already exists (title + Canvas ID match): ' . $course_name . ' - Canvas ID: ' . $course_id);
                        continue;
                    }
                    // If different Canvas ID, append ID to make title unique
                    $original_name = $course_name;
                    $course_name = $course_name . ' (Canvas ID: ' . $course_id . ')';
                    error_log('CCS_Importer: Title conflict resolved - changed from "' . $original_name . '" to "' . $course_name . '"');
                }
                
                // Generate slug-based course URL with detailed logging
                error_log('CCS_Importer: Generating course slug for: ' . $course_name);
                $course_slug = $this->generate_course_slug($course_name);
                error_log('CCS_Importer: Generated slug: ' . $course_slug);
                
                $enrollment_url = 'https://learn.nationaldeafcenter.org/courses/' . $course_slug;
                error_log('CCS_Importer: Generated enrollment URL: ' . $enrollment_url);
                
                // Prepare course content using content handler with course ID
                $course_content = '';
                if ($this->content_handler) {
                    error_log('CCS_Importer: Preparing course content using content handler...');
                    // Pass course_id as part of course details for proper content generation
                    $course_details['id'] = $course_id;
                    $course_content = $this->content_handler->prepare_course_content($course_details);
                    error_log('CCS_Importer: Generated course content length: ' . strlen($course_content));
                } else {
                    error_log('CCS_Importer: WARNING - Content handler not available');
                }
                
                // Create WordPress post with detailed logging
                error_log('CCS_Importer: Creating WordPress post...');
                $post_data = array(
                    'post_title' => sanitize_text_field($course_name),
                    'post_content' => $course_content,
                    'post_status' => 'publish',
                    'post_type' => 'courses',
                    'post_author' => get_current_user_id()
                );
                
                error_log('CCS_Importer: Post data: ' . print_r($post_data, true));
                
                $post_id = wp_insert_post($post_data);
                error_log('CCS_Importer: wp_insert_post result: ' . print_r($post_id, true));
                
                if ($post_id && !is_wp_error($post_id)) {
                    error_log('CCS_Importer: SUCCESS - Post created with ID: ' . $post_id);
                    
                    // Add Canvas metadata with logging
                    error_log('CCS_Importer: Adding post metadata...');
                    
                    $meta_updates = array(
                        'canvas_course_id' => intval($course_id),
                        'canvas_course_code' => sanitize_text_field($course_details['course_code'] ?? ''),
                        'canvas_start_at' => sanitize_text_field($course_details['start_at'] ?? ''),
                        'canvas_end_at' => sanitize_text_field($course_details['end_at'] ?? ''),
                        'canvas_enrollment_term_id' => intval($course_details['enrollment_term_id'] ?? 0),
                        'link' => esc_url_raw($enrollment_url)
                    );
                    
                    foreach ($meta_updates as $meta_key => $meta_value) {
                        $update_result = update_post_meta($post_id, $meta_key, $meta_value);
                        error_log('CCS_Importer: Updated meta ' . $meta_key . ' = ' . $meta_value . ' (result: ' . ($update_result ? 'success' : 'failed') . ')');
                    }
                    
                    // Handle course image
                    if (!empty($course_details['image_download_url']) && $this->media_handler) {
                        error_log('CCS_Importer: Setting featured image from: ' . $course_details['image_download_url']);
                        $image_result = $this->media_handler->set_featured_image($post_id, $course_details['image_download_url'], $course_name);
                        
                        if ($image_result) {
                            error_log('CCS_Importer: Successfully set featured image for: ' . $course_name);
                            if ($this->logger) $this->logger->log('Successfully set featured image for: ' . $course_name);
                        } else {
                            error_log('CCS_Importer: Failed to set featured image for: ' . $course_name);
                            if ($this->logger) $this->logger->log('Failed to set featured image for: ' . $course_name, 'warning');
                        }
                    } else {
                        error_log('CCS_Importer: No image URL or media handler not available');
                    }
                    
                    $results['imported']++;
                    error_log('CCS_Importer: Course import completed - Post ID: ' . $post_id . ', URL: ' . $enrollment_url);
                    if ($this->logger) $this->logger->log('Successfully imported course: ' . $course_name . ' (Post ID: ' . $post_id . ', URL: ' . $enrollment_url . ')');
                } else {
                    $results['errors']++;
                    $error_message = is_wp_error($post_id) ? $post_id->get_error_message() : 'Unknown error';
                    error_log('CCS_Importer: ERROR - Failed to create post: ' . $error_message);
                    if ($this->logger) $this->logger->log('Failed to create post for course: ' . $course_name . ' - ' . $error_message, 'error');
                }
                
            } catch (Exception $e) {
                $results['errors']++;
                $error_msg = 'Exception processing course ID ' . $course_id . ': ' . $e->getMessage();
                error_log('CCS_Importer: EXCEPTION - ' . $error_msg);
                error_log('CCS_Importer: Exception trace: ' . $e->getTraceAsString());
                if ($this->logger) $this->logger->log($error_msg, 'error');
            }
        }
        
        $results['message'] = sprintf(
            __('Import completed: %d imported, %d skipped, %d errors', 'canvas-course-sync'),
            $results['imported'],
            $results['skipped'],
            $results['errors']
        );
        
        error_log('CCS_Importer: Import process completed - Results: ' . print_r($results, true));
        
        return $results;
    }
}
