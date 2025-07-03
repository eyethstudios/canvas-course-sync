
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

        $course_id = $course_details['id'];
        $content = '';

        // Module Description Section (primary content)
        $content .= $this->build_module_description($course_details);

        // Learning Objectives Section (specific learning outcomes)
        $content .= $this->build_learning_objectives($course_id, $course_details);

        // Badge Information Section
        $content .= $this->build_badge_information($course_details);

        // Continuing Education Credit Section
        $content .= $this->build_ce_credit_information($course_details);

        return $content;
    }

    /**
     * Build module description section
     */
    private function build_module_description($course_details) {
        $content = '';
        
        $content .= "<div class='module-description'>\n";
        $content .= "<h2>Module Description</h2>\n";
        
        // Use public description or syllabus as primary description
        $description = '';
        if (!empty($course_details['public_description'])) {
            $description = $course_details['public_description'];
        } elseif (!empty($course_details['syllabus_body'])) {
            $description = $course_details['syllabus_body'];
        } elseif (!empty($course_details['description'])) {
            $description = $course_details['description'];
        }
        
        if ($description) {
            $content .= wp_kses_post($description) . "\n";
        } else {
            // Fallback generic description
            $course_name = $course_details['name'] ?? 'this course';
            $content .= "<p>This module provides comprehensive training and information related to " . esc_html($course_name) . ". Participants will gain practical knowledge and skills that can be applied in professional settings.</p>\n";
        }
        
        $content .= "</div>\n\n";
        return $content;
    }

    /**
     * Build learning objectives section
     */
    private function build_learning_objectives($course_id, $course_details) {
        $content = '';
        
        $content .= "<div class='learning-objectives'>\n";
        $content .= "<h2>Learning Objectives</h2>\n";
        $content .= "<p><strong>Participants will be able to:</strong></p>\n";
        $content .= "<ul>\n";

        // Try to get objectives from Canvas API first
        $objectives_found = false;
        if ($this->api) {
            $outcomes = $this->api->make_request("courses/{$course_id}/outcome_groups");
            
            if (!is_wp_error($outcomes) && !empty($outcomes)) {
                foreach ($outcomes as $outcome_group) {
                    if (!empty($outcome_group['outcomes'])) {
                        foreach ($outcome_group['outcomes'] as $outcome) {
                            if (!empty($outcome['description'])) {
                                $content .= "<li>" . wp_kses_post($outcome['description']) . "</li>\n";
                                $objectives_found = true;
                            }
                        }
                    }
                }
            }
        }

        // Fallback to generic learning objectives if none found
        if (!$objectives_found) {
            $course_name = $course_details['name'] ?? 'the subject matter';
            $content .= "<li>Demonstrate comprehensive knowledge of " . esc_html($course_name) . " and its practical applications</li>\n";
            $content .= "<li>Identify key strategies and best practices related to the course content</li>\n";
            $content .= "<li>Apply learned concepts in professional and practical settings</li>\n";
            $content .= "<li>Evaluate and implement solutions based on course material</li>\n";
        }

        $content .= "</ul>\n</div>\n\n";
        return $content;
    }

    /**
     * Build badge information section
     */
    private function build_badge_information($course_details) {
        $content = '';
        
        $content .= "<div class='badge-information'>\n";
        $content .= "<h2>Badge Information</h2>\n";
        
        // Determine badge category based on course content
        $badge_category = $this->determine_badge_category($course_details);
        
        $content .= "<p><strong>Module content category:</strong> " . esc_html($badge_category) . "</p>\n";
        
        $course_name = $course_details['name'] ?? 'Course';
        $content .= "<div class='badge-display'>\n";
        $content .= "<p><strong>" . esc_html($course_name) . "</strong></p>\n";
        $content .= "<p>Upon successful completion, you will receive a digital badge that validates your achievement in " . esc_html($badge_category) . ".</p>\n";
        $content .= "<p>Learn more about <a href='https://nationaldeafcenter.badgr.com/public/organization/badges' target='_blank'>NDC Badges here</a>.</p>\n";
        $content .= "</div>\n";
        
        $content .= "</div>\n\n";
        return $content;
    }

    /**
     * Build continuing education credit information section
     */
    private function build_ce_credit_information($course_details) {
        $content = '';
        
        $content .= "<div class='continuing-education-credit'>\n";
        $content .= "<h2>Continuing Education Credit</h2>\n";
        
        // Try to extract specific CE hours from course details
        $ce_hours = $this->extract_ce_hours($course_details);
        
        if ($ce_hours) {
            $content .= "<p>This module is pre-approved for <strong>" . esc_html($ce_hours) . " NDC Continuing Professional Education Clock Hours</strong> and <strong>" . esc_html($ce_hours) . " CRCC Clock Hours</strong>.</p>\n";
        } else {
            // Default to 1 hour if no specific hours found
            $content .= "<p>This module is pre-approved for <strong>1 NDC Continuing Professional Education Clock Hour</strong> and <strong>1 CRCC Clock Hour</strong>.</p>\n";
        }
        
        $content .= "<div class='ce-details'>\n";
        $content .= "<h3>Professional Recognition</h3>\n";
        $content .= "<p>This course content has been developed to meet professional standards and may be accepted by:</p>\n";
        $content .= "<ul>\n";
        $content .= "<li>State licensing boards</li>\n";
        $content .= "<li>Professional certification organizations</li>\n";
        $content .= "<li>Employers for professional development requirements</li>\n";
        $content .= "</ul>\n";
        $content .= "<p><em>Note: CE credit acceptance varies by profession and jurisdiction. Please verify requirements with your specific licensing board or organization.</em></p>\n";
        $content .= "</div>\n";
        
        $content .= "</div>\n\n";
        return $content;
    }

    /**
     * Determine badge category based on course content
     */
    private function determine_badge_category($course_details) {
        $course_name = strtolower($course_details['name'] ?? '');
        $description = strtolower($course_details['public_description'] ?? '') . ' ' . strtolower($course_details['syllabus_body'] ?? '');
        
        // Check for accessibility-related keywords
        if (strpos($course_name, 'accessibility') !== false || 
            strpos($course_name, 'assistive') !== false ||
            strpos($description, 'accessibility') !== false ||
            strpos($description, 'assistive technology') !== false) {
            return 'Accessibility Practices';
        }
        
        // Check for rehabilitation keywords
        if (strpos($course_name, 'rehabilitation') !== false || 
            strpos($course_name, 'vocational') !== false ||
            strpos($description, 'rehabilitation') !== false ||
            strpos($description, 'vocational') !== false) {
            return 'Vocational Rehabilitation';
        }
        
        // Check for mentoring keywords
        if (strpos($course_name, 'mentor') !== false || 
            strpos($description, 'mentor') !== false) {
            return 'Professional Development';
        }
        
        // Check for awareness/education keywords
        if (strpos($course_name, 'awareness') !== false || 
            strpos($course_name, 'deaf') !== false ||
            strpos($description, 'deaf awareness') !== false) {
            return 'Deaf Awareness';
        }
        
        // Default category
        return 'Professional Development';
    }

    /**
     * Extract CE hours from course details
     */
    private function extract_ce_hours($course_details) {
        // Look for CE hours in various fields
        $search_fields = array(
            'public_description',
            'description', 
            'syllabus_body',
            'name'
        );

        foreach ($search_fields as $field) {
            if (!empty($course_details[$field])) {
                $text = $course_details[$field];
                
                // Look for patterns like "1.5 CE", "2 hours", "3.0 credits"
                if (preg_match('/(\d+(?:\.\d+)?)\s*(?:CE|credit|hour)s?/i', $text, $matches)) {
                    return $matches[1];
                }
            }
        }

        return null;
    }
}
