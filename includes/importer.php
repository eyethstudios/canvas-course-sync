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
     * Constructor
     */
    public function __construct() {
        global $canvas_course_sync;
        $this->logger = $canvas_course_sync->logger ?? new CCS_Logger();
        $this->api = $canvas_course_sync->api ?? new CCS_Canvas_API();
    }

    /**
     * Import courses from Canvas
     *
     * @param int $per_page Number of items per API page request
     * @return array Result of import process
     */
    public function import_courses($per_page = 50) {
        $this->logger->log('Starting course import process');
        
        // Get all courses using pagination
        $this->logger->log('Fetching courses from Canvas API with pagination (per_page=' . $per_page . ')');
        $canvas_courses = $this->api->get_courses($per_page);
        
        if (is_wp_error($canvas_courses)) {
            $this->logger->log('Failed to fetch courses from Canvas API: ' . $canvas_courses->get_error_message(), 'error');
            return array(
                'imported' => 0,
                'skipped' => 0,
                'errors' => 1,
                'total' => 0
            );
        }

        $total_courses = count($canvas_courses);
        $this->logger->log('Preparing to process ' . $total_courses . ' courses');
        
        $imported = 0;
        $skipped = 0;
        $errors = 0;
        $processed = 0;

        foreach ($canvas_courses as $canvas_course) {
            $course_id = $canvas_course->id;
            $course_name = $canvas_course->name;
            
            $processed++;
            if ($processed % 10 == 0) {
                $this->logger->log('Progress: ' . $processed . '/' . $total_courses . ' courses processed');
            }
            
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
                continue; // Skip to the next course
            }
            
            // If no match by ID, check by title (using a more reliable method)
            $existing_by_title = get_posts(array(
                'post_type'      => 'courses',
                'post_status'    => array('draft', 'publish', 'private', 'pending'),
                'title'          => $course_name, // WordPress will match this exactly
                'posts_per_page' => 1,
                'fields'         => 'ids',
            ));

            $this->logger->log('Checking for existing course with title: ' . $course_name . ', Found: ' . count($existing_by_title));
            
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
                continue; // Skip to the next course
            }
            
            // Get detailed course information
            $course_details = $this->api->get_course_details($course_id);
            if (is_wp_error($course_details)) {
                $this->logger->log('Failed to get details for course ' . $course_id . ': ' . $course_details->get_error_message(), 'error');
                $errors++;
                continue;
            }

            // Process syllabus content
            $syllabus_content = '';
            $post_content = '';
            
            // Check for syllabus content
            if (!empty($course_details->syllabus_body)) {
                $syllabus_content = $course_details->syllabus_body;
                $this->logger->log('Found syllabus content (' . strlen($syllabus_content) . ' chars)');
                $post_content = $syllabus_content;
            } else {
                $this->logger->log('No syllabus content found for course');
            }
            
            // Check for public description as fallback
            if (empty($post_content) && !empty($course_details->public_description)) {
                $this->logger->log('Using public description as fallback (' . strlen($course_details->public_description) . ' chars)');
                $post_content = $course_details->public_description;
            }

            // For debugging
            $this->logger->log('Post content length: ' . strlen($post_content));
            
            // Prepare post data - ensure post_status is draft
            $args = array(
                'post_title'   => $course_name ?? '',
                'post_status'  => 'draft', // Ensure it's set to draft
                'post_type'    => 'courses',
                'post_content' => $post_content, // Set prepared content
            );
            
            // Create new post
            $this->logger->log('Creating new course: ' . $course_name);
            $post_id = wp_insert_post($args);
            
            if (is_wp_error($post_id) || !$post_id) {
                $this->logger->log('Failed to create post for course ' . $course_id . ': ' . (is_wp_error($post_id) ? $post_id->get_error_message() : 'Unknown error'), 'error');
                $errors++;
                continue;
            } else {
                $this->logger->log('New post created successfully with ID: ' . $post_id . ' and ' . strlen($post_content) . ' chars of content');
            }
            
            // Save marker meta for future lookups
            update_post_meta($post_id, 'canvas_course_id', $course_id);

            // Set course link
            $canvas_domain = $this->api->get_domain();
            if (!empty($canvas_domain) && !empty($course_id)) {
                $canvas_course_link = trailingslashit($canvas_domain) . 'courses/' . $course_id;
                update_post_meta($post_id, 'link', esc_url_raw($canvas_course_link));
            }
            
            // Check for and handle featured image
            if (!empty($course_details->image_download_url)) {
                $this->logger->log('Course has image at URL: ' . $course_details->image_download_url);
                
                // Download and set the featured image properly
                $result = $this->set_featured_image($post_id, $course_details->image_download_url, $course_name);
                
                if ($result) {
                    $this->logger->log('Successfully set featured image for course');
                } else {
                    $this->logger->log('Failed to set featured image for course', 'warning');
                }
            } else {
                $this->logger->log('No image available for this course');
            }
            
            $imported++;
        }

        $this->logger->log('Import complete. Processed: ' . $total_courses . ', Imported: ' . $imported . ', Skipped: ' . $skipped . ', Errors: ' . $errors);

        return array(
            'imported' => $imported,
            'skipped'  => $skipped,
            'errors'   => $errors,
            'total'    => $total_courses,
        );
    }
    
    /**
     * Set featured image for a course
     * 
     * @param int $post_id The post ID
     * @param string $image_url The image URL
     * @param string $course_name The course name for the image title
     * @return bool True on success, false on failure
     */
    private function set_featured_image($post_id, $image_url, $course_name) {
        // Check if post already has a featured image
        if (has_post_thumbnail($post_id)) {
            $this->logger->log('Post already has featured image. Removing old image before setting new one.');
            delete_post_thumbnail($post_id);
        }
        
        $this->logger->log('Setting featured image for course: ' . $course_name);
        
        // Ensure the URL doesn't have any spaces
        $image_url = str_replace(' ', '%20', $image_url);
        $this->logger->log('Downloading image from URL: ' . $image_url);
        
        // Download image from Canvas using WordPress functions
        include_once(ABSPATH . 'wp-admin/includes/file.php');
        include_once(ABSPATH . 'wp-admin/includes/media.php');
        include_once(ABSPATH . 'wp-admin/includes/image.php');
        
        // Get the file and save it to the media library directly
        $attachment_id = media_sideload_image($image_url, $post_id, $course_name . ' - Featured Image', 'id');
        
        if (is_wp_error($attachment_id)) {
            $this->logger->log('Failed to sideload image: ' . $attachment_id->get_error_message(), 'error');
            
            // Fall back to manual download if sideload fails
            $tmp_file = $this->api->download_file($image_url);
            if (is_wp_error($tmp_file)) {
                $this->logger->log('Manual download also failed: ' . $tmp_file->get_error_message(), 'error');
                return false;
            }
            
            $file_array = array(
                'name' => basename($image_url),
                'tmp_name' => $tmp_file
            );
            
            $attachment_id = media_handle_sideload($file_array, $post_id, $course_name . ' - Featured Image');
            
            if (is_wp_error($attachment_id)) {
                $this->logger->log('Manual sideload also failed: ' . $attachment_id->get_error_message(), 'error');
                @unlink($tmp_file);
                return false;
            }
        }
        
        // Set as featured image
        $result = set_post_thumbnail($post_id, $attachment_id);
        
        if ($result) {
            $this->logger->log('Successfully set featured image (thumbnail ID: ' . $attachment_id . ') for post ID: ' . $post_id);
        } else {
            $this->logger->log('Failed to set post thumbnail', 'error');
        }
        
        return $result;
    }

    /**
     * Display course link metabox
     * 
     * @param WP_Post $post The post object
     */
    public function display_course_link_metabox($post) {
        $canvas_link = get_post_meta($post->ID, 'link', true);
        echo '<p><strong>Canvas Course Link:</strong> ';
        if (!empty($canvas_link)) {
            echo '<a href="' . esc_url($canvas_link) . '" target="_blank" rel="noopener noreferrer">' . esc_html($canvas_link) . '</a>';
        } else {
            echo 'No link available.';
        }
        echo '</p>';
    }
}
