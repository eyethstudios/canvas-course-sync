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

        // Build comprehensive course content
        $content .= $this->build_comprehensive_course_content($course_details, $modules, $pages);

        error_log('CCS_Content_Handler: Generated content length: ' . strlen($content));
        
        return $content;
    }

    /**
     * Build comprehensive course content from all available sources
     */
    private function build_comprehensive_course_content($course_details, $modules = array(), $pages = array()) {
        $content = '';
        $course_name = $course_details['name'] ?? 'Unknown Course';
        
        // First, check if we have syllabus content from Canvas
        if (!empty($course_details['syllabus_body'])) {
            error_log('CCS_Content_Handler: Using syllabus body as primary content');
            $content .= "<div class='course-content'>\n";
            $content .= wp_kses_post($course_details['syllabus_body']);
            $content .= "</div>\n\n";
            return $content; // Return early if we have good syllabus content
        }
        
        // If no syllabus, check public description
        if (!empty($course_details['public_description'])) {
            error_log('CCS_Content_Handler: Using public description as primary content');
            $content .= "<div class='course-content'>\n";
            $content .= wp_kses_post($course_details['public_description']);
            $content .= "</div>\n\n";
            return $content; // Return early if we have good description
        }
        
        // If still no content, build from modules and pages
        $content .= "<div class='course-content'>\n";
        
        // Try to find the main course content page
        $main_content = $this->find_main_course_content($course_details['id'], $pages);
        if (!empty($main_content)) {
            $content .= $main_content;
        } else {
            // Build structured content from modules
            $content .= $this->build_structured_module_content($course_details, $modules, $pages);
        }
        
        $content .= "</div>\n\n";
        
        return $content;
    }

    /**
     * Find the main course content from pages
     */
    private function find_main_course_content($course_id, $pages = array()) {
        if (empty($pages) || !$this->api) {
            return '';
        }
        
        // Priority pages to check (in order)
        $priority_keywords = array(
            'course description',
            'course overview',
            'about this course',
            'module description',
            'course content',
            'introduction',
            'overview',
            'about',
            'welcome',
            'home'
        );
        
        // First pass: look for exact matches
        foreach ($priority_keywords as $keyword) {
            foreach ($pages as $page) {
                $page_title = strtolower($page['title'] ?? '');
                if (strpos($page_title, $keyword) !== false) {
                    $page_content = $this->api->get_page_content($course_id, $page['url']);
                    if (!is_wp_error($page_content) && !empty($page_content['body'])) {
                        $body = $page_content['body'];
                        
                        // Skip if this is badge/completion content
                        if ($this->is_badge_or_completion_content($body)) {
                            continue;
                        }
                        
                        error_log('CCS_Content_Handler: Found main content from page: ' . $page['title']);
                        return wp_kses_post($body);
                    }
                }
            }
        }
        
        // Second pass: find the first substantial non-badge page
        foreach ($pages as $page) {
            if ($this->api && !empty($page['url'])) {
                $page_content = $this->api->get_page_content($course_id, $page['url']);
                if (!is_wp_error($page_content) && !empty($page_content['body'])) {
                    $body = $page_content['body'];
                    $clean_text = strip_tags($body);
                    
                    // Check if this is substantial content (not badge/completion)
                    if (strlen($clean_text) > 300 && !$this->is_badge_or_completion_content($body)) {
                        error_log('CCS_Content_Handler: Using substantial page content from: ' . $page['title']);
                        return wp_kses_post($body);
                    }
                }
            }
        }
        
        return '';
    }

    /**
     * Check if content is primarily about badges or completion
     */
    private function is_badge_or_completion_content($content) {
        $clean_text = strtolower(strip_tags($content));
        
        // Count badge/completion related words
        $badge_words = array('badge', 'badgr', 'completion', 'certificate', 'credly', 'earned');
        $badge_count = 0;
        foreach ($badge_words as $word) {
            $badge_count += substr_count($clean_text, $word);
        }
        
        // If more than 5 badge-related words in content, it's likely badge content
        if ($badge_count > 5) {
            return true;
        }
        
        // Check if badge content is the majority of the text
        $total_words = str_word_count($clean_text);
        if ($total_words > 0 && ($badge_count / $total_words) > 0.1) {
            return true;
        }
        
        return false;
    }

    /**
     * Build structured content from modules
     */
    private function build_structured_module_content($course_details, $modules = array(), $pages = array()) {
        $content = '';
        
        // Add course description if available
        if (!empty($course_details['description'])) {
            $content .= "<h2>Course Description</h2>\n";
            $content .= "<p>" . wp_kses_post($course_details['description']) . "</p>\n\n";
        }
        
        // Add module information
        if (!empty($modules)) {
            $content .= "<h2>Course Modules</h2>\n";
            $content .= "<div class='course-modules'>\n";
            
            foreach ($modules as $module) {
                $module_name = $module['name'] ?? 'Unnamed Module';
                $content .= "<h3>" . esc_html($module_name) . "</h3>\n";
                
                if (!empty($module['items'])) {
                    $content .= "<ul>\n";
                    foreach ($module['items'] as $item) {
                        $item_title = $item['title'] ?? 'Untitled';
                        $item_type = $item['type'] ?? 'Item';
                        
                        // Skip badge-related items
                        if (stripos($item_title, 'badge') !== false || 
                            stripos($item_title, 'completion') !== false) {
                            continue;
                        }
                        
                        $content .= "<li><strong>" . esc_html($item_type) . ":</strong> " . esc_html($item_title) . "</li>\n";
                    }
                    $content .= "</ul>\n";
                }
            }
            
            $content .= "</div>\n\n";
        }
        
        // Add learning objectives if found
        $objectives = $this->extract_learning_objectives($course_details, $modules, $pages);
        if (!empty($objectives)) {
            $content .= "<h2>Learning Objectives</h2>\n";
            $content .= "<ul>\n";
            foreach ($objectives as $objective) {
                $content .= "<li>" . esc_html($objective) . "</li>\n";
            }
            $content .= "</ul>\n\n";
        }
        
        // Add any additional relevant information
        if (!empty($course_details['course_code'])) {
            $content .= "<p><strong>Course Code:</strong> " . esc_html($course_details['course_code']) . "</p>\n";
        }
        
        return $content;
    }

    /**
     * Extract learning objectives from course content
     */
    private function extract_learning_objectives($course_details, $modules = array(), $pages = array()) {
        $objectives = array();
        
        // Search in pages for objectives
        if (!empty($pages) && $this->api) {
            foreach ($pages as $page) {
                $page_title = strtolower($page['title'] ?? '');
                if (strpos($page_title, 'objective') !== false || 
                    strpos($page_title, 'outcome') !== false ||
                    strpos($page_title, 'goal') !== false) {
                    
                    $page_content = $this->api->get_page_content($course_details['id'], $page['url']);
                    if (!is_wp_error($page_content) && !empty($page_content['body'])) {
                        // Extract list items as objectives
                        if (preg_match_all('/<li[^>]*>(.*?)<\/li>/is', $page_content['body'], $matches)) {
                            foreach ($matches[1] as $match) {
                                $clean_text = trim(wp_strip_all_tags($match));
                                if (!empty($clean_text) && strlen($clean_text) > 20) {
                                    $objectives[] = $clean_text;
                                }
                            }
                        }
                    }
                    
                    if (!empty($objectives)) {
                        break; // Found objectives, stop searching
                    }
                }
            }
        }
        
        return $objectives;
    }
}