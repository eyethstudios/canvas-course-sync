<?php
/**
 * Handles API communication with Canvas LMS.
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
     * API domain
     *
     * @var string
     */
    private $domain;

    /**
     * API key
     *
     * @var string
     */
    private $api_key;

    /**
     * Logger instance
     *
     * @var CCS_Logger
     */
    private $logger;

    /**
     * Constructor
     */
    public function __construct() {
        $this->domain = get_option('ccs_api_domain', '');
        $this->api_key = get_option('ccs_api_key', '');
        global $canvas_course_sync;
        $this->logger = isset($canvas_course_sync->logger) ? $canvas_course_sync->logger : new CCS_Logger();
    }

    /**
     * Gets courses from Canvas API
     *
     * @return array|WP_Error Array of courses or WP_Error on failure
     */
    public function get_courses() {
        $endpoint = 'courses';
        $params = array(
            'include[]' => 'term',
            'include[]' => 'total_students',
            'include[]' => 'course_image',
            'include[]' => 'created_at',  // Make sure created_at is included
            'state[]' => 'available',
            'per_page' => 100,
        );
        
        $this->logger->log('Fetching courses from Canvas API');
        
        $courses = $this->api_request($endpoint, $params);
        
        if (is_wp_error($courses)) {
            $this->logger->log('Error fetching courses: ' . $courses->get_error_message(), 'error');
            return $courses;
        }
        
        // Log the first course to inspect its structure
        if (!empty($courses) && is_array($courses)) {
            $this->logger->log('First course structure: ' . print_r($courses[0], true));
        } else {
            $this->logger->log('No courses returned from API');
        }
        
        return $courses;
    }

    /**
     * Get course details by ID
     *
     * @param int $course_id Canvas course ID
     * @return object|WP_Error Course details object or WP_Error on failure
     */
    public function get_course_details($course_id) {
        $endpoint = 'courses/' . $course_id;
        $params = array(
            'include[]' => 'term',
            'include[]' => 'course_image'
        );
        
        $this->logger->log('Fetching details for course ID: ' . $course_id);
        
        $course = $this->api_request($endpoint, $params);
        
        if (is_wp_error($course)) {
            $this->logger->log('Failed to get details for course ' . $course_id . ': ' . $course->get_error_message(), 'error');
            return $course;
        }
        
        // If course image is available, get the download URL
        if (isset($course->course_image) && isset($course->course_image->display_name)) {
            $course->image_download_url = $this->get_file_download_url($course->course_image->display_name);
        }
        
        return $course;
    }

    /**
     * Get file download URL
     *
     * @param string $file_id File ID
     * @return string|WP_Error File download URL or WP_Error on failure
     */
    public function get_file_download_url($file_id) {
        $endpoint = 'files/' . $file_id . '/download';
        
        $this->logger->log('Fetching download URL for file ID: ' . $file_id);
        
        $response = wp_remote_get($this->domain . '/api/v1/' . $endpoint, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->api_key
            ),
            'sslverify' => false,
        ));
        
        if (is_wp_error($response)) {
            $this->logger->log('Failed to get download URL for file ' . $file_id . ': ' . $response->get_error_message(), 'error');
            return $response;
        }
        
        $redirect_url = wp_remote_retrieve_header($response, 'location');
        
        if (empty($redirect_url)) {
            $this->logger->log('No redirect URL found for file ' . $file_id, 'warning');
            return false;
        }
        
        return $redirect_url;
    }

    /**
     * Test the API connection
     *
     * @return bool|string True on success, error message on failure
     */
    public function test_connection() {
        $endpoint = 'accounts';
        $params = array(
            'per_page' => 1,
        );
        
        $this->logger->log('Testing API connection');
        
        $response = $this->api_request($endpoint, $params);
        
        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            $this->logger->log('API connection test failed: ' . $error_message, 'error');
            return $error_message;
        }
        
        if (is_array($response) && !empty($response)) {
            $this->logger->log('API connection test successful');
            return true;
        } else {
            $this->logger->log('API connection test failed: Empty response', 'error');
            return 'Empty response from API';
        }
    }

    /**
     * Make a request to the Canvas API
     *
     * @param string $endpoint API endpoint
     * @param array $params Query parameters
     * @return array|WP_Error Response body or WP_Error on failure
     */
    private function api_request($endpoint, $params = array()) {
        $url = trailingslashit($this->domain) . 'api/v1/' . $endpoint;
        
        if (!empty($params)) {
            $url = add_query_arg($params, $url);
        }
        
        $this->logger->log('API Request URL: ' . $url);
        
        $response = wp_remote_get($url, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->api_key
            ),
            'sslverify' => false,
        ));
        
        if (is_wp_error($response)) {
            $this->logger->log('API request failed: ' . $response->get_error_message(), 'error');
            return $response;
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body);
        
        // Log the API response
        $this->logger->log('API Response: ' . print_r($data, true));
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->logger->log('Failed to decode JSON response: ' . json_last_error_msg(), 'error');
            return new WP_Error('json_decode_failed', 'Failed to decode JSON response: ' . json_last_error_msg());
        }
        
        return $data;
    }

    /**
     * Get the API domain
     *
     * @return string API domain
     */
    public function get_domain() {
        return $this->domain;
    }
}
