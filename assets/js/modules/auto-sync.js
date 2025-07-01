/**
 * Canvas Course Sync - Auto-Sync Management Module
 * Handles automatic synchronization functionality
 * 
 * @package Canvas_Course_Sync
 */

(function($) {
    'use strict';

    // Auto-Sync Module
    window.CCSAutoSync = {
        
        /**
         * Run manual auto-sync
         */
        runAutoSync: function() {
            console.log('CCS Auto-Sync: Running manual auto-sync');
            
            const $button = $('#ccs-run-auto-sync');
            const originalText = $button.text();
            
            CCSAdmin.ui.showLoading($button, 'Running Auto-Sync...');
            
            CCSAdmin.ajax.request({
                data: {
                    action: 'ccs_run_auto_sync',
                    nonce: window.ccsAjax.runAutoSyncNonce || ''
                },
                context: 'Manual Auto-Sync',
                timeout: 60000 // Extended timeout for sync operations
            }).done(function(response) {
                console.log('CCS Auto-Sync: Response:', response);
                
                if (response.success) {
                    const message = response.data.message || 'Auto-sync completed successfully';
                    CCSAdmin.ui.showSuccess(message);
                    
                    // Show detailed results if available
                    if (response.data.results) {
                        const results = response.data.results;
                        const detailMsg = `Imported: ${results.imported || 0}, Skipped: ${results.skipped || 0}, Errors: ${results.errors || 0}`;
                        CCSAdmin.ui.showSuccess(detailMsg);
                    }
                } else {
                    const errorMsg = response.data || 'Auto-sync failed';
                    CCSAdmin.errorHandler.handleError(errorMsg, 'Auto-Sync');
                }
            }).always(function() {
                CCSAdmin.ui.hideLoading($button, originalText);
            });
        },

        /**
         * Toggle auto-sync setting
         */
        toggleAutoSync: function($checkbox) {
            const isEnabled = $checkbox.is(':checked');
            console.log('CCS Auto-Sync: Toggling auto-sync to', isEnabled);
            
            CCSAdmin.ajax.request({
                data: {
                    action: 'ccs_toggle_auto_sync',
                    nonce: window.ccsAjax.toggleAutoSyncNonce || '',
                    enabled: isEnabled ? 1 : 0
                },
                context: 'Toggle Auto-Sync'
            }).done(function(response) {
                if (response.success) {
                    const status = isEnabled ? 'enabled' : 'disabled';
                    CCSAdmin.ui.showSuccess(`Auto-sync ${status} successfully`);
                } else {
                    // Revert checkbox state on error
                    $checkbox.prop('checked', !isEnabled);
                    const errorMsg = response.data || 'Failed to toggle auto-sync';
                    CCSAdmin.errorHandler.handleError(errorMsg, 'Toggle Auto-Sync');
                }
            }).fail(function() {
                // Revert checkbox state on failure
                $checkbox.prop('checked', !isEnabled);
            });
        },

        /**
         * Initialize auto-sync module
         */
        init: function() {
            console.log('CCS Auto-Sync: Initializing auto-sync module');
            
            // Bind manual auto-sync button
            $(document).on('click', '#ccs-run-auto-sync', () => {
                this.runAutoSync();
            });
            
            // Bind auto-sync toggle
            $(document).on('change', '#ccs_auto_sync_enabled', function() {
                CCSAutoSync.toggleAutoSync($(this));
            });
            
            console.log('CCS Auto-Sync: Module initialized');
        }
    };

    // Initialize when core is ready
    $(document).ready(function() {
        if (window.CCSAdmin) {
            CCSAutoSync.init();
        } else {
            // Wait for core to load
            setTimeout(() => CCSAutoSync.init(), 100);
        }
    });

})(jQuery);