
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
        error_log('CCS_Sync_Controls: Constructor called at ' . current_time('mysql'));
        
        // Hook the render method to the action
        add_action('ccs_render_sync_controls', array($this, 'render'));
        
        error_log('CCS_Sync_Controls: Added action hook for ccs_render_sync_controls');
    }

    /**
     * Render sync controls section
     */
    public function render() {
        error_log('CCS_Sync_Controls: render() method called at ' . current_time('mysql'));
        
        $omit_nonce = wp_create_nonce('ccs_omit_courses');
        error_log('CCS_Sync_Controls: Generated omit nonce: ' . $omit_nonce);
        
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
            
            <!-- Course wrapper that shows after loading courses -->
            <div id="ccs-courses-wrapper" style="display: none; margin-top: 20px;">
                <!-- Course list container -->
                <div id="ccs-course-list" class="ccs-course-list" style="margin-bottom: 20px;"></div>
                
                <!-- Action buttons - Always visible when courses are loaded -->
                <div class="ccs-action-buttons" style="padding: 15px; background: #f9f9f9; border: 1px solid #ddd; border-radius: 4px;">
                    <div style="margin-bottom: 10px;">
                        <strong><?php esc_html_e('Course Actions:', 'canvas-course-sync'); ?></strong>
                    </div>
                    
                    <button id="ccs-select-all" class="button" style="margin-right: 10px;">
                        <?php esc_html_e('Select All', 'canvas-course-sync'); ?>
                    </button>
                    <button id="ccs-deselect-all" class="button" style="margin-right: 15px;">
                        <?php esc_html_e('Deselect All', 'canvas-course-sync'); ?>
                    </button>
                    
                    <button id="ccs-sync-selected" class="button button-primary" style="margin-right: 10px;">
                        <?php esc_html_e('Sync Selected Courses', 'canvas-course-sync'); ?>
                    </button>
                    <button id="ccs-omit-selected" class="button button-secondary" style="margin-right: 10px; background: #dc3545; border-color: #dc3545; color: white;">
                        <?php esc_html_e('Omit Selected Courses', 'canvas-course-sync'); ?>
                    </button>
                    <button id="ccs-restore-omitted" class="button" style="background: #28a745; border-color: #28a745; color: white;">
                        <?php esc_html_e('Restore Omitted Courses', 'canvas-course-sync'); ?>
                    </button>
                </div>
                
                <!-- Progress and results sections -->
                <div id="ccs-sync-progress" style="display: none; margin-top: 20px;">
                    <p><?php esc_html_e('Syncing selected courses...', 'canvas-course-sync'); ?></p>
                    <div class="ccs-progress-bar-container">
                        <div id="ccs-sync-progress-bar" class="ccs-progress-bar"></div>
                    </div>
                    <div id="ccs-sync-status"></div>
                </div>
                
                <div id="ccs-sync-results" style="display: none; margin-top: 20px;">
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
            console.log('CCS_Sync_Controls: Script initializing...');
            
            // Store nonce for omit functionality
            window.ccsOmitNonce = '<?php echo esc_js($omit_nonce); ?>';
            
            // Remove any existing handlers to prevent duplicates
            $(document).off('click.ccs-controls');
            
            // Use event delegation for all buttons
            $(document).on('click.ccs-controls', '#ccs-select-all', function(e) {
                e.preventDefault();
                console.log('CCS: Select all clicked');
                $('.ccs-course-checkbox').prop('checked', true);
            });
            
            $(document).on('click.ccs-controls', '#ccs-deselect-all', function(e) {
                e.preventDefault();
                console.log('CCS: Deselect all clicked');
                $('.ccs-course-checkbox').prop('checked', false);
            });
            
            // Omit selected courses
            $(document).on('click.ccs-controls', '#ccs-omit-selected', function(e) {
                e.preventDefault();
                console.log('CCS: Omit button clicked');
                
                const selectedCourses = $('.ccs-course-checkbox:checked').map(function() {
                    return $(this).val();
                }).get();
                
                if (selectedCourses.length === 0) {
                    alert('<?php echo esc_js(__('Please select at least one course to omit.', 'canvas-course-sync')); ?>');
                    return;
                }
                
                if (!confirm('<?php echo esc_js(__('Are you sure you want to omit', 'canvas-course-sync')); ?> ' + selectedCourses.length + ' <?php echo esc_js(__('course(s) from future auto-syncs?', 'canvas-course-sync')); ?>')) {
                    return;
                }
                
                const button = $(this);
                const originalText = button.text();
                button.prop('disabled', true).text('<?php echo esc_js(__('Omitting...', 'canvas-course-sync')); ?>');
                
                $.ajax({
                    url: typeof ccsAjax !== 'undefined' ? ccsAjax.ajaxUrl : ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'ccs_omit_courses',
                        nonce: window.ccsOmitNonce,
                        course_ids: selectedCourses
                    },
                    success: function(response) {
                        console.log('CCS: Omit response:', response);
                        button.prop('disabled', false).text(originalText);
                        
                        if (response.success) {
                            alert(response.data.message);
                            // Refresh course list
                            $('#ccs-get-courses').trigger('click');
                        } else {
                            alert('<?php echo esc_js(__('Error:', 'canvas-course-sync')); ?> ' + (response.data.message || '<?php echo esc_js(__('Unknown error', 'canvas-course-sync')); ?>'));
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('CCS: Omit error:', error, xhr.responseText);
                        button.prop('disabled', false).text(originalText);
                        alert('<?php echo esc_js(__('Network error. Please try again.', 'canvas-course-sync')); ?>');
                    }
                });
            });
            
            // Restore omitted courses
            $(document).on('click.ccs-controls', '#ccs-restore-omitted', function(e) {
                e.preventDefault();
                console.log('CCS: Restore omitted clicked');
                
                if (!confirm('<?php echo esc_js(__('Are you sure you want to restore all omitted courses for future auto-syncs?', 'canvas-course-sync')); ?>')) {
                    return;
                }
                
                const button = $(this);
                const originalText = button.text();
                button.prop('disabled', true).text('<?php echo esc_js(__('Restoring...', 'canvas-course-sync')); ?>');
                
                $.ajax({
                    url: typeof ccsAjax !== 'undefined' ? ccsAjax.ajaxUrl : ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'ccs_restore_omitted',
                        nonce: window.ccsOmitNonce
                    },
                    success: function(response) {
                        console.log('CCS: Restore response:', response);
                        button.prop('disabled', false).text(originalText);
                        
                        if (response.success) {
                            alert(response.data.message);
                            // Refresh course list
                            $('#ccs-get-courses').trigger('click');
                        } else {
                            alert('<?php echo esc_js(__('Error:', 'canvas-course-sync')); ?> ' + (response.data.message || '<?php echo esc_js(__('Unknown error', 'canvas-course-sync')); ?>'));
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('CCS: Restore error:', error, xhr.responseText);
                        button.prop('disabled', false).text(originalText);
                        alert('<?php echo esc_js(__('Network error. Please try again.', 'canvas-course-sync')); ?>');
                    }
                });
            });
            
            console.log('CCS_Sync_Controls: Event handlers attached');
        });
        </script>
        
        <style>
        .ccs-action-buttons {
            display: block !important;
            visibility: visible !important;
        }
        
        .ccs-action-buttons button {
            display: inline-block !important;
        }
        
        #ccs-omit-selected {
            background-color: #dc3545 !important;
            border-color: #dc3545 !important;
            color: white !important;
        }
        
        #ccs-omit-selected:hover {
            background-color: #c82333 !important;
            border-color: #bd2130 !important;
        }
        
        #ccs-restore-omitted {
            background-color: #28a745 !important;
            border-color: #28a745 !important;
            color: white !important;
        }
        
        #ccs-restore-omitted:hover {
            background-color: #218838 !important;
            border-color: #1e7e34 !important;
        }
        </style>
        <?php
        
        error_log('CCS_Sync_Controls: render() method completed');
    }
}
