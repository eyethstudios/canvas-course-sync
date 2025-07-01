/**
 * Canvas Course Sync - Logging Management Module
 * Handles log display, clearing, and refreshing
 * 
 * @package Canvas_Course_Sync
 */

(function($) {
    'use strict';

    // Logs Module
    window.CCSLogs = {
        
        /**
         * Refresh logs display
         */
        refreshLogs: function() {
            console.log('CCS Logs: Refreshing logs display');
            
            const $button = $('#ccs-refresh-logs');
            const $logsDiv = $('#ccs-logs-display');
            const originalText = $button.text();
            
            CCSAdmin.ui.showLoading($button, 'Refreshing...');
            
            CCSAdmin.ajax.request({
                data: {
                    action: 'ccs_refresh_logs',
                    nonce: window.ccsAjax.refreshLogsNonce || ''
                },
                context: 'Refresh Logs'
            }).done(function(response) {
                console.log('CCS Logs: Refresh response:', response);
                
                if (response.success && response.data) {
                    $logsDiv.html(response.data);
                    CCSAdmin.ui.showSuccess('Logs refreshed successfully');
                } else {
                    const errorMsg = response.data || 'Failed to refresh logs';
                    CCSAdmin.errorHandler.handleError(errorMsg, 'Refresh Logs');
                }
            }).always(function() {
                CCSAdmin.ui.hideLoading($button, originalText);
            });
        },

        /**
         * Clear all logs
         */
        clearLogs: function() {
            console.log('CCS Logs: Clearing logs');
            
            if (!confirm('Are you sure you want to clear all logs? This action cannot be undone.')) {
                return;
            }
            
            const $button = $('#ccs-clear-logs');
            const $logsDiv = $('#ccs-logs-display');
            const originalText = $button.text();
            
            CCSAdmin.ui.showLoading($button, 'Clearing...');
            
            CCSAdmin.ajax.request({
                data: {
                    action: 'ccs_clear_logs',
                    nonce: window.ccsAjax.clearLogsNonce || ''
                },
                context: 'Clear Logs'
            }).done(function(response) {
                console.log('CCS Logs: Clear response:', response);
                
                if (response.success) {
                    $logsDiv.html('<div class="notice notice-info"><p>No logs found.</p></div>');
                    CCSAdmin.ui.showSuccess('All logs cleared successfully');
                } else {
                    const errorMsg = response.data || 'Failed to clear logs';
                    CCSAdmin.errorHandler.handleError(errorMsg, 'Clear Logs');
                }
            }).always(function() {
                CCSAdmin.ui.hideLoading($button, originalText);
            });
        },

        /**
         * Auto-refresh logs periodically
         */
        setupAutoRefresh: function() {
            // Refresh logs every 30 seconds if on logs page
            if ($('#ccs-logs-display').length > 0) {
                setInterval(() => {
                    // Only auto-refresh if page is visible
                    if (!document.hidden) {
                        this.refreshLogs();
                    }
                }, 30000);
                
                console.log('CCS Logs: Auto-refresh enabled (30s interval)');
            }
        },

        /**
         * Initialize logs module
         */
        init: function() {
            console.log('CCS Logs: Initializing logs module');
            
            // Bind refresh button
            $(document).on('click', '#ccs-refresh-logs', () => {
                this.refreshLogs();
            });
            
            // Bind clear button
            $(document).on('click', '#ccs-clear-logs', () => {
                this.clearLogs();
            });
            
            // Setup auto-refresh
            this.setupAutoRefresh();
            
            console.log('CCS Logs: Module initialized');
        }
    };

    // Initialize when core is ready
    $(document).ready(function() {
        if (window.CCSAdmin) {
            CCSLogs.init();
        } else {
            // Wait for core to load
            setTimeout(() => CCSLogs.init(), 100);
        }
    });

})(jQuery);