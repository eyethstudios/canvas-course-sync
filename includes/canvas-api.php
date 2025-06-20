
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
    
    private $logger;
    
    public function __construct() {
        // Logger will be set via get_logger() when needed
    }
    
    /**
     * Get logger instance safely
     */
    private function get_logger() {
        if ($this->logger === null) {
            $canvas_course_sync = canvas_course_sync();
            if ($canvas_course_sync && isset($canvas_course_sync->logger)) {
                $this->logger = $canvas_course_sync->logger;
            }
        }
        return $this->logger;
    }
    
    /**
     * Get Canvas credentials with validation
     */
    private function get_credentials() {
        $domain = get_option('ccs_canvas_domain');
        $token = get_option('ccs_canvas_token');
        
        if (empty($domain) || empty($token)) {
            return new WP_Error('missing_credentials', __('Canvas domain and API token are required.', 'canvas-course-sync'));
        }
        
        // Validate domain format
        $domain = untrailingslashit(trim($domain));
        if (!filter_var($domain, FILTER_VALIDATE_URL)) {
            return new WP_Error('invalid_domain', __('Canvas domain must be a valid URL.', 'canvas-course-sync'));
        }
        
        // Validate token format (basic check)
        $token = trim($token);
        if (strlen($token) < 10) {
            return new WP_Error('invalid_token', __('Canvas API token appears to be invalid.', 'canvas-course-sync'));
        }
        
        return array(
            'domain' => $domain,
            'token' => $token
        );
    }
    
    /**
     * Make API request with improved error handling
     */
    private function make_request($endpoint, $args = array()) {
        $credentials = $this->get_credentials();
        if (is_wp_error($credentials)) {
            return $credentials;
        }
        
        $url = $credentials['domain'] . '/api/v1/' . ltrim($endpoint, '/');
        
        $default_args = array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $credentials['token'],
                'Content-Type' => 'application/json',
                'User-Agent' => 'WordPress-Canvas-Course-Sync/' . (defined('CCS_VERSION') ? CCS_VERSION : '1.0')
            ),
            'timeout' => 30,
            'sslverify' => true
        );
        
        $args = wp_parse_args($args, $default_args);
        
        $logger = $this->get_logger();
        if ($logger) {
            $logger->log('Making API request to: ' . $url);
        }
        
        $response = wp_remote_get($url, $args);
        
        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            if ($logger) {
                $logger->log('API request failed: ' . $error_message, 'error');
            }
            return new WP_Error('api_request_failed', sprintf(__('API request failed: %s', 'canvas-course-sync'), $error_message));
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        
        // Handle different HTTP status codes
        if ($response_code === 401) {
            $error_message = __('Authentication failed. Please check your Canvas API token.', 'canvas-course-sync');
            if ($logger) {
                $logger->log('API authentication failed (401)', 'error');
            }
            return new WP_Error('authentication_failed', $error_message);
        }
        
        if ($response_code === 403) {
            $error_message = __('Access forbidden. Your API token may not have sufficient permissions.', 'canvas-course-sync');
            if ($logger) {
                $logger->log('API access forbidden (403)', 'error');
            }
            return new WP_Error('access_forbidden', $error_message);
        }
        
        if ($response_code === 404) {
            $error_message = __('Canvas API endpoint not found. Please check your Canvas domain.', 'canvas-course-sync');
            if ($logger) {
                $logger->log('API endpoint not found (404)', 'error');
            }
            return new WP_Error('endpoint_not_found', $error_message);
        }
        
        if ($response_code !== 200) {
            $error_message = sprintf(__('API returned status code %d', 'canvas-course-sync'), $response_code);
            if (!empty($body)) {
                $decoded_body = json_decode($body, true);
                if (isset($decoded_body['message'])) {
                    $error_message .= ': ' . $decoded_body['message'];
                } elseif (isset($decoded_body['error'])) {
                    $error_message .= ': ' . $decoded_body['error'];
                }
            }
            
            if ($logger) {
                $logger->log('API error: ' . $error_message, 'error');
            }
            
            return new WP_Error('api_error', $error_message);
        }
        
        if (empty($body)) {
            return new WP_Error('empty_response', __('Empty response from Canvas API', 'canvas-course-sync'));
        }
        
        $data = json_decode($body, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $json_error = json_last_error_msg();
            if ($logger) {
                $logger->log('JSON decode error: ' . $json_error, 'error');
            }
            return new WP_Error('invalid_json', sprintf(__('Invalid JSON response: %s', 'canvas-course-sync'), $json_error));
        }
        
        return $data;
    }
    
    /**
     * Test Canvas API connection
     */
    public function test_connection() {
        $result = $this->make_request('users/self');
        
        if (is_wp_error($result)) {
            return $result;
        }
        
        // Validate that we got user data
        if (!isset($result['id']) || !isset($result['name'])) {
            return new WP_Error('invalid_user_data', __('Invalid user data received from Canvas API', 'canvas-course-sync'));
        }
        
        $logger = $this->get_logger();
        if ($logger) {
            $logger->log('Connection test successful for user: ' . $result['name']);
        }
        
        return true;
    }
    
    /**
     * Get courses from Canvas with better validation
     */
    public function get_courses() {
        $result = $this->make_request('courses?per_page=100&include[]=term&include[]=total_students&enrollment_state=active');
        
        if (is_wp_error($result)) {
            return $result;
        }
        
        if (!is_array($result)) {
            return new WP_Error('invalid_courses_response', __('Invalid courses response from Canvas API', 'canvas-course-sync'));
        }
        
        // Filter out invalid courses
        $valid_courses = array();
        foreach ($result as $course) {
            if (isset($course['id']) && isset($course['name']) && !empty($course['name'])) {
                $valid_courses[] = $course;
            }
        }
        
        $logger = $this->get_logger();
        if ($logger) {
            $logger->log('Retrieved ' . count($valid_courses) . ' valid courses from Canvas (out of ' . count($result) . ' total)');
        }
        
        return $valid_courses;
    }
    
    /**
     * Get course details from Canvas
     */
    public function get_course_details($course_id) {
        if (empty($course_id) || !is_numeric($course_id)) {
            return new WP_Error('invalid_course_id', __('Invalid course ID provided', 'canvas-course-sync'));
        }
        
        $endpoint = 'courses/' . intval($course_id) . '?include[]=term&include[]=syllabus_body&include[]=total_students';
        $result = $this->make_request($endpoint);
        
        if (is_wp_error($result)) {
            return $result;
        }
        
        if (!is_array($result) || !isset($result['id'])) {
            return new WP_Error('invalid_course_details', __('Invalid course details response from Canvas API', 'canvas-course-sync'));
        }
        
        return $result;
    }
}
