
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
    }

    /**
     * Render sync controls section
     */
    public function render() {
        $omit_nonce = wp_create_nonce('ccs_omit_courses');
        ?>
        <div class="ccs-panel">
            <h2><?php esc_html_e('Synchronize Courses', 'canvas-course-sync'); ?></h2>
            <p><?php esc_html_e('Load the available courses from Canvas, then select which ones you want to sync or omit.', 'canvas-course-sync'); ?></p>
            
            <button id="ccs-get-courses" class="button button-secondary">
                <?php esc_html_e('Load Available Courses', 'canvas-course-sync'); ?>
            </button>
            <span id="ccs-loading-courses" style="display: none;">
                <div class="ccs-spinner"></div>
                <?php esc_html_e('Loading courses...', 'canvas-course-sync'); ?>
            </span>
            
            <div id="ccs-courses-wrapper" style="display: none;">
                <div id="ccs-course-list" class="ccs-course-list"></div>
                
                <div class="ccs-action-buttons" style="margin-top: 20px; padding-top: 15px; border-top: 1px solid #ddd;">
                    <button id="ccs-sync-selected" class="button button-primary">
                        <?php esc_html_e('Sync Selected Courses', 'canvas-course-sync'); ?>
                    </button>
                    <button id="ccs-omit-selected" class="button button-secondary" style="margin-left: 10px;">
                        <?php esc_html_e('Omit Selected Courses', 'canvas-course-sync'); ?>
                    </button>
                    <button id="ccs-select-all" class="button" style="margin-left: 10px;">
                        <?php esc_html_e('Select All', 'canvas-course-sync'); ?>
                    </button>
                    <button id="ccs-deselect-all" class="button" style="margin-left: 5px;">
                        <?php esc_html_e('Deselect All', 'canvas-course-sync'); ?>
                    </button>
                </div>
                
                <div id="ccs-sync-progress" style="display: none;">
                    <p><?php esc_html_e('Syncing selected courses...', 'canvas-course-sync'); ?></p>
                    <div class="ccs-progress-bar-container">
                        <div id="ccs-sync-progress-bar" class="ccs-progress-bar"></div>
                    </div>
                    <div id="ccs-sync-status"></div>
                </div>
                
                <div id="ccs-sync-results" style="display: none;">
                    <h3><?php esc_html_e('Sync Results', 'canvas-course-sync'); ?></h3>
                    <div id="ccs-sync-message"></div>
                    <table class="ccs-results-table">
                        <tr>
                            <th><?php esc_html_e('Imported', 'canvas-course-sync'); ?></th>
                            <td id="ccs-imported">0</td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e('Skipped', 'canvas-course-sync'); ?></th>
                            <td id="ccs-skipped">0</td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e('Errors', 'canvas-course-sync'); ?></th>
                            <td id="ccs-errors">0</td>
                        </tr>
                    </table>
                </div>
            </div>
        </div>
        
        <script type="text/javascript">
        jQuery(document).ready(function($) {
            // Ensure clean state - remove all existing handlers first
            $('#ccs-select-all, #ccs-deselect-all, #ccs-omit-selected').off();
            
            // Select/Deselect all functionality
            $('#ccs-select-all').on('click', function(e) {
                e.preventDefault();
                $('.ccs-course-checkbox').prop('checked', true);
            });
            
            $('#ccs-deselect-all').on('click', function(e) {
                e.preventDefault();
                $('.ccs-course-checkbox').prop('checked', false);
            });
            
            // Omit courses functionality
            $('#ccs-omit-selected').on('click', function(e) {
                e.preventDefault();
                
                const selectedCourses = $('.ccs-course-checkbox:checked').map(function() {
                    return $(this).val();
                }).get();
                
                if (selectedCourses.length === 0) {
                    alert('<?php echo esc_js(__('Please select at least one course to omit.', 'canvas-course-sync')); ?>');
                    return;
                }
                
                if (!confirm('<?php echo esc_js(__('Are you sure you want to omit', 'canvas-course-sync')); ?> ' + selectedCourses.length + ' <?php echo esc_js(__('course(s) from future syncs?', 'canvas-course-sync')); ?>')) {
                    return;
                }
                
                const button = $(this);
                const originalText = button.text();
                button.prop('disabled', true).text('<?php echo esc_js(__('Omitting...', 'canvas-course-sync')); ?>');
                
                $.ajax({
                    url: ccsAjax.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'ccs_omit_courses',
                        nonce: '<?php echo esc_js($omit_nonce); ?>',
                        course_ids: selectedCourses
                    },
                    success: function(response) {
                        button.prop('disabled', false).text(originalText);
                        
                        if (response.success) {
                            alert(response.data.message);
                            // Refresh course list to show omitted status
                            $('#ccs-get-courses').trigger('click');
                        } else {
                            alert('<?php echo esc_js(__('Error:', 'canvas-course-sync')); ?> ' + (response.data.message || '<?php echo esc_js(__('Unknown error occurred', 'canvas-course-sync')); ?>'));
                        }
                    },
                    error: function(xhr, status, error) {
                        button.prop('disabled', false).text(originalText);
                        alert('<?php echo esc_js(__('Network error occurred. Please try again.', 'canvas-course-sync')); ?>');
                    }
                });
            });
        });
        </script>
        <?php
    }
}
