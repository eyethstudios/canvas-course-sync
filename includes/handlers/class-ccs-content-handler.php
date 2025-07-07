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
            return '';
        }

        // Try to fetch catalog content first
        $catalog_content = $this->fetch_catalog_content($course_details);
        
        if (!empty($catalog_content)) {
            return $catalog_content;
        }

        // Fallback to generic content if catalog fetch fails
        $content = '';

        // Build simple course content sections
        // 1. Module Description
        $content .= $this->build_module_description($course_details);
        
        // 2. Learning Objectives
        $content .= $this->build_learning_objectives($course_details);
        
        // 3. Continuing Education Credit
        $content .= $this->build_continuing_education_credit($course_details);

        return $content;
    }

    /**
     * Fetch content from catalog listing
     * 
     * @param array $course_details Course details from Canvas API
     * @return string Catalog content HTML or empty string if failed
     */
    private function fetch_catalog_content($course_details) {
        $course_name = $course_details['name'] ?? '';
        
        if (empty($course_name)) {
            return '';
        }

        // Generate catalog URL from course name
        $catalog_url = $this->generate_catalog_url($course_name);
        
        if (empty($catalog_url)) {
            return '';
        }

        // Try to fetch catalog page with the generated URL
        $response = wp_remote_get($catalog_url, array(
            'timeout' => 30,
            'headers' => array(
                'User-Agent' => 'Canvas Course Sync Plugin'
            )
        ));

        if (is_wp_error($response)) {
            if ($this->logger) {
                $this->logger->log('Failed to fetch catalog content from ' . $catalog_url . ': ' . $response->get_error_message());
            }
            return $this->try_fallback_urls($course_name);
        }

        // Check HTTP response code
        $response_code = wp_remote_retrieve_response_code($response);
        if ($response_code !== 200) {
            if ($this->logger) {
                $this->logger->log('Received HTTP ' . $response_code . ' for catalog URL: ' . $catalog_url);
            }
            return $this->try_fallback_urls($course_name);
        }

        $body = wp_remote_retrieve_body($response);
        $parsed_content = $this->parse_catalog_content($body);
        
        // If we got a valid response but no content, log it
        if (empty($parsed_content) && $this->logger) {
            $this->logger->log('Successfully fetched catalog page but could not extract content from: ' . $catalog_url);
        }
        
        return $parsed_content;
    }

    /**
     * Generate catalog URL from course name
     * 
     * @param string $course_name Course name
     * @return string Catalog URL or empty string
     */
    private function generate_catalog_url($course_name) {
        // Convert course name to URL slug
        $slug = sanitize_title($course_name);
        
        if (empty($slug)) {
            if ($this->logger) {
                $this->logger->log('Failed to generate slug for course: ' . $course_name);
            }
            return '';
        }

        // Handle special cases and common variations
        $slug = $this->normalize_course_slug($slug);
        
        $catalog_url = 'https://learn.nationaldeafcenter.org/courses/' . $slug;
        
        if ($this->logger) {
            $this->logger->log('Generated catalog URL for "' . $course_name . '": ' . $catalog_url);
        }
        
        return $catalog_url;
    }

    /**
     * Normalize course slug to match catalog URL patterns
     * 
     * @param string $slug Generated slug
     * @return string Normalized slug
     */
    private function normalize_course_slug($slug) {
        // Handle common variations and ensure consistent formatting
        $normalizations = array(
            // Common word replacements
            'vr-professionals' => 'vocational-rehabilitation-professionals',
            'deaf-awareness-for-vr' => 'deaf-awareness-for-vocational-rehabilitation-professionals',
            'assistive-tech' => 'assistive-technology',
            'work-based-learning-experiences' => 'work-based-learning',
            // Add more normalizations as needed
        );
        
        // Apply normalizations
        foreach ($normalizations as $pattern => $replacement) {
            if (strpos($slug, $pattern) !== false) {
                $slug = str_replace($pattern, $replacement, $slug);
                break;
            }
        }
        
        return $slug;
    }

    /**
     * Parse catalog content from HTML
     * 
     * @param string $html HTML content from catalog page
     * @return string Parsed content HTML
     */
    private function parse_catalog_content($html) {
        if (empty($html)) {
            return '';
        }

        // Create DOMDocument to parse HTML
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        libxml_clear_errors();
        
        $xpath = new DOMXPath($dom);
        $content = '';

        // Look for the main content div that contains course information
        $content_nodes = $xpath->query("//div[contains(@class, 'css-8no1sn-text')]");
        
        if ($content_nodes->length > 0) {
            $main_content = $content_nodes->item(0);
            
            // Get the raw HTML content
            $raw_content = '';
            foreach ($main_content->childNodes as $child) {
                $raw_content .= $dom->saveHTML($child);
            }
            
            // Clean up and process the content
            $content = $this->process_catalog_html_content($raw_content);
        }

        // If no content found, fallback to old extraction method
        if (empty($content)) {
            // Extract Module Description
            $module_desc = $this->extract_module_description($xpath);
            if (!empty($module_desc)) {
                $content .= "<div class='module-description'>\n";
                $content .= "<h2>Module Description</h2>\n";
                $content .= "<p>" . wp_kses_post($module_desc) . "</p>\n";
                $content .= "</div>\n\n";
            }

            // Extract Learning Objectives
            $learning_objectives = $this->extract_learning_objectives($xpath);
            if (!empty($learning_objectives)) {
                $content .= "<div class='learning-objectives'>\n";
                $content .= "<h2>Learning Objectives</h2>\n";
                $content .= "<p><strong>Participants will be able to:</strong></p>\n";
                $content .= "<ul>\n";
                foreach ($learning_objectives as $objective) {
                    $content .= "<li>" . wp_kses_post($objective) . "</li>\n";
                }
                $content .= "</ul>\n";
                $content .= "</div>\n\n";
            }

            // Add Continuing Education Credit section
            $content .= $this->build_continuing_education_credit(array());
        }

        if ($this->logger && !empty($content)) {
            $this->logger->log('Successfully parsed catalog content, length: ' . strlen($content));
        }

        return $content;
    }

    /**
     * Process raw HTML content from catalog
     * 
     * @param string $raw_content Raw HTML content
     * @return string Processed content HTML
     */
    private function process_catalog_html_content($raw_content) {
        if (empty($raw_content)) {
            return '';
        }

        // Clean up the HTML and format it properly
        $content = wp_kses_post($raw_content);
        
        // Remove badge information paragraph (contains "Badge information:")
        $content = preg_replace('/<p[^>]*><strong>Badge information:.*?<\/p>/s', '', $content);
        
        // Remove any empty paragraphs with just &nbsp;
        $content = preg_replace('/<p[^>]*>\s*&nbsp;\s*<\/p>/s', '', $content);
        
        // Ensure proper spacing between sections
        $content = preg_replace('/(<\/p>)\s*(<p><strong>)/s', '$1' . "\n\n" . '$2', $content);
        
        return trim($content);
    }

    /**
     * Try fallback URLs when the primary URL fails
     * 
     * @param string $course_name Course name
     * @return string Parsed content HTML or empty string
     */
    private function try_fallback_urls($course_name) {
        if ($this->logger) {
            $this->logger->log('Trying fallback URLs for course: ' . $course_name);
        }
        
        // Generate alternative slug patterns
        $fallback_slugs = $this->generate_fallback_slugs($course_name);
        
        foreach ($fallback_slugs as $slug) {
            $fallback_url = 'https://learn.nationaldeafcenter.org/courses/' . $slug;
            
            if ($this->logger) {
                $this->logger->log('Trying fallback URL: ' . $fallback_url);
            }
            
            $response = wp_remote_get($fallback_url, array(
                'timeout' => 30,
                'headers' => array(
                    'User-Agent' => 'Canvas Course Sync Plugin'
                )
            ));
            
            if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
                $body = wp_remote_retrieve_body($response);
                $content = $this->parse_catalog_content($body);
                
                if (!empty($content)) {
                    if ($this->logger) {
                        $this->logger->log('Successfully found content using fallback URL: ' . $fallback_url);
                    }
                    return $content;
                }
            }
        }
        
        if ($this->logger) {
            $this->logger->log('All fallback URLs failed for course: ' . $course_name);
        }
        
        return '';
    }

    /**
     * Generate fallback slug variations
     * 
     * @param string $course_name Course name
     * @return array Array of fallback slugs
     */
    private function generate_fallback_slugs($course_name) {
        $slugs = array();
        $base_slug = sanitize_title($course_name);
        
        // Try common variations
        $variations = array(
            // Remove common words
            str_replace(array('-for-', '-and-', '-the-', '-in-', '-on-', '-of-'), '-', $base_slug),
            // Shortened versions
            str_replace('vocational-rehabilitation', 'vr', $base_slug),
            str_replace('assistive-technology', 'assistive-tech', $base_slug),
            str_replace('professionals', 'pros', $base_slug),
            // Different word orders for multi-word titles
            $this->reverse_slug_words($base_slug),
        );
        
        // Remove duplicates and empty values
        $slugs = array_unique(array_filter($variations));
        
        return $slugs;
    }

    /**
     * Reverse word order in slug for alternative matching
     * 
     * @param string $slug Original slug
     * @return string Reversed slug
     */
    private function reverse_slug_words($slug) {
        $words = explode('-', $slug);
        if (count($words) > 2) {
            return implode('-', array_reverse($words));
        }
        return $slug;
    }

    /**
     * Extract module description from catalog HTML
     * 
     * @param DOMXPath $xpath XPath object
     * @return string Module description or empty string
     */
    private function extract_module_description($xpath) {
        // Look for "Module Description:" text
        $desc_nodes = $xpath->query("//*[contains(text(), 'Module Description:')]");
        
        if ($desc_nodes->length > 0) {
            $parent = $desc_nodes->item(0)->parentNode;
            // Get the text content after "Module Description:"
            $full_text = $parent->textContent;
            $pos = strpos($full_text, 'Module Description:');
            if ($pos !== false) {
                $desc_text = substr($full_text, $pos + strlen('Module Description:'));
                // Clean up and trim
                $desc_text = trim($desc_text);
                // Remove any learning objectives that might be included
                $obj_pos = strpos($desc_text, 'Learning objectives');
                if ($obj_pos !== false) {
                    $desc_text = substr($desc_text, 0, $obj_pos);
                }
                return trim($desc_text);
            }
        }

        return '';
    }

    /**
     * Extract learning objectives from catalog HTML
     * 
     * @param DOMXPath $xpath XPath object
     * @return array Array of learning objectives
     */
    private function extract_learning_objectives($xpath) {
        $objectives = array();
        
        // Look for "Learning objectives" text
        $obj_nodes = $xpath->query("//*[contains(text(), 'Learning objectives')]");
        
        if ($obj_nodes->length > 0) {
            $parent = $obj_nodes->item(0)->parentNode;
            $full_text = $parent->textContent;
            
            // Find the start of objectives list
            $start_pos = strpos($full_text, 'Participants will be able to:');
            if ($start_pos !== false) {
                $objectives_text = substr($full_text, $start_pos + strlen('Participants will be able to:'));
                
                // Split by bullet points or line breaks
                $lines = preg_split('/\n|\r|\•|-/', $objectives_text);
                
                foreach ($lines as $line) {
                    $line = trim($line);
                    if (!empty($line) && strlen($line) > 10) {
                        // Clean up the objective text
                        $line = preg_replace('/^\s*[\•\-\*]\s*/', '', $line);
                        $objectives[] = trim($line);
                    }
                }
            }
        }

        return $objectives;
    }

    /**
     * Build module description section
     */
    private function build_module_description($course_details) {
        $content = '';
        
        $content .= "<div class='module-description'>\n";
        $content .= "<h2>Module Description</h2>\n";
        
        // Get description from course details
        $description = '';
        
        // Try different description fields
        if (!empty($course_details['syllabus_body'])) {
            $description = $course_details['syllabus_body'];
        } elseif (!empty($course_details['public_description'])) {
            $description = $course_details['public_description'];
        } elseif (!empty($course_details['description'])) {
            $description = $course_details['description'];
        }
        
        // Add the description content
        if (!empty($description)) {
            $content .= wp_kses_post($description);
        } else {
            $course_name = $course_details['name'] ?? 'this course';
            $content .= "<p>This module provides comprehensive training on " . esc_html($course_name) . ".</p>";
        }
        
        $content .= "</div>\n\n";
        
        return $content;
    }

    /**
     * Build learning objectives section
     */
    private function build_learning_objectives($course_details) {
        $content = '';
        
        $content .= "<div class='learning-objectives'>\n";
        $content .= "<h2>Learning Objectives</h2>\n";
        $content .= "<p><strong>Participants will be able to:</strong></p>\n";
        $content .= "<ul>\n";
        
        // Provide course-specific default objectives
        $course_name = strtolower($course_details['name'] ?? '');
        if (strpos($course_name, 'assistive technology') !== false) {
            $content .= "<li>Demonstrate knowledge of assistive technologies and their relevance to supporting deaf individuals</li>\n";
            $content .= "<li>Identify actionable strategies for implementing assistive technologies in training and workplace settings</li>\n";
            $content .= "<li>Develop strategies for creating accessible environments where deaf individuals feel valued and supported</li>\n";
            $content .= "<li>Create and evaluate policies that promote accessibility and support continuous improvement</li>\n";
        } elseif (strpos($course_name, 'deaf awareness') !== false) {
            $content .= "<li>Understand deaf culture and the deaf community</li>\n";
            $content .= "<li>Recognize the importance of accessibility in vocational rehabilitation</li>\n";
            $content .= "<li>Identify effective communication strategies with deaf individuals</li>\n";
            $content .= "<li>Develop culturally competent practices in service delivery</li>\n";
        } elseif (strpos($course_name, 'mentoring') !== false) {
            $content .= "<li>Understand the principles of effective mentoring for deaf individuals</li>\n";
            $content .= "<li>Identify strategies for building meaningful mentor-mentee relationships</li>\n";
            $content .= "<li>Develop communication techniques appropriate for deaf mentees</li>\n";
            $content .= "<li>Create supportive environments that promote growth and development</li>\n";
        } else {
            $content .= "<li>Understand the key concepts and principles covered in this course</li>\n";
            $content .= "<li>Apply learned skills in practical scenarios</li>\n";
            $content .= "<li>Demonstrate proficiency in the course subject matter</li>\n";
        }
        
        $content .= "</ul>\n";
        $content .= "</div>\n\n";
        
        return $content;
    }

    /**
     * Build continuing education credit section
     */
    private function build_continuing_education_credit($course_details) {
        $content = '';
        
        $content .= "<div class='continuing-education-credit'>\n";
        $content .= "<h2>Continuing Education Credit</h2>\n";
        
        // Default credit information
        $content .= "<p>This course is designed to provide professional development opportunities for individuals working with deaf and hard-of-hearing populations.</p>\n";
        $content .= "<p>Upon successful completion of this course, participants will receive a certificate of completion.</p>\n";
        
        $content .= "</div>\n\n";
        
        return $content;
    }
}