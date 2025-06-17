
(function($) {
    'use strict';

    // Debug function to check environment
    function debugEnvironment() {
        console.log('=== CCS Debug Information ===');
        console.log('jQuery version:', $.fn.jquery);
        console.log('Document ready state:', document.readyState);
        console.log('ccsAjax defined:', typeof ccsAjax !== 'undefined');
        
        if (typeof ccsAjax !== 'undefined') {
            console.log('ccsAjax object:', ccsAjax);
        } else {
            console.error('ccsAjax is not defined! Check script localization.');
        }
        
        // Check button existence
        var testBtn = $('#ccs-test-connection');
        var getCoursesBtn = $('#ccs-get-courses');
        var syncBtn = $('#ccs-sync-selected');
        
        console.log('Test connection button found:', testBtn.length);
        console.log('Get courses button found:', getCoursesBtn.length);
        console.log('Sync button found:', syncBtn.length);
        
        if (testBtn.length === 0) {
            console.error('Test connection button not found in DOM');
        }
        if (getCoursesBtn.length === 0) {
            console.error('Get courses button not found in DOM');
        }
    }

    $(document).ready(function() {
        console.log('CCS Admin script loaded and document ready');
        
        // Run debug check
        debugEnvironment();
        
        // Check if required variables are available
        if (typeof ccsAjax === 'undefined') {
            console.error('CCS Admin: ccsAjax variable not found - script localization failed');
            alert('Admin script not properly configured. Please refresh the page.');
            return;
        }
        
        console.log('CCS Admin initialized successfully');
        
        // Test Connection button
        $(document).on('click', '#ccs-test-connection', function(e) {
            e.preventDefault();
            console.log('Test connection button clicked');
            
            var button = $(this);
            var resultDiv = $('#ccs-connection-result');
            var originalText = button.text();
            
            button.prop('disabled', true).text('Testing...');
            resultDiv.html('<div class="spinner is-active" style="float:none;"></div> Testing connection...');
            
            $.ajax({
                url: ccsAjax.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'ccs_test_connection',
                    nonce: ccsAjax.testConnectionNonce
                },
                success: function(response) {
                    console.log('Test connection response:', response);
                    if (response.success) {
                        resultDiv.html('<div class="notice notice-success inline"><p>' + response.data + '</p></div>');
                        showNotice('Connection test successful!', 'success');
                    } else {
                        var errorMsg = response.data || 'Unknown error occurred';
                        resultDiv.html('<div class="notice notice-error inline"><p>Connection failed: ' + errorMsg + '</p></div>');
                        showNotice('Connection test failed: ' + errorMsg, 'error');
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Connection test error:', {xhr, status, error});
                    console.error('Response text:', xhr.responseText);
                    var errorMsg = 'AJAX Error: ' + error;
                    if (xhr.responseText) {
                        try {
                            var errorData = JSON.parse(xhr.responseText);
                            if (errorData.data) {
                                errorMsg = errorData.data;
                            }
                        } catch (e) {
                            errorMsg = xhr.responseText.substring(0, 100);
                        }
                    }
                    resultDiv.html('<div class="notice notice-error inline"><p>Connection error: ' + errorMsg + '</p></div>');
                    showNotice('Connection test failed: ' + errorMsg, 'error');
                },
                complete: function() {
                    button.prop('disabled', false).text(originalText);
                }
            });
        });

        // Get Courses button
        $(document).on('click', '#ccs-get-courses', function(e) {
            e.preventDefault();
            console.log('Get courses button clicked');
            
            var button = $(this);
            var coursesList = $('#ccs-courses-list');
            var originalText = button.text();
            
            button.prop('disabled', true).text('Loading...');
            coursesList.html('<div class="spinner is-active" style="float:none;"></div> Loading courses...');
            
            $.ajax({
                url: ccsAjax.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'ccs_get_courses',
                    nonce: ccsAjax.getCoursesNonce
                },
                success: function(response) {
                    console.log('Get courses response:', response);
                    if (response.success) {
                        displayCourses(response.data);
                        showNotice('Courses loaded successfully!', 'success');
                    } else {
                        var errorMsg = response.data || 'Failed to load courses';
                        coursesList.html('<div class="notice notice-error inline"><p>' + errorMsg + '</p></div>');
                        showNotice('Failed to load courses: ' + errorMsg, 'error');
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Get courses error:', {xhr, status, error});
                    console.error('Response text:', xhr.responseText);
                    var errorMsg = 'AJAX Error: ' + error;
                    if (xhr.responseText) {
                        try {
                            var errorData = JSON.parse(xhr.responseText);
                            if (errorData.data) {
                                errorMsg = errorData.data;
                            }
                        } catch (e) {
                            errorMsg = xhr.responseText.substring(0, 100);
                        }
                    }
                    coursesList.html('<div class="notice notice-error inline"><p>Error loading courses: ' + errorMsg + '</p></div>');
                    showNotice('Failed to load courses: ' + errorMsg, 'error');
                },
                complete: function() {
                    button.prop('disabled', false).text(originalText);
                }
            });
        });

        // Sync Selected Courses button
        $(document).on('click', '#ccs-sync-selected', function(e) {
            e.preventDefault();
            console.log('Sync selected button clicked');
            
            var selectedCourses = [];
            $('.ccs-course-checkbox:checked').each(function() {
                selectedCourses.push($(this).val());
            });
            
            console.log('Selected courses:', selectedCourses);
            
            if (selectedCourses.length === 0) {
                showNotice('Please select at least one course to sync.', 'warning');
                return;
            }
            
            var button = $(this);
            var statusDiv = $('#ccs-sync-status');
            var originalText = button.text();
            
            button.prop('disabled', true).text('Syncing...');
            statusDiv.html('<div class="spinner is-active" style="float:none;"></div> Syncing courses...');
            
            $.ajax({
                url: ccsAjax.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'ccs_sync_courses',
                    nonce: ccsAjax.syncCoursesNonce,
                    course_ids: selectedCourses
                },
                success: function(response) {
                    console.log('Sync response:', response);
                    if (response.success) {
                        displaySyncResults(response.data);
                        showNotice('Courses synced successfully!', 'success');
                    } else {
                        var errorMsg = response.data || 'Sync failed';
                        statusDiv.html('<div class="notice notice-error inline"><p>' + errorMsg + '</p></div>');
                        showNotice('Sync failed: ' + errorMsg, 'error');
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Sync courses error:', {xhr, status, error});
                    console.error('Response text:', xhr.responseText);
                    var errorMsg = 'AJAX Error: ' + error;
                    if (xhr.responseText) {
                        try {
                            var errorData = JSON.parse(xhr.responseText);
                            if (errorData.data) {
                                errorMsg = errorData.data;
                            }
                        } catch (e) {
                            errorMsg = xhr.responseText.substring(0, 100);
                        }
                    }
                    statusDiv.html('<div class="notice notice-error inline"><p>Sync error: ' + errorMsg + '</p></div>');
                    showNotice('Sync failed: ' + errorMsg, 'error');
                },
                complete: function() {
                    button.prop('disabled', false).text(originalText);
                }
            });
        });

        // Helper functions
        function displayCourses(courses) {
            console.log('Displaying courses:', courses);
            
            var coursesList = $('#ccs-courses-list');
            
            if (!Array.isArray(courses) || courses.length === 0) {
                coursesList.html('<div class="notice notice-info inline"><p>No courses found.</p></div>');
                return;
            }

            var html = '<div class="ccs-courses">';
            html += '<h3>Select courses to sync:</h3>';
            html += '<div class="ccs-course-checkboxes" style="max-height: 300px; overflow-y: auto; border: 1px solid #ddd; padding: 10px; margin: 10px 0;">';
            
            courses.forEach(function(course) {
                var courseId = course.id || '';
                var courseName = course.name || 'Unnamed Course';
                var existsInWp = course.exists_in_wp || false;
                var disabled = existsInWp ? 'disabled' : '';
                var status = existsInWp ? ' (Already exists in WordPress)' : '';
                
                html += '<label style="display: block; margin: 5px 0;">';
                html += '<input type="checkbox" class="ccs-course-checkbox" value="' + escapeHtml(courseId) + '" ' + disabled + '> ';
                html += escapeHtml(courseName) + status;
                html += '</label>';
            });
            
            html += '</div>';
            html += '</div>';
            
            coursesList.html(html);
            $('#ccs-sync-selected').show();
        }

        function displaySyncResults(results) {
            console.log('Displaying sync results:', results);
            
            var statusDiv = $('#ccs-sync-status');
            var html = '<div class="ccs-sync-results">';
            html += '<h3>Sync Results</h3>';
            
            if (results.message) {
                html += '<div class="notice notice-success inline"><p>' + escapeHtml(results.message) + '</p></div>';
            }
            
            html += '<table class="widefat">';
            html += '<tr><td><strong>Imported:</strong></td><td>' + parseInt(results.imported || 0) + '</td></tr>';
            html += '<tr><td><strong>Skipped:</strong></td><td>' + parseInt(results.skipped || 0) + '</td></tr>';
            html += '<tr><td><strong>Errors:</strong></td><td>' + parseInt(results.errors || 0) + '</td></tr>';
            html += '<tr><td><strong>Total:</strong></td><td>' + parseInt(results.total || 0) + '</td></tr>';
            html += '</table>';
            html += '</div>';
            
            statusDiv.html(html);
        }
        
        function showNotice(message, type) {
            type = type || 'info';
            var noticeClass = 'notice-' + type;
            
            var notice = $('<div class="notice ' + noticeClass + ' is-dismissible"><p>' + escapeHtml(message) + '</p><button type="button" class="notice-dismiss"><span class="screen-reader-text">Dismiss this notice.</span></button></div>');
            $('.wrap h1').after(notice);
            
            // Handle dismiss button
            notice.find('.notice-dismiss').on('click', function() {
                notice.fadeOut();
            });
            
            // Auto-dismiss after 5 seconds
            setTimeout(function() {
                notice.fadeOut();
            }, 5000);
        }
        
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
    });

})(jQuery);
