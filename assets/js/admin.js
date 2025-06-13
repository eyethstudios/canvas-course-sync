
/**
 * Canvas Course Sync Admin JavaScript
 */

jQuery(document).ready(function($) {
    // Connection testing functionality
    function initConnectionTester() {
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
                    
                    console.log('Connection test response:', response);
                    
                    if (response.success) {
                        resultContainer.html('<div class="ccs-success">' + response.data + '</div>');
                    } else {
                        const errorMsg = response.data || 'Unknown error occurred';
                        resultContainer.html('<div class="ccs-error">Connection failed: ' + errorMsg + '</div>');
                        console.error('Connection test failed:', errorMsg);
                    }
                },
                error: function(xhr, status, error) {
                    button.attr('disabled', false);
                    console.error('Connection test AJAX error:', {status, error, responseText: xhr.responseText});
                    resultContainer.html('<div class="ccs-error">Connection error: ' + error + '</div>');
                },
                timeout: 30000 // 30 second timeout
            });
        });
    }

    // Sync functionality
    function initSyncManager() {
        $('#ccs-sync-selected').on('click', function() {
            const button = $(this);
            const resultContainer = $('#ccs-sync-result');
            const selectedCourses = [];
            
            $('input[name="selected_courses[]"]:checked').each(function() {
                selectedCourses.push($(this).val());
            });
            
            if (selectedCourses.length === 0) {
                resultContainer.html('<div class="ccs-error">Please select at least one course to sync.</div>');
                return;
            }
            
            button.attr('disabled', true);
            resultContainer.html('<div class="ccs-spinner"></div> Syncing courses...');
            
            $.ajax({
                url: ccsData.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'ccs_sync_courses',
                    nonce: ccsData.syncNonce,
                    course_ids: selectedCourses
                },
                success: function(response) {
                    button.attr('disabled', false);
                    
                    if (response.success) {
                        const data = response.data;
                        resultContainer.html('<div class="ccs-success">' + data.message + '</div>');
                    } else {
                        const errorMsg = response.data || 'Sync failed';
                        resultContainer.html('<div class="ccs-error">' + errorMsg + '</div>');
                    }
                },
                error: function(xhr, status, error) {
                    button.attr('disabled', false);
                    resultContainer.html('<div class="ccs-error">Error: ' + error + '</div>');
                },
                timeout: 120000 // 2 minute timeout for sync
            });
        });
    }

    // Course loading functionality
    function initCoursesLoader() {
        $('#ccs-load-courses').on('click', function() {
            const button = $(this);
            const container = $('#ccs-courses-list');
            
            button.attr('disabled', true);
            container.html('<div class="ccs-spinner"></div> Loading courses...');
            
            $.ajax({
                url: ccsData.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'ccs_get_courses',
                    nonce: ccsData.getCoursesNonce
                },
                success: function(response) {
                    button.attr('disabled', false);
                    
                    if (response.success && response.data) {
                        let html = '<div class="ccs-courses-selection">';
                        html += '<p><label><input type="checkbox" id="ccs-select-all"> Select All</label></p>';
                        
                        response.data.forEach(function(course) {
                            html += '<p><label>';
                            html += '<input type="checkbox" name="selected_courses[]" value="' + course.id + '"> ';
                            html += course.name;
                            html += '</label></p>';
                        });
                        
                        html += '</div>';
                        container.html(html);
                        
                        // Add select all functionality
                        $('#ccs-select-all').on('change', function() {
                            $('input[name="selected_courses[]"]').prop('checked', this.checked);
                        });
                    } else {
                        const errorMsg = response.data || 'Failed to load courses';
                        container.html('<div class="ccs-error">' + errorMsg + '</div>');
                    }
                },
                error: function(xhr, status, error) {
                    button.attr('disabled', false);
                    container.html('<div class="ccs-error">Error loading courses: ' + error + '</div>');
                },
                timeout: 30000
            });
        });
    }

    // Logs management functionality
    function initLogsManager() {
        $('#ccs-clear-logs').on('click', function() {
            const button = $(this);
            const resultContainer = $('#ccs-logs-result');
            
            if (!confirm('Are you sure you want to clear all logs?')) {
                return;
            }
            
            button.attr('disabled', true);
            resultContainer.html('<div class="ccs-spinner"></div> Clearing logs...');
            
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
                        resultContainer.html('<div class="ccs-success">' + response.data + '</div>');
                        // Refresh logs display
                        $('#ccs-logs-display').html('<p>Logs cleared.</p>');
                    } else {
                        const errorMsg = response.data || 'Failed to clear logs';
                        resultContainer.html('<div class="ccs-error">' + errorMsg + '</div>');
                    }
                },
                error: function(xhr, status, error) {
                    button.attr('disabled', false);
                    resultContainer.html('<div class="ccs-error">Error: ' + error + '</div>');
                }
            });
        });
    }

    // Auto-sync functionality
    function initAutoSync() {
        $('#ccs-trigger-auto-sync').on('click', function() {
            const button = $(this);
            const resultContainer = $('#ccs-auto-sync-result');
            
            button.attr('disabled', true);
            resultContainer.html('<div class="ccs-spinner"></div> Running auto-sync...');
            
            $.ajax({
                url: ccsData.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'ccs_run_auto_sync',
                    nonce: ccsData.autoSyncNonce
                },
                success: function(response) {
                    button.attr('disabled', false);
                    
                    if (response.success) {
                        resultContainer.html('<div class="ccs-success">' + response.data.message + '</div>');
                    } else {
                        const errorMsg = response.data || 'Auto-sync failed';
                        resultContainer.html('<div class="ccs-error">' + errorMsg + '</div>');
                    }
                },
                error: function(xhr, status, error) {
                    button.attr('disabled', false);
                    resultContainer.html('<div class="ccs-error">Error: ' + error + '</div>');
                },
                timeout: 60000 // 60 second timeout for auto-sync
            });
        });
    }

    // Initialize all functionality
    initConnectionTester();
    initSyncManager();
    initCoursesLoader();
    initLogsManager();
    initAutoSync();
});
