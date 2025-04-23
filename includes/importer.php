
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
     * @return array Result of import process
     */
    public function import_courses() {
        $this->logger->log('Starting course import process');
        
        $canvas_courses = $this->api->get_courses();
        if (is_wp_error($canvas_courses)) {
            $this->logger->log('Failed to fetch courses from Canvas API: ' . $canvas_courses->get_error_message(), 'error');
            return array(
                'imported' => 0,
                'skipped' => 0,
                'errors' => 1
            );
        }

        $imported = 0;
        $skipped = 0;
        $errors = 0;

        foreach ($canvas_courses as $canvas_course) {
            $course_id = $canvas_course->id;
            $course_name = $canvas_course->name;
            
            $this->logger->log('Processing course: ' . $course_name . ' (ID: ' . $course_id . ')');
            
            // Get detailed course information
            $course_details = $this->api->get_course_details($course_id);
            if (is_wp_error($course_details)) {
                $this->logger->log('Failed to get details for course ' . $course_id . ': ' . $course_details->get_error_message(), 'error');
                $errors++;
                continue;
            }

            // Get syllabus content
            $syllabus_content = '';
            if (!empty($course_details->syllabus_body)) {
                $syllabus_content = $course_details->syllabus_body;
                $this->logger->log('Found syllabus content (' . strlen($syllabus_content) . ' chars)');
            } else {
                $this->logger->log('No syllabus content found for course');
            }

            // Prepare post data - set post_status to draft
            $args = array(
                'post_title'   => $course_name ?? '',
                'post_status'  => 'draft', // Set to draft instead of publish
                'post_type'    => 'courses',
                'post_content' => $syllabus_content, // Set syllabus content as post_content
            );
            
            // Check if course already exists by post title
            $existing = get_posts(array(
                'post_type'      => 'courses',
                'title'          => $course_name,
                'posts_per_page' => 1,
                'fields'         => 'ids',
            ));

            if ($existing && count($existing) > 0) {
                $post_id = $existing[0];
                $this->logger->log('Updating existing course: ' . $course_name . ' (Post ID: ' . $post_id . ')');
                
                // Update existing post
                $args['ID'] = $post_id;
                wp_update_post($args);
            } else {
                $this->logger->log('Creating new course: ' . $course_name);
                
                // Create new post
                $post_id = wp_insert_post($args);
                if (is_wp_error($post_id) || !$post_id) {
                    $this->logger->log('Failed to create post for course ' . $course_id . ': ' . ($post_id->get_error_message() ?? 'Unknown error'), 'error');
                    $errors++;
                    continue;
                }
                
                // Save marker meta for future lookups (optional, keeps old method for debugging if needed)
                update_post_meta($post_id, 'canvas_course_id', $course_id);
            }

            // Set course link
            $canvas_domain = $this->api->get_domain();
            if (!empty($canvas_domain) && !empty($course_id)) {
                $canvas_course_link = trailingslashit($canvas_domain) . 'courses/' . $course_id;
                update_post_meta($post_id, 'link', esc_url_raw($canvas_course_link));
            }
            
            // Handle featured image if course has an image
            if (!empty($course_details->image_download_url)) {
                $this->logger->log('Course has image at URL: ' . $course_details->image_download_url);
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

        return array(
            'imported' => $imported,
            'skipped'  => $skipped,
            'errors'   => $errors,
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
        
        // Download image from Canvas
        $tmp_file = $this->api->download_file($image_url);
        if (is_wp_error($tmp_file)) {
            $this->logger->log('Failed to download image: ' . $tmp_file->get_error_message(), 'error');
            return false;
        }
        
        $this->logger->log('Successfully downloaded image to temp file: ' . $tmp_file);
        
        // Prepare file data for upload
        $file_name = basename($image_url);
        if (empty(pathinfo($file_name, PATHINFO_EXTENSION))) {
            // If no file extension, add .jpg as default
            $file_name .= '.jpg';
        }
        
        $file_type = wp_check_filetype($file_name, null);
        if (empty($file_type['type'])) {
            // Default to jpeg if filetype can't be determined
            $file_type['type'] = 'image/jpeg';
        }
        
        $this->logger->log('Uploading with file type: ' . $file_type['type']);
        
        // Read file content before upload
        $file_content = file_get_contents($tmp_file);
        if ($file_content === false) {
            $this->logger->log('Failed to read temporary file content', 'error');
            @unlink($tmp_file);
            return false;
        }
        
        // Upload the file to WordPress media
        $upload = wp_upload_bits($file_name, null, $file_content);
        
        // Clean up temp file
        @unlink($tmp_file);
        
        if ($upload['error']) {
            $this->logger->log('Failed to upload image: ' . $upload['error'], 'error');
            return false;
        }
        
        $this->logger->log('Successfully uploaded image to: ' . $upload['file']);
        
        // Prepare attachment data
        $attachment = array(
            'post_mime_type' => $file_type['type'],
            'post_title'     => sanitize_text_field($course_name . ' - Featured Image'),
            'post_content'   => '',
            'post_status'    => 'inherit'
        );
        
        // Insert attachment into media library
        $attachment_id = wp_insert_attachment($attachment, $upload['file'], $post_id);
        if (is_wp_error($attachment_id) || !$attachment_id) {
            $this->logger->log('Failed to insert attachment: ' . ($attachment_id->get_error_message() ?? 'Unknown error'), 'error');
            return false;
        }
        
        $this->logger->log('Created attachment with ID: ' . $attachment_id);
        
        // Make sure that the WordPress image processing functions are loaded
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        
        // Generate metadata for the attachment
        $attachment_data = wp_generate_attachment_metadata($attachment_id, $upload['file']);
        wp_update_attachment_metadata($attachment_id, $attachment_data);
        
        $this->logger->log('Updated attachment metadata');
        
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
