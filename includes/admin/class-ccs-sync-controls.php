
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
        
        // Enqueue and localize script for omit functionality
        wp_enqueue_script('jquery');
        wp_localize_script('ccs-admin-js', 'ccsOmitData', array(
            'omitNonce' => $omit_nonce,
            'restoreNonce' => wp_create_nonce('ccs_restore_omitted')
        ));
        
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
                
                <!-- Action buttons -->
                <div class="ccs-action-buttons">
                    <h3><?php esc_html_e('Course Actions', 'canvas-course-sync'); ?></h3>
                    
                    <div class="ccs-button-group" style="margin-bottom: 15px;">
                        <button id="ccs-select-all" class="button">
                            <?php esc_html_e('Select All', 'canvas-course-sync'); ?>
                        </button>
                        <button id="ccs-deselect-all" class="button">
                            <?php esc_html_e('Deselect All', 'canvas-course-sync'); ?>
                        </button>
                    </div>
                    
                    <div class="ccs-button-group">
                        <button id="ccs-sync-selected" class="button button-primary">
                            <?php esc_html_e('Sync Selected Courses', 'canvas-course-sync'); ?>
                        </button>
                        <button id="ccs-omit-selected" class="button ccs-omit-btn">
                            <?php esc_html_e('Omit Selected from Auto-Sync', 'canvas-course-sync'); ?>
                        </button>
                        <button id="ccs-restore-omitted" class="button ccs-restore-btn">
                            <?php esc_html_e('Restore All Omitted Courses', 'canvas-course-sync'); ?>
                        </button>
                    </div>
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
        (function($) {
            'use strict';
            
            $(document).ready(function() {
                console.log('CCS_Sync_Controls: Omit nonces available:', typeof ccsOmitData !== 'undefined' ? ccsOmitData : 'Not available');
            });
        })(jQuery);
        </script>
        
        <style>
        .ccs-action-buttons {
            background: #f9f9f9;
            border: 1px solid #ddd;
            border-radius: 4px;
            padding: 20px;
            margin-top: 20px;
        }
        
        .ccs-action-buttons h3 {
            margin-top: 0;
            margin-bottom: 15px;
            color: #333;
        }
        
        .ccs-button-group {
            margin-bottom: 10px;
        }
        
        .ccs-button-group button {
            margin-right: 10px;
            margin-bottom: 5px;
        }
        
        .ccs-omit-btn {
            background-color: #dc3545;
            border-color: #dc3545;
            color: white;
        }
        
        .ccs-omit-btn:hover {
            background-color: #c82333;
            border-color: #bd2130;
        }
        
        .ccs-restore-btn {
            background-color: #28a745;
            border-color: #28a745;
            color: white;
        }
        
        .ccs-restore-btn:hover {
            background-color: #218838;
            border-color: #1e7e34;
        }
        </style>
        <?php
        
        error_log('CCS_Sync_Controls: render() method completed');
    }
}
