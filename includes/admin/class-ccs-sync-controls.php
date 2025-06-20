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
                    <button id="ccs-sync-courses" class="button button-primary" disabled>
                        <?php _e('Sync Selected Courses', 'canvas-course-sync'); ?>
                    </button>
                    <button id="ccs-omit-courses" class="button button-secondary" style="margin-left: 10px;" disabled>
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
        <?php
    }
}
