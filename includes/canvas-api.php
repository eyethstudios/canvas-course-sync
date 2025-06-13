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
        $this->api_key = get_option('ccs_api_token', ''); // Fixed: was ccs_api_key, should match admin settings
        $canvas_course_sync = canvas_course_sync();
        $this->logger = ($canvas_course_sync && isset($canvas_course_sync->logger)) ? $canvas_course_sync->logger : new CCS_Logger();
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
        // Check if API domain and key are set
        if (empty($this->domain)) {
            $this->logger->log('API connection test failed: API domain not configured', 'error');
            return 'API domain not configured';
        }

        if (empty($this->api_key)) {
            $this->logger->log('API connection test failed: API key not configured', 'error');
            return 'API key not configured';
        }

        $endpoint = 'users/self';  // Changed from 'accounts' to a simpler endpoint
        $params = array();  // No parameters needed for this endpoint
        
        $this->logger->log('Testing API connection to: ' . $this->domain);
        
        $response = $this->api_request($endpoint, $params);
        
        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            $this->logger->log('API connection test failed: ' . $error_message, 'error');
            return $error_message;
        }
        
        // Check if response is empty or null
        if (empty($response)) {
            $this->logger->log('API connection test failed: Empty response received', 'error');
            return 'Empty response received. Please check your API credentials and domain.';
        }
        
        // If we got a valid response object with an ID, it's successful
        if (is_object($response) && isset($response->id)) {
            $this->logger->log('API connection test successful. Connected as user ID: ' . $response->id);
            return true;
        } else {
            $this->logger->log('API connection test failed: Invalid response format', 'error');
            return 'Invalid response format. Got: ' . print_r($response, true);
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
        // Make sure domain ends with a trailing slash
        $domain = trailingslashit($this->domain);
        $url = $domain . 'api/v1/' . $endpoint;
        
        if (!empty($params)) {
            $url = add_query_arg($params, $url);
        }
        
        $this->logger->log('API Request URL: ' . $url);
        
        $response = wp_remote_get($url, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->api_key
            ),
            'sslverify' => false,
            'timeout' => 30,  // Increased timeout to 30 seconds
        ));
        
        if (is_wp_error($response)) {
            $this->logger->log('API request failed: ' . $response->get_error_message(), 'error');
            return $response;
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        if ($status_code !== 200) {
            $this->logger->log('API request returned non-200 status code: ' . $status_code, 'error');
            $error_msg = 'HTTP Error: ' . $status_code;
            $body = wp_remote_retrieve_body($response);
            if (!empty($body)) {
                $error_msg .= ' - Response: ' . $body;
            }
            return new WP_Error('api_error', $error_msg);
        }
        
        $body = wp_remote_retrieve_body($response);
        if (empty($body)) {
            $this->logger->log('API request returned empty body', 'error');
            return new WP_Error('empty_response', 'Empty response body received from API');
        }
        
        $this->logger->log('API Response Body: ' . substr($body, 0, 500) . (strlen($body) > 500 ? '...' : ''));
        
        $data = json_decode($body);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->logger->log('Failed to decode JSON response: ' . json_last_error_msg(), 'error');
            return new WP_Error('json_decode_failed', 'Failed to decode JSON response: ' . json_last_error_msg() . ' - Raw response: ' . substr($body, 0, 255));
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
