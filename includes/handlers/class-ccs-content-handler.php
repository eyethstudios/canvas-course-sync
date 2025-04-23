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
     * Prepare course content from Canvas data
     * 
     * @param object $course_details Course details object from Canvas API
     * @return string Prepared content
     */
    public function prepare_course_content($course_details) {
        $content = '';
        
        // Check for syllabus content first (priority)
        if (!empty($course_details->syllabus_body)) {
            $this->logger->log('Using syllabus content for course (' . strlen($course_details->syllabus_body) . ' chars)');
            $content = $course_details->syllabus_body;
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
                // Optionally append description if we already have content
                $this->logger->log('Appending course description to existing content');
                $content .= "\n\n<h3>Course Description</h3>\n" . $course_details->description;
            }
        }
        
        // Make sure we sanitize the content but keep the HTML
        $content = wp_kses_post($content);
        
        return $content;
    }
}
