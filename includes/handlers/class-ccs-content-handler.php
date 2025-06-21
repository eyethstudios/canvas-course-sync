
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
        
        // Get completion requirements from modules
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

        // Try to get course-specific badge/CE information
        $course_endpoint = "courses/{$course_id}?include[]=public_description&include[]=syllabus_body";
        $course_data = $canvas_course_sync->api->make_request($course_endpoint);
        
        if (!is_wp_error($course_data)) {
            // Look for badge/CE information in course descriptions
            $search_text = '';
            if (!empty($course_data['syllabus_body'])) {
                $search_text .= strtolower($course_data['syllabus_body']);
            }
            if (!empty($course_data['public_description'])) {
                $search_text .= strtolower($course_data['public_description']);
            }
            
            // Check for CE credit information
            if (preg_match('/(\d+(?:\.\d+)?)\s*(?:ce|continuing education|contact)\s*(?:hour|credit|unit)/i', $search_text, $matches)) {
                $requirements['ce_credits'] = $matches[1];
            }
            
            // Check for badge information
            if (strpos($search_text, 'badge') !== false || strpos($search_text, 'certificate') !== false) {
                $requirements['has_badge'] = true;
            }
        }

        return $requirements;
    }

    /**
     * Prepare detailed course content with module descriptions, learning objectives, and specific badge/CE info
     * 
     * @param array $modules Array of modules from Canvas API
     * @param int $course_id Canvas course ID
     * @return string Prepared detailed content
     */
    private function prepare_detailed_content($modules, $course_id) {
        if (empty($modules) || !is_array($modules)) {
            error_log('CCS Debug: No modules provided to prepare_detailed_content');
            return '';
        }

        $content = "";
        error_log('CCS Debug: Processing ' . count($modules) . ' modules for detailed content');
        
        // Get course requirements and badge info
        $course_requirements = $this->get_course_requirements($course_id);
        
        // Process each module for detailed information
        foreach ($modules as $module) {
            if (empty($module['name'])) {
                continue;
            }
            
            $content .= "<h2>" . esc_html($module['name']) . "</h2>\n";
            
            // Add Module Description (prioritized)
            if (!empty($module['description'])) {
                $content .= "<h3>Module Description</h3>\n";
                $content .= "<div class='module-description'>\n";
                $content .= wp_kses_post($module['description']) . "\n";
                $content .= "</div>\n\n";
            }
            
            // Get detailed module information for learning objectives
            if (!empty($module['id'])) {
                $module_details = $this->get_module_details($course_id, $module['id']);
                
                if (!is_wp_error($module_details) && !empty($module_details['items'])) {
                    $learning_objectives = array();
                    $module_content = array();
                    
                    foreach ($module_details['items'] as $item) {
                        if (!empty($item['title'])) {
                            $title_lower = strtolower($item['title']);
                            
                            // Look specifically for learning objectives
                            if (strpos($title_lower, 'objective') !== false || 
                                strpos($title_lower, 'learning') !== false ||
                                strpos($title_lower, 'outcome') !== false ||
                                strpos($title_lower, 'goal') !== false) {
                                $learning_objectives[] = $item['title'];
                            }
                            
                            // Extract content for learning objectives from descriptions
                            if (!empty($item['content_details']['body'])) {
                                $body_text = strip_tags($item['content_details']['body']);
                                // Look for objective-like content
                                if (preg_match_all('/(?:objective|goal|outcome|learn).*?[:\-]\s*(.+?)(?:\n|$)/i', $body_text, $matches)) {
                                    foreach ($matches[1] as $match) {
                                        $learning_objectives[] = trim($match);
                                    }
                                }
                            }
                        }
                    }
                    
                    // Display Learning Objectives (prioritized)
                    if (!empty($learning_objectives)) {
                        $content .= "<h3>Learning Objectives</h3>\n<ul>\n";
                        foreach (array_unique($learning_objectives) as $objective) {
                            $content .= "<li>" . esc_html($objective) . "</li>\n";
                        }
                        $content .= "</ul>\n\n";
                    }
                }
            }
            
            // Add prerequisites if available
            if (!empty($module['prerequisites']) && is_array($module['prerequisites'])) {
                $content .= "<h3>Prerequisites</h3>\n<ul>\n";
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
        
        // Add specific Badge Information
        $content .= "<h2>Badge Information</h2>\n";
        if (!empty($course_requirements['has_badge'])) {
            $content .= "<p>This course awards a digital badge upon successful completion of all modules and assessments. The badge serves as a verified credential demonstrating your mastery of the course content and skills.</p>\n";
            if (!empty($course_requirements['completion_requirements'])) {
                $content .= "<p><strong>Badge Requirements:</strong> Complete all required modules, pass all assessments, and meet participation requirements as outlined in each module.</p>\n";
            }
        } else {
            $content .= "<p>Digital badges are available for this course upon successful completion. Badges provide portable, verifiable credentials that can be shared on social media and professional platforms.</p>\n";
        }
        $content .= "\n";
        
        // Add specific Continuing Education Credit information
        $content .= "<h2>Continuing Education Credits</h2>\n";
        if (!empty($course_requirements['ce_credits'])) {
            $content .= "<p>This course provides <strong>" . esc_html($course_requirements['ce_credits']) . " CE credits</strong> upon successful completion.</p>\n";
            $content .= "<p>CE credits are awarded to participants who complete all required modules, pass assessments, and meet attendance requirements. These credits can be used to maintain professional licenses and certifications.</p>\n";
        } else {
            $content .= "<p>This course offers Continuing Education (CE) credits upon successful completion. CE credits help maintain professional certifications and fulfill ongoing education requirements for various professional licenses.</p>\n";
        }
        $content .= "<p><strong>Note:</strong> Participants should verify CE credit acceptance with their specific licensing board or professional organization before enrollment.</p>\n\n";
        
        error_log('CCS Debug: Generated detailed content length: ' . strlen($content));
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
        
        // Get modules for detailed content
        if (!empty($course_details->id)) {
            $canvas_course_sync = canvas_course_sync();
            if ($canvas_course_sync && isset($canvas_course_sync->api)) {
                error_log('CCS Debug: Attempting to get course modules for detailed content');
                $modules = $canvas_course_sync->api->get_course_modules($course_details->id);
                if (!is_wp_error($modules) && !empty($modules)) {
                    $detailed_content = $this->prepare_detailed_content($modules, $course_details->id);
                    if (!empty($detailed_content)) {
                        $content .= $detailed_content;
                        error_log('CCS Debug: Added detailed content (' . strlen($detailed_content) . ' chars)');
                    }
                } else {
                    error_log('CCS Debug: Failed to get modules or no modules available');
                }
            }
        }
        
        // Add syllabus content if available and no detailed content was generated
        if (empty($content) && !empty($course_details->syllabus_body)) {
            if ($this->logger) $this->logger->log('Adding syllabus content (' . strlen($course_details->syllabus_body) . ' chars)');
            error_log('CCS Debug: Adding syllabus content (' . strlen($course_details->syllabus_body) . ' chars)');
            $content .= "<h2>Course Syllabus</h2>\n" . wp_kses_post($course_details->syllabus_body);
        }
        
        // If no content yet, use public description
        if (empty($content) && !empty($course_details->public_description)) {
            if ($this->logger) $this->logger->log('Using public description (' . strlen($course_details->public_description) . ' chars)');
            error_log('CCS Debug: Using public description (' . strlen($course_details->public_description) . ' chars)');
            $content = wp_kses_post($course_details->public_description);
        }
        
        // Add course description if available and we don't have detailed content
        if (empty($content) && !empty($course_details->description)) {
            if ($this->logger) $this->logger->log('Using course description as main content (' . strlen($course_details->description) . ' chars)');
            error_log('CCS Debug: Using course description as main content (' . strlen($course_details->description) . ' chars)');
            $content = wp_kses_post($course_details->description);
        }
        
        error_log('CCS Debug: Final prepared content length: ' . strlen($content));
        
        return $content;
    }
}
