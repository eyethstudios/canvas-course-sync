
/**
 * Course synchronization functionality
 */
export function initSyncManager($) {
    console.log('CCS Debug: Initializing sync manager');
    
    // Sync selected courses
    $('#ccs-sync-selected, #ccs-sync-courses').on('click', function(e) {
        e.preventDefault();
        console.log('CCS Debug: Sync button clicked');
        
        const selectedCourses = $('.ccs-course-checkbox:checked').map(function() {
            return $(this).val();
        }).get();
        
        console.log('CCS Debug: Selected courses:', selectedCourses);
        
        if (selectedCourses.length === 0) {
            alert('Please select at least one course to sync.');
            return;
        }
        
        const button = $(this);
        const progress = $('#ccs-sync-progress');
        const results = $('#ccs-sync-results');
        const statusText = $('#ccs-sync-status');
        const progressBar = $('#ccs-sync-progress-bar');
        
        console.log('CCS Debug: Starting sync process...');
        
        // Disable button and show progress
        button.prop('disabled', true);
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
                    console.log('CCS Debug: Status response:', response);
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
                    console.error('CCS Debug: Status check error:', error, xhr.responseText);
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
                console.log('CCS Debug: Sync response:', response);
                clearInterval(syncInterval);
                button.prop('disabled', false);
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
                    console.error('CCS Debug: Sync failed:', response);
                }
                
                results.show();
                
                // Auto-refresh after 5 seconds
                setTimeout(function() {
                    if (confirm('Sync completed. Would you like to refresh the page to see updated results?')) {
                        location.reload();
                    }
                }, 3000);
            },
            error: function(xhr, status, error) {
                console.error('CCS Debug: Sync AJAX error:', error, xhr.responseText);
                clearInterval(syncInterval);
                button.prop('disabled', false);
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
    });
    
    console.log('CCS Debug: Sync manager initialized');
}
