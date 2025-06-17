
<?php
/**
 * Canvas API Handler
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
     *
     * @var string
     */
    private $domain;

    /**
     * API token
     *
     * @var string
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
     * Test API connection
     *
     * @return bool|string True on success, error message on failure
     */
    public function test_connection() {
        if (empty($this->domain) || empty($this->token)) {
            return __('Canvas domain and API token are required', 'canvas-course-sync');
        }

        $url = trailingslashit($this->domain) . 'api/v1/users/self';
        
        $response = wp_remote_get($url, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->token
            ),
            'timeout' => 30
        ));

        if (is_wp_error($response)) {
            return $response->get_error_message();
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            return sprintf(__('API returned status code %d', 'canvas-course-sync'), $code);
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
            return new WP_Error('missing_credentials', __('Canvas domain and API token are required', 'canvas-course-sync'));
        }

        $url = trailingslashit($this->domain) . 'api/v1/courses?per_page=100';
        
        $response = wp_remote_get($url, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->token
            ),
            'timeout' => 30
        ));

        if (is_wp_error($response)) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            return new WP_Error('api_error', sprintf(__('API returned status code %d', 'canvas-course-sync'), $code));
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
            return new WP_Error('missing_credentials', __('Canvas domain and API token are required', 'canvas-course-sync'));
        }

        $url = trailingslashit($this->domain) . 'api/v1/courses/' . $course_id . '?include[]=term&include[]=total_students';
        
        $response = wp_remote_get($url, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->token
            ),
            'timeout' => 30
        ));

        if (is_wp_error($response)) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            return new WP_Error('api_error', sprintf(__('API returned status code %d', 'canvas-course-sync'), $code));
        }

        $body = wp_remote_retrieve_body($response);
        $course = json_decode($body);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return new WP_Error('json_error', __('Invalid JSON response from Canvas API', 'canvas-course-sync'));
        }

        return $course;
    }

    /**
     * Get Canvas domain
     *
     * @return string Canvas domain
     */
    public function get_domain() {
        return $this->domain;
    }
}
