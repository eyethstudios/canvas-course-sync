
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

        // About Course Section (comprehensive course description)
        $content .= $this->build_about_course_section($course_details, $pages);

        // Module Description Section (from actual Canvas content)
        $content .= $this->build_module_description($course_details, $modules, $pages);

        // Learning Objectives Section (from Canvas modules and pages)
        $content .= $this->build_learning_objectives($course_id, $course_details, $modules, $pages);

        // Badge Information Section
        $content .= $this->build_badge_information($course_details, $pages);

        // Continuing Education Credit Section
        $content .= $this->build_ce_credit_information($course_details, $pages);

        error_log('CCS_Content_Handler: Generated content length: ' . strlen($content));
        
        return $content;
    }

    /**
     * Build about course section from Canvas content
     */
    private function build_about_course_section($course_details, $pages = array()) {
        $content = '';
        $course_name = $course_details['name'] ?? 'Unknown Course';
        
        error_log('CCS_Content_Handler: Building about course section for ' . $course_name . ' with ' . count($pages) . ' pages');
        
        $content .= "<div class='about-course'>\n";
        $content .= "<h2>About This Course</h2>\n";
        
        $course_description = '';
        
        // Priority order: public_description, syllabus_body, description
        if (!empty($course_details['public_description'])) {
            $course_description = $course_details['public_description'];
            error_log('CCS_Content_Handler: Using public_description for about section');
        } elseif (!empty($course_details['syllabus_body'])) {
            $course_description = $course_details['syllabus_body'];
            error_log('CCS_Content_Handler: Using syllabus_body for about section');
        } elseif (!empty($course_details['description'])) {
            $course_description = $course_details['description'];
            error_log('CCS_Content_Handler: Using description field for about section');
        }
        
        // Look for course overview/about pages
        if (empty($course_description) && !empty($pages)) {
            error_log('CCS_Content_Handler: No course description found, searching pages...');
            foreach ($pages as $page) {
                $page_title = strtolower($page['title'] ?? '');
                if (stripos($page_title, 'about') !== false || 
                    stripos($page_title, 'overview') !== false ||
                    stripos($page_title, 'introduction') !== false ||
                    stripos($page_title, 'description') !== false) {
                    
                    error_log('CCS_Content_Handler: Found relevant about page: ' . $page['title']);
                    
                    // Get the page content
                    if ($this->api && !empty($page['url'])) {
                        $page_content = $this->api->get_page_content($course_details['id'], $page['url']);
                        if (!is_wp_error($page_content) && !empty($page_content['body'])) {
                            $course_description = $page_content['body'];
                            error_log('CCS_Content_Handler: Retrieved about page content, length: ' . strlen($course_description));
                            break;
                        }
                    }
                }
            }
        }
        
        // Use course description if found, otherwise use fallback
        if (!empty($course_description)) {
            $clean_description = wp_kses_post($course_description);
            $content .= $clean_description . "\n";
            error_log('CCS_Content_Handler: Added about content, length: ' . strlen($clean_description));
        } else {
            // Fallback generic description only if no Canvas content found
            error_log('CCS_Content_Handler: No about content found, using fallback for: ' . $course_name);
            $content .= "<p>This comprehensive course on " . esc_html($course_name) . " provides essential knowledge and practical skills for professionals working in this field. Participants will gain insights into best practices, current standards, and effective strategies.</p>\n";
        }
        
        $content .= "</div>\n\n";
        return $content;
    }

    /**
     * Build module description section from Canvas content
     */
    private function build_module_description($course_details, $modules = array(), $pages = array()) {
        $content = '';
        $course_name = $course_details['name'] ?? 'Unknown Course';
        
        error_log('CCS_Content_Handler: Building module description for ' . $course_name . ' with ' . count($modules) . ' modules and ' . count($pages) . ' pages');
        
        $content .= "<div class='module-description'>\n";
        $content .= "<h2>Module Description</h2>\n";
        
        $module_description = '';
        
        // First, look specifically in pages for module description content
        if (!empty($pages)) {
            error_log('CCS_Content_Handler: Searching pages for module description...');
            foreach ($pages as $page) {
                $page_title = strtolower($page['title'] ?? '');
                error_log('CCS_Content_Handler: Checking page: ' . $page['title']);
                
                // Look for pages that likely contain module descriptions
                if (stripos($page_title, 'module') !== false && 
                    (stripos($page_title, 'description') !== false || 
                     stripos($page_title, 'overview') !== false ||
                     stripos($page_title, 'introduction') !== false)) {
                    
                    error_log('CCS_Content_Handler: Found module description page: ' . $page['title']);
                    
                    // If page has body content included, use it
                    if (!empty($page['body'])) {
                        $module_description = $page['body'];
                        error_log('CCS_Content_Handler: Using page body content, length: ' . strlen($module_description));
                        break;
                    }
                    
                    // Otherwise, fetch the page content
                    if ($this->api && !empty($page['url'])) {
                        $page_content = $this->api->get_page_content($course_details['id'], $page['url']);
                        if (!is_wp_error($page_content) && !empty($page_content['body'])) {
                            $module_description = $page_content['body'];
                            error_log('CCS_Content_Handler: Retrieved module page content, length: ' . strlen($module_description));
                            break;
                        }
                    }
                }
            }
        }
        
        // Skip course-level descriptions to avoid duplication with about section
        
        // If still no description, look in module content
        if (empty($module_description) && !empty($modules)) {
            error_log('CCS_Content_Handler: No course description found, checking modules...');
            foreach ($modules as $module) {
                // Check module description first
                if (!empty($module['description'])) {
                    $module_description = $module['description'];
                    error_log('CCS_Content_Handler: Using module description from: ' . ($module['name'] ?? 'unnamed module'));
                    break;
                }
                
                // Look for modules with description or introduction content
                if (!empty($module['items'])) {
                    foreach ($module['items'] as $item) {
                        if ($item['type'] === 'Page' && 
                            (stripos($item['title'], 'description') !== false || 
                             stripos($item['title'], 'introduction') !== false ||
                             stripos($item['title'], 'overview') !== false)) {
                            
                            error_log('CCS_Content_Handler: Found relevant page: ' . $item['title']);
                            
                            // Get the page content using the API's get_page_content method
                            if ($this->api && !empty($item['page_url'])) {
                                $page_content = $this->api->get_page_content($course_details['id'], $item['page_url']);
                                if (!is_wp_error($page_content) && !empty($page_content['body'])) {
                                    $module_description = $page_content['body'];
                                    error_log('CCS_Content_Handler: Retrieved page content, length: ' . strlen($module_description));
                                    break 2; // Break out of both loops
                                } else {
                                    error_log('CCS_Content_Handler: Failed to get page content');
                                }
                            }
                        }
                    }
                }
            }
        }
        
        // Use module description if found, otherwise use fallback
        if (!empty($module_description)) {
            $clean_description = wp_kses_post($module_description);
            $content .= $clean_description . "\n";
            error_log('CCS_Content_Handler: Added description content, length: ' . strlen($clean_description));
        } else {
            // Fallback generic description only if no Canvas content found
            error_log('CCS_Content_Handler: No specific content found, using fallback for: ' . $course_name);
            $content .= "<p>This module provides comprehensive training and information related to " . esc_html($course_name) . ". Participants will gain practical knowledge and skills that can be applied in professional settings.</p>\n";
        }
        
        $content .= "</div>\n\n";
        return $content;
    }

    /**
     * Build learning objectives section from Canvas modules and pages
     */
    private function build_learning_objectives($course_id, $course_details, $modules = array(), $pages = array()) {
        $content = '';
        
        $content .= "<div class='learning-objectives'>\n";
        $content .= "<h2>Learning Objectives</h2>\n";
        $content .= "<p><strong>Participants will be able to:</strong></p>\n";
        $content .= "<ul>\n";

        $objectives_found = false;
        
        // First, look specifically in pages for learning objectives
        if (!empty($pages)) {
            error_log('CCS_Content_Handler: Searching pages for learning objectives...');
            foreach ($pages as $page) {
                $page_title = strtolower($page['title'] ?? '');
                error_log('CCS_Content_Handler: Checking page: ' . $page['title']);
                
                if (stripos($page_title, 'objective') !== false || 
                    stripos($page_title, 'outcome') !== false ||
                    stripos($page_title, 'goal') !== false ||
                    stripos($page_title, 'learning') !== false) {
                    
                    error_log('CCS_Content_Handler: Found objectives page: ' . $page['title']);
                    
                    $objectives_content = '';
                    
                    // If page has body content included, use it
                    if (!empty($page['body'])) {
                        $objectives_content = $page['body'];
                        error_log('CCS_Content_Handler: Using page body content for objectives');
                    } else if ($this->api && !empty($page['url'])) {
                        // Otherwise, fetch the page content
                        $page_content = $this->api->get_page_content($course_id, $page['url']);
                        if (!is_wp_error($page_content) && !empty($page_content['body'])) {
                            $objectives_content = $page_content['body'];
                            error_log('CCS_Content_Handler: Retrieved objectives page content');
                        }
                    }
                    
                    if (!empty($objectives_content)) {
                        // Extract list items from HTML content
                        if (preg_match_all('/<li[^>]*>(.*?)<\/li>/is', $objectives_content, $matches)) {
                            foreach ($matches[1] as $objective) {
                                $clean_objective = wp_strip_all_tags($objective);
                                if (!empty(trim($clean_objective))) {
                                    $content .= "<li>" . esc_html(trim($clean_objective)) . "</li>\n";
                                    $objectives_found = true;
                                }
                            }
                        }
                        
                        // Also try to extract objectives from paragraph content
                        if (!$objectives_found && preg_match_all('/(?:participants?\s+will(?:\s+be\s+able\s+to)?|learners?\s+will|objectives?)[:\s]*([^\.\n]+)/i', $objectives_content, $matches)) {
                            foreach ($matches[1] as $objective) {
                                $clean_objective = trim(wp_strip_all_tags($objective));
                                if (!empty($clean_objective) && strlen($clean_objective) > 10) {
                                    $content .= "<li>" . esc_html($clean_objective) . "</li>\n";
                                    $objectives_found = true;
                                }
                            }
                        }
                        
                        if ($objectives_found) {
                            error_log('CCS_Content_Handler: Successfully extracted objectives from page');
                            break; // Break out of page loop
                        }
                    }
                }
            }
        }
        
        // If no objectives found in pages, try modules
        if (!$objectives_found && !empty($modules)) {
            error_log('CCS_Content_Handler: Checking modules for learning objectives...');
            foreach ($modules as $module) {
                if (!empty($module['items'])) {
                    foreach ($module['items'] as $item) {
                        if ($item['type'] === 'Page' && 
                            (stripos($item['title'], 'objective') !== false || 
                             stripos($item['title'], 'outcome') !== false ||
                             stripos($item['title'], 'goal') !== false)) {
                            
                            error_log('CCS_Content_Handler: Found objectives page in module: ' . $item['title']);
                            
                            // Get the page content for learning objectives
                            if ($this->api && !empty($item['page_url'])) {
                                $page_content = $this->api->get_page_content($course_id, $item['page_url']);
                                if (!is_wp_error($page_content) && !empty($page_content['body'])) {
                                    $objectives_content = $page_content['body'];
                                    
                                    // Extract list items from HTML content
                                    if (preg_match_all('/<li[^>]*>(.*?)<\/li>/is', $objectives_content, $matches)) {
                                        foreach ($matches[1] as $objective) {
                                            $clean_objective = wp_strip_all_tags($objective);
                                            if (!empty(trim($clean_objective))) {
                                                $content .= "<li>" . esc_html(trim($clean_objective)) . "</li>\n";
                                                $objectives_found = true;
                                            }
                                        }
                                    }
                                    
                                    if ($objectives_found) {
                                        break 2; // Break out of both loops
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
        
        // Try Canvas outcome groups API if no objectives found in modules or pages
        if (!$objectives_found && $this->api) {
            error_log('CCS_Content_Handler: Checking Canvas outcome groups...');
            $outcomes_result = $this->api->make_request("courses/{$course_id}/outcome_groups");
            
            if (!is_wp_error($outcomes_result) && !empty($outcomes_result['data'])) {
                foreach ($outcomes_result['data'] as $outcome_group) {
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
            error_log('CCS_Content_Handler: No specific objectives found, using generic ones');
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
    private function build_badge_information($course_details, $pages = array()) {
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
    private function build_ce_credit_information($course_details, $pages = array()) {
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
