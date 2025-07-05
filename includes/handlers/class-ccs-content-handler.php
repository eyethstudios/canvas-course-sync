<?php
/**
 * Content Handler for Canvas Course Sync
 * Builds detailed course content from Canvas API data
 *
 * @package Canvas_Course_Sync
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class CCS_Content_Handler {
    /**
     * Canvas API instance
     */
    private $api;

    /**
     * Logger instance
     */
    private $logger;

    /**
     * Constructor
     */
    public function __construct() {
        $this->init_dependencies();
    }

    /**
     * Initialize dependencies
     */
    private function init_dependencies() {
        $canvas_course_sync = canvas_course_sync();
        
        if ($canvas_course_sync && isset($canvas_course_sync->api)) {
            $this->api = $canvas_course_sync->api;
        }
        
        if ($canvas_course_sync && isset($canvas_course_sync->logger)) {
            $this->logger = $canvas_course_sync->logger;
        }
    }

    /**
     * Prepare detailed course content
     * 
     * @param array $course_details Course details from Canvas API
     * @return string Complete course content HTML
     */
    public function prepare_course_content($course_details) {
        if (empty($course_details) || empty($course_details['id'])) {
            return '';
        }

        $content = '';

        // Build simple course content sections
        // 1. Module Description
        $content .= $this->build_module_description($course_details);
        
        // 2. Learning Objectives
        $content .= $this->build_learning_objectives($course_details);
        
        // 3. Continuing Education Credit
        $content .= $this->build_continuing_education_credit($course_details);

        return $content;
    }

    /**
     * Build module description section
     */
    private function build_module_description($course_details) {
        $content = '';
        
        $content .= "<div class='module-description'>\n";
        $content .= "<h2>Module Description</h2>\n";
        
        // Get description from course details
        $description = '';
        
        // Try different description fields
        if (!empty($course_details['syllabus_body'])) {
            $description = $course_details['syllabus_body'];
        } elseif (!empty($course_details['public_description'])) {
            $description = $course_details['public_description'];
        } elseif (!empty($course_details['description'])) {
            $description = $course_details['description'];
        }
        
        // Add the description content
        if (!empty($description)) {
            $content .= wp_kses_post($description);
        } else {
            $course_name = $course_details['name'] ?? 'this course';
            $content .= "<p>This module provides comprehensive training on " . esc_html($course_name) . ".</p>";
        }
        
        $content .= "</div>\n\n";
        
        return $content;
    }

    /**
     * Build learning objectives section
     */
    private function build_learning_objectives($course_details) {
        $content = '';
        
        $content .= "<div class='learning-objectives'>\n";
        $content .= "<h2>Learning Objectives</h2>\n";
        $content .= "<p><strong>Participants will be able to:</strong></p>\n";
        $content .= "<ul>\n";
        
        // Provide course-specific default objectives
        $course_name = strtolower($course_details['name'] ?? '');
        if (strpos($course_name, 'assistive technology') !== false) {
            $content .= "<li>Demonstrate knowledge of assistive technologies and their relevance to supporting deaf individuals</li>\n";
            $content .= "<li>Identify actionable strategies for implementing assistive technologies in training and workplace settings</li>\n";
            $content .= "<li>Develop strategies for creating accessible environments where deaf individuals feel valued and supported</li>\n";
            $content .= "<li>Create and evaluate policies that promote accessibility and support continuous improvement</li>\n";
        } elseif (strpos($course_name, 'deaf awareness') !== false) {
            $content .= "<li>Understand deaf culture and the deaf community</li>\n";
            $content .= "<li>Recognize the importance of accessibility in vocational rehabilitation</li>\n";
            $content .= "<li>Identify effective communication strategies with deaf individuals</li>\n";
            $content .= "<li>Develop culturally competent practices in service delivery</li>\n";
        } elseif (strpos($course_name, 'mentoring') !== false) {
            $content .= "<li>Understand the principles of effective mentoring for deaf individuals</li>\n";
            $content .= "<li>Identify strategies for building meaningful mentor-mentee relationships</li>\n";
            $content .= "<li>Develop communication techniques appropriate for deaf mentees</li>\n";
            $content .= "<li>Create supportive environments that promote growth and development</li>\n";
        } else {
            $content .= "<li>Understand the key concepts and principles covered in this course</li>\n";
            $content .= "<li>Apply learned skills in practical scenarios</li>\n";
            $content .= "<li>Demonstrate proficiency in the course subject matter</li>\n";
        }
        
        $content .= "</ul>\n";
        $content .= "</div>\n\n";
        
        return $content;
    }

    /**
     * Build continuing education credit section
     */
    private function build_continuing_education_credit($course_details) {
        $content = '';
        
        $content .= "<div class='continuing-education-credit'>\n";
        $content .= "<h2>Continuing Education Credit</h2>\n";
        
        // Default credit information
        $content .= "<p>This course is designed to provide professional development opportunities for individuals working with deaf and hard-of-hearing populations.</p>\n";
        $content .= "<p>Upon successful completion of this course, participants will receive a certificate of completion.</p>\n";
        
        $content .= "</div>\n\n";
        
        return $content;
    }
}