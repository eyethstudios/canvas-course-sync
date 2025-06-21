
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
        // Safely get global instance
        $this->init_dependencies();
    }

    /**
     * Initialize dependencies safely
     */
    private function init_dependencies() {
        $canvas_course_sync = canvas_course_sync();
        
        if ($canvas_course_sync) {
            $this->logger = $canvas_course_sync->logger ?? null;
            $this->api = $canvas_course_sync->api ?? null;
        }
        
        // Create fallback instances if needed
        if (!$this->logger && class_exists('CCS_Logger')) {
            $this->logger = new CCS_Logger();
        }
        
        if (!$this->api && class_exists('CCS_Canvas_API')) {
            $this->api = new CCS_Canvas_API();
        }
        
        error_log('CCS Debug: Media handler initialized with logger: ' . ($this->logger ? 'yes' : 'no') . ', api: ' . ($this->api ? 'yes' : 'no'));
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
        error_log('CCS Debug: Media handler set_featured_image called for post: ' . $post_id . ', URL: ' . $image_url);
        
        // Check if post already has a featured image
        if (has_post_thumbnail($post_id)) {
            if ($this->logger) $this->logger->log('Post already has featured image. Removing old image before setting new one.');
            error_log('CCS Debug: Post already has featured image, removing old one');
            delete_post_thumbnail($post_id);
        }
        
        if ($this->logger) $this->logger->log('Setting featured image for course: ' . $course_name);
        
        // Ensure the URL doesn't have any spaces
        $image_url = str_replace(' ', '%20', $image_url);
        if ($this->logger) $this->logger->log('Downloading image from URL: ' . $image_url);
        error_log('CCS Debug: Processed image URL: ' . $image_url);
        
        // Download image from Canvas using WordPress functions
        include_once(ABSPATH . 'wp-admin/includes/file.php');
        include_once(ABSPATH . 'wp-admin/includes/media.php');
        include_once(ABSPATH . 'wp-admin/includes/image.php');
        
        // Get the file and save it to the media library directly
        $attachment_id = media_sideload_image($image_url, $post_id, $course_name . ' - Featured Image', 'id');
        
        if (is_wp_error($attachment_id)) {
            error_log('CCS Debug: media_sideload_image failed: ' . $attachment_id->get_error_message());
            if ($this->logger) $this->logger->log('Failed to sideload image: ' . $attachment_id->get_error_message(), 'error');
            
            // Fall back to manual download if sideload fails and API is available
            if ($this->api) {
                error_log('CCS Debug: Trying manual download with API');
                $tmp_file = $this->api->download_file($image_url);
                if (is_wp_error($tmp_file)) {
                    error_log('CCS Debug: Manual download also failed: ' . $tmp_file->get_error_message());
                    if ($this->logger) $this->logger->log('Manual download also failed: ' . $tmp_file->get_error_message(), 'error');
                    return false;
                }
                
                $file_array = array(
                    'name' => basename($image_url),
                    'tmp_name' => $tmp_file
                );
                
                $attachment_id = media_handle_sideload($file_array, $post_id, $course_name . ' - Featured Image');
                
                if (is_wp_error($attachment_id)) {
                    error_log('CCS Debug: Manual sideload also failed: ' . $attachment_id->get_error_message());
                    if ($this->logger) $this->logger->log('Manual sideload also failed: ' . $attachment_id->get_error_message(), 'error');
                    @unlink($tmp_file);
                    return false;
                }
            } else {
                error_log('CCS Debug: No API available for manual download fallback');
                return false;
            }
        }
        
        error_log('CCS Debug: Got attachment ID: ' . $attachment_id);
        
        // Set as featured image
        $result = set_post_thumbnail($post_id, $attachment_id);
        
        if ($result) {
            error_log('CCS Debug: Successfully set post thumbnail');
            if ($this->logger) $this->logger->log('Successfully set featured image (thumbnail ID: ' . $attachment_id . ') for post ID: ' . $post_id);
        } else {
            error_log('CCS Debug: Failed to set post thumbnail');
            if ($this->logger) $this->logger->log('Failed to set post thumbnail', 'error');
        }
        
        return $result;
    }
}
