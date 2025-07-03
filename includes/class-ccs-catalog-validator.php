<?php
/**
 * Catalog Validator for Canvas Course Sync
 * Validates Canvas courses against approved catalog
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class CCS_Catalog_Validator {
    
    /**
     * Logger instance
     */
    private $logger;

    /**
     * Catalog URL
     */
    private $catalog_url;

    /**
     * Cached course list from catalog
     */
    private $catalog_courses = null;

    /**
     * Constructor
     */
    public function __construct() {
        $canvas_course_sync = canvas_course_sync();
        if ($canvas_course_sync && isset($canvas_course_sync->logger)) {
            $this->logger = $canvas_course_sync->logger;
        }
        
        // Get catalog URL from settings (user-configurable)
        $this->catalog_url = get_option('ccs_catalog_url', CCS_DEFAULT_CATALOG_URL);
        
        if (empty($this->catalog_url)) {
            $this->catalog_url = CCS_DEFAULT_CATALOG_URL;
        }
    }

    /**
     * Fetch course list from catalog URL
     */
    private function fetch_catalog_courses() {
        if ($this->catalog_courses !== null) {
            return $this->catalog_courses;
        }

        $cache_key = 'ccs_catalog_courses_' . md5($this->catalog_url);
        $cached = get_transient($cache_key);
        
        if ($cached !== false) {
            $this->catalog_courses = $cached;
            return $this->catalog_courses;
        }

        // Fetch courses from catalog URL
        $response = wp_remote_get($this->catalog_url, array(
            'timeout' => 30,
            'headers' => array(
                'User-Agent' => 'Canvas Course Sync Plugin',
                'Cache-Control' => 'no-cache, no-store, must-revalidate',
                'Pragma' => 'no-cache'
            )
        ));

        if (is_wp_error($response)) {
            $this->log_info('Failed to fetch catalog from ' . $this->catalog_url . ': ' . $response->get_error_message());
            // Fallback to default catalog
            $this->catalog_courses = $this->get_default_catalog();
            return $this->catalog_courses;
        }

        $body = wp_remote_retrieve_body($response);
        $courses = $this->parse_catalog_html($body);
        
        if (empty($courses)) {
            $this->log_info('No courses found in catalog, using default list');
            $courses = $this->get_default_catalog();
        }

        // Cache for 1 hour
        set_transient($cache_key, $courses, HOUR_IN_SECONDS);
        $this->catalog_courses = $courses;
        
        $this->log_info('Fetched ' . count($courses) . ' courses from user-configured catalog: ' . $this->catalog_url);
        return $this->catalog_courses;
    }
    
    /**
     * Force refresh of catalog courses (clears cache)
     */
    public function force_catalog_refresh() {
        $cache_key = 'ccs_catalog_courses_' . md5($this->catalog_url);
        delete_transient($cache_key);
        $this->catalog_courses = null;
        $this->log_info('Forced catalog refresh - cache cleared for: ' . $this->catalog_url);
    }

    /**
     * Parse HTML content to extract course titles
     */
    private function parse_catalog_html($html) {
        $courses = array();
        
        // Create DOMDocument to parse HTML
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML($html);
        libxml_clear_errors();
        
        $xpath = new DOMXPath($dom);
        
        // Look for course links specifically - the catalog uses [**Course Name**] format
        $link_nodes = $xpath->query('//a[contains(@href, "/courses/")]');
        
        foreach ($link_nodes as $link) {
            $title = trim($link->textContent);
            // Remove asterisks from markdown-style formatting
            $title = trim($title, '*');
            
            if (!empty($title) && strlen($title) > 5) {
                // Skip common navigation links
                if (!in_array(strtolower($title), ['courses', 'course', 'home', 'about', 'contact'])) {
                    $courses[] = $title;
                }
            }
        }
        
        // Fallback: Look for specific course title selectors
        if (empty($courses)) {
            $selectors = array(
                '//h1[contains(@class, "course-title")]',
                '//h2[contains(@class, "course-title")]', 
                '//h3[contains(@class, "course-title")]',
                '//*[contains(@class, "course-name")]',
                '//*[contains(@class, "course-title")]',
                '//h1', '//h2', '//h3' // Final fallback
            );
            
            foreach ($selectors as $selector) {
                $nodes = $xpath->query($selector);
                foreach ($nodes as $node) {
                    $title = trim($node->textContent);
                    if (!empty($title) && strlen($title) > 5) {
                        $courses[] = $title;
                    }
                }
                
                // If we found courses with specific selectors, use those
                if (!empty($courses) && $selector !== '//h1' && $selector !== '//h2' && $selector !== '//h3') {
                    break;
                }
            }
        }
        
        // Remove duplicates and clean up
        $courses = array_unique($courses);
        $courses = array_filter($courses, function($course) {
            return !empty($course) && strlen($course) > 5;
        });
        
        $this->log_info('Parsed ' . count($courses) . ' courses from catalog HTML');
        
        return array_values($courses);
    }

    /**
     * Get default catalog courses (fallback)
     */
    private function get_default_catalog() {
        return [
            // Page 1 courses
            'Assistive Technology in Training and Workplace Settings',
            'Deaf Awareness for Vocational Rehabilitation Professionals',
            'Effective Mentoring for Deaf People',
            'Introduction to Deaf Rehabilitation',
            'Partnering with Deaf Youth: Strength-Based Transition Planning for VR Professionals',
            'Pre-Employment Transition Services (Pre-ETS) and Deaf Youth',
            'Data-Driven Decision Making: What Does it Matter?',
            'Deaf 101',
            'Finding Data About Deaf People',
            'Summer Programs for Deaf Youth: Stories and Strategies',
            'Attitudes as Barriers for Deaf People',
            'Building Relationships with Deaf Communities',
            'Discovering System Barriers and Exploring the WHY',
            'Transforming Systems to Improve Experiences for Deaf People',
            'Legal Frameworks and Responsibilities for Accessibility',
            'Accommodations 101',
            'Coordinating Services for Deaf Students',
            'Captioned Media 101',
            'Introduction to Interpreting Services',
            'Speech-to-Text 101',
            
            // Page 2 courses
            'Assistive Listening Devices and Systems',
            'Introduction to Remote Services',
            'Testing Experiences for Deaf Students',
            'Designing Accessible Online Experiences for Deaf People',
            'Supporting Accessible Learning Environments and Instruction for Deaf Students',
            'Using UDL Principles for Teaching Deaf Students Online',
            'OnDemand Webinar: Commencement for All: Making Graduation Accessible',
            'OnDemand Webinar: What are Assistive Listening Systems?',
            'OnDemand Webinar: Preparing Access Services for Deaf College Students: Tips & Resources',
            'Note Taker Training',
            'Advanced Practices: Evaluating & Managing Services Using Data',
            'Collecting Data from the Community',
            'FAC Improving Campus Access',
            'FAC Planning & Hosting Community Conversations',
            'OnDemand Webinar: Automated Craptioning: Wh@t Dead Dey Say?',
            'OnDemand Webinar Breaking Barriers: Navigating the Grievance Process',
            'OnDemand Webinar: Centralized Systems that Promote #DeafSucess at Colleges',
            'OnDemand Webinar: Deaf People Leading the Way',
            'On Demand Webinar: Does Auto Captioning Effectively Accommodate Deaf People?',
            'OnDemand Webinar Exploring Assistive Technology Options for Deaf Students',
            
            // Page 3 courses
            'OnDemand Webinar: For Deaf People, By Deaf People: Centering Deaf People in Systems Change',
            'OnDemand Webinar: HIPAA and Access',
            'OnDemand Webinar: Mentoring Deaf Youth Leads to #Deaf Success',
            'OnDemand Webinar: Pathways To and Through Health Science Education',
            'OnDemand Webinar Preventing Retraumatization: Establishing Responsive Mental Health Support for Deaf Students',
            'OnDemand Webinar: Re-Framing the Interactive Process to Achieve Effective Communication Access',
            'OnDemand Webinar: Using Data to Further Dialogue for Change',
            'Work-Based Learning Programs'
        ];
    }

    /**
     * Validate and auto-omit courses not in catalog
     * 
     * @param array $canvas_courses Array of Canvas courses
     * @return array Array with validation results
     */
    public function validate_against_catalog($canvas_courses) {
        $results = [
            'validated' => [],
            'omitted' => [],
            'auto_omitted_ids' => []
        ];

        if (empty($canvas_courses) || !is_array($canvas_courses)) {
            return $results;
        }

        // Get catalog courses
        $approved_courses = $this->fetch_catalog_courses();

        // Get current omitted courses
        $omitted_courses = get_option('ccs_omitted_courses', []);
        if (!is_array($omitted_courses)) {
            $omitted_courses = [];
        }

        foreach ($canvas_courses as $course) {
            $course_id = isset($course['id']) ? intval($course['id']) : 0;
            $course_name = isset($course['name']) ? trim($course['name']) : '';

            if (empty($course_name) || $course_id <= 0) {
                continue;
            }

            // Check if course is in approved catalog
            if ($this->is_course_approved($course_name, $approved_courses)) {
                $results['validated'][] = $course;
                $this->log_info("Course validated: {$course_name} (ID: {$course_id})");
            } else {
                // Auto-omit course not in catalog
                if (!in_array($course_id, $omitted_courses)) {
                    $omitted_courses[] = $course_id;
                    $results['auto_omitted_ids'][] = $course_id;
                    $this->log_info("Auto-omitted course not in catalog: {$course_name} (ID: {$course_id})");
                }
                $results['omitted'][] = $course;
            }
        }

        // Update omitted courses option
        if (!empty($results['auto_omitted_ids'])) {
            update_option('ccs_omitted_courses', $omitted_courses);
            $this->log_info("Updated omitted courses list. Total omitted: " . count($omitted_courses));
        }

        return $results;
    }

    /**
     * Check if course title is approved in catalog
     * 
     * @param string $course_name Course name to check
     * @param array $approved_courses List of approved courses
     * @return bool True if approved, false otherwise
     */
    private function is_course_approved($course_name, $approved_courses = null) {
        if ($approved_courses === null) {
            $approved_courses = $this->fetch_catalog_courses();
        }
        
        $course_name = trim($course_name);
        
        // Log the course being validated
        error_log("CCS_Catalog_Validator: Validating course: '{$course_name}'");
        
        // Exact match first
        if (in_array($course_name, $approved_courses)) {
            error_log("CCS_Catalog_Validator: APPROVED - Exact match found for: '{$course_name}'");
            return true;
        }

        // More strict validation - only allow courses that are very similar
        foreach ($approved_courses as $approved_course) {
            // Check similarity (90% match for better precision)
            $similarity = 0;
            similar_text(strtolower($course_name), strtolower($approved_course), $similarity);
            if ($similarity >= 90) {
                error_log("CCS_Catalog_Validator: APPROVED - High similarity ({$similarity}%) between '{$course_name}' and '{$approved_course}'");
                return true;
            }
            
            // Very strict substring matching - only if strings are very similar in length
            $course_len = strlen($course_name);
            $approved_len = strlen($approved_course);
            $min_len = min($course_len, $approved_len);
            $max_len = max($course_len, $approved_len);
            
            // Only allow if length difference is small (within 20%)
            if ($min_len > 15 && ($min_len / $max_len) >= 0.8) {
                if (stripos($course_name, $approved_course) !== false || stripos($approved_course, $course_name) !== false) {
                    error_log("CCS_Catalog_Validator: APPROVED - Strict substring match between '{$course_name}' and '{$approved_course}'");
                    return true;
                }
            }
        }

        error_log("CCS_Catalog_Validator: REJECTED - No match found for: '{$course_name}'");
        return false;
    }

    /**
     * Get list of approved courses
     * 
     * @return array List of approved course titles
     */
    public function get_approved_courses() {
        return $this->fetch_catalog_courses();
    }

    /**
     * Add course to approved list
     * 
     * @param string $course_name Course name to approve
     */
    public function add_approved_course($course_name) {
        if (!empty($course_name)) {
            $approved_courses = $this->fetch_catalog_courses();
            if (!in_array($course_name, $approved_courses)) {
                $approved_courses[] = trim($course_name);
                // Clear cache to force refresh
                $cache_key = 'ccs_catalog_courses_' . md5($this->catalog_url);
                delete_transient($cache_key);
                $this->catalog_courses = $approved_courses;
                $this->log_info("Added approved course: {$course_name}");
            }
        }
    }

    /**
     * Log info message
     */
    private function log_info($message) {
        if ($this->logger) {
            $this->logger->log($message, 'info');
        }
        error_log('CCS Catalog Validator: ' . $message);
    }

    /**
     * Generate validation report
     * 
     * @param array $validation_results Results from validate_against_catalog
     * @return string HTML report
     */
    public function generate_validation_report($validation_results) {
        $html = '<div class="ccs-validation-report">';
        
        $html .= '<h3>Catalog Validation Report</h3>';
        
        $validated_count = count($validation_results['validated']);
        $omitted_count = count($validation_results['omitted']);
        $auto_omitted_count = count($validation_results['auto_omitted_ids']);
        
        $html .= '<div class="validation-summary">';
        $html .= '<p><strong>Validated Courses:</strong> ' . $validated_count . '</p>';
        $html .= '<p><strong>Omitted Courses:</strong> ' . $omitted_count . '</p>';
        if ($auto_omitted_count > 0) {
            $html .= '<p><strong>Auto-Omitted (Not in Catalog):</strong> ' . $auto_omitted_count . '</p>';
        }
        $html .= '</div>';

        if (!empty($validation_results['omitted'])) {
            $html .= '<h4>Courses Not Found in Catalog (Omitted):</h4>';
            $html .= '<ul>';
            foreach ($validation_results['omitted'] as $course) {
                $course_name = esc_html($course['name'] ?? 'Unknown');
                $course_id = $course['id'] ?? 'N/A';
                $html .= "<li>{$course_name} (ID: {$course_id})</li>";
            }
            $html .= '</ul>';
        }

        $html .= '</div>';
        
        return $html;
    }
}