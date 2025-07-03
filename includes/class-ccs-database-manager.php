
<?php
/**
 * Database Manager for Canvas Course Sync
 * Handles database operations with proper constraints and transactions
 *
 * @package Canvas_Course_Sync
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class CCS_Database_Manager {
    
    /**
     * Logger instance
     */
    private $logger;
    
    /**
     * Constructor with dependency injection
     *
     * @param CCS_Logger|null $logger Logger instance (optional)
     */
    public function __construct(CCS_Logger $logger = null) {
        $this->logger = $logger;
        
        // Create custom table for course tracking if needed
        $this->maybe_create_course_tracking_table();
        
        // Database manager initialized
    }
    
    /**
     * Create course tracking table with proper constraints
     */
    private function maybe_create_course_tracking_table() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'ccs_course_tracking';
        
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            canvas_course_id bigint NOT NULL,
            wordpress_post_id bigint NOT NULL,
            course_title varchar(255) NOT NULL,
            course_slug varchar(255) NOT NULL,
            sync_status varchar(50) DEFAULT 'synced',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY canvas_course_id (canvas_course_id),
            UNIQUE KEY wordpress_post_id (wordpress_post_id),
            UNIQUE KEY course_slug (course_slug),
            INDEX course_title (course_title),
            INDEX sync_status (sync_status)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        $result = dbDelta($sql);
        
        // Suppress output for AJAX requests
    }
    
    /**
     * Check if course already exists with database-level constraints
     */
    public function course_exists($canvas_id, $course_title = '') {
        global $wpdb;
        
        error_log('CCS_Database_Manager: Checking if course exists - Canvas ID: ' . $canvas_id . ', Title: ' . $course_title);
        
        // Check custom tracking table first
        $tracking_table = $wpdb->prefix . 'ccs_course_tracking';
        $existing_tracking = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $tracking_table WHERE canvas_course_id = %d",
            intval($canvas_id)
        ));
        
        if ($existing_tracking) {
            error_log('CCS_Database_Manager: Course found in tracking table: ' . print_r($existing_tracking, true));
            return array(
                'exists' => true,
                'type' => 'tracking_table',
                'post_id' => $existing_tracking->wordpress_post_id,
                'data' => $existing_tracking
            );
        }
        
        // Check WordPress posts meta
        $existing_by_canvas_id = $wpdb->get_row($wpdb->prepare(
            "SELECT p.ID, p.post_title FROM {$wpdb->posts} p 
             INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id 
             WHERE p.post_type = 'courses' 
             AND pm.meta_key = 'canvas_course_id' 
             AND pm.meta_value = %d 
             AND p.post_status != 'trash'",
            intval($canvas_id)
        ));
        
        if ($existing_by_canvas_id) {
            error_log('CCS_Database_Manager: Course found by Canvas ID in WordPress: ' . print_r($existing_by_canvas_id, true));
            return array(
                'exists' => true,
                'type' => 'wordpress_meta',
                'post_id' => $existing_by_canvas_id->ID,
                'data' => $existing_by_canvas_id
            );
        }
        
        // Check by title if provided
        if (!empty($course_title)) {
            $existing_by_title = $wpdb->get_row($wpdb->prepare(
                "SELECT ID, post_title FROM {$wpdb->posts} 
                 WHERE post_type = 'courses' 
                 AND post_title = %s 
                 AND post_status != 'trash'",
                trim($course_title)
            ));
            
            if ($existing_by_title) {
                error_log('CCS_Database_Manager: Course found by title in WordPress: ' . print_r($existing_by_title, true));
                return array(
                    'exists' => true,
                    'type' => 'wordpress_title',
                    'post_id' => $existing_by_title->ID,
                    'data' => $existing_by_title
                );
            }
        }
        
        error_log('CCS_Database_Manager: Course does not exist');
        return array('exists' => false);
    }
    
    /**
     * Create course with transaction handling
     */
    public function create_course_with_transaction($course_data) {
        global $wpdb;
        
        error_log('CCS_Database_Manager: Starting transaction for course creation');
        error_log('CCS_Database_Manager: Course data: ' . print_r($course_data, true));
        
        // Start transaction
        $wpdb->query('START TRANSACTION');
        
        try {
            // Verify course doesn't exist again (race condition check)
            $exists_check = $this->course_exists($course_data['canvas_id'], $course_data['title']);
            if ($exists_check['exists']) {
                error_log('CCS_Database_Manager: TRANSACTION ROLLBACK - Course already exists during transaction');
                $wpdb->query('ROLLBACK');
                return array(
                    'success' => false,
                    'error' => 'Course already exists',
                    'existing_post_id' => $exists_check['post_id']
                );
            }
            
            // Create WordPress post
            $post_data = array(
                'post_title' => sanitize_text_field($course_data['title']),
                'post_content' => $course_data['content'] ?? '',
                'post_status' => 'draft',
                'post_type' => 'courses',
                'post_author' => get_current_user_id()
            );
            
            error_log('CCS_Database_Manager: Creating WordPress post with data: ' . print_r($post_data, true));
            $post_id = wp_insert_post($post_data);
            
            if (!$post_id || is_wp_error($post_id)) {
                throw new Exception('Failed to create WordPress post: ' . (is_wp_error($post_id) ? $post_id->get_error_message() : 'Unknown error'));
            }
            
            error_log('CCS_Database_Manager: Created WordPress post ID: ' . $post_id);
            
            // Add post meta
            $meta_data = array(
                'canvas_course_id' => intval($course_data['canvas_id']),
                'canvas_course_code' => sanitize_text_field($course_data['course_code'] ?? ''),
                'canvas_start_at' => sanitize_text_field($course_data['start_at'] ?? ''),
                'canvas_end_at' => sanitize_text_field($course_data['end_at'] ?? ''),
                'canvas_enrollment_term_id' => intval($course_data['enrollment_term_id'] ?? 0),
                'link' => esc_url_raw($course_data['enrollment_url'] ?? '')
            );
            
            foreach ($meta_data as $meta_key => $meta_value) {
                $meta_result = update_post_meta($post_id, $meta_key, $meta_value);
                error_log('CCS_Database_Manager: Updated meta ' . $meta_key . ' = ' . $meta_value . ' (result: ' . ($meta_result ? 'success' : 'failed') . ')');
                
                if ($meta_result === false && !metadata_exists('post', $post_id, $meta_key)) {
                    throw new Exception('Failed to update post meta: ' . $meta_key);
                }
            }
            
            // Add to tracking table
            $tracking_table = $wpdb->prefix . 'ccs_course_tracking';
            $tracking_result = $wpdb->insert(
                $tracking_table,
                array(
                    'canvas_course_id' => intval($course_data['canvas_id']),
                    'wordpress_post_id' => $post_id,
                    'course_title' => sanitize_text_field($course_data['title']),
                    'course_slug' => sanitize_title($course_data['slug'] ?? ''),
                    'sync_status' => 'synced'
                ),
                array('%d', '%d', '%s', '%s', '%s')
            );
            
            if ($tracking_result === false) {
                throw new Exception('Failed to insert into tracking table: ' . $wpdb->last_error);
            }
            
            error_log('CCS_Database_Manager: Added to tracking table with ID: ' . $wpdb->insert_id);
            
            // Commit transaction
            $wpdb->query('COMMIT');
            error_log('CCS_Database_Manager: Transaction committed successfully');
            
            return array(
                'success' => true,
                'post_id' => $post_id,
                'tracking_id' => $wpdb->insert_id
            );
            
        } catch (Exception $e) {
            // Rollback transaction
            $wpdb->query('ROLLBACK');
            error_log('CCS_Database_Manager: TRANSACTION ROLLBACK - Error: ' . $e->getMessage());
            
            if ($this->logger) {
                $this->logger->log('Database transaction failed: ' . $e->getMessage(), 'error');
            }
            
            return array(
                'success' => false,
                'error' => $e->getMessage()
            );
        }
    }
    
    /**
     * Get course sync statistics
     */
    public function get_sync_stats() {
        global $wpdb;
        
        $tracking_table = $wpdb->prefix . 'ccs_course_tracking';
        
        $stats = $wpdb->get_row("
            SELECT 
                COUNT(*) as total_synced,
                COUNT(CASE WHEN sync_status = 'synced' THEN 1 END) as active_synced,
                COUNT(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR) THEN 1 END) as synced_today
            FROM $tracking_table
        ");
        
        return $stats ? (array)$stats : array('total_synced' => 0, 'active_synced' => 0, 'synced_today' => 0);
    }
    
    /**
     * Check for deleted WordPress courses and update their sync status
     * 
     * @return array Results of the cleanup operation
     */
    public function cleanup_deleted_courses() {
        global $wpdb;
        
        $tracking_table = $wpdb->prefix . 'ccs_course_tracking';
        $updated_count = 0;
        $results = array(
            'checked' => 0,
            'updated' => 0,
            'details' => array()
        );
        
        error_log('CCS_Database_Manager: Starting cleanup of deleted courses');
        
        if ($this->logger) {
            $this->logger->log('Starting cleanup of deleted courses');
        }
        
        // Get all tracked courses with 'synced' status
        $synced_courses = $wpdb->get_results("
            SELECT * FROM $tracking_table 
            WHERE sync_status = 'synced'
        ");
        
        $results['checked'] = count($synced_courses);
        error_log('CCS_Database_Manager: Found ' . count($synced_courses) . ' courses with synced status to check');
        
        foreach ($synced_courses as $tracked_course) {
            error_log('CCS_Database_Manager: Checking course: ' . $tracked_course->course_title . ' (Post ID: ' . $tracked_course->wordpress_post_id . ')');
            
            // Check if the WordPress post still exists and is not trashed
            $post = get_post($tracked_course->wordpress_post_id);
            
            if (!$post) {
                error_log('CCS_Database_Manager: Post does not exist - Post ID: ' . $tracked_course->wordpress_post_id);
                $status = 'deleted';
                $should_update = true;
            } elseif ($post->post_status === 'trash') {
                error_log('CCS_Database_Manager: Post is trashed - Post ID: ' . $tracked_course->wordpress_post_id);
                $status = 'trashed';
                $should_update = true;
            } else {
                error_log('CCS_Database_Manager: Post exists and is not trashed - Post ID: ' . $tracked_course->wordpress_post_id . ' (Status: ' . $post->post_status . ')');
                $should_update = false;
            }
            
            if ($should_update) {
                // Post is deleted or trashed, update sync status to 'available'
                $update_result = $wpdb->update(
                    $tracking_table,
                    array('sync_status' => 'available'),
                    array('id' => $tracked_course->id),
                    array('%s'),
                    array('%d')
                );
                
                error_log('CCS_Database_Manager: Update result for tracking ID ' . $tracked_course->id . ': ' . ($update_result !== false ? 'SUCCESS' : 'FAILED'));
                
                if ($update_result !== false) {
                    $updated_count++;
                    
                    $results['details'][] = array(
                        'canvas_id' => $tracked_course->canvas_course_id,
                        'course_title' => $tracked_course->course_title,
                        'post_id' => $tracked_course->wordpress_post_id,
                        'status' => $status
                    );
                    
                    if ($this->logger) {
                        $this->logger->log("Updated course sync status to 'available': {$tracked_course->course_title} (Canvas ID: {$tracked_course->canvas_course_id}) - Post was {$status}");
                    }
                } else {
                    error_log('CCS_Database_Manager: Failed to update tracking table for course: ' . $tracked_course->course_title . ' - Error: ' . $wpdb->last_error);
                }
            }
        }
        
        $results['updated'] = $updated_count;
        
        error_log('CCS_Database_Manager: Cleanup completed: ' . $updated_count . ' courses updated from synced to available');
        
        if ($this->logger) {
            $this->logger->log("Cleanup completed: {$updated_count} courses updated from 'synced' to 'available'");
        }
        
        return $results;
    }
}
