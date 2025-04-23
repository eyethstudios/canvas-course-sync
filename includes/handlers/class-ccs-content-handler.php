<?php
/**
 * Handles content preparation for course imports
 *
 * @package Canvas_Course_Sync
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Content Handler class
 */
class CCS_Content_Handler {
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
        global $canvas_course_sync;
        $this->logger = $canvas_course_sync->logger ?? new CCS_Logger();
    }

    /**
     * Prepare modules content
     * 
     * @param array $modules Array of modules from Canvas API
     * @return string Prepared modules content
     */
    private function prepare_modules_content($modules) {
        if (empty($modules)) {
            return '';
        }

        $content = "<h2>Course Modules</h2>\n\n";
        
        foreach ($modules as $module) {
            if (empty($module->name)) {
                continue;
            }
            
            $content .= "<h3>" . esc_html($module->name) . "</h3>\n";
            
            // Add module description if available
            if (!empty($module->description)) {
                $content .= wp_kses_post($module->description) . "\n\n";
            }
            
            // Add learning objectives (prerequisites) if available
            if (!empty($module->prerequisites)) {
                $content .= "<h4>Learning Objectives:</h4>\n<ul>\n";
                foreach ($module->prerequisites as $prereq) {
                    $content .= "<li>" . esc_html($prereq) . "</li>\n";
                }
                $content .= "</ul>\n\n";
            }
            
            // Add items if available
            if (!empty($module->items)) {
                $content .= "<h4>Module Items:</h4>\n<ul>\n";
                foreach ($module->items as $item) {
                    if (!empty($item->title)) {
                        $content .= "<li>" . esc_html($item->title) . "</li>\n";
                    }
                }
                $content .= "</ul>\n\n";
            }
        }
        
        return $content;
    }

    /**
     * Prepare course content from Canvas data
     * 
     * @param object $course_details Course details object from Canvas API
     * @return string Prepared content
     */
    public function prepare_course_content($course_details) {
        global $canvas_course_sync;
        $content = '';
        
        // Get modules for the course
        if (isset($canvas_course_sync->api)) {
            $modules = $canvas_course_sync->api->get_course_modules($course_details->id);
            if (!is_wp_error($modules)) {
                $modules_content = $this->prepare_modules_content($modules);
                if (!empty($modules_content)) {
                    $content .= $modules_content;
                }
            }
        }
        
        // Check for syllabus content
        if (!empty($course_details->syllabus_body)) {
            $this->logger->log('Using syllabus content for course (' . strlen($course_details->syllabus_body) . ' chars)');
            $content .= "<h2>Course Syllabus</h2>\n" . $course_details->syllabus_body;
        } 
        
        // If no syllabus, check for public description
        if (empty($content) && !empty($course_details->public_description)) {
            $this->logger->log('Using public description as fallback (' . strlen($course_details->public_description) . ' chars)');
            $content = $course_details->public_description;
        } 
        
        // If we have a course description, use it as a fallback or addition
        if (!empty($course_details->description)) {
            if (empty($content)) {
                $this->logger->log('Using course description as content (' . strlen($course_details->description) . ' chars)');
                $content = $course_details->description;
            } else {
                $this->logger->log('Appending course description to existing content');
                $content .= "\n\n<h2>Course Description</h2>\n" . $course_details->description;
            }
        }
        
        // Sanitize the content but keep the HTML
        $content = wp_kses_post($content);
        
        return $content;
    }
}
