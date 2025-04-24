
/**
 * Canvas Course Sync Admin JavaScript
 */
jQuery(document).ready(function($) {
    'use strict';
    
    console.log('Canvas Course Sync admin.js initialized');
    
    // Debug element visibility
    console.log('Clear logs button exists:', $('#ccs-clear-logs').length > 0);
    console.log('Load courses button exists:', $('#ccs-load-courses').length > 0);
    
    // Direct event binding for clear logs button
    $('#ccs-clear-logs').on('click', function(e) {
        e.preventDefault();
        console.log('Clear logs button clicked');
        const button = $(this);
        button.attr('disabled', true);
        
        $.ajax({
            url: ccsData.ajaxUrl,
            type: 'POST',
            data: {
                action: 'ccs_clear_logs',
                nonce: ccsData.clearLogsNonce
            },
            success: function(response) {
                button.attr('disabled', false);
                console.log('Clear logs response:', response);
                if (response.success) {
                    $('.ccs-log-container').html('<p>Logs cleared successfully.</p>');
                } else {
                    alert('Failed to clear logs: ' + (response.data || 'Unknown error'));
                }
            },
            error: function(xhr, status, error) {
                button.attr('disabled', false);
                console.error('Clear logs error:', error, xhr.responseText);
                alert('Failed to clear logs. Please try again. Error: ' + error);
            }
        });

        return false;
    });
    
    // Direct event binding for load courses button
    $('#ccs-load-courses').on('click', function(e) {
        e.preventDefault();
        console.log('Load courses button clicked');
        const button = $(this);
        const courseList = $('#ccs-course-list');
        const loadingText = $('#ccs-loading-courses');
        const coursesWrapper = $('#ccs-courses-wrapper');
        
        button.attr('disabled', true);
        loadingText.show();
        courseList.html('');
        
        console.log('Sending AJAX request to load courses, nonce:', ccsData.getCoursesNonce);
        
        $.ajax({
            url: ccsData.ajaxUrl,
            type: 'POST',
            data: {
                action: 'ccs_get_courses',
                nonce: ccsData.getCoursesNonce
            },
            success: function(response) {
                console.log('Load courses response:', response);
                if (response.success && Array.isArray(response.data)) {
                    // Sort courses by creation date (most recent first)
                    const sortedCourses = response.data.sort((a, b) => {
                        // Debug creation dates
                        console.log(`Course ${a.name}: created_at = ${a.created_at}`);
                        console.log(`Course ${b.name}: created_at = ${b.created_at}`);
                        return new Date(b.created_at || 0) - new Date(a.created_at || 0);
                    });
                    
                    console.log('Sorted courses:', sortedCourses);
                    
                    let html = '<div class="ccs-select-all">' +
                        '<label>' +
                        '<input type="checkbox" id="ccs-select-all-checkbox" checked> ' +
                        'Select/Deselect All</label>' +
                        '</div>';
                        
                    sortedCourses.forEach(function(course) {
                        html += '<div class="ccs-course-item">' +
                            '<label>' +
                            '<input type="checkbox" class="ccs-course-checkbox" ' +
                            'value="' + course.id + '" checked> ' +
                            course.name + '</label>' +
                            '</div>';
                    });
                    
                    courseList.html(html);
                    coursesWrapper.show();
                } else {
                    const errorMessage = response.data || 'Error loading courses. Please try again.';
                    courseList.html('<p class="error">Error loading courses: ' + errorMessage + '</p>');
                    coursesWrapper.show();
                }
            },
            error: function(xhr, status, error) {
                console.error('Load courses error:', error, xhr.responseText);
                courseList.html('<p class="error">Connection error occurred. Please try again.</p>');
                coursesWrapper.show();
            },
            complete: function() {
                button.attr('disabled', false);
                loadingText.hide();
            }
        });

        return false;
    });

    // Handle select all checkbox
    $(document).on('change', '#ccs-select-all-checkbox', function() {
        $('.ccs-course-checkbox').prop('checked', $(this).prop('checked'));
    });
    
    // Connection tester functionality
    $('#ccs-test-connection').on('click', function(e) {
        e.preventDefault();
        console.log('Test connection button clicked');
        const button = $(this);
        const resultContainer = $('#ccs-connection-result');
        
        button.attr('disabled', true);
        resultContainer.html('<div class="ccs-spinner"></div> Testing connection...');
        
        $.ajax({
            url: ccsData.ajaxUrl,
            type: 'POST',
            data: {
                action: 'ccs_test_connection',
                nonce: ccsData.testConnectionNonce
            },
            success: function(response) {
                button.attr('disabled', false);
                console.log('Connection test response:', response);
                
                if (response.success) {
                    resultContainer.html('<div class="ccs-success">' + response.data + '</div>');
                } else {
                    resultContainer.html('<div class="ccs-error">Connection failed: ' + (response.data || 'Unknown error') + '</div>');
                }
            },
            error: function(xhr, status, error) {
                button.attr('disabled', false);
                console.error('Connection test error:', error, xhr.responseText);
                resultContainer.html('<div class="ccs-error">Connection error: ' + error + '</div>');
            }
        });
        
        return false;
    });
    
    // Sync courses functionality
    $('#ccs-sync-courses').on('click', function(e) {
        e.preventDefault();
        console.log('Sync courses button clicked');
        const button = $(this);
        const syncProgress = $('#ccs-sync-progress');
        const syncResults = $('#ccs-sync-results');
        const syncMessage = $('#ccs-sync-message');
        const importedCount = $('#ccs-imported');
        const skippedCount = $('#ccs-skipped');
        const errorsCount = $('#ccs-errors');
        const progressBar = $('#ccs-sync-progress-bar');
        const syncStatus = $('#ccs-sync-status');
        
        // Get selected course IDs
        let courseIds = [];
        $('.ccs-course-checkbox:checked').each(function() {
            courseIds.push($(this).val());
        });
        
        if (courseIds.length === 0) {
            alert('Please select at least one course to sync.');
            return false;
        }
        
        button.attr('disabled', true);
        syncProgress.show();
        syncResults.hide();
        syncMessage.html('');
        importedCount.text('0');
        skippedCount.text('0');
        errorsCount.text('0');
        progressBar.css('width', '0%');
        syncStatus.text('Initializing...');
        
        console.log('Starting sync for courses:', courseIds);
        
        // Set up status checking interval
        let statusInterval = setInterval(function() {
            $.ajax({
                url: ccsData.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'ccs_sync_status',
                    nonce: ccsData.syncStatusNonce
                },
                success: function(response) {
                    console.log('Sync status update:', response);
                    if (response.success && response.data) {
                        syncStatus.text(response.data.status);
                        
                        // Update progress bar if we have processed/total info
                        if (response.data.processed && response.data.total) {
                            const percent = Math.min(100, Math.round((response.data.processed / response.data.total) * 100));
                            progressBar.css('width', percent + '%');
                        }
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Error checking sync status:', error);
                }
            });
        }, 2000);
        
        // AJAX call to start course sync
        $.ajax({
            url: ccsData.ajaxUrl,
            type: 'POST',
            data: {
                action: 'ccs_sync_courses',
                nonce: ccsData.syncNonce,
                course_ids: courseIds
            },
            success: function(response) {
                clearInterval(statusInterval);
                console.log('Sync courses response:', response);
                button.attr('disabled', false);
                syncProgress.hide();
                syncResults.show();
                
                if (response.success) {
                    syncMessage.html('<div class="ccs-success">' + response.data.message + '</div>');
                    importedCount.text(response.data.imported);
                    skippedCount.text(response.data.skipped);
                    errorsCount.text(response.data.errors);
                } else {
                    syncMessage.html('<div class="ccs-error">Sync failed: ' + (response.data || 'Unknown error') + '</div>');
                }
            },
            error: function(xhr, status, error) {
                clearInterval(statusInterval);
                console.error('Sync courses error:', error, xhr.responseText);
                button.attr('disabled', false);
                syncProgress.hide();
                syncResults.show();
                syncMessage.html('<div class="ccs-error">Sync error: ' + error + '</div>');
            }
        });
        
        return false;
    });
    
    // Check if ccsData object exists and contains necessary data
    if (typeof ccsData === 'undefined') {
        console.error('ccsData is not defined. Scripts may not be properly localized.');
    } else {
        console.log('ccsData available:', ccsData);
    }

    // Console log document ready complete
    console.log('Canvas Course Sync admin.js initialization completed');
});
