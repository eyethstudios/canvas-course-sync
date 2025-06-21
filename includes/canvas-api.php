
<?php
/**
 * Canvas API Handler for Canvas Course Sync
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
    private $canvas_domain;
    
    /**
     * Canvas API token
     */
    private $canvas_token;
    
    /**
     * Logger instance
     */
    private $logger;
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->canvas_domain = get_option('ccs_canvas_domain');
        $this->canvas_token = get_option('ccs_canvas_token');
        
        // Get logger from main plugin instance
        $canvas_course_sync = canvas_course_sync();
        if ($canvas_course_sync && isset($canvas_course_sync->logger)) {
            $this->logger = $canvas_course_sync->logger;
        }
    }
    
    /**
     * Make API request to Canvas (now public for use by other classes)
     */
    public function make_request($endpoint, $method = 'GET', $data = null) {
        if (empty($this->canvas_domain) || empty($this->canvas_token)) {
            return new WP_Error('missing_credentials', __('Canvas API credentials not configured.', 'canvas-course-sync'));
        }
        
        // Ensure domain has proper format
        $domain = rtrim($this->canvas_domain, '/');
        if (!preg_match('/^https?:\/\//', $domain)) {
            $domain = 'https://' . $domain;
        }
        
        $url = $domain . '/api/v1/' . ltrim($endpoint, '/');
        
        $args = array(
            'method' => $method,
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->canvas_token,
                'Content-Type' => 'application/json'
            ),
            'timeout' => 30
        );
        
        if ($data && $method !== 'GET') {
            $args['body'] = wp_json_encode($data);
        }
        
        if ($this->logger) {
            $this->logger->log('Making Canvas API request to: ' . $url);
        }
        
        $response = wp_remote_request($url, $args);
        
        if (is_wp_error($response)) {
            if ($this->logger) {
                $this->logger->log('Canvas API request failed: ' . $response->get_error_message(), 'error');
            }
            return $response;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        
        if ($response_code >= 400) {
            $error_message = sprintf(__('Canvas API returned error %d: %s', 'canvas-course-sync'), $response_code, $response_body);
            if ($this->logger) {
                $this->logger->log($error_message, 'error');
            }
            return new WP_Error('api_error', $error_message);
        }
        
        $decoded = json_decode($response_body, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            $error_message = __('Invalid JSON response from Canvas API', 'canvas-course-sync');
            if ($this->logger) {
                $this->logger->log($error_message, 'error');
            }
            return new WP_Error('invalid_json', $error_message);
        }
        
        return $decoded;
    }
    
    /**
     * Test connection to Canvas API
     */
    public function test_connection() {
        $result = $this->make_request('courses?per_page=1');
        
        if (is_wp_error($result)) {
            return $result;
        }
        
        if ($this->logger) {
            $this->logger->log('Canvas API connection test successful');
        }
        
        return true;
    }
    
    /**
     * Get courses from Canvas
     */
    public function get_courses($per_page = 100) {
        $all_courses = array();
        $page = 1;
        $max_pages = 10; // Safety limit
        
        do {
            $endpoint = "courses?enrollment_type=teacher&include[]=syllabus_body&include[]=public_description&include[]=total_students&per_page={$per_page}&page={$page}";
            $courses = $this->make_request($endpoint);
            
            if (is_wp_error($courses)) {
                return $courses;
            }
            
            if (empty($courses) || !is_array($courses)) {
                break;
            }
            
            $all_courses = array_merge($all_courses, $courses);
            $page++;
            
        } while (count($courses) == $per_page && $page <= $max_pages);
        
        if ($this->logger) {
            $this->logger->log('Retrieved ' . count($all_courses) . ' courses from Canvas API');
        }
        
        return $all_courses;
    }
    
    /**
     * Get course details from Canvas
     */
    public function get_course_details($course_id) {
        $endpoint = "courses/{$course_id}?include[]=syllabus_body&include[]=public_description&include[]=course_image";
        
        $course_details = $this->make_request($endpoint);
        
        if (is_wp_error($course_details)) {
            return $course_details;
        }
        
        if ($this->logger) {
            $this->logger->log('Retrieved course details for course ID: ' . $course_id);
        }
        
        return $course_details;
    }
    
    /**
     * Get course modules from Canvas
     */
    public function get_course_modules($course_id) {
        $endpoint = "courses/{$course_id}/modules?include[]=items&per_page=100";
        
        $modules = $this->make_request($endpoint);
        
        if (is_wp_error($modules)) {
            if ($this->logger) {
                $this->logger->log('Failed to get modules for course ' . $course_id . ': ' . $modules->get_error_message(), 'warning');
            }
            return $modules;
        }
        
        if ($this->logger) {
            $this->logger->log('Retrieved ' . count($modules) . ' modules for course ID: ' . $course_id);
        }
        
        return $modules;
    }
    
    /**
     * Download file from Canvas
     */
    public function download_file($url) {
        if (empty($url)) {
            return new WP_Error('empty_url', __('No URL provided for file download', 'canvas-course-sync'));
        }
        
        $args = array(
            'timeout' => 60,
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->canvas_token
            )
        );
        
        $response = wp_remote_get($url, $args);
        
        if (is_wp_error($response)) {
            if ($this->logger) {
                $this->logger->log('Failed to download file from: ' . $url . ' - ' . $response->get_error_message(), 'error');
            }
            return $response;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        if ($response_code !== 200) {
            $error_message = sprintf(__('File download failed with status %d', 'canvas-course-sync'), $response_code);
            if ($this->logger) {
                $this->logger->log($error_message, 'error');
            }
            return new WP_Error('download_failed', $error_message);
        }
        
        $file_data = wp_remote_retrieve_body($response);
        
        if (empty($file_data)) {
            $error_message = __('Downloaded file is empty', 'canvas-course-sync');
            if ($this->logger) {
                $this->logger->log($error_message, 'error');
            }
            return new WP_Error('empty_file', $error_message);
        }
        
        if ($this->logger) {
            $this->logger->log('Successfully downloaded file from: ' . $url . ' (' . strlen($file_data) . ' bytes)');
        }
        
        return $file_data;
    }
}
