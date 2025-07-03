
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

// Define API constants
if (!defined('CCS_MAX_API_PAGES')) {
    define('CCS_MAX_API_PAGES', 50); // Increased from 10 to handle more courses
}

if (!defined('CCS_API_TIMEOUT')) {
    define('CCS_API_TIMEOUT', 30);
}

if (!defined('CCS_FILE_DOWNLOAD_TIMEOUT')) {
    define('CCS_FILE_DOWNLOAD_TIMEOUT', 60);
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
     * Constructor with dependency injection
     *
     * @param CCS_Logger|null $logger Logger instance (optional)
     */
    public function __construct(CCS_Logger $logger = null) {
        $this->canvas_domain = get_option('ccs_canvas_domain');
        $this->canvas_token = get_option('ccs_canvas_token');
        $this->logger = $logger;
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
            'timeout' => CCS_API_TIMEOUT
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
        
        // Return both data and headers for pagination
        return array(
            'data' => $decoded,
            'headers' => wp_remote_retrieve_headers($response)
        );
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
    public function get_courses($per_page = 50) {
        $all_courses = array();
        $page = 1;
        $max_pages = CCS_MAX_API_PAGES; // Safety limit
        
        error_log('CCS_Canvas_API: Starting get_courses() with per_page=' . $per_page . ', max_pages=' . $max_pages);
        
        do {
            $endpoint = "courses?enrollment_type=teacher&include[]=syllabus_body&include[]=public_description&include[]=total_students&per_page={$per_page}&page={$page}";
            error_log('CCS_Canvas_API: Requesting page ' . $page . ' with endpoint: ' . $endpoint);
            
            $result = $this->make_request($endpoint);
            
            if (is_wp_error($result)) {
                error_log('CCS_Canvas_API: ERROR on page ' . $page . ': ' . $result->get_error_message());
                return $result;
            }
            
            $courses = $result['data'];
            $headers = $result['headers'];
            
            error_log('CCS_Canvas_API: Page ' . $page . ' returned ' . (is_array($courses) ? count($courses) : 0) . ' courses');
            error_log('CCS_Canvas_API: Response headers: ' . print_r($headers, true));
            
            if (empty($courses) || !is_array($courses)) {
                error_log('CCS_Canvas_API: Breaking - no courses returned on page ' . $page);
                break;
            }
            
            $all_courses = array_merge($all_courses, $courses);
            
            if ($this->logger) {
                $this->logger->log('Retrieved page ' . $page . ' with ' . count($courses) . ' courses (total so far: ' . count($all_courses) . ')');
            }
            
            // Check if there's a next page - improved logic
            $has_next_page = false;
            
            // First, check if we got a full page (indicating there might be more)
            if (count($courses) == $per_page) {
                error_log('CCS_Canvas_API: Got full page (' . $per_page . ' courses), likely more pages available');
                $has_next_page = true;
            }
            
            // Also check Link header if available
            if (isset($headers['link'])) {
                $link_header = $headers['link'];
                if (is_array($link_header)) {
                    $link_header = implode(', ', $link_header);
                }
                $link_has_next = strpos($link_header, 'rel="next"') !== false;
                error_log('CCS_Canvas_API: Link header: ' . $link_header);
                error_log('CCS_Canvas_API: Link header indicates next page: ' . ($link_has_next ? 'YES' : 'NO'));
                
                // Use Link header result if it suggests no more pages
                if (!$link_has_next) {
                    $has_next_page = false;
                    error_log('CCS_Canvas_API: Link header says no more pages, stopping');
                }
            } else {
                error_log('CCS_Canvas_API: No link header found');
            }
            
            $page++;
            error_log('CCS_Canvas_API: Moving to page ' . $page . ', has_next_page=' . ($has_next_page ? 'true' : 'false') . ', within max_pages=' . ($page <= $max_pages ? 'true' : 'false'));
            
        } while ($has_next_page && $page <= $max_pages);
        
        error_log('CCS_Canvas_API: Final result: ' . count($all_courses) . ' total courses retrieved across ' . ($page - 1) . ' pages');
        
        if ($this->logger) {
            $this->logger->log('Retrieved ' . count($all_courses) . ' total courses from Canvas API across ' . ($page - 1) . ' pages');
        }
        
        return $all_courses;
    }
    
    /**
     * Get course details from Canvas
     */
    public function get_course_details($course_id) {
        $endpoint = "courses/{$course_id}?include[]=syllabus_body&include[]=public_description&include[]=course_image";
        
        $result = $this->make_request($endpoint);
        
        if (is_wp_error($result)) {
            return $result;
        }
        
        $course_details = $result['data'];
        
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
        
        $result = $this->make_request($endpoint);
        
        if (is_wp_error($result)) {
            if ($this->logger) {
                $this->logger->log('Failed to get modules for course ' . $course_id . ': ' . $result->get_error_message(), 'warning');
            }
            return $result;
        }
        
        $modules = $result['data'];
        
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
            'timeout' => CCS_FILE_DOWNLOAD_TIMEOUT,
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
