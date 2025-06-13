
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
            
            button.prop('disabled', true).text('Loading...');
            
            $.ajax({
                url: window.ajaxurl,
                type: 'POST',
                data: {
                    action: 'ccs_get_courses',
                    nonce: window.ccsNonces.get_courses
                },
                success: function(response) {
                    if (response.success && response.data) {
                        displayCoursesList(response.data);
                        $('#ccs-sync-selected').prop('disabled', false);
                        showNotice('Loaded ' + response.data.length + ' courses', 'success');
                    } else {
                        showNotice('Error: ' + (response.data || 'Failed to load courses'), 'error');
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Get courses error:', error);
                    showNotice('Error: Failed to load courses', 'error');
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
            var html = '<h3>Available Courses</h3>';
            html += '<div class="courses-list">';
            
            if (courses && courses.length > 0) {
                html += '<div style="margin-bottom: 10px;">';
                html += '<label><input type="checkbox" id="select-all-courses"> Select All</label>';
                html += '</div>';
                
                courses.forEach(function(course) {
                    html += '<div class="course-item">';
                    html += '<label>';
                    html += '<input type="checkbox" class="course-checkbox" value="' + escapeHtml(course.id) + '"> ';
                    html += escapeHtml(course.name) + ' (' + escapeHtml(course.course_code) + ')';
                    html += '</label>';
                    html += '</div>';
                });
            } else {
                html += '<p>No courses found.</p>';
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
