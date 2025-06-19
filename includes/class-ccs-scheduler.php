
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
     * Constructor
     */
    public function __construct() {
        $canvas_course_sync = canvas_course_sync();
        $this->logger = ($canvas_course_sync && isset($canvas_course_sync->logger)) ? $canvas_course_sync->logger : new CCS_Logger();
        $this->api = ($canvas_course_sync && isset($canvas_course_sync->api)) ? $canvas_course_sync->api : new CCS_Canvas_API();
        $this->importer = ($canvas_course_sync && isset($canvas_course_sync->importer)) ? $canvas_course_sync->importer : new CCS_Importer();
        
        // Schedule weekly sync if enabled
        add_action('ccs_weekly_sync', array($this, 'run_auto_sync'));
        add_action('wp', array($this, 'schedule_auto_sync'));
        
        // Hook to clear schedule on deactivation
        register_deactivation_hook(CCS_PLUGIN_DIR . 'canvas-course-sync.php', array($this, 'clear_scheduled_sync'));
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

            // Filter out excluded courses
            $filtered_courses = array_filter($canvas_courses, function($course) {
                $course_name = isset($course['name']) ? $course['name'] : '';
                return !ccs_is_course_excluded($course_name);
            });

            $this->logger->log('After filtering excluded courses: ' . count($filtered_courses) . ' courses remain');

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
        
        // Get existing WordPress courses for comparison
        $existing_wp_courses = get_posts(array(
            'post_type'      => 'courses',
            'post_status'    => array('draft', 'publish', 'private', 'pending'),
            'posts_per_page' => -1,
            'fields'         => 'ids'
        ));
        
        $existing_titles = array();
        $existing_canvas_ids = array();
        
        foreach ($existing_wp_courses as $post_id) {
            $title = get_the_title($post_id);
            $canvas_id = get_post_meta($post_id, 'canvas_course_id', true);
            
            if (!empty($title)) {
                $existing_titles[] = strtolower(trim($title));
            }
            if (!empty($canvas_id)) {
                $existing_canvas_ids[] = $canvas_id;
            }
        }
        
        $this->logger->log('Found ' . count($existing_wp_courses) . ' existing WordPress courses for comparison');
        $this->logger->log('Existing Canvas IDs: ' . count($existing_canvas_ids) . ', Existing titles: ' . count($existing_titles));
        
        foreach ($canvas_courses as $course) {
            $exists_in_wp = false;
            $match_type = '';
            
            // Check by Canvas ID first (most reliable)
            if (in_array($course->id, $existing_canvas_ids)) {
                $exists_in_wp = true;
                $match_type = 'canvas_id';
                $this->logger->log('Skipping course "' . $course->name . '" - already exists by Canvas ID: ' . $course->id);
            } else {
                // Check by title (case-insensitive)
                $course_title_lower = strtolower(trim($course->name));
                if (in_array($course_title_lower, $existing_titles)) {
                    $exists_in_wp = true;
                    $match_type = 'title';
                    $this->logger->log('Skipping course "' . $course->name . '" - already exists by title match');
                }
            }
            
            if (!$exists_in_wp) {
                $new_courses[] = $course;
                $this->logger->log('Course "' . $course->name . '" marked for import (ID: ' . $course->id . ')');
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
                'meta_value' => $course->id,
                'posts_per_page' => 1,
            ));
            
            if (!empty($wp_posts)) {
                $edit_link = get_edit_post_link($wp_posts[0]->ID);
                $message .= sprintf("- %s\n  Edit: %s\n\n", $course->name, $edit_link);
            } else {
                $message .= sprintf("- %s (not found in WordPress)\n\n", $course->name);
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
