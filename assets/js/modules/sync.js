
/**
 * Course synchronization functionality
 */
export function initSyncManager($) {
    $('#ccs-sync-courses').on('click', function() {
        const selectedCourses = $('.ccs-course-checkbox:checked').map(function() {
            return $(this).val();
        }).get();
        
        if (selectedCourses.length === 0) {
            alert('Please select at least one course to sync.');
            return;
        }
        
        const button = $(this);
        const progress = $('#ccs-sync-progress');
        const results = $('#ccs-sync-results');
        const statusText = $('#ccs-sync-status');
        const progressBar = $('#ccs-sync-progress-bar');
        
        button.attr('disabled', true);
        progress.show();
        results.hide();
        
        let syncInterval = setInterval(function() {
            $.ajax({
                url: ccsData.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'ccs_sync_status',
                    nonce: ccsData.syncStatusNonce
                },
                success: function(response) {
                    if (response.success && response.data) {
                        statusText.html(response.data.status);
                        if (response.data.processed && response.data.total) {
                            const percent = (response.data.processed / response.data.total) * 100;
                            progressBar.css('width', percent + '%');
                        }
                    }
                }
            });
        }, 2000);
        
        $.ajax({
            url: ccsData.ajaxUrl,
            type: 'POST',
            data: {
                action: 'ccs_sync_courses',
                nonce: ccsData.syncNonce,
                course_ids: selectedCourses
            },
            success: function(response) {
                clearInterval(syncInterval);
                button.attr('disabled', false);
                progress.hide();
                
                if (response.success) {
                    $('#ccs-sync-message').html('<div class="notice notice-success inline"><p>' + response.data.message + '</p></div>');
                    $('#ccs-imported').text(response.data.imported);
                    $('#ccs-skipped').text(response.data.skipped);
                    $('#ccs-errors').text(response.data.errors);
                } else {
                    $('#ccs-sync-message').html('<div class="notice notice-error inline"><p>' + (response.data.message || 'Sync failed.') + '</p></div>');
                }
                
                results.show();
                
                setTimeout(function() {
                    location.reload();
                }, 5000);
            },
            error: function() {
                clearInterval(syncInterval);
                button.attr('disabled', false);
                progress.hide();
                $('#ccs-sync-message').html('<div class="notice notice-error inline"><p>Connection error occurred. Please try again.</p></div>');
                results.show();
            }
        });
    });
}
