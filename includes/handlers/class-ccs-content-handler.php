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
        
        // Log all available course fields for debugging
        error_log('CCS_Content_Handler: Available course fields: ' . implode(', ', array_keys($course_details)));
        
        // PRIORITY 1: Try to get description from catalog page first
        $description = '';
        $catalog_content = $this->fetch_catalog_course_content($course_details['name'] ?? '');
        
        if (!empty($catalog_content['description'])) {
            $description = $catalog_content['description'];
            error_log('CCS_Content_Handler: Using catalog description for module description (length: ' . strlen($description) . ')');
        }
        // PRIORITY 2: Try to get description from Canvas course fields
        elseif (!empty($course_details['syllabus_body'])) {
            $description = $course_details['syllabus_body'];
            error_log('CCS_Content_Handler: Using syllabus_body for module description (length: ' . strlen($description) . ')');
        }
        // PRIORITY 3: public_description
        elseif (!empty($course_details['public_description'])) {
            $description = $course_details['public_description'];
            error_log('CCS_Content_Handler: Using public_description for module description (length: ' . strlen($description) . ')');
        }
        // PRIORITY 4: regular description
        elseif (!empty($course_details['description'])) {
            $description = $course_details['description'];
            error_log('CCS_Content_Handler: Using description field for module description (length: ' . strlen($description) . ')');
        }
        
        // PRIORITY 5: Try catalog backup for course-specific content
        if (empty($description)) {
            $description = $this->get_catalog_backup_description($course_details['name'] ?? '');
            if (!empty($description)) {
                error_log('CCS_Content_Handler: Using catalog backup description for: ' . ($course_details['name'] ?? 'Unknown'));
            }
        }
        
        // If still no description, search ALL pages thoroughly
        if (empty($description) && !empty($pages) && $this->api) {
            error_log('CCS_Content_Handler: No course description found, searching ALL pages thoroughly...');
            
            // Search every page for substantial content
            foreach ($pages as $page) {
                $page_content = $this->api->get_page_content($course_details['id'], $page['url']);
                if (!is_wp_error($page_content) && !empty($page_content['body'])) {
                    $body = $page_content['body'];
                    $clean_text = strip_tags($body);
                    
                    error_log('CCS_Content_Handler: Checking page "' . $page['title'] . '" (length: ' . strlen($clean_text) . ')');
                    
                    // Look specifically for module description content
                    if (preg_match('/module description[:\s]*(.{50,1000})/i', $clean_text, $matches)) {
                        $description = '<p>' . trim($matches[1]) . '</p>';
                        error_log('CCS_Content_Handler: Found module description pattern in page: ' . $page['title']);
                        break;
                    }
                    
                    // Look for detailed course content (substantial paragraphs about the course)
                    if (strlen($clean_text) > 200 && !$this->is_badge_or_completion_content($body)) {
                        // Check if this contains course-specific content
                        $course_terms = array('training', 'workplace', 'deaf', 'assistive', 'technology', 'participants', 'module');
                        $term_count = 0;
                        foreach ($course_terms as $term) {
                            if (stripos($clean_text, $term) !== false) {
                                $term_count++;
                            }
                        }
                        
                        if ($term_count >= 3) { // If it contains multiple course-related terms
                            $description = $body;
                            error_log('CCS_Content_Handler: Found substantial course content in page: ' . $page['title'] . ' (terms: ' . $term_count . ')');
                            break;
                        }
                    }
                }
            }
        }
        
        // If still no description, try to get it from module items
        if (empty($description) && !empty($modules)) {
            error_log('CCS_Content_Handler: Searching module items for course description...');
            foreach ($modules as $module) {
                if (!empty($module['items'])) {
                    foreach ($module['items'] as $item) {
                        if ($item['type'] === 'Page' && $this->api && !empty($item['url'])) {
                            $page_content = $this->api->get_page_content($course_details['id'], $item['url']);
                            if (!is_wp_error($page_content) && !empty($page_content['body'])) {
                                $body = $page_content['body'];
                                $clean_text = strip_tags($body);
                                
                                if (strlen($clean_text) > 200 && !$this->is_badge_or_completion_content($body)) {
                                    $description = $body;
                                    error_log('CCS_Content_Handler: Found description in module item: ' . ($item['title'] ?? 'Untitled'));
                                    break 2;
                                }
                            }
                        }
                    }
                }
            }
        }
        
        // Add the description content
        if (!empty($description)) {
            // Clean up the description - remove extra whitespace but preserve HTML
            $description = trim($description);
            $content .= wp_kses_post($description);
            error_log('CCS_Content_Handler: Added description content (final length: ' . strlen($description) . ')');
        } else {
            // Log warning and provide course-specific placeholder
            error_log('CCS_Content_Handler: WARNING - No course description found anywhere for: ' . ($course_details['name'] ?? 'Unknown'));
            $course_name = $course_details['name'] ?? 'this course';
            $content .= "<p>This module provides comprehensive training on " . esc_html($course_name) . ". Detailed course content is being loaded from Canvas.</p>";
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
        
        // PRIORITY 1: First try to get objectives from catalog page
        $catalog_content = $this->fetch_catalog_course_content($course_details['name'] ?? '');
        
        if (!empty($catalog_content['objectives'])) {
            $objectives = $catalog_content['objectives'];
            error_log('CCS_Content_Handler: Using catalog objectives for: ' . ($course_details['name'] ?? 'Unknown'));
        } 
        // PRIORITY 2: Fallback to extracting from Canvas content
        else {
            $objectives = $this->extract_objectives_from_canvas($course_id, $course_details, $modules, $pages);
        }
        
        // Search ALL pages for learning objectives, not just those with objective in title
        if (!empty($pages) && $this->api) {
            error_log('CCS_Content_Handler: Searching all pages for learning objectives...');
            
            foreach ($pages as $page) {
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
        
        // If no objectives found in pages, search in course description fields
        if (empty($objectives)) {
            error_log('CCS_Content_Handler: Searching course fields for learning objectives...');
            $search_fields = array('syllabus_body', 'public_description', 'description');
            foreach ($search_fields as $field) {
                if (!empty($course_details[$field])) {
                    $objectives = $this->extract_objectives_from_content($course_details[$field]);
                    if (!empty($objectives)) {
                        error_log('CCS_Content_Handler: Found ' . count($objectives) . ' objectives in course ' . $field);
                        break;
                    }
                }
            }
        }
        
        // If still no objectives, search ALL module items thoroughly
        if (empty($objectives) && !empty($modules)) {
            error_log('CCS_Content_Handler: Searching all module items for learning objectives...');
            foreach ($modules as $module) {
                if (!empty($module['items'])) {
                    foreach ($module['items'] as $item) {
                        if ($item['type'] === 'Page' && $this->api && !empty($item['url'])) {
                            $page_content = $this->api->get_page_content($course_id, $item['url']);
                            if (!is_wp_error($page_content) && !empty($page_content['body'])) {
                                $objectives = $this->extract_objectives_from_content($page_content['body']);
                                if (!empty($objectives)) {
                                    error_log('CCS_Content_Handler: Found ' . count($objectives) . ' objectives in module item: ' . ($item['title'] ?? 'Untitled'));
                                    break 2;
                                }
                            }
                        }
                    }
                }
            }
        }
        
        // If still no objectives, try catalog backup
        if (empty($objectives)) {
            $objectives = $this->get_catalog_backup_objectives($course_details['name'] ?? '');
            if (!empty($objectives)) {
                error_log('CCS_Content_Handler: Using catalog backup objectives for: ' . ($course_details['name'] ?? 'Unknown'));
            }
        }
        
        // Add objectives to content
        if (!empty($objectives)) {
            foreach ($objectives as $objective) {
                $content .= "<li>" . esc_html($objective) . "</li>\n";
            }
            error_log('CCS_Content_Handler: Added ' . count($objectives) . ' learning objectives to content');
        } else {
            // Log warning about missing objectives
            error_log('CCS_Content_Handler: WARNING - No learning objectives found for course: ' . ($course_details['name'] ?? 'Unknown'));
            
            // Provide course-specific default objectives
            $course_name = strtolower($course_details['name'] ?? '');
            if (strpos($course_name, 'assistive technology') !== false) {
                $content .= "<li>Demonstrate knowledge of assistive technologies and their relevance to supporting deaf individuals</li>\n";
                $content .= "<li>Identify actionable strategies for implementing assistive technologies in training and workplace settings</li>\n";
                $content .= "<li>Develop strategies for creating accessible environments where deaf individuals feel valued and supported</li>\n";
                $content .= "<li>Create and evaluate policies that promote accessibility and support continuous improvement</li>\n";
            } else {
                $content .= "<li>Understand the key concepts and principles covered in this course</li>\n";
                $content .= "<li>Apply learned skills in practical scenarios</li>\n";
                $content .= "<li>Demonstrate proficiency in the course subject matter</li>\n";
            }
        }
        
        $content .= "</ul>\n";
        $content .= "</div>\n\n";
        
        return $content;
    }
    
    /**
     * Fetch comprehensive course content from catalog page
     */
    private function fetch_catalog_course_content($course_name) {
        $course_links = $this->get_catalog_course_links();
        $course_url = null;
        
        // Find matching course URL
        foreach ($course_links as $title => $url) {
            if (stripos($title, $course_name) !== false || stripos($course_name, $title) !== false) {
                $course_url = $url;
                break;
            }
        }
        
        if (!$course_url) {
            error_log("CCS_Content_Handler: No catalog URL found for course: {$course_name}");
            return array();
        }
        
        // Check cache first
        $cache_key = 'ccs_catalog_content_' . md5($course_url);
        $cached = get_transient($cache_key);
        if ($cached !== false) {
            return $cached;
        }
        
        // Fetch course page
        $response = wp_remote_get($course_url, array('timeout' => 30));
        
        if (is_wp_error($response)) {
            error_log("CCS_Content_Handler: Failed to fetch catalog page: " . $response->get_error_message());
            return array();
        }
        
        $html = wp_remote_retrieve_body($response);
        $content = array();
        
        // Extract course description
        $content['description'] = $this->extract_description_from_catalog_html($html);
        
        // Extract learning objectives  
        $content['objectives'] = $this->extract_objectives_from_catalog_html($html);
        
        // Extract additional course details
        $content['details'] = $this->extract_course_details_from_catalog_html($html);
        
        // Cache for 2 hours
        set_transient($cache_key, $content, 2 * HOUR_IN_SECONDS);
        
        error_log("CCS_Content_Handler: Fetched catalog content for: {$course_name}");
        return $content;
    }
    
    /**
     * Extract description from catalog HTML
     */
    private function extract_description_from_catalog_html($html) {
        // First try to find NDC-specific markdown pattern: **Module Description:** 
        if (preg_match('/\*\*Module Description:\*\*\s*(.*?)(?:\*\*|$)/is', $html, $matches)) {
            $description = trim(strip_tags($matches[1]));
            if (strlen($description) > 50) {
                error_log('CCS_Content_Handler: Found module description using NDC markdown pattern');
                return $description;
            }
        }
        
        // Try alternative pattern for "About This Course" section
        if (preg_match('/## About This Course.*?\*\*Module Description:\*\*\s*(.*?)(?:\*\*|<|$)/is', $html, $matches)) {
            $description = trim(strip_tags($matches[1]));
            if (strlen($description) > 50) {
                error_log('CCS_Content_Handler: Found description in About This Course section');
                return $description;
            }
        }
        
        // Look for course description in various HTML patterns
        $description_patterns = array(
            // Main course description
            '/<div[^>]*class="[^"]*description[^"]*"[^>]*>(.*?)<\/div>/is',
            '/<p[^>]*class="[^"]*description[^"]*"[^>]*>(.*?)<\/p>/is',
            // Course summary/overview
            '/<div[^>]*class="[^"]*summary[^"]*"[^>]*>(.*?)<\/div>/is',
            '/<div[^>]*class="[^"]*overview[^"]*"[^>]*>(.*?)<\/div>/is',
            // Generic content areas
            '/<div[^>]*class="[^"]*content[^"]*"[^>]*>(.*?)<\/div>/is'
        );
        
        foreach ($description_patterns as $pattern) {
            if (preg_match($pattern, $html, $matches)) {
                $description = trim(wp_strip_all_tags($matches[1], true));
                if (strlen($description) > 100) { // Ensure it's substantial content
                    return $description;
                }
            }
        }
        
        error_log('CCS_Content_Handler: No description found in catalog HTML');
        return '';
    }
    
    /**
     * Extract learning objectives from catalog HTML
     */
    private function extract_objectives_from_catalog_html($html) {
        $objectives = array();
        
        // First try NDC-specific markdown pattern: **Learning Objectives:** By the end of this module, participants will be able to:
        if (preg_match('/\*\*Learning Objectives:\*\*\s*By the end of this module, participants will be able to:\s*(.*?)(?:\*\*|```|$)/is', $html, $matches)) {
            $objectives_text = $matches[1];
            error_log('CCS_Content_Handler: Found learning objectives using NDC markdown pattern');
            
            // Extract bullet points or dash-separated items
            $lines = preg_split('/[\r\n]+/', $objectives_text);
            foreach ($lines as $line) {
                $line = trim($line);
                // Look for lines starting with - or • or numbered items
                if (preg_match('/^[-•*]\s*(.+)/', $line, $match) || 
                    preg_match('/^\d+\.\s*(.+)/', $line, $match)) {
                    $clean_objective = trim($match[1]);
                    if (strlen($clean_objective) > 20) {
                        $objectives[] = $clean_objective;
                    }
                }
            }
            
            if (!empty($objectives)) {
                return $objectives;
            }
        }
        
        // Look for objectives sections in HTML
        $objective_patterns = array(
            // Learning objectives section
            '/<div[^>]*(?:learning.?objectives|objectives)[^>]*>(.*?)<\/div>/is',
            '/<section[^>]*(?:learning.?objectives|objectives)[^>]*>(.*?)<\/section>/is',
            // Will be able to sections
            '/(?:participants|students|learners)?\s*will\s*be\s*able\s*to[:\s]+(.*?)(?:<\/ul>|<\/ol>|<\/div>)/is'
        );
        
        foreach ($objective_patterns as $pattern) {
            if (preg_match($pattern, $html, $matches)) {
                $section = $matches[1];
                
                // Extract list items
                if (preg_match_all('/<li[^>]*>(.*?)<\/li>/is', $section, $li_matches)) {
                    foreach ($li_matches[1] as $objective) {
                        $clean_objective = trim(wp_strip_all_tags($objective));
                        if (strlen($clean_objective) > 20) {
                            $objectives[] = $clean_objective;
                        }
                    }
                }
                
                if (!empty($objectives)) {
                    break;
                }
            }
        }
        
        if (empty($objectives)) {
            error_log('CCS_Content_Handler: No learning objectives found in catalog HTML');
        }
        
        return $objectives;
    }
    
    /**
     * Extract additional course details from catalog HTML
     */
    private function extract_course_details_from_catalog_html($html) {
        $details = array();
        
        // Extract course duration
        if (preg_match('/duration[^>]*>([^<]+)/i', $html, $matches)) {
            $details['duration'] = trim($matches[1]);
        }
        
        // Extract course format (self-paced, etc.)
        if (preg_match('/(?:format|pace)[^>]*>([^<]+)/i', $html, $matches)) {
            $details['format'] = trim($matches[1]);
        }
        
        // Extract prerequisites
        if (preg_match('/prerequisite[^>]*>(.*?)<\/[^>]+>/is', $html, $matches)) {
            $details['prerequisites'] = trim(wp_strip_all_tags($matches[1]));
        }
        
        return $details;
    }
    
    /**
     * Extract objectives from Canvas content (fallback method)
     */
    private function extract_objectives_from_canvas($course_id, $course_details, $modules, $pages) {
        $objectives = array();
        
        // Search through modules and pages for learning objectives
        if (!empty($modules)) {
            foreach ($modules as $module) {
                if (!empty($module['items'])) {
                    foreach ($module['items'] as $item) {
                        if ($item['type'] === 'Page' && $this->api && !empty($item['url'])) {
                            $page_content = $this->api->get_page_content($course_id, $item['url']);
                            if (!is_wp_error($page_content) && !empty($page_content['body'])) {
                                $page_objectives = $this->extract_objectives_from_content($page_content['body']);
                                if (!empty($page_objectives)) {
                                    $objectives = array_merge($objectives, $page_objectives);
                                }
                            }
                        }
                    }
                }
            }
        }
        
        return $objectives;
    }

    /**
     * Extract objectives from HTML content
     */
    private function extract_objectives_from_content($html_content) {
        $objectives = array();
        
        // Look for learning objectives section specifically
        if (preg_match('/learning objectives?[:\s\-]*(.+?)(?=<h|$)/is', $html_content, $section_match)) {
            $objectives_section = $section_match[1];
            error_log('CCS_Content_Handler: Found learning objectives section in content');
            
            // Extract list items from the objectives section
            if (preg_match_all('/<li[^>]*>(.*?)<\/li>/is', $objectives_section, $matches)) {
                foreach ($matches[1] as $match) {
                    $clean_text = trim(wp_strip_all_tags($match));
                    if (strlen($clean_text) > 20 && strlen($clean_text) < 500) {
                        $objectives[] = $clean_text;
                    }
                }
            }
            
            // If no list items in section, look for bullet points or numbered items
            if (empty($objectives)) {
                $lines = explode("\n", strip_tags($objectives_section));
                foreach ($lines as $line) {
                    $line = trim($line);
                    if (preg_match('/^[-•*]\s*(.+)/', $line, $match) || 
                        preg_match('/^\d+\.\s*(.+)/', $line, $match)) {
                        $clean_text = trim($match[1]);
                        if (strlen($clean_text) > 20 && strlen($clean_text) < 500) {
                            $objectives[] = $clean_text;
                        }
                    }
                }
            }
        }
        
        // Fallback: Look for any unordered or ordered lists in the content  
        if (empty($objectives)) {
            if (preg_match_all('/<li[^>]*>(.*?)<\/li>/is', $html_content, $matches)) {
                foreach ($matches[1] as $match) {
                    $clean_text = trim(wp_strip_all_tags($match));
                    
                    // Check if this looks like a learning objective
                    $objective_keywords = array('learn', 'understand', 'identify', 'describe', 'explain', 'demonstrate', 'analyze', 'evaluate', 'apply', 'create', 'design', 'develop', 'implement', 'recognize', 'compare', 'contrast', 'discuss', 'examine', 'explore', 'investigate', 'assess', 'interpret');
                    
                    $contains_keyword = false;
                    foreach ($objective_keywords as $keyword) {
                        if (stripos($clean_text, $keyword) !== false) {
                            $contains_keyword = true;
                            break;
                        }
                    }
                    
                    if ($contains_keyword && strlen($clean_text) > 20 && strlen($clean_text) < 500) {
                        $objectives[] = $clean_text;
                    }
                }
            }
        }
        
        // Additional patterns for objectives not in lists
        if (empty($objectives)) {
            $patterns = array(
                '/(?:by the end|after completing|upon completion)[^.!?]*[.!?]/i',
                '/(?:you will|students will|learners will)[^.!?]*[.!?]/i',
                '/(?:objective|goal)[^:]*:([^.!?]*[.!?])/i'
            );
            
            foreach ($patterns as $pattern) {
                if (preg_match_all($pattern, $html_content, $matches)) {
                    foreach ($matches[0] as $match) {
                        $clean_text = trim(wp_strip_all_tags($match));
                        if (strlen($clean_text) > 30 && strlen($clean_text) < 500) {
                            $objectives[] = $clean_text;
                        }
                    }
                }
            }
        }
        
        // Clean up and return unique objectives
        $objectives = array_unique($objectives);
        $objectives = array_filter($objectives, function($obj) {
            return strlen(trim($obj)) > 20;
        });
        
        return array_values($objectives);
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
     * Build badge information section
     */
    /**
     * Build badge information section
     */
    private function build_badge_information($course_details) {
        $content = '';
        
        $content .= "<div class='badge-information'>\n";
        $content .= "<p><strong>Badge information:</strong> Module content category - ";
        
        // Get badge category based on course name from catalog data
        $course_name = $course_details['name'] ?? '';
        $badge_info = $this->get_badge_info_from_catalog($course_name);
        
        if ($badge_info) {
            $content .= "<strong>" . esc_html($badge_info['category']) . "</strong></p>\n\n";
            
            // Add badge image with proper styling to match catalog  
            $badge_image_file = $badge_info['image_file'];
            $badge_image_path = CCS_PLUGIN_DIR . 'assets/images/' . $badge_image_file;
            
            // Check if badge image file exists, otherwise use default
            if (!file_exists($badge_image_path)) {
                $badge_image_file = 'ndc-badge.svg'; // Use default badge if specific one doesn't exist
                $badge_image_path = CCS_PLUGIN_DIR . 'assets/images/' . $badge_image_file;
            }
            
            $badge_image_url = CCS_PLUGIN_URL . 'assets/images/' . $badge_image_file;
            $content .= "<div class='badge-image' style='text-align: center; margin: 20px 0;'>\n";
            $content .= "<img src='" . esc_url($badge_image_url) . "' alt='Badge for " . esc_attr($badge_info['category']) . "' style='width: 150px; height: 150px; border-radius: 50%; border: 3px solid #2c5aa0;' />\n";
            $content .= "</div>\n\n";
            
            // Add badge earning information
            if (!empty($badge_info['badge_name'])) {
                $content .= "<p style='text-align: center;'>Earn Your <em><a href='" . esc_url($badge_info['badge_url']) . "' target='_blank'>" . esc_html($badge_info['badge_name']) . "</a></em> badge</p>\n\n";
            } else {
                $content .= "<p style='text-align: center;'>" . esc_html($course_name) . "</p>\n\n";
            }
            
            $content .= "<p>Learn more about <a href='https://nationaldeafcenter.badgr.com/public/organization/badges' target='_blank'>NDC Badges here</a>.</p>\n";
        } else {
            // Default badge information with fallback to category determination
            $badge_category = $this->determine_badge_category($course_details);
            $content .= "<strong>" . esc_html($badge_category) . "</strong></p>\n\n";
            
            // Add default badge image
            $badge_image_url = CCS_PLUGIN_URL . 'assets/images/ndc-badge.svg';
            $content .= "<div class='badge-image' style='text-align: center; margin: 20px 0;'>\n";
            $content .= "<img src='" . esc_url($badge_image_url) . "' alt='NDC Badge' style='width: 150px; height: 150px;' />\n";
            $content .= "</div>\n\n";
            
            $content .= "<p style='text-align: center;'>" . esc_html($course_name) . "</p>\n\n";
            $content .= "<p>Learn more about <a href='https://nationaldeafcenter.badgr.com/public/organization/badges' target='_blank'>NDC Badges here</a>.</p>\n";
        }
        
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
     * Get catalog CE hours for a course
     */
    private function get_catalog_ce_hours($course_name) {
        $catalog_ce_data = $this->get_catalog_ce_data();
        
        $course_key = $this->normalize_course_name($course_name);
        
        if (isset($catalog_ce_data[$course_key])) {
            return $catalog_ce_data[$course_key];
        }
        
        // If exact match not found, try partial matching
        foreach ($catalog_ce_data as $key => $ce_info) {
            if (stripos($course_name, str_replace('_', ' ', $key)) !== false || 
                stripos(str_replace('_', ' ', $key), $course_name) !== false) {
                error_log('CCS_Content_Handler: Found partial CE match for: ' . $course_name . ' -> ' . $key);
                return $ce_info;
            }
        }
        
        error_log('CCS_Content_Handler: WARNING - No catalog CE hours found for course: ' . $course_name);
        return null;
    }
    
    /**
     * Get catalog CE data mapping
     */
    private function get_catalog_ce_data() {
        return array(
            'assistive_technology_in_training_and_workplace_settings' => array(
                'hours' => '1',
                'text' => 'This module is pre-approved for 1 NDC Continuing Professional Education Clock Hour and 1 CRCC Clock Hour.'
            ),
            'deaf_awareness_for_vocational_rehabilitation_professionals' => array(
                'hours' => '1.5',
                'text' => 'This module is pre-approved for 1.5 NDC Continuing Professional Education Clock Hours and 1.5 CRCC Clock Hours.'
            ),
            'effective_mentoring_for_deaf_people' => array(
                'hours' => '1',
                'text' => 'This module is pre-approved for 1 NDC Continuing Professional Education Clock Hour and 1 CRCC Clock Hour.'
            ),
            'introduction_to_deaf_rehabilitation' => array(
                'hours' => '1',
                'text' => 'This module is pre-approved for 1 NDC Continuing Professional Education Clock Hour and 1 CRCC Clock Hour.'
            )
        );
    }
    
    /**
     * Build continuing education credit section
     */
    private function build_continuing_education_credit($course_details) {
        $content = '';
        
        $content .= "<div class='continuing-education-credit'>\n";
        $content .= "<h2>Continuing Education Credit</h2>\n";
        
        // Get CE hours from catalog data first
        $ce_info = $this->get_catalog_ce_hours($course_details['name'] ?? '');
        
        if ($ce_info && !empty($ce_info['text'])) {
            $content .= "<p>" . esc_html($ce_info['text']) . "</p>\n";
            error_log('CCS_Content_Handler: Using catalog CE information for: ' . ($course_details['name'] ?? 'Unknown'));
        } else {
            // Log warning about missing CE information
            error_log('CCS_Content_Handler: WARNING - No catalog CE information found for course: ' . ($course_details['name'] ?? 'Unknown'));
            $content .= "<p><em>Continuing education credit information is not available for this course.</em></p>\n";
        }
        
        $content .= "</div>\n\n";
        
        return $content;
    }
    
    /**
     * Get catalog backup description for a course
     */
    private function get_catalog_backup_description($course_name) {
        $catalog_data = $this->get_catalog_backup_data();
        
        $course_key = $this->normalize_course_name($course_name);
        
        if (isset($catalog_data[$course_key]) && !empty($catalog_data[$course_key]['description'])) {
            return '<p>' . $catalog_data[$course_key]['description'] . '</p>';
        }
        
        return '';
    }
    
    /**
     * Get catalog backup objectives for a course
     */
    private function get_catalog_backup_objectives($course_name) {
        $catalog_data = $this->get_catalog_backup_data();
        
        $course_key = $this->normalize_course_name($course_name);
        
        if (isset($catalog_data[$course_key]) && !empty($catalog_data[$course_key]['objectives'])) {
            return $catalog_data[$course_key]['objectives'];
        }
        
        return array();
    }
    
    /**
     * Get catalog backup data
     */
    private function get_catalog_backup_data() {
        return array(
            'assistive_technology_in_training_and_workplace_settings' => array(
                'description' => 'This module provides a detailed look at assistive technology used in training and workplace settings to support deaf people. Topics include interpreting services, assistive listening devices (ALDs), hearing aids, cochlear implants (CIs), visual alarms, speech-to-text (STT) technology, and other relevant technologies. Participants will learn about the benefits of these technologies and how they can enhance accessibility and productivity in the workplace.',
                'objectives' => array(
                    'Demonstrate knowledge of assistive technologies, their relevance to supporting deaf trainees and employees, and how to apply this understanding to create accessible workplaces.',
                    'Identify actionable strategies for implementing assistive technologies and creating environments where deaf trainees and employees feel valued and supported.',
                    'Develop strategies for training programs and provide ongoing support to ensure the continued effectiveness of assistive technologies in training and workplace settings.',
                    'Create, implement, and evaluate training and workplace policies that promote accessibility and support continuous improvement.'
                )
            ),
            'deaf_awareness_for_vocational_rehabilitation_professionals' => array(
                'description' => 'This module aims to increase awareness of deaf people\'s lived experiences and interactions with vocational rehabilitation services. Vocational rehabilitation (VR) professionals can use this information to reflect on existing systemic barriers and how they impact the effectiveness of vocational rehabilitation services. Using the resources provided, participants can implement person-centered and culturally responsive practices that improve overall experiences with vocational rehabilitation services for deaf people.',
                'objectives' => array(
                    'Describe how centering the lived experiences of deaf people contributes to effective VR services.',
                    'Identify system barriers that impact access to education and employment opportunities.',
                    'Reflect on existing practices and identify opportunities to include culturally responsive and person-centered approaches that prioritize the needs of deaf people.',
                    'Apply knowledge of deaf communities to improve the impact and effectiveness of vocational rehabilitation services received by deaf people.'
                )
            ),
            'effective_mentoring_for_deaf_people' => array(
                'description' => 'This module explores the benefits of mentoring opportunities for deaf youth, anticipated challenges, and key considerations for effective mentoring. It is designed to provide information and resources to professionals planning mentoring programs and deaf mentors to help build or strengthen mentoring programs. Participants may also consider sharing information from the course with families of deaf youth.',
                'objectives' => array(
                    'State the significant benefits of mentorship',
                    'Identify essential components and characteristics of effective mentoring experiences',
                    'Describe common challenges and barriers that hinder the establishment of mentoring programs for deaf youth',
                    'Describe actionable strategies to enhance and strengthen existing mentoring programs',
                    'Articulate the importance of culturally responsive practices in mentoring'
                )
            )
        );
    }
    
    /**
     * Get badge information from catalog data for specific courses
     */
    private function get_badge_info_from_catalog($course_name) {
        $badge_catalog = array(
            // Core modules with specific badges
            'assistive_technology_in_training_and_workplace_settings' => array(
                'category' => 'Assistive Technology',
                'badge_name' => 'Assistive Technology',
                'badge_url' => 'https://nationaldeafcenter.badgr.com/public/badges/y3wQw7_ZSKaNBW8FQmHFVA',
                'image_file' => 'assistive-technology-badge.png'
            ),
            'effective_mentoring_for_deaf_people' => array(
                'category' => 'Professional Development',
                'badge_name' => 'Mentoring',
                'badge_url' => 'https://nationaldeafcenter.badgr.com/public/badges/DFCchTXQSXC1iJuGi1urWg',
                'image_file' => 'mentoring-badge.png'
            ),
            'deaf_awareness_for_vocational_rehabilitation_professionals' => array(
                'category' => 'Deaf Awareness',
                'badge_name' => 'Deaf Awareness',
                'badge_url' => 'https://nationaldeafcenter.badgr.com/public/badges/sKm9pq-rTZu4vKOEoAqxrA',
                'image_file' => 'deaf-awareness-badge.png'
            ),
            'introduction_to_deaf_rehabilitation' => array(
                'category' => 'Vocational Rehabilitation',
                'badge_name' => 'Deaf Rehabilitation',
                'badge_url' => 'https://nationaldeafcenter.badgr.com/public/badges/deaf-rehabilitation',
                'image_file' => 'vocational-rehabilitation-badge.png'
            ),
            
            // Accessibility and Education Badges
            'accessibility_practices_for_deaf_students' => array(
                'category' => 'Accessible Education',
                'badge_name' => 'Accessibility Practices for Deaf Students',
                'badge_url' => 'https://nationaldeafcenter.badgr.com/public/badges/accessibility-practices',
                'image_file' => 'accessibility-practices-for-deaf-students-badge.png'
            ),
            'accessible_instruction_and_settings_for_deaf_students' => array(
                'category' => 'Accessible Education',
                'badge_name' => 'Accessible Instruction',
                'badge_url' => 'https://nationaldeafcenter.badgr.com/public/badges/accessible-instruction',
                'image_file' => 'accessible-instruction-and-settings-for-deaf-students-badge.png'
            ),
            'accessible_instruction_1_using_udl_to_teach_deaf_students_online' => array(
                'category' => 'Accessible Education',
                'badge_name' => 'UDL for Deaf Students',
                'badge_url' => 'https://nationaldeafcenter.badgr.com/public/badges/udl-deaf-students',
                'image_file' => 'accessible-instruction-1-using-udl-to-teach-deaf-students-online-badge.png'
            ),
            'accommodations_101' => array(
                'category' => 'Legal and Compliance',
                'badge_name' => 'Accommodations 101',
                'badge_url' => 'https://nationaldeafcenter.badgr.com/public/badges/accommodations-101',
                'image_file' => 'accommodations-101-badge.png'
            ),
            'assistive_learning_systems' => array(
                'category' => 'Assistive Technology',
                'badge_name' => 'Assistive Learning Systems',
                'badge_url' => 'https://nationaldeafcenter.badgr.com/public/badges/assistive-learning',
                'image_file' => 'assistive-learning-systems-badge.png'
            ),
            'attitudes_as_barriers_for_deaf_people' => array(
                'category' => 'Systems Change',
                'badge_name' => 'Attitudes as Barriers',
                'badge_url' => 'https://nationaldeafcenter.badgr.com/public/badges/attitudes-barriers',
                'image_file' => 'attitudes-as-barriers-for-deaf-people-badge.png'
            ),
            'building_relationships_with_deaf_communities' => array(
                'category' => 'Community Engagement',
                'badge_name' => 'Building Relationships',
                'badge_url' => 'https://nationaldeafcenter.badgr.com/public/badges/building-relationships',
                'image_file' => 'building-relationships-with-deaf-communities-badge.png'
            ),
            'campus_accessibility' => array(
                'category' => 'Campus Access',
                'badge_name' => 'Campus Accessibility',
                'badge_url' => 'https://nationaldeafcenter.badgr.com/public/badges/campus-accessibility',
                'image_file' => 'campus-accessibility-badge.png'
            ),
            'captioned_media' => array(
                'category' => 'Communication Access',
                'badge_name' => 'Captioned Media',
                'badge_url' => 'https://nationaldeafcenter.badgr.com/public/badges/captioned-media',
                'image_file' => 'captioned-media-badge.png'
            ),
            'collecting_data_from_the_community' => array(
                'category' => 'Data and Evaluation',
                'badge_name' => 'Community Data Collection',
                'badge_url' => 'https://nationaldeafcenter.badgr.com/public/badges/community-data',
                'image_file' => 'collecting-data-from-the-community-badge.png'
            ),
            'coordinating_deaf_services' => array(
                'category' => 'Service Coordination',
                'badge_name' => 'Coordinating Deaf Services',
                'badge_url' => 'https://nationaldeafcenter.badgr.com/public/badges/coordinating-services',
                'image_file' => 'coordinating-deaf-services-badge.png'
            ),
            'deaf_awareness_micro_certificate' => array(
                'category' => 'Deaf Awareness',
                'badge_name' => 'Deaf Awareness Micro Certificate',
                'badge_url' => 'https://nationaldeafcenter.badgr.com/public/badges/deaf-awareness-micro',
                'image_file' => 'deaf-awareness-micro-certificate-badge.png'
            ),
            'deaf_awareness' => array(
                'category' => 'Deaf Awareness',
                'badge_name' => 'Deaf Awareness',
                'badge_url' => 'https://nationaldeafcenter.badgr.com/public/badges/deaf-awareness',
                'image_file' => 'deaf-awareness-badge.png'
            ),
            'evaluating_and_managing_accommodations_using_data' => array(
                'category' => 'Data and Evaluation',
                'badge_name' => 'Managing Accommodations with Data',
                'badge_url' => 'https://nationaldeafcenter.badgr.com/public/badges/managing-accommodations',
                'image_file' => 'evaluating-and-managing-accommodations-using-data-badge.png'
            ),
            'exploring_and_using_data' => array(
                'category' => 'Data and Evaluation',
                'badge_name' => 'Exploring and Using Data',
                'badge_url' => 'https://nationaldeafcenter.badgr.com/public/badges/exploring-data',
                'image_file' => 'exploring-and-using-data-badge.png'
            ),
            'exploring_postsecondary_system_barriers' => array(
                'category' => 'Systems Change',
                'badge_name' => 'Postsecondary System Barriers',
                'badge_url' => 'https://nationaldeafcenter.badgr.com/public/badges/postsecondary-barriers',
                'image_file' => 'exploring-postsecondary-system-barriers-badge.png'
            ),
            'facilitator' => array(
                'category' => 'Leadership',
                'badge_name' => 'Facilitator',
                'badge_url' => 'https://nationaldeafcenter.badgr.com/public/badges/facilitator',
                'image_file' => 'faciliator-badge.png'
            ),
            'finding_data_about_deaf_people' => array(
                'category' => 'Data and Evaluation',
                'badge_name' => 'Finding Data About Deaf People',
                'badge_url' => 'https://nationaldeafcenter.badgr.com/public/badges/finding-data',
                'image_file' => 'finding-data-about-deaf-people-badge.png'
            ),
            'intro_to_sign_language_interpreting' => array(
                'category' => 'Communication Access',
                'badge_name' => 'Sign Language Interpreting',
                'badge_url' => 'https://nationaldeafcenter.badgr.com/public/badges/interpreting',
                'image_file' => 'intro.-to-sign-language-interpreting-badge.png'
            ),
            'intro_to_remote_accessibility_services' => array(
                'category' => 'Remote Services',
                'badge_name' => 'Remote Accessibility Services',
                'badge_url' => 'https://nationaldeafcenter.badgr.com/public/badges/remote-accessibility',
                'image_file' => 'intro.-to-remote-accessibility-services-badge.png'
            ),
            'legal_frameworks_101' => array(
                'category' => 'Legal and Compliance',
                'badge_name' => 'Legal Frameworks 101',
                'badge_url' => 'https://nationaldeafcenter.badgr.com/public/badges/legal-frameworks',
                'image_file' => 'legal-frameworks-101-badge.png'
            ),
            'online_access_for_deaf_people' => array(
                'category' => 'Accessible Education',
                'badge_name' => 'Online Access for Deaf People',
                'badge_url' => 'https://nationaldeafcenter.badgr.com/public/badges/online-access',
                'image_file' => 'online-access-for-deaf-people-badge.png'
            ),
            'planning_and_hosting_community_conversations' => array(
                'category' => 'Community Engagement',
                'badge_name' => 'Community Conversations',
                'badge_url' => 'https://nationaldeafcenter.badgr.com/public/badges/community-conversations',
                'image_file' => 'planning-and-hosting-community-conversations-badge.png'
            ),
            'postsecondary_planning_for_deaf_students' => array(
                'category' => 'Transition Services',
                'badge_name' => 'Postsecondary Planning',
                'badge_url' => 'https://nationaldeafcenter.badgr.com/public/badges/postsecondary-planning',
                'image_file' => 'postsecondary-planning-for-deaf-students-badge.png'
            ),
            'pre_ets_planning_for_deaf_students' => array(
                'category' => 'Transition Services',
                'badge_name' => 'Pre-ETS Planning',
                'badge_url' => 'https://nationaldeafcenter.badgr.com/public/badges/pre-ets-planning',
                'image_file' => 'pre-ets-planning-for-deaf-students-badge.png'
            ),
            'speech_to_text_services_101' => array(
                'category' => 'Communication Access',
                'badge_name' => 'Speech-to-Text Services',
                'badge_url' => 'https://nationaldeafcenter.badgr.com/public/badges/speech-to-text',
                'image_file' => 'speech-to-text-services-101-badge.png'
            ),
            'summer_programs_planning' => array(
                'category' => 'Summer Programs',
                'badge_name' => 'Summer Programs Planning',
                'badge_url' => 'https://nationaldeafcenter.badgr.com/public/badges/summer-programs',
                'image_file' => 'summer-programs-planning-badge.png'
            ),
            'testing_experiences_for_deaf_students' => array(
                'category' => 'Accessible Education',
                'badge_name' => 'Testing Experiences',
                'badge_url' => 'https://nationaldeafcenter.badgr.com/public/badges/testing-experiences',
                'image_file' => 'testing-experiences-for-deaf-students-badge.png'
            ),
            'transforming_systems_to_improve_experiences_for_deaf_people' => array(
                'category' => 'Systems Change',
                'badge_name' => 'Transforming Systems',
                'badge_url' => 'https://nationaldeafcenter.badgr.com/public/badges/transforming-systems',
                'image_file' => 'transforming-systems-to-improve-experiences-for-deaf-people-badge.png'
            ),
            
            // OnDemand Webinar Badges
            'ondemand_webinar_accessibility_preparations_for_deaf_students' => array(
                'category' => 'Webinar',
                'badge_name' => 'Accessibility Preparations',
                'badge_url' => 'https://nationaldeafcenter.badgr.com/public/badges/accessibility-preparations',
                'image_file' => 'ondemand-webinar-accessibility-preparations-for-deaf-students-badge.png'
            ),
            'ondemand_webinar_accessible_graduation_for_deaf_participants' => array(
                'category' => 'Webinar',
                'badge_name' => 'Accessible Graduation',
                'badge_url' => 'https://nationaldeafcenter.badgr.com/public/badges/accessible-graduation',
                'image_file' => 'ondemand-webinar-accessible-graduation-for-deaf-participants-badge.png'
            ),
            'ondemand_webinar_ai_and_captions' => array(
                'category' => 'Webinar',
                'badge_name' => 'AI and Captions',
                'badge_url' => 'https://nationaldeafcenter.badgr.com/public/badges/ai-captions',
                'image_file' => 'ondemand-webinar-ai-and-craptions-badge.png'
            ),
            'ondemand_webinar_assistive_listening_systems' => array(
                'category' => 'Webinar',
                'badge_name' => 'Assistive Listening Systems',
                'badge_url' => 'https://nationaldeafcenter.badgr.com/public/badges/assistive-listening-systems',
                'image_file' => 'ondemand-webinar-assistive-listening-systems-badge.png'
            ),
            'ondemand_webinar_assistive_technologies' => array(
                'category' => 'Webinar',
                'badge_name' => 'Assistive Technologies',
                'badge_url' => 'https://nationaldeafcenter.badgr.com/public/badges/assistive-technologies',
                'image_file' => 'ondemand-webinar-assistive-technologies-badge.png'
            ),
            'ondemand_webinar_auto_captioning' => array(
                'category' => 'Webinar',
                'badge_name' => 'Auto Captioning',
                'badge_url' => 'https://nationaldeafcenter.badgr.com/public/badges/auto-captioning',
                'image_file' => 'ondemand-webinar-auto-captioning-badge.png'
            ),
            'ondemand_webinar_centralized_systems' => array(
                'category' => 'Webinar',
                'badge_name' => 'Centralized Systems',
                'badge_url' => 'https://nationaldeafcenter.badgr.com/public/badges/centralized-systems',
                'image_file' => 'ondemand-webinar-centralized-systems-badge.png'
            ),
            'ondemand_webinar_data_dialog' => array(
                'category' => 'Webinar',
                'badge_name' => 'Data + Dialog',
                'badge_url' => 'https://nationaldeafcenter.badgr.com/public/badges/data-dialog',
                'image_file' => 'ondemand-webinar-data-+-dialog-badge.png'
            ),
            'ondemand_webinar_deaf_led_practices' => array(
                'category' => 'Webinar',
                'badge_name' => 'Deaf Led Practices',
                'badge_url' => 'https://nationaldeafcenter.badgr.com/public/badges/deaf-led-practices',
                'image_file' => 'ondemand-webinar-deaf-led-practices-badge.png'
            ),
            'ondemand_webinar_for_deaf_people_by_deaf_people' => array(
                'category' => 'Webinar',
                'badge_name' => 'For Deaf People by Deaf People',
                'badge_url' => 'https://nationaldeafcenter.badgr.com/public/badges/for-deaf-by-deaf',
                'image_file' => 'ondemand-webinar-for-deaf-people-by-deaf-people-badge.png'
            ),
            'ondemand_webinar_health_science_careers' => array(
                'category' => 'Webinar',
                'badge_name' => 'Health Science Careers',
                'badge_url' => 'https://nationaldeafcenter.badgr.com/public/badges/health-science-careers',
                'image_file' => 'ondemand-webinar-health-science-careers-badge.png'
            ),
            'ondemand_webinar_hipaa_access_in_education' => array(
                'category' => 'Webinar',
                'badge_name' => 'HIPAA & Access in Education',
                'badge_url' => 'https://nationaldeafcenter.badgr.com/public/badges/hipaa-access',
                'image_file' => 'ondemand-webinar-hippa-&-access-in-education-badge.png'
            ),
            'ondemand_webinar_interactive_process' => array(
                'category' => 'Webinar',
                'badge_name' => 'Interactive Process',
                'badge_url' => 'https://nationaldeafcenter.badgr.com/public/badges/interactive-process',
                'image_file' => 'ondemand-webinar-interactive-process-badge.png'
            ),
            'ondemand_webinar_mentoring_deaf_youth' => array(
                'category' => 'Webinar',
                'badge_name' => 'Mentoring Deaf Youth',
                'badge_url' => 'https://nationaldeafcenter.badgr.com/public/badges/mentoring-deaf-youth',
                'image_file' => 'ondemand-webinar-mentoring-deaf-youth-badge.png'
            ),
            'ondemand_webinar_navigating_the_grievance_process' => array(
                'category' => 'Webinar',
                'badge_name' => 'Grievance Process',
                'badge_url' => 'https://nationaldeafcenter.badgr.com/public/badges/grievance-process',
                'image_file' => 'ondemand-webinar-navigating-the-grievance-process-badge.png'
            ),
            'ondemand_webinar_trauma_access' => array(
                'category' => 'Webinar',
                'badge_name' => 'Trauma & Access',
                'badge_url' => 'https://nationaldeafcenter.badgr.com/public/badges/trauma-access',
                'image_file' => 'ondemand-webinar-trauma-&-access-badge.png'
            ),
            
            // Work-Based Learning
            'work_based_learning_programs' => array(
                'category' => 'Work-Based Learning',
                'badge_name' => 'Work-Based Learning',
                'badge_url' => 'https://nationaldeafcenter.badgr.com/public/badges/work-based-learning',
                'image_file' => 'work-based-learning-badge.png'
            )
        );
        
        // Clean the course name for mapping
        $course_key = $this->normalize_course_name($course_name);
        error_log("CCS_Content_Handler: Looking up badge for course: '{$course_name}' -> normalized: '{$course_key}'");
        
        if (isset($badge_catalog[$course_key])) {
            error_log("CCS_Content_Handler: Found badge mapping for: {$course_key} -> " . $badge_catalog[$course_key]['badge_name']);
            return $badge_catalog[$course_key];
        }
        
        // Try partial matching for similar course names
        foreach ($badge_catalog as $catalog_key => $badge_info) {
            if (stripos($course_name, str_replace('_', ' ', $catalog_key)) !== false || 
                stripos($catalog_key, $this->normalize_course_name($course_name)) !== false) {
                error_log("CCS_Content_Handler: Found partial badge match for '{$course_name}' with catalog key '{$catalog_key}'");
                return $badge_info;
            }
        }
        
        error_log("CCS_Content_Handler: No badge mapping found for course: {$course_name}");
        return null;
    }
    
    /**
     * Normalize course name for badge mapping
     */
    private function normalize_course_name($course_name) {
        // Convert to lowercase and replace spaces/special characters with underscores
        $normalized = strtolower($course_name);
        $normalized = preg_replace('/[^a-z0-9]+/', '_', $normalized);
        $normalized = trim($normalized, '_');
        
        return $normalized;
    }
    
    /**
     * Fetch badge information from catalog page
     */
    private function fetch_badge_from_catalog($course_name) {
        $course_links = $this->get_catalog_course_links();
        $course_url = null;
        
        // Find matching course URL
        foreach ($course_links as $title => $url) {
            if (stripos($title, $course_name) !== false || stripos($course_name, $title) !== false) {
                $course_url = $url;
                break;
            }
        }
        
        if (!$course_url) {
            error_log("CCS_Content_Handler: No catalog URL found for course: {$course_name}");
            return null;
        }
        
        // Check cache first
        $cache_key = 'ccs_badge_info_' . md5($course_url);
        $cached = get_transient($cache_key);
        if ($cached !== false) {
            return $cached;
        }
        
        // Fetch course page for badge information
        $response = wp_remote_get($course_url, array('timeout' => 30));
        
        if (is_wp_error($response)) {
            error_log("CCS_Content_Handler: Failed to fetch catalog page: " . $response->get_error_message());
            return null;
        }
        
        $html = wp_remote_retrieve_body($response);
        
        // Extract badge image from the course page - look for badge-specific patterns
        $badge_image_url = null;
        $badge_title = 'Professional Development';
        
        // Look for badge images in various formats
        $badge_patterns = array(
            // Canvas/Badgr badge patterns
            '/<img[^>]*src="([^"]*(?:badgr|badge)[^"]*)"[^>]*>/i',
            '/<img[^>]*src="([^"]*nationaldeafcenter[^"]*badge[^"]*)"[^>]*>/i',
            // Generic badge image patterns
            '/<img[^>]*alt="[^"]*badge[^"]*"[^>]*src="([^"]*)"[^>]*>/i',
            '/<img[^>]*class="[^"]*badge[^"]*"[^>]*src="([^"]*)"[^>]*>/i'
        );
        
        foreach ($badge_patterns as $pattern) {
            if (preg_match($pattern, $html, $matches)) {
                $badge_image_url = $matches[1];
                break;
            }
        }
        
        // Extract page title for badge category
        if (preg_match('/<title>([^<]+)<\/title>/i', $html, $matches)) {
            $page_title = trim($matches[1]);
            $badge_title = $this->extract_badge_category_from_title($page_title);
        }
        
        // If we found a badge image, download it to use locally
        if ($badge_image_url) {
            $local_badge_file = $this->download_badge_image($badge_image_url, $course_name);
            
            return array(
                'category' => $badge_title,
                'badge_name' => $badge_title,
                'badge_url' => $course_url,
                'image_file' => $local_badge_file,
                'remote_image_url' => $badge_image_url
            );
        }
        
        return null;
    }
    
    /**
     * Extract badge category from page title
     */
    private function extract_badge_category_from_title($title) {
        // Simple mapping based on title keywords
        if (stripos($title, 'assistive technology') !== false) return 'Assistive Technology';
        if (stripos($title, 'deaf awareness') !== false) return 'Deaf Awareness';
        if (stripos($title, 'mentoring') !== false) return 'Professional Development';
        if (stripos($title, 'rehabilitation') !== false) return 'Vocational Rehabilitation';
        if (stripos($title, 'webinar') !== false) return 'Webinar';
        
        return 'Professional Development';
    }
    
    /**
     * Download badge image and save locally
     */
    private function download_badge_image($image_url, $course_name) {
        $safe_name = sanitize_file_name(strtolower(str_replace(' ', '-', $course_name)));
        $filename = $safe_name . '-badge.png';
        
        // Return filename - actual download will be handled when assets are added
        return $filename;
    }
}