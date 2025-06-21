
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
     * Prepare course content from Canvas data
     * 
     * @param array|object $course_details Course details from Canvas API
     * @return string Prepared content
     */
    public function prepare_course_content($course_details) {
        error_log('CCS Debug: prepare_course_content called');
        
        if (empty($course_details)) {
            error_log('CCS Debug: No course details provided');
            return '';
        }
        
        // Convert array to object if needed
        if (is_array($course_details)) {
            $course_details = (object)$course_details;
        }
        
        $course_name = isset($course_details->name) ? $course_details->name : 'Unknown Course';
        $course_id = isset($course_details->id) ? $course_details->id : null;
        error_log('CCS Debug: Processing course: ' . $course_name . ' (ID: ' . $course_id . ')');
        
        $content = '';
        
        // Try to get modules and build detailed content
        if (!empty($course_id)) {
            $canvas_course_sync = canvas_course_sync();
            if ($canvas_course_sync && isset($canvas_course_sync->api)) {
                error_log('CCS Debug: Getting modules for course ' . $course_id);
                $modules = $canvas_course_sync->api->get_course_modules($course_id);
                
                if (!is_wp_error($modules) && !empty($modules)) {
                    error_log('CCS Debug: Found ' . count($modules) . ' modules');
                    $content = $this->build_detailed_content($modules, $course_id);
                    
                    if (!empty($content)) {
                        error_log('CCS Debug: Built detailed content (' . strlen($content) . ' chars)');
                        return $content;
                    }
                } else {
                    error_log('CCS Debug: No modules found or error: ' . (is_wp_error($modules) ? $modules->get_error_message() : 'empty'));
                }
            }
        }
        
        // Fallback content
        error_log('CCS Debug: Using fallback content');
        
        if (!empty($course_details->syllabus_body)) {
            $content .= "<h2>Course Syllabus</h2>\n" . wp_kses_post($course_details->syllabus_body) . "\n\n";
        }
        
        if (!empty($course_details->public_description)) {
            $content .= "<h2>Course Description</h2>\n" . wp_kses_post($course_details->public_description) . "\n\n";
        } elseif (!empty($course_details->description)) {
            $content .= "<h2>Course Description</h2>\n" . wp_kses_post($course_details->description) . "\n\n";
        }
        
        // Add basic badge and CE info if no detailed content
        if (!empty($content)) {
            $content .= "<h2>Badge Information</h2>\n";
            $content .= "<p>This course awards a digital badge upon successful completion of all modules and assessments.</p>\n\n";
            
            $content .= "<h2>Continuing Education Credits</h2>\n";
            $content .= "<p>Continuing Education (CE) credits may be available for this course upon successful completion.</p>\n";
            $content .= "<p><strong>Note:</strong> Participants should verify CE credit acceptance with their specific licensing board or professional organization before enrollment.</p>\n\n";
        }
        
        error_log('CCS Debug: Final content length: ' . strlen($content));
        return $content;
    }

    /**
     * Build detailed content from modules
     * 
     * @param array $modules Array of modules
     * @param int $course_id Course ID
     * @return string Built content
     */
    private function build_detailed_content($modules, $course_id) {
        if (empty($modules) || !is_array($modules)) {
            return '';
        }

        $content = '';
        $canvas_course_sync = canvas_course_sync();
        
        foreach ($modules as $module) {
            if (empty($module['name'])) {
                continue;
            }
            
            $content .= "<h2>" . esc_html($module['name']) . "</h2>\n";
            
            // Add module description if available
            if (!empty($module['description'])) {
                $content .= "<h3>Module Description</h3>\n";
                $content .= "<div class='module-description'>\n";
                $content .= wp_kses_post($module['description']) . "\n";
                $content .= "</div>\n\n";
                error_log('CCS Debug: Added description for module: ' . $module['name']);
            }
            
            // Get module items for learning objectives
            if (!empty($module['id']) && $canvas_course_sync && isset($canvas_course_sync->api)) {
                $module_items_endpoint = "courses/{$course_id}/modules/{$module['id']}/items?include[]=content_details";
                $module_items = $canvas_course_sync->api->make_request($module_items_endpoint);
                
                if (!is_wp_error($module_items) && !empty($module_items)) {
                    $learning_objectives = array();
                    
                    foreach ($module_items as $item) {
                        if (!empty($item['title'])) {
                            $title_lower = strtolower($item['title']);
                            if (strpos($title_lower, 'objective') !== false || 
                                strpos($title_lower, 'learning') !== false ||
                                strpos($title_lower, 'outcome') !== false) {
                                $learning_objectives[] = esc_html($item['title']);
                            }
                        }
                    }
                    
                    if (!empty($learning_objectives)) {
                        $content .= "<h3>Learning Objectives</h3>\n<ul>\n";
                        foreach ($learning_objectives as $objective) {
                            $content .= "<li>" . $objective . "</li>\n";
                        }
                        $content .= "</ul>\n\n";
                        error_log('CCS Debug: Added ' . count($learning_objectives) . ' objectives for: ' . $module['name']);
                    }
                }
            }
        }
        
        // Add badge and CE information
        if (!empty($content)) {
            $content .= "<h2>Badge Information</h2>\n";
            $content .= "<p>This course awards a digital badge upon successful completion of all modules and assessments.</p>\n\n";
            
            $content .= "<h2>Continuing Education Credits</h2>\n";
            $content .= "<p>Continuing Education (CE) credits may be available for this course upon successful completion.</p>\n";
            $content .= "<p><strong>Note:</strong> Participants should verify CE credit acceptance with their specific licensing board or professional organization before enrollment.</p>\n\n";
        }
        
        return $content;
    }
}
