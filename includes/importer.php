
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
     * Database manager instance
     */
    private $db_manager;

    /**
     * Slug generator instance
     */
    private $slug_generator;
    
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

        // Initialize database manager
        if (!class_exists('CCS_Database_Manager')) {
            require_once plugin_dir_path(__FILE__) . 'class-ccs-database-manager.php';
        }
        $this->db_manager = new CCS_Database_Manager();

        // Initialize slug generator
        if (!class_exists('CCS_Slug_Generator')) {
            require_once plugin_dir_path(__FILE__) . 'class-ccs-slug-generator.php';
        }
        $this->slug_generator = new CCS_Slug_Generator();
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
            'message' => '',
            'details' => array()
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
                // ENHANCED duplicate prevention using database manager
                error_log('CCS_Importer: Checking for existing course with Canvas ID: ' . $course_id);
                
                $exists_check = $this->db_manager->course_exists($course_id);
                
                if ($exists_check['exists']) {
                    error_log('CCS_Importer: DUPLICATE FOUND - Course already exists: ' . $exists_check['type']);
                    error_log('CCS_Importer: Existing data: ' . print_r($exists_check['data'], true));
                    $results['skipped']++;
                    $results['details'][] = array(
                        'course_id' => $course_id,
                        'status' => 'skipped',
                        'reason' => 'Already exists (' . $exists_check['type'] . ')',
                        'existing_post_id' => $exists_check['post_id']
                    );
                    if ($this->logger) $this->logger->log('Course already exists (' . $exists_check['type'] . '): ' . $course_id . ' - Post ID: ' . $exists_check['post_id']);
                    continue;
                }
                
                error_log('CCS_Importer: No existing course found, fetching course details...');
                $course_details = $this->api->get_course_details($course_id);
                
                if (is_wp_error($course_details)) {
                    error_log('CCS_Importer: ERROR - Failed to get course details: ' . $course_details->get_error_message());
                    $results['errors']++;
                    $error_msg = 'Failed to get course details for ID ' . $course_id . ': ' . $course_details->get_error_message();
                    $results['details'][] = array(
                        'course_id' => $course_id,
                        'status' => 'error',
                        'reason' => $error_msg
                    );
                    if ($this->logger) $this->logger->log($error_msg, 'error');
                    continue;
                }
                
                error_log('CCS_Importer: Course details received: ' . print_r($course_details, true));
                
                $course_name = isset($course_details['name']) ? trim($course_details['name']) : 'Untitled Course';
                error_log('CCS_Importer: Course name: ' . $course_name);
                
                // VERIFY SLUG GENERATION with detailed logging
                error_log('CCS_Importer: Generating course slug for: ' . $course_name);
                $slug_result = $this->slug_generator->generate_course_slug($course_name, $course_id);
                
                if (!$slug_result['success']) {
                    error_log('CCS_Importer: ERROR - Slug generation failed: ' . $slug_result['error']);
                    $results['errors']++;
                    $results['details'][] = array(
                        'course_id' => $course_id,
                        'status' => 'error',
                        'reason' => 'Slug generation failed: ' . $slug_result['error']
                    );
                    continue;
                }
                
                $course_slug = $slug_result['slug'];
                error_log('CCS_Importer: ✓ SLUG GENERATION VERIFIED - Generated slug: ' . $course_slug);
                
                // VERIFY ENROLLMENT URL GENERATION
                $enrollment_url = $this->slug_generator->generate_enrollment_url($course_slug);
                if (!$enrollment_url) {
                    error_log('CCS_Importer: ERROR - URL generation failed for slug: ' . $course_slug);
                    $results['errors']++;
                    continue;
                }
                error_log('CCS_Importer: ✓ URL GENERATION VERIFIED - Generated URL: ' . $enrollment_url);
                
                // Prepare course content using content handler with course ID
                $course_content = '';
                if ($this->content_handler) {
                    error_log('CCS_Importer: Preparing course content using content handler...');
                    // Pass course_id as part of course details for proper content generation
                    $course_details['id'] = $course_id;
                    $course_content = $this->content_handler->prepare_course_content($course_details);
                    error_log('CCS_Importer: ✓ CONTENT GENERATION VERIFIED - Generated content length: ' . strlen($course_content));
                } else {
                    error_log('CCS_Importer: WARNING - Content handler not available');
                }
                
                // Prepare data for database transaction
                $course_data = array(
                    'canvas_id' => $course_id,
                    'title' => $course_name,
                    'content' => $course_content,
                    'slug' => $course_slug,
                    'enrollment_url' => $enrollment_url,
                    'course_code' => $course_details['course_code'] ?? '',
                    'start_at' => $course_details['start_at'] ?? '',
                    'end_at' => $course_details['end_at'] ?? '',
                    'enrollment_term_id' => $course_details['enrollment_term_id'] ?? 0
                );
                
                error_log('CCS_Importer: Creating course with transaction handling...');
                $creation_result = $this->db_manager->create_course_with_transaction($course_data);
                
                if ($creation_result['success']) {
                    $post_id = $creation_result['post_id'];
                    error_log('CCS_Importer: ✓ COURSE CREATION VERIFIED - Post ID: ' . $post_id);
                    
                    // VERIFY META FIELDS WERE UPDATED CORRECTLY
                    $this->verify_meta_fields($post_id, $course_data);
                    
                    // Handle course image
                    if (!empty($course_details['image_download_url']) && $this->media_handler) {
                        error_log('CCS_Importer: Setting featured image from: ' . $course_details['image_download_url']);
                        $image_result = $this->media_handler->set_featured_image($post_id, $course_details['image_download_url'], $course_name);
                        
                        if ($image_result) {
                            error_log('CCS_Importer: ✓ FEATURED IMAGE VERIFIED - Successfully set for: ' . $course_name);
                            if ($this->logger) $this->logger->log('Successfully set featured image for: ' . $course_name);
                        } else {
                            error_log('CCS_Importer: ⚠ Featured image failed for: ' . $course_name);
                            if ($this->logger) $this->logger->log('Failed to set featured image for: ' . $course_name, 'warning');
                        }
                    }
                    
                    $results['imported']++;
                    $results['details'][] = array(
                        'course_id' => $course_id,
                        'status' => 'imported',
                        'post_id' => $post_id,
                        'title' => $course_name,
                        'slug' => $course_slug,
                        'url' => $enrollment_url
                    );
                    
                    error_log('CCS_Importer: ✓ COURSE IMPORT COMPLETED SUCCESSFULLY - Post ID: ' . $post_id . ', URL: ' . $enrollment_url);
                    if ($this->logger) $this->logger->log('Successfully imported course: ' . $course_name . ' (Post ID: ' . $post_id . ', URL: ' . $enrollment_url . ')');
                } else {
                    $results['errors']++;
                    $error_message = $creation_result['error'];
                    error_log('CCS_Importer: ERROR - Database transaction failed: ' . $error_message);
                    $results['details'][] = array(
                        'course_id' => $course_id,
                        'status' => 'error',
                        'reason' => 'Database transaction failed: ' . $error_message
                    );
                    if ($this->logger) $this->logger->log('Database transaction failed for course: ' . $course_name . ' - ' . $error_message, 'error');
                }
                
            } catch (Exception $e) {
                $results['errors']++;
                $error_msg = 'Exception processing course ID ' . $course_id . ': ' . $e->getMessage();
                error_log('CCS_Importer: EXCEPTION - ' . $error_msg);
                error_log('CCS_Importer: Exception trace: ' . $e->getTraceAsString());
                $results['details'][] = array(
                    'course_id' => $course_id,
                    'status' => 'error',
                    'reason' => $error_msg
                );
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

    /**
     * Verify meta fields were updated correctly
     */
    private function verify_meta_fields($post_id, $course_data) {
        error_log('CCS_Importer: ✓ VERIFYING META FIELDS for Post ID: ' . $post_id);
        
        $expected_meta = array(
            'canvas_course_id' => intval($course_data['canvas_id']),
            'canvas_course_code' => $course_data['course_code'],
            'canvas_start_at' => $course_data['start_at'],
            'canvas_end_at' => $course_data['end_at'],
            'canvas_enrollment_term_id' => intval($course_data['enrollment_term_id']),
            'link' => $course_data['enrollment_url']
        );
        
        $verification_results = array();
        
        foreach ($expected_meta as $meta_key => $expected_value) {
            $actual_value = get_post_meta($post_id, $meta_key, true);
            $matches = ($actual_value == $expected_value);
            
            $verification_results[$meta_key] = array(
                'expected' => $expected_value,
                'actual' => $actual_value,
                'matches' => $matches
            );
            
            if ($matches) {
                error_log('CCS_Importer: ✓ Meta field verified: ' . $meta_key . ' = ' . $actual_value);
            } else {
                error_log('CCS_Importer: ✗ Meta field mismatch: ' . $meta_key . ' - Expected: ' . $expected_value . ', Actual: ' . $actual_value);
            }
        }
        
        error_log('CCS_Importer: Meta field verification complete: ' . print_r($verification_results, true));
        
        return $verification_results;
    }
}
