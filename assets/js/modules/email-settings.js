/**
 * Email Settings Module for Canvas Course Sync
 */
(function($) {
    'use strict';

    /**
     * Initialize email settings functionality
     */
    function initEmailSettings() {
        // Toggle email row visibility based on auto-sync checkbox
        $('#ccs_auto_sync_enabled').change(function() {
            const isChecked = $(this).is(':checked');
            const emailRow = $('#ccs-email-row');
            const emailInput = $('#ccs_notification_email');
            
            if (isChecked) {
                emailRow.show();
                emailInput.prop('required', true);
            } else {
                emailRow.hide();
                emailInput.prop('required', false);
            }
        });

        console.log('CCS Email Settings: Initialized');
    }

    // Initialize when DOM is ready
    $(document).ready(function() {
        initEmailSettings();
    });

    // Export for use by other modules
    window.CCS = window.CCS || {};
    window.CCS.EmailSettings = {
        init: initEmailSettings
    };

})(jQuery);