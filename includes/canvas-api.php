
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
     * Logger instance
     */
    private $logger;
    
    /**
     * Constructor
     */
    public function __construct() {
        // Don't initialize logger in constructor to avoid circular dependency
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
     * Get Canvas credentials
     *
     * @return array|WP_Error Credentials array or error
     */
    private function get_credentials() {
        $domain = get_option('ccs_canvas_domain');
        $token = get_option('ccs_canvas_token');
        
        if (empty($domain) || empty($token)) {
            return new WP_Error('missing_credentials', __('Canvas domain and API token are required.', 'canvas-course-sync'));
        }
        
        return array(
            'domain' => untrailingslashit($domain),
            'token' => $token
        );
    }
    
    /**
     * Make API request
     *
     * @param string $endpoint API endpoint
     * @param array $args Additional request arguments
     * @return array|WP_Error Response data or error
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
                'Content-Type' => 'application/json'
            ),
            'timeout' => 30,
        );
        
        $args = wp_parse_args($args, $default_args);
        
        $logger = $this->get_logger();
        if ($logger) {
            $logger->log('Making API request to: ' . $url);
        }
        
        $response = wp_remote_get($url, $args);
        
        if (is_wp_error($response)) {
            if ($logger) {
                $logger->log('API request failed: ' . $response->get_error_message(), 'error');
            }
            return $response;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        
        if ($response_code !== 200) {
            $error_message = sprintf(__('API returned status code %d', 'canvas-course-sync'), $response_code);
            if (!empty($body)) {
                $decoded_body = json_decode($body, true);
                if (isset($decoded_body['message'])) {
                    $error_message .= ': ' . $decoded_body['message'];
                }
            }
            
            if ($logger) {
                $logger->log('API error: ' . $error_message, 'error');
            }
            
            return new WP_Error('api_error', $error_message);
        }
        
        $data = json_decode($body, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return new WP_Error('invalid_response', __('Invalid JSON response from Canvas API', 'canvas-course-sync'));
        }
        
        return $data;
    }
    
    /**
     * Test Canvas API connection
     *
     * @return bool|WP_Error True on success, WP_Error on failure
     */
    public function test_connection() {
        $result = $this->make_request('users/self');
        
        if (is_wp_error($result)) {
            return $result;
        }
        
        $logger = $this->get_logger();
        if ($logger) {
            $logger->log('Connection test successful');
        }
        
        return true;
    }
    
    /**
     * Get courses from Canvas
     *
     * @return array|WP_Error Array of courses or WP_Error on failure
     */
    public function get_courses() {
        $result = $this->make_request('courses?per_page=100&include[]=term');
        
        if (is_wp_error($result)) {
            return $result;
        }
        
        if (!is_array($result)) {
            return new WP_Error('invalid_response', __('Invalid response from Canvas API', 'canvas-course-sync'));
        }
        
        $logger = $this->get_logger();
        if ($logger) {
            $logger->log('Retrieved ' . count($result) . ' courses from Canvas');
        }
        
        return $result;
    }
    
    /**
     * Get course details from Canvas
     *
     * @param int $course_id Course ID
     * @return array|WP_Error Course data or WP_Error on failure
     */
    public function get_course_details($course_id) {
        $endpoint = 'courses/' . intval($course_id) . '?include[]=term&include[]=syllabus_body';
        $result = $this->make_request($endpoint);
        
        if (is_wp_error($result)) {
            return $result;
        }
        
        if (!is_array($result)) {
            return new WP_Error('invalid_response', __('Invalid response from Canvas API', 'canvas-course-sync'));
        }
        
        return $result;
    }
}
