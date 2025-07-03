
<?php
/**
 * Canvas Course Sync Scheduler
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class CCS_Scheduler {
    /**
     * Logger instance
     *
     * @var CCS_Logger
     */
    private $logger;

    /**
     * Canvas API instance
     *
     * @var CCS_Canvas_API
     */
    private $api;

    /**
     * Importer instance
     *
     * @var CCS_Importer
     */
    private $importer;

    /**
     * Database manager instance
     *
     * @var CCS_Database_Manager
     */
    private $db_manager;

    /**
     * Constructor with dependency injection
     *
     * @param CCS_Logger|null $logger Logger instance (optional)
     * @param CCS_Canvas_API|null $api Canvas API instance (optional)
     * @param CCS_Importer|null $importer Importer instance (optional)
     */
    public function __construct(CCS_Logger $logger = null, CCS_Canvas_API $api = null, CCS_Importer $importer = null) {
        $this->logger = $logger ?: new CCS_Logger();
        $this->api = $api ?: new CCS_Canvas_API($this->logger);
        $this->importer = $importer;
        
        // Schedule weekly sync if enabled
        add_action('ccs_weekly_sync', array($this, 'run_auto_sync'));
        add_action('wp', array($this, 'schedule_auto_sync'));
        
        // Hook to clear schedule on deactivation
        register_deactivation_hook(CCS_PLUGIN_FILE, array($this, 'clear_scheduled_sync'));
    }

    /**
     * Schedule the weekly auto-sync
     */
    public function schedule_auto_sync() {
        if (!wp_next_scheduled('ccs_weekly_sync') && get_option('ccs_auto_sync_enabled')) {
            wp_schedule_event(time(), 'weekly', 'ccs_weekly_sync');
            $this->logger->log('Weekly auto-sync scheduled');
        } elseif (wp_next_scheduled('ccs_weekly_sync') && !get_option('ccs_auto_sync_enabled')) {
            wp_clear_scheduled_hook('ccs_weekly_sync');
            $this->logger->log('Weekly auto-sync unscheduled');
        }
    }

    /**
     * Clear scheduled sync
     */
    public function clear_scheduled_sync() {
        wp_clear_scheduled_hook('ccs_weekly_sync');
        $this->logger->log('Scheduled sync cleared');
    }

    /**
     * Run automatic sync for new courses only
     */
    public function run_auto_sync() {
        $this->logger->log('Starting automatic sync process');
        
        try {
            // Get all courses from Canvas
            $canvas_courses = $this->api->get_courses();
            
            if (is_wp_error($canvas_courses)) {
                $this->logger->log('Failed to get courses from Canvas: ' . $canvas_courses->get_error_message(), 'error');
                return false;
            }

            $this->logger->log('Found ' . count($canvas_courses) . ' courses from Canvas API');

            // Validate courses against catalog instead of hard-coded exclusions
            if (class_exists('CCS_Catalog_Validator')) {
                $validator = new CCS_Catalog_Validator();
                $validation_results = $validator->validate_against_catalog($canvas_courses);
                $filtered_courses = $validation_results['validated'];
            } else {
                // Fallback - use all courses if validator not available
                $filtered_courses = $canvas_courses;
            }

            $this->logger->log('After catalog validation: ' . count($filtered_courses) . ' courses remain');

            // Find new courses (not already in WordPress)
            $new_courses = $this->find_new_courses($filtered_courses);
            
            if (empty($new_courses)) {
                $this->logger->log('No new courses found during auto-sync');
                return true;
            }

            $this->logger->log('Found ' . count($new_courses) . ' new courses to import');

            // Import new courses
            $course_ids = array_column($new_courses, 'id');
            $result = $this->importer->import_courses($course_ids);
            
            // Send notification email
            $this->send_sync_notification($new_courses, $result);
            
            $this->logger->log('Auto-sync completed: ' . $result['imported'] . ' courses imported');
            
            return true;
            
        } catch (Exception $e) {
            $this->logger->log('Auto-sync failed: ' . $e->getMessage(), 'error');
            return false;
        }
    }

    /**
     * Find courses that don't exist in WordPress using the same logic as manual sync
     *
     * @param array $canvas_courses Array of Canvas courses
     * @return array Array of new courses
     */
    private function find_new_courses($canvas_courses) {
        $new_courses = array();
        
        // Initialize database manager if not already available
        if (!isset($this->db_manager)) {
            $this->db_manager = new CCS_Database_Manager($this->logger);
        }
        
        $this->logger->log('Checking ' . count($canvas_courses) . ' Canvas courses for duplicates using database manager');
        
        foreach ($canvas_courses as $course) {
            // Use standardized database manager check with enhanced logic for deleted courses
            $exists_check = $this->db_manager->course_exists($course['id'], $course['name']);
            
            if ($exists_check['exists']) {
                // Check if the existing post is actually available (same logic as manual sync)
                $existing_post = get_post($exists_check['post_id']);
                if ($existing_post && $existing_post->post_status !== 'trash') {
                    $this->logger->log('Skipping course "' . $course['name'] . '" - already exists and is active (' . $exists_check['type'] . ') - Post ID: ' . $exists_check['post_id']);
                } else {
                    // Course tracking exists but post is deleted/trashed - include for re-import
                    $new_courses[] = $course;
                    $this->logger->log('Course "' . $course['name'] . '" marked for re-import (tracking exists but post deleted) - Canvas ID: ' . $course['id']);
                }
            } else {
                $new_courses[] = $course;
                $this->logger->log('Course "' . $course['name'] . '" marked for import (Canvas ID: ' . $course['id'] . ')');
            }
        }
        
        return $new_courses;
    }

    /**
     * Send notification email about synced courses
     *
     * @param array $new_courses Array of new courses
     * @param array $result Import result
     */
    private function send_sync_notification($new_courses, $result) {
        $email = get_option('ccs_notification_email');
        
        if (empty($email)) {
            $this->logger->log('No notification email configured', 'warning');
            return;
        }

        $subject = sprintf('[%s] New Canvas Courses Synced', get_bloginfo('name'));
        
        $message = "New courses have been automatically synced from Canvas:\n\n";
        $message .= sprintf("Import Summary:\n- Imported: %d\n- Skipped: %d\n- Errors: %d\n\n", 
            $result['imported'], $result['skipped'], $result['errors']);
        
        $message .= "New Courses:\n";
        
        foreach ($new_courses as $course) {
            // Find the WordPress post for this course
            $wp_posts = get_posts(array(
                'post_type' => 'courses',
                'meta_key' => 'canvas_course_id',
                'meta_value' => $course['id'],
                'posts_per_page' => 1,
            ));
            
            if (!empty($wp_posts)) {
                $edit_link = get_edit_post_link($wp_posts[0]->ID);
                $message .= sprintf("- %s\n  Edit: %s\n\n", $course['name'], $edit_link);
            } else {
                $message .= sprintf("- %s (not found in WordPress)\n\n", $course['name']);
            }
        }
        
        $message .= sprintf("\nView all courses: %s", admin_url('edit.php?post_type=courses'));
        
        $sent = wp_mail($email, $subject, $message);
        
        if ($sent) {
            $this->logger->log('Sync notification email sent to: ' . $email);
        } else {
            $this->logger->log('Failed to send sync notification email', 'error');
        }
    }
}
