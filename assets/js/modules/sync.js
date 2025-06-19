
/**
 * Course synchronization functionality
 */
export function initSyncManager($) {
    $('#ccs-sync-courses').on('click', function() {
        console.log('Sync button clicked');
        
        const selectedCourses = $('.ccs-course-checkbox:checked').map(function() {
            return $(this).val();
        }).get();
        
        console.log('Selected courses:', selectedCourses);
        
        if (selectedCourses.length === 0) {
            alert('Please select at least one course to sync.');
            return;
        }
        
        const button = $(this);
        const progress = $('#ccs-sync-progress');
        const results = $('#ccs-sync-results');
        const statusText = $('#ccs-sync-status');
        const progressBar = $('#ccs-sync-progress-bar');
        
        console.log('Starting sync process...');
        
        button.attr('disabled', true);
        progress.show();
        results.hide();
        
        // Clear any previous messages
        $('#ccs-sync-message').empty();
        
        let syncInterval = setInterval(function() {
            $.ajax({
                url: ccsAjax.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'ccs_sync_status',
                    nonce: ccsAjax.syncStatusNonce
                },
                success: function(response) {
                    console.log('Status response:', response);
                    if (response.success && response.data) {
                        statusText.html(response.data.status);
                        if (response.data.processed && response.data.total) {
                            const percent = (response.data.processed / response.data.total) * 100;
                            progressBar.css('width', percent + '%');
                        }
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Status check error:', error, xhr.responseText);
                }
            });
        }, 2000);
        
        $.ajax({
            url: ccsAjax.ajaxUrl,
            type: 'POST',
            data: {
                action: 'ccs_sync_courses',
                nonce: ccsAjax.syncCoursesNonce,
                course_ids: selectedCourses
            },
            success: function(response) {
                console.log('Sync response:', response);
                clearInterval(syncInterval);
                button.attr('disabled', false);
                progress.hide();
                
                if (response.success) {
                    $('#ccs-sync-message').html('<div class="notice notice-success inline"><p>' + response.data.message + '</p></div>');
                    $('#ccs-imported').text(response.data.imported || 0);
                    $('#ccs-skipped').text(response.data.skipped || 0);
                    $('#ccs-errors').text(response.data.errors || 0);
                } else {
                    const errorMessage = response.data && response.data.message ? response.data.message : 'Sync failed.';
                    $('#ccs-sync-message').html('<div class="notice notice-error inline"><p>' + errorMessage + '</p></div>');
                    console.error('Sync failed:', response);
                }
                
                results.show();
                
                setTimeout(function() {
                    location.reload();
                }, 5000);
            },
            error: function(xhr, status, error) {
                console.error('Sync error:', error, xhr.responseText);
                clearInterval(syncInterval);
                button.attr('disabled', false);
                progress.hide();
                
                let errorDetails = '';
                try {
                    const errorResponse = JSON.parse(xhr.responseText);
                    errorDetails = errorResponse.data ? errorResponse.data : xhr.responseText;
                } catch (e) {
                    errorDetails = xhr.responseText || error;
                }
                
                $('#ccs-sync-message').html('<div class="notice notice-error inline"><p>Connection error occurred. Please try again. Error: ' + error + '<br>Details: ' + errorDetails + '</p></div>');
                results.show();
            }
        });
    });
}
