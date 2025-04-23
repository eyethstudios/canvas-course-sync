/**
 * Canvas Course Sync Admin JavaScript
 */
jQuery(document).ready(function($) {
    'use strict';
    
    // Initialize connection tester
    if (typeof initConnectionTester === 'function') {
        initConnectionTester($);
    } else {
        console.error('Connection tester module not loaded');
    }
    
    // Initialize log manager
    initLogManager($);
    
    // Initialize course manager
    initCourseManager($);
    
    // Initialize sync manager
    if (typeof initSyncManager === 'function') {
        initSyncManager($);
    } else {
        console.error('Sync manager module not loaded');
    }
});

/**
 * Log management functionality
 */
function initLogManager($) {
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
                } else {
                    alert('Failed to clear logs: ' + (response.data || 'Unknown error'));
                }
            },
            error: function(xhr, status, error) {
                button.attr('disabled', false);
                alert('Failed to clear logs. Please try again. Error: ' + error);
            }
        });
    });
}

/**
 * Course management functionality
 */
function initCourseManager($) {
    // Load courses button handler
    $('#ccs-load-courses').on('click', function() {
        const button = $(this);
        const courseList = $('#ccs-course-list');
        const loadingText = $('#ccs-loading-courses');
        const coursesWrapper = $('#ccs-courses-wrapper');
        
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
                if (response.success && Array.isArray(response.data)) {
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
                    coursesWrapper.show();
                } else {
                    const errorMessage = response.data || 'Error loading courses. Please try again.';
                    courseList.html('<p class="error">Error loading courses: ' + errorMessage + '</p>');
                    coursesWrapper.show();
                }
            },
            error: function(xhr, status, error) {
                courseList.html('<p class="error">Connection error occurred. Please try again.</p>');
                coursesWrapper.show();
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
}

/**
 * Connection tester functionality
 */
function initConnectionTester($) {
    $('#ccs-test-connection').on('click', function() {
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
                
                if (response.success) {
                    resultContainer.html('<div class="ccs-success">' + response.data + '</div>');
                } else {
                    resultContainer.html('<div class="ccs-error">Connection failed: ' + (response.data || 'Unknown error') + '</div>');
                }
            },
            error: function(xhr, status, error) {
                button.attr('disabled', false);
                resultContainer.html('<div class="ccs-error">Connection error: ' + error + '</div>');
            }
        });
    });
}

/**
 * Sync manager functionality
 */
function initSyncManager($) {
    $('#ccs-sync-courses').on('click', function() {
        const button = $(this);
        const courseList = $('#ccs-course-list');
        const syncProgress = $('#ccs-sync-progress');
        const syncResults = $('#ccs-sync-results');
        const syncMessage = $('#ccs-sync-message');
        const importedCount = $('#ccs-imported');
        const skippedCount = $('#ccs-skipped');
        const errorsCount = $('#ccs-errors');
        const progressBar = $('#ccs-sync-progress-bar');
        const syncStatus = $('#ccs-sync-status');
        
        button.attr('disabled', true);
        syncProgress.show();
        syncResults.hide();
        syncMessage.html('');
        importedCount.text('0');
        skippedCount.text('0');
        errorsCount.text('0');
        progressBar.css('width', '0%');
        syncStatus.text('Initializing...');
        
        // Get selected course IDs
        let courseIds = [];
        $('.ccs-course-checkbox:checked').each(function() {
            courseIds.push($(this).val());
        });
        
        if (courseIds.length === 0) {
            alert('Please select at least one course to sync.');
            button.attr('disabled', false);
            syncProgress.hide();
            return;
        }
        
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
                button.attr('disabled', false);
                syncProgress.hide();
                syncResults.show();
                syncMessage.html('<div class="ccs-error">Sync error: ' + error + '</div>');
            }
        });
    });
    
    function updateSyncStatus() {
        $.ajax({
            url: ccsData.ajaxUrl,
            type: 'POST',
            data: {
                action: 'ccs_sync_status',
                nonce: ccsData.syncStatusNonce
            },
            success: function(response) {
                if (response.success) {
                    // Update progress bar and status
                    const progress = response.data.progress;
                    $('#ccs-sync-progress-bar').css('width', progress + '%');
                    $('#ccs-sync-status').text('Syncing: ' + progress + '%');
                } else {
                    console.error('Failed to get sync status: ' + (response.data || 'Unknown error'));
                }
            },
            error: function(xhr, status, error) {
                console.error('Error getting sync status: ' + error);
            }
        });
    }
}
