/**
 * Canvas Course Sync Admin JavaScript
 */
(function($) {
    'use strict';
    
    $(document).ready(function() {
        // Test connection button
        $('#ccs-test-connection').on('click', function() {
            const button = $(this);
            const statusSpan = $('#ccs-connection-status');
            
            button.attr('disabled', true);
            statusSpan.html('Testing connection...').removeClass('ccs-status-success ccs-status-error');
            
            $.ajax({
                url: ccsData.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'ccs_test_connection',
                    nonce: ccsData.testConnectionNonce
                },
                success: function(response) {
                    button.attr('disabled', false);
                    if (response.success) {
                        statusSpan.html('✓ ' + response.data).addClass('ccs-status-success');
                    } else {
                        statusSpan.html('✗ ' + response.data).addClass('ccs-status-error');
                    }
                },
                error: function() {
                    button.attr('disabled', false);
                    statusSpan.html('✗ Connection error').addClass('ccs-status-error');
                }
            });
        });
        
        // Clear logs button
        $('#ccs-clear-logs').on('click', function() {
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
                    if (response.success) {
                        $('.ccs-log-container').html('<p>Logs cleared successfully.</p>');
                    }
                },
                error: function() {
                    button.attr('disabled', false);
                    alert('Failed to clear logs. Please try again.');
                }
            });
        });

        // Load courses button
        $('#ccs-load-courses').on('click', function() {
            const button = $(this);
            const courseList = $('#ccs-course-list');
            const loadingText = $('#ccs-loading-courses');
            
            button.attr('disabled', true);
            loadingText.show();
            courseList.html('');
            
            $.ajax({
                url: ccsData.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'ccs_get_courses',
                    nonce: ccsData.getCoursesNonce
                },
                success: function(response) {
                    if (response.success) {
                        let html = '<div class="ccs-select-all">' +
                            '<label>' +
                            '<input type="checkbox" id="ccs-select-all-checkbox" checked> ' +
                            'Select/Deselect All</label>' +
                            '</div>';
                            
                        response.data.forEach(function(course) {
                            html += '<div class="ccs-course-item">' +
                                '<label>' +
                                '<input type="checkbox" class="ccs-course-checkbox" ' +
                                'value="' + course.id + '" checked> ' +
                                course.name + '</label>' +
                                '</div>';
                        });
                        
                        courseList.html(html);
                        $('#ccs-courses-wrapper').show();
                    } else {
                        courseList.html('<p class="error">Error loading courses: ' + response.data + '</p>');
                    }
                },
                error: function() {
                    courseList.html('<p class="error">Connection error occurred. Please try again.</p>');
                },
                complete: function() {
                    button.attr('disabled', false);
                    loadingText.hide();
                }
            });
        });

        // Handle select all checkbox
        $(document).on('change', '#ccs-select-all-checkbox', function() {
            $('.ccs-course-checkbox').prop('checked', $(this).prop('checked'));
        });
        
        // Sync courses button
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
                        $('#ccs-sync-message').html('<div class="notice notice-error inline"><p>' + response.data.message || 'Sync failed.' + '</p></div>');
                    }
                    
                    results.show();
                    
                    // Refresh the page to update log display
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
    });
})(jQuery);
