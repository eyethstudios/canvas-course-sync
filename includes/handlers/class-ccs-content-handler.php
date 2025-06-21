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
                        
                        // Add badge and CE information for courses with module content
                        $content .= $this->get_badge_and_ce_content();
                        
                        return $content;
                    }
                } else {
                    error_log('CCS Debug: No modules found or error: ' . (is_wp_error($modules) ? $modules->get_error_message() : 'empty'));
                }
            }
        }
        
        // Fallback content with course description if available
        error_log('CCS Debug: Using fallback content');
        
        if (!empty($course_details->public_description)) {
            $content .= "<h2>Course Description</h2>\n" . wp_kses_post($course_details->public_description) . "\n\n";
        } elseif (!empty($course_details->description)) {
            $content .= "<h2>Course Description</h2>\n" . wp_kses_post($course_details->description) . "\n\n";
        }
        
        if (!empty($course_details->syllabus_body)) {
            $content .= "<h2>Course Syllabus</h2>\n" . wp_kses_post($course_details->syllabus_body) . "\n\n";
        }
        
        // Add badge and CE info for fallback content too
        $content .= $this->get_badge_and_ce_content();
        
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
                $content .= "<div class='module-description'>\n";
                $content .= wp_kses_post($module['description']) . "\n";
                $content .= "</div>\n\n";
                error_log('CCS Debug: Added module description for: ' . $module['name']);
            }
            
            // Get module items to extract learning objectives and content
            if (!empty($module['id']) && $canvas_course_sync && isset($canvas_course_sync->api)) {
                $module_items_endpoint = "courses/{$course_id}/modules/{$module['id']}/items?include[]=content_details";
                $module_items = $canvas_course_sync->api->make_request($module_items_endpoint);
                
                if (!is_wp_error($module_items) && !empty($module_items)) {
                    $learning_objectives = $this->extract_learning_objectives($module_items, $module['name']);
                    if (!empty($learning_objectives)) {
                        $content .= $learning_objectives;
                    }
                }
            }
        }
        
        return $content;
    }

    /**
     * Extract learning objectives from module items
     * 
     * @param array $module_items Array of module items
     * @param string $module_name Module name for logging
     * @return string Learning objectives content
     */
    private function extract_learning_objectives($module_items, $module_name) {
        $objectives_content = '';
        $found_objectives = array();
        
        foreach ($module_items as $item) {
            if (empty($item['title'])) {
                continue;
            }
            
            $item_title = $item['title'];
            
            // Look for learning objectives in various forms
            if (preg_match('/(?:learning\s+)?objectives?|outcomes?|goals?/i', $item_title)) {
                
                // Try to get the actual content of the objective item
                if (!empty($item['page_url']) && isset($item['type']) && $item['type'] === 'Page') {
                    // This is a page with objectives - we could fetch its content
                    $objective_text = $this->clean_objective_text($item_title);
                    $found_objectives[] = $objective_text;
                } else {
                    // Just use the title
                    $objective_text = $this->clean_objective_text($item_title);
                    $found_objectives[] = $objective_text;
                }
                
                error_log('CCS Debug: Found learning objective: ' . $objective_text);
                continue;
            }
            
            // Also look for items that might contain objectives in their content
            if (preg_match('/^(by the end|upon completion|after completing|students will)/i', $item_title)) {
                $found_objectives[] = esc_html($item_title);
            }
        }
        
        // Build learning objectives section
        if (!empty($found_objectives)) {
            $objectives_content .= "<h3>Learning Objectives</h3>\n<ul>\n";
            foreach (array_unique($found_objectives) as $objective) {
                $objectives_content .= "<li>" . $objective . "</li>\n";
            }
            $objectives_content .= "</ul>\n\n";
            error_log('CCS Debug: Added ' . count($found_objectives) . ' learning objectives for: ' . $module_name);
        }
        
        return $objectives_content;
    }

    /**
     * Clean and format objective text
     * 
     * @param string $text Raw objective text
     * @return string Cleaned objective text
     */
    private function clean_objective_text($text) {
        // Remove common prefixes
        $text = preg_replace('/^(learning\s+)?objectives?:?\s*/i', '', $text);
        $text = preg_replace('/^(learning\s+)?outcomes?:?\s*/i', '', $text);
        $text = preg_replace('/^(learning\s+)?goals?:?\s*/i', '', $text);
        
        // Clean up formatting
        $text = trim($text);
        $text = ucfirst($text);
        
        // Ensure it ends with proper punctuation
        if (!preg_match('/[.!?]$/', $text)) {
            $text .= '.';
        }
        
        return esc_html($text);
    }
    
    /**
     * Get badge and continuing education content
     * 
     * @return string Badge and CE content
     */
    private function get_badge_and_ce_content() {
        $content = "<h2>Digital Badge</h2>\n";
        $content .= "<p>Upon successful completion of all course modules and assessments, participants will receive a digital badge that can be shared on professional networks and social media platforms.</p>\n\n";
        
        $content .= "<h2>Continuing Education Credits</h2>\n";
        $content .= "<p>This course may qualify for Continuing Education (CE) credits. The number of credits and acceptance varies by profession and licensing board.</p>\n";
        $content .= "<p><strong>Important:</strong> Please verify CE credit acceptance with your specific licensing board or professional organization before enrollment, as requirements vary by state and profession.</p>\n\n";
        
        return $content;
    }
}
