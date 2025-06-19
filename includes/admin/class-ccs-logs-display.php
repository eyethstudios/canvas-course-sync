<?php
/**
 * Canvas Course Sync Logs Display
 *
 * @package Canvas_Course_Sync
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Logs Display class
 */
class CCS_Logs_Display {
    /**
     * Logger instance
     *
     * @var CCS_Logger
     */
    private $logger;

    /**
     * Constructor
     */
    public function __construct() {
        $canvas_course_sync = canvas_course_sync();
        $this->logger = ($canvas_course_sync && isset($canvas_course_sync->logger)) ? $canvas_course_sync->logger : null;
    }

    /**
     * Render logs page
     */
    public function render() {
        // Enqueue scripts inline for this page
        wp_enqueue_script('jquery');
        ?>
        <div class="wrap">
            <h1><?php _e('Canvas Course Sync - Logs', 'canvas-course-sync'); ?></h1>
            
            <div class="ccs-logs-container">
                <div class="ccs-logs-controls" style="margin: 20px 0;">
                    <button type="button" id="ccs-refresh-logs" class="button button-secondary">
                        <?php _e('Refresh Logs', 'canvas-course-sync'); ?>
                    </button>
                    <button type="button" id="ccs-clear-logs" class="button button-secondary" style="margin-left: 10px;">
                        <?php _e('Clear All Logs', 'canvas-course-sync'); ?>
                    </button>
                </div>
                
                <?php if ($this->logger): ?>
                    <div id="ccs-logs-display">
                        <?php $this->display_logs(); ?>
                    </div>
                <?php else: ?>
                    <div class="notice notice-error">
                        <p><?php _e('Logger not available. Please check plugin installation.', 'canvas-course-sync'); ?></p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            console.log('CCS Debug: Logs page script loaded');
            
            // Check if ccsAjax is available
            if (typeof ccsAjax === 'undefined') {
                console.error('CCS Debug: ccsAjax not available on logs page');
                // Create a minimal ccsAjax object for logs page
                window.ccsAjax = {
                    ajaxUrl: '<?php echo admin_url('admin-ajax.php'); ?>',
                    clearLogsNonce: '<?php echo wp_create_nonce('ccs_clear_logs'); ?>',
                    refreshLogsNonce: '<?php echo wp_create_nonce('ccs_refresh_logs'); ?>'
                };
                console.log('CCS Debug: Created ccsAjax object for logs page');
            }
            
            console.log('CCS Debug: ccsAjax object:', ccsAjax);
            console.log('CCS Debug: Clear logs button exists:', $('#ccs-clear-logs').length > 0);
            console.log('CCS Debug: Refresh logs button exists:', $('#ccs-refresh-logs').length > 0);
            
            // Initialize log manager functionality directly
            initLogsDirectly($);
        });
        
        function initLogsDirectly($) {
            console.log('CCS Debug: Initializing logs functionality directly');
            
            // Clear logs functionality
            $('#ccs-clear-logs').off('click').on('click', function(e) {
                e.preventDefault();
                console.log('CCS Debug: Clear logs button clicked (direct)');
                
                const button = $(this);
                const originalText = button.text();
                button.attr('disabled', true).text('Clearing...');
                
                $.ajax({
                    url: ccsAjax.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'ccs_clear_logs',
                        nonce: ccsAjax.clearLogsNonce
                    },
                    success: function(response) {
                        console.log('CCS Debug: Clear logs response:', response);
                        button.attr('disabled', false).text(originalText);
                        if (response.success) {
                            $('#ccs-logs-display').html('<div class="notice notice-success"><p>Logs cleared successfully.</p></div>');
                            setTimeout(function() {
                                refreshLogsDirect();
                            }, 1000);
                        } else {
                            const errorMsg = response.data && response.data.message ? response.data.message : (response.data || 'Unknown error');
                            alert('Failed to clear logs: ' + errorMsg);
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('CCS Debug: Clear logs error:', error, xhr.responseText);
                        button.attr('disabled', false).text(originalText);
                        alert('Failed to clear logs. Please try again. Error: ' + error);
                    }
                });
            });
            
            // Refresh logs functionality
            $('#ccs-refresh-logs').off('click').on('click', function(e) {
                e.preventDefault();
                console.log('CCS Debug: Refresh logs button clicked (direct)');
                refreshLogsDirect();
            });
            
            function refreshLogsDirect() {
                const button = $('#ccs-refresh-logs');
                const originalText = button.text();
                button.attr('disabled', true).text('Refreshing...');
                
                $.ajax({
                    url: ccsAjax.ajaxUrl,
                    type: 'POST,
                    data: {
                        action: 'ccs_refresh_logs',
                        nonce: ccsAjax.refreshLogsNonce
                    },
                    success: function(response) {
                        console.log('CCS Debug: Refresh logs response:', response);
                        button.attr('disabled', false).text(originalText);
                        if (response.success) {
                            if (response.data && response.data.html) {
                                $('#ccs-logs-display').html(response.data.html);
                            } else {
                                $('#ccs-logs-display').html('<div class="notice notice-info"><p>No logs data received.</p></div>');
                            }
                        } else {
                            const errorMsg = response.data && response.data.message ? response.data.message : (response.data || 'Unknown error');
                            alert('Failed to refresh logs: ' + errorMsg);
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('CCS Debug: Refresh logs error:', error, xhr.responseText);
                        button.attr('disabled', false).text(originalText);
                        alert('Failed to refresh logs. Please try again. Error: ' + error);
                    }
                });
            }
            
            console.log('CCS Debug: Direct logs functionality initialized');
        }
        </script>
        <?php
    }
    
    /**
     * Display logs table
     */
    private function display_logs() {
        $logs = $this->logger ? $this->logger->get_recent_logs(50) : array();
        
        if (empty($logs)) {
            echo '<div class="notice notice-info"><p>' . __('No logs found.', 'canvas-course-sync') . '</p></div>';
            return;
        }
        
        ?>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th scope="col" style="width: 150px;"><?php _e('Timestamp', 'canvas-course-sync'); ?></th>
                    <th scope="col" style="width: 80px;"><?php _e('Level', 'canvas-course-sync'); ?></th>
                    <th scope="col"><?php _e('Message', 'canvas-course-sync'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($logs as $log): ?>
                    <tr>
                        <td>
                            <?php 
                            $timestamp = isset($log->timestamp) ? $log->timestamp : '';
                            echo esc_html(mysql2date('Y-m-d H:i:s', $timestamp));
                            ?>
                        </td>
                        <td>
                            <span class="ccs-log-level ccs-log-level-<?php echo esc_attr($log->level ?? 'info'); ?>">
                                <?php echo esc_html(strtoupper($log->level ?? 'INFO')); ?>
                            </span>
                        </td>
                        <td><?php echo esc_html($log->message ?? ''); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        
        <style>
        .ccs-log-level {
            padding: 2px 6px;
            border-radius: 3px;
            font-size: 11px;
            font-weight: bold;
        }
        .ccs-log-level-info {
            background: #d1ecf1;
            color: #0c5460;
        }
        .ccs-log-level-warning {
            background: #fff3cd;
            color: #856404;
        }
        .ccs-log-level-error {
            background: #f8d7da;
            color: #721c24;
        }
        </style>
        <?php
    }
}
