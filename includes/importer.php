
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
class CCS_Importer {
    
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
     * Constructor with dependency injection
     *
     * @param CCS_Logger $logger Logger instance
     * @param CCS_Canvas_API $api Canvas API instance
     * @param CCS_Media_Handler $media_handler Media handler instance
     * @param CCS_Content_Handler $content_handler Content handler instance
     * @param CCS_Database_Manager $db_manager Database manager instance
     * @param CCS_Slug_Generator $slug_generator Slug generator instance
     */
    public function __construct(
        CCS_Logger $logger,
        CCS_Canvas_API $api,
        CCS_Media_Handler $media_handler,
        CCS_Content_Handler $content_handler,
        CCS_Database_Manager $db_manager,
        CCS_Slug_Generator $slug_generator
    ) {
        $this->logger = $logger;
        $this->api = $api;
        $this->media_handler = $media_handler;
        $this->content_handler = $content_handler;
        $this->db_manager = $db_manager;
        $this->slug_generator = $slug_generator;
    }

    
    /**
     * Check if a course exists
     *
     * @param int $course_id Canvas course ID
     * @param string $course_name Course name
     * @return array Course exists check result
     */
    public function course_exists($course_id, $course_name = '') {
        return $this->db_manager->course_exists($course_id, $course_name);
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
                // Check for existing course
                error_log('CCS_Importer: Checking for existing course with Canvas ID: ' . $course_id);
                
                $exists_check = $this->db_manager->course_exists($course_id);
                
                if ($exists_check['exists']) {
                    // Check if the existing post is actually available
                    $existing_post = get_post($exists_check['post_id']);
                    if ($existing_post && $existing_post->post_status !== 'trash') {
                        error_log('CCS_Importer: DUPLICATE FOUND - Course already exists and is active: ' . $exists_check['type']);
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
                    } else {
                        error_log('CCS_Importer: Course tracking exists but post is deleted/trashed - will re-import with fresh content');
                        // Continue with import process to recreate the course with fresh Canvas content
                    }
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
                
                error_log('CCS_Importer: Course details received for: ' . ($course_details['name'] ?? 'Unknown'));
                
                $course_name = isset($course_details['name']) ? trim($course_details['name']) : 'Untitled Course';
                
                // Generate slug
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
                $enrollment_url = $this->slug_generator->generate_enrollment_url($course_slug);
                
                // Prepare detailed course content
                error_log('CCS_Importer: Preparing detailed course content...');
                $course_content = '';
                if ($this->content_handler) {
                    $course_details['id'] = $course_id;
                    $course_content = $this->content_handler->prepare_course_content($course_details);
                    error_log('CCS_Importer: Generated detailed content length: ' . strlen($course_content));
                }
                
                // Create course using database manager transaction
                error_log('CCS_Importer: Creating course using transaction...');
                $course_data = array(
                    'canvas_id' => $course_id,
                    'title' => $course_name,
                    'content' => $course_content,
                    'course_code' => $course_details['course_code'] ?? '',
                    'start_at' => $course_details['start_at'] ?? '',
                    'end_at' => $course_details['end_at'] ?? '',
                    'enrollment_term_id' => $course_details['enrollment_term_id'] ?? 0,
                    'enrollment_url' => $enrollment_url,
                    'slug' => $course_slug
                );
                
                $create_result = $this->db_manager->create_course_with_transaction($course_data);
                
                if (!$create_result['success']) {
                    error_log('CCS_Importer: ERROR - Transaction failed: ' . $create_result['error']);
                    $results['errors']++;
                    $results['details'][] = array(
                        'course_id' => $course_id,
                        'status' => 'error',
                        'reason' => 'Database transaction failed: ' . $create_result['error']
                    );
                    continue;
                }
                
                $post_id = $create_result['post_id'];
                
                if (is_wp_error($post_id)) {
                    error_log('CCS_Importer: ERROR - Failed to create post: ' . $post_id->get_error_message());
                    $results['errors']++;
                    $results['details'][] = array(
                        'course_id' => $course_id,
                        'status' => 'error',
                        'reason' => 'Failed to create post: ' . $post_id->get_error_message()
                    );
                    continue;
                }
                
                error_log('CCS_Importer: ✓ Course created as DRAFT - Post ID: ' . $post_id);
                
                // Handle course image
                if (!empty($course_details['image_download_url']) && $this->media_handler) {
                    $image_result = $this->media_handler->set_featured_image($post_id, $course_details['image_download_url'], $course_name);
                    if ($image_result) {
                        error_log('CCS_Importer: ✓ Featured image set successfully');
                    }
                }
                
                $results['imported']++;
                $results['details'][] = array(
                    'course_id' => $course_id,
                    'status' => 'imported',
                    'post_id' => $post_id,
                    'title' => $course_name,
                    'slug' => $course_slug,
                    'url' => $enrollment_url,
                    'post_status' => 'draft'
                );
                
                error_log('CCS_Importer: ✓ Course import completed - Post ID: ' . $post_id . ' (DRAFT)');
                if ($this->logger) $this->logger->log('Successfully imported course as draft: ' . $course_name . ' (Post ID: ' . $post_id . ')');
                
            } catch (Exception $e) {
                $results['errors']++;
                $error_msg = 'Exception processing course ID ' . $course_id . ': ' . $e->getMessage();
                error_log('CCS_Importer: EXCEPTION - ' . $error_msg);
                $results['details'][] = array(
                    'course_id' => $course_id,
                    'status' => 'error',
                    'reason' => $error_msg
                );
                if ($this->logger) $this->logger->log($error_msg, 'error');
            }
        }
        
        $results['message'] = sprintf(
            __('Import completed: %d imported as drafts, %d skipped, %d errors', 'canvas-course-sync'),
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
