
/**
 * Course synchronization functionality
 */
export function initSyncManager($) {
    let syncInProgress = false;
    
    // Completely remove any existing handlers to prevent duplicates
    $('#ccs-sync-selected').off('click.sync');
    
    $('#ccs-sync-selected').on('click.sync', function(e) {
        e.preventDefault();
        e.stopPropagation();
        e.stopImmediatePropagation();
        
        // Prevent duplicate syncs
        if (syncInProgress) {
            console.log('Sync already in progress, ignoring duplicate request');
            return false;
        }
        
        const selectedCourses = $('.ccs-course-checkbox:checked').map(function() {
            return $(this).val();
        }).get();
        
        if (selectedCourses.length === 0) {
            alert('Please select at least one course to sync.');
            return false;
        }
        
        // Single confirmation dialog - prevent multiple popups
        if (!confirm('Are you sure you want to sync ' + selectedCourses.length + ' selected course(s)?')) {
            return false;
        }
        
        const button = $(this);
        const progress = $('#ccs-sync-progress');
        const results = $('#ccs-sync-results');
        const statusText = $('#ccs-sync-status');
        const progressBar = $('#ccs-sync-progress-bar');
        
        // Set sync in progress flag immediately
        syncInProgress = true;
        
        // Disable all sync buttons and show progress
        $('#ccs-sync-selected, #ccs-sync-courses').prop('disabled', true);
        progress.show();
        results.hide();
        
        // Clear any previous messages
        $('#ccs-sync-message').empty();
        
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
        
        // Start the sync process
        $.ajax({
            url: ccsAjax.ajaxUrl,
            type: 'POST',
            data: {
                action: 'ccs_sync_courses',
                nonce: ccsAjax.syncCoursesNonce,
                course_ids: selectedCourses
            },
            success: function(response) {
                clearInterval(syncInterval);
                
                // Re-enable buttons and reset sync flag
                $('#ccs-sync-selected, #ccs-sync-courses').prop('disabled', false);
                syncInProgress = false;
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
                clearInterval(syncInterval);
                
                // Re-enable buttons and reset sync flag
                $('#ccs-sync-selected, #ccs-sync-courses').prop('disabled', false);
                syncInProgress = false;
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
