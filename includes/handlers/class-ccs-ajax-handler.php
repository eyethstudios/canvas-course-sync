
<?php
/**
 * AJAX Handler for Canvas Course Sync
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class CCS_Ajax_Handler {
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
        $canvas_course_sync = canvas_course_sync();
        $this->logger = ($canvas_course_sync && isset($canvas_course_sync->logger)) ? $canvas_course_sync->logger : new CCS_Logger();
        $this->api = ($canvas_course_sync && isset($canvas_course_sync->api)) ? $canvas_course_sync->api : new CCS_Canvas_API();
        
        // Register AJAX handlers
        add_action('wp_ajax_ccs_get_courses', array($this, 'get_courses'));
    }

    /**
     * Get courses from Canvas with WordPress existence check
     */
    public function get_courses() {
        // Verify nonce
        if (!wp_verify_nonce($_POST['nonce'], 'ccs_get_courses')) {
            wp_die('Security check failed');
        }

        $this->logger->log('AJAX get_courses request received');

        try {
            // Get courses from Canvas
            $canvas_courses = $this->api->get_courses();
            
            if (is_wp_error($canvas_courses)) {
                $this->logger->log('Failed to get courses from Canvas: ' . $canvas_courses->get_error_message(), 'error');
                wp_send_json_error($canvas_courses->get_error_message());
                return;
            }

            $this->logger->log('Canvas courses fetched: ' . count($canvas_courses) . ' courses found');

            // Get existing WordPress courses for comparison
            $existing_wp_courses = get_posts(array(
                'post_type'      => 'courses',
                'post_status'    => array('draft', 'publish', 'private', 'pending'),
                'posts_per_page' => -1,
                'fields'         => 'ids'
            ));
            
            $existing_titles = array();
            $existing_canvas_ids = array();
            
            foreach ($existing_wp_courses as $post_id) {
                $title = get_the_title($post_id);
                $canvas_id = get_post_meta($post_id, 'canvas_course_id', true);
                
                if (!empty($title)) {
                    $existing_titles[] = strtolower(trim($title));
                }
                if (!empty($canvas_id)) {
                    $existing_canvas_ids[] = intval($canvas_id);
                }
            }
            
            $this->logger->log('Found ' . count($existing_wp_courses) . ' existing WordPress courses for comparison');
            $this->logger->log('Existing Canvas IDs: ' . count($existing_canvas_ids) . ', Existing titles: ' . count($existing_titles));
            
            // Add existence check to each course
            foreach ($canvas_courses as &$course) {
                $exists_in_wp = false;
                $match_type = '';
                
                // Check by Canvas ID first (most reliable)
                if (in_array(intval($course->id), $existing_canvas_ids)) {
                    $exists_in_wp = true;
                    $match_type = 'canvas_id';
                    $this->logger->log('Course "' . $course->name . '" exists by Canvas ID: ' . $course->id);
                } else {
                    // Check by title (case-insensitive)
                    $course_title_lower = strtolower(trim($course->name));
                    if (in_array($course_title_lower, $existing_titles)) {
                        $exists_in_wp = true;
                        $match_type = 'title';
                        $this->logger->log('Course "' . $course->name . '" exists by title match');
                    }
                }
                
                // Add the properties to the course object
                $course->exists_in_wp = $exists_in_wp;
                $course->match_type = $match_type;
                
                if (!$exists_in_wp) {
                    $this->logger->log('Course "' . $course->name . '" does not exist in WordPress (ID: ' . $course->id . ')');
                }
            }

            $this->logger->log('Course existence check completed');
            wp_send_json_success($canvas_courses);

        } catch (Exception $e) {
            $this->logger->log('Error in get_courses AJAX handler: ' . $e->getMessage(), 'error');
            wp_send_json_error('An error occurred while fetching courses');
        }
    }
}

// Initialize the AJAX handler
new CCS_Ajax_Handler();
