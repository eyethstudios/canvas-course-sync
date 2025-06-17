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
        console.log('Test connection nonce:', ccsAjax.testConnectionNonce ? ccsAjax.testConnectionNonce.substring(0, 10) + '...' : 'NOT SET');

        // Test Connection button handler
        $('#ccs-test-connection').on('click', function(e) {
            e.preventDefault();
            console.log('Test connection button clicked');
            
            var $button = $(this);
            var $resultDiv = $('#ccs-connection-result');
            var originalText = $button.text();
            
            console.log('Button found, result div found:', $resultDiv.length > 0);
            
            // Disable button and show loading
            $button.prop('disabled', true).text(ccsAjax.strings.testing || 'Testing...');
            $resultDiv.html('<div class="spinner is-active" style="float:none;"></div> Testing connection...');
            
            console.log('Making AJAX request to:', ccsAjax.ajaxUrl);
            console.log('Using nonce:', ccsAjax.testConnectionNonce);
            
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
                    console.log('Test connection response received:', response);
                    
                    if (response && response.success) {
                        $resultDiv.html('<div class="notice notice-success inline"><p>' + escapeHtml(response.data) + '</p></div>');
                        console.log('Success response displayed');
                    } else {
                        var errorMsg = (response && response.data) ? response.data : 'Unknown error occurred';
                        $resultDiv.html('<div class="notice notice-error inline"><p>Connection failed: ' + escapeHtml(errorMsg) + '</p></div>');
                        console.log('Error response displayed:', errorMsg);
                    }
                },
                error: function(xhr, status, error) {
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
                    console.log('AJAX request completed');
                    $button.prop('disabled', false).text(originalText);
                }
            });
        });

        // Get Courses button handler
        $('#ccs-get-courses').on('click', function(e) {
            e.preventDefault();
            console.log('Get courses button clicked');
            
            var $button = $(this);
            var $coursesList = $('#ccs-courses-list');
            var originalText = $button.text();
            
            console.log('Get courses button found, courses list found:', $coursesList.length > 0);
            
            // Disable button and show loading
            $button.prop('disabled', true).text(ccsAjax.strings.loading || 'Loading...');
            $coursesList.html('<div class="spinner is-active" style="float:none;"></div> Loading courses...');
            
            console.log('Making get courses AJAX request');
            
            $.ajax({
                url: ccsAjax.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'ccs_get_courses',
                    nonce: ccsAjax.getCoursesNonce
                },
                timeout: 30000,
                beforeSend: function(xhr, settings) {
                    console.log('Get courses AJAX request starting:', settings);
                },
                success: function(response) {
                    console.log('Get courses response received:', response);
                    
                    if (response && response.success && Array.isArray(response.data)) {
                        console.log('Displaying', response.data.length, 'courses');
                        displayCourses(response.data);
                        $('#ccs-sync-selected').show();
                    } else {
                        var errorMsg = (response && response.data) ? response.data : 'Failed to load courses';
                        $coursesList.html('<div class="notice notice-error inline"><p>' + escapeHtml(errorMsg) + '</p></div>');
                        console.log('Error loading courses:', errorMsg);
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Get courses AJAX Error:', {xhr: xhr, status: status, error: error});
                    var errorMsg = 'Error loading courses: ' + error;
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
                    $coursesList.html('<div class="notice notice-error inline"><p>' + escapeHtml(errorMsg) + '</p></div>');
                },
                complete: function() {
                    console.log('Get courses AJAX request completed');
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

        // Helper function to display courses
        function displayCourses(courses) {
            var $coursesList = $('#ccs-courses-list');
            
            if (!courses || courses.length === 0) {
                $coursesList.html('<div class="notice notice-info inline"><p>No courses found.</p></div>');
                return;
            }

            var html = '<div class="ccs-courses-wrapper">';
            html += '<h3>Select courses to sync:</h3>';
            html += '<div class="ccs-course-list" style="max-height: 300px; overflow-y: auto; border: 1px solid #ddd; padding: 10px; margin: 10px 0;">';
            
            courses.forEach(function(course) {
                var courseId = course.id || '';
                var courseName = course.name || 'Unnamed Course';
                var existsInWp = course.exists_in_wp || false;
                var disabled = existsInWp ? 'disabled' : '';
                var status = existsInWp ? ' (Already exists)' : '';
                
                html += '<label style="display: block; margin: 5px 0;">';
                html += '<input type="checkbox" class="ccs-course-checkbox" value="' + escapeHtml(courseId) + '" ' + disabled + '> ';
                html += escapeHtml(courseName) + status;
                html += '</label>';
            });
            
            html += '</div></div>';
            $coursesList.html(html);
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
        console.log('All event handlers attached successfully');
        console.log('Test connection button exists:', $('#ccs-test-connection').length > 0);
        console.log('Get courses button exists:', $('#ccs-get-courses').length > 0);
        console.log('Connection result div exists:', $('#ccs-connection-result').length > 0);
        console.log('Courses list div exists:', $('#ccs-courses-list').length > 0);
    });

})(jQuery);
