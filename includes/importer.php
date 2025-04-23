<?php
/**
 * Handles importing courses from Canvas into WP.
 *
 * @package Canvas_Course_Sync
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Include handlers
require_once plugin_dir_path(__FILE__) . 'handlers/class-ccs-content-handler.php';
require_once plugin_dir_path(__FILE__) . 'handlers/class-ccs-media-handler.php';

/**
 * Importer class
 */
class CCS_Importer {
    /**
     * Logger instance
     *
     * @var CCS_Logger
     */
    private $logger;

    /**
     * Canvas API instance
     *
     * @var CCS_Canvas_API
     */
    private $api;

    /**
     * Content Handler instance
     *
     * @var CCS_Content_Handler
     */
    private $content_handler;

    /**
     * Media Handler instance
     *
     * @var CCS_Media_Handler
     */
    private $media_handler;

    /**
     * Constructor
     */
    public function __construct() {
        global $canvas_course_sync;
        $this->logger = $canvas_course_sync->logger ?? new CCS_Logger();
        $this->api = $canvas_course_sync->api ?? new CCS_Canvas_API();
        $this->content_handler = new CCS_Content_Handler();
        $this->media_handler = new CCS_Media_Handler();
    }

    /**
     * Import specific courses from Canvas
     *
     * @param array $course_ids Array of Canvas course IDs to import
     * @return array Result of import process
     */
    public function import_courses($course_ids = array()) {
        $this->logger->log('Starting course import process for ' . count($course_ids) . ' selected courses');
        ccs_clear_sync_status();
        
        $imported = 0;
        $skipped = 0;
        $errors = 0;
        $processed = 0;
        $total_courses = count($course_ids);

        foreach ($course_ids as $course_id) {
            $processed++;
            ccs_update_sync_status(
                sprintf('Processing course %d of %d...', $processed, $total_courses),
                array(
                    'processed' => $processed,
                    'total' => $total_courses,
                    'imported' => $imported,
                    'skipped' => $skipped,
                    'errors' => $errors
                )
            );
            
            $course_details = $this->api->get_course_details($course_id);
            
            if (is_wp_error($course_details)) {
                $this->logger->log('Failed to get details for course ' . $course_id . ': ' . $course_details->get_error_message(), 'error');
                $errors++;
                continue;
            }
            
            $course_name = $course_details->name;
            
            $this->logger->log('Processing course: ' . $course_name . ' (ID: ' . $course_id . ')');
            
            // First, check if we have a post with this Canvas ID already
            $existing_by_id = get_posts(array(
                'post_type'      => 'courses',
                'post_status'    => array('draft', 'publish', 'private', 'pending'),
                'posts_per_page' => 1,
                'meta_key'       => 'canvas_course_id',
                'meta_value'     => $course_id,
                'fields'         => 'ids',
            ));

            if (!empty($existing_by_id)) {
                $post_id = $existing_by_id[0];
                $this->logger->log('Found existing course with Canvas ID metadata. Post ID: ' . $post_id . '. Skipping import.');
                $skipped++;
                continue;
            }
            
            // If no match by ID, check by title
            $existing_by_title = get_posts(array(
                'post_type'      => 'courses',
                'post_status'    => array('draft', 'publish', 'private', 'pending'),
                'title'          => $course_name,
                'posts_per_page' => 1,
                'fields'         => 'ids',
            ));
            
            if (!empty($existing_by_title)) {
                $post_id = $existing_by_title[0];
                $this->logger->log('Found existing course with ID: ' . $post_id . '. Skipping import.');
                
                // Maybe update existing Canvas course ID if not present
                $existing_canvas_id = get_post_meta($post_id, 'canvas_course_id', true);
                if (empty($existing_canvas_id)) {
                    update_post_meta($post_id, 'canvas_course_id', $course_id);
                    $this->logger->log('Added missing Canvas course ID metadata to existing post.');
                }
                
                $skipped++;
                continue;
            }
            
            // Process content using the content handler
            $post_content = $this->content_handler->prepare_course_content($course_details);
            
            // For debugging
            $this->logger->log('Post content length: ' . strlen($post_content) . ' characters');
            if (empty($post_content)) {
                $this->logger->log('Warning: No content found for course', 'warning');
            }
            
            // Create new post
            $post_id = wp_insert_post(array(
                'post_title'   => $course_name ?? '',
                'post_status'  => 'draft',
                'post_type'    => 'courses',
                'post_content' => $post_content,
            ));
            
            if (is_wp_error($post_id) || !$post_id) {
                $this->logger->log('Failed to create post for course ' . $course_id . ': ' . (is_wp_error($post_id) ? $post_id->get_error_message() : 'Unknown error'), 'error');
                $errors++;
                continue;
            }
            
            // Save marker meta for future lookups
            update_post_meta($post_id, 'canvas_course_id', $course_id);
            
            // Set course link
            $canvas_domain = $this->api->get_domain();
            if (!empty($canvas_domain) && !empty($course_id)) {
                $canvas_course_link = trailingslashit($canvas_domain) . 'courses/' . $course_id;
                update_post_meta($post_id, 'link', esc_url_raw($canvas_course_link));
            }
            
            // Handle featured image using the media handler
            if (!empty($course_details->image_download_url)) {
                $this->logger->log('Course has image at URL: ' . $course_details->image_download_url);
                
                $result = $this->media_handler->set_featured_image($post_id, $course_details->image_download_url, $course_name);
                
                if ($result) {
                    $this->logger->log('Successfully set featured image for course');
                } else {
                    $this->logger->log('Failed to set featured image for course', 'warning');
                }
            }
            
            if (!empty($existing_by_id) || !empty($existing_by_title)) {
                $skipped++;
            } else {
                $imported++;
            }
        }

        $final_status = sprintf(
            'Import complete. Processed %d courses (%d imported, %d skipped, %d errors)',
            $total_courses,
            $imported,
            $skipped,
            $errors
        );
        
        $this->logger->log($final_status);
        ccs_clear_sync_status();

        return array(
            'imported' => $imported,
            'skipped'  => $skipped,
            'errors'   => $errors,
            'total'    => $total_courses,
            'message'  => $final_status
        );
    }
}
