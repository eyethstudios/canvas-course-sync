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
     * Approved course titles from National Deaf Center catalog
     */
    private $approved_courses = [
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
        'Legal Frameworks and Responsibilities for Accessibility'
    ];

    /**
     * Logger instance
     */
    private $logger;

    /**
     * Constructor
     */
    public function __construct() {
        $canvas_course_sync = canvas_course_sync();
        if ($canvas_course_sync && isset($canvas_course_sync->logger)) {
            $this->logger = $canvas_course_sync->logger;
        }
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
            if ($this->is_course_approved($course_name)) {
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
     * @return bool True if approved, false otherwise
     */
    private function is_course_approved($course_name) {
        $course_name = trim($course_name);
        
        // Exact match first
        if (in_array($course_name, $this->approved_courses)) {
            return true;
        }

        // Fuzzy matching for slight variations
        foreach ($this->approved_courses as $approved_course) {
            // Check if course name contains the approved course title (case insensitive)
            if (stripos($course_name, $approved_course) !== false || 
                stripos($approved_course, $course_name) !== false) {
                return true;
            }

            // Check similarity (85% match)
            $similarity = 0;
            similar_text(strtolower($course_name), strtolower($approved_course), $similarity);
            if ($similarity >= 85) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get list of approved courses
     * 
     * @return array List of approved course titles
     */
    public function get_approved_courses() {
        return $this->approved_courses;
    }

    /**
     * Add course to approved list
     * 
     * @param string $course_name Course name to approve
     */
    public function add_approved_course($course_name) {
        if (!empty($course_name) && !in_array($course_name, $this->approved_courses)) {
            $this->approved_courses[] = trim($course_name);
            // In production, this should be saved to database or config
            $this->log_info("Added approved course: {$course_name}");
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