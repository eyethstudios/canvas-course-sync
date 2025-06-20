
<?php
/**
 * Canvas Course Sync Controls Component
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class CCS_Sync_Controls {
    /**
     * Constructor
     */
    public function __construct() {
        // Hook the render method to the action
        add_action('ccs_render_sync_controls', array($this, 'render'));
        
        // Add AJAX handlers for omit functionality
        add_action('wp_ajax_ccs_omit_courses', array($this, 'ajax_omit_courses'));
    }

    /**
     * AJAX handler for omitting courses
     */
    public function ajax_omit_courses() {
        // Verify nonce and permissions
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Insufficient permissions'));
            return;
        }
        
        $nonce = isset($_POST['nonce']) ? sanitize_text_field($_POST['nonce']) : '';
        if (!wp_verify_nonce($nonce, 'ccs_omit_courses')) {
            wp_send_json_error(array('message' => 'Security check failed'));
            return;
        }
        
        $course_ids = isset($_POST['course_ids']) ? array_map('intval', $_POST['course_ids']) : array();
        
        if (empty($course_ids)) {
            wp_send_json_error(array('message' => 'No courses selected'));
            return;
        }
        
        // Get existing omitted courses
        $omitted_courses = get_option('ccs_omitted_courses', array());
        
        // Add new courses to omitted list
        foreach ($course_ids as $course_id) {
            if (!in_array($course_id, $omitted_courses)) {
                $omitted_courses[] = $course_id;
            }
        }
        
        // Save updated list
        update_option('ccs_omitted_courses', $omitted_courses);
        
        wp_send_json_success(array(
            'message' => sprintf(__('%d courses have been omitted from future syncs.', 'canvas-course-sync'), count($course_ids)),
            'omitted_count' => count($course_ids)
        ));
    }

    /**
     * Render sync controls section
     */
    public function render() {
        ?>
        <div class="ccs-panel">
            <h2><?php _e('Synchronize Courses', 'canvas-course-sync'); ?></h2>
            <p><?php _e('Load the available courses from Canvas, then select which ones you want to sync or omit.', 'canvas-course-sync'); ?></p>
            
            <button id="ccs-get-courses" class="button button-secondary">
                <?php _e('Load Available Courses', 'canvas-course-sync'); ?>
            </button>
            <span id="ccs-loading-courses" style="display: none;">
                <div class="ccs-spinner"></div>
                <?php _e('Loading courses...', 'canvas-course-sync'); ?>
            </span>
            
            <div id="ccs-courses-wrapper" style="display: none;">
                <div id="ccs-course-list" class="ccs-course-list"></div>
                
                <div class="ccs-action-buttons" style="margin-top: 20px; padding-top: 15px; border-top: 1px solid #ddd;">
                    <button id="ccs-sync-selected" class="button button-primary">
                        <?php _e('Sync Selected Courses', 'canvas-course-sync'); ?>
                    </button>
                    <button id="ccs-omit-selected" class="button button-secondary" style="margin-left: 10px;">
                        <?php _e('Omit Selected Courses', 'canvas-course-sync'); ?>
                    </button>
                </div>
                
                <div id="ccs-sync-progress" style="display: none;">
                    <p><?php _e('Syncing selected courses...', 'canvas-course-sync'); ?></p>
                    <div class="ccs-progress-bar-container">
                        <div id="ccs-sync-progress-bar" class="ccs-progress-bar"></div>
                    </div>
                    <div id="ccs-sync-status"></div>
                </div>
                
                <div id="ccs-sync-results" style="display: none;">
                    <h3><?php _e('Sync Results', 'canvas-course-sync'); ?></h3>
                    <div id="ccs-sync-message"></div>
                    <table class="ccs-results-table">
                        <tr>
                            <th><?php _e('Imported', 'canvas-course-sync'); ?></th>
                            <td id="ccs-imported">0</td>
                        </tr>
                        <tr>
                            <th><?php _e('Skipped', 'canvas-course-sync'); ?></th>
                            <td id="ccs-skipped">0</td>
                        </tr>
                        <tr>
                            <th><?php _e('Errors', 'canvas-course-sync'); ?></th>
                            <td id="ccs-errors">0</td>
                        </tr>
                    </table>
                </div>
            </div>
        </div>
        
        <script type="text/javascript">
        jQuery(document).ready(function($) {
            // Add omit courses functionality
            $('#ccs-omit-selected').on('click', function(e) {
                e.preventDefault();
                
                const selectedCourses = $('.ccs-course-checkbox:checked').map(function() {
                    return $(this).val();
                }).get();
                
                if (selectedCourses.length === 0) {
                    alert('Please select at least one course to omit.');
                    return;
                }
                
                if (!confirm('Are you sure you want to omit ' + selectedCourses.length + ' course(s) from future syncs?')) {
                    return;
                }
                
                const button = $(this);
                button.prop('disabled', true).text('Omitting...');
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'ccs_omit_courses',
                        nonce: '<?php echo wp_create_nonce('ccs_omit_courses'); ?>',
                        course_ids: selectedCourses
                    },
                    success: function(response) {
                        button.prop('disabled', false).text('<?php _e('Omit Selected Courses', 'canvas-course-sync'); ?>');
                        
                        if (response.success) {
                            alert(response.data.message);
                            // Refresh course list to show omitted status
                            $('#ccs-get-courses').click();
                        } else {
                            alert('Error: ' + (response.data.message || 'Unknown error occurred'));
                        }
                    },
                    error: function() {
                        button.prop('disabled', false).text('<?php _e('Omit Selected Courses', 'canvas-course-sync'); ?>');
                        alert('Network error occurred. Please try again.');
                    }
                });
            });
        });
        </script>
        <?php
    }
}
