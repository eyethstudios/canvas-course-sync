
<?php
/**
 * Course Slug Generator for Canvas Course Sync
 * Handles proper URL slug generation with verification
 *
 * @package Canvas_Course_Sync
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class CCS_Slug_Generator {
    
    /**
     * Logger instance
     */
    private $logger;
    
    /**
     * Constructor
     */
    public function __construct() {
        $canvas_course_sync = canvas_course_sync();
        if ($canvas_course_sync && isset($canvas_course_sync->logger)) {
            $this->logger = $canvas_course_sync->logger;
        }
        
        error_log('CCS_Slug_Generator: Initialized at ' . current_time('mysql'));
    }
    
    /**
     * Generate URL slug from course title with comprehensive logging
     *
     * @param string $title Course title
     * @param int $course_id Canvas course ID for uniqueness
     * @return array Slug generation result
     */
    public function generate_course_slug($title, $course_id = 0) {
        error_log('CCS_Slug_Generator: generate_course_slug() called at ' . current_time('mysql'));
        error_log('CCS_Slug_Generator: Input title: "' . $title . '"');
        error_log('CCS_Slug_Generator: Input course_id: ' . $course_id);
        
        if (empty($title)) {
            error_log('CCS_Slug_Generator: ERROR - Empty title provided');
            return array(
                'success' => false,
                'slug' => 'course-' . $course_id,
                'error' => 'Empty title provided',
                'fallback_used' => true
            );
        }
        
        // Step 1: Basic sanitization
        $slug = strtolower(trim($title));
        error_log('CCS_Slug_Generator: Step 1 - Lowercase: "' . $slug . '"');
        
        // Step 2: Remove special characters except spaces and hyphens
        $slug = preg_replace('/[^a-z0-9\s\-]/', '', $slug);
        error_log('CCS_Slug_Generator: Step 2 - Remove special chars: "' . $slug . '"');
        
        // Step 3: Replace multiple spaces/hyphens with single hyphen
        $slug = preg_replace('/[\s\-]+/', '-', $slug);
        error_log('CCS_Slug_Generator: Step 3 - Replace spaces: "' . $slug . '"');
        
        // Step 4: Trim leading/trailing hyphens
        $slug = trim($slug, '-');
        error_log('CCS_Slug_Generator: Step 4 - Trim hyphens: "' . $slug . '"');
        
        // Step 5: Ensure minimum length and not empty
        if (empty($slug) || strlen($slug) < 3) {
            $slug = 'course-' . $course_id;
            error_log('CCS_Slug_Generator: Step 5 - Fallback slug used: "' . $slug . '"');
            $fallback_used = true;
        } else {
            $fallback_used = false;
        }
        
        // Step 6: Check for uniqueness
        $original_slug = $slug;
        $slug = $this->ensure_unique_slug($slug, $course_id);
        
        if ($slug !== $original_slug) {
            error_log('CCS_Slug_Generator: Slug made unique: "' . $original_slug . '" -> "' . $slug . '"');
        }
        
        // Step 7: Final validation
        if (!$this->validate_slug($slug)) {
            error_log('CCS_Slug_Generator: ERROR - Generated slug failed validation: "' . $slug . '"');
            $slug = 'course-' . $course_id . '-' . time();
            error_log('CCS_Slug_Generator: Using timestamp fallback: "' . $slug . '"');
            $fallback_used = true;
        }
        
        $result = array(
            'success' => true,
            'slug' => $slug,
            'original_title' => $title,
            'fallback_used' => $fallback_used,
            'steps' => array(
                'input' => $title,
                'lowercase' => strtolower(trim($title)),
                'sanitized' => $original_slug,
                'final' => $slug
            )
        );
        
        error_log('CCS_Slug_Generator: Final result: ' . print_r($result, true));
        
        if ($this->logger) {
            $this->logger->log('Generated slug for "' . $title . '": "' . $slug . '"' . ($fallback_used ? ' (fallback used)' : ''));
        }
        
        return $result;
    }
    
    /**
     * Ensure slug uniqueness across WordPress and tracking table
     */
    private function ensure_unique_slug($slug, $course_id = 0) {
        global $wpdb;
        
        error_log('CCS_Slug_Generator: Checking slug uniqueness: "' . $slug . '"');
        
        $original_slug = $slug;
        $counter = 1;
        
        while ($this->slug_exists($slug, $course_id)) {
            $slug = $original_slug . '-' . $counter;
            $counter++;
            error_log('CCS_Slug_Generator: Slug exists, trying: "' . $slug . '"');
            
            // Prevent infinite loop
            if ($counter > 100) {
                $slug = $original_slug . '-' . time();
                error_log('CCS_Slug_Generator: Counter limit reached, using timestamp: "' . $slug . '"');
                break;
            }
        }
        
        return $slug;
    }
    
    /**
     * Check if slug already exists
     */
    private function slug_exists($slug, $exclude_course_id = 0) {
        global $wpdb;
        
        // Check WordPress posts
        $wp_exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} 
             WHERE post_name = %s 
             AND post_type = 'courses' 
             AND post_status != 'trash'",
            $slug
        ));
        
        if ($wp_exists > 0) {
            error_log('CCS_Slug_Generator: Slug exists in WordPress posts: "' . $slug . '"');
            return true;
        }
        
        // Check tracking table
        $tracking_table = $wpdb->prefix . 'ccs_course_tracking';
        $tracking_exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $tracking_table 
             WHERE course_slug = %s" . 
             ($exclude_course_id > 0 ? " AND canvas_course_id != %d" : ""),
            $exclude_course_id > 0 ? array($slug, $exclude_course_id) : array($slug)
        ));
        
        if ($tracking_exists > 0) {
            error_log('CCS_Slug_Generator: Slug exists in tracking table: "' . $slug . '"');
            return true;
        }
        
        return false;
    }
    
    /**
     * Validate generated slug
     */
    private function validate_slug($slug) {
        // Check basic requirements
        if (empty($slug)) {
            return false;
        }
        
        if (strlen($slug) < 3 || strlen($slug) > 200) {
            return false;
        }
        
        // Check format
        if (!preg_match('/^[a-z0-9\-]+$/', $slug)) {
            return false;
        }
        
        // Check for reserved words
        $reserved_words = array('admin', 'api', 'www', 'mail', 'ftp', 'localhost', 'course', 'courses');
        if (in_array($slug, $reserved_words)) {
            return false;
        }
        
        return true;
    }
    
    /**
     * Generate full enrollment URL
     */
    public function generate_enrollment_url($slug) {
        error_log('CCS_Slug_Generator: generate_enrollment_url() called with slug: "' . $slug . '"');
        
        $base_url = 'https://learn.nationaldeafcenter.org/courses/';
        $full_url = $base_url . $slug;
        
        error_log('CCS_Slug_Generator: Generated enrollment URL: "' . $full_url . '"');
        
        // Validate URL
        if (!filter_var($full_url, FILTER_VALIDATE_URL)) {
            error_log('CCS_Slug_Generator: ERROR - Generated URL failed validation: "' . $full_url . '"');
            return false;
        }
        
        return $full_url;
    }
    
    /**
     * Verify slug generation is working
     */
    public function verify_slug_generation() {
        error_log('CCS_Slug_Generator: Running slug generation verification...');
        
        $test_cases = array(
            'Effective Mentoring for Deaf People',
            'ASL Grammar & Linguistics 101',
            'Special Characters !@#$%^&*()',
            '   Leading and Trailing Spaces   ',
            'Multiple    Spaces    Between    Words',
            'Hyphens-Already-Present',
            ''
        );
        
        $results = array();
        
        foreach ($test_cases as $index => $test_title) {
            $result = $this->generate_course_slug($test_title, 1000 + $index);
            $results[] = array(
                'input' => $test_title,
                'output' => $result['slug'],
                'success' => $result['success'],
                'fallback_used' => $result['fallback_used'] ?? false
            );
            
            error_log('CCS_Slug_Generator: Test "' . $test_title . '" -> "' . $result['slug'] . '"');
        }
        
        return $results;
    }
}
