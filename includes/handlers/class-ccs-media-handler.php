
<?php
/**
 * Handles media-related operations for course imports
 *
 * @package Canvas_Course_Sync
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Media Handler class
 */
class CCS_Media_Handler {
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
     * Set featured image for a course
     * 
     * @param int $post_id The post ID
     * @param string $image_url The image URL
     * @param string $course_name The course name for the image title
     * @return bool True on success, false on failure
     */
    public function set_featured_image($post_id, $image_url, $course_name) {
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
}

