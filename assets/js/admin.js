(function($) {
    'use strict';

    $(document).ready(function() {
        // Check if required variables are available
        if (typeof window.ccsNonces === 'undefined' || typeof window.ajaxurl === 'undefined') {
            console.error('CCS Admin: Required variables not found');
            return;
        }

        // Test Connection button
        $('#ccs-test-connection').on('click', function(e) {
            e.preventDefault();
            var button = $(this);
            var originalText = button.text();
            
            button.prop('disabled', true).text('Testing...');
            
            $.ajax({
                url: window.ajaxurl,
                type: 'POST',
                data: {
                    action: 'ccs_test_connection',
                    nonce: window.ccsNonces.test_connection
                },
                success: function(response) {
                    if (response.success) {
                        showNotice('Success: ' + response.data, 'success');
                    } else {
                        showNotice('Error: ' + response.data, 'error');
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Connection test error:', error);
                    showNotice('Error: Failed to test connection', 'error');
                },
                complete: function() {
                    button.prop('disabled', false).text(originalText);
                }
            });
        });

        // Get Courses button
        $('#ccs-get-courses').on('click', function(e) {
            e.preventDefault();
            var button = $(this);
            var originalText = button.text();
            
            console.log('Get Courses button clicked');
            button.prop('disabled', true).text('Loading...');
            
            $.ajax({
                url: window.ajaxurl,
                type: 'POST',
                data: {
                    action: 'ccs_get_courses',
                    nonce: window.ccsNonces.get_courses
                },
                success: function(response) {
                    console.log('Get courses response:', response);
                    
                    if (response.success && response.data) {
                        console.log('Courses data type:', typeof response.data);
                        console.log('Courses data length:', response.data.length);
                        console.log('First course:', response.data[0]);
                        
                        if (Array.isArray(response.data) && response.data.length > 0) {
                            displayCoursesList(response.data);
                            $('#ccs-sync-selected').prop('disabled', false);
                            showNotice('Loaded ' + response.data.length + ' courses', 'success');
                        } else {
                            console.log('No courses found in response');
                            $('#ccs-courses-list').html('<p>No courses found. This could mean:</p><ul><li>You don\'t have any courses assigned to you</li><li>Your API token doesn\'t have the right permissions</li><li>All your courses are in a non-available state</li></ul>');
                            showNotice('No courses found', 'warning');
                        }
                    } else {
                        console.error('Error in response:', response);
                        showNotice('Error: ' + (response.data || 'Failed to load courses'), 'error');
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Get courses AJAX error:', {
                        xhr: xhr,
                        status: status,
                        error: error,
                        responseText: xhr.responseText
                    });
                    showNotice('Error: Failed to load courses. Check console for details.', 'error');
                },
                complete: function() {
                    button.prop('disabled', false).text(originalText);
                }
            });
        });

        // Sync Selected Courses button
        $('#ccs-sync-selected').on('click', function(e) {
            e.preventDefault();
            var selectedCourses = [];
            $('.course-checkbox:checked').each(function() {
                selectedCourses.push($(this).val());
            });
            
            if (selectedCourses.length === 0) {
                showNotice('Please select at least one course to sync.', 'warning');
                return;
            }
            
            var button = $(this);
            var originalText = button.text();
            
            button.prop('disabled', true).text('Syncing...');
            
            $.ajax({
                url: window.ajaxurl,
                type: 'POST',
                data: {
                    action: 'ccs_sync_courses',
                    nonce: window.ccsNonces.sync_courses,
                    course_ids: selectedCourses
                },
                success: function(response) {
                    if (response.success) {
                        displaySyncResults(response.data);
                        showNotice('Sync completed successfully', 'success');
                    } else {
                        showNotice('Error: ' + response.data, 'error');
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Sync courses error:', error);
                    showNotice('Error: Failed to sync courses', 'error');
                },
                complete: function() {
                    button.prop('disabled', false).text(originalText);
                }
            });
        });

        function displayCoursesList(courses) {
            console.log('Displaying courses list:', courses);
            
            var html = '<h3>Available Courses (' + courses.length + ')</h3>';
            html += '<div class="courses-list">';
            
            if (courses && courses.length > 0) {
                html += '<div style="margin-bottom: 10px;">';
                html += '<label><input type="checkbox" id="select-all-courses"> Select All</label>';
                html += '</div>';
                
                courses.forEach(function(course) {
                    console.log('Processing course:', course);
                    
                    var courseId = course.id || 'unknown';
                    var courseName = course.name || 'Unnamed Course';
                    var courseCode = course.course_code || '';
                    
                    html += '<div class="course-item">';
                    html += '<label>';
                    html += '<input type="checkbox" class="course-checkbox" value="' + escapeHtml(courseId) + '"> ';
                    html += escapeHtml(courseName);
                    if (courseCode) {
                        html += ' (' + escapeHtml(courseCode) + ')';
                    }
                    html += '</label>';
                    html += '</div>';
                });
            } else {
                html += '<p>No courses available.</p>';
            }
            
            html += '</div>';
            $('#ccs-courses-list').html(html);
            
            // Add select all functionality
            $('#select-all-courses').on('change', function() {
                $('.course-checkbox').prop('checked', $(this).prop('checked'));
            });
        }

        function displaySyncResults(results) {
            var html = '<div class="sync-results">';
            html += '<h3>Sync Results</h3>';
            html += '<p>' + escapeHtml(results.message || 'Sync completed') + '</p>';
            html += '<ul>';
            html += '<li>Imported: ' + parseInt(results.imported || 0) + '</li>';
            html += '<li>Skipped: ' + parseInt(results.skipped || 0) + '</li>';
            html += '<li>Errors: ' + parseInt(results.errors || 0) + '</li>';
            html += '<li>Total: ' + parseInt(results.total || 0) + '</li>';
            html += '</ul>';
            html += '</div>';
            $('#ccs-sync-status').html(html);
        }
        
        function showNotice(message, type) {
            var noticeClass = 'notice-info';
            if (type === 'success') noticeClass = 'notice-success';
            if (type === 'error') noticeClass = 'notice-error';
            if (type === 'warning') noticeClass = 'notice-warning';
            
            var notice = $('<div class="notice ' + noticeClass + ' is-dismissible"><p>' + escapeHtml(message) + '</p></div>');
            $('.wrap h1').after(notice);
            
            // Auto-dismiss after 5 seconds
            setTimeout(function() {
                notice.fadeOut();
            }, 5000);
        }
        
        function escapeHtml(text) {
            var map = {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            };
            return text.toString().replace(/[&<>"']/g, function(m) { return map[m]; });
        }
    });

})(jQuery);
