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
        
        // First try to get description from Canvas course fields
        $description = '';
        
        // Priority 1: syllabus_body (most comprehensive content)
        if (!empty($course_details['syllabus_body'])) {
            $description = $course_details['syllabus_body'];
            error_log('CCS_Content_Handler: Using syllabus_body for module description (length: ' . strlen($description) . ')');
        }
        // Priority 2: public_description
        elseif (!empty($course_details['public_description'])) {
            $description = $course_details['public_description'];
            error_log('CCS_Content_Handler: Using public_description for module description (length: ' . strlen($description) . ')');
        }
        // Priority 3: regular description
        elseif (!empty($course_details['description'])) {
            $description = $course_details['description'];
            error_log('CCS_Content_Handler: Using description field for module description (length: ' . strlen($description) . ')');
        }
        
        // If no Canvas description, try catalog backup first
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
        
        $objectives = array();
        
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
                        if (strlen($clean_text) > 20) {
                            $objectives[] = $clean_text;
                        }
                    }
                }
            }
        }
        
        // If no objectives section found, try to extract list items that look like objectives
        if (empty($objectives)) {
            if (preg_match_all('/<li[^>]*>(.*?)<\/li>/is', $html_content, $matches)) {
                foreach ($matches[1] as $match) {
                    $clean_text = trim(wp_strip_all_tags($match));
                    
                    // Check if this looks like a learning objective
                    if (strlen($clean_text) > 30 && 
                        strlen($clean_text) < 500 &&
                        stripos($clean_text, 'badge') === false &&
                        stripos($clean_text, 'completion') === false &&
                        stripos($clean_text, 'certificate') === false &&
                        (stripos($clean_text, 'demonstrate') !== false ||
                         stripos($clean_text, 'identify') !== false ||
                         stripos($clean_text, 'develop') !== false ||
                         stripos($clean_text, 'create') !== false ||
                         stripos($clean_text, 'understand') !== false ||
                         stripos($clean_text, 'apply') !== false ||
                         stripos($clean_text, 'analyze') !== false ||
                         stripos($clean_text, 'evaluate') !== false)) {
                        
                        $objectives[] = $clean_text;
                    }
                }
            }
        }
        
        // If still no objectives, look for objective patterns in plain text
        if (empty($objectives)) {
            $text = strip_tags($html_content);
            $patterns = array(
                '/(?:participants will be able to:|students will be able to:|learners will:|upon completion.*?will)\s*(.+?)(?=\n\n|\.|$)/is',
                '/(?:objective \d+:|learning objective:)\s*([^\.]+\.)/i',
                '/(?:•|\*|-)\s*([A-Z][^\.]{20,400}\.)/m'
            );
            
            foreach ($patterns as $pattern) {
                if (preg_match_all($pattern, $text, $matches)) {
                    foreach ($matches[1] as $match) {
                        $clean_text = trim($match);
                        if (!empty($clean_text)) {
                            // Split by periods or line breaks to get individual objectives
                            $split_objectives = preg_split('/[\.]\s*(?=[A-Z])|[\n]\s*[-•*]/', $clean_text);
                            foreach ($split_objectives as $obj) {
                                $obj = trim($obj);
                                if (strlen($obj) > 20 && strlen($obj) < 400) {
                                    $objectives[] = $obj;
                                }
                            }
                        }
                    }
                }
                
                if (!empty($objectives)) {
                    break;
                }
            }
        }
        
        // Clean up and return unique objectives
        $objectives = array_unique($objectives);
        $objectives = array_filter($objectives, function($obj) {
            return strlen(trim($obj)) > 20;
        });
        
        error_log('CCS_Content_Handler: Extracted ' . count($objectives) . ' objectives from content');
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
        $content .= "<p style='text-align: center; font-weight: bold;'>" . esc_html($course_name) . "</p>\n";
        $content .= "<p style='text-align: center;'>Issued by: National Deaf Center</p>\n";
        $content .= "<p style='text-align: center; margin-top: 10px;'><a href='https://nationaldeafcenter.badgr.com/public/organization/badges' target='_blank'>Learn more about NDC Badges here.</a></p>\n";
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
     * Normalize course name for catalog lookup
     */
    private function normalize_course_name($course_name) {
        return strtolower(preg_replace('/[^a-zA-Z0-9]/', '_', trim($course_name)));
    }
}