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
     * Get API token
     *
     * @return string API token
     */
    public function get_token() {
        return $this->api_token;
    }
    
    /**
     * Get API domain
     *
     * @return string API domain
     */
    public function get_domain() {
        return $this->api_domain;
    }

    /**
     * Get courses from Canvas with pagination support
     *
     * @param int $per_page Number of items per page (default: 50)
     * @return array|WP_Error Array of all courses or error
     */
    public function get_courses($per_page = 50) {
        $this->logger->log('Fetching all courses from Canvas API with pagination');
        
        if (empty($this->api_domain) || empty($this->api_token)) {
            $this->logger->log('API domain or token not set', 'error');
            return new WP_Error('api_config', 'API domain or token not configured');
        }
        
        // Initial URL with per_page parameter
        $url = trailingslashit($this->api_domain) . 'api/v1/courses?per_page=' . $per_page;
        
        // Common request arguments
        $args = array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->api_token
            ),
            'timeout' => 45 // Increased timeout for potentially larger responses
        );
        
        $all_courses = array();
        $page_count = 0;
        
        // Get first page
        $this->logger->log('Fetching courses page 1');
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
        
        // Add first page of results
        $all_courses = array_merge($all_courses, $courses);
        $page_count++;
        
        $this->logger->log('Retrieved ' . count($courses) . ' courses from page 1');
        
        // Check for Link header to see if there are more pages
        $links = $this->parse_link_header(wp_remote_retrieve_header($response, 'Link'));
        
        // Continue fetching pages until we've fetched all courses
        while (isset($links['next'])) {
            $page_count++;
            $this->logger->log('Fetching courses page ' . $page_count);
            
            $response = wp_remote_get($links['next'], $args);
            
            if (is_wp_error($response)) {
                $this->logger->log('Failed to fetch page ' . $page_count . ': ' . $response->get_error_message(), 'error');
                continue;
            }
            
            $response_code = wp_remote_retrieve_response_code($response);
            if ($response_code !== 200) {
                $this->logger->log('API returned non-200 status code on page ' . $page_count . ': ' . $response_code, 'warning');
                continue;
            }
            
            $body = wp_remote_retrieve_body($response);
            $courses = json_decode($body);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->logger->log('Failed to parse JSON response for page ' . $page_count . ': ' . json_last_error_msg(), 'warning');
                continue;
            }
            
            // Add this page of results
            $all_courses = array_merge($all_courses, $courses);
            $this->logger->log('Retrieved ' . count($courses) . ' courses from page ' . $page_count);
            
            // Update links for next iteration
            $links = $this->parse_link_header(wp_remote_retrieve_header($response, 'Link'));
            
            // Add a small delay to prevent overwhelming the Canvas API
            usleep(200000); // 200ms delay
        }
        
        $this->logger->log('Successfully retrieved ' . count($all_courses) . ' total courses from ' . $page_count . ' pages');
        
        return $all_courses;
    }

    /**
     * Parse the Link header to extract pagination URLs
     *
     * @param string $header Link header content
     * @return array Associative array of link relations and URLs
     */
    private function parse_link_header($header) {
        $links = array();
        
        if (empty($header)) {
            return $links;
        }
        
        // Split parts by comma
        $parts = explode(',', $header);
        
        foreach ($parts as $part) {
            // Extract URL and rel
            if (preg_match('/<(.+)>;\s*rel="(.+)"/', $part, $match)) {
                $links[$match[2]] = $match[1];
            }
        }
        
        return $links;
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
        
        // Include additional parameters to get more complete course information
        // Explicitly request syllabus_body and course_image
        $url = trailingslashit($this->api_domain) . 'api/v1/courses/' . $course_id . '?include[]=syllabus_body&include[]=term&include[]=course_image&include[]=public_description';
        $args = array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->api_token
            ),
            'timeout' => 60 // Increased timeout for larger responses
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
        $this->logger->log('Course has syllabus: ' . (!empty($course->syllabus_body) ? 'Yes ('.strlen($course->syllabus_body).' chars)' : 'No'));
        $this->logger->log('Course has image: ' . (!empty($course->image_download_url) ? 'Yes' : 'No'));
        
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
        
        $url = trailingslashit($this->api_domain) . 'api/v1/courses/' . $course_id . '/files?sort=created_at&order=desc&per_page=50';
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
        
        // Check if the URL already has parameters
        $url_with_token = $url;
        
        // Only add access token if the URL doesn't already contain it
        if (strpos($url, 'access_token=') === false) {
            if (strpos($url, '?') === false) {
                $url_with_token .= '?access_token=' . $this->api_token;
            } else {
                $url_with_token .= '&access_token=' . $this->api_token;
            }
        }
        
        $this->logger->log('Downloading from URL with token appended');
        
        // Use WordPress HTTP API with increased timeout and no SSL verification
        $response = wp_remote_get($url_with_token, array(
            'timeout' => 120, // Increased timeout for larger files
            'redirection' => 5,
            'sslverify' => false,
            'user-agent' => 'WordPress/Canvas-Course-Sync', // Custom user agent
            'headers' => array(
                'Accept' => '*/*' // Accept any content type
            )
        ));
        
        if (is_wp_error($response)) {
            $this->logger->log('Remote get failed: ' . $response->get_error_message(), 'error');
            return $response;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        if ($response_code !== 200) {
            $this->logger->log('Download returned non-200 status code: ' . $response_code, 'error');
            return new WP_Error('download_error', 'Download returned status code: ' . $response_code);
        }
        
        // Get response headers to check content type
        $headers = wp_remote_retrieve_headers($response);
        $content_type = $headers['content-type'] ?? '';
        
        $this->logger->log('Downloaded content with type: ' . $content_type);
        
        // Create a temporary file
        $tmp_file = wp_tempnam();
        if (!$tmp_file) {
            $this->logger->log('Failed to create temporary file', 'error');
            return new WP_Error('temp_file', 'Failed to create temporary file');
        }
        
        // Write the response body to the temporary file
        $file_content = wp_remote_retrieve_body($response);
        $bytes_written = file_put_contents($tmp_file, $file_content);
        
        if ($bytes_written === false) {
            $this->logger->log('Failed to write to temporary file', 'error');
            @unlink($tmp_file);
            return new WP_Error('file_write', 'Failed to write to temporary file');
        }
        
        $this->logger->log('Successfully downloaded file to: ' . $tmp_file . ' (' . $bytes_written . ' bytes)');
        
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
