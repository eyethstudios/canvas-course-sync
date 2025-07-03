/**
 * Canvas Course Sync - GitHub Updater Script
 * 
 * @package Canvas_Course_Sync
 */

(function($) {
    'use strict';
    
    console.log('CCS: Updater script loaded');
    
    // Make ccsCheckForUpdates globally available
    window.ccsCheckForUpdates = function() {
        console.log('CCS: Checking for updates...');
        
        // Verify updater data exists
        if (typeof ccsUpdaterData === 'undefined') {
            console.error('CCS: ccsUpdaterData not available');
            alert('Update check not available - missing data');
            return;
        }
        
        if (!ccsUpdaterData.nonce) {
            console.error('CCS: Update check nonce not available');
            alert('Update check not available - missing security nonce');
            return;
        }
        
        $.ajax({
            url: ccsUpdaterData.ajaxUrl,
            type: 'POST',
            data: {
                action: 'ccs_check_updates',
                nonce: ccsUpdaterData.nonce
            },
            timeout: 30000,
            success: function(response) {
                console.log('CCS: Update check response:', response);
                if (response.success) {
                    alert(response.data.message || 'Update check completed');
                    // Reload the page if an update was found to show the update notice
                    if (response.data.update_available) {
                        setTimeout(function() {
                            location.reload();
                        }, 1000);
                    }
                } else {
                    alert('Update check failed: ' + (response.data || 'Unknown error'));
                }
            },
            error: function(xhr, status, error) {
                console.error('CCS: Update check failed:', error);
                alert('Update check failed: ' + error);
            }
        });
    };
    
})(jQuery);