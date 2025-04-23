
<?php
/**
 * Canvas API handler
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
     * Logger instance
     *
     * @var CCS_Logger
     */
    private $logger;

    /**
     * API domain
     *
     * @var string
     */
    private $api_domain;

    /**
     * API token
     *
     * @var string
     */
    private $api_token;

    /**
     * Constructor
     */
    public function __construct() {
        global $canvas_course_sync;
        $this->logger = $canvas_course_sync->logger ?? new CCS_Logger();
        
        $this->api_domain = get_option('ccs_api_domain', '');
        $this->api_token = get_option('ccs_api_token', '');
    }

    /**
     * Get courses from Canvas
     *
     * @return array|WP_Error
     */
    public function get_courses() {
        $this->logger->log('Fetching courses from Canvas API');
        
        if (empty($this->api_domain) || empty($this->api_token)) {
            $this->logger->log('API domain or token not set', 'error');
            return new WP_Error('api_config', 'API domain or token not configured');
        }
        
        $url = trailingslashit($this->api_domain) . 'api/v1/courses';
        $args = array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->api_token
            ),
            'timeout' => 30
        );
        
        $response = wp_remote_get($url, $args);
        
        if (is_wp_error($response)) {
            $this->logger->log('API request failed: ' . $response->get_error_message(), 'error');
            return $response;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        
        if ($response_code !== 200) {
            $this->logger->log('API returned non-200 status code: ' . $response_code, 'error');
            return new WP_Error('api_error', 'API returned status code: ' . $response_code);
        }
        
        $body = wp_remote_retrieve_body($response);
        $courses = json_decode($body);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->logger->log('Failed to parse JSON response: ' . json_last_error_msg(), 'error');
            return new WP_Error('json_parse', 'Failed to parse JSON response');
        }
        
        $this->logger->log('Successfully retrieved ' . count($courses) . ' courses from Canvas API');
        
        return $courses;
    }

    /**
     * Get course details
     *
     * @param int $course_id Canvas course ID
     * @return object|WP_Error
     */
    public function get_course_details($course_id) {
        $this->logger->log('Fetching details for course ID: ' . $course_id);
        
        if (empty($this->api_domain) || empty($this->api_token)) {
            $this->logger->log('API domain or token not set', 'error');
            return new WP_Error('api_config', 'API domain or token not configured');
        }
        
        $url = trailingslashit($this->api_domain) . 'api/v1/courses/' . $course_id;
        $args = array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->api_token
            ),
            'timeout' => 30
        );
        
        $response = wp_remote_get($url, $args);
        
        if (is_wp_error($response)) {
            $this->logger->log('API request failed: ' . $response->get_error_message(), 'error');
            return $response;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        
        if ($response_code !== 200) {
            $this->logger->log('API returned non-200 status code: ' . $response_code, 'error');
            return new WP_Error('api_error', 'API returned status code: ' . $response_code);
        }
        
        $body = wp_remote_retrieve_body($response);
        $course = json_decode($body);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->logger->log('Failed to parse JSON response: ' . json_last_error_msg(), 'error');
            return new WP_Error('json_parse', 'Failed to parse JSON response');
        }
        
        $this->logger->log('Successfully retrieved details for course: ' . $course->name);
        
        return $course;
    }

    /**
     * Get course files
     *
     * @param int $course_id Canvas course ID
     * @return array|WP_Error
     */
    public function get_course_files($course_id) {
        $this->logger->log('Fetching files for course ID: ' . $course_id);
        
        if (empty($this->api_domain) || empty($this->api_token)) {
            $this->logger->log('API domain or token not set', 'error');
            return new WP_Error('api_config', 'API domain or token not configured');
        }
        
        $url = trailingslashit($this->api_domain) . 'api/v1/courses/' . $course_id . '/files';
        $args = array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->api_token
            ),
            'timeout' => 30
        );
        
        $response = wp_remote_get($url, $args);
        
        if (is_wp_error($response)) {
            $this->logger->log('API request failed: ' . $response->get_error_message(), 'error');
            return $response;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        
        if ($response_code !== 200) {
            $this->logger->log('API returned non-200 status code: ' . $response_code, 'error');
            return new WP_Error('api_error', 'API returned status code: ' . $response_code);
        }
        
        $body = wp_remote_retrieve_body($response);
        $files = json_decode($body);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->logger->log('Failed to parse JSON response: ' . json_last_error_msg(), 'error');
            return new WP_Error('json_parse', 'Failed to parse JSON response');
        }
        
        $this->logger->log('Successfully retrieved ' . count($files) . ' files for course ID: ' . $course_id);
        
        return $files;
    }

    /**
     * Download file from Canvas
     *
     * @param string $url File URL
     * @return string|WP_Error Path to downloaded file
     */
    public function download_file($url) {
        $this->logger->log('Downloading file from: ' . $url);
        
        if (empty($this->api_token)) {
            $this->logger->log('API token not set', 'error');
            return new WP_Error('api_config', 'API token not configured');
        }
        
        $tmp_file = download_url($url . '?access_token=' . $this->api_token);
        
        if (is_wp_error($tmp_file)) {
            $this->logger->log('Failed to download file: ' . $tmp_file->get_error_message(), 'error');
            return $tmp_file;
        }
        
        $this->logger->log('Successfully downloaded file to: ' . $tmp_file);
        
        return $tmp_file;
    }

    /**
     * Test API connection
     *
     * @return boolean
     */
    public function test_connection() {
        $this->logger->log('Testing Canvas API connection');
        
        if (empty($this->api_domain) || empty($this->api_token)) {
            $this->logger->log('API domain or token not set', 'error');
            return false;
        }
        
        $url = trailingslashit($this->api_domain) . 'api/v1/users/self';
        $args = array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->api_token
            ),
            'timeout' => 30
        );
        
        $response = wp_remote_get($url, $args);
        
        if (is_wp_error($response)) {
            $this->logger->log('API connection test failed: ' . $response->get_error_message(), 'error');
            return false;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        
        if ($response_code !== 200) {
            $this->logger->log('API connection test failed with status code: ' . $response_code, 'error');
            return false;
        }
        
        $this->logger->log('API connection test successful');
        
        return true;
    }
}
