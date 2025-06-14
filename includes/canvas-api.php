
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
        $this->api_key = get_option('ccs_api_token', '');
        $canvas_course_sync = canvas_course_sync();
        $this->logger = ($canvas_course_sync && isset($canvas_course_sync->logger)) ? $canvas_course_sync->logger : null;
        
        // Only validate and clean domain if it's not empty
        if (!empty($this->domain)) {
            $this->domain = $this->clean_domain($this->domain);
        }
        
        // Log initialization only if logger is available
        if ($this->logger) {
            $this->logger->log('Canvas API initialized - Domain: ' . ($this->domain ? 'configured' : 'not configured') . ', Token: ' . ($this->api_key ? 'configured' : 'not configured'));
        }
    }

    /**
     * Clean and validate domain URL
     *
     * @param string $domain Raw domain input
     * @return string Cleaned domain URL
     */
    private function clean_domain($domain) {
        // Remove trailing slashes
        $domain = rtrim($domain, '/');
        
        // Add https:// if no protocol specified
        if (!preg_match('/^https?:\/\//', $domain)) {
            $domain = 'https://' . $domain;
        }
        
        // Validate URL format
        if (!filter_var($domain, FILTER_VALIDATE_URL)) {
            if ($this->logger) {
                $this->logger->log('Invalid domain format: ' . $domain, 'error');
            }
            return '';
        }
        
        return $domain;
    }

    /**
     * Test the API connection
     *
     * @return bool|string True on success, error message on failure
     */
    public function test_connection() {
        // Check if API domain and key are set
        if (empty($this->domain)) {
            $error_msg = 'API domain not configured. Please enter your Canvas domain URL.';
            if ($this->logger) {
                $this->logger->log('API connection test failed: ' . $error_msg, 'error');
            }
            return $error_msg;
        }

        if (empty($this->api_key)) {
            $error_msg = 'API token not configured. Please enter your Canvas API token.';
            if ($this->logger) {
                $this->logger->log('API connection test failed: ' . $error_msg, 'error');
            }
            return $error_msg;
        }

        // Validate domain format
        if (!filter_var($this->domain, FILTER_VALIDATE_URL)) {
            $error_msg = 'Invalid domain format. Please enter a valid URL (e.g., https://canvas.instructure.com)';
            if ($this->logger) {
                $this->logger->log('API connection test failed: ' . $error_msg, 'error');
            }
            return $error_msg;
        }

        $endpoint = 'users/self';
        $params = array();
        
        if ($this->logger) {
            $this->logger->log('Testing API connection to: ' . $this->domain);
        }
        
        $response = $this->api_request($endpoint, $params);
        
        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            if ($this->logger) {
                $this->logger->log('API connection test failed: ' . $error_message, 'error');
            }
            
            // Provide more specific error messages
            if (strpos($error_message, 'HTTP Error: 401') !== false) {
                return 'Authentication failed. Please check your API token.';
            } elseif (strpos($error_message, 'HTTP Error: 404') !== false) {
                return 'Canvas instance not found. Please check your domain URL.';
            } elseif (strpos($error_message, 'HTTP Error: 403') !== false) {
                return 'Access denied. Please check your API token permissions.';
            } elseif (strpos($error_message, 'cURL error') !== false) {
                return 'Connection failed. Please check your domain URL and network connectivity.';
            }
            
            return $error_message;
        }
        
        // Check if response is empty or null
        if (empty($response)) {
            $error_msg = 'Empty response received. Please check your API credentials and domain.';
            if ($this->logger) {
                $this->logger->log('API connection test failed: ' . $error_msg, 'error');
            }
            return $error_msg;
        }
        
        // If we got a valid response object with an ID, it's successful
        if (is_object($response) && isset($response->id)) {
            $success_msg = 'Connection successful! Connected as user: ' . (isset($response->name) ? $response->name : 'User ID ' . $response->id);
            if ($this->logger) {
                $this->logger->log('API connection test successful. ' . $success_msg);
            }
            return true;
        } else {
            $error_msg = 'Invalid response format. Expected user object with ID.';
            if ($this->logger) {
                $this->logger->log('API connection test failed: ' . $error_msg, 'error');
            }
            return $error_msg;
        }
    }

    /**
     * Gets courses from Canvas API
     *
     * @return array|WP_Error Array of courses or WP_Error on failure
     */
    public function get_courses() {
        if ($this->logger) {
            $this->logger->log('Starting get_courses() method');
        }
        
        // Check if API is configured
        if (empty($this->domain) || empty($this->api_key)) {
            $error_msg = 'API not configured properly. Domain: ' . ($this->domain ? 'set' : 'empty') . ', Token: ' . ($this->api_key ? 'set' : 'empty');
            if ($this->logger) {
                $this->logger->log($error_msg, 'error');
            }
            return new WP_Error('api_not_configured', $error_msg);
        }

        $endpoint = 'courses';
        
        // Updated parameters to match Canvas API expectations
        $params = array(
            'include' => array('term', 'course_image', 'total_students'),
            'state' => array('available', 'completed'),
            'per_page' => 100,
            'enrollment_type' => 'teacher',
            'enrollment_state' => 'active'
        );
        
        if ($this->logger) {
            $this->logger->log('Making API request to fetch courses with params: ' . print_r($params, true));
        }
        
        $courses = $this->api_request($endpoint, $params);
        
        if (is_wp_error($courses)) {
            if ($this->logger) {
                $this->logger->log('Error fetching courses: ' . $courses->get_error_message(), 'error');
            }
            return $courses;
        }
        
        // Check if courses is an array
        if (!is_array($courses)) {
            if ($this->logger) {
                $this->logger->log('Courses response is not an array. Type: ' . gettype($courses) . ', Value: ' . print_r($courses, true), 'error');
            }
            return new WP_Error('invalid_response', 'Expected array of courses, got: ' . gettype($courses));
        }
        
        $course_count = count($courses);
        if ($this->logger) {
            $this->logger->log('Successfully fetched ' . $course_count . ' courses from Canvas API');
            
            // Log details of first few courses for debugging
            if ($course_count > 0) {
                $this->logger->log('First course details: ' . print_r($courses[0], true));
                if ($course_count > 1) {
                    $this->logger->log('Second course details: ' . print_r($courses[1], true));
                }
            } else {
                $this->logger->log('No courses returned from API. This could mean:');
                $this->logger->log('1. User has no courses assigned');
                $this->logger->log('2. API token lacks proper permissions');
                $this->logger->log('3. All courses are in non-available state');
            }
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
        
        if ($this->logger) {
            $this->logger->log('Fetching details for course ID: ' . $course_id);
        }
        
        $course = $this->api_request($endpoint, $params);
        
        if (is_wp_error($course)) {
            if ($this->logger) {
                $this->logger->log('Failed to get details for course ' . $course_id . ': ' . $course->get_error_message(), 'error');
            }
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
        
        if ($this->logger) {
            $this->logger->log('Fetching download URL for file ID: ' . $file_id);
        }
        
        $response = wp_remote_get($this->domain . '/api/v1/' . $endpoint, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->api_key
            ),
            'sslverify' => false,
        ));
        
        if (is_wp_error($response)) {
            if ($this->logger) {
                $this->logger->log('Failed to get download URL for file ' . $file_id . ': ' . $response->get_error_message(), 'error');
            }
            return $response;
        }
        
        $redirect_url = wp_remote_retrieve_header($response, 'location');
        
        if (empty($redirect_url)) {
            if ($this->logger) {
                $this->logger->log('No redirect URL found for file ' . $file_id, 'warning');
            }
            return false;
        }
        
        return $redirect_url;
    }

    /**
     * Make a request to the Canvas API
     *
     * @param string $endpoint API endpoint
     * @param array $params Query parameters
     * @return array|WP_Error Response body or WP_Error on failure
     */
    private function api_request($endpoint, $params = array()) {
        // Validate prerequisites
        if (empty($this->domain)) {
            return new WP_Error('missing_domain', 'Canvas domain not configured');
        }
        
        if (empty($this->api_key)) {
            return new WP_Error('missing_token', 'Canvas API token not configured');
        }
        
        // Build URL
        $url = trailingslashit($this->domain) . 'api/v1/' . $endpoint;
        
        if ($this->logger) {
            $this->logger->log('Base API URL: ' . $url);
        }

        // Build query string with proper array handling for Canvas API
        $query_string = '';
        if (!empty($params)) {
            $query_parts = array();
            foreach ($params as $key => $value) {
                if (is_array($value)) {
                    // For Canvas API, array parameters need special formatting
                    foreach ($value as $v) {
                        if (strpos($key, '[]') === false) {
                            $query_parts[] = $key . '[]=' . urlencode($v);
                        } else {
                            $query_parts[] = $key . '=' . urlencode($v);
                        }
                    }
                } else {
                    $query_parts[] = $key . '=' . urlencode($value);
                }
            }
            $query_string = implode('&', $query_parts);
            $url = $url . '?' . $query_string;
        }
        
        if ($this->logger) {
            $this->logger->log('Final API Request URL: ' . $url);
            $this->logger->log('Query string: ' . $query_string);
        }
        
        // Make the request
        $response = wp_remote_get($url, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->api_key,
                'Content-Type' => 'application/json',
                'User-Agent' => 'WordPress-Canvas-Course-Sync/' . CCS_VERSION
            ),
            'sslverify' => true,
            'timeout' => 30,
        ));
        
        // Handle WordPress HTTP errors
        if (is_wp_error($response)) {
            if ($this->logger) {
                $this->logger->log('API request failed (WP Error): ' . $response->get_error_message(), 'error');
            }
            return $response;
        }
        
        // Get response details
        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $headers = wp_remote_retrieve_headers($response);
        
        if ($this->logger) {
            $this->logger->log('API Response Status: ' . $status_code);
            $this->logger->log('API Response Headers: ' . print_r($headers, true));
            $this->logger->log('API Response Body (first 2000 chars): ' . substr($body, 0, 2000));
        }
        
        // Handle HTTP error status codes
        if ($status_code !== 200) {
            $error_msg = 'HTTP Error: ' . $status_code;
            
            // Try to get more details from response body
            if (!empty($body)) {
                $decoded_body = json_decode($body, true);
                if (json_last_error() === JSON_ERROR_NONE && isset($decoded_body['errors'])) {
                    if (is_array($decoded_body['errors'])) {
                        $error_msg .= ' - ' . implode(', ', $decoded_body['errors']);
                    } else {
                        $error_msg .= ' - ' . $decoded_body['errors'];
                    }
                } else {
                    $error_msg .= ' - Response: ' . substr($body, 0, 200);
                }
            }
            
            if ($this->logger) {
                $this->logger->log('API request returned error status: ' . $error_msg, 'error');
            }
            return new WP_Error('api_error', $error_msg);
        }
        
        // Handle empty response
        if (empty($body)) {
            if ($this->logger) {
                $this->logger->log('API request returned empty body', 'error');
            }
            return new WP_Error('empty_response', 'Empty response body received from API');
        }
        
        // Decode JSON response
        $data = json_decode($body);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            $json_error = json_last_error_msg();
            if ($this->logger) {
                $this->logger->log('Failed to decode JSON response: ' . $json_error, 'error');
                $this->logger->log('Raw response body: ' . $body, 'error');
            }
            return new WP_Error('json_decode_failed', 'Failed to decode JSON response: ' . $json_error);
        }
        
        if ($this->logger) {
            $this->logger->log('Successfully decoded JSON response. Type: ' . gettype($data));
            if (is_array($data)) {
                $this->logger->log('Response is array with ' . count($data) . ' elements');
            } elseif (is_object($data)) {
                $this->logger->log('Response is object with properties: ' . implode(', ', array_keys((array)$data)));
            }
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
