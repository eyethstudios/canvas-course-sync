
<?php
/**
 * Canvas API class for Canvas Course Sync
 *
 * @package Canvas_Course_Sync
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Canvas API class
 */
class CCS_Canvas_API {
    
    /**
     * Canvas domain
     */
    private $domain;
    
    /**
     * API token
     */
    private $token;
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->domain = get_option('ccs_canvas_domain');
        $this->token = get_option('ccs_canvas_token');
    }
    
    /**
     * Test connection to Canvas API
     *
     * @return bool|WP_Error True on success, WP_Error on failure
     */
    public function test_connection() {
        if (empty($this->domain) || empty($this->token)) {
            return new WP_Error('missing_credentials', __('Canvas domain and API token are required.', 'canvas-course-sync'));
        }
        
        $url = trailingslashit($this->domain) . 'api/v1/users/self';
        
        $response = wp_remote_get($url, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->token,
            ),
            'timeout' => 30,
        ));
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        if ($status_code !== 200) {
            return new WP_Error('api_error', sprintf(__('API returned status code %d', 'canvas-course-sync'), $status_code));
        }
        
        return true;
    }
    
    /**
     * Get courses from Canvas
     *
     * @return array|WP_Error Array of courses or WP_Error on failure
     */
    public function get_courses() {
        if (empty($this->domain) || empty($this->token)) {
            return new WP_Error('missing_credentials', __('Canvas domain and API token are required.', 'canvas-course-sync'));
        }
        
        $url = trailingslashit($this->domain) . 'api/v1/courses';
        
        $response = wp_remote_get($url, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->token,
            ),
            'timeout' => 30,
        ));
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        if ($status_code !== 200) {
            return new WP_Error('api_error', sprintf(__('API returned status code %d', 'canvas-course-sync'), $status_code));
        }
        
        $body = wp_remote_retrieve_body($response);
        $courses = json_decode($body, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            return new WP_Error('json_error', __('Invalid JSON response from Canvas API', 'canvas-course-sync'));
        }
        
        return $courses;
    }
    
    /**
     * Get course details
     *
     * @param int $course_id Course ID
     * @return object|WP_Error Course details or WP_Error on failure
     */
    public function get_course_details($course_id) {
        if (empty($this->domain) || empty($this->token)) {
            return new WP_Error('missing_credentials', __('Canvas domain and API token are required.', 'canvas-course-sync'));
        }
        
        $url = trailingslashit($this->domain) . 'api/v1/courses/' . intval($course_id);
        
        $response = wp_remote_get($url, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->token,
            ),
            'timeout' => 30,
        ));
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        if ($status_code !== 200) {
            return new WP_Error('api_error', sprintf(__('API returned status code %d', 'canvas-course-sync'), $status_code));
        }
        
        $body = wp_remote_retrieve_body($response);
        $course = json_decode($body);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            return new WP_Error('json_error', __('Invalid JSON response from Canvas API', 'canvas-course-sync'));
        }
        
        return $course;
    }
}
