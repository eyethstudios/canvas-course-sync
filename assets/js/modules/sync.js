
/**
 * Course synchronization functionality
 */
export function initSyncManager($) {
    console.log('CCS_Sync: initSyncManager() called at', new Date().toISOString());
    
    let syncInProgress = false;
    
    // Log existing event handlers before cleanup
    const existingHandlers = $._data(document, 'events');
    console.log('CCS_Sync: Existing event handlers before cleanup:', existingHandlers);
    
    // Remove ALL existing sync handlers to prevent duplicates
    $(document).off('click.sync-manager');
    $('#ccs-sync-selected').off();
    console.log('CCS_Sync: Removed existing event handlers');
    
    // Check for conflicting event handlers
    setTimeout(() => {
        const postCleanupHandlers = $._data(document, 'events');
        console.log('CCS_Sync: Event handlers after cleanup:', postCleanupHandlers);
    }, 100);
    
    // Use namespaced events to prevent conflicts
    $(document).on('click.sync-manager', '#ccs-sync-selected', function(e) {
        console.log('CCS_Sync: Sync button clicked at', new Date().toISOString());
        console.log('CCS_Sync: Event target:', e.target);
        console.log('CCS_Sync: Event currentTarget:', e.currentTarget);
        console.log('CCS_Sync: syncInProgress flag:', syncInProgress);
        
        e.preventDefault();
        e.stopPropagation();
        e.stopImmediatePropagation();
        
        // Immediate duplicate prevention check
        if (syncInProgress) {
            console.log('CCS_Sync: DUPLICATE REQUEST BLOCKED - Sync already in progress');
            alert('Sync is already in progress. Please wait for it to complete.');
            return false;
        }
        
        // Set sync in progress flag IMMEDIATELY to prevent any race conditions
        syncInProgress = true;
        console.log('CCS_Sync: Set syncInProgress = true');
        
        const selectedCourses = $('.ccs-course-checkbox:checked').map(function() {
            return $(this).val();
        }).get();
        
        console.log('CCS_Sync: Selected courses:', selectedCourses);
        
        if (selectedCourses.length === 0) {
            syncInProgress = false; // Reset flag
            console.log('CCS_Sync: No courses selected, reset syncInProgress = false');
            alert('Please select at least one course to sync.');
            return false;
        }
        
        // Enhanced filtering to prevent already synced courses
        const newCoursesToSync = [];
        const alreadySyncedCourses = [];
        
        $('.ccs-course-checkbox:checked').each(function() {
            const courseId = $(this).val();
            const courseRow = $(this).closest('.ccs-course-item, tr');
            const statusElement = courseRow.find('.ccs-course-status');
            const status = statusElement.text().toLowerCase().trim();
            
            console.log('CCS_Sync: Course', courseId, 'status:', status);
            
            // Only sync if not already synced
            if (status !== 'already synced' && status !== 'synced' && !status.includes('synced')) {
                newCoursesToSync.push(courseId);
                console.log('CCS_Sync: Course', courseId, 'added to sync queue');
            } else {
                alreadySyncedCourses.push(courseId);
                console.log('CCS_Sync: Course', courseId, 'skipped - already synced');
            }
        });
        
        console.log('CCS_Sync: New courses to sync:', newCoursesToSync);
        console.log('CCS_Sync: Already synced courses:', alreadySyncedCourses);
        
        if (newCoursesToSync.length === 0) {
            syncInProgress = false; // Reset flag
            console.log('CCS_Sync: No new courses to sync, reset syncInProgress = false');
            alert('Selected courses are already synced. Please select new courses to sync.');
            return false;
        }
        
        // Single confirmation dialog for new courses only
        const confirmMessage = 'Are you sure you want to sync ' + newCoursesToSync.length + ' new course(s)?';
        if (alreadySyncedCourses.length > 0) {
            const warningMessage = 'Warning: ' + alreadySyncedCourses.length + ' selected course(s) are already synced and will be skipped.\n\n' + confirmMessage;
            if (!confirm(warningMessage)) {
                syncInProgress = false; // Reset flag
                console.log('CCS_Sync: User cancelled sync, reset syncInProgress = false');
                return false;
            }
        } else {
            if (!confirm(confirmMessage)) {
                syncInProgress = false; // Reset flag
                console.log('CCS_Sync: User cancelled sync, reset syncInProgress = false');
                return false;
            }
        }
        
        console.log('CCS_Sync: User confirmed sync, proceeding...');
        
        const button = $(this);
        const progress = $('#ccs-sync-progress');
        const results = $('#ccs-sync-results');
        const statusText = $('#ccs-sync-status');
        const progressBar = $('#ccs-sync-progress-bar');
        
        // Disable ALL sync-related buttons immediately
        $('#ccs-sync-selected, #ccs-sync-courses, #ccs-get-courses').prop('disabled', true);
        console.log('CCS_Sync: Disabled all sync buttons');
        
        progress.show();
        results.hide();
        
        // Clear any previous messages
        $('#ccs-sync-message').empty();
        statusText.html('Initializing sync...');
        progressBar.css('width', '0%').text('0%');
        
        console.log('CCS_Sync: Starting status polling...');
        
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
                    console.log('CCS_Sync: Status poll response:', response);
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
                    console.error('CCS_Sync: Status check error:', error);
                    console.error('CCS_Sync: Status check xhr:', xhr);
                }
            });
        }, 2000);
        
        // Start the sync process with filtered courses
        console.log('CCS_Sync: Starting sync AJAX request...');
        console.log('CCS_Sync: AJAX URL:', ccsAjax.ajaxUrl);
        console.log('CCS_Sync: Nonce:', ccsAjax.syncCoursesNonce);
        
        $.ajax({
            url: ccsAjax.ajaxUrl,
            type: 'POST',
            data: {
                action: 'ccs_sync_courses',
                nonce: ccsAjax.syncCoursesNonce,
                course_ids: newCoursesToSync
            },
            success: function(response) {
                console.log('CCS_Sync: Sync AJAX success response:', response);
                clearInterval(syncInterval);
                
                // Reset sync state
                syncInProgress = false;
                console.log('CCS_Sync: Reset syncInProgress = false after success');
                
                $('#ccs-sync-selected, #ccs-sync-courses, #ccs-get-courses').prop('disabled', false);
                progress.hide();
                
                if (response.success && response.data) {
                    const data = response.data;
                    $('#ccs-sync-message').html('<div class="notice notice-success inline"><p>' + (data.message || 'Sync completed successfully!') + '</p></div>');
                    $('#ccs-imported').text(data.imported || 0);
                    $('#ccs-skipped').text(data.skipped || 0);
                    $('#ccs-errors').text(data.errors || 0);
                    console.log('CCS_Sync: Sync completed successfully:', data);
                } else {
                    const errorMessage = response.data && response.data.message ? response.data.message : 'Sync failed with unknown error.';
                    $('#ccs-sync-message').html('<div class="notice notice-error inline"><p>' + errorMessage + '</p></div>');
                    console.error('CCS_Sync: Sync completed with error:', errorMessage);
                }
                
                results.show();
                
                // Auto-refresh after 3 seconds (removed extra confirmation)
                setTimeout(function() {
                    console.log('CCS_Sync: Auto-refreshing page after sync completion');
                    location.reload();
                }, 2000);
            },
            error: function(xhr, status, error) {
                console.error('CCS_Sync: Sync AJAX error:', error);
                console.error('CCS_Sync: Error xhr:', xhr);
                console.error('CCS_Sync: Error status:', status);
                console.error('CCS_Sync: Response text:', xhr.responseText);
                
                clearInterval(syncInterval);
                
                // Reset sync state
                syncInProgress = false;
                console.log('CCS_Sync: Reset syncInProgress = false after error');
                
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
    
    console.log('CCS_Sync: initSyncManager() completed');
}
