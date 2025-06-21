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
        
        // Check WordPress hooks and timing
        error_log('CCS_Sync_Controls: Current hook: ' . current_action());
        error_log('CCS_Sync_Controls: Doing action: ' . (doing_action() ? doing_action() : 'none'));
        error_log('CCS_Sync_Controls: Admin page hook suffix: ' . (isset($GLOBALS['hook_suffix']) ? $GLOBALS['hook_suffix'] : 'not set'));
        
        $omit_nonce = wp_create_nonce('ccs_omit_courses');
        error_log('CCS_Sync_Controls: Generated omit nonce: ' . $omit_nonce);
        
        // Check if required JavaScript variables are available
        error_log('CCS_Sync_Controls: Checking if ccsAjax is localized...');
        
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
                
                <!-- DEBUG: Verify button is rendered -->
                <div id="ccs-debug-info" style="margin-top: 10px; padding: 10px; background: #f0f0f0; font-size: 12px; color: #666;">
                    <strong>Debug Info:</strong><br>
                    Omit button rendered at: <?php echo current_time('mysql'); ?><br>
                    Nonce: <?php echo esc_html($omit_nonce); ?><br>
                    Hook: <?php echo esc_html(current_action()); ?>
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
            console.log('CCS_Sync_Controls: Script loaded at', new Date().toISOString());
            console.log('CCS_Sync_Controls: jQuery version:', $.fn.jquery);
            console.log('CCS_Sync_Controls: ccsAjax available:', typeof ccsAjax !== 'undefined');
            if (typeof ccsAjax !== 'undefined') {
                console.log('CCS_Sync_Controls: ccsAjax contents:', ccsAjax);
            }
            
            // Store nonce for omit functionality
            window.ccsOmitNonce = '<?php echo esc_js($omit_nonce); ?>';
            console.log('CCS_Sync_Controls: Stored omit nonce:', window.ccsOmitNonce);
            
            // Check for existing event handlers
            const existingHandlers = $._data(document, 'events');
            console.log('CCS_Sync_Controls: Existing event handlers:', existingHandlers);
            
            // Remove any existing handlers to prevent duplicates
            $(document).off('click.ccs-controls');
            console.log('CCS_Sync_Controls: Removed existing event handlers');
            
            // Verify button exists in DOM
            const omitButton = $('#ccs-omit-selected');
            console.log('CCS_Sync_Controls: Omit button found:', omitButton.length > 0);
            console.log('CCS_Sync_Controls: Omit button HTML:', omitButton.length > 0 ? omitButton[0].outerHTML : 'NOT FOUND');
            
            // Use namespaced event delegation to prevent conflicts
            $(document).on('click.ccs-controls', '#ccs-select-all', function(e) {
                e.preventDefault();
                console.log('CCS_Sync_Controls: Select all clicked');
                $('.ccs-course-checkbox').prop('checked', true);
            });
            
            $(document).on('click.ccs-controls', '#ccs-deselect-all', function(e) {
                e.preventDefault();
                console.log('CCS_Sync_Controls: Deselect all clicked');
                $('.ccs-course-checkbox').prop('checked', false);
            });
            
            $(document).on('click.ccs-controls', '#ccs-omit-selected', function(e) {
                e.preventDefault();
                console.log('CCS_Sync_Controls: Omit button clicked at', new Date().toISOString());
                console.log('CCS_Sync_Controls: Event target:', e.target);
                console.log('CCS_Sync_Controls: Event currentTarget:', e.currentTarget);
                
                const selectedCourses = $('.ccs-course-checkbox:checked').map(function() {
                    return $(this).val();
                }).get();
                
                console.log('CCS_Sync_Controls: Selected courses for omit:', selectedCourses);
                
                if (selectedCourses.length === 0) {
                    alert('<?php echo esc_js(__('Please select at least one course to omit.', 'canvas-course-sync')); ?>');
                    return;
                }
                
                if (!confirm('<?php echo esc_js(__('Are you sure you want to omit', 'canvas-course-sync')); ?> ' + selectedCourses.length + ' <?php echo esc_js(__('course(s) from future syncs?', 'canvas-course-sync')); ?>')) {
                    console.log('CCS_Sync_Controls: User cancelled omit operation');
                    return;
                }
                
                const button = $(this);
                const originalText = button.text();
                button.prop('disabled', true).text('<?php echo esc_js(__('Omitting...', 'canvas-course-sync')); ?>');
                
                console.log('CCS_Sync_Controls: Starting omit AJAX request...');
                console.log('CCS_Sync_Controls: AJAX URL:', ccsAjax ? ccsAjax.ajaxUrl : 'NOT AVAILABLE');
                console.log('CCS_Sync_Controls: Nonce:', window.ccsOmitNonce);
                
                $.ajax({
                    url: ccsAjax ? ccsAjax.ajaxUrl : ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'ccs_omit_courses',
                        nonce: window.ccsOmitNonce,
                        course_ids: selectedCourses
                    },
                    success: function(response) {
                        console.log('CCS_Sync_Controls: Omit AJAX success response:', response);
                        button.prop('disabled', false).text(originalText);
                        
                        if (response.success) {
                            alert(response.data.message);
                            // Refresh course list to show omitted status
                            $('#ccs-get-courses').trigger('click');
                        } else {
                            console.error('CCS_Sync_Controls: Omit failed:', response.data);
                            alert('<?php echo esc_js(__('Error:', 'canvas-course-sync')); ?> ' + (response.data.message || '<?php echo esc_js(__('Unknown error occurred', 'canvas-course-sync')); ?>'));
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('CCS_Sync_Controls: Omit AJAX error:', error);
                        console.error('CCS_Sync_Controls: Error xhr:', xhr);
                        console.error('CCS_Sync_Controls: Error status:', status);
                        console.error('CCS_Sync_Controls: Response text:', xhr.responseText);
                        
                        button.prop('disabled', false).text(originalText);
                        alert('<?php echo esc_js(__('Network error occurred. Please try again.', 'canvas-course-sync')); ?>');
                    }
                });
            });
            
            // Verify event handlers are attached
            setTimeout(function() {
                const postAttachHandlers = $._data(document, 'events');
                console.log('CCS_Sync_Controls: Event handlers after attachment:', postAttachHandlers);
            }, 100);
        });
        </script>
        <?php
        
        error_log('CCS_Sync_Controls: render() method completed');
    }
}
