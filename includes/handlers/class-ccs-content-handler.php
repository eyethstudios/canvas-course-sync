
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

        // Course Overview Section
        $content .= $this->build_course_overview($course_details);

        // Detailed Modules Section
        $content .= $this->build_modules_content($course_id);

        // Learning Outcomes Section
        $content .= $this->build_learning_outcomes($course_id);

        // Assessment and Completion Section
        $content .= $this->build_assessment_section($course_id);

        // Badge and CE Credits Section (course-specific)
        $content .= $this->build_credentials_section($course_details);

        return $content;
    }

    /**
     * Build course overview section
     */
    private function build_course_overview($course_details) {
        $content = '';
        
        if (!empty($course_details['public_description'])) {
            $content .= "<div class='course-overview'>\n";
            $content .= "<h2>Course Overview</h2>\n";
            $content .= wp_kses_post($course_details['public_description']) . "\n";
            $content .= "</div>\n\n";
        }

        if (!empty($course_details['syllabus_body'])) {
            $content .= "<div class='course-syllabus'>\n";
            $content .= "<h2>Course Information</h2>\n";
            $content .= wp_kses_post($course_details['syllabus_body']) . "\n";
            $content .= "</div>\n\n";
        }

        return $content;
    }

    /**
     * Build detailed modules content
     */
    private function build_modules_content($course_id) {
        if (!$this->api) {
            return '';
        }

        $modules = $this->api->get_course_modules($course_id);
        
        if (is_wp_error($modules) || empty($modules)) {
            return '';
        }

        $content = "<div class='course-modules'>\n";
        $content .= "<h2>Course Modules</h2>\n";

        foreach ($modules as $module) {
            if (empty($module['name'])) {
                continue;
            }

            $content .= "<div class='module'>\n";
            $content .= "<h3>" . esc_html($module['name']) . "</h3>\n";

            // Module description
            if (!empty($module['description'])) {
                $content .= "<div class='module-description'>\n";
                $content .= wp_kses_post($module['description']) . "\n";
                $content .= "</div>\n";
            }

            // Get module items for detailed content
            $module_items = $this->get_module_items($course_id, $module['id']);
            if (!empty($module_items)) {
                $content .= $this->build_module_items_content($module_items);
            }

            $content .= "</div>\n\n";
        }

        $content .= "</div>\n\n";
        return $content;
    }

    /**
     * Get module items from Canvas API
     */
    private function get_module_items($course_id, $module_id) {
        if (!$this->api) {
            return array();
        }

        $endpoint = "courses/{$course_id}/modules/{$module_id}/items?include[]=content_details";
        $items = $this->api->make_request($endpoint);

        if (is_wp_error($items)) {
            return array();
        }

        return is_array($items) ? $items : array();
    }

    /**
     * Build module items content
     */
    private function build_module_items_content($items) {
        $content = "<div class='module-items'>\n";
        $content .= "<ul>\n";

        foreach ($items as $item) {
            if (empty($item['title'])) {
                continue;
            }

            $content .= "<li>";
            $content .= "<strong>" . esc_html($item['title']) . "</strong>";

            // Add item type badge
            if (!empty($item['type'])) {
                $type_label = $this->get_item_type_label($item['type']);
                $content .= " <span class='item-type'>[" . $type_label . "]</span>";
            }

            // Add item description if available
            if (!empty($item['content_details']['body'])) {
                $content .= "<div class='item-description'>";
                $content .= wp_kses_post($item['content_details']['body']);
                $content .= "</div>";
            }

            $content .= "</li>\n";
        }

        $content .= "</ul>\n</div>\n";
        return $content;
    }

    /**
     * Get readable item type label
     */
    private function get_item_type_label($type) {
        $types = array(
            'Assignment' => 'Assignment',
            'Quiz' => 'Quiz',
            'Page' => 'Reading',
            'Discussion' => 'Discussion',
            'ExternalUrl' => 'External Link',
            'File' => 'Document',
            'ExternalTool' => 'Interactive Tool'
        );

        return isset($types[$type]) ? $types[$type] : $type;
    }

    /**
     * Build learning outcomes section
     */
    private function build_learning_outcomes($course_id) {
        if (!$this->api) {
            return '';
        }

        // Get course outcomes
        $outcomes = $this->api->make_request("courses/{$course_id}/outcome_groups");
        
        if (is_wp_error($outcomes) || empty($outcomes)) {
            return '';
        }

        $content = "<div class='learning-outcomes'>\n";
        $content .= "<h2>Learning Outcomes</h2>\n";
        $content .= "<p>Upon successful completion of this course, you will be able to:</p>\n";
        $content .= "<ul>\n";

        foreach ($outcomes as $outcome_group) {
            if (!empty($outcome_group['outcomes'])) {
                foreach ($outcome_group['outcomes'] as $outcome) {
                    if (!empty($outcome['description'])) {
                        $content .= "<li>" . wp_kses_post($outcome['description']) . "</li>\n";
                    }
                }
            }
        }

        $content .= "</ul>\n</div>\n\n";
        return $content;
    }

    /**
     * Build assessment section
     */
    private function build_assessment_section($course_id) {
        if (!$this->api) {
            return '';
        }

        // Get assignments and quizzes
        $assignments = $this->api->make_request("courses/{$course_id}/assignments");
        $quizzes = $this->api->make_request("courses/{$course_id}/quizzes");

        $content = "<div class='course-assessment'>\n";
        $content .= "<h2>Assessment & Completion Requirements</h2>\n";

        if (!is_wp_error($assignments) && !empty($assignments)) {
            $content .= "<h3>Assignments</h3>\n<ul>\n";
            foreach ($assignments as $assignment) {
                if (!empty($assignment['name'])) {
                    $content .= "<li>" . esc_html($assignment['name']);
                    if (!empty($assignment['points_possible'])) {
                        $content .= " (" . $assignment['points_possible'] . " points)";
                    }
                    $content .= "</li>\n";
                }
            }
            $content .= "</ul>\n";
        }

        if (!is_wp_error($quizzes) && !empty($quizzes)) {
            $content .= "<h3>Quizzes & Assessments</h3>\n<ul>\n";
            foreach ($quizzes as $quiz) {
                if (!empty($quiz['title'])) {
                    $content .= "<li>" . esc_html($quiz['title']);
                    if (!empty($quiz['points_possible'])) {
                        $content .= " (" . $quiz['points_possible'] . " points)";
                    }
                    $content .= "</li>\n";
                }
            }
            $content .= "</ul>\n";
        }

        $content .= "</div>\n\n";
        return $content;
    }

    /**
     * Build course-specific credentials section
     */
    private function build_credentials_section($course_details) {
        $course_name = $course_details['name'] ?? 'this course';
        
        $content = "<div class='course-credentials'>\n";
        
        // Digital Badge Section
        $content .= "<h2>Digital Badge</h2>\n";
        $content .= "<p>Upon successful completion of <strong>" . esc_html($course_name) . "</strong>, you will receive a digital badge that validates your achievement. This badge can be:</p>\n";
        $content .= "<ul>\n";
        $content .= "<li>Shared on professional networks like LinkedIn</li>\n";
        $content .= "<li>Added to your email signature</li>\n";
        $content .= "<li>Included in your professional portfolio</li>\n";
        $content .= "<li>Used to demonstrate your expertise to employers</li>\n";
        $content .= "</ul>\n\n";

        // CE Credits Section
        $content .= "<h2>Continuing Education Credits</h2>\n";
        
        // Try to extract CE information from course details
        $ce_hours = $this->extract_ce_hours($course_details);
        
        if ($ce_hours) {
            $content .= "<p><strong>" . esc_html($course_name) . "</strong> is approved for <strong>" . $ce_hours . " continuing education credits</strong>.</p>\n";
        } else {
            $content .= "<p><strong>" . esc_html($course_name) . "</strong> may qualify for continuing education credits.</p>\n";
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
                    return $matches[1] . ' hours';
                }
            }
        }

        return null;
    }
}
