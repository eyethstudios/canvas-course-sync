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
            error_log('CCS_Content_Handler: Missing course details or ID');
            return '';
        }

        $course_id = $course_details['id'];
        $course_name = $course_details['name'] ?? 'Unknown Course';
        error_log('CCS_Content_Handler: Preparing content for course: ' . $course_name . ' (ID: ' . $course_id . ')');
        
        $content = '';

        // Get additional course data for comprehensive content
        $modules = array();
        $pages = array();
        
        if ($this->api) {
            error_log('CCS_Content_Handler: Fetching modules and pages for course ' . $course_id);
            
            // Get course modules
            $modules_result = $this->api->get_course_modules($course_id);
            if (!is_wp_error($modules_result)) {
                $modules = $modules_result;
                error_log('CCS_Content_Handler: Retrieved ' . count($modules) . ' modules');
            } else {
                error_log('CCS_Content_Handler: Failed to get modules: ' . $modules_result->get_error_message());
            }
            
            // Get course pages for additional content
            $pages_result = $this->api->get_course_pages($course_id);
            if (!is_wp_error($pages_result)) {
                $pages = $pages_result;
                error_log('CCS_Content_Handler: Retrieved ' . count($pages) . ' pages');
                
                // Log page titles for debugging
                foreach ($pages as $page) {
                    error_log('CCS_Content_Handler: Found page: ' . ($page['title'] ?? 'Untitled'));
                }
            } else {
                error_log('CCS_Content_Handler: Failed to get pages: ' . $pages_result->get_error_message());
            }
        } else {
            error_log('CCS_Content_Handler: API instance not available');
        }

        // Build the four required sections to match catalog format
        // 1. Module Description
        $content .= $this->build_module_description($course_details, $modules, $pages);
        
        // 2. Learning Objectives
        $content .= $this->build_learning_objectives($course_id, $course_details, $modules, $pages);
        
        // 3. Badge Information
        $content .= $this->build_badge_information($course_details);
        
        // 4. Continuing Education Credit
        $content .= $this->build_continuing_education_credit($course_details);

        error_log('CCS_Content_Handler: Generated content length: ' . strlen($content));
        
        return $content;
    }

    /**
     * Build module description section
     */
    private function build_module_description($course_details, $modules = array(), $pages = array()) {
        $content = '';
        
        $content .= "<div class='module-description'>\n";
        $content .= "<h2>Module Description</h2>\n";
        
        // First try to get description from Canvas course fields
        $description = '';
        
        // Priority 1: syllabus_body
        if (!empty($course_details['syllabus_body'])) {
            $description = $course_details['syllabus_body'];
            error_log('CCS_Content_Handler: Using syllabus_body for module description');
        }
        // Priority 2: public_description
        elseif (!empty($course_details['public_description'])) {
            $description = $course_details['public_description'];
            error_log('CCS_Content_Handler: Using public_description for module description');
        }
        // Priority 3: regular description
        elseif (!empty($course_details['description'])) {
            $description = $course_details['description'];
            error_log('CCS_Content_Handler: Using description field for module description');
        }
        
        // If no description from course fields, search pages
        if (empty($description) && !empty($pages) && $this->api) {
            error_log('CCS_Content_Handler: Searching pages for course description...');
            
            // Look for course overview/description pages
            $description_keywords = array(
                'course description',
                'module description', 
                'course overview',
                'about this course',
                'introduction',
                'overview',
                'about'
            );
            
            foreach ($description_keywords as $keyword) {
                foreach ($pages as $page) {
                    $page_title = strtolower($page['title'] ?? '');
                    if (strpos($page_title, $keyword) !== false) {
                        $page_content = $this->api->get_page_content($course_details['id'], $page['url']);
                        if (!is_wp_error($page_content) && !empty($page_content['body'])) {
                            $body = $page_content['body'];
                            
                            // Skip badge/completion pages
                            $clean_text = strtolower(strip_tags($body));
                            if (stripos($clean_text, 'badge') === false && 
                                stripos($clean_text, 'completion') === false &&
                                strlen($clean_text) > 100) {
                                
                                $description = $body;
                                error_log('CCS_Content_Handler: Found description from page: ' . $page['title']);
                                break 2;
                            }
                        }
                    }
                }
            }
        }
        
        // Add the description content
        if (!empty($description)) {
            $content .= wp_kses_post($description);
        } else {
            // If still no description, provide a minimal placeholder
            $content .= "<p>This course provides comprehensive training on " . esc_html($course_details['name'] ?? 'the subject matter') . ".</p>";
        }
        
        $content .= "</div>\n\n";
        
        return $content;
    }

    /**
     * Build learning objectives section
     */
    private function build_learning_objectives($course_id, $course_details, $modules = array(), $pages = array()) {
        $content = '';
        
        $content .= "<div class='learning-objectives'>\n";
        $content .= "<h2>Learning Objectives</h2>\n";
        $content .= "<p><strong>Participants will be able to:</strong></p>\n";
        $content .= "<ul>\n";
        
        $objectives = array();
        
        // Search pages for learning objectives
        if (!empty($pages) && $this->api) {
            error_log('CCS_Content_Handler: Searching pages for learning objectives...');
            
            foreach ($pages as $page) {
                $page_title = strtolower($page['title'] ?? '');
                
                // Check if this page might contain objectives
                if (strpos($page_title, 'objective') !== false || 
                    strpos($page_title, 'outcome') !== false ||
                    strpos($page_title, 'goal') !== false ||
                    strpos($page_title, 'learn') !== false) {
                    
                    $page_content = $this->api->get_page_content($course_id, $page['url']);
                    if (!is_wp_error($page_content) && !empty($page_content['body'])) {
                        $objectives = $this->extract_objectives_from_content($page_content['body']);
                        if (!empty($objectives)) {
                            error_log('CCS_Content_Handler: Found ' . count($objectives) . ' objectives from page: ' . $page['title']);
                            break;
                        }
                    }
                }
            }
        }
        
        // If no objectives found in dedicated pages, search in course description
        if (empty($objectives)) {
            $search_fields = array('syllabus_body', 'public_description', 'description');
            foreach ($search_fields as $field) {
                if (!empty($course_details[$field])) {
                    $objectives = $this->extract_objectives_from_content($course_details[$field]);
                    if (!empty($objectives)) {
                        error_log('CCS_Content_Handler: Found objectives in course ' . $field);
                        break;
                    }
                }
            }
        }
        
        // If still no objectives, check module items
        if (empty($objectives) && !empty($modules)) {
            foreach ($modules as $module) {
                if (!empty($module['items'])) {
                    foreach ($module['items'] as $item) {
                        // Look for items that might be objectives
                        if ($item['type'] === 'Page') {
                            $item_title = strtolower($item['title'] ?? '');
                            if (strpos($item_title, 'objective') !== false ||
                                strpos($item_title, 'outcome') !== false) {
                                
                                // Get the page content
                                if ($this->api && !empty($item['url'])) {
                                    $page_content = $this->api->get_page_content($course_id, $item['url']);
                                    if (!is_wp_error($page_content) && !empty($page_content['body'])) {
                                        $objectives = $this->extract_objectives_from_content($page_content['body']);
                                        if (!empty($objectives)) {
                                            break 2;
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
        
        // Add objectives to content
        if (!empty($objectives)) {
            foreach ($objectives as $objective) {
                $content .= "<li>" . esc_html($objective) . "</li>\n";
            }
        } else {
            // Default objectives based on course type
            $content .= "<li>Understand the key concepts and principles covered in this course</li>\n";
            $content .= "<li>Apply learned skills in practical scenarios</li>\n";
            $content .= "<li>Demonstrate proficiency in the course subject matter</li>\n";
        }
        
        $content .= "</ul>\n";
        $content .= "</div>\n\n";
        
        return $content;
    }

    /**
     * Extract objectives from HTML content
     */
    private function extract_objectives_from_content($html_content) {
        $objectives = array();
        
        // First try to extract list items
        if (preg_match_all('/<li[^>]*>(.*?)<\/li>/is', $html_content, $matches)) {
            foreach ($matches[1] as $match) {
                $clean_text = trim(wp_strip_all_tags($match));
                
                // Check if this looks like an objective (not too short, not badge-related)
                if (strlen($clean_text) > 20 && 
                    strlen($clean_text) < 300 &&
                    stripos($clean_text, 'badge') === false &&
                    stripos($clean_text, 'completion') === false &&
                    stripos($clean_text, 'certificate') === false) {
                    
                    $objectives[] = $clean_text;
                }
            }
        }
        
        // If no list items, look for objective patterns in text
        if (empty($objectives)) {
            $patterns = array(
                '/(?:Participants will be able to:|Students will be able to:|Learners will:|Upon completion.*?will)\s*([^\.]+\.)/i',
                '/(?:Objective \d+:|Learning Objective:)\s*([^\.]+\.)/i',
                '/(?:•|\*|-)\s*([A-Z][^\.]{20,200}\.)/m'
            );
            
            foreach ($patterns as $pattern) {
                if (preg_match_all($pattern, $html_content, $matches)) {
                    foreach ($matches[1] as $match) {
                        $clean_text = trim(wp_strip_all_tags($match));
                        if (!empty($clean_text)) {
                            $objectives[] = $clean_text;
                        }
                    }
                }
                
                if (!empty($objectives)) {
                    break;
                }
            }
        }
        
        // Return unique objectives
        return array_unique($objectives);
    }

    /**
     * Build badge information section
     */
    private function build_badge_information($course_details) {
        $content = '';
        
        $content .= "<div class='badge-information'>\n";
        $content .= "<h2>Badge Information</h2>\n";
        
        // Determine badge category based on course name and content
        $badge_category = $this->determine_badge_category($course_details);
        
        $content .= "<p><strong>Module content category:</strong> " . esc_html($badge_category) . "</p>\n";
        
        // Create badge display section similar to catalog
        $course_name = $course_details['name'] ?? 'Course';
        $content .= "<div class='badge-display' style='margin-top: 20px; padding: 15px; background-color: #f5f5f5; border-radius: 5px;'>\n";
        $content .= "<p style='text-align: center; margin-bottom: 10px;'><img src='/wp-content/plugins/canvas-course-sync/assets/images/badge-placeholder.png' alt='Course Badge' style='max-width: 150px; height: auto;' onerror='this.style.display=\"none\"'></p>\n";
        $content .= "<p style='text-align: center; font-weight: bold;'>" . esc_html($course_name) . "</p>\n";
        $content .= "<p style='text-align: center;'>Issued by: National Deaf Center</p>\n";
        $content .= "</div>\n";
        
        $content .= "</div>\n\n";
        
        return $content;
    }

    /**
     * Determine badge category based on course content
     */
    private function determine_badge_category($course_details) {
        $course_name = strtolower($course_details['name'] ?? '');
        $description = strtolower($course_details['public_description'] ?? '') . ' ' . 
                      strtolower($course_details['syllabus_body'] ?? '') . ' ' .
                      strtolower($course_details['description'] ?? '');
        
        // Check for specific category matches based on actual catalog courses
        
        // Assistive Technology category
        if (strpos($course_name, 'assistive technology') !== false || 
            strpos($course_name, 'assistive listening') !== false ||
            strpos($description, 'assistive technology') !== false) {
            return 'Assistive Technology';
        }
        
        // Deaf Awareness category
        if (strpos($course_name, 'deaf awareness') !== false ||
            strpos($course_name, 'deaf 101') !== false ||
            strpos($description, 'deaf awareness') !== false) {
            return 'Deaf Awareness';
        }
        
        // Vocational Rehabilitation category
        if (strpos($course_name, 'vocational rehabilitation') !== false ||
            strpos($course_name, 'introduction to deaf rehabilitation') !== false ||
            strpos($description, 'vocational rehabilitation') !== false) {
            return 'Vocational Rehabilitation';
        }
        
        // Transition Services category
        if (strpos($course_name, 'transition') !== false ||
            strpos($course_name, 'pre-ets') !== false ||
            strpos($course_name, 'pre-employment') !== false ||
            strpos($course_name, 'deaf youth') !== false) {
            return 'Transition Services';
        }
        
        // Data and Evaluation category
        if (strpos($course_name, 'data-driven') !== false ||
            strpos($course_name, 'finding data') !== false ||
            strpos($course_name, 'collecting data') !== false ||
            strpos($course_name, 'using data') !== false ||
            strpos($course_name, 'evaluating') !== false) {
            return 'Data and Evaluation';
        }
        
        // Systems Change category
        if (strpos($course_name, 'barriers') !== false ||
            strpos($course_name, 'transforming systems') !== false ||
            strpos($course_name, 'building relationships') !== false ||
            strpos($course_name, 'systems change') !== false) {
            return 'Systems Change';
        }
        
        // Legal and Compliance category
        if (strpos($course_name, 'legal framework') !== false ||
            strpos($course_name, 'accommodations 101') !== false ||
            strpos($course_name, 'hipaa') !== false ||
            strpos($course_name, 'grievance') !== false) {
            return 'Legal and Compliance';
        }
        
        // Communication Access category
        if (strpos($course_name, 'captioned media') !== false ||
            strpos($course_name, 'caption') !== false ||
            strpos($course_name, 'interpreting') !== false ||
            strpos($course_name, 'speech-to-text') !== false ||
            strpos($course_name, 'note taker') !== false) {
            return 'Communication Access';
        }
        
        // Accessible Education category
        if (strpos($course_name, 'accessible online') !== false ||
            strpos($course_name, 'accessible learning') !== false ||
            strpos($course_name, 'udl') !== false ||
            strpos($course_name, 'universal design') !== false ||
            strpos($course_name, 'testing experiences') !== false) {
            return 'Accessible Education';
        }
        
        // Remote Services category
        if (strpos($course_name, 'remote services') !== false ||
            strpos($course_name, 'online') !== false && strpos($course_name, 'deaf') !== false) {
            return 'Remote Services';
        }
        
        // Professional Development category (for mentoring, etc.)
        if (strpos($course_name, 'mentor') !== false ||
            strpos($course_name, 'professional development') !== false ||
            strpos($course_name, 'effective mentoring') !== false) {
            return 'Professional Development';
        }
        
        // Community Engagement category
        if (strpos($course_name, 'community conversation') !== false ||
            strpos($course_name, 'community') !== false ||
            strpos($course_name, 'fac ') !== false) {
            return 'Community Engagement';
        }
        
        // Work-Based Learning category
        if (strpos($course_name, 'work-based learning') !== false ||
            strpos($course_name, 'workplace') !== false) {
            return 'Work-Based Learning';
        }
        
        // Campus Access category
        if (strpos($course_name, 'campus access') !== false ||
            strpos($course_name, 'improving campus') !== false ||
            strpos($course_name, 'college students') !== false) {
            return 'Campus Access';
        }
        
        // Health and Wellness category
        if (strpos($course_name, 'mental health') !== false ||
            strpos($course_name, 'health science') !== false ||
            strpos($course_name, 'retraumatization') !== false) {
            return 'Health and Wellness';
        }
        
        // Events and Ceremonies category
        if (strpos($course_name, 'commencement') !== false ||
            strpos($course_name, 'graduation') !== false) {
            return 'Events and Ceremonies';
        }
        
        // Leadership category
        if (strpos($course_name, 'deaf people leading') !== false ||
            strpos($course_name, 'leadership') !== false) {
            return 'Leadership';
        }
        
        // If no specific category is found, look for OnDemand Webinar
        if (strpos($course_name, 'ondemand webinar') !== false) {
            // Try to extract category from webinar topic
            if (strpos($course_name, 'assistive') !== false) return 'Assistive Technology';
            if (strpos($course_name, 'access') !== false) return 'Communication Access';
            if (strpos($course_name, 'deaf') !== false) return 'Deaf Awareness';
            // For webinars, use Webinar as category
            return 'Webinar';
        }
        
        // Summer Programs category
        if (strpos($course_name, 'summer program') !== false) {
            return 'Summer Programs';
        }
        
        // If still no match, log warning and return generic category
        error_log('CCS_Content_Handler: WARNING - No specific category found for course: ' . ($course_details['name'] ?? 'Unknown'));
        return 'General Education';
    }

    /**
     * Extract CE hours from course details
     */
    private function extract_ce_hours($course_details) {
        // Search for CE hours in various fields
        $search_text = '';
        $fields = array('name', 'public_description', 'syllabus_body', 'description');
        
        foreach ($fields as $field) {
            if (!empty($course_details[$field])) {
                $search_text .= ' ' . $course_details[$field];
            }
        }
        
        // Look for patterns like "1.5 CE", "2 hours", "3.0 credits", "1 hour"
        if (preg_match('/(\d+(?:\.\d+)?)\s*(?:CE|continuing education|credit|hour|clock hour)/i', $search_text, $matches)) {
            return $matches[1];
        }
        
        // If no hours found in course fields, check Canvas pages
        if ($this->api && !empty($course_details['id'])) {
            $pages_result = $this->api->get_course_pages($course_details['id']);
            if (!is_wp_error($pages_result)) {
                foreach ($pages_result as $page) {
                    // Check page titles and content for CE information
                    if ($this->api && !empty($page['url'])) {
                        $page_content = $this->api->get_page_content($course_details['id'], $page['url']);
                        if (!is_wp_error($page_content) && !empty($page_content['body'])) {
                            if (preg_match('/(\d+(?:\.\d+)?)\s*(?:CE|continuing education|credit|hour|clock hour)/i', $page_content['body'], $matches)) {
                                error_log('CCS_Content_Handler: Found CE hours in page: ' . $page['title'] . ' - ' . $matches[1] . ' hours');
                                return $matches[1];
                            }
                        }
                    }
                }
            }
        }
        
        // No default - return null if hours not specified
        error_log('CCS_Content_Handler: WARNING - No CE hours found for course: ' . ($course_details['name'] ?? 'Unknown'));
        return null;
    }
    
    /**
     * Build continuing education credit section
     */
    private function build_continuing_education_credit($course_details) {
        $content = '';
        
        $content .= "<div class='continuing-education-credit'>\n";
        $content .= "<h2>Continuing Education Credit</h2>\n";
        
        // Extract CE hours
        $ce_hours = $this->extract_ce_hours($course_details);
        
        if ($ce_hours) {
            $hours_text = $ce_hours == '1' ? '1 NDC Continuing Professional Education Clock Hour' : $ce_hours . ' NDC Continuing Professional Education Clock Hours';
            $crcc_hours_text = $ce_hours == '1' ? '1 CRCC Clock Hour' : $ce_hours . ' CRCC Clock Hours';
            
            $content .= "<p>This module is pre-approved for <strong>" . $hours_text . "</strong> and <strong>" . $crcc_hours_text . "</strong>.</p>\n";
        } else {
            // No default hours - show that CE information is being loaded
            $content .= "<p><em>Continuing education credit information is being loaded from Canvas. Please check back for details.</em></p>\n";
        }
        
        $content .= "</div>\n\n";
        
        return $content;
    }
}