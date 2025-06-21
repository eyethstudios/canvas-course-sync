
/**
 * Course synchronization functionality
 */
export function initSyncManager($) {
    let syncInProgress = false;
    
    // Remove ALL existing sync handlers to prevent duplicates
    $(document).off('click.sync-manager');
    $('#ccs-sync-selected').off();
    
    // Use namespaced events to prevent conflicts
    $(document).on('click.sync-manager', '#ccs-sync-selected', function(e) {
        e.preventDefault();
        e.stopPropagation();
        e.stopImmediatePropagation();
        
        console.log('Sync button clicked, syncInProgress:', syncInProgress);
        
        // Prevent duplicate syncs - check immediately and return
        if (syncInProgress) {
            console.log('Sync already in progress, ignoring duplicate request');
            return false;
        }
        
        // Set sync in progress flag IMMEDIATELY to prevent any race conditions
        syncInProgress = true;
        
        const selectedCourses = $('.ccs-course-checkbox:checked').map(function() {
            return $(this).val();
        }).get();
        
        if (selectedCourses.length === 0) {
            syncInProgress = false; // Reset flag
            alert('Please select at least one course to sync.');
            return false;
        }
        
        // Filter out already synced courses to prevent duplicates
        const newCoursesToSync = [];
        $('.ccs-course-checkbox:checked').each(function() {
            const courseRow = $(this).closest('.ccs-course-item, tr');
            const statusElement = courseRow.find('.ccs-course-status');
            const status = statusElement.text().toLowerCase();
            
            // Only sync if not already synced
            if (status !== 'already synced' && status !== 'synced') {
                newCoursesToSync.push($(this).val());
            }
        });
        
        if (newCoursesToSync.length === 0) {
            syncInProgress = false; // Reset flag
            alert('Selected courses are already synced. Please select new courses to sync.');
            return false;
        }
        
        if (newCoursesToSync.length !== selectedCourses.length) {
            const alreadySynced = selectedCourses.length - newCoursesToSync.length;
            if (!confirm('Warning: ' + alreadySynced + ' selected course(s) are already synced and will be skipped. Continue with syncing ' + newCoursesToSync.length + ' new course(s)?')) {
                syncInProgress = false; // Reset flag
                return false;
            }
        }
        
        // Single confirmation dialog for new courses only
        if (!confirm('Are you sure you want to sync ' + newCoursesToSync.length + ' new course(s)?')) {
            syncInProgress = false; // Reset flag
            return false;
        }
        
        const button = $(this);
        const progress = $('#ccs-sync-progress');
        const results = $('#ccs-sync-results');
        const statusText = $('#ccs-sync-status');
        const progressBar = $('#ccs-sync-progress-bar');
        
        // Disable ALL sync-related buttons immediately
        $('#ccs-sync-selected, #ccs-sync-courses, #ccs-get-courses').prop('disabled', true);
        progress.show();
        results.hide();
        
        // Clear any previous messages
        $('#ccs-sync-message').empty();
        statusText.html('Initializing sync...');
        progressBar.css('width', '0%').text('0%');
        
        // Status polling interval
        let syncInterval = setInterval(function() {
            $.ajax({
                url: ccsAjax.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'ccs_sync_status',
                    nonce: ccsAjax.syncStatusNonce
                },
                success: function(response) {
                    if (response.success && response.data) {
                        statusText.html(response.data.status || 'Processing...');
                        if (response.data.processed && response.data.total) {
                            const percent = Math.round((response.data.processed / response.data.total) * 100);
                            progressBar.css('width', percent + '%');
                            progressBar.text(percent + '%');
                        }
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Status check error:', error);
                }
            });
        }, 2000);
        
        // Start the sync process with filtered courses
        $.ajax({
            url: ccsAjax.ajaxUrl,
            type: 'POST',
            data: {
                action: 'ccs_sync_courses',
                nonce: ccsAjax.syncCoursesNonce,
                course_ids: newCoursesToSync
            },
            success: function(response) {
                console.log('Sync completed successfully');
                clearInterval(syncInterval);
                
                // Reset sync state
                syncInProgress = false;
                $('#ccs-sync-selected, #ccs-sync-courses, #ccs-get-courses').prop('disabled', false);
                progress.hide();
                
                if (response.success && response.data) {
                    const data = response.data;
                    $('#ccs-sync-message').html('<div class="notice notice-success inline"><p>' + (data.message || 'Sync completed successfully!') + '</p></div>');
                    $('#ccs-imported').text(data.imported || 0);
                    $('#ccs-skipped').text(data.skipped || 0);
                    $('#ccs-errors').text(data.errors || 0);
                } else {
                    const errorMessage = response.data && response.data.message ? response.data.message : 'Sync failed with unknown error.';
                    $('#ccs-sync-message').html('<div class="notice notice-error inline"><p>' + errorMessage + '</p></div>');
                }
                
                results.show();
                
                // Auto-refresh after 3 seconds
                setTimeout(function() {
                    if (confirm('Sync completed. Would you like to refresh the page to see updated results?')) {
                        location.reload();
                    }
                }, 3000);
            },
            error: function(xhr, status, error) {
                console.log('Sync failed with error:', error);
                clearInterval(syncInterval);
                
                // Reset sync state
                syncInProgress = false;
                $('#ccs-sync-selected, #ccs-sync-courses, #ccs-get-courses').prop('disabled', false);
                progress.hide();
                
                let errorDetails = '';
                try {
                    const errorResponse = JSON.parse(xhr.responseText);
                    errorDetails = errorResponse.data && errorResponse.data.message ? errorResponse.data.message : xhr.responseText;
                } catch (e) {
                    errorDetails = xhr.responseText || error;
                }
                
                $('#ccs-sync-message').html('<div class="notice notice-error inline"><p><strong>Connection Error:</strong> ' + error + '<br><small>Details: ' + errorDetails + '</small></p></div>');
                results.show();
            }
        });
        
        return false;
    });
}
