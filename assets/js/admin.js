
(function($) {
    'use strict';

    $(document).ready(function() {
        // Check if required variables are available
        if (typeof ccsAjax === 'undefined') {
            console.error('CCS Admin: Required AJAX variables not found');
            return;
        }

        // Test Connection button
        $('#ccs-test-connection').on('click', function(e) {
            e.preventDefault();
            var button = $(this);
            var originalText = button.text();
            
            button.prop('disabled', true).text('Testing...');
            
            $.ajax({
                url: ccsAjax.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'ccs_test_connection',
                    nonce: ccsAjax.nonces.test_connection
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
                url: ccsAjax.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'ccs_get_courses',
                    nonce: ccsAjax.nonces.get_courses
                },
                success: function(response) {
                    if (response.success) {
                        displayCourses(response.data);
                        showNotice('Courses loaded successfully', 'success');
                    } else {
                        showNotice('Error: ' + response.data, 'error');
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
            $('.ccs-course-checkbox:checked').each(function() {
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
                url: ccsAjax.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'ccs_sync_courses',
                    nonce: ccsAjax.nonces.sync_courses,
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

        function displayCourses(courses) {
            if (!Array.isArray(courses) || courses.length === 0) {
                $('#ccs-courses-list').html('<p>No courses found.</p>');
                return;
            }

            var html = '<div class="ccs-courses"><h3>Select courses to sync:</h3>';
            courses.forEach(function(course) {
                var disabled = course.exists_in_wp ? 'disabled' : '';
                var status = course.exists_in_wp ? ' (Already exists)' : '';
                html += '<label><input type="checkbox" class="ccs-course-checkbox" value="' + 
                        escapeHtml(course.id) + '" ' + disabled + '> ' + 
                        escapeHtml(course.name) + status + '</label><br>';
            });
            html += '</div>';
            
            $('#ccs-courses-list').html(html);
            $('#ccs-sync-selected').prop('disabled', false);
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
