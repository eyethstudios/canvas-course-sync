<?php
/**
 * Canvas Course Importer
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
     * @return array Import statistics
     */
    public function import_courses() {
        $this->logger->log('Starting course import process');
        
        $stats = array(
            'imported' => 0,
            'skipped' => 0,
            'errors' => 0
        );
        
        // Get courses from Canvas
        $courses = $this->api->get_courses();
        
        if (is_wp_error($courses)) {
            $this->logger->log('Failed to get courses: ' . $courses->get_error_message(), 'error');
            throw new Exception('Failed to get courses: ' . $courses->get_error_message());
        }
        
        $this->logger->log('Found ' . count($courses) . ' courses to process');
        
        // Process each course
        foreach ($courses as $course) {
            try {
                $this->logger->log('Processing course: ' . $course->name . ' (ID: ' . $course->id . ')');
                
                // Check if course already exists
                if ($this->course_exists($course->id)) {
                    $this->logger->log('Course already exists, skipping: ' . $course->name);
                    $stats['skipped']++;
                    continue;
                }
                
                // Get full course details
                $course_details = $this->api->get_course_details($course->id);
                
                if (is_wp_error($course_details)) {
                    $this->logger->log('Failed to get course details: ' . $course_details->get_error_message(), 'error');
                    $stats['errors']++;
                    continue;
                }
                
                // Debug log the course details to check what we're receiving
                $this->logger->log('Course details received: ' . print_r($course_details, true));
                
                // Create the course
                $course_id = $this->create_course($course_details);
                
                if (is_wp_error($course_id)) {
                    $this->logger->log('Failed to create course: ' . $course_id->get_error_message(), 'error');
                    $stats['errors']++;
                    continue;
                }
                
                // Try to set featured image
                $image_result = $this->set_featured_image($course_id, $course_details);
                $this->logger->log('Featured image result: ' . ($image_result ? 'Success' : 'Failed'));
                
                $this->logger->log('Successfully imported course: ' . $course_details->name . ' (WordPress ID: ' . $course_id . ')');
                $stats['imported']++;
                
            } catch (Exception $e) {
                $this->logger->log('Error processing course: ' . $e->getMessage(), 'error');
                $stats['errors']++;
            }
        }
        
        $this->logger->log('Import process completed. Imported: ' . $stats['imported'] . ', Skipped: ' . $stats['skipped'] . ', Errors: ' . $stats['errors']);
        
        return $stats;
    }

    /**
     * Check if a course already exists
     *
     * @param int $canvas_id Canvas course ID
     * @return boolean
     */
    private function course_exists($canvas_id) {
        $this->logger->log('Checking if course exists with Canvas ID: ' . $canvas_id);
        
        $args = array(
            'post_type' => 'courses',
            'post_status' => 'any',
            'meta_query' => array(
                array(
                    'key' => '_canvas_course_id',
                    'value' => $canvas_id,
                    'compare' => '='
                )
            ),
            'posts_per_page' => 1,
            'fields' => 'ids'
        );
        
        $query = new WP_Query($args);
        
        return $query->have_posts();
    }

    /**
     * Create a course post
     *
     * @param object $course Course data from Canvas
     * @return int|WP_Error WordPress post ID or error
     */
    private function create_course($course) {
        $this->logger->log('Creating course: ' . $course->name);
        
        // Handle description - ensure we capture the full HTML content
        $description = '';
        if (!empty($course->description)) {
            $description = $course->description;
        } elseif (!empty($course->syllabus_body)) {
            $description = $course->syllabus_body;
        }
        
        // Log the description to debug
        $this->logger->log('Course description length: ' . strlen($description));
        
        // Prepare post data
        $post_data = array(
            'post_title' => wp_strip_all_tags($course->name),
            'post_content' => $description,
            'post_status' => 'publish',
            'post_type' => 'courses'
        );
        
        // Insert the post
        $post_id = wp_insert_post($post_data, true);
        
        if (is_wp_error($post_id)) {
            $this->logger->log('Failed to create course: ' . $post_id->get_error_message(), 'error');
            return $post_id;
        }
        
        // Add Canvas course ID as post meta
        update_post_meta($post_id, '_canvas_course_id', $course->id);
        
        // Add other course details as post meta
        if (!empty($course->course_code)) {
            update_post_meta($post_id, '_canvas_course_code', $course->course_code);
        }
        
        if (!empty($course->start_at)) {
            update_post_meta($post_id, '_canvas_start_date', $course->start_at);
        }
        
        if (!empty($course->end_at)) {
            update_post_meta($post_id, '_canvas_end_date', $course->end_at);
        }
        
        $this->logger->log('Successfully created course with ID: ' . $post_id);
        
        return $post_id;
    }

    /**
     * Set featured image for course
     *
     * @param int $post_id WordPress post ID
     * @param object $course Canvas course data
     * @return boolean Success status
     */
    private function set_featured_image($post_id, $course) {
        $this->logger->log('Attempting to set featured image for course: ' . $course->name);
        
        // Check for course image URL in different possible properties
        $image_url = null;
        
        if (!empty($course->image_download_url)) {
            $image_url = $course->image_download_url;
            $this->logger->log('Found image_download_url: ' . $image_url);
        } elseif (!empty($course->image_url)) {
            $image_url = $course->image_url;
            $this->logger->log('Found image_url: ' . $image_url);
        } elseif (!empty($course->course_image_url)) {
            $image_url = $course->course_image_url;
            $this->logger->log('Found course_image_url: ' . $image_url);
        }
        
        // If we found an image URL, try to download and attach it
        if ($image_url) {
            try {
                $this->logger->log('Downloading image from URL: ' . $image_url);
                
                // Add the access token if needed
                if (strpos($image_url, '?') === false && strpos($image_url, $this->api_domain) !== false) {
                    $image_url .= '?access_token=' . $this->api->get_token();
                }
                
                $tmp_file = download_url($image_url);
                
                if (is_wp_error($tmp_file)) {
                    $this->logger->log('Failed to download course image: ' . $tmp_file->get_error_message(), 'error');
                    // Try alternate method through the API
                    return $this->try_api_image_download($post_id, $image_url, $course->name);
                }
                
                $result = $this->attach_image($post_id, $tmp_file, $course->name . ' Featured Image');
                return !is_wp_error($result);
            } catch (Exception $e) {
                $this->logger->log('Error setting featured image: ' . $e->getMessage(), 'error');
                return false;
            }
        }
        
        // Try to get course files and use a suitable image file
        try {
            $this->logger->log('No direct image URL found, checking course files');
            $files = $this->api->get_course_files($course->id);
            
            if (is_wp_error($files) || empty($files)) {
                $this->logger->log('No files found for course or error occurred', 'warning');
                return false;
            }
            
            // Look for image files
            $image_file = null;
            foreach ($files as $file) {
                if (isset($file->content_type) && strpos($file->content_type, 'image/') === 0) {
                    $image_file = $file;
                    break;
                }
            }
            
            if ($image_file) {
                $this->logger->log('Found suitable image in course files: ' . $image_file->display_name);
                
                if (empty($image_file->url)) {
                    $this->logger->log('Image file URL is empty', 'warning');
                    return false;
                }
                
                $tmp_file = $this->api->download_file($image_file->url);
                
                if (is_wp_error($tmp_file)) {
                    $this->logger->log('Failed to download image file: ' . $tmp_file->get_error_message(), 'error');
                    return false;
                }
                
                $result = $this->attach_image($post_id, $tmp_file, $course->name . ' Featured Image');
                return !is_wp_error($result);
            }
            
            $this->logger->log('No suitable image found for course', 'warning');
            return false;
        } catch (Exception $e) {
            $this->logger->log('Error looking for course images: ' . $e->getMessage(), 'error');
            return false;
        }
    }
    
    /**
     * Alternate method to download image via API
     *
     * @param int $post_id WordPress post ID
     * @param string $url Image URL
     * @param string $title Course title
     * @return boolean Success status
     */
    private function try_api_image_download($post_id, $url, $title) {
        $this->logger->log('Trying alternate image download method through API');
        
        try {
            $tmp_file = $this->api->download_file($url);
            
            if (is_wp_error($tmp_file)) {
                $this->logger->log('API file download failed: ' . $tmp_file->get_error_message(), 'error');
                return false;
            }
            
            $result = $this->attach_image($post_id, $tmp_file, $title . ' Featured Image');
            return !is_wp_error($result);
        } catch (Exception $e) {
            $this->logger->log('API image download error: ' . $e->getMessage(), 'error');
            return false;
        }
    }

    /**
     * Attach image to post as featured image
     *
     * @param int $post_id WordPress post ID
     * @param string $file Path to temporary file
     * @param string $title Image title
     * @return int|WP_Error Attachment ID or error
     */
    private function attach_image($post_id, $file, $title) {
        $this->logger->log('Attaching image to post ID: ' . $post_id);
        
        // Check if file exists
        if (!file_exists($file)) {
            $this->logger->log('Image file does not exist at path: ' . $file, 'error');
            return new WP_Error('missing_file', 'Image file does not exist');
        }
        
        // Check file size
        $file_size = filesize($file);
        if ($file_size === 0) {
            $this->logger->log('Image file is empty (0 bytes)', 'error');
            @unlink($file); // Delete the temp file
            return new WP_Error('empty_file', 'Image file is empty');
        }
        $this->logger->log('Image file size: ' . $file_size . ' bytes');
        
        // Check file type
        $file_type = wp_check_filetype(basename($file), null);
        
        if (empty($file_type['type'])) {
            $this->logger->log('Invalid file type for image', 'error');
            @unlink($file); // Delete the temp file
            return new WP_Error('invalid_file_type', 'Invalid file type for image');
        }
        
        $this->logger->log('File type detected: ' . $file_type['type']);
        
        // Prepare attachment data
        $attachment = array(
            'post_mime_type' => $file_type['type'],
            'post_title' => sanitize_text_field($title),
            'post_content' => '',
            'post_status' => 'inherit'
        );
        
        // Insert attachment
        $attach_id = wp_insert_attachment($attachment, $file, $post_id);
        
        if (is_wp_error($attach_id)) {
            $this->logger->log('Failed to insert attachment: ' . $attach_id->get_error_message(), 'error');
            @unlink($file); // Delete the temp file
            return $attach_id;
        }
        
        // Generate metadata
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        $attach_data = wp_generate_attachment_metadata($attach_id, $file);
        wp_update_attachment_metadata($attach_id, $attach_data);
        
        // Set as featured image
        $set_result = set_post_thumbnail($post_id, $attach_id);
        $this->logger->log('Set post thumbnail result: ' . ($set_result ? 'Success' : 'Failed'));
        
        $this->logger->log('Successfully attached image as featured image. Attachment ID: ' . $attach_id);
        
        // Delete the temp file
        @unlink($file);
        
        return $attach_id;
    }

    // Add to existing metabox display logic in the CCS_Importer class or create_metabox_content function
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

    // Make sure this is hooked up to the metabox display action
    // Example:
    // add_meta_box('ccs_course_link', 'Course Link', array($this, 'display_course_link_metabox'), 'courses', 'side', 'default');
}
