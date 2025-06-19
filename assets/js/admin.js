(function($) {
    'use strict';

    // Wait for DOM and ensure ccsAjax is available
    $(document).ready(function() {
        console.log('CCS Admin script loaded');
        console.log('Document ready, jQuery version:', $.fn.jquery);
        
        // Check if required variables are available
        if (typeof ccsAjax === 'undefined') {
            console.error('ccsAjax variable not found - AJAX calls will fail');
            console.log('Available global variables:', Object.keys(window));
            showError('JavaScript configuration error. Please refresh the page.');
            return;
        }
        
        console.log('ccsAjax object found:', ccsAjax);
        console.log('AJAX URL:', ccsAjax.ajaxUrl);
        console.log('Test connection nonce available:', !!ccsAjax.testConnectionNonce);
        console.log('Get courses nonce available:', !!ccsAjax.getCoursesNonce);

        // Test Connection button handler
        $('#ccs-test-connection').off('click').on('click', function(e) {
            e.preventDefault();
            console.log('=== TEST CONNECTION CLICKED ===');
            
            var $button = $(this);
            var $resultDiv = $('#ccs-connection-result');
            var originalText = $button.text();
            
            console.log('Button found, result div found:', $resultDiv.length > 0);
            
            // Disable button and show loading
            $button.prop('disabled', true).text('Testing...');
            $resultDiv.html('<div class="spinner is-active" style="float:none;"></div> Testing connection...');
            
            console.log('Making AJAX request with data:', {
                action: 'ccs_test_connection',
                nonce: ccsAjax.testConnectionNonce
            });
            
            $.ajax({
                url: ccsAjax.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'ccs_test_connection',
                    nonce: ccsAjax.testConnectionNonce
                },
                timeout: 30000,
                beforeSend: function(xhr, settings) {
                    console.log('AJAX request starting:', settings);
                },
                success: function(response) {
                    console.log('=== TEST CONNECTION SUCCESS ===');
                    console.log('Response:', response);
                    
                    if (response && response.success) {
                        $resultDiv.html('<div class="notice notice-success inline"><p>' + escapeHtml(response.data) + '</p></div>');
                        console.log('Success message displayed');
                    } else {
                        var errorMsg = (response && response.data) ? response.data : 'Unknown error occurred';
                        $resultDiv.html('<div class="notice notice-error inline"><p>Connection failed: ' + escapeHtml(errorMsg) + '</p></div>');
                        console.log('Error message displayed:', errorMsg);
                    }
                },
                error: function(xhr, status, error) {
                    console.log('=== TEST CONNECTION ERROR ===');
                    console.error('AJAX Error Details:', {
                        xhr: xhr,
                        status: status,
                        error: error,
                        responseText: xhr.responseText,
                        readyState: xhr.readyState,
                        statusText: xhr.statusText
                    });
                    
                    var errorMsg = 'Connection error: ' + error;
                    if (xhr.responseText) {
                        try {
                            var responseObj = JSON.parse(xhr.responseText);
                            if (responseObj && responseObj.data) {
                                errorMsg = 'Error: ' + responseObj.data;
                            }
                        } catch (e) {
                            errorMsg += ' (Response: ' + xhr.responseText.substring(0, 100) + ')';
                        }
                    }
                    $resultDiv.html('<div class="notice notice-error inline"><p>' + escapeHtml(errorMsg) + '</p></div>');
                },
                complete: function() {
                    console.log('Test connection AJAX completed');
                    $button.prop('disabled', false).text(originalText);
                }
            });
        });

        // Get Courses button handler
        $('#ccs-get-courses').off('click').on('click', function(e) {
            e.preventDefault();
            console.log('=== GET COURSES CLICKED ===');
            
            var $button = $(this);
            var $coursesList = $('#ccs-courses-list');
            var originalText = $button.text();
            
            console.log('Get courses button found, courses list found:', $coursesList.length > 0);
            
            // Disable button and show loading
            $button.prop('disabled', true).text('Loading...');
            $coursesList.html('<div class="spinner is-active" style="float:none;"></div> Loading courses...');
            
            console.log('Making get courses AJAX request with data:', {
                action: 'ccs_get_courses',
                nonce: ccsAjax.getCoursesNonce
            });
            
            $.ajax({
                url: ccsAjax.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'ccs_get_courses',
                    nonce: ccsAjax.getCoursesNonce
                },
                timeout: 30000,
                beforeSend: function(xhr, settings) {
                    console.log('Get courses AJAX request starting with data:', settings.data);
                },
                success: function(response) {
                    console.log('=== GET COURSES SUCCESS ===');
                    console.log('Raw response received:', response);
                    console.log('Response type:', typeof response);
                    
                    var courses = [];
                    var isValidResponse = false;
                    
                    // Handle standard WordPress AJAX success format
                    if (response && response.success === true && response.data) {
                        console.log('Standard WP AJAX success response detected');
                        if (Array.isArray(response.data)) {
                            courses = response.data;
                            isValidResponse = true;
                            console.log('Response data is array with', courses.length, 'courses');
                        } else if (typeof response.data === 'object' && response.data !== null) {
                            // Convert object with numeric keys to array
                            courses = Object.values(response.data);
                            isValidResponse = true;
                            console.log('Converted WP response object to array with', courses.length, 'courses');
                        }
                    }
                    // Handle direct array response
                    else if (Array.isArray(response)) {
                        console.log('Direct array response detected');
                        courses = response;
                        isValidResponse = true;
                    }
                    // Handle direct object response with numeric keys (your case)
                    else if (response && typeof response === 'object' && response !== null && response.success !== false) {
                        console.log('Direct object response detected');
                        var keys = Object.keys(response);
                        console.log('Object has', keys.length, 'keys');
                        
                        // Check if keys are numeric (indicating course data)
                        var hasNumericKeys = keys.length > 0 && keys.every(function(key) {
                            return !isNaN(parseInt(key, 10));
                        });
                        
                        if (hasNumericKeys) {
                            // Check if values look like course objects
                            var hasValidCourseData = keys.slice(0, 3).every(function(key) {
                                var item = response[key];
                                return item && typeof item === 'object' && 
                                       (item.hasOwnProperty('id') && item.hasOwnProperty('name'));
                            });
                            
                            if (hasValidCourseData) {
                                console.log('Valid course data detected in numeric object format');
                                courses = Object.values(response);
                                isValidResponse = true;
                                console.log('Extracted', courses.length, 'courses from numeric object response');
                            } else {
                                console.log('Object with numeric keys but invalid course data structure');
                            }
                        } else {
                            console.log('Object does not have numeric keys, checking for other valid structures...');
                            // Could be other object formats, but for now treat as invalid
                        }
                    }
                    
                    if (isValidResponse && courses.length > 0) {
                        console.log('Processing', courses.length, 'valid courses');
                        console.log('Sample course data:', courses[0]);
                        displayCourses(courses);
                        $('#ccs-sync-selected').show();
                    } else if (isValidResponse && courses.length === 0) {
                        console.log('Valid response but no courses found');
                        $coursesList.html('<div class="notice notice-info inline"><p>No courses found.</p></div>');
                    } else {
                        console.error('Unable to process response - not recognized as valid course data');
                        console.log('Failed to process response:', response);
                        $coursesList.html('<div class="notice notice-error inline"><p>Unable to process course data. Please check browser console for details.</p></div>');
                    }
                },
                error: function(xhr, status, error) {
                    console.log('=== GET COURSES ERROR ===');
                    console.error('Get courses AJAX Error:', {
                        xhr: xhr, 
                        status: status, 
                        error: error,
                        responseText: xhr.responseText,
                        readyState: xhr.readyState
                    });
                    
                    var errorMsg = 'Error loading courses: ' + error;
                    if (xhr.responseText) {
                        try {
                            var responseObj = JSON.parse(xhr.responseText);
                            if (responseObj && responseObj.data) {
                                errorMsg = 'Server error: ' + String(responseObj.data);
                            }
                        } catch (e) {
                            errorMsg += ' (Server response: ' + xhr.responseText.substring(0, 200) + ')';
                        }
                    }
                    $coursesList.html('<div class="notice notice-error inline"><p>' + escapeHtml(errorMsg) + '</p></div>');
                },
                complete: function() {
                    console.log('Get courses AJAX completed');
                    $button.prop('disabled', false).text(originalText);
                }
            });
        });

        // Sync Selected Courses button handler
        $('#ccs-sync-selected').on('click', function(e) {
            e.preventDefault();
            console.log('Sync selected button clicked');
            
            var selectedCourses = [];
            $('.ccs-course-checkbox:checked').each(function() {
                selectedCourses.push($(this).val());
            });
            
            if (selectedCourses.length === 0) {
                alert(ccsAjax.strings.selectCourses || 'Please select at least one course to sync.');
                return;
            }
            
            var $button = $(this);
            var $statusDiv = $('#ccs-sync-status');
            var originalText = $button.text();
            
            // Disable button and show loading
            $button.prop('disabled', true).text(ccsAjax.strings.syncing || 'Syncing...');
            $statusDiv.html('<div class="spinner is-active" style="float:none;"></div> Syncing courses...');
            
            $.ajax({
                url: ccsAjax.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'ccs_sync_courses',
                    nonce: ccsAjax.syncCoursesNonce,
                    course_ids: selectedCourses
                },
                timeout: 60000, // Longer timeout for sync operations
                success: function(response) {
                    console.log('Sync response:', response);
                    
                    if (response.success) {
                        displaySyncResults(response.data);
                    } else {
                        var errorMsg = response.data || 'Sync failed';
                        $statusDiv.html('<div class="notice notice-error inline"><p>' + errorMsg + '</p></div>');
                    }
                },
                error: function(xhr, status, error) {
                    console.error('AJAX Error:', {xhr: xhr, status: status, error: error});
                    var errorMsg = 'Sync error: ' + error;
                    if (xhr.responseText) {
                        errorMsg += ' (' + xhr.responseText.substring(0, 100) + ')';
                    }
                    $statusDiv.html('<div class="notice notice-error inline"><p>' + errorMsg + '</p></div>');
                },
                complete: function() {
                    $button.prop('disabled', false).text(originalText);
                }
            });
        });

        // Clear Logs button handler
        $('#ccs-clear-logs').on('click', function(e) {
            e.preventDefault();
            console.log('Clear logs button clicked');
            
            var $button = $(this);
            var originalText = $button.text();
            
            $button.prop('disabled', true).text('Clearing...');
            
            $.ajax({
                url: ccsAjax.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'ccs_clear_logs',
                    nonce: ccsAjax.clearLogsNonce
                },
                timeout: 30000,
                success: function(response) {
                    console.log('Clear logs response:', response);
                    
                    if (response.success) {
                        $('.ccs-log-container').html('<p>Logs cleared successfully.</p>');
                    } else {
                        var errorMsg = response.data || 'Failed to clear logs';
                        alert('Failed to clear logs: ' + errorMsg);
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Clear logs AJAX Error:', {xhr: xhr, status: status, error: error});
                    alert('Failed to clear logs. Please try again. Error: ' + error);
                },
                complete: function() {
                    $button.prop('disabled', false).text(originalText);
                }
            });
        });

        // Helper function to display courses
        function displayCourses(courses) {
            var $coursesList = $('#ccs-courses-list');
            
            console.log('displayCourses called with:', courses);
            
            if (!courses || courses.length === 0) {
                $coursesList.html('<div class="notice notice-info inline"><p>No courses found.</p></div>');
                return;
            }

            // Sort courses by status priority and then by creation date
            var sortedCourses = courses.sort(function(a, b) {
                var statusPriority = { 'new': 1, 'exists': 2, 'synced': 3 };
                var priorityA = statusPriority[a.status] || 999;
                var priorityB = statusPriority[b.status] || 999;
                
                if (priorityA !== priorityB) {
                    return priorityA - priorityB;
                }
                
                return new Date(b.created_at || 0) - new Date(a.created_at || 0);
            });

            var html = '<div class="ccs-courses-wrapper">';
            html += '<h3>Select courses to sync (' + courses.length + ' found):</h3>';
            
            // Add controls section
            html += '<div class="ccs-controls-section">' +
                '<div class="ccs-select-all">' +
                '<label>' +
                '<input type="checkbox" id="ccs-select-all-checkbox" checked> ' +
                'Select/Deselect All</label>' +
                '</div>' +
                '<div class="ccs-filter-controls">' +
                '<label for="ccs-status-filter">Filter by Status: </label>' +
                '<select id="ccs-status-filter" class="ccs-filter-dropdown">' +
                '<option value="all">All Courses</option>' +
                '<option value="new">New Only</option>' +
                '<option value="exists">Title Exists Only</option>' +
                '<option value="synced">Already Synced Only</option>' +
                '</select>' +
                '</div>' +
                '<div class="ccs-sort-controls">' +
                '<label for="ccs-sort-select">Sort by: </label>' +
                '<select id="ccs-sort-select" class="ccs-sort-dropdown">' +
                '<option value="status">Status (New → Existing → Synced)</option>' +
                '<option value="name">Course Name (A-Z)</option>' +
                '<option value="date">Creation Date (Newest First)</option>' +
                '</select>' +
                '</div>' +
                '</div>';
            
            html += '<div class="ccs-course-list" style="max-height: 300px; overflow-y: auto; border: 1px solid #ddd; padding: 10px; margin: 10px 0; background: #f9f9f9;">';
            
            sortedCourses.forEach(function(course, index) {
                console.log('Processing course ' + index + ':', course);
                
                // Safely extract and convert values
                var courseId = course.id ? String(course.id) : '';
                var courseName = course.name ? String(course.name) : 'Unnamed Course';
                var courseCode = course.course_code ? String(course.course_code) : '';
                var status = course.status ? String(course.status) : 'new';
                var statusLabel = course.status_label ? String(course.status_label) : 'New';
                var createdAt = course.created_at ? String(course.created_at) : '';
                
                // Default to checked for new courses, unchecked for existing
                var checkboxChecked = status === 'new' ? 'checked' : '';
                var statusClass = '';
                var statusText = '';
                
                if (status === 'synced') {
                    statusClass = 'ccs-course-exists';
                    statusText = ' <span class="ccs-status-badge ccs-exists-canvas-id">(' + escapeHtml(statusLabel) + ')</span>';
                } else if (status === 'exists') {
                    statusClass = 'ccs-course-exists';
                    statusText = ' <span class="ccs-status-badge ccs-exists-title">(' + escapeHtml(statusLabel) + ')</span>';
                } else {
                    statusText = ' <span class="ccs-status-badge ccs-new-course">(' + escapeHtml(statusLabel) + ')</span>';
                }
                
                // Add course code if available and different from name
                var courseDisplayName = courseName;
                if (courseCode && courseCode !== courseName) {
                    courseDisplayName += ' [' + courseCode + ']';
                }
                
                html += '<div class="ccs-course-item ' + statusClass + '" ' +
                    'data-course-name="' + escapeHtml(courseName) + '" ' +
                    'data-created-at="' + escapeHtml(createdAt) + '" ' +
                    'data-status="' + escapeHtml(status) + '">' +
                    '<label style="display: block; margin: 5px 0; padding: 5px; border-bottom: 1px solid #eee;">' +
                    '<input type="checkbox" class="ccs-course-checkbox" value="' + escapeHtml(courseId) + '" ' + checkboxChecked + '> ' +
                    '<span>' + escapeHtml(courseDisplayName) + statusText + '</span>' +
                    '</label>' +
                    '</div>';
            });
            
            html += '</div>';
            html += '<button type="button" id="ccs-sync-selected" class="button button-primary" style="margin-top: 10px;">Sync Selected Courses</button>';
            html += '</div>';
            
            $coursesList.html(html);
            console.log('Courses displayed successfully');
            
            // Bind control handlers
            bindCourseControls();
        }

        // Helper function to bind course control handlers
        function bindCourseControls() {
            // Handle select all checkbox
            $(document).off('change', '#ccs-select-all-checkbox').on('change', '#ccs-select-all-checkbox', function() {
                var isChecked = $(this).prop('checked');
                $('.ccs-course-checkbox:visible').prop('checked', isChecked);
            });
            
            // Handle status filter dropdown
            $(document).off('change', '#ccs-status-filter').on('change', '#ccs-status-filter', function() {
                var filterValue = $(this).val();
                var courseItems = $('.ccs-course-item');
                
                courseItems.each(function() {
                    var $item = $(this);
                    var status = $item.data('status');
                    
                    if (filterValue === 'all' || status === filterValue) {
                        $item.show();
                    } else {
                        $item.hide();
                    }
                });
                
                updateSelectAllCheckbox();
            });
            
            // Handle sort dropdown change
            $(document).off('change', '#ccs-sort-select').on('change', '#ccs-sort-select', function() {
                var sortBy = $(this).val();
                var courseItems = $('.ccs-course-item').toArray();
                
                courseItems.sort(function(a, b) {
                    var $a = $(a);
                    var $b = $(b);
                    
                    switch(sortBy) {
                        case 'name':
                            return $a.data('course-name').localeCompare($b.data('course-name'));
                            
                        case 'date':
                            var dateA = new Date($a.data('created-at') || 0);
                            var dateB = new Date($b.data('created-at') || 0);
                            return dateB - dateA;
                            
                        case 'status':
                        default:
                            var statusPriority = { 'new': 1, 'exists': 2, 'synced': 3 };
                            var priorityA = statusPriority[$a.data('status')] || 999;
                            var priorityB = statusPriority[$b.data('status')] || 999;
                            
                            if (priorityA !== priorityB) {
                                return priorityA - priorityB;
                            }
                            
                            var dateA2 = new Date($a.data('created-at') || 0);
                            var dateB2 = new Date($b.data('created-at') || 0);
                            return dateB2 - dateA2;
                    }
                });
                
                // Re-append sorted items
                var courseList = $('.ccs-course-list');
                var controlsSection = $('.ccs-controls-section');
                
                courseList.empty();
                
                courseItems.forEach(function(item) {
                    courseList.append(item);
                });
            });
            
            // Update select all checkbox when individual checkboxes change
            $(document).off('change', '.ccs-course-checkbox').on('change', '.ccs-course-checkbox', function() {
                updateSelectAllCheckbox();
            });
        }
        
        // Helper function to update select all checkbox
        function updateSelectAllCheckbox() {
            var visibleCheckboxes = $('.ccs-course-checkbox:visible');
            var checkedVisibleCheckboxes = $('.ccs-course-checkbox:visible:checked');
            var selectAllCheckbox = $('#ccs-select-all-checkbox');
            
            if (visibleCheckboxes.length === 0) {
                selectAllCheckbox.prop('indeterminate', false).prop('checked', false);
            } else if (checkedVisibleCheckboxes.length === visibleCheckboxes.length) {
                selectAllCheckbox.prop('indeterminate', false).prop('checked', true);
            } else if (checkedVisibleCheckboxes.length > 0) {
                selectAllCheckbox.prop('indeterminate', true);
            } else {
                selectAllCheckbox.prop('indeterminate', false).prop('checked', false);
            }
        }

        // Helper function to display sync results
        function displaySyncResults(results) {
            var $statusDiv = $('#ccs-sync-status');
            var html = '<div class="ccs-sync-results">';
            html += '<h3>Sync Results</h3>';
            
            if (results.message) {
                html += '<div class="notice notice-success inline"><p>' + escapeHtml(results.message) + '</p></div>';
            }
            
            html += '<table class="widefat">';
            html += '<tr><td><strong>Imported:</strong></td><td>' + (results.imported || 0) + '</td></tr>';
            html += '<tr><td><strong>Skipped:</strong></td><td>' + (results.skipped || 0) + '</td></tr>';
            html += '<tr><td><strong>Errors:</strong></td><td>' + (results.errors || 0) + '</td></tr>';
            html += '<tr><td><strong>Total:</strong></td><td>' + (results.total || 0) + '</td></tr>';
            html += '</table>';
            html += '</div>';
            
            $statusDiv.html(html);
        }
        
        // Helper function to escape HTML
        function escapeHtml(text) {
            if (typeof text !== 'string') {
                text = String(text);
            }
            var map = {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            };
            return text.replace(/[&<>"']/g, function(m) { return map[m]; });
        }
        
        // Helper function to show errors
        function showError(message) {
            if ($('#ccs-connection-result').length) {
                $('#ccs-connection-result').html('<div class="notice notice-error inline"><p>' + escapeHtml(message) + '</p></div>');
            } else if ($('#ccs-courses-list').length) {
                $('#ccs-courses-list').html('<div class="notice notice-error inline"><p>' + escapeHtml(message) + '</p></div>');
            } else {
                console.error('CCS Error:', message);
            }
        }

        // Enhanced logging for debugging
        console.log('=== CCS ADMIN INITIALIZATION COMPLETE ===');
        console.log('Test connection button exists:', $('#ccs-test-connection').length > 0);
        console.log('Get courses button exists:', $('#ccs-get-courses').length > 0);
        console.log('Connection result div exists:', $('#ccs-connection-result').length > 0);
        console.log('Courses list div exists:', $('#ccs-courses-list').length > 0);
    });
})(jQuery);
