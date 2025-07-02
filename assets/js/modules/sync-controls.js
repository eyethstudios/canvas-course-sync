/**
 * Sync Controls Module for Canvas Course Sync
 */
(function($) {
    'use strict';

    /**
     * Initialize sync controls functionality
     */
    function initSyncControls() {
        console.log('CCS Sync Controls: Initializing...');
        
        // Check if omit data is available
        if (typeof ccsOmitData !== 'undefined') {
            console.log('CCS Sync Controls: Omit nonces available:', ccsOmitData);
        } else {
            console.warn('CCS Sync Controls: Omit data not available');
        }

        console.log('CCS Sync Controls: Initialized');
    }

    // Initialize when DOM is ready
    $(document).ready(function() {
        initSyncControls();
    });

    // Export for use by other modules
    window.CCS = window.CCS || {};
    window.CCS.SyncControls = {
        init: initSyncControls
    };

})(jQuery);