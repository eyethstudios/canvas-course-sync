
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
     * Constructor
     */
    public function __construct() {
        // Initialize API
    }
    
    /**
     * Test Canvas API connection
     *
     * @return bool|WP_Error True on success, WP_Error on failure
     */
    public function test_connection() {
        $domain = get_option('ccs_canvas_domain');
        $token = get_option('ccs_canvas_token');
        
        if (empty($domain) || empty($token)) {
            return new WP_Error('missing_credentials', __('Canvas domain and API token are required.', 'canvas-course-sync'));
        }
        
        $url = untrailingslashit($domain) . '/api/v1/users/self';
        
        $response = wp_remote_get($url, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $token,
            ),
            'timeout' => 30,
        ));
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        if ($response_code !== 200) {
            return new WP_Error('api_error', sprintf(__('API returned status code %d', 'canvas-course-sync'), $response_code));
        }
        
        return true;
    }
    
    /**
     * Get courses from Canvas
     *
     * @return array|WP_Error Array of courses or WP_Error on failure
     */
    public function get_courses() {
        $domain = get_option('ccs_canvas_domain');
        $token = get_option('ccs_canvas_token');
        
        if (empty($domain) || empty($token)) {
            return new WP_Error('missing_credentials', __('Canvas domain and API token are required.', 'canvas-course-sync'));
        }
        
        $url = untrailingslashit($domain) . '/api/v1/courses';
        
        $response = wp_remote_get($url, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $token,
            ),
            'timeout' => 30,
        ));
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        if ($response_code !== 200) {
            return new WP_Error('api_error', sprintf(__('API returned status code %d', 'canvas-course-sync'), $response_code));
        }
        
        $body = wp_remote_retrieve_body($response);
        $courses = json_decode($body);
        
        if (!is_array($courses)) {
            return new WP_Error('invalid_response', __('Invalid response from Canvas API', 'canvas-course-sync'));
        }
        
        return $courses;
    }
    
    /**
     * Get course details from Canvas
     *
     * @param int $course_id Course ID
     * @return object|WP_Error Course object or WP_Error on failure
     */
    public function get_course_details($course_id) {
        $domain = get_option('ccs_canvas_domain');
        $token = get_option('ccs_canvas_token');
        
        if (empty($domain) || empty($token)) {
            return new WP_Error('missing_credentials', __('Canvas domain and API token are required.', 'canvas-course-sync'));
        }
        
        $url = untrailingslashit($domain) . '/api/v1/courses/' . intval($course_id);
        
        $response = wp_remote_get($url, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $token,
            ),
            'timeout' => 30,
        ));
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        if ($response_code !== 200) {
            return new WP_Error('api_error', sprintf(__('API returned status code %d', 'canvas-course-sync'), $response_code));
        }
        
        $body = wp_remote_retrieve_body($response);
        $course = json_decode($body);
        
        if (!is_object($course)) {
            return new WP_Error('invalid_response', __('Invalid response from Canvas API', 'canvas-course-sync'));
        }
        
        return $course;
    }
}
