
(function($) {
    'use strict';

    // Admin functionality for Canvas Course Sync
    $(document).ready(function() {
        // Test Connection button
        $('#ccs-test-connection').on('click', function(e) {
            e.preventDefault();
            var button = $(this);
            var originalText = button.text();
            
            button.prop('disabled', true).text('Testing...');
            
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'ccs_test_connection',
                    nonce: $('#ccs_test_connection_nonce').val()
                },
                success: function(response) {
                    if (response.success) {
                        alert('Success: ' + response.data);
                    } else {
                        alert('Error: ' + response.data);
                    }
                },
                error: function() {
                    alert('Error: Failed to test connection');
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
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'ccs_get_courses',
                    nonce: $('#ccs_get_courses_nonce').val()
                },
                success: function(response) {
                    if (response.success && response.data) {
                        displayCoursesList(response.data);
                        $('#ccs-sync-selected').prop('disabled', false);
                    } else {
                        alert('Error: ' + (response.data || 'Failed to load courses'));
                    }
                },
                error: function() {
                    alert('Error: Failed to load courses');
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
                alert('Please select at least one course to sync.');
                return;
            }
            
            var button = $(this);
            var originalText = button.text();
            
            button.prop('disabled', true).text('Syncing...');
            
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'ccs_sync_courses',
                    nonce: $('#ccs_sync_nonce').val(),
                    course_ids: selectedCourses
                },
                success: function(response) {
                    if (response.success) {
                        displaySyncResults(response.data);
                    } else {
                        alert('Error: ' + response.data);
                    }
                },
                error: function() {
                    alert('Error: Failed to sync courses');
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
                courses.forEach(function(course) {
                    html += '<div class="course-item">';
                    html += '<label>';
                    html += '<input type="checkbox" class="course-checkbox" value="' + course.id + '"> ';
                    html += course.name + ' (' + course.course_code + ')';
                    html += '</label>';
                    html += '</div>';
                });
            } else {
                html += '<p>No courses found.</p>';
            }
            
            html += '</div>';
            $('#ccs-courses-list').html(html);
        }

        function displaySyncResults(results) {
            var html = '<div class="sync-results">';
            html += '<h3>Sync Results</h3>';
            html += '<p>' + results.message + '</p>';
            html += '<ul>';
            html += '<li>Imported: ' + results.imported + '</li>';
            html += '<li>Skipped: ' + results.skipped + '</li>';
            html += '<li>Errors: ' + results.errors + '</li>';
            html += '<li>Total: ' + results.total + '</li>';
            html += '</ul>';
            html += '</div>';
            $('#ccs-sync-status').html(html);
        }
    });

})(jQuery);
