
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
        $this->init_dependencies();
    }

    /**
     * Initialize dependencies safely
     */
    private function init_dependencies() {
        $canvas_course_sync = canvas_course_sync();
        
        if ($canvas_course_sync && isset($canvas_course_sync->logger)) {
            $this->logger = $canvas_course_sync->logger;
        } elseif (class_exists('CCS_Logger')) {
            $this->logger = new CCS_Logger();
        }
        
        error_log('CCS Debug: Content handler initialized with logger: ' . ($this->logger ? 'yes' : 'no'));
    }

    /**
     * Get detailed module information from Canvas
     * 
     * @param int $course_id Canvas course ID
     * @param int $module_id Canvas module ID
     * @return array|WP_Error Module details or error
     */
    private function get_module_details($course_id, $module_id) {
        $canvas_course_sync = canvas_course_sync();
        if (!$canvas_course_sync || !isset($canvas_course_sync->api)) {
            return new WP_Error('no_api', 'Canvas API not available');
        }

        // Get detailed module information including items
        $endpoint = "courses/{$course_id}/modules/{$module_id}?include[]=items&include[]=content_details";
        $module_details = $canvas_course_sync->api->make_request($endpoint);
        
        if (is_wp_error($module_details)) {
            error_log('CCS Debug: Failed to get module details for module ' . $module_id . ': ' . $module_details->get_error_message());
            return $module_details;
        }

        return $module_details;
    }

    /**
     * Get course completion requirements and badge information
     * 
     * @param int $course_id Canvas course ID
     * @return array Course requirements and badge info
     */
    private function get_course_requirements($course_id) {
        $canvas_course_sync = canvas_course_sync();
        if (!$canvas_course_sync || !isset($canvas_course_sync->api)) {
            return array();
        }

        $requirements = array();
        
        // Try to get completion requirements
        $completion_endpoint = "courses/{$course_id}/modules?include[]=completion_requirements";
        $completion_data = $canvas_course_sync->api->make_request($completion_endpoint);
        
        if (!is_wp_error($completion_data) && is_array($completion_data)) {
            foreach ($completion_data as $module) {
                if (!empty($module['completion_requirements'])) {
                    $requirements['completion_requirements'] = $module['completion_requirements'];
                    break;
                }
            }
        }

        // Try to get course settings for badge/CE credit info
        $settings_endpoint = "courses/{$course_id}/settings";
        $settings_data = $canvas_course_sync->api->make_request($settings_endpoint);
        
        if (!is_wp_error($settings_data)) {
            if (isset($settings_data['allow_student_forum_attachments'])) {
                $requirements['settings'] = $settings_data;
            }
        }

        return $requirements;
    }

    /**
     * Prepare modules content with detailed information
     * 
     * @param array $modules Array of modules from Canvas API
     * @param int $course_id Canvas course ID
     * @return string Prepared modules content
     */
    private function prepare_modules_content($modules, $course_id) {
        if (empty($modules) || !is_array($modules)) {
            error_log('CCS Debug: No modules provided to prepare_modules_content');
            return '';
        }

        $content = "<h2>Course Modules</h2>\n\n";
        error_log('CCS Debug: Processing ' . count($modules) . ' modules with detailed info');
        
        // Get course requirements and badge info
        $course_requirements = $this->get_course_requirements($course_id);
        
        foreach ($modules as $module) {
            if (empty($module['name'])) {
                continue;
            }
            
            $content .= "<h3>" . esc_html($module['name']) . "</h3>\n";
            
            // Add module description if available
            if (!empty($module['description'])) {
                $content .= "<div class='module-description'>" . wp_kses_post($module['description']) . "</div>\n\n";
            }
            
            // Get detailed module information
            if (!empty($module['id'])) {
                $module_details = $this->get_module_details($course_id, $module['id']);
                
                if (!is_wp_error($module_details)) {
                    // Add learning objectives from module details
                    if (!empty($module_details['items']) && is_array($module_details['items'])) {
                        $learning_objectives = array();
                        $assignments = array();
                        
                        foreach ($module_details['items'] as $item) {
                            // Look for learning objectives in item titles or content
                            if (!empty($item['title'])) {
                                $title_lower = strtolower($item['title']);
                                if (strpos($title_lower, 'objective') !== false || 
                                    strpos($title_lower, 'learning') !== false ||
                                    strpos($title_lower, 'outcome') !== false) {
                                    $learning_objectives[] = $item['title'];
                                } elseif (!empty($item['type']) && $item['type'] === 'Assignment') {
                                    $assignments[] = $item['title'];
                                }
                            }
                        }
                        
                        // Display learning objectives
                        if (!empty($learning_objectives)) {
                            $content .= "<h4>Learning Objectives:</h4>\n<ul>\n";
                            foreach ($learning_objectives as $objective) {
                                $content .= "<li>" . esc_html($objective) . "</li>\n";
                            }
                            $content .= "</ul>\n\n";
                        }
                        
                        // Display assignments as module activities
                        if (!empty($assignments)) {
                            $content .= "<h4>Module Activities:</h4>\n<ul>\n";
                            foreach ($assignments as $assignment) {
                                $content .= "<li>" . esc_html($assignment) . "</li>\n";
                            }
                            $content .= "</ul>\n\n";
                        }
                    }
                }
            }
            
            // Add completion requirements from prerequisites
            if (!empty($module['prerequisites']) && is_array($module['prerequisites'])) {
                $content .= "<h4>Prerequisites:</h4>\n<ul>\n";
                foreach ($module['prerequisites'] as $prereq) {
                    if (is_string($prereq)) {
                        $content .= "<li>" . esc_html($prereq) . "</li>\n";
                    } elseif (is_array($prereq) && isset($prereq['name'])) {
                        $content .= "<li>" . esc_html($prereq['name']) . "</li>\n";
                    }
                }
                $content .= "</ul>\n\n";
            }
        }
        
        // Add badge and CE credit information if available
        if (!empty($course_requirements)) {
            $content .= "<h2>Course Information</h2>\n";
            
            // Add badge information (commonly found in course completion)
            if (!empty($course_requirements['completion_requirements'])) {
                $content .= "<h3>Badge Information</h3>\n";
                $content .= "<p>This course offers digital badges upon completion of all requirements.</p>\n\n";
            }
            
            // Add continuing education credit info
            $content .= "<h3>Continuing Education Credit</h3>\n";
            $content .= "<p>This course may offer continuing education credits. Please check with your institution or professional organization for specific credit requirements and approval.</p>\n\n";
        }
        
        error_log('CCS Debug: Generated detailed modules content length: ' . strlen($content));
        return $content;
    }

    /**
     * Prepare course content from Canvas data
     * 
     * @param object $course_details Course details object from Canvas API
     * @return string Prepared content
     */
    public function prepare_course_content($course_details) {
        error_log('CCS Debug: Content handler prepare_course_content called');
        
        if (empty($course_details)) {
            error_log('CCS Debug: No course details provided');
            return '';
        }
        
        // Convert array to object if needed
        if (is_array($course_details)) {
            $course_details = (object)$course_details;
        }
        
        $course_name = isset($course_details->name) ? $course_details->name : 'Unknown Course';
        error_log('CCS Debug: Preparing content for course: ' . $course_name);
        
        $content = '';
        
        // Get modules for the course if we have an API and course ID
        if (!empty($course_details->id)) {
            $canvas_course_sync = canvas_course_sync();
            if ($canvas_course_sync && isset($canvas_course_sync->api)) {
                error_log('CCS Debug: Attempting to get course modules for course ID: ' . $course_details->id);
                $modules = $canvas_course_sync->api->get_course_modules($course_details->id);
                if (!is_wp_error($modules) && !empty($modules)) {
                    $modules_content = $this->prepare_modules_content($modules, $course_details->id);
                    if (!empty($modules_content)) {
                        $content .= $modules_content;
                        error_log('CCS Debug: Added detailed modules content (' . strlen($modules_content) . ' chars)');
                    }
                } else {
                    if (is_wp_error($modules)) {
                        error_log('CCS Debug: Failed to get modules: ' . $modules->get_error_message());
                    } else {
                        error_log('CCS Debug: No modules returned for course');
                    }
                }
            } else {
                error_log('CCS Debug: API not available for getting modules');
            }
        }
        
        // Check for syllabus content
        if (!empty($course_details->syllabus_body)) {
            if ($this->logger) $this->logger->log('Adding syllabus content (' . strlen($course_details->syllabus_body) . ' chars)');
            error_log('CCS Debug: Adding syllabus content (' . strlen($course_details->syllabus_body) . ' chars)');
            $content .= (!empty($content) ? "\n\n" : "") . "<h2>Course Syllabus</h2>\n" . wp_kses_post($course_details->syllabus_body);
        }
        
        // If no syllabus, check for public description
        if (empty($content) && !empty($course_details->public_description)) {
            if ($this->logger) $this->logger->log('Using public description (' . strlen($course_details->public_description) . ' chars)');
            error_log('CCS Debug: Using public description (' . strlen($course_details->public_description) . ' chars)');
            $content = wp_kses_post($course_details->public_description);
        }
        
        // Add course description if available
        if (!empty($course_details->description)) {
            if (empty($content)) {
                if ($this->logger) $this->logger->log('Using course description as main content (' . strlen($course_details->description) . ' chars)');
                error_log('CCS Debug: Using course description as main content (' . strlen($course_details->description) . ' chars)');
                $content = wp_kses_post($course_details->description);
            } else {
                if ($this->logger) $this->logger->log('Appending course description to existing content');
                error_log('CCS Debug: Appending course description to existing content');
                $content .= "\n\n<h2>Course Description</h2>\n" . wp_kses_post($course_details->description);
            }
        }
        
        error_log('CCS Debug: Final prepared content length: ' . strlen($content));
        
        return $content;
    }
}
